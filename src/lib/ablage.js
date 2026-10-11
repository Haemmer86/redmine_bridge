/**
 * Gemeinsame Helfer für die Ticket-Ablage (Dateiliste und große Vorschau).
 */

// Nextclouds eigener Vorschau-Dienst — derselbe, den auch die Dateien-App
// und Vorgänge nutzen. Braucht keinen eigenen Baustein: Bild einfach mit
// der Datei-ID anfragen, Nextcloud liefert eine passend zugeschnittene
// Voransicht (bei PDFs die erste Seite, bei Office-Dateien den Inhalt).
export function vorschauUrl(dateiId, groesse = 256) {
  const pfad = `/core/preview?fileId=${dateiId}&x=${groesse}&y=${groesse}&a=1&mode=fit`
  if (typeof window !== 'undefined' && window.OC && typeof window.OC.generateUrl === 'function') {
    return window.OC.generateUrl(pfad)
  }
  return pfad
}

// Nextclouds "Direktlink" — leitet zur Datei an ihrem tatsächlichen Ort in
// der Dateien-App weiter, inklusive automatisch geöffnetem Betrachter.
export function vorschauDirektUrl(dateiId) {
  const pfad = `/f/${dateiId}`
  if (typeof window !== 'undefined' && window.OC && typeof window.OC.generateUrl === 'function') {
    return window.OC.generateUrl(pfad)
  }
  return pfad
}

export function dateisymbol(mime) {
  if (!mime) return '📄'
  if (mime.startsWith('image/')) return '🖼️'
  if (mime === 'application/pdf') return '📕'
  if (mime.includes('word')) return '📝'
  if (mime.includes('sheet') || mime.includes('excel')) return '📊'
  if (mime.includes('zip') || mime.includes('compressed')) return '🗜️'
  return '📄'
}
