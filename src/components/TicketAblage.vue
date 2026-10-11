<script setup>
import { ref, computed, onMounted } from 'vue'
import { api } from '../lib/api.js'
import { mailApi } from '../lib/mailApi.js'
import { mailTextAusQuelle } from '../lib/mimeParser.js'
import { istHeic, heicZuJpeg } from '../lib/heic.js'
import { dateisymbol } from '../lib/ablage.js'

// Ablage eines Tickets (rechte Spalte der Detailseite): Ordner, Dateien,
// Links, Telefonnotizen, zugeordnete E-Mails (auch aus dem Posteingang) und
// Schlagworte. Der Server liefert nach jeder Änderung den kompletten
// Ablagestand; er geht als `aktualisiert` an die Detailseite, die ihn hält
// (Ordnerpfad, Ordnerfehler, Dateiliste). Fehler gehen als `fehler` nach
// oben. Die große Vorschau öffnet die Detailseite (`vorschau-oeffnen`).
const props = defineProps({
  id: { type: Number, required: true },
  dateien: { type: Array, default: () => [] },
  ordnerPfad: { type: String, default: null },
  ordnerFehler: { type: String, default: null },
})
const emit = defineEmits(['aktualisiert', 'fehler', 'vorschau-oeffnen', 'datei-geloescht'])

function ablageUebernehmen(antwort) {
  emit('aktualisiert', { ordnerPfad: antwort.ordnerPfad, ordnerFehler: antwort.ordnerFehler, dateien: antwort.dateien })
}

const dateiInput = ref(null)
const hochladeLaeuft = ref(false)

async function dateiAusgewaehlt(ereignis) {
  const ausgewaehlteDateien = Array.from(ereignis.target.files || [])
  if (!ausgewaehlteDateien.length) return
  hochladeLaeuft.value = true
  emit('fehler', null)
  const fehlgeschlagen = []
  try {
    // Nacheinander statt parallel — die API nimmt pro Aufruf genau eine
    // Datei entgegen, und der Ordner-Abgleich auf dem Server verträgt
    // sich nicht gut mit gleichzeitigen Anfragen für denselben Ordner.
    for (const datei of ausgewaehlteDateien) {
      try {
        const hochzuladendeDatei = istHeic(datei) ? await heicZuJpeg(datei) : datei
        const antwort = await api.dateiHochladen(props.id, hochzuladendeDatei)
        ablageUebernehmen(antwort)
      } catch (e) {
        fehlgeschlagen.push(`${datei.name}: ${e.message}`)
      }
    }
    if (fehlgeschlagen.length) {
      emit('fehler', 'Hochladen fehlgeschlagen bei ' + fehlgeschlagen.length + ' Datei(en) — ' + fehlgeschlagen.join('; '))
    }
  } finally {
    hochladeLaeuft.value = false
    if (dateiInput.value) dateiInput.value.value = ''
  }
}

function groesseFormatieren(bytes) {
  if (bytes < 1024) return `${bytes} B`
  return `${Math.round(bytes / 1024)} KB`
}

// Zeigt an, wann eine Datei zuletzt abgelegt/geändert wurde — "geaendert"
// kommt vom Server als Unix-Zeitstempel (Sekunden), daher die *1000 für JS.
function hochladeDatumFormatieren(zeitstempel) {
  if (!zeitstempel) return ''
  const dt = new Date(zeitstempel * 1000)
  const zweistellig = (n) => String(n).padStart(2, '0')
  return `${dt.getFullYear()}-${zweistellig(dt.getMonth() + 1)}-${zweistellig(dt.getDate())} ${zweistellig(dt.getHours())}:${zweistellig(dt.getMinutes())}`
}

// Öffnet den Ablage-Ordner direkt in Nextclouds eigener Dateien-App, in
// einem neuen Tab — der "dir"-Parameter ist dort seit jeher der Weg, eine
// bestimmte Stelle im Dateibaum zu adressieren.
function ordnerOeffnenUrl() {
  if (!props.ordnerPfad) return '#'
  const ziel = '/' + props.ordnerPfad
  const basis = typeof window !== 'undefined' && window.OC && typeof window.OC.generateUrl === 'function'
    ? window.OC.generateUrl('/apps/files/files')
    : '/apps/files/files'
  return `${basis}?dir=${encodeURIComponent(ziel)}`
}


// Grobe Kategorie je Datei — bewusst nur drei Gruppen (nicht nach exakter
// Endung), damit die Liste übersichtlich bleibt, egal ob ein PDF, ein Word-
// oder ein Excel-Dokument abgelegt wurde. Reihenfolge (Dokument vor Bild vor
// Sonstiges) bestimmt zugleich die Sortierung in weitereDateien().
const KATEGORIE_REIHENFOLGE = { Dokument: 0, Bild: 1, Sonstiges: 2 }
function dateiKategorie(d) {
  const mime = d.mime || ''
  if (mime.startsWith('image/')) return 'Bild'
  if (
    mime === 'application/pdf'
    || mime.startsWith('text/')
    || mime.includes('word')
    || mime.includes('sheet')
    || mime.includes('excel')
    || mime.includes('presentation')
    || mime.includes('powerpoint')
    || mime.includes('opendocument')
  ) return 'Dokument'
  return 'Sonstiges'
}

// Einheitliches Datumsformat für den Gesprächsverlauf — sowohl für unsere
// eigenen Notizen (schon im Format "YYYY-MM-DD HH:MM") als auch für
// .eml-Dateien (rohe, unterschiedlich lange E-Mail-Kopfzeile) —, damit
// beide Zeilentypen optisch gleich aussehen, statt einer kurzen Notiz neben
// einer langen englischen Datumszeile.
function datumFormatieren(eintrag) {
  const iso = eintrag.eml ? eintrag.eml.datumIso : eintrag.notiz?.datumIso
  if (iso) {
    const dt = new Date(iso)
    if (!Number.isNaN(dt.getTime())) {
      const zweistellig = (n) => String(n).padStart(2, '0')
      return `${dt.getFullYear()}-${zweistellig(dt.getMonth() + 1)}-${zweistellig(dt.getDate())} ${zweistellig(dt.getHours())}:${zweistellig(dt.getMinutes())}`
    }
  }
  return eintrag.eml ? eintrag.eml.datum : eintrag.notiz?.datum
}

// ─── Große Vorschau ─────────────────────────────────────────────────────
//
// Die Liste zeigt nur Namen und ein kleines Symbol — erst ein Klick öffnet
// die eigentliche, große Voransicht (DateiVorschau.vue, von der Detailseite
// über der ganzen Seite eingeblendet). Links (.url-Dateien) haben keine
// solche Vorschau, die öffnen direkt ihr Ziel (siehe Klick-Handler in der
// Dateizeile selbst).
function vorschauOeffnen(datei) {
  emit('vorschau-oeffnen', datei)
}


// ─── Datei/Notiz/Link aus der Ablage löschen ────────────────────────────

async function dateiLoeschen(datei) {
  const anzeigename = datei.notiz
    ? (datei.notiz.betreff || datei.notiz.art)
    : datei.url
      ? datei.name.replace(/\.url$/i, '')
      : datei.name
  if (!window.confirm(`"${anzeigename}" wirklich löschen? Das kann nicht rückgängig gemacht werden.`)) {
    return
  }
  emit('fehler', null)
  try {
    const antwort = await api.dateiLoeschen(props.id, datei.id)
    ablageUebernehmen(antwort)
    emit('datei-geloescht', datei.id)
  } catch (e) {
    emit('fehler', 'Löschen fehlgeschlagen: ' + e.message)
  }
}

function linkOeffnen(url) {
  window.open(url, '_blank', 'noopener')
}

// ─── Gesprächsverlauf: E-Mails und Notizen chronologisch, getrennt von den
// übrigen Dateien (Uploads, Links) ────────────────────────────────────────

const verlaufEintraege = computed(() =>
  props.dateien
    .filter((d) => d.eml || d.notiz)
    .slice()
    .sort((a, b) => {
      const da = (a.eml || a.notiz)?.datumIso || ''
      const db = (b.eml || b.notiz)?.datumIso || ''
      return db.localeCompare(da) // neueste zuerst
    }),
)
const urlEintraege = computed(() =>
  props.dateien
    .filter((d) => d.url)
    .slice()
    .sort((a, b) => (b.geaendert || 0) - (a.geaendert || 0)), // neueste zuerst
)
// Erst nach Kategorie gruppiert (Dokument, Bild, Sonstiges), innerhalb
// einer Kategorie nach Ablagedatum (neueste zuerst) — so stehen z. B. alle
// Fotos eines Tickets zusammen, statt mit Rechnungen und Verträgen bunt
// durchmischt in einer einzigen, nur nach Datum sortierten Liste zu stehen.
const weitereDateien = computed(() =>
  props.dateien
    .filter((d) => !d.eml && !d.notiz && !d.url)
    .slice()
    .sort((a, b) => {
      const ka = KATEGORIE_REIHENFOLGE[dateiKategorie(a)] ?? 9
      const kb = KATEGORIE_REIHENFOLGE[dateiKategorie(b)] ?? 9
      if (ka !== kb) return ka - kb
      return (b.geaendert || 0) - (a.geaendert || 0) // neueste (zuletzt hochgeladen/geändert) zuerst
    }),
)

// ─── Aus Posteingang zuordnen (experimentell, siehe mailApi.js) ─────────

const posteingangOffen = ref(false)
const postfachLaeuft = ref(false)
const postfachFehler = ref(null)
const postfachNachrichten = ref([])
const zuordnenLaeuftFuer = ref(null)

const postfachKonten = ref([])
const ausgewaehltesKontoId = ref(null)

async function posteingangOeffnen() {
  posteingangOffen.value = true
  postfachFehler.value = null
  postfachLaeuft.value = true
  postfachNachrichten.value = []
  try {
    // Konten nur beim ersten Öffnen laden, nicht bei jedem erneuten Klick.
    if (!postfachKonten.value.length) {
      postfachKonten.value = await mailApi.konten()
    }
    if (!postfachKonten.value.length) throw new Error('Kein Mail-Konto gefunden.')

    if (!ausgewaehltesKontoId.value) {
      ausgewaehltesKontoId.value = postfachKonten.value[0].id
    }
    await postfachNachrichtenLaden()
  } catch (e) {
    postfachFehler.value = e.message
    postfachLaeuft.value = false
  }
}

// Lädt die Nachrichten des GERADE ausgewählten Kontos — eigene Funktion,
// damit ein Kontowechsel (siehe @change im <select>) dieselbe Logik nutzen
// kann, ohne die Kontoliste jedes Mal neu abzufragen.
async function postfachNachrichtenLaden() {
  postfachFehler.value = null
  postfachLaeuft.value = true
  postfachNachrichten.value = []
  try {
    const postfaecher = await mailApi.postfaecher(ausgewaehltesKontoId.value)
    const posteingang = postfaecher.find((p) => (p.specialUse || []).includes('inbox'))
      || postfaecher.find((p) => /inbox|posteingang/i.test(p.name || p.displayName || ''))
      || postfaecher[0]
    if (!posteingang) throw new Error('Kein Postfach gefunden.')

    postfachNachrichten.value = await mailApi.nachrichten(posteingang.databaseId, 30)
  } catch (e) {
    postfachFehler.value = e.message
  } finally {
    postfachLaeuft.value = false
  }
}

function postfachAbsender(nachricht) {
  const eintrag = nachricht.from?.[0]
  return eintrag?.label || eintrag?.email || ''
}

async function nachrichtZuordnen(nachricht) {
  zuordnenLaeuftFuer.value = nachricht.databaseId
  emit('fehler', null)
  try {
    // "previewText" aus der Nachrichtenliste ist nur ein kurzer, oft
    // mitten im Wort abgeschnittener Anrisstext (für die Listenansicht
    // gedacht) — für den Gesprächsverlauf wird stattdessen der volle
    // Mailtext aus dem rohen Nachrichtenquelltext extrahiert. Schlägt das
    // fehl (z. B. unerwartetes MIME-Format), bleibt previewText als
    // Ausweichlösung, damit die Zuordnung trotzdem nicht ganz abbricht.
    let volltext = nachricht.previewText || ''
    try {
      const quelle = await mailApi.nachrichtQuelle(nachricht.databaseId)
      const geparst = mailTextAusQuelle(quelle)
      if (geparst) volltext = geparst
    } catch (e) {
      // Volltext nicht lesbar — Vorschautext bleibt als Ausweichlösung
    }

    const datumIso = nachricht.dateInt ? new Date(nachricht.dateInt * 1000).toISOString() : ''
    const antwort = await api.mailVerlaufAblegen(
      props.id,
      postfachAbsender(nachricht),
      nachricht.subject || '(kein Betreff)',
      volltext,
      datumIso,
      nachricht.messageId || '',
    )
    ablageUebernehmen(antwort)

    // Anhänge der E-Mail zusätzlich in die Ablage legen, einer nach dem
    // anderen (nicht parallel, damit bei vielen Anhängen nicht alles
    // gleichzeitig auf den Server drückt). Scheitert einer, soll das nicht
    // die bereits erfolgreich zugeordnete E-Mail rückgängig machen -
    // stattdessen einfach mit den übrigen weitermachen und am Ende auf den
    // gescheiterten hinweisen.
    const fehlgeschlageneAnhaenge = []
    for (const anhang of nachricht.attachments || []) {
      try {
        const blob = await mailApi.nachrichtAnhangHolen(anhang.downloadUrl)
        const datei = new File([blob], anhang.fileName || 'Anhang', { type: anhang.mime || blob.type })
        // Eigener Endpunkt statt api.dateiHochladen: benennt den Anhang nach
        // demselben "E-Mail {Datum} {Betreff}"-Schema wie den zugehörigen
        // Gesprächsverlauf-Eintrag, statt ihn unter seinem oft
        // unaussagekräftigen Originalnamen abzulegen.
        const anhangAntwort = await api.mailAnhangAblegen(props.id, nachricht.subject || '(kein Betreff)', datumIso, datei)
        ablageUebernehmen(anhangAntwort)
      } catch (e) {
        fehlgeschlageneAnhaenge.push(anhang.fileName || 'unbenannt')
      }
    }
    if (fehlgeschlageneAnhaenge.length) {
      emit('fehler', `E-Mail zugeordnet, aber diese Anhänge konnten nicht abgelegt werden: ${fehlgeschlageneAnhaenge.join(', ')}`)
    }

    posteingangOffen.value = false
  } catch (e) {
    emit('fehler', 'Zuordnung fehlgeschlagen: ' + e.message)
  } finally {
    zuordnenLaeuftFuer.value = null
  }
}

// ─── Link ablegen (z. B. Paperless-ngx) ─────────────────────────────────

const linkFormularOffen = ref(false)
const linkBezeichnung = ref('')
const linkUrl = ref('')
const linkSpeichernLaeuft = ref(false)

async function linkSpeichern() {
  if (!linkBezeichnung.value.trim() || !linkUrl.value.trim()) {
    emit('fehler', 'Bitte Bezeichnung und Adresse eintragen.')
    return
  }
  linkSpeichernLaeuft.value = true
  emit('fehler', null)
  try {
    const antwort = await api.urlAblegen(props.id, linkBezeichnung.value, linkUrl.value)
    ablageUebernehmen(antwort)
    linkBezeichnung.value = ''
    linkUrl.value = ''
    linkFormularOffen.value = false
  } catch (e) {
    emit('fehler', e.message)
  } finally {
    linkSpeichernLaeuft.value = false
  }
}

// ─── Notiz / Telefonat ablegen ───────────────────────────────────────────

const notizFormularOffen = ref(false)
const notizArt = ref('Telefonat')
const notizAnsprechpartner = ref('')
const notizText = ref('')
const notizSpeichernLaeuft = ref(false)

async function notizSpeichern() {
  if (!notizText.value.trim()) {
    emit('fehler', 'Bitte einen Notiztext eintragen.')
    return
  }
  notizSpeichernLaeuft.value = true
  emit('fehler', null)
  try {
    const antwort = await api.notizAblegen(props.id, notizArt.value, notizText.value, notizAnsprechpartner.value)
    ablageUebernehmen(antwort)
    notizText.value = ''
    notizAnsprechpartner.value = ''
    notizFormularOffen.value = false
  } catch (e) {
    emit('fehler', e.message)
  } finally {
    notizSpeichernLaeuft.value = false
  }
}

// ─── Schlagworte ─────────────────────────────────────────────────────────

const alleTagNamen = ref([])
// Welche Datei gerade ein offenes Eingabefeld für ein neues Schlagwort hat —
// nur eine gleichzeitig, sonst wird die Kachel-Liste unübersichtlich.
const tagEingabeFuer = ref(null)
const neuerTagName = ref('')
const tagLaeuft = ref(false)

onMounted(() => {
  api.alleTags().then((a) => { alleTagNamen.value = a.tags.map((t) => t.name) }).catch(() => {})
})

function tagEingabeOeffnen(dateiId) {
  tagEingabeFuer.value = dateiId
  neuerTagName.value = ''
}

function dateiInListe(dateiId) {
  return props.dateien.find((d) => d.id === dateiId)
}

async function tagHinzufuegen(dateiId) {
  if (!neuerTagName.value.trim()) { tagEingabeFuer.value = null; return }
  tagLaeuft.value = true
  emit('fehler', null)
  try {
    const antwort = await api.tagSetzen(dateiId, neuerTagName.value.trim())
    const d = dateiInListe(dateiId)
    if (d) d.tags = antwort.tags
    tagEingabeFuer.value = null
  } catch (e) {
    emit('fehler', e.message)
  } finally {
    tagLaeuft.value = false
  }
}

async function tagEntfernen(dateiId, tagId) {
  emit('fehler', null)
  try {
    const antwort = await api.tagEntfernen(dateiId, tagId)
    const d = dateiInListe(dateiId)
    if (d) d.tags = antwort.tags
  } catch (e) {
    emit('fehler', e.message)
  }
}
</script>

<template>
  <aside class="rb-karte rb-ablage-spalte">
    <h2 class="rb-kartentitel">Ablage</h2>

    <p v-if="ordnerFehler" class="rb-meldung rb-meldung-fehler">
      Ordner konnte nicht erstellt/gelesen werden: {{ ordnerFehler }}
    </p>
    <template v-else>
      <p class="rb-ordnerpfad">
        <span class="rb-gedaempft">Ordner:</span><br><code>{{ ordnerPfad }}</code>
      </p>
      <a :href="ordnerOeffnenUrl()" target="_blank" rel="noopener" class="rb-ordner-oeffnen">
        📁 Ordner in Dateien öffnen
      </a>

      <div class="rb-ablage-aktionen">
        <label class="rb-hochladen" :class="{ 'rb-hochladen-aktiv': hochladeLaeuft }">
          <span v-if="hochladeLaeuft"><span class="rb-spinner"></span> Wird hochgeladen …</span>
          <span v-else>+ Datei ablegen</span>
          <input ref="dateiInput" type="file" multiple :disabled="hochladeLaeuft" class="rb-datei-input" @change="dateiAusgewaehlt">
        </label>

        <button v-if="!linkFormularOffen" type="button" class="rb-hochladen" @click="linkFormularOffen = true">
          + Link ablegen
        </button>
        <div v-else class="rb-link-formular">
          <input v-model="linkBezeichnung" type="text" class="rb-eingabe" placeholder="Bezeichnung (z. B. Rechnung 2026-042)">
          <input v-model="linkUrl" type="url" class="rb-eingabe" placeholder="https://paperless.example.com/documents/...">
          <div class="rb-link-formular-aktionen">
            <button type="button" class="rb-knopf-sekundaer" @click="linkFormularOffen = false">Abbrechen</button>
            <button type="button" class="rb-knopf-primaer" :disabled="linkSpeichernLaeuft" @click="linkSpeichern">
              {{ linkSpeichernLaeuft ? 'Speichert …' : 'Speichern' }}
            </button>
          </div>
        </div>

        <button v-if="!notizFormularOffen" type="button" class="rb-hochladen" @click="notizFormularOffen = true">
          + Telefonat / Notiz
        </button>
        <div v-else class="rb-link-formular">
          <select v-model="notizArt" class="rb-eingabe">
            <option value="Telefonat">Telefonat</option>
            <option value="Notiz">Notiz</option>
          </select>
          <input v-model="notizAnsprechpartner" type="text" class="rb-eingabe" placeholder="Ansprechpartner (optional)">
          <textarea v-model="notizText" rows="4" class="rb-eingabe" placeholder="Was wurde besprochen?"></textarea>
          <div class="rb-link-formular-aktionen">
            <button type="button" class="rb-knopf-sekundaer" @click="notizFormularOffen = false">Abbrechen</button>
            <button type="button" class="rb-knopf-primaer" :disabled="notizSpeichernLaeuft" @click="notizSpeichern">
              {{ notizSpeichernLaeuft ? 'Speichert …' : 'Speichern' }}
            </button>
          </div>
        </div>

        <button v-if="!posteingangOffen" type="button" class="rb-hochladen" @click="posteingangOeffnen">
          📧 Aus Posteingang zuordnen
        </button>
        <div v-else class="rb-link-formular">
          <div class="rb-postfach-kopf">
            <span>Posteingang</span>
            <button type="button" class="rb-tag-entfernen" title="Schließen" @click="posteingangOffen = false">×</button>
          </div>
          <select
            v-if="postfachKonten.length > 1"
            v-model="ausgewaehltesKontoId"
            class="rb-eingabe"
            @change="postfachNachrichtenLaden"
          >
            <option v-for="k in postfachKonten" :key="k.id" :value="k.id">
              {{ k.name }} ({{ k.emailAddress }})
            </option>
          </select>
          <p v-if="postfachLaeuft" class="rb-gedaempft"><span class="rb-spinner"></span> Lädt …</p>
          <p v-else-if="postfachFehler" class="rb-meldung rb-meldung-fehler">{{ postfachFehler }}</p>
          <p v-else-if="!postfachNachrichten.length" class="rb-gedaempft">Keine Nachrichten gefunden.</p>
          <ul v-else class="rb-postfachliste">
            <li v-for="n in postfachNachrichten" :key="n.databaseId" class="rb-postfachzeile">
              <div class="rb-postfachzeile-info">
                <span class="rb-dateiname">
                  {{ n.subject || '(kein Betreff)' }}<span v-if="n.attachments && n.attachments.length" title="Hat Anhänge"> 📎</span>
                </span>
                <span class="rb-gedaempft rb-postfach-absender">{{ postfachAbsender(n) }}</span>
              </div>
              <button
                type="button"
                class="rb-knopf-sekundaer"
                :disabled="zuordnenLaeuftFuer === n.databaseId"
                @click="nachrichtZuordnen(n)"
              >
                {{ zuordnenLaeuftFuer === n.databaseId ? '…' : 'Zuordnen' }}
              </button>
            </li>
          </ul>
        </div>
      </div>

      <template v-if="urlEintraege.length">
        <h3 class="rb-unterueberschrift">Links</h3>
        <ul class="rb-dateiliste">
          <li v-for="d in urlEintraege" :key="d.id" class="rb-dateizeile">
            <div class="rb-dateizeile-reihe">
              <button
                type="button"
                class="rb-dateizeile-knopf"
                title="Link öffnen"
                @click="linkOeffnen(d.url)"
              >
                <span class="rb-dateisymbol-klein">🔗</span>
                <span class="rb-dateiname" :title="d.name">{{ d.name.replace(/\.url$/i, '') }}</span>
              </button>
              <button type="button" class="rb-datei-loeschen" title="Löschen" @click="dateiLoeschen(d)">🗑</button>
            </div>

            <div class="rb-tag-bereich">
              <span v-for="t in d.tags" :key="t.id" class="rb-tag-chip">
                {{ t.name }}
                <button type="button" class="rb-tag-entfernen" title="Schlagwort entfernen" @click="tagEntfernen(d.id, t.id)">×</button>
              </span>
              <span v-if="tagEingabeFuer === d.id" class="rb-tag-eingabe-zeile">
                <input
                  v-model="neuerTagName"
                  list="rb-tag-vorschlaege"
                  type="text"
                  class="rb-tag-eingabe"
                  placeholder="Schlagwort …"
                  autofocus
                  @keydown.enter="tagHinzufuegen(d.id)"
                  @keydown.esc="tagEingabeFuer = null"
                  @blur="tagHinzufuegen(d.id)"
                >
              </span>
              <button v-else type="button" class="rb-tag-hinzufuegen" @click="tagEingabeOeffnen(d.id)">+ Tag</button>
            </div>
          </li>
        </ul>
      </template>

      <template v-if="verlaufEintraege.length">
        <h3 class="rb-unterueberschrift">Gesprächsverlauf</h3>
        <ul class="rb-dateiliste">
          <li v-for="d in verlaufEintraege" :key="d.id" class="rb-dateizeile">
            <div class="rb-dateizeile-reihe">
              <button
                type="button"
                class="rb-dateizeile-knopf"
                title="Vorschau öffnen"
                @click="vorschauOeffnen(d)"
              >
                <span class="rb-dateisymbol-klein">{{ d.eml ? '✉️' : d.notiz.art === 'Telefonat' ? '📞' : d.notiz.art === 'E-Mail' ? '✉️' : '📝' }}</span>
                <span class="rb-dateiname" :title="d.name">
                  <template v-if="d.eml">{{ d.eml.betreff || d.name }}</template>
                  <template v-else-if="d.notiz.art === 'E-Mail'">{{ d.notiz.betreff }}</template>
                  <template v-else>{{ d.notiz.art }}<span v-if="d.notiz.ansprechpartner"> – {{ d.notiz.ansprechpartner }}</span></template>
                </span>
                <span class="rb-gedaempft rb-dateigroesse">{{ datumFormatieren(d) }}</span>
              </button>
              <button type="button" class="rb-datei-loeschen" title="Löschen" @click="dateiLoeschen(d)">🗑</button>
            </div>

            <div class="rb-tag-bereich">
              <span v-for="t in d.tags" :key="t.id" class="rb-tag-chip">
                {{ t.name }}
                <button type="button" class="rb-tag-entfernen" title="Schlagwort entfernen" @click="tagEntfernen(d.id, t.id)">×</button>
              </span>
              <span v-if="tagEingabeFuer === d.id" class="rb-tag-eingabe-zeile">
                <input
                  v-model="neuerTagName"
                  list="rb-tag-vorschlaege"
                  type="text"
                  class="rb-tag-eingabe"
                  placeholder="Schlagwort …"
                  autofocus
                  @keydown.enter="tagHinzufuegen(d.id)"
                  @keydown.esc="tagEingabeFuer = null"
                  @blur="tagHinzufuegen(d.id)"
                >
              </span>
              <button v-else type="button" class="rb-tag-hinzufuegen" @click="tagEingabeOeffnen(d.id)">+ Tag</button>
            </div>
          </li>
        </ul>
      </template>

      <h3 v-if="verlaufEintraege.length || urlEintraege.length" class="rb-unterueberschrift">Weitere Dateien</h3>
      <ul v-if="weitereDateien.length" class="rb-dateiliste">
        <li v-for="d in weitereDateien" :key="d.id" class="rb-dateizeile">
          <div class="rb-dateizeile-reihe">
            <button
              type="button"
              class="rb-dateizeile-knopf"
              :title="d.url ? 'Link öffnen' : 'Vorschau öffnen'"
              @click="d.url ? linkOeffnen(d.url) : vorschauOeffnen(d)"
            >
              <span class="rb-dateisymbol-klein">{{ d.url ? '🔗' : dateisymbol(d.mime) }}</span>
              <span class="rb-dateiname" :title="d.name">{{ d.url ? d.name.replace(/\.url$/i, '') : d.name }}</span>
              <span
                v-if="!d.url"
                class="rb-kategorie-chip"
                :class="'rb-kategorie-' + dateiKategorie(d).toLowerCase()"
              >{{ dateiKategorie(d) }}</span>
              <span v-if="!d.url" class="rb-gedaempft rb-dateispalte-groesse">{{ groesseFormatieren(d.groesse) }}</span>
              <span v-if="!d.url" class="rb-gedaempft rb-dateispalte-datum">{{ hochladeDatumFormatieren(d.geaendert) }}</span>
            </button>
            <button type="button" class="rb-datei-loeschen" title="Löschen" @click="dateiLoeschen(d)">🗑</button>
          </div>

          <div class="rb-tag-bereich">
            <span v-for="t in d.tags" :key="t.id" class="rb-tag-chip">
              {{ t.name }}
              <button type="button" class="rb-tag-entfernen" title="Schlagwort entfernen" @click="tagEntfernen(d.id, t.id)">×</button>
            </span>
            <span v-if="tagEingabeFuer === d.id" class="rb-tag-eingabe-zeile">
              <input
                v-model="neuerTagName"
                list="rb-tag-vorschlaege"
                type="text"
                class="rb-tag-eingabe"
                placeholder="Schlagwort …"
                autofocus
                @keydown.enter="tagHinzufuegen(d.id)"
                @keydown.esc="tagEingabeFuer = null"
                @blur="tagHinzufuegen(d.id)"
              >
            </span>
            <button v-else type="button" class="rb-tag-hinzufuegen" @click="tagEingabeOeffnen(d.id)">+ Tag</button>
          </div>
        </li>
      </ul>
      <p v-if="!verlaufEintraege.length && !weitereDateien.length && !urlEintraege.length" class="rb-gedaempft rb-keine-dateien">
        Noch keine Dateien in diesem Ordner.
      </p>

      <datalist id="rb-tag-vorschlaege">
        <option v-for="name in alleTagNamen" :key="name" :value="name"></option>
      </datalist>
    </template>
  </aside>
</template>

<style scoped>
.rb-layout > .rb-karte {
  /* Ohne das hier: eine einzelne lange, leerzeichenlose Zeichenkette
     (z. B. ein langer Dateiname) zwingt diese Spalte auf ihre
     Inhalts-Mindestbreite, egal was "1fr 1fr" eigentlich vorgibt — die
     rechte Spalte wird dann breiter als die Hälfte, das Layout läuft aus
     dem Bildschirm. min-width: 0 erlaubt dem Grid-Item, unter seine
     Inhalts-Mindestbreite zu schrumpfen, sodass stattdessen der Inhalt
     selbst umbricht/abgeschnitten wird (siehe .rb-dateiname). */
  min-width: 0;
}

.rb-karte {
  background: var(--color-main-background, #fff);
  border: 1px solid var(--color-border, #e0e0e3);
  border-radius: var(--border-radius-large, 10px);
  padding: 24px;
}

.rb-kartentitel {
  margin: 0 0 16px;
  font-size: 1.1em;
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

.rb-ordnerpfad {
  margin: 0 0 8px;
  font-size: 0.85em;
  line-height: 1.5;
  word-break: break-word;
}

.rb-ordnerpfad code {
  background: var(--color-background-hover, #f5f5f7);
  padding: 2px 6px;
  border-radius: 4px;
  display: inline-block;
  margin-top: 2px;
}

.rb-ordner-oeffnen {
  display: inline-block;
  margin-bottom: 16px;
  font-size: 0.85em;
  color: var(--color-primary-element, #0069c2);
  text-decoration: none;
}

.rb-ordner-oeffnen:hover {
  text-decoration: underline;
}

.rb-gedaempft {
  color: var(--color-text-maxcontrast, #767676);
}

/* Kompakte Liste statt Kacheln — die eigentliche Vorschau lebt jetzt im
   eigenen, größeren Fenster (siehe .rb-vorschau-overlay weiter unten). */
.rb-dateiliste {
  list-style: none;
  margin: 0 0 16px;
  padding: 0;
}

.rb-dateizeile {
  border-bottom: 1px solid var(--color-border, #eee);
  padding: 6px 0;
}

.rb-dateizeile:last-child {
  border-bottom: none;
}

.rb-dateizeile-reihe {
  display: flex;
  align-items: center;
  gap: 4px;
}

.rb-dateizeile-knopf {
  display: flex;
  /* flex-start statt center: bei einem auf zwei Zeilen umgebrochenen
     Dateinamen (siehe .rb-dateiname) sollen Symbol/Kategorie/Größe/Datum
     oben an der ersten Zeile ausgerichtet sein, nicht mittig über die
     ganze, jetzt höhere Zeile hinweg. */
  align-items: flex-start;
  gap: 8px;
  flex: 1;
  min-width: 0;
  background: none;
  border: none;
  padding: 4px 2px;
  cursor: pointer;
  text-align: left;
  border-radius: var(--border-radius, 6px);
}

.rb-dateizeile-knopf:hover {
  background: var(--color-background-hover, #f5f5f7);
}

.rb-datei-loeschen {
  flex-shrink: 0;
  background: none;
  border: none;
  cursor: pointer;
  padding: 4px 6px;
  border-radius: var(--border-radius, 6px);
  opacity: 0.5;
  font-size: 0.9em;
}

.rb-datei-loeschen:hover {
  opacity: 1;
  background: #fdecea;
}

.rb-dateisymbol-klein {
  font-size: 1.1em;
  flex-shrink: 0;
}

.rb-dateiname {
  font-size: 0.85em;
  color: var(--color-main-text, #222);
  flex: 1;
  /* min-width: 0 ist hier der eigentliche Kern des Fixes: ein Flex-Item
     ist standardmäßig mindestens so breit wie sein ungebrochener Inhalt
     (min-width: auto) — ohne das hier ignoriert der lange Dateiname jeden
     Umbruch/jede Ellipse und zieht die ganze Zeile (und damit die
     Ablage-Spalte, siehe .rb-layout > .rb-karte) in die Breite. */
  min-width: 0;
  /* Ab einer bestimmten Länge zwei Zeilen statt einer einzigen, beliebig
     breiten Zeile — bei noch längeren Namen sorgt line-clamp danach für
     eine Ellipse statt einer dritten Zeile. */
  display: -webkit-box;
  -webkit-line-clamp: 2;
  line-clamp: 2;
  -webkit-box-orient: vertical;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: normal;
  overflow-wrap: break-word;
  word-break: break-word;
  line-height: 1.3;
}

.rb-dateigroesse {
  font-size: 0.75em;
  flex-shrink: 0;
}

/* Feste Breite statt "so breit wie der Text" — sonst verschiebt sich
   Größe/Datum je nach Kategoriewort (DOKUMENT ist länger als BILD) und die
   Zeilen wirken nicht wie Spalten, sondern wie zufällig verrutschter Text.
   Dieselbe feste-Breite-Logik gilt für die beiden folgenden Spalten. */
.rb-kategorie-chip {
  flex-shrink: 0;
  width: 76px;
  text-align: center;
  font-size: 0.68em;
  font-weight: 600;
  text-transform: uppercase;
  letter-spacing: 0.03em;
  padding: 2px 4px;
  border-radius: 999px;
  white-space: nowrap;
}

.rb-dateispalte-groesse {
  flex-shrink: 0;
  width: 60px;
  text-align: right;
  font-size: 0.75em;
  font-variant-numeric: tabular-nums;
}

.rb-dateispalte-datum {
  flex-shrink: 0;
  width: 112px;
  text-align: right;
  font-size: 0.75em;
  font-variant-numeric: tabular-nums;
}

.rb-kategorie-dokument {
  background: rgba(0, 105, 194, 0.12);
  color: #0069c2;
}

.rb-kategorie-bild {
  background: rgba(46, 125, 50, 0.12);
  color: #2e7d32;
}

.rb-kategorie-sonstiges {
  background: var(--color-background-darker, #ededf0);
  color: var(--color-text-maxcontrast, #767676);
}

.rb-keine-dateien {
  margin: 0 0 16px;
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

.rb-postfach-kopf {
  display: flex;
  justify-content: space-between;
  align-items: center;
  font-weight: 600;
  margin-bottom: 8px;
}

.rb-postfachliste {
  list-style: none;
  margin: 0;
  padding: 0;
  max-height: 320px;
  overflow-y: auto;
}

.rb-postfachzeile {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 10px;
  padding: 8px 0;
  border-bottom: 1px solid var(--color-border, #eee);
}

.rb-postfachzeile:last-child {
  border-bottom: none;
}

.rb-postfachzeile-info {
  display: flex;
  flex-direction: column;
  gap: 2px;
  overflow: hidden;
  flex: 1;
}

.rb-postfachzeile-info .rb-dateiname {
  font-size: 0.85em;
}

.rb-postfach-absender {
  font-size: 0.75em;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.rb-tag-bereich {
  display: flex;
  flex-wrap: wrap;
  gap: 4px;
  align-items: center;
  margin-top: 2px;
}

.rb-tag-chip {
  display: inline-flex;
  align-items: center;
  gap: 2px;
  background: var(--color-primary-element-light, #e5f0fa);
  color: var(--color-main-text, #222);
  font-size: 0.7em;
  padding: 1px 4px 1px 8px;
  border-radius: 10px;
  line-height: 1.6;
}

.rb-tag-entfernen {
  background: none;
  border: none;
  color: inherit;
  cursor: pointer;
  font-size: 1.1em;
  line-height: 1;
  padding: 0 2px;
}

.rb-tag-hinzufuegen {
  background: none;
  border: 1px dashed var(--color-border-dark, #b8b8bb);
  border-radius: 10px;
  font-size: 0.7em;
  padding: 1px 8px;
  cursor: pointer;
  color: var(--color-text-maxcontrast, #767676);
}

.rb-tag-hinzufuegen:hover {
  border-color: var(--color-primary-element, #0069c2);
  color: var(--color-primary-element, #0069c2);
}

.rb-tag-eingabe {
  font-size: 0.75em;
  padding: 2px 6px;
  border-radius: 8px;
  border: 1px solid var(--color-border, #d8d8db);
  width: 100px;
}

.rb-ablage-aktionen {
  display: flex;
  flex-direction: column;
  gap: 8px;
  margin-top: 12px;
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

.rb-hochladen-aktiv {
  cursor: default;
  color: var(--color-text-maxcontrast, #767676);
}

.rb-datei-input {
  position: absolute;
  width: 1px;
  height: 1px;
  opacity: 0;
  overflow: hidden;
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
  margin-right: 4px;
}

/* Unterhalb dieser Breite reicht der Platz nicht mehr für zwei Spalten
   nebeneinander — die Ablage rutscht dann unter das Formular. */
@media (max-width: 640px) {
  .rb-karte {
    padding: 16px;
    border-radius: var(--border-radius, 6px);
  }
}

@keyframes rb-drehen {
  to { transform: rotate(360deg); }
}
</style>
