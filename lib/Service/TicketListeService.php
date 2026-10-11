<?php

declare(strict_types=1);

namespace OCA\RedmineBridge\Service;

/**
 * Ticketliste für die Oberfläche: Tickets aus Redmine holen, um den
 * Rechnungsstatus aus der eigenen Tabelle ergänzen, den "voraussichtlichen
 * Betrag" (Ticketwert) berechnen und danach sortieren — soweit Redmine das
 * selbst nicht kann (Fälligkeit, Betrag).
 *
 * Der Ticketwert wird auch im Frontend berechnet (SammelrechnungDialog.vue). Bei Änderungen an der Berechnung (ticketwert(),
 * zuschlagBloecke()) beide Stellen anpassen.
 */
class TicketListeService {

	/** Zulässige Sortierungen der Ticketliste (alles andere fällt auf "geaendert" zurück). */
	public const SORTIERUNGEN = ['geaendert', 'faelligkeit', 'betrag', 'neu_alt', 'alt_neu'];

	public function __construct(
		private readonly RedmineClient $redmine,
		private readonly RechnungsStatusService $rechnungsstatus,
	) {
	}

	/**
	 * Holt alle Tickets zu Status/Projekt aus Redmine (in 100er-Schritten) —
	 * Grundlage für Sortierungen, die Redmine selbst nicht kann. Die
	 * Sicherheitsgrenze von 2000 Tickets schützt vor einer Endlosschleife bei
	 * einer unerwarteten Antwort (und davor, dass eine riesige Ansicht wie
	 * "Alle Status" den Server ewig beschäftigt).
	 *
	 * @return list<array<string,mixed>>
	 */
	public function alleTicketsHolen(string $status, string $projekt): array {
		$issues = [];
		$offset = 0;
		while ($offset < 2000) {
			$abfrage = [
				'status_id' => $status,
				'limit' => 100,
				'offset' => $offset,
				'sort' => 'updated_on:desc',
			];
			if ($projekt !== '') {
				$abfrage['project_id'] = $projekt;
			}
			$antwort = $this->redmine->anfrage('GET', '/issues.json', $abfrage);
			$stapel = $antwort['issues'] ?? [];
			array_push($issues, ...$stapel);
			$offset += 100;
			if (count($stapel) < 100 || $offset >= (int)($antwort['total_count'] ?? 0)) {
				break;
			}
		}

		return $issues;
	}

	/**
	 * Voraussichtlicher Betrag eines Tickets für Anzeige und Sortierung:
	 * Pauschale → der hinterlegte Pauschalbetrag, Stunden → Stundensatz mal
	 * bisher gebuchte Stunden. Ohne Abrechnungsdaten (oder ohne Satz bzw.
	 * Betrag) gibt es keinen Wert (null) — solche Tickets stehen bei der
	 * Sortierung nach Betrag am Ende.
	 *
	 * @param array<string,mixed> $issue bereits um `rechnungsstatus` angereichert
	 */
	private function ticketwert(array $issue): ?float {
		$daten = $issue['rechnungsstatus'] ?? [];
		$art = $daten['abrechnungsart'] ?? null;
		if ($art === 'pauschale') {
			return isset($daten['pauschalbetrag']) ? (float)$daten['pauschalbetrag'] : null;
		}
		if ($art === 'stunden' && isset($daten['stundensatz'])) {
			return round((float)$daten['stundensatz'] * (float)($issue['spent_hours'] ?? 0), 2);
		}
		if ($art === 'pauschale_zuschlag' && isset($daten['pauschalbetrag'])) {
			$bloecke = self::zuschlagBloecke(
				(int)round((float)($issue['spent_hours'] ?? 0) * 60),
				(int)($daten['inklusiv_minuten'] ?? 0),
				(int)($daten['takt_minuten'] ?? 0),
			);

			return round((float)$daten['pauschalbetrag'] + $bloecke * (float)($daten['takt_preis'] ?? 0), 2);
		}

		return null;
	}

	/**
	 * Anzahl der Zuschlags-Takte bei "Pauschale + Zeitzuschlag": alles, was
	 * über die Inklusivzeit hinausgeht, wird in angefangenen Takten
	 * berechnet (z. B. 74 Min. bei 60 Min. inklusive und 15-Min.-Takt = 14
	 * Min. darüber = 1 Takt; 76 Min. = 2 Takte). Ohne gültigen Takt (0) gibt
	 * es keinen Zuschlag.
	 */
	private static function zuschlagBloecke(int $istMinuten, int $inklusivMinuten, int $taktMinuten): int {
		if ($taktMinuten <= 0) {
			return 0;
		}
		$darueber = $istMinuten - max(0, $inklusivMinuten);

		return $darueber > 0 ? (int)ceil($darueber / $taktMinuten) : 0;
	}

	/**
	 * Sortiert angereicherte Tickets nach der gewählten Reihenfolge. Fehlende
	 * Werte (kein Fälligkeitsdatum, kein Betrag) stehen immer am Ende; bei
	 * Gleichstand entscheidet "zuletzt geändert" (neuestes zuerst), damit die
	 * Reihenfolge stabil und nachvollziehbar bleibt.
	 *
	 * @param list<array<string,mixed>> $issues
	 * @return list<array<string,mixed>>
	 */
	public function ticketsSortieren(array $issues, string $sortierung): array {
		$geaendert = static fn (array $a, array $b): int => strcmp((string)($b['updated_on'] ?? ''), (string)($a['updated_on'] ?? ''));

		usort($issues, function (array $a, array $b) use ($sortierung, $geaendert): int {
			switch ($sortierung) {
				case 'faelligkeit':
					$da = (string)($a['due_date'] ?? '');
					$db = (string)($b['due_date'] ?? '');
					if ($da === '' && $db !== '') {
						return 1;
					}
					if ($da !== '' && $db === '') {
						return -1;
					}
					$vergleich = strcmp($da, $db); // frühestes Datum zuerst
					break;
				case 'betrag':
					$wa = $this->ticketwert($a);
					$wb = $this->ticketwert($b);
					if ($wa === null && $wb !== null) {
						return 1;
					}
					if ($wa !== null && $wb === null) {
						return -1;
					}
					$vergleich = ($wb ?? 0.0) <=> ($wa ?? 0.0); // höchster Betrag zuerst
					break;
				case 'neu_alt':
					$vergleich = strcmp((string)($b['created_on'] ?? ''), (string)($a['created_on'] ?? ''));
					break;
				case 'alt_neu':
					$vergleich = strcmp((string)($a['created_on'] ?? ''), (string)($b['created_on'] ?? ''));
					break;
				default:
					$vergleich = 0;
			}

			return $vergleich !== 0 ? $vergleich : $geaendert($a, $b);
		});

		return $issues;
	}

	/**
	 * Alle rechnungspflichtigen, aber noch nicht abgerechneten Tickets —
	 * unabhängig vom Redmine-Status (offen/erledigt) und über alle
	 * Projekte hinweg (bzw. eingeschränkt auf `$projekt`, falls gesetzt).
	 *
	 * Geht bewusst vom lokalen Rechnungsstatus aus (statt Redmine seitenweise
	 * zu durchsuchen): die Menge der offenen Rechnungen ist naturgemäß klein
	 * und wächst nicht mit der Gesamtzahl aller Tickets. Redmine wird nur
	 * für genau diese Ticket-IDs abgefragt (in Stapeln von 100, gegen sehr
	 * lange Abfrage-Adressen), um Betreff/Projekt/Status aktuell zu halten.
	 */
	public function unfakturierteTickets(string $projekt, string $sortierung = 'geaendert'): array {
		$ticketIds = $this->rechnungsstatus->offenePflichtigeTicketIds();
		if ($ticketIds === []) {
			return ['tickets' => [], 'gesamt' => 0, 'seite' => 1];
		}

		$issues = [];
		foreach (array_chunk($ticketIds, 100) as $stapel) {
			$antwort = $this->redmine->anfrage('GET', '/issues.json', [
				'issue_id' => implode(',', $stapel),
				'status_id' => '*',
				'limit' => 100,
			]);
			array_push($issues, ...($antwort['issues'] ?? []));
		}

		if ($projekt !== '') {
			$issues = array_values(array_filter(
				$issues,
				static fn (array $i): bool => (string)($i['project']['id'] ?? '') === $projekt,
			));
		}

		$issues = $this->rechnungsstatusAnreichern($issues);
		$issues = $this->ticketsSortieren($issues, $sortierung);

		return ['tickets' => $issues, 'gesamt' => count($issues), 'seite' => 1];
	}

	/**
	 * Reichert eine Liste von Redmine-Tickets um ihren lokal gespeicherten
	 * Rechnungsstatus an (rechnungspflichtig? / schon gestellt? / wann?).
	 *
	 * @param list<array<string,mixed>> $issues
	 * @return list<array<string,mixed>>
	 */
	public function rechnungsstatusAnreichern(array $issues): array {
		$ids = array_map(static fn (array $i): int => (int)($i['id'] ?? 0), $issues);
		$karte = $this->rechnungsstatus->fuerTickets($ids);

		foreach ($issues as &$issue) {
			$issue['rechnungsstatus'] = $karte[(int)($issue['id'] ?? 0)] ?? [
				'rechnungspflichtig' => false,
				'rechnung_gestellt' => false,
				'rechnung_gestellt_am' => null,
				'rechnung_gestellt_von' => null,
			];
		}
		unset($issue);

		// Voraussichtlicher Betrag je Ticket (für Anzeige und Sortierung),
		// erst NACH dem Anreichern berechenbar, weil er den lokalen
		// Rechnungsdaten entstammt.
		foreach ($issues as &$issue) {
			$issue['ticketwert'] = $this->ticketwert($issue);
		}
		unset($issue);

		return $issues;
	}
}
