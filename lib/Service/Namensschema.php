<?php

declare(strict_types=1);

namespace OCA\RedmineBridge\Service;

/**
 * Bildet Ordnernamen für die Redmine-Ticket-Ablage.
 *
 * Struktur: {Basis}/{Projekt}/{Jahr}/{Ticketnummer} - {Betreff}
 *
 * Angelehnt an das Namensschema aus IKS Vorgänge — dieselben Regeln zur
 * Bereinigung von Zeichen und Längenbegrenzung gelten hier genauso, weil
 * dieselben Dateisysteme (Nextcloud, externe Freigaben) dieselben
 * Einschränkungen haben.
 */
class Namensschema {

	/** Zeichen, die Nextcloud bzw. gängige Dateisysteme in Namen nicht zulassen. */
	private const VERBOTEN = ['\\', '/', ':', '*', '?', '"', '<', '>', '|', "\0"];

	/** Obergrenze für einen Namensbestandteil in Bytes. */
	public const MAX_BYTES = 200;

	public function __construct(
		private readonly string $basisPfad,
	) {
	}

	public function basisPfad(): string {
		return $this->basisPfad;
	}

	/**
	 * Der vollständige Ordnerpfad eines Tickets, relativ zum
	 * Nextcloud-Wurzelordner.
	 */
	public function ordnerPfad(string $projekt, int $jahr, int $ticketnummer, string $betreff): string {
		$projektName = $this->bereinige($projekt);
		if ($projektName === '') {
			$projektName = '_Ohne Projekt';
		}

		return $this->basisPfad . '/' . $projektName . '/' . $jahr . '/' . $this->ordnerName($ticketnummer, $betreff);
	}

	public function ordnerName(int $ticketnummer, string $betreff): string {
		$betreffBereinigt = $this->bereinige($betreff);
		$name = $betreffBereinigt === ''
			? '#' . $ticketnummer
			: '#' . $ticketnummer . ' - ' . $betreffBereinigt;

		return $this->kuerze($name);
	}

	/**
	 * Entfernt unzulässige Zeichen und normalisiert Leerraum.
	 */
	public function bereinige(string $wert): string {
		$wert = str_replace(self::VERBOTEN, ' ', $wert);
		$wert = preg_replace('/[\x00-\x1F\x7F]/u', ' ', $wert) ?? '';
		$wert = preg_replace('/\.{2,}/', ' ', $wert) ?? $wert;
		$wert = preg_replace('/\s+/u', ' ', $wert) ?? '';
		$wert = trim($wert);
		$wert = trim($wert, '. ');

		return trim($wert);
	}

	/**
	 * Kürzt bytegenau, ohne ein Mehrbyte-Zeichen zu zerteilen.
	 */
	public function kuerze(string $wert, int $reserviert = 0): string {
		$max = self::MAX_BYTES - $reserviert;
		if ($max <= 0 || strlen($wert) <= $max) {
			return $wert;
		}

		return rtrim(mb_strcut($wert, 0, $max, 'UTF-8'), " .\t\n\r\0\x0B");
	}
}
