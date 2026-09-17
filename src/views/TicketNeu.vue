<script setup>
import { ref, reactive, onMounted, watch } from 'vue'
import { api } from '../lib/api.js'

const formulardaten = ref({ tracker: [], prioritaet: [], projekte: [] })
const mitglieder = ref([])
const fehler = ref(null)
const ladend = ref(true)
const speichernLaeuft = ref(false)

const formular = reactive({
  project_id: null,
  subject: '',
  description: '',
  tracker_id: null,
  priority_id: null,
  assigned_to_id: null,
  start_date: '',
  due_date: '',
})

onMounted(async () => {
  try {
    formulardaten.value = await api.formulardaten()
    // Sinnvolle Vorbelegung statt leerer Auswahl — bei einem einzelnen
    // Projekt/Tracker erspart das einen unnötigen Klick.
    if (formulardaten.value.projekte.length) formular.project_id = formulardaten.value.projekte[0].id
    if (formulardaten.value.tracker.length) formular.tracker_id = formulardaten.value.tracker[0].id
    if (formulardaten.value.prioritaet.length) {
      const normal = formulardaten.value.prioritaet.find((p) => p.is_default) || formulardaten.value.prioritaet[0]
      formular.priority_id = normal.id
    }
  } catch (e) {
    fehler.value = e.message
  } finally {
    ladend.value = false
  }
})

// Die Bearbeiter-Auswahl hängt vom Projekt ab — wechselt das Projekt, ist
// die bisherige Auswahl womöglich gar nicht mehr Mitglied dort.
watch(() => formular.project_id, async (id) => {
  formular.assigned_to_id = null
  if (!id) { mitglieder.value = []; return }
  try {
    const antwort = await api.mitglieder(id)
    mitglieder.value = antwort.mitglieder
  } catch {
    mitglieder.value = []
  }
}, { immediate: true })

async function speichern() {
  if (!formular.project_id) {
    fehler.value = 'Bitte ein Projekt auswählen.'
    return
  }
  if (!formular.subject.trim()) {
    fehler.value = 'Bitte einen Betreff eintragen.'
    return
  }
  speichernLaeuft.value = true
  fehler.value = null
  try {
    const antwort = await api.ticketErstellen({ ...formular })
    // Direkt zur Detailseite des neu angelegten Tickets — dort entsteht
    // beim ersten Aufruf automatisch der Ablage-Ordner.
    window.location.hash = `#/tickets/${antwort.ticket.id}`
  } catch (e) {
    fehler.value = e.message
  } finally {
    speichernLaeuft.value = false
  }
}

function zurueck() {
  window.location.hash = ''
}
</script>

<template>
  <div class="rb-seite">
    <a href="#" class="rb-zurueck" @click.prevent="zurueck">← Zurück zur Liste</a>

    <header class="rb-kopf">
      <h1>Neues Ticket</h1>
    </header>

    <div v-if="fehler" class="rb-meldung rb-meldung-fehler">{{ fehler }}</div>

    <section v-if="!ladend" class="rb-karte">
      <label class="rb-feld-breit">
        <span class="rb-label">Projekt</span>
        <select v-model.number="formular.project_id" class="rb-eingabe">
          <option v-for="p in formulardaten.projekte" :key="p.id" :value="p.id">{{ p.name }}</option>
        </select>
      </label>

      <label class="rb-feld-breit">
        <span class="rb-label">Betreff</span>
        <input v-model="formular.subject" type="text" class="rb-eingabe" placeholder="Kurze Beschreibung des Anliegens">
      </label>

      <label class="rb-feld-breit">
        <span class="rb-label">Beschreibung</span>
        <textarea v-model="formular.description" rows="5" class="rb-eingabe"></textarea>
      </label>

      <div class="rb-raster">
        <label>
          <span class="rb-label">Tracker</span>
          <select v-model.number="formular.tracker_id" class="rb-eingabe">
            <option v-for="t in formulardaten.tracker" :key="t.id" :value="t.id">{{ t.name }}</option>
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
          <span class="rb-label">Start</span>
          <input v-model="formular.start_date" type="date" class="rb-eingabe">
        </label>
        <label>
          <span class="rb-label">Fällig am</span>
          <input v-model="formular.due_date" type="date" class="rb-eingabe">
        </label>
      </div>

      <div class="rb-aktionen">
        <button class="rb-knopf-primaer" :disabled="speichernLaeuft" @click="speichern">
          <span v-if="speichernLaeuft" class="rb-spinner rb-spinner-hell"></span>
          {{ speichernLaeuft ? 'Wird angelegt …' : 'Ticket anlegen' }}
        </button>
      </div>
    </section>
  </div>
</template>

<style scoped>
.rb-seite {
  padding: 32px 40px;
  max-width: 960px;
  margin: 0 auto;
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
.rb-kopf h1 {
  margin: 0;
  font-size: 1.4em;
  color: #fff;
  text-shadow: 0 1px 3px rgba(0, 0, 0, 0.4);
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

.rb-aktionen {
  margin-top: 20px;
  padding-top: 16px;
  border-top: 1px solid var(--color-border, #eee);
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

.rb-spinner {
  display: inline-block;
  width: 14px;
  height: 14px;
  border: 2px solid var(--color-border, #d8d8db);
  border-top-color: var(--color-primary-element, #0069c2);
  border-radius: 50%;
  animation: rb-drehen 0.7s linear infinite;
  vertical-align: middle;
}
.rb-spinner-hell {
  border-color: rgba(255, 255, 255, 0.4);
  border-top-color: #fff;
}
@keyframes rb-drehen {
  to { transform: rotate(360deg); }
}

@media (max-width: 640px) {
  .rb-seite { padding: 16px; }
  .rb-karte { padding: 16px; }
  .rb-raster { grid-template-columns: 1fr; }
}
</style>
