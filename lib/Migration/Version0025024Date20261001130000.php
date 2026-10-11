<?php

declare(strict_types=1);

namespace OCA\RedmineBridge\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Legt die Tabelle für den "Zuständigen Mitarbeiter" je Ticket an (Version
 * 0.25.24) — ein eigenes, von Redmines "Zugewiesen an" unabhängiges Feld,
 * mit dem jedes Mitglied der Mitarbeiter-Gruppe einem Ticket zugeordnet
 * werden kann, unabhängig von einer Redmine-Projektmitgliedschaft. Siehe
 * {@see \OCA\RedmineBridge\Service\TicketZustaendigkeitService}.
 */
class Version0025024Date20261001130000 extends SimpleMigrationStep {

	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if (!$schema->hasTable('redmine_bridge_zustaendig')) {
			$tabelle = $schema->createTable('redmine_bridge_zustaendig');
			$tabelle->addColumn('ticket_id', 'integer', [
				'notnull' => true,
				'unsigned' => true,
			]);
			$tabelle->addColumn('nextcloud_user_id', 'string', [
				'notnull' => true,
				'length' => 64,
			]);
			$tabelle->addColumn('geaendert_am', 'datetime', [
				'notnull' => false,
			]);
			$tabelle->setPrimaryKey(['ticket_id']);
		}

		return $schema;
	}
}
