<?php

declare(strict_types=1);

namespace OCA\RedmineBridge\BackgroundJob;

use OCA\RedmineBridge\Service\RedmineClient;
use OCA\RedmineBridge\Service\TicketKalenderService;
use OCA\RedmineBridge\Service\TicketZustaendigkeitService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;

/**
 * Gleicht alle Tickets mit einem zuständigen Mitarbeiter (siehe
 * TicketZustaendigkeitService) regelmäßig gegen Redmine ab und hält so den
 * Kalendertermin für deren Fälligkeitsdatum aktuell (siehe
 * TicketKalenderService) — auch dann, wenn das Fälligkeitsdatum, der
 * Status oder die Zuständigkeit direkt in Redmine geändert wurde, ohne
 * dass jemand das Ticket in dieser App öffnet. Ein unmittelbarer Abgleich
 * beim Speichern über die App selbst passiert zusätzlich sofort (siehe
 * TicketController und MitarbeiterController), dieser Hintergrundlauf ist das Sicherheitsnetz für
 * alles andere.
 *
 * Alle 15 Minuten — häufig genug, um eine Fälligkeit zeitnah im Kalender
 * zu haben, ohne Redmine bei vielen zugeordneten Tickets unnötig oft
 * abzufragen.
 */
class KalenderAbgleich extends TimedJob {

	public function __construct(
		ITimeFactory $time,
		private readonly RedmineClient $redmine,
		private readonly TicketZustaendigkeitService $ticketZustaendigkeit,
		private readonly TicketKalenderService $ticketKalender,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct($time);
		$this->setInterval(900);
	}

	protected function run($argument): void {
		if (!$this->redmine->konfiguriert()) {
			return;
		}

		// Kandidaten: alles mit einer Zuständigkeits-Zuordnung (kann einen
		// Termin brauchen) UND alles, wofür aktuell ein Termin
		// nachverfolgt wird (könnte einen nicht mehr brauchen, z. B. nach
		// einer Erledigung direkt in Redmine) — siehe Klassenkommentare
		// der beiden Services.
		$ticketIds = array_values(array_unique(array_merge(
			$this->ticketZustaendigkeit->alleTicketIds(),
			$this->ticketKalender->alleVerfolgtenTicketIds(),
		)));
		if ($ticketIds === []) {
			return;
		}

		$gefundeneIds = [];
		foreach (array_chunk($ticketIds, 100) as $stapel) {
			try {
				$antwort = $this->redmine->anfrage('GET', '/issues.json', [
					// status_id=* statt des Redmine-Standards (nur offene) —
					// gerade erledigte Tickets müssen hier ebenfalls auftauchen,
					// damit TicketKalenderService::synchronisieren() ihren
					// Termin erkennt und entfernt.
					'status_id' => '*',
					'issue_id' => implode(',', $stapel),
					'limit' => 100,
				]);
			} catch (\Throwable $e) {
				$this->logger->warning('Redmine Bridge: Kalenderabgleich — Redmine-Abfrage fehlgeschlagen.', ['exception' => $e]);
				continue;
			}

			foreach ($antwort['issues'] ?? [] as $ticket) {
				$gefundeneIds[(int)$ticket['id']] = true;
				$this->ticketKalender->synchronisieren($ticket);
			}
		}

		// IDs, die in der Antwort fehlten, gibt es in Redmine nicht mehr
		// (z. B. gelöscht) — einen zugehörigen Termin trotzdem aufräumen.
		foreach ($ticketIds as $id) {
			if (!isset($gefundeneIds[$id])) {
				$this->ticketKalender->entfernen($id);
			}
		}
	}
}
