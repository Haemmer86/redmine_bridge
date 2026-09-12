<script setup>
import { ref, reactive, onMounted } from 'vue'
import { api } from '../lib/api.js'

const formular = reactive({ name: '', identifier: '', description: '' })
const fehler = ref(null)
const erfolgHinweis = ref(false)
const speichernLaeuft = ref(false)
const kurznameVonHandGeaendert = ref(false)

const benutzer = ref([])
const rollen = ref([])
const neuesM_benutzerId = ref(null)
const neuesM_rolleId = ref(null)
const mitgliederEntwurf = ref([])

onMounted(async () => {
  try {
    const daten = await api.formulardaten()
    benutzer.value = daten.benutzer
    rollen.value = daten.rollen
    if (rollen.value.length) neuesM_rolleId.value = rollen.value[0].id
  } catch {
    // Ohne Benutzer-/Rollenliste bleibt die Mitgliederzuordnung leer —
    // das Projekt selbst lässt sich trotzdem anlegen.
  }
})

// Schlägt einen technisch gültigen Kurznamen vor, solange der Anwender ihn
// nicht selbst angefasst hat — Redmine erlaubt dort nur Kleinbuchstaben,
// Ziffern und Bindestriche, und der Kurzname ist nach dem Anlegen praktisch
// nicht mehr änderbar.
function kurznameAusNamenAbleiten() {
  if (kurznameVonHandGeaendert.value) return
  formular.identifier = formular.name
    .toLowerCase()
    .normalize('NFD').replace(/[\u0300-\u036f]/g, '') // Umlaute/Akzente entfernen
    .replace(/[^a-z0-9]+/g, '-')
    .replace(/^-+|-+$/g, '')
    .slice(0, 100)
}

function mitgliedZumEntwurfHinzufuegen() {
  if (!neuesM_benutzerId.value || !neuesM_rolleId.value) return
  // Denselben Benutzer mit derselben Rolle nicht zweimal in den Entwurf
  // aufnehmen — beim Speichern entstünden sonst zwei Anfragen für dieselbe
  // Zuordnung.
  const doppelt = mitgliederEntwurf.value.some(
    (m) => m.benutzerId === neuesM_benutzerId.value && m.rolleId === neuesM_rolleId.value,
  )
  if (doppelt) return

  const b = benutzer.value.find((x) => x.id === neuesM_benutzerId.value)
  const r = rollen.value.find((x) => x.id === neuesM_rolleId.value)
  mitgliederEntwurf.value.push({
    benutzerId: neuesM_benutzerId.value,
    rolleId: neuesM_rolleId.value,
    benutzerName: b?.firstname ? `${b.firstname} ${b.lastname}` : b?.login,
    rolleName: r?.name,
  })
}

function ausEntwurfEntfernen(index) {
  mitgliederEntwurf.value.splice(index, 1)
}

async function speichern() {
  if (!formular.name.trim()) {
    fehler.value = 'Bitte einen Namen eintragen.'
    return
  }
  if (!formular.identifier.trim()) {
    fehler.value = 'Bitte einen Kurznamen eintragen.'
    return
  }
  speichernLaeuft.value = true
  fehler.value = null
  try {
    const antwort = await api.projektErstellen({ ...formular })
    const projektId = antwort.projekt?.id

    // Mitgliedschaften erst NACH dem Anlegen möglich — bewusst nacheinander
    // statt gleichzeitig, damit eine fehlgeschlagene Zuordnung eindeutig
    // benennbar bleibt, statt in einer Sammelfehlermeldung unterzugehen.
    const fehlgeschlagen = []
    for (const m of mitgliederEntwurf.value) {
      try {
        await api.mitgliedHinzufuegen(projektId, m.benutzerId, [m.rolleId])
      } catch (e) {
        fehlgeschlagen.push(`${m.benutzerName} (${m.rolleName}): ${e.message}`)
      }
    }

    if (fehlgeschlagen.length) {
      fehler.value = 'Projekt angelegt, aber nicht alle Mitglieder konnten zugeordnet werden: ' + fehlgeschlagen.join('; ')
      speichernLaeuft.value = false
      return
    }

    erfolgHinweis.value = true
    setTimeout(() => { window.location.hash = '' }, 1200)
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
      <h1>Neues Projekt</h1>
    </header>

    <div v-if="fehler" class="rb-meldung rb-meldung-fehler">{{ fehler }}</div>
    <div v-if="erfolgHinweis" class="rb-meldung rb-meldung-erfolg">Projekt angelegt.</div>

    <section class="rb-karte">
      <label class="rb-feld-breit">
        <span class="rb-label">Name</span>
        <input
          v-model="formular.name"
          type="text"
          class="rb-eingabe"
          placeholder="z. B. Kundenportal Ablösung"
          @input="kurznameAusNamenAbleiten"
        >
      </label>

      <label class="rb-feld-breit">
        <span class="rb-label">Kurzname (Teil der Adresse, nachträglich kaum änderbar)</span>
        <input
          v-model="formular.identifier"
          type="text"
          class="rb-eingabe"
          placeholder="kundenportal-abloesung"
          @input="kurznameVonHandGeaendert = true"
        >
      </label>

      <label class="rb-feld-breit">
        <span class="rb-label">Beschreibung</span>
        <textarea v-model="formular.description" rows="4" class="rb-eingabe"></textarea>
      </label>
    </section>

    <section class="rb-karte">
      <h2 class="rb-kartentitel">Mitglieder</h2>

      <p v-if="!benutzer.length" class="rb-gedaempft">
        Keine Benutzerliste verfügbar — Mitglieder lassen sich nachträglich direkt in Redmine zuordnen.
      </p>

      <template v-else>
        <div class="rb-mitglied-zeile">
          <select v-model.number="neuesM_benutzerId" class="rb-eingabe">
            <option :value="null">— Benutzer wählen —</option>
            <option v-for="b in benutzer" :key="b.id" :value="b.id">
              {{ b.firstname ? `${b.firstname} ${b.lastname}` : b.login }}
            </option>
          </select>
          <select v-model.number="neuesM_rolleId" class="rb-eingabe">
            <option v-for="r in rollen" :key="r.id" :value="r.id">{{ r.name }}</option>
          </select>
          <button class="rb-knopf-sekundaer" type="button" @click="mitgliedZumEntwurfHinzufuegen">+ Hinzufügen</button>
        </div>

        <ul v-if="mitgliederEntwurf.length" class="rb-mitgliederliste">
          <li v-for="(m, i) in mitgliederEntwurf" :key="m.benutzerId + '-' + m.rolleId" class="rb-mitgliedzeile">
            <span>{{ m.benutzerName }} <span class="rb-gedaempft">— {{ m.rolleName }}</span></span>
            <button class="rb-entfernen" type="button" title="Entfernen" @click="ausEntwurfEntfernen(i)">×</button>
          </li>
        </ul>
        <p v-else class="rb-gedaempft">Noch keine Mitglieder für die Übernahme vorgemerkt.</p>
      </template>
    </section>

    <div class="rb-aktionen-frei">
      <button class="rb-knopf-primaer" :disabled="speichernLaeuft" @click="speichern">
        <span v-if="speichernLaeuft" class="rb-spinner rb-spinner-hell"></span>
        {{ speichernLaeuft ? 'Wird angelegt …' : 'Projekt anlegen' }}
      </button>
    </div>
  </div>
</template>

<style scoped>
.rb-seite {
  padding: 32px 40px;
  max-width: 700px;
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
  margin-bottom: 20px;
}
.rb-kartentitel {
  margin: 0 0 16px;
  font-size: 1.1em;
}
.rb-gedaempft {
  color: var(--color-text-maxcontrast, #767676);
}

.rb-mitglied-zeile {
  display: flex;
  gap: 10px;
  margin-bottom: 16px;
}
.rb-mitglied-zeile .rb-eingabe {
  flex: 1;
}
.rb-knopf-sekundaer {
  padding: 7px 14px;
  border-radius: var(--border-radius, 6px);
  border: 1px solid var(--color-border, #d8d8db);
  background: var(--color-main-background, #fff);
  color: var(--color-main-text, #222);
  cursor: pointer;
  white-space: nowrap;
}
.rb-knopf-sekundaer:hover {
  border-color: var(--color-primary-element, #0069c2);
}

.rb-mitgliederliste {
  list-style: none;
  margin: 0;
  padding: 0;
}
.rb-mitgliedzeile {
  display: flex;
  justify-content: space-between;
  align-items: center;
  padding: 8px 0;
  border-bottom: 1px solid var(--color-border, #eee);
  font-size: 0.9em;
}
.rb-mitgliedzeile:last-child {
  border-bottom: none;
}
.rb-entfernen {
  background: none;
  border: none;
  font-size: 1.2em;
  line-height: 1;
  color: var(--color-text-maxcontrast, #767676);
  cursor: pointer;
  padding: 2px 6px;
}
.rb-entfernen:hover {
  color: #c62828;
}

.rb-aktionen-frei {
  margin-bottom: 20px;
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
.rb-meldung-erfolg {
  background: #eaf6ea;
  color: #2e7d32;
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
}
</style>
