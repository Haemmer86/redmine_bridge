<?php

declare(strict_types=1);

namespace OCA\RedmineBridge\Service;

use OCA\RedmineBridge\AppInfo\Application;
use OCP\Http\Client\IClientService;
use OCP\IConfig;

/**
 * Spricht eine Odoo-Instanz über deren JSON-RPC-Schnittstelle an
 * (`/jsonrpc`) — dieselbe externe API wie das offizielle XML-RPC-Beispiel
 * in Odoos Dokumentation, nur als JSON statt XML. Vorteil gegenüber
 * XML-RPC: kommt ohne PHPs `xmlrpc`-Erweiterung aus, die im
 * Nextcloud-Container nicht garantiert vorhanden ist.
 *
 * Nur für die Sammelrechnungs-Funktion gedacht — legt ausschließlich
 * RechnungsENTWÜRFE an (`account.move`, Status bleibt "draft"). Odoo
 * validiert/verbucht/versendet hier nichts automatisch, das bleibt
 * bewusst ein manueller Schritt in Odoo selbst.
 */
class OdooClient {

	public function __construct(
		private readonly IClientService $clientService,
		private readonly IConfig $config,
	) {
	}

	public function konfiguriert(): bool {
		return $this->url() !== '' && $this->db() !== '' && $this->benutzer() !== '' && $this->apiSchluessel() !== '';
	}

	public function url(): string {
		return rtrim(
			$this->config->getAppValue(Application::APP_ID, Application::CONF_ODOO_URL, ''),
			'/',
		);
	}

	public function db(): string {
		return $this->config->getAppValue(Application::APP_ID, Application::CONF_ODOO_DB, '');
	}

	public function benutzer(): string {
		return $this->config->getAppValue(Application::APP_ID, Application::CONF_ODOO_BENUTZER, '');
	}

	private function apiSchluessel(): string {
		return $this->config->getAppValue(Application::APP_ID, Application::CONF_ODOO_API_SCHLUESSEL, '');
	}

	/**
	 * Prüft, ob die hinterlegten Zugangsdaten tatsächlich funktionieren —
	 * Anmeldung plus ein leichtgewichtiger authentifizierter Aufruf (eigenen
	 * Benutzernamen lesen), analog zu RedmineClient::verbunden().
	 *
	 * @return array{ok:bool,benutzer?:string,fehler?:string}
	 */
	public function verbunden(): array {
		if (!$this->konfiguriert()) {
			return ['ok' => false, 'fehler' => 'Adresse, Datenbank, Benutzer oder API-Schlüssel fehlt.'];
		}

		try {
			$uid = $this->uid();
			$daten = $this->ausfuehren('res.users', 'read', [[$uid]], ['fields' => ['name']]);
		} catch (\Throwable $e) {
			return ['ok' => false, 'fehler' => $e->getMessage()];
		}

		return ['ok' => true, 'benutzer' => $daten[0]['name'] ?? $this->benutzer()];
	}

	/**
	 * Sucht Kunden (res.partner) für die Auswahl im Sammelrechnungs-Dialog.
	 * Leere Suche liefert die zuletzt angelegten/geänderten Kontakte, wie in
	 * Odoos eigener Kontaktsuche üblich.
	 *
	 * @return array<int,array{id:int,name:string,email:?string}>
	 */
	public function kunden(string $suche = '', int $limit = 20): array {
		$domain = $suche !== ''
			? [['name', 'ilike', $suche]]
			: [];

		$treffer = $this->ausfuehren('res.partner', 'search_read', [$domain], [
			'fields' => ['id', 'name', 'email'],
			'limit' => $limit,
			'order' => $suche !== '' ? 'name asc' : 'write_date desc',
		]);

		return array_map(static fn (array $p): array => [
			'id' => (int)$p['id'],
			'name' => (string)$p['name'],
			'email' => $p['email'] !== false ? (string)$p['email'] : null,
		], $treffer);
	}

	/**
	 * Legt einen Rechnungsentwurf in Odoo an: eine Position je übergebenem
	 * Eintrag (Freitext-Beschreibung + manuell eingetragener Betrag),
	 * Menge fest auf 1 — es gibt hier keinen Stundensatz/keine Mengenlogik,
	 * der Betrag ist bereits die gewünschte Positionssumme.
	 *
	 * @param array<int,array{beschreibung:string,betrag:float}> $positionen
	 * @return array{id:int,url:string}
	 */
	public function sammelrechnungErstellen(int $partnerId, array $positionen): array {
		$zeilen = [];
		foreach ($positionen as $position) {
			$beschreibung = trim((string)($position['beschreibung'] ?? ''));
			$betrag = (float)($position['betrag'] ?? 0);
			if ($beschreibung === '' || $betrag <= 0) {
				continue;
			}
			// Odoos "Command"-Tupel für One2many-Felder: (0, 0, Werte) =
			// neue verknüpfte Zeile anlegen.
			$zeilen[] = [0, 0, [
				'name' => $beschreibung,
				'quantity' => 1,
				'price_unit' => $betrag,
			]];
		}

		if (count($zeilen) === 0) {
			throw new \RuntimeException('Keine gültige Position (Beschreibung + Betrag > 0 nötig).');
		}

		$id = $this->ausfuehren('account.move', 'create', [[
			'move_type' => 'out_invoice',
			'partner_id' => $partnerId,
			'invoice_line_ids' => $zeilen,
		]]);

		return [
			'id' => (int)$id,
			'url' => $this->rechnungsUrl((int)$id),
		];
	}

	private function rechnungsUrl(int $id): string {
		return $this->url() . '/web#id=' . $id . '&view_type=form&model=account.move';
	}

	/**
	 * Meldet sich an und liefert die Odoo-Benutzer-ID. Wird pro Aufruf neu
	 * geholt statt zwischengespeichert — bei der geringen Aufrufhäufigkeit
	 * dieser Funktion (Sammelrechnung ist ein bewusster, seltener Klick,
	 * keine Listenansicht mit vielen Anfragen) unnötig, dafür kein
	 * zusätzlicher Zustand zwischen Anfragen zu verwalten.
	 */
	private function uid(): int {
		$ergebnis = $this->jsonRpc('common', 'login', [$this->db(), $this->benutzer(), $this->apiSchluessel()]);
		if (!is_int($ergebnis) || $ergebnis <= 0) {
			throw new \RuntimeException('Odoo-Anmeldung fehlgeschlagen — Datenbank, Benutzer oder API-Schlüssel prüfen.');
		}
		return $ergebnis;
	}

	/**
	 * Führt eine ORM-Methode aus (`execute_kw`) — das Arbeitspferd der
	 * externen Odoo-API, entspricht z. B.
	 * `models.execute_kw(db, uid, password, modell, methode, args, kwargs)`
	 * aus Odoos offizieller Dokumentation.
	 *
	 * @param array<int,mixed> $args Positionsargumente für die ORM-Methode
	 * @param array<string,mixed> $kwargs Schlüsselwort-Argumente (z. B. "fields", "limit")
	 */
	private function ausfuehren(string $modell, string $methode, array $args = [], array $kwargs = []): mixed {
		if (!$this->konfiguriert()) {
			throw new \RuntimeException('Odoo ist nicht konfiguriert (Adresse/Datenbank/Benutzer/API-Schlüssel fehlt).');
		}

		return $this->jsonRpc('object', 'execute_kw', [
			$this->db(),
			$this->uid(),
			$this->apiSchluessel(),
			$modell,
			$methode,
			$args,
			$kwargs,
		]);
	}

	/**
	 * Roher JSON-RPC-2.0-Aufruf gegen Odoos `/jsonrpc`-Endpunkt. Odoo bettet
	 * den eigentlichen Dienst/Methode/Argumente-Aufbau (wie bei XML-RPC) in
	 * das `params`-Feld ein, statt eigene JSON-RPC-Methodennamen pro
	 * Endpunkt zu verwenden — deshalb hier immer `"method": "call"`.
	 */
	private function jsonRpc(string $dienst, string $methode, array $args): mixed {
		if ($this->url() === '') {
			throw new \RuntimeException('Odoo-Adresse fehlt.');
		}

		$client = $this->clientService->newClient();
		$rumpf = [
			'jsonrpc' => '2.0',
			'method' => 'call',
			'params' => [
				'service' => $dienst,
				'method' => $methode,
				'args' => $args,
			],
			'id' => random_int(1, 1_000_000_000),
		];

		try {
			$antwort = $client->post($this->url() . '/jsonrpc', [
				'headers' => ['Content-Type' => 'application/json'],
				'body' => json_encode($rumpf, \JSON_THROW_ON_ERROR),
				'timeout' => 15,
			]);
		} catch (\Throwable $e) {
			throw new \RuntimeException('Odoo-Anfrage fehlgeschlagen: ' . $e->getMessage(), previous: $e);
		}

		$koerper = (string)$antwort->getBody();

		try {
			$daten = json_decode($koerper, true, flags: \JSON_THROW_ON_ERROR);
		} catch (\JsonException $e) {
			throw new \RuntimeException('Odoo lieferte keine gültige JSON-Antwort: ' . $e->getMessage());
		}

		if (isset($daten['error'])) {
			$meldung = $daten['error']['data']['message']
				?? $daten['error']['message']
				?? 'Unbekannter Odoo-Fehler';
			throw new \RuntimeException('Odoo lehnte die Anfrage ab: ' . $meldung);
		}

		return $daten['result'] ?? null;
	}
}
