<?php
/**
 * Plugin Name:  Spielbank Events
 * Plugin URI:   https://spielbank.com.de
 * Description:  Scrapet Spielbank-Daten (LocalBusiness, GamblingResort, OpeningHoursSpecification, Event), verwaltet sie in einer editierbaren Datenbank und veröffentlicht sie per Shortcode.
 * Version:      2.0.0
 * Author:       Spielbank.com.de
 * Text Domain:  spielbank-events
 */

defined('ABSPATH') || exit;

define('SBE_VERSION',    '2.0.0');
define('SBE_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('SBE_PLUGIN_URL', plugin_dir_url(__FILE__));

require_once SBE_PLUGIN_DIR . 'includes/db.php';
require_once SBE_PLUGIN_DIR . 'includes/scraper.php';
require_once SBE_PLUGIN_DIR . 'includes/rest-api.php';
require_once SBE_PLUGIN_DIR . 'admin/admin.php';
require_once SBE_PLUGIN_DIR . 'public/shortcode.php';

register_activation_hook(__FILE__,   'sbe_activate');
register_deactivation_hook(__FILE__, 'sbe_deactivate');

function sbe_activate(): void {
    sbe_create_table();
    sbe_insert_demo_data();
    flush_rewrite_rules();
}
function sbe_deactivate(): void { flush_rewrite_rules(); }

// ── AJAX: Events ─────────────────────────────────────────────────────────────
add_action('wp_ajax_sbe_scrape',          'sbe_ajax_scrape');
add_action('wp_ajax_sbe_save_event',      'sbe_ajax_save_event');
add_action('wp_ajax_sbe_delete_event',    'sbe_ajax_delete_event');
add_action('wp_ajax_sbe_publish',         'sbe_ajax_publish');
add_action('wp_ajax_sbe_get_all_events',  'sbe_ajax_get_all_events');

// ── AJAX: Locations ───────────────────────────────────────────────────────────
add_action('wp_ajax_sbe_get_all_locations', 'sbe_ajax_get_all_locations');
add_action('wp_ajax_sbe_save_location',     'sbe_ajax_save_location');
add_action('wp_ajax_sbe_delete_location',   'sbe_ajax_delete_location');
add_action('wp_ajax_sbe_get_hours',         'sbe_ajax_get_hours');
add_action('wp_ajax_sbe_save_hours',        'sbe_ajax_save_hours');

function sbe_ajax_scrape(): void {
    check_ajax_referer('sbe_nonce', 'nonce');
    if (!current_user_can('manage_options')) wp_die();
    wp_send_json_success(sbe_run_scraper());
}

function sbe_ajax_save_event(): void {
    check_ajax_referer('sbe_nonce', 'nonce');
    if (!current_user_can('manage_options')) wp_die();
    $id   = intval($_POST['id'] ?? 0);
    $data = sbe_sanitize_event_post();
    wp_send_json_success(['id' => sbe_save_event($data, $id)]);
}

function sbe_ajax_delete_event(): void {
    check_ajax_referer('sbe_nonce', 'nonce');
    if (!current_user_can('manage_options')) wp_die();
    sbe_delete_event(intval($_POST['id'] ?? 0));
    wp_send_json_success();
}

function sbe_ajax_publish(): void {
    check_ajax_referer('sbe_nonce', 'nonce');
    if (!current_user_can('manage_options')) wp_die();
    global $wpdb;
    $t = sbe_table('events');
    $wpdb->query("UPDATE `$t` SET status='published' WHERE status='approved'");
    $count = $wpdb->get_var("SELECT COUNT(*) FROM `$t` WHERE status='published'");
    wp_send_json_success(['message' => "$count Events veröffentlicht.", 'count' => $count]);
}

function sbe_ajax_get_all_events(): void {
    check_ajax_referer('sbe_nonce', 'nonce');
    if (!current_user_can('manage_options')) wp_die();
    wp_send_json_success(sbe_get_all_events());
}

function sbe_ajax_get_all_locations(): void {
    check_ajax_referer('sbe_nonce', 'nonce');
    if (!current_user_can('manage_options')) wp_die();
    $locs = sbe_get_all_locations();
    foreach ($locs as &$loc) {
        $loc['hours'] = sbe_get_hours((int)$loc['id']);
    }
    wp_send_json_success($locs);
}

function sbe_ajax_save_location(): void {
    check_ajax_referer('sbe_nonce', 'nonce');
    if (!current_user_can('manage_options')) wp_die();
    $id   = intval($_POST['id'] ?? 0);
    $data = sbe_sanitize_location_post();
    wp_send_json_success(['id' => sbe_save_location($data, $id)]);
}

function sbe_ajax_delete_location(): void {
    check_ajax_referer('sbe_nonce', 'nonce');
    if (!current_user_can('manage_options')) wp_die();
    sbe_delete_location(intval($_POST['id'] ?? 0));
    wp_send_json_success();
}

function sbe_ajax_get_hours(): void {
    check_ajax_referer('sbe_nonce', 'nonce');
    if (!current_user_can('manage_options')) wp_die();
    wp_send_json_success(sbe_get_hours(intval($_POST['location_id'] ?? 0)));
}

function sbe_ajax_save_hours(): void {
    check_ajax_referer('sbe_nonce', 'nonce');
    if (!current_user_can('manage_options')) wp_die();
    $loc_id = intval($_POST['location_id'] ?? 0);
    $rows   = json_decode(stripslashes($_POST['hours'] ?? '[]'), true);
    if (!is_array($rows)) wp_send_json_error('Invalid hours data');
    $clean = [];
    $valid_days = ['Monday','Tuesday','Wednesday','Thursday','Friday','Saturday','Sunday'];
    foreach ($rows as $row) {
        $day = sanitize_text_field($row['day_of_week'] ?? '');
        if (!in_array($day, $valid_days)) continue;
        $clean[] = [
            'day_of_week'   => $day,
            'opens'         => sanitize_text_field($row['opens']  ?? '12:00'),
            'closes'        => sanitize_text_field($row['closes'] ?? '03:00'),
            'valid_from'    => !empty($row['valid_from'])    ? sanitize_text_field($row['valid_from'])    : null,
            'valid_through' => !empty($row['valid_through']) ? sanitize_text_field($row['valid_through']) : null,
            'label'         => !empty($row['label'])         ? sanitize_text_field($row['label'])         : null,
        ];
    }
    sbe_replace_hours($loc_id, $clean);
    wp_send_json_success(['saved' => count($clean)]);
}

// ── Sanitizers ────────────────────────────────────────────────────────────────
function sbe_sanitize_event_post(): array {
    return [
        'name'          => sanitize_text_field($_POST['name']          ?? ''),
        'start_date'    => sanitize_text_field($_POST['start_date']    ?? '') ?: null,
        'end_date'      => sanitize_text_field($_POST['end_date']      ?? '') ?: null,
        'description'   => sanitize_textarea_field($_POST['description'] ?? ''),
        'location'      => sanitize_text_field($_POST['location']      ?? ''),
        'city'          => sanitize_text_field($_POST['city']          ?? ''),
        'address'       => sanitize_text_field($_POST['address']       ?? ''),
        'organizer'     => sanitize_text_field($_POST['organizer']     ?? ''),
        'url'           => esc_url_raw($_POST['url']                   ?? ''),
        'image_url'     => esc_url_raw($_POST['image_url']             ?? ''),
        'event_type'    => sanitize_text_field($_POST['event_type']    ?? ''),
        'ticket_url'    => esc_url_raw($_POST['ticket_url']            ?? ''),
        'price'         => sanitize_text_field($_POST['price']         ?? ''),
        'price_currency'=> sanitize_text_field($_POST['price_currency']?? 'EUR'),
        'status'        => sanitize_text_field($_POST['status']        ?? 'draft'),
        'source'        => sanitize_text_field($_POST['source']        ?? 'manual'),
        'location_id'   => intval($_POST['location_id']                ?? 0) ?: null,
    ];
}

function sbe_sanitize_location_post(): array {
    return [
        'name'               => sanitize_text_field($_POST['name']               ?? ''),
        'alternate_name'     => sanitize_text_field($_POST['alternate_name']     ?? ''),
        'description'        => sanitize_textarea_field($_POST['description']    ?? ''),
        'url'                => esc_url_raw($_POST['url']                        ?? ''),
        'telephone'          => sanitize_text_field($_POST['telephone']          ?? ''),
        'email'              => sanitize_email($_POST['email']                   ?? ''),
        'street_address'     => sanitize_text_field($_POST['street_address']     ?? ''),
        'address_locality'   => sanitize_text_field($_POST['address_locality']   ?? ''),
        'postal_code'        => sanitize_text_field($_POST['postal_code']        ?? ''),
        'address_region'     => sanitize_text_field($_POST['address_region']     ?? ''),
        'address_country'    => sanitize_text_field($_POST['address_country']    ?? 'DE'),
        'latitude'           => !empty($_POST['latitude'])  ? (float)$_POST['latitude']  : null,
        'longitude'          => !empty($_POST['longitude']) ? (float)$_POST['longitude'] : null,
        'price_range'        => sanitize_text_field($_POST['price_range']        ?? ''),
        'currencies_accepted'=> sanitize_text_field($_POST['currencies_accepted']?? ''),
        'payment_accepted'   => sanitize_text_field($_POST['payment_accepted']   ?? ''),
        'is_accessible'      => intval($_POST['is_accessible']                   ?? 0),
        'smoking_allowed'    => intval($_POST['smoking_allowed']                 ?? 0),
        'image_url'          => esc_url_raw($_POST['image_url']                  ?? ''),
        'logo_url'           => esc_url_raw($_POST['logo_url']                   ?? ''),
        'rating_value'       => !empty($_POST['rating_value']) ? (float)$_POST['rating_value'] : null,
        'review_count'       => !empty($_POST['review_count']) ? intval($_POST['review_count']) : null,
        'same_as'            => sanitize_text_field($_POST['same_as']            ?? ''),
        'has_map'            => esc_url_raw($_POST['has_map']                    ?? ''),
        'status'             => sanitize_text_field($_POST['status']             ?? 'active'),
    ];
}
