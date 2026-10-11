/**
 * Experimentelle Anbindung an Nextclouds eigene Mail-App.
 *
 * ⚠️ Nutzt KEINE offiziell dokumentierte Schnittstelle — die Mail-App bietet
 * (Stand heute) keine öffentliche OCP-API zum Lesen des Posteingangs, nur
 * zum Versenden. Die hier verwendeten Adressen sind aus der eigenen
 * Oberfläche der Mail-App abgeleitet und können sich bei einem Update der
 * Mail-App ändern, ohne Vorwarnung.
 *
 * Bewusst als eigene Datei, getrennt von api.js (unsere eigene, stabile
 * Redmine-Anbindung) — ein Fehlschlag hier darf nie den Rest der App
 * beeinträchtigen.
 */
function url(pfad) {
  if (typeof window !== 'undefined' && window.OC && typeof window.OC.generateUrl === 'function') {
    return window.OC.generateUrl(pfad)
  }
  return pfad
}

async function holen(pfad, roherText = false) {
  const antwort = await fetch(url(pfad), {
    headers: {
      Accept: roherText ? '*/*' : 'application/json',
      // Nextclouds interne Apps markieren eigene API-Aufrufe damit — ohne
      // diesen Kopfeintrag antwortet die Mail-App mit 412 (Precondition
      // Failed), als Schutz gegen fremde Webseiten, die dieselbe Adresse
      // im Hintergrund aufrufen könnten.
      'OCS-APIRequest': 'true',
    },
  })
  if (!antwort.ok) {
    throw new Error(`Mail-App antwortete mit Fehler ${antwort.status} auf ${pfad}`)
  }
  return roherText ? antwort.text() : antwort.json()
}

// Nextclouds Mail-App liefert Listen mal als reines Array, mal verpackt in
// ein Objekt mit einem benannten Schlüssel — beide Formen abfangen, statt
// bei der "falschen" Form sofort mit einem JS-Fehler abzubrechen.
function alsListe(antwort, schluessel) {
  if (Array.isArray(antwort)) return antwort
  if (antwort && Array.isArray(antwort[schluessel])) return antwort[schluessel]
  return []
}

export const mailApi = {
  konten: () => holen('/apps/mail/api/accounts').then((a) => alsListe(a, 'accounts')),
  postfaecher: (kontoId) => holen(`/apps/mail/api/mailboxes?accountId=${kontoId}`).then((a) => alsListe(a, 'mailboxes')),
  nachrichten: (postfachId, limit = 30) =>
    holen(`/apps/mail/api/messages?mailboxId=${postfachId}&limit=${limit}`).then((a) => alsListe(a, 'messages')),
  // Der rohe MIME-Quelltext der Nachricht — genau das Format, das eine
  // .eml-Datei erwartet.
  //
  // Die Mail-App liefert hier trotz "roherText" KEINEN reinen Text, sondern
  // ein JSON-Objekt {"source": "..."} — deshalb hier auspacken. Ohne das
  // landet der komplette JSON-String (eine einzige Zeile ohne echte
  // Zeilenumbrüche, da JSON \r\n nur als zwei Zeichen escaped statt als
  // echtes CRLF) im MIME-Parser, der dann mangels erkennbarer Kopfzeilen
  // leer zurückfällt — genau der Fehler, der den kurzen Vorschautext statt
  // des vollständigen Mailtexts übrig ließ.
  async nachrichtQuelle(nachrichtId) {
    const roh = await holen(`/apps/mail/api/messages/${nachrichtId}/source`, true)
    try {
      const eingepackt = JSON.parse(roh)
      if (eingepackt && typeof eingepackt.source === 'string') {
        return eingepackt.source
      }
    } catch {
      // Antwort war doch bereits reiner Text (z. B. bei einer künftigen
      // Änderung der Mail-App) — dann direkt verwenden statt abzubrechen.
    }
    return roh
  },

  // Anhänge kommen bereits mit vollständiger, direkt aufrufbarer Adresse aus
  // der Nachrichtenliste (Feld "downloadUrl") - hier reicht ein einfacher
  // fetch() mit demselben Sicherheits-Kopfeintrag wie bei allen anderen
  // Mail-App-Aufrufen.
  async nachrichtAnhangHolen(downloadUrl) {
    const antwort = await fetch(downloadUrl, {
      headers: { 'OCS-APIRequest': 'true' },
    })
    if (!antwort.ok) {
      throw new Error(`Anhang-Download fehlgeschlagen (${antwort.status})`)
    }
    return antwort.blob()
  },
}
