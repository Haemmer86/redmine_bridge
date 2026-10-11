<script setup>
import { ref, onMounted } from 'vue'
import { api } from '../lib/api.js'

// Ganzes Projekt abschließen (aus der Ticketliste, siehe TicketListe.vue):
// Setzt zuerst alle noch offenen Tickets des Projekts auf "Erledigt"
// (derselbe Ziel-Status wie die 100-%-Automatik der Liste, kommt als
// `erledigtStatus` herein) und schließt danach das Redmine-Projekt selbst ab.
// Vorher eine Vorschau: wie viele Tickets betroffen sind und ob darunter noch
// nicht abgerechnete Tickets sind — das ist nur eine Warnung, kein Blocker.
//
// Die Komponente wird nur bei Bedarf eingehängt (v-if), jedes Öffnen startet
// also mit frischem Zustand. Nach dem Abschließen meldet sie `aktualisieren`,
// damit die Liste neu lädt.
const props = defineProps({
  projekt: { type: Object, required: true }, // { id, name }
  erledigtStatus: { type: Object, default: null },
})
const emit = defineEmits(['schliessen', 'aktualisieren'])

const projektAbschliessenVorschauLaedt = ref(false)
const projektAbschliessenVorschauFehler = ref(null)
const projektAbschliessenVorschau = ref(null) // { offenAnzahl, unfakturierteTickets }
const projektAbschliessenLaeuft = ref(false)
const projektAbschliessenFehler = ref(null)
const projektAbschliessenErgebnis = ref(null)

async function vorschauLaden() {
  projektAbschliessenVorschauLaedt.value = true
  try {
    projektAbschliessenVorschau.value = await api.projektAbschliessenVorschau(props.projekt.id)
  } catch (e) {
    projektAbschliessenVorschauFehler.value = e.message
  } finally {
    projektAbschliessenVorschauLaedt.value = false
  }
}

onMounted(vorschauLaden)

function projektAbschliessenSchliessen() {
  emit('schliessen')
}

async function projektAbschliessenBestaetigen() {
  if (projektAbschliessenLaeuft.value || !props.projekt) return
  // Ohne ermittelbaren "Erledigt"-Status (siehe `erledigtStatus` oben)
  // lieber gar nicht erst versuchen, Tickets auf einen falschen/leeren
  // Status zu setzen.
  if (!props.erledigtStatus) {
    projektAbschliessenFehler.value = 'Kein Ticket-Status "Erledigt" gefunden — bitte in Redmine prüfen.'
    return
  }
  projektAbschliessenLaeuft.value = true
  projektAbschliessenFehler.value = null
  try {
    projektAbschliessenErgebnis.value = await api.projektAbschliessen(
      props.projekt.id,
      props.erledigtStatus.id,
    )
    // Eine Bulk-Aktion über beliebig viele Tickets lässt sich nicht sauber
    // optimistisch im Frontend nachbilden — stattdessen die aktuelle Ansicht
    // einfach neu laden.
    emit('aktualisieren')
  } catch (e) {
    projektAbschliessenFehler.value = e.message
  } finally {
    projektAbschliessenLaeuft.value = false
  }
}
</script>

<template>
  <div
    class="rb-modal-overlay"
    @click.self="projektAbschliessenSchliessen"
    @keydown.esc="projektAbschliessenSchliessen"
  >
    <div class="rb-modal-fenster">
      <header class="rb-modal-kopf">
        <span class="rb-modal-titel">
          Projekt abschließen<template v-if="projekt"> — {{ projekt.name }}</template>
        </span>
        <button type="button" class="rb-modal-schliessen" title="Schließen" @click="projektAbschliessenSchliessen">×</button>
      </header>

      <div class="rb-modal-inhalt">
        <template v-if="projektAbschliessenErgebnis">
          <p v-if="projektAbschliessenErgebnis.projektGeschlossen">
            <strong style="color: #2e7d32;">✔ Projekt abgeschlossen</strong>
          </p>
          <p v-else class="rb-zustand-fehler">
            Die Tickets wurden erledigt, das Projekt selbst konnte aber nicht auf "Abgeschlossen" gesetzt
            werden: {{ projektAbschliessenErgebnis.projektFehler }}
          </p>
          <p class="rb-gedaempft">
            {{ projektAbschliessenErgebnis.ticketsAktualisiert.length }}
            Ticket{{ projektAbschliessenErgebnis.ticketsAktualisiert.length === 1 ? '' : 's' }} auf "Erledigt"
            gesetzt.
          </p>
          <p v-if="projektAbschliessenErgebnis.ticketsFehlgeschlagen.length > 0" class="rb-zustand-fehler">
            {{ projektAbschliessenErgebnis.ticketsFehlgeschlagen.length }} Ticket(s) konnten nicht aktualisiert
            werden:
            <span v-for="f in projektAbschliessenErgebnis.ticketsFehlgeschlagen" :key="f.id">#{{ f.id }} ({{ f.fehler }}) </span>
          </p>
          <div class="rb-modal-aktionen">
            <button type="button" class="rb-knopf-sekundaer" @click="projektAbschliessenSchliessen">Fertig</button>
          </div>
        </template>

        <template v-else>
          <div v-if="projektAbschliessenVorschauLaedt" class="rb-zustand">
            <span class="rb-spinner"></span> Wird geprüft …
          </div>
          <p v-else-if="projektAbschliessenVorschauFehler" class="rb-zustand-fehler">{{ projektAbschliessenVorschauFehler }}</p>
          <template v-else-if="projektAbschliessenVorschau">
            <p>
              Setzt alle noch offenen Tickets dieses Projekts auf "Erledigt" und schließt anschließend das
              Projekt selbst in Redmine ab.
            </p>
            <p v-if="projektAbschliessenVorschau.offenAnzahl > 0">
              <strong>{{ projektAbschliessenVorschau.offenAnzahl }}</strong>
              offene{{ projektAbschliessenVorschau.offenAnzahl === 1 ? 's' : '' }} Ticket{{ projektAbschliessenVorschau.offenAnzahl === 1 ? '' : 's' }}
              werden dabei auf "Erledigt" gesetzt.
            </p>
            <p v-else class="rb-gedaempft">Es sind aktuell keine offenen Tickets mehr in diesem Projekt.</p>

            <div v-if="projektAbschliessenVorschau.unfakturierteTickets.length > 0" class="rb-warnung">
              ⚠ {{ projektAbschliessenVorschau.unfakturierteTickets.length }} Ticket(s) sind als "Rechnung
              erforderlich" markiert, aber noch nicht abgerechnet:
              <ul>
                <li v-for="t in projektAbschliessenVorschau.unfakturierteTickets" :key="t.id">
                  #{{ t.id }} {{ t.subject }}
                </li>
              </ul>
              Du kannst trotzdem fortfahren — der Filter "Nur nicht abgerechnete" findet diese Tickets danach
              weiterhin.
            </div>

            <p v-if="projektAbschliessenFehler" class="rb-zustand-fehler">{{ projektAbschliessenFehler }}</p>

            <div class="rb-modal-aktionen">
              <button type="button" class="rb-knopf-sekundaer" @click="projektAbschliessenSchliessen">Abbrechen</button>
              <button
                type="button"
                class="rb-knopf-primaer"
                :disabled="projektAbschliessenLaeuft"
                @click="projektAbschliessenBestaetigen"
              >
                {{ projektAbschliessenLaeuft ? 'Wird abgeschlossen …' : 'Projekt endgültig abschließen' }}
              </button>
            </div>
          </template>
        </template>
      </div>
    </div>
  </div>
</template>

<style scoped>
.rb-gedaempft {
  color: var(--color-text-maxcontrast, #767676);
}

.rb-warnung {
  margin: 12px 0;
  padding: 10px 14px;
  border-radius: var(--border-radius, 6px);
  border: 1px solid #e5a50a;
  background: rgba(229, 165, 10, 0.12);
  color: var(--color-main-text, #222);
  font-size: 0.9em;
}

.rb-warnung ul {
  margin: 6px 0;
  padding-left: 20px;
  max-height: 160px;
  overflow-y: auto;
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

/* ─── Sammelrechnung-Dialog, dasselbe Overlay-Muster wie die
   Datei-Großvorschau in TicketDetail.vue ─────────────────────────────── */
.rb-modal-overlay {
  position: fixed;
  inset: 0;
  background: rgba(0, 0, 0, 0.6);
  display: flex;
  align-items: center;
  justify-content: center;
  z-index: 10000;
  padding: 24px;
}

.rb-modal-fenster {
  background: var(--color-main-background, #fff);
  border-radius: var(--border-radius-large, 10px);
  width: 640px;
  max-width: 100%;
  max-height: 90vh;
  display: flex;
  flex-direction: column;
  overflow: hidden;
}

.rb-modal-kopf {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 16px;
  padding: 12px 16px;
  border-bottom: 1px solid var(--color-border, #e0e0e3);
}

.rb-modal-titel {
  font-weight: 600;
}

.rb-modal-schliessen {
  background: none;
  border: none;
  font-size: 1.6em;
  line-height: 1;
  cursor: pointer;
  color: var(--color-text-maxcontrast, #767676);
  padding: 0 4px;
}

.rb-modal-schliessen:hover {
  color: var(--color-main-text, #222);
}

.rb-modal-inhalt {
  padding: 16px;
  overflow-y: auto;
}

.rb-modal-aktionen {
  display: flex;
  justify-content: flex-end;
  gap: 10px;
  margin-top: 8px;
}

@keyframes rb-drehen {
  to { transform: rotate(360deg); }
}
</style>
