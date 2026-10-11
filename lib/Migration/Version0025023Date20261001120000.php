<?php

declare(strict_types=1);

namespace OCA\RedmineBridge\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Legt die Tabelle für die Mitarbeiter-Zuordnung an (Version 0.25.23):
 * welcher Redmine-Benutzer (Bearbeiter) gehört zu welchem Nextcloud-Konto.
 *
 * Grundlage für die eingeschränkte "Meine Tickets"-Ansicht: Redmine selbst
 * kennt Nextcloud-Konten nicht, diese Zuordnung muss also in dieser App
 * gepflegt werden (durch einen Administrator in den Admin-Einstellungen,
 * siehe SettingsController). Bewusste Ausnahme vom sonstigen
 * "keine eigene Datenbanktabelle"-Grundsatz dieser App — dieselbe
 * Begründung wie bei redmine_bridge_rechnung (Version0025013): keine
 * Entsprechung in Redmine, reine Konfigurationsangelegenheit dieser App.
 */
class Version0025023Date20261001120000 extends SimpleMigrationStep {

	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if (!$schema->hasTable('redmine_bridge_mitarbeiter')) {
			$tabelle = $schema->createTable('redmine_bridge_mitarbeiter');
			$tabelle->addColumn('nextcloud_user_id', 'string', [
				'notnull' => true,
				'length' => 64,
			]);
			$tabelle->addColumn('redmine_user_id', 'integer', [
				'notnull' => true,
				'unsigned' => true,
			]);
			// Nur zur Anzeige in den Admin-Einstellungen zwischengespeichert
			// (Name zum Zeitpunkt der Zuordnung) — maßgeblich bleibt immer
			// redmine_user_id, die bei jedem Ticketabruf frisch gegen Redmine
			// aufgelöst wird.
			$tabelle->addColumn('redmine_user_name', 'string', [
				'notnull' => false,
				'length' => 255,
			]);
			$tabelle->addColumn('geaendert_am', 'datetime', [
				'notnull' => false,
			]);
			$tabelle->setPrimaryKey(['nextcloud_user_id']);
		}

		return $schema;
	}
}
