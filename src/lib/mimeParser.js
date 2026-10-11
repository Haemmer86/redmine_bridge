/**
 * Wandelt Nextcloud Mails rohen MIME-Quelltext (siehe mailApi.nachrichtQuelle)
 * in den besten lesbaren Text-Teil um.
 *
 * Grund für einen eigenen, kleinen Parser statt einer fertigen Bibliothek:
 * wir brauchen nur EINEN Text-Teil aus der Nachricht (bevorzugt text/plain,
 * sonst text/html zu Text gewandelt) — kein vollständiges MIME-Objekt mit
 * allen Anhängen, Kopfzeilen usw. Anhänge selbst werden an anderer Stelle
 * (mailApi.nachrichtAnhangHolen, über die Anhangsliste der Nachricht) separat
 * abgelegt, hier interessiert nur der Text.
 *
 * Deckt die in der Praxis übliche Bandbreite ab (multipart/alternative und
 * multipart/mixed, quoted-printable und base64, gängige Zeichensätze über
 * TextDecoder) — kein vollständiger RFC-2045-Parser, aber für normale
 * Geschäfts-E-Mails ausreichend. Schlägt die Zerlegung fehl, liefert die
 * Funktion einen leeren String zurück, die aufrufende Stelle fällt dann auf
 * den kurzen Vorschautext zurück statt ganz abzubrechen.
 */

function kopfzeilenBlock(quelltext) {
  const treffer = quelltext.match(/\r?\n\r?\n/)
  if (!treffer) return { header: quelltext, body: '' }
  const index = quelltext.search(/\r?\n\r?\n/)
  return { header: quelltext.slice(0, index), body: quelltext.slice(index + treffer[0].length) }
}

function headerWert(headerBlock, name) {
  // Kopfzeilen können über mehrere Zeilen umgebrochen sein (jede
  // Folgezeile beginnt mit Leerraum) — das beim Zusammenfassen mit
  // einfangen, sonst reißt z. B. eine lange Content-Type-Zeile mit
  // boundary-Parameter mittendrin ab.
  const re = new RegExp(`^${name}:[ \\t]*(.+(?:\\r?\\n[ \\t]+.+)*)`, 'im')
  const treffer = headerBlock.match(re)
  if (!treffer) return null
  return treffer[1].replace(/\r?\n[ \t]+/g, ' ').trim()
}

function parameterAusHeader(wert, name) {
  if (!wert) return null
  const re = new RegExp(`${name}=("([^"]*)"|[^;\\s]+)`, 'i')
  const treffer = wert.match(re)
  if (!treffer) return null
  return (treffer[2] ?? treffer[1]).trim()
}

function quotedPrintableAlsBytes(text) {
  // Weiche Zeilenumbrüche (soft line breaks, "=" am Zeilenende) sind reine
  // Formatierung des Quelltexts und gehören nicht zum eigentlichen Inhalt.
  const bereinigt = text.replace(/=\r?\n/g, '')
  const bytes = []
  for (let i = 0; i < bereinigt.length; i++) {
    if (bereinigt[i] === '=' && /^[0-9A-Fa-f]{2}/.test(bereinigt.slice(i + 1, i + 3))) {
      bytes.push(parseInt(bereinigt.slice(i + 1, i + 3), 16))
      i += 2
    } else {
      bytes.push(bereinigt.charCodeAt(i) & 0xff)
    }
  }
  return new Uint8Array(bytes)
}

function bytesAlsText(bytes, charset) {
  try {
    return new TextDecoder(charset || 'utf-8').decode(bytes)
  } catch {
    // Unbekannter/nicht unterstützter Zeichensatz — UTF-8 ist die weitaus
    // häufigste Wahl und liefert für die meisten anderen Ein-Byte-Sätze
    // zumindest lesbaren (wenn auch nicht perfekten) Text statt eines Fehlers.
    return new TextDecoder('utf-8').decode(bytes)
  }
}

function teilInhaltDekodieren(rohtext, transferEncoding, charset) {
  const enc = (transferEncoding || '7bit').toLowerCase().trim()
  if (enc === 'base64') {
    const bereinigt = rohtext.replace(/[\r\n\s]/g, '')
    try {
      const binaer = atob(bereinigt)
      const bytes = Uint8Array.from(binaer, (c) => c.charCodeAt(0))
      return bytesAlsText(bytes, charset)
    } catch {
      return ''
    }
  }
  if (enc === 'quoted-printable') {
    return bytesAlsText(quotedPrintableAlsBytes(rohtext), charset)
  }
  return rohtext
}

function htmlZuText(html) {
  const ohneUnsichtbares = html
    .replace(/<style[\s\S]*?<\/style>/gi, '')
    .replace(/<script[\s\S]*?<\/script>/gi, '')
    .replace(/<br\s*\/?>/gi, '\n')
    .replace(/<\/p>/gi, '\n\n')
    .replace(/<\/(div|tr|li|h[1-6])>/gi, '\n')
    .replace(/<[^>]+>/g, '')
  // Textarea statt eines unsichtbaren DOM-Elements: dekodiert HTML-Entities
  // (&auml; usw.) zuverlässig, ohne dass der Browser den (bereits von Tags
  // befreiten) Inhalt irgendwo im sichtbaren Dokument rendert.
  const bereich = document.createElement('textarea')
  bereich.innerHTML = ohneUnsichtbares
  return bereich.value.replace(/\n{3,}/g, '\n\n').trim()
}

// Sucht rekursiv den besten lesbaren Text-Teil einer (ggf. mehrteiligen)
// MIME-Nachricht. Bevorzugt text/plain; bei multipart/alternative (Text
// UND HTML derselben Nachricht) wird trotzdem so lange weitergesucht, bis
// entweder ein text/plain-Teil gefunden wird oder alle Teile durch sind —
// erst dann wird ein gefundener HTML-Teil als Ausweichlösung verwendet.
function bestenTextTeilFinden(quelltext) {
  const { header, body } = kopfzeilenBlock(quelltext)
  const contentType = headerWert(header, 'Content-Type') || 'text/plain'
  const transferEncoding = headerWert(header, 'Content-Transfer-Encoding')
  const charset = parameterAusHeader(contentType, 'charset')

  if (/^multipart\//i.test(contentType)) {
    const boundary = parameterAusHeader(contentType, 'boundary')
    if (!boundary) return null
    const teile = body.split(`--${boundary}`).slice(1, -1)
    let htmlTreffer = null
    for (const teil of teile) {
      const bereinigterTeil = teil.replace(/^\r?\n/, '')
      const treffer = bestenTextTeilFinden(bereinigterTeil)
      if (!treffer) continue
      if (treffer.art === 'text') return treffer
      if (treffer.art === 'html' && !htmlTreffer) htmlTreffer = treffer
    }
    return htmlTreffer
  }

  if (/^text\/plain/i.test(contentType)) {
    return { art: 'text', inhalt: teilInhaltDekodieren(body, transferEncoding, charset) }
  }
  if (/^text\/html/i.test(contentType)) {
    return { art: 'html', inhalt: htmlZuText(teilInhaltDekodieren(body, transferEncoding, charset)) }
  }
  // Andere Content-Types (Anhänge usw.) sind hier nicht von Interesse.
  return null
}

/**
 * @param {string} quelltext Roher MIME-Quelltext, wie ihn
 *   mailApi.nachrichtQuelle() liefert.
 * @returns {string} Bester gefundener Text-Teil, oder leerer String, wenn
 *   sich keiner sicher extrahieren ließ.
 */
export function mailTextAusQuelle(quelltext) {
  if (!quelltext) return ''
  try {
    const treffer = bestenTextTeilFinden(quelltext)
    return treffer ? treffer.inhalt : ''
  } catch {
    return ''
  }
}
