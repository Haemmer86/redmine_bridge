<script setup>
import { ref, reactive, onMounted, onUnmounted, watch, computed } from 'vue'
import { api } from '../lib/api.js'
import { mailApi } from '../lib/mailApi.js'

const props = defineProps({
  id: { type: Number, required: true },
})

const ticket = ref(null)
const ordnerPfad = ref(null)
const ordnerFehler = ref(null)
const dateien = ref([])
const formulardaten = ref({ tracker: [], status: [], prioritaet: [], projekte: [] })
const mitglieder = ref([])
const ladend = ref(true)
const speichernLaeuft = ref(false)
const fehler = ref(null)
const gespeichertHinweis = ref(false)

// Die editierbaren Felder getrennt vom Original halten — sonst zeigt die
// Maske beim Tippen sofort einen "ungespeichert"-Zustand für Felder, die
// noch niemand angefasst hat, und ein Ladefehler könnte das Original
// überschreiben, während man mitten in der Eingabe ist.
const formular = reactive({
  subject: '',
  description: '',
  status_id: null,
  priority_id: null,
  tracker_id: null,
  assigned_to_id: null,
  start_date: '',
  due_date: '',
  done_ratio: 0,
  estimated_hours: null,
})

function uebernehmen(t) {
  formular.subject = t.subject || ''
  formular.description = t.description || ''
  formular.status_id = t.status?.id ?? null
  formular.priority_id = t.priority?.id ?? null
  formular.tracker_id = t.tracker?.id ?? null
  formular.assigned_to_id = t.assigned_to?.id ?? null
  formular.start_date = t.start_date || ''
  formular.due_date = t.due_date || ''
  formular.done_ratio = t.done_ratio ?? 0
  formular.estimated_hours = t.estimated_hours ?? null
}

async function laden() {
  ladend.value = true
  fehler.value = null
  try {
    const antwort = await api.ticket(props.id)
    ticket.value = antwort.ticket
    ordnerPfad.value = antwort.ordnerPfad
    ordnerFehler.value = antwort.ordnerFehler
    dateien.value = antwort.dateien
    uebernehmen(antwort.ticket)

    if (antwort.ticket?.project?.id) {
      api.mitglieder(antwort.ticket.project.id)
        .then((m) => { mitglieder.value = m.mitglieder })
        .catch(() => { mitglieder.value = [] })
    }
  } catch (e) {
    fehler.value = e.message
  } finally {
    ladend.value = false
  }
}

onMounted(async () => {
  laden()
  try {
    formulardaten.value = await api.formulardaten()
  } catch {
    // Ohne Formulardaten lassen sich die Auswahllisten nicht befüllen —
    // die Maske zeigt dann leere Dropdowns, ist aber nicht blockiert.
  }
})

watch(() => props.id, laden)

async function speichern() {
  speichernLaeuft.value = true
  fehler.value = null
  gespeichertHinweis.value = false
  try {
    const antwort = await api.ticketAktualisieren(props.id, { ...formular })
    ticket.value = antwort.ticket
    ordnerPfad.value = antwort.ordnerPfad
    ordnerFehler.value = antwort.ordnerFehler
    dateien.value = antwort.dateien
    uebernehmen(antwort.ticket)
    gespeichertHinweis.value = true
    setTimeout(() => { gespeichertHinweis.value = false }, 3000)
  } catch (e) {
    fehler.value = e.message
  } finally {
    speichernLaeuft.value = false
  }
}

// ─── Ist-Stunden erfassen (Redmines eigene Zeiterfassung) ───────────────

const istStundenAnzeige = computed(() => {
  const wert = ticket.value?.spent_hours
  return wert != null ? `${wert} h` : '0 h'
})

const zeiterfassungFormularOffen = ref(false)
const zeAktivitaeten = ref([])
const zeStunden = ref(null)
const zeAktivitaetId = ref(0)
const zeDatum = ref(new Date().toISOString().slice(0, 10))
const zeKommentar = ref('')
const zeSpeichernLaeuft = ref(false)
const zeFehler = ref(null)

async function zeiterfassungOeffnen() {
  zeiterfassungFormularOffen.value = true
  zeFehler.value = null
  // Aktivitäten nur beim ersten Öffnen laden, nicht bei jedem Klick erneut.
  if (zeAktivitaeten.value.length) return
  try {
    const antwort = await api.zeiterfassungAktivitaeten()
    zeAktivitaeten.value = antwort.aktivitaeten || []
  } catch (e) {
    // Liste bleibt einfach leer - Redmine akzeptiert die Zeiterfassung
    // notfalls auch ohne mitgeschickte Aktivität, je nach Konfiguration.
    zeAktivitaeten.value = []
  }
}

async function zeitErfassen() {
  if (!zeStunden.value || zeStunden.value <= 0) {
    zeFehler.value = 'Bitte eine Stundenzahl größer 0 eintragen.'
    return
  }
  zeSpeichernLaeuft.value = true
  zeFehler.value = null
  try {
    const antwort = await api.zeiterfassungAnlegen(
      props.id,
      zeStunden.value,
      zeAktivitaetId.value,
      zeKommentar.value,
      zeDatum.value,
    )
    ticket.value = antwort.ticket
    zeStunden.value = null
    zeKommentar.value = ''
    zeiterfassungFormularOffen.value = false
  } catch (e) {
    zeFehler.value = e.message
  } finally {
    zeSpeichernLaeuft.value = false
  }
}

// ─── Kommentare (Redmine-Journaleinträge) ───────────────────────────────
//
// Redmine liefert bei "include=journals" auch reine Feldänderungen als
// Journaleinträge mit (z. B. "Status geändert von X zu Y") - die haben
// aber einen leeren "notes"-Text. Nur Einträge mit tatsächlichem
// Kommentartext anzeigen, sonst wäre die Liste voller technischer
// Änderungsprotokolle statt echter Kommentare.
const kommentare = computed(() =>
  (ticket.value?.journals || []).filter((j) => j.notes && j.notes.trim() !== ''),
)

const neuerKommentar = ref('')
const kommentarSendenLaeuft = ref(false)

function kommentarDatumFormatieren(iso) {
  if (!iso) return ''
  const dt = new Date(iso)
  if (Number.isNaN(dt.getTime())) return iso
  const zweistellig = (n) => String(n).padStart(2, '0')
  return `${dt.getFullYear()}-${zweistellig(dt.getMonth() + 1)}-${zweistellig(dt.getDate())} ${zweistellig(dt.getHours())}:${zweistellig(dt.getMinutes())}`
}

async function kommentarSenden() {
  if (!neuerKommentar.value.trim()) return
  kommentarSendenLaeuft.value = true
  fehler.value = null
  try {
    // Nur "notes" mitschicken - Redmine ändert dann ausschließlich den
    // Kommentarverlauf, alle anderen Ticketfelder bleiben unangetastet.
    const antwort = await api.ticketAktualisieren(props.id, { notes: neuerKommentar.value })
    ticket.value = antwort.ticket
    neuerKommentar.value = ''
  } catch (e) {
    fehler.value = 'Kommentar senden fehlgeschlagen: ' + e.message
  } finally {
    kommentarSendenLaeuft.value = false
  }
}

const dateiInput = ref(null)
const hochladeLaeuft = ref(false)

// Erkennt HEIC/HEIF-Fotos (typisch: iPhone-Fotomediathek, nicht frisch mit
// der Kamera aufgenommen). Der Browser liefert dafür je nach Version einen
// leeren oder falschen MIME-Typ — deshalb zusätzlich über die Dateiendung
// geprüft.
function istHeic(datei) {
  const name = (datei.name || '').toLowerCase()
  const typ = (datei.type || '').toLowerCase()
  return name.endsWith('.heic') || name.endsWith('.heif') || typ === 'image/heic' || typ === 'image/heif'
}

// Wandelt eine HEIC/HEIF-Datei im Browser in ein JPEG um, BEVOR sie
// hochgeladen wird — Nextcloud kann daraus keine Vorschau erzeugen und die
// meisten Browser zeigen HEIC gar nicht erst an (Download statt Anzeige).
// Funktioniert dort, wo diese Dateien auch entstehen: Safari/WebKit
// (iPhone, Mac) kann HEIC über das Betriebssystem selbst dekodieren. Kann
// ein Browser HEIC nicht dekodieren, schlägt die Umwandlung lautlos fehl
// und die Originaldatei wird unverändert hochgeladen (heutiges Verhalten).
async function heicZuJpeg(datei) {
  const objektUrl = URL.createObjectURL(datei)
  try {
    const bild = await new Promise((resolve, reject) => {
      const img = new Image()
      img.onload = () => resolve(img)
      img.onerror = () => reject(new Error('Browser kann HEIC nicht dekodieren'))
      img.src = objektUrl
    })
    const canvas = document.createElement('canvas')
    canvas.width = bild.naturalWidth
    canvas.height = bild.naturalHeight
    canvas.getContext('2d').drawImage(bild, 0, 0)
    const blob = await new Promise((resolve, reject) => {
      canvas.toBlob((b) => (b ? resolve(b) : reject(new Error('Konvertierung fehlgeschlagen'))), 'image/jpeg', 0.9)
    })
    return new File([blob], datei.name.replace(/\.(heic|heif)$/i, '.jpg'), { type: 'image/jpeg' })
  } catch {
    return datei
  } finally {
    URL.revokeObjectURL(objektUrl)
  }
}

async function dateiAusgewaehlt(ereignis) {
  const ausgewaehlteDateien = Array.from(ereignis.target.files || [])
  if (!ausgewaehlteDateien.length) return
  hochladeLaeuft.value = true
  fehler.value = null
  const fehlgeschlagen = []
  try {
    // Nacheinander statt parallel — die API nimmt pro Aufruf genau eine
    // Datei entgegen, und der Ordner-Abgleich auf dem Server verträgt
    // sich nicht gut mit gleichzeitigen Anfragen für denselben Ordner.
    for (const datei of ausgewaehlteDateien) {
      try {
        const hochzuladendeDatei = istHeic(datei) ? await heicZuJpeg(datei) : datei
        const antwort = await api.dateiHochladen(props.id, hochzuladendeDatei)
        ordnerPfad.value = antwort.ordnerPfad
        ordnerFehler.value = antwort.ordnerFehler
        dateien.value = antwort.dateien
      } catch (e) {
        fehlgeschlagen.push(`${datei.name}: ${e.message}`)
      }
    }
    if (fehlgeschlagen.length) {
      fehler.value = 'Hochladen fehlgeschlagen bei ' + fehlgeschlagen.length + ' Datei(en) — ' + fehlgeschlagen.join('; ')
    }
  } finally {
    hochladeLaeuft.value = false
    if (dateiInput.value) dateiInput.value.value = ''
  }
}

function zurueck() {
  window.location.hash = ''
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
  if (!ordnerPfad.value) return '#'
  const ziel = '/' + ordnerPfad.value
  const basis = typeof window !== 'undefined' && window.OC && typeof window.OC.generateUrl === 'function'
    ? window.OC.generateUrl('/apps/files/files')
    : '/apps/files/files'
  return `${basis}?dir=${encodeURIComponent(ziel)}`
}

// Nextclouds eigener Vorschau-Dienst — derselbe, den auch die Dateien-App
// und Vorgänge nutzen. Braucht keinen eigenen Baustein: Bild einfach mit
// der Datei-ID anfragen, Nextcloud liefert eine passend zugeschnittene
// Voransicht (bei PDFs die erste Seite, bei Office-Dateien den Inhalt).
function vorschauUrl(dateiId, groesse = 256) {
  const pfad = `/core/preview?fileId=${dateiId}&x=${groesse}&y=${groesse}&a=1&mode=fit`
  if (typeof window !== 'undefined' && window.OC && typeof window.OC.generateUrl === 'function') {
    return window.OC.generateUrl(pfad)
  }
  return pfad
}

// Nextclouds "Direktlink" — leitet zur Datei an ihrem tatsächlichen Ort in
// der Dateien-App weiter, inklusive automatisch geöffnetem Betrachter.
function vorschauDirektUrl(dateiId) {
  const pfad = `/f/${dateiId}`
  if (typeof window !== 'undefined' && window.OC && typeof window.OC.generateUrl === 'function') {
    return window.OC.generateUrl(pfad)
  }
  return pfad
}

function dateisymbol(mime) {
  if (!mime) return '📄'
  if (mime.startsWith('image/')) return '🖼️'
  if (mime === 'application/pdf') return '📕'
  if (mime.includes('word')) return '📝'
  if (mime.includes('sheet') || mime.includes('excel')) return '📊'
  if (mime.includes('zip') || mime.includes('compressed')) return '🗜️'
  return '📄'
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

// ─── Große Vorschau, getrennt von der Liste ─────────────────────────────
//
// Die Liste zeigt nur Namen und ein kleines Symbol — erst ein Klick öffnet
// die eigentliche, große Voransicht in einem eigenen Fenster darüber.
// Links (.url-Dateien) haben keine solche Vorschau, die öffnen direkt ihr
// Ziel (siehe Klick-Handler in der Dateizeile selbst).

const vorschauDatei = ref(null)
const vorschauGrossFehler = ref(false)

function vorschauOeffnen(datei) {
  vorschauGrossFehler.value = false
  vorschauDatei.value = datei
}

function vorschauSchliessen() {
  vorschauDatei.value = null
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
  fehler.value = null
  try {
    const antwort = await api.dateiLoeschen(props.id, datei.id)
    ordnerPfad.value = antwort.ordnerPfad
    ordnerFehler.value = antwort.ordnerFehler
    dateien.value = antwort.dateien
    if (vorschauDatei.value?.id === datei.id) {
      vorschauSchliessen()
    }
  } catch (e) {
    fehler.value = 'Löschen fehlgeschlagen: ' + e.message
  }
}

function vorschauGrossFehlgeschlagen() {
  vorschauGrossFehler.value = true
}

function linkOeffnen(url) {
  window.open(url, '_blank', 'noopener')
}

function escTaste(ereignis) {
  if (ereignis.key === 'Escape' && vorschauDatei.value) vorschauSchliessen()
}
onMounted(() => window.addEventListener('keydown', escTaste))
onUnmounted(() => window.removeEventListener('keydown', escTaste))

// ─── Gesprächsverlauf: E-Mails und Notizen chronologisch, getrennt von den
// übrigen Dateien (Uploads, Links) ────────────────────────────────────────

const verlaufEintraege = computed(() =>
  dateien.value
    .filter((d) => d.eml || d.notiz)
    .slice()
    .sort((a, b) => {
      const da = (a.eml || a.notiz)?.datumIso || ''
      const db = (b.eml || b.notiz)?.datumIso || ''
      return db.localeCompare(da) // neueste zuerst
    }),
)
const urlEintraege = computed(() =>
  dateien.value
    .filter((d) => d.url)
    .slice()
    .sort((a, b) => (b.geaendert || 0) - (a.geaendert || 0)), // neueste zuerst
)
// Erst nach Kategorie gruppiert (Dokument, Bild, Sonstiges), innerhalb
// einer Kategorie nach Ablagedatum (neueste zuerst) — so stehen z. B. alle
// Fotos eines Tickets zusammen, statt mit Rechnungen und Verträgen bunt
// durchmischt in einer einzigen, nur nach Datum sortierten Liste zu stehen.
const weitereDateien = computed(() =>
  dateien.value
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
  fehler.value = null
  try {
    const datumIso = nachricht.dateInt ? new Date(nachricht.dateInt * 1000).toISOString() : ''
    const antwort = await api.mailVerlaufAblegen(
      props.id,
      postfachAbsender(nachricht),
      nachricht.subject || '(kein Betreff)',
      nachricht.previewText || '',
      datumIso,
      nachricht.messageId || '',
    )
    ordnerPfad.value = antwort.ordnerPfad
    ordnerFehler.value = antwort.ordnerFehler
    dateien.value = antwort.dateien

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
        ordnerPfad.value = anhangAntwort.ordnerPfad
        ordnerFehler.value = anhangAntwort.ordnerFehler
        dateien.value = anhangAntwort.dateien
      } catch (e) {
        fehlgeschlageneAnhaenge.push(anhang.fileName || 'unbenannt')
      }
    }
    if (fehlgeschlageneAnhaenge.length) {
      fehler.value = `E-Mail zugeordnet, aber diese Anhänge konnten nicht abgelegt werden: ${fehlgeschlageneAnhaenge.join(', ')}`
    }

    posteingangOffen.value = false
  } catch (e) {
    fehler.value = 'Zuordnung fehlgeschlagen: ' + e.message
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
    fehler.value = 'Bitte Bezeichnung und Adresse eintragen.'
    return
  }
  linkSpeichernLaeuft.value = true
  fehler.value = null
  try {
    const antwort = await api.urlAblegen(props.id, linkBezeichnung.value, linkUrl.value)
    ordnerPfad.value = antwort.ordnerPfad
    ordnerFehler.value = antwort.ordnerFehler
    dateien.value = antwort.dateien
    linkBezeichnung.value = ''
    linkUrl.value = ''
    linkFormularOffen.value = false
  } catch (e) {
    fehler.value = e.message
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
    fehler.value = 'Bitte einen Notiztext eintragen.'
    return
  }
  notizSpeichernLaeuft.value = true
  fehler.value = null
  try {
    const antwort = await api.notizAblegen(props.id, notizArt.value, notizText.value, notizAnsprechpartner.value)
    ordnerPfad.value = antwort.ordnerPfad
    ordnerFehler.value = antwort.ordnerFehler
    dateien.value = antwort.dateien
    notizText.value = ''
    notizAnsprechpartner.value = ''
    notizFormularOffen.value = false
  } catch (e) {
    fehler.value = e.message
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
  return dateien.value.find((d) => d.id === dateiId)
}

async function tagHinzufuegen(dateiId) {
  if (!neuerTagName.value.trim()) { tagEingabeFuer.value = null; return }
  tagLaeuft.value = true
  fehler.value = null
  try {
    const antwort = await api.tagSetzen(dateiId, neuerTagName.value.trim())
    const d = dateiInListe(dateiId)
    if (d) d.tags = antwort.tags
    tagEingabeFuer.value = null
  } catch (e) {
    fehler.value = e.message
  } finally {
    tagLaeuft.value = false
  }
}

async function tagEntfernen(dateiId, tagId) {
  fehler.value = null
  try {
    const antwort = await api.tagEntfernen(dateiId, tagId)
    const d = dateiInListe(dateiId)
    if (d) d.tags = antwort.tags
  } catch (e) {
    fehler.value = e.message
  }
}
</script>

<template>
  <div class="rb-seite">
    <a href="#" class="rb-zurueck" @click.prevent="zurueck">← Zurück zur Liste</a>

    <div v-if="ladend" class="rb-zustand">
      <span class="rb-spinner"></span> Ticket wird geladen …
    </div>
    <div v-else-if="fehler && !ticket" class="rb-zustand rb-zustand-fehler">
      Ticket konnte nicht geladen werden: {{ fehler }}
    </div>

    <template v-else-if="ticket">
      <header class="rb-kopf">
        <span class="rb-projektname">{{ ticket.project?.name }}</span>
        <h1>#{{ ticket.id }} {{ ticket.subject }}</h1>
      </header>

      <div v-if="fehler" class="rb-meldung rb-meldung-fehler">{{ fehler }}</div>
      <div v-if="gespeichertHinweis" class="rb-meldung rb-meldung-erfolg">Gespeichert.</div>

      <div class="rb-layout">
        <section class="rb-karte">
          <label class="rb-feld-breit">
            <span class="rb-label">Betreff</span>
            <input v-model="formular.subject" type="text" class="rb-eingabe">
          </label>

          <label class="rb-feld-breit">
            <span class="rb-label">Beschreibung</span>
            <textarea v-model="formular.description" rows="6" class="rb-eingabe"></textarea>
          </label>

          <div class="rb-raster">
            <label>
              <span class="rb-label">Tracker</span>
              <select v-model.number="formular.tracker_id" class="rb-eingabe">
                <option v-for="t in formulardaten.tracker" :key="t.id" :value="t.id">{{ t.name }}</option>
              </select>
            </label>
            <label>
              <span class="rb-label">Status</span>
              <select v-model.number="formular.status_id" class="rb-eingabe">
                <option v-for="s in formulardaten.status" :key="s.id" :value="s.id">{{ s.name }}</option>
              </select>
            </label>
            <label>
              <span class="rb-label">Priorität</span>
              <select v-model.number="formular.priority_id" class="rb-eingabe">
                <option v-for="p in formulardaten.prioritaet" :key="p.id" :value="p.id">{{ p.name }}</option>
              </select>
            </label>
            <label>
              <span class="rb-label">Zugewiesen an</span>
              <select v-model.number="formular.assigned_to_id" class="rb-eingabe">
                <option :value="null">— Niemand —</option>
                <option v-for="m in mitglieder" :key="m.id" :value="m.id">{{ m.name }}</option>
              </select>
            </label>
            <label>
              <span class="rb-label">Start</span>
              <input v-model="formular.start_date" type="date" class="rb-eingabe">
            </label>
            <label>
              <span class="rb-label">Fällig am</span>
              <input v-model="formular.due_date" type="date" class="rb-eingabe">
            </label>
            <label>
              <span class="rb-label">Fortschritt</span>
              <div class="rb-fortschritt-zeile">
                <input v-model.number="formular.done_ratio" type="range" min="0" max="100" step="10" class="rb-schieber">
                <span class="rb-gedaempft">{{ formular.done_ratio }} %</span>
              </div>
            </label>
            <label>
              <span class="rb-label">Geschätzte Stunden</span>
              <input v-model.number="formular.estimated_hours" type="number" min="0" step="0.5" class="rb-eingabe">
            </label>
          </div>

          <div class="rb-zeiterfassung">
            <div class="rb-zeiterfassung-kopf">
              <span class="rb-label">Ist-Stunden (Redmine-Zeiterfassung)</span>
              <strong>{{ istStundenAnzeige }}</strong>
            </div>

            <button v-if="!zeiterfassungFormularOffen" type="button" class="rb-hochladen" @click="zeiterfassungOeffnen">
              + Zeit erfassen
            </button>
            <div v-else class="rb-link-formular">
              <input v-model.number="zeStunden" type="number" min="0" step="0.25" class="rb-eingabe" placeholder="Stunden, z. B. 1.5">
              <select v-model.number="zeAktivitaetId" class="rb-eingabe">
                <option :value="0">Aktivität wählen …</option>
                <option v-for="a in zeAktivitaeten" :key="a.id" :value="a.id">{{ a.name }}</option>
              </select>
              <input v-model="zeDatum" type="date" class="rb-eingabe">
              <textarea v-model="zeKommentar" rows="2" class="rb-eingabe" placeholder="Kommentar (optional)"></textarea>
              <p v-if="zeFehler" class="rb-meldung rb-meldung-fehler">{{ zeFehler }}</p>
              <div class="rb-link-formular-aktionen">
                <button type="button" class="rb-knopf-sekundaer" @click="zeiterfassungFormularOffen = false">Abbrechen</button>
                <button type="button" class="rb-knopf-primaer" :disabled="zeSpeichernLaeuft" @click="zeitErfassen">
                  {{ zeSpeichernLaeuft ? 'Speichert …' : 'Erfassen' }}
                </button>
              </div>
            </div>
          </div>

          <div class="rb-aktionen">
            <button class="rb-knopf-primaer" :disabled="speichernLaeuft" @click="speichern">
              <span v-if="speichernLaeuft" class="rb-spinner rb-spinner-hell"></span>
              {{ speichernLaeuft ? 'Speichert …' : 'Speichern' }}
            </button>
          </div>

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
        </section>

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
      </div>

      <div v-if="vorschauDatei" class="rb-vorschau-overlay" @click.self="vorschauSchliessen" @keydown.esc="vorschauSchliessen">
        <div class="rb-vorschau-fenster">
          <header class="rb-vorschau-kopf">
            <span class="rb-vorschau-titel" :title="vorschauDatei.name">{{ vorschauDatei.name }}</span>
            <div class="rb-vorschau-kopf-aktionen">
              <a :href="vorschauDirektUrl(vorschauDatei.id)" target="_blank" rel="noopener" class="rb-vorschau-link-knopf">
                In Dateien öffnen ↗
              </a>
              <button type="button" class="rb-vorschau-schliessen" title="Schließen" @click="vorschauSchliessen">×</button>
            </div>
          </header>
          <div class="rb-vorschau-inhalt">
            <div v-if="vorschauDatei.notiz" class="rb-vorschau-text">
              <p class="rb-vorschau-notiz-kopf">
                <strong>{{ vorschauDatei.notiz.art === 'E-Mail' ? vorschauDatei.notiz.betreff : vorschauDatei.notiz.art }}</strong>
                <span v-if="vorschauDatei.notiz.datum" class="rb-gedaempft"> — {{ vorschauDatei.notiz.datum }}</span>
              </p>
              <p v-if="vorschauDatei.notiz.art === 'E-Mail'" class="rb-gedaempft">Von: {{ vorschauDatei.notiz.von }}</p>
              <p v-else-if="vorschauDatei.notiz.ansprechpartner" class="rb-gedaempft">
                Ansprechpartner: {{ vorschauDatei.notiz.ansprechpartner }}
              </p>
              <p class="rb-vorschau-notiztext">{{ vorschauDatei.notiz.text }}</p>
            </div>

            <div v-else-if="vorschauDatei.eml" class="rb-vorschau-text">
              <p><strong>Von:</strong> {{ vorschauDatei.eml.von || '—' }}</p>
              <p><strong>Betreff:</strong> {{ vorschauDatei.eml.betreff || '—' }}</p>
              <p><strong>Datum:</strong> {{ vorschauDatei.eml.datum || '—' }}</p>
              <p class="rb-gedaempft">Vollständige E-Mail über „In Dateien öffnen" ansehen.</p>
            </div>

            <template v-else>
              <img
                v-show="!vorschauGrossFehler"
                :src="vorschauUrl(vorschauDatei.id, 1400)"
                :alt="vorschauDatei.name"
                class="rb-vorschau-grossbild"
                @error="vorschauGrossFehlgeschlagen"
              >
              <p v-if="vorschauGrossFehler" class="rb-gedaempft">
                {{ dateisymbol(vorschauDatei.mime) }} Für diesen Dateityp gibt es keine Bildvorschau — über „In Dateien öffnen" ansehen.
              </p>
            </template>
          </div>
        </div>
      </div>
    </template>
  </div>
</template>

<style scoped>
.rb-seite {
  padding: 32px 40px;
  max-width: none;
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
.rb-projektname {
  display: block;
  font-size: 0.85em;
  color: #fff;
  text-shadow: 0 1px 3px rgba(0, 0, 0, 0.4);
  margin-bottom: 2px;
}
.rb-kopf h1 {
  margin: 0;
  font-size: 1.4em;
  color: #fff;
  text-shadow: 0 1px 3px rgba(0, 0, 0, 0.4);
}

.rb-layout {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 20px;
  align-items: start;
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

.rb-raster {
  display: grid;
  grid-template-columns: 1fr;
  gap: 16px;
}
.rb-raster label {
  display: flex;
  flex-direction: column;
  gap: 6px;
}
.rb-fortschritt-zeile {
  display: flex;
  align-items: center;
  gap: 10px;
}
.rb-schieber {
  flex: 1;
}

.rb-aktionen {
  margin-top: 20px;
  padding-top: 16px;
  border-top: 1px solid var(--color-border, #eee);
}

.rb-zeiterfassung {
  margin-top: 4px;
  margin-bottom: 16px;
}
.rb-zeiterfassung-kopf {
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin-bottom: 8px;
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
  align-items: center;
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
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
  flex: 1;
  color: var(--color-main-text, #222);
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
  margin-right: 4px;
}
.rb-spinner-hell {
  border-color: rgba(255, 255, 255, 0.4);
  border-top-color: #fff;
}
@keyframes rb-drehen {
  to { transform: rotate(360deg); }
}

/* Unterhalb dieser Breite reicht der Platz nicht mehr für zwei Spalten
   nebeneinander — die Ablage rutscht dann unter das Formular. */
@media (max-width: 640px) {
  .rb-seite {
    padding: 16px;
  }
  .rb-karte {
    padding: 16px;
    border-radius: var(--border-radius, 6px);
  }
  .rb-raster {
    grid-template-columns: 1fr;
  }
  .rb-layout {
    grid-template-columns: 1fr;
  }
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
/* Schlägt das Laden fehl (Dateityp ohne Bildvorschau), blendet Vue das
   Bild-Element über v-show aus — der Hinweistext übernimmt (siehe Template). */
</style>
