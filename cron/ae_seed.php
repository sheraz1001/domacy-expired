<?php
// Grows the .ae watch list. .ae has no public zone file, so names are collected from:
//  1. SSL certificate logs (crt.sh): .ae sub-zones daily + keyword searches that grow over time
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

// --- 2. SSL certificate logs (crt.sh) ---
// crt.sh can't list all of .ae at once, so we search it in two ways:
//  a) once a day, every public sub-zone ("%.co.ae", "%.net.ae" ...)
//  b) every run, a few keywords ("dubai", "perfume" ...); any word found inside a new
//     .ae name is added to the keyword list, so coverage keeps growing.
$state = data_dir() . '/ae_crt_state.json';
$st = is_file($state) ? (json_decode(file_get_contents($state), true) ?: []) : [];
$st += ['queue' => [], 'done' => [], 'zones_date' => ''];

$queries = [];
if ($st['zones_date'] !== gmdate('Y-m-d')) {
    foreach (AE_SECOND_LEVEL as $z) $queries[] = "%.$z.ae";
    $st['zones_date'] = gmdate('Y-m-d');
}
if (!$st['queue']) {
    $st['queue'] = array_values(array_diff(ae_seed_keywords(), $st['done']));
    if (!$st['queue']) { $st['done'] = []; $st['queue'] = ae_seed_keywords(); logmsg('crt.sh: keyword list finished, starting again'); }
}
$perRun = max(1, (int)config('ae_crt_queries_per_run'));
for ($i = 0; $i < $perRun && $st['queue']; $i++) $queries[] = array_shift($st['queue']);

foreach ($queries as $q) {
    $r = http_get('https://crt.sh/?q=' . rawurlencode($q) . '&output=json', ['Accept: application/json'], null, 180);
    $json = $r['code'] === 200 ? json_decode((string)$r['body'], true) : null;
    if (!str_starts_with($q, '%')) $st['done'][] = $q;
    if (!is_array($json)) { logmsg("crt.sh '$q': no data (HTTP {$r['code']})"); sleep(5); continue; }
    $names = [];
    foreach ($json as $row) {
        foreach (preg_split('/\s+/', ($row['common_name'] ?? '') . ' ' . ($row['name_value'] ?? '')) as $host) {
            if ($host !== '' && ($d = ae_registrable($host))) $names[$d] = true;
        }
    }
    $names = array_keys($names);
    $n = ae_add($names, 'ssl');
    $total += $n;
    // Learn new keywords from the names we found
    $known = array_flip(array_merge($st['done'], $st['queue']));
    foreach ($names as $d) {
        foreach (preg_split('/[-0-9]+/', substr($d, 0, strpos($d, '.'))) as $word) {
            if (strlen($word) >= 4 && strlen($word) <= 20 && !isset($known[$word])) { $st['queue'][] = $word; $known[$word] = true; }
        }
    }
    logmsg("crt.sh '$q': " . count($json) . ' certs, ' . count($names) . " .ae names, $n new");
    sleep(5); // be polite to crt.sh
}
$st['done'] = array_values(array_unique($st['done']));
file_put_contents($state, json_encode($st));
@unlink(data_dir() . '/ae_crt_queue.json');

$count = (int)db()->query('SELECT COUNT(*) FROM ae_domains')->fetchColumn();
db()->prepare('INSERT INTO import_log (source, rows_count, ran_at) VALUES (?,?,?)')->execute(['ae_seed', $total, gmdate('Y-m-d H:i:s')]);
logmsg("Watch list: $count .ae names ($total new this run, " . count($st['queue']) . ' keywords queued)');
