<?php

declare(strict_types=1);

namespace OCA\RedmineBridge\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Erweitert redmine_bridge_rechnung (siehe Version0025013Date20260925220000)
 * um Abrechnungsart, Rechnungsbetrag/Stundensatz und eine Bemerkung je
 * Ticket. Alles optional und jederzeit im Ticket änderbar — wird beim
 * Anlegen nichts angegeben, bleiben die Spalten einfach NULL.
 *
 * Bewusst eine eigene Migration statt die bestehende Version0025013 zu
 * ändern — eine bereits ausgelieferte Migration wird nie nachträglich
 * bearbeitet.
 */
class Version0025014Date20260928203920 extends SimpleMigrationStep {

	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		$tabelle = $schema->getTable('redmine_bridge_rechnung');

		if (!$tabelle->hasColumn('abrechnungsart')) {
			// 'pauschale' oder 'stunden' — bewusst als String statt Enum, damit
			// spätere weitere Abrechnungsarten keine neue Migration brauchen.
			$tabelle->addColumn('abrechnungsart', 'string', [
				'notnull' => false,
				'length' => 20,
			]);
		}
		if (!$tabelle->hasColumn('pauschalbetrag')) {
			$tabelle->addColumn('pauschalbetrag', 'decimal', [
				'notnull' => false,
				'precision' => 10,
				'scale' => 2,
			]);
		}
		if (!$tabelle->hasColumn('stundensatz')) {
			$tabelle->addColumn('stundensatz', 'decimal', [
				'notnull' => false,
				'precision' => 10,
				'scale' => 2,
			]);
		}
		if (!$tabelle->hasColumn('bemerkung')) {
			$tabelle->addColumn('bemerkung', 'string', [
				'notnull' => false,
				'length' => 1000,
			]);
		}

		return $schema;
	}
}
