<?php
defined('ABSPATH') || exit;

/**
 * Drei Tabellen:
 *   wp_spielbank_locations  – GamblingResort / LocalBusiness
 *   wp_spielbank_hours      – OpeningHoursSpecification (n:1 zu locations)
 *   wp_spielbank_events     – Event (n:1 zu locations)
 */

function sbe_table(string $name): string {
    global $wpdb;
    return $wpdb->prefix . 'spielbank_' . $name;
}

// ── Create all tables ─────────────────────────────────────────────────────────
function sbe_create_table(): void {
    global $wpdb;
    $charset = $wpdb->get_charset_collate();
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';

    // 1. LOCATIONS  (LocalBusiness + GamblingResort)
    dbDelta("CREATE TABLE IF NOT EXISTS `" . sbe_table('locations') . "` (
        id                   INT UNSIGNED  NOT NULL AUTO_INCREMENT,

        -- Core identity
        name                 VARCHAR(255)  NOT NULL,
        alternate_name       VARCHAR(255)  DEFAULT NULL,
        description          TEXT          DEFAULT NULL,
        url                  VARCHAR(500)  DEFAULT NULL,

        -- Contact
        telephone            VARCHAR(60)   DEFAULT NULL,
        email                VARCHAR(120)  DEFAULT NULL,
        fax_number           VARCHAR(60)   DEFAULT NULL,

        -- PostalAddress
        street_address       VARCHAR(255)  DEFAULT NULL,
        address_locality     VARCHAR(100)  DEFAULT NULL,
        postal_code          VARCHAR(20)   DEFAULT NULL,
        address_region       VARCHAR(100)  DEFAULT NULL,
        address_country      CHAR(2)       NOT NULL DEFAULT 'DE',

        -- GeoCoordinates
        latitude             DECIMAL(10,7) DEFAULT NULL,
        longitude            DECIMAL(10,7) DEFAULT NULL,

        -- Business
        price_range          VARCHAR(10)   DEFAULT NULL,
        currencies_accepted  VARCHAR(50)   DEFAULT NULL,
        payment_accepted     VARCHAR(255)  DEFAULT NULL,
        is_accessible        TINYINT(1)    DEFAULT 0,
        smoking_allowed      TINYINT(1)    DEFAULT 0,

        -- Media
        image_url            VARCHAR(500)  DEFAULT NULL,
        logo_url             VARCHAR(500)  DEFAULT NULL,

        -- AggregateRating
        rating_value         DECIMAL(3,1)  DEFAULT NULL,
        review_count         INT UNSIGNED  DEFAULT NULL,
        best_rating          DECIMAL(3,1)  DEFAULT 5.0,

        -- sameAs  (JSON array of URLs)
        same_as              TEXT          DEFAULT NULL,
        has_map              VARCHAR(500)  DEFAULT NULL,

        -- amenityFeature  (JSON array)
        amenity_features     TEXT          DEFAULT NULL,

        -- Meta
        source               VARCHAR(100)  DEFAULT NULL,
        status               ENUM('active','inactive','draft') NOT NULL DEFAULT 'active',
        scraped_at           DATETIME      DEFAULT CURRENT_TIMESTAMP,
        updated_at           DATETIME      DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

        PRIMARY KEY (id),
        UNIQUE KEY uq_name_city (name(100), address_locality(50))
    ) $charset;");

    // 2. HOURS  (OpeningHoursSpecification)
    dbDelta("CREATE TABLE IF NOT EXISTS `" . sbe_table('hours') . "` (
        id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
        location_id   INT UNSIGNED NOT NULL,
        day_of_week   VARCHAR(20)  NOT NULL,
        opens         TIME         NOT NULL DEFAULT '12:00:00',
        closes        TIME         NOT NULL DEFAULT '03:00:00',
        valid_from    DATE         DEFAULT NULL,
        valid_through DATE         DEFAULT NULL,
        label         VARCHAR(100) DEFAULT NULL,
        PRIMARY KEY (id),
        KEY idx_location (location_id)
    ) $charset;");

    // 3. EVENTS  (Event)
    dbDelta("CREATE TABLE IF NOT EXISTS `" . sbe_table('events') . "` (
        id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
        location_id     INT UNSIGNED DEFAULT NULL,

        name            VARCHAR(255) NOT NULL,
        start_date      DATETIME     DEFAULT NULL,
        end_date        DATETIME     DEFAULT NULL,
        description     TEXT         DEFAULT NULL,
        url             VARCHAR(500) DEFAULT NULL,
        image_url       VARCHAR(500) DEFAULT NULL,

        location        VARCHAR(255) DEFAULT NULL,
        city            VARCHAR(100) DEFAULT NULL,
        address         VARCHAR(255) DEFAULT NULL,
        organizer       VARCHAR(255) DEFAULT NULL,

        event_type      VARCHAR(100) DEFAULT NULL,

        ticket_url      VARCHAR(500) DEFAULT NULL,
        price           VARCHAR(50)  DEFAULT NULL,
        price_currency  CHAR(3)      DEFAULT 'EUR',

        status          ENUM('scraped','approved','published','draft') NOT NULL DEFAULT 'scraped',
        source          VARCHAR(100) DEFAULT NULL,
        scraped_at      DATETIME     DEFAULT CURRENT_TIMESTAMP,
        updated_at      DATETIME     DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

        PRIMARY KEY (id),
        KEY idx_location (location_id)
    ) $charset;");
}

// ── Locations ─────────────────────────────────────────────────────────────────
function sbe_get_all_locations(): array {
    global $wpdb;
    return $wpdb->get_results(
        "SELECT * FROM `" . sbe_table('locations') . "` ORDER BY name ASC", ARRAY_A
    ) ?: [];
}

function sbe_get_location(int $id): ?array {
    global $wpdb;
    return $wpdb->get_row(
        $wpdb->prepare("SELECT * FROM `" . sbe_table('locations') . "` WHERE id=%d", $id), ARRAY_A
    ) ?: null;
}

function sbe_upsert_location(array $data): int {
    global $wpdb;
    $t = sbe_table('locations');
    $existing = $wpdb->get_var($wpdb->prepare(
        "SELECT id FROM `$t` WHERE name=%s AND address_locality=%s LIMIT 1",
        $data['name'], $data['address_locality'] ?? ''
    ));
    if ($existing) {
        $data['updated_at'] = current_time('mysql');
        $wpdb->update($t, $data, ['id' => $existing]);
        return (int)$existing;
    }
    $data['scraped_at'] = current_time('mysql');
    $wpdb->insert($t, $data);
    return (int)$wpdb->insert_id;
}

function sbe_save_location(array $data, int $id = 0): int {
    global $wpdb;
    $t = sbe_table('locations');
    if ($id > 0) {
        $data['updated_at'] = current_time('mysql');
        $wpdb->update($t, $data, ['id' => $id]);
        return $id;
    }
    $data['scraped_at'] = current_time('mysql');
    $wpdb->insert($t, $data);
    return (int)$wpdb->insert_id;
}

function sbe_delete_location(int $id): void {
    global $wpdb;
    $wpdb->delete(sbe_table('locations'), ['id' => $id], ['%d']);
    $wpdb->delete(sbe_table('hours'),     ['location_id' => $id], ['%d']);
}

// ── Hours ─────────────────────────────────────────────────────────────────────
function sbe_get_hours(int $location_id): array {
    global $wpdb;
    return $wpdb->get_results($wpdb->prepare(
        "SELECT * FROM `" . sbe_table('hours') . "`
         WHERE location_id=%d
         ORDER BY FIELD(day_of_week,'Monday','Tuesday','Wednesday','Thursday','Friday','Saturday','Sunday')",
        $location_id
    ), ARRAY_A) ?: [];
}

function sbe_upsert_hours(int $location_id, string $day, string $opens, string $closes, ?string $label = null): void {
    global $wpdb;
    $t = sbe_table('hours');
    $existing = $wpdb->get_var($wpdb->prepare(
        "SELECT id FROM `$t` WHERE location_id=%d AND day_of_week=%s AND valid_from IS NULL LIMIT 1",
        $location_id, $day
    ));
    $row = ['location_id'=>$location_id,'day_of_week'=>$day,'opens'=>$opens,'closes'=>$closes,'label'=>$label];
    if ($existing) {
        $wpdb->update($t, $row, ['id' => $existing]);
    } else {
        $wpdb->insert($t, $row);
    }
}

function sbe_replace_hours(int $location_id, array $rows): void {
    global $wpdb;
    $wpdb->delete(sbe_table('hours'), ['location_id' => $location_id], ['%d']);
    foreach ($rows as $row) {
        $row['location_id'] = $location_id;
        $wpdb->insert(sbe_table('hours'), $row);
    }
}

// ── Events ────────────────────────────────────────────────────────────────────
function sbe_get_all_events(?string $status = null): array {
    global $wpdb;
    $t = sbe_table('events');
    if ($status) {
        return $wpdb->get_results(
            $wpdb->prepare("SELECT * FROM `$t` WHERE status=%s ORDER BY start_date ASC", $status), ARRAY_A
        ) ?: [];
    }
    return $wpdb->get_results("SELECT * FROM `$t` ORDER BY start_date ASC", ARRAY_A) ?: [];
}

function sbe_get_published_events(): array { return sbe_get_all_events('published'); }

function sbe_save_event(array $data, int $id = 0): int {
    global $wpdb;
    $t = sbe_table('events');
    foreach (['start_date','end_date'] as $f) {
        if (isset($data[$f]) && $data[$f] === '') $data[$f] = null;
    }
    if ($id > 0) {
        $data['updated_at'] = current_time('mysql');
        $wpdb->update($t, $data, ['id' => $id]);
        return $id;
    }
    $data['scraped_at'] = current_time('mysql');
    $wpdb->insert($t, $data);
    return (int)$wpdb->insert_id;
}

function sbe_delete_event(int $id): void {
    global $wpdb;
    $wpdb->delete(sbe_table('events'), ['id' => $id], ['%d']);
}

function sbe_event_exists(string $name, ?string $date): bool {
    global $wpdb;
    $t = sbe_table('events');
    if ($date) {
        return (bool)$wpdb->get_var($wpdb->prepare(
            "SELECT id FROM `$t` WHERE name=%s AND start_date=%s LIMIT 1", $name, $date
        ));
    }
    return (bool)$wpdb->get_var($wpdb->prepare(
        "SELECT id FROM `$t` WHERE name=%s LIMIT 1", $name
    ));
}

// ── Demo data ─────────────────────────────────────────────────────────────────
function sbe_insert_demo_data(): void {
    global $wpdb;
    if ($wpdb->get_var("SELECT COUNT(*) FROM `" . sbe_table('locations') . "`") > 0) return;

    $locations = [
        [
            'loc' => [
                'name'=>'Spielbank Berlin – Casino am Potsdamer Platz',
                'alternate_name'=>'Spielbank Berlin Potsdamer Platz',
                'description'=>'Das Casino am Potsdamer Platz ist das Flaggschiff der Spielbank Berlin – Roulette, Poker, Blackjack und exklusive Events im Herzen Berlins.',
                'url'=>'https://www.spielbank-berlin.de',
                'telephone'=>'+49 30 255990',
                'email'=>'info@spielbank-berlin.de',
                'street_address'=>'Marlene-Dietrich-Platz 1','address_locality'=>'Berlin',
                'postal_code'=>'10785','address_region'=>'Berlin','address_country'=>'DE',
                'latitude'=>52.5094090,'longitude'=>13.3754280,
                'price_range'=>'€€€','currencies_accepted'=>'EUR','payment_accepted'=>'Cash, Credit Card',
                'is_accessible'=>1,'smoking_allowed'=>0,
                'rating_value'=>4.2,'review_count'=>1840,'best_rating'=>5.0,
                'same_as'=>json_encode(['https://www.facebook.com/SpielBankBerlin','https://www.instagram.com/spielbankberlin']),
                'has_map'=>'https://maps.google.com/?q=Spielbank+Berlin+Potsdamer+Platz',
                'amenity_features'=>json_encode([['name'=>'Parkplatz','value'=>true],['name'=>'Bar','value'=>true],['name'=>'Restaurant','value'=>true],['name'=>'Garderobe','value'=>true],['name'=>'Dresscode','value'=>'Elegant casual']]),
                'source'=>'demo','status'=>'active',
            ],
            'hours' => [
                ['Monday','11:00','03:00'],['Tuesday','11:00','03:00'],['Wednesday','11:00','03:00'],
                ['Thursday','11:00','03:00'],['Friday','11:00','04:00'],['Saturday','11:00','04:00'],
                ['Sunday','11:00','03:00'],
            ],
            'events' => [
                ['name'=>'4th German Dealer Championship','start_date'=>'2026-04-28 10:00:00','end_date'=>'2026-04-30 23:59:00','description'=>'Berlin wird zum Zentrum der deutschen Dealer-Elite. Die 4. German Dealer Championship.','url'=>'https://www.spielbank-berlin.de/en/home/','event_type'=>'Sonstige','city'=>'Berlin','address'=>'Marlene-Dietrich-Platz 1, 10785 Berlin'],
                ['name'=>'Triple A Poker Series XII','start_date'=>'2025-12-08 11:00:00','end_date'=>'2025-12-14 23:59:00','description'=>'Garantierter Preispool von 250.000 €. Main Event, Side Events und Satellites.','url'=>'https://www.spielbank-berlin.de/en/news/triple-a-the-poker-tournament-of-poker-tournaments/','event_type'=>'Poker','city'=>'Berlin','address'=>'Marlene-Dietrich-Platz 1, 10785 Berlin'],
                ['name'=>'Valentine\'s Day – Liebe, Spannung & Kulinarik','start_date'=>'2026-02-14 18:00:00','end_date'=>null,'description'=>'Alle vier Standorte der Spielbank Berlin laden zum Valentinstag ein.','url'=>'https://www.spielbank-berlin.de/en/home/','event_type'=>'Sonstige','city'=>'Berlin','address'=>'Marlene-Dietrich-Platz 1, 10785 Berlin'],
            ],
        ],
        [
            'loc' => [
                'name'=>'Spielbank Berlin – Casino am Fernsehturm',
                'alternate_name'=>'Spielbank Berlin Fernsehturm',
                'description'=>'Das Casino am Fernsehturm bietet Spielvergnügen direkt am Alexanderplatz im Herzen von Berlin-Mitte.',
                'url'=>'https://www.spielbank-berlin.de',
                'telephone'=>'+49 30 255990',
                'street_address'=>'Panoramastraße 1A','address_locality'=>'Berlin',
                'postal_code'=>'10178','address_region'=>'Berlin','address_country'=>'DE',
                'latitude'=>52.5208890,'longitude'=>13.4093670,
                'price_range'=>'€€','currencies_accepted'=>'EUR','payment_accepted'=>'Cash, Credit Card',
                'is_accessible'=>1,'smoking_allowed'=>0,
                'rating_value'=>4.0,'review_count'=>940,'best_rating'=>5.0,
                'same_as'=>json_encode(['https://www.facebook.com/SpielBankBerlin']),
                'has_map'=>'https://maps.google.com/?q=Spielbank+Berlin+Fernsehturm',
                'source'=>'demo','status'=>'active',
            ],
            'hours' => [
                ['Monday','12:00','03:00'],['Tuesday','12:00','03:00'],['Wednesday','12:00','03:00'],
                ['Thursday','12:00','03:00'],['Friday','12:00','04:00'],['Saturday','12:00','04:00'],
                ['Sunday','12:00','03:00'],
            ],
            'events' => [
                ['name'=>'Casino am Fernsehturm – 13. Jahrestag','start_date'=>'2026-02-01 18:00:00','end_date'=>null,'description'=>'Das Casino am Fernsehturm feiert seinen 13. Jahrestag mit Sonderauslosungen.','url'=>'https://www.spielbank-berlin.de/en/home/','event_type'=>'Sonstige','city'=>'Berlin','address'=>'Panoramastraße 1A, 10178 Berlin'],
            ],
        ],
        [
            'loc' => [
                'name'=>'Spielbank Hamburg',
                'alternate_name'=>'Casino Esplanade Hamburg',
                'description'=>'Die Spielbank Hamburg im Casino Esplanade – Hamburgs führendes Casino mit Roulette, Poker und exklusiven Live-Events.',
                'url'=>'https://www.spielbank-hamburg.de',
                'telephone'=>'+49 40 334660',
                'email'=>'info@spielbank-hamburg.de',
                'street_address'=>'Stephansplatz 10','address_locality'=>'Hamburg',
                'postal_code'=>'20354','address_region'=>'Hamburg','address_country'=>'DE',
                'latitude'=>53.5614780,'longitude'=>9.9901330,
                'price_range'=>'€€€','currencies_accepted'=>'EUR','payment_accepted'=>'Cash, Credit Card, EC',
                'is_accessible'=>1,'smoking_allowed'=>0,
                'rating_value'=>4.3,'review_count'=>2100,'best_rating'=>5.0,
                'same_as'=>json_encode(['https://www.facebook.com/SpielBankHamburg','https://www.instagram.com/spielbankhamburg']),
                'has_map'=>'https://maps.google.com/?q=Spielbank+Hamburg+Stephansplatz',
                'amenity_features'=>json_encode([['name'=>'Bar','value'=>true],['name'=>'Terrasse','value'=>true],['name'=>'Live-Events','value'=>true]]),
                'source'=>'demo','status'=>'active',
            ],
            'hours' => [
                ['Monday','12:00','04:00'],['Tuesday','12:00','04:00'],['Wednesday','12:00','04:00'],
                ['Thursday','12:00','04:00'],['Friday','12:00','05:00'],['Saturday','12:00','05:00'],
                ['Sunday','12:00','04:00'],
            ],
            'events' => [
                ['name'=>'Casino Open Air – Mika Henning','start_date'=>'2025-06-26 19:00:00','end_date'=>null,'description'=>'After-Work Open Air auf der Terrasse. Melodischer Techno. Early-Bird ab 17 Uhr.','url'=>'https://www.spielbank-hamburg.de/veranstaltung/casino-open-air-mit-mika-henning/','event_type'=>'Musik','city'=>'Hamburg','address'=>'Stephansplatz 10, 20354 Hamburg','ticket_url'=>'https://bit.ly/sbhh-salsa-0625'],
            ],
        ],
    ];

    foreach ($locations as $entry) {
        $loc_id = sbe_upsert_location($entry['loc']);
        foreach ($entry['hours'] as $h) {
            sbe_upsert_hours($loc_id, $h[0], $h[1], $h[2]);
        }
        foreach ($entry['events'] as $ev) {
            $ev['location_id'] = $loc_id;
            $ev['organizer']   = $entry['loc']['name'];
            $ev['location']    = $entry['loc']['name'];
            $ev['status']      = 'published';
            $ev['source']      = 'demo';
            if (!sbe_event_exists($ev['name'], $ev['start_date'] ?? null)) {
                sbe_save_event($ev);
            }
        }
    }
}
