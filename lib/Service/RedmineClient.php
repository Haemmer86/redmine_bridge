<?php

declare(strict_types=1);

namespace OCA\RedmineBruecke\Service;

use OCA\RedmineBruecke\AppInfo\Application;
use OCP\Http\Client\IClientService;
use OCP\IConfig;

/**
 * Spricht die Redmine-REST-API an.
 *
 * Nutzt Nextclouds eigenen HTTP-Client (IClientService) statt curl direkt —
 * damit greifen automatisch die Proxy- und Zeitüberschreitungs-Einstellungen
 * der Nextcloud-Instanz, ohne dass diese App sich selbst darum kümmern muss.
 *
 * Redmine authentifiziert entweder über den Kopfeintrag
 * `X-Redmine-API-Key` oder per HTTP-Basisauthentifizierung mit dem
 * API-Schlüssel als Benutzername. Der Kopfeintrag ist der übliche Weg
 * und funktioniert unabhängig davon, ob Redmine Basisauthentifizierung
 * überhaupt zulässt.
 */
class RedmineClient {

	public function __construct(
		private readonly IClientService $clientService,
		private readonly IConfig $config,
	) {
	}

	/**
	 * Ob eine Basis-Adresse und ein API-Schlüssel hinterlegt sind.
	 *
	 * Kein Verbindungstest — nur die Prüfung, ob überhaupt etwas
	 * konfiguriert wurde. Für den echten Verbindungstest: {@see verbunden()}.
	 */
	public function konfiguriert(): bool {
		return $this->basisUrl() !== '' && $this->apiSchluessel() !== '';
	}

	public function basisUrl(): string {
		return rtrim(
			$this->config->getAppValue(Application::APP_ID, Application::CONF_BASIS_URL, ''),
			'/',
		);
	}

	private function apiSchluessel(): string {
		return $this->config->getAppValue(Application::APP_ID, Application::CONF_API_SCHLUESSEL, '');
	}

	/**
	 * Prüft, ob die hinterlegten Zugangsdaten tatsächlich funktionieren.
	 *
	 * Ruft `/users/current.json` ab — das ist der leichtestmögliche
	 * authentifizierte Aufruf, den jede Redmine-Instanz beantwortet,
	 * unabhängig von Projektrechten.
	 *
	 * @return array{ok:bool,benutzer?:string,fehler?:string}
	 */
	public function verbunden(): array {
		if (!$this->konfiguriert()) {
			return ['ok' => false, 'fehler' => 'Basis-Adresse oder API-Schlüssel fehlt.'];
		}

		try {
			$antwort = $this->anfrage('GET', '/users/current.json');
		} catch (\Throwable $e) {
			return ['ok' => false, 'fehler' => $e->getMessage()];
		}

		$name = trim(
			(string)($antwort['user']['firstname'] ?? '') . ' ' . (string)($antwort['user']['lastname'] ?? ''),
		);

		return ['ok' => true, 'benutzer' => $name !== '' ? $name : ($antwort['user']['login'] ?? '')];
	}

	/**
	 * Führt eine Anfrage gegen die Redmine-API aus und liefert die
	 * dekodierte JSON-Antwort.
	 *
	 * @param array<string,mixed> $daten Für POST/PUT als JSON-Rumpf, für GET als Abfrageparameter
	 * @return array<string,mixed>
	 * @throws \RuntimeException bei Netzwerk- oder HTTP-Fehlern
	 */
	public function anfrage(string $methode, string $pfad, array $daten = []): array {
		if (!$this->konfiguriert()) {
			throw new \RuntimeException('Redmine ist nicht konfiguriert (Basis-Adresse/API-Schlüssel fehlt).');
		}

		$client = $this->clientService->newClient();
		$url = $this->basisUrl() . $pfad;

		$optionen = [
			'headers' => [
				'X-Redmine-API-Key' => $this->apiSchluessel(),
				'Content-Type' => 'application/json',
			],
			'timeout' => 15,
		];

		try {
			$antwort = match (strtoupper($methode)) {
				'GET' => $client->get($url, $optionen + ['query' => $daten]),
				'POST' => $client->post($url, $optionen + ['body' => json_encode($daten, \JSON_THROW_ON_ERROR)]),
				'PUT' => $client->put($url, $optionen + ['body' => json_encode($daten, \JSON_THROW_ON_ERROR)]),
				'DELETE' => $client->delete($url, $optionen),
				default => throw new \InvalidArgumentException('Unbekannte HTTP-Methode: ' . $methode),
			};
		} catch (\Throwable $e) {
			// Nextclouds HTTP-Client wirft bei 4xx/5xx-Antworten eine Exception,
			// statt einfach den Statuscode zurückzugeben — hier in eine
			// verständliche Meldung übersetzen, statt die technische
			// Guzzle-Exception unverändert durchzureichen.
			throw new \RuntimeException(
				'Redmine-Anfrage fehlgeschlagen (' . $methode . ' ' . $pfad . '): ' . $e->getMessage(),
				previous: $e,
			);
		}

		$koerper = (string)$antwort->getBody();
		if ($koerper === '') {
			// DELETE und manche PUT-Aufrufe antworten ohne Rumpf (204 No Content)
			return [];
		}

		try {
			return json_decode($koerper, true, flags: \JSON_THROW_ON_ERROR);
		} catch (\JsonException $e) {
			throw new \RuntimeException('Redmine lieferte keine gültige JSON-Antwort: ' . $e->getMessage());
		}
	}
}
