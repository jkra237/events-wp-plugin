<?php
defined('ABSPATH') || exit;

/**
 * Scraper – zieht LocalBusiness/GamblingResort + OpeningHoursSpecification + Events
 * aus öffentlichen Spielbank-Seiten. robots.txt wird vor jedem Request geprüft.
 */

// ── Source definitions ────────────────────────────────────────────────────────
function sbe_get_sources(): array {
    return [
        [
            'name'          => 'spielbank-berlin.de',
            'base_url'      => 'https://www.spielbank-berlin.de',
            'events_url'    => 'https://www.spielbank-berlin.de/en/news-2/',
            'contact_url'   => 'https://www.spielbank-berlin.de/en/contact/',
            'robots_url'    => 'https://www.spielbank-berlin.de/robots.txt',
            // Known static facts (fallback / seed when live scrape fails)
            'seed' => [
                'name'             => 'Spielbank Berlin',
                'url'              => 'https://www.spielbank-berlin.de',
                'telephone'        => '+49 30 255990',
                'email'            => 'info@spielbank-berlin.de',
                'street_address'   => 'Marlene-Dietrich-Platz 1',
                'address_locality' => 'Berlin',
                'postal_code'      => '10785',
                'address_region'   => 'Berlin',
                'address_country'  => 'DE',
                'latitude'         => 52.5094090,
                'longitude'        => 13.3754280,
                'price_range'      => '€€€',
                'currencies_accepted' => 'EUR',
                'payment_accepted' => 'Cash, Credit Card',
                'is_accessible'    => 1,
                'smoking_allowed'  => 0,
                'same_as'          => json_encode(['https://www.facebook.com/SpielBankBerlin','https://www.instagram.com/spielbankberlin']),
                'has_map'          => 'https://maps.google.com/?q=Spielbank+Berlin+Potsdamer+Platz',
            ],
            'seed_hours' => [
                ['Monday','11:00','03:00'],['Tuesday','11:00','03:00'],
                ['Wednesday','11:00','03:00'],['Thursday','11:00','03:00'],
                ['Friday','11:00','04:00'],['Saturday','11:00','04:00'],
                ['Sunday','11:00','03:00'],
            ],
        ],
        [
            'name'          => 'spielbank-hamburg.de',
            'base_url'      => 'https://www.spielbank-hamburg.de',
            'events_url'    => 'https://www.spielbank-hamburg.de/veranstaltungen/',
            'contact_url'   => 'https://www.spielbank-hamburg.de/kontakt/',
            'robots_url'    => 'https://www.spielbank-hamburg.de/robots.txt',
            'seed' => [
                'name'             => 'Spielbank Hamburg',
                'url'              => 'https://www.spielbank-hamburg.de',
                'telephone'        => '+49 40 334660',
                'email'            => 'info@spielbank-hamburg.de',
                'street_address'   => 'Stephansplatz 10',
                'address_locality' => 'Hamburg',
                'postal_code'      => '20354',
                'address_region'   => 'Hamburg',
                'address_country'  => 'DE',
                'latitude'         => 53.5614780,
                'longitude'        => 9.9901330,
                'price_range'      => '€€€',
                'currencies_accepted' => 'EUR',
                'payment_accepted' => 'Cash, Credit Card, EC',
                'is_accessible'    => 1,
                'smoking_allowed'  => 0,
                'same_as'          => json_encode(['https://www.facebook.com/SpielBankHamburg','https://www.instagram.com/spielbankhamburg']),
                'has_map'          => 'https://maps.google.com/?q=Spielbank+Hamburg+Stephansplatz',
            ],
            'seed_hours' => [
                ['Monday','12:00','04:00'],['Tuesday','12:00','04:00'],
                ['Wednesday','12:00','04:00'],['Thursday','12:00','04:00'],
                ['Friday','12:00','05:00'],['Saturday','12:00','05:00'],
                ['Sunday','12:00','04:00'],
            ],
        ],
    ];
}

// ── Main runner ───────────────────────────────────────────────────────────────
function sbe_run_scraper(): array {
    $log         = [];
    $events_saved = 0;
    $locs_saved  = 0;
    $skipped     = 0;
    $errors      = [];

    foreach (sbe_get_sources() as $src) {
        $log[] = "▶ Quelle: {$src['name']}";

        // ── robots.txt check ──────────────────────────────────────────────────
        if (!sbe_robots_allowed($src['robots_url'], $src['events_url'])) {
            $log[]    = "  ✗ robots.txt verbietet Scraping – übersprungen.";
            $errors[] = $src['name'] . ': robots.txt disallow';
            continue;
        }
        $log[] = "  ✓ robots.txt OK";

        // ── 1. Location / business data ───────────────────────────────────────
        $loc_data = $src['seed'];  // start with known seed data
        $loc_data['source'] = $src['name'];
        $loc_data['status'] = 'active';

        // Try to enrich from contact/imprint page
        $contact_html = sbe_fetch($src['contact_url']);
        if (!is_wp_error($contact_html) && !empty($contact_html)) {
            $log[] = "  ✓ Kontaktseite geladen";
            $enriched = sbe_parse_contact_page($contact_html, $src['base_url']);
            // Only override seed fields if we actually found something
            foreach ($enriched as $k => $v) {
                if (!empty($v)) $loc_data[$k] = $v;
            }
        } else {
            $log[] = "  ℹ Kontaktseite nicht erreichbar – nutze Seed-Daten";
        }

        // Also try main page for description/image
        $main_html = sbe_fetch($src['base_url']);
        if (!is_wp_error($main_html) && !empty($main_html)) {
            $meta = sbe_parse_meta_tags($main_html);
            if (!empty($meta['description']) && empty($loc_data['description'])) {
                $loc_data['description'] = $meta['description'];
            }
            if (!empty($meta['image']) && empty($loc_data['image_url'])) {
                $loc_data['image_url'] = $meta['image'];
            }
            if (!empty($meta['logo']) && empty($loc_data['logo_url'])) {
                $loc_data['logo_url'] = $meta['logo'];
            }
            $log[] = "  ✓ Hauptseite geparst (Meta-Tags)";
        }

        // Try to extract JSON-LD from source page (many WP sites already have it)
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

        // ── 2. Opening hours ─────────────────────────────────────────────────
        $hours = [];
        if (!empty($main_html)) {
            $hours = sbe_parse_opening_hours($main_html);
        }
        if (empty($hours)) {
            // Fallback to seed hours
            $hours = array_map(function($h) {
                return ['day_of_week'=>$h[0],'opens'=>$h[1],'closes'=>$h[2]];
            }, $src['seed_hours']);
            $log[] = "  ℹ Öffnungszeiten: Seed-Daten genutzt";
        } else {
            $log[] = "  ✓ " . count($hours) . " Öffnungszeiten geparst";
        }
        sbe_replace_hours($loc_id, $hours);

        // ── 3. Events ─────────────────────────────────────────────────────────
        sleep(1); // polite delay
        $events_html = sbe_fetch($src['events_url']);
        if (is_wp_error($events_html) || empty($events_html)) {
            $log[]    = "  ✗ Events-Seite nicht erreichbar";
            $errors[] = $src['name'] . ': events page fetch failed';
        } else {
            $log[]   = "  ✓ Events-Seite geladen (" . strlen($events_html) . " Bytes)";
            $events  = sbe_parse_events($events_html, $src, $loc_id);
            $log[]   = "  → " . count($events) . " Events gefunden";

            foreach ($events as $ev) {
                if (sbe_event_exists($ev['name'], $ev['start_date'] ?? null)) {
                    $skipped++;
                    continue;
                }
                sbe_save_event($ev);
                $events_saved++;
            }
        }

        sleep(1);
    }

    return [
        'log'          => $log,
        'locs_saved'   => $locs_saved,
        'events_saved' => $events_saved,
        'skipped'      => $skipped,
        'errors'       => $errors,
        'message'      => "$locs_saved Standorte + $events_saved neue Events gespeichert.",
    ];
}

// ── Robots.txt ────────────────────────────────────────────────────────────────
function sbe_robots_allowed(string $robots_url, string $target): bool {
    $body = sbe_fetch($robots_url, 5);
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

// ── HTTP Fetch ────────────────────────────────────────────────────────────────
function sbe_fetch(string $url, int $timeout = 15) {
    $resp = wp_remote_get($url, [
        'timeout'    => $timeout,
        'user-agent' => 'SpielbankEventBot/1.0 (WordPress; +https://spielbank.com.de)',
        'headers'    => ['Accept-Language' => 'de-DE,de;q=0.9,en;q=0.8'],
    ]);
    if (is_wp_error($resp)) return $resp;
    $code = wp_remote_retrieve_response_code($resp);
    if ($code >= 400) return new WP_Error('http_error', "HTTP $code für $url");
    return wp_remote_retrieve_body($resp);
}

// ── Parse: Meta tags (OG / standard) ─────────────────────────────────────────
function sbe_parse_meta_tags(string $html): array {
    $data = [];
    // og:description / description
    if (preg_match('/<meta[^>]+(?:name=["\']description["\']|property=["\']og:description["\'])[^>]+content=["\'](.*?)["\']/is', $html, $m))
        $data['description'] = html_entity_decode(trim($m[1]), ENT_QUOTES);
    // og:image
    if (preg_match('/<meta[^>]+property=["\']og:image["\'][^>]+content=["\'](.*?)["\']/is', $html, $m))
        $data['image'] = trim($m[1]);
    // logo via link rel
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

        // Handle @graph
        $items = isset($json['@graph']) ? $json['@graph'] : [$json];
        foreach ($items as $item) {
            $type = is_array($item['@type'] ?? null) ? $item['@type'] : [$item['@type'] ?? ''];
            $is_biz = array_intersect($type, ['LocalBusiness','GamblingResort','CasinoOrGamblingPlace','TouristAttraction']);
            if (empty($is_biz)) continue;

            if (!empty($item['name']))        $data['name']        = $item['name'];
            if (!empty($item['description'])) $data['description'] = $item['description'];
            if (!empty($item['telephone']))   $data['telephone']   = $item['telephone'];
            if (!empty($item['email']))       $data['email']       = $item['email'];
            if (!empty($item['url']))         $data['url']         = $item['url'];
            if (!empty($item['image']))       $data['image_url']   = is_array($item['image']) ? ($item['image']['url'] ?? $item['image'][0]) : $item['image'];
            if (!empty($item['logo']))        $data['logo_url']    = is_array($item['logo'])  ? ($item['logo']['url'] ?? '') : $item['logo'];
            if (!empty($item['priceRange']))  $data['price_range'] = $item['priceRange'];

            // Address
            $addr = $item['address'] ?? null;
            if ($addr) {
                if (!empty($addr['streetAddress']))   $data['street_address']   = $addr['streetAddress'];
                if (!empty($addr['addressLocality'])) $data['address_locality'] = $addr['addressLocality'];
                if (!empty($addr['postalCode']))       $data['postal_code']      = $addr['postalCode'];
                if (!empty($addr['addressRegion']))    $data['address_region']   = $addr['addressRegion'];
                if (!empty($addr['addressCountry']))   $data['address_country']  = $addr['addressCountry'];
            }

            // Geo
            $geo = $item['geo'] ?? null;
            if ($geo) {
                if (!empty($geo['latitude']))  $data['latitude']  = (float)$geo['latitude'];
                if (!empty($geo['longitude'])) $data['longitude'] = (float)$geo['longitude'];
            }

            // AggregateRating
            $rating = $item['aggregateRating'] ?? null;
            if ($rating) {
                if (!empty($rating['ratingValue'])) $data['rating_value']  = (float)$rating['ratingValue'];
                if (!empty($rating['reviewCount'])) $data['review_count']  = (int)$rating['reviewCount'];
                if (!empty($rating['bestRating']))  $data['best_rating']   = (float)$rating['bestRating'];
            }

            // sameAs
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

    // Phone: look for tel: links or patterns
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

    // Address via structured text
    if (preg_match('/(\d{5})\s+([A-ZÄÖÜ][a-zäöüß\-]+(?:\s[A-ZÄÖÜ][a-zäöüß\-]+)*)/u', $html, $m)) {
        $data['postal_code']      = $m[1];
        $data['address_locality'] = trim($m[2]);
    }

    // Opening hours from contact page
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

    // Attempt 2: openingHours short form  e.g. "Mo-Fr 11:00-03:00"
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
    $days    = [];
    $all     = ['Monday','Tuesday','Wednesday','Thursday','Friday','Saturday','Sunday'];
    $str     = trim(strtolower($str));

    // Split by comma
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
        foreach ($jsonld_events as &$ev) {
            $ev['location_id'] = $loc_id;
            $ev['organizer']   = $src['seed']['name'];
            $ev['location']    = $src['seed']['name'];
            $ev['city']        = $src['seed']['address_locality'];
            $ev['address']     = $src['seed']['street_address'] . ', ' . $src['seed']['postal_code'] . ' ' . $src['seed']['address_locality'];
            $ev['source']      = $src['name'];
            $ev['status']      = 'scraped';
        }
        return $jsonld_events;
    }

    // DOM fallback
    $selectors = ['//article', '//*[contains(@class,"post")]', '//*[contains(@class,"event")]', '//*[contains(@class,"entry")]'];
    $nodes = null;
    foreach ($selectors as $sel) {
        $found = $x->query($sel);
        if ($found && $found->length > 0) { $nodes = $found; break; }
    }
    if (!$nodes) return [];

    foreach ($nodes as $node) {
        $title_node = $x->query('.//h2|.//h3|.//h4', $node)->item(0);
        if (!$title_node) continue;
        $title = trim($title_node->textContent);
        if (strlen($title) < 5 || strlen($title) > 250) continue;

        $link_node = $x->query('.//a[@href]', $node)->item(0);
        $url = '';
        if ($link_node) {
            $url = $link_node->getAttribute('href');
            if ($url && !str_starts_with($url, 'http')) {
                $url = rtrim($src['base_url'], '/') . '/' . ltrim($url, '/');
            }
        }

        $desc_node = $x->query('.//p', $node)->item(0);
        $desc = $desc_node ? trim(substr($desc_node->textContent, 0, 500)) : '';

        $date_node = $x->query('.//*[contains(@class,"date") or contains(@class,"time") or self::time]', $node)->item(0);
        $date_raw  = $date_node ? trim($date_node->textContent) : '';
        $date_iso  = sbe_parse_date($date_raw);

        // Image
        $img_node = $x->query('.//img[@src]', $node)->item(0);
        $img_url  = '';
        if ($img_node) {
            $img_url = $img_node->getAttribute('src');
            if ($img_url && !str_starts_with($img_url, 'http')) {
                $img_url = rtrim($src['base_url'], '/') . '/' . ltrim($img_url, '/');
            }
        }

        $events[] = [
            'location_id' => $loc_id,
            'name'        => $title,
            'url'         => $url,
            'description' => $desc,
            'start_date'  => $date_iso,
            'image_url'   => $img_url,
            'location'    => $src['seed']['name'],
            'city'        => $src['seed']['address_locality'],
            'address'     => $src['seed']['street_address'] . ', ' . $src['seed']['postal_code'] . ' ' . $src['seed']['address_locality'],
            'organizer'   => $src['seed']['name'],
            'event_type'  => sbe_guess_type($title . ' ' . $desc),
            'source'      => $src['name'],
            'status'      => 'scraped',
        ];

        if (count($events) >= 25) break;
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

            // Ticket/offer
            if (!empty($item['offers'])) {
                $offer = is_array($item['offers']) ? $item['offers'][0] : $item['offers'];
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
