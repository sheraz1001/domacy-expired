<?php
// Checks .ae names on the watch list against aeDA WHOIS, politely (1 query per 1.5 s).
// Priority: never checked > pending (expiring) > recently released > registered not checked for 7 days.
// A name that was registered and is now "No Data Found" is recorded as released today.
// Runs every 5 minutes:  php cron/ae_whois.php
require __DIR__ . '/../lib/bootstrap.php';
require __DIR__ . '/../lib/ae.php';
cli_only();
@set_time_limit(0);

$limit = max(1, (int)config('ae_whois_per_run'));
$delay = max(0, (int)config('ae_whois_delay_ms')) * 1000;
$now = gmdate('Y-m-d H:i:s');
$today = gmdate('Y-m-d');
$h12 = gmdate('Y-m-d H:i:s', time() - 12 * 3600);
$d1 = gmdate('Y-m-d H:i:s', time() - 86400);
$d7 = gmdate('Y-m-d H:i:s', time() - 7 * 86400);

$picks = [];
$sqls = [
    "SELECT * FROM ae_domains WHERE checked_at IS NULL ORDER BY has_digits, has_hyphen, sld_len LIMIT %d",
    "SELECT * FROM ae_domains WHERE status = 'pending' AND checked_at < '$h12' ORDER BY checked_at LIMIT %d",
    "SELECT * FROM ae_domains WHERE status = 'available' AND checked_at < '$d1' ORDER BY checked_at LIMIT %d",
    "SELECT * FROM ae_domains WHERE status = 'registered' AND checked_at < '$d7' ORDER BY checked_at LIMIT %d",
];
foreach ($sqls as $sql) {
    $left = $limit - count($picks);
    if ($left <= 0) break;
    foreach (db()->query(sprintf($sql, $left)) as $r) $picks[$r['id']] = $r;
}

$upd = db()->prepare('UPDATE ae_domains SET status = ?, whois_status = ?, status_since = ?, dropped_on = ?, checked_at = ? WHERE id = ?');
$stats = ['available' => 0, 'registered' => 0, 'pending' => 0, 'released' => 0, 'error' => 0];
$errorsInRow = 0;

foreach ($picks as $r) {
    $res = ae_whois($r['domain']);
    if ($res['state'] === 'error') {
        $stats['error']++;
        if (++$errorsInRow >= 5) { logmsg('aeDA WHOIS not answering (' . $res['raw'] . '), stopping this run'); break; }
        usleep($delay * 2);
        continue;
    }
    $errorsInRow = 0;
    $state = $res['state'];
    $since = $state === $r['status'] ? ($r['status_since'] ?: $today) : $today;
    $dropped = $r['dropped_on'];
    if ($state === 'available' && in_array($r['status'], ['registered', 'pending'], true)) {
        $dropped = $today;            // it was registered before: just released
        $stats['released']++;
    } elseif ($state !== 'available') {
        $dropped = null;              // re-registered
    }
    $upd->execute([$state, $res['raw'], $since, $dropped, $now, $r['id']]);
    $stats[$state]++;
    usleep($delay);
}

if (array_sum($stats) - $stats['error'] > 0) clear_cache();
db()->prepare('INSERT INTO import_log (source, rows_count, ran_at) VALUES (?,?,?)')->execute(['ae_whois', count($picks), $now]);
logmsg(sprintf('Checked %d .ae names: %d registered, %d pending delete, %d available (%d newly released), %d errors',
    count($picks), $stats['registered'], $stats['pending'], $stats['available'], $stats['released'], $stats['error']));
