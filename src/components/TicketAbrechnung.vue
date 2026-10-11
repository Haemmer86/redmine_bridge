<script setup>
import { ref, computed } from 'vue'
import { api } from '../lib/api.js'
import { datumUhrzeit as kommentarDatumFormatieren } from '../lib/format.js'

// Rechnungsangaben eines Tickets: "Rechnung erforderlich/gestellt" sowie
// Abrechnungsart (Pauschale, Stunden, Pauschale + Zeitzuschlag) mit Beträgen
// und Bemerkung. Liegt in einer eigenen Tabelle dieser App (kein
// Redmine-Feld, siehe RechnungsStatusService). Jede Änderung wird sofort
// gespeichert; die Antwort des Servers geht als `aktualisiert` an die
// Detailseite. Die Eingabefelder bearbeiten das übergebene
// `rechnungsstatus`-Objekt direkt (es gehört der Detailseite).
const props = defineProps({
  id: { type: Number, required: true },
  rechnungsstatus: { type: Object, default: null },
  spentHours: { type: Number, default: null },
})
const emit = defineEmits(['aktualisiert'])

// ─── Rechnungsstatus ─────────────────────────────────────────────────────
//
// Eigene kleine Tabelle dieser App (kein Redmine-Feld), siehe
// RechnungsStatusService. Beide Häkchen schreiben sofort, kein separater
// "Speichern"-Knopf nötig - "Rechnung erforderlich" ist jederzeit im
// Ticket änderbar, nicht nur bei der Neuanlage.
const rechnungLaeuft = ref(false)
const rechnungFehler = ref(null)

async function rechnungspflichtigUmschalten() {
  rechnungLaeuft.value = true
  rechnungFehler.value = null
  try {
    const neu = !props.rechnungsstatus?.rechnungspflichtig
    const antwort = await api.rechnungsstatusSetzen(props.id, { rechnungspflichtig: neu })
    emit('aktualisiert', antwort.rechnungsstatus)
  } catch (e) {
    rechnungFehler.value = e.message
  } finally {
    rechnungLaeuft.value = false
  }
}

async function rechnungGestelltUmschalten() {
  rechnungLaeuft.value = true
  rechnungFehler.value = null
  try {
    const neu = !props.rechnungsstatus?.rechnung_gestellt
    const antwort = await api.rechnungsstatusSetzen(props.id, { rechnungGestellt: neu })
    emit('aktualisiert', antwort.rechnungsstatus)
  } catch (e) {
    rechnungFehler.value = e.message
  } finally {
    rechnungLaeuft.value = false
  }
}

function rechnungGestelltAmAnzeige() {
  const iso = props.rechnungsstatus?.rechnung_gestellt_am
  return iso ? kommentarDatumFormatieren(iso) : ''
}

// Abrechnungsart, Betrag/Stundensatz und Bemerkung — anders als die beiden
// Häkchen oben ein zusammengehöriger Block, der als Ganzes speichert
// (Moduswechsel sofort per Klick, Zahlen-/Textfelder beim Verlassen des
// Felds statt bei jedem Tastendruck). Fließt später als Vorschlag in die
// Sammelrechnung ein (siehe TicketListe.vue).
const rechnungsdatenLaeuft = ref(false)
const rechnungsdatenFehler = ref(null)

async function rechnungsdatenSpeichern() {
  if (!props.rechnungsstatus) return
  rechnungsdatenLaeuft.value = true
  rechnungsdatenFehler.value = null
  const rs = props.rechnungsstatus
  try {
    const antwort = await api.rechnungsdatenSetzen(props.id, {
      abrechnungsart: rs.abrechnungsart || null,
      pauschalbetrag: (rs.abrechnungsart === 'pauschale' || rs.abrechnungsart === 'pauschale_zuschlag') && rs.pauschalbetrag !== null && rs.pauschalbetrag !== '' ? Number(rs.pauschalbetrag) : null,
      stundensatz: rs.abrechnungsart === 'stunden' && rs.stundensatz !== null && rs.stundensatz !== '' ? Number(rs.stundensatz) : null,
      bemerkung: (rs.bemerkung || '').trim() || null,
      inklusivMinuten: rs.abrechnungsart === 'pauschale_zuschlag' ? Number(rs.inklusiv_minuten) || 0 : null,
      taktMinuten: rs.abrechnungsart === 'pauschale_zuschlag' ? Number(rs.takt_minuten) || 0 : null,
      taktPreis: rs.abrechnungsart === 'pauschale_zuschlag' ? Number(rs.takt_preis) || 0 : null,
    })
    emit('aktualisiert', antwort.rechnungsstatus)
  } catch (e) {
    rechnungsdatenFehler.value = e.message
  } finally {
    rechnungsdatenLaeuft.value = false
  }
}

function abrechnungsartWaehlen(wert) {
  if (!props.rechnungsstatus) return
  const rs = props.rechnungsstatus
  rs.abrechnungsart = rs.abrechnungsart === wert ? '' : wert
  // Beim ersten Wechsel auf "Pauschale + Zeitzuschlag" die übliche
  // Vorbelegung eintragen (60 Min. inklusive, 15-Min.-Takt, 12,50 € je
  // Takt) — jederzeit änderbar, nur damit die Felder nicht leer starten.
  if (rs.abrechnungsart === 'pauschale_zuschlag') {
    if (!rs.inklusiv_minuten) rs.inklusiv_minuten = 60
    if (!rs.takt_minuten) rs.takt_minuten = 15
    if (!rs.takt_preis) rs.takt_preis = 12.5
  }
  rechnungsdatenSpeichern()
}

// Live-Anzeige bei "Pauschale + Zeitzuschlag": was die bisher gebuchte Zeit
// (Redmines spent_hours) gerade an Zuschlag ergibt — gleiche Rechnung wie
// beim Ticketwert auf dem Server (angefangene Takte über der Inklusivzeit).
const zuschlagInfo = computed(() => {
  const rs = props.rechnungsstatus
  if (!rs || rs.abrechnungsart !== 'pauschale_zuschlag') return null
  const ist = Math.round((Number(props.spentHours) || 0) * 60)
  const inklusiv = Number(rs.inklusiv_minuten) || 0
  const takt = Number(rs.takt_minuten) || 0
  const bloecke = takt > 0 && ist > inklusiv ? Math.ceil((ist - inklusiv) / takt) : 0
  const zuschlag = bloecke * (Number(rs.takt_preis) || 0)
  return { ist, bloecke, zuschlag, gesamt: (Number(rs.pauschalbetrag) || 0) + zuschlag }
})
</script>

<template>
  <div class="rb-rechnung">
    <label class="rb-rechnung-feld">
      <input
        type="checkbox"
        :checked="rechnungsstatus?.rechnungspflichtig"
        :disabled="rechnungLaeuft"
        @change="rechnungspflichtigUmschalten"
      >
      <span class="rb-label">Rechnung erforderlich</span>
    </label>

    <template v-if="rechnungsstatus?.rechnungspflichtig">
      <label class="rb-rechnung-feld">
        <input
          type="checkbox"
          :checked="rechnungsstatus?.rechnung_gestellt"
          :disabled="rechnungLaeuft"
          @change="rechnungGestelltUmschalten"
        >
        <span class="rb-label">Rechnung gestellt</span>
      </label>
      <span v-if="rechnungsstatus?.rechnung_gestellt" class="rb-gedaempft rb-rechnung-datum">
        am {{ rechnungGestelltAmAnzeige() }}<template v-if="rechnungsstatus?.rechnung_gestellt_von"> von {{ rechnungsstatus.rechnung_gestellt_von }}</template>
      </span>
    </template>

    <p v-if="rechnungFehler" class="rb-meldung rb-meldung-fehler">{{ rechnungFehler }}</p>
  </div>

  <div v-if="rechnungsstatus?.rechnungspflichtig" class="rb-rechnungsdaten">
    <div class="rb-modus-umschalter">
      <button
        type="button"
        :class="{ 'rb-modus-aktiv': rechnungsstatus?.abrechnungsart === 'pauschale' }"
        :disabled="rechnungsdatenLaeuft"
        @click="abrechnungsartWaehlen('pauschale')"
      >Pauschale</button>
      <button
        type="button"
        :class="{ 'rb-modus-aktiv': rechnungsstatus?.abrechnungsart === 'stunden' }"
        :disabled="rechnungsdatenLaeuft"
        @click="abrechnungsartWaehlen('stunden')"
      >Nach Stunden</button>
      <button
        type="button"
        :class="{ 'rb-modus-aktiv': rechnungsstatus?.abrechnungsart === 'pauschale_zuschlag' }"
        :disabled="rechnungsdatenLaeuft"
        @click="abrechnungsartWaehlen('pauschale_zuschlag')"
      >Pauschale + Zeitzuschlag</button>
    </div>

    <label v-if="rechnungsstatus?.abrechnungsart === 'pauschale' || rechnungsstatus?.abrechnungsart === 'pauschale_zuschlag'" class="rb-rechnungsdaten-feld">
      <span class="rb-label">{{ rechnungsstatus?.abrechnungsart === 'pauschale_zuschlag' ? 'Pauschale (€)' : 'Rechnungsbetrag (€)' }}</span>
      <input
        v-model.number="rechnungsstatus.pauschalbetrag"
        type="number" min="0" step="0.01"
        class="rb-eingabe rb-betrag-feld"
        :disabled="rechnungsdatenLaeuft"
        @change="rechnungsdatenSpeichern"
      >
    </label>
    <label v-if="rechnungsstatus?.abrechnungsart === 'stunden'" class="rb-rechnungsdaten-feld">
      <span class="rb-label">Stundensatz (€/h)</span>
      <input
        v-model.number="rechnungsstatus.stundensatz"
        type="number" min="0" step="0.01"
        class="rb-eingabe rb-betrag-feld"
        :disabled="rechnungsdatenLaeuft"
        @change="rechnungsdatenSpeichern"
      >
    </label>

    <template v-if="rechnungsstatus?.abrechnungsart === 'pauschale_zuschlag'">
      <label class="rb-rechnungsdaten-feld">
        <span class="rb-label">Inklusivzeit (Min.)</span>
        <input
          v-model.number="rechnungsstatus.inklusiv_minuten"
          type="number" min="0" step="1"
          class="rb-eingabe rb-betrag-feld"
          :disabled="rechnungsdatenLaeuft"
          @change="rechnungsdatenSpeichern"
        >
      </label>
      <label class="rb-rechnungsdaten-feld">
        <span class="rb-label">Takt danach (Min.)</span>
        <input
          v-model.number="rechnungsstatus.takt_minuten"
          type="number" min="1" step="1"
          class="rb-eingabe rb-betrag-feld"
          :disabled="rechnungsdatenLaeuft"
          @change="rechnungsdatenSpeichern"
        >
      </label>
      <label class="rb-rechnungsdaten-feld">
        <span class="rb-label">Preis je Takt (€)</span>
        <input
          v-model.number="rechnungsstatus.takt_preis"
          type="number" min="0" step="0.01"
          class="rb-eingabe rb-betrag-feld"
          :disabled="rechnungsdatenLaeuft"
          @change="rechnungsdatenSpeichern"
        >
      </label>
      <p v-if="zuschlagInfo" class="rb-gedaempft rb-zuschlag-info">
        Bisher gebucht: {{ zuschlagInfo.ist }} Min. →
        {{ zuschlagInfo.bloecke }} Zuschlags-Takt{{ zuschlagInfo.bloecke === 1 ? '' : 'e' }}
        = {{ zuschlagInfo.zuschlag.toFixed(2) }} € · Gesamt aktuell {{ zuschlagInfo.gesamt.toFixed(2) }} €
      </p>
    </template>

    <label class="rb-rechnungsdaten-feld rb-rechnungsdaten-bemerkung">
      <span class="rb-label">Bemerkung</span>
      <input
        v-model="rechnungsstatus.bemerkung"
        type="text"
        class="rb-eingabe"
        placeholder="z. B. Anfahrtspauschale — fließt in die Rechnungsposition ein"
        :disabled="rechnungsdatenLaeuft"
        @change="rechnungsdatenSpeichern"
      >
    </label>

    <p v-if="rechnungsdatenFehler" class="rb-meldung rb-meldung-fehler">{{ rechnungsdatenFehler }}</p>
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

.rb-rechnung {
  display: flex;
  align-items: center;
  flex-wrap: wrap;
  gap: 16px;
  margin: 16px 0;
  padding: 12px 16px;
  background: var(--color-background-hover, #f4f4f6);
  border-radius: var(--border-radius, 6px);
}

.rb-rechnung-feld {
  display: flex;
  align-items: center;
  gap: 8px;
  cursor: pointer;
}

.rb-rechnung-feld .rb-label {
  color: var(--color-main-text, #222);
}

.rb-rechnung-datum {
  font-size: 0.85em;
}

.rb-rechnungsdaten {
  display: flex;
  flex-wrap: wrap;
  align-items: flex-end;
  gap: 16px;
  margin: -8px 0 16px;
  padding: 12px 16px;
  background: var(--color-background-hover, #f4f4f6);
  border-radius: var(--border-radius, 6px);
}

.rb-rechnungsdaten-feld {
  display: flex;
  flex-direction: column;
  gap: 6px;
}

.rb-rechnungsdaten-bemerkung {
  flex: 1 1 240px;
  min-width: 200px;
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

.rb-betrag-feld {
  width: 110px;
  box-sizing: border-box;
}

.rb-kommentare textarea.rb-eingabe {
  width: 100%;
  margin-bottom: 8px;
  resize: vertical;
}

.rb-meldung {
  padding: 10px 14px;
  border-radius: var(--border-radius, 6px);
  margin-bottom: 16px;
  font-size: 0.9em;
}

.rb-zuschlag-info {
  margin: 4px 0 0;
  font-size: 13px;
}

.rb-meldung-fehler {
  background: #fdecea;
  color: #c62828;
}

.rb-gedaempft {
  color: var(--color-text-maxcontrast, #767676);
}
</style>
