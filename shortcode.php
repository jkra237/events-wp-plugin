<?php
defined('ABSPATH') || exit;

/**
 * Scraper v3 – zieht LocalBusiness/GamblingResort + OpeningHoursSpecification + Events
 * aus öffentlichen Spielbank-Seiten.
 *
 * Verbesserungen:
 *  - Quellen kommen aus der DB (Spielbank Quellen Tab)
 *  - Retry-Logik mit konfigurierbarer Wiederholung
 *  - Sitemap.xml parsen für Event-URLs
 *  - Pagination-Support (Nächste-Seite folgen)
 *  - Per-Source Settings (User-Agent, Timeout, Delay, Headers)
 *  - Cookie-Handling & Referer
 *  - Besseres Logging pro Quelle in DB
 */

// ── Source definitions (now from DB) ─────────────────────────────────────────
function sbe_get_sources(): array {
    $db_sources = sbe_get_all_sources(true); // only active
    $sources    = [];
    foreach ($db_sources as $row) {
        $sources[] = sbe_source_to_legacy($row);
    }
    return $sources;
}

// ── Main runner ───────────────────────────────────────────────────────────────
function sbe_run_scraper(): array {
    $log         = [];
    $events_saved = 0;
    $locs_saved  = 0;
    $skipped     = 0;
    $errors      = [];

    foreach (sbe_get_sources() as $src) {
        $result = sbe_scrape_source($src);
        $log          = array_merge($log, $result['log']);
        $events_saved += $result['events_saved'];
        $locs_saved   += $result['locs_saved'];
        $skipped      += $result['skipped'];
        $errors        = array_merge($errors, $result['errors']);
    }

    return [
        'log'          => $log,
        'locs_saved'   => $locs_saved,
        'events_saved' => $events_saved,
        'skipped'      => $skipped,
        'errors'       => $errors,
        'message'      => "$locs_saved Standorte + $events_saved neue Events gespeichert ($skipped übersprungen).",
    ];
}

// ── Single-source runner (for progress-based scraping) ───────────────────────
function sbe_run_scraper_single(int $source_id): array {
    $row = sbe_get_source($source_id);
    if (!$row) return ['log' => ['✗ Quelle nicht gefunden.'], 'events_saved' => 0, 'locs_saved' => 0, 'skipped' => 0, 'errors' => ['Source not found']];
    $src = sbe_source_to_legacy($row);
    return sbe_scrape_source($src);
}

// ── Scrape a single source ───────────────────────────────────────────────────
function sbe_scrape_source(array $src): array {
    $settings    = sbe_get_settings();
    $log         = [];
    $events_saved = 0;
    $locs_saved  = 0;
    $skipped     = 0;
    $errors      = [];

    $src_id = $src['id'] ?? 0;
    $log[] = "▶ Quelle: {$src['name']}";

    // Merge per-source settings with global defaults
    $timeout    = ($src['request_timeout'] ?? 0) > 0 ? $src['request_timeout'] : $settings['request_timeout'];
    $delay      = ($src['request_delay'] ?? 0) > 0   ? $src['request_delay']   : $settings['request_delay'];
    $user_agent = !empty($src['custom_user_agent'])   ? $src['custom_user_agent'] : $settings['user_agent'];
    $max_pages  = max(1, (int)($src['max_pages'] ?? 1));
    $retries    = (int)$settings['retry_count'];
    $retry_delay = (int)$settings['retry_delay'];

    $fetch_opts = [
        'timeout'    => $timeout,
        'user-agent' => $user_agent,
        'headers'    => array_merge(
            ['Accept-Language' => $settings['accept_language']],
            $src['custom_headers'] ?? []
        ),
    ];

    // ── robots.txt check ────────────────────────────────────────────────
    $first_events_url = !empty($src['events_urls'][0]) ? $src['events_urls'][0] : $src['base_url'];
    if (!sbe_robots_allowed($src['robots_url'], $first_events_url, $fetch_opts)) {
        $log[]    = "  ✗ robots.txt verbietet Scraping – übersprungen.";
        $errors[] = $src['name'] . ': robots.txt disallow';
        if ($src_id) sbe_update_source_status($src_id, ['last_error' => 'robots.txt disallow', 'last_scraped' => current_time('mysql')]);
        return compact('log', 'events_saved', 'locs_saved', 'skipped', 'errors');
    }
    $log[] = "  ✓ robots.txt OK";

    // ── 1. Location / business data ─────────────────────────────────────
    $loc_data = $src['seed'] ?? [];
    $loc_data['source'] = $src['name'];
    $loc_data['status'] = 'active';

    // Ensure location has a name – fallback to source name
    if (empty($loc_data['name'])) {
        $loc_data['name'] = $src['name'];
    }

    // Try to enrich from contact/imprint page
    if (!empty($src['contact_url'])) {
        $contact_html = sbe_fetch_with_retry($src['contact_url'], $fetch_opts, $retries, $retry_delay);
        if (!is_wp_error($contact_html) && !empty($contact_html)) {
            $log[] = "  ✓ Kontaktseite geladen";
            $enriched = sbe_parse_contact_page($contact_html, $src['base_url']);
            foreach ($enriched as $k => $v) {
                if (!empty($v)) $loc_data[$k] = $v;
            }
        } else {
            $log[] = "  ℹ Kontaktseite nicht erreichbar – nutze Seed-Daten";
        }
    }

    // Also try main page for description/image
    $main_html = sbe_fetch_with_retry($src['base_url'], $fetch_opts, $retries, $retry_delay);
    if (!is_wp_error($main_html) && !empty($main_html)) {
        $meta = sbe_parse_meta_tags($main_html);
        if (!empty($meta['description']) && empty($loc_data['description'])) $loc_data['description'] = $meta['description'];
        if (!empty($meta['image']) && empty($loc_data['image_url']))         $loc_data['image_url']   = $meta['image'];
        if (!empty($meta['logo']) && empty($loc_data['logo_url']))           $loc_data['logo_url']    = $meta['logo'];
        $log[] = "  ✓ Hauptseite geparst (Meta-Tags)";
    }

    // Try to extract JSON-LD from source page
    if (!empty($main_html)) {
        $jsonld_data = sbe_extract_jsonld($main_html);
        if ($jsonld_data) {
            $loc_data = array_merge($loc_data, $jsonld_data);
            $log[] = "  ✓ JSON-LD auf Quellseite gefunden und übernommen";
        }
    }

    $loc_id = sbe_upsert_location($loc_data);
    $log[]  = "  ✓ Location gespeichert (ID: $loc_id)";
    $locs_saved++;

    // ── 2. Opening hours ────────────────────────────────────────────────
    $hours = [];
    if (!empty($main_html)) {
        $hours = sbe_parse_opening_hours($main_html);
    }
    if (empty($hours) && !empty($src['seed_hours'])) {
        $hours = array_map(function($h) {
            return ['day_of_week'=>$h[0],'opens'=>$h[1],'closes'=>$h[2]];
        }, $src['seed_hours']);
        $log[] = "  ℹ Öffnungszeiten: Seed-Daten genutzt";
    } elseif (!empty($hours)) {
        $log[] = "  ✓ " . count($hours) . " Öffnungszeiten geparst";
    }
    sbe_replace_hours($loc_id, $hours);

    // ── 3. Events ───────────────────────────────────────────────────────
    $all_events = [];
    $events_urls = [];

    // Primary events URLs (can be multiple)
    if (!empty($src['events_urls']) && is_array($src['events_urls'])) {
        $events_urls = $src['events_urls'];
    } elseif (!empty($src['events_url'])) {
        // Fallback: single URL string (legacy or raw)
        $events_urls = array_values(array_filter(array_map('trim', preg_split('/[\r\n]+/', $src['events_url']))));
    }

    // Try sitemap for additional event URLs
    if (!empty($settings['check_sitemap'])) {
        $sitemap_url = !empty($src['sitemap_url']) ? $src['sitemap_url'] : rtrim($src['base_url'], '/') . '/sitemap.xml';
        $sitemap_events = sbe_parse_sitemap_for_events($sitemap_url, $fetch_opts, $retries, $retry_delay);
        if (!empty($sitemap_events)) {
            $events_urls = array_merge($events_urls, $sitemap_events);
            $events_urls = array_unique($events_urls);
            $log[] = "  ✓ Sitemap: " . count($sitemap_events) . " Event-URLs gefunden";
        }
    }

    $page = 0;
    $total_events_this_source = 0;

    foreach ($events_urls as $ev_url) {
        if ($total_events_this_source >= (int)$settings['max_events']) break;

        sleep($delay);
        $events_html = sbe_fetch_with_retry($ev_url, array_merge($fetch_opts, [
            'headers' => array_merge($fetch_opts['headers'], ['Referer' => $src['base_url']]),
        ]), $retries, $retry_delay);

        if (is_wp_error($events_html) || empty($events_html)) {
            $log[]    = "  ✗ Events-Seite nicht erreichbar: $ev_url";
            $errors[] = $src['name'] . ": events page fetch failed ($ev_url)";
            continue;
        }

        // Encoding normalization (Scrapy-inspired)
        $events_html = sbe_ensure_utf8($events_html);

        $log[] = "  ✓ Events-Seite geladen (" . strlen($events_html) . " Bytes): $ev_url";

        $events = sbe_parse_events($events_html, $src, $loc_id);
        $log[]  = "  → " . count($events) . " Events gefunden";

        $blacklisted = 0;
        $enriched    = 0;
        $crawl_depth = (int)($settings['crawl_depth'] ?? 1);

        foreach ($events as $ev) {
            if ($total_events_this_source >= (int)$settings['max_events']) break;

            // Blacklist filter
            if (sbe_is_blacklisted($ev['name'], $ev['description'] ?? '')) {
                $blacklisted++;
                continue;
            }

            if (sbe_event_exists($ev['name'], $ev['start_date'] ?? null)) {
                $skipped++;
                continue;
            }

            // Detail-page enrichment (depth >= 1: follow event links for more data)
            if ($crawl_depth >= 1 && !empty($ev['url']) && (empty($ev['description']) || empty($ev['start_date']) || empty($ev['image_url']))) {
                sleep(max(1, (int)($delay / 2)));
                $ev = sbe_enrich_event_from_detail($ev, $fetch_opts, 1, $retry_delay, $crawl_depth);
                $enriched++;
            }

            // Final blacklist check after enrichment (description may have changed)
            if (sbe_is_blacklisted($ev['name'], $ev['description'] ?? '')) {
                $blacklisted++;
                continue;
            }

            sbe_save_event($ev);
            $events_saved++;
            $total_events_this_source++;
        }

        if ($blacklisted > 0) $log[] = "  ⊘ $blacklisted Events per Blacklist gefiltert";
        if ($enriched > 0)    $log[] = "  ↳ $enriched Events per Detail-Seite angereichert";

        $page++;

        // Follow pagination
        if ($src['follow_pagination'] && $page < $max_pages) {
            $next = sbe_find_next_page($events_html, $ev_url, $src['base_url'], $src['pagination_sel'] ?: '');
            if ($next && !in_array($next, $events_urls)) {
                $events_urls[] = $next;
                $log[] = "  ↳ Pagination: nächste Seite gefunden";
            }
        }
    }

    // Update source status in DB
    if ($src_id) {
        sbe_update_source_status($src_id, [
            'last_scraped'  => current_time('mysql'),
            'last_error'    => null,
            'events_found'  => $total_events_this_source,
        ]);
    }

    return [
        'log'          => $log,
        'locs_saved'   => $locs_saved,
        'events_saved' => $events_saved,
        'skipped'      => $skipped,
        'errors'       => $errors,
        'message'      => "{$src['name']}: $locs_saved Standort + $events_saved neue Events ($skipped übersprungen).",
    ];
}

// ── Robots.txt ────────────────────────────────────────────────────────────────
function sbe_robots_allowed(string $robots_url, string $target, array $fetch_opts = []): bool {
    $body = sbe_fetch($robots_url, array_merge($fetch_opts, ['timeout' => 5]));
    if (is_wp_error($body) || empty($body)) return true;

    $active = false;
    $disallowed = [];
    foreach (explode("\n", $body) as $line) {
        $line = trim($line);
        if (stripos($line, 'user-agent:') === 0) {
            $agent  = strtolower(trim(substr($line, 11)));
            $active = ($agent === '*' || str_contains($agent, 'bot'));
        }
        if ($active && stripos($line, 'disallow:') === 0) {
            $path = trim(substr($line, 9));
            if ($path) $disallowed[] = $path;
        }
    }

    $path = wp_parse_url($target, PHP_URL_PATH) ?: '/';
    foreach ($disallowed as $d) {
        if ($d === '/' || str_starts_with($path, $d)) return false;
    }
    return true;
}

// ── HTTP Fetch (basic) ───────────────────────────────────────────────────────
function sbe_fetch(string $url, array $opts = []) {
    $defaults = [
        'timeout'    => 15,
        'user-agent' => 'SpielbankEventBot/2.0 (WordPress; +https://spielbank.com.de)',
        'headers'    => ['Accept-Language' => 'de-DE,de;q=0.9,en;q=0.8'],
        'redirection' => 5,
        'sslverify'  => true,
    ];
    $args = array_merge($defaults, $opts);

    // Ensure headers are properly merged
    if (isset($opts['headers']) && isset($defaults['headers'])) {
        $args['headers'] = array_merge($defaults['headers'], $opts['headers']);
    }

    // Add Accept header for better compatibility
    if (!isset($args['headers']['Accept'])) {
        $args['headers']['Accept'] = 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8';
    }

    $resp = wp_remote_get($url, $args);
    if (is_wp_error($resp)) return $resp;
    $code = wp_remote_retrieve_response_code($resp);
    if ($code >= 400) return new WP_Error('http_error', "HTTP $code für $url");
    return wp_remote_retrieve_body($resp);
}

// ── HTTP Fetch with Retry ────────────────────────────────────────────────────
function sbe_fetch_with_retry(string $url, array $opts = [], int $retries = 2, int $retry_delay = 3) {
    $last_error = null;
    for ($i = 0; $i <= $retries; $i++) {
        if ($i > 0) sleep($retry_delay);
        $result = sbe_fetch($url, $opts);
        if (!is_wp_error($result) && !empty($result)) return $result;
        $last_error = $result;
    }
    return $last_error ?: new WP_Error('fetch_failed', "Fetch fehlgeschlagen nach " . ($retries+1) . " Versuchen: $url");
}

// ── Sitemap Parser ───────────────────────────────────────────────────────────
function sbe_parse_sitemap_for_events(string $sitemap_url, array $fetch_opts = [], int $retries = 1, int $retry_delay = 2): array {
    $urls = [];
    $body = sbe_fetch_with_retry($sitemap_url, $fetch_opts, $retries, $retry_delay);
    if (is_wp_error($body) || empty($body)) return [];

    // Check if this is a sitemap index
    if (str_contains($body, '<sitemapindex')) {
        preg_match_all('/<loc>\s*(.*?)\s*<\/loc>/i', $body, $m);
        foreach ($m[1] as $child_url) {
            // Look for sub-sitemaps that might contain events
            if (preg_match('/event|veranstaltung|news|aktuell/i', $child_url)) {
                $child_body = sbe_fetch_with_retry($child_url, $fetch_opts, 1, $retry_delay);
                if (!is_wp_error($child_body) && !empty($child_body)) {
                    $urls = array_merge($urls, sbe_extract_event_urls_from_sitemap($child_body));
                }
            }
        }
    } else {
        $urls = sbe_extract_event_urls_from_sitemap($body);
    }

    return array_slice(array_unique($urls), 0, 20); // max 20
}

function sbe_extract_event_urls_from_sitemap(string $xml): array {
    $urls = [];
    preg_match_all('/<loc>\s*(.*?)\s*<\/loc>/i', $xml, $m);
    foreach ($m[1] as $url) {
        if (preg_match('/event|veranstaltung|news|aktuell|programm|kalender/i', $url)) {
            $urls[] = trim($url);
        }
    }
    return $urls;
}

// ── Pagination Finder ────────────────────────────────────────────────────────
function sbe_find_next_page(string $html, string $current_url, string $base_url, string $custom_sel = ''): ?string {
    $dom = new DOMDocument();
    libxml_use_internal_errors(true);
    $dom->loadHTML('<?xml encoding="UTF-8">' . $html);
    libxml_clear_errors();
    $x = new DOMXPath($dom);

    // Custom selector first
    $selectors = [];
    if (!empty($custom_sel)) {
        $selectors[] = $custom_sel;
    }
    // Common pagination selectors
    $selectors = array_merge($selectors, [
        '//a[contains(@class,"next")]',
        '//a[contains(@class,"page-next")]',
        '//li[contains(@class,"next")]/a',
        '//a[@rel="next"]',
        '//*[contains(@class,"pagination")]//a[contains(text(),"›")]',
        '//*[contains(@class,"pagination")]//a[contains(text(),"»")]',
        '//*[contains(@class,"nav-next")]/a',
        '//*[contains(@class,"pagination")]//a[contains(text(),"Nächste")]',
        '//*[contains(@class,"pagination")]//a[contains(text(),"Next")]',
        '//*[contains(@class,"pagination")]//a[contains(text(),"Weiter")]',
    ]);

    foreach ($selectors as $sel) {
        $nodes = $x->query($sel);
        if ($nodes && $nodes->length > 0) {
            $href = $nodes->item(0)->getAttribute('href');
            if ($href && $href !== '#' && $href !== $current_url) {
                if (!str_starts_with($href, 'http')) {
                    $href = rtrim($base_url, '/') . '/' . ltrim($href, '/');
                }
                return $href;
            }
        }
    }

    return null;
}

// ── Parse: Meta tags (OG / standard) ─────────────────────────────────────────
function sbe_parse_meta_tags(string $html): array {
    $data = [];
    if (preg_match('/<meta[^>]+(?:name=["\']description["\']|property=["\']og:description["\'])[^>]+content=["\'](.*?)["\']/is', $html, $m))
        $data['description'] = html_entity_decode(trim($m[1]), ENT_QUOTES);
    if (preg_match('/<meta[^>]+property=["\']og:image["\'][^>]+content=["\'](.*?)["\']/is', $html, $m))
        $data['image'] = trim($m[1]);
    if (preg_match('/<link[^>]+rel=["\'](?:icon|shortcut icon|apple-touch-icon)["\'][^>]+href=["\'](.*?)["\']/is', $html, $m))
        $data['logo'] = trim($m[1]);
    return $data;
}

// ── Parse: Existing JSON-LD on source page ────────────────────────────────────
function sbe_extract_jsonld(string $html): array {
    $data = [];
    preg_match_all('/<script[^>]+type=["\']application\/ld\+json["\'][^>]*>(.*?)<\/script>/is', $html, $matches);
    foreach ($matches[1] as $raw) {
        $json = json_decode(trim($raw), true);
        if (!$json) continue;

        $items = isset($json['@graph']) ? $json['@graph'] : [$json];
        foreach ($items as $item) {
            $type = is_array($item['@type'] ?? null) ? $item['@type'] : [$item['@type'] ?? ''];
            $is_biz = array_intersect($type, ['LocalBusiness','GamblingResort','CasinoOrGamblingPlace','TouristAttraction','Casino','EntertainmentBusiness']);
            if (empty($is_biz)) continue;

            if (!empty($item['name']))        $data['name']        = $item['name'];
            if (!empty($item['description'])) $data['description'] = $item['description'];
            if (!empty($item['telephone']))   $data['telephone']   = $item['telephone'];
            if (!empty($item['email']))       $data['email']       = $item['email'];
            if (!empty($item['url']))         $data['url']         = $item['url'];
            if (!empty($item['image']))       $data['image_url']   = is_array($item['image']) ? ($item['image']['url'] ?? $item['image'][0]) : $item['image'];
            if (!empty($item['logo']))        $data['logo_url']    = is_array($item['logo'])  ? ($item['logo']['url'] ?? '') : $item['logo'];
            if (!empty($item['priceRange']))  $data['price_range'] = $item['priceRange'];

            $addr = $item['address'] ?? null;
            if ($addr) {
                if (!empty($addr['streetAddress']))   $data['street_address']   = $addr['streetAddress'];
                if (!empty($addr['addressLocality'])) $data['address_locality'] = $addr['addressLocality'];
                if (!empty($addr['postalCode']))       $data['postal_code']      = $addr['postalCode'];
                if (!empty($addr['addressRegion']))    $data['address_region']   = $addr['addressRegion'];
                if (!empty($addr['addressCountry']))   $data['address_country']  = $addr['addressCountry'];
            }

            $geo = $item['geo'] ?? null;
            if ($geo) {
                if (!empty($geo['latitude']))  $data['latitude']  = (float)$geo['latitude'];
                if (!empty($geo['longitude'])) $data['longitude'] = (float)$geo['longitude'];
            }

            $rating = $item['aggregateRating'] ?? null;
            if ($rating) {
                if (!empty($rating['ratingValue'])) $data['rating_value']  = (float)$rating['ratingValue'];
                if (!empty($rating['reviewCount'])) $data['review_count']  = (int)$rating['reviewCount'];
                if (!empty($rating['bestRating']))  $data['best_rating']   = (float)$rating['bestRating'];
            }

            if (!empty($item['sameAs'])) {
                $data['same_as'] = json_encode(is_array($item['sameAs']) ? $item['sameAs'] : [$item['sameAs']]);
            }

            if (!empty($data)) break 2;
        }
    }
    return $data;
}

// ── Parse: Contact page for address/phone ─────────────────────────────────────
function sbe_parse_contact_page(string $html, string $base_url): array {
    $data = [];
    $dom  = new DOMDocument();
    libxml_use_internal_errors(true);
    $dom->loadHTML('<?xml encoding="UTF-8">' . $html);
    libxml_clear_errors();
    $x = new DOMXPath($dom);

    // Phone
    $tel_nodes = $x->query('//*[contains(@href,"tel:")]');
    foreach ($tel_nodes as $n) {
        $tel = preg_replace('/[^+0-9 \-]/', '', $n->textContent);
        if (strlen($tel) >= 6) { $data['telephone'] = trim($tel); break; }
    }
    if (empty($data['telephone'])) {
        if (preg_match('/(?:Tel\.?|Telefon)[:\s]+([+0-9 \-\/\(\)]{6,20})/i', $html, $m))
            $data['telephone'] = trim($m[1]);
    }

    // Email
    $email_nodes = $x->query('//*[contains(@href,"mailto:")]');
    foreach ($email_nodes as $n) {
        $href = $n->getAttribute('href');
        $email = str_replace('mailto:', '', $href);
        if (filter_var($email, FILTER_VALIDATE_EMAIL)) { $data['email'] = $email; break; }
    }

    // Address
    if (preg_match('/(\d{5})\s+([A-ZÄÖÜ][a-zäöüß\-]+(?:\s[A-ZÄÖÜ][a-zäöüß\-]+)*)/u', $html, $m)) {
        $data['postal_code']      = $m[1];
        $data['address_locality'] = trim($m[2]);
    }

    // Opening hours
    $hours_raw = sbe_parse_opening_hours($html);
    if (!empty($hours_raw)) $data['_hours'] = $hours_raw;

    return $data;
}

// ── Parse: Opening hours ──────────────────────────────────────────────────────
function sbe_parse_opening_hours(string $html): array {
    $hours = [];
    $day_map = [
        'montag'=>'Monday','dienstag'=>'Tuesday','mittwoch'=>'Wednesday',
        'donnerstag'=>'Thursday','freitag'=>'Friday','samstag'=>'Saturday',
        'sonntag'=>'Sunday','mo'=>'Monday','di'=>'Tuesday','mi'=>'Wednesday',
        'do'=>'Thursday','fr'=>'Friday','sa'=>'Saturday','so'=>'Sunday',
        'monday'=>'Monday','tuesday'=>'Tuesday','wednesday'=>'Wednesday',
        'thursday'=>'Thursday','friday'=>'Friday','saturday'=>'Saturday',
        'sunday'=>'Sunday',
    ];

    // Attempt 1: JSON-LD openingHoursSpecification
    preg_match_all('/"openingHoursSpecification"\s*:\s*(\[.*?\])/s', $html, $jm);
    if (!empty($jm[1])) {
        $specs = json_decode($jm[1][0], true);
        if (is_array($specs)) {
            foreach ($specs as $spec) {
                $days = is_array($spec['dayOfWeek'] ?? null)
                    ? $spec['dayOfWeek']
                    : (isset($spec['dayOfWeek']) ? [$spec['dayOfWeek']] : []);
                foreach ($days as $day) {
                    $day_clean = str_replace('https://schema.org/', '', $day);
                    $hours[] = [
                        'day_of_week'  => $day_clean,
                        'opens'        => substr($spec['opens'] ?? '12:00', 0, 5),
                        'closes'       => substr($spec['closes'] ?? '03:00', 0, 5),
                        'valid_from'   => $spec['validFrom'] ?? null,
                        'valid_through'=> $spec['validThrough'] ?? null,
                    ];
                }
            }
            if (!empty($hours)) return $hours;
        }
    }

    // Attempt 2: openingHours short form
    preg_match_all('/([A-Za-zÄÖÜäöü\-,\s]+)\s+(\d{1,2}:\d{2})\s*[–\-]\s*(\d{1,2}:\d{2})/u', $html, $m);
    foreach ($m[1] as $i => $day_str) {
        $opens  = $m[2][$i];
        $closes = $m[3][$i];
        $days   = sbe_expand_day_range($day_str, $day_map);
        foreach ($days as $day) {
            $hours[] = ['day_of_week'=>$day,'opens'=>$opens,'closes'=>$closes];
        }
    }

    return $hours;
}

function sbe_expand_day_range(string $str, array $day_map): array {
    $days = [];
    $all  = ['Monday','Tuesday','Wednesday','Thursday','Friday','Saturday','Sunday'];
    $str  = trim(strtolower($str));

    $parts = preg_split('/[,\s]+/', $str);
    foreach ($parts as $part) {
        $part = trim($part);
        if (str_contains($part, '-')) {
            [$from, $to] = explode('-', $part, 2);
            $from_en = $day_map[trim($from)] ?? null;
            $to_en   = $day_map[trim($to)]   ?? null;
            if ($from_en && $to_en) {
                $fi = array_search($from_en, $all);
                $ti = array_search($to_en, $all);
                if ($fi !== false && $ti !== false) {
                    for ($j = $fi; $j <= $ti; $j++) $days[] = $all[$j];
                }
            }
        } elseif (isset($day_map[$part])) {
            $days[] = $day_map[$part];
        }
    }
    return array_unique($days);
}

// ── Parse: Events from news/events page ──────────────────────────────────────
function sbe_parse_events(string $html, array $src, int $loc_id): array {
    $events = [];
    $dom    = new DOMDocument();
    libxml_use_internal_errors(true);
    $dom->loadHTML('<?xml encoding="UTF-8">' . $html);
    libxml_clear_errors();
    $x = new DOMXPath($dom);

    // Try JSON-LD first
    $jsonld_events = sbe_extract_events_jsonld($html);
    if (!empty($jsonld_events)) {
        $seed = $src['seed'] ?? [];
        foreach ($jsonld_events as &$ev) {
            $ev['location_id'] = $loc_id;
            $ev['organizer']   = $seed['name'] ?? $src['name'];
            $ev['location']    = $seed['name'] ?? $src['name'];
            $ev['city']        = $seed['address_locality'] ?? '';
            $ev['address']     = ($seed['street_address'] ?? '') . ', ' . ($seed['postal_code'] ?? '') . ' ' . ($seed['address_locality'] ?? '');
            $ev['source']      = $src['name'];
            $ev['status']      = 'scraped';
        }
        return $jsonld_events;
    }

    // Microdata fallback (itemprop based)
    $microdata_events = sbe_extract_microdata_events($x, $src, $loc_id);
    if (!empty($microdata_events)) return $microdata_events;

    // DOM fallback with custom + default selectors
    $selectors = ['//article', '//*[contains(@class,"post")]', '//*[contains(@class,"event")]', '//*[contains(@class,"entry")]', '//*[contains(@class,"veranstaltung")]', '//*[contains(@class,"card")]'];

    // Add custom selectors if configured
    if (!empty($src['event_selectors'])) {
        $custom = array_filter(array_map('trim', explode("\n", $src['event_selectors'])));
        $selectors = array_merge($custom, $selectors);
    }

    $nodes = null;
    foreach ($selectors as $sel) {
        $found = $x->query($sel);
        if ($found && $found->length > 0) { $nodes = $found; break; }
    }
    if (!$nodes) return [];

    $seed = $src['seed'] ?? [];
    foreach ($nodes as $node) {
        $title_node = $x->query('.//h2|.//h3|.//h4', $node)->item(0);
        if (!$title_node) continue;
        $title = trim($title_node->textContent);
        if (strlen($title) < 5 || strlen($title) > 250) continue;

        $link_node = $x->query('.//a[@href]', $node)->item(0);
        $url = '';
        if ($link_node) {
            $url = $link_node->getAttribute('href');
            if ($url && !str_starts_with($url, 'http'))
                $url = rtrim($src['base_url'], '/') . '/' . ltrim($url, '/');
        }

        $desc_node = $x->query('.//p', $node)->item(0);
        $desc = $desc_node ? trim(substr($desc_node->textContent, 0, 500)) : '';

        $date_node = $x->query('.//*[contains(@class,"date") or contains(@class,"time") or self::time or @datetime]', $node)->item(0);
        $date_raw  = '';
        if ($date_node) {
            // Prefer datetime attribute if available
            $date_raw = $date_node->getAttribute('datetime') ?: trim($date_node->textContent);
        }
        $date_iso = sbe_parse_date($date_raw);

        // Image – also check data-src for lazy loading
        $img_node = $x->query('.//img[@src or @data-src or @data-lazy-src]', $node)->item(0);
        $img_url  = '';
        if ($img_node) {
            $img_url = $img_node->getAttribute('data-src')
                    ?: $img_node->getAttribute('data-lazy-src')
                    ?: $img_node->getAttribute('src');
            if ($img_url && !str_starts_with($img_url, 'http'))
                $img_url = rtrim($src['base_url'], '/') . '/' . ltrim($img_url, '/');
        }

        $events[] = [
            'location_id' => $loc_id,
            'name'        => $title,
            'url'         => $url,
            'description' => $desc,
            'start_date'  => $date_iso,
            'image_url'   => $img_url,
            'location'    => $seed['name'] ?? $src['name'],
            'city'        => $seed['address_locality'] ?? '',
            'address'     => ($seed['street_address'] ?? '') . ', ' . ($seed['postal_code'] ?? '') . ' ' . ($seed['address_locality'] ?? ''),
            'organizer'   => $seed['name'] ?? $src['name'],
            'event_type'  => sbe_guess_type($title . ' ' . $desc),
            'source'      => $src['name'],
            'status'      => 'scraped',
        ];

        if (count($events) >= 25) break;
    }
    return $events;
}

// ── Parse: Microdata Events (itemprop) ───────────────────────────────────────
function sbe_extract_microdata_events(DOMXPath $x, array $src, int $loc_id): array {
    $events = [];
    $nodes = $x->query('//*[@itemtype and contains(@itemtype,"Event")]');
    if (!$nodes || $nodes->length === 0) return [];

    $seed = $src['seed'] ?? [];
    foreach ($nodes as $node) {
        $name_node = $x->query('.//*[@itemprop="name"]', $node)->item(0);
        if (!$name_node) continue;
        $name = trim($name_node->textContent);
        if (strlen($name) < 5) continue;

        $start = $x->query('.//*[@itemprop="startDate"]', $node)->item(0);
        $end   = $x->query('.//*[@itemprop="endDate"]', $node)->item(0);
        $desc  = $x->query('.//*[@itemprop="description"]', $node)->item(0);
        $url   = $x->query('.//*[@itemprop="url"]', $node)->item(0);
        $img   = $x->query('.//*[@itemprop="image"]', $node)->item(0);

        $events[] = [
            'location_id' => $loc_id,
            'name'        => $name,
            'start_date'  => $start ? sbe_normalize_date($start->getAttribute('content') ?: $start->getAttribute('datetime') ?: $start->textContent) : null,
            'end_date'    => $end   ? sbe_normalize_date($end->getAttribute('content') ?: $end->getAttribute('datetime') ?: $end->textContent) : null,
            'description' => $desc  ? trim(substr($desc->textContent, 0, 500)) : '',
            'url'         => $url   ? ($url->getAttribute('href') ?: $url->getAttribute('content') ?: '') : '',
            'image_url'   => $img   ? ($img->getAttribute('src') ?: $img->getAttribute('content') ?: '') : '',
            'location'    => $seed['name'] ?? $src['name'],
            'city'        => $seed['address_locality'] ?? '',
            'address'     => ($seed['street_address'] ?? '') . ', ' . ($seed['postal_code'] ?? '') . ' ' . ($seed['address_locality'] ?? ''),
            'organizer'   => $seed['name'] ?? $src['name'],
            'event_type'  => sbe_guess_type($name),
            'source'      => $src['name'],
            'status'      => 'scraped',
        ];
    }
    return $events;
}

// ── Parse: Events from JSON-LD ────────────────────────────────────────────────
function sbe_extract_events_jsonld(string $html): array {
    $events = [];
    preg_match_all('/<script[^>]+type=["\']application\/ld\+json["\'][^>]*>(.*?)<\/script>/is', $html, $matches);
    foreach ($matches[1] as $raw) {
        $json = json_decode(trim($raw), true);
        if (!$json) continue;
        $items = isset($json['@graph']) ? $json['@graph'] : [$json];
        foreach ($items as $item) {
            $type = is_array($item['@type'] ?? null) ? $item['@type'] : [$item['@type'] ?? ''];
            if (!in_array('Event', $type)) continue;

            $ev = [
                'name'        => $item['name'] ?? '',
                'description' => is_array($item['description'] ?? null) ? implode(' ', $item['description']) : ($item['description'] ?? ''),
                'url'         => $item['url'] ?? '',
                'start_date'  => isset($item['startDate']) ? sbe_normalize_date($item['startDate']) : null,
                'end_date'    => isset($item['endDate'])   ? sbe_normalize_date($item['endDate'])   : null,
                'image_url'   => is_array($item['image'] ?? null) ? ($item['image']['url'] ?? $item['image'][0] ?? '') : ($item['image'] ?? ''),
                'event_type'  => sbe_guess_type($item['name'] ?? ''),
            ];

            if (!empty($item['offers'])) {
                $offer = is_array($item['offers']) ? ($item['offers'][0] ?? $item['offers']) : $item['offers'];
                $ev['ticket_url']     = $offer['url'] ?? '';
                $ev['price']          = isset($offer['price']) ? (string)$offer['price'] : '';
                $ev['price_currency'] = $offer['priceCurrency'] ?? 'EUR';
            }

            if (strlen($ev['name']) >= 5) $events[] = $ev;
        }
    }
    return $events;
}

// ── Helpers ───────────────────────────────────────────────────────────────────

/**
 * Blacklist filter – skip events whose name/description matches junk patterns.
 * Default patterns + user-configurable via Settings.
 */
function sbe_is_blacklisted(string $name, string $description = ''): bool {
    static $cached_patterns = null;

    if ($cached_patterns === null) {
        $settings = sbe_get_settings();
        $custom   = $settings['blacklist_patterns'] ?? '';

        // Built-in patterns (always active)
        $cached_patterns = [
            'newsletter',
            'abonnieren',
            'subscribe',
            'jetzt anmelden',
            'sign\s*up',
            'cookie',
            'datenschutz',
            'privacy\s*policy',
            'impressum',
            'agb',
            'nutzungsbedingungen',
            'terms\s*(of|&)\s*(service|use)',
            'stellenangebot',
            'karriere',
            'job\s*offer',
        ];

        // Add user-defined patterns
        if (!empty($custom)) {
            $user_patterns = array_filter(array_map('trim', preg_split('/[\r\n]+/', $custom)));
            $cached_patterns = array_merge($cached_patterns, $user_patterns);
        }
    }

    $text = strtolower($name . ' ' . $description);
    foreach ($cached_patterns as $p) {
        $p = trim(strtolower($p));
        if (empty($p)) continue;
        if (@preg_match('/' . $p . '/iu', $text)) return true;
    }
    return false;
}

/**
 * Try to crawl a detail page for richer event data.
 * Scrapy-inspired: follow event link → extract structured data from the detail page.
 * @param int $max_depth 1 = detail page only, 2 = also follow links found on detail page
 */
function sbe_enrich_event_from_detail(array $ev, array $fetch_opts, int $retries, int $retry_delay, int $max_depth = 1): array {
    if (empty($ev['url'])) return $ev;

    $html = sbe_fetch_with_retry($ev['url'], $fetch_opts, $retries, $retry_delay);
    if (is_wp_error($html) || empty($html)) return $ev;
    $html = sbe_ensure_utf8($html);

    // 1. Try JSON-LD on detail page
    $jsonld_events = sbe_extract_events_jsonld($html);
    if (!empty($jsonld_events)) {
        $detail = $jsonld_events[0];
        if (empty($ev['description']) && !empty($detail['description']))
            $ev['description'] = $detail['description'];
        if (empty($ev['start_date']) && !empty($detail['start_date']))
            $ev['start_date'] = $detail['start_date'];
        if (empty($ev['end_date']) && !empty($detail['end_date']))
            $ev['end_date'] = $detail['end_date'];
        if (empty($ev['image_url']) && !empty($detail['image_url']))
            $ev['image_url'] = $detail['image_url'];
        if (empty($ev['ticket_url']) && !empty($detail['ticket_url']))
            $ev['ticket_url'] = $detail['ticket_url'];
        if (empty($ev['price']) && !empty($detail['price']))
            $ev['price'] = $detail['price'];
        return $ev;
    }

    // 2. Fallback: extract meta tags from detail page
    $meta = sbe_parse_meta_tags($html);
    if (empty($ev['description']) && !empty($meta['description']))
        $ev['description'] = $meta['description'];
    if (empty($ev['image_url']) && !empty($meta['image']))
        $ev['image_url'] = $meta['image'];

    // 2b. Deep HTML parsing – tables, info-boxes, dl/dt/dd, key-value patterns
    $extracted = sbe_extract_structured_info($html);
    if (empty($ev['start_date']) && !empty($extracted['date']))
        $ev['start_date'] = $extracted['date'];
    if (empty($ev['end_date']) && !empty($extracted['end_date']))
        $ev['end_date'] = $extracted['end_date'];
    if (empty($ev['price']) && !empty($extracted['price']))
        $ev['price'] = $extracted['price'];
    if (empty($ev['ticket_url']) && !empty($extracted['ticket_url']))
        $ev['ticket_url'] = $extracted['ticket_url'];
    if (empty($ev['image_url']) && !empty($extracted['image_url']))
        $ev['image_url'] = $extracted['image_url'];

    // 3. Try to find a longer description in the page body
    if (empty($ev['description']) || strlen($ev['description']) < 50) {
        $dom = new DOMDocument();
        libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="UTF-8">' . $html);
        libxml_clear_errors();
        $x = new DOMXPath($dom);
        $content_sels = [
            '//*[contains(@class,"entry-content")]',
            '//*[contains(@class,"post-content")]',
            '//*[contains(@class,"article-content")]',
            '//*[contains(@class,"event-content")]',
            '//*[contains(@class,"event-description")]',
            '//article',
            '//main',
        ];
        foreach ($content_sels as $sel) {
            $nodes = $x->query($sel);
            if ($nodes && $nodes->length > 0) {
                $text = trim($nodes->item(0)->textContent);
                $text = preg_replace('/\s+/', ' ', $text);
                if (strlen($text) > 80 && strlen($text) > strlen($ev['description'] ?? '')) {
                    $ev['description'] = substr($text, 0, 800);
                    break;
                }
            }
        }
    }

    // 4. Try to find date if still missing
    if (empty($ev['start_date'])) {
        if (preg_match('/<time[^>]+datetime=["\']([^"\']+)["\']/i', $html, $m)) {
            $ev['start_date'] = sbe_normalize_date($m[1]);
        }
    }

    // 5. Depth 2: follow links on detail page for ticket/price info
    if ($max_depth >= 2) {
        $dom2 = new DOMDocument();
        libxml_use_internal_errors(true);
        $dom2->loadHTML('<?xml encoding="UTF-8">' . $html);
        libxml_clear_errors();
        $x2 = new DOMXPath($dom2);

        // Look for ticket links
        if (empty($ev['ticket_url'])) {
            $ticket_sels = [
                '//a[contains(@href,"ticket")]',
                '//a[contains(@class,"ticket")]',
                '//a[contains(@class,"buy")]',
                '//a[contains(@class,"cta")]',
                '//a[contains(translate(text(),"ABCDEFGHIJKLMNOPQRSTUVWXYZ","abcdefghijklmnopqrstuvwxyz"),"ticket")]',
                '//a[contains(translate(text(),"ABCDEFGHIJKLMNOPQRSTUVWXYZ","abcdefghijklmnopqrstuvwxyz"),"kaufen")]',
                '//a[contains(translate(text(),"ABCDEFGHIJKLMNOPQRSTUVWXYZ","abcdefghijklmnopqrstuvwxyz"),"buchen")]',
            ];
            foreach ($ticket_sels as $sel) {
                $nodes = $x2->query($sel);
                if ($nodes && $nodes->length > 0) {
                    $href = $nodes->item(0)->getAttribute('href');
                    if ($href && $href !== '#') {
                        if (!str_starts_with($href, 'http')) {
                            $parsed = parse_url($ev['url']);
                            $href = ($parsed['scheme'] ?? 'https') . '://' . ($parsed['host'] ?? '') . '/' . ltrim($href, '/');
                        }
                        $ev['ticket_url'] = $href;
                        break;
                    }
                }
            }
        }

        // Look for price on the detail page
        if (empty($ev['price'])) {
            if (preg_match('/(\d+[.,]?\d*)\s*€|€\s*(\d+[.,]?\d*)|EUR\s*(\d+[.,]?\d*)|(\d+[.,]?\d*)\s*EUR/i', $html, $pm)) {
                $ev['price'] = $pm[1] ?: $pm[2] ?: $pm[3] ?: $pm[4];
            }
        }
    }

    return $ev;
}

/**
 * Deep HTML parsing – extract structured info from tables, dl/dt/dd, info-boxes.
 * Looks for dates, prices, ticket links, images via multiple strategies.
 */
function sbe_extract_structured_info(string $html): array {
    $info = ['date' => null, 'end_date' => null, 'price' => '', 'ticket_url' => '', 'image_url' => ''];

    $dom = new DOMDocument();
    libxml_use_internal_errors(true);
    $dom->loadHTML('<?xml encoding="UTF-8">' . $html);
    libxml_clear_errors();
    $x = new DOMXPath($dom);

    // ── Strategy 1: Definition lists (dl/dt/dd) ─────────────────────────
    $dts = $x->query('//dt');
    if ($dts) {
        foreach ($dts as $dt) {
            $label = strtolower(trim($dt->textContent));
            $dd = $x->query('following-sibling::dd[1]', $dt)->item(0);
            if (!$dd) continue;
            $value = trim($dd->textContent);
            if (empty($value)) continue;

            if (preg_match('/datum|date|wann|termin|zeit/i', $label) && empty($info['date'])) {
                $info['date'] = sbe_parse_date($value);
            }
            if (preg_match('/ende|bis|end/i', $label) && empty($info['end_date'])) {
                $info['end_date'] = sbe_parse_date($value);
            }
            if (preg_match('/preis|eintritt|kosten|price|fee|buy.in|buy-in/i', $label) && empty($info['price'])) {
                $info['price'] = sbe_extract_price_from_text($value);
            }
            if (preg_match('/ticket|karten|anmeldung|buchung|registration/i', $label)) {
                $link = $x->query('.//a[@href]', $dd)->item(0);
                if ($link) $info['ticket_url'] = $link->getAttribute('href');
            }
        }
    }

    // ── Strategy 2: Tables with label-value rows ────────────────────────
    $rows = $x->query('//table//tr');
    if ($rows) {
        foreach ($rows as $row) {
            $cells = $x->query('.//td|.//th', $row);
            if ($cells->length < 2) continue;
            $label = strtolower(trim($cells->item(0)->textContent));
            $valueTd = $cells->item(1);
            $value = trim($valueTd->textContent);

            if (preg_match('/datum|date|wann|termin|beginn|start/i', $label) && empty($info['date'])) {
                $info['date'] = sbe_parse_date($value);
            }
            if (preg_match('/ende|bis|end/i', $label) && empty($info['end_date'])) {
                $info['end_date'] = sbe_parse_date($value);
            }
            if (preg_match('/preis|eintritt|kosten|price|buy.in|garantie|guarantee/i', $label) && empty($info['price'])) {
                $info['price'] = sbe_extract_price_from_text($value);
            }
            if (preg_match('/ticket|karten|buchung|anmeldung/i', $label)) {
                $link = $x->query('.//a[@href]', $valueTd)->item(0);
                if ($link && empty($info['ticket_url'])) $info['ticket_url'] = $link->getAttribute('href');
            }
        }
    }

    // ── Strategy 3: Key-value divs / spans (class-based) ────────────────
    $kv_sels = [
        '//*[contains(@class,"event-detail")]',
        '//*[contains(@class,"event-info")]',
        '//*[contains(@class,"event-meta")]',
        '//*[contains(@class,"info-box")]',
        '//*[contains(@class,"details")]',
        '//*[contains(@class,"fact")]',
    ];
    foreach ($kv_sels as $sel) {
        $nodes = $x->query($sel);
        if (!$nodes) continue;
        foreach ($nodes as $node) {
            $text = $node->textContent;
            // Try to extract date from this block
            if (empty($info['date'])) {
                if (preg_match('/(\d{1,2})\.(\d{1,2})\.(\d{4})(?:\s*[\-–]\s*(\d{1,2})\.(\d{1,2})\.(\d{4}))?/', $text, $dm)) {
                    $info['date'] = sprintf('%04d-%02d-%02d 20:00:00', $dm[3], $dm[2], $dm[1]);
                    if (!empty($dm[4]) && empty($info['end_date'])) {
                        $info['end_date'] = sprintf('%04d-%02d-%02d 23:59:00', $dm[6], $dm[5], $dm[4]);
                    }
                }
            }
            // Try to extract price
            if (empty($info['price'])) {
                $info['price'] = sbe_extract_price_from_text($text);
            }
        }
    }

    // ── Strategy 4: Regex on full HTML for common patterns ──────────────
    // Date: "15. März 2026" or "15.03.2026 – 17.03.2026"
    if (empty($info['date'])) {
        if (preg_match('/<time[^>]+datetime=["\']([^"\']+)["\']/i', $html, $m)) {
            $info['date'] = sbe_normalize_date($m[1]);
        }
    }

    // Price: €XX or XX € or EUR XX patterns
    if (empty($info['price'])) {
        $info['price'] = sbe_extract_price_from_text(strip_tags($html));
    }

    // Ticket URL from any link with ticket-related text
    if (empty($info['ticket_url'])) {
        $ticket_links = $x->query('//a[contains(translate(@href,"ABCDEFGHIJKLMNOPQRSTUVWXYZ","abcdefghijklmnopqrstuvwxyz"),"ticket") or contains(translate(@href,"ABCDEFGHIJKLMNOPQRSTUVWXYZ","abcdefghijklmnopqrstuvwxyz"),"buchung") or contains(translate(@href,"ABCDEFGHIJKLMNOPQRSTUVWXYZ","abcdefghijklmnopqrstuvwxyz"),"reserv")]');
        if ($ticket_links && $ticket_links->length > 0) {
            $info['ticket_url'] = $ticket_links->item(0)->getAttribute('href');
        }
    }

    // OG image as last resort
    if (empty($info['image_url'])) {
        if (preg_match('/<meta[^>]+property=["\']og:image["\'][^>]+content=["\'](.*?)["\']/is', $html, $m)) {
            $info['image_url'] = trim($m[1]);
        }
    }

    return $info;
}

/**
 * Extract a price value from arbitrary text.
 * Handles: "50 €", "€ 50", "EUR 50", "50,00 EUR", "ab 25€", "Buy-in: 100+10"
 */
function sbe_extract_price_from_text(string $text): string {
    // Limit text to first 2000 chars to avoid parsing entire pages
    $text = substr($text, 0, 2000);

    // "Buy-in: 100+10" poker format
    if (preg_match('/buy[\s\-]*in[:\s]*(\d+\s*\+\s*\d+)/i', $text, $m)) {
        return str_replace(' ', '', $m[1]);
    }
    // "50,00 €" or "50 €" or "€50" or "€ 50,00"
    if (preg_match('/(\d+[.,]?\d*)\s*€/', $text, $m)) return $m[1];
    if (preg_match('/€\s*(\d+[.,]?\d*)/', $text, $m)) return $m[1];
    // "EUR 50" or "50 EUR"
    if (preg_match('/EUR\s*(\d+[.,]?\d*)/i', $text, $m)) return $m[1];
    if (preg_match('/(\d+[.,]?\d*)\s*EUR/i', $text, $m)) return $m[1];
    // "ab 25" (entry fee pattern)
    if (preg_match('/(?:eintritt|entry|ab|from)[:\s]*(\d+[.,]?\d*)/i', $text, $m)) return $m[1];

    return '';
}

/**
 * Detect page encoding and convert to UTF-8 if necessary.
 * Scrapy-inspired: handle non-UTF-8 pages gracefully.
 */
function sbe_ensure_utf8(string $html): string {
    // Check for charset in meta tag
    if (preg_match('/<meta[^>]+charset=["\']?([a-zA-Z0-9\-]+)/i', $html, $m)) {
        $charset = strtoupper(trim($m[1]));
        if ($charset && $charset !== 'UTF-8' && $charset !== 'UTF8') {
            $converted = @iconv($charset, 'UTF-8//IGNORE', $html);
            if ($converted) return $converted;
        }
    }
    // Check for Content-Type header charset
    if (preg_match('/<meta[^>]+content=["\'][^"\']*charset=([a-zA-Z0-9\-]+)/i', $html, $m)) {
        $charset = strtoupper(trim($m[1]));
        if ($charset && $charset !== 'UTF-8' && $charset !== 'UTF8') {
            $converted = @iconv($charset, 'UTF-8//IGNORE', $html);
            if ($converted) return $converted;
        }
    }
    // If it's not valid UTF-8, try latin1
    if (!mb_check_encoding($html, 'UTF-8')) {
        $converted = @mb_convert_encoding($html, 'UTF-8', 'ISO-8859-1');
        if ($converted) return $converted;
    }
    return $html;
}

function sbe_parse_date(string $raw): ?string {
    if (empty(trim($raw))) return null;
    $raw = preg_replace('/\s+/', ' ', trim($raw));
    $months = [
        'januar'=>1,'februar'=>2,'märz'=>3,'maerz'=>3,'april'=>4,'mai'=>5,'juni'=>6,
        'juli'=>7,'august'=>8,'september'=>9,'oktober'=>10,'november'=>11,'dezember'=>12,
        'jan'=>1,'feb'=>2,'mär'=>3,'apr'=>4,'jun'=>6,'jul'=>7,'aug'=>8,
        'sep'=>9,'okt'=>10,'nov'=>11,'dez'=>12,
    ];
    if (preg_match('/(\d{1,2})\.(\d{1,2})\.(\d{4})/', $raw, $m))
        return sprintf('%04d-%02d-%02d 20:00:00', $m[3], $m[2], $m[1]);
    if (preg_match('/(\d{1,2})\.\s*([a-zA-ZäöüÄÖÜ]+)\s+(\d{4})/u', $raw, $m)) {
        $mon = strtolower($m[2]);
        if (isset($months[$mon]))
            return sprintf('%04d-%02d-%02d 20:00:00', $m[3], $months[$mon], $m[1]);
    }
    $ts = strtotime($raw);
    if ($ts && $ts > 0) return date('Y-m-d H:i:s', $ts);
    return null;
}

function sbe_normalize_date(string $raw): ?string {
    $ts = strtotime($raw);
    return ($ts && $ts > 0) ? date('Y-m-d H:i:s', $ts) : null;
}

function sbe_guess_type(string $text): string {
    $t = strtolower($text);
    if (preg_match('/poker|turnier|tournament|series|championship/', $t)) return 'Poker';
    if (preg_match('/blackjack/', $t))                                    return 'Blackjack';
    if (preg_match('/roulette/', $t))                                     return 'Roulette';
    if (preg_match('/slot|machine|automat/', $t))                         return 'Slots';
    if (preg_match('/concert|musik|music|band|dj|live|open.air/', $t))   return 'Musik';
    return 'Sonstige';
}

// ══════════════════════════════════════════════════════════════════════════════
// AUTO-DISCOVERY: Detect sub-pages from Base-URL
// ══════════════════════════════════════════════════════════════════════════════

/**
 * Step-based autodiscover – each call executes one phase and returns partial results.
 * Steps: robots → sitemap → homepage → bruteforce_events → bruteforce_contact → done
 */
function sbe_autodiscover_step(string $base_url, string $step, array $state): array {
    $settings  = sbe_get_settings();
    $base_url  = rtrim($base_url, '/');
    $fetch_opts = [
        'timeout'    => min(10, (int)$settings['request_timeout']),
        'user-agent' => $settings['user_agent'],
        'headers'    => ['Accept-Language' => $settings['accept_language']],
    ];

    // Initialize state on first call
    if (empty($state)) {
        $state = [
            'name'        => '',
            'sitemap_url' => '',
            'robots_url'  => $base_url . '/robots.txt',
            'events_urls' => [],
            'contact_url' => '',
            'seed_data'   => null,
            'log'         => [],
        ];
    }

    switch ($step) {

        case 'robots':
            $robots_body = sbe_fetch($base_url . '/robots.txt', $fetch_opts);
            if (!is_wp_error($robots_body) && !empty($robots_body)) {
                $state['log'][] = '✓ robots.txt gefunden';
                if (preg_match('/^Sitemap:\s*(.+)$/mi', $robots_body, $m)) {
                    $state['sitemap_url'] = trim($m[1]);
                    $state['log'][] = '✓ Sitemap-URL: ' . $state['sitemap_url'];
                }
            } else {
                $state['log'][] = 'ℹ robots.txt nicht erreichbar';
            }
            return ['step' => 'robots', 'next' => 'sitemap', 'state' => $state];

        case 'sitemap':
            $sitemap_url = $state['sitemap_url'] ?: $base_url . '/sitemap.xml';
            $sitemap_urls = sbe_autodiscover_from_sitemap($sitemap_url, $base_url, $fetch_opts);
            if (!empty($sitemap_urls['events'])) {
                $state['events_urls'] = array_merge($state['events_urls'], $sitemap_urls['events']);
                $state['log'][] = '✓ Sitemap: ' . count($sitemap_urls['events']) . ' Event-Seiten gefunden';
            }
            if (!empty($sitemap_urls['contact']) && empty($state['contact_url'])) {
                $state['contact_url'] = $sitemap_urls['contact'];
                $state['log'][] = '✓ Sitemap: Kontakt-Seite gefunden';
            }
            if (!empty($sitemap_urls['sitemap'])) {
                $state['sitemap_url'] = $sitemap_urls['sitemap'];
            }
            if (empty($sitemap_urls['events']) && empty($sitemap_urls['contact'])) {
                $state['log'][] = 'ℹ Sitemap: keine relevanten URLs gefunden';
            }
            return ['step' => 'sitemap', 'next' => 'homepage', 'state' => $state];

        case 'homepage':
            $home_html = sbe_fetch($base_url, $fetch_opts);
            if (!is_wp_error($home_html) && !empty($home_html)) {
                $home_html = sbe_ensure_utf8($home_html);
                $state['log'][] = '✓ Homepage geladen';

                if (preg_match('/<title[^>]*>([^<]+)/i', $home_html, $m)) {
                    $title = trim(html_entity_decode($m[1], ENT_QUOTES));
                    $title = preg_replace('/\s*[\|–\-]\s*[^|–\-]+$/', '', $title);
                    $state['name'] = $title;
                }

                $jsonld = sbe_extract_jsonld($home_html);
                if (!empty($jsonld)) {
                    $state['seed_data'] = $jsonld;
                    if (!empty($jsonld['name'])) $state['name'] = $jsonld['name'];
                    $state['log'][] = '✓ JSON-LD Business-Daten gefunden';
                }

                $nav_links = sbe_extract_nav_links($home_html, $base_url);
                if (!empty($nav_links['events'])) {
                    $state['events_urls'] = array_merge($state['events_urls'], $nav_links['events']);
                    $state['log'][] = '✓ Navigation: ' . count($nav_links['events']) . ' Event-Links gefunden';
                }
                if (!empty($nav_links['contact']) && empty($state['contact_url'])) {
                    $state['contact_url'] = $nav_links['contact'];
                    $state['log'][] = '✓ Navigation: Kontakt-Link gefunden';
                }
            } else {
                $state['log'][] = '⚠ Homepage nicht erreichbar';
            }
            // Skip brute-force if we already found events
            $next = empty($state['events_urls']) ? 'bruteforce_events' : (empty($state['contact_url']) ? 'bruteforce_contact' : 'done');
            return ['step' => 'homepage', 'next' => $next, 'state' => $state];

        case 'bruteforce_events':
            $event_paths = [
                '/events/', '/events', '/veranstaltungen/', '/veranstaltungen',
                '/programm/', '/programm', '/kalender/', '/calendar/',
                '/turniere/', '/tournaments/', '/poker/', '/poker-turniere/',
                '/en/events/', '/en/news/', '/news/', '/aktuelles/',
            ];
            foreach ($event_paths as $path) {
                $test_url = $base_url . $path;
                $resp = sbe_fetch($test_url, array_merge($fetch_opts, ['timeout' => 5]));
                if (!is_wp_error($resp) && !empty($resp) && strlen($resp) > 500) {
                    $state['events_urls'][] = $test_url;
                    $state['log'][] = '✓ Brute-Force: ' . $path . ' erreichbar';
                    break;
                }
                usleep(300000);
            }
            if (empty($state['events_urls'])) {
                $state['log'][] = '⚠ Keine Event-Seiten gefunden';
            }
            $next = empty($state['contact_url']) ? 'bruteforce_contact' : 'done';
            return ['step' => 'bruteforce_events', 'next' => $next, 'state' => $state];

        case 'bruteforce_contact':
            $contact_paths = [
                '/kontakt/', '/kontakt', '/contact/', '/contact',
                '/en/contact/', '/impressum/', '/impressum',
            ];
            foreach ($contact_paths as $path) {
                $test_url = $base_url . $path;
                $resp = sbe_fetch($test_url, array_merge($fetch_opts, ['timeout' => 5]));
                if (!is_wp_error($resp) && !empty($resp) && strlen($resp) > 500) {
                    $state['contact_url'] = $test_url;
                    $state['log'][] = '✓ Brute-Force: ' . $path . ' erreichbar';
                    break;
                }
                usleep(300000);
            }
            if (empty($state['contact_url'])) {
                $state['log'][] = 'ℹ Keine Kontaktseite gefunden';
            }
            return ['step' => 'bruteforce_contact', 'next' => 'done', 'state' => $state];

        case 'done':
        default:
            $state['events_urls'] = array_values(array_unique($state['events_urls']));
            $state['log'][] = '✓ Auto-Erkennung abgeschlossen';
            return ['step' => 'done', 'next' => null, 'state' => $state];
    }
}

/**
 * Full autodiscover (non-step, for internal use).
 */
function sbe_autodiscover_urls(string $base_url): array {
    $settings  = sbe_get_settings();
    $base_url  = rtrim($base_url, '/');
    $fetch_opts = [
        'timeout'    => min(10, (int)$settings['request_timeout']),
        'user-agent' => $settings['user_agent'],
        'headers'    => ['Accept-Language' => $settings['accept_language']],
    ];

    $result = [
        'name'        => '',
        'sitemap_url' => '',
        'robots_url'  => $base_url . '/robots.txt',
        'events_urls' => [],
        'contact_url' => '',
        'seed_data'   => null,
        'log'         => [],
    ];

    // ── 1. robots.txt → find Sitemap directive ──────────────────────────
    $robots_body = sbe_fetch($base_url . '/robots.txt', $fetch_opts);
    if (!is_wp_error($robots_body) && !empty($robots_body)) {
        $result['log'][] = '✓ robots.txt gefunden';
        if (preg_match('/^Sitemap:\s*(.+)$/mi', $robots_body, $m)) {
            $result['sitemap_url'] = trim($m[1]);
            $result['log'][] = '✓ Sitemap-URL aus robots.txt: ' . $result['sitemap_url'];
        }
    }

    // ── 2. Sitemap parsen ───────────────────────────────────────────────
    $sitemap_url = $result['sitemap_url'] ?: $base_url . '/sitemap.xml';
    $sitemap_urls = sbe_autodiscover_from_sitemap($sitemap_url, $base_url, $fetch_opts);
    if (!empty($sitemap_urls)) {
        if (!empty($sitemap_urls['events'])) {
            $result['events_urls'] = array_merge($result['events_urls'], $sitemap_urls['events']);
            $result['log'][] = '✓ Sitemap: ' . count($sitemap_urls['events']) . ' Event-Seiten gefunden';
        }
        if (!empty($sitemap_urls['contact']) && empty($result['contact_url'])) {
            $result['contact_url'] = $sitemap_urls['contact'];
            $result['log'][] = '✓ Sitemap: Kontakt-Seite gefunden';
        }
        if (!empty($sitemap_urls['sitemap'])) {
            $result['sitemap_url'] = $sitemap_urls['sitemap'];
        }
    }

    // ── 3. Homepage crawlen → Nav-Links + JSON-LD ───────────────────────
    $home_html = sbe_fetch($base_url, $fetch_opts);
    if (!is_wp_error($home_html) && !empty($home_html)) {
        $home_html = sbe_ensure_utf8($home_html);
        $result['log'][] = '✓ Homepage geladen';

        // Extract site name
        if (preg_match('/<title[^>]*>([^<]+)/i', $home_html, $m)) {
            $title = trim(html_entity_decode($m[1], ENT_QUOTES));
            // Clean up common suffixes
            $title = preg_replace('/\s*[\|–\-]\s*[^|–\-]+$/', '', $title);
            $result['name'] = $title;
        }

        // JSON-LD business data → seed_data
        $jsonld = sbe_extract_jsonld($home_html);
        if (!empty($jsonld)) {
            $result['seed_data'] = $jsonld;
            if (!empty($jsonld['name'])) $result['name'] = $jsonld['name'];
            $result['log'][] = '✓ JSON-LD Business-Daten gefunden';
        }

        // Parse nav/footer links for event and contact pages
        $nav_links = sbe_extract_nav_links($home_html, $base_url);
        if (!empty($nav_links['events'])) {
            $result['events_urls'] = array_merge($result['events_urls'], $nav_links['events']);
            $result['log'][] = '✓ Navigation: ' . count($nav_links['events']) . ' Event-Links gefunden';
        }
        if (!empty($nav_links['contact']) && empty($result['contact_url'])) {
            $result['contact_url'] = $nav_links['contact'];
            $result['log'][] = '✓ Navigation: Kontakt-Link gefunden';
        }
    }

    // ── 4. Brute-force gängige Pfade ────────────────────────────────────
    $event_paths = [
        '/events/', '/events', '/veranstaltungen/', '/veranstaltungen',
        '/programm/', '/programm', '/kalender/', '/calendar/',
        '/turniere/', '/tournaments/', '/poker/', '/poker-turniere/',
        '/en/events/', '/en/news/', '/news/', '/aktuelles/',
    ];
    $contact_paths = [
        '/kontakt/', '/kontakt', '/contact/', '/contact',
        '/en/contact/', '/impressum/', '/impressum',
    ];

    // Only probe paths we haven't found yet
    if (empty($result['events_urls'])) {
        foreach ($event_paths as $path) {
            $test_url = $base_url . $path;
            $resp = sbe_fetch($test_url, array_merge($fetch_opts, ['timeout' => 5]));
            if (!is_wp_error($resp) && !empty($resp) && strlen($resp) > 500) {
                $result['events_urls'][] = $test_url;
                $result['log'][] = '✓ Brute-Force: ' . $path . ' erreichbar';
                break; // One is enough for brute-force
            }
            usleep(300000); // 300ms polite delay
        }
    }

    if (empty($result['contact_url'])) {
        foreach ($contact_paths as $path) {
            $test_url = $base_url . $path;
            $resp = sbe_fetch($test_url, array_merge($fetch_opts, ['timeout' => 5]));
            if (!is_wp_error($resp) && !empty($resp) && strlen($resp) > 500) {
                $result['contact_url'] = $test_url;
                $result['log'][] = '✓ Brute-Force: ' . $path . ' erreichbar';
                break;
            }
            usleep(300000);
        }
    }

    // Deduplicate events URLs
    $result['events_urls'] = array_values(array_unique($result['events_urls']));

    if (empty($result['events_urls'])) {
        $result['log'][] = '⚠ Keine Event-Seiten gefunden – bitte manuell eingeben.';
    }
    if (empty($result['contact_url'])) {
        $result['log'][] = 'ℹ Keine Kontaktseite gefunden.';
    }

    return $result;
}

/**
 * Parse sitemap (and sitemap index) for event/contact URLs.
 */
function sbe_autodiscover_from_sitemap(string $sitemap_url, string $base_url, array $fetch_opts): array {
    $found = ['events' => [], 'contact' => '', 'sitemap' => $sitemap_url];
    $body  = sbe_fetch($sitemap_url, $fetch_opts);
    if (is_wp_error($body) || empty($body)) return $found;

    // Sitemap index → find sub-sitemaps
    if (str_contains($body, '<sitemapindex')) {
        preg_match_all('/<loc>\s*(.*?)\s*<\/loc>/i', $body, $m);
        foreach ($m[1] as $child_url) {
            if (preg_match('/event|veranstaltung|news|aktuell|post|page/i', $child_url)) {
                $child_body = sbe_fetch($child_url, $fetch_opts);
                if (!is_wp_error($child_body) && !empty($child_body)) {
                    $sub = sbe_autodiscover_classify_sitemap_urls($child_body, $base_url);
                    $found['events']  = array_merge($found['events'], $sub['events']);
                    if (!empty($sub['contact'])) $found['contact'] = $sub['contact'];
                }
                usleep(200000);
            }
        }
    } else {
        $sub = sbe_autodiscover_classify_sitemap_urls($body, $base_url);
        $found['events']  = $sub['events'];
        $found['contact'] = $sub['contact'];
    }

    // Limit results
    $found['events'] = array_slice(array_unique($found['events']), 0, 10);
    return $found;
}

/**
 * Classify URLs from a sitemap body into events/contact categories.
 */
function sbe_autodiscover_classify_sitemap_urls(string $xml, string $base_url): array {
    $events  = [];
    $contact = '';
    preg_match_all('/<loc>\s*(.*?)\s*<\/loc>/i', $xml, $m);
    foreach ($m[1] as $url) {
        $url  = trim($url);
        $path = strtolower(wp_parse_url($url, PHP_URL_PATH) ?: '');

        if (preg_match('/event|veranstaltung|turnier|tournament|programm|kalender/i', $path)) {
            // Only add list pages (not individual event posts with dates/IDs)
            if (!preg_match('/\d{4}[\-\/]\d{2}|\d{6,}/', $path)) {
                $events[] = $url;
            }
        }
        if (empty($contact) && preg_match('/kontakt|contact|impressum/i', $path)) {
            $contact = $url;
        }
    }
    return ['events' => $events, 'contact' => $contact];
}

/**
 * Extract navigation and footer links from HTML, classify into event/contact.
 */
function sbe_extract_nav_links(string $html, string $base_url): array {
    $events  = [];
    $contact = '';

    $dom = new DOMDocument();
    libxml_use_internal_errors(true);
    $dom->loadHTML('<?xml encoding="UTF-8">' . $html);
    libxml_clear_errors();
    $x = new DOMXPath($dom);

    // Search in nav, header, footer areas
    $areas = [
        '//nav//a[@href]',
        '//header//a[@href]',
        '//footer//a[@href]',
        '//*[contains(@class,"menu")]//a[@href]',
        '//*[contains(@class,"nav")]//a[@href]',
    ];

    $seen = [];
    foreach ($areas as $sel) {
        $links = $x->query($sel);
        if (!$links) continue;
        foreach ($links as $link) {
            $href = $link->getAttribute('href');
            $text = strtolower(trim($link->textContent));
            if (empty($href) || $href === '#' || str_starts_with($href, 'javascript:')) continue;

            // Make absolute
            if (!str_starts_with($href, 'http')) {
                $href = rtrim($base_url, '/') . '/' . ltrim($href, '/');
            }

            // Only same domain
            $link_host = wp_parse_url($href, PHP_URL_HOST);
            $base_host = wp_parse_url($base_url, PHP_URL_HOST);
            if ($link_host && $base_host && $link_host !== $base_host) continue;

            if (isset($seen[$href])) continue;
            $seen[$href] = true;

            $path = strtolower(wp_parse_url($href, PHP_URL_PATH) ?: '');

            // Classify by link text AND path
            $is_event = preg_match('/event|veranstaltung|turnier|tournament|programm|kalender|poker|was ist los/i', $text . ' ' . $path);
            $is_contact = preg_match('/kontakt|contact|impressum|anfahrt/i', $text . ' ' . $path);

            if ($is_event && !preg_match('/\d{4}[\-\/]\d{2}/', $path)) {
                $events[] = $href;
            }
            if ($is_contact && empty($contact)) {
                $contact = $href;
            }
        }
    }

    return ['events' => array_values(array_unique($events)), 'contact' => $contact];
}

// ══════════════════════════════════════════════════════════════════════════════
// EXTERNAL EVENTS: Search Eventim, Eventbrite, Reservix for casino events
// ══════════════════════════════════════════════════════════════════════════════

/**
 * Search external ticket portals for events matching a query (spielbank name).
 * Returns merged, deduplicated results from all portals.
 */
function sbe_search_external_events(string $query): array {
    $settings   = sbe_get_settings();
    $fetch_opts = [
        'timeout'    => 10,
        'user-agent' => $settings['user_agent'],
        'headers'    => ['Accept-Language' => 'de-DE,de;q=0.9,en;q=0.8'],
    ];

    $all_events = [];
    $log        = [];

    // ── Eventim ──────────────────────────────────────────────────────────
    $eventim_url = 'https://www.eventim.de/search/?searchterm=' . urlencode($query);
    $eventim_html = sbe_fetch($eventim_url, $fetch_opts);
    if (!is_wp_error($eventim_html) && !empty($eventim_html)) {
        $eventim_html = sbe_ensure_utf8($eventim_html);
        $found = sbe_parse_eventim_results($eventim_html);
        $all_events = array_merge($all_events, $found);
        $log[] = '✓ Eventim: ' . count($found) . ' Ergebnisse';
    } else {
        $log[] = 'ℹ Eventim: nicht erreichbar';
    }

    sleep(1);

    // ── Eventbrite ───────────────────────────────────────────────────────
    $eventbrite_url = 'https://www.eventbrite.de/d/germany/' . urlencode($query) . '/';
    $eventbrite_html = sbe_fetch($eventbrite_url, $fetch_opts);
    if (!is_wp_error($eventbrite_html) && !empty($eventbrite_html)) {
        $eventbrite_html = sbe_ensure_utf8($eventbrite_html);
        $found = sbe_parse_eventbrite_results($eventbrite_html);
        $all_events = array_merge($all_events, $found);
        $log[] = '✓ Eventbrite: ' . count($found) . ' Ergebnisse';
    } else {
        $log[] = 'ℹ Eventbrite: nicht erreichbar';
    }

    sleep(1);

    // ── Reservix ─────────────────────────────────────────────────────────
    $reservix_url = 'https://www.reservix.de/suche?q=' . urlencode($query);
    $reservix_html = sbe_fetch($reservix_url, $fetch_opts);
    if (!is_wp_error($reservix_html) && !empty($reservix_html)) {
        $reservix_html = sbe_ensure_utf8($reservix_html);
        $found = sbe_parse_reservix_results($reservix_html);
        $all_events = array_merge($all_events, $found);
        $log[] = '✓ Reservix: ' . count($found) . ' Ergebnisse';
    } else {
        $log[] = 'ℹ Reservix: nicht erreichbar';
    }

    return ['events' => $all_events, 'log' => $log, 'query' => $query];
}

/**
 * Parse Eventim search results page.
 */
function sbe_parse_eventim_results(string $html): array {
    $events = [];

    // Eventim uses JSON-LD for search results
    $jsonld_events = sbe_extract_events_jsonld($html);
    if (!empty($jsonld_events)) {
        foreach ($jsonld_events as &$ev) {
            $ev['source'] = 'eventim.de';
            $ev['ticket_url'] = $ev['url'] ?? '';
        }
        return array_slice($jsonld_events, 0, 20);
    }

    // DOM fallback
    $dom = new DOMDocument();
    libxml_use_internal_errors(true);
    $dom->loadHTML('<?xml encoding="UTF-8">' . $html);
    libxml_clear_errors();
    $x = new DOMXPath($dom);

    $cards = $x->query('//*[contains(@class,"event-card") or contains(@class,"search-result") or contains(@class,"product-card")]');
    if (!$cards) return [];

    foreach ($cards as $card) {
        $title_el = $x->query('.//h2|.//h3|.//h4|.//*[contains(@class,"title")]', $card)->item(0);
        if (!$title_el) continue;
        $name = trim($title_el->textContent);
        if (strlen($name) < 5) continue;

        $link = $x->query('.//a[@href]', $card)->item(0);
        $url  = $link ? $link->getAttribute('href') : '';
        if ($url && !str_starts_with($url, 'http')) $url = 'https://www.eventim.de' . $url;

        $date_el = $x->query('.//*[contains(@class,"date") or self::time]', $card)->item(0);
        $date    = $date_el ? sbe_parse_date($date_el->getAttribute('datetime') ?: $date_el->textContent) : null;

        $loc_el = $x->query('.//*[contains(@class,"location") or contains(@class,"venue")]', $card)->item(0);
        $location = $loc_el ? trim($loc_el->textContent) : '';

        $price_el = $x->query('.//*[contains(@class,"price")]', $card)->item(0);
        $price = $price_el ? sbe_extract_price_from_text($price_el->textContent) : '';

        $img_el = $x->query('.//img[@src or @data-src]', $card)->item(0);
        $img    = $img_el ? ($img_el->getAttribute('data-src') ?: $img_el->getAttribute('src')) : '';

        $events[] = [
            'name'        => $name,
            'start_date'  => $date,
            'url'         => $url,
            'ticket_url'  => $url,
            'location'    => $location,
            'price'       => $price,
            'image_url'   => $img,
            'source'      => 'eventim.de',
            'event_type'  => sbe_guess_type($name),
        ];
        if (count($events) >= 20) break;
    }
    return $events;
}

/**
 * Parse Eventbrite search results page.
 */
function sbe_parse_eventbrite_results(string $html): array {
    $events = [];

    // Eventbrite often embeds JSON-LD
    $jsonld_events = sbe_extract_events_jsonld($html);
    if (!empty($jsonld_events)) {
        foreach ($jsonld_events as &$ev) {
            $ev['source'] = 'eventbrite.de';
            $ev['ticket_url'] = $ev['url'] ?? '';
        }
        return array_slice($jsonld_events, 0, 20);
    }

    // DOM fallback for Eventbrite's card structure
    $dom = new DOMDocument();
    libxml_use_internal_errors(true);
    $dom->loadHTML('<?xml encoding="UTF-8">' . $html);
    libxml_clear_errors();
    $x = new DOMXPath($dom);

    $cards = $x->query('//*[contains(@class,"event-card") or contains(@class,"search-event")]');
    if (!$cards) return [];

    foreach ($cards as $card) {
        $title_el = $x->query('.//h2|.//h3|.//*[contains(@class,"event-card__title")]', $card)->item(0);
        if (!$title_el) continue;
        $name = trim($title_el->textContent);
        if (strlen($name) < 5) continue;

        $link = $x->query('.//a[@href]', $card)->item(0);
        $url  = $link ? $link->getAttribute('href') : '';

        $date_el = $x->query('.//*[contains(@class,"date") or self::time or contains(@class,"event-card__date")]', $card)->item(0);
        $date    = $date_el ? sbe_parse_date($date_el->getAttribute('datetime') ?: $date_el->textContent) : null;

        $loc_el = $x->query('.//*[contains(@class,"location") or contains(@class,"venue") or contains(@class,"event-card__venue")]', $card)->item(0);
        $location = $loc_el ? trim($loc_el->textContent) : '';

        $price_el = $x->query('.//*[contains(@class,"price")]', $card)->item(0);
        $price = $price_el ? sbe_extract_price_from_text($price_el->textContent) : '';

        $img_el = $x->query('.//img[@src or @data-src]', $card)->item(0);
        $img    = $img_el ? ($img_el->getAttribute('data-src') ?: $img_el->getAttribute('src')) : '';

        $events[] = [
            'name'        => $name,
            'start_date'  => $date,
            'url'         => $url,
            'ticket_url'  => $url,
            'location'    => $location,
            'price'       => $price,
            'image_url'   => $img,
            'source'      => 'eventbrite.de',
            'event_type'  => sbe_guess_type($name),
        ];
        if (count($events) >= 20) break;
    }
    return $events;
}

/**
 * Parse Reservix search results page.
 */
function sbe_parse_reservix_results(string $html): array {
    $events = [];
    $dom = new DOMDocument();
    libxml_use_internal_errors(true);
    $dom->loadHTML('<?xml encoding="UTF-8">' . $html);
    libxml_clear_errors();
    $x = new DOMXPath($dom);

    // Reservix uses JSON-LD sometimes
    $jsonld_events = sbe_extract_events_jsonld($html);
    if (!empty($jsonld_events)) {
        foreach ($jsonld_events as &$ev) {
            $ev['source'] = 'reservix.de';
            $ev['ticket_url'] = $ev['url'] ?? '';
        }
        return array_slice($jsonld_events, 0, 20);
    }

    // DOM fallback
    $cards = $x->query('//*[contains(@class,"event") and (contains(@class,"item") or contains(@class,"card") or contains(@class,"result"))]');
    if (!$cards) return [];

    foreach ($cards as $card) {
        $title_el = $x->query('.//h2|.//h3|.//h4|.//*[contains(@class,"title")]', $card)->item(0);
        if (!$title_el) continue;
        $name = trim($title_el->textContent);
        if (strlen($name) < 5) continue;

        $link = $x->query('.//a[@href]', $card)->item(0);
        $url  = $link ? $link->getAttribute('href') : '';
        if ($url && !str_starts_with($url, 'http')) $url = 'https://www.reservix.de' . $url;

        $date_el = $x->query('.//*[contains(@class,"date") or self::time]', $card)->item(0);
        $date    = $date_el ? sbe_parse_date($date_el->getAttribute('datetime') ?: $date_el->textContent) : null;

        $loc_el = $x->query('.//*[contains(@class,"location") or contains(@class,"venue")]', $card)->item(0);
        $location = $loc_el ? trim($loc_el->textContent) : '';

        $price_el = $x->query('.//*[contains(@class,"price")]', $card)->item(0);
        $price = $price_el ? sbe_extract_price_from_text($price_el->textContent) : '';

        $events[] = [
            'name'        => $name,
            'start_date'  => $date,
            'url'         => $url,
            'ticket_url'  => $url,
            'location'    => $location,
            'price'       => $price,
            'source'      => 'reservix.de',
            'event_type'  => sbe_guess_type($name),
        ];
        if (count($events) >= 20) break;
    }
    return $events;
}
