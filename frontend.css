<?php
defined('ABSPATH') || exit;

/**
 * Public REST API  –  /wp-json/spielbank-events/v1/
 *
 * Endpoints:
 *   GET /wp-json/spielbank-events/v1/feed
 *       Returns all active locations (with today's hours) +
 *       all published events in one response.
 *       No authentication required – read-only, public data only.
 *
 *   GET /wp-json/spielbank-events/v1/locations
 *       Active locations with full hours array.
 *
 *   GET /wp-json/spielbank-events/v1/events
 *       Published events. Optional ?type=Poker to filter by event_type.
 */

add_action('rest_api_init', 'sbe_register_rest_routes');

function sbe_register_rest_routes(): void {
    $ns = 'spielbank-events/v1';

    // Combined feed (locations + events) – primary endpoint for the standalone HTML
    register_rest_route($ns, '/feed', [
        'methods'             => WP_REST_Server::READABLE,
        'callback'            => 'sbe_rest_feed',
        'permission_callback' => '__return_true',  // public read-only
    ]);

    // Locations only
    register_rest_route($ns, '/locations', [
        'methods'             => WP_REST_Server::READABLE,
        'callback'            => 'sbe_rest_locations',
        'permission_callback' => '__return_true',
    ]);

    // Events only
    register_rest_route($ns, '/events', [
        'methods'             => WP_REST_Server::READABLE,
        'callback'            => 'sbe_rest_events',
        'permission_callback' => '__return_true',
        'args'                => [
            'type' => [
                'required'          => false,
                'sanitize_callback' => 'sanitize_text_field',
            ],
            'city' => [
                'required'          => false,
                'sanitize_callback' => 'sanitize_text_field',
            ],
        ],
    ]);
}

// ── CORS: allow any origin to read the public endpoints ───────────────────────
add_action('rest_api_init', function() {
    // Only add CORS for our own namespace – don't touch WP core routes
    add_filter('rest_pre_serve_request', 'sbe_rest_cors_headers', 10, 4);
});

function sbe_rest_cors_headers(bool $served, WP_HTTP_Response $result, WP_REST_Request $request, WP_REST_Server $server): bool {
    $route = $request->get_route();
    if (strpos($route, '/spielbank-events/v1/') === false) return $served;

    $allowed_origins = sbe_get_allowed_origins();
    $origin          = $_SERVER['HTTP_ORIGIN'] ?? '';

    if (in_array('*', $allowed_origins, true) || in_array($origin, $allowed_origins, true)) {
        $send_origin = in_array('*', $allowed_origins, true) ? '*' : $origin;
        header('Access-Control-Allow-Origin: '  . $send_origin);
        header('Access-Control-Allow-Methods: GET, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type');
        header('Access-Control-Max-Age: 3600');
        header('Vary: Origin');
    }

    return $served;
}

/**
 * Allowed origins – configurable via wp-config.php:
 *   define('SBE_ALLOWED_ORIGINS', 'https://andere-domain.de,https://test.de');
 * Or set to '*' to allow all (useful for testing):
 *   define('SBE_ALLOWED_ORIGINS', '*');
 */
function sbe_get_allowed_origins(): array {
    if (defined('SBE_ALLOWED_ORIGINS')) {
        $raw = SBE_ALLOWED_ORIGINS;
        if ($raw === '*') return ['*'];
        return array_map('trim', explode(',', $raw));
    }
    // Default: allow all (you can restrict after testing)
    return ['*'];
}

// ── /feed ─────────────────────────────────────────────────────────────────────
function sbe_rest_feed(WP_REST_Request $request): WP_REST_Response {
    return new WP_REST_Response([
        'locations' => sbe_rest_format_locations(),
        'events'    => sbe_rest_format_events(),
        'generated' => current_time('c'),
    ], 200);
}

// ── /locations ────────────────────────────────────────────────────────────────
function sbe_rest_locations(WP_REST_Request $request): WP_REST_Response {
    return new WP_REST_Response(sbe_rest_format_locations(), 200);
}

// ── /events ───────────────────────────────────────────────────────────────────
function sbe_rest_events(WP_REST_Request $request): WP_REST_Response {
    $type = $request->get_param('type') ?? '';
    $city = $request->get_param('city') ?? '';
    return new WP_REST_Response(sbe_rest_format_events($type, $city), 200);
}

// ── Formatters ────────────────────────────────────────────────────────────────

/**
 * Format locations for the API response.
 * Only includes active locations + only the fields this layout actually displays.
 */
function sbe_rest_format_locations(): array {
    $rows   = sbe_get_all_locations();
    $events = sbe_get_published_events();
    $out    = [];

    foreach ($rows as $loc) {
        if (($loc['status'] ?? '') !== 'active') continue;

        $id    = (int)$loc['id'];
        $hours = sbe_get_hours($id);

        // Count published events for this location
        $event_count = count(array_filter($events, function($ev) use ($id, $loc) {
            return (int)($ev['location_id'] ?? 0) === $id
                || ($ev['organizer'] ?? '') === $loc['name']
                || ($ev['location']  ?? '') === $loc['name'];
        }));

        // Build today's hours entry (what the HTML displays)
        $today_en    = date('l'); // e.g. "Monday"
        $today_hours = null;
        foreach ($hours as $h) {
            if ($h['day_of_week'] === $today_en) {
                $today_hours = [
                    'opens'  => substr($h['opens'],  0, 5),
                    'closes' => substr($h['closes'], 0, 5),
                ];
                break;
            }
        }

        $out[] = [
            // Identity – name, city, address
            'id'           => $id,
            'name'         => $loc['name']              ?? '',
            'city'         => $loc['address_locality']  ?? '',
            'addr'         => trim(
                                ($loc['street_address'] ?? '') . ', ' .
                                ($loc['postal_code']    ?? '') . ' ' .
                                ($loc['address_locality'] ?? ''),
                                ', '
                              ),
            // Rating
            'rating'       => $loc['rating_value']  ? (float)$loc['rating_value'] : null,
            'review_count' => $loc['review_count']  ? (int)$loc['review_count']   : null,
            // Business info
            'price_range'  => $loc['price_range']   ?? '',
            'telephone'    => $loc['telephone']     ?? '',
            // Media
            'image_url'    => $loc['image_url']     ?? '',
            // Today's hours (the only hours field rendered in the card)
            'today_hours'  => $today_hours,
            // Event count badge
            'event_count'  => $event_count,
        ];
    }

    return $out;
}

/**
 * Format published events for the API response.
 * Only includes fields rendered by the HTML layout.
 */
function sbe_rest_format_events(string $type_filter = '', string $city_filter = ''): array {
    $rows = sbe_get_published_events();
    $out  = [];

    foreach ($rows as $ev) {
        if ($type_filter && strcasecmp($ev['event_type'] ?? '', $type_filter) !== 0) continue;
        if ($city_filter && stripos($ev['city'] ?? '', $city_filter) === false)       continue;

        $out[] = [
            'id'             => (int)$ev['id'],
            // Display fields
            'name'           => $ev['name']           ?? '',
            'start_date'     => $ev['start_date']     ?? '',
            'end_date'       => $ev['end_date']        ?? '',
            'organizer'      => $ev['organizer']       ?? '',
            'city'           => $ev['city']            ?? '',
            'event_type'     => $ev['event_type']      ?? 'Sonstige',
            // Links
            'url'            => $ev['url']             ?? '',
            'ticket_url'     => $ev['ticket_url']      ?? '',
            // Price
            'price'          => $ev['price']           ?? '',
            'price_currency' => $ev['price_currency']  ?? 'EUR',
            // FK so the HTML can associate event → location
            'location_id'    => (int)($ev['location_id'] ?? 0),
        ];
    }

    return $out;
}
