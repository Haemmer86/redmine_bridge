<?php

declare(strict_types=1);

namespace OCA\RedmineBridge\Dashboard;

use OCA\RedmineBridge\AppInfo\Application;
use OCA\RedmineBridge\Service\RedmineClient;
use OCP\Dashboard\IAPIWidget;
use OCP\Dashboard\IAPIWidgetV2;
use OCP\Dashboard\IButtonWidget;
use OCP\Dashboard\IIconWidget;
use OCP\Dashboard\Model\WidgetButton;
use OCP\Dashboard\Model\WidgetItem;
use OCP\Dashboard\Model\WidgetItems;
use OCP\IL10N;
use OCP\IURLGenerator;
use Psr\Log\LoggerInterface;

/**
 * Zeigt die zuletzt aktualisierten offenen Tickets im Nextcloud-Dashboard.
 *
 * Besonders relevant für die mobilen Apps: Anders als die Web-Oberfläche
 * haben die kein eigenes Navigationsziel für Fremd-Apps wie diese, aber
 * ein Dashboard-Tab — und der rendert genau solche API-Widgets (siehe
 * {@see IAPIWidget}) selbst, ganz ohne eigenes JavaScript dieser App.
 *
 * Implementiert sowohl das ältere {@see IAPIWidget} (V1) als auch
 * {@see IAPIWidgetV2} (V2, seit NC 27.1): Der Dashboard-OCS-Endpunkt
 * `api/v2/widget-items` ruft ausschließlich Widgets auf, die
 * IAPIWidgetV2 implementieren — ohne das bleibt die Karte leer, obwohl
 * der Aufruf mit „200 OK" durchläuft (kein Fehler, das Widget wird von
 * diesem Endpunkt schlicht komplett übersprungen). V1 bleibt zusätzlich
 * bestehen, falls ein Client noch den alten Endpunkt `api/v1/widget-items`
 * nutzt.
 *
 * Bewusst ohne Zuordnung zu einzelnen Redmine-Benutzern: Diese App nutzt
 * einen einzigen, app-weiten API-Schlüssel (siehe {@see RedmineClient}) —
 * es gibt keine Verknüpfung zwischen Nextcloud-Konto und Redmine-Benutzer.
 * Die Liste zeigt deshalb die zuletzt aktualisierten offenen Tickets über
 * alle Projekte hinweg, nicht "meine" Tickets.
 */
class RedmineWidget implements IAPIWidget, IAPIWidgetV2, IButtonWidget, IIconWidget {

	public function __construct(
		private readonly RedmineClient $redmine,
		private readonly IURLGenerator $url,
		private readonly IL10N $l10n,
		private readonly LoggerInterface $logger,
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
		// Reines API-Widget (siehe IAPIWidget/IAPIWidgetV2) — kein eigenes
		// JS-Bundle nötig, Web- und mobile Apps rendern die Einträge aus
		// getItems()/getItemsV2() selbst.
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
		return $this->ticketEintraege($since, $limit);
	}

	public function getItemsV2(string $userId, ?string $since = null, int $limit = 7): WidgetItems {
		return new WidgetItems(
			$this->ticketEintraege($since, $limit),
			$this->l10n->t('Keine offenen Tickets.'),
		);
	}

	/**
	 * @return WidgetItem[]
	 */
	private function ticketEintraege(?string $since, int $limit): array {
		if (!$this->redmine->konfiguriert()) {
			$this->logger->warning('Redmine-Dashboard-Widget: nicht konfiguriert (Basis-Adresse/API-Schlüssel fehlt).', ['app' => Application::APP_ID]);
			return [];
		}

		try {
			$antwort = $this->redmine->anfrage('GET', '/issues.json', [
				'status_id' => 'open',
				'limit' => $limit,
				'sort' => 'updated_on:desc',
			]);
		} catch (\Throwable $e) {
			// Dashboard soll bei Redmine-Ausfall nicht die ganze Seite
			// blockieren — einfach leer bleiben, wie bei den übrigen
			// Redmine-Aufrufen dieser App im Fehlerfall auch. Der Grund
			// wird aber geloggt, damit er sich im Fehlerfall nachvollziehen
			// lässt (dieser Aufruf lief sonst komplett stumm).
			$this->logger->warning('Redmine-Dashboard-Widget: Abruf fehlgeschlagen: ' . $e->getMessage(), ['app' => Application::APP_ID, 'exception' => $e]);
			return [];
		}

		$this->logger->info('Redmine-Dashboard-Widget: ' . count($antwort['issues'] ?? []) . ' Ticket(s) erhalten (von total_count=' . ($antwort['total_count'] ?? '?') . ').', ['app' => Application::APP_ID]);

		$basisUrl = $this->url->linkToRouteAbsolute('redmine_bridge.page.index');

		// Nach Projekt gruppiert statt in Redmines chronologischer Reihenfolge:
		// array_push() erhält innerhalb jeder Projekt-Gruppe die
		// Original-Sortierung (aktuellste zuerst), PHP-Arrays behalten dabei
		// die Einfüge-Reihenfolge der Projekte selbst — das erste in den
		// Ergebnissen aufgetauchte Projekt (also mit dem zuletzt aktualisierten
		// Ticket) steht damit auch in der gruppierten Liste zuerst.
		$nachProjekt = [];
		foreach ($antwort['issues'] ?? [] as $ticket) {
			$nachProjekt[$ticket['project']['id'] ?? 0][] = $ticket;
		}
		$gruppiert = [];
		foreach ($nachProjekt as $projektTickets) {
			array_push($gruppiert, ...$projektTickets);
		}

		$eintraege = [];
		foreach ($gruppiert as $ticket) {
			$projektId = (int)($ticket['project']['id'] ?? 0);
			$projektName = (string)($ticket['project']['name'] ?? '');
			$eintraege[] = new WidgetItem(
				'#' . $ticket['id'] . ' ' . ($ticket['subject'] ?? ''),
				trim($projektName . ' · ' . ($ticket['status']['name'] ?? '')),
				$basisUrl . '#/tickets/' . $ticket['id'],
				$this->projektIconUrl($projektId, $projektName),
				(string)($ticket['id'] ?? ''),
			);
		}

		return $eintraege;
	}

	/**
	 * Icon für die Ticket-Zeile: rundes Farb-Icon des zugehörigen Projekts
	 * (siehe {@see \OCA\RedmineBridge\Service\ProjektFarbeService}) statt
	 * des generischen "?"-Platzhalters, den Nextcloud sonst für Widget-Items
	 * ohne eigenes Icon anzeigt. Dieselbe Farbe erscheint als Punkt neben dem
	 * Projektnamen in der Ticket-Übersichtstabelle.
	 */
	private function projektIconUrl(int $projektId, string $projektName): string {
		return $this->url->linkToRouteAbsolute('redmine_bridge.api.projektIcon', [
			'projektId' => $projektId,
			'name' => $projektName,
		]);
	}
}
