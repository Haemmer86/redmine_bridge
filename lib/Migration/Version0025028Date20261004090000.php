<?php

declare(strict_types=1);

namespace OCA\RedmineBridge\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Legt die Tabelle an, mit der verfolgt wird, welcher Kalendertermin
 * (Kalender-ID + Objekt-URI des zugrunde liegenden CalDAV-Backends) zu
 * welchem Ticket gehört (Version 0.25.28) — siehe
 * {@see \OCA\RedmineBridge\Service\TicketKalenderService}.
 *
 * Nur Nachverfolgung, keine Kopie von Ticketinhalten: der Termin selbst
 * (Betreff, Datum) liegt ausschließlich im Nextcloud-Kalender des
 * zuständigen Mitarbeiters, hier steht nur, wo genau er liegt, damit er
 * beim nächsten Abgleich wiedergefunden, aktualisiert oder gelöscht
 * werden kann.
 */
class Version0025028Date20261004090000 extends SimpleMigrationStep {

	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if (!$schema->hasTable('redmine_bridge_kalender')) {
			$tabelle = $schema->createTable('redmine_bridge_kalender');
			$tabelle->addColumn('ticket_id', 'integer', [
				'notnull' => true,
				'unsigned' => true,
			]);
			$tabelle->addColumn('nextcloud_user_id', 'string', [
				'notnull' => true,
				'length' => 64,
			]);
			$tabelle->addColumn('kalender_id', 'integer', [
				'notnull' => true,
				'unsigned' => true,
			]);
			$tabelle->addColumn('objekt_uri', 'string', [
				'notnull' => true,
				'length' => 255,
			]);
			$tabelle->addColumn('geaendert_am', 'datetime', [
				'notnull' => false,
			]);
			$tabelle->setPrimaryKey(['ticket_id']);
		}

		return $schema;
	}
}
