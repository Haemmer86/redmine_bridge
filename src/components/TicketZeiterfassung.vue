<script setup>
import { ref, computed } from 'vue'
import { api } from '../lib/api.js'

// Ist-Stunden eines Tickets (Redmines eigene Zeiterfassung): Anzeige der
// bisher gebuchten Zeit und ein kleines Formular, um Zeit zu erfassen. Nach
// dem Buchen liefert der Server das frische Ticket; es geht per
// `aktualisiert` an die Detailseite.
const props = defineProps({
  id: { type: Number, required: true },
  spentHours: { type: Number, default: null },
})
const emit = defineEmits(['aktualisiert'])

const istStundenAnzeige = computed(() => {
  const wert = props.spentHours
  return wert != null ? `${wert} h` : '0 h'
})

const zeiterfassungFormularOffen = ref(false)
const zeAktivitaeten = ref([])
const zeStunden = ref(null)
const zeAktivitaetId = ref(0)
const zeDatum = ref(new Date().toISOString().slice(0, 10))
const zeKommentar = ref('')
const zeSpeichernLaeuft = ref(false)
const zeFehler = ref(null)

async function zeiterfassungOeffnen() {
  zeiterfassungFormularOffen.value = true
  zeFehler.value = null
  // Aktivitäten nur beim ersten Öffnen laden, nicht bei jedem Klick erneut.
  if (zeAktivitaeten.value.length) return
  try {
    const antwort = await api.zeiterfassungAktivitaeten()
    zeAktivitaeten.value = antwort.aktivitaeten || []
  } catch (e) {
    // Liste bleibt einfach leer - Redmine akzeptiert die Zeiterfassung
    // notfalls auch ohne mitgeschickte Aktivität, je nach Konfiguration.
    zeAktivitaeten.value = []
  }
}

async function zeitErfassen() {
  if (!zeStunden.value || zeStunden.value <= 0) {
    zeFehler.value = 'Bitte eine Stundenzahl größer 0 eintragen.'
    return
  }
  zeSpeichernLaeuft.value = true
  zeFehler.value = null
  try {
    const antwort = await api.zeiterfassungAnlegen(
      props.id,
      zeStunden.value,
      zeAktivitaetId.value,
      zeKommentar.value,
      zeDatum.value,
    )
    emit('aktualisiert', antwort.ticket)
    zeStunden.value = null
    zeKommentar.value = ''
    zeiterfassungFormularOffen.value = false
  } catch (e) {
    zeFehler.value = e.message
  } finally {
    zeSpeichernLaeuft.value = false
  }
}
</script>

<template>
  <div class="rb-zeiterfassung">
    <div class="rb-zeiterfassung-kopf">
      <span class="rb-label">Ist-Stunden (Redmine-Zeiterfassung)</span>
      <strong>{{ istStundenAnzeige }}</strong>
    </div>

    <button v-if="!zeiterfassungFormularOffen" type="button" class="rb-hochladen" @click="zeiterfassungOeffnen">
      + Zeit erfassen
    </button>
    <div v-else class="rb-link-formular">
      <input v-model.number="zeStunden" type="number" min="0" step="0.25" class="rb-eingabe" placeholder="Stunden, z. B. 1.5">
      <select v-model.number="zeAktivitaetId" class="rb-eingabe">
        <option :value="0">Aktivität wählen …</option>
        <option v-for="a in zeAktivitaeten" :key="a.id" :value="a.id">{{ a.name }}</option>
      </select>
      <input v-model="zeDatum" type="date" class="rb-eingabe">
      <textarea v-model="zeKommentar" rows="2" class="rb-eingabe" placeholder="Kommentar (optional)"></textarea>
      <p v-if="zeFehler" class="rb-meldung rb-meldung-fehler">{{ zeFehler }}</p>
      <div class="rb-link-formular-aktionen">
        <button type="button" class="rb-knopf-sekundaer" @click="zeiterfassungFormularOffen = false">Abbrechen</button>
        <button type="button" class="rb-knopf-primaer" :disabled="zeSpeichernLaeuft" @click="zeitErfassen">
          {{ zeSpeichernLaeuft ? 'Speichert …' : 'Erfassen' }}
        </button>
      </div>
    </div>
  </div>
</template>

<style scoped>
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

.rb-rechnung-feld .rb-label {
  color: var(--color-main-text, #222);
}

.rb-zeiterfassung {
  margin-top: 4px;
  margin-bottom: 16px;
}

.rb-zeiterfassung-kopf {
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin-bottom: 8px;
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

.rb-link-formular {
  display: flex;
  flex-direction: column;
  gap: 8px;
  padding: 12px;
  border: 1px solid var(--color-border, #e0e0e3);
  border-radius: var(--border-radius, 6px);
}

.rb-link-formular-aktionen {
  display: flex;
  gap: 8px;
  justify-content: flex-end;
}

.rb-knopf-sekundaer {
  padding: 6px 14px;
  border-radius: var(--border-radius, 6px);
  border: 1px solid var(--color-border, #d8d8db);
  background: var(--color-main-background, #fff);
  color: var(--color-main-text, #222);
  cursor: pointer;
  font-size: 0.9em;
}

.rb-hochladen {
  display: block;
  text-align: center;
  padding: 8px 18px;
  border: 1px dashed var(--color-border-dark, #b8b8bb);
  border-radius: var(--border-radius, 6px);
  cursor: pointer;
  font-size: 0.9em;
  color: var(--color-main-text, #222);
}

.rb-hochladen:hover {
  border-color: var(--color-primary-element, #0069c2);
}
</style>
