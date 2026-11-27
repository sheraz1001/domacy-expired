<?php
// Search-engine settings: which pages are indexed, their titles, descriptions, canonical URLs and FAQ.
// Only clean pages are indexed: a tab, optionally with one ending (tld). Searches, filters, sorting and
// later pages are "noindex, follow" so they are crawled for links but never compete with the clean pages.

const SEO_TLD_PAGES = 20; // endings per tab listed in the sitemap and in "Popular endings"

function seo_tld(): string
{
    $t = strtolower(preg_replace('/[^a-z0-9\-.]/i', '', f('tld')));
    return str_contains($t, ',') ? '' : $t;
}

function seo_indexable(): bool
{
    if (!config('allow_indexing')) return false;
    $allowed = ['tab', 'tld'];
    foreach ($_GET as $k => $v) {
        if (!in_array($k, $allowed, true) && $v !== '' && $v !== null) return false;
    }
    if (f('tld') !== '' && seo_tld() === '') return false;
    return true;
}

function seo_url(string $tab, string $tld = ''): string
{
    $base = config('site_url');
    if ($tab === 'deleted' && $tld === '') return $base . '/';
    $q = ['tab' => $tab];
    if ($tld !== '') $q['tld'] = $tld;
    return $base . '/?' . http_build_query($q);
}

function seo_title(string $tab, string $tld): string
{
    $e = $tld !== '' ? '.' . $tld . ' ' : '';
    return match ($tab) {
        'expiring' => "Expiring {$e}domain auctions with authority and backlinks",
        'closeout' => "Expired {$e}domains for sale: closeouts from \$5",
        'ae' => 'Expiring and deleted .ae domains (UAE): pending delete and just released',
        default => $tld !== '' ? "Expired .{$tld} domains: deleted names available to register" : 'Expired domains: deleted names available to register today',
    };
}

function seo_description(string $tab, string $tld): string
{
    $e = $tld !== '' ? '.' . $tld . ' ' : '';
    return match ($tab) {
        'expiring' => "Search expiring {$e}domain auctions free: Semrush authority, referring domains, age, search volume and price, updated every day.",
        'closeout' => "Buy expired {$e}domains from \$5: unsold closeout auctions with authority, age and backlinks. Free daily list, search and CSV export.",
        'ae' => 'Free list of expiring .ae and .co.ae domains: names in pending delete at the UAE registry and names that have just become free to register.',
        default => "Free expired {$e}domain list: names deleted from the registry in the last 30 days, checked so you can see which are available to register now.",
    };
}

function seo_h1(string $tab, string $tld): string
{
    $e = $tld !== '' ? '.' . h($tld) . ' ' : '';
    return match ($tab) {
        'expiring' => "Expiring {$e}<span class=\"grad\">domain auctions</span>.",
        'closeout' => "Expired {$e}domains from <span class=\"grad\">\$5</span>.",
        'ae' => 'Expiring <span class="grad">.ae domains</span>.',
        default => "Find <span class=\"grad\">expired {$e}domains</span>.",
    };
}

/** Questions answered on every page and marked up as FAQPage. */
function seo_faq(): array
{
    return [
        ['What is an expired domain?', 'An expired domain is a name whose owner did not renew it. After the expiry date it passes through a grace period and a redemption period, and if nobody renews it the registry deletes it and anyone can register it again.'],
        ['What is the difference between deleted, expiring and closeout domains?', 'Deleted domains have been removed from the registry and can be registered at the normal price if they are still free. Expiring auctions are expired names being auctioned before deletion. Closeouts are auction names that did not sell and can be bought instantly, from $5.'],
        ['Why do people buy expired domains?', 'An expired domain may already have links from other websites, an age and type-in visitors, and short or memorable names come back on the market this way. Check its history and reputation first, because some expired names were used for spam.'],
        ['How do I register an expired domain?', 'Find a deleted name marked Available and choose Register. It opens Domacy search with the name filled in, where you see the first-year and renewal price before you pay.'],
        ['How often is the expired domain list updated?', 'Every day. Deleted names come from the official registry zone files each morning, auctions and closeouts are refreshed each afternoon, and availability is rechecked every 15 minutes.'],
        ['Is this expired domain search free?', 'Yes. Searching, filtering and downloading up to 10,000 results as a CSV file are free and need no account.'],
        ['What if the expired domain I want is already taken?', 'Place a backorder at Domacy. We watch the name and try to register it for you if it is released. Placing a backorder is free and there is no guarantee.'],
    ];
}

function seo_jsonld(string $tab, string $tld): string
{
    $site = config('site_url');
    $faq = array_map(fn($q) => ['@type' => 'Question', 'name' => $q[0], 'acceptedAnswer' => ['@type' => 'Answer', 'text' => $q[1]]], seo_faq());
    $graph = [
        ['@type' => 'WebSite', '@id' => $site . '/#website', 'url' => $site . '/', 'name' => 'Domacy Expired Domains',
            'publisher' => ['@type' => 'Organization', 'name' => 'Domacy', 'url' => config('main_site')],
            'potentialAction' => ['@type' => 'SearchAction', 'target' => $site . '/?tab=deleted&q={query}', 'query-input' => 'required name=query']],
        ['@type' => 'BreadcrumbList', 'itemListElement' => array_values(array_filter([
            ['@type' => 'ListItem', 'position' => 1, 'name' => 'Domacy', 'item' => config('main_site') . '/'],
            ['@type' => 'ListItem', 'position' => 2, 'name' => 'Expired domains', 'item' => $site . '/'],
            $tab !== 'deleted' || $tld !== '' ? ['@type' => 'ListItem', 'position' => 3, 'name' => TABS[$tab]['label'] . ($tld ? " .$tld" : ''), 'item' => seo_url($tab, $tld)] : null,
        ]))],
        ['@type' => 'FAQPage', 'mainEntity' => $faq],
    ];
    return '<script type="application/ld+json">' . json_encode(['@context' => 'https://schema.org', '@graph' => $graph], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . '</script>';
}
