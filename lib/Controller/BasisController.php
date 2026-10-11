<?php

declare(strict_types=1);

namespace OCA\RedmineBridge\Controller;

use OCA\RedmineBridge\AppInfo\Application;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\DataResponse;
use OCP\IConfig;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Gemeinsame Grundlage aller JSON-API-Controller dieser App.
 *
 * Hier — und nur hier — sitzt die Zugriffsbeschränkung für die
 * Mitarbeiter-Gruppe: `geschuetzterAufruf()` sperrt jeden Endpunkt, der
 * nicht ausdrücklich mit `mitarbeiterErlaubt: true` freigegeben wurde
 * (siehe MitarbeiterController). Neue Endpunkte laufen deshalb immer über
 * diese Methode.
 *
 * Lese-Endpunkte sind ohne CSRF-Prüfung erreichbar (unverändert wie ein
 * normaler Seitenaufruf), Schreib-Endpunkte (PUT/POST) verlangen den
 * `requesttoken`-Kopfeintrag, den die Nextcloud-Oberfläche automatisch
 * mitschickt.
 */
abstract class BasisController extends Controller {

	public function __construct(
		IRequest $request,
		protected readonly IUserSession $userSession,
		protected readonly IGroupManager $groupManager,
		protected readonly IConfig $config,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	protected function benutzerId(): string {
		return $this->userSession->getUser()?->getUID() ?? '';
	}

	/**
	 * Ob das angemeldete Konto Mitglied der (in den Admin-Einstellungen
	 * konfigurierten) Mitarbeiter-Gruppe ist — unabhängig davon, ob es
	 * deswegen auch tatsächlich auf die eingeschränkte Ansicht beschränkt
	 * wird (siehe `istMitarbeiterNurAnsicht()` direkt darunter). Für die
	 * Oberfläche: ob "Meine Tickets" für dieses Konto überhaupt relevant
	 * ist (siehe `sitzung()`).
	 */
	protected function istMitarbeiterGruppenMitglied(): bool {
		$gruppe = $this->config->getAppValue(Application::APP_ID, Application::CONF_MITARBEITER_GRUPPE, '');
		if ($gruppe === '') {
			return false;
		}

		return $this->groupManager->isInGroup($this->benutzerId(), $gruppe);
	}

	/**
	 * Ob das angemeldete Konto auf die eingeschränkte "Meine
	 * Tickets"-Ansicht beschränkt wird — die normale
	 * Ticketübersicht/-verwaltung bleibt ihm dann versperrt (siehe
	 * `geschuetzterAufruf()` unten).
	 *
	 * Admin-Konten werden davon ausgenommen, selbst wenn sie zusätzlich
	 * Mitglied der Mitarbeiter-Gruppe sind (z. B. um sich selbst Tickets
	 * zuordnen und die eigene Technikersicht nutzen zu können, ohne dafür
	 * die normale Ticketverwaltung/Admin-Einstellungen zu verlieren) — sie
	 * behalten die volle Oberfläche und erreichen "Meine Tickets" bei
	 * Bedarf freiwillig über einen eigenen Navigationspunkt (siehe
	 * TicketListe.vue), statt fest darauf verengt zu werden.
	 */
	protected function istMitarbeiterNurAnsicht(): bool {
		if (!$this->istMitarbeiterGruppenMitglied()) {
			return false;
		}

		return !$this->groupManager->isAdmin($this->benutzerId());
	}

	/**
	 * Verpackt eine Redmine-Anfrage einheitlich als DataResponse.
	 *
	 * Redmine antwortet bei Validierungsfehlern mit HTTP 422 und einer
	 * `errors`-Liste im Rumpf — die geht in der generischen
	 * RuntimeException von RedmineClient sonst verloren. Deshalb hier
	 * gezielt behandelt, statt alles unter "Anfrage fehlgeschlagen" zu
	 * verstecken.
	 *
	 * `$mitarbeiterErlaubt` ist die EINZIGE Stelle, an der die
	 * Mitarbeiter-Beschränkung durchgesetzt wird: Da praktisch jeder
	 * Lese-/Schreib-Endpunkt dieser Klasse über diese Methode läuft, sperrt
	 * der Standardwert `false` neue wie bestehende Endpunkte automatisch für
	 * die Mitarbeiter-Gruppe, sofern sie nicht einzeln und bewusst mit
	 * `true` freigegeben wurden (siehe die `meine*`-Endpunkte unten). Sicher
	 * per Voreinstellung statt pro Endpunkt einzeln daran denken zu müssen.
	 */
	protected function geschuetzterAufruf(callable $fn, bool $mitarbeiterErlaubt = false): DataResponse {
		if (!$mitarbeiterErlaubt && $this->istMitarbeiterNurAnsicht()) {
			return new DataResponse(['fehler' => 'Kein Zugriff auf diese Funktion.'], Http::STATUS_FORBIDDEN);
		}

		try {
			return new DataResponse($fn());
		} catch (\Throwable $e) {
			return new DataResponse(['fehler' => $e->getMessage()], Http::STATUS_BAD_GATEWAY);
		}
	}
}
