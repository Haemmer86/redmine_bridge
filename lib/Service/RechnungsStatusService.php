<?php

declare(strict_types=1);

namespace OCA\RedmineBridge\Service;

use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * Verwaltet den Rechnungsstatus je Ticket (rechnungspflichtig? / schon
 * gestellt? / wann / von wem) in einer eigenen kleinen Tabelle.
 *
 * Bewusste Ausnahme vom sonstigen "keine eigene Datenbanktabelle"-Grundsatz
 * dieser App: Redmine selbst hat kein Feld dafür, und es handelt sich um
 * eine reine Buchhaltungs-Angelegenheit dieser App, keine
 * Redmine-Ticketeigenschaft. Ein Ticket ohne Zeile in dieser Tabelle gilt
 * als "nicht rechnungspflichtig" — so bleiben Bestandstickets unauffällig,
 * bis sie einzeln als rechnungspflichtig markiert werden.
 */
class RechnungsStatusService {

	private const TABELLE = 'redmine_bridge_rechnung';

	public function __construct(
		private readonly IDBConnection $db,
	) {
	}

	/**
	 * @param list<int> $ticketIds
	 * @return array<int,array{rechnungspflichtig:bool,rechnung_gestellt:bool,rechnung_gestellt_am:?string,rechnung_gestellt_von:?string,abrechnungsart:?string,pauschalbetrag:?float,stundensatz:?float,bemerkung:?string,inklusiv_minuten:?int,takt_minuten:?int,takt_preis:?float}>
	 */
	public function fuerTickets(array $ticketIds): array {
		if ($ticketIds === []) {
			return [];
		}

		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from(self::TABELLE)
			->where($qb->expr()->in('ticket_id', $qb->createNamedParameter(
				array_map('intval', $ticketIds),
				IQueryBuilder::PARAM_INT_ARRAY,
			)));

		$ergebnis = [];
		foreach ($qb->executeQuery()->fetchAll() as $zeile) {
			$ergebnis[(int)$zeile['ticket_id']] = $this->zeileAbbilden($zeile);
		}

		return $ergebnis;
	}

	/**
	 * @return array{rechnungspflichtig:bool,rechnung_gestellt:bool,rechnung_gestellt_am:?string,rechnung_gestellt_von:?string,abrechnungsart:?string,pauschalbetrag:?float,stundensatz:?float,bemerkung:?string,inklusiv_minuten:?int,takt_minuten:?int,takt_preis:?float}
	 */
	public function fuerTicket(int $ticketId): array {
		return $this->fuerTickets([$ticketId])[$ticketId] ?? $this->standard();
	}

	/**
	 * Ticket-IDs, die rechnungspflichtig sind, aber noch nicht abgerechnet
	 * wurden — Grundlage für den Übersichts-Filter "Nur nicht abgerechnete",
	 * unabhängig vom Redmine-Status (offen/erledigt).
	 *
	 * @return list<int>
	 */
	public function offenePflichtigeTicketIds(): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('ticket_id')
			->from(self::TABELLE)
			->where($qb->expr()->eq('rechnungspflichtig', $qb->createNamedParameter(1, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('rechnung_gestellt', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT)));

		return array_map(
			static fn (array $zeile): int => (int)$zeile['ticket_id'],
			$qb->executeQuery()->fetchAll(),
		);
	}

	/**
	 * @return array{rechnungspflichtig:bool,rechnung_gestellt:bool,rechnung_gestellt_am:?string,rechnung_gestellt_von:?string,abrechnungsart:?string,pauschalbetrag:?float,stundensatz:?float,bemerkung:?string,inklusiv_minuten:?int,takt_minuten:?int,takt_preis:?float}
	 */
	public function rechnungspflichtigSetzen(int $ticketId, bool $wert): array {
		$jetzt = new \DateTime();

		if ($this->zeileVorhanden($ticketId)) {
			$qb = $this->db->getQueryBuilder();
			$qb->update(self::TABELLE)
				->set('rechnungspflichtig', $qb->createNamedParameter($wert ? 1 : 0, IQueryBuilder::PARAM_INT))
				->set('geaendert_am', $qb->createNamedParameter($jetzt, IQueryBuilder::PARAM_DATE))
				->where($qb->expr()->eq('ticket_id', $qb->createNamedParameter($ticketId, IQueryBuilder::PARAM_INT)));
			$qb->executeStatement();
		} else {
			$qb = $this->db->getQueryBuilder();
			$qb->insert(self::TABELLE)
				->setValue('ticket_id', $qb->createNamedParameter($ticketId, IQueryBuilder::PARAM_INT))
				->setValue('rechnungspflichtig', $qb->createNamedParameter($wert ? 1 : 0, IQueryBuilder::PARAM_INT))
				->setValue('rechnung_gestellt', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT))
				->setValue('geaendert_am', $qb->createNamedParameter($jetzt, IQueryBuilder::PARAM_DATE));
			$qb->executeStatement();
		}

		return $this->fuerTicket($ticketId);
	}

	/**
	 * @return array{rechnungspflichtig:bool,rechnung_gestellt:bool,rechnung_gestellt_am:?string,rechnung_gestellt_von:?string,abrechnungsart:?string,pauschalbetrag:?float,stundensatz:?float,bemerkung:?string,inklusiv_minuten:?int,takt_minuten:?int,takt_preis:?float}
	 */
	public function rechnungGestelltSetzen(int $ticketId, bool $wert, string $benutzerId): array {
		$jetzt = new \DateTime();

		if ($this->zeileVorhanden($ticketId)) {
			$qb = $this->db->getQueryBuilder();
			$qb->update(self::TABELLE)
				->set('rechnung_gestellt', $qb->createNamedParameter($wert ? 1 : 0, IQueryBuilder::PARAM_INT))
				->set('geaendert_am', $qb->createNamedParameter($jetzt, IQueryBuilder::PARAM_DATE));
			if ($wert) {
				$qb->set('rechnung_gestellt_am', $qb->createNamedParameter($jetzt, IQueryBuilder::PARAM_DATE));
				$qb->set('rechnung_gestellt_von', $qb->createNamedParameter($benutzerId));
			} else {
				$qb->set('rechnung_gestellt_am', $qb->createNamedParameter(null, IQueryBuilder::PARAM_DATE));
				$qb->set('rechnung_gestellt_von', $qb->createNamedParameter(null));
			}
			$qb->where($qb->expr()->eq('ticket_id', $qb->createNamedParameter($ticketId, IQueryBuilder::PARAM_INT)));
			$qb->executeStatement();
		} else {
			$qb = $this->db->getQueryBuilder();
			$qb->insert(self::TABELLE)
				->setValue('ticket_id', $qb->createNamedParameter($ticketId, IQueryBuilder::PARAM_INT))
				->setValue('rechnungspflichtig', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT))
				->setValue('rechnung_gestellt', $qb->createNamedParameter($wert ? 1 : 0, IQueryBuilder::PARAM_INT))
				->setValue('geaendert_am', $qb->createNamedParameter($jetzt, IQueryBuilder::PARAM_DATE));
			if ($wert) {
				$qb->setValue('rechnung_gestellt_am', $qb->createNamedParameter($jetzt, IQueryBuilder::PARAM_DATE));
				$qb->setValue('rechnung_gestellt_von', $qb->createNamedParameter($benutzerId));
			}
			$qb->executeStatement();
		}

		return $this->fuerTicket($ticketId);
	}

	/**
	 * Setzt Abrechnungsart, Betrag/Stundensatz und Bemerkung in einem Zug —
	 * anders als die beiden Häkchen oben gehören diese Felder inhaltlich
	 * zusammen und werden vom Frontend immer gemeinsam übergeben (auch als
	 * null, wenn ein Feld geleert werden soll).
	 *
	 * @return array{rechnungspflichtig:bool,rechnung_gestellt:bool,rechnung_gestellt_am:?string,rechnung_gestellt_von:?string,abrechnungsart:?string,pauschalbetrag:?float,stundensatz:?float,bemerkung:?string,inklusiv_minuten:?int,takt_minuten:?int,takt_preis:?float}
	 */
	public function rechnungsdatenSetzen(
		int $ticketId,
		?string $abrechnungsart,
		?float $pauschalbetrag,
		?float $stundensatz,
		?string $bemerkung,
		?int $inklusivMinuten = null,
		?int $taktMinuten = null,
		?float $taktPreis = null,
	): array {
		$jetzt = new \DateTime();

		if ($this->zeileVorhanden($ticketId)) {
			$qb = $this->db->getQueryBuilder();
			$qb->update(self::TABELLE)
				->set('abrechnungsart', $qb->createNamedParameter($abrechnungsart))
				->set('pauschalbetrag', $qb->createNamedParameter($pauschalbetrag))
				->set('stundensatz', $qb->createNamedParameter($stundensatz))
				->set('bemerkung', $qb->createNamedParameter($bemerkung))
				->set('inklusiv_minuten', $qb->createNamedParameter($inklusivMinuten))
				->set('takt_minuten', $qb->createNamedParameter($taktMinuten))
				->set('takt_preis', $qb->createNamedParameter($taktPreis))
				->set('geaendert_am', $qb->createNamedParameter($jetzt, IQueryBuilder::PARAM_DATE))
				->where($qb->expr()->eq('ticket_id', $qb->createNamedParameter($ticketId, IQueryBuilder::PARAM_INT)));
			$qb->executeStatement();
		} else {
			$qb = $this->db->getQueryBuilder();
			$qb->insert(self::TABELLE)
				->setValue('ticket_id', $qb->createNamedParameter($ticketId, IQueryBuilder::PARAM_INT))
				->setValue('rechnungspflichtig', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT))
				->setValue('rechnung_gestellt', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT))
				->setValue('abrechnungsart', $qb->createNamedParameter($abrechnungsart))
				->setValue('pauschalbetrag', $qb->createNamedParameter($pauschalbetrag))
				->setValue('stundensatz', $qb->createNamedParameter($stundensatz))
				->setValue('bemerkung', $qb->createNamedParameter($bemerkung))
				->setValue('inklusiv_minuten', $qb->createNamedParameter($inklusivMinuten))
				->setValue('takt_minuten', $qb->createNamedParameter($taktMinuten))
				->setValue('takt_preis', $qb->createNamedParameter($taktPreis))
				->setValue('geaendert_am', $qb->createNamedParameter($jetzt, IQueryBuilder::PARAM_DATE));
			$qb->executeStatement();
		}

		return $this->fuerTicket($ticketId);
	}

	private function standard(): array {
		return [
			'rechnungspflichtig' => false,
			'rechnung_gestellt' => false,
			'rechnung_gestellt_am' => null,
			'rechnung_gestellt_von' => null,
			'abrechnungsart' => null,
			'pauschalbetrag' => null,
			'stundensatz' => null,
			'bemerkung' => null,
			'inklusiv_minuten' => null,
			'takt_minuten' => null,
			'takt_preis' => null,
		];
	}

	private function zeileAbbilden(array $zeile): array {
		return [
			'rechnungspflichtig' => (bool)$zeile['rechnungspflichtig'],
			'rechnung_gestellt' => (bool)$zeile['rechnung_gestellt'],
			'rechnung_gestellt_am' => $zeile['rechnung_gestellt_am'],
			'rechnung_gestellt_von' => $zeile['rechnung_gestellt_von'],
			'abrechnungsart' => $zeile['abrechnungsart'] ?? null,
			'pauschalbetrag' => isset($zeile['pauschalbetrag']) ? (float)$zeile['pauschalbetrag'] : null,
			'stundensatz' => isset($zeile['stundensatz']) ? (float)$zeile['stundensatz'] : null,
			'bemerkung' => $zeile['bemerkung'] ?? null,
			'inklusiv_minuten' => isset($zeile['inklusiv_minuten']) ? (int)$zeile['inklusiv_minuten'] : null,
			'takt_minuten' => isset($zeile['takt_minuten']) ? (int)$zeile['takt_minuten'] : null,
			'takt_preis' => isset($zeile['takt_preis']) ? (float)$zeile['takt_preis'] : null,
		];
	}

	private function zeileVorhanden(int $ticketId): bool {
		$qb = $this->db->getQueryBuilder();
		$qb->select('ticket_id')
			->from(self::TABELLE)
			->where($qb->expr()->eq('ticket_id', $qb->createNamedParameter($ticketId, IQueryBuilder::PARAM_INT)));

		return $qb->executeQuery()->fetchOne() !== false;
	}
}
