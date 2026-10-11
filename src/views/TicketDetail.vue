<script setup>
import { ref, reactive, onMounted, watch, computed } from 'vue'
import { api } from '../lib/api.js'
import TicketZeiterfassung from '../components/TicketZeiterfassung.vue'
import TicketAbrechnung from '../components/TicketAbrechnung.vue'
import TicketKommentare from '../components/TicketKommentare.vue'
import TicketAblage from '../components/TicketAblage.vue'
import DateiVorschau from '../components/DateiVorschau.vue'

const props = defineProps({
  id: { type: Number, required: true },
})

const ticket = ref(null)
const ordnerPfad = ref(null)
const ordnerFehler = ref(null)
const dateien = ref([])
const formulardaten = ref({ tracker: [], status: [], prioritaet: [], projekte: [] })
const mitglieder = ref([])
const mitarbeiterListe = ref([])
const ladend = ref(true)
const speichernLaeuft = ref(false)
const fehler = ref(null)
const gespeichertHinweis = ref(false)

// Die editierbaren Felder getrennt vom Original halten — sonst zeigt die
// Maske beim Tippen sofort einen "ungespeichert"-Zustand für Felder, die
// noch niemand angefasst hat, und ein Ladefehler könnte das Original
// überschreiben, während man mitten in der Eingabe ist.
const formular = reactive({
  subject: '',
  description: '',
  status_id: null,
  priority_id: null,
  tracker_id: null,
  assigned_to_id: null,
  start_date: '',
  due_date: '',
  done_ratio: 0,
  estimated_hours: null,
})

// Redmine-unabhängig: wer dieses Ticket in der eingeschränkten "Meine
// Tickets"-Ansicht sieht (siehe api.mitarbeiterListe()/zustaendigerMitarbeiterSetzen()
// und TicketZustaendigkeitService) — bewusst getrennt von `formular`, da es
// kein Redmine-Feld ist und über einen eigenen Endpunkt gespeichert wird,
// statt mit `{ ...formular }` an ticketAktualisieren() durchgereicht zu
// werden (das würde die Zuordnung still verwerfen, siehe speichern() unten).
const zustaendigerMitarbeiter = ref(null)

// Schnappschuss des zuletzt geladenen/gespeicherten Formularstands — Grundlage
// für die "ungespeicherte Änderungen"-Erkennung beim Verlassen der Seite
// (siehe `hatUngespeicherteAenderungen` und `zurueck()` unten). Als String statt
// als Objekt gehalten, damit ein einfacher Vergleich reicht, ohne bei jedem
// Tastendruck jedes Feld einzeln gegenzuprüfen.
const formularSchnappschuss = ref('')

function uebernehmen(t) {
  formular.subject = t.subject || ''
  formular.description = t.description || ''
  formular.status_id = t.status?.id ?? null
  formular.priority_id = t.priority?.id ?? null
  formular.tracker_id = t.tracker?.id ?? null
  formular.assigned_to_id = t.assigned_to?.id ?? null
  formular.start_date = t.start_date || ''
  formular.due_date = t.due_date || ''
  formular.done_ratio = t.done_ratio ?? 0
  formular.estimated_hours = t.estimated_hours ?? null
  zustaendigerMitarbeiter.value = t.zustaendigerMitarbeiter ?? null
  formularSchnappschuss.value = JSON.stringify({ ...formular, zustaendigerMitarbeiter: zustaendigerMitarbeiter.value })
}

const hatUngespeicherteAenderungen = computed(() =>
  JSON.stringify({ ...formular, zustaendigerMitarbeiter: zustaendigerMitarbeiter.value }) !== formularSchnappschuss.value,
)

async function laden() {
  ladend.value = true
  fehler.value = null
  try {
    const antwort = await api.ticket(props.id)
    ticket.value = antwort.ticket
    ordnerPfad.value = antwort.ordnerPfad
    ordnerFehler.value = antwort.ordnerFehler
    dateien.value = antwort.dateien
    uebernehmen(antwort.ticket)

    if (antwort.ticket?.project?.id) {
      api.mitglieder(antwort.ticket.project.id)
        .then((m) => { mitglieder.value = m.mitglieder })
        .catch(() => { mitglieder.value = [] })
    }
  } catch (e) {
    fehler.value = e.message
  } finally {
    ladend.value = false
  }
}

onMounted(async () => {
  laden()
  try {
    formulardaten.value = await api.formulardaten()
  } catch {
    // Ohne Formulardaten lassen sich die Auswahllisten nicht befüllen —
    // die Maske zeigt dann leere Dropdowns, ist aber nicht blockiert.
  }
  try {
    const antwort = await api.mitarbeiterListe()
    mitarbeiterListe.value = antwort.mitarbeiter || []
  } catch {
    mitarbeiterListe.value = []
  }
})

watch(() => props.id, laden)

async function speichern() {
  speichernLaeuft.value = true
  fehler.value = null
  gespeichertHinweis.value = false
  try {
    const antwort = await api.ticketAktualisieren(props.id, { ...formular })
    const frischesTicket = antwort.ticket

    // Kein Redmine-Feld, deshalb über einen eigenen Endpunkt gespeichert —
    // nur aufgerufen, wenn sich tatsächlich etwas geändert hat, damit ein
    // bloßes erneutes Speichern nicht unnötig die Zuordnungstabelle anfasst.
    if (zustaendigerMitarbeiter.value !== (ticket.value?.zustaendigerMitarbeiter ?? null)) {
      const zAntwort = await api.zustaendigerMitarbeiterSetzen(props.id, zustaendigerMitarbeiter.value)
      if (frischesTicket) frischesTicket.zustaendigerMitarbeiter = zAntwort.zustaendigerMitarbeiter
    }

    ticket.value = frischesTicket
    ordnerPfad.value = antwort.ordnerPfad
    ordnerFehler.value = antwort.ordnerFehler
    dateien.value = antwort.dateien
    uebernehmen(frischesTicket)
    gespeichertHinweis.value = true
    setTimeout(() => { gespeichertHinweis.value = false }, 3000)
  } catch (e) {
    fehler.value = e.message
  } finally {
    speichernLaeuft.value = false
  }
}

// ─── Ablage-Stand, Fehlermeldungen und Vorschau der Unterkomponenten ───────
function ablageUebernehmen(stand) {
  ordnerPfad.value = stand.ordnerPfad
  ordnerFehler.value = stand.ordnerFehler
  dateien.value = stand.dateien
}

const vorschauDatei = ref(null)

function vorschauOeffnen(datei) {
  vorschauDatei.value = datei
}

function vorschauSchliessen() {
  vorschauDatei.value = null
}

function dateiGeloescht(dateiId) {
  if (vorschauDatei.value?.id === dateiId) vorschauSchliessen()
}

// Vor dem Verlassen warnen, wenn am Formular oben (Betreff, Beschreibung,
// Status usw.) etwas geändert, aber noch nicht über den Speichern-Knopf
// gesichert wurde — die übrigen Bereiche der Seite (Zeiterfassung, Links,
// Notizen, Dateien, Rechnungsdaten) speichern ohnehin sofort bzw. haben
// eigene Speichern-Knöpfe und lösen diese Warnung nicht aus.
function zurueck() {
  if (hatUngespeicherteAenderungen.value) {
    const verlassenOhneSpeichern = window.confirm(
      'Es gibt ungespeicherte Änderungen an diesem Ticket. Trotzdem zur Liste zurückkehren und die Änderungen verwerfen?',
    )
    if (!verlassenOhneSpeichern) {
      return
    }
  }
  window.location.hash = ''
}
</script>

<template>
  <div class="rb-seite">
    <a href="#" class="rb-zurueck" @click.prevent="zurueck">← Zurück zur Liste</a>

    <div v-if="ladend" class="rb-zustand">
      <span class="rb-spinner"></span> Ticket wird geladen …
    </div>
    <div v-else-if="fehler && !ticket" class="rb-zustand rb-zustand-fehler">
      Ticket konnte nicht geladen werden: {{ fehler }}
    </div>

    <template v-else-if="ticket">
      <header class="rb-kopf">
        <span class="rb-projektname">{{ ticket.project?.name }}</span>
        <h1>#{{ ticket.id }} {{ ticket.subject }}</h1>
      </header>

      <div v-if="fehler" class="rb-meldung rb-meldung-fehler">{{ fehler }}</div>
      <div v-if="gespeichertHinweis" class="rb-meldung rb-meldung-erfolg">Gespeichert.</div>

      <div class="rb-layout">
        <section class="rb-karte">
          <label class="rb-feld-breit">
            <span class="rb-label">Betreff</span>
            <input v-model="formular.subject" type="text" class="rb-eingabe">
          </label>

          <label class="rb-feld-breit">
            <span class="rb-label">Beschreibung</span>
            <textarea v-model="formular.description" rows="6" class="rb-eingabe"></textarea>
          </label>

          <div class="rb-raster">
            <label>
              <span class="rb-label">Tracker</span>
              <select v-model.number="formular.tracker_id" class="rb-eingabe">
                <option v-for="t in formulardaten.tracker" :key="t.id" :value="t.id">{{ t.name }}</option>
              </select>
            </label>
            <label>
              <span class="rb-label">Status</span>
              <select v-model.number="formular.status_id" class="rb-eingabe">
                <option v-for="s in formulardaten.status" :key="s.id" :value="s.id">{{ s.name }}</option>
              </select>
            </label>
            <label>
              <span class="rb-label">Priorität</span>
              <select v-model.number="formular.priority_id" class="rb-eingabe">
                <option v-for="p in formulardaten.prioritaet" :key="p.id" :value="p.id">{{ p.name }}</option>
              </select>
            </label>
            <label>
              <span class="rb-label">Zugewiesen an</span>
              <select v-model.number="formular.assigned_to_id" class="rb-eingabe">
                <option :value="null">— Niemand —</option>
                <option v-for="m in mitglieder" :key="m.id" :value="m.id">{{ m.name }}</option>
              </select>
            </label>
            <label>
              <span class="rb-label">Zuständiger Mitarbeiter</span>
              <select v-model="zustaendigerMitarbeiter" class="rb-eingabe">
                <option :value="null">— Niemand —</option>
                <option v-for="m in mitarbeiterListe" :key="m.uid" :value="m.uid">{{ m.name }}</option>
              </select>
            </label>
            <label>
              <span class="rb-label">Start</span>
              <input v-model="formular.start_date" type="date" class="rb-eingabe">
            </label>
            <label>
              <span class="rb-label">Fällig am</span>
              <input v-model="formular.due_date" type="date" class="rb-eingabe">
            </label>
            <label>
              <span class="rb-label">Fortschritt</span>
              <div class="rb-fortschritt-zeile">
                <input v-model.number="formular.done_ratio" type="range" min="0" max="100" step="10" class="rb-schieber">
                <span class="rb-gedaempft">{{ formular.done_ratio }} %</span>
              </div>
            </label>
            <label>
              <span class="rb-label">Geschätzte Stunden</span>
              <input v-model.number="formular.estimated_hours" type="number" min="0" step="0.5" class="rb-eingabe">
            </label>
          </div>

          <TicketZeiterfassung
            :id="id"
            :spentHours="ticket.spent_hours"
            @aktualisiert="ticket = $event"
          />

          <TicketAbrechnung
            :id="id"
            :rechnungsstatus="ticket.rechnungsstatus"
            :spentHours="ticket.spent_hours"
            @aktualisiert="ticket.rechnungsstatus = $event"
          />

          <div class="rb-aktionen">
            <button class="rb-knopf-primaer" :disabled="speichernLaeuft" @click="speichern">
              <span v-if="speichernLaeuft" class="rb-spinner rb-spinner-hell"></span>
              {{ speichernLaeuft ? 'Speichert …' : 'Speichern' }}
            </button>
          </div>

          <TicketKommentare
            :id="id"
            :journals="ticket.journals"
            @aktualisiert="ticket = $event"
            @fehler="fehler = $event"
          />
        </section>

        <TicketAblage
          :id="id"
          :dateien="dateien"
          :ordnerPfad="ordnerPfad"
          :ordnerFehler="ordnerFehler"
          @aktualisiert="ablageUebernehmen"
          @fehler="fehler = $event"
          @vorschau-oeffnen="vorschauOeffnen"
          @datei-geloescht="dateiGeloescht"
        />
      </div>

      <DateiVorschau
        v-if="vorschauDatei"
        :key="vorschauDatei.id"
        :datei="vorschauDatei"
        @schliessen="vorschauSchliessen"
      />
    </template>
  </div>
</template>

<style scoped>
.rb-seite {
  padding: 32px 40px;
  max-width: none;
}

.rb-zurueck {
  display: inline-block;
  margin-bottom: 20px;
  color: #fff;
  text-shadow: 0 1px 3px rgba(0, 0, 0, 0.4);
  text-decoration: none;
}

.rb-zurueck:hover {
  color: var(--color-primary-element-light, #cde5f7);
}

.rb-kopf {
  margin-bottom: 24px;
}

.rb-projektname {
  display: block;
  font-size: 0.85em;
  color: #fff;
  text-shadow: 0 1px 3px rgba(0, 0, 0, 0.4);
  margin-bottom: 2px;
}

.rb-kopf h1 {
  margin: 0;
  font-size: 1.4em;
  color: #fff;
  text-shadow: 0 1px 3px rgba(0, 0, 0, 0.4);
}

.rb-layout {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 20px;
  align-items: start;
}

.rb-layout > .rb-karte {
  /* Ohne das hier: eine einzelne lange, leerzeichenlose Zeichenkette
     (z. B. ein langer Dateiname) zwingt diese Spalte auf ihre
     Inhalts-Mindestbreite, egal was "1fr 1fr" eigentlich vorgibt — die
     rechte Spalte wird dann breiter als die Hälfte, das Layout läuft aus
     dem Bildschirm. min-width: 0 erlaubt dem Grid-Item, unter seine
     Inhalts-Mindestbreite zu schrumpfen, sodass stattdessen der Inhalt
     selbst umbricht/abgeschnitten wird (siehe .rb-dateiname). */
  min-width: 0;
}

.rb-karte {
  background: var(--color-main-background, #fff);
  border: 1px solid var(--color-border, #e0e0e3);
  border-radius: var(--border-radius-large, 10px);
  padding: 24px;
}

.rb-feld-breit {
  display: flex;
  flex-direction: column;
  gap: 6px;
  margin-bottom: 16px;
}

.rb-label {
  font-size: 0.8em;
  color: var(--color-text-maxcontrast, #767676);
}

.rb-eingabe {
  padding: 7px 10px;
  border-radius: var(--border-radius, 6px);
  border: 1px solid var(--color-border, #d8d8db);
  background: var(--color-main-background, #fff);
  color: var(--color-main-text, #222);
  font: inherit;
  width: 100%;
  box-sizing: border-box;
}

.rb-eingabe:focus-visible {
  outline: 2px solid var(--color-primary-element, #0069c2);
  outline-offset: -1px;
}

textarea.rb-eingabe {
  resize: vertical;
  font-family: inherit;
}

.rb-raster {
  display: grid;
  grid-template-columns: 1fr;
  gap: 16px;
}

.rb-raster label {
  display: flex;
  flex-direction: column;
  gap: 6px;
}

.rb-fortschritt-zeile {
  display: flex;
  align-items: center;
  gap: 10px;
}

.rb-schieber {
  flex: 1;
}

.rb-aktionen {
  margin-top: 20px;
  padding-top: 16px;
  border-top: 1px solid var(--color-border, #eee);
}

.rb-rechnung-feld .rb-label {
  color: var(--color-main-text, #222);
}

.rb-kommentare textarea.rb-eingabe {
  width: 100%;
  margin-bottom: 8px;
  resize: vertical;
}

.rb-knopf-primaer {
  display: inline-flex;
  align-items: center;
  gap: 8px;
  background: var(--color-primary-element, #0069c2);
  color: var(--color-primary-element-text, #fff);
  border: none;
  padding: 9px 22px;
  border-radius: var(--border-radius, 6px);
  font: inherit;
  font-weight: 600;
  cursor: pointer;
}

.rb-knopf-primaer:hover:not(:disabled) {
  filter: brightness(1.08);
}

.rb-knopf-primaer:disabled {
  opacity: 0.7;
  cursor: default;
}

.rb-meldung {
  padding: 10px 14px;
  border-radius: var(--border-radius, 6px);
  margin-bottom: 16px;
  font-size: 0.9em;
}

.rb-meldung-fehler {
  background: #fdecea;
  color: #c62828;
}

.rb-meldung-erfolg {
  background: #eaf6ea;
  color: #2e7d32;
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
  margin-right: 4px;
}

.rb-spinner-hell {
  border-color: rgba(255, 255, 255, 0.4);
  border-top-color: #fff;
}

/* Unterhalb dieser Breite reicht der Platz nicht mehr für zwei Spalten
   nebeneinander — die Ablage rutscht dann unter das Formular. */
@media (max-width: 640px) {
  .rb-seite {
    padding: 16px;
  }
  .rb-karte {
    padding: 16px;
    border-radius: var(--border-radius, 6px);
  }
  .rb-raster {
    grid-template-columns: 1fr;
  }
  .rb-layout {
    grid-template-columns: 1fr;
  }
}

@keyframes rb-drehen {
  to { transform: rotate(360deg); }
}
</style>
