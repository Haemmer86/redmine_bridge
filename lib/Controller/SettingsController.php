<?php

declare(strict_types=1);

namespace OCA\RedmineBridge\Controller;

use OCA\RedmineBridge\AppInfo\Application;
use OCA\RedmineBridge\Service\MitarbeiterZuordnungService;
use OCA\RedmineBridge\Service\RedmineClient;
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
		private readonly RedmineClient $redmine,
		private readonly MitarbeiterZuordnungService $mitarbeiterZuordnung,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	/**
	 * Die Admin-Section selbst ist nur für Administratoren erreichbar, aber
	 * die Ziel-URL des Formulars liegt außerhalb dieser Absicherung — wer die
	 * Adresse direkt aufruft, muss hier separat geprüft werden.
	 */
	#[FrontpageRoute(verb: 'POST', url: '/settings/speichern')]
	public function speichern(
		string $basisUrl = '',
		string $apiSchluessel = '',
		string $basisPfad = '',
		string $odooUrl = '',
		string $odooDb = '',
		string $odooBenutzer = '',
		string $odooApiSchluessel = '',
		bool $mitarbeiterFormular = false,
		string $mitarbeiterGruppe = '',
		array $zuordnung = [],
	): RedirectResponse {
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

		// Odoo-Anbindung (Sammelrechnungen) — dieselbe Logik wie oben bei
		// Redmine: der API-Schlüssel wird nie im Klartext angezeigt, ein
		// leeres Feld beim erneuten Speichern löscht ihn deshalb nicht.
		$odooUrl = rtrim(trim($odooUrl), '/');
		if ($odooUrl !== '') {
			if (!preg_match('#^https?://#i', $odooUrl)) {
				$odooUrl = 'https://' . $odooUrl;
			}
			$this->config->setAppValue(Application::APP_ID, Application::CONF_ODOO_URL, $odooUrl);
		}

		$odooDb = trim($odooDb);
		if ($odooDb !== '') {
			$this->config->setAppValue(Application::APP_ID, Application::CONF_ODOO_DB, $odooDb);
		}

		$odooBenutzer = trim($odooBenutzer);
		if ($odooBenutzer !== '') {
			$this->config->setAppValue(Application::APP_ID, Application::CONF_ODOO_BENUTZER, $odooBenutzer);
		}

		if (trim($odooApiSchluessel) !== '') {
			$this->config->setAppValue(Application::APP_ID, Application::CONF_ODOO_API_SCHLUESSEL, trim($odooApiSchluessel));
		}

		// Mitarbeiter-Gruppe + Zuordnungen für die eingeschränkte
		// "Meine Tickets"-Ansicht — eigenes Formular (eigener versteckter
		// Marker `mitarbeiterFormular`), damit ein Speichern der
		// Redmine-/Odoo-Verbindung nicht versehentlich die Gruppe auf "aus"
		// zurücksetzt: leer ist hier ein bewusstes "aus", nicht "nicht
		// angegeben" (anders als oben bei URL/Schlüssel), das Formular
		// bietet dafür immer eine "— keine —"-Option an.
		if ($mitarbeiterFormular) {
			$this->config->setAppValue(Application::APP_ID, Application::CONF_MITARBEITER_GRUPPE, trim($mitarbeiterGruppe));

			// Für die Anzeige im Admin-Formular wird der Redmine-Name
			// aufgelöst statt dem Client zu vertrauen — schlägt der Abruf
			// fehl, wird trotzdem gespeichert, nur ohne hübschen Namen (die
			// Zuordnung selbst hängt ausschließlich an redmine_user_id).
			$redmineNamen = [];
			try {
				$antwort = $this->redmine->anfrage('GET', '/users.json', ['status' => 1, 'limit' => 100]);
				foreach ($antwort['users'] ?? [] as $benutzer) {
					$name = trim(($benutzer['firstname'] ?? '') . ' ' . ($benutzer['lastname'] ?? ''));
					$redmineNamen[(int)$benutzer['id']] = $name !== '' ? $name : ($benutzer['login'] ?? '');
				}
			} catch (\Throwable) {
				// Siehe Kommentar oben — ohne Namen wird trotzdem gespeichert.
			}

			foreach ($zuordnung as $nextcloudUserId => $redmineUserId) {
				$redmineUserId = (int)$redmineUserId;
				if ($redmineUserId <= 0) {
					// Leere Auswahl ("— keiner —") entfernt eine bestehende
					// Zuordnung, statt sie stehen zu lassen.
					$this->mitarbeiterZuordnung->entfernen((string)$nextcloudUserId);
					continue;
				}
				$this->mitarbeiterZuordnung->setzen(
					(string)$nextcloudUserId,
					$redmineUserId,
					$redmineNamen[$redmineUserId] ?? null,
				);
			}
		}

		return new RedirectResponse($ziel);
	}
}
