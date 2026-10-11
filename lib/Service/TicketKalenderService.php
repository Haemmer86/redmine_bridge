<?php

declare(strict_types=1);

namespace OCA\RedmineBridge\Service;

use OCA\RedmineBridge\AppInfo\Application;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\IURLGenerator;
use OCP\Server;
use Psr\Log\LoggerInterface;

/**
 * Trägt das Fälligkeitsdatum eines Tickets als Termin in den persönlichen
 * Nextcloud-Kalender des "Zuständigen Mitarbeiters" (siehe
 * {@see TicketZustaendigkeitService}) ein — in einem eigenen, automatisch
 * angelegten Kalender "Redmine Tickets", nicht im Standardkalender, damit
 * sich das sauber ein-/ausblenden lässt.
 *
 * Ohne zuständigen Mitarbeiter, ohne Fälligkeitsdatum oder bei bereits
 * erledigtem Ticket gibt es keinen bzw. keinen weiteren Termin — ein
 * vorhandener wird dann automatisch wieder entfernt.
 *
 * Nutzt bewusst NICHT die öffentliche `OCP\Calendar`-API: die erlaubt zwar
 * das Anlegen von Terminen (`ICreateFromString`), aber kein Aktualisieren
 * oder Löschen eines bereits angelegten Termins — beides wird hier
 * zwingend gebraucht (Fälligkeitsdatum ändert sich, Ticket wird erledigt).
 * Stattdessen wird das interne CalDAV-Backend der DAV-App verwendet
 * (`OCA\DAV\CalDAV\CalDavBackend`) — keine offiziell stabile öffentliche
 * Schnittstelle, aber der übliche Weg, den auch andere
 * Kalender-Integrationen Dritter nutzen. Deshalb wird es ausschließlich
 * lazy über `Server::get()` aufgelöst und jeder Aufruf steht in einem
 * try/catch: schlägt das fehl (z. B. nach einem Nextcloud-Update mit
 * geänderter interner Schnittstelle), bricht NUR der Kalenderabgleich ab
 * (geloggt), nie die Ticketverwaltung selbst.
 */
class TicketKalenderService {

	private const TABELLE = 'redmine_bridge_kalender';
	private const KALENDER_URI = 'redmine-tickets';
	private const KALENDER_NAME = 'Redmine Tickets';

	private ?object $calDavBackendZwischenspeicher = null;
	private bool $calDavBackendFehlgeschlagen = false;

	/** @var array<int,bool>|null */
	private ?array $geschlosseneStatusIdsZwischenspeicher = null;

	public function __construct(
		private readonly IDBConnection $db,
		private readonly TicketZustaendigkeitService $ticketZustaendigkeit,
		private readonly RedmineClient $redmine,
		private readonly IConfig $config,
		private readonly IURLGenerator $urlGenerator,
		private readonly LoggerInterface $logger,
	) {
	}

	/**
	 * Gleicht den Kalendertermin eines einzelnen Tickets mit dessen
	 * aktuellem Stand ab (anlegen/aktualisieren/verschieben/löschen, je
	 * nachdem). `$ticket` ist ein rohes Redmine-Issue-Array (wie von
	 * `/issues.json` bzw. `/issues/{id}.json` geliefert) — Aufrufer haben
	 * das ohnehin meist schon frisch geladen, ein erneuter Redmine-Aufruf
	 * hier wäre unnötig.
	 */
	public function synchronisieren(array $ticket): void {
		$ticketId = (int)($ticket['id'] ?? 0);
		if ($ticketId <= 0) {
			return;
		}

		$zustaendiger = $this->ticketZustaendigkeit->fuerTicket($ticketId);
		$faelligkeit = (string)($ticket['due_date'] ?? '');

		if ($zustaendiger === null || $faelligkeit === '' || $this->istErledigt($ticket)) {
			$this->entfernen($ticketId);
			return;
		}

		$backend = $this->calDavBackend();
		if ($backend === null) {
			return;
		}

		try {
			$kalenderId = $this->kalenderIdFuerBenutzer($backend, $zustaendiger);
			if ($kalenderId === null) {
				return;
			}

			$objektUri = 'redmine-ticket-' . $ticketId . '.ics';
			$icsDaten = $this->icsBauen($ticketId, trim((string)($ticket['subject'] ?? '')), $faelligkeit);
			$bestehend = $this->bestehenderEintrag($ticketId);

			if ($bestehend !== null && (int)$bestehend['kalender_id'] === $kalenderId) {
				// Gleicher Mitarbeiter/Kalender wie beim letzten Abgleich —
				// bestehenden Termin einfach überschreiben.
				$backend->updateCalendarObject($kalenderId, $bestehend['objekt_uri'], $icsDaten);
			} else {
				if ($bestehend !== null) {
					// Zuständigkeit hat seit dem letzten Abgleich gewechselt —
					// alten Termin beim vorherigen Mitarbeiter entfernen,
					// bevor der neue angelegt wird.
					$this->terminLoeschenOhneFehler($backend, (int)$bestehend['kalender_id'], (string)$bestehend['objekt_uri']);
				}
				$backend->createCalendarObject($kalenderId, $objektUri, $icsDaten);
			}

			$this->eintragSpeichern($ticketId, $zustaendiger, $kalenderId, $objektUri);
		} catch (\Throwable $e) {
			$this->logger->warning(
				'Redmine Bridge: Kalenderabgleich für Ticket #' . $ticketId . ' fehlgeschlagen.',
				['exception' => $e],
			);
		}
	}

	/**
	 * Entfernt einen vorhandenen Kalendertermin wieder (Ticket ohne
	 * zuständigen Mitarbeiter/Fälligkeitsdatum, erledigt, oder in Redmine
	 * gar nicht mehr vorhanden). Ohne vorhandenen Eintrag ein No-Op.
	 */
	public function entfernen(int $ticketId): void {
		$bestehend = $this->bestehenderEintrag($ticketId);
		if ($bestehend === null) {
			return;
		}

		$backend = $this->calDavBackend();
		if ($backend !== null) {
			$this->terminLoeschenOhneFehler($backend, (int)$bestehend['kalender_id'], (string)$bestehend['objekt_uri']);
		}

		$this->eintragLoeschen($ticketId);
	}

	/**
	 * Alle Ticket-IDs, für die aktuell ein Kalendertermin nachverfolgt
	 * wird — Sicherheitsnetz für den Hintergrundabgleich (KalenderAbgleich),
	 * damit auch ein Termin aufgeräumt wird, dessen Ticket zwischenzeitlich
	 * auf einem anderen Weg als über diese App verändert wurde.
	 *
	 * @return list<int>
	 */
	public function alleVerfolgtenTicketIds(): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('ticket_id')->from(self::TABELLE);

		return array_map(
			static fn (array $zeile): int => (int)$zeile['ticket_id'],
			$qb->executeQuery()->fetchAll(),
		);
	}

	/**
	 * Ob der Ticket-Status als "erledigt" gilt — Redmines
	 * Issue-Antwort liefert das nicht direkt mit (nur Status-ID/-Name),
	 * die Zuordnung kommt separat über `/issue_statuses.json`. Pro
	 * Objektinstanz (= pro Anfrage bzw. pro Durchlauf des
	 * Hintergrundabgleichs) nur einmal abgefragt und zwischengespeichert.
	 */
	private function istErledigt(array $ticket): bool {
		$statusId = $ticket['status']['id'] ?? null;
		if ($statusId === null) {
			return false;
		}

		if ($this->geschlosseneStatusIdsZwischenspeicher === null) {
			$this->geschlosseneStatusIdsZwischenspeicher = [];
			try {
				$antwort = $this->redmine->anfrage('GET', '/issue_statuses.json');
				foreach ($antwort['issue_statuses'] ?? [] as $s) {
					if ($s['is_closed'] ?? false) {
						$this->geschlosseneStatusIdsZwischenspeicher[(int)$s['id']] = true;
					}
				}
			} catch (\Throwable $e) {
				// Bei Fehlschlag lieber "nicht erledigt" annehmen — ein
				// bestehender Termin bleibt dann einmal zu viel stehen,
				// statt einen eigentlich noch offenen fälschlich zu löschen.
				$this->logger->warning('Redmine Bridge: Status-Liste für Kalenderabgleich nicht abrufbar.', ['exception' => $e]);
			}
		}

		return isset($this->geschlosseneStatusIdsZwischenspeicher[(int)$statusId]);
	}

	/**
	 * Löst das interne CalDAV-Backend der DAV-App lazy auf (nicht per
	 * Konstruktor-Injektion — schlägt die Auflösung einmal fehl, soll das
	 * nur den Kalenderabgleich lahmlegen, nicht die gesamte App, siehe
	 * Klassenkommentar oben). Ein Fehlschlag wird gemerkt, damit nicht bei
	 * jedem einzelnen Ticket in einem Abgleichlauf erneut derselbe teure
	 * Auflösungsversuch (und dieselbe Logmeldung) wiederholt wird.
	 */
	private function calDavBackend(): ?object {
		if ($this->calDavBackendZwischenspeicher !== null) {
			return $this->calDavBackendZwischenspeicher;
		}
		if ($this->calDavBackendFehlgeschlagen) {
			return null;
		}

		try {
			$this->calDavBackendZwischenspeicher = Server::get(\OCA\DAV\CalDAV\CalDavBackend::class);

			return $this->calDavBackendZwischenspeicher;
		} catch (\Throwable $e) {
			$this->calDavBackendFehlgeschlagen = true;
			$this->logger->warning(
				'Redmine Bridge: CalDAV-Backend (Kalender-App) nicht verfügbar — Fälligkeitsdatum-Abgleich übersprungen.',
				['exception' => $e],
			);

			return null;
		}
	}

	/**
	 * Findet den Kalender "Redmine Tickets" im persönlichen Kalenderbestand
	 * eines Nextcloud-Kontos, legt ihn beim ersten Mal an.
	 */
	private function kalenderIdFuerBenutzer(object $backend, string $nextcloudUserId): ?int {
		$principal = 'principals/users/' . $nextcloudUserId;

		try {
			foreach ($backend->getCalendarsForUser($principal) as $kalender) {
				if (($kalender['uri'] ?? '') === self::KALENDER_URI) {
					return (int)$kalender['id'];
				}
			}

			return (int)$backend->createCalendar($principal, self::KALENDER_URI, [
				'{DAV:}displayname' => self::KALENDER_NAME,
			]);
		} catch (\Throwable $e) {
			$this->logger->warning(
				'Redmine Bridge: Kalender "Redmine Tickets" für ' . $nextcloudUserId . ' konnte nicht ermittelt/angelegt werden.',
				['exception' => $e],
			);

			return null;
		}
	}

	private function terminLoeschenOhneFehler(object $backend, int $kalenderId, string $objektUri): void {
		try {
			$backend->deleteCalendarObject($kalenderId, $objektUri);
		} catch (\Throwable) {
			// Termin oder ganzer Kalender bereits weg (z. B. vom Mitarbeiter
			// selbst gelöscht) — unkritisch, der Verfolgungseintrag wird
			// gleich ohnehin entfernt/überschrieben.
		}
	}

	/**
	 * Baut die ICS-Nutzlast für einen ganztägigen Termin am
	 * Fälligkeitsdatum. Ganztägig nach iCalendar-Konvention: DTEND ist der
	 * FOLGETag (exklusives Ende), nicht derselbe Tag.
	 */
	private function icsBauen(int $ticketId, string $betreff, string $faelligkeit): string {
		$start = new \DateTime($faelligkeit);
		$ende = (clone $start)->modify('+1 day');
		$jetzt = new \DateTime('now', new \DateTimeZone('UTC'));

		$zeilen = [
			'BEGIN:VCALENDAR',
			'VERSION:2.0',
			'PRODID:-//Hammer IT Solutions//Redmine Bridge//DE',
			'CALSCALE:GREGORIAN',
			'BEGIN:VEVENT',
			'UID:redmine-ticket-' . $ticketId . '@redmine-bridge',
			'DTSTAMP:' . $jetzt->format('Ymd\THis\Z'),
			'DTSTART;VALUE=DATE:' . $start->format('Ymd'),
			'DTEND;VALUE=DATE:' . $ende->format('Ymd'),
			'SUMMARY:' . $this->icsEscape('#' . $ticketId . ' ' . $betreff),
		];

		// Verlinkung direkt in die Redmine-Bridge-Oberfläche (Ticket-Detail
		// dieser App) — zusätzlich zum Redmine-Link, falls Mathias direkt
		// aus dem Kalender heraus im gewohnten Ticket-Formular statt in
		// Redmine selbst landen will. `URL:` ist die dafür vorgesehene
		// ICS-Eigenschaft (ein eigener, von vielen Kalender-Apps als Link/
		// Knopf angezeigter Verweis); zusätzlich in der Beschreibung, da
		// nicht jeder Kalender-Client die URL-Eigenschaft anzeigt.
		$bridgeUrl = $this->urlGenerator->linkToRouteAbsolute(Application::APP_ID . '.page.index') . '#/tickets/' . $ticketId;
		$beschreibungZeilen = ['Redmine Bridge: ' . $bridgeUrl];

		$basisUrl = $this->config->getAppValue(Application::APP_ID, Application::CONF_BASIS_URL, '');
		if ($basisUrl !== '') {
			$beschreibungZeilen[] = 'Redmine: ' . rtrim($basisUrl, '/') . '/issues/' . $ticketId;
		}

		$zeilen[] = 'URL:' . $bridgeUrl;
		$zeilen[] = 'DESCRIPTION:' . $this->icsEscape(implode("\n", $beschreibungZeilen));

		$zeilen[] = 'END:VEVENT';
		$zeilen[] = 'END:VCALENDAR';

		return implode("\r\n", $zeilen) . "\r\n";
	}

	private function icsEscape(string $text): string {
		return str_replace(["\\", ';', ',', "\n"], ['\\\\', '\\;', '\\,', '\\n'], $text);
	}

	/**
	 * @return array{nextcloud_user_id:string,kalender_id:int,objekt_uri:string}|null
	 */
	private function bestehenderEintrag(int $ticketId): ?array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('nextcloud_user_id', 'kalender_id', 'objekt_uri')
			->from(self::TABELLE)
			->where($qb->expr()->eq('ticket_id', $qb->createNamedParameter($ticketId, IQueryBuilder::PARAM_INT)));

		$zeile = $qb->executeQuery()->fetch();
		if ($zeile === false) {
			return null;
		}

		return [
			'nextcloud_user_id' => (string)$zeile['nextcloud_user_id'],
			'kalender_id' => (int)$zeile['kalender_id'],
			'objekt_uri' => (string)$zeile['objekt_uri'],
		];
	}

	private function eintragSpeichern(int $ticketId, string $nextcloudUserId, int $kalenderId, string $objektUri): void {
		$jetzt = new \DateTime();

		if ($this->bestehenderEintrag($ticketId) !== null) {
			$qb = $this->db->getQueryBuilder();
			$qb->update(self::TABELLE)
				->set('nextcloud_user_id', $qb->createNamedParameter($nextcloudUserId))
				->set('kalender_id', $qb->createNamedParameter($kalenderId, IQueryBuilder::PARAM_INT))
				->set('objekt_uri', $qb->createNamedParameter($objektUri))
				->set('geaendert_am', $qb->createNamedParameter($jetzt, IQueryBuilder::PARAM_DATE))
				->where($qb->expr()->eq('ticket_id', $qb->createNamedParameter($ticketId, IQueryBuilder::PARAM_INT)));
			$qb->executeStatement();

			return;
		}

		$qb = $this->db->getQueryBuilder();
		$qb->insert(self::TABELLE)
			->setValue('ticket_id', $qb->createNamedParameter($ticketId, IQueryBuilder::PARAM_INT))
			->setValue('nextcloud_user_id', $qb->createNamedParameter($nextcloudUserId))
			->setValue('kalender_id', $qb->createNamedParameter($kalenderId, IQueryBuilder::PARAM_INT))
			->setValue('objekt_uri', $qb->createNamedParameter($objektUri))
			->setValue('geaendert_am', $qb->createNamedParameter($jetzt, IQueryBuilder::PARAM_DATE));
		$qb->executeStatement();
	}

	private function eintragLoeschen(int $ticketId): void {
		$qb = $this->db->getQueryBuilder();
		$qb->delete(self::TABELLE)
			->where($qb->expr()->eq('ticket_id', $qb->createNamedParameter($ticketId, IQueryBuilder::PARAM_INT)));
		$qb->executeStatement();
	}
}
