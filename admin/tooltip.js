/**
 * Spielbank Events – Tooltip System
 *
 * Reads data-sbe-tip attributes and injects ⓘ icons after form labels.
 * Renders a smart-positioned floating bubble with schema.org context.
 */
(function () {
  'use strict';

  // ── Tooltip definitions ────────────────────────────────────────────────────
  // Key = data-sbe-tip value  →  { title, text, schema[] }
  var TIPS = {

    /* ── TOOLBAR BUTTONS ── */
    'btn-scrape': {
      title: 'Jetzt scrapen',
      text:  'Startet den automatischen Scraper. Er prüft zunächst robots.txt jeder Quelle, lädt dann die Events-Seite, extrahiert JSON-LD (falls vorhanden), Öffnungszeiten und Event-Daten und speichert alles in der Datenbank mit Status „Gescrapt".',
      schema: []
    },
    'btn-approve-all': {
      title: 'Alle genehmigen',
      text:  'Setzt alle Events mit Status „Gescrapt" auf „Genehmigt". Nutze das, um nach einem Scrape-Lauf alle neuen Daten zu bestätigen, bevor sie veröffentlicht werden.',
      schema: []
    },
    'btn-publish': {
      title: 'Auf /events/ veröffentlichen',
      text:  'Setzt alle „Genehmigten" Events auf „Veröffentlicht". Erst dann erscheinen sie im Shortcode [spielbank_events] auf deiner Website. Nicht genehmigte Events bleiben verborgen.',
      schema: []
    },
    'btn-add-event': {
      title: 'Event manuell hinzufügen',
      text:  'Öffnet das Formular zum Anlegen eines neuen Events ohne Scraping – z.B. für eigene Veranstaltungen oder Korrekturen.',
      schema: []
    },
    'btn-add-location': {
      title: 'Standort hinzufügen',
      text:  'Öffnet das Formular zum Anlegen eines neuen Spielbank-Standorts mit allen LocalBusiness-, GamblingResort- und OpeningHoursSpecification-Feldern.',
      schema: []
    },

    /* ── STATS ── */
    'stat-total': {
      title: 'Events gesamt',
      text:  'Gesamtzahl aller Events in der Datenbank – unabhängig vom Status. Enthält Entwürfe, gescrapte, genehmigte und veröffentlichte Events.',
      schema: []
    },
    'stat-scraped': {
      title: 'Neu gescrapt',
      text:  'Events, die der Scraper gefunden und importiert hat, aber noch nicht manuell geprüft wurden. Sie sind auf der Website noch nicht sichtbar.',
      schema: []
    },
    'stat-approved': {
      title: 'Genehmigt',
      text:  'Events, die du geprüft und genehmigt hast. Sie sind bereit zur Veröffentlichung, erscheinen aber erst nach dem Klick auf „Veröffentlichen" live.',
      schema: []
    },
    'stat-published': {
      title: 'Veröffentlicht',
      text:  'Events, die live auf der /events/-Seite sichtbar sind. Sie werden im Shortcode ausgegeben und als schema.org/Event im JSON-LD eingefügt.',
      schema: ['event']
    },
    'stat-locs': {
      title: 'Standorte',
      text:  'Anzahl der Spielbank-Standorte in der Datenbank. Jeder Standort erzeugt einen GamblingResort/LocalBusiness-Eintrag im JSON-LD.',
      schema: ['local', 'gambling']
    },

    /* ── TABS ── */
    'tab-events': {
      title: 'Events-Tab',
      text:  'Verwaltung aller Veranstaltungen. Hier kannst du gescrapte Events prüfen, bearbeiten, genehmigen, veröffentlichen oder löschen.',
      schema: ['event']
    },
    'tab-locations': {
      title: 'Standorte & Öffnungszeiten',
      text:  'Verwaltung der Spielbank-Standorte mit vollständigen LocalBusiness-, GamblingResort- und OpeningHoursSpecification-Daten für Google Rich Results.',
      schema: ['local', 'gambling', 'hours']
    },

    /* ── SCHEMA BADGES ── */
    'schema-local': {
      title: 'schema.org/LocalBusiness',
      text:  'Basistyp für Unternehmen. Enthält Name, Adresse, Telefon, Öffnungszeiten, Bewertung. Wird von Google für Knowledge Panels und Local Search ausgewertet.',
      schema: ['local']
    },
    'schema-gambling': {
      title: 'schema.org/GamblingResort',
      text:  'Spezialisierter Untertyp von LocalBusiness für Casinos und Spielbanken. Erbt alle LocalBusiness-Felder und signalisiert Google den spezifischen Geschäftstyp.',
      schema: ['gambling']
    },
    'schema-hours': {
      title: 'schema.org/OpeningHoursSpecification',
      text:  'Strukturierte Öffnungszeiten pro Wochentag mit opens/closes-Zeiten. Ermöglicht die Anzeige der Öffnungszeiten direkt in den Google-Suchergebnissen.',
      schema: ['hours']
    },

    /* ── TABLE HEADERS: EVENTS ── */
    'th-event-name': {
      title: 'Event-Name',
      text:  'Der vollständige Name der Veranstaltung. Wird als schema.org/Event → name ausgegeben.',
      schema: ['event']
    },
    'th-event-date': {
      title: 'Datum',
      text:  'Startdatum und -uhrzeit des Events. Entspricht schema.org/Event → startDate im ISO 8601-Format.',
      schema: ['event']
    },
    'th-event-city': {
      title: 'Ort/Stadt',
      text:  'Die Stadt, in der das Event stattfindet. Fließt in schema.org/Event → location → PostalAddress → addressLocality ein.',
      schema: ['event']
    },
    'th-event-type': {
      title: 'Event-Typ',
      text:  'Interne Klassifikation (Poker, Roulette etc.) für die Filter-Tabs auf der Website. Wird nicht direkt im schema.org ausgegeben.',
      schema: []
    },
    'th-event-source': {
      title: 'Quelle',
      text:  'Die Website, von der dieses Event gescrapt wurde (z.B. spielbank-berlin.de), oder „manual" für manuell angelegte Events.',
      schema: []
    },
    'th-event-status': {
      title: 'Status',
      text:  'Workflow-Status: Gescrapt → Genehmigt → Veröffentlicht. Nur veröffentlichte Events erscheinen im Shortcode und im JSON-LD.',
      schema: []
    },

    /* ── TABLE HEADERS: LOCATIONS ── */
    'th-loc-name':  { title: 'Standortname', text: 'Vollständiger Name der Spielbank inklusive Standortbezeichnung. Wird als schema.org/GamblingResort → name ausgegeben.', schema: ['gambling'] },
    'th-loc-city':  { title: 'Stadt', text: 'Ort des Standorts (addressLocality). Teil der PostalAddress in LocalBusiness und GamblingResort.', schema: ['local'] },
    'th-loc-plz':   { title: 'Postleitzahl', text: 'Postleitzahl des Standorts (postalCode). Wichtig für Local SEO und Google Maps-Zuordnung.', schema: ['local'] },
    'th-loc-tel':   { title: 'Telefon', text: 'Telefonnummer im internationalen Format (+49...). Wird als schema.org/LocalBusiness → telephone ausgegeben.', schema: ['local'] },
    'th-loc-rating':{ title: 'Bewertung', text: 'Durchschnittliche Sternebewertung (aggregateRating → ratingValue). Wird in Google-Suchergebnissen als Sterne-Snippet angezeigt.', schema: ['local'] },
    'th-loc-price': { title: 'Preisklasse', text: 'Preisniveau in €-Symbolen (z.B. €€€). Entspricht schema.org/LocalBusiness → priceRange.', schema: ['local'] },
    'th-loc-hours': { title: 'Öffnungszeiten', text: 'Anzahl der hinterlegten Wochentage mit Öffnungszeiten. Jeder Tag ist eine OpeningHoursSpecification.', schema: ['hours'] },

    /* ── ROW ACTIONS ── */
    'action-edit-event':     { title: 'Event bearbeiten', text: 'Öffnet das Bearbeitungsformular für dieses Event. Alle schema.org-relevanten Felder sind editierbar.', schema: [] },
    'action-approve':        { title: 'Event genehmigen', text: 'Setzt den Status auf „Genehmigt". Danach kann das Event per „Veröffentlichen" live geschaltet werden.', schema: [] },
    'action-delete-event':   { title: 'Event löschen', text: 'Löscht dieses Event dauerhaft aus der Datenbank. Es verschwindet auch aus dem JSON-LD-Output. Nicht rückgängig zu machen!', schema: [] },
    'action-edit-location':  { title: 'Standort bearbeiten', text: 'Öffnet das Formular mit allen LocalBusiness-, GamblingResort- und Öffnungszeiten-Feldern.', schema: ['local','gambling','hours'] },
    'action-delete-location':{ title: 'Standort löschen', text: 'Löscht den Standort und alle zugehörigen Öffnungszeiten dauerhaft. Nicht rückgängig zu machen!', schema: [] },

    /* ── FILTERS ── */
    'filter-status': { title: 'Status-Filter', text: 'Zeigt nur Events eines bestimmten Workflow-Status an. Nützlich um z.B. alle ungeprueften (Gescrapt) Events zu sehen.', schema: [] },
    'filter-type':   { title: 'Typ-Filter', text: 'Filtert Events nach Kategorie (Poker, Roulette etc.). Entspricht dem Tab-Filter auf der öffentlichen Events-Seite.', schema: [] },
    'filter-search': { title: 'Volltextsuche', text: 'Durchsucht alle sichtbaren Spalten nach dem eingegebenen Begriff – Name, Ort, Quelle und Status.', schema: [] },

    /* ── BATCH BAR ── */
    'batch-approve': { title: 'Auswahl genehmigen', text: 'Setzt alle ausgewählten Events auf Status „Genehmigt". Danach können sie gesammelt veröffentlicht werden.', schema: [] },
    'batch-publish': { title: 'Auswahl veröffentlichen', text: 'Schaltet alle ausgewählten Events direkt live. Sie erscheinen sofort im Shortcode und im JSON-LD.', schema: [] },
    'batch-delete':  { title: 'Auswahl löschen', text: 'Löscht alle markierten Events permanent. Diese Aktion kann nicht rückgängig gemacht werden!', schema: [] },

    /* ── SHORTCODE ── */
    'shortcode': {
      title: '[spielbank_events] Shortcode',
      text:  'Füge diesen Shortcode in eine WordPress-Seite ein (z.B. /events/). Er rendert automatisch alle veröffentlichten Events und injiziert das vollständige schema.org JSON-LD im @graph-Format.',
      schema: ['event', 'gambling', 'hours']
    },

    /* ══ EVENT MODAL FIELDS ══ */
    'ef-name': {
      title: 'Event-Name (name)',
      text:  'Vollständiger Titel der Veranstaltung. Pflichtfeld.',
      schema: ['event'], required: true
    },
    'ef-start': {
      title: 'Startdatum (startDate)',
      text:  'Datum und Uhrzeit, wann das Event beginnt. Wird als ISO 8601 in schema.org/Event → startDate ausgegeben.',
      schema: ['event']
    },
    'ef-end': {
      title: 'Enddatum (endDate)',
      text:  'Datum und Uhrzeit, wann das Event endet. Optional, aber empfohlen für mehrtägige Veranstaltungen. → schema.org/Event → endDate.',
      schema: ['event']
    },
    'ef-desc': {
      title: 'Beschreibung (description)',
      text:  'Kurze Beschreibung der Veranstaltung. Erscheint in der Event-Card auf der Website und im schema.org/Event → description.',
      schema: ['event']
    },
    'ef-location': {
      title: 'Veranstaltungsort (Place → name)',
      text:  'Name des Ortes, an dem das Event stattfindet (z.B. "Casino am Potsdamer Platz"). → schema.org/Event → location → Place → name.',
      schema: ['event']
    },
    'ef-city': {
      title: 'Stadt (addressLocality)',
      text:  'Stadt des Veranstaltungsorts. → schema.org/Event → location → address → PostalAddress → addressLocality.',
      schema: ['event']
    },
    'ef-address': {
      title: 'Adresse (streetAddress)',
      text:  'Vollständige Straße mit Hausnummer. → schema.org/Event → location → address → PostalAddress → streetAddress.',
      schema: ['event']
    },
    'ef-organizer': {
      title: 'Veranstalter (organizer)',
      text:  'Name der veranstaltenden Spielbank. Wird als schema.org/GamblingResort referenziert in → schema.org/Event → organizer.',
      schema: ['event', 'gambling']
    },
    'ef-type': {
      title: 'Event-Typ (intern)',
      text:  'Interne Kategorie für die Filterung auf der Website (Poker, Roulette etc.). Nicht direkt im schema.org-Output, beeinflusst aber die Darstellung.',
      schema: []
    },
    'ef-url': {
      title: 'URL (url)',
      text:  'Direkte URL zur Event-Detailseite auf der Quelle. → schema.org/Event → url.',
      schema: ['event']
    },
    'ef-image': {
      title: 'Bild-URL (image)',
      text:  'URL zu einem Vorschaubild des Events. → schema.org/Event → image. Empfohlen: min. 1200×628px.',
      schema: ['event']
    },
    'ef-ticket': {
      title: 'Ticket-URL (offers → url)',
      text:  'Link zur Ticketbuchung. → schema.org/Event → offers → Offer → url. Aktiviert das Ticket-Snippet in der Google-Suche.',
      schema: ['event']
    },
    'ef-price': {
      title: 'Preis (offers → price)',
      text:  'Eintrittspreiss als Zahl ohne Währungssymbol (z.B. "15"). → schema.org/Offer → price. Zusammen mit Währung und Ticket-URL für Ticketing Rich Results.',
      schema: ['offer']
    },
    'ef-currency': {
      title: 'Währung (offers → priceCurrency)',
      text:  'ISO 4217 Währungscode (z.B. "EUR"). → schema.org/Offer → priceCurrency.',
      schema: ['offer']
    },
    'ef-status': {
      title: 'Workflow-Status',
      text:  'Entwurf = nicht sichtbar · Gescrapt = automatisch importiert, ungeprüft · Genehmigt = geprüft, wartend · Veröffentlicht = live auf Website und im JSON-LD.',
      schema: []
    },

    /* ══ LOCATION MODAL FIELDS ══ */
    'lf-name': {
      title: 'Name (name) *',
      text:  'Offizieller Name der Spielbank inklusive Standortbezeichnung. Pflichtfeld. → schema.org/GamblingResort → name.',
      schema: ['local','gambling'], required: true
    },
    'lf-altname': {
      title: 'Alternativer Name (alternateName)',
      text:  'Kurzform oder bekannter Alias des Standorts. → schema.org/LocalBusiness → alternateName.',
      schema: ['local']
    },
    'lf-desc': {
      title: 'Beschreibung (description)',
      text:  'Beschreibender Text der Spielbank. Erscheint in der Google-Suche im Knowledge Panel. → schema.org/LocalBusiness → description.',
      schema: ['local']
    },
    'lf-url': {
      title: 'Website-URL (url)',
      text:  'Offizielle Website der Spielbank. → schema.org/LocalBusiness → url.',
      schema: ['local']
    },
    'lf-price': {
      title: 'Preisklasse (priceRange)',
      text:  'Typischerweise €, €€, €€€ oder €€€€. Wird in Google-Suchergebnissen und Maps angezeigt. → schema.org/LocalBusiness → priceRange.',
      schema: ['local']
    },
    'lf-tel': {
      title: 'Telefon (telephone)',
      text:  'Rufnummer im internationalen Format (+49 ...). Klickbar in mobilen Suchergebnissen. → schema.org/LocalBusiness → telephone.',
      schema: ['local']
    },
    'lf-email': {
      title: 'E-Mail (email)',
      text:  'Kontakt-E-Mail-Adresse. → schema.org/LocalBusiness → email.',
      schema: ['local']
    },
    'lf-street': {
      title: 'Straße & Hausnummer (streetAddress)',
      text:  'Vollständige Straßenadresse. → schema.org/PostalAddress → streetAddress. Google benötigt dieses Feld für Local Pack-Einträge.',
      schema: ['local']
    },
    'lf-city': {
      title: 'Ort (addressLocality)',
      text:  'Stadt oder Gemeinde. → schema.org/PostalAddress → addressLocality.',
      schema: ['local']
    },
    'lf-plz': {
      title: 'Postleitzahl (postalCode)',
      text:  '5-stellige deutsche PLZ. → schema.org/PostalAddress → postalCode. Wichtig für präzise Google Maps-Platzierung.',
      schema: ['local']
    },
    'lf-region': {
      title: 'Bundesland (addressRegion)',
      text:  'Deutsches Bundesland (z.B. "Berlin", "Hamburg"). → schema.org/PostalAddress → addressRegion.',
      schema: ['local']
    },
    'lf-country': {
      title: 'Land (addressCountry)',
      text:  'ISO 3166-1 alpha-2 Ländercode (z.B. "DE"). → schema.org/PostalAddress → addressCountry.',
      schema: ['local']
    },
    'lf-lat': {
      title: 'Breitengrad (latitude)',
      text:  'GPS-Breitengrad (z.B. 52.5094090). → schema.org/GeoCoordinates → latitude. Ermöglicht Google Maps-Marker und Near-me-Suchen.',
      schema: ['local']
    },
    'lf-lng': {
      title: 'Längengrad (longitude)',
      text:  'GPS-Längengrad (z.B. 13.3754280). → schema.org/GeoCoordinates → longitude.',
      schema: ['local']
    },
    'lf-rating': {
      title: 'Bewertung (ratingValue)',
      text:  'Durchschnittliche Sternebewertung (0–5). → schema.org/AggregateRating → ratingValue. Erscheint als Sterne-Snippet in Google-Suchergebnissen.',
      schema: ['local']
    },
    'lf-reviews': {
      title: 'Anzahl Bewertungen (reviewCount)',
      text:  'Gesamtanzahl der Bewertungen, auf denen der Durchschnitt basiert. → schema.org/AggregateRating → reviewCount. Pflicht wenn ratingValue gesetzt ist.',
      schema: ['local']
    },
    'lf-image': {
      title: 'Bild-URL (image)',
      text:  'Hauptbild des Standorts. → schema.org/LocalBusiness → image. Empfohlen: min. 1200×628px, JPG oder PNG.',
      schema: ['local']
    },
    'lf-logo': {
      title: 'Logo-URL (logo)',
      text:  'URL zum Logo. → schema.org/LocalBusiness → logo → ImageObject. Wird im Knowledge Panel und in E-Mail-Strukturdaten angezeigt.',
      schema: ['local']
    },
    'lf-sameas': {
      title: 'sameAs-URLs',
      text:  'Komma-getrennte Liste von URLs zu Social-Media-Profilen und anderen autoritativen Quellen (Wikipedia, Wikidata, Facebook etc.). → schema.org/Thing → sameAs. Stärkt die Entitätserkennung durch Google.',
      schema: ['local']
    },
    'lf-map': {
      title: 'Maps-URL (hasMap)',
      text:  'Direktlink zu Google Maps oder einer anderen Karte. → schema.org/Place → hasMap. Wird in manchen Google-Darstellungen als Kartenlink angezeigt.',
      schema: ['local']
    },
    'lf-hours': {
      title: 'Öffnungszeiten (OpeningHoursSpecification)',
      text:  'Öffnungszeiten pro Wochentag. Jede Zeile erzeugt ein eigenständiges schema.org/OpeningHoursSpecification-Objekt mit dayOfWeek, opens und closes. Wird in der Google-Suche als „Geöffnet / Geschlossen"-Info angezeigt.',
      schema: ['hours']
    },
    'lf-payment': {
      title: 'Akzeptierte Zahlungsmittel (paymentAccepted)',
      text:  'Komma-getrennte Liste (z.B. "Cash, Credit Card, EC"). → schema.org/LocalBusiness → paymentAccepted.',
      schema: ['local']
    },
    'lf-currencies': {
      title: 'Akzeptierte Währungen (currenciesAccepted)',
      text:  'ISO 4217 Währungscodes, komma-getrennt (z.B. "EUR"). → schema.org/LocalBusiness → currenciesAccepted.',
      schema: ['local']
    },
    'lf-accessible': {
      title: 'Barrierefrei (isAccessibleForFree)',
      text:  'Gibt an ob der Standort kostenfrei zugänglich / barrierefrei ist. → schema.org/Place → isAccessibleForFree.',
      schema: ['local']
    },
    'lf-smoking': {
      title: 'Rauchen erlaubt (smokingAllowed)',
      text:  'Ob Rauchen erlaubt ist. → schema.org/LodgingBusiness / Place → smokingAllowed.',
      schema: ['local']
    },
    'lf-status': {
      title: 'Standort-Status',
      text:  'Aktiv = im JSON-LD ausgegeben und auf der Website sichtbar · Entwurf = noch in Bearbeitung · Inaktiv = ausgeblendet.',
      schema: []
    },

    /* ── HOURS EDITOR ── */
    'hours-opens':  { title: 'Öffnungszeit (opens)', text: 'Uhrzeit, zu der der Standort öffnet. → schema.org/OpeningHoursSpecification → opens. Format: HH:MM (24h).', schema: ['hours'] },
    'hours-closes': { title: 'Schließzeit (closes)', text: 'Uhrzeit, zu der der Standort schließt. → schema.org/OpeningHoursSpecification → closes. Für Öffnungen über Mitternacht: z.B. 03:00.', schema: ['hours'] },
    'hours-label':  { title: 'Sonderbezeichnung (label)', text: 'Optionale interne Bezeichnung für Sonderöffnungszeiten, z.B. "Silvester" oder "Feiertag". Nicht im schema.org-Output, nur zur internen Kennzeichnung.', schema: [] },
  };

  // Schema label config
  var SCHEMA_LABELS = {
    local:    { text: 'LocalBusiness', cls: 'sbe-tip-schema--local' },
    gambling: { text: 'GamblingResort', cls: 'sbe-tip-schema--gambling' },
    hours:    { text: 'OpeningHoursSpecification', cls: 'sbe-tip-schema--hours' },
    event:    { text: 'Event', cls: 'sbe-tip-schema--event' },
    offer:    { text: 'Offer', cls: 'sbe-tip-schema--offer' },
  };

  // ── Create tooltip DOM ─────────────────────────────────────────────────────
  var $tip = null;
  var hideTimer = null;

  function init() {
    // Create the floating bubble
    $tip = document.createElement('div');
    $tip.id = 'sbe-tooltip';
    $tip.innerHTML = '<div class="sbe-tip-box"><span class="sbe-tip-title"></span><span class="sbe-tip-text"></span><div class="sbe-tip-tags"></div></div>';
    document.body.appendChild($tip);

    // Inject ⓘ icons after every form label that has a matching data-sbe-tip on its input
    injectLabelIcons();

    // Attach hover listeners to all data-sbe-tip elements
    document.addEventListener('mouseover',  onMouseover,  true);
    document.addEventListener('mouseout',   onMouseout,   true);
    document.addEventListener('focusin',    onFocusin,    true);
    document.addEventListener('focusout',   onMouseout,   true);
    document.addEventListener('touchstart', onMouseover,  { passive: true });

    // Pulse first few icons as a hint
    var icons = document.querySelectorAll('.sbe-tip-icon');
    for (var i = 0; i < Math.min(3, icons.length); i++) {
      icons[i].classList.add('sbe-tip-icon--pulse');
    }

    // Re-inject icons when modals open (dynamic content)
    document.addEventListener('click', function(e) {
      setTimeout(injectLabelIcons, 50);
    });
  }

  function injectLabelIcons() {
    // Find all .sbe-form-row elements that have a label + an input with data-sbe-tip
    var rows = document.querySelectorAll('.sbe-form-row, .sbe-form-section');
    rows.forEach(function(row) {
      var label = row.querySelector('label');
      if (!label || label.querySelector('.sbe-tip-icon')) return;

      var input = row.querySelector('[data-sbe-tip]');
      if (!input) return;

      var key = input.getAttribute('data-sbe-tip');
      if (!TIPS[key]) return;

      var icon = document.createElement('span');
      icon.className = 'sbe-tip-icon';
      icon.setAttribute('data-sbe-tip', key);
      icon.setAttribute('aria-label', 'Hilfe: ' + (TIPS[key].title || key));
      icon.textContent = 'i';
      label.appendChild(icon);
    });

    // Also inject on table headers with data-sbe-tip
    document.querySelectorAll('th[data-sbe-tip]').forEach(function(th) {
      if (th.querySelector('.sbe-tip-icon')) return;
      var icon = document.createElement('span');
      icon.className = 'sbe-tip-icon';
      icon.setAttribute('data-sbe-tip', th.getAttribute('data-sbe-tip'));
      icon.textContent = 'i';
      th.appendChild(document.createTextNode(' '));
      th.appendChild(icon);
    });
  }

  function onMouseover(e) {
    var el = e.target.closest('[data-sbe-tip]');
    if (!el) return;
    clearTimeout(hideTimer);
    showTip(el);
  }

  function onFocusin(e) {
    var el = e.target.closest('[data-sbe-tip]');
    if (!el) return;
    showTip(el);
  }

  function onMouseout(e) {
    hideTimer = setTimeout(hideTip, 120);
  }

  function showTip(el) {
    var key  = el.getAttribute('data-sbe-tip');
    var data = TIPS[key];
    if (!data) return;

    // Fill content
    $tip.querySelector('.sbe-tip-title').textContent = data.title || key;
    $tip.querySelector('.sbe-tip-text').textContent  = data.text  || '';

    // Schema badges
    var tagsEl = $tip.querySelector('.sbe-tip-tags');
    tagsEl.innerHTML = '';
    (data.schema || []).forEach(function(s) {
      if (!SCHEMA_LABELS[s]) return;
      var badge = document.createElement('span');
      badge.className = 'sbe-tip-schema ' + SCHEMA_LABELS[s].cls;
      badge.textContent = SCHEMA_LABELS[s].text;
      tagsEl.appendChild(badge);
      tagsEl.appendChild(document.createTextNode(' '));
    });

    // Required note
    var existingReq = $tip.querySelector('.sbe-tip-required');
    if (existingReq) existingReq.remove();
    if (data.required) {
      var req = document.createElement('span');
      req.className = 'sbe-tip-required';
      req.textContent = '* Pflichtfeld';
      tagsEl.appendChild(req);
    }

    // Position
    $tip.classList.remove('sbe-tip--visible','sbe-tip--below','sbe-tip--above','sbe-tip--right');
    $tip.style.opacity = '0';
    $tip.style.display = 'block';

    var rect   = el.getBoundingClientRect();
    var tipH   = $tip.offsetHeight || 120;
    var tipW   = $tip.offsetWidth  || 260;
    var vw     = window.innerWidth;
    var vh     = window.innerHeight;
    var margin = 10;

    var top, left;
    var arrowClass = 'sbe-tip--below';

    // Prefer showing below
    if (rect.bottom + tipH + margin < vh) {
      top  = rect.bottom + margin;
      arrowClass = 'sbe-tip--below';
    } else {
      top  = rect.top - tipH - margin;
      arrowClass = 'sbe-tip--above';
    }

    // Horizontal: align to left of element, but don't overflow viewport
    left = rect.left;
    if (left + tipW > vw - margin) {
      left = vw - tipW - margin;
      arrowClass += ' sbe-tip--right';
    }
    if (left < margin) left = margin;

    $tip.style.top  = top  + 'px';
    $tip.style.left = left + 'px';
    $tip.classList.add(arrowClass);
    $tip.classList.add('sbe-tip--visible');
  }

  function hideTip() {
    if ($tip) $tip.classList.remove('sbe-tip--visible');
  }

  // ── Boot ──────────────────────────────────────────────────────────────────
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
