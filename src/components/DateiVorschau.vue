<script setup>
import { ref, onMounted, onUnmounted } from 'vue'
import { vorschauUrl, vorschauDirektUrl, dateisymbol } from '../lib/ablage.js'

// Große Voransicht einer Ablage-Datei in einem Fenster über der Seite: Bild/
// PDF-Vorschau von Nextcloud, bei Notizen und E-Mails deren Text. Die
// Detailseite hängt die Komponente nur ein, solange eine Datei geöffnet ist.
const props = defineProps({
  datei: { type: Object, required: true },
})
const emit = defineEmits(['schliessen'])

const vorschauGrossFehler = ref(false)

function vorschauSchliessen() {
  emit('schliessen')
}

function vorschauGrossFehlgeschlagen() {
  vorschauGrossFehler.value = true
}

function escTaste(ereignis) {
  if (ereignis.key === 'Escape') vorschauSchliessen()
}
onMounted(() => window.addEventListener('keydown', escTaste))
onUnmounted(() => window.removeEventListener('keydown', escTaste))
</script>

<template>
  <div class="rb-vorschau-overlay" @click.self="vorschauSchliessen" @keydown.esc="vorschauSchliessen">
    <div class="rb-vorschau-fenster">
      <header class="rb-vorschau-kopf">
        <span class="rb-vorschau-titel" :title="datei.name">{{ datei.name }}</span>
        <div class="rb-vorschau-kopf-aktionen">
          <a :href="vorschauDirektUrl(datei.id)" target="_blank" rel="noopener" class="rb-vorschau-link-knopf">
            In Dateien öffnen ↗
          </a>
          <button type="button" class="rb-vorschau-schliessen" title="Schließen" @click="vorschauSchliessen">×</button>
        </div>
      </header>
      <div class="rb-vorschau-inhalt">
        <div v-if="datei.notiz" class="rb-vorschau-text">
          <p class="rb-vorschau-notiz-kopf">
            <strong>{{ datei.notiz.art === 'E-Mail' ? datei.notiz.betreff : datei.notiz.art }}</strong>
            <span v-if="datei.notiz.datum" class="rb-gedaempft"> — {{ datei.notiz.datum }}</span>
          </p>
          <p v-if="datei.notiz.art === 'E-Mail'" class="rb-gedaempft">Von: {{ datei.notiz.von }}</p>
          <p v-else-if="datei.notiz.ansprechpartner" class="rb-gedaempft">
            Ansprechpartner: {{ datei.notiz.ansprechpartner }}
          </p>
          <p class="rb-vorschau-notiztext">{{ datei.notiz.text }}</p>
        </div>

        <div v-else-if="datei.eml" class="rb-vorschau-text">
          <p><strong>Von:</strong> {{ datei.eml.von || '—' }}</p>
          <p><strong>Betreff:</strong> {{ datei.eml.betreff || '—' }}</p>
          <p><strong>Datum:</strong> {{ datei.eml.datum || '—' }}</p>
          <p class="rb-gedaempft">Vollständige E-Mail über „In Dateien öffnen" ansehen.</p>
        </div>

        <template v-else>
          <img
            v-show="!vorschauGrossFehler"
            :src="vorschauUrl(datei.id, 1400)"
            :alt="datei.name"
            class="rb-vorschau-grossbild"
            @error="vorschauGrossFehlgeschlagen"
          >
          <p v-if="vorschauGrossFehler" class="rb-gedaempft">
            {{ dateisymbol(datei.mime) }} Für diesen Dateityp gibt es keine Bildvorschau — über „In Dateien öffnen" ansehen.
          </p>
        </template>
      </div>
    </div>
  </div>
</template>

<style scoped>
.rb-gedaempft {
  color: var(--color-text-maxcontrast, #767676);
}

/* ─── Große Vorschau, als eigenes Fenster über allem ─────────────────── */
.rb-vorschau-overlay {
  position: fixed;
  inset: 0;
  background: rgba(0, 0, 0, 0.7);
  display: flex;
  align-items: center;
  justify-content: center;
  z-index: 10000;
  padding: 24px;
}

.rb-vorschau-fenster {
  background: var(--color-main-background, #fff);
  border-radius: var(--border-radius-large, 10px);
  max-width: 90vw;
  max-height: 90vh;
  display: flex;
  flex-direction: column;
  overflow: hidden;
}

.rb-vorschau-kopf {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 16px;
  padding: 12px 16px;
  border-bottom: 1px solid var(--color-border, #e0e0e3);
}

.rb-vorschau-titel {
  font-weight: 600;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.rb-vorschau-kopf-aktionen {
  display: flex;
  align-items: center;
  gap: 12px;
  flex-shrink: 0;
}

.rb-vorschau-link-knopf {
  font-size: 0.85em;
  color: var(--color-primary-element, #0069c2);
  text-decoration: none;
  white-space: nowrap;
}

.rb-vorschau-link-knopf:hover {
  text-decoration: underline;
}

.rb-vorschau-schliessen {
  background: none;
  border: none;
  font-size: 1.6em;
  line-height: 1;
  cursor: pointer;
  color: var(--color-text-maxcontrast, #767676);
  padding: 0 4px;
}

.rb-vorschau-schliessen:hover {
  color: var(--color-main-text, #222);
}

.rb-vorschau-inhalt {
  padding: 16px;
  overflow: auto;
  display: flex;
  align-items: center;
  justify-content: center;
  flex: 1;
}

.rb-vorschau-grossbild {
  max-width: 100%;
  max-height: calc(90vh - 100px);
  object-fit: contain;
  display: block;
}

.rb-vorschau-text {
  text-align: left;
  max-width: 480px;
  width: 100%;
}

.rb-vorschau-notiz-kopf {
  margin: 0 0 8px;
}

.rb-vorschau-notiztext {
  white-space: pre-wrap;
  line-height: 1.5;
}
</style>
