<?php

declare(strict_types=1);

namespace OCA\RedmineBridge\Controller;

use OCA\RedmineBridge\AppInfo\Application;
use OCA\RedmineBridge\Service\AblageService;
use OCA\RedmineBridge\Service\ProjektFarbeService;
use OCA\RedmineBridge\Service\OdooClient;
use OCA\RedmineBridge\Service\RedmineClient;
use OCA\RedmineBridge\Service\TagService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\FrontpageRoute;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\DataDisplayResponse;
use OCP\AppFramework\Http\DataResponse;
use OCP\Files\File;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * JSON-API für die Vue-Oberfläche.
 *
 * Reine Durchreiche zu Redmine — es gibt keinen eigenen Datenbestand.
 * Lese-Endpunkte sind ohne CSRF-Prüfung erreichbar (unverändert wie ein
 * normaler Seitenaufruf), Schreib-Endpunkte (PUT/POST) verlangen den
 * `requesttoken`-Kopfeintrag, den die Nextcloud-Oberfläche automatisch
 * mitschickt.
 */
class ApiController extends Controller {

	public function __construct(
		IRequest $request,
		private readonly RedmineClient $redmine,
		private readonly AblageService $ablage,
		private readonly TagService $tags,
		private readonly IUserSession $userSession,
		private readonly ProjektFarbeService $projektFarbe,
		private readonly OdooClient $odoo,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	private function benutzerId(): string {
		return $this->userSession->getUser()?->getUID() ?? '';
	}

	/**
	 * Verpackt eine Redmine-Anfrage einheitlich als DataResponse.
	 *
	 * Redmine antwortet bei Validierungsfehlern mit HTTP 422 und einer
	 * `errors`-Liste im Rumpf — die geht in der generischen
	 * RuntimeException von RedmineClient sonst verloren. Deshalb hier
	 * gezielt behandelt, statt alles unter "Anfrage fehlgeschlagen" zu
	 * verstecken.
	 */
	private function geschuetzterAufruf(callable $fn): DataResponse {
		try {
			return new DataResponse($fn());
		} catch (\Throwable $e) {
			return new DataResponse(['fehler' => $e->getMessage()], Http::STATUS_BAD_GATEWAY);
		}
	}

	// ─── Lesen ────────────────────────────────────────────────────────────

	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[FrontpageRoute(verb: 'GET', url: '/api/verbindung')]
	public function verbindung(): DataResponse {
		if (!$this->redmine->konfiguriert()) {
			return new DataResponse(['ok' => false, 'fehler' => 'Nicht konfiguriert.']);
		}

		return new DataResponse($this->redmine->verbunden());
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[FrontpageRoute(verb: 'GET', url: '/api/tickets')]
	public function tickets(string $status = 'open', string $projekt = '', int $seite = 1): DataResponse {
		return $this->geschuetzterAufruf(function () use ($status, $projekt, $seite) {
			$abfrage = [
				'status_id' => $status,
				'limit' => 50,
				'offset' => ($seite - 1) * 50,
				'sort' => 'updated_on:desc',
			];
			if ($projekt !== '') {
				$abfrage['project_id'] = $projekt;
			}

			$antwort = $this->redmine->anfrage('GET', '/issues.json', $abfrage);

			return [
				'tickets' => $antwort['issues'] ?? [],
				'gesamt' => $antwort['total_count'] ?? 0,
				'seite' => $seite,
			];
		});
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[FrontpageRoute(verb: 'GET', url: '/api/tickets/{id}')]
	public function ticket(int $id): DataResponse {
		return $this->geschuetzterAufruf(function () use ($id) {
			$antwort = $this->redmine->anfrage('GET', "/issues/{$id}.json", [
				'include' => 'attachments,journals',
			]);
			$ticket = $antwort['issue'] ?? null;
			if ($ticket === null) {
				throw new \RuntimeException('Ticket nicht gefunden.');
			}

			$ablage = $this->ablageInfo($ticket);

			return ['ticket' => $ticket] + $ablage;
		});
	}

	/**
	 * Ordnerpfad und Dateiliste eines Tickets — als eigene Methode, damit
	 * ticket() und die spätere Aktualisierung denselben Code nutzen.
	 *
	 * @return array{ordnerPfad:?string,ordnerFehler:?string,dateien:list<array>}
	 */
	private function ablageInfo(array $ticket): array {
		try {
			$ordner = $this->ablage->ordnerFuerTicket($ticket, $this->benutzerId());
			$pfad = preg_replace('#^/[^/]+/files/#', '', $ordner->getPath()) ?? $ordner->getPath();

			$dateien = [];
			foreach ($this->ablage->dateienDesTickets($ticket, $this->benutzerId()) as $datei) {
				$eintrag = [
					'id' => $datei->getId(),
					'name' => $datei->getName(),
					'groesse' => $datei->getSize(),
					'mime' => $datei->getMimeType(),
					'geaendert' => $datei->getMTime(),
					'url' => null,
					'eml' => null,
					'notiz' => null,
					'tags' => [],
				];
				$nameKlein = strtolower($eintrag['name']);

				// .url-Dateien sind unsere eigenen Verweise (z. B. zu
				// Paperless-ngx) — Zieladresse aus dem Inhalt lesen, damit
				// die Oberfläche daraus einen klickbaren Verweis statt
				// einer Bildvorschau macht.
				if (str_ends_with($nameKlein, '.url')) {
					try {
						if (preg_match('/^URL=(.+)$/mi', $datei->getContent(), $treffer) === 1) {
							$eintrag['url'] = trim($treffer[1]);
						}
					} catch (\Throwable) {
						// Inhalt nicht lesbar — bleibt einfach eine normale Datei
					}
				} elseif (str_ends_with($nameKlein, '.eml')) {
					// Nur die ersten Bytes lesen — E-Mails können durch
					// Anhänge groß werden, die Kopfzeilen stehen aber immer
					// ganz am Anfang, lange vor jedem Dateianhang.
					try {
						$eintrag['eml'] = $this->emlKopfzeilenLesen($datei);
					} catch (\Throwable) {
						// Kein lesbarer E-Mail-Kopf — bleibt eine normale Datei
					}
				} elseif (str_ends_with($nameKlein, '.txt')) {
					try {
						$roh = $datei->getContent();
						if (str_starts_with(ltrim($roh), 'REDMINE-BRUECKE-NOTIZ')) {
							$eintrag['notiz'] = $this->notizInhaltParsen($roh);
						}
					} catch (\Throwable) {
						// Nicht lesbar oder keine unserer Notizen — bleibt
						// eine normale Textdatei
					}
				}

				try {
					$eintrag['tags'] = $this->tags->tagsFuerDatei($datei->getId());
				} catch (\Throwable) {
					// Schlagwort-Dienst nicht verfügbar — Datei bleibt
					// trotzdem sichtbar, nur ohne Schlagworte.
				}

				$dateien[] = $eintrag;
			}

			return ['ordnerPfad' => $pfad, 'ordnerFehler' => null, 'dateien' => $dateien];
		} catch (\Throwable $e) {
			return ['ordnerPfad' => null, 'ordnerFehler' => $e->getMessage(), 'dateien' => []];
		}
	}

	/**
	 * Liest Von/Betreff/Datum aus einer `.eml`-Datei.
	 *
	 * Bewusst nur die ersten 8 KB — die Kopfzeilen einer E-Mail stehen immer
	 * ganz am Anfang, weit vor dem eigentlichen Inhalt und erst recht vor
	 * etwaigen Anhängen. `getContent()` würde bei einer E-Mail mit großem
	 * Anhang unnötig viel einlesen, nur um drei Kopfzeilen zu finden.
	 *
	 * Erkennt keine über mehrere Zeilen umgebrochenen Kopfzeilen (nach
	 * RFC 2822 technisch zulässig) — für eine Kurzanzeige in der Liste
	 * reicht der Regelfall (eine Zeile je Kopf) aus.
	 *
	 * @return array{von:?string,betreff:?string,datum:?string}|null
	 */
	private function emlKopfzeilenLesen(File $datei): ?array {
		$handle = $datei->fopen('r');
		if ($handle === false) {
			return null;
		}
		$kopf = fread($handle, 8192);
		fclose($handle);
		if ($kopf === false) {
			return null;
		}

		$von = null;
		$betreff = null;
		$datum = null;
		if (preg_match('/^From:\s*(.+)$/mi', $kopf, $t) === 1) {
			$von = $this->mimeKopfDekodieren(trim($t[1]));
		}
		if (preg_match('/^Subject:\s*(.+)$/mi', $kopf, $t) === 1) {
			$betreff = $this->mimeKopfDekodieren(trim($t[1]));
		}
		if (preg_match('/^Date:\s*(.+)$/mi', $kopf, $t) === 1) {
			$datum = trim($t[1]);
		}

		if ($von === null && $betreff === null) {
			return null;
		}

		return ['von' => $von, 'betreff' => $betreff, 'datum' => $datum, 'datumIso' => $this->datumIsoAus($datum)];
	}

	/**
	 * Wandelt eine beliebig formatierte Datumsangabe (z. B. den
	 * RFC-2822-Kopf einer E-Mail) in ein sortierbares ISO-Format um —
	 * fürs Einsortieren im chronologischen Gesprächsverlauf. Schlägt das
	 * Parsen fehl, bleibt der Eintrag einfach unsortiert am Ende.
	 */
	private function datumIsoAus(?string $datum): ?string {
		if ($datum === null || $datum === '') {
			return null;
		}
		try {
			return (new \DateTimeImmutable($datum))->format(\DATE_ATOM);
		} catch (\Throwable) {
			return null;
		}
	}

	/**
	 * E-Mail-Kopfzeilen sind bei nicht-lateinischen Zeichen (Umlaute!) nach
	 * RFC 2047 kodiert, z. B. `=?UTF-8?B?...?=` — ohne Dekodierung stünde in
	 * der Liste kryptischer Kauderwelsch statt "Müller".
	 */
	private function mimeKopfDekodieren(string $wert): string {
		$dekodiert = @mb_decode_mimeheader($wert);

		return $dekodiert !== false && $dekodiert !== '' ? $dekodiert : $wert;
	}

	/**
	 * Zerlegt den Inhalt einer eigenen Notiz-Textdatei wieder in ihre
	 * Bestandteile — das Gegenstück zu AblageService::notizAblegen().
	 *
	 * @return array{art:?string,datum:?string,ansprechpartner:?string,text:string}
	 */
	private function notizInhaltParsen(string $inhalt): array {
		$zeilen = explode("\n", str_replace("\r\n", "\n", $inhalt));
		$ergebnis = ['art' => null, 'datum' => null, 'ansprechpartner' => null, 'von' => null, 'betreff' => null, 'text' => ''];
		$imTextbereich = false;
		$textZeilen = [];

		foreach ($zeilen as $index => $zeile) {
			if ($index === 0) {
				continue; // die Erkennungszeile "REDMINE-BRUECKE-NOTIZ" selbst
			}
			if ($imTextbereich) {
				$textZeilen[] = $zeile;
				continue;
			}
			if (trim($zeile) === '') {
				$imTextbereich = true;
				continue;
			}
			if (preg_match('/^Art:\s*(.+)$/i', $zeile, $t) === 1) {
				$ergebnis['art'] = trim($t[1]);
			} elseif (preg_match('/^Datum:\s*(.+)$/i', $zeile, $t) === 1) {
				$ergebnis['datum'] = trim($t[1]);
			} elseif (preg_match('/^Ansprechpartner:\s*(.+)$/i', $zeile, $t) === 1) {
				$ergebnis['ansprechpartner'] = trim($t[1]);
			} elseif (preg_match('/^Von:\s*(.+)$/i', $zeile, $t) === 1) {
				$ergebnis['von'] = trim($t[1]);
			} elseif (preg_match('/^Betreff:\s*(.+)$/i', $zeile, $t) === 1) {
				$ergebnis['betreff'] = trim($t[1]);
			}
		}

		$ergebnis['text'] = trim(implode("\n", $textZeilen));
		$ergebnis['datumIso'] = $this->datumIsoAus($ergebnis['datum']);

		return $ergebnis;
	}

	/**
	 * Alles, was die Bearbeitungsmaske für Auswahllisten braucht.
	 *
	 * Getrennt vom Ticket selbst abgefragt, weil sich diese Listen kaum
	 * ändern — die Oberfläche kann sie clientseitig zwischenspeichern,
	 * statt sie bei jedem Ticket neu zu laden.
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[FrontpageRoute(verb: 'GET', url: '/api/formulardaten')]
	public function formulardaten(): DataResponse {
		return $this->geschuetzterAufruf(function () {
			$tracker = $this->redmine->anfrage('GET', '/trackers.json');
			$status = $this->redmine->anfrage('GET', '/issue_statuses.json');
			$prioritaet = $this->redmine->anfrage('GET', '/enumerations/issue_priorities.json');
			$projekte = $this->redmine->anfrage('GET', '/projects.json', ['limit' => 100]);

			// Rollen und Benutzerliste getrennt abgesichert: `/users.json`
			// verlangt auf vielen Redmine-Instanzen Administratorrechte.
			// Schlägt das fehl, sollen Ticket-Formulare trotzdem
			// funktionieren — nur die Mitgliederzuordnung beim
			// Projekt-Anlegen bleibt dann leer, statt die ganze Seite zu
			// blockieren.
			try {
				$rollen = $this->redmine->anfrage('GET', '/roles.json');
			} catch (\Throwable) {
				$rollen = ['roles' => []];
			}
			try {
				$benutzer = $this->redmine->anfrage('GET', '/users.json', ['status' => 1, 'limit' => 100]);
			} catch (\Throwable) {
				$benutzer = ['users' => []];
			}

			return [
				'tracker' => $tracker['trackers'] ?? [],
				'status' => $status['issue_statuses'] ?? [],
				'prioritaet' => $prioritaet['issue_priorities'] ?? [],
				'projekte' => $projekte['projects'] ?? [],
				'rollen' => $rollen['roles'] ?? [],
				'benutzer' => $benutzer['users'] ?? [],
			];
		});
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

	// ─── Schreiben ────────────────────────────────────────────────────────

	/**
	 * Aktualisiert ein Ticket in Redmine.
	 *
	 * Nur die tatsächlich mitgesendeten Felder werden verändert — Redmine
	 * überschreibt bei PUT nur die im Rumpf enthaltenen Eigenschaften,
	 * alles andere bleibt automatisch unangetastet. `notes` ist Redmines
	 * eigenes Feld für einen neuen Kommentar/Journaleintrag — wird nur
	 * dieses eine Feld mitgeschickt, ändert sich am Ticket selbst nichts,
	 * es kommt nur ein neuer Eintrag im Verlauf hinzu.
	 *
	 * @param array<string,mixed> $ticket Felder, die geändert werden sollen
	 */
	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'PUT', url: '/api/tickets/{id}')]
	public function ticketAktualisieren(int $id, array $ticket): DataResponse {
		return $this->geschuetzterAufruf(function () use ($id, $ticket) {
			$erlaubteFelder = [
				'subject', 'description', 'status_id', 'priority_id', 'tracker_id',
				'assigned_to_id', 'start_date', 'due_date', 'done_ratio',
				'estimated_hours', 'custom_fields', 'notes',
			];
			$nutzlast = array_intersect_key($ticket, array_flip($erlaubteFelder));

			// Redmine antwortet auf ein erfolgreiches PUT mit 204 ohne Rumpf —
			// deshalb danach separat neu abfragen, um der Oberfläche den
			// aktuellen Stand zurückzugeben, statt nur "ok" zu melden.
			$this->redmine->anfrage('PUT', "/issues/{$id}.json", ['issue' => $nutzlast]);
			$frisch = $this->redmine->anfrage('GET', "/issues/{$id}.json", ['include' => 'attachments,journals']);

			return ['ticket' => $frisch['issue'] ?? null] + $this->ablageInfo($frisch['issue'] ?? []);
		});
	}

	/**
	 * Liefert Redmines konfigurierte Zeiterfassungs-Aktivitäten (z. B.
	 * "Entwicklung", "Support") — Redmine verlangt bei den meisten
	 * Instanzen eine davon beim Anlegen einer Zeiterfassung.
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[FrontpageRoute(verb: 'GET', url: '/api/zeiterfassung-aktivitaeten')]
	public function zeiterfassungAktivitaeten(): DataResponse {
		return $this->geschuetzterAufruf(function () {
			$antwort = $this->redmine->anfrage('GET', '/enumerations/time_entry_activities.json');
			$aktivitaeten = [];
			foreach ($antwort['time_entry_activities'] ?? [] as $a) {
				$aktivitaeten[] = ['id' => $a['id'], 'name' => $a['name']];
			}

			return ['aktivitaeten' => $aktivitaeten];
		});
	}

	/**
	 * Legt eine neue Zeiterfassung (Ist-Stunden) für ein Ticket in Redmine
	 * an. Nach dem Anlegen wird das Ticket neu abgefragt, damit
	 * `spent_hours` (die von Redmine geführte Summe) sofort aktuell ist.
	 */
	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'POST', url: '/api/tickets/{id}/zeiterfassung')]
	public function zeiterfassungAnlegen(
		int $id,
		float $stunden,
		int $aktivitaetId = 0,
		string $kommentar = '',
		string $datum = '',
	): DataResponse {
		if ($stunden <= 0) {
			return new DataResponse(['fehler' => 'Bitte eine Stundenzahl größer 0 eintragen.'], Http::STATUS_BAD_REQUEST);
		}

		return $this->geschuetzterAufruf(function () use ($id, $stunden, $aktivitaetId, $kommentar, $datum) {
			$nutzlast = [
				'issue_id' => $id,
				'hours' => $stunden,
			];
			if ($aktivitaetId > 0) {
				$nutzlast['activity_id'] = $aktivitaetId;
			}
			if ($kommentar !== '') {
				$nutzlast['comments'] = $kommentar;
			}
			if ($datum !== '') {
				$nutzlast['spent_on'] = $datum;
			}

			$this->redmine->anfrage('POST', '/time_entries.json', ['time_entry' => $nutzlast]);

			$frisch = $this->redmine->anfrage('GET', "/issues/{$id}.json", ['include' => 'attachments,journals']);

			return ['ticket' => $frisch['issue'] ?? null] + $this->ablageInfo($frisch['issue'] ?? []);
		});
	}

	/**
	 * Legt ein neues Ticket in Redmine an.
	 *
	 * Projekt und Betreff sind bei Redmine selbst Pflichtfelder — wird
	 * eines davon vergessen, meldet Redmine einen 422-Validierungsfehler
	 * mit einer eigenen "errors"-Liste. Hier wird stattdessen VORHER
	 * geprüft, um eine klare deutsche Meldung statt der rohen
	 * Redmine-Antwort zu zeigen.
	 */
	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'POST', url: '/api/tickets')]
	public function ticketErstellen(array $ticket): DataResponse {
		return $this->geschuetzterAufruf(function () use ($ticket) {
			$erlaubteFelder = [
				'project_id', 'subject', 'description', 'tracker_id', 'priority_id',
				'assigned_to_id', 'start_date', 'due_date',
			];
			$nutzlast = array_intersect_key($ticket, array_flip($erlaubteFelder));

			if (empty($nutzlast['project_id'])) {
				throw new \RuntimeException('Projekt ist ein Pflichtfeld.');
			}
			if (empty(trim((string)($nutzlast['subject'] ?? '')))) {
				throw new \RuntimeException('Betreff ist ein Pflichtfeld.');
			}

			$antwort = $this->redmine->anfrage('POST', '/issues.json', ['issue' => $nutzlast]);

			return ['ticket' => $antwort['issue'] ?? null];
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
	 * Legt eine hochgeladene Datei im Ticket-Ordner ab.
	 *
	 * Landet NUR in Nextcloud, wie besprochen — kein Redmine-Anhang.
	 */
	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'POST', url: '/api/tickets/{id}/dateien')]
	public function dateiHochladen(int $id): DataResponse {
		$hochgeladen = $this->request->getUploadedFile('datei');
		if ($hochgeladen === null || ($hochgeladen['error'] ?? \UPLOAD_ERR_NO_FILE) !== \UPLOAD_ERR_OK) {
			return new DataResponse(['fehler' => 'Keine Datei erhalten.'], Http::STATUS_BAD_REQUEST);
		}

		return $this->geschuetzterAufruf(function () use ($id, $hochgeladen) {
			$antwort = $this->redmine->anfrage('GET', "/issues/{$id}.json");
			$ticket = $antwort['issue'] ?? null;
			if ($ticket === null) {
				throw new \RuntimeException('Ticket nicht gefunden.');
			}

			$inhalt = fopen($hochgeladen['tmp_name'], 'rb');
			$this->ablage->dateiAblegen($ticket, $this->benutzerId(), $hochgeladen['name'], $inhalt);

			return $this->ablageInfo($ticket);
		});
	}

	/**
	 * Legt einen Anhang einer aus dem Posteingang zugeordneten E-Mail ab,
	 * benannt nach demselben Schema wie der zugehörige
	 * Gesprächsverlauf-Eintrag, siehe
	 * {@see AblageService::mailAnhangAblegen()}.
	 *
	 * Landet NUR in Nextcloud, wie besprochen — kein Redmine-Anhang.
	 */
	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'POST', url: '/api/tickets/{id}/mail-anhang')]
	public function mailAnhangAblegen(int $id, string $betreff = '', string $datum = ''): DataResponse {
		$hochgeladen = $this->request->getUploadedFile('datei');
		if ($hochgeladen === null || ($hochgeladen['error'] ?? \UPLOAD_ERR_NO_FILE) !== \UPLOAD_ERR_OK) {
			return new DataResponse(['fehler' => 'Keine Datei erhalten.'], Http::STATUS_BAD_REQUEST);
		}

		return $this->geschuetzterAufruf(function () use ($id, $betreff, $datum, $hochgeladen) {
			$antwort = $this->redmine->anfrage('GET', "/issues/{$id}.json");
			$ticket = $antwort['issue'] ?? null;
			if ($ticket === null) {
				throw new \RuntimeException('Ticket nicht gefunden.');
			}

			$zeitpunkt = null;
			if ($datum !== '') {
				try {
					$zeitpunkt = new \DateTimeImmutable($datum);
				} catch (\Throwable) {
					$zeitpunkt = null;
				}
			}

			$inhalt = fopen($hochgeladen['tmp_name'], 'rb');
			$this->ablage->mailAnhangAblegen(
				$ticket,
				$this->benutzerId(),
				$betreff !== '' ? $betreff : '(kein Betreff)',
				$zeitpunkt,
				$hochgeladen['name'],
				$inhalt,
			);

			return $this->ablageInfo($ticket);
		});
	}

	/**
	 * Legt einen Verweis (z. B. auf ein Paperless-ngx-Dokument) als kleine
	 * Verknüpfungsdatei im Ticket-Ordner ab — siehe
	 * {@see AblageService::urlAblegen()} für das Format.
	 */
	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'POST', url: '/api/tickets/{id}/urls')]
	public function urlAblegen(int $id, string $bezeichnung, string $url): DataResponse {
		if (!preg_match('#^https?://#i', trim($url))) {
			return new DataResponse(['fehler' => 'Nur http(s)-Adressen sind erlaubt.'], Http::STATUS_BAD_REQUEST);
		}

		return $this->geschuetzterAufruf(function () use ($id, $bezeichnung, $url) {
			$antwort = $this->redmine->anfrage('GET', "/issues/{$id}.json");
			$ticket = $antwort['issue'] ?? null;
			if ($ticket === null) {
				throw new \RuntimeException('Ticket nicht gefunden.');
			}

			$this->ablage->urlAblegen($ticket, $this->benutzerId(), $bezeichnung, trim($url));

			return $this->ablageInfo($ticket);
		});
	}

	/**
	 * Legt eine Telefon- oder allgemeine Notiz als kleine Textdatei im
	 * Ticket-Ordner ab — für einen nachvollziehbaren Gesprächsverlauf, siehe
	 * {@see AblageService::notizAblegen()}.
	 */
	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'POST', url: '/api/tickets/{id}/notizen')]
	public function notizAblegen(int $id, string $art, string $text, string $ansprechpartner = ''): DataResponse {
		if (trim($text) === '') {
			return new DataResponse(['fehler' => 'Notiztext darf nicht leer sein.'], Http::STATUS_BAD_REQUEST);
		}

		return $this->geschuetzterAufruf(function () use ($id, $art, $text, $ansprechpartner) {
			$antwort = $this->redmine->anfrage('GET', "/issues/{$id}.json");
			$ticket = $antwort['issue'] ?? null;
			if ($ticket === null) {
				throw new \RuntimeException('Ticket nicht gefunden.');
			}

			$this->ablage->notizAblegen(
				$ticket,
				$this->benutzerId(),
				$art !== '' ? $art : 'Notiz',
				trim($text),
				trim($ansprechpartner) !== '' ? trim($ansprechpartner) : null,
			);

			return $this->ablageInfo($ticket);
		});
	}

	/**
	 * Legt eine aus dem Posteingang zugeordnete E-Mail als lesbaren
	 * Gesprächsverlauf-Eintrag ab (kein rohes `.eml`), siehe
	 * {@see AblageService::mailVerlaufAblegen()}.
	 */
	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'POST', url: '/api/tickets/{id}/mail-verlauf')]
	public function mailVerlaufAblegen(int $id, string $von, string $betreff, string $text, string $datum = '', string $nachrichtKennung = ''): DataResponse {
		return $this->geschuetzterAufruf(function () use ($id, $von, $betreff, $text, $datum, $nachrichtKennung) {
			$antwort = $this->redmine->anfrage('GET', "/issues/{$id}.json");
			$ticket = $antwort['issue'] ?? null;
			if ($ticket === null) {
				throw new \RuntimeException('Ticket nicht gefunden.');
			}

			if ($nachrichtKennung !== '' && $this->ablage->mailBereitsZugeordnet($ticket, $this->benutzerId(), $nachrichtKennung)) {
				throw new \RuntimeException('Diese E-Mail ist diesem Ticket bereits zugeordnet.');
			}

			$zeitpunkt = null;
			if ($datum !== '') {
				try {
					$zeitpunkt = new \DateTimeImmutable($datum);
				} catch (\Throwable) {
					$zeitpunkt = null;
				}
			}

			$this->ablage->mailVerlaufAblegen(
				$ticket,
				$this->benutzerId(),
				$von,
				$betreff !== '' ? $betreff : '(kein Betreff)',
				$text,
				$zeitpunkt,
				$nachrichtKennung,
			);

			return $this->ablageInfo($ticket);
		});
	}

	/**
	 * Löscht eine Datei aus der Ticket-Ablage.
	 */
	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'DELETE', url: '/api/tickets/{id}/dateien/{dateiId}')]
	public function dateiLoeschen(int $id, int $dateiId): DataResponse {
		return $this->geschuetzterAufruf(function () use ($id, $dateiId) {
			$antwort = $this->redmine->anfrage('GET', "/issues/{$id}.json");
			$ticket = $antwort['issue'] ?? null;
			if ($ticket === null) {
				throw new \RuntimeException('Ticket nicht gefunden.');
			}

			$this->ablage->dateiLoeschen($dateiId, $this->benutzerId());

			return $this->ablageInfo($ticket);
		});
	}

	/**
	 * Alle in Nextcloud vorhandenen Schlagworte, für Vorschläge in der
	 * Oberfläche.
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[FrontpageRoute(verb: 'GET', url: '/api/tags')]
	public function tags(): DataResponse {
		return $this->geschuetzterAufruf(fn () => ['tags' => $this->tags->alleTags()]);
	}

	/**
	 * Setzt ein Schlagwort auf einer Ablage-Datei — legt es bei Bedarf an.
	 */
	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'POST', url: '/api/dateien/{dateiId}/tags')]
	public function tagSetzen(int $dateiId, string $name): DataResponse {
		return $this->geschuetzterAufruf(fn () => ['tags' => $this->tags->tagSetzen($dateiId, $name)]);
	}

	/**
	 * Entfernt ein Schlagwort von einer Ablage-Datei.
	 */
	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'DELETE', url: '/api/dateien/{dateiId}/tags/{tagId}')]
	public function tagEntfernen(int $dateiId, int $tagId): DataResponse {
		return $this->geschuetzterAufruf(fn () => ['tags' => $this->tags->tagEntfernen($dateiId, $tagId)]);
	}

	// ─── Projekt-Icons ────────────────────────────────────────────────────

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

	// ─── Odoo — Sammelrechnungen ────────────────────────────────────────

	/**
	 * Kundensuche für den Sammelrechnungs-Dialog (res.partner in Odoo).
	 * Leere Suche liefert die zuletzt geänderten Kontakte, wie Odoos eigene
	 * Kontaktsuche das auch macht.
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[FrontpageRoute(verb: 'GET', url: '/api/odoo/kunden')]
	public function odooKunden(string $suche = ''): DataResponse {
		if (!$this->odoo->konfiguriert()) {
			return new DataResponse(['fehler' => 'Odoo ist nicht konfiguriert (siehe Admin-Einstellungen).'], Http::STATUS_BAD_GATEWAY);
		}

		return $this->geschuetzterAufruf(fn () => ['kunden' => $this->odoo->kunden($suche)]);
	}

	/**
	 * Legt aus mehreren ausgewählten Tickets einen Rechnungsentwurf in Odoo
	 * an (eine Freitext-Position je Ticket, Betrag manuell im Dialog
	 * eingetragen). Odoo verbucht/versendet dabei nichts automatisch — der
	 * Entwurf bleibt in Odoo zur Kontrolle, bevor Mathias ihn bestätigt.
	 *
	 * @param array<int,array{beschreibung?:string,betrag?:float|string}> $positionen
	 */
	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'POST', url: '/api/odoo/sammelrechnung')]
	public function odooSammelrechnung(int $partnerId = 0, array $positionen = []): DataResponse {
		if (!$this->odoo->konfiguriert()) {
			return new DataResponse(['fehler' => 'Odoo ist nicht konfiguriert (siehe Admin-Einstellungen).'], Http::STATUS_BAD_GATEWAY);
		}
		if ($partnerId <= 0 || count($positionen) === 0) {
			return new DataResponse(['fehler' => 'Kunde und mindestens eine Position werden benötigt.'], Http::STATUS_BAD_REQUEST);
		}

		return $this->geschuetzterAufruf(fn () => $this->odoo->sammelrechnungErstellen($partnerId, $positionen));
	}
}
