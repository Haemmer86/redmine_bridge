<?php

declare(strict_types=1);

namespace OCA\RedmineBridge\Service;

/**
 * Erzeugt für ein Redmine-Projekt eine feste, wiederholbare Farbe (aus der
 * Projekt-ID abgeleitet, nicht zufällig) sowie ein rundes Buchstaben-Icon in
 * genau dieser Farbe.
 *
 * Zweck: Im Dashboard-Widget (siehe {@see \OCA\RedmineBridge\Dashboard\RedmineWidget})
 * bekommt jedes Ticket statt des generischen "?"-Platzhalters ein Icon in der
 * Farbe seines Projekts, und in der Ticket-Übersichtstabelle (TicketListe.vue)
 * taucht exakt dieselbe Farbe als kleiner Punkt neben dem Projektnamen auf —
 * so lässt sich ein Projekt an beiden Stellen auf einen Blick wiedererkennen.
 *
 * Der Farbton wird über den "goldenen Winkel" (137°) aus der Projekt-ID
 * abgeleitet statt sequenziell hochgezählt — das verteilt auch bei
 * benachbarten IDs deutlich unterscheidbare Farben, ganz ohne Farbtabelle.
 * Dieselbe Formel ist absichtlich 1:1 in TicketListe.vue (JavaScript)
 * dupliziert, damit Backend (PHP, für die SVG-Icons) und Frontend
 * (JavaScript, für den Farbpunkt in der Tabelle) ohne gemeinsamen
 * Netzwerkaufruf zur selben Farbe kommen.
 */
class ProjektFarbeService {

	public function farbTon(int $projektId): int {
		return ($projektId * 137) % 360;
	}

	public function hex(int $projektId): string {
		return $this->hslZuHex($this->farbTon($projektId), 55, 45);
	}

	/**
	 * Ein bis zwei Großbuchstaben aus dem Projektnamen fürs Icon, z. B.
	 * "GLT MES Conncet" → "GM", "IKS" → "I".
	 */
	public function initialen(string $name): string {
		$name = trim($name);
		if ($name === '') {
			return '?';
		}

		$woerter = preg_split('/\s+/', $name) ?: [$name];
		$initialen = '';
		foreach (array_slice($woerter, 0, 2) as $wort) {
			$initialen .= mb_strtoupper(mb_substr($wort, 0, 1));
		}

		return $initialen !== '' ? $initialen : mb_strtoupper(mb_substr($name, 0, 1));
	}

	/**
	 * Rundes SVG-Icon (44×44, wie von Nextclouds Dashboard-Widget-Items
	 * erwartet — siehe {@see \OCP\Dashboard\Model\WidgetItem::getIconUrl()}):
	 * gefüllter Kreis in der Projektfarbe mit den Projekt-Initialen.
	 */
	public function iconSvg(int $projektId, string $name): string {
		$farbe = $this->hex($projektId);
		$initialen = htmlspecialchars($this->initialen($name), ENT_QUOTES | ENT_XML1);

		return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 44 44" width="44" height="44">'
			. '<circle cx="22" cy="22" r="22" fill="' . $farbe . '"/>'
			. '<text x="22" y="28" font-family="sans-serif" font-size="16" font-weight="600" fill="#ffffff" text-anchor="middle">' . $initialen . '</text>'
			. '</svg>';
	}

	private function hslZuHex(int $h, int $s, int $l): string {
		$s /= 100;
		$l /= 100;
		$c = (1 - abs(2 * $l - 1)) * $s;
		$x = $c * (1 - abs(fmod($h / 60, 2) - 1));
		$m = $l - $c / 2;

		[$r, $g, $b] = match (true) {
			$h < 60 => [$c, $x, 0],
			$h < 120 => [$x, $c, 0],
			$h < 180 => [0, $c, $x],
			$h < 240 => [0, $x, $c],
			$h < 300 => [$x, 0, $c],
			default => [$c, 0, $x],
		};

		$zuHex = static fn (float $v) => str_pad(dechex((int)round(($v + $m) * 255)), 2, '0', STR_PAD_LEFT);

		return '#' . $zuHex($r) . $zuHex($g) . $zuHex($b);
	}
}
