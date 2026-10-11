<?php

declare(strict_types=1);

namespace OCA\RedmineBridge\Controller;

use OCA\RedmineBridge\AppInfo\Application;
use OCA\RedmineBridge\Service\RedmineClient;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\FrontpageRoute;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IRequest;
use OCP\Util;

/**
 * Lädt die Vue-Oberfläche.
 *
 * Die eigentlichen Daten holt sich die Oberfläche selbst über
 * den API-Controllern ({@see BasisController}) — dieser Controller liefert nur noch das
 * HTML-Gerüst und bindet das gebaute JavaScript ein. Fehlt die
 * Grundkonfiguration, wird stattdessen der Hinweis dazu gezeigt, ganz
 * ohne die Vue-Anwendung überhaupt erst zu laden.
 */
class PageController extends Controller {

	public function __construct(
		IRequest $request,
		private readonly RedmineClient $redmine,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[FrontpageRoute(verb: 'GET', url: '/')]
	public function index(): TemplateResponse {
		if (!$this->redmine->konfiguriert()) {
			return new TemplateResponse(Application::APP_ID, 'main');
		}

		$version = $this->skriptVersion();
		$skriptName = Application::APP_ID . '-main' . ($version !== '' ? '-' . $version : '');
		Util::addScript(Application::APP_ID, $skriptName);
		// Kein Util::addStyle() mehr nötig: Seit der Build auf das "iife"-
		// Format umgestellt wurde, bettet Vite das CSS direkt ins
		// JavaScript ein (fügt beim Laden automatisch ein <style>-Element
		// ein) — es gibt keine separate .css-Datei mehr, die man einbinden
		// müsste. Siehe vite.config.js für die Begründung.

		return new TemplateResponse(Application::APP_ID, 'app');
	}

	/**
	 * Liest die App-Version aus appinfo/info.xml — dieselbe Quelle, aus der
	 * vite.config.js beim Bauen den Dateinamen der JS-Datei ableitet (z. B.
	 * "redmine_bridge-main-0.25.16.js"). So bleibt der hier angeforderte
	 * Skriptname immer exakt zum tatsächlich gebauten Dateinamen passend,
	 * ohne die Version an zwei Stellen von Hand pflegen zu müssen.
	 *
	 * Hintergrund: Cloudflare hat nach einem Deploy wiederholt noch die
	 * alte JS-Datei ausgeliefert, obwohl sich Nextclouds eigene
	 * Versions-Query (?v=...) auf derselben URL bereits geändert hatte —
	 * ein Dateiname, der sich bei jeder Version ändert, macht das
	 * strukturell unmöglich.
	 */
	private function skriptVersion(): string {
		$infoPfad = __DIR__ . '/../../appinfo/info.xml';
		$inhalt = @file_get_contents($infoPfad);
		if ($inhalt !== false && preg_match('/<version>([^<]+)<\/version>/', $inhalt, $treffer)) {
			return $treffer[1];
		}
		// Sollte appinfo/info.xml aus irgendeinem Grund nicht lesbar sein,
		// lädt das Skript ohne Versionsanhang — funktioniert nur, falls
		// eine so benannte Datei zufällig existiert (etwa direkt nach der
		// Umstellung), ist aber ein bewusst harmloser Fallback statt eines
		// Fehlers.
		return '';
	}
}
