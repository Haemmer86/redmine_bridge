<?php

declare(strict_types=1);

namespace OCA\RedmineBridge\AppInfo;

use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;

/**
 * Bindet eine Redmine-Instanz per REST-API in Nextcloud ein.
 *
 * Fast ohne eigene Datenbanktabellen: Redmine bleibt die einzige Quelle
 * der Wahrheit für Ticketinhalte, jeder Aufruf fragt live bei Redmine ab.
 * Einzige Ausnahme (seit 0.25.13): der Rechnungsstatus je Ticket
 * (rechnungspflichtig?/gestellt?) — eine reine Buchhaltungs-Angelegenheit
 * dieser App ohne Entsprechung in Redmine, siehe RechnungsStatusService
 * und die Migration Version0025013Date20260925220000.
 */
class Application extends App implements IBootstrap {
	public const APP_ID = 'redmine_bridge';

	// Konfigurationsschlüssel für die Admin-Einstellungen
	public const CONF_BASIS_URL = 'basis_url';
	public const CONF_API_SCHLUESSEL = 'api_schluessel';
	public const CONF_BASIS_PFAD = 'basis_pfad';
	public const STANDARD_BASIS_PFAD = 'Redmine';

	// Konfigurationsschlüssel für die Odoo-Anbindung (Sammelrechnungen aus
	// mehreren Tickets) — eigener Block, unabhängig von der Redmine-Anbindung.
	public const CONF_ODOO_URL = 'odoo_url';
	public const CONF_ODOO_DB = 'odoo_db';
	public const CONF_ODOO_BENUTZER = 'odoo_benutzer';
	public const CONF_ODOO_API_SCHLUESSEL = 'odoo_api_schluessel';

	// Konfigurationsschlüssel für die eingeschränkte "Meine Tickets"-Ansicht
	// für Mitarbeiter: Nextcloud-Gruppe, deren Mitglieder NUR diese Ansicht
	// sehen (die normale Ticketübersicht/Gantt/Verwaltung bleibt ihnen
	// versperrt) — siehe BasisController::istMitarbeiterNurAnsicht().
	public const CONF_MITARBEITER_GRUPPE = 'mitarbeiter_gruppe';

	public function __construct() {
		parent::__construct(self::APP_ID);
	}

	public function register(IRegistrationContext $context): void {
		// Namensschema hängt an der Konfiguration, nicht am Konstruktor der
		// aufrufenden Klasse — deshalb hier manuell verdrahtet, statt
		// Nextcloud die automatische Auflösung zu überlassen.
		$context->registerService(\OCA\RedmineBridge\Service\Namensschema::class, static function ($c): \OCA\RedmineBridge\Service\Namensschema {
			$config = $c->get(\OCP\IConfig::class);

			return new \OCA\RedmineBridge\Service\Namensschema(
				trim($config->getAppValue(self::APP_ID, self::CONF_BASIS_PFAD, self::STANDARD_BASIS_PFAD), '/'),
			);
		});

		// Zeigt offene Tickets im Nextcloud-Dashboard — insbesondere für
		// die mobilen Apps gedacht, die sonst keinen eigenen
		// Navigationspunkt für diese App haben (siehe RedmineWidget).
		$context->registerDashboardWidget(\OCA\RedmineBridge\Dashboard\RedmineWidget::class);
	}

	public function boot(IBootContext $context): void {
		// Regelmäßiger Fälligkeitsdatum→Kalender-Abgleich (siehe
		// KalenderAbgleich/TicketKalenderService) — IJobList::add() ist
		// idempotent (merkt sich Klasse+Argument), ein erneutes
		// Hinzufügen bei jedem Seitenaufruf legt also keine Duplikate an.
		$context->getAppContainer()->get(\OCP\BackgroundJob\IJobList::class)
			->add(\OCA\RedmineBridge\BackgroundJob\KalenderAbgleich::class);
	}
}
