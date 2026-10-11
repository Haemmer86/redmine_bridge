<script setup>
import { ref, onMounted, watch, computed } from 'vue'
import { api } from '../lib/api.js'
import SammelrechnungDialog from '../components/SammelrechnungDialog.vue'
import ProjektAbschliessenDialog from '../components/ProjektAbschliessenDialog.vue'

// Nur true für ein Admin-Konto, das zusätzlich Mitglied der
// Mitarbeiter-Gruppe ist (siehe App.vue/MitarbeiterController::sitzung()) — zeigt
// dann den freiwilligen "Meine Tickets"-Navigationspunkt unten an. Normale
// Gruppenmitglieder ohne Admin-Rechte sehen diese Komponente ohnehin nie,
// die werden fest auf "Meine Tickets" verengt.
defineProps({
  meineTicketsVerfuegbar: { type: Boolean, default: false },
})

const tickets = ref([])
const gesamt = ref(0)
const seite = ref(1)
const status = ref('open')
const projektFilter = ref('')
const nurUnfakturiert = ref(false)
const rechnungLaeuftFuer = ref(null)

// ─── Sortierung ─────────────────────────────────────────────────────────
// Die Sortierung läuft serverseitig (über alle Seiten, nicht nur innerhalb
// der gerade sichtbaren 50 Tickets) — siehe TicketController::tickets(). Die
// Wahl wird je Gerät gemerkt (localStorage), wie bei der Ansicht unten.
const SORTIERUNGEN = [
  { wert: 'geaendert', text: 'Zuletzt geändert' },
  { wert: 'faelligkeit', text: 'Fälligkeit (früheste zuerst)' },
  { wert: 'betrag', text: 'Ticketwert (höchster zuerst)' },
  { wert: 'neu_alt', text: 'Neu → Alt (Erstelldatum)' },
  { wert: 'alt_neu', text: 'Alt → Neu (Erstelldatum)' },
]
const SORTIERUNG_SPEICHER_SCHLUESSEL = 'redmine_bridge.ticketliste.sortierung'
function sortierungAusSpeicher() {
  try {
    const wert = window.localStorage.getItem(SORTIERUNG_SPEICHER_SCHLUESSEL)
    return SORTIERUNGEN.some((s) => s.wert === wert) ? wert : 'geaendert'
  } catch {
    return 'geaendert'
  }
}
const sortierung = ref(sortierungAusSpeicher())
watch(sortierung, (wert) => {
  try {
    window.localStorage.setItem(SORTIERUNG_SPEICHER_SCHLUESSEL, wert)
  } catch {
    // ohne localStorage (z. B. privater Modus) gilt die Wahl nur für diesen Aufruf
  }
})

const euroFormat = new Intl.NumberFormat('de-DE', { style: 'currency', currency: 'EUR' })
function datumKurz(iso) {
  if (!iso) return ''
  const [j, m, t] = String(iso).slice(0, 10).split('-')
  return t && m && j ? `${t}.${m}.${j}` : ''
}
// Kleine Zusatzzeile unter dem Betreff, passend zur gewählten Sortierung —
// sonst sieht man der Liste nicht an, WONACH sie gerade geordnet ist.
function sortierungInfo(t) {
  if (sortierung.value === 'faelligkeit') {
    return t.due_date ? `fällig ${datumKurz(t.due_date)}` : 'ohne Fälligkeit'
  }
  if (sortierung.value === 'betrag') {
    return t.ticketwert != null ? euroFormat.format(t.ticketwert) : 'ohne Wert'
  }
  if (sortierung.value === 'neu_alt' || sortierung.value === 'alt_neu') {
    return `angelegt ${datumKurz(t.created_on)}`
  }
  return ''
}

// ─── Ansicht Tabelle/Karten ─────────────────────────────────────────────
// Standardmäßig entscheidet die Bildschirmbreite per CSS (siehe
// @media (max-width: 640px) weiter unten), aber manche Geräte/Browser
// melden eine andere effektive Breite als erwartet — deshalb zusätzlich
// ein manueller Umschalter, der die automatische Erkennung übersteuert.
// Die Wahl wird je Gerät gemerkt (localStorage), damit sie nicht bei
// jedem Aufruf neu gesetzt werden muss.
const ANSICHT_SPEICHER_SCHLUESSEL = 'redmine_bridge.ticketliste.ansicht'
function ansichtAusSpeicher() {
  try {
    const wert = window.localStorage.getItem(ANSICHT_SPEICHER_SCHLUESSEL)
    return wert === 'tabelle' || wert === 'karten' ? wert : 'auto'
  } catch {
    return 'auto'
  }
}
const ansicht = ref(ansichtAusSpeicher())
watch(ansicht, (wert) => {
  try {
    window.localStorage.setItem(ANSICHT_SPEICHER_SCHLUESSEL, wert)
  } catch {
    // localStorage nicht verfügbar (z. B. privater Modus) — dann halt
    // ohne Persistenz, die Umschaltung funktioniert trotzdem für diesen Aufruf.
  }
})
// Erzwingt die gewählte Ansicht per Inline-Style, das jede CSS-Regel
// (auch die @media-Umschaltung weiter unten) übersteuert — so wirkt der
// Umschalter unabhängig davon, was der Browser als Bildschirmbreite meldet.
function ansichtStil(welche) {
  if (ansicht.value === 'auto') return {}
  if (ansicht.value === welche) return { display: welche === 'tabelle' ? 'table' : 'block' }
  return { display: 'none' }
}
const projekte = ref([])
const statusListe = ref([])
const fortschrittLaeuftFuer = ref(null)
const ladend = ref(true)
const fehler = ref(null)

// ─── Mehrfachauswahl + Odoo-Sammelrechnung ─────────────────────────────
const ausgewaehlt = ref(new Set())
const sammelrechnungOffen = ref(false)

async function laden() {
  ladend.value = true
  fehler.value = null
  // Eine Auswahl bezieht sich auf die gerade sichtbaren Zeilen — bei einem
  // Neuladen (Filterwechsel, Seitenwechsel) ist sie nicht mehr eindeutig
  // gültig, deshalb hier zurücksetzen statt sie über Seiten hinweg
  // mitzuschleppen.
  ausgewaehlt.value.clear()
  try {
    const antwort = await api.tickets(status.value, projektFilter.value, seite.value, nurUnfakturiert.value, sortierung.value)
    tickets.value = antwort.tickets
    gesamt.value = antwort.gesamt
  } catch (e) {
    fehler.value = e.message
  } finally {
    ladend.value = false
  }
}

onMounted(async () => {
  laden()
  try {
    const daten = await api.formulardaten()
    projekte.value = daten.projekte
    statusListe.value = daten.status || []
  } catch {
    // Projektliste/Statusliste sind nur für Filter bzw. die
    // "100 % → erledigt"-Automatik — schlägt das fehl, bleiben sie eben
    // leer, die Ticketliste selbst funktioniert trotzdem.
  }
})

watch([status, projektFilter, nurUnfakturiert, sortierung], () => {
  seite.value = 1
  laden()
})
watch(seite, laden)

function offnen(id) {
  window.location.hash = `#/tickets/${id}`
}

// Farblogik nach Bedeutung, nicht nach Zufall: erledigt wirkt beruhigt
// (grün), neu wirkt aufmerksamkeitsheischend (blau), alles andere neutral.
function statusFarbe(name) {
  const n = (name || '').toLowerCase()
  if (n.includes('erledigt') || n.includes('geschlossen') || n.includes('abgeschlossen')) return 'gruen'
  if (n.includes('neu')) return 'blau'
  if (n.includes('bearbeitung') || n.includes('progress')) return 'gelb'
  return 'grau'
}

function prioritaetFarbe(name) {
  const n = (name || '').toLowerCase()
  if (n.includes('hoch') || n.includes('dringend') || n.includes('sofort')) return 'rot'
  if (n.includes('niedrig') || n.includes('low')) return 'grau'
  return 'grau'
}

// Feste Farbe je Projekt, aus der Projekt-ID abgeleitet (nicht zufällig) —
// derselbe Farbton (goldener Winkel, 137°) wie im Dashboard-Widget-Icon
// (siehe ProjektFarbeService.php auf dem Server), damit ein Projekt hier
// wie dort dieselbe Farbe trägt, ohne dafür eine Anfrage zu brauchen.
function projektFarbe(projektId) {
  if (!projektId) return 'transparent'
  const farbton = (Number(projektId) * 137) % 360
  return `hsl(${farbton}, 55%, 45%)`
}

// Das über der Projekt-Filter-Dropdown ausgewählte Projekt als Objekt —
// unabhängig von den gerade geladenen Tickets. Der "Projekt abschließen"-
// Knopf am Gruppenkopf (siehe unten) erscheint nur für Projekte, die in der
// aktuellen Ansicht mindestens ein Ticket haben (z. B. keins mehr im
// Status-Filter "Offen") — ein Projekt ganz ohne offene Tickets hätte dort
// sonst gar keinen Knopf mehr. Dieser zweite Knopf direkt am Filter greift
// unabhängig davon, sobald ein konkretes Projekt ausgewählt ist.
const projektFilterAusgewaehlt = computed(() => projekte.value.find((p) => p.id === projektFilter.value) || null)

// Tickets nach Projekt gruppiert statt als flache Liste — Reihenfolge der
// Gruppen bleibt dabei die der aktuellen Sortierung (server-seitig nach
// updated_on:desc), die Gruppe des zuletzt bearbeiteten Tickets steht also
// oben. Gilt nur je geladener Seite (Paginierung bleibt unverändert).
const gruppiert = computed(() => {
  // Bei einer gewählten Sortierung (Fälligkeit/Wert/Datum) gilt sie über alle
  // Projekte hinweg — eine Gruppierung nach Projekt würde die Reihenfolge
  // wieder zerreißen. Dann eine flache Liste ohne Gruppenköpfe; das Projekt
  // steht stattdessen am jeweiligen Ticket.
  if (sortierung.value !== 'geaendert') {
    return [{ projekt: null, flach: true, tickets: tickets.value }]
  }
  const gruppen = new Map()
  for (const t of tickets.value) {
    const id = t.project?.id ?? 0
    if (!gruppen.has(id)) {
      gruppen.set(id, { projekt: t.project, tickets: [] })
    }
    gruppen.get(id).tickets.push(t)
  }
  return Array.from(gruppen.values())
})

// Ziel-Status für die "100 % → erledigt"-Automatik: bevorzugt ein Status,
// der exakt "Erledigt" heißt (das übliche Wort in dieser Redmine-Instanz),
// sonst ersatzweise der erste als abgeschlossen markierte Status
// (`is_closed`) — irgendein erledigter Status ist besser als gar keiner.
const erledigtStatus = computed(() => {
  const exakt = statusListe.value.find((s) => (s.name || '').trim().toLowerCase() === 'erledigt')
  if (exakt) return exakt
  return statusListe.value.find((s) => s.is_closed) || null
})

function istGeschlossen(statusId) {
  const eintrag = statusListe.value.find((s) => s.id === statusId)
  return !!eintrag?.is_closed
}

// Erfüllung in 10-%-Schritten anpassen (dieselbe Schrittweite wie der
// Schieberegler in der Ticket-Detailansicht). Erreicht der Wert 100 %,
// wird zusätzlich der Status automatisch auf "Erledigt" gesetzt — sofern
// das Ticket nicht ohnehin schon einen abgeschlossenen Status hat. Bei der
// aktuellen Filteransicht "Offen" verschwindet das Ticket danach aus der
// Liste, weil es nicht mehr offen ist.
async function fortschrittAendern(ticket, delta) {
  const vorherigerWert = ticket.done_ratio ?? 0
  const neuerWert = Math.min(100, Math.max(0, vorherigerWert + delta))
  if (neuerWert === vorherigerWert || fortschrittLaeuftFuer.value !== null) return

  fortschrittLaeuftFuer.value = ticket.id
  const vorherigerStatus = ticket.status
  ticket.done_ratio = neuerWert // optimistisch, bei Fehler unten zurückgesetzt

  const felder = { done_ratio: neuerWert }
  if (neuerWert >= 100 && !istGeschlossen(ticket.status?.id) && erledigtStatus.value) {
    felder.status_id = erledigtStatus.value.id
    ticket.status = { id: erledigtStatus.value.id, name: erledigtStatus.value.name }
  }

  try {
    const antwort = await api.ticketAktualisieren(ticket.id, felder)
    if (antwort.ticket) {
      Object.assign(ticket, antwort.ticket)
    }
    // In der Ansicht "Offen" gehört ein jetzt geschlossenes Ticket nicht
    // mehr in die Liste — sonst wirkt es, als hätte die Änderung nicht
    // gegriffen.
    if (status.value === 'open' && istGeschlossen(ticket.status?.id)) {
      tickets.value = tickets.value.filter((t) => t.id !== ticket.id)
      gesamt.value = Math.max(0, gesamt.value - 1)
    }
  } catch (e) {
    ticket.done_ratio = vorherigerWert
    ticket.status = vorherigerStatus
    fehler.value = `Erfüllung konnte nicht geändert werden: ${e.message}`
  } finally {
    fortschrittLaeuftFuer.value = null
  }
}

// ─── Mehrfachauswahl ────────────────────────────────────────────────────

function auswahlUmschalten(id) {
  if (ausgewaehlt.value.has(id)) {
    ausgewaehlt.value.delete(id)
  } else {
    ausgewaehlt.value.add(id)
  }
}

function auswahlAufheben() {
  ausgewaehlt.value.clear()
}

const alleAusgewaehlt = computed(
  () => tickets.value.length > 0 && tickets.value.every((t) => ausgewaehlt.value.has(t.id)),
)

function alleUmschalten() {
  if (alleAusgewaehlt.value) {
    for (const t of tickets.value) ausgewaehlt.value.delete(t.id)
  } else {
    for (const t of tickets.value) ausgewaehlt.value.add(t.id)
  }
}

const ausgewaehlteTickets = computed(() => tickets.value.filter((t) => ausgewaehlt.value.has(t.id)))

// ─── Rechnungsstatus ─────────────────────────────────────────────────────
//
// Häkchen "Rechnung gestellt" — nur sichtbar, wenn das Ticket überhaupt als
// rechnungspflichtig markiert ist (siehe Neu-Anlage bzw. Ticket-Detail).
// Schreibt sofort (kein separater "Speichern"-Knopf), analog zu den
// Fortschritt-Knöpfen weiter unten.
async function rechnungUmschalten(ticket) {
  rechnungLaeuftFuer.value = ticket.id
  try {
    const neu = !ticket.rechnungsstatus?.rechnung_gestellt
    const antwort = await api.rechnungsstatusSetzen(ticket.id, { rechnungGestellt: neu })
    ticket.rechnungsstatus = antwort.rechnungsstatus
    // In der Filteransicht "Nur nicht abgerechnete" verschwindet ein
    // gerade abgehaktes Ticket sofort aus der Liste — dieselbe
    // Optimistisch-entfernen-Logik wie bei "100 % → erledigt" oben.
    if (nurUnfakturiert.value && neu) {
      tickets.value = tickets.value.filter((t) => t.id !== ticket.id)
    }
  } catch (e) {
    fehler.value = e.message
  } finally {
    rechnungLaeuftFuer.value = null
  }
}

// ─── Odoo-Sammelrechnung ────────────────────────────────────────────────
//
// Dialog siehe components/SammelrechnungDialog.vue. Hier nur: öffnen (mit den
// ausgewählten Tickets) und nach dem Anlegen die Liste lokal nachziehen.
function sammelrechnungOeffnen() {
  if (ausgewaehlteTickets.value.length === 0) return
  sammelrechnungOffen.value = true
}

function sammelrechnungSchliessen() {
  sammelrechnungOffen.value = false
}

function sammelrechnungAbgerechnet(erledigtIds) {
  // Der Server markiert die betroffenen Tickets jetzt automatisch als
  // "Rechnung gestellt" — lokal direkt nachziehen statt auf ein Neuladen zu
  // warten, dieselbe optimistische Aktualisierung wie in rechnungUmschalten().
  for (const t of tickets.value) {
    if (erledigtIds.has(t.id)) {
      t.rechnungsstatus = { ...(t.rechnungsstatus || {}), rechnung_gestellt: true }
    }
  }
  if (nurUnfakturiert.value) {
    tickets.value = tickets.value.filter((t) => !erledigtIds.has(t.id))
  }
  auswahlAufheben()
}

// ─── Ganzes Projekt abschließen ─────────────────────────────────────────
//
// Dialog siehe components/ProjektAbschliessenDialog.vue; hier nur das Ziel
// (Projekt-ID + Name), solange der Dialog offen ist.
const projektAbschliessenZiel = ref(null) // { id, name }

function projektAbschliessenOeffnen(projekt) {
  if (!projekt?.id) return
  projektAbschliessenZiel.value = projekt
}

function projektAbschliessenSchliessen() {
  projektAbschliessenZiel.value = null
}
</script>

<template>
  <div class="rb-seite">
    <header class="rb-kopf">
      <h1>Tickets</h1>
      <div class="rb-kopf-aktionen">
        <a v-if="meineTicketsVerfuegbar" href="#/meine-tickets" class="rb-knopf-sekundaer-hell">👤 Meine Tickets</a>
        <a href="#/projekte" class="rb-knopf-sekundaer-hell">📊 Zeitplan</a>
        <a href="#/projekte/neu" class="rb-knopf-sekundaer-hell">+ Neues Projekt</a>
        <a href="#/tickets/neu" class="rb-knopf-primaer-hell">+ Neues Ticket</a>
      </div>
    </header>

    <section class="rb-karte">
      <div class="rb-filter">
        <select v-model="status" class="rb-select" :disabled="nurUnfakturiert">
          <option value="open">Offen</option>
          <option value="closed">Erledigt</option>
          <option value="*">Alle</option>
        </select>
        <select v-model="projektFilter" class="rb-select">
          <option value="">Alle Projekte</option>
          <option v-for="p in projekte" :key="p.id" :value="p.id">{{ p.name }}</option>
        </select>
        <select v-model="sortierung" class="rb-select" aria-label="Sortierung" title="Sortierung">
          <option v-for="s in SORTIERUNGEN" :key="s.wert" :value="s.wert">{{ s.text }}</option>
        </select>
        <label class="rb-checkbox-filter">
          <input type="checkbox" v-model="nurUnfakturiert">
          Nur nicht abgerechnete
        </label>
        <div class="rb-modus-umschalter rb-ansicht-umschalter" title="Ansicht: automatisch nach Bildschirmbreite oder fest wählen">
          <button type="button" :class="{ 'rb-modus-aktiv': ansicht === 'auto' }" @click="ansicht = 'auto'">Automatisch</button>
          <button type="button" :class="{ 'rb-modus-aktiv': ansicht === 'tabelle' }" @click="ansicht = 'tabelle'">Tabelle</button>
          <button type="button" :class="{ 'rb-modus-aktiv': ansicht === 'karten' }" @click="ansicht = 'karten'">Karten</button>
        </div>
        <button
          v-if="projektFilterAusgewaehlt"
          type="button"
          class="rb-knopf-abschliessen"
          @click="projektAbschliessenOeffnen(projektFilterAusgewaehlt)"
        >Projekt abschließen</button>
      </div>

      <div v-if="ausgewaehlt.size > 0" class="rb-auswahl-leiste">
        <span>{{ ausgewaehlt.size }} Ticket{{ ausgewaehlt.size === 1 ? '' : 's' }} ausgewählt</span>
        <div class="rb-auswahl-aktionen">
          <button type="button" class="rb-knopf-sekundaer" @click="auswahlAufheben">Auswahl aufheben</button>
          <button type="button" class="rb-knopf-primaer" @click="sammelrechnungOeffnen">🧾 Sammelrechnung erstellen</button>
        </div>
      </div>

      <div v-if="ladend" class="rb-zustand">
        <span class="rb-spinner"></span> Tickets werden geladen …
      </div>
      <div v-else-if="fehler" class="rb-zustand rb-zustand-fehler">
        Verbindung zu Redmine fehlgeschlagen: {{ fehler }}
      </div>
      <div v-else-if="tickets.length === 0" class="rb-zustand">
        Keine Tickets in dieser Ansicht. Ändere den Filter oder lege das erste Ticket in Redmine an.
      </div>

      <template v-else>
      <table class="rb-tabelle" :style="ansichtStil('tabelle')">
        <thead>
          <tr>
            <th class="rb-col-schmal">
              <input
                type="checkbox"
                :checked="alleAusgewaehlt"
                aria-label="Alle Tickets auf dieser Seite auswählen"
                @change="alleUmschalten"
              >
            </th>
            <th class="rb-col-schmal">#</th>
            <th>Betreff</th>
            <th class="rb-col-schmal">Status</th>
            <th class="rb-col-schmal">Priorität</th>
            <th>Zugewiesen an</th>
            <th class="rb-col-erfuellung">Erfüllung</th>
            <th class="rb-col-schmal">Rechnung</th>
          </tr>
        </thead>
        <tbody v-for="g in gruppiert" :key="g.projekt?.id ?? 'ohne-projekt'">
          <tr v-if="!g.flach" class="rb-gruppenkopf">
            <td colspan="8" :style="{ borderLeftColor: projektFarbe(g.projekt?.id) }">
              <div class="rb-gruppenkopf-zeile">
                <span>
                  <span class="rb-punkt" :style="{ background: projektFarbe(g.projekt?.id) }"></span>
                  <strong>{{ g.projekt?.name || 'Ohne Projekt' }}</strong>
                  <span class="rb-gedaempft"> · {{ g.tickets.length }} Ticket{{ g.tickets.length === 1 ? '' : 's' }}</span>
                </span>
                <button
                  v-if="g.projekt?.id"
                  type="button"
                  class="rb-knopf-abschliessen"
                  @click.stop="projektAbschliessenOeffnen(g.projekt)"
                >Projekt abschließen</button>
              </div>
            </td>
          </tr>
          <tr v-for="t in g.tickets" :key="t.id" class="rb-zeile" tabindex="0" @click="offnen(t.id)" @keydown.enter="offnen(t.id)">
            <td class="rb-auswahl-zelle" @click.stop @keydown.enter.stop>
              <input
                type="checkbox"
                :checked="ausgewaehlt.has(t.id)"
                aria-label="Ticket auswählen"
                @change="auswahlUmschalten(t.id)"
              >
            </td>
            <td class="rb-nummer">#{{ t.id }}</td>
            <td class="rb-betreff">
              {{ t.subject }}
              <div v-if="g.flach || sortierungInfo(t)" class="rb-sortierinfo">
                <template v-if="g.flach">
                  <span class="rb-punkt" :style="{ background: projektFarbe(t.project?.id) }"></span>{{ t.project?.name || 'Ohne Projekt' }}
                </template>
                <span v-if="sortierungInfo(t)"><template v-if="g.flach"> · </template>{{ sortierungInfo(t) }}</span>
              </div>
            </td>
            <td><span class="rb-punkt" :class="'rb-punkt-' + statusFarbe(t.status?.name)"></span>{{ t.status?.name }}</td>
            <td>
              <span v-if="prioritaetFarbe(t.priority?.name) === 'rot'" class="rb-punkt rb-punkt-rot"></span>
              {{ t.priority?.name }}
            </td>
            <td class="rb-gedaempft">{{ t.assigned_to?.name || '—' }}</td>
            <td class="rb-erfuellung" @click.stop @keydown.enter.stop>
              <div class="rb-fortschritt-zeile">
                <button
                  type="button"
                  class="rb-fortschritt-knopf"
                  :disabled="(t.done_ratio ?? 0) <= 0 || fortschrittLaeuftFuer === t.id"
                  aria-label="Erfüllung um 10 % verringern"
                  @click="fortschrittAendern(t, -10)"
                >−</button>
                <div class="rb-fortschritt-balken" :title="(t.done_ratio ?? 0) + ' % erledigt'">
                  <div
                    class="rb-fortschritt-fuellung"
                    :class="{ 'rb-fortschritt-fertig': (t.done_ratio ?? 0) >= 100 }"
                    :style="{ width: (t.done_ratio ?? 0) + '%' }"
                  ></div>
                </div>
                <button
                  type="button"
                  class="rb-fortschritt-knopf"
                  :disabled="(t.done_ratio ?? 0) >= 100 || fortschrittLaeuftFuer === t.id"
                  aria-label="Erfüllung um 10 % erhöhen"
                  @click="fortschrittAendern(t, 10)"
                >+</button>
                <span class="rb-gedaempft rb-fortschritt-prozent">{{ t.done_ratio ?? 0 }} %</span>
              </div>
            </td>
            <td class="rb-rechnung-zelle" @click.stop @keydown.enter.stop>
              <label v-if="t.rechnungsstatus?.rechnungspflichtig" class="rb-rechnung-checkbox">
                <input
                  type="checkbox"
                  :checked="t.rechnungsstatus.rechnung_gestellt"
                  :disabled="rechnungLaeuftFuer === t.id"
                  aria-label="Rechnung gestellt"
                  @change="rechnungUmschalten(t)"
                >
              </label>
              <span v-else class="rb-gedaempft">—</span>
            </td>
          </tr>
        </tbody>
      </table>

      <!-- Mobile Kartenansicht: dieselben Daten/Funktionen wie die Tabelle
           oben, nur anders angeordnet — Spalten nebeneinander lassen sich ab
           einer bestimmten Breite nicht mehr sinnvoll quetschen, eine Karte
           je Ticket mit den Werten untereinander bleibt dagegen auch auf
           einem Telefon lesbar. Reine CSS-Umschaltung (siehe
           @media (max-width: 640px) unten) statt zwei Komponenten oder einer
           JS-Breakpoint-Abfrage — passt zum Rest dieser Datei. -->
      <div class="rb-karten" :style="ansichtStil('karten')">
        <div v-for="g in gruppiert" :key="g.projekt?.id ?? 'ohne-projekt'" class="rb-karten-gruppe">
          <div v-if="!g.flach" class="rb-karten-gruppenkopf" :style="{ borderLeftColor: projektFarbe(g.projekt?.id) }">
            <span>
              <span class="rb-punkt" :style="{ background: projektFarbe(g.projekt?.id) }"></span>
              <strong>{{ g.projekt?.name || 'Ohne Projekt' }}</strong>
              <span class="rb-gedaempft"> · {{ g.tickets.length }} Ticket{{ g.tickets.length === 1 ? '' : 's' }}</span>
            </span>
            <button
              v-if="g.projekt?.id"
              type="button"
              class="rb-knopf-abschliessen"
              @click.stop="projektAbschliessenOeffnen(g.projekt)"
            >Projekt abschließen</button>
          </div>

          <div
            v-for="t in g.tickets"
            :key="t.id"
            class="rb-karte-ticket"
            tabindex="0"
            @click="offnen(t.id)"
            @keydown.enter="offnen(t.id)"
          >
            <div class="rb-karte-kopf">
              <input
                type="checkbox"
                :checked="ausgewaehlt.has(t.id)"
                aria-label="Ticket auswählen"
                class="rb-karte-auswahl"
                @click.stop
                @change="auswahlUmschalten(t.id)"
              >
              <span class="rb-nummer">#{{ t.id }}</span>
              <strong class="rb-betreff">{{ t.subject }}</strong>
            </div>
            <div v-if="g.flach || sortierungInfo(t)" class="rb-sortierinfo rb-sortierinfo-karte">
              <template v-if="g.flach">
                <span class="rb-punkt" :style="{ background: projektFarbe(t.project?.id) }"></span>{{ t.project?.name || 'Ohne Projekt' }}
              </template>
              <span v-if="sortierungInfo(t)"><template v-if="g.flach"> · </template>{{ sortierungInfo(t) }}</span>
            </div>

            <div class="rb-karte-zeile">
              <span><span class="rb-punkt" :class="'rb-punkt-' + statusFarbe(t.status?.name)"></span>{{ t.status?.name }}</span>
              <span>
                <span v-if="prioritaetFarbe(t.priority?.name) === 'rot'" class="rb-punkt rb-punkt-rot"></span>
                {{ t.priority?.name }}
              </span>
            </div>

            <div class="rb-karte-zeile rb-karte-zeile-zugewiesen rb-gedaempft">{{ t.assigned_to?.name || 'Niemand zugewiesen' }}</div>

            <div class="rb-karte-erfuellung" @click.stop @keydown.enter.stop>
              <div class="rb-fortschritt-zeile">
                <button
                  type="button"
                  class="rb-fortschritt-knopf"
                  :disabled="(t.done_ratio ?? 0) <= 0 || fortschrittLaeuftFuer === t.id"
                  aria-label="Erfüllung um 10 % verringern"
                  @click="fortschrittAendern(t, -10)"
                >−</button>
                <div class="rb-fortschritt-balken" :title="(t.done_ratio ?? 0) + ' % erledigt'">
                  <div
                    class="rb-fortschritt-fuellung"
                    :class="{ 'rb-fortschritt-fertig': (t.done_ratio ?? 0) >= 100 }"
                    :style="{ width: (t.done_ratio ?? 0) + '%' }"
                  ></div>
                </div>
                <button
                  type="button"
                  class="rb-fortschritt-knopf"
                  :disabled="(t.done_ratio ?? 0) >= 100 || fortschrittLaeuftFuer === t.id"
                  aria-label="Erfüllung um 10 % erhöhen"
                  @click="fortschrittAendern(t, 10)"
                >+</button>
                <span class="rb-gedaempft rb-fortschritt-prozent">{{ t.done_ratio ?? 0 }} %</span>
              </div>
            </div>

            <label v-if="t.rechnungsstatus?.rechnungspflichtig" class="rb-karte-rechnung" @click.stop @keydown.enter.stop>
              <input
                type="checkbox"
                :checked="t.rechnungsstatus.rechnung_gestellt"
                :disabled="rechnungLaeuftFuer === t.id"
                @change="rechnungUmschalten(t)"
              >
              Rechnung gestellt
            </label>
          </div>
        </div>
      </div>
      </template>

      <div v-if="gesamt > 50" class="rb-pagination">
        <button class="rb-knopf-sekundaer" :disabled="seite === 1" @click="seite--">← Zurück</button>
        <span class="rb-gedaempft">Seite {{ seite }} von {{ Math.ceil(gesamt / 50) }} · {{ gesamt }} Tickets</span>
        <button class="rb-knopf-sekundaer" :disabled="seite >= Math.ceil(gesamt / 50)" @click="seite++">Weiter →</button>
      </div>
    </section>

    <SammelrechnungDialog
      v-if="sammelrechnungOffen"
      :tickets="ausgewaehlteTickets"
      @schliessen="sammelrechnungSchliessen"
      @abgerechnet="sammelrechnungAbgerechnet"
    />

    <ProjektAbschliessenDialog
      v-if="projektAbschliessenZiel"
      :projekt="projektAbschliessenZiel"
      :erledigtStatus="erledigtStatus"
      @schliessen="projektAbschliessenSchliessen"
      @aktualisieren="laden"
    />
  </div>
</template>

<style scoped>
.rb-seite {
  padding: 32px 40px;
  max-width: none;
}

.rb-kopf {
  margin-bottom: 16px;
  display: flex;
  align-items: center;
  justify-content: space-between;
  flex-wrap: wrap;
  gap: 12px;
}

.rb-kopf-aktionen {
  display: flex;
  gap: 10px;
}

.rb-kopf h1 {
  margin: 0;
  font-size: 1.5em;
  /* Läuft über einem Nextcloud-Hintergrundbild — ohne Textschatten
     verschwindet die Überschrift auf hellen Bildstellen fast völlig. */
  text-shadow: 0 1px 3px rgba(0, 0, 0, 0.4);
  color: #fff;
}

.rb-knopf-primaer-hell,
.rb-knopf-sekundaer-hell {
  display: inline-block;
  padding: 8px 16px;
  border-radius: var(--border-radius, 6px);
  text-decoration: none;
  font-size: 0.9em;
  font-weight: 600;
}

.rb-knopf-primaer-hell {
  background: var(--color-primary-element, #0069c2);
  color: #fff;
}

.rb-knopf-primaer-hell:hover {
  filter: brightness(1.1);
}

.rb-knopf-sekundaer-hell {
  background: rgba(255, 255, 255, 0.92);
  /* Bewusst fest statt var(--color-main-text): dieser Knopf sitzt immer auf
     dem hellen, halbtransparenten Hintergrund direkt über dem Header-Bild —
     unabhängig vom Hell-/Dunkelmodus. Mit der Text-Variable wurde die
     Schrift im Dunkelmodus hell auf hellem Grund und damit unlesbar. */
  color: #222;
}

.rb-knopf-sekundaer-hell:hover {
  background: #fff;
}

.rb-karte {
  background: var(--color-main-background, #fff);
  border: 1px solid var(--color-border, #e0e0e3);
  border-radius: var(--border-radius-large, 10px);
  padding: 20px 24px 8px;
}

.rb-filter {
  display: flex;
  align-items: center;
  gap: 10px;
  margin-bottom: 16px;
}

.rb-select {
  padding: 6px 12px;
  border-radius: var(--border-radius, 6px);
  border: 1px solid var(--color-border, #d8d8db);
  background: var(--color-main-background, #fff);
  color: var(--color-main-text, #222);
}

.rb-checkbox-filter {
  display: flex;
  align-items: center;
  gap: 6px;
  font-size: 0.9em;
  color: var(--color-main-text, #222);
  cursor: pointer;
}

.rb-rechnung-zelle {
  text-align: center;
}

.rb-rechnung-checkbox {
  display: inline-flex;
  cursor: pointer;
}

.rb-tabelle {
  width: 100%;
  border-collapse: collapse;
}

.rb-tabelle th {
  text-align: left;
  padding: 10px 12px;
  font-weight: 600;
  font-size: 0.85em;
  color: var(--color-text-maxcontrast, #767676);
  border-bottom: 1px solid var(--color-border, #e0e0e3);
}

.rb-tabelle td {
  padding: 12px;
  border-bottom: 1px solid var(--color-border, #eee);
  vertical-align: middle;
  color: var(--color-main-text, #222);
}

.rb-col-schmal {
  width: 1%;
  white-space: nowrap;
}

.rb-nummer {
  color: var(--color-text-maxcontrast, #767676);
  font-variant-numeric: tabular-nums;
}

.rb-betreff {
  font-weight: 500;
}

.rb-gedaempft {
  color: var(--color-text-maxcontrast, #767676);
}

.rb-zeile {
  cursor: pointer;
}

.rb-zeile:hover,
.rb-zeile:focus-visible {
  background: var(--color-background-hover, #f5f5f7);
}

.rb-zeile:focus-visible {
  outline: 2px solid var(--color-primary-element, #0069c2);
  outline-offset: -2px;
}

.rb-punkt {
  display: inline-block;
  width: 8px;
  height: 8px;
  border-radius: 50%;
  margin-right: 8px;
  vertical-align: middle;
}

.rb-gruppenkopf td {
  padding: 10px 12px 10px 14px;
  border-bottom: 1px solid var(--color-border, #e0e0e3);
  border-left: 4px solid transparent;
  background: var(--color-background-hover, #f5f5f7);
  font-size: 0.9em;
}

.rb-gruppenkopf:first-child td {
  border-top: none;
}

.rb-gruppenkopf-zeile {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
}

.rb-knopf-abschliessen {
  flex: none;
  padding: 4px 10px;
  border-radius: var(--border-radius, 6px);
  border: 1px solid var(--color-border, #d8d8db);
  background: var(--color-main-background, #fff);
  color: var(--color-text-maxcontrast, #767676);
  font-size: 0.85em;
  cursor: pointer;
}

.rb-knopf-abschliessen:hover {
  background: var(--color-background-hover, #f5f5f7);
  color: var(--color-main-text, #222);
}

.rb-punkt-gruen { background: #2e7d32; }

.rb-punkt-blau { background: #0069c2; }

.rb-punkt-gelb { background: #e5a50a; }

.rb-punkt-rot { background: #c62828; }

.rb-punkt-grau { background: #9b9b9b; }

.rb-col-erfuellung {
  width: 1%;
  white-space: nowrap;
}

.rb-fortschritt-zeile {
  display: flex;
  align-items: center;
  gap: 6px;
}

.rb-fortschritt-knopf {
  flex: none;
  width: 22px;
  height: 22px;
  line-height: 1;
  border-radius: 50%;
  border: 1px solid var(--color-border, #d8d8db);
  background: var(--color-main-background, #fff);
  color: var(--color-main-text, #222);
  cursor: pointer;
  font-weight: 600;
}

.rb-fortschritt-knopf:hover:not(:disabled) {
  background: var(--color-background-hover, #f5f5f7);
}

.rb-fortschritt-knopf:disabled {
  opacity: 0.35;
  cursor: default;
}

.rb-fortschritt-balken {
  flex: none;
  width: 80px;
  height: 8px;
  border-radius: 4px;
  background: var(--color-background-darker, #ededf0);
  overflow: hidden;
}

.rb-fortschritt-fuellung {
  height: 100%;
  border-radius: 4px;
  /* Blau = noch in Arbeit, damit die Farbe erst beim Erreichen von 100 %
     auf Grün wechselt — derselbe Bedeutungscode wie bei den Status-Punkten
     (gruen = erledigt). */
  background: var(--color-primary-element, #0069c2);
  transition: width 0.2s ease;
}

.rb-fortschritt-fuellung.rb-fortschritt-fertig {
  background: #2e7d32;
}

.rb-fortschritt-prozent {
  flex: none;
  width: 38px;
  font-variant-numeric: tabular-nums;
  font-size: 0.85em;
}

/* ─── Mobile Kartenansicht (siehe @media (max-width: 640px) unten, das
   schaltet zwischen .rb-tabelle und .rb-karten um) ──────────────────────── */
.rb-karten {
  display: none;
}

.rb-karten-gruppe + .rb-karten-gruppe {
  margin-top: 20px;
}

.rb-karten-gruppenkopf {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 10px;
  flex-wrap: wrap;
  padding: 8px 10px 8px 12px;
  margin-bottom: 10px;
  border-left: 4px solid transparent;
  border-radius: var(--border-radius, 6px);
  background: var(--color-background-hover, #f5f5f7);
  font-size: 0.9em;
}

.rb-karte-ticket {
  border: 1px solid var(--color-border, #e0e0e3);
  border-radius: var(--border-radius, 6px);
  padding: 12px 14px;
  margin-bottom: 10px;
  cursor: pointer;
}

.rb-karte-ticket:focus-visible {
  outline: 2px solid var(--color-primary-element, #0069c2);
  outline-offset: 2px;
}

.rb-karte-kopf {
  display: flex;
  align-items: flex-start;
  gap: 8px;
  margin-bottom: 8px;
}

.rb-karte-auswahl {
  flex: none;
  margin-top: 3px;
}

.rb-karte-kopf .rb-nummer {
  flex: none;
}

.rb-karte-kopf .rb-betreff {
  flex: 1 1 auto;
}

.rb-karte-zeile {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 10px;
  margin-bottom: 8px;
  font-size: 0.92em;
}

.rb-karte-zeile-zugewiesen {
  justify-content: flex-start;
  font-size: 0.88em;
}

.rb-karte-erfuellung {
  margin-bottom: 4px;
}

/* In der Karte ist mehr Breite verfügbar als in der engen Tabellenspalte —
   der Balken darf sich deshalb strecken statt auf 80px fixiert zu bleiben. */
.rb-karte-erfuellung .rb-fortschritt-balken {
  flex: 1 1 auto;
  width: auto;
}

.rb-karte-erfuellung .rb-fortschritt-prozent {
  width: 44px;
  text-align: right;
}

.rb-karte-rechnung {
  display: flex;
  align-items: center;
  gap: 8px;
  margin-top: 10px;
  padding-top: 10px;
  border-top: 1px solid var(--color-border, #e0e0e3);
  font-size: 0.9em;
  color: var(--color-text-maxcontrast, #767676);
}

.rb-zustand {
  padding: 48px 12px;
  text-align: center;
  color: var(--color-text-maxcontrast, #767676);
}

.rb-zustand-fehler {
  color: #c62828;
}

.rb-spinner {
  display: inline-block;
  width: 14px;
  height: 14px;
  border: 2px solid var(--color-border, #d8d8db);
  border-top-color: var(--color-primary-element, #0069c2);
  border-radius: 50%;
  animation: rb-drehen 0.7s linear infinite;
  vertical-align: middle;
  margin-right: 8px;
}

.rb-knopf-sekundaer {
  padding: 6px 14px;
  border-radius: var(--border-radius, 6px);
  border: 1px solid var(--color-border, #d8d8db);
  background: var(--color-main-background, #fff);
  color: var(--color-main-text, #222);
  cursor: pointer;
}

.rb-knopf-sekundaer:disabled {
  opacity: 0.5;
  cursor: default;
}

.rb-pagination {
  display: flex;
  gap: 16px;
  align-items: center;
  justify-content: center;
  padding: 16px 0 20px;
}

/* Unterhalb dieser Breite bleibt für zwei Spalten kein sinnvoller Platz
   mehr — Tablet im Hochformat und jedes Handy fallen darunter. */
@media (max-width: 640px) {
  .rb-seite {
    padding: 16px;
  }
  .rb-karte {
    padding: 12px 16px 4px;
    border-radius: var(--border-radius, 6px);
  }
  .rb-filter {
    flex-direction: column;
  }
  .rb-select {
    width: 100%;
    box-sizing: border-box;
  }
    /* Unterhalb dieser Breite lassen sich die acht Spalten nicht mehr
     sinnvoll quetschen (auch nicht mit horizontalem Scrollen lesbar) —
     stattdessen übernimmt die Kartenansicht (.rb-karten), siehe Template. */
  .rb-tabelle {
    display: none;
  }
  .rb-karten {
    display: block;
  }
}

/* ─── Mehrfachauswahl ──────────────────────────────────────────────────── */
.rb-auswahl-zelle {
  width: 1%;
  white-space: nowrap;
}

.rb-auswahl-leiste {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
  flex-wrap: wrap;
  padding: 10px 14px;
  margin-bottom: 12px;
  border-radius: var(--border-radius, 6px);
  background: var(--color-primary-element-light, #e8f1fb);
  color: var(--color-main-text, #222);
}

.rb-auswahl-aktionen {
  display: flex;
  gap: 10px;
}

.rb-knopf-primaer {
  display: inline-block;
  padding: 8px 16px;
  border-radius: var(--border-radius, 6px);
  border: none;
  background: var(--color-primary-element, #0069c2);
  color: #fff;
  font-weight: 600;
  font-size: 0.9em;
  text-decoration: none;
  cursor: pointer;
}

.rb-knopf-primaer:hover:not(:disabled) {
  filter: brightness(1.1);
}

.rb-knopf-primaer:disabled {
  opacity: 0.5;
  cursor: default;
}

.rb-modus-umschalter {
  display: flex;
  flex: none;
  border: 1px solid var(--color-border, #d8d8db);
  border-radius: var(--border-radius, 6px);
  overflow: hidden;
}

.rb-modus-umschalter button {
  border: none;
  background: var(--color-main-background, #fff);
  color: var(--color-main-text, #222);
  padding: 6px 10px;
  font-size: 0.8em;
  cursor: pointer;
}

.rb-modus-umschalter button + button {
  border-left: 1px solid var(--color-border, #d8d8db);
}

.rb-modus-umschalter button.rb-modus-aktiv {
  background: var(--color-primary-element, #0069c2);
  color: #fff;
}

.rb-sortierinfo {
  margin-top: 2px;
  font-size: 12px;
  font-weight: normal;
  color: var(--color-text-maxcontrast, #6b6b72);
}

.rb-sortierinfo-karte {
  margin: 2px 0 6px;
}

@keyframes rb-drehen {
  to { transform: rotate(360deg); }
}
</style>
