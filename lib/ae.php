<?php
// Helpers for the .ae watch list.

/** Public second-level zones under .ae that anyone can register in. */
const AE_SECOND_LEVEL = ['co', 'net', 'org', 'pro', 'name'];

/**
 * Turns any hostname (www.shop.example.co.ae, *.example.ae) into the registrable
 * .ae domain (example.co.ae / example.ae), or null if it isn't one.
 */
function ae_registrable(string $host): ?string
{
    $host = strtolower(trim($host, " \t\r\n.*"));
    if (!str_ends_with($host, '.ae') || !preg_match('/^[a-z0-9.-]+$/', $host)) return null;
    $labels = explode('.', $host);
    $n = count($labels);
    if ($n < 2) return null;
    if ($n >= 3 && in_array($labels[$n - 2], AE_SECOND_LEVEL, true)) {
        $name = $labels[$n - 3] . '.' . $labels[$n - 2] . '.ae';
    } elseif (in_array($labels[$n - 2], ['gov', 'mil', 'ac', 'sch'], true)) {
        return null; // restricted zones
    } else {
        $name = $labels[$n - 2] . '.ae';
    }
    $sld = explode('.', $name)[0];
    if ($sld === '' || strlen($sld) > 63 || $sld[0] === '-' || str_ends_with($sld, '-')) return null;
    return $name;
}

/** Adds names to the watch list (ignores ones already there). Returns how many were new. */
function ae_add(array $domains, string $source): int
{
    $domains = array_values(array_unique(array_filter($domains)));
    if (!$domains) return 0;
    $before = (int)db()->query('SELECT COUNT(*) FROM ae_domains')->fetchColumn();
    $today = gmdate('Y-m-d');
    $ins = is_sqlite() ? 'INSERT OR IGNORE' : 'INSERT IGNORE';
    db()->beginTransaction();
    foreach (array_chunk($domains, 500) as $chunk) {
        $ph = implode(',', array_fill(0, count($chunk), '(?,?,?,?,?,?,?,?)'));
        $params = [];
        foreach ($chunk as $d) {
            $sld = substr($d, 0, strpos($d, '.'));
            $tld = substr($d, strpos($d, '.') + 1);
            array_push($params, $d, $sld, $tld, strlen($sld), preg_match('/\d/', $sld) ? 1 : 0, str_contains($sld, '-') ? 1 : 0, $source, $today);
        }
        db()->prepare("$ins INTO ae_domains (domain, sld, tld, sld_len, has_digits, has_hyphen, source, first_seen) VALUES $ph")->execute($params);
    }
    db()->commit();
    return (int)db()->query('SELECT COUNT(*) FROM ae_domains')->fetchColumn() - $before;
}

/**
 * Queries aeDA WHOIS. Returns ['state' => available|registered|pending|error, 'raw' => status text].
 */
function ae_whois(string $domain): array
{
    $f = @fsockopen((string)config('ae_whois_host'), 43, $errno, $err, 10);
    if (!$f) return ['state' => 'error', 'raw' => "connect: $err"];
    stream_set_timeout($f, 15);
    fwrite($f, $domain . "\r\n");
    $resp = stream_get_contents($f);
    fclose($f);
    if ($resp === false || trim($resp) === '') return ['state' => 'error', 'raw' => 'empty response'];
    if (stripos($resp, 'BLACKLISTED') !== false || stripos($resp, 'exceeded the query limit') !== false) {
        return ['state' => 'blocked', 'raw' => 'rate limit'];
    }
    if (stripos($resp, 'No Data Found') !== false) return ['state' => 'available', 'raw' => 'No Data Found'];
    if (stripos($resp, 'Domain Name:') === false) return ['state' => 'error', 'raw' => substr(trim($resp), 0, 120)];

    preg_match_all('/^\s*Status:\s*(.+)$/mi', $resp, $m);
    $statuses = array_map('trim', $m[1] ?? []);
    $raw = substr(implode(', ', $statuses) ?: 'ok', 0, 120);
    // Anything that signals the name is on its way out
    $pending = preg_match('/pendingDelete|redemption|expired|hold|suspend|quarantine|pendingRestore/i', $raw);
    return ['state' => $pending ? 'pending' : 'registered', 'raw' => $raw];
}

/** Starting keywords for crt.sh searches. More are learned from every .ae name found. */
function ae_seed_keywords(): array
{
    $w = 'dubai abudhabi sharjah ajman fujairah rasalkhaimah rak ummalquwain alain uae emirates emirati gulf arab arabia khaleej
    alnoor almadina alwasl jumeirah deira bur marina jebel jafza difc dmcc dafza kizad masdar khalifa zayed rashid maktoum nahyan
    business trading trade tradings group holding holdings company corp general enterprises international global services solutions
    consult consulting consultancy advisory accounting audit tax legal law lawyers advocates notary insurance finance capital invest
    investment bank exchange money gold jewellery jewelry diamond watches perfume perfumes oud attar fragrance cosmetics beauty salon
    spa clinic medical dental doctor hospital health pharmacy care wellness fitness gym yoga sports academy school college university
    education training institute learning kids nursery travel tours tourism holidays hotel hotels resort apartments rent rental cars
    car auto motors garage parts tyres rentacar limousine transport logistics shipping cargo freight courier delivery express marine
    yacht boat fishing real estate realestate property properties homes villa villas building construction contracting contractors
    engineering technical maintenance cleaning facility fm security fire safety electrical electro mechanical plumbing ac cooling
    aluminium steel glass wood furniture interior interiors design designs decor art gallery studio photo media advertising marketing
    digital tech technology techno it software web app apps cloud data network systems smart ai cyber computer mobile phone telecom
    food foods restaurant cafe coffee bakery sweets chocolate dates catering kitchen grill shawarma burger pizza juice water fresh
    organic market mart supermarket store shop shopping mall online fashion style boutique abaya kandura textiles fabrics shoes bags
    gifts flowers events wedding party printing press packaging plastic paper chemicals oil gas energy solar power petroleum industries
    industrial factory manufacturing equipment machinery tools hardware supply supplies supplier trading distribution import export
    agency agencies broker brokers recruitment jobs careers hr manpower visa typing documents clearing pro government smart
    halal islamic quran masjid mosque charity foundation society club association council center centre hub zone point plus pro
    royal golden silver star sun moon pearl palm falcon desert oasis sand camel horse najm noor amal salam hayat baraka faisal ahmed mohammed';
    return array_values(array_unique(array_filter(preg_split('/\s+/', $w))));
}
