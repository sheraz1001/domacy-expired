<?php
// Checks whether deleted domains are really available, using free RDAP (no API key).
// RDAP 404 = not registered (available), 200 = registered again / still on hold.
// Runs every 5 minutes. Checks many names in parallel, best names first:
// newest drop, no numbers, no hyphens, shortest.
require __DIR__ . '/../lib/bootstrap.php';
cli_only();
@set_time_limit(0);

$limit = max(1, (int)config('rdap_per_run'));
$parallel = max(1, (int)config('rdap_parallel'));
$bootstrap = rdap_bootstrap();

$rows = db()->query("SELECT id, domain, tld FROM deleted_domains WHERE status = 'unknown'
    ORDER BY dropped_on DESC, has_digits ASC, has_hyphen ASC, sld_len ASC, domain ASC LIMIT $limit")->fetchAll();

$upd = db()->prepare('UPDATE deleted_domains SET status = ?, checked_at = ? WHERE id = ?');
$stats = ['available' => 0, 'registered' => 0, 'error' => 0, 'no_rdap' => 0];
$blocked = [];       // TLDs that rate-limited us this run
$queue = [];
foreach ($rows as $r) {
    $base = $bootstrap[$r['tld']] ?? null;
    if (!$base) { $upd->execute(['no_rdap', gmdate('Y-m-d H:i:s'), $r['id']]); $stats['no_rdap']++; continue; }
    $r['url'] = rtrim($base, '/') . '/domain/' . rawurlencode($r['domain']);
    $queue[] = $r;
}

$mh = curl_multi_init();
$active = [];
$started = time();
$pending = [];
db()->beginTransaction();

$add = function () use (&$queue, &$active, $mh, &$blocked) {
    while ($queue) {
        $r = array_shift($queue);
        if (isset($blocked[$r['tld']])) continue;
        $ch = curl_init($r['url']);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_NOBODY => false,
            CURLOPT_TIMEOUT => 20, CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_HTTPHEADER => ['Accept: application/rdap+json'],
            CURLOPT_USERAGENT => 'DomacyExpired/1.0 (+https://expired.domacy.com)',
        ]);
        curl_multi_add_handle($mh, $ch);
        $active[(int)$ch] = $r;
        return true;
    }
    return false;
};

for ($i = 0; $i < $parallel; $i++) if (!$add()) break;

do {
    curl_multi_exec($mh, $running);
    curl_multi_select($mh, 1.0);
    while ($info = curl_multi_info_read($mh)) {
        $ch = $info['handle'];
        $r = $active[(int)$ch];
        unset($active[(int)$ch]);
        $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_multi_remove_handle($mh, $ch);
        curl_close($ch);

        if ($code === 404) $status = 'available';
        elseif ($code === 200) $status = 'registered';
        elseif ($code === 429) {
            if (!isset($blocked[$r['tld']])) logmsg("Rate limited by .{$r['tld']} RDAP, pausing that TLD until next run");
            $blocked[$r['tld']] = true; $status = null;
        } else { $stats['error']++; $status = null; }

        if ($status) {
            $stats[$status]++;
            $upd->execute([$status, gmdate('Y-m-d H:i:s'), $r['id']]);
            if (($stats['available'] + $stats['registered']) % 500 === 0) { db()->commit(); db()->beginTransaction(); }
        }
        $add();
    }
} while ($active || ($running ?? 0) > 0);

db()->commit();
curl_multi_close($mh);
if ($stats['available'] || $stats['registered']) clear_cache();
logmsg(sprintf('Checked %d in %ds: %d available, %d registered, %d errors, %d without RDAP',
    count($rows), time() - $started, $stats['available'], $stats['registered'], $stats['error'], $stats['no_rdap']));

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
