<script setup>
import { ref, onMounted, watch, computed } from 'vue'
import { api } from '../lib/api.js'

const tickets = ref([])
const gesamt = ref(0)
const seite = ref(1)
const status = ref('open')
const projektFilter = ref('')
const projekte = ref([])
const statusListe = ref([])
const fortschrittLaeuftFuer = ref(null)
const ladend = ref(true)
const fehler = ref(null)

async function laden() {
  ladend.value = true
  fehler.value = null
  try {
    const antwort = await api.tickets(status.value, projektFilter.value, seite.value)
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

watch([status, projektFilter], () => {
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

// Tickets nach Projekt gruppiert statt als flache Liste — Reihenfolge der
// Gruppen bleibt dabei die der aktuellen Sortierung (server-seitig nach
// updated_on:desc), die Gruppe des zuletzt bearbeiteten Tickets steht also
// oben. Gilt nur je geladener Seite (Paginierung bleibt unverändert).
const gruppiert = computed(() => {
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
</script>

<template>
  <div class="rb-seite">
    <header class="rb-kopf">
      <h1>Tickets</h1>
      <div class="rb-kopf-aktionen">
        <a href="#/projekte" class="rb-knopf-sekundaer-hell">📊 Zeitplan</a>
        <a href="#/projekte/neu" class="rb-knopf-sekundaer-hell">+ Neues Projekt</a>
        <a href="#/tickets/neu" class="rb-knopf-primaer-hell">+ Neues Ticket</a>
      </div>
    </header>

    <section class="rb-karte">
      <div class="rb-filter">
        <select v-model="status" class="rb-select">
          <option value="open">Offen</option>
          <option value="closed">Erledigt</option>
          <option value="*">Alle</option>
        </select>
        <select v-model="projektFilter" class="rb-select">
          <option value="">Alle Projekte</option>
          <option v-for="p in projekte" :key="p.id" :value="p.id">{{ p.name }}</option>
        </select>
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

      <table v-else class="rb-tabelle">
        <thead>
          <tr>
            <th class="rb-col-schmal">#</th>
            <th>Betreff</th>
            <th class="rb-col-schmal">Status</th>
            <th class="rb-col-schmal">Priorität</th>
            <th>Zugewiesen an</th>
            <th class="rb-col-erfuellung">Erfüllung</th>
          </tr>
        </thead>
        <tbody v-for="g in gruppiert" :key="g.projekt?.id ?? 'ohne-projekt'">
          <tr class="rb-gruppenkopf">
            <td colspan="6" :style="{ borderLeftColor: projektFarbe(g.projekt?.id) }">
              <span class="rb-punkt" :style="{ background: projektFarbe(g.projekt?.id) }"></span>
              <strong>{{ g.projekt?.name || 'Ohne Projekt' }}</strong>
              <span class="rb-gedaempft"> · {{ g.tickets.length }} Ticket{{ g.tickets.length === 1 ? '' : 's' }}</span>
            </td>
          </tr>
          <tr v-for="t in g.tickets" :key="t.id" class="rb-zeile" tabindex="0" @click="offnen(t.id)" @keydown.enter="offnen(t.id)">
            <td class="rb-nummer">#{{ t.id }}</td>
            <td class="rb-betreff">{{ t.subject }}</td>
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
          </tr>
        </tbody>
      </table>

      <div v-if="gesamt > 50" class="rb-pagination">
        <button class="rb-knopf-sekundaer" :disabled="seite === 1" @click="seite--">← Zurück</button>
        <span class="rb-gedaempft">Seite {{ seite }} von {{ Math.ceil(gesamt / 50) }} · {{ gesamt }} Tickets</span>
        <button class="rb-knopf-sekundaer" :disabled="seite >= Math.ceil(gesamt / 50)" @click="seite++">Weiter →</button>
      </div>
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
  /* Die Tabelle bleibt in ihrer Breite erhalten und scrollt seitlich,
     statt Spalten bis zur Unleserlichkeit zu quetschen. */
  .rb-tabelle {
    display: block;
    overflow-x: auto;
    white-space: nowrap;
  }
  .rb-betreff {
    white-space: normal;
    min-width: 140px;
  }
}
</style>
