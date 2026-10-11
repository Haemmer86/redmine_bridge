<script setup>
import { ref, computed } from 'vue'
import { api } from '../lib/api.js'
import { datumUhrzeit as kommentarDatumFormatieren } from '../lib/format.js'

// Kommentare eines Tickets (Redmine-Journaleinträge) und das Eingabefeld für
// einen neuen Kommentar. Nach dem Senden liefert der Server das frische
// Ticket (`aktualisiert`); Fehler gehen als `fehler` an die Detailseite, die
// sie oben als Meldung zeigt.
const props = defineProps({
  id: { type: Number, required: true },
  journals: { type: Array, default: () => [] },
})
const emit = defineEmits(['aktualisiert', 'fehler'])

// ─── Kommentare (Redmine-Journaleinträge) ───────────────────────────────
//
// Redmine liefert bei "include=journals" auch reine Feldänderungen als
// Journaleinträge mit (z. B. "Status geändert von X zu Y") - die haben
// aber einen leeren "notes"-Text. Nur Einträge mit tatsächlichem
// Kommentartext anzeigen, sonst wäre die Liste voller technischer
// Änderungsprotokolle statt echter Kommentare.
const kommentare = computed(() =>
  (props.journals || []).filter((j) => j.notes && j.notes.trim() !== ''),
)

const neuerKommentar = ref('')
const kommentarSendenLaeuft = ref(false)

async function kommentarSenden() {
  if (!neuerKommentar.value.trim()) return
  kommentarSendenLaeuft.value = true
  emit('fehler', null)
  try {
    // Nur "notes" mitschicken - Redmine ändert dann ausschließlich den
    // Kommentarverlauf, alle anderen Ticketfelder bleiben unangetastet.
    const antwort = await api.ticketAktualisieren(props.id, { notes: neuerKommentar.value })
    emit('aktualisiert', antwort.ticket)
    neuerKommentar.value = ''
  } catch (e) {
    emit('fehler', 'Kommentar senden fehlgeschlagen: ' + e.message)
  } finally {
    kommentarSendenLaeuft.value = false
  }
}
</script>

<template>
  <div class="rb-kommentare">
    <h3 class="rb-unterueberschrift">Kommentare</h3>
    <ul v-if="kommentare.length" class="rb-kommentarliste">
      <li v-for="j in kommentare" :key="j.id" class="rb-kommentar">
        <div class="rb-kommentar-kopf">
          <strong>{{ j.user?.name || 'Unbekannt' }}</strong>
          <span class="rb-gedaempft">{{ kommentarDatumFormatieren(j.created_on) }}</span>
        </div>
        <p class="rb-kommentar-text">{{ j.notes }}</p>
      </li>
    </ul>
    <p v-else class="rb-gedaempft">Noch keine Kommentare.</p>

    <textarea
      v-model="neuerKommentar"
      rows="3"
      class="rb-eingabe"
      placeholder="Kommentar hinzufügen — wird direkt an Redmine gesendet …"
    ></textarea>
    <button
      type="button"
      class="rb-knopf-sekundaer"
      :disabled="kommentarSendenLaeuft || !neuerKommentar.trim()"
      @click="kommentarSenden"
    >
      {{ kommentarSendenLaeuft ? 'Wird gesendet …' : 'Kommentar senden' }}
    </button>
  </div>
</template>

<style scoped>
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

.rb-kommentare {
  margin-top: 24px;
  padding-top: 16px;
  border-top: 1px solid var(--color-border, #eee);
}

.rb-kommentarliste {
  list-style: none;
  margin: 0 0 16px;
  padding: 0;
  max-height: 400px;
  overflow-y: auto;
}

.rb-kommentar {
  padding: 10px 0;
  border-bottom: 1px solid var(--color-border, #eee);
}

.rb-kommentar:last-child {
  border-bottom: none;
}

.rb-kommentar-kopf {
  display: flex;
  justify-content: space-between;
  gap: 12px;
  font-size: 0.85em;
  margin-bottom: 4px;
}

.rb-kommentar-text {
  white-space: pre-wrap;
  margin: 0;
  line-height: 1.5;
}

.rb-kommentare textarea.rb-eingabe {
  width: 100%;
  margin-bottom: 8px;
  resize: vertical;
}

.rb-gedaempft {
  color: var(--color-text-maxcontrast, #767676);
}

.rb-unterueberschrift {
  font-size: 0.85em;
  text-transform: uppercase;
  letter-spacing: 0.03em;
  color: var(--color-text-maxcontrast, #767676);
  margin: 16px 0 4px;
}

.rb-unterueberschrift:first-of-type {
  margin-top: 0;
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
</style>
