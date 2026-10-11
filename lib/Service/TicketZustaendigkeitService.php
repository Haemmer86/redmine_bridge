<?php

declare(strict_types=1);

namespace OCA\RedmineBridge\Service;

use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * Verwaltet den "Zuständigen Mitarbeiter" je Ticket — ein eigenes, von
 * Redmine unabhängiges Feld (zusätzlich zu Redmines "Zugewiesen an"), das
 * ausschließlich bestimmt, wem ein Ticket in der eingeschränkten
 * "Meine Tickets"-Ansicht angezeigt wird (siehe MitarbeiterController::meineTickets()
 * und ::meinTicketAktualisieren()).
 *
 * Bewusst getrennt von Redmines "Zugewiesen an" (assigned_to_id), das nur
 * unter Redmine-Projektmitgliedern wählbar ist: dieses Feld erlaubt JEDES
 * Mitglied der Mitarbeiter-Gruppe, unabhängig von einer
 * Redmine-Projektmitgliedschaft. Ebenfalls getrennt von
 * {@see MitarbeiterZuordnungService}: jene Klasse pflegt die globale
 * Identität (welcher Redmine-Benutzer "ist" dieses Nextcloud-Konto, einmalig
 * vom Administrator gepflegt), diese hier die Zuordnung je einzelnem Ticket
 * (im Alltag von jedem mit Zugriff auf die normale Ticketverwaltung
 * gepflegt, siehe TicketNeu.vue/TicketDetail.vue).
 */
class TicketZustaendigkeitService {

	private const TABELLE = 'redmine_bridge_zustaendig';

	public function __construct(
		private readonly IDBConnection $db,
	) {
	}

	public function fuerTicket(int $ticketId): ?string {
		$qb = $this->db->getQueryBuilder();
		$qb->select('nextcloud_user_id')
			->from(self::TABELLE)
			->where($qb->expr()->eq('ticket_id', $qb->createNamedParameter($ticketId, IQueryBuilder::PARAM_INT)));

		$zeile = $qb->executeQuery()->fetch();

		return $zeile !== false ? (string)$zeile['nextcloud_user_id'] : null;
	}

	/**
	 * @param list<int> $ticketIds
	 * @return array<int,string> Ticket-ID => Nextcloud-Benutzer-ID
	 */
	public function fuerTickets(array $ticketIds): array {
		if ($ticketIds === []) {
			return [];
		}

		$qb = $this->db->getQueryBuilder();
		$qb->select('ticket_id', 'nextcloud_user_id')
			->from(self::TABELLE)
			->where($qb->expr()->in('ticket_id', $qb->createNamedParameter($ticketIds, IQueryBuilder::PARAM_INT_ARRAY)));

		$ergebnis = [];
		foreach ($qb->executeQuery()->fetchAll() as $zeile) {
			$ergebnis[(int)$zeile['ticket_id']] = (string)$zeile['nextcloud_user_id'];
		}

		return $ergebnis;
	}

	/**
	 * Alle Ticket-IDs, die einem bestimmten Nextcloud-Konto als zuständig
	 * zugeordnet sind — Grundlage für MitarbeiterController::meineTickets(). Die
	 * zugehörigen Ticketinhalte werden erst danach, gezielt für genau diese
	 * IDs, bei Redmine abgefragt (siehe dort) — dieselbe Vorgehensweise wie
	 * bei den unfakturierten Tickets (unfakturierteTicketIds()).
	 *
	 * @return list<int>
	 */
	public function ticketIdsFuer(string $nextcloudUserId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('ticket_id')
			->from(self::TABELLE)
			->where($qb->expr()->eq('nextcloud_user_id', $qb->createNamedParameter($nextcloudUserId)));

		return array_map(
			static fn (array $zeile): int => (int)$zeile['ticket_id'],
			$qb->executeQuery()->fetchAll(),
		);
	}

	/**
	 * Alle Ticket-IDs mit irgendeiner Zuordnung, über alle Mitarbeiter hinweg
	 * — Grundlage für den regelmäßigen Kalenderabgleich
	 * (KalenderAbgleich), der ja gerade NICHT nach einem einzelnen
	 * Benutzer filtern darf, sondern alle zugeordneten Tickets auf
	 * geänderte Fälligkeitsdaten prüfen muss.
	 *
	 * @return list<int>
	 */
	public function alleTicketIds(): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('ticket_id')->from(self::TABELLE);

		return array_map(
			static fn (array $zeile): int => (int)$zeile['ticket_id'],
			$qb->executeQuery()->fetchAll(),
		);
	}

	/**
	 * Setzt den zuständigen Mitarbeiter für ein Ticket. `null` oder eine
	 * leere Zeichenkette entfernt eine bestehende Zuordnung wieder (Ticket
	 * hat dann keinen zuständigen Mitarbeiter mehr und verschwindet aus
	 * dessen "Meine Tickets"-Ansicht).
	 */
	public function setzen(int $ticketId, ?string $nextcloudUserId): void {
		if ($nextcloudUserId === null || $nextcloudUserId === '') {
			$this->entfernen($ticketId);
			return;
		}

		$jetzt = new \DateTime();

		if ($this->fuerTicket($ticketId) !== null) {
			$qb = $this->db->getQueryBuilder();
			$qb->update(self::TABELLE)
				->set('nextcloud_user_id', $qb->createNamedParameter($nextcloudUserId))
				->set('geaendert_am', $qb->createNamedParameter($jetzt, IQueryBuilder::PARAM_DATE))
				->where($qb->expr()->eq('ticket_id', $qb->createNamedParameter($ticketId, IQueryBuilder::PARAM_INT)));
			$qb->executeStatement();
			return;
		}

		$qb = $this->db->getQueryBuilder();
		$qb->insert(self::TABELLE)
			->setValue('ticket_id', $qb->createNamedParameter($ticketId, IQueryBuilder::PARAM_INT))
			->setValue('nextcloud_user_id', $qb->createNamedParameter($nextcloudUserId))
			->setValue('geaendert_am', $qb->createNamedParameter($jetzt, IQueryBuilder::PARAM_DATE));
		$qb->executeStatement();
	}

	public function entfernen(int $ticketId): void {
		$qb = $this->db->getQueryBuilder();
		$qb->delete(self::TABELLE)
			->where($qb->expr()->eq('ticket_id', $qb->createNamedParameter($ticketId, IQueryBuilder::PARAM_INT)));
		$qb->executeStatement();
	}
}
