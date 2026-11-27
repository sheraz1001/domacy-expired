<?php
// Checks whether deleted domains are really available, using free RDAP (no API key).
// RDAP 404 = not registered (available), 200 = registered again / still on hold.
// Run every 10-15 minutes:  php cron/check_rdap.php
require __DIR__ . '/../lib/bootstrap.php';
cli_only();
@set_time_limit(0);

$limit = (int)config('rdap_per_run');
$delay = (int)config('rdap_delay_ms') * 1000;
$bootstrap = rdap_bootstrap();

// Newest drops first, shortest names first (the ones people look at)
$rows = db()->query("SELECT id, domain, tld FROM deleted_domains WHERE status = 'unknown'
    ORDER BY dropped_on DESC, sld_len ASC LIMIT $limit")->fetchAll();

$upd = db()->prepare('UPDATE deleted_domains SET status = ?, checked_at = ? WHERE id = ?');
$stats = ['available' => 0, 'registered' => 0, 'error' => 0];
$blockedTlds = [];

foreach ($rows as $r) {
    if (isset($blockedTlds[$r['tld']])) continue;
    $base = $bootstrap[$r['tld']] ?? null;
    if (!$base) { $upd->execute(['no_rdap', gmdate('Y-m-d H:i:s'), $r['id']]); continue; }

    $res = http_get(rtrim($base, '/') . '/domain/' . rawurlencode($r['domain']), ['Accept: application/rdap+json'], null, 20);
    if ($res['code'] === 404) $status = 'available';
    elseif ($res['code'] === 200) $status = 'registered';
    elseif ($res['code'] === 429) { $blockedTlds[$r['tld']] = true; logmsg("Rate limited by {$r['tld']} RDAP, pausing that TLD"); continue; }
    else { $stats['error']++; continue; }

    $stats[$status]++;
    $upd->execute([$status, gmdate('Y-m-d H:i:s'), $r['id']]);
    usleep($delay);
}
if ($stats['available'] || $stats['registered']) clear_cache();
logmsg(sprintf('Checked %d: %d available, %d registered, %d errors', count($rows), $stats['available'], $stats['registered'], $stats['error']));

function rdap_bootstrap(): array
{
    $cache = data_dir() . '/rdap_dns.json';
    if (!is_file($cache) || filemtime($cache) < time() - 7 * 86400) {
        $r = http_get('https://data.iana.org/rdap/dns.json');
        if ($r['code'] === 200) file_put_contents($cache, $r['body']);
    }
    $map = [];
    foreach (json_decode((string)@file_get_contents($cache), true)['services'] ?? [] as [$tlds, $urls]) {
        $url = $urls[0];
        foreach ($urls as $u) if (str_starts_with($u, 'https')) { $url = $u; break; }
        foreach ($tlds as $t) $map[strtolower($t)] = $url;
    }
    return $map;
}
