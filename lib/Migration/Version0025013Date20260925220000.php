<?php

declare(strict_types=1);

namespace OCA\RedmineBridge\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Legt die Tabelle für den Rechnungsstatus je Ticket an (rechnungspflichtig
 * ja/nein, Rechnung gestellt ja/nein/wann/von wem).
 *
 * Bewusste Ausnahme vom sonstigen "keine eigene Datenbanktabelle"-Grundsatz
 * dieser App (siehe Application.php) — Redmine hat dafür kein eigenes Feld,
 * und es handelt sich um eine reine Buchhaltungs-Angelegenheit dieser App,
 * keine Redmine-Ticketeigenschaft. Ein Ticket ohne Zeile in dieser Tabelle
 * gilt als "nicht rechnungspflichtig" (bewusste Vorgabe für Bestandstickets,
 * die vor dieser Funktion angelegt wurden).
 */
class Version0025013Date20260925220000 extends SimpleMigrationStep {

	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if (!$schema->hasTable('redmine_bridge_rechnung')) {
			$tabelle = $schema->createTable('redmine_bridge_rechnung');
			$tabelle->addColumn('ticket_id', 'integer', [
				'notnull' => true,
				'unsigned' => true,
			]);
			$tabelle->addColumn('rechnungspflichtig', 'smallint', [
				'notnull' => true,
				'default' => 0,
			]);
			$tabelle->addColumn('rechnung_gestellt', 'smallint', [
				'notnull' => true,
				'default' => 0,
			]);
			$tabelle->addColumn('rechnung_gestellt_am', 'datetime', [
				'notnull' => false,
			]);
			$tabelle->addColumn('rechnung_gestellt_von', 'string', [
				'notnull' => false,
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
