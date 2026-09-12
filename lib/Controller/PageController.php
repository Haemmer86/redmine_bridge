<?php

declare(strict_types=1);

namespace OCA\RedmineBruecke\Controller;

use OCA\RedmineBruecke\AppInfo\Application;
use OCA\RedmineBruecke\Service\RedmineClient;
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
 * {@see ApiController} — dieser Controller liefert nur noch das
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
			return new TemplateResponse(Application::APP_ID, 'main', [
				'konfiguriert' => false,
				'verbindung' => null,
			]);
		}

		Util::addScript(Application::APP_ID, Application::APP_ID . '-main');
		Util::addStyle(Application::APP_ID, Application::APP_ID . '-main');

		return new TemplateResponse(Application::APP_ID, 'app');
	}
}
