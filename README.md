# Redmine Bridge für Nextcloud

Zeigt Tickets und Projekte einer angebundenen [Redmine](https://www.redmine.org/)-Instanz
direkt in Nextcloud an und macht sie bearbeitbar. Änderungen werden über die
Redmine-REST-API sofort in Redmine gespeichert — die App führt keine eigene
Kopie der Daten.

## Funktionen

- **Tickets ansehen, bearbeiten, neu anlegen** — Status, Priorität, Tracker,
  Bearbeiter, Termine, Fortschritt
- **Projekte neu anlegen**, inklusive Mitgliederzuordnung (Benutzer + Rolle)
- **Automatische Ordnerablage** je Ticket (`Projekt / Jahr / #Nummer - Betreff`),
  auf einer beliebigen in Nextcloud eingebundenen Freigabe
- **Dateien und Links ablegen** — Links (z. B. zu Paperless-ngx) landen als
  kleine `.url`-Verknüpfung im Ordner, mit klickbarer Darstellung in der App
- **Gesprächsverlauf**: Telefonnotizen und zugeordnete E-Mails erscheinen
  chronologisch, lesbar direkt in der Liste
- **E-Mails aus dem Posteingang zuordnen** (experimentell, siehe unten)
- **Schlagworte** über Nextclouds eigenes Tag-System — dieselben Tags wie in
  der normalen Dateien-App
- Große Dateivorschau in einem eigenen Fenster, inklusive Direktlink in die
  Dateien-App

## Voraussetzungen

- Eine laufende Nextcloud-Instanz (getestet mit 34.x)
- Eine erreichbare Redmine-Instanz mit aktivierter REST-API und einem
  API-Schlüssel (Redmine: „Mein Konto" → „API-Zugriffsschlüssel anzeigen")
- Node.js + npm, falls du die Oberfläche selbst bauen willst
  (fertig gebaute `js/`/`css/`-Dateien sind im Repository enthalten)

## Installation

1. Repository in den `custom_apps`-Ordner deiner Nextcloud-Instanz kopieren,
   als Ordner `redmine_bridge`:
   ```bash
   git clone https://github.com/<dein-benutzername>/redmine_bridge.git \
     /pfad/zu/nextcloud/custom_apps/redmine_bridge
   ```
   (bei einer Docker-Installation: in den Container kopieren, z. B. mit
   `docker cp`)
2. Rechte setzen, damit der Webserver-Benutzer (meist `www-data`) lesen kann:
   ```bash
   chown -R www-data:www-data /pfad/zu/custom_apps/redmine_bridge
   ```
3. App aktivieren:
   ```bash
   php occ app:enable redmine_bridge
   ```
4. In Nextcloud: **Einstellungen → Administration → Redmine** — dort die
   Redmine-Adresse, den API-Schlüssel und den gewünschten Ablage-Wurzelpfad
   eintragen.

## Selbst bauen

Nur nötig, wenn du am Vue-Frontend etwas änderst:

```bash
npm install
npm run build
```

Das erzeugt `js/redmine_bridge-main.js` (das CSS ist automatisch mit
eingebettet, es gibt keine separate `.css`-Datei — siehe `vite.config.js`).

**Wichtig:** Bei jeder Änderung an JS/CSS die Versionsnummer in
`appinfo/info.xml` erhöhen — sonst liefern Zwischenspeicher (Browser, CDNs
wie Cloudflare) weiterhin die alte Version aus.

## Architektur, kurz

- **Backend** (`lib/`): reine PHP-Controller/Dienste, keine eigene
  Datenbanktabelle — jede Anfrage fragt live bei Redmine bzw. im
  Nextcloud-Dateisystem nach
- **Frontend** (`src/`): Vue 3, mit Vite gebaut, ohne zusätzlichen Router
  (einfaches Hash-Routing für die zwei/drei Ansichten)
- **Ablage-Dateien** folgen einer erkennbaren Konvention:
  - `*.url` — Link-Verknüpfung (Format: Windows-Internetverknüpfung)
  - `*.txt`, beginnend mit `REDMINE-BRUECKE-NOTIZ` — Telefonnotiz oder
    zugeordnete E-Mail
  - `*.eml` — rohe E-Mail (Kopfzeilen werden automatisch ausgelesen und
    angezeigt)

## Bekannte Einschränkungen

- **Posteingang-Zuordnung ist experimentell.** Sie nutzt nicht offiziell
  dokumentierte REST-Adressen der Nextcloud-Mail-App
  (`/apps/mail/api/...`), die sich bei einem Mail-App-Update ändern können.
  Schlägt sie fehl, bricht nur diese eine Funktion — der Rest der App bleibt
  unberührt.
- **Mitgliederliste beim Projekt-Anlegen** setzt voraus, dass `/users.json`
  in deiner Redmine-Instanz für den verwendeten Account erreichbar ist —
  das verlangt auf manchen Instanzen Administratorrechte.
- Keine Zeiterfassung (bewusst nicht umgesetzt).

## Mitmachen

Fehler und Vorschläge gerne als [Issue](../../issues) melden. Pull Requests
willkommen — bitte kurz beschreiben, was sich ändert und warum.

## Lizenz

[AGPL-3.0](LICENSE)
