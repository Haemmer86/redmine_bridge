<script setup>
import { ref, computed, onMounted, onUnmounted } from 'vue'
import TicketListe from './views/TicketListe.vue'
import TicketDetail from './views/TicketDetail.vue'
import TicketNeu from './views/TicketNeu.vue'
import ProjektNeu from './views/ProjektNeu.vue'

// Bewusst kein vue-router: Vier Ansichten rechtfertigen keine zusätzliche
// Abhängigkeit. Das Hash (#/tickets/28) reicht für "Adresse kopierbar" und
// "Zurück-Taste funktioniert" völlig aus.
const hash = ref(window.location.hash)

function aktualisieren() {
  hash.value = window.location.hash
}

onMounted(() => window.addEventListener('hashchange', aktualisieren))
onUnmounted(() => window.removeEventListener('hashchange', aktualisieren))

const route = computed(() => {
  if (hash.value === '#/tickets/neu') return { name: 'ticket-neu' }
  if (hash.value === '#/projekte/neu') return { name: 'projekt-neu' }
  const treffer = hash.value.match(/^#\/tickets\/(\d+)/)
  if (treffer) return { name: 'ticket-detail', id: Number(treffer[1]) }
  return { name: 'liste' }
})
</script>

<template>
  <TicketNeu v-if="route.name === 'ticket-neu'" />
  <ProjektNeu v-else-if="route.name === 'projekt-neu'" />
  <TicketDetail v-else-if="route.name === 'ticket-detail'" :id="route.id" />
  <TicketListe v-else />
</template>

<style>
/* Nicht "scoped": muss auch außerhalb der Vue-Komponenten greifen, auf dem
   Behälter, den Nextcloud selbst um die App herum aufbaut. Ohne das bleibt
   der Inhalt unterhalb der Bildschirmhöhe unerreichbar — die Seite meldet
   keinen Fehler, man kommt einfach nicht mit der Maus/dem Rad dorthin. */
#redmine-bruecke-app,
#app-content,
#content {
  height: 100%;
  overflow-y: auto !important;
}
</style>
