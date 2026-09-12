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
 * Bewusst ohne eigene Datenbanktabellen: Redmine bleibt die einzige
 * Quelle der Wahrheit. Jeder Aufruf fragt live bei Redmine ab — das
 * vermeidet Synchronisations-Konflikte, kostet aber eine Anfrage pro
 * Ansicht. Sollte Redmine sich als zu langsam erweisen, ist ein
 * Zwischenspeicher (Cache) der naheliegende nächste Schritt, nicht
 * Teil des Grundgerüsts.
 */
class Application extends App implements IBootstrap {
	public const APP_ID = 'redmine_bridge';

	// Konfigurationsschlüssel für die Admin-Einstellungen
	public const CONF_BASIS_URL = 'basis_url';
	public const CONF_API_SCHLUESSEL = 'api_schluessel';
	public const CONF_BASIS_PFAD = 'basis_pfad';
	public const STANDARD_BASIS_PFAD = 'Redmine';

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
	}

	public function boot(IBootContext $context): void {
	}
}
