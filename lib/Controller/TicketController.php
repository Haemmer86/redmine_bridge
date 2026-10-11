<?php

declare(strict_types=1);

namespace OCA\RedmineBridge\Controller;

use OCA\RedmineBridge\Service\AblageAnsichtService;
use OCA\RedmineBridge\Service\RechnungsStatusService;
use OCA\RedmineBridge\Service\RedmineClient;
use OCA\RedmineBridge\Service\TicketKalenderService;
use OCA\RedmineBridge\Service\TicketListeService;
use OCA\RedmineBridge\Service\TicketZustaendigkeitService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\FrontpageRoute;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\IConfig;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Tickets: Liste, Detail, Bearbeiten, Anlegen, Zeiterfassung.
 *
 * Teil der JSON-API für die Vue-Oberfläche; gemeinsame Schutzlogik
 * (Mitarbeiter-Beschränkung, Fehlerverpackung) steht in {@see BasisController}.
 */
class TicketController extends BasisController {

	public function __construct(
		IRequest $request,
		IUserSession $userSession,
		IGroupManager $groupManager,
		IConfig $config,
		private readonly RedmineClient $redmine,
		private readonly AblageAnsichtService $ablageAnsicht,
		private readonly RechnungsStatusService $rechnungsstatus,
		private readonly TicketZustaendigkeitService $ticketZustaendigkeit,
		private readonly TicketKalenderService $ticketKalender,
		private readonly TicketListeService $ticketListe,
	) {
		parent::__construct($request, $userSession, $groupManager, $config);
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[FrontpageRoute(verb: 'GET', url: '/api/tickets')]
	public function tickets(string $status = 'open', string $projekt = '', int $seite = 1, bool $nurUnfakturiert = false, string $sortierung = 'geaendert'): DataResponse {
		return $this->geschuetzterAufruf(function () use ($status, $projekt, $seite, $nurUnfakturiert, $sortierung) {
			if (!in_array($sortierung, TicketListeService::SORTIERUNGEN, true)) {
				$sortierung = 'geaendert';
			}

			if ($nurUnfakturiert) {
				return $this->ticketListe->unfakturierteTickets($projekt, $sortierung);
			}

			// Fälligkeit und Ticketwert kann Redmine nicht selbst sortieren
			// (der Wert liegt in der eigenen Rechnungstabelle, und bei der
			// Fälligkeit soll "ohne Datum" sicher ans Ende, was je nach
			// Datenbank sonst anders läuft). Damit die Sortierung über ALLE
			// Seiten gilt und nicht nur innerhalb von 50 Tickets, werden dafür
			// alle passenden Tickets geholt, hier sortiert und dann
			// seitenweise ausgeliefert.
			if ($sortierung === 'faelligkeit' || $sortierung === 'betrag') {
				$issues = $this->ticketListe->rechnungsstatusAnreichern($this->ticketListe->alleTicketsHolen($status, $projekt));
				$issues = $this->ticketListe->ticketsSortieren($issues, $sortierung);

				return [
					'tickets' => array_slice($issues, ($seite - 1) * 50, 50),
					'gesamt' => count($issues),
					'seite' => $seite,
				];
			}

			$redmineSortierung = match ($sortierung) {
				'neu_alt' => 'created_on:desc',
				'alt_neu' => 'created_on:asc',
				default => 'updated_on:desc',
			};

			$abfrage = [
				'status_id' => $status,
				'limit' => 50,
				'offset' => ($seite - 1) * 50,
				'sort' => $redmineSortierung,
			];
			if ($projekt !== '') {
				$abfrage['project_id'] = $projekt;
			}

			$antwort = $this->redmine->anfrage('GET', '/issues.json', $abfrage);

			return [
				'tickets' => $this->ticketListe->rechnungsstatusAnreichern($antwort['issues'] ?? []),
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
			$ticket['rechnungsstatus'] = $this->rechnungsstatus->fuerTicket($id);
			$ticket['zustaendigerMitarbeiter'] = $this->ticketZustaendigkeit->fuerTicket($id);

			$ablage = $this->ablageAnsicht->fuerTicket($this->benutzerId(), $ticket);

			return ['ticket' => $ticket] + $ablage;
		});
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
			$frischesTicket = $frisch['issue'] ?? null;
			if ($frischesTicket !== null) {
				$frischesTicket['rechnungsstatus'] = $this->rechnungsstatus->fuerTicket($id);
				$frischesTicket['zustaendigerMitarbeiter'] = $this->ticketZustaendigkeit->fuerTicket($id);
				// Deckt u. a. ein geändertes Fälligkeitsdatum ab — unabhängig
				// davon, ob gerade genau dieses Feld im PUT-Rumpf enthalten
				// war, da $frischesTicket ohnehin immer der aktuelle
				// Redmine-Stand ist.
				$this->ticketKalender->synchronisieren($frischesTicket);
			}

			return ['ticket' => $frischesTicket] + $this->ablageAnsicht->fuerTicket($this->benutzerId(), $frischesTicket ?? []);
		});
	}

	/**
	 * Setzt den "Zuständigen Mitarbeiter" eines Tickets — ein eigenes, von
	 * Redmines "Zugewiesen an" unabhängiges Feld, das ausschließlich
	 * bestimmt, wem das Ticket in der eingeschränkten "Meine
	 * Tickets"-Ansicht angezeigt wird (siehe TicketZustaendigkeitService).
	 * `null`/leer entfernt eine bestehende Zuordnung wieder.
	 */
	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'PUT', url: '/api/tickets/{id}/zustaendiger-mitarbeiter')]
	public function zustaendigerMitarbeiterSetzen(int $id, ?string $nextcloudUserId = null): DataResponse {
		return $this->geschuetzterAufruf(function () use ($id, $nextcloudUserId) {
			$this->ticketZustaendigkeit->setzen($id, $nextcloudUserId);

			// Wechselt die Zuständigkeit, muss ein eventueller
			// Kalendertermin mit umziehen (alter Mitarbeiter → neuer
			// Mitarbeiter) bzw. neu entstehen/verschwinden — dafür wird das
			// Ticket hier einmal frisch von Redmine geholt (Betreff/
			// Fälligkeitsdatum/Status), dieser Endpunkt bekommt sie sonst
			// gar nicht mitgeliefert.
			$aktuellesTicket = $this->redmine->anfrage('GET', "/issues/{$id}.json")['issue'] ?? null;
			if ($aktuellesTicket !== null) {
				$this->ticketKalender->synchronisieren($aktuellesTicket);
			}

			return ['zustaendigerMitarbeiter' => $this->ticketZustaendigkeit->fuerTicket($id)];
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
			$frischesTicket = $frisch['issue'] ?? null;
			if ($frischesTicket !== null) {
				$frischesTicket['rechnungsstatus'] = $this->rechnungsstatus->fuerTicket($id);
			}

			return ['ticket' => $frischesTicket] + $this->ablageAnsicht->fuerTicket($this->benutzerId(), $frischesTicket ?? []);
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
	 *
	 * `rechnungspflichtig` ist kein Redmine-Feld — es landet in der
	 * eigenen Rechnungsstatus-Tabelle dieser App, siehe
	 * {@see RechnungsStatusService}. Dasselbe gilt für `abrechnungsart`
	 * ('pauschale'/'stunden'/'pauschale_zuschlag'), `pauschalbetrag`, `stundensatz`,
	 * die Zuschlags-Konditionen (`inklusivMinuten`/`taktMinuten`/`taktPreis`) und
	 * `bemerkung` — alle vier optional, bei der Neuanlage meist noch nicht
	 * bekannt und jederzeit im Ticket nachtragbar.
	 */
	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'POST', url: '/api/tickets')]
	public function ticketErstellen(
		array $ticket,
		bool $rechnungspflichtig = true,
		?string $abrechnungsart = null,
		?float $pauschalbetrag = null,
		?float $stundensatz = null,
		?string $bemerkung = null,
		?string $zustaendigerMitarbeiter = null,
		?int $inklusivMinuten = null,
		?int $taktMinuten = null,
		?float $taktPreis = null,
	): DataResponse {
		return $this->geschuetzterAufruf(function () use (
			$ticket,
			$rechnungspflichtig,
			$abrechnungsart,
			$pauschalbetrag,
			$stundensatz,
			$bemerkung,
			$zustaendigerMitarbeiter,
			$inklusivMinuten,
			$taktMinuten,
			$taktPreis,
		) {
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
			$neuesTicket = $antwort['issue'] ?? null;

			if ($neuesTicket !== null) {
				$ticketId = (int)$neuesTicket['id'];
				$rechnungsstatus = $this->rechnungsstatus->rechnungspflichtigSetzen($ticketId, $rechnungspflichtig);

				if ($abrechnungsart !== null || $pauschalbetrag !== null || $stundensatz !== null || $bemerkung !== null) {
					$rechnungsstatus = $this->rechnungsstatus->rechnungsdatenSetzen(
						$ticketId,
						$abrechnungsart,
						$pauschalbetrag,
						$stundensatz,
						$bemerkung,
						$inklusivMinuten,
						$taktMinuten,
						$taktPreis,
					);
				}

				$neuesTicket['rechnungsstatus'] = $rechnungsstatus;

				if ($zustaendigerMitarbeiter !== null && $zustaendigerMitarbeiter !== '') {
					$this->ticketZustaendigkeit->setzen($ticketId, $zustaendigerMitarbeiter);
				}
				$neuesTicket['zustaendigerMitarbeiter'] = $this->ticketZustaendigkeit->fuerTicket($ticketId);
				$this->ticketKalender->synchronisieren($neuesTicket);
			}

			return ['ticket' => $neuesTicket];
		});
	}
}
