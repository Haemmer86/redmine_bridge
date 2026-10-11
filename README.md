# Redmine Bridge für Nextcloud

Zeigt Tickets und Projekte einer angebundenen [Redmine](https://www.redmine.org/)-Instanz
direkt in Nextcloud an und macht sie bearbeitbar. Ticketinhalte werden
**nicht kopiert**: jede Anfrage geht live an die Redmine-REST-API, Änderungen
werden sofort dort gespeichert. Daneben hält die App ein paar eigene Daten,
die es in Redmine nicht gibt (Rechnungsstatus, Mitarbeiter-Zuordnung,
Kalender-Nachverfolgung — siehe „Eigene Datenbanktabellen").

## Funktionen

- **Tickets** ansehen, bearbeiten, neu anlegen; Liste mit Filtern und
  Sortierung (zuletzt geändert, Fälligkeit, Ticketwert, neu→alt, alt→neu)
- **Projekte** neu anlegen (inkl. Mitglieder + Rolle), Projekt-Gantt-Ansicht,
  Projekt mit offenen Tickets abschließen
- **Zeiterfassung** je Ticket (bucht in Redmine)
- **Ablage je Ticket**: Ordner `Projekt / Jahr / #Nummer - Betreff` auf einer
  in Nextcloud eingebundenen Freigabe; Dateien, Links (`.url`),
  Telefonnotizen und zugeordnete E-Mails, chronologischer Gesprächsverlauf
- **Schlagworte** über Nextclouds eigenes Tag-System (dieselben wie in der
  Dateien-App)
- **Abrechnung**: je Ticket rechnungspflichtig / gestellt, Abrechnungsart
  **Pauschale**, **Stunden** oder **Pauschale + Zeitzuschlag** (Inklusivzeit,
  Takt, Preis je Takt); Sammelrechnung aus mehreren Tickets als Entwurf in
  **Odoo**
- **Meine Tickets**: stark eingeschränkte Ansicht für Mitarbeiter (Mitglieder
  einer festgelegten Nextcloud-Gruppe sehen nur ihre eigenen Tickets)
- **Zuständiger Mitarbeiter** je Ticket und automatischer
  **Kalender-Abgleich** (Fälligkeit → Termin im Kalender „Redmine Tickets")
- **Dashboard-Widget** mit offenen Tickets (auch für die mobilen Apps)
- E-Mails aus dem Nextcloud-Posteingang zuordnen (experimentell, s. u.)

## Voraussetzungen

- Nextcloud 33–35, PHP 8.2–8.5 (siehe `appinfo/info.xml`)
- Redmine mit aktivierter REST-API und API-Schlüssel („Mein Konto" →
  „API-Zugriffsschlüssel anzeigen")
- Optional: Odoo (JSON-RPC, Benutzer + API-Schlüssel) für Sammelrechnungen
- Node.js + npm nur zum Selbstbauen der Oberfläche; die fertig gebaute
  `js/redmine_bridge-main-<version>.js` liegt im Repository

## Installation

1. App als Ordner `redmine_bridge` nach `custom_apps/` der Nextcloud kopieren
   (bei Docker: `docker cp <ordner>/. <container>:/var/www/html/custom_apps/redmine_bridge/`)
2. Rechte setzen: `chown -R www-data:www-data custom_apps/redmine_bridge`
3. `php occ app:enable redmine_bridge` — das legt auch die Tabellen an
4. **Einstellungen → Administration → Redmine**: Redmine-Adresse,
   API-Schlüssel, Ablage-Wurzelordner; optional Odoo-Zugang,
   Mitarbeiter-Gruppe und die Zuordnung Nextcloud-Konto → Redmine-Benutzer

### Update einspielen (Docker / NAS)

Neues ZIP **in einen frischen, leeren Ordner** entpacken (nie über einen
alten), die Version prüfen, dann kopieren und neu aktivieren:

```bash
rm -rf ~/rb_neu && mkdir ~/rb_neu && unzip -o redmine_bridge_X.Y.Z.zip -d ~/rb_neu
grep "<version>" ~/rb_neu/redmine_bridge/appinfo/info.xml     # muss die neue Version zeigen
sudo docker cp ~/rb_neu/redmine_bridge/. nextcloud-aio-nextcloud:/var/www/html/custom_apps/redmine_bridge/
sudo docker exec nextcloud-aio-nextcloud chown -R www-data:www-data /var/www/html/custom_apps/redmine_bridge
sudo docker exec --user www-data nextcloud-aio-nextcloud php occ app:disable redmine_bridge
sudo docker exec --user www-data nextcloud-aio-nextcloud php occ app:enable redmine_bridge   # führt neue Migrationen aus
```

Danach im Browser hart neu laden (Strg+Shift+R).

## Selbst bauen

```bash
npm install
npm run build      # schreibt js/redmine_bridge-main-<version>.js
```

Das CSS ist in die JS-Datei eingebettet (Vite-Format `iife`), es gibt keine
separate CSS-Datei. **Bei jeder Änderung an Oberfläche oder Backend die
Version in `appinfo/info.xml` erhöhen** — daraus entsteht der
Dateiname der JS-Datei; so liefert kein Cache (Browser, Cloudflare) eine alte
Version aus. `package.json` trägt bewusst keine eigene Version.

## Code-Landkarte (wo finde ich was?)

**Backend (`lib/`, PHP, Namensraum `OCA\RedmineBridge`)**

| Pfad | Zweck |
|---|---|
| `AppInfo/Application.php` | Konstanten (Config-Schlüssel), Dienst-Verdrahtung, Dashboard-Widget, Hintergrundjob |
| `Controller/PageController.php` | Seitenaufruf, bindet die JS-Datei ein |
| `Controller/BasisController.php` | gemeinsame Basis aller API-Controller: `geschuetzterAufruf()` (Zugriffsschutz Mitarbeiter-Gruppe, Fehler → JSON) |
| `Controller/TicketController.php` | Ticketliste, Ticket lesen/ändern/anlegen, Kommentare, Zeiten |
| `Controller/ProjektController.php` | Projekte, Gantt, Projekt anlegen/abschließen, Projekt-Icon |
| `Controller/AblageController.php` | Ticket-Ablage: Dateien, Notizen, Verweise, Mail-Anhänge, Tags |
| `Controller/AbrechnungController.php` | Rechnungsstatus, Kunden/Artikel aus Odoo, Sammelrechnung |
| `Controller/MitarbeiterController.php` | Zuordnung Konto ↔ Redmine, „Meine Tickets“, Sitzung (einzige Endpunkte, die die Mitarbeiter-Gruppe nutzen darf) |
| `Controller/SettingsController.php` | Speichern der Admin-Einstellungen |
| `Service/RedmineClient.php` | Redmine-REST-Aufrufe |
| `Service/OdooClient.php` | Odoo-JSON-RPC (Kunden, Artikel, Sammelrechnung) |
| `Service/AblageService.php` | **Schreiben** in die Ticket-Ablage (Ordner, Dateien, Notizen, `.url`) |
| `Service/AblageAnsichtService.php` | **Lesen** der Ablage für die Oberfläche (Verweis-Ziel, E-Mail-Kopf, Notiz, Tags) |
| `Service/TicketListeService.php` | Ticketliste: Redmine-Abruf, Rechnungsstatus ergänzen, Ticketwert, Sortierung |
| `Service/Namensschema.php` | Ordner-/Dateinamen der Ablage |
| `Service/TagService.php` | Nextcloud-Schlagworte |
| `Service/RechnungsStatusService.php` | Tabelle `redmine_bridge_rechnung` |
| `Service/MitarbeiterZuordnungService.php` | Tabelle `redmine_bridge_mitarbeiter` |
| `Service/TicketZustaendigkeitService.php` | Tabelle `redmine_bridge_zustaendig` |
| `Service/TicketKalenderService.php` | Tabelle `redmine_bridge_kalender`, schreibt Termine |
| `Service/ProjektFarbeService.php` | feste Projektfarbe + Buchstaben-Icon |
| `BackgroundJob/KalenderAbgleich.php` | regelmäßiger Fälligkeit→Kalender-Abgleich |
| `Dashboard/RedmineWidget.php` | Dashboard-Widget |
| `Settings/` | Admin-Seite (`templates/settings/admin.php`) |
| `Migration/` | Datenbank-Migrationen (siehe Regel unten) |

**Frontend (`src/`, Vue 3 + Vite, kein Router — Hash-Routing in `App.vue`)**

| Pfad | Zweck |
|---|---|
| `main.js`, `App.vue` | Start, Navigation, Rollenprüfung (`api.sitzung()`) |
| `views/TicketListe.vue` | Ticketliste, Filter, Sortierung (nutzt die beiden Dialoge unten) |
| `views/TicketDetail.vue` | Ticket bearbeiten (Kopfdaten, Zuständigkeit, Termin); setzt die Bausteine unten zusammen |
| `components/TicketZeiterfassung.vue` | Ist-Stunden erfassen |
| `components/TicketAbrechnung.vue` | Rechnungsstatus, Abrechnungsart, Beträge |
| `components/TicketKommentare.vue` | Gesprächsverlauf + neuer Kommentar |
| `components/TicketAblage.vue` | Dateiliste, Upload, Verweise, Notizen, Posteingang, Schlagworte |
| `components/DateiVorschau.vue` | große Vorschau (Bild, Notiz, E-Mail) |
| `components/SammelrechnungDialog.vue` | Sammelrechnung in Odoo aus mehreren Tickets |
| `components/ProjektAbschliessenDialog.vue` | Projekt mit allen Tickets abschließen |
| `views/TicketNeu.vue`, `views/ProjektNeu.vue` | Anlegen |
| `views/ProjekteGantt.vue` | Projektübersicht als Gantt, Projekt abschließen |
| `views/MeineTickets.vue` | eingeschränkte Mitarbeiter-Ansicht |
| `lib/api.js` | **alle** Aufrufe an das Backend (eine Zeile je Endpunkt) |
| `lib/mailApi.js`, `lib/mimeParser.js` | E-Mails aus der Nextcloud-Mail-App holen und lesen |
| `lib/format.js`, `lib/ablage.js` | kleine Anzeige-Helfer (Datum, Datei-Symbol, Vorschau-URL) |
| `lib/heic.js` | HEIC-Fotos beim Hochladen nach JPEG wandeln |

**Eigene Datenbanktabellen** (alles, was es in Redmine nicht gibt):
`redmine_bridge_rechnung` (Abrechnung je Ticket), `redmine_bridge_mitarbeiter`
(Nextcloud-Konto ↔ Redmine-Benutzer), `redmine_bridge_zustaendig`
(zuständiger Mitarbeiter je Ticket), `redmine_bridge_kalender` (welcher
Kalendertermin zu welchem Ticket gehört).

**Regeln**
- Eine ausgelieferte Migration wird **nie** geändert; Schemaänderungen
  bekommen eine neue Klasse `Version<Nummer>Date<Zeitstempel>`.
- Neuer Endpunkt: Methode mit `#[FrontpageRoute]` im passenden Controller (von `BasisController` abgeleitet, Aufruf über `geschuetzterAufruf()`) **und**
  eine Zeile in `src/lib/api.js`.
- Die Berechnung des Ticketwerts (Pauschale / Stunden / Zeitzuschlag) gibt es
  **zweimal**: PHP `TicketListeService::ticketwert()` (für Sortierung/Liste) und
  JS `SammelrechnungDialog.vue` (Sammelrechnung). Bei Änderung beide anpassen.
- Ablage-Dateien: `*.url` = Verweis (Windows-Internetverknüpfung),
  `*.txt` mit erster Zeile `REDMINE-BRUECKE-NOTIZ` = Notiz/zugeordnete
  E-Mail, `*.eml` = rohe E-Mail (Kopfzeilen werden ausgelesen).

## Bekannte Einschränkungen

- **Posteingang-Zuordnung ist experimentell.** Sie nutzt nicht offiziell
  dokumentierte REST-Adressen der Nextcloud-Mail-App (`/apps/mail/api/...`),
  die sich bei einem Mail-App-Update ändern können. Schlägt sie fehl, bricht
  nur diese Funktion.
- **Mitgliederliste beim Projekt-Anlegen** setzt voraus, dass `/users.json`
  für den verwendeten Redmine-Account erreichbar ist (verlangt auf manchen
  Instanzen Administratorrechte).

## Lizenz

[AGPL-3.0](LICENSE)
