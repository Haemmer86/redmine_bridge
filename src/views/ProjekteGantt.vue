<script setup>
import { ref, reactive, onMounted, computed } from 'vue'
import { api } from '../lib/api.js'

const projekte = ref([])
const ladend = ref(true)
const fehler = ref(null)

const filterProjekt = ref('')
const filterStatus = ref('alle')
const filterVon = ref('')
const filterBis = ref('')

// Welche Projekte eingeklappt sind (Ticket-Unterzeilen ausgeblendet) —
// standardmäßig ist jedes Projekt aufgeklappt (undefined ist falsy).
const eingeklappt = reactive({})

async function laden() {
  ladend.value = true
  fehler.value = null
  try {
    const antwort = await api.projekteGantt()
    projekte.value = antwort.projekte
  } catch (e) {
    fehler.value = e.message
  } finally {
    ladend.value = false
  }
}

onMounted(laden)

function zurueck() {
  window.location.hash = ''
}

function datumFormatieren(iso) {
  const [jahr, monat, tag] = iso.split('-')
  return `${tag}.${monat}.${jahr}`
}

function toggleAufklappen(projektId) {
  eingeklappt[projektId] = !eingeklappt[projektId]
}

function filterZuruecksetzen() {
  filterProjekt.value = ''
  filterStatus.value = 'alle'
  filterVon.value = ''
  filterBis.value = ''
}

const filterAktiv = computed(() => {
  return !!filterProjekt.value || filterStatus.value !== 'alle' || !!filterVon.value || !!filterBis.value
})

// Feste Farbe je Projekt, aus der Projekt-ID abgeleitet (nicht zufällig) —
// dieselbe Formel wie in TicketListe.vue und im Dashboard-Widget-Icon
// (ProjektFarbeService.php auf dem Server), damit ein Projekt überall
// dieselbe Farbe trägt.
function projektFarbe(projektId) {
  if (!projektId) return 'transparent'
  const farbton = (Number(projektId) * 137) % 360
  return `hsl(${farbton}, 55%, 45%)`
}

// ─── Filterung ────────────────────────────────────────────────────────
//
// Läuft komplett clientseitig auf den beim Laden einmal geholten Daten
// (siehe ApiController::projekteGantt(), liefert Projekte inkl. ihrer
// Einzeltickets) — Projekt-/Status-/Zeitraumfilter ändern sich ohne
// erneuten Serverzugriff. Ein Projekt ohne zum Filter passende Tickets
// verschwindet komplett aus der Ansicht.
const gefilterteProjekte = computed(() => {
  const ergebnis = []
  for (const p of projekte.value) {
    if (filterProjekt.value && String(p.projektId) !== String(filterProjekt.value)) {
      continue
    }
    const tickets = p.tickets.filter((t) => {
      if (filterStatus.value === 'offen' && t.erledigt) return false
      if (filterStatus.value === 'erledigt' && !t.erledigt) return false
      // Überlappungstest: Ticket ausblenden, wenn es komplett vor "Von"
      // endet oder komplett nach "Bis" beginnt.
      if (filterVon.value && t.ende < filterVon.value) return false
      if (filterBis.value && t.start > filterBis.value) return false
      return true
    })
    if (!tickets.length) {
      continue
    }
    let start = tickets[0].start
    let ende = tickets[0].ende
    let erledigtAnzahl = 0
    for (const t of tickets) {
      if (t.start < start) start = t.start
      if (t.ende > ende) ende = t.ende
      if (t.erledigt) erledigtAnzahl++
    }
    ergebnis.push({
      projektId: p.projektId,
      name: p.name,
      start,
      ende,
      ticketAnzahl: tickets.length,
      erledigtAnzahl,
      tickets,
    })
  }
  return ergebnis
})

// ─── Zeitachse ────────────────────────────────────────────────────────
//
// Redmine liefert nur Start-/Enddatum je Ticket, kein eigenes Datum je
// Projekt — die Spanne je Projekt kommt bereits aggregiert vom Server,
// hier wird daraus nur noch die gemeinsame Zeitachse berechnet, auf
// ganze Monate gerundet mit etwas Luft an beiden Rändern. Basiert auf
// den gefilterten Projekten, damit die Achse bei aktivem Zeit-/Status-/
// Projektfilter enger auf das Sichtbare zugeschnitten ist.

const bereich = computed(() => {
  if (!gefilterteProjekte.value.length) return null
  let min = new Date(gefilterteProjekte.value[0].start)
  let max = new Date(gefilterteProjekte.value[0].ende)
  for (const p of gefilterteProjekte.value) {
    const s = new Date(p.start)
    const e = new Date(p.ende)
    if (s < min) min = s
    if (e > max) max = e
  }
  const anfang = new Date(min.getFullYear(), min.getMonth() - 1, 1)
  const ende = new Date(max.getFullYear(), max.getMonth() + 2, 0)
  return { anfang, ende, spanneMs: ende.getTime() - anfang.getTime() }
})

const monate = computed(() => {
  if (!bereich.value) return []
  const liste = []
  const cursor = new Date(bereich.value.anfang)
  while (cursor <= bereich.value.ende) {
    liste.push({
      label: cursor.toLocaleDateString('de-DE', { month: 'short', year: '2-digit' }),
      linksProzent: ((cursor.getTime() - bereich.value.anfang.getTime()) / bereich.value.spanneMs) * 100,
    })
    cursor.setMonth(cursor.getMonth() + 1)
  }
  return liste
})

const heuteProzent = computed(() => {
  if (!bereich.value) return null
  const heute = new Date()
  if (heute < bereich.value.anfang || heute > bereich.value.ende) return null
  return ((heute.getTime() - bereich.value.anfang.getTime()) / bereich.value.spanneMs) * 100
})

function balkenPosition(start, ende) {
  const s = new Date(start)
  const e = new Date(ende)
  const linksProzent = ((s.getTime() - bereich.value.anfang.getTime()) / bereich.value.spanneMs) * 100
  // Mindestbreite, damit auch eintägige/kurze Tickets als Balken sichtbar bleiben.
  const breiteProzent = Math.max(((e.getTime() - s.getTime()) / bereich.value.spanneMs) * 100, 0.6)
  return { linksProzent, breiteProzent }
}

const balkenProjekte = computed(() => {
  if (!bereich.value) return []
  return gefilterteProjekte.value.map((p) => {
    const { linksProzent, breiteProzent } = balkenPosition(p.start, p.ende)
    const erledigtProzent = p.ticketAnzahl ? (p.erledigtAnzahl / p.ticketAnzahl) * 100 : 0
    const tickets = p.tickets.map((t) => ({
      ...t,
      ...balkenPosition(t.start, t.ende),
      startFormatiert: datumFormatieren(t.start),
      endeFormatiert: datumFormatieren(t.ende),
    }))
    return {
      ...p,
      linksProzent,
      breiteProzent,
      erledigtProzent,
      startFormatiert: datumFormatieren(p.start),
      endeFormatiert: datumFormatieren(p.ende),
      tickets,
    }
  })
})

// Projekt- und Ticketzeilen zu einer flachen Liste zusammengefasst — zwei
// parallele Spalten (Namen links, Balken rechts) werden anhand dieser
// gemeinsamen, gleich sortierten Liste gerendert, damit Zeilenhöhen auf
// beiden Seiten exakt zusammenpassen, auch wenn Projekte unterschiedlich
// viele (auf- oder zugeklappte) Tickets mitbringen.
const zeilen = computed(() => {
  const liste = []
  for (const p of balkenProjekte.value) {
    liste.push({
      typ: 'projekt',
      key: 'p' + p.projektId,
      projektId: p.projektId,
      name: p.name,
      farbe: projektFarbe(p.projektId),
      linksProzent: p.linksProzent,
      breiteProzent: p.breiteProzent,
      erledigtProzent: p.erledigtProzent,
      titel: `${p.name}: ${p.startFormatiert} – ${p.endeFormatiert} · ${p.erledigtAnzahl}/${p.ticketAnzahl} Tickets erledigt`,
      ticketAnzahl: p.ticketAnzahl,
      aufgeklappt: !eingeklappt[p.projektId],
    })
    if (!eingeklappt[p.projektId]) {
      for (const t of p.tickets) {
        liste.push({
          typ: 'ticket',
          key: 't' + t.id,
          id: t.id,
          name: `#${t.id} ${t.betreff}`,
          farbe: projektFarbe(p.projektId),
          erledigt: t.erledigt,
          linksProzent: t.linksProzent,
          breiteProzent: t.breiteProzent,
          titel: `#${t.id} ${t.betreff}: ${t.startFormatiert} – ${t.endeFormatiert} · ${t.status}`,
        })
      }
    }
  }
  return liste
})
</script>

<template>
  <div class="rb-seite">
    <header class="rb-kopf">
      <h1>Projekte — Zeitplan</h1>
      <div class="rb-kopf-aktionen">
        <button type="button" class="rb-knopf-sekundaer-hell" @click="zurueck">← Zu den Tickets</button>
      </div>
    </header>

    <section class="rb-karte">
      <div class="rb-filter">
        <select v-model="filterProjekt" class="rb-select">
          <option value="">Alle Projekte</option>
          <option v-for="p in projekte" :key="p.projektId" :value="p.projektId">{{ p.name }}</option>
        </select>
        <select v-model="filterStatus" class="rb-select">
          <option value="alle">Alle Status</option>
          <option value="offen">Offen</option>
          <option value="erledigt">Erledigt</option>
        </select>
        <label class="rb-zeitfilter">
          Von
          <input type="date" v-model="filterVon" class="rb-select">
        </label>
        <label class="rb-zeitfilter">
          Bis
          <input type="date" v-model="filterBis" class="rb-select">
        </label>
        <button v-if="filterAktiv" type="button" class="rb-knopf-sekundaer" @click="filterZuruecksetzen">
          Filter zurücksetzen
        </button>
      </div>

      <div v-if="ladend" class="rb-zustand">
        <span class="rb-spinner"></span> Zeitplan wird geladen …
      </div>
      <div v-else-if="fehler" class="rb-zustand rb-zustand-fehler">
        Verbindung zu Redmine fehlgeschlagen: {{ fehler }}
      </div>
      <div v-else-if="!projekte.length" class="rb-zustand">
        Keine Tickets mit Start- und Fälligkeitsdatum gefunden — der Zeitplan braucht
        mindestens ein Ticket mit gesetztem Start- und Enddatum.
      </div>
      <div v-else-if="!zeilen.length" class="rb-zustand">
        Keine Tickets passen zu diesem Filter. <button type="button" class="rb-knopf-textlink" @click="filterZuruecksetzen">Filter zurücksetzen</button>
      </div>

      <div v-else class="rb-gantt-wrapper">
        <div class="rb-gantt">
          <div class="rb-gantt-achse">
            <span class="rb-gantt-projektspalte"></span>
            <div class="rb-gantt-monate">
              <span
                v-for="(m, i) in monate"
                :key="i"
                class="rb-gantt-monat"
                :style="{ left: m.linksProzent + '%' }"
              >{{ m.label }}</span>
            </div>
          </div>

          <div class="rb-gantt-koerper">
            <div class="rb-gantt-projektspalte-liste">
              <div
                v-for="z in zeilen"
                :key="z.key"
                class="rb-gantt-name"
                :class="z.typ === 'projekt' ? 'rb-gantt-projektname' : 'rb-gantt-ticketname'"
              >
                <template v-if="z.typ === 'projekt'">
                  <button
                    type="button"
                    class="rb-gantt-toggle"
                    :title="z.aufgeklappt ? 'Tickets einklappen' : 'Tickets aufklappen'"
                    @click="toggleAufklappen(z.projektId)"
                  >{{ z.aufgeklappt ? '▾' : '▸' }}</button>
                  <span class="rb-punkt" :style="{ background: z.farbe }"></span>
                  <span class="rb-gantt-name-text" :title="z.name">{{ z.name }}</span>
                  <span class="rb-gedaempft rb-gantt-anzahl">{{ z.ticketAnzahl }}</span>
                </template>
                <template v-else>
                  <span class="rb-gantt-name-text" :title="z.name">{{ z.name }}</span>
                </template>
              </div>
            </div>
            <div class="rb-gantt-spuren">
              <span v-if="heuteProzent !== null" class="rb-gantt-heute" :style="{ left: heuteProzent + '%' }" title="Heute"></span>
              <div
                v-for="z in zeilen"
                :key="z.key"
                class="rb-gantt-spur"
                :class="z.typ === 'ticket' ? 'rb-gantt-spur-klein' : ''"
              >
                <div
                  v-if="z.typ === 'projekt'"
                  class="rb-gantt-balken"
                  :style="{ left: z.linksProzent + '%', width: z.breiteProzent + '%' }"
                  :title="z.titel"
                >
                  <span class="rb-gantt-balken-erledigt" :style="{ width: z.erledigtProzent + '%' }"></span>
                </div>
                <div
                  v-else
                  class="rb-gantt-ticketbalken"
                  :class="{ 'ist-erledigt': z.erledigt }"
                  :style="{ left: z.linksProzent + '%', width: z.breiteProzent + '%', background: z.erledigt ? undefined : z.farbe }"
                  :title="z.titel"
                ></div>
              </div>
            </div>
          </div>
        </div>
      </div>

      <p v-if="zeilen.length" class="rb-gedaempft rb-gantt-hinweis">
        Zeitspanne je Projekt aus dem frühesten Start- und spätesten Fälligkeitsdatum seiner
        Tickets — grüner Anteil im Balken zeigt den Anteil bereits erledigter Tickets. Über den
        Pfeil ▸/▾ lassen sich die Einzeltickets je Projekt auf- und zuklappen.
      </p>
    </section>
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
  text-shadow: 0 1px 3px rgba(0, 0, 0, 0.4);
  color: #fff;
}
.rb-knopf-sekundaer-hell {
  display: inline-block;
  padding: 8px 16px;
  border-radius: var(--border-radius, 6px);
  border: none;
  text-decoration: none;
  font-size: 0.9em;
  font-weight: 600;
  background: rgba(255, 255, 255, 0.92);
  /* Bewusst fest statt var(--color-main-text): siehe TicketListe.vue —
     derselbe Knopf-Typ, derselbe Grund. */
  color: #222;
  cursor: pointer;
}
.rb-knopf-sekundaer-hell:hover {
  background: #fff;
}
.rb-karte {
  background: var(--color-main-background, #fff);
  border: 1px solid var(--color-border, #e0e0e3);
  border-radius: var(--border-radius-large, 10px);
  padding: 20px 24px;
}
.rb-gedaempft {
  color: var(--color-text-maxcontrast, #767676);
}

.rb-filter {
  display: flex;
  flex-wrap: wrap;
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
.rb-zeitfilter {
  display: flex;
  align-items: center;
  gap: 6px;
  font-size: 0.85em;
  color: var(--color-text-maxcontrast, #767676);
}
.rb-zeitfilter .rb-select {
  padding: 5px 8px;
  /* Ohne color-scheme rendert Chrome das Kalender-Symbol und das native
     Datumsauswahl-Popup von <input type="date"> weiterhin in Hell —
     auf dem dunklen Eingabefeld (Dunkelmodus) macht das Symbol praktisch
     unsichtbar, wirkt wie "lässt sich nicht öffnen". Nextclouds
     Standard-Theme folgt seinerseits prefers-color-scheme, daher reicht
     hier dieselbe Media Query. */
  color-scheme: light;
}
@media (prefers-color-scheme: dark) {
  .rb-zeitfilter .rb-select {
    color-scheme: dark;
  }
}
.rb-knopf-sekundaer {
  padding: 6px 14px;
  border-radius: var(--border-radius, 6px);
  border: 1px solid var(--color-border, #d8d8db);
  background: var(--color-main-background, #fff);
  color: var(--color-main-text, #222);
  cursor: pointer;
  font-size: 0.85em;
}
.rb-knopf-textlink {
  background: none;
  border: none;
  padding: 0;
  color: var(--color-primary-element, #0069c2);
  text-decoration: underline;
  cursor: pointer;
  font: inherit;
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
@keyframes rb-drehen {
  to { transform: rotate(360deg); }
}

.rb-punkt {
  display: inline-block;
  width: 8px;
  height: 8px;
  border-radius: 50%;
  margin-right: 6px;
  vertical-align: middle;
  flex: none;
}

/* Horizontal scrollbar statt gequetschter Balken auf schmalen Bildschirmen
   — Projektnamen und Zeitachse bleiben dabei bewusst zusammen scrollbar,
   wie auch die Ticket-Tabelle es auf der Startseite handhabt. */
.rb-gantt-wrapper {
  overflow-x: auto;
}
.rb-gantt {
  min-width: 720px;
}

.rb-gantt-achse {
  display: flex;
}
.rb-gantt-projektspalte {
  flex: 0 0 220px;
}
.rb-gantt-monate {
  position: relative;
  flex: 1 1 auto;
  height: 26px;
  border-bottom: 1px solid var(--color-border, #e0e0e3);
}
.rb-gantt-monat {
  position: absolute;
  top: 0;
  padding-left: 4px;
  font-size: 0.78em;
  color: var(--color-text-maxcontrast, #767676);
  white-space: nowrap;
  border-left: 1px solid var(--color-border, #eee);
  height: 100%;
  display: flex;
  align-items: flex-end;
  padding-bottom: 4px;
}

.rb-gantt-koerper {
  display: flex;
}
.rb-gantt-projektspalte-liste {
  flex: 0 0 220px;
}
.rb-gantt-name {
  height: 42px;
  display: flex;
  align-items: center;
  padding-right: 12px;
  overflow: hidden;
  border-bottom: 1px solid var(--color-border, #eee);
}
.rb-gantt-projektname {
  font-size: 0.88em;
  font-weight: 500;
}
.rb-gantt-ticketname {
  height: 30px;
  padding-left: 26px;
  font-size: 0.78em;
  color: var(--color-text-maxcontrast, #767676);
}
.rb-gantt-name-text {
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}
.rb-gantt-anzahl {
  margin-left: auto;
  font-size: 0.85em;
  flex: none;
}
.rb-gantt-toggle {
  flex: none;
  width: 16px;
  border: none;
  background: none;
  padding: 0;
  margin-right: 2px;
  color: var(--color-text-maxcontrast, #767676);
  cursor: pointer;
  font-size: 0.8em;
}

.rb-gantt-spuren {
  position: relative;
  flex: 1 1 auto;
}
.rb-gantt-heute {
  position: absolute;
  top: 0;
  bottom: 0;
  width: 2px;
  background: #c62828;
  z-index: 2;
}
.rb-gantt-spur {
  height: 42px;
  position: relative;
  border-bottom: 1px solid var(--color-border, #eee);
}
.rb-gantt-spur-klein {
  height: 30px;
}
.rb-gantt-balken {
  position: absolute;
  top: 10px;
  height: 22px;
  border-radius: 5px;
  background: var(--color-primary-element, #0069c2);
  overflow: hidden;
  min-width: 6px;
  box-shadow: 0 1px 2px rgba(0, 0, 0, 0.15);
}
.rb-gantt-balken-erledigt {
  display: block;
  height: 100%;
  background: #2e7d32;
}
.rb-gantt-ticketbalken {
  position: absolute;
  top: 8px;
  height: 14px;
  border-radius: 4px;
  min-width: 5px;
  opacity: 0.85;
}
.rb-gantt-ticketbalken.ist-erledigt {
  background: #2e7d32;
  opacity: 0.7;
}

.rb-gantt-hinweis {
  margin: 16px 0 4px;
  font-size: 0.85em;
}

@media (max-width: 640px) {
  .rb-seite {
    padding: 16px;
  }
  .rb-karte {
    padding: 12px 16px;
    border-radius: var(--border-radius, 6px);
  }
  .rb-filter {
    flex-direction: column;
    align-items: stretch;
  }
  .rb-filter .rb-select,
  .rb-zeitfilter {
    width: 100%;
    box-sizing: border-box;
  }
}
</style>
