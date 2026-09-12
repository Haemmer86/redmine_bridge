<?php

declare(strict_types=1);

namespace OCA\RedmineBruecke\Controller;

use OCA\RedmineBruecke\AppInfo\Application;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\FrontpageRoute;
use OCP\AppFramework\Http\RedirectResponse;
use OCP\IConfig;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Nimmt das Admin-Einstellungsformular entgegen.
 *
 * Klassisches Formular statt AJAX — dieselbe Begründung wie beim
 * Ablage-Pfad in IKS Vorgänge: für zwei Textfelder lohnt sich kein
 * eigener JS-Build-Schritt für die Administrationsoberfläche.
 */
class SettingsController extends Controller {

	public function __construct(
		IRequest $request,
		private readonly IConfig $config,
		private readonly IGroupManager $groupManager,
		private readonly IUserSession $userSession,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	/**
	 * Die Admin-Section selbst ist nur für Administratoren erreichbar, aber
	 * die Ziel-URL des Formulars liegt außerhalb dieser Absicherung — wer die
	 * Adresse direkt aufruft, muss hier separat geprüft werden.
	 */
	#[FrontpageRoute(verb: 'POST', url: '/settings/speichern')]
	public function speichern(string $basisUrl = '', string $apiSchluessel = '', string $basisPfad = ''): RedirectResponse {
		$ziel = $this->request->getHeader('Referer') !== ''
			? $this->request->getHeader('Referer')
			: '/';

		$benutzer = $this->userSession->getUser();
		if ($benutzer === null || !$this->groupManager->isAdmin($benutzer->getUID())) {
			return new RedirectResponse($ziel);
		}

		$basisUrl = rtrim(trim($basisUrl), '/');
		if ($basisUrl !== '') {
			// http:// ergänzen, falls vergessen — sonst schlägt jede Anfrage
			// mit einer kryptischen "Invalid URL"-Meldung fehl, statt mit
			// einem verständlichen Hinweis.
			if (!preg_match('#^https?://#i', $basisUrl)) {
				$basisUrl = 'https://' . $basisUrl;
			}
			$this->config->setAppValue(Application::APP_ID, Application::CONF_BASIS_URL, $basisUrl);
		}

		// Nur überschreiben, wenn tatsächlich ein neuer Wert eingegeben wurde —
		// das Formular zeigt den bestehenden Schlüssel aus Sicherheitsgründen
		// nie im Klartext an, ein leeres Feld beim erneuten Speichern der URL
		// soll den vorhandenen Schlüssel deshalb nicht löschen.
		if (trim($apiSchluessel) !== '') {
			$this->config->setAppValue(Application::APP_ID, Application::CONF_API_SCHLUESSEL, trim($apiSchluessel));
		}

		$basisPfad = trim($basisPfad, '/');
		if ($basisPfad !== '') {
			$this->config->setAppValue(Application::APP_ID, Application::CONF_BASIS_PFAD, $basisPfad);
		}

		return new RedirectResponse($ziel);
	}
}
