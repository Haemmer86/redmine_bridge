<?php

declare(strict_types=1);

namespace OCA\RedmineBridge\Controller;

use OCA\RedmineBridge\Service\AblageAnsichtService;
use OCA\RedmineBridge\Service\AblageService;
use OCA\RedmineBridge\Service\RedmineClient;
use OCA\RedmineBridge\Service\TagService;
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
 * Ticket-Ablage: Dateien, Links, Notizen, E-Mails und Schlagworte.
 *
 * Teil der JSON-API für die Vue-Oberfläche; gemeinsame Schutzlogik
 * (Mitarbeiter-Beschränkung, Fehlerverpackung) steht in {@see BasisController}.
 */
class AblageController extends BasisController {

	public function __construct(
		IRequest $request,
		IUserSession $userSession,
		IGroupManager $groupManager,
		IConfig $config,
		private readonly RedmineClient $redmine,
		private readonly AblageService $ablage,
		private readonly AblageAnsichtService $ablageAnsicht,
		private readonly TagService $tags,
	) {
		parent::__construct($request, $userSession, $groupManager, $config);
	}

	/**
	 * Legt eine hochgeladene Datei im Ticket-Ordner ab.
	 *
	 * Landet NUR in Nextcloud, wie besprochen — kein Redmine-Anhang.
	 */
	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'POST', url: '/api/tickets/{id}/dateien')]
	public function dateiHochladen(int $id): DataResponse {
		$hochgeladen = $this->request->getUploadedFile('datei');
		if ($hochgeladen === null || ($hochgeladen['error'] ?? \UPLOAD_ERR_NO_FILE) !== \UPLOAD_ERR_OK) {
			return new DataResponse(['fehler' => 'Keine Datei erhalten.'], Http::STATUS_BAD_REQUEST);
		}

		return $this->geschuetzterAufruf(function () use ($id, $hochgeladen) {
			$antwort = $this->redmine->anfrage('GET', "/issues/{$id}.json");
			$ticket = $antwort['issue'] ?? null;
			if ($ticket === null) {
				throw new \RuntimeException('Ticket nicht gefunden.');
			}

			$inhalt = fopen($hochgeladen['tmp_name'], 'rb');
			$this->ablage->dateiAblegen($ticket, $this->benutzerId(), $hochgeladen['name'], $inhalt);

			return $this->ablageAnsicht->fuerTicket($this->benutzerId(), $ticket);
		});
	}

	/**
	 * Legt einen Anhang einer aus dem Posteingang zugeordneten E-Mail ab,
	 * benannt nach demselben Schema wie der zugehörige
	 * Gesprächsverlauf-Eintrag, siehe
	 * {@see AblageService::mailAnhangAblegen()}.
	 *
	 * Landet NUR in Nextcloud, wie besprochen — kein Redmine-Anhang.
	 */
	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'POST', url: '/api/tickets/{id}/mail-anhang')]
	public function mailAnhangAblegen(int $id, string $betreff = '', string $datum = ''): DataResponse {
		$hochgeladen = $this->request->getUploadedFile('datei');
		if ($hochgeladen === null || ($hochgeladen['error'] ?? \UPLOAD_ERR_NO_FILE) !== \UPLOAD_ERR_OK) {
			return new DataResponse(['fehler' => 'Keine Datei erhalten.'], Http::STATUS_BAD_REQUEST);
		}

		return $this->geschuetzterAufruf(function () use ($id, $betreff, $datum, $hochgeladen) {
			$antwort = $this->redmine->anfrage('GET', "/issues/{$id}.json");
			$ticket = $antwort['issue'] ?? null;
			if ($ticket === null) {
				throw new \RuntimeException('Ticket nicht gefunden.');
			}

			$zeitpunkt = null;
			if ($datum !== '') {
				try {
					$zeitpunkt = new \DateTimeImmutable($datum);
				} catch (\Throwable) {
					$zeitpunkt = null;
				}
			}

			$inhalt = fopen($hochgeladen['tmp_name'], 'rb');
			$this->ablage->mailAnhangAblegen(
				$ticket,
				$this->benutzerId(),
				$betreff !== '' ? $betreff : '(kein Betreff)',
				$zeitpunkt,
				$hochgeladen['name'],
				$inhalt,
			);

			return $this->ablageAnsicht->fuerTicket($this->benutzerId(), $ticket);
		});
	}

	/**
	 * Legt einen Verweis (z. B. auf ein Paperless-ngx-Dokument) als kleine
	 * Verknüpfungsdatei im Ticket-Ordner ab — siehe
	 * {@see AblageService::urlAblegen()} für das Format.
	 */
	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'POST', url: '/api/tickets/{id}/urls')]
	public function urlAblegen(int $id, string $bezeichnung, string $url): DataResponse {
		if (!preg_match('#^https?://#i', trim($url))) {
			return new DataResponse(['fehler' => 'Nur http(s)-Adressen sind erlaubt.'], Http::STATUS_BAD_REQUEST);
		}

		return $this->geschuetzterAufruf(function () use ($id, $bezeichnung, $url) {
			$antwort = $this->redmine->anfrage('GET', "/issues/{$id}.json");
			$ticket = $antwort['issue'] ?? null;
			if ($ticket === null) {
				throw new \RuntimeException('Ticket nicht gefunden.');
			}

			$this->ablage->urlAblegen($ticket, $this->benutzerId(), $bezeichnung, trim($url));

			return $this->ablageAnsicht->fuerTicket($this->benutzerId(), $ticket);
		});
	}

	/**
	 * Legt eine Telefon- oder allgemeine Notiz als kleine Textdatei im
	 * Ticket-Ordner ab — für einen nachvollziehbaren Gesprächsverlauf, siehe
	 * {@see AblageService::notizAblegen()}.
	 */
	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'POST', url: '/api/tickets/{id}/notizen')]
	public function notizAblegen(int $id, string $art, string $text, string $ansprechpartner = ''): DataResponse {
		if (trim($text) === '') {
			return new DataResponse(['fehler' => 'Notiztext darf nicht leer sein.'], Http::STATUS_BAD_REQUEST);
		}

		return $this->geschuetzterAufruf(function () use ($id, $art, $text, $ansprechpartner) {
			$antwort = $this->redmine->anfrage('GET', "/issues/{$id}.json");
			$ticket = $antwort['issue'] ?? null;
			if ($ticket === null) {
				throw new \RuntimeException('Ticket nicht gefunden.');
			}

			$this->ablage->notizAblegen(
				$ticket,
				$this->benutzerId(),
				$art !== '' ? $art : 'Notiz',
				trim($text),
				trim($ansprechpartner) !== '' ? trim($ansprechpartner) : null,
			);

			return $this->ablageAnsicht->fuerTicket($this->benutzerId(), $ticket);
		});
	}

	/**
	 * Legt eine aus dem Posteingang zugeordnete E-Mail als lesbaren
	 * Gesprächsverlauf-Eintrag ab (kein rohes `.eml`), siehe
	 * {@see AblageService::mailVerlaufAblegen()}.
	 */
	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'POST', url: '/api/tickets/{id}/mail-verlauf')]
	public function mailVerlaufAblegen(int $id, string $von, string $betreff, string $text, string $datum = '', string $nachrichtKennung = ''): DataResponse {
		return $this->geschuetzterAufruf(function () use ($id, $von, $betreff, $text, $datum, $nachrichtKennung) {
			$antwort = $this->redmine->anfrage('GET', "/issues/{$id}.json");
			$ticket = $antwort['issue'] ?? null;
			if ($ticket === null) {
				throw new \RuntimeException('Ticket nicht gefunden.');
			}

			if ($nachrichtKennung !== '' && $this->ablage->mailBereitsZugeordnet($ticket, $this->benutzerId(), $nachrichtKennung)) {
				throw new \RuntimeException('Diese E-Mail ist diesem Ticket bereits zugeordnet.');
			}

			$zeitpunkt = null;
			if ($datum !== '') {
				try {
					$zeitpunkt = new \DateTimeImmutable($datum);
				} catch (\Throwable) {
					$zeitpunkt = null;
				}
			}

			$this->ablage->mailVerlaufAblegen(
				$ticket,
				$this->benutzerId(),
				$von,
				$betreff !== '' ? $betreff : '(kein Betreff)',
				$text,
				$zeitpunkt,
				$nachrichtKennung,
			);

			return $this->ablageAnsicht->fuerTicket($this->benutzerId(), $ticket);
		});
	}

	/**
	 * Löscht eine Datei aus der Ticket-Ablage.
	 */
	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'DELETE', url: '/api/tickets/{id}/dateien/{dateiId}')]
	public function dateiLoeschen(int $id, int $dateiId): DataResponse {
		return $this->geschuetzterAufruf(function () use ($id, $dateiId) {
			$antwort = $this->redmine->anfrage('GET', "/issues/{$id}.json");
			$ticket = $antwort['issue'] ?? null;
			if ($ticket === null) {
				throw new \RuntimeException('Ticket nicht gefunden.');
			}

			$this->ablage->dateiLoeschen($dateiId, $this->benutzerId());

			return $this->ablageAnsicht->fuerTicket($this->benutzerId(), $ticket);
		});
	}

	/**
	 * Alle in Nextcloud vorhandenen Schlagworte, für Vorschläge in der
	 * Oberfläche.
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[FrontpageRoute(verb: 'GET', url: '/api/tags')]
	public function tags(): DataResponse {
		return $this->geschuetzterAufruf(fn () => ['tags' => $this->tags->alleTags()]);
	}

	/**
	 * Setzt ein Schlagwort auf einer Ablage-Datei — legt es bei Bedarf an.
	 */
	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'POST', url: '/api/dateien/{dateiId}/tags')]
	public function tagSetzen(int $dateiId, string $name): DataResponse {
		return $this->geschuetzterAufruf(fn () => ['tags' => $this->tags->tagSetzen($dateiId, $name)]);
	}

	/**
	 * Entfernt ein Schlagwort von einer Ablage-Datei.
	 */
	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'DELETE', url: '/api/dateien/{dateiId}/tags/{tagId}')]
	public function tagEntfernen(int $dateiId, int $tagId): DataResponse {
		return $this->geschuetzterAufruf(fn () => ['tags' => $this->tags->tagEntfernen($dateiId, $tagId)]);
	}
}
