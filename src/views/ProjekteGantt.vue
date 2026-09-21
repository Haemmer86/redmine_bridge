<script setup>
import { ref, onMounted, computed } from 'vue'
import { api } from '../lib/api.js'

const projekte = ref([])
const ladend = ref(true)
const fehler = ref(null)

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

// ─── Zeitachse ────────────────────────────────────────────────────────
//
// Redmine liefert nur Start-/Enddatum je TICKET, kein eigenes Datum je
// Projekt — die Spanne je Projekt kommt bereits fertig aggregiert vom
// Server (siehe ApiController::projekteGantt()). Hier wird daraus nur
// noch die gemeinsame Zeitachse für alle Projekte berechnet, auf ganze
// Monate gerundet mit etwas Luft an beiden Rändern.

const bereich = computed(() => {
  if (!projekte.value.length) return null
  let min = new Date(projekte.value[0].start)
  let max = new Date(projekte.value[0].ende)
  for (const p of projekte.value) {
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

const balken = computed(() => {
  if (!bereich.value) return []
  return projekte.value.map((p) => {
    const start = new Date(p.start)
    const ende = new Date(p.ende)
    const linksProzent = ((start.getTime() - bereich.value.anfang.getTime()) / bereich.value.spanneMs) * 100
    // Mindestbreite, damit auch eintägige/kurze Tickets als Balken sichtbar bleiben.
    const breiteProzent = Math.max(((ende.getTime() - start.getTime()) / bereich.value.spanneMs) * 100, 0.6)
    const erledigtProzent = p.ticketAnzahl ? (p.erledigtAnzahl / p.ticketAnzahl) * 100 : 0
    return {
      ...p,
      linksProzent,
      breiteProzent,
      erledigtProzent,
      startFormatiert: datumFormatieren(p.start),
      endeFormatiert: datumFormatieren(p.ende),
    }
  })
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
      <div v-if="ladend" class="rb-zustand">
        <span class="rb-spinner"></span> Zeitplan wird geladen …
      </div>
      <div v-else-if="fehler" class="rb-zustand rb-zustand-fehler">
        Verbindung zu Redmine fehlgeschlagen: {{ fehler }}
      </div>
      <div v-else-if="!balken.length" class="rb-zustand">
        Keine Tickets mit Start- und Fälligkeitsdatum gefunden — der Zeitplan braucht
        mindestens ein Ticket mit gesetztem Start- und Enddatum.
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
              <div v-for="p in balken" :key="p.projektId + '-name'" class="rb-gantt-projektname" :title="p.name">
                {{ p.name }}
              </div>
            </div>
            <div class="rb-gantt-spuren">
              <span v-if="heuteProzent !== null" class="rb-gantt-heute" :style="{ left: heuteProzent + '%' }" title="Heute"></span>
              <div v-for="p in balken" :key="p.projektId" class="rb-gantt-spur">
                <div
                  class="rb-gantt-balken"
                  :style="{ left: p.linksProzent + '%', width: p.breiteProzent + '%' }"
                  :title="`${p.name}: ${p.startFormatiert} – ${p.endeFormatiert} · ${p.erledigtAnzahl}/${p.ticketAnzahl} Tickets erledigt`"
                >
                  <span class="rb-gantt-balken-erledigt" :style="{ width: p.erledigtProzent + '%' }"></span>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>

      <p class="rb-gedaempft rb-gantt-hinweis">
        Zeitspanne je Projekt aus dem frühesten Start- und spätesten Fälligkeitsdatum seiner
        Tickets — grüner Anteil im Balken zeigt den Anteil bereits erledigter Tickets.
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
  color: var(--color-main-text, #222);
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
  flex: 0 0 200px;
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
  flex: 0 0 200px;
}
.rb-gantt-projektname {
  height: 42px;
  display: flex;
  align-items: center;
  padding-right: 12px;
  font-size: 0.88em;
  font-weight: 500;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
  border-bottom: 1px solid var(--color-border, #eee);
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
}
</style>
