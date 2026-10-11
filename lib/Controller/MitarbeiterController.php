<?php

declare(strict_types=1);

namespace OCA\RedmineBridge\Controller;

use OCA\RedmineBridge\AppInfo\Application;
use OCA\RedmineBridge\Service\AblageService;
use OCA\RedmineBridge\Service\MitarbeiterZuordnungService;
use OCA\RedmineBridge\Service\RedmineClient;
use OCA\RedmineBridge\Service\TicketKalenderService;
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
 * Mitarbeiter-Ansicht („Meine Tickets“) und Sitzungsinfo.
 *
 * Eingeschränkte Ansicht für Mitarbeiter, die nicht die volle
 * Ticketverwaltung sehen sollen: nur die eigenen, über
 * MitarbeiterZuordnungService zugeordneten Tickets, mit Status ändern
 * und Kommentar hinzufügen — kein Zugriff auf andere Tickets, Projekte,
 * Dateien, Zeiterfassung, Rechnungen o. ä. Diese Endpunkte reichen
 * `mitarbeiterErlaubt: true` an `geschuetzterAufruf()` durch und sind
 * deshalb die einzigen, die ein Konto aus der Mitarbeiter-Gruppe
 * erreichen kann (siehe BasisController::istMitarbeiterNurAnsicht()).
 *
 * Sicherheit beruht NICHT darauf, dass das Frontend nur die eigenen
 * Tickets anzeigt — `meineTickets()` filtert serverseitig hart nach der
 * hinterlegten Zuordnung, und `meinTicketAktualisieren()` prüft vor jeder
 * Änderung erneut, dass das Ticket wirklich dem zugeordneten
 * Redmine-Benutzer zugewiesen ist. Ein manuell gebastelter Aufruf mit
 * einer fremden Ticket-ID schlägt damit mit 403 fehl.
 */
class MitarbeiterController extends BasisController {

	public function __construct(
		IRequest $request,
		IUserSession $userSession,
		IGroupManager $groupManager,
		IConfig $config,
		private readonly RedmineClient $redmine,
		private readonly AblageService $ablage,
		private readonly MitarbeiterZuordnungService $mitarbeiterZuordnung,
		private readonly TicketZustaendigkeitService $ticketZustaendigkeit,
		private readonly TicketKalenderService $ticketKalender,
	) {
		parent::__construct($request, $userSession, $groupManager, $config);
	}

	/**
	 * Für den Seitenaufbau: ob das angemeldete Konto in der eingeschränkten
	 * Mitarbeiter-Ansicht landet, und ob dafür überhaupt ein
	 * Redmine-Benutzer hinterlegt ist (ohne Zuordnung kann "Meine Tickets"
	 * nichts anzeigen — die Oberfläche zeigt dann einen Hinweis statt einer
	 * leeren Liste).
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[FrontpageRoute(verb: 'GET', url: '/api/sitzung')]
	public function sitzung(): DataResponse {
		return $this->geschuetzterAufruf(function () {
			return [
				'istMitarbeiter' => $this->istMitarbeiterNurAnsicht(),
				// Für die Oberfläche: ob "Meine Tickets" als freiwilliger
				// Navigationspunkt angeboten werden soll (Admin-Konto, das
				// zusätzlich Mitglied der Mitarbeiter-Gruppe ist — siehe
				// istMitarbeiterNurAnsicht()). Für normale Gruppenmitglieder
				// ohne Admin-Rechte ist das irrelevant, die landen über
				// `istMitarbeiter` ohnehin ausschließlich dort.
				'mitarbeiterGruppenMitglied' => $this->istMitarbeiterGruppenMitglied(),
			];
		}, mitarbeiterErlaubt: true);
	}

	/**
	 * Nextcloud-Konten, die als "Zuständiger Mitarbeiter" eines Tickets
	 * ausgewählt werden können (siehe TicketZustaendigkeitService): Mitglieder
	 * der konfigurierten Mitarbeiter-Gruppe, die zusätzlich bereits eine
	 * Redmine-Zuordnung haben (siehe MitarbeiterZuordnungService). Ohne
	 * konfigurierte Gruppe oder ohne Zuordnung bleibt die Liste leer — dann
	 * gibt es (noch) niemanden, der die eingeschränkte "Meine
	 * Tickets"-Ansicht überhaupt sehen und die Zuordnung dort nutzen könnte.
	 *
	 * Bewusst NICHT mit `mitarbeiterErlaubt: true` freigegeben: diese Liste
	 * dient der normalen Ticketverwaltung (wer trägt wen in welches Ticket
	 * ein), nicht der eingeschränkten Mitarbeiter-Ansicht selbst.
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[FrontpageRoute(verb: 'GET', url: '/api/mitarbeiter-liste')]
	public function mitarbeiterListe(): DataResponse {
		return $this->geschuetzterAufruf(function () {
			$gruppe = $this->config->getAppValue(Application::APP_ID, Application::CONF_MITARBEITER_GRUPPE, '');
			if ($gruppe === '' || !$this->groupManager->groupExists($gruppe)) {
				return ['mitarbeiter' => []];
			}

			$zuordnungen = $this->mitarbeiterZuordnung->alle();
			$ergebnis = [];
			foreach ($this->groupManager->get($gruppe)->getUsers() as $benutzer) {
				if (!isset($zuordnungen[$benutzer->getUID()])) {
					continue;
				}
				$ergebnis[] = ['uid' => $benutzer->getUID(), 'name' => $benutzer->getDisplayName()];
			}

			return ['mitarbeiter' => $ergebnis];
		});
	}

	/**
	 * Redmines Ticket-Status-Liste — eigener, bewusst minimaler Endpunkt für
	 * die Mitarbeiter-Ansicht statt des vollen `formulardaten()`-Endpunkts,
	 * der zusätzlich Tracker/Prioritäten/Projekte/Rollen/die komplette
	 * Benutzerliste mitliefert — unnötige Angriffsfläche für ein Konto, das
	 * ohnehin nur Status ändern darf.
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[FrontpageRoute(verb: 'GET', url: '/api/meine-tickets/status-liste')]
	public function meineTicketsStatusListe(): DataResponse {
		return $this->geschuetzterAufruf(function () {
			$antwort = $this->redmine->anfrage('GET', '/issue_statuses.json');

			return ['status' => $antwort['issue_statuses'] ?? []];
		}, mitarbeiterErlaubt: true);
	}

	/**
	 * Tickets, denen das angemeldete Nextcloud-Konto als "Zuständiger
	 * Mitarbeiter" zugeordnet ist (siehe TicketZustaendigkeitService) —
	 * unabhängig von Redmines eigenem "Zugewiesen an"-Feld und unabhängig vom
	 * Projekt. Die Zuordnung ist Redmine unbekannt, deshalb zuerst die
	 * zugeordneten Ticket-IDs aus der eigenen Tabelle lesen und erst danach
	 * gezielt (in Stapeln von 100) bei Redmine nach Inhalten fragen — dieselbe
	 * Vorgehensweise wie bei den unfakturierten Tickets weiter unten.
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[FrontpageRoute(verb: 'GET', url: '/api/meine-tickets')]
	public function meineTickets(string $status = 'open'): DataResponse {
		return $this->geschuetzterAufruf(function () use ($status) {
			$ticketIds = $this->ticketZustaendigkeit->ticketIdsFuer($this->benutzerId());
			if ($ticketIds === []) {
				return ['tickets' => []];
			}

			$issues = [];
			foreach (array_chunk($ticketIds, 100) as $stapel) {
				$antwort = $this->redmine->anfrage('GET', '/issues.json', [
					'issue_id' => implode(',', $stapel),
					'status_id' => $status,
					'limit' => 100,
				]);
				array_push($issues, ...($antwort['issues'] ?? []));
			}

			usort($issues, static fn (array $a, array $b): int => strcmp((string)($b['updated_on'] ?? ''), (string)($a['updated_on'] ?? '')));

			return ['tickets' => $issues];
		}, mitarbeiterErlaubt: true);
	}

	/**
	 * Status ändern und/oder einen Kommentar hinzufügen — die einzige
	 * Schreibmöglichkeit der Mitarbeiter-Ansicht. Prüft vor jeder Änderung
	 * erneut gegen die eigene Zuordnungstabelle, dass das Ticket tatsächlich
	 * dem angemeldeten Konto als zuständig zugeordnet ist, statt sich auf die
	 * Vorauswahl im Frontend zu verlassen.
	 */
	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'PUT', url: '/api/meine-tickets/{id}')]
	public function meinTicketAktualisieren(int $id, ?int $statusId = null, string $notiz = ''): DataResponse {
		return $this->geschuetzterAufruf(function () use ($id, $statusId, $notiz) {
			if ($this->ticketZustaendigkeit->fuerTicket($id) !== $this->benutzerId()) {
				throw new \RuntimeException('Dieses Ticket ist dir nicht als zuständig zugeordnet.');
			}

			$nutzlast = [];
			if ($statusId !== null && $statusId > 0) {
				$nutzlast['status_id'] = $statusId;
			}
			if (trim($notiz) !== '') {
				$nutzlast['notes'] = trim($notiz);
			}
			if ($nutzlast === []) {
				throw new \RuntimeException('Weder Status noch Bemerkung angegeben.');
			}

			$this->redmine->anfrage('PUT', "/issues/{$id}.json", ['issue' => $nutzlast]);
			$frisch = $this->redmine->anfrage('GET', "/issues/{$id}.json");
			$frischesTicket = $frisch['issue'] ?? null;
			if ($frischesTicket !== null) {
				// Schließt der Mitarbeiter sein eigenes Ticket hier, soll ein
				// dafür bestehender Kalendertermin sofort verschwinden, nicht
				// erst beim nächsten Hintergrundabgleich.
				$this->ticketKalender->synchronisieren($frischesTicket);
			}

			return ['ticket' => $frischesTicket];
		}, mitarbeiterErlaubt: true);
	}

	/**
	 * Lädt eine Datei zu einem der eigenen zugeordneten Tickets hoch — die
	 * einzige Datei-Funktion der Mitarbeiter-Ansicht. Auf Mathias'
	 * ausdrücklichen Wunsch bewusst NUR Hochladen: anders als
	 * dateiHochladen() unten liefert dieser Endpunkt keine Dateiliste des
	 * Ticket-Ordners zurück, damit die eingeschränkte Ansicht auch weiterhin
	 * keinen Einblick in bereits vorhandene Dateien bekommt. Landet NUR in
	 * Nextcloud, wie bei der normalen Dateiablage auch — kein Redmine-Anhang.
	 */
	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'POST', url: '/api/meine-tickets/{id}/dateien')]
	public function meinTicketDateiHochladen(int $id): DataResponse {
		$hochgeladen = $this->request->getUploadedFile('datei');
		if ($hochgeladen === null || ($hochgeladen['error'] ?? \UPLOAD_ERR_NO_FILE) !== \UPLOAD_ERR_OK) {
			return new DataResponse(['fehler' => 'Keine Datei erhalten.'], Http::STATUS_BAD_REQUEST);
		}

		return $this->geschuetzterAufruf(function () use ($id, $hochgeladen) {
			if ($this->ticketZustaendigkeit->fuerTicket($id) !== $this->benutzerId()) {
				throw new \RuntimeException('Dieses Ticket ist dir nicht als zuständig zugeordnet.');
			}

			$antwort = $this->redmine->anfrage('GET', "/issues/{$id}.json");
			$ticket = $antwort['issue'] ?? null;
			if ($ticket === null) {
				throw new \RuntimeException('Ticket nicht gefunden.');
			}

			$inhalt = fopen($hochgeladen['tmp_name'], 'rb');
			$datei = $this->ablage->dateiAblegen($ticket, $this->benutzerId(), $hochgeladen['name'], $inhalt);

			return ['hochgeladen' => $datei->getName()];
		}, mitarbeiterErlaubt: true);
	}
}
