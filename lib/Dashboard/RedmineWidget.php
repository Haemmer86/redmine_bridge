<?php

declare(strict_types=1);

namespace OCA\RedmineBridge\Dashboard;

use OCA\RedmineBridge\AppInfo\Application;
use OCA\RedmineBridge\Service\RedmineClient;
use OCP\Dashboard\IAPIWidget;
use OCP\Dashboard\IButtonWidget;
use OCP\Dashboard\IIconWidget;
use OCP\Dashboard\Model\WidgetButton;
use OCP\Dashboard\Model\WidgetItem;
use OCP\IL10N;
use OCP\IURLGenerator;

/**
 * Zeigt die zuletzt aktualisierten offenen Tickets im Nextcloud-Dashboard.
 *
 * Besonders relevant für die mobilen Apps: Anders als die Web-Oberfläche
 * haben die kein eigenes Navigationsziel für Fremd-Apps wie diese, aber
 * ein Dashboard-Tab — und der rendert genau solche API-Widgets (siehe
 * {@see IAPIWidget}) selbst, ganz ohne eigenes JavaScript dieser App.
 *
 * Bewusst ohne Zuordnung zu einzelnen Redmine-Benutzern: Diese App nutzt
 * einen einzigen, app-weiten API-Schlüssel (siehe {@see RedmineClient}) —
 * es gibt keine Verknüpfung zwischen Nextcloud-Konto und Redmine-Benutzer.
 * Die Liste zeigt deshalb die zuletzt aktualisierten offenen Tickets über
 * alle Projekte hinweg, nicht "meine" Tickets.
 */
class RedmineWidget implements IAPIWidget, IButtonWidget, IIconWidget {

	public function __construct(
		private readonly RedmineClient $redmine,
		private readonly IURLGenerator $url,
		private readonly IL10N $l10n,
	) {
	}

	public function getId(): string {
		return Application::APP_ID;
	}

	public function getTitle(): string {
		return $this->l10n->t('Redmine-Tickets');
	}

	public function getOrder(): int {
		return 10;
	}

	public function getIconClass(): string {
		// Ungenutzt — diese App hat keine registrierte CSS-Icon-Klasse,
		// stattdessen liefert getIconUrl() (IIconWidget) das eigene Symbol.
		return '';
	}

	public function getIconUrl(): string {
		return $this->url->getAbsoluteURL($this->url->imagePath(Application::APP_ID, 'redmine-symbol-dashboard.svg'));
	}

	public function getUrl(): ?string {
		return $this->url->linkToRouteAbsolute('redmine_bridge.page.index');
	}

	public function load(): void {
		// Reines API-Widget (siehe IAPIWidget) — kein eigenes JS-Bundle
		// nötig, Web- und mobile Apps rendern die Einträge aus getItems()
		// selbst.
	}

	public function getWidgetButtons(string $userId): array {
		return [
			new WidgetButton(
				WidgetButton::TYPE_MORE,
				$this->url->linkToRouteAbsolute('redmine_bridge.page.index'),
				$this->l10n->t('Alle Tickets öffnen'),
			),
		];
	}

	public function getItems(string $userId, ?string $since = null, int $limit = 7): array {
		if (!$this->redmine->konfiguriert()) {
			return [];
		}

		try {
			$antwort = $this->redmine->anfrage('GET', '/issues.json', [
				'status_id' => 'open',
				'limit' => $limit,
				'sort' => 'updated_on:desc',
			]);
		} catch (\Throwable) {
			// Dashboard soll bei Redmine-Ausfall nicht die ganze Seite
			// blockieren — einfach leer bleiben, wie bei den übrigen
			// Redmine-Aufrufen dieser App im Fehlerfall auch.
			return [];
		}

		$basisUrl = $this->url->linkToRouteAbsolute('redmine_bridge.page.index');

		$eintraege = [];
		foreach ($antwort['issues'] ?? [] as $ticket) {
			$eintraege[] = new WidgetItem(
				'#' . $ticket['id'] . ' ' . ($ticket['subject'] ?? ''),
				trim(($ticket['project']['name'] ?? '') . ' · ' . ($ticket['status']['name'] ?? '')),
				$basisUrl . '#/tickets/' . $ticket['id'],
				'',
				(string)($ticket['id'] ?? ''),
			);
		}

		return $eintraege;
	}
}
