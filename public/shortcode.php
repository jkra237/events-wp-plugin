<?php
defined('ABSPATH') || exit;

add_shortcode('spielbank_events', 'sbe_shortcode');
add_action('wp_enqueue_scripts',  'sbe_frontend_assets');

function sbe_frontend_assets(): void {
    if (!is_page()) return;
    global $post;
    if ($post && has_shortcode($post->post_content, 'spielbank_events')) {
        wp_enqueue_style('sbe-frontend', SBE_PLUGIN_URL . 'public/frontend.css', [], SBE_VERSION);
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// MAIN SHORTCODE
// ─────────────────────────────────────────────────────────────────────────────
function sbe_shortcode(array $atts = []): string {

    // ── Load all data from DB ──────────────────────────────────────────────
    $events    = sbe_get_published_events();          // wp_spielbank_events
    $locations = sbe_get_all_locations();             // wp_spielbank_locations

    foreach ($locations as &$loc) {
        $loc['hours']     = sbe_get_hours((int)$loc['id']);   // wp_spielbank_hours
        $loc['amenities'] = !empty($loc['amenity_features'])
                            ? json_decode($loc['amenity_features'], true) : [];
        $loc['same_as_arr'] = !empty($loc['same_as'])
                            ? json_decode($loc['same_as'], true) : [];
        // Count matching published events
        $loc['event_count'] = 0;
        foreach ($events as $ev) {
            if ((int)($ev['location_id'] ?? 0) === (int)$loc['id']
                || ($ev['organizer'] ?? '') === $loc['name']
                || ($ev['location']  ?? '') === $loc['name']) {
                $loc['event_count']++;
            }
        }
    }
    unset($loc);

    // ── Build structured data for JS ───────────────────────────────────────
    // Pass the FULL location row (including hours, amenities etc.) to JS
    $js_locations = array_values(array_map(function($loc) {
        return [
            'id'             => (int)$loc['id'],
            'name'           => $loc['name']               ?? '',
            'alternate_name' => $loc['alternate_name']     ?? '',
            'description'    => $loc['description']        ?? '',
            'url'            => $loc['url']                ?? '',
            'telephone'      => $loc['telephone']          ?? '',
            'email'          => $loc['email']              ?? '',
            'street_address' => $loc['street_address']     ?? '',
            'city'           => $loc['address_locality']   ?? '',
            'postal_code'    => $loc['postal_code']        ?? '',
            'address_country'=> $loc['address_country']   ?? 'DE',
            'latitude'       => (float)($loc['latitude']  ?? 0),
            'longitude'      => (float)($loc['longitude'] ?? 0),
            'price_range'    => $loc['price_range']        ?? '',
            'payment'        => $loc['payment_accepted']   ?? '',
            'currencies'     => $loc['currencies_accepted']?? '',
            'is_accessible'  => (bool)($loc['is_accessible'] ?? 0),
            'smoking'        => (bool)($loc['smoking_allowed'] ?? 0),
            'rating'         => (float)($loc['rating_value'] ?? 0),
            'review_count'   => (int)($loc['review_count']   ?? 0),
            'image_url'      => $loc['image_url']          ?? '',
            'has_map'        => $loc['has_map']            ?? '',
            'status'         => $loc['status']             ?? 'active',
            'event_count'    => (int)$loc['event_count'],
            'hours'          => array_values(array_map(function($h) {
                return [
                    'day'    => $h['day_of_week'],
                    'opens'  => substr($h['opens'] ?? '',  0, 5),
                    'closes' => substr($h['closes'] ?? '', 0, 5),
                    'label'  => $h['label'] ?? '',
                ];
            }, $loc['hours'])),
            'amenities' => array_values(array_map(function($a) {
                return ['name' => $a['name'] ?? '', 'value' => $a['value'] ?? false];
            }, $loc['amenities'])),
        ];
    }, $locations));

    $js_events = array_values(array_map(function($ev) {
        return [
            'id'          => (int)$ev['id'],
            'name'        => $ev['name']        ?? '',
            'description' => $ev['description'] ?? '',
            'start_date'  => $ev['start_date']  ?? '',
            'end_date'    => $ev['end_date']    ?? '',
            'location_id' => (int)($ev['location_id'] ?? 0),
            'location'    => $ev['location']    ?? '',
            'organizer'   => $ev['organizer']   ?? '',
            'city'        => $ev['city']        ?? '',
            'address'     => $ev['address']     ?? '',
            'event_type'  => $ev['event_type']  ?? 'Sonstige',
            'url'         => $ev['url']         ?? '',
            'image_url'   => $ev['image_url']   ?? '',
            'ticket_url'  => $ev['ticket_url']  ?? '',
            'price'       => $ev['price']       ?? '',
            'price_currency' => $ev['price_currency'] ?? 'EUR',
        ];
    }, $events));

    // Derive event types actually present (for tab visibility)
    $present_types = array_unique(array_filter(array_column($events, 'event_type')));

    ob_start();

    // ── JSON-LD ────────────────────────────────────────────────────────────
    echo sbe_render_jsonld($locations, $events);

    // ── HTML ───────────────────────────────────────────────────────────────
?>
<div id="sb-app">

  <!-- ── HEADER ── -->
  <div class="sb-header">
    <a href="/" class="sb-logo">
      <div class="sb-logo-icon">
        <svg viewBox="0 0 24 24" fill="none" stroke="#1a1a1a" stroke-width="2.5">
          <circle cx="12" cy="12" r="10"/><path d="M12 6v6l4 2"/>
        </svg>
      </div>
      Spielbank<span class="sb-logo-tld">.com.de</span>
    </a>
    <nav class="sb-nav">
      <a href="/">Spielbanken</a>
      <a href="#" class="active">Events</a>
    </nav>
  </div>

  <!-- ── SEARCH BAR ── -->
  <div class="sb-search">
    <div class="sb-field-wrap sb-field-wrap--city">
      <svg class="sb-field-icon" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
        <path d="M12 2C8.13 2 5 5.13 5 9c0 5.25 7 13 7 13s7-7.75 7-13c0-3.87-3.13-7-7-7zm0 9.5c-1.38 0-2.5-1.12-2.5-2.5s1.12-2.5 2.5-2.5 2.5 1.12 2.5 2.5-1.12 2.5-2.5 2.5z"/>
      </svg>
      <input type="text" id="sb-search-city" placeholder="Stadt" autocomplete="off"/>
      <button class="sb-field-clear" id="sb-city-clear" style="display:none" onclick="sbClearCity()">✕</button>
    </div>
    <div class="sb-field-wrap sb-field-wrap--cat">
      <svg class="sb-field-icon" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
        <path d="M4 6h16M4 12h16M4 18h16"/>
      </svg>
      <select id="sb-search-cat">
        <option value="">Alle Kategorien</option>
        <?php foreach (['Poker','Blackjack','Roulette','Slots','Musik','Sonstige'] as $t): ?>
          <option value="<?= esc_attr($t) ?>"><?= esc_html($t) ?></option>
        <?php endforeach; ?>
      </select>
      <svg class="sb-field-arrow" width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M6 9l6 6 6-6"/></svg>
    </div>
    <button class="sb-btn-search" onclick="sbSearch()">Suchen</button>
  </div>

  <!-- ── RESULT META LINE ── -->
  <div class="sb-result-bar">
    <span id="sb-result-count"><?= count($locations) ?> Spielbanken · <?= count($events) ?> Events</span>
  </div>

  <!-- ── BODY: 2-COLUMN LAYOUT ── -->
  <div class="sb-body">

    <!-- ── LEFT: LOCATION LIST ── -->
    <div class="sb-list" id="sb-list-panel">
      <?php if (empty($locations)): ?>
        <div class="sb-empty">
          <span>Keine Standorte gefunden.</span>
          <small>Bitte scrapen und veröffentlichen im WordPress-Backend.</small>
        </div>
      <?php else: ?>
        <?php foreach ($locations as $i => $loc):
          $stars_full  = min(5, (int)round((float)($loc['rating_value'] ?? 4)));
          $stars_empty = 5 - $stars_full;
          $addr_full   = trim(($loc['street_address']??'').', '.($loc['postal_code']??'').' '.($loc['address_locality']??''), ', ');
          $today_en    = date('l'); // e.g. "Monday"
          $today_hours = null;
          foreach ($loc['hours'] as $h) {
              if ($h['day_of_week'] === $today_en) { $today_hours = $h; break; }
          }
          $is_open_now = false;
          if ($today_hours) {
              $now   = (int)date('Hi');
              $opens = (int)str_replace(':', '', substr($today_hours['opens'], 0, 5));
              $close = (int)str_replace(':', '', substr($today_hours['closes'], 0, 5));
              $is_open_now = $close < $opens
                  ? ($now >= $opens || $now < $close)   // overnight
                  : ($now >= $opens && $now < $close);
          }
        ?>
        <div class="sb-casino-card<?= $i===0?' active':'' ?>"
             id="sb-card-<?= $i ?>"
             onclick="sbSelectLocation(<?= $i ?>)"
             data-loc-id="<?= (int)$loc['id'] ?>"
             data-city="<?= esc_attr(strtolower($loc['address_locality']??'')) ?>">

          <!-- Thumbnail -->
          <div class="sb-casino-thumb"
               <?php if (!empty($loc['image_url'])): ?>
                 style="background-image:url('<?= esc_url($loc['image_url']) ?>')"
               <?php else: ?>
                 style="background:linear-gradient(135deg,<?= sbe_loc_color($i,0) ?>,<?= sbe_loc_color($i,1) ?>)"
               <?php endif; ?>>
            <?php if (empty($loc['image_url'])): ?>
              <svg viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="1.5" width="30" height="30">
                <rect x="3" y="3" width="18" height="18" rx="2"/><path d="M3 9h18M9 21V9"/>
              </svg>
            <?php endif; ?>
            <?php if (!empty($loc['price_range'])): ?>
              <span class="sb-thumb-price"><?= esc_html($loc['price_range']) ?></span>
            <?php endif; ?>
          </div>

          <!-- Info -->
          <div class="sb-casino-info">
            <div class="sb-casino-name"><?= esc_html($loc['name']) ?></div>

            <!-- Rating row -->
            <div class="sb-casino-rating-row">
              <span class="sb-casino-stars">
                <?= str_repeat('★', $stars_full) . str_repeat('☆', $stars_empty) ?>
              </span>
              <?php if (!empty($loc['rating_value'])): ?>
                <span class="sb-casino-rating-val"><?= number_format((float)$loc['rating_value'], 1) ?></span>
              <?php endif; ?>
              <?php if (!empty($loc['review_count'])): ?>
                <span class="sb-casino-reviews">(<?= number_format((int)$loc['review_count']) ?>)</span>
              <?php endif; ?>
            </div>

            <!-- Address -->
            <?php if ($addr_full): ?>
              <div class="sb-casino-addr">
                <svg width="10" height="12" viewBox="0 0 12 16" fill="currentColor"><path d="M6 0C3.24 0 1 2.24 1 5c0 3.75 5 11 5 11s5-7.25 5-11c0-2.76-2.24-5-5-5zm0 7.5C4.62 7.5 3.5 6.38 3.5 5S4.62 2.5 6 2.5 8.5 3.62 8.5 5 7.38 7.5 6 7.5z"/></svg>
                <?= esc_html($addr_full) ?>
              </div>
            <?php endif; ?>

            <!-- Phone -->
            <?php if (!empty($loc['telephone'])): ?>
              <div class="sb-casino-phone">
                <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 16.92v3a2 2 0 01-2.18 2 19.79 19.79 0 01-8.63-3.07A19.5 19.5 0 013.07 9.81 19.79 19.79 0 01.03 1.18 2 2 0 012 .02h3a2 2 0 012 1.72c.127.96.361 1.903.7 2.81a2 2 0 01-.45 2.11L6.09 7.91a16 16 0 006 6l1.27-1.27a2 2 0 012.11-.45c.907.339 1.85.573 2.81.7A2 2 0 0122 16.92z"/></svg>
                <a href="tel:<?= esc_attr($loc['telephone']) ?>"><?= esc_html($loc['telephone']) ?></a>
              </div>
            <?php endif; ?>

            <!-- Today's hours -->
            <div class="sb-casino-hours-today">
              <?php if ($today_hours): ?>
                <span class="sb-open-dot sb-open-dot--<?= $is_open_now?'open':'closed' ?>"></span>
                <span class="sb-open-label"><?= $is_open_now ? 'Geöffnet' : 'Geschlossen' ?></span>
                <span class="sb-hours-time"><?= esc_html(substr($today_hours['opens'],0,5)) ?>–<?= esc_html(substr($today_hours['closes'],0,5)) ?> Uhr</span>
              <?php else: ?>
                <span class="sb-open-dot sb-open-dot--unknown"></span>
                <span class="sb-open-label">Öffnungszeiten unbekannt</span>
              <?php endif; ?>
            </div>

            <!-- Amenities -->
            <?php if (!empty($loc['amenities'])): ?>
              <div class="sb-casino-amenities">
                <?php foreach (array_slice($loc['amenities'], 0, 4) as $am):
                  if (empty($am['value']) || $am['value'] === false || $am['value'] === 'false') continue; ?>
                  <span class="sb-amenity-pill"><?= esc_html($am['name']) ?></span>
                <?php endforeach; ?>
              </div>
            <?php endif; ?>

            <!-- Footer row -->
            <div class="sb-casino-footer">
              <span class="sb-casino-badge">
                <?= $loc['event_count'] ?> Event<?= $loc['event_count'] !== 1 ? 's' : '' ?>
              </span>
              <?php if (!empty($loc['url'])): ?>
                <a class="sb-casino-website" href="<?= esc_url($loc['url']) ?>" target="_blank" rel="noopener" onclick="event.stopPropagation()">
                  Website →
                </a>
              <?php endif; ?>
            </div>
          </div>
        </div>
        <?php endforeach; ?>
      <?php endif; ?>
    </div><!-- /sb-list -->

    <!-- ── RIGHT COLUMN ── -->
    <div class="sb-right">

      <!-- ── MAP PLACEHOLDER ── -->
      <div class="sb-map" id="sb-map-area">
        <!-- Grid + street skeleton -->
        <div class="sb-map-bg"></div>
        <div class="sb-map-street h major" style="top:32%"></div>
        <div class="sb-map-street h" style="top:18%"></div>
        <div class="sb-map-street h" style="top:52%"></div>
        <div class="sb-map-street h" style="top:70%"></div>
        <div class="sb-map-street h" style="top:84%"></div>
        <div class="sb-map-street v major" style="left:28%"></div>
        <div class="sb-map-street v" style="left:14%"></div>
        <div class="sb-map-street v" style="left:48%"></div>
        <div class="sb-map-street v" style="left:66%"></div>
        <div class="sb-map-street v" style="left:80%"></div>
        <div class="sb-map-diagonal"></div>
        <div class="sb-map-water" style="width:110px;height:55px;top:12%;left:54%;transform:rotate(-12deg)"></div>
        <!-- Pins: positions spread across the placeholder -->
        <?php
        $pin_positions = [[30,40],[20,60],[44,54],[25,22],[52,68],[35,80],[15,45]];
        foreach (array_slice($locations, 0, count($pin_positions)) as $pi => $ploc):
          $pp = $pin_positions[$pi];
        ?>
          <div class="sb-map-pin<?= $pi===0?' active':'' ?>"
               id="sb-pin-<?= $pi ?>"
               style="top:<?= $pp[0] ?>%;left:<?= $pp[1] ?>%"
               onclick="sbSelectLocation(<?= $pi ?>)">
            <div class="sb-map-pin-dot<?= $pi===0?' active':'' ?>"></div>
            <?php if ($pi === 0): ?>
              <div class="sb-map-label"><?= esc_html($ploc['name']) ?></div>
            <?php endif; ?>
          </div>
        <?php endforeach; ?>
        <!-- Controls -->
        <div class="sb-map-fullscreen" title="Vollbild">
          <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="#555" stroke-width="2">
            <path d="M15 3h6v6M9 21H3v-6M21 3l-7 7M3 21l7-7"/>
          </svg>
        </div>
        <div class="sb-map-corner">
          <div class="sb-map-ctrl">+</div>
          <div class="sb-map-ctrl">−</div>
        </div>
        <div class="sb-map-attrib">Kartenplatzhalter – In Produktion: Google Maps / Leaflet einbinden</div>
      </div>

      <!-- ── EVENTS PANEL ── -->
      <div class="sb-events">

        <!-- Header row -->
        <div class="sb-events-header">
          <div class="sb-events-title">Events</div>
          <div class="sb-events-meta" id="sb-events-meta">
            <?= count($events) ?> Veranstaltungen
          </div>
        </div>

        <!-- Category tabs – only show types that actually have events -->
        <div class="sb-tabs" id="sb-tabs">
          <button class="sb-tab active" data-cat="all">Alle</button>
          <?php foreach (['Poker','Blackjack','Roulette','Slots','Musik','Sonstige'] as $type):
            if (!in_array($type, $present_types)) continue; ?>
            <button class="sb-tab" data-cat="<?= esc_attr($type) ?>"><?= esc_html($type) ?></button>
          <?php endforeach; ?>
        </div>

        <!-- Event grid -->
        <div class="sb-events-grid" id="sb-events-grid">
          <?php if (empty($events)): ?>
            <div class="sb-empty sb-empty--events">
              <span>Keine Events veröffentlicht.</span>
              <small>Im Backend Events genehmigen und veröffentlichen.</small>
            </div>
          <?php else: ?>
            <?php foreach (array_slice($events, 0, 6) as $ev): ?>
              <?= sbe_render_event_card($ev) ?>
            <?php endforeach; ?>
          <?php endif; ?>
        </div>

        <?php if (count($events) > 6): ?>
          <div class="sb-events-more">
            <a href="#" id="sb-show-more">Alle <?= count($events) ?> Events anzeigen →</a>
          </div>
        <?php endif; ?>

        <!-- Selected location detail panel (shown when casino card is clicked) -->
        <div class="sb-loc-detail" id="sb-loc-detail" style="display:none">
          <div class="sb-loc-detail-header">
            <strong id="sb-loc-detail-name"></strong>
            <button class="sb-loc-detail-close" onclick="sbCloseDetail()">✕</button>
          </div>
          <div class="sb-loc-detail-body" id="sb-loc-detail-body"></div>
        </div>

      </div><!-- /sb-events -->
    </div><!-- /sb-right -->
  </div><!-- /sb-body -->
</div><!-- /sb-app -->

<!-- ── DATA FOR JS ── -->
<script>
/* phpcs:disable */
var SBE_LOCATIONS = <?= json_encode($js_locations, JSON_HEX_TAG | JSON_HEX_APOS | JSON_UNESCAPED_UNICODE) ?>;
var SBE_EVENTS    = <?= json_encode($js_events,    JSON_HEX_TAG | JSON_HEX_APOS | JSON_UNESCAPED_UNICODE) ?>;
/* phpcs:enable */
</script>
<script>
(function () {
  'use strict';

  /* ── Constants ─────────────────────────────────────────────────────────── */
  var DE_MONTHS = ['Jan','Feb','Mär','Apr','Mai','Jun','Jul','Aug','Sep','Okt','Nov','Dez'];
  var DE_DAYS   = {Monday:'Mo',Tuesday:'Di',Wednesday:'Mi',Thursday:'Do',Friday:'Fr',Saturday:'Sa',Sunday:'So'};
  var ICONS     = {Poker:'♠',Blackjack:'♣',Roulette:'○',Slots:'◈',Musik:'♪',Sonstige:'◆'};
  var DAY_ORDER = ['Monday','Tuesday','Wednesday','Thursday','Friday','Saturday','Sunday'];

  var currentLocIdx = 0;
  var currentCat    = 'all';
  var currentCity   = '';

  /* ── Date / time helpers ──────────────────────────────────────────────── */
  function fmtDate(iso) {
    if (!iso) return '–';
    var d = new Date(iso.replace(' ', 'T'));
    if (isNaN(d)) return iso;
    return d.getDate() + '. ' + DE_MONTHS[d.getMonth()] + ' ' + d.getFullYear()
         + ', ' + pad(d.getHours()) + ':' + pad(d.getMinutes()) + ' Uhr';
  }
  function fmtDateShort(iso) {
    if (!iso) return '';
    var d = new Date(iso.replace(' ', 'T'));
    if (isNaN(d)) return '';
    return d.getDate() + '. ' + DE_MONTHS[d.getMonth()] + ', ' + pad(d.getHours()) + ':' + pad(d.getMinutes()) + ' Uhr';
  }
  function pad(n) { return n < 10 ? '0' + n : '' + n; }

  function isOpenNow(hours) {
    if (!hours || !hours.length) return null;
    var dayEn = ['Sunday','Monday','Tuesday','Wednesday','Thursday','Friday','Saturday'][new Date().getDay()];
    var h = hours.find(function(x){ return x.day === dayEn; });
    if (!h) return null;
    var now = new Date(), nowMins = now.getHours() * 60 + now.getMinutes();
    var opMins = timeToMins(h.opens), clMins = timeToMins(h.closes);
    if (clMins < opMins) return nowMins >= opMins || nowMins < clMins;
    return nowMins >= opMins && nowMins < clMins;
  }
  function timeToMins(t) {
    var p = (t||'').split(':');
    return parseInt(p[0]||0) * 60 + parseInt(p[1]||0);
  }
  function todayHours(hours) {
    if (!hours || !hours.length) return null;
    var dayEn = ['Sunday','Monday','Tuesday','Wednesday','Thursday','Friday','Saturday'][new Date().getDay()];
    return hours.find(function(x){ return x.day === dayEn; }) || null;
  }

  /* ── Escape HTML ──────────────────────────────────────────────────────── */
  function esc(s) {
    return String(s || '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
  }

  /* ── Render: event card (full detail) ─────────────────────────────────── */
  function renderEventCard(ev) {
    var icon     = ICONS[ev.event_type] || '◆';
    var dateStr  = fmtDateShort(ev.start_date);
    var endStr   = ev.end_date ? ' – ' + fmtDateShort(ev.end_date) : '';
    var hasEnd   = !!ev.end_date;
    var desc     = ev.description ? ev.description.substring(0, 120) + (ev.description.length > 120 ? '…' : '') : '';
    var hasTicket= !!ev.ticket_url;
    var hasPrice = !!ev.price;
    var hasImg   = !!ev.image_url;

    var html = '<div class="sb-event-card" data-cat="' + esc(ev.event_type) + '" data-city="' + esc((ev.city||'').toLowerCase()) + '" data-loc-id="' + ev.location_id + '">';

    // Image banner (if available)
    if (hasImg) {
      html += '<div class="sb-event-img" style="background-image:url(\'' + esc(ev.image_url) + '\')"></div>';
    }

    html += '<div class="sb-event-inner">';

    // Top row: type chip + date
    html += '<div class="sb-event-top">'
          +   '<span class="sb-event-type-chip sb-chip--' + esc((ev.event_type||'sonstige').toLowerCase()) + '">'
          +     icon + ' ' + esc(ev.event_type || 'Sonstige')
          +   '</span>';
    if (dateStr) {
      html += '<span class="sb-event-date-chip">' + esc(dateStr) + (endStr ? '<br><small>' + esc(endStr.replace(' – ','')) + '</small>' : '') + '</span>';
    }
    html += '</div>';

    // Title
    html += '<div class="sb-event-name">' + esc(ev.name) + '</div>';

    // Organizer / location
    var venue = ev.organizer || ev.location || '';
    if (venue) {
      html += '<div class="sb-event-venue">'
            + '<svg width="10" height="12" viewBox="0 0 12 16" fill="currentColor"><path d="M6 0C3.24 0 1 2.24 1 5c0 3.75 5 11 5 11s5-7.25 5-11c0-2.76-2.24-5-5-5zm0 7.5C4.62 7.5 3.5 6.38 3.5 5S4.62 2.5 6 2.5 8.5 3.62 8.5 5 7.38 7.5 6 7.5z"/></svg>'
            + esc(venue)
            + (ev.city ? ' · ' + esc(ev.city) : '')
            + '</div>';
    }

    // Description
    if (desc) {
      html += '<div class="sb-event-desc">' + esc(desc) + '</div>';
    }

    // Footer: price + ticket link
    if (hasTicket || hasPrice || ev.url) {
      html += '<div class="sb-event-footer">';
      if (hasPrice) {
        html += '<span class="sb-event-price">' + esc(ev.price) + ' ' + esc(ev.price_currency || 'EUR') + '</span>';
      }
      if (hasTicket) {
        html += '<a class="sb-event-ticket" href="' + esc(ev.ticket_url) + '" target="_blank" rel="noopener" onclick="event.stopPropagation()">Tickets →</a>';
      } else if (ev.url) {
        html += '<a class="sb-event-link" href="' + esc(ev.url) + '" target="_blank" rel="noopener" onclick="event.stopPropagation()">Mehr erfahren →</a>';
      }
      html += '</div>';
    }

    html += '</div></div>'; // /sb-event-inner /sb-event-card
    return html;
  }

  /* ── Render: location detail panel ────────────────────────────────────── */
  function renderLocDetail(loc) {
    var open = isOpenNow(loc.hours);
    var th   = todayHours(loc.hours);
    var html = '';

    // Status + contact
    html += '<div class="sb-ld-row sb-ld-row--status">';
    if (open !== null) {
      html += '<span class="sb-open-dot sb-open-dot--' + (open?'open':'closed') + '"></span>'
            + '<strong>' + (open ? 'Jetzt geöffnet' : 'Jetzt geschlossen') + '</strong>';
      if (th) html += '<span class="sb-ld-hours"> · heute ' + esc(th.opens.substring(0,5)) + '–' + esc(th.closes.substring(0,5)) + ' Uhr</span>';
    }
    html += '</div>';

    if (loc.telephone) {
      html += '<div class="sb-ld-row"><svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 16.92v3a2 2 0 01-2.18 2 19.79 19.79 0 01-8.63-3.07A19.5 19.5 0 013.07 9.81 19.79 19.79 0 01.03 1.18 2 2 0 012 .02h3a2 2 0 012 1.72c.127.96.361 1.903.7 2.81a2 2 0 01-.45 2.11L6.09 7.91a16 16 0 006 6l1.27-1.27a2 2 0 012.11-.45c.907.339 1.85.573 2.81.7A2 2 0 0122 16.92z"/></svg> <a href="tel:' + esc(loc.telephone) + '">' + esc(loc.telephone) + '</a></div>';
    }
    if (loc.email) {
      html += '<div class="sb-ld-row"><svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg> <a href="mailto:' + esc(loc.email) + '">' + esc(loc.email) + '</a></div>';
    }
    if (loc.street_address || loc.city) {
      html += '<div class="sb-ld-row"><svg width="10" height="12" viewBox="0 0 12 16" fill="currentColor"><path d="M6 0C3.24 0 1 2.24 1 5c0 3.75 5 11 5 11s5-7.25 5-11c0-2.76-2.24-5-5-5zm0 7.5C4.62 7.5 3.5 6.38 3.5 5S4.62 2.5 6 2.5 8.5 3.62 8.5 5 7.38 7.5 6 7.5z"/></svg> '
            + esc(loc.street_address) + (loc.city ? ', ' + esc(loc.postal_code) + ' ' + esc(loc.city) : '') + '</div>';
    }

    // Full opening hours table
    if (loc.hours && loc.hours.length) {
      var todayEn = ['Sunday','Monday','Tuesday','Wednesday','Thursday','Friday','Saturday'][new Date().getDay()];
      html += '<div class="sb-ld-hours-title">Öffnungszeiten</div>'
            + '<table class="sb-ld-hours-table">';
      DAY_ORDER.forEach(function(day) {
        var h = loc.hours.find(function(x){ return x.day === day; });
        var isToday = day === todayEn;
        html += '<tr class="' + (isToday ? 'sb-ld-today' : '') + '">'
              + '<td>' + (DE_DAYS[day] || day) + '</td>'
              + '<td>' + (h ? esc(h.opens.substring(0,5)) + ' – ' + esc(h.closes.substring(0,5)) + ' Uhr' : '<em>Geschlossen</em>') + '</td>'
              + (h && h.label ? '<td><small>' + esc(h.label) + '</small></td>' : '<td></td>')
              + '</tr>';
      });
      html += '</table>';
    }

    // Payment / accessibility
    if (loc.payment || loc.is_accessible !== undefined || loc.smoking !== undefined) {
      html += '<div class="sb-ld-extras">';
      if (loc.payment)        html += '<span class="sb-ld-pill">💳 ' + esc(loc.payment) + '</span>';
      if (loc.is_accessible)  html += '<span class="sb-ld-pill">♿ Barrierefrei</span>';
      if (!loc.smoking)       html += '<span class="sb-ld-pill">🚭 Rauchfrei</span>';
      html += '</div>';
    }

    // Map link
    if (loc.has_map) {
      html += '<div class="sb-ld-row"><a href="' + esc(loc.has_map) + '" target="_blank" rel="noopener" class="sb-ld-map-link">📍 In Google Maps anzeigen</a></div>';
    }

    // Amenities
    if (loc.amenities && loc.amenities.length) {
      var activeAm = loc.amenities.filter(function(a){ return a.value && a.value !== 'false'; });
      if (activeAm.length) {
        html += '<div class="sb-ld-amenities">';
        activeAm.forEach(function(a){ html += '<span class="sb-amenity-pill">' + esc(a.name) + '</span>'; });
        html += '</div>';
      }
    }

    return html;
  }

  /* ── Filtering ────────────────────────────────────────────────────────── */
  function getFilteredEvents() {
    return SBE_EVENTS.filter(function(ev) {
      var catOk  = currentCat === 'all' || ev.event_type === currentCat;
      var cityOk = !currentCity || (ev.city || '').toLowerCase().includes(currentCity);
      return catOk && cityOk;
    });
  }

  function renderEventGrid() {
    var grid      = document.getElementById('sb-events-grid');
    var metaEl    = document.getElementById('sb-events-meta');
    var moreEl    = document.getElementById('sb-show-more');
    var filtered  = getFilteredEvents();

    if (!filtered.length) {
      grid.innerHTML = '<div class="sb-empty sb-empty--events"><span>Keine Events für diese Auswahl.</span></div>';
      if (metaEl) metaEl.textContent = '0 Veranstaltungen';
      if (moreEl) moreEl.closest('.sb-events-more') && (moreEl.closest('.sb-events-more').style.display = 'none');
      return;
    }

    grid.innerHTML = filtered.map(renderEventCard).join('');
    if (metaEl) metaEl.textContent = filtered.length + ' Veranstaltung' + (filtered.length !== 1 ? 'en' : '');

    if (moreEl) {
      var moreWrap = moreEl.closest('.sb-events-more');
      if (moreWrap) {
        // Already show all after filtering
        moreWrap.style.display = 'none';
      }
    }
  }

  function renderLocationList() {
    var count = 0;
    document.querySelectorAll('#sb-list-panel .sb-casino-card').forEach(function(card) {
      var city = card.getAttribute('data-city') || '';
      var show = !currentCity || city.includes(currentCity);
      card.style.display = show ? 'flex' : 'none';
      if (show) count++;
    });
    var countEl = document.getElementById('sb-result-count');
    if (countEl) {
      var evCount = getFilteredEvents().length;
      countEl.textContent = count + ' Spielbank' + (count !== 1 ? 'en' : '') + ' · ' + evCount + ' Event' + (evCount !== 1 ? 's' : '');
    }
  }

  /* ── Select location ──────────────────────────────────────────────────── */
  window.sbSelectLocation = function(idx) {
    currentLocIdx = idx;

    // Update active card
    document.querySelectorAll('#sb-list-panel .sb-casino-card').forEach(function(c, i) {
      c.classList.toggle('active', i === idx);
    });

    // Update map pins
    document.querySelectorAll('#sb-map-area .sb-map-pin').forEach(function(p, i) {
      p.classList.toggle('active', i === idx);
      var dot = p.querySelector('.sb-map-pin-dot');
      if (dot) dot.classList.toggle('active', i === idx);
      var label = p.querySelector('.sb-map-label');
      if (label) p.removeChild(label);
      if (i === idx && SBE_LOCATIONS[idx]) {
        var lbl = document.createElement('div');
        lbl.className = 'sb-map-label';
        lbl.textContent = SBE_LOCATIONS[idx].name;
        p.appendChild(lbl);
      }
    });

    // Show location detail panel
    var loc = SBE_LOCATIONS[idx];
    if (loc) {
      var nameEl = document.getElementById('sb-loc-detail-name');
      var bodyEl = document.getElementById('sb-loc-detail-body');
      var panel  = document.getElementById('sb-loc-detail');
      if (nameEl) nameEl.textContent = loc.name;
      if (bodyEl) bodyEl.innerHTML   = renderLocDetail(loc);
      if (panel)  panel.style.display = 'block';

      // Also filter events by this location
      if (loc.city) {
        currentCity = loc.city.toLowerCase();
        var cityInput = document.getElementById('sb-search-city');
        if (cityInput) cityInput.value = loc.city;
        updateClearBtn();
      }
      renderEventGrid();
    }
  };

  window.sbCloseDetail = function() {
    var panel = document.getElementById('sb-loc-detail');
    if (panel) panel.style.display = 'none';
  };

  /* ── Search ───────────────────────────────────────────────────────────── */
  window.sbSearch = function() {
    var cityInput = document.getElementById('sb-search-city');
    var catSelect = document.getElementById('sb-search-cat');
    currentCity = (cityInput ? cityInput.value.toLowerCase().trim() : '');
    currentCat  = (catSelect ? catSelect.value : 'all') || 'all';

    // Sync tabs with category select
    document.querySelectorAll('#sb-app .sb-tab').forEach(function(t) {
      t.classList.toggle('active', t.getAttribute('data-cat') === currentCat);
    });

    updateClearBtn();
    renderLocationList();
    renderEventGrid();
  };

  window.sbClearCity = function() {
    var cityInput = document.getElementById('sb-search-city');
    if (cityInput) cityInput.value = '';
    currentCity = '';
    updateClearBtn();
    renderLocationList();
    renderEventGrid();
  };

  function updateClearBtn() {
    var btn = document.getElementById('sb-city-clear');
    if (btn) btn.style.display = currentCity ? 'block' : 'none';
  }

  // Live search on keyup
  var cityInput = document.getElementById('sb-search-city');
  if (cityInput) {
    cityInput.addEventListener('input', function() {
      currentCity = this.value.toLowerCase().trim();
      updateClearBtn();
      renderLocationList();
      renderEventGrid();
    });
    cityInput.addEventListener('keydown', function(e) {
      if (e.key === 'Enter') sbSearch();
    });
  }

  // Category select syncs with tabs
  var catSelect = document.getElementById('sb-search-cat');
  if (catSelect) {
    catSelect.addEventListener('change', function() {
      currentCat = this.value || 'all';
      document.querySelectorAll('#sb-app .sb-tab').forEach(function(t) {
        t.classList.toggle('active', t.getAttribute('data-cat') === currentCat);
      });
      renderEventGrid();
    });
  }

  /* ── Category tabs ────────────────────────────────────────────────────── */
  document.querySelectorAll('#sb-app .sb-tab').forEach(function(tab) {
    tab.addEventListener('click', function() {
      document.querySelectorAll('#sb-app .sb-tab').forEach(function(t) { t.classList.remove('active'); });
      tab.classList.add('active');
      currentCat = tab.getAttribute('data-cat');
      // Sync dropdown
      var sel = document.getElementById('sb-search-cat');
      if (sel) sel.value = currentCat === 'all' ? '' : currentCat;
      renderEventGrid();
    });
  });

  /* ── Show more ────────────────────────────────────────────────────────── */
  var showMoreBtn = document.getElementById('sb-show-more');
  if (showMoreBtn) {
    showMoreBtn.addEventListener('click', function(e) {
      e.preventDefault();
      renderEventGrid();
      var wrap = this.closest('.sb-events-more');
      if (wrap) wrap.style.display = 'none';
    });
  }

})();
</script>
<?php
    return ob_get_clean();
}

// ─────────────────────────────────────────────────────────────────────────────
// EVENT CARD (PHP – server-rendered initial batch)
// ─────────────────────────────────────────────────────────────────────────────
function sbe_render_event_card(array $ev): string {
    $icons   = ['Poker'=>'♠','Blackjack'=>'♣','Roulette'=>'○','Slots'=>'◈','Musik'=>'♪','Sonstige'=>'◆'];
    $icon    = $icons[$ev['event_type'] ?? ''] ?? '◆';
    $type    = $ev['event_type'] ?? 'Sonstige';
    $date    = !empty($ev['start_date'])
                 ? date('d. M, H:i', strtotime($ev['start_date'])) . ' Uhr'
                 : '–';
    $end     = !empty($ev['end_date'])
                 ? date('d. M, H:i', strtotime($ev['end_date'])) . ' Uhr'
                 : '';
    $venue   = esc_html($ev['organizer'] ?? $ev['location'] ?? '');
    $city    = esc_html($ev['city'] ?? '');
    $desc    = '';
    if (!empty($ev['description'])) {
        $desc = mb_strlen($ev['description']) > 120
                    ? esc_html(mb_substr($ev['description'], 0, 120)) . '…'
                    : esc_html($ev['description']);
    }
    $type_cls = strtolower($type);

    $html  = '<div class="sb-event-card" data-cat="' . esc_attr($type) . '" data-city="' . esc_attr(strtolower($ev['city']??'')) . '" data-loc-id="' . intval($ev['location_id']??0) . '">';
    if (!empty($ev['image_url'])) {
        $html .= '<div class="sb-event-img" style="background-image:url(\'' . esc_url($ev['image_url']) . '\')"></div>';
    }
    $html .= '<div class="sb-event-inner">';
    $html .= '<div class="sb-event-top">'
           .   '<span class="sb-event-type-chip sb-chip--' . esc_attr($type_cls) . '">' . $icon . ' ' . esc_html($type) . '</span>'
           .   '<span class="sb-event-date-chip">' . esc_html($date) . ($end ? '<br><small>' . esc_html($end) . '</small>' : '') . '</span>'
           . '</div>';
    $html .= '<div class="sb-event-name">' . esc_html($ev['name']) . '</div>';
    if ($venue || $city) {
        $html .= '<div class="sb-event-venue"><svg width="10" height="12" viewBox="0 0 12 16" fill="currentColor"><path d="M6 0C3.24 0 1 2.24 1 5c0 3.75 5 11 5 11s5-7.25 5-11c0-2.76-2.24-5-5-5zm0 7.5C4.62 7.5 3.5 6.38 3.5 5S4.62 2.5 6 2.5 8.5 3.62 8.5 5 7.38 7.5 6 7.5z"/></svg> '
               . $venue . ($city ? ' · ' . $city : '') . '</div>';
    }
    if ($desc) {
        $html .= '<div class="sb-event-desc">' . $desc . '</div>';
    }
    $has_footer = !empty($ev['ticket_url']) || !empty($ev['price']) || !empty($ev['url']);
    if ($has_footer) {
        $html .= '<div class="sb-event-footer">';
        if (!empty($ev['price'])) {
            $html .= '<span class="sb-event-price">' . esc_html($ev['price']) . ' ' . esc_html($ev['price_currency']??'EUR') . '</span>';
        }
        if (!empty($ev['ticket_url'])) {
            $html .= '<a class="sb-event-ticket" href="' . esc_url($ev['ticket_url']) . '" target="_blank" rel="noopener" onclick="event.stopPropagation()">Tickets →</a>';
        } elseif (!empty($ev['url'])) {
            $html .= '<a class="sb-event-link" href="' . esc_url($ev['url']) . '" target="_blank" rel="noopener" onclick="event.stopPropagation()">Mehr erfahren →</a>';
        }
        $html .= '</div>';
    }
    $html .= '</div></div>';
    return $html;
}

// ─────────────────────────────────────────────────────────────────────────────
// COLOUR HELPER
// ─────────────────────────────────────────────────────────────────────────────
function sbe_loc_color(int $i, int $slot): string {
    $pairs = [
        ['#8B5A4A','#5a3a2a'],['#5A6B7A','#3a4a5a'],['#7A5A6B','#4a3a4a'],
        ['#6B7A5A','#4a5a3a'],['#7A6B4A','#5a4a2a'],['#5A7A6B','#3a5a4a'],
    ];
    return $pairs[$i % count($pairs)][$slot] ?? '#555';
}

// ─────────────────────────────────────────────────────────────────────────────
// JSON-LD  (all three schema types in @graph)
// ─────────────────────────────────────────────────────────────────────────────
function sbe_render_jsonld(array $locations, array $events): string {
    $graph = [];

    foreach ($locations as $loc) {
        $hours_specs = [];
        foreach ($loc['hours'] ?? [] as $h) {
            $spec = [
                '@type'     => 'OpeningHoursSpecification',
                'dayOfWeek' => 'https://schema.org/' . $h['day_of_week'],
                'opens'     => substr($h['opens'],  0, 5),
                'closes'    => substr($h['closes'], 0, 5),
            ];
            if (!empty($h['valid_from']))    $spec['validFrom']    = $h['valid_from'];
            if (!empty($h['valid_through'])) $spec['validThrough'] = $h['valid_through'];
            $hours_specs[] = $spec;
        }

        $biz = [
            '@type' => ['GamblingResort', 'LocalBusiness'],
            '@id'   => ($loc['url'] ?: get_permalink()) . '#location-' . $loc['id'],
            'name'  => $loc['name'],
        ];
        if (!empty($loc['alternate_name'])) $biz['alternateName']      = $loc['alternate_name'];
        if (!empty($loc['description']))    $biz['description']        = $loc['description'];
        if (!empty($loc['url']))            $biz['url']                = $loc['url'];
        if (!empty($loc['telephone']))      $biz['telephone']          = $loc['telephone'];
        if (!empty($loc['email']))          $biz['email']              = $loc['email'];
        if (!empty($loc['image_url']))      $biz['image']              = $loc['image_url'];
        if (!empty($loc['logo_url']))       $biz['logo']               = ['@type'=>'ImageObject','url'=>$loc['logo_url']];
        if (!empty($loc['price_range']))    $biz['priceRange']         = $loc['price_range'];
        if (!empty($loc['currencies_accepted'])) $biz['currenciesAccepted'] = $loc['currencies_accepted'];
        if (!empty($loc['payment_accepted']))    $biz['paymentAccepted']    = $loc['payment_accepted'];
        if (!empty($loc['has_map']))             $biz['hasMap']             = $loc['has_map'];
        $biz['isAccessibleForFree'] = (bool)($loc['is_accessible'] ?? 0);
        $biz['smokingAllowed']      = (bool)($loc['smoking_allowed'] ?? 0);

        $biz['address'] = array_filter([
            '@type'           => 'PostalAddress',
            'streetAddress'   => $loc['street_address']   ?? null,
            'addressLocality' => $loc['address_locality'] ?? null,
            'postalCode'      => $loc['postal_code']       ?? null,
            'addressRegion'   => $loc['address_region']   ?? null,
            'addressCountry'  => $loc['address_country']  ?? 'DE',
        ]);

        if (!empty($loc['latitude']) && !empty($loc['longitude'])) {
            $biz['geo'] = [
                '@type'     => 'GeoCoordinates',
                'latitude'  => (float)$loc['latitude'],
                'longitude' => (float)$loc['longitude'],
            ];
        }

        if (!empty($loc['rating_value'])) {
            $biz['aggregateRating'] = [
                '@type'       => 'AggregateRating',
                'ratingValue' => (float)$loc['rating_value'],
                'reviewCount' => (int)($loc['review_count'] ?? 0),
                'bestRating'  => (float)($loc['best_rating']  ?? 5.0),
                'worstRating' => 1,
            ];
        }

        $sa = json_decode($loc['same_as'] ?? '', true);
        if (is_array($sa) && !empty($sa)) $biz['sameAs'] = $sa;

        $af = json_decode($loc['amenity_features'] ?? '', true);
        if (is_array($af) && !empty($af)) {
            $biz['amenityFeature'] = array_map(fn($f) => [
                '@type' => 'LocationFeatureSpecification',
                'name'  => $f['name'],
                'value' => $f['value'],
            ], $af);
        }

        if (!empty($hours_specs)) $biz['openingHoursSpecification'] = $hours_specs;

        $graph[] = $biz;
    }

    foreach ($events as $ev) {
        $item = [
            '@type'               => 'Event',
            'name'                => $ev['name'],
            'eventStatus'         => 'https://schema.org/EventScheduled',
            'eventAttendanceMode' => 'https://schema.org/OfflineEventAttendanceMode',
        ];
        if (!empty($ev['description'])) $item['description'] = $ev['description'];
        if (!empty($ev['url']))         $item['url']         = $ev['url'];
        if (!empty($ev['image_url']))   $item['image']       = $ev['image_url'];
        if (!empty($ev['start_date'])) $item['startDate']   = $ev['start_date'];
        if (!empty($ev['end_date']))   $item['endDate']     = $ev['end_date'];

        if (!empty($ev['location'])) {
            $item['location'] = array_filter([
                '@type'   => 'Place',
                'name'    => $ev['location'],
                'address' => array_filter([
                    '@type'           => 'PostalAddress',
                    'streetAddress'   => $ev['address'] ?? null,
                    'addressLocality' => $ev['city']    ?? null,
                    'addressCountry'  => 'DE',
                ]),
            ]);
        }
        if (!empty($ev['organizer'])) {
            $item['organizer'] = ['@type'=>'GamblingResort','name'=>$ev['organizer']];
        }
        if (!empty($ev['ticket_url']) || !empty($ev['price'])) {
            $offer = ['@type'=>'Offer','availability'=>'https://schema.org/InStock'];
            if (!empty($ev['ticket_url']))     $offer['url']           = $ev['ticket_url'];
            if (!empty($ev['price']))          $offer['price']         = $ev['price'];
            if (!empty($ev['price_currency'])) $offer['priceCurrency'] = $ev['price_currency'];
            $item['offers'] = $offer;
        }
        $graph[] = $item;
    }

    if (empty($graph)) return '';
    return '<script type="application/ld+json">'
         . json_encode(['@context'=>'https://schema.org','@graph'=>$graph],
                       JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
         . '</script>' . "\n";
}
