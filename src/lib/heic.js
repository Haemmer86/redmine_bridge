/**
 * HEIC/HEIF-Erkennung und -Umwandlung für Datei-Uploads.
 *
 * Eigenes Modul statt Duplikat je Ansicht (ursprünglich nur in
 * TicketDetail.vue, jetzt auch im Datei-Upload der Mitarbeiter-Ansicht
 * MeineTickets.vue gebraucht) — beide Stellen sollen bei einer künftigen
 * Änderung (z. B. Qualitätsstufe, Dateiendung) nicht einzeln gepflegt werden
 * müssen.
 */

// Erkennt HEIC/HEIF-Fotos (typisch: iPhone-Fotomediathek, nicht frisch mit
// der Kamera aufgenommen). Der Browser liefert dafür je nach Version einen
// leeren oder falschen MIME-Typ — deshalb zusätzlich über die Dateiendung
// geprüft.
export function istHeic(datei) {
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
export async function heicZuJpeg(datei) {
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
