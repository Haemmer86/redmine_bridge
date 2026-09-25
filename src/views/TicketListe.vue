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

// ─── Mehrfachauswahl + Odoo-Sammelrechnung ─────────────────────────────
const ausgewaehlt = ref(new Set())
const sammelrechnungOffen = ref(false)
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

async function laden() {
  ladend.value = true
  fehler.value = null
  // Eine Auswahl bezieht sich auf die gerade sichtbaren Zeilen — bei einem
  // Neuladen (Filterwechsel, Seitenwechsel) ist sie nicht mehr eindeutig
  // gültig, deshalb hier zurücksetzen statt sie über Seiten hinweg
  // mitzuschleppen.
  ausgewaehlt.value.clear()
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

// ─── Mehrfachauswahl ────────────────────────────────────────────────────

function auswahlUmschalten(id) {
  if (ausgewaehlt.value.has(id)) {
    ausgewaehlt.value.delete(id)
  } else {
    ausgewaehlt.value.add(id)
  }
}

function auswahlAufheben() {
  ausgewaehlt.value.clear()
}

const alleAusgewaehlt = computed(
  () => tickets.value.length > 0 && tickets.value.every((t) => ausgewaehlt.value.has(t.id)),
)

function alleUmschalten() {
  if (alleAusgewaehlt.value) {
    for (const t of tickets.value) ausgewaehlt.value.delete(t.id)
  } else {
    for (const t of tickets.value) ausgewaehlt.value.add(t.id)
  }
}

const ausgewaehlteTickets = computed(() => tickets.value.filter((t) => ausgewaehlt.value.has(t.id)))

// ─── Odoo-Sammelrechnung ────────────────────────────────────────────────
//
// Legt aus mehreren ausgewählten Tickets einen RechnungsENTWURF in Odoo an
// (eine Freitext-Position je Ticket, Betrag wird hier manuell eingetragen —
// es gibt keine automatische Ableitung aus einem Stundensatz). Odoo
// verbucht/versendet dabei nichts von selbst, der Entwurf bleibt zur
// Kontrolle in Odoo liegen.

function sammelrechnungOeffnen() {
  if (ausgewaehlteTickets.value.length === 0) return
  positionen.value = ausgewaehlteTickets.value.map((t) => ({
    ticketId: t.id,
    beschreibung: `#${t.id} ${t.subject}`,
    // "pauschale" = fester Gesamtbetrag der Position, "stunden" = Menge ×
    // Stundensatz, mit Redmines bisher erfasster Zeit als Vorschlag.
    modus: 'pauschale',
    produkt: null,
    betrag: null,
    stunden: t.spent_hours ?? 0,
    stundensatz: null,
    // Eigener Artikel-Suchzustand je Position, nicht global — in einer
    // Sammelrechnung braucht jedes Ticket einen eigenen Artikel.
    artikelSuche: '',
    artikelListe: [],
    artikelSucheLaeuft: false,
    artikelFehler: null,
  }))
  odooKundeSuche.value = ''
  odooKundenListe.value = []
  odooKundeAusgewaehlt.value = null
  kundenSucheFehler.value = null
  sammelrechnungFehler.value = null
  sammelrechnungErgebnis.value = null
  sammelrechnungOffen.value = true
  odooKundenSuchen()
  // Direkt beim Öffnen schon eine Artikel-Vorschlagsliste je Position
  // laden (zuletzt geänderte Artikel) — Mathias muss nicht erst tippen,
  // um zu sehen, was zur Auswahl steht.
  for (const p of positionen.value) artikelSuchen(p)
}

function sammelrechnungSchliessen() {
  sammelrechnungOffen.value = false
}

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
  // feste Beziehung zum ausgehandelten Gesamtbetrag.
  if (position.modus === 'stunden') {
    position.stundensatz = artikel.list_price
  }
}

const summe = computed(() =>
  positionen.value.reduce((s, p) => {
    if (p.modus === 'stunden') {
      return s + (Number(p.stunden) || 0) * (Number(p.stundensatz) || 0)
    }
    return s + (Number(p.betrag) || 0)
  }, 0),
)

const positionenGueltig = computed(
  () =>
    positionen.value.length > 0 &&
    positionen.value.every((p) => {
      if (!p.produkt || p.beschreibung.trim() === '') return false
      if (p.modus === 'stunden') return Number(p.stunden) > 0 && Number(p.stundensatz) > 0
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
      positionen.value.map((p) => ({
        beschreibung: p.beschreibung,
        produktId: p.produkt?.id,
        modus: p.modus,
        betrag: p.modus === 'pauschale' ? Number(p.betrag) || 0 : null,
        stunden: p.modus === 'stunden' ? Number(p.stunden) || 0 : null,
        stundensatz: p.modus === 'stunden' ? Number(p.stundensatz) || 0 : null,
      })),
    )
    sammelrechnungErgebnis.value = antwort
    auswahlAufheben()
  } catch (e) {
    sammelrechnungFehler.value = e.message
  } finally {
    sammelrechnungLaeuft.value = false
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

      <div v-if="ausgewaehlt.size > 0" class="rb-auswahl-leiste">
        <span>{{ ausgewaehlt.size }} Ticket{{ ausgewaehlt.size === 1 ? '' : 's' }} ausgewählt</span>
        <div class="rb-auswahl-aktionen">
          <button type="button" class="rb-knopf-sekundaer" @click="auswahlAufheben">Auswahl aufheben</button>
          <button type="button" class="rb-knopf-primaer" @click="sammelrechnungOeffnen">🧾 Sammelrechnung erstellen</button>
        </div>
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
            <th class="rb-col-schmal">
              <input
                type="checkbox"
                :checked="alleAusgewaehlt"
                aria-label="Alle Tickets auf dieser Seite auswählen"
                @change="alleUmschalten"
              >
            </th>
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
            <td colspan="7" :style="{ borderLeftColor: projektFarbe(g.projekt?.id) }">
              <span class="rb-punkt" :style="{ background: projektFarbe(g.projekt?.id) }"></span>
              <strong>{{ g.projekt?.name || 'Ohne Projekt' }}</strong>
              <span class="rb-gedaempft"> · {{ g.tickets.length }} Ticket{{ g.tickets.length === 1 ? '' : 's' }}</span>
            </td>
          </tr>
          <tr v-for="t in g.tickets" :key="t.id" class="rb-zeile" tabindex="0" @click="offnen(t.id)" @keydown.enter="offnen(t.id)">
            <td class="rb-auswahl-zelle" @click.stop @keydown.enter.stop>
              <input
                type="checkbox"
                :checked="ausgewaehlt.has(t.id)"
                aria-label="Ticket auswählen"
                @change="auswahlUmschalten(t.id)"
              >
            </td>
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

    <div
      v-if="sammelrechnungOffen"
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
              bewusster nächster Schritt dort.
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
                  <input v-model="p.beschreibung" type="text" class="rb-position-beschreibung">
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

/* ─── Mehrfachauswahl ──────────────────────────────────────────────────── */
.rb-auswahl-zelle {
  width: 1%;
  white-space: nowrap;
}
.rb-auswahl-leiste {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
  flex-wrap: wrap;
  padding: 10px 14px;
  margin-bottom: 12px;
  border-radius: var(--border-radius, 6px);
  background: var(--color-primary-element-light, #e8f1fb);
  color: var(--color-main-text, #222);
}
.rb-auswahl-aktionen {
  display: flex;
  gap: 10px;
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
  align-items: center;
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
