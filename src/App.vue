<script setup>
import { ref, computed, onMounted, onUnmounted } from 'vue'
import { api } from './lib/api.js'
import TicketListe from './views/TicketListe.vue'
import TicketDetail from './views/TicketDetail.vue'
import TicketNeu from './views/TicketNeu.vue'
import ProjektNeu from './views/ProjektNeu.vue'
import ProjekteGantt from './views/ProjekteGantt.vue'
import MeineTickets from './views/MeineTickets.vue'

// Bewusst kein vue-router: Vier Ansichten rechtfertigen keine zusätzliche
// Abhängigkeit. Das Hash (#/tickets/28) reicht für "Adresse kopierbar" und
// "Zurück-Taste funktioniert" völlig aus.
const hash = ref(window.location.hash)

function aktualisieren() {
  hash.value = window.location.hash
}

onMounted(() => window.addEventListener('hashchange', aktualisieren))
onUnmounted(() => window.removeEventListener('hashchange', aktualisieren))

// Mitglieder der (in den Admin-Einstellungen konfigurierten)
// Mitarbeiter-Gruppe sehen AUSSCHLIESSLICH "Meine Tickets" — unabhängig vom
// Hash in der Adresszeile. Das ist nur die Oberflächen-Seite der
// Einschränkung: die eigentliche Absicherung läuft serverseitig über
// BasisController::geschuetzterAufruf() (jeder Endpunkt außer den
// `meine*`-Endpunkten liefert diesem Konto ohnehin 403), hier geht es nur
// darum, gar nicht erst Oberfläche für gesperrte Funktionen anzuzeigen.
const sitzungGeladen = ref(false)
const istMitarbeiter = ref(false)
// Admin-Konto, das zusätzlich Mitglied der Mitarbeiter-Gruppe ist — wird
// (anders als normale Gruppenmitglieder) NICHT auf "Meine Tickets"
// verengt, bekommt dafür aber den freiwilligen Navigationspunkt dorthin
// in TicketListe.vue angeboten (siehe MitarbeiterController::sitzung()).
const mitarbeiterGruppenMitglied = ref(false)

onMounted(async () => {
  try {
    const antwort = await api.sitzung()
    istMitarbeiter.value = antwort.istMitarbeiter
    mitarbeiterGruppenMitglied.value = antwort.mitarbeiterGruppenMitglied
  } catch {
    // Schlägt die Prüfung selbst fehl (z. B. Redmine-Verbindung gerade
    // down), lieber die normale Oberfläche zeigen als die Seite zu
    // blockieren — die serverseitige Absicherung der einzelnen Endpunkte
    // bleibt davon unberührt.
    istMitarbeiter.value = false
    mitarbeiterGruppenMitglied.value = false
  } finally {
    sitzungGeladen.value = true
  }
})

const route = computed(() => {
  if (hash.value === '#/tickets/neu') return { name: 'ticket-neu' }
  if (hash.value === '#/projekte/neu') return { name: 'projekt-neu' }
  if (hash.value === '#/projekte') return { name: 'projekte-gantt' }
  if (hash.value === '#/meine-tickets') return { name: 'meine-tickets' }
  const treffer = hash.value.match(/^#\/tickets\/(\d+)/)
  if (treffer) return { name: 'ticket-detail', id: Number(treffer[1]) }
  return { name: 'liste' }
})
</script>

<template>
  <div v-if="!sitzungGeladen" class="rb-lade-sperre">Wird geladen …</div>
  <MeineTickets v-else-if="istMitarbeiter" />
  <template v-else>
    <MeineTickets v-if="route.name === 'meine-tickets'" :zeigeZurueckLink="true" />
    <TicketNeu v-else-if="route.name === 'ticket-neu'" />
    <ProjektNeu v-else-if="route.name === 'projekt-neu'" />
    <ProjekteGantt v-else-if="route.name === 'projekte-gantt'" />
    <TicketDetail v-else-if="route.name === 'ticket-detail'" :id="route.id" />
    <TicketListe v-else :meineTicketsVerfuegbar="mitarbeiterGruppenMitglied" />
  </template>
</template>

<style>
.rb-lade-sperre {
  padding: 48px 24px;
  text-align: center;
  color: var(--color-text-maxcontrast, #767676);
}

/* Nicht "scoped": muss auch außerhalb der Vue-Komponenten greifen, auf dem
   Behälter, den Nextcloud selbst um die App herum aufbaut. Ohne das bleibt
   der Inhalt unterhalb der Bildschirmhöhe unerreichbar — die Seite meldet
   keinen Fehler, man kommt einfach nicht mit der Maus/dem Rad dorthin. */
#redmine-bridge-app,
#app-content,
#content {
  height: 100%;
  overflow-y: auto !important;
}

/* Nextclouds eigenes Seitengerüst begrenzt den Inhaltsbereich mancher Apps
   auf eine feste Höchstbreite (für Lesbarkeit bei reinen Textseiten gedacht)
   — für unsere tabellen-/formularlastige Oberfläche wollen wir stattdessen
   die volle verfügbare Breite. #content.app-redmine_bridge (ID + eigene
   App-Klasse kombiniert) hat höhere Spezifität als Nextclouds allgemeine
   "#content"-Regel und gewinnt deshalb zuverlässig, unabhängig davon, in
   welcher Reihenfolge die Stylesheets geladen werden. */
#content.app-redmine_bridge,
#app-content,
#app-content-vue,
#content-vue,
.app-content,
main {
  max-width: none !important;
  width: 100% !important;
}

/* Falls der Elternbereich mit Flexbox arbeitet, streckt sich ein Kind ohne
   eigene Breitenangabe nicht automatisch mit — das hier zwingt unseren
   eingehängten Bereich, den verfügbaren Platz tatsächlich auszufüllen,
   statt sich nur an seinem eigenen Inhalt zu orientieren. Ergänzt um
   align-self: stretch, da der Elternbereich seine Kinder offenbar
   UNTEREINANDER anordnet (flex-direction: column) — dort steuert
   flex-grow nur die Höhe, für die Breite braucht es zusätzlich
   align-self, um die Quer-Achse zu füllen. */
#redmine-bridge-app {
  width: 100% !important;
  flex: 1 1 auto !important;
  align-self: stretch !important;
}
</style>
