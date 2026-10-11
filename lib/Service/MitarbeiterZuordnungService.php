<?php

declare(strict_types=1);

namespace OCA\RedmineBridge\Service;

use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * Verwaltet die Zuordnung Nextcloud-Konto → Redmine-Benutzer (Bearbeiter).
 *
 * Grundlage für die eingeschränkte "Meine Tickets"-Ansicht für Mitarbeiter
 * (siehe MitarbeiterController::meineTickets() und ::meinTicketAktualisieren()):
 * ohne eine Zuordnung kann ein Nextcloud-Konto dort keine Tickets sehen,
 * unabhängig davon, ob es Mitglied der dafür konfigurierten Nextcloud-Gruppe
 * ist. Gepflegt wird die Zuordnung ausschließlich von einem Administrator
 * in den Admin-Einstellungen, siehe SettingsController::speichern().
 */
class MitarbeiterZuordnungService {

	private const TABELLE = 'redmine_bridge_mitarbeiter';

	public function __construct(
		private readonly IDBConnection $db,
	) {
	}

	/**
	 * @return array<string,array{redmine_user_id:int,redmine_user_name:?string}>
	 */
	public function alle(): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from(self::TABELLE);

		$ergebnis = [];
		foreach ($qb->executeQuery()->fetchAll() as $zeile) {
			$ergebnis[(string)$zeile['nextcloud_user_id']] = [
				'redmine_user_id' => (int)$zeile['redmine_user_id'],
				'redmine_user_name' => $zeile['redmine_user_name'] ?? null,
			];
		}

		return $ergebnis;
	}

	/**
	 * @return array{redmine_user_id:int,redmine_user_name:?string}|null
	 */
	public function fuer(string $nextcloudUserId): ?array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from(self::TABELLE)
			->where($qb->expr()->eq('nextcloud_user_id', $qb->createNamedParameter($nextcloudUserId)));

		$zeile = $qb->executeQuery()->fetch();
		if ($zeile === false) {
			return null;
		}

		return [
			'redmine_user_id' => (int)$zeile['redmine_user_id'],
			'redmine_user_name' => $zeile['redmine_user_name'] ?? null,
		];
	}

	public function setzen(string $nextcloudUserId, int $redmineUserId, ?string $redmineUserName): void {
		$jetzt = new \DateTime();

		if ($this->fuer($nextcloudUserId) !== null) {
			$qb = $this->db->getQueryBuilder();
			$qb->update(self::TABELLE)
				->set('redmine_user_id', $qb->createNamedParameter($redmineUserId, IQueryBuilder::PARAM_INT))
				->set('redmine_user_name', $qb->createNamedParameter($redmineUserName))
				->set('geaendert_am', $qb->createNamedParameter($jetzt, IQueryBuilder::PARAM_DATE))
				->where($qb->expr()->eq('nextcloud_user_id', $qb->createNamedParameter($nextcloudUserId)));
			$qb->executeStatement();
			return;
		}

		$qb = $this->db->getQueryBuilder();
		$qb->insert(self::TABELLE)
			->setValue('nextcloud_user_id', $qb->createNamedParameter($nextcloudUserId))
			->setValue('redmine_user_id', $qb->createNamedParameter($redmineUserId, IQueryBuilder::PARAM_INT))
			->setValue('redmine_user_name', $qb->createNamedParameter($redmineUserName))
			->setValue('geaendert_am', $qb->createNamedParameter($jetzt, IQueryBuilder::PARAM_DATE));
		$qb->executeStatement();
	}

	public function entfernen(string $nextcloudUserId): void {
		$qb = $this->db->getQueryBuilder();
		$qb->delete(self::TABELLE)
			->where($qb->expr()->eq('nextcloud_user_id', $qb->createNamedParameter($nextcloudUserId)));
		$qb->executeStatement();
	}
}
