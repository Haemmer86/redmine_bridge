<script setup>
import { ref, computed, watch, onMounted } from 'vue'
import { api } from '../lib/api.js'

// Sammelrechnung aus den ausgewählten Tickets der Ticketliste (siehe
// TicketListe.vue): Kunde und Artikel kommen aus Odoo, die Positionen werden
// hier vorbereitet und als RechnungsENTWURF an Odoo geschickt.
//
// Die Komponente wird von der Liste nur bei Bedarf eingehängt (v-if) — ein
// frisches Öffnen ist deshalb immer ein frischer Zustand. Nach erfolgreichem
// Anlegen meldet sie `abgerechnet` mit den Ticketnummern, die Liste markiert
// die Tickets daraufhin lokal als abgerechnet.
const props = defineProps({
  tickets: { type: Array, required: true },
})
const emit = defineEmits(['schliessen', 'abgerechnet'])

const odooKundeSuche = ref('')
const odooKundenListe = ref([])
const odooKundeAusgewaehlt = ref(null)
const kundenSucheLaeuft = ref(false)
const kundenSucheFehler = ref(null)
const positionen = ref([])
const sammelrechnungLaeuft = ref(false)
const sammelrechnungFehler = ref(null)
const sammelrechnungErgebnis = ref(null)
let kundenSucheTimer = null

function sammelrechnungSchliessen() {
  emit('schliessen')
}

// ─── Odoo-Sammelrechnung ────────────────────────────────────────────────
//
// Legt aus mehreren ausgewählten Tickets einen RechnungsENTWURF in Odoo an
// (eine Freitext-Position je Ticket, Betrag wird hier manuell eingetragen —
// es gibt keine automatische Ableitung aus einem Stundensatz). Odoo
// verbucht/versendet dabei nichts von selbst, der Entwurf bleibt zur
// Kontrolle in Odoo liegen.

// Positionsbeschreibung aus der Redmine-Ticketbeschreibung ableiten, nicht
// aus dem Titel — der Titel bleibt oft knapp/generisch, während die
// Beschreibung meist den eigentlichen Leistungsinhalt trägt. Zeilenumbrüche
// aus Redmine bleiben dabei 1:1 erhalten (Odoo-Rechnungspositionen können
// mehrzeiligen Text darstellen) — nur \r\n wird zu \n vereinheitlicht, ohne
// die Umbrüche selbst zu verändern. Keine Ticketnummer/-präfix mehr davor,
// nur der reine Beschreibungstext. Fehlt die Beschreibung ganz, bleibt der
// Titel als Rückfall, damit die Position nie leer/unbrauchbar wird.
function positionsBeschreibung(t) {
  const roh = (t.description || '').replace(/\r\n/g, '\n').trim()
  if (!roh) return t.subject
  return roh.length > 500 ? roh.slice(0, 497) + '…' : roh
}

function positionenVorbereiten() {
  positionen.value = props.tickets.map((t) => {
    const rs = t.rechnungsstatus || {}
    // Im Ticket hinterlegte Abrechnungsart/Betrag/Stundensatz als
    // Vorschlag übernehmen, statt immer bei "Pauschale" ohne Betrag zu
    // starten — fehlt eine Vorgabe, bleibt es beim bisherigen Verhalten.
    const modus = ['stunden', 'pauschale_zuschlag'].includes(rs.abrechnungsart) ? rs.abrechnungsart : 'pauschale'
    let beschreibung = positionsBeschreibung(t)
    if (rs.bemerkung) beschreibung += ` — ${rs.bemerkung}`
    return {
      ticketId: t.id,
      beschreibung,
      // "pauschale" = fester Gesamtbetrag der Position, "stunden" = Menge ×
      // Stundensatz, mit Redmines bisher erfasster Zeit als Vorschlag.
      modus,
      produkt: null,
      betrag: modus === 'pauschale' || modus === 'pauschale_zuschlag' ? (rs.pauschalbetrag ?? null) : null,
      stunden: t.spent_hours ?? 0,
      stundensatz: modus === 'stunden' ? (rs.stundensatz ?? null) : null,
      // "pauschale_zuschlag": die Pauschale (betrag) deckt `inklusivMinuten`
      // ab, danach je angefangenem Takt `taktPreis`. `istMinuten` startet mit
      // Redmines bisher gebuchter Zeit und ist im Dialog änderbar.
      istMinuten: Math.round((Number(t.spent_hours) || 0) * 60),
      inklusivMinuten: rs.inklusiv_minuten ?? 60,
      taktMinuten: rs.takt_minuten ?? 15,
      taktPreis: rs.takt_preis ?? 12.5,
      // Eigener Artikel-Suchzustand je Position, nicht global — in einer
      // Sammelrechnung braucht jedes Ticket einen eigenen Artikel.
      artikelSuche: '',
      artikelListe: [],
      artikelSucheLaeuft: false,
      artikelFehler: null,
    }
  })
  odooKundenSuchen()
  // Direkt beim Öffnen schon eine Artikel-Vorschlagsliste je Position
  // laden (zuletzt geänderte Artikel) — Mathias muss nicht erst tippen,
  // um zu sehen, was zur Auswahl steht.
  for (const p of positionen.value) artikelSuchen(p)
}

onMounted(positionenVorbereiten)

async function odooKundenSuchen() {
  kundenSucheLaeuft.value = true
  kundenSucheFehler.value = null
  try {
    const antwort = await api.odooKunden(odooKundeSuche.value)
    odooKundenListe.value = antwort.kunden || []
  } catch (e) {
    kundenSucheFehler.value = e.message
  } finally {
    kundenSucheLaeuft.value = false
  }
}

// Leicht verzögert (300 ms) statt bei jedem Tastendruck — sonst eine
// Anfrage pro Buchstabe während des Tippens.
watch(odooKundeSuche, () => {
  clearTimeout(kundenSucheTimer)
  kundenSucheTimer = setTimeout(odooKundenSuchen, 300)
})

function odooKundeWaehlen(kunde) {
  odooKundeAusgewaehlt.value = kunde
}

// ─── Odoo-Artikel je Position ───────────────────────────────────────────
//
// Jede Position braucht zwingend einen Artikel (bestimmt Steuer/Erlöskonto
// in Odoo) — eigener Such-Zustand pro Position statt eines globalen, da
// mehrere Positionen gleichzeitig im Dialog stehen. Ein Timer je Ticket-ID
// verhindert eine Anfrage pro Tastendruck.
const artikelSucheTimer = {}

function artikelSucheAendern(position) {
  clearTimeout(artikelSucheTimer[position.ticketId])
  artikelSucheTimer[position.ticketId] = setTimeout(() => artikelSuchen(position), 300)
}

async function artikelSuchen(position) {
  position.artikelSucheLaeuft = true
  position.artikelFehler = null
  try {
    const antwort = await api.odooArtikel(position.artikelSuche || '')
    position.artikelListe = antwort.artikel || []
  } catch (e) {
    position.artikelFehler = e.message
  } finally {
    position.artikelSucheLaeuft = false
  }
}

function artikelWaehlen(position, artikel) {
  position.produkt = artikel
  position.artikelSuche = ''
  position.artikelListe = []
  // Verkaufspreis des Artikels nur im Stunden-Modus als Stundensatz-
  // Vorschlag übernehmen — bei einer Pauschale hat der Artikelpreis keine
  // feste Beziehung zum ausgehandelten Gesamtbetrag. Und nur, wenn noch
  // kein Stundensatz vorbelegt ist (z. B. aus dem Ticket übernommen) —
  // sonst würde der Artikelpreis eine bewusste Vorgabe überschreiben.
  if (position.modus === 'stunden' && !position.stundensatz) {
    position.stundensatz = artikel.list_price
  }
}

// Anzahl der Zuschlags-Takte: alles über der Inklusivzeit, in angefangenen
// Takten gerechnet (74 Min. bei 60 inklusive und 15-Min.-Takt = 14 Min. =
// 1 Takt, 76 Min. = 2 Takte). Gleiche Rechnung wie auf dem Server.
function zuschlagBloecke(p) {
  const takt = Number(p.taktMinuten) || 0
  const darueber = (Number(p.istMinuten) || 0) - (Number(p.inklusivMinuten) || 0)
  return takt > 0 && darueber > 0 ? Math.ceil(darueber / takt) : 0
}

function positionsBetrag(p) {
  if (p.modus === 'stunden') {
    return (Number(p.stunden) || 0) * (Number(p.stundensatz) || 0)
  }
  if (p.modus === 'pauschale_zuschlag') {
    return (Number(p.betrag) || 0) + zuschlagBloecke(p) * (Number(p.taktPreis) || 0)
  }
  return Number(p.betrag) || 0
}

const summe = computed(() => positionen.value.reduce((s, p) => s + positionsBetrag(p), 0))

const positionenGueltig = computed(
  () =>
    positionen.value.length > 0 &&
    positionen.value.every((p) => {
      if (!p.produkt || p.beschreibung.trim() === '') return false
      if (p.modus === 'stunden') return Number(p.stunden) > 0 && Number(p.stundensatz) > 0
      if (p.modus === 'pauschale_zuschlag') {
        return Number(p.betrag) > 0 && (zuschlagBloecke(p) === 0 || Number(p.taktPreis) > 0)
      }
      return Number(p.betrag) > 0
    }),
)

async function sammelrechnungAbsenden() {
  if (!odooKundeAusgewaehlt.value || !positionenGueltig.value || sammelrechnungLaeuft.value) return
  sammelrechnungFehler.value = null
  sammelrechnungLaeuft.value = true
  try {
    const antwort = await api.odooSammelrechnung(
      odooKundeAusgewaehlt.value.id,
      positionen.value.flatMap((p) => {
        // ticketId mitschicken, damit der Server hinterher genau diese
        // Tickets automatisch als "Rechnung gestellt" markieren kann.
        if (p.modus === 'pauschale_zuschlag') {
          // Zwei Positionen für den Kunden nachvollziehbar: die Pauschale
          // selbst und — nur wenn die Inklusivzeit überschritten ist — der
          // Mehraufwand als Menge (Takte) × Preis je Takt. Odoo kennt dafür
          // nichts Besonderes: der Mehraufwand läuft als normale
          // Mengen-Position (Server-Modus "stunden" = Menge × Preis).
          const liste = [{
            ticketId: p.ticketId,
            beschreibung: p.beschreibung,
            produktId: p.produkt?.id,
            modus: 'pauschale',
            betrag: Number(p.betrag) || 0,
            stunden: null,
            stundensatz: null,
          }]
          const bloecke = zuschlagBloecke(p)
          if (bloecke > 0) {
            liste.push({
              ticketId: p.ticketId,
              beschreibung: `Mehraufwand: ${p.istMinuten} Min. Ist-Zeit, ${p.inklusivMinuten} Min. in der Pauschale enthalten — ${bloecke} × ${p.taktMinuten} Min.`,
              produktId: p.produkt?.id,
              modus: 'stunden',
              betrag: null,
              stunden: bloecke,
              stundensatz: Number(p.taktPreis) || 0,
            })
          }
          return liste
        }
        return [{
          ticketId: p.ticketId,
          beschreibung: p.beschreibung,
          produktId: p.produkt?.id,
          modus: p.modus,
          betrag: p.modus === 'pauschale' ? Number(p.betrag) || 0 : null,
          stunden: p.modus === 'stunden' ? Number(p.stunden) || 0 : null,
          stundensatz: p.modus === 'stunden' ? Number(p.stundensatz) || 0 : null,
        }]
      }),
    )
    sammelrechnungErgebnis.value = antwort
    // Der Server markiert die betroffenen Tickets jetzt automatisch als
    // "Rechnung gestellt" (siehe AbrechnungController::odooSammelrechnung()) —
    // die Ticketliste zieht das lokal nach, statt auf ein Neuladen zu warten.
    emit('abgerechnet', new Set(positionen.value.map((p) => p.ticketId)))
  } catch (e) {
    sammelrechnungFehler.value = e.message
  } finally {
    sammelrechnungLaeuft.value = false
  }
}
</script>

<template>
  <div
    class="rb-modal-overlay"
    @click.self="sammelrechnungSchliessen"
    @keydown.esc="sammelrechnungSchliessen"
  >
    <div class="rb-modal-fenster">
      <header class="rb-modal-kopf">
        <span class="rb-modal-titel">Sammelrechnung erstellen</span>
        <button type="button" class="rb-modal-schliessen" title="Schließen" @click="sammelrechnungSchliessen">×</button>
      </header>

      <div class="rb-modal-inhalt">
        <template v-if="sammelrechnungErgebnis">
          <p><strong style="color: #2e7d32;">✔ Rechnungsentwurf angelegt</strong></p>
          <p class="rb-gedaempft">
            Der Entwurf liegt in Odoo bereit — er wurde weder verbucht noch versendet, das bleibt ein
            bewusster nächster Schritt dort. {{ sammelrechnungErgebnis.ticketIds?.length || 0 }}
            Ticket{{ (sammelrechnungErgebnis.ticketIds?.length || 0) === 1 ? '' : 's' }} wurden dabei
            automatisch als "Rechnung gestellt" markiert.
          </p>
          <p>
            <a :href="sammelrechnungErgebnis.url" target="_blank" rel="noopener" class="rb-knopf-primaer">
              Rechnung in Odoo öffnen ↗
            </a>
          </p>
          <div class="rb-modal-aktionen">
            <button type="button" class="rb-knopf-sekundaer" @click="sammelrechnungSchliessen">Fertig</button>
          </div>
        </template>

        <template v-else>
          <div class="rb-feld">
            <label for="odoo-kunde-suche">Odoo-Kunde</label>
            <input
              id="odoo-kunde-suche"
              v-model="odooKundeSuche"
              type="text"
              placeholder="Kunde suchen …"
              autocomplete="off"
            >
            <p v-if="odooKundeAusgewaehlt" class="rb-gedaempft">
              Ausgewählt: <strong>{{ odooKundeAusgewaehlt.name }}</strong>
              <button type="button" class="rb-link-knopf" @click="odooKundeAusgewaehlt = null">ändern</button>
            </p>
            <ul v-else class="rb-kundenliste">
              <li v-if="kundenSucheLaeuft" class="rb-gedaempft">Wird gesucht …</li>
              <li v-else-if="kundenSucheFehler" class="rb-zustand-fehler">{{ kundenSucheFehler }}</li>
              <li v-else-if="odooKundenListe.length === 0" class="rb-gedaempft">Keine Treffer.</li>
              <li
                v-for="k in odooKundenListe"
                :key="k.id"
                class="rb-kunden-eintrag"
                @click="odooKundeWaehlen(k)"
              >
                {{ k.name }} <span v-if="k.email" class="rb-gedaempft">— {{ k.email }}</span>
              </li>
            </ul>
          </div>

          <div class="rb-positionsliste">
            <div v-for="p in positionen" :key="p.ticketId" class="rb-position">
              <div class="rb-position-kopf">
                <textarea v-model="p.beschreibung" rows="2" class="rb-position-beschreibung"></textarea>
                <div class="rb-modus-umschalter">
                  <button
                    type="button"
                    :class="{ 'rb-modus-aktiv': p.modus === 'pauschale' }"
                    @click="p.modus = 'pauschale'"
                  >Pauschale</button>
                  <button
                    type="button"
                    :class="{ 'rb-modus-aktiv': p.modus === 'stunden' }"
                    @click="p.modus = 'stunden'"
                  >Stunden</button>
                  <button
                    type="button"
                    :class="{ 'rb-modus-aktiv': p.modus === 'pauschale_zuschlag' }"
                    @click="p.modus = 'pauschale_zuschlag'"
                  >Pauschale + Zuschlag</button>
                </div>
              </div>

              <div class="rb-position-artikel">
                <template v-if="p.produkt">
                  <span class="rb-gedaempft">Artikel: <strong>{{ p.produkt.name }}</strong></span>
                  <button type="button" class="rb-link-knopf" @click="p.produkt = null">ändern</button>
                </template>
                <template v-else>
                  <input
                    v-model="p.artikelSuche"
                    type="text"
                    placeholder="Odoo-Artikel suchen … (Pflicht)"
                    autocomplete="off"
                    @input="artikelSucheAendern(p)"
                  >
                  <ul class="rb-kundenliste rb-artikelliste">
                    <li v-if="p.artikelSucheLaeuft" class="rb-gedaempft">Wird gesucht …</li>
                    <li v-else-if="p.artikelFehler" class="rb-zustand-fehler">{{ p.artikelFehler }}</li>
                    <li v-else-if="p.artikelListe.length === 0" class="rb-gedaempft">Keine Treffer.</li>
                    <li
                      v-for="a in p.artikelListe"
                      :key="a.id"
                      class="rb-kunden-eintrag"
                      @click="artikelWaehlen(p, a)"
                    >
                      {{ a.name }} <span class="rb-gedaempft">— {{ a.list_price.toFixed(2) }} €</span>
                    </li>
                  </ul>
                </template>
              </div>

              <div class="rb-position-betrag">
                <template v-if="p.modus === 'pauschale'">
                  <label>Betrag (€)</label>
                  <input v-model.number="p.betrag" type="number" min="0" step="0.01" class="rb-betrag-feld">
                </template>
                <template v-else-if="p.modus === 'pauschale_zuschlag'">
                  <label>Pauschale (€)</label>
                  <input v-model.number="p.betrag" type="number" min="0" step="0.01" class="rb-betrag-feld">
                  <label>Ist-Zeit (Min.)</label>
                  <input v-model.number="p.istMinuten" type="number" min="0" step="1" class="rb-betrag-feld">
                  <label>inkl. (Min.)</label>
                  <input v-model.number="p.inklusivMinuten" type="number" min="0" step="1" class="rb-betrag-feld">
                  <label>Takt (Min.)</label>
                  <input v-model.number="p.taktMinuten" type="number" min="1" step="1" class="rb-betrag-feld">
                  <label>€ je Takt</label>
                  <input v-model.number="p.taktPreis" type="number" min="0" step="0.01" class="rb-betrag-feld">
                  <span class="rb-gedaempft rb-position-zwischensumme">
                    {{ zuschlagBloecke(p) }} Takt{{ zuschlagBloecke(p) === 1 ? '' : 'e' }} Zuschlag
                    = {{ positionsBetrag(p).toFixed(2) }} € gesamt
                  </span>
                </template>
                <template v-else>
                  <label>Stunden</label>
                  <input v-model.number="p.stunden" type="number" min="0" step="0.01" class="rb-betrag-feld">
                  <label>Satz (€/Std.)</label>
                  <input v-model.number="p.stundensatz" type="number" min="0" step="0.01" class="rb-betrag-feld">
                  <span class="rb-gedaempft rb-position-zwischensumme">
                    = {{ ((Number(p.stunden) || 0) * (Number(p.stundensatz) || 0)).toFixed(2) }} €
                  </span>
                </template>
              </div>
            </div>

            <div class="rb-positionsliste-summe">
              <span class="rb-gedaempft">Summe</span>
              <strong>{{ summe.toFixed(2) }} €</strong>
            </div>
          </div>

          <p v-if="sammelrechnungFehler" class="rb-zustand-fehler">{{ sammelrechnungFehler }}</p>

          <div class="rb-modal-aktionen">
            <button type="button" class="rb-knopf-sekundaer" @click="sammelrechnungSchliessen">Abbrechen</button>
            <button
              type="button"
              class="rb-knopf-primaer"
              :disabled="!odooKundeAusgewaehlt || !positionenGueltig || sammelrechnungLaeuft"
              @click="sammelrechnungAbsenden"
            >
              {{ sammelrechnungLaeuft ? 'Wird angelegt …' : 'Rechnungsentwurf anlegen' }}
            </button>
          </div>
        </template>
      </div>
    </div>
  </div>
</template>

<style scoped>
.rb-gedaempft {
  color: var(--color-text-maxcontrast, #767676);
}

.rb-zustand-fehler {
  color: #c62828;
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

.rb-feld {
  margin-bottom: 16px;
}

.rb-feld label {
  display: block;
  margin-bottom: 4px;
  font-weight: 600;
  font-size: 0.9em;
}

.rb-feld input[type='text'] {
  width: 100%;
  box-sizing: border-box;
  padding: 8px 10px;
  border-radius: var(--border-radius, 6px);
  border: 1px solid var(--color-border, #d8d8db);
  background: var(--color-main-background, #fff);
  color: var(--color-main-text, #222);
}

.rb-link-knopf {
  background: none;
  border: none;
  color: var(--color-primary-element, #0069c2);
  cursor: pointer;
  padding: 0;
  font-size: 0.9em;
  text-decoration: underline;
  margin-left: 6px;
}

.rb-kundenliste {
  list-style: none;
  margin: 8px 0 0;
  padding: 0;
  max-height: 160px;
  overflow-y: auto;
  border: 1px solid var(--color-border, #e0e0e3);
  border-radius: var(--border-radius, 6px);
}

.rb-kunden-eintrag {
  padding: 8px 10px;
  cursor: pointer;
  border-bottom: 1px solid var(--color-border, #eee);
}

.rb-kunden-eintrag:last-child {
  border-bottom: none;
}

.rb-kunden-eintrag:hover {
  background: var(--color-background-hover, #f5f5f7);
}

.rb-positionsliste {
  margin-bottom: 12px;
}

.rb-position {
  padding: 10px 12px;
  margin-bottom: 8px;
  border: 1px solid var(--color-border, #e0e0e3);
  border-radius: var(--border-radius, 6px);
}

.rb-position-kopf {
  display: flex;
  align-items: flex-start;
  gap: 8px;
  margin-bottom: 8px;
}

.rb-position-beschreibung {
  flex: 1;
  box-sizing: border-box;
  padding: 6px 8px;
  border-radius: var(--border-radius, 6px);
  border: 1px solid var(--color-border, #d8d8db);
  background: var(--color-main-background, #fff);
  color: var(--color-main-text, #222);
  /* Textarea statt Einzeiler, damit Zeilenumbrüche aus der
     Ticketbeschreibung (siehe positionsBeschreibung()) tatsächlich
     sichtbar bleiben und beim manuellen Nachbearbeiten erhalten
     bleiben, statt in einem <input> stillschweigend verloren zu gehen. */
  font: inherit;
  resize: vertical;
  min-height: 2.4em;
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

.rb-position-artikel {
  margin-bottom: 8px;
}

.rb-position-artikel > input[type='text'] {
  width: 100%;
  box-sizing: border-box;
  padding: 6px 8px;
  border-radius: var(--border-radius, 6px);
  border: 1px solid var(--color-border, #d8d8db);
  background: var(--color-main-background, #fff);
  color: var(--color-main-text, #222);
}

.rb-artikelliste {
  max-height: 120px;
}

.rb-position-betrag {
  display: flex;
  align-items: center;
  gap: 6px;
  flex-wrap: wrap;
}

.rb-position-betrag label {
  font-size: 0.8em;
  color: var(--color-text-maxcontrast, #767676);
}

.rb-betrag-feld {
  width: 90px;
  box-sizing: border-box;
  padding: 6px 8px;
  border-radius: var(--border-radius, 6px);
  border: 1px solid var(--color-border, #d8d8db);
  background: var(--color-main-background, #fff);
  color: var(--color-main-text, #222);
  text-align: right;
}

.rb-position-zwischensumme {
  margin-left: auto;
  font-size: 0.85em;
}

.rb-positionsliste-summe {
  display: flex;
  justify-content: space-between;
  padding: 8px 4px 0;
  border-top: 2px solid var(--color-border, #e0e0e3);
}

.rb-modal-aktionen {
  display: flex;
  justify-content: flex-end;
  gap: 10px;
  margin-top: 8px;
}
</style>
