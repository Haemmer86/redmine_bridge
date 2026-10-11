<?php

declare(strict_types=1);

namespace OCA\RedmineBridge\Settings;

use OCA\RedmineBridge\AppInfo\Application;
use OCA\RedmineBridge\Service\MitarbeiterZuordnungService;
use OCA\RedmineBridge\Service\OdooClient;
use OCA\RedmineBridge\Service\RedmineClient;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IConfig;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\Settings\ISettings;
use OCP\Util;

/**
 * Admin-Einstellungsseite für die Redmine-Verbindung.
 *
 * Der API-Schlüssel wird hier eingegeben und landet ausschließlich in der
 * Nextcloud-Konfiguration (Datenbank) — nie im Chat, nie in einer Datei,
 * die versehentlich geteilt werden könnte.
 */
class Admin implements ISettings {

	public function __construct(
		private readonly IConfig $config,
		private readonly IURLGenerator $url,
		private readonly IRequest $request,
		private readonly RedmineClient $redmine,
		private readonly OdooClient $odoo,
		private readonly IGroupManager $groupManager,
		private readonly MitarbeiterZuordnungService $mitarbeiterZuordnung,
	) {
	}

	public function getForm(): TemplateResponse {
		$basisUrl = $this->config->getAppValue(Application::APP_ID, Application::CONF_BASIS_URL, '');
		$hatSchluessel = $this->config->getAppValue(Application::APP_ID, Application::CONF_API_SCHLUESSEL, '') !== '';
		$basisPfad = trim(
			$this->config->getAppValue(Application::APP_ID, Application::CONF_BASIS_PFAD, Application::STANDARD_BASIS_PFAD),
			'/',
		);

		$verbindung = $this->redmine->konfiguriert() ? $this->redmine->verbunden() : null;

		$odooUrl = $this->config->getAppValue(Application::APP_ID, Application::CONF_ODOO_URL, '');
		$odooDb = $this->config->getAppValue(Application::APP_ID, Application::CONF_ODOO_DB, '');
		$odooBenutzer = $this->config->getAppValue(Application::APP_ID, Application::CONF_ODOO_BENUTZER, '');
		$odooHatSchluessel = $this->config->getAppValue(Application::APP_ID, Application::CONF_ODOO_API_SCHLUESSEL, '') !== '';
		$odooVerbindung = $this->odoo->konfiguriert() ? $this->odoo->verbunden() : null;

		return new TemplateResponse(Application::APP_ID, 'settings/admin', [
			'basisUrl' => $basisUrl,
			'hatSchluessel' => $hatSchluessel,
			'basisPfad' => $basisPfad,
			'verbindung' => $verbindung,
			'odooUrl' => $odooUrl,
			'odooDb' => $odooDb,
			'odooBenutzer' => $odooBenutzer,
			'odooHatSchluessel' => $odooHatSchluessel,
			'odooVerbindung' => $odooVerbindung,
			'actionUrl' => $this->url->linkToRoute('redmine_bridge.settings.speichern'),
			'requesttoken' => Util::callRegister(),
		] + $this->mitarbeiterDaten());
	}

	/**
	 * Daten für den "Meine Tickets"-Abschnitt: alle Nextcloud-Gruppen (zur
	 * Auswahl der Mitarbeiter-Gruppe), deren aktuelle Mitglieder (zur
	 * Zuordnung je Mitglied) und Redmines Benutzerliste (zur Auswahl, wer
	 * wer ist) samt der bereits gespeicherten Zuordnungen.
	 */
	private function mitarbeiterDaten(): array {
		$gruppen = [];
		foreach ($this->groupManager->search('') as $gruppe) {
			$gruppen[] = ['gid' => $gruppe->getGID(), 'name' => $gruppe->getDisplayName()];
		}

		$ausgewaehlteGruppe = $this->config->getAppValue(Application::APP_ID, Application::CONF_MITARBEITER_GRUPPE, '');

		$mitglieder = [];
		if ($ausgewaehlteGruppe !== '' && $this->groupManager->groupExists($ausgewaehlteGruppe)) {
			foreach ($this->groupManager->get($ausgewaehlteGruppe)->getUsers() as $benutzer) {
				$mitglieder[] = ['uid' => $benutzer->getUID(), 'name' => $benutzer->getDisplayName()];
			}
		}

		// Redmines Benutzerliste verlangt auf vielen Instanzen
		// Administratorrechte für den verwendeten API-Schlüssel (siehe
		// TicketController::formulardaten()) — schlägt das fehl, bleibt die
		// Auswahl in der Vorlage einfach leer, statt die ganze Seite zu
		// blockieren; die bereits gespeicherten Zuordnungen bleiben trotzdem
		// sichtbar (über ihren zwischengespeicherten Namen).
		$redmineBenutzer = [];
		if ($this->redmine->konfiguriert()) {
			try {
				$antwort = $this->redmine->anfrage('GET', '/users.json', ['status' => 1, 'limit' => 100]);
				foreach ($antwort['users'] ?? [] as $benutzer) {
					$name = trim(($benutzer['firstname'] ?? '') . ' ' . ($benutzer['lastname'] ?? ''));
					$redmineBenutzer[] = ['id' => $benutzer['id'], 'name' => $name !== '' ? $name : ($benutzer['login'] ?? ('#' . $benutzer['id']))];
				}
			} catch (\Throwable) {
				$redmineBenutzer = [];
			}
		}

		return [
			'gruppen' => $gruppen,
			'ausgewaehlteGruppe' => $ausgewaehlteGruppe,
			'mitglieder' => $mitglieder,
			'redmineBenutzer' => $redmineBenutzer,
			'zuordnungen' => $this->mitarbeiterZuordnung->alle(),
		];
	}

	public function getSection(): ?string {
		return Application::APP_ID;
	}

	public function getPriority(): int {
		return 10;
	}
}
