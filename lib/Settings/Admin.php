<?php

declare(strict_types=1);

namespace OCA\RedmineBruecke\Settings;

use OCA\RedmineBruecke\AppInfo\Application;
use OCA\RedmineBruecke\Service\RedmineClient;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IConfig;
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
	) {
	}

	public function getForm(): TemplateResponse {
		Util::addStyle(Application::APP_ID, 'settings-admin');

		$basisUrl = $this->config->getAppValue(Application::APP_ID, Application::CONF_BASIS_URL, '');
		$hatSchluessel = $this->config->getAppValue(Application::APP_ID, Application::CONF_API_SCHLUESSEL, '') !== '';
		$basisPfad = trim(
			$this->config->getAppValue(Application::APP_ID, Application::CONF_BASIS_PFAD, Application::STANDARD_BASIS_PFAD),
			'/',
		);

		$verbindung = $this->redmine->konfiguriert() ? $this->redmine->verbunden() : null;

		return new TemplateResponse(Application::APP_ID, 'settings/admin', [
			'basisUrl' => $basisUrl,
			'hatSchluessel' => $hatSchluessel,
			'basisPfad' => $basisPfad,
			'verbindung' => $verbindung,
			'actionUrl' => $this->url->linkToRoute('redmine_bruecke.settings.speichern'),
			'requesttoken' => Util::callRegister(),
		]);
	}

	public function getSection(): ?string {
		return Application::APP_ID;
	}

	public function getPriority(): int {
		return 10;
	}
}
