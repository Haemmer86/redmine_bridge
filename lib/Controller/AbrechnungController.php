<?php

declare(strict_types=1);

namespace OCA\RedmineBridge\Controller;

use OCA\RedmineBridge\Service\OdooClient;
use OCA\RedmineBridge\Service\RechnungsStatusService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\FrontpageRoute;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\IConfig;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Abrechnung: Rechnungsstatus je Ticket und Odoo-Sammelrechnung.
 *
 * Teil der JSON-API für die Vue-Oberfläche; gemeinsame Schutzlogik
 * (Mitarbeiter-Beschränkung, Fehlerverpackung) steht in {@see BasisController}.
 */
class AbrechnungController extends BasisController {

	public function __construct(
		IRequest $request,
		IUserSession $userSession,
		IGroupManager $groupManager,
		IConfig $config,
		private readonly OdooClient $odoo,
		private readonly RechnungsStatusService $rechnungsstatus,
	) {
		parent::__construct($request, $userSession, $groupManager, $config);
	}

	/**
	 * Setzt eines oder beide Felder des Rechnungsstatus eines Tickets —
	 * "rechnungspflichtig" (jederzeit im Ticket änderbar, nicht nur bei der
	 * Neuanlage) und/oder "rechnungGestellt" (Häkchen in Übersicht/Ticket).
	 * Nur mitgesendete Felder werden geändert, dasselbe Muster wie bei
	 * {@see ticketAktualisieren()}. Landet in einer eigenen kleinen
	 * Tabelle, nicht in Redmine — siehe {@see RechnungsStatusService}.
	 */
	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'PUT', url: '/api/tickets/{id}/rechnungsstatus')]
	public function rechnungsstatusSetzen(int $id, ?bool $rechnungspflichtig = null, ?bool $rechnungGestellt = null): DataResponse {
		return $this->geschuetzterAufruf(function () use ($id, $rechnungspflichtig, $rechnungGestellt) {
			if ($rechnungspflichtig !== null) {
				$this->rechnungsstatus->rechnungspflichtigSetzen($id, $rechnungspflichtig);
			}
			if ($rechnungGestellt !== null) {
				$this->rechnungsstatus->rechnungGestelltSetzen($id, $rechnungGestellt, $this->benutzerId());
			}

			return ['rechnungsstatus' => $this->rechnungsstatus->fuerTicket($id)];
		});
	}

	/**
	 * Setzt Abrechnungsart, Rechnungsbetrag/Stundensatz und Bemerkung eines
	 * Tickets — anders als {@see rechnungsstatusSetzen()} werden hier immer
	 * alle vier Felder gemeinsam geschrieben (auch als null, um ein Feld zu
	 * leeren), da sie im Frontend als ein zusammengehöriger Block bearbeitet
	 * werden. Landet ebenfalls in der eigenen Rechnungsstatus-Tabelle, siehe
	 * {@see RechnungsStatusService}.
	 */
	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'PUT', url: '/api/tickets/{id}/rechnungsdaten')]
	public function rechnungsdatenSetzen(
		int $id,
		?string $abrechnungsart = null,
		?float $pauschalbetrag = null,
		?float $stundensatz = null,
		?string $bemerkung = null,
		?int $inklusivMinuten = null,
		?int $taktMinuten = null,
		?float $taktPreis = null,
	): DataResponse {
		return $this->geschuetzterAufruf(fn () => [
			'rechnungsstatus' => $this->rechnungsstatus->rechnungsdatenSetzen(
				$id,
				$abrechnungsart,
				$pauschalbetrag,
				$stundensatz,
				$bemerkung,
				$inklusivMinuten,
				$taktMinuten,
				$taktPreis,
			),
		]);
	}

	/**
	 * Kundensuche für den Sammelrechnungs-Dialog (res.partner in Odoo).
	 * Leere Suche liefert die zuletzt geänderten Kontakte, wie Odoos eigene
	 * Kontaktsuche das auch macht.
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[FrontpageRoute(verb: 'GET', url: '/api/odoo/kunden')]
	public function odooKunden(string $suche = ''): DataResponse {
		if (!$this->odoo->konfiguriert()) {
			return new DataResponse(['fehler' => 'Odoo ist nicht konfiguriert (siehe Admin-Einstellungen).'], Http::STATUS_BAD_GATEWAY);
		}

		return $this->geschuetzterAufruf(fn () => ['kunden' => $this->odoo->kunden($suche)]);
	}

	/**
	 * Artikelsuche für den Sammelrechnungs-Dialog (product.product in
	 * Odoo) — jede Position braucht dort zwingend einen Artikel.
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[FrontpageRoute(verb: 'GET', url: '/api/odoo/artikel')]
	public function odooArtikel(string $suche = ''): DataResponse {
		if (!$this->odoo->konfiguriert()) {
			return new DataResponse(['fehler' => 'Odoo ist nicht konfiguriert (siehe Admin-Einstellungen).'], Http::STATUS_BAD_GATEWAY);
		}

		return $this->geschuetzterAufruf(fn () => ['artikel' => $this->odoo->artikel($suche)]);
	}

	/**
	 * Legt aus mehreren ausgewählten Tickets einen Rechnungsentwurf in Odoo
	 * an (eine Freitext-Position je Ticket, Betrag manuell im Dialog
	 * eingetragen). Odoo verbucht/versendet dabei nichts automatisch — der
	 * Entwurf bleibt in Odoo zur Kontrolle, bevor Mathias ihn bestätigt.
	 *
	 * Markiert im Anschluss automatisch alle Tickets, die tatsächlich eine
	 * Position in der Rechnung bekommen haben, als "Rechnung gestellt" —
	 * derselbe Rechnungsstatus wie beim manuellen Häkchen in Übersicht/
	 * Ticket (siehe {@see rechnungsstatusSetzen()}). Schlägt das
	 * Setzen für ein einzelnes Ticket fehl, wird das übrige Ergebnis davon
	 * nicht berührt — die Rechnung in Odoo wurde ja bereits angelegt, das
	 * darf ein einzelner fehlgeschlagener Status-Eintrag nicht als Fehler
	 * der ganzen Aktion erscheinen lassen.
	 *
	 * @param array<int,array{ticketId?:int,beschreibung?:string,betrag?:float|string}> $positionen
	 */
	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'POST', url: '/api/odoo/sammelrechnung')]
	public function odooSammelrechnung(int $partnerId = 0, array $positionen = []): DataResponse {
		if (!$this->odoo->konfiguriert()) {
			return new DataResponse(['fehler' => 'Odoo ist nicht konfiguriert (siehe Admin-Einstellungen).'], Http::STATUS_BAD_GATEWAY);
		}
		if ($partnerId <= 0 || count($positionen) === 0) {
			return new DataResponse(['fehler' => 'Kunde und mindestens eine Position werden benötigt.'], Http::STATUS_BAD_REQUEST);
		}

		return $this->geschuetzterAufruf(function () use ($partnerId, $positionen) {
			$ergebnis = $this->odoo->sammelrechnungErstellen($partnerId, $positionen);

			$benutzerId = $this->benutzerId();
			foreach ($ergebnis['ticketIds'] ?? [] as $ticketId) {
				try {
					$this->rechnungsstatus->rechnungGestelltSetzen($ticketId, true, $benutzerId);
				} catch (\Throwable) {
					// Bewusst verschluckt — s. Methodenkommentar oben.
				}
			}

			return $ergebnis;
		});
	}
}
