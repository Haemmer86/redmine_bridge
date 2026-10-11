/**
 * Kleiner Fetch-Wrapper für die eigene API.
 *
 * Nutzt `OC.generateUrl`, wenn verfügbar (korrekt auch bei Nextcloud in
 * einem Unterverzeichnis), sonst einen relativen Pfad als Ausweichlösung
 * für lokale Entwicklung außerhalb von Nextcloud.
 */
function url(pfad) {
  if (typeof window !== 'undefined' && window.OC && typeof window.OC.generateUrl === 'function') {
    return window.OC.generateUrl('/apps/redmine_bridge' + pfad)
  }
  return '/apps/redmine_bridge' + pfad
}

function requestToken() {
  return typeof window !== 'undefined' && window.OC ? window.OC.requestToken : ''
}

async function anfrage(methode, pfad, rumpf) {
  const optionen = {
    method: methode,
    headers: { Accept: 'application/json' },
  }
  // Nur bei verändernden Methoden nötig — GET braucht kein CSRF-Token,
  // und ein unnötig gesetzter Kopfeintrag stört zwar nicht, aber warum.
  if (methode !== 'GET') {
    optionen.headers.requesttoken = requestToken()
  }
  if (rumpf !== undefined) {
    optionen.headers['Content-Type'] = 'application/json'
    optionen.body = JSON.stringify(rumpf)
  }

  const antwort = await fetch(url(pfad), optionen)
  const daten = await antwort.json().catch(() => ({}))
  if (!antwort.ok || daten.fehler) {
    throw new Error(daten.fehler || `Anfrage fehlgeschlagen (${antwort.status})`)
  }

  return daten
}

// Datei-Upload (multipart/form-data) — das Content-Type-Kopfzeile setzt der
// Browser selbst samt Trennmarke, deshalb hier bewusst nicht von Hand.
async function hochladen(pfad, felder) {
  const formular = new FormData()
  for (const [name, wert] of Object.entries(felder)) {
    formular.append(name, wert ?? '')
  }
  const antwort = await fetch(url(pfad), {
    method: 'POST',
    headers: { requesttoken: requestToken() },
    body: formular,
  })
  const daten = await antwort.json().catch(() => ({}))
  if (!antwort.ok || daten.fehler) {
    throw new Error(daten.fehler || `Hochladen fehlgeschlagen (${antwort.status})`)
  }
  return daten
}

export const api = {
  sitzung: () => anfrage('GET', '/api/sitzung'),
  mitarbeiterListe: () => anfrage('GET', '/api/mitarbeiter-liste'),
  meineTickets: (status = 'open') => anfrage('GET', `/api/meine-tickets?status=${encodeURIComponent(status)}`),
  meineTicketsStatusListe: () => anfrage('GET', '/api/meine-tickets/status-liste'),
  meinTicketAktualisieren: (id, felder) => anfrage('PUT', `/api/meine-tickets/${id}`, felder),

  meinTicketDateiHochladen: (id, datei) => hochladen(`/api/meine-tickets/${id}/dateien`, { datei }),
  tickets: (status = 'open', projekt = '', seite = 1, nurUnfakturiert = false, sortierung = 'geaendert') =>
    anfrage('GET', `/api/tickets?status=${encodeURIComponent(status)}&projekt=${encodeURIComponent(projekt)}&seite=${seite}&nurUnfakturiert=${nurUnfakturiert ? 1 : 0}&sortierung=${encodeURIComponent(sortierung)}`),
  ticket: (id) => anfrage('GET', `/api/tickets/${id}`),
  ticketAktualisieren: (id, felder) => anfrage('PUT', `/api/tickets/${id}`, { ticket: felder }),
  zustaendigerMitarbeiterSetzen: (id, nextcloudUserId) =>
    anfrage('PUT', `/api/tickets/${id}/zustaendiger-mitarbeiter`, { nextcloudUserId }),
  rechnungsstatusSetzen: (id, felder) => anfrage('PUT', `/api/tickets/${id}/rechnungsstatus`, felder),
  rechnungsdatenSetzen: (id, felder) => anfrage('PUT', `/api/tickets/${id}/rechnungsdaten`, felder),
  zeiterfassungAktivitaeten: () => anfrage('GET', '/api/zeiterfassung-aktivitaeten'),
  zeiterfassungAnlegen: (id, stunden, aktivitaetId, kommentar, datum) =>
    anfrage('POST', `/api/tickets/${id}/zeiterfassung`, { stunden, aktivitaetId, kommentar, datum }),
  ticketErstellen: (felder, rechnungspflichtig = true, rechnungsdaten = {}, zustaendigerMitarbeiter = null) =>
    anfrage('POST', '/api/tickets', { ticket: felder, rechnungspflichtig, zustaendigerMitarbeiter, ...rechnungsdaten }),
  projektErstellen: (felder) => anfrage('POST', '/api/projekte', { projekt: felder }),
  mitgliedHinzufuegen: (projektId, userId, rolleIds) =>
    anfrage('POST', `/api/projekte/${projektId}/mitglied-hinzufuegen`, { userId, rolleIds }),
  formulardaten: () => anfrage('GET', '/api/formulardaten'),
  mitglieder: (projektId) => anfrage('GET', `/api/projekte/${projektId}/mitglieder`),
  projekteGantt: () => anfrage('GET', '/api/projekte/gantt'),
  projektAbschliessenVorschau: (projektId) => anfrage('GET', `/api/projekte/${projektId}/abschliessen-vorschau`),
  projektAbschliessen: (projektId, erledigtStatusId) =>
    anfrage('PUT', `/api/projekte/${projektId}/abschliessen`, { erledigtStatusId }),

  dateiHochladen: (id, datei) => hochladen(`/api/tickets/${id}/dateien`, { datei }),

  mailAnhangAblegen: (id, betreff, datum, datei) =>
    hochladen(`/api/tickets/${id}/mail-anhang`, { betreff, datum, datei }),

  urlAblegen: (id, bezeichnung, url) => anfrage('POST', `/api/tickets/${id}/urls`, { bezeichnung, url }),
  notizAblegen: (id, art, text, ansprechpartner) => anfrage('POST', `/api/tickets/${id}/notizen`, { art, text, ansprechpartner }),
  mailVerlaufAblegen: (id, von, betreff, text, datum, nachrichtKennung) =>
    anfrage('POST', `/api/tickets/${id}/mail-verlauf`, { von, betreff, text, datum, nachrichtKennung }),
  dateiLoeschen: (id, dateiId) => anfrage('DELETE', `/api/tickets/${id}/dateien/${dateiId}`),
  alleTags: () => anfrage('GET', '/api/tags'),
  odooKunden: (suche = '') => anfrage('GET', `/api/odoo/kunden?suche=${encodeURIComponent(suche)}`),
  odooArtikel: (suche = '') => anfrage('GET', `/api/odoo/artikel?suche=${encodeURIComponent(suche)}`),
  odooSammelrechnung: (partnerId, positionen) =>
    anfrage('POST', '/api/odoo/sammelrechnung', { partnerId, positionen }),
  tagSetzen: (dateiId, name) => anfrage('POST', `/api/dateien/${dateiId}/tags`, { name }),
  tagEntfernen: (dateiId, tagId) => anfrage('DELETE', `/api/dateien/${dateiId}/tags/${tagId}`),
}
