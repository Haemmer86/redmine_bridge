<?php

declare(strict_types=1);

namespace OCA\RedmineBridge\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Erweitert redmine_bridge_rechnung (siehe Version0025013/Version0025014)
 * um die Konditionen der Abrechnungsart "Pauschale + Zeitzuschlag":
 * ein Pauschalbetrag deckt eine Inklusivzeit ab (z. B. 60 Minuten), danach
 * wird je angefangenem Takt (z. B. 15 Minuten) ein fester Preis berechnet
 * (z. B. 12,50 €). Alle drei Werte sind je Ticket einstellbar und optional;
 * der Pauschalbetrag selbst steht weiter in `pauschalbetrag`.
 *
 * Bewusst eine eigene Migration statt die bestehenden zu ändern — eine
 * bereits ausgelieferte Migration wird nie nachträglich bearbeitet.
 */
class Version0025032Date20261010190000 extends SimpleMigrationStep {

	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		$tabelle = $schema->getTable('redmine_bridge_rechnung');

		if (!$tabelle->hasColumn('inklusiv_minuten')) {
			$tabelle->addColumn('inklusiv_minuten', 'integer', [
				'notnull' => false,
			]);
		}
		if (!$tabelle->hasColumn('takt_minuten')) {
			$tabelle->addColumn('takt_minuten', 'integer', [
				'notnull' => false,
			]);
		}
		if (!$tabelle->hasColumn('takt_preis')) {
			$tabelle->addColumn('takt_preis', 'decimal', [
				'notnull' => false,
				'precision' => 10,
				'scale' => 2,
			]);
		}

		return $schema;
	}
}
