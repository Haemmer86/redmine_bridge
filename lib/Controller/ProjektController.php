<?php

declare(strict_types=1);

namespace OCA\RedmineBridge\Controller;

use OCA\RedmineBridge\Service\ProjektFarbeService;
use OCA\RedmineBridge\Service\RedmineClient;
use OCA\RedmineBridge\Service\TicketListeService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\FrontpageRoute;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\DataDisplayResponse;
use OCP\AppFramework\Http\DataResponse;
use OCP\IConfig;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Projekte: Gantt-Übersicht, Mitglieder, Anlegen, Abschließen, Icons.
 *
 * Teil der JSON-API für die Vue-Oberfläche; gemeinsame Schutzlogik
 * (Mitarbeiter-Beschränkung, Fehlerverpackung) steht in {@see BasisController}.
 */
class ProjektController extends BasisController {

	public function __construct(
		IRequest $request,
		IUserSession $userSession,
		IGroupManager $groupManager,
		IConfig $config,
		private readonly RedmineClient $redmine,
		private readonly ProjektFarbeService $projektFarbe,
		private readonly TicketListeService $ticketListe,
	) {
		parent::__construct($request, $userSession, $groupManager, $config);
	}

	/**
	 * Zeitspanne je Projekt — Grundlage für die Gantt-Ansicht auf der
	 * Projekte-Seite.
	 *
	 * Redmine kennt kein Start-/Enddatum auf Projektebene, nur je Ticket.
	 * Die Zeitspanne eines Projekts wird deshalb aus dem frühesten
	 * Startdatum und dem spätesten Fälligkeitsdatum seiner Tickets
	 * abgeleitet. Projekte ganz ohne datierte Tickets liefern keinen
	 * Balken und tauchen in der Ansicht nicht auf.
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[FrontpageRoute(verb: 'GET', url: '/api/projekte/gantt')]
	public function projekteGantt(): DataResponse {
		return $this->geschuetzterAufruf(function () {
			$projekte = $this->redmine->anfrage('GET', '/projects.json', ['limit' => 100]);
			$projektNamen = [];
			foreach ($projekte['projects'] ?? [] as $p) {
				$projektNamen[$p['id']] = $p['name'];
			}

			$statusAntwort = $this->redmine->anfrage('GET', '/issue_statuses.json');
			$geschlosseneStatusIds = [];
			foreach ($statusAntwort['issue_statuses'] ?? [] as $s) {
				if ($s['is_closed'] ?? false) {
					$geschlosseneStatusIds[$s['id']] = true;
				}
			}

			// Alle Tickets holen (offen wie erledigt — für den Zeitplan
			// zählt die geplante Spanne, nicht der aktuelle Status),
			// seitenweise bis zu einer Obergrenze, die für eine
			// Handwerks-/KMU-Redmine-Instanz reichlich ist.
			$tickets = [];
			$maxSeiten = 5;
			for ($seite = 0; $seite < $maxSeiten; $seite++) {
				$antwort = $this->redmine->anfrage('GET', '/issues.json', [
					'status_id' => '*',
					'limit' => 100,
					'offset' => $seite * 100,
				]);
				$stapel = $antwort['issues'] ?? [];
				array_push($tickets, ...$stapel);
				if (count($stapel) < 100 || count($tickets) >= ($antwort['total_count'] ?? 0)) {
					break;
				}
			}

			$zeitspannen = [];
			foreach ($tickets as $ticket) {
				$projektId = $ticket['project']['id'] ?? null;
				$start = $ticket['start_date'] ?? null;
				if ($projektId === null || $start === null) {
					continue;
				}
				$ende = $ticket['due_date'] ?? $start;
				if ($ende < $start) {
					$ende = $start;
				}

				if (!isset($zeitspannen[$projektId])) {
					$zeitspannen[$projektId] = [
						'projektId' => $projektId,
						'name' => $projektNamen[$projektId] ?? $ticket['project']['name'] ?? ('#' . $projektId),
						'start' => $start,
						'ende' => $ende,
						'ticketAnzahl' => 0,
						'erledigtAnzahl' => 0,
						'tickets' => [],
					];
				}
				if ($start < $zeitspannen[$projektId]['start']) {
					$zeitspannen[$projektId]['start'] = $start;
				}
				if ($ende > $zeitspannen[$projektId]['ende']) {
					$zeitspannen[$projektId]['ende'] = $ende;
				}
				$zeitspannen[$projektId]['ticketAnzahl']++;
				$erledigt = isset($geschlosseneStatusIds[$ticket['status']['id'] ?? null]);
				if ($erledigt) {
					$zeitspannen[$projektId]['erledigtAnzahl']++;
				}
				$zeitspannen[$projektId]['tickets'][] = [
					'id' => $ticket['id'] ?? null,
					'betreff' => $ticket['subject'] ?? '',
					'start' => $start,
					'ende' => $ende,
					'status' => $ticket['status']['name'] ?? '',
					'erledigt' => $erledigt,
				];
			}

			$ergebnis = array_values($zeitspannen);
			usort($ergebnis, static fn ($a, $b) => $a['start'] <=> $b['start']);
			foreach ($ergebnis as &$projekt) {
				usort($projekt['tickets'], static fn ($a, $b) => $a['start'] <=> $b['start']);
			}
			unset($projekt);

			return ['projekte' => $ergebnis];
		});
	}

	/**
	 * Mitglieder eines Projekts — die Quelle für die Bearbeiter-Auswahl.
	 *
	 * Bewusst je Projekt abgefragt statt eine globale Benutzerliste zu
	 * laden: `/users.json` verlangt Administratorrechte auf vielen
	 * Redmine-Instanzen, `/projects/{id}/memberships.json` dagegen nicht —
	 * jeder, der das Projekt sieht, darf auch dessen Mitglieder sehen.
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[FrontpageRoute(verb: 'GET', url: '/api/projekte/{projektId}/mitglieder')]
	public function mitglieder(int $projektId): DataResponse {
		return $this->geschuetzterAufruf(function () use ($projektId) {
			$antwort = $this->redmine->anfrage('GET', "/projects/{$projektId}/memberships.json", ['limit' => 100]);
			$namen = [];
			foreach ($antwort['memberships'] ?? [] as $mitgliedschaft) {
				if (isset($mitgliedschaft['user'])) {
					$namen[$mitgliedschaft['user']['id']] = $mitgliedschaft['user']['name'];
				}
			}

			// Redmine dedupliziert nicht: dieselbe Person kann über mehrere
			// Rollen mehrfach auftauchen. Über die ID als Schlüssel entsteht
			// das von selbst.
			$ergebnis = [];
			foreach ($namen as $id => $name) {
				$ergebnis[] = ['id' => $id, 'name' => $name];
			}

			return ['mitglieder' => $ergebnis];
		});
	}

	/**
	 * Legt ein neues Projekt in Redmine an.
	 *
	 * `identifier` ist bei Redmine der URL-Baustein des Projekts — einmal
	 * vergeben, praktisch nicht mehr änderbar. Redmine selbst erzwingt das
	 * Muster (Kleinbuchstaben, Ziffern, Bindestriche); die Vorprüfung hier
	 * verhindert nur die häufigsten Eingabefehler (Leerfeld), das genaue
	 * Muster überlässt die App bewusst Redmines eigener Fehlermeldung.
	 */
	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'POST', url: '/api/projekte')]
	public function projektErstellen(array $projekt): DataResponse {
		return $this->geschuetzterAufruf(function () use ($projekt) {
			$erlaubteFelder = ['name', 'identifier', 'description'];
			$nutzlast = array_intersect_key($projekt, array_flip($erlaubteFelder));

			if (empty(trim((string)($nutzlast['name'] ?? '')))) {
				throw new \RuntimeException('Name ist ein Pflichtfeld.');
			}
			if (empty(trim((string)($nutzlast['identifier'] ?? '')))) {
				throw new \RuntimeException('Kurzname ist ein Pflichtfeld.');
			}

			$antwort = $this->redmine->anfrage('POST', '/projects.json', ['project' => $nutzlast]);

			return ['projekt' => $antwort['project'] ?? null];
		});
	}

	/**
	 * Fügt einem Projekt ein Mitglied mit mindestens einer Rolle hinzu.
	 *
	 * Erst NACH dem Anlegen eines Projekts möglich — Redmines
	 * Mitgliedschafts-Endpunkt hängt an der Projekt-ID, die es vor dem
	 * Anlegen noch nicht gibt.
	 */
	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'POST', url: '/api/projekte/{projektId}/mitglied-hinzufuegen')]
	public function mitgliedHinzufuegen(int $projektId, int $userId, array $rolleIds): DataResponse {
		return $this->geschuetzterAufruf(function () use ($projektId, $userId, $rolleIds) {
			if ($userId <= 0) {
				throw new \RuntimeException('Kein Benutzer ausgewählt.');
			}
			if (empty($rolleIds)) {
				throw new \RuntimeException('Mindestens eine Rolle ist erforderlich.');
			}

			$this->redmine->anfrage('POST', "/projects/{$projektId}/memberships.json", [
				'membership' => ['user_id' => $userId, 'role_ids' => $rolleIds],
			]);

			return ['ok' => true];
		});
	}

	/**
	 * Alle offenen Tickets eines Projekts, seitenweise gegen Redmine
	 * abgefragt (Sicherheitsgrenze 20 Seiten à 100 = 2000 Tickets, damit ein
	 * Redmine-Antwortfehler nicht zu einer Endlosschleife führen kann).
	 *
	 * @return list<array<string,mixed>>
	 */
	private function offeneTicketsProjekt(int $projektId): array {
		$issues = [];
		$offset = 0;
		do {
			$antwort = $this->redmine->anfrage('GET', '/issues.json', [
				'project_id' => $projektId,
				'status_id' => 'open',
				'limit' => 100,
				'offset' => $offset,
			]);
			$stapel = $antwort['issues'] ?? [];
			array_push($issues, ...$stapel);
			$offset += 100;
			$gesamt = (int)($antwort['total_count'] ?? count($issues));
		} while (count($stapel) === 100 && $offset < 2000 && $offset < $gesamt);

		return $issues;
	}

	/**
	 * Vorschau vor dem Abschließen eines ganzen Projekts: Anzahl noch
	 * offener Tickets sowie die Liste der Tickets, die zwar
	 * rechnungspflichtig sind, aber noch nicht abgerechnet wurden — Grundlage
	 * für die Warnung im Bestätigungsdialog. Ändert noch nichts.
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[FrontpageRoute(verb: 'GET', url: '/api/projekte/{projektId}/abschliessen-vorschau')]
	public function projektAbschliessenVorschau(int $projektId): DataResponse {
		return $this->geschuetzterAufruf(function () use ($projektId) {
			$offeneTickets = $this->offeneTicketsProjekt($projektId);
			$unfakturiert = $this->ticketListe->unfakturierteTickets((string)$projektId);

			return [
				'offenAnzahl' => count($offeneTickets),
				'unfakturierteTickets' => array_map(
					static fn (array $t): array => [
						'id' => $t['id'],
						'subject' => $t['subject'] ?? '',
					],
					$unfakturiert['tickets'],
				),
			];
		});
	}

	/**
	 * Schließt ein ganzes Projekt ab: setzt zuerst alle noch offenen Tickets
	 * des Projekts auf den übergebenen "Erledigt"-Status (derselbe, den auch
	 * die 100-%-Automatik in der Ticketliste verwendet — die Oberfläche
	 * ermittelt ihn einmal aus der Statusliste und schickt ihn mit, damit
	 * diese Logik nicht doppelt gepflegt werden muss), danach den
	 * Redmine-Projektstatus selbst auf "Abgeschlossen".
	 *
	 * Ein einzelnes fehlschlagendes Ticket bricht die Aktion nicht ab — die
	 * übrigen Tickets und der Projektabschluss sollen davon nicht betroffen
	 * sein; Fehlschläge kommen als eigene Liste in der Antwort zurück, damit
	 * die Oberfläche sie anzeigen kann.
	 *
	 * Redmines Projektstatus-Werte sind fest codierte Konstanten (nicht wie
	 * Ticket-Status je Instanz konfigurierbar): 1 = aktiv, 5 = abgeschlossen,
	 * 9 = archiviert. Das Setzen über die REST-API verlangt auf Redmine-Seite
	 * das Recht "Projekte schließen" für den verwendeten API-Schlüssel.
	 */
	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'PUT', url: '/api/projekte/{projektId}/abschliessen')]
	public function projektAbschliessen(int $projektId, int $erledigtStatusId = 0): DataResponse {
		if ($erledigtStatusId <= 0) {
			return new DataResponse(['fehler' => 'Kein "Erledigt"-Status gefunden — bitte Ticket-Status in Redmine prüfen.'], Http::STATUS_BAD_REQUEST);
		}

		return $this->geschuetzterAufruf(function () use ($projektId, $erledigtStatusId) {
			$offeneTickets = $this->offeneTicketsProjekt($projektId);

			$aktualisiert = [];
			$fehlgeschlagen = [];
			foreach ($offeneTickets as $ticket) {
				$ticketId = (int)($ticket['id'] ?? 0);
				try {
					$this->redmine->anfrage('PUT', "/issues/{$ticketId}.json", [
						'issue' => ['status_id' => $erledigtStatusId, 'done_ratio' => 100],
					]);
					$aktualisiert[] = $ticketId;
				} catch (\Throwable $e) {
					$fehlgeschlagen[] = ['id' => $ticketId, 'fehler' => $e->getMessage()];
				}
			}

			$projektFehler = null;
			try {
				$this->redmine->anfrage('PUT', "/projects/{$projektId}.json", [
					'project' => ['status' => 5],
				]);
				$projektGeschlossen = true;
			} catch (\Throwable $e) {
				$projektGeschlossen = false;
				$projektFehler = $e->getMessage();
			}

			return [
				'ticketsAktualisiert' => $aktualisiert,
				'ticketsFehlgeschlagen' => $fehlgeschlagen,
				'projektGeschlossen' => $projektGeschlossen,
				'projektFehler' => $projektFehler,
			];
		});
	}

	/**
	 * Rundes Farb-Icon für ein Projekt (siehe {@see ProjektFarbeService}) —
	 * wird vom Dashboard-Widget als Ticket-Icon verwendet. Der Projektname
	 * kommt als Abfrageparameter mit, statt ihn hier erneut aus Redmine zu
	 * laden — der Aufrufer (z. B. RedmineWidget) hat ihn ohnehin schon zur
	 * Hand.
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[FrontpageRoute(verb: 'GET', url: '/api/icon/projekt/{projektId}')]
	public function projektIcon(int $projektId, string $name = ''): DataDisplayResponse {
		$antwort = new DataDisplayResponse(
			$this->projektFarbe->iconSvg($projektId, $name),
			Http::STATUS_OK,
			['Content-Type' => 'image/svg+xml'],
		);
		// Farbe/Initialen hängen nur an Projekt-ID und -Name, ändern sich
		// praktisch nie — beliebig lange cachebar.
		$antwort->cacheFor(60 * 60 * 24 * 30, false, true);

		return $antwort;
	}
}
