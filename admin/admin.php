<?php
defined('ABSPATH') || exit;

add_action('admin_menu',            'sbe_add_admin_menu');
add_action('admin_enqueue_scripts', 'sbe_admin_assets');

function sbe_add_admin_menu(): void {
    add_menu_page('Spielbank Events','Spielbank Events','manage_options',
        'spielbank-events','sbe_render_admin_page','dashicons-calendar-alt',30);
}

function sbe_admin_assets(string $hook): void {
    if ($hook !== 'toplevel_page_spielbank-events') return;
    wp_enqueue_style ('sbe-admin',   SBE_PLUGIN_URL.'admin/admin.css',   [], SBE_VERSION);
    wp_enqueue_style ('sbe-tooltip', SBE_PLUGIN_URL.'admin/tooltip.css', [], SBE_VERSION);
    wp_enqueue_script('sbe-admin',   SBE_PLUGIN_URL.'admin/admin.js',    ['jquery'], SBE_VERSION, true);
    wp_enqueue_script('sbe-tooltip', SBE_PLUGIN_URL.'admin/tooltip.js',  [], SBE_VERSION, true);
    wp_localize_script('sbe-admin','SBE',[
        'ajax_url' => admin_url('admin-ajax.php'),
        'nonce'    => wp_create_nonce('sbe_nonce'),
    ]);
}

function sbe_render_admin_page(): void {
    $events    = sbe_get_all_events();
    $locations = sbe_get_all_locations();
    $ecounts   = array_count_values(array_column($events,'status'));
    $tab       = sanitize_text_field($_GET['sbe_tab'] ?? 'events');
    ?>
    <div class="wrap sbe-wrap">

      <h1 class="sbe-page-title">
        <span class="dashicons dashicons-calendar-alt"></span> Spielbank Events
        <span class="sbe-shortcode-hint" data-sbe-tip="shortcode">
          Shortcode: <code>[spielbank_events]</code>
        </span>
      </h1>

      <!-- STATS -->
      <div class="sbe-stats">
        <div class="sbe-stat" data-sbe-tip="stat-total">
          <strong><?= count($events) ?></strong><span>Events gesamt</span>
        </div>
        <div class="sbe-stat sbe-stat--scraped" data-sbe-tip="stat-scraped">
          <strong><?= $ecounts['scraped']??0 ?></strong><span>Neu gescrapt</span>
        </div>
        <div class="sbe-stat sbe-stat--approved" data-sbe-tip="stat-approved">
          <strong><?= $ecounts['approved']??0 ?></strong><span>Genehmigt</span>
        </div>
        <div class="sbe-stat sbe-stat--published" data-sbe-tip="stat-published">
          <strong><?= $ecounts['published']??0 ?></strong><span>Veröffentlicht</span>
        </div>
        <div class="sbe-stat sbe-stat--locs" data-sbe-tip="stat-locs">
          <strong><?= count($locations) ?></strong><span>Standorte</span>
        </div>
      </div>

      <!-- TOOLBAR -->
      <div class="sbe-toolbar">
        <button id="sbe-btn-scrape" class="button button-primary sbe-btn-scrape" data-sbe-tip="btn-scrape">
          <span class="dashicons dashicons-update"></span> Jetzt scrapen
        </button>
        <button id="sbe-btn-approve-all" class="button sbe-btn-approve" data-sbe-tip="btn-approve-all">
          <span class="dashicons dashicons-yes-alt"></span> Alle genehmigen
        </button>
        <button id="sbe-btn-publish" class="button sbe-btn-publish" data-sbe-tip="btn-publish">
          <span class="dashicons dashicons-upload"></span> Auf /events/ veröffentlichen
        </button>
      </div>

      <!-- LOG + NOTICE -->
      <div id="sbe-log" class="sbe-log" style="display:none">
        <div class="sbe-log-header">
          <strong>Scraper-Log</strong>
          <button class="sbe-log-close" onclick="document.getElementById('sbe-log').style.display='none'">✕</button>
        </div>
        <pre id="sbe-log-content"></pre>
      </div>
      <div id="sbe-notice" class="sbe-notice" style="display:none"></div>

      <!-- TABS -->
      <div class="sbe-tabs-nav">
        <button class="sbe-tab-btn <?= $tab==='events'?'active':'' ?>" data-tab="events" data-sbe-tip="tab-events">
          <span class="dashicons dashicons-calendar-alt"></span> Events (<?= count($events) ?>)
        </button>
        <button class="sbe-tab-btn <?= $tab==='locations'?'active':'' ?>" data-tab="locations" data-sbe-tip="tab-locations">
          <span class="dashicons dashicons-location"></span> Standorte &amp; Öffnungszeiten (<?= count($locations) ?>)
        </button>
      </div>

      <!-- ══ TAB: EVENTS ══ -->
      <div id="sbe-tab-events" class="sbe-tab-panel" <?= $tab!=='events'?'style="display:none"':'' ?>>
        <div class="sbe-toolbar sbe-toolbar--sub">
          <button id="sbe-btn-add-event" class="button sbe-btn-add" data-sbe-tip="btn-add-event">
            <span class="dashicons dashicons-plus-alt"></span> Event hinzufügen
          </button>
          <select id="sbe-filter-status" data-sbe-tip="filter-status">
            <option value="">Alle Status</option>
            <option value="scraped">Gescrapt</option>
            <option value="approved">Genehmigt</option>
            <option value="published">Veröffentlicht</option>
            <option value="draft">Entwurf</option>
          </select>
          <select id="sbe-filter-type" data-sbe-tip="filter-type">
            <option value="">Alle Typen</option>
            <option>Poker</option><option>Blackjack</option><option>Roulette</option>
            <option>Slots</option><option>Musik</option><option>Sonstige</option>
          </select>
          <input type="text" id="sbe-filter-search" placeholder="Suchen…" data-sbe-tip="filter-search" />
        </div>

        <div class="sbe-table-wrap">
          <table class="sbe-table" id="sbe-table">
            <thead><tr>
              <th style="width:40px"><input type="checkbox" id="sbe-check-all" title="Alle auswählen"/></th>
              <th data-sbe-tip="th-event-name">Event-Name</th>
              <th style="width:120px" data-sbe-tip="th-event-date">Datum</th>
              <th style="width:100px" data-sbe-tip="th-event-city">Ort</th>
              <th style="width:85px"  data-sbe-tip="th-event-type">Typ</th>
              <th style="width:85px"  data-sbe-tip="th-event-source">Quelle</th>
              <th style="width:90px"  data-sbe-tip="th-event-status">Status</th>
              <th style="width:130px">Aktionen</th>
            </tr></thead>
            <tbody id="sbe-tbody">
              <?php foreach($events as $ev): echo sbe_render_event_row($ev); endforeach; ?>
            </tbody>
          </table>
        </div>

        <div class="sbe-batch-bar" id="sbe-batch-bar" style="display:none">
          <span id="sbe-batch-count">0 ausgewählt</span>
          <button class="button" id="sbe-batch-approve" data-sbe-tip="batch-approve">Genehmigen</button>
          <button class="button" id="sbe-batch-publish" data-sbe-tip="batch-publish">Veröffentlichen</button>
          <button class="button sbe-btn-danger" id="sbe-batch-delete" data-sbe-tip="batch-delete">Löschen</button>
        </div>
      </div>

      <!-- ══ TAB: LOCATIONS ══ -->
      <div id="sbe-tab-locations" class="sbe-tab-panel" <?= $tab==='locations'?'':'style="display:none"' ?>>
        <div class="sbe-toolbar sbe-toolbar--sub">
          <button id="sbe-btn-add-location" class="button sbe-btn-add" data-sbe-tip="btn-add-location">
            <span class="dashicons dashicons-plus-alt"></span> Standort hinzufügen
          </button>
        </div>

        <div class="sbe-schema-legend">
          <strong>Schema-Typen in dieser Tabelle:</strong>
          <span class="sbe-schema-badge" data-sbe-tip="schema-local">LocalBusiness</span>
          <span class="sbe-schema-badge sbe-schema-badge--gambling" data-sbe-tip="schema-gambling">GamblingResort</span>
          <span class="sbe-schema-badge sbe-schema-badge--hours" data-sbe-tip="schema-hours">OpeningHoursSpecification</span>
        </div>

        <div class="sbe-table-wrap">
          <table class="sbe-table">
            <thead><tr>
              <th data-sbe-tip="th-loc-name">Name</th>
              <th style="width:100px" data-sbe-tip="th-loc-city">Stadt</th>
              <th style="width:70px"  data-sbe-tip="th-loc-plz">PLZ</th>
              <th style="width:90px"  data-sbe-tip="th-loc-tel">Telefon</th>
              <th style="width:60px"  data-sbe-tip="th-loc-rating">Rating</th>
              <th style="width:60px"  data-sbe-tip="th-loc-price">Preis</th>
              <th style="width:70px"  data-sbe-tip="th-loc-hours">Öffnungszeiten</th>
              <th style="width:80px">Status</th>
              <th style="width:130px">Aktionen</th>
            </tr></thead>
            <tbody id="sbe-loc-tbody">
              <?php foreach($locations as $loc): echo sbe_render_location_row($loc); endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>

    </div><!-- /wrap -->

    <!-- ══ MODAL: Event ══ -->
    <div id="sbe-modal-event" class="sbe-modal" style="display:none">
      <div class="sbe-modal-inner sbe-modal-inner--wide">
        <div class="sbe-modal-header">
          <h2 id="sbe-modal-event-title">Event bearbeiten</h2>
          <button class="sbe-modal-close" onclick="sbeCloseModal('event')">✕</button>
        </div>
        <div class="sbe-modal-body">
          <input type="hidden" id="sbe-ef-id"/>

          <div class="sbe-form-section"><h3>Event-Schema <span class="sbe-schema-badge sbe-schema-badge--event">schema.org/Event</span></h3></div>

          <div class="sbe-form-row sbe-form-row--full">
            <label>Name *</label>
            <input type="text" id="sbe-ef-name" data-sbe-tip="ef-name"/>
          </div>
          <div class="sbe-form-row">
            <label>Start-Datum &amp; Zeit</label>
            <input type="datetime-local" id="sbe-ef-start" data-sbe-tip="ef-start"/>
          </div>
          <div class="sbe-form-row">
            <label>End-Datum &amp; Zeit</label>
            <input type="datetime-local" id="sbe-ef-end" data-sbe-tip="ef-end"/>
          </div>
          <div class="sbe-form-row sbe-form-row--full">
            <label>Beschreibung</label>
            <textarea id="sbe-ef-desc" rows="3" data-sbe-tip="ef-desc"></textarea>
          </div>
          <div class="sbe-form-row">
            <label>Veranstaltungsort (Place)</label>
            <input type="text" id="sbe-ef-location" data-sbe-tip="ef-location"/>
          </div>
          <div class="sbe-form-row">
            <label>Stadt</label>
            <input type="text" id="sbe-ef-city" data-sbe-tip="ef-city"/>
          </div>
          <div class="sbe-form-row sbe-form-row--full">
            <label>Adresse</label>
            <input type="text" id="sbe-ef-address" data-sbe-tip="ef-address"/>
          </div>
          <div class="sbe-form-row">
            <label>Veranstalter (Organizer)</label>
            <input type="text" id="sbe-ef-organizer" data-sbe-tip="ef-organizer"/>
          </div>
          <div class="sbe-form-row">
            <label>Event-Typ</label>
            <select id="sbe-ef-type" data-sbe-tip="ef-type">
              <option>Poker</option><option>Blackjack</option><option>Roulette</option>
              <option>Slots</option><option>Musik</option><option>Sonstige</option>
            </select>
          </div>
          <div class="sbe-form-row sbe-form-row--full">
            <label>URL</label>
            <input type="url" id="sbe-ef-url" data-sbe-tip="ef-url"/>
          </div>
          <div class="sbe-form-row sbe-form-row--full">
            <label>Bild-URL</label>
            <input type="url" id="sbe-ef-image" data-sbe-tip="ef-image"/>
          </div>

          <div class="sbe-form-section"><h3>Offer – Tickets &amp; Preis <span class="sbe-schema-badge sbe-schema-badge--offer">schema.org/Offer</span></h3></div>

          <div class="sbe-form-row">
            <label>Ticket-URL</label>
            <input type="url" id="sbe-ef-ticket" data-sbe-tip="ef-ticket"/>
          </div>
          <div class="sbe-form-row">
            <label>Preis</label>
            <input type="text" id="sbe-ef-price" placeholder="z.B. 15" data-sbe-tip="ef-price"/>
          </div>
          <div class="sbe-form-row">
            <label>Währung</label>
            <input type="text" id="sbe-ef-currency" value="EUR" data-sbe-tip="ef-currency"/>
          </div>
          <div class="sbe-form-row">
            <label>Status</label>
            <select id="sbe-ef-status" data-sbe-tip="ef-status">
              <option value="draft">Entwurf</option>
              <option value="scraped">Gescrapt</option>
              <option value="approved">Genehmigt</option>
              <option value="published">Veröffentlicht</option>
            </select>
          </div>
        </div>
        <div class="sbe-modal-footer">
          <button class="button" onclick="sbeCloseModal('event')">Abbrechen</button>
          <button class="button button-primary" onclick="sbeSaveEventModal()">Speichern</button>
        </div>
      </div>
    </div>

    <!-- ══ MODAL: Location ══ -->
    <div id="sbe-modal-location" class="sbe-modal" style="display:none">
      <div class="sbe-modal-inner sbe-modal-inner--wide">
        <div class="sbe-modal-header">
          <h2 id="sbe-modal-loc-title">Standort bearbeiten</h2>
          <button class="sbe-modal-close" onclick="sbeCloseModal('location')">✕</button>
        </div>
        <div class="sbe-modal-body sbe-modal-body--3col">
          <input type="hidden" id="sbe-lf-id"/>

          <div class="sbe-form-section sbe-form-section--span">
            <h3>🏢 LocalBusiness / GamblingResort
              <span class="sbe-schema-badge" style="margin-left:6px">LocalBusiness</span>
              <span class="sbe-schema-badge sbe-schema-badge--gambling">GamblingResort</span>
            </h3>
          </div>
          <div class="sbe-form-row sbe-form-row--full">
            <label>Name *</label>
            <input type="text" id="sbe-lf-name" data-sbe-tip="lf-name"/>
          </div>
          <div class="sbe-form-row sbe-form-row--full">
            <label>Alternativer Name</label>
            <input type="text" id="sbe-lf-altname" data-sbe-tip="lf-altname"/>
          </div>
          <div class="sbe-form-row sbe-form-row--full">
            <label>Beschreibung</label>
            <textarea id="sbe-lf-desc" rows="3" data-sbe-tip="lf-desc"></textarea>
          </div>
          <div class="sbe-form-row">
            <label>Website-URL</label>
            <input type="url" id="sbe-lf-url" data-sbe-tip="lf-url"/>
          </div>
          <div class="sbe-form-row">
            <label>Preisklasse (priceRange)</label>
            <input type="text" id="sbe-lf-price" placeholder="€€€" data-sbe-tip="lf-price"/>
          </div>

          <div class="sbe-form-section sbe-form-section--span"><h3>📞 Kontakt</h3></div>
          <div class="sbe-form-row">
            <label>Telefon</label>
            <input type="text" id="sbe-lf-tel" data-sbe-tip="lf-tel"/>
          </div>
          <div class="sbe-form-row">
            <label>E-Mail</label>
            <input type="email" id="sbe-lf-email" data-sbe-tip="lf-email"/>
          </div>

          <div class="sbe-form-section sbe-form-section--span">
            <h3>📍 PostalAddress <span class="sbe-schema-badge" style="margin-left:6px">PostalAddress</span></h3>
          </div>
          <div class="sbe-form-row sbe-form-row--full">
            <label>Straße &amp; Hausnummer</label>
            <input type="text" id="sbe-lf-street" data-sbe-tip="lf-street"/>
          </div>
          <div class="sbe-form-row">
            <label>Ort (addressLocality)</label>
            <input type="text" id="sbe-lf-city" data-sbe-tip="lf-city"/>
          </div>
          <div class="sbe-form-row">
            <label>PLZ (postalCode)</label>
            <input type="text" id="sbe-lf-plz" data-sbe-tip="lf-plz"/>
          </div>
          <div class="sbe-form-row">
            <label>Bundesland (addressRegion)</label>
            <input type="text" id="sbe-lf-region" data-sbe-tip="lf-region"/>
          </div>
          <div class="sbe-form-row">
            <label>Land (addressCountry)</label>
            <input type="text" id="sbe-lf-country" value="DE" data-sbe-tip="lf-country"/>
          </div>

          <div class="sbe-form-section sbe-form-section--span">
            <h3>🌐 GeoCoordinates <span class="sbe-schema-badge" style="margin-left:6px">GeoCoordinates</span></h3>
          </div>
          <div class="sbe-form-row">
            <label>Breitengrad (latitude)</label>
            <input type="number" step="0.0000001" id="sbe-lf-lat" data-sbe-tip="lf-lat"/>
          </div>
          <div class="sbe-form-row">
            <label>Längengrad (longitude)</label>
            <input type="number" step="0.0000001" id="sbe-lf-lng" data-sbe-tip="lf-lng"/>
          </div>

          <div class="sbe-form-section sbe-form-section--span">
            <h3>⭐ AggregateRating <span class="sbe-schema-badge" style="margin-left:6px">AggregateRating</span></h3>
          </div>
          <div class="sbe-form-row">
            <label>Bewertung (ratingValue)</label>
            <input type="number" step="0.1" min="0" max="5" id="sbe-lf-rating" data-sbe-tip="lf-rating"/>
          </div>
          <div class="sbe-form-row">
            <label>Anzahl Bewertungen (reviewCount)</label>
            <input type="number" id="sbe-lf-reviews" data-sbe-tip="lf-reviews"/>
          </div>

          <div class="sbe-form-section sbe-form-section--span"><h3>🖼️ Medien</h3></div>
          <div class="sbe-form-row">
            <label>Bild-URL (image)</label>
            <input type="url" id="sbe-lf-image" data-sbe-tip="lf-image"/>
          </div>
          <div class="sbe-form-row">
            <label>Logo-URL (logo)</label>
            <input type="url" id="sbe-lf-logo" data-sbe-tip="lf-logo"/>
          </div>

          <div class="sbe-form-section sbe-form-section--span"><h3>🔗 sameAs / hasMap</h3></div>
          <div class="sbe-form-row sbe-form-row--full">
            <label>sameAs URLs (komma-getrennt)</label>
            <input type="text" id="sbe-lf-sameas" placeholder="https://facebook.com/..., https://instagram.com/..." data-sbe-tip="lf-sameas"/>
          </div>
          <div class="sbe-form-row sbe-form-row--full">
            <label>Maps-URL (hasMap)</label>
            <input type="url" id="sbe-lf-map" data-sbe-tip="lf-map"/>
          </div>

          <div class="sbe-form-section sbe-form-section--span">
            <h3>🕐 OpeningHoursSpecification
              <span class="sbe-schema-badge sbe-schema-badge--hours" style="margin-left:6px">OpeningHoursSpecification</span>
              <span class="sbe-tip-icon" data-sbe-tip="lf-hours" style="cursor:pointer">i</span>
            </h3>
          </div>
          <div class="sbe-form-row--full" id="sbe-hours-editor">
            <!-- Rendered by JS; column headers get data-sbe-tip via JS -->
          </div>

          <div class="sbe-form-section sbe-form-section--span"><h3>⚙️ Sonstiges</h3></div>
          <div class="sbe-form-row">
            <label>Zahlung (paymentAccepted)</label>
            <input type="text" id="sbe-lf-payment" placeholder="Cash, Credit Card" data-sbe-tip="lf-payment"/>
          </div>
          <div class="sbe-form-row">
            <label>Währungen</label>
            <input type="text" id="sbe-lf-currencies" value="EUR" data-sbe-tip="lf-currencies"/>
          </div>
          <div class="sbe-form-row">
            <label>Barrierefrei</label>
            <select id="sbe-lf-accessible" data-sbe-tip="lf-accessible">
              <option value="1">Ja</option><option value="0">Nein</option>
            </select>
          </div>
          <div class="sbe-form-row">
            <label>Rauchen erlaubt</label>
            <select id="sbe-lf-smoking" data-sbe-tip="lf-smoking">
              <option value="0">Nein</option><option value="1">Ja</option>
            </select>
          </div>
          <div class="sbe-form-row">
            <label>Status</label>
            <select id="sbe-lf-status" data-sbe-tip="lf-status">
              <option value="active">Aktiv</option>
              <option value="draft">Entwurf</option>
              <option value="inactive">Inaktiv</option>
            </select>
          </div>
        </div>
        <div class="sbe-modal-footer">
          <button class="button" onclick="sbeCloseModal('location')">Abbrechen</button>
          <button class="button button-primary" onclick="sbeSaveLocationModal()">Standort speichern</button>
        </div>
      </div>
    </div>
    <?php
}

// ── Row renderers ─────────────────────────────────────────────────────────────
function sbe_render_event_row(array $ev): string {
    $date = $ev['start_date'] ? date('d.m.Y H:i', strtotime($ev['start_date'])) : '–';
    $cls  = ['scraped'=>'sbe-status--scraped','approved'=>'sbe-status--approved','published'=>'sbe-status--published','draft'=>'sbe-status--draft'][$ev['status']] ?? '';
    $lbl  = ['scraped'=>'Gescrapt','approved'=>'Genehmigt','published'=>'Veröffentlicht','draft'=>'Entwurf'][$ev['status']] ?? $ev['status'];
    $id   = (int)$ev['id'];
    $j    = esc_attr(json_encode($ev));
    return "<tr id=\"sbe-row-$id\" data-id=\"$id\">
      <td><input type=\"checkbox\" class=\"sbe-row-check\" value=\"$id\"/></td>
      <td class=\"sbe-col-name\"><strong>" . esc_html($ev['name']) . "</strong></td>
      <td>$date</td>
      <td>" . esc_html($ev['city']??'') . "</td>
      <td>" . esc_html($ev['event_type']??'') . "</td>
      <td class=\"sbe-col-source\">" . esc_html($ev['source']??'') . "</td>
      <td><span class=\"sbe-status $cls\">$lbl</span></td>
      <td class=\"sbe-col-actions\">
        <button class=\"button button-small sbe-btn-edit-event\" data-ev=\"$j\" data-sbe-tip=\"action-edit-event\">Bearbeiten</button>
        <button class=\"button button-small sbe-btn-approve-row\" data-id=\"$id\" data-sbe-tip=\"action-approve\">✓</button>
        <button class=\"button button-small sbe-btn-delete-event\" data-id=\"$id\" data-sbe-tip=\"action-delete-event\">✕</button>
      </td></tr>";
}

function sbe_render_location_row(array $loc): string {
    $hours   = sbe_get_hours((int)$loc['id']);
    $h_count = count($hours);
    $id  = (int)$loc['id'];
    $j   = esc_attr(json_encode($loc));
    $cls = $loc['status']==='active' ? 'sbe-status--published' : 'sbe-status--draft';
    return "<tr id=\"sbe-loc-row-$id\">
      <td class=\"sbe-col-name\"><strong>" . esc_html($loc['name']) . "</strong><br><small>" . esc_html($loc['url']??'') . "</small></td>
      <td>" . esc_html($loc['address_locality']??'') . "</td>
      <td>" . esc_html($loc['postal_code']??'') . "</td>
      <td>" . esc_html($loc['telephone']??'') . "</td>
      <td>" . ($loc['rating_value'] ? '★ '.esc_html($loc['rating_value']) : '–') . "</td>
      <td>" . esc_html($loc['price_range']??'–') . "</td>
      <td><span class=\"sbe-hours-count\">$h_count Tage</span></td>
      <td><span class=\"sbe-status $cls\">" . ($loc['status']==='active'?'Aktiv':'Entwurf') . "</span></td>
      <td class=\"sbe-col-actions\">
        <button class=\"button button-small sbe-btn-edit-location\" data-loc=\"$j\" data-sbe-tip=\"action-edit-location\">Bearbeiten</button>
        <button class=\"button button-small sbe-btn-delete-location\" data-id=\"$id\" data-sbe-tip=\"action-delete-location\">✕</button>
      </td></tr>";
}
