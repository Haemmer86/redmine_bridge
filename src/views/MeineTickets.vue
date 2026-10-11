<script setup>
import { ref, onMounted, watch, reactive } from 'vue'
import { api } from '../lib/api.js'
import { istHeic, heicZuJpeg } from '../lib/heic.js'

// Eingeschränkte Mitarbeiter-Ansicht: nur die dem Konto zugeordneten
// Tickets (siehe MitarbeiterController::meineTickets()), Status ändern + Kommentar
// hinzufügen — keine Projektverwaltung, Zeiterfassung, Dateien, Rechnungen
// o. ä. Absichtlich eine eigene, einfache Komponente statt TicketListe.vue
// wiederzuverwenden: andere Zielgruppe, deutlich kleinerer Funktionsumfang,
// eigene serverseitige Endpunkte (siehe App.vue für die Weiche).
//
// `zeigeZurueckLink`: true nur, wenn diese Ansicht freiwillig aufgerufen
// wurde (Admin-Konto, das zusätzlich Mitglied der Mitarbeiter-Gruppe ist —
// siehe TicketListe.vue/App.vue) statt fest erzwungen. Normale
// Gruppenmitglieder ohne Admin-Rechte haben keine andere Ansicht, zu der
// sie zurückkönnten, für sie bleibt der Link weg.
defineProps({
  zeigeZurueckLink: { type: Boolean, default: false },
})

const tickets = ref([])
const statusListe = ref([])
const status = ref('open')
const ladend = ref(true)
const fehler = ref(null)

// Je Ticket eigener Bearbeitungszustand (gewählter Status, Bemerkungstext,
// Speichern-Zustand) — getrennt vom geladenen Ticket gehalten, analog zum
// `formular`-Muster in TicketDetail.vue.
const bearbeitung = reactive({})

function bearbeitungFuer(ticket) {
  if (!bearbeitung[ticket.id]) {
    bearbeitung[ticket.id] = {
      statusId: ticket.status?.id ?? null,
      notiz: '',
      speichernLaeuft: false,
      fehler: null,
      gespeichertHinweis: false,
      dateiHochladenLaeuft: false,
      dateiFehler: null,
      dateiHochgeladenName: null,
    }
  }
  return bearbeitung[ticket.id]
}

async function laden() {
  ladend.value = true
  fehler.value = null
  try {
    const antwort = await api.meineTickets(status.value)
    tickets.value = antwort.tickets || []
    for (const t of tickets.value) bearbeitungFuer(t)
  } catch (e) {
    fehler.value = e.message
  } finally {
    ladend.value = false
  }
}

onMounted(async () => {
  laden()
  try {
    const antwort = await api.meineTicketsStatusListe()
    statusListe.value = antwort.status || []
  } catch {
    // Ohne Statusliste bleibt die Auswahl leer — Status ändern geht dann
    // nicht, der Rest der Seite (Tickets ansehen, Bemerkung schreiben)
    // funktioniert trotzdem.
  }
})

watch(status, laden)

async function speichern(ticket) {
  const b = bearbeitungFuer(ticket)
  const statusGeaendert = b.statusId !== (ticket.status?.id ?? null)
  const notizVorhanden = b.notiz.trim() !== ''
  if ((!statusGeaendert && !notizVorhanden) || b.speichernLaeuft) return

  b.speichernLaeuft = true
  b.fehler = null
  b.gespeichertHinweis = false
  try {
    const antwort = await api.meinTicketAktualisieren(ticket.id, {
      statusId: statusGeaendert ? b.statusId : null,
      notiz: b.notiz,
    })
    if (antwort.ticket) {
      Object.assign(ticket, antwort.ticket)
    }
    b.notiz = ''
    b.statusId = ticket.status?.id ?? null
    b.gespeichertHinweis = true
    setTimeout(() => { b.gespeichertHinweis = false }, 3000)
    // Ist das Ticket nach einer Statusänderung in der aktuellen Ansicht
    // "Offen" nicht mehr offen, gehört es dort nicht mehr hin — dieselbe
    // Logik wie in TicketListe.vue.
    if (status.value === 'open' && statusGeaendert) {
      const nochOffen = statusListe.value.find((s) => s.id === b.statusId)
      if (nochOffen?.is_closed) {
        tickets.value = tickets.value.filter((t) => t.id !== ticket.id)
      }
    }
  } catch (e) {
    b.fehler = e.message
  } finally {
    b.speichernLaeuft = false
  }
}

// Datei zum Ticket hochladen — bewusst die einzige Datei-Funktion dieser
// Ansicht: kein Einblick in bereits vorhandene Dateien, nur Hochladen (siehe
// MitarbeiterController::meinTicketDateiHochladen()). HEIC/HEIF (typisch bei einem
// frisch mit dem iPhone aufgenommenen Foto) wird vor dem Hochladen in JPEG
// umgewandelt, dieselbe Logik wie in TicketDetail.vue.
async function dateiHochladen(ticket, ereignis) {
  const datei = ereignis.target.files?.[0]
  ereignis.target.value = '' // dieselbe Datei muss sich erneut auswählen lassen
  if (!datei) return

  const b = bearbeitungFuer(ticket)
  b.dateiHochladenLaeuft = true
  b.dateiFehler = null
  b.dateiHochgeladenName = null
  try {
    const hochzuladendeDatei = istHeic(datei) ? await heicZuJpeg(datei) : datei
    const antwort = await api.meinTicketDateiHochladen(ticket.id, hochzuladendeDatei)
    b.dateiHochgeladenName = antwort.hochgeladen
    setTimeout(() => { b.dateiHochgeladenName = null }, 4000)
  } catch (e) {
    b.dateiFehler = e.message
  } finally {
    b.dateiHochladenLaeuft = false
  }
}
</script>

<template>
  <div class="rb-mt-seite">
    <header class="rb-mt-kopf">
      <h1>Meine Tickets</h1>
      <a v-if="zeigeZurueckLink" href="#/" class="rb-mt-zurueck">← Zur Ticketübersicht</a>
    </header>

    <section class="rb-mt-karte">
      <div class="rb-mt-filter">
        <button type="button" :class="{ 'rb-mt-filter-aktiv': status === 'open' }" @click="status = 'open'">Offen</button>
        <button type="button" :class="{ 'rb-mt-filter-aktiv': status === '*' }" @click="status = '*'">Alle</button>
      </div>

      <div v-if="ladend" class="rb-mt-zustand">
        <span class="rb-mt-spinner"></span> Tickets werden geladen …
      </div>
      <div v-else-if="fehler" class="rb-mt-zustand rb-mt-zustand-fehler">{{ fehler }}</div>
      <div v-else-if="tickets.length === 0" class="rb-mt-zustand">Keine Tickets in dieser Ansicht.</div>

      <div v-else class="rb-mt-liste">
        <div v-for="t in tickets" :key="t.id" class="rb-mt-ticket">
          <div class="rb-mt-ticket-kopf">
            <span class="rb-mt-nummer">#{{ t.id }}</span>
            <strong class="rb-mt-betreff">{{ t.subject }}</strong>
            <span class="rb-mt-projekt">{{ t.project?.name }}</span>
          </div>

          <div class="rb-mt-ticket-zeile">
            <label :for="'status-' + t.id">Status</label>
            <select :id="'status-' + t.id" v-model.number="bearbeitungFuer(t).statusId" class="rb-mt-select">
              <option v-for="s in statusListe" :key="s.id" :value="s.id">{{ s.name }}</option>
            </select>
          </div>

          <div class="rb-mt-ticket-zeile rb-mt-ticket-zeile-bemerkung">
            <label :for="'notiz-' + t.id">Bemerkung</label>
            <textarea
              :id="'notiz-' + t.id"
              v-model="bearbeitungFuer(t).notiz"
              rows="2"
              placeholder="Kurze Bemerkung zum Ticket …"
              class="rb-mt-textarea"
            ></textarea>
          </div>

          <div class="rb-mt-datei-zeile">
            <label class="rb-mt-hochladen" :class="{ 'rb-mt-hochladen-aktiv': bearbeitungFuer(t).dateiHochladenLaeuft }">
              <span v-if="bearbeitungFuer(t).dateiHochladenLaeuft"><span class="rb-mt-spinner"></span> Wird hochgeladen …</span>
              <span v-else>+ Datei hochladen</span>
              <input
                type="file"
                :disabled="bearbeitungFuer(t).dateiHochladenLaeuft"
                class="rb-mt-datei-input"
                @change="dateiHochladen(t, $event)"
              >
            </label>
            <span v-if="bearbeitungFuer(t).dateiHochgeladenName" class="rb-mt-gespeichert">✔ „{{ bearbeitungFuer(t).dateiHochgeladenName }}" hochgeladen</span>
          </div>
          <p v-if="bearbeitungFuer(t).dateiFehler" class="rb-mt-zustand-fehler">{{ bearbeitungFuer(t).dateiFehler }}</p>

          <p v-if="bearbeitungFuer(t).fehler" class="rb-mt-zustand-fehler">{{ bearbeitungFuer(t).fehler }}</p>

          <div class="rb-mt-ticket-aktionen">
            <span v-if="bearbeitungFuer(t).gespeichertHinweis" class="rb-mt-gespeichert">✔ Gespeichert</span>
            <button
              type="button"
              class="rb-mt-knopf-primaer"
              :disabled="bearbeitungFuer(t).speichernLaeuft"
              @click="speichern(t)"
            >
              {{ bearbeitungFuer(t).speichernLaeuft ? 'Wird gespeichert …' : 'Speichern' }}
            </button>
          </div>
        </div>
      </div>
    </section>
  </div>
</template>

<style scoped>
.rb-mt-seite {
  padding: 32px 24px;
  max-width: 720px;
  margin: 0 auto;
}
.rb-mt-kopf {
  display: flex;
  align-items: baseline;
  justify-content: space-between;
  gap: 12px;
  flex-wrap: wrap;
}
.rb-mt-kopf h1 {
  margin: 0 0 16px;
  font-size: 1.5em;
  text-shadow: 0 1px 3px rgba(0, 0, 0, 0.4);
  color: #fff;
}
.rb-mt-zurueck {
  margin-bottom: 16px;
  color: #fff;
  text-shadow: 0 1px 3px rgba(0, 0, 0, 0.4);
  text-decoration: underline;
  font-size: 0.9em;
  white-space: nowrap;
}

.rb-mt-karte {
  background: var(--color-main-background, #fff);
  border: 1px solid var(--color-border, #e0e0e3);
  border-radius: var(--border-radius-large, 10px);
  padding: 20px 24px;
}
.rb-mt-filter {
  display: flex;
  gap: 8px;
  margin-bottom: 16px;
}
.rb-mt-filter button {
  padding: 6px 14px;
  border-radius: var(--border-radius, 6px);
  border: 1px solid var(--color-border, #d8d8db);
  background: var(--color-main-background, #fff);
  color: var(--color-main-text, #222);
  cursor: pointer;
}
.rb-mt-filter-aktiv {
  background: var(--color-primary-element, #0069c2) !important;
  border-color: var(--color-primary-element, #0069c2) !important;
  color: #fff !important;
}
.rb-mt-zustand {
  padding: 32px 12px;
  text-align: center;
  color: var(--color-text-maxcontrast, #767676);
}
.rb-mt-zustand-fehler {
  color: #c62828;
}
.rb-mt-spinner {
  display: inline-block;
  width: 14px;
  height: 14px;
  border: 2px solid var(--color-border, #d8d8db);
  border-top-color: var(--color-primary-element, #0069c2);
  border-radius: 50%;
  animation: rb-mt-drehen 0.7s linear infinite;
  vertical-align: middle;
  margin-right: 8px;
}
@keyframes rb-mt-drehen {
  to { transform: rotate(360deg); }
}
.rb-mt-liste {
  display: flex;
  flex-direction: column;
  gap: 16px;
}
.rb-mt-ticket {
  border: 1px solid var(--color-border, #e0e0e3);
  border-radius: var(--border-radius, 6px);
  padding: 14px 16px;
}
.rb-mt-ticket-kopf {
  display: flex;
  flex-wrap: wrap;
  align-items: baseline;
  gap: 8px;
  margin-bottom: 10px;
}
.rb-mt-nummer {
  color: var(--color-text-maxcontrast, #767676);
  font-variant-numeric: tabular-nums;
}
.rb-mt-betreff {
  flex: 1 1 auto;
}
.rb-mt-projekt {
  color: var(--color-text-maxcontrast, #767676);
  font-size: 0.9em;
}
.rb-mt-ticket-zeile {
  display: flex;
  align-items: center;
  gap: 10px;
  margin-bottom: 8px;
}
.rb-mt-ticket-zeile-bemerkung {
  align-items: flex-start;
}
.rb-mt-ticket-zeile label {
  flex: none;
  width: 80px;
  font-size: 0.9em;
  color: var(--color-text-maxcontrast, #767676);
}
.rb-mt-select,
.rb-mt-textarea {
  flex: 1;
  box-sizing: border-box;
  padding: 6px 8px;
  border-radius: var(--border-radius, 6px);
  border: 1px solid var(--color-border, #d8d8db);
  background: var(--color-main-background, #fff);
  color: var(--color-main-text, #222);
  font: inherit;
}
.rb-mt-textarea {
  resize: vertical;
  min-height: 2.4em;
}
.rb-mt-datei-zeile {
  display: flex;
  align-items: center;
  gap: 10px;
  margin-bottom: 8px;
}
.rb-mt-hochladen {
  display: inline-block;
  padding: 6px 14px;
  border: 1px dashed var(--color-border-dark, #b8b8bb);
  border-radius: var(--border-radius, 6px);
  cursor: pointer;
  font-size: 0.9em;
  color: var(--color-main-text, #222);
}
.rb-mt-hochladen:hover {
  border-color: var(--color-primary-element, #0069c2);
}
.rb-mt-hochladen-aktiv {
  cursor: default;
  color: var(--color-text-maxcontrast, #767676);
}
.rb-mt-datei-input {
  position: absolute;
  width: 1px;
  height: 1px;
  opacity: 0;
  overflow: hidden;
}
.rb-mt-ticket-aktionen {
  display: flex;
  align-items: center;
  justify-content: flex-end;
  gap: 12px;
  margin-top: 4px;
}
.rb-mt-gespeichert {
  color: #2e7d32;
  font-size: 0.9em;
}
.rb-mt-knopf-primaer {
  padding: 6px 16px;
  border-radius: var(--border-radius, 6px);
  border: none;
  background: var(--color-primary-element, #0069c2);
  color: #fff;
  cursor: pointer;
}
.rb-mt-knopf-primaer:disabled {
  opacity: 0.5;
  cursor: default;
}
</style>
