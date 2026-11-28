<?php
// Daily "Deleted domains" job using ICANN CZDS zone files.
// Downloads today's zone for each configured TLD, compares with yesterday's,
// and stores names that disappeared. Run daily after 07:00 UTC:
//   php cron/czds_deleted.php            (all configured zones)
//   php cron/czds_deleted.php --zone=xyz (one zone)
// Needs: a VPS (shell access, gzip/awk/sort/comm) and ~3x the unzipped zone size in free disk.
require __DIR__ . '/../lib/bootstrap.php';
cli_only();
@set_time_limit(0);

$args = getopt('', ['zone:', 'local:', 'part:', 'of:']);
$zones = !empty($args['zone']) ? [strtolower($args['zone'])] : config('czds_zones');
$dir = data_dir('zones');
$today = gmdate('Y-m-d');
$workers = max(1, (int)config('czds_workers'));
$isChild = isset($args['part']);

// Parent: split the work over several processes so zone files download in parallel
// (ICANN serves each download slowly, so 4 at once is ~4x faster overall).
if (!$isChild && $workers > 1 && empty($args['zone']) && empty($args['local'])) {
    $procs = [];
    for ($i = 0; $i < $workers; $i++) {
        $cmd = [PHP_BINARY, __FILE__, "--part=$i", "--of=$workers"];
        $procs[] = proc_open($cmd, [1 => STDOUT, 2 => STDERR], $pipes);
        sleep(2);
    }
    foreach ($procs as $p) if (is_resource($p)) proc_close($p);
    housekeeping();
    exit(0);
}

$links = [];
if (empty($args['local'])) {
    $token = czds_login();
    $r = http_get('https://czds-api.icann.org/czds/downloads/links', ["Authorization: Bearer $token", 'Accept: application/json']);
    if ($r['code'] !== 200) exit(logmsg("Could not list zone links (HTTP {$r['code']})"));
    foreach (json_decode($r['body'], true) ?: [] as $url) {
        $links[strtolower(basename($url, '.zone'))] = $url;
    }
    if (!$isChild || (int)$args['part'] === 0) logmsg('Approved zones available: ' . count($links));
}

if ($zones === ['all']) {
    // Every approved zone; biggest/most popular first so they are ready earliest
    $first = ['com', 'net', 'org', 'info', 'xyz', 'online', 'shop', 'store', 'site', 'top', 'biz', 'app', 'dev', 'pro', 'club', 'live', 'tech'];
    $all = array_keys($links);
    sort($all);
    $zones = array_values(array_unique(array_merge(array_intersect($first, $all), $all)));
}
$pending = array_values(array_filter($zones, fn($t) => !is_file("$dir/$t.$today.txt") && (empty($links) || isset($links[$t]))));
// Zones that already have an earlier snapshot go first: they produce deleted names right away
$hasPrev = fn($t) => (bool)array_filter(glob("$dir/$t.*.txt") ?: [], fn($f) => !str_ends_with($f, ".$today.txt"));
usort($pending, fn($a, $b) => $hasPrev($b) <=> $hasPrev($a)); // stable sort keeps popularity order
if ($isChild) {
    $part = (int)$args['part']; $of = max(1, (int)$args['of']);
    $pending = array_values(array_filter($pending, fn($t, $i) => $i % $of === $part, ARRAY_FILTER_USE_BOTH));
}
if (!$pending) { if (!$isChild) logmsg('All approved zones already done today.'); exit(0); }
foreach ($pending as $tld) {
    if (gmdate('Y-m-d') !== $today) { logmsg('New UTC day started - stopping so the new day\'s run can begin.'); break; }
    try {
        process_zone($tld, $links[$tld] ?? null, $token ?? '', $dir, $today, $args['local'] ?? null);
    } catch (Throwable $e) {
        logmsg("[$tld] ERROR: " . $e->getMessage());
    }
}
if (!$isChild) housekeeping();
exit(0);

function czds_login(): string
{
    $ch = curl_init('https://account-api.icann.org/api/authenticate');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json'],
        CURLOPT_POSTFIELDS => json_encode(['username' => config('czds_username'), 'password' => config('czds_password')]),
        CURLOPT_TIMEOUT => 60,
        CURLOPT_USERAGENT => 'DomacyExpired/1.0 (+https://expired.domacy.com)', // required by ICANN, else redirected to maintenance
    ]);
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    $tok = json_decode((string)$body, true)['accessToken'] ?? null;
    if ($code === 301 || $code === 302 || $code === 503) {
        logmsg("ICANN API is in maintenance (HTTP $code) - will retry next hour.");
        exit(0);
    }
    if ($code !== 200 || !$tok) {
        logmsg("CZDS login failed (HTTP $code). Check CZDS_USERNAME / CZDS_PASSWORD in Coolify.");
        exit(1);
    }
    return $tok;
}

function process_zone(string $tld, ?string $url, string $token, string $dir, string $today, ?string $local): void
{
    $gz = "$dir/$tld.$today.zone.gz";
    $list = "$dir/$tld.$today.txt";

    if (!is_file($list)) {
        if ($local) {
            $gz = $local;
        } else {
            if (!$url) { logmsg("[$tld] not approved yet - skipping"); return; }
            // Download with integrity check: a cut-off transfer gives a truncated .gz,
            // which would silently produce a tiny name list. Retry up to 3 times.
            // ICANN often drops the connection mid-file, so resume from where it stopped (up to 10 times).
            @unlink("$gz.part");
            for ($try = 1; ; $try++) {
                clearstatcache();
                $have = is_file("$gz.part") ? filesize("$gz.part") : 0;
                logmsg("[$tld] downloading zone" . ($have ? ' (resuming at ' . round($have / 1048576, 1) . ' MB)' : '') . ' ...');
                $r = http_get($url, ["Authorization: Bearer $token"], "$gz.part", 6 * 3600, $have > 0);
                if ($have > 0 && $r['code'] === 200) {
                    // Server ignored the resume request and sent the whole file again: start clean
                    @unlink("$gz.part");
                    $r = http_get($url, ["Authorization: Bearer $token"], "$gz.part", 6 * 3600);
                }
                $complete = in_array($r['code'], [200, 206], true) && $r['error'] === '';
                if ($complete && gzip_ok("$gz.part")) break;
                if ($complete) @unlink("$gz.part"); // finished but corrupt: start over
                if ($try >= 10) { @unlink("$gz.part"); throw new RuntimeException("download failed after 10 tries (HTTP {$r['code']} {$r['error']})"); }
                sleep(15);
            }
            rename("$gz.part", $gz);
        }
        logmsg("[$tld] extracting second-level names ...");
        // Keep only "<label>.<tld>." owner names, lowercase, unique, sorted (C locale for comm).
        $dots = substr_count($tld, '.') + 2;
        $cmd = sprintf(
            "gzip -dc %s | awk -v n=%d '{o=tolower($1); if (gsub(/\\./,\".\",o)==n && o ~ /\\.%s\\.$/) print substr(o,1,length(o)-1)}' | LC_ALL=C sort -u -S 10%% -T %s > %s",
            escapeshellarg($gz), $dots, preg_quote($tld, '/'), escapeshellarg($dir), escapeshellarg("$list.part")
        );
        exec('bash -o pipefail -c ' . escapeshellarg($cmd), $out, $rc);
        if ($rc !== 0) throw new RuntimeException("extract failed (rc=$rc)");
        rename("$list.part", $list);
        if (!$local) @unlink($gz);
    }

    $count = line_count($list);
    logmsg("[$tld] $count names today");

    // Find the most recent earlier snapshot
    $prev = null;
    foreach (glob("$dir/$tld.*.txt") as $f) {
        if ($f !== $list && (!$prev || $f > $prev)) $prev = $f;
    }
    if (!$prev) { logmsg("[$tld] first snapshot saved; deleted list starts tomorrow."); return; }

    $prevCount = line_count($prev);
    if ($prevCount > 0 && $count < $prevCount * 0.97) {
        throw new RuntimeException("today's zone is >3% smaller than the previous one ($count vs $prevCount) - possible truncated download, skipping diff");
    }

    $dropped = "$dir/$tld.$today.dropped";
    exec(sprintf('LC_ALL=C comm -23 %s %s > %s', escapeshellarg($prev), escapeshellarg($list), escapeshellarg($dropped)), $o, $rc);
    if ($rc !== 0) throw new RuntimeException('comm failed');

    $cols = ['domain', 'sld', 'tld', 'sld_len', 'has_digits', 'has_hyphen', 'dropped_on', 'status', 'checked_at'];
    $rows = [];
    $n = 0;
    db()->beginTransaction();
    $fh = fopen($dropped, 'r');
    while (($line = fgets($fh)) !== false) {
        $domain = trim($line);
        if ($domain === '') continue;
        [$sld, $t] = split_domain($domain);
        $rows[] = [$domain, $sld, $t, strlen($sld), preg_match('/\d/', $sld) ? 1 : 0, str_contains($sld, '-') ? 1 : 0, $today, 'unknown', null];
        $n++;
        if (count($rows) >= 500) { bulk_upsert('deleted_domains', $cols, $rows, ['domain']); $rows = []; }
    }
    fclose($fh);
    if ($rows) bulk_upsert('deleted_domains', $cols, $rows, ['domain']);
    db()->commit();
    db()->prepare('INSERT INTO import_log (source, rows_count, ran_at) VALUES (?,?,?)')
        ->execute(["czds_$tld", $n, gmdate('Y-m-d H:i:s')]);
    logmsg("[$tld] $n names dropped since " . basename($prev));

    // Keep only today's snapshot
    @unlink($prev);
    @unlink($dropped);
    foreach (glob("$dir/$tld.*.txt") as $f) if ($f !== $list) @unlink($f);
}

function gzip_ok(string $f): bool
{
    exec('gzip -t ' . escapeshellarg($f) . ' 2>/dev/null', $o, $rc);
    return $rc === 0;
}

function line_count(string $f): int
{
    return (int)trim((string)shell_exec('wc -l < ' . escapeshellarg($f)));
}

function housekeeping(): void
{
    $keep = (int)config('deleted_keep_days');
    $n = db()->prepare('DELETE FROM deleted_domains WHERE dropped_on < ?');
    $n->execute([gmdate('Y-m-d', strtotime("-$keep days"))]);
    logmsg("Removed {$n->rowCount()} deleted-domain rows older than $keep days.");
    clear_cache();
}
