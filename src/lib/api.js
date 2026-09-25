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

export const api = {
  verbindung: () => anfrage('GET', '/api/verbindung'),
  tickets: (status = 'open', projekt = '', seite = 1) =>
    anfrage('GET', `/api/tickets?status=${encodeURIComponent(status)}&projekt=${encodeURIComponent(projekt)}&seite=${seite}`),
  ticket: (id) => anfrage('GET', `/api/tickets/${id}`),
  ticketAktualisieren: (id, felder) => anfrage('PUT', `/api/tickets/${id}`, { ticket: felder }),
  zeiterfassungAktivitaeten: () => anfrage('GET', '/api/zeiterfassung-aktivitaeten'),
  zeiterfassungAnlegen: (id, stunden, aktivitaetId, kommentar, datum) =>
    anfrage('POST', `/api/tickets/${id}/zeiterfassung`, { stunden, aktivitaetId, kommentar, datum }),
  ticketErstellen: (felder) => anfrage('POST', '/api/tickets', { ticket: felder }),
  projektErstellen: (felder) => anfrage('POST', '/api/projekte', { projekt: felder }),
  mitgliedHinzufuegen: (projektId, userId, rolleIds) =>
    anfrage('POST', `/api/projekte/${projektId}/mitglied-hinzufuegen`, { userId, rolleIds }),
  formulardaten: () => anfrage('GET', '/api/formulardaten'),
  mitglieder: (projektId) => anfrage('GET', `/api/projekte/${projektId}/mitglieder`),
  projekteGantt: () => anfrage('GET', '/api/projekte/gantt'),

  async dateiHochladen(id, datei) {
    const formular = new FormData()
    formular.append('datei', datei)
    const antwort = await fetch(url(`/api/tickets/${id}/dateien`), {
      method: 'POST',
      headers: { requesttoken: requestToken() },
      body: formular,
    })
    const daten = await antwort.json().catch(() => ({}))
    if (!antwort.ok || daten.fehler) {
      throw new Error(daten.fehler || `Hochladen fehlgeschlagen (${antwort.status})`)
    }
    return daten
  },

  async mailAnhangAblegen(id, betreff, datum, datei) {
    const formular = new FormData()
    formular.append('betreff', betreff || '')
    formular.append('datum', datum || '')
    formular.append('datei', datei)
    const antwort = await fetch(url(`/api/tickets/${id}/mail-anhang`), {
      method: 'POST',
      headers: { requesttoken: requestToken() },
      body: formular,
    })
    const daten = await antwort.json().catch(() => ({}))
    if (!antwort.ok || daten.fehler) {
      throw new Error(daten.fehler || `Hochladen fehlgeschlagen (${antwort.status})`)
    }
    return daten
  },

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
