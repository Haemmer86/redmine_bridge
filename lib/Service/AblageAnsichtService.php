<?php

declare(strict_types=1);

namespace OCA\RedmineBridge\Service;

use OCP\Files\File;

/**
 * Bereitet die Ablage eines Tickets (Ordner + Dateien) für die Oberfläche
 * auf: liest aus den Dateien die Anzeigedaten — Zieladresse einer
 * `.url`-Verknüpfung, Kopfzeilen einer `.eml`, Inhalt einer eigenen
 * Notiz-`.txt` — und hängt die Schlagworte an.
 *
 * Reine Lese-/Anzeigeseite der Ablage. Geschrieben wird in
 * {@see AblageService}; das Format der Notiz-Textdateien (Erkennungszeile
 * `REDMINE-BRUECKE-NOTIZ`) ist dort festgelegt und wird hier in
 * notizInhaltParsen() wieder zerlegt — beide Stellen ändern sich nur
 * gemeinsam.
 */
class AblageAnsichtService {

	public function __construct(
		private readonly AblageService $ablage,
		private readonly TagService $tags,
	) {
	}

	/**
	 * Ordnerpfad und Dateiliste eines Tickets — für die Oberfläche
	 * aufbereitet (Verweis-Ziele, E-Mail-Kopfzeilen, Notizen, Schlagworte).
	 *
	 * @return array{ordnerPfad:?string,ordnerFehler:?string,dateien:list<array>}
	 */
	public function fuerTicket(string $benutzerId, array $ticket): array {
		try {
			$ordner = $this->ablage->ordnerFuerTicket($ticket, $benutzerId);
			$pfad = preg_replace('#^/[^/]+/files/#', '', $ordner->getPath()) ?? $ordner->getPath();

			$dateien = [];
			foreach ($this->ablage->dateienDesTickets($ticket, $benutzerId) as $datei) {
				$eintrag = [
					'id' => $datei->getId(),
					'name' => $datei->getName(),
					'groesse' => $datei->getSize(),
					'mime' => $datei->getMimeType(),
					'geaendert' => $datei->getMTime(),
					'url' => null,
					'eml' => null,
					'notiz' => null,
					'tags' => [],
				];
				$nameKlein = strtolower($eintrag['name']);

				// .url-Dateien sind unsere eigenen Verweise (z. B. zu
				// Paperless-ngx) — Zieladresse aus dem Inhalt lesen, damit
				// die Oberfläche daraus einen klickbaren Verweis statt
				// einer Bildvorschau macht.
				if (str_ends_with($nameKlein, '.url')) {
					try {
						if (preg_match('/^URL=(.+)$/mi', $datei->getContent(), $treffer) === 1) {
							$eintrag['url'] = trim($treffer[1]);
						}
					} catch (\Throwable) {
						// Inhalt nicht lesbar — bleibt einfach eine normale Datei
					}
				} elseif (str_ends_with($nameKlein, '.eml')) {
					// Nur die ersten Bytes lesen — E-Mails können durch
					// Anhänge groß werden, die Kopfzeilen stehen aber immer
					// ganz am Anfang, lange vor jedem Dateianhang.
					try {
						$eintrag['eml'] = $this->emlKopfzeilenLesen($datei);
					} catch (\Throwable) {
						// Kein lesbarer E-Mail-Kopf — bleibt eine normale Datei
					}
				} elseif (str_ends_with($nameKlein, '.txt')) {
					try {
						$roh = $datei->getContent();
						if (str_starts_with(ltrim($roh), 'REDMINE-BRUECKE-NOTIZ')) {
							$eintrag['notiz'] = $this->notizInhaltParsen($roh);
						}
					} catch (\Throwable) {
						// Nicht lesbar oder keine unserer Notizen — bleibt
						// eine normale Textdatei
					}
				}

				try {
					$eintrag['tags'] = $this->tags->tagsFuerDatei($datei->getId());
				} catch (\Throwable) {
					// Schlagwort-Dienst nicht verfügbar — Datei bleibt
					// trotzdem sichtbar, nur ohne Schlagworte.
				}

				$dateien[] = $eintrag;
			}

			return ['ordnerPfad' => $pfad, 'ordnerFehler' => null, 'dateien' => $dateien];
		} catch (\Throwable $e) {
			return ['ordnerPfad' => null, 'ordnerFehler' => $e->getMessage(), 'dateien' => []];
		}
	}

	/**
	 * Liest Von/Betreff/Datum aus einer `.eml`-Datei.
	 *
	 * Bewusst nur die ersten 8 KB — die Kopfzeilen einer E-Mail stehen immer
	 * ganz am Anfang, weit vor dem eigentlichen Inhalt und erst recht vor
	 * etwaigen Anhängen. `getContent()` würde bei einer E-Mail mit großem
	 * Anhang unnötig viel einlesen, nur um drei Kopfzeilen zu finden.
	 *
	 * Erkennt keine über mehrere Zeilen umgebrochenen Kopfzeilen (nach
	 * RFC 2822 technisch zulässig) — für eine Kurzanzeige in der Liste
	 * reicht der Regelfall (eine Zeile je Kopf) aus.
	 *
	 * @return array{von:?string,betreff:?string,datum:?string}|null
	 */
	private function emlKopfzeilenLesen(File $datei): ?array {
		$handle = $datei->fopen('r');
		if ($handle === false) {
			return null;
		}
		$kopf = fread($handle, 8192);
		fclose($handle);
		if ($kopf === false) {
			return null;
		}

		$von = null;
		$betreff = null;
		$datum = null;
		if (preg_match('/^From:\s*(.+)$/mi', $kopf, $t) === 1) {
			$von = $this->mimeKopfDekodieren(trim($t[1]));
		}
		if (preg_match('/^Subject:\s*(.+)$/mi', $kopf, $t) === 1) {
			$betreff = $this->mimeKopfDekodieren(trim($t[1]));
		}
		if (preg_match('/^Date:\s*(.+)$/mi', $kopf, $t) === 1) {
			$datum = trim($t[1]);
		}

		if ($von === null && $betreff === null) {
			return null;
		}

		return ['von' => $von, 'betreff' => $betreff, 'datum' => $datum, 'datumIso' => $this->datumIsoAus($datum)];
	}

	/**
	 * Wandelt eine beliebig formatierte Datumsangabe (z. B. den
	 * RFC-2822-Kopf einer E-Mail) in ein sortierbares ISO-Format um —
	 * fürs Einsortieren im chronologischen Gesprächsverlauf. Schlägt das
	 * Parsen fehl, bleibt der Eintrag einfach unsortiert am Ende.
	 */
	private function datumIsoAus(?string $datum): ?string {
		if ($datum === null || $datum === '') {
			return null;
		}
		try {
			return (new \DateTimeImmutable($datum))->format(\DATE_ATOM);
		} catch (\Throwable) {
			return null;
		}
	}

	/**
	 * E-Mail-Kopfzeilen sind bei nicht-lateinischen Zeichen (Umlaute!) nach
	 * RFC 2047 kodiert, z. B. `=?UTF-8?B?...?=` — ohne Dekodierung stünde in
	 * der Liste kryptischer Kauderwelsch statt "Müller".
	 */
	private function mimeKopfDekodieren(string $wert): string {
		$dekodiert = @mb_decode_mimeheader($wert);

		return $dekodiert !== false && $dekodiert !== '' ? $dekodiert : $wert;
	}

	/**
	 * Zerlegt den Inhalt einer eigenen Notiz-Textdatei wieder in ihre
	 * Bestandteile — das Gegenstück zu AblageService::notizAblegen().
	 *
	 * @return array{art:?string,datum:?string,ansprechpartner:?string,text:string}
	 */
	private function notizInhaltParsen(string $inhalt): array {
		$zeilen = explode("\n", str_replace("\r\n", "\n", $inhalt));
		$ergebnis = ['art' => null, 'datum' => null, 'ansprechpartner' => null, 'von' => null, 'betreff' => null, 'text' => ''];
		$imTextbereich = false;
		$textZeilen = [];

		foreach ($zeilen as $index => $zeile) {
			if ($index === 0) {
				continue; // die Erkennungszeile "REDMINE-BRUECKE-NOTIZ" selbst
			}
			if ($imTextbereich) {
				$textZeilen[] = $zeile;
				continue;
			}
			if (trim($zeile) === '') {
				$imTextbereich = true;
				continue;
			}
			if (preg_match('/^Art:\s*(.+)$/i', $zeile, $t) === 1) {
				$ergebnis['art'] = trim($t[1]);
			} elseif (preg_match('/^Datum:\s*(.+)$/i', $zeile, $t) === 1) {
				$ergebnis['datum'] = trim($t[1]);
			} elseif (preg_match('/^Ansprechpartner:\s*(.+)$/i', $zeile, $t) === 1) {
				$ergebnis['ansprechpartner'] = trim($t[1]);
			} elseif (preg_match('/^Von:\s*(.+)$/i', $zeile, $t) === 1) {
				$ergebnis['von'] = trim($t[1]);
			} elseif (preg_match('/^Betreff:\s*(.+)$/i', $zeile, $t) === 1) {
				$ergebnis['betreff'] = trim($t[1]);
			}
		}

		$ergebnis['text'] = trim(implode("\n", $textZeilen));
		$ergebnis['datumIso'] = $this->datumIsoAus($ergebnis['datum']);

		return $ergebnis;
	}
}
