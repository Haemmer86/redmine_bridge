/**
 * Kleine Anzeige-Helfer, die mehrere Ansichten brauchen.
 */

// "2026-10-01 10:00" aus einem ISO-Zeitstempel (Ortszeit); ein nicht
// lesbarer Wert kommt unverändert zurück, ein leerer als leerer Text.
export function datumUhrzeit(iso) {
  if (!iso) return ''
  const dt = new Date(iso)
  if (Number.isNaN(dt.getTime())) return iso
  const zweistellig = (n) => String(n).padStart(2, '0')
  return `${dt.getFullYear()}-${zweistellig(dt.getMonth() + 1)}-${zweistellig(dt.getDate())} ${zweistellig(dt.getHours())}:${zweistellig(dt.getMinutes())}`
}
