<?php

declare(strict_types=1);

namespace OCA\RedmineBridge\Service;

use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\NotPermittedException;

/**
 * Legt für jedes Redmine-Ticket automatisch einen Ordner in Nextcloud an —
 * genau wie AblageService in IKS Vorgänge, nur ohne eigene Datenbank im
 * Rücken.
 *
 * Ordner werden anhand der Ticketnummer wiedergefunden (Präfix "#<Nummer>"
 * im Ordnernamen, siehe {@see ordnerFuerNummerFinden()}), nicht anhand des
 * vollständigen Namens — ändert sich der Betreff in Redmine, wird der
 * bestehende Ordner beim nächsten Aufruf umbenannt statt ein zweiter
 * angelegt. Wechselt das Ticket dagegen Projekt oder Jahr, liegt der
 * bisherige Ordner in einem anderen Eltern-Ordner und wird dort nicht
 * gefunden — dafür bräuchte es eine echte Ticket-ID → Datei-ID-Zuordnung,
 * die es hier (bewusst, ohne eigene Datenbank) nicht gibt.
 */
class AblageService {

	public function __construct(
		private readonly IRootFolder $rootFolder,
		private readonly Namensschema $schema,
	) {
	}

	public function schema(): Namensschema {
		return $this->schema;
	}

	/**
	 * Liefert den Ordner eines Tickets und legt ihn samt Elternordnern an,
	 * falls er fehlt.
	 *
	 * @param array<string,mixed> $ticket Redmine-Ticket als dekodiertes JSON
	 * @throws NotPermittedException wenn der Benutzer dort nicht schreiben darf
	 */
	public function ordnerFuerTicket(array $ticket, string $userId): Folder {
		$projekt = (string)($ticket['project']['name'] ?? '');
		$jahr = $this->jahrDesTickets($ticket);
		$nummer = (int)($ticket['id'] ?? 0);
		$betreff = (string)($ticket['subject'] ?? '');

		$projektName = $this->schema->bereinige($projekt);
		if ($projektName === '') {
			$projektName = '_Ohne Projekt';
		}
		$elternPfad = $this->schema->basisPfad() . '/' . $projektName . '/' . $jahr;
		$zielName = $this->schema->ordnerName($nummer, $betreff);

		$wurzel = $this->rootFolder->getUserFolder($userId);
		$eltern = $this->ordnerAnlegen($wurzel, $elternPfad);

		return $this->ordnerFuerNummerFinden($eltern, $nummer, $zielName);
	}

	/**
	 * Sucht im Eltern-Ordner (Projekt/Jahr) nach einem bereits vorhandenen
	 * Ticket-Ordner anhand der Ticketnummer — am Präfix "#<Nummer>" im
	 * Ordnernamen erkannt, unabhängig vom aktuell gespeicherten Betreff.
	 * Weicht der gefundene Name vom gewünschten ab (Betreff wurde in
	 * Redmine geändert), wird der Ordner umbenannt statt ein zweiter
	 * angelegt. Existiert noch keiner, wird er neu erstellt.
	 *
	 * "#4" darf dabei nicht auf den Ordner von Ticket #41 passen — deshalb
	 * nur exakte Übereinstimmung oder ein Präfix, dem direkt " - " oder ein
	 * Leerzeichen folgt, nie ein reines str_starts_with auf die nackte
	 * Nummer.
	 */
	private function ordnerFuerNummerFinden(Folder $eltern, int $nummer, string $zielName): Folder {
		$praefix = '#' . $nummer;
		foreach ($eltern->getDirectoryListing() as $knoten) {
			if (!($knoten instanceof Folder)) {
				continue;
			}
			$name = $knoten->getName();
			if ($name !== $praefix && !str_starts_with($name, $praefix . ' - ') && !str_starts_with($name, $praefix . ' ')) {
				continue;
			}

			if ($name === $zielName) {
				return $knoten;
			}

			try {
				return $knoten->move($eltern->getPath() . '/' . $zielName);
			} catch (\Throwable) {
				// Umbenennen fehlgeschlagen (z. B. Namenskollision mit
				// einem anderen Ordner) — lieber den bestehenden Ordner
				// unter dem alten Namen weiterverwenden als einen zweiten
				// für dasselbe Ticket anzulegen.
				return $knoten;
			}
		}

		return $eltern->getOrCreateFolder($zielName);
	}

	/**
	 * Alle Dateien im Ticket-Ordner, ohne Unterordner.
	 *
	 * @return list<File>
	 */
	public function dateienDesTickets(array $ticket, string $userId): array {
		$ordner = $this->ordnerFuerTicket($ticket, $userId);
		$this->ordnerAktualisieren($ordner);

		return array_values(array_filter(
			$ordner->getDirectoryListing(),
			static fn ($n) => $n instanceof File,
		));
	}

	/**
	 * Gleicht Nextclouds Datei-Zwischenspeicher mit dem tatsächlichen Inhalt
	 * des Ordners ab.
	 *
	 * ⚠️ Ohne das sieht die App Dateien nicht, die auf anderem Weg als über
	 * Nextcloud selbst in den Ordner gelangt sind — z. B. über „In Nextcloud
	 * speichern" aus der Mail-App in einer Session, die den Ordner noch nicht
	 * kannte, oder direkt über die Netzwerkfreigabe. Nextclouds externe
	 * Speicher prüfen von sich aus nur auf GEÄNDERTE bereits bekannte
	 * Dateien, nicht zuverlässig auf komplett NEUE — deshalb hier ein
	 * gezielter, auf diesen einen Ordner beschränkter Abgleich bei jedem
	 * Aufruf statt eines vollständigen (teuren) Speicher-weiten Scans.
	 */
	private function ordnerAktualisieren(Folder $ordner): void {
		try {
			$ordner->getStorage()->getScanner()->scan($ordner->getInternalPath());
		} catch (\Throwable) {
			// Manche Speicherarten unterstützen das nicht (selten) — die
			// Liste zeigt dann höchstens den zuletzt bekannten Stand,
			// bricht aber nicht ab.
		}
	}

	/**
	 * Legt eine hochgeladene Datei im Ticket-Ordner ab.
	 *
	 * @param resource|string $inhalt
	 */
	public function dateiAblegen(array $ticket, string $userId, string $dateiname, mixed $inhalt): File {
		$ordner = $this->ordnerFuerTicket($ticket, $userId);
		$basis = $this->schema->bereinige($dateiname) ?: 'Datei';
		$name = $basis;
		$i = 2;
		while ($ordner->nodeExists($name)) {
			$name = $this->schema->kuerze($basis, strlen((string)$i) + 3) . " ({$i})";
			$i++;
		}

		return $ordner->newFile($name, $inhalt);
	}

	/**
	 * Legt einen E-Mail-Anhang im Ticket-Ordner ab, benannt nach demselben
	 * "E-Mail {Datum} {Betreff}"-Schema wie der zugehörige
	 * Gesprächsverlauf-Eintrag (siehe {@see mailVerlaufAblegen()}) — Anhang
	 * und E-Mail-Text bekommen dadurch denselben Anfang, stehen im Ordner
	 * direkt nebeneinander und sind auf den ersten Blick als zusammengehörig
	 * erkennbar, statt unter ihrem oft unaussagekräftigen Originalnamen
	 * (z. B. "Scan0001.pdf") zu verschwinden. Datum wird bewusst identisch
	 * zu {@see mailVerlaufAblegen()} geparst, damit beide Namen exakt
	 * übereinstimmen.
	 *
	 * @param resource|string $inhalt
	 */
	public function mailAnhangAblegen(
		array $ticket,
		string $userId,
		string $betreff,
		?\DateTimeImmutable $datum,
		string $dateiname,
		mixed $inhalt,
	): File {
		$ordner = $this->ordnerFuerTicket($ticket, $userId);
		$zeitpunkt = $datum ?? new \DateTimeImmutable();
		$endung = pathinfo($dateiname, PATHINFO_EXTENSION);
		$originalOhneEndung = pathinfo($dateiname, PATHINFO_FILENAME);

		$basis = $this->schema->bereinige(
			'E-Mail ' . $zeitpunkt->format('Y-m-d H-i') . ' ' . $betreff . ' - ' . $originalOhneEndung
		);
		if ($basis === '') {
			$basis = 'Anhang';
		}

		$reserviert = $endung !== '' ? strlen($endung) + 1 : 0;
		$name = $this->schema->kuerze($basis, $reserviert) . ($endung !== '' ? '.' . $endung : '');
		$i = 2;
		while ($ordner->nodeExists($name)) {
			$zusatz = " ({$i})";
			$reserviert = strlen($zusatz) + ($endung !== '' ? strlen($endung) + 1 : 0);
			$name = $this->schema->kuerze($basis, $reserviert) . $zusatz . ($endung !== '' ? '.' . $endung : '');
			$i++;
		}

		return $ordner->newFile($name, $inhalt);
	}

	/**
	 * Legt einen Verweis (z. B. auf ein Paperless-ngx-Dokument) als kleine
	 * Verknüpfungsdatei im Ticket-Ordner ab.
	 *
	 * Nextclouds eigener Dateibrowser öffnet eine `.url`-Datei nicht
	 * automatisch im Ziel — die App selbst erkennt das Format beim
	 * Auflisten der Ablage und macht daraus einen anklickbaren Verweis
	 * (siehe ApiController::ablageInfo()). Die physische Datei bleibt
	 * trotzdem bestehen: Wer den Ordner direkt in Nextcloud durchsucht,
	 * findet dort wenigstens den Namen und kann die Adresse im
	 * Dateiinhalt nachlesen.
	 */
	public function urlAblegen(array $ticket, string $userId, string $bezeichnung, string $url): File {
		$ordner = $this->ordnerFuerTicket($ticket, $userId);
		$basis = $this->schema->bereinige($bezeichnung) ?: 'Link';
		$name = $basis . '.url';
		$i = 2;
		while ($ordner->nodeExists($name)) {
			$name = $this->schema->kuerze($basis, strlen((string)$i) + 6) . " ({$i}).url";
			$i++;
		}

		// Windows-"Internetverknüpfung"-Format — weit verbreiteter Standard
		// für genau diesen Zweck, auch wenn Nextcloud selbst ihn nicht
		// interpretiert.
		$inhalt = "[InternetShortcut]\r\nURL={$url}\r\n";

		return $ordner->newFile($name, $inhalt);
	}

	/**
	 * Legt eine Telefon- oder allgemeine Notiz als kleine Textdatei im
	 * Ticket-Ordner ab — für einen nachvollziehbaren Gesprächsverlauf ohne
	 * eigene Datenbank.
	 *
	 * Das führende Erkennungswort `REDMINE-BRUECKE-NOTIZ` unterscheidet
	 * unsere eigenen Notizen von irgendeiner beliebigen, händisch
	 * hochgeladenen `.txt`-Datei — nur Dateien mit diesem Kopf bekommen in
	 * der Oberfläche die besondere Notiz-Darstellung (Symbol, Ansprechpartner
	 * hervorgehoben usw.), siehe ApiController::ablageInfo().
	 */
	public function notizAblegen(
		array $ticket,
		string $userId,
		string $art,
		string $text,
		?string $ansprechpartner,
	): File {
		$ordner = $this->ordnerFuerTicket($ticket, $userId);
		$jetzt = new \DateTimeImmutable();
		$basis = $this->schema->bereinige($art . ' ' . $jetzt->format('Y-m-d H-i'));
		$name = $basis . '.txt';
		$i = 2;
		while ($ordner->nodeExists($name)) {
			$name = $this->schema->kuerze($basis, strlen((string)$i) + 5) . " ({$i}).txt";
			$i++;
		}

		$inhalt = "REDMINE-BRUECKE-NOTIZ\r\n";
		$inhalt .= 'Art: ' . $art . "\r\n";
		$inhalt .= 'Datum: ' . $jetzt->format('Y-m-d H:i') . "\r\n";
		if ($ansprechpartner !== null && $ansprechpartner !== '') {
			$inhalt .= 'Ansprechpartner: ' . $ansprechpartner . "\r\n";
		}
		$inhalt .= "\r\n" . $text . "\r\n";

		return $ordner->newFile($name, $inhalt);
	}

	/**
	 * Legt eine zugeordnete E-Mail als lesbaren Gesprächsverlauf-Eintrag ab
	 * — bewusst NICHT als rohe `.eml`-Datei, sondern als Text mit Von,
	 * Betreff und Inhalt, damit man ihn direkt in der Liste lesen kann,
	 * ohne die Datei extra zu öffnen.
	 *
	 * @param ?\DateTimeImmutable $datum Das tatsächliche Sendedatum der
	 *   E-Mail, nicht der Zeitpunkt der Zuordnung — sonst würde eine alte
	 *   E-Mail im Verlauf so aussehen, als wäre sie gerade eben eingegangen.
	 * @param string $nachrichtKennung Die RFC822-Message-ID der E-Mail
	 *   (eindeutig je Nachricht) — wird mit abgelegt, damit
	 *   {@see mailBereitsZugeordnet()} doppelte Zuordnungen erkennen kann.
	 */
	public function mailVerlaufAblegen(
		array $ticket,
		string $userId,
		string $von,
		string $betreff,
		string $text,
		?\DateTimeImmutable $datum,
		string $nachrichtKennung = '',
	): File {
		$ordner = $this->ordnerFuerTicket($ticket, $userId);
		$zeitpunkt = $datum ?? new \DateTimeImmutable();
		$basis = $this->schema->bereinige('E-Mail ' . $zeitpunkt->format('Y-m-d H-i') . ' ' . $betreff);
		$name = $this->schema->kuerze($basis) . '.txt';
		$i = 2;
		while ($ordner->nodeExists($name)) {
			$name = $this->schema->kuerze($basis, strlen((string)$i) + 5) . " ({$i}).txt";
			$i++;
		}

		$inhalt = "REDMINE-BRUECKE-NOTIZ\r\n";
		$inhalt .= "Art: E-Mail\r\n";
		$inhalt .= 'Datum: ' . $zeitpunkt->format('Y-m-d H:i') . "\r\n";
		$inhalt .= 'Von: ' . $von . "\r\n";
		$inhalt .= 'Betreff: ' . $betreff . "\r\n";
		if ($nachrichtKennung !== '') {
			$inhalt .= 'Nachricht-Kennung: ' . $nachrichtKennung . "\r\n";
		}
		$inhalt .= "\r\n" . $text . "\r\n";

		return $ordner->newFile($name, $inhalt);
	}

	/**
	 * Prüft, ob eine E-Mail (per eindeutiger Nachricht-Kennung) diesem
	 * Ticket bereits zugeordnet wurde — verhindert doppelte Einträge im
	 * Gesprächsverlauf, wenn dieselbe Nachricht versehentlich zweimal aus
	 * dem Posteingang zugeordnet wird.
	 */
	public function mailBereitsZugeordnet(array $ticket, string $userId, string $nachrichtKennung): bool {
		if ($nachrichtKennung === '') {
			return false;
		}

		$ordner = $this->ordnerFuerTicket($ticket, $userId);
		foreach ($ordner->getDirectoryListing() as $knoten) {
			if (!($knoten instanceof File) || !str_ends_with(strtolower($knoten->getName()), '.txt')) {
				continue;
			}
			try {
				$inhalt = $knoten->getContent();
			} catch (\Throwable) {
				continue;
			}
			if (str_contains($inhalt, 'Nachricht-Kennung: ' . $nachrichtKennung)) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Löscht eine Datei aus der Ablage — auf die eigene Ticket-Ablage
	 * beschränkt, damit über eine falsche/manipulierte ID nicht versehentlich
	 * irgendeine andere Datei des Benutzers gelöscht werden kann.
	 */
	public function dateiLoeschen(int $dateiId, string $userId): void {
		$benutzerOrdner = $this->rootFolder->getUserFolder($userId);
		$treffer = $benutzerOrdner->getById($dateiId);
		if (empty($treffer)) {
			throw new \RuntimeException('Datei wurde nicht gefunden.');
		}
		$knoten = $treffer[0];
		if (!($knoten instanceof File)) {
			throw new \RuntimeException('Nur Dateien können hier gelöscht werden.');
		}

		$erlaubterPfad = rtrim($benutzerOrdner->getPath(), '/') . '/' . $this->schema->basisPfad() . '/';
		if (!str_starts_with($knoten->getPath() . '/', $erlaubterPfad)) {
			throw new \RuntimeException('Diese Datei liegt außerhalb der Ticket-Ablage und wird hier nicht gelöscht.');
		}

		$knoten->delete();
	}

	/**
	 * Das Jahr, unter dem das Ticket einsortiert wird.
	 *
	 * Redmine liefert `start_date` (Datum, ohne Uhrzeit) oder `created_on`
	 * (Zeitstempel). Bevorzugt wird das Startdatum — das ist der Zeitpunkt,
	 * zu dem fachlich an dem Ticket gearbeitet wird, nicht der Moment, in dem
	 * es angelegt wurde.
	 */
	private function jahrDesTickets(array $ticket): int {
		$rohdatum = $ticket['start_date'] ?? $ticket['created_on'] ?? null;
		if (is_string($rohdatum) && $rohdatum !== '') {
			try {
				return (int)(new \DateTimeImmutable($rohdatum))->format('Y');
			} catch (\Exception) {
				// Fällt durch auf das aktuelle Jahr
			}
		}

		return (int)date('Y');
	}

	/**
	 * Legt einen Ordnerpfad Ebene für Ebene an.
	 *
	 * `Folder::getOrCreateFolder()` ist zwar rennsicher, aber NICHT rekursiv —
	 * bei „Redmine/Projekt X/2026/#123 - Betreff" fehlen aber regelmäßig
	 * gleich mehrere Ebenen. Deshalb Segment für Segment.
	 */
	private function ordnerAnlegen(Folder $wurzel, string $pfad): Folder {
		$aktuell = $wurzel;
		foreach (explode('/', trim($pfad, '/')) as $segment) {
			if ($segment === '') {
				continue;
			}
			$aktuell = $aktuell->getOrCreateFolder($segment);
		}

		return $aktuell;
	}
}
