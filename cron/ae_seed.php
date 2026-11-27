<?php
// Grows the .ae watch list. .ae has no public zone file, so names are collected from:
//  1. SSL certificate logs (crt.sh), swept in small prefix pieces ("a%.ae", "ab%.ae" ...)
//  2. The Tranco top-1M sites list (once a day)
// Runs hourly:  php cron/ae_seed.php
require __DIR__ . '/../lib/bootstrap.php';
require __DIR__ . '/../lib/ae.php';
cli_only();
@set_time_limit(0);
ini_set('memory_limit', '1024M');

$total = 0;

// --- 1. Tranco top sites (daily) ---
$stamp = data_dir() . '/ae_tranco.date';
if (@file_get_contents($stamp) !== gmdate('Y-m-d')) {
    $zip = data_dir() . '/tranco.zip';
    $r = http_get('https://tranco-list.eu/top-1m.csv.zip', [], $zip, 300);
    if ($r['code'] === 200) {
        $z = new ZipArchive();
        if ($z->open($zip) === true) {
            $names = [];
            $fh = $z->getStream($z->getNameIndex(0));
            while (($line = fgets($fh)) !== false) {
                $host = trim(substr($line, strpos($line, ',') + 1));
                if (str_ends_with($host, '.ae') && ($d = ae_registrable($host))) $names[] = $d;
            }
            $z->close();
            $n = ae_add($names, 'toplist');
            $total += $n;
            logmsg('Tranco: ' . count(array_unique($names)) . " .ae names, $n new");
            file_put_contents($stamp, gmdate('Y-m-d'));
        }
        @unlink($zip);
    } else {
        logmsg("Tranco download failed (HTTP {$r['code']})");
    }
}

// --- 2. crt.sh prefix sweep ---
// Queue of prefixes to query. A query that is too big (crt.sh 502/timeout) is split into longer prefixes.
$qfile = data_dir() . '/ae_crt_queue.json';
$queue = is_file($qfile) ? (json_decode(file_get_contents($qfile), true) ?: []) : [];
$chars = array_merge(range('a', 'z'), range('0', '9'));
if (!$queue) {
    $queue = $chars; // start a new full sweep
    logmsg('crt.sh: starting a new sweep');
}

$perRun = max(1, (int)config('ae_crt_queries_per_run'));
for ($i = 0; $i < $perRun && $queue; $i++) {
    $prefix = array_shift($queue);
    $url = 'https://crt.sh/?q=' . rawurlencode($prefix . '%.ae') . '&output=json';
    $r = http_get($url, ['Accept: application/json'], null, 150);
    $json = $r['code'] === 200 ? json_decode((string)$r['body'], true) : null;

    if (!is_array($json)) {
        if (strlen($prefix) < 4) {
            // Too big: split into longer prefixes and try those later
            foreach ($chars as $c) $queue[] = $prefix . $c;
            foreach (['-', '.'] as $c) if (strlen($prefix) >= 1) $queue[] = $prefix . $c;
            logmsg("crt.sh: '$prefix' too big (HTTP {$r['code']}), split into " . (count($chars) + 2) . ' pieces');
        } else {
            logmsg("crt.sh: '$prefix' failed (HTTP {$r['code']}), skipped");
        }
        sleep(10);
        continue;
    }
    $names = [];
    foreach ($json as $row) {
        foreach ([$row['common_name'] ?? '', $row['name_value'] ?? ''] as $field) {
            foreach (preg_split('/\s+/', (string)$field) as $host) {
                if ($host !== '' && ($d = ae_registrable($host))) $names[] = $d;
            }
        }
    }
    $n = ae_add($names, 'ssl');
    $total += $n;
    logmsg("crt.sh '$prefix': " . count($json) . ' certs, ' . count(array_unique($names)) . " .ae names, $n new");
    sleep(10); // be polite to crt.sh
}
file_put_contents($qfile, json_encode(array_values($queue)));

$count = (int)db()->query('SELECT COUNT(*) FROM ae_domains')->fetchColumn();
db()->prepare('INSERT INTO import_log (source, rows_count, ran_at) VALUES (?,?,?)')->execute(['ae_seed', $total, gmdate('Y-m-d H:i:s')]);
logmsg("Watch list: $count .ae names ($total new this run, " . count($queue) . ' prefixes left in sweep)');
