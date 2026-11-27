<?php
// Downloads GoDaddy Auctions free inventory files and imports them.
// Run daily after 16:00 UTC (files refresh ~14:00-15:00 UTC):  php cron/import_godaddy.php
// Options: --local=/path/file.json.zip  (skip download)   --type=expiring|closeout
require __DIR__ . '/../lib/bootstrap.php';
cli_only();
@set_time_limit(0);
ini_set('memory_limit', '512M');

$args = getopt('', ['local:', 'type:']);
$files = config('godaddy_files');
if (!empty($args['type'])) $files = array_intersect_key($files, [$args['type'] => 1]);
$skipAdult = (bool)config('godaddy_skip_adult');

$cols = ['domain','sld','tld','sld_len','has_digits','has_hyphen','list_type','auction_type','end_time','price','bids','age',
    'valuation','pageviews','parking_rev','tf','cf','backlinks','ref_domains','semrush_as','semrush_rd','semrush_bl',
    'search_volume','cpc','exact_tlds','keyword_regs','is_adult','link','batch'];

$failed = 0;
foreach ($files as $type => $file) {
    $zipPath = $args['local'] ?? (data_dir('godaddy') . '/' . $file);
    if (empty($args['local'])) {
        logmsg("Downloading $file ...");
        $r = http_get('https://inventory.auctions.godaddy.com/' . rawurlencode($file), [], $zipPath . '.part', 1800);
        if ($r['code'] !== 200) { logmsg("Download failed ({$r['code']} {$r['error']})"); @unlink($zipPath . '.part'); $failed++; continue; }
        rename($zipPath . '.part', $zipPath);
    }

    $zip = new ZipArchive();
    if ($zip->open($zipPath) !== true) { logmsg("Cannot open $zipPath"); $failed++; continue; }
    $stream = $zip->getStream($zip->getNameIndex(0));
    $batch = date('YmdHis');
    $buf = '';
    $inData = false;
    $rows = [];
    $total = 0;
    db()->beginTransaction();

    while (!feof($stream)) {
        $buf .= fread($stream, 1 << 20);
        if (!$inData) {
            $p = strpos($buf, '"data"');
            if ($p === false) { $buf = substr($buf, -10); continue; }
            $buf = substr($buf, $p + 6);
            $inData = true;
        }
        // Objects are flat and pretty-printed: each ends with a newline followed by "}".
        $offset = 0;
        while (true) {
            $start = strpos($buf, '{', $offset);
            if ($start === false) break;
            $end = strpos($buf, "\n}", $start);
            if ($end === false) break;
            $obj = json_decode(substr($buf, $start, $end - $start + 2), true);
            $offset = $end + 2;
            if (!is_array($obj) || empty($obj['domainName'])) continue;
            if ($skipAdult && !empty($obj['isAdult'])) continue;
            $row = map_row($obj, $type, $batch);
            if ($row) { $rows[] = $row; $total++; }
            if (count($rows) >= 500) {
                bulk_upsert('auctions', $cols, $rows, ['domain', 'list_type']);
                $rows = [];
                if ($total % 50000 === 0) {
                    db()->commit(); db()->beginTransaction();
                    logmsg("  $type: $total rows");
                }
            }
        }
        $buf = substr($buf, $offset);
    }
    if ($rows) bulk_upsert('auctions', $cols, $rows, ['domain', 'list_type']);
    db()->commit();
    $zip->close();

    // Remember when each listing first appeared (for "new since yesterday" alerts)
    db()->prepare('UPDATE auctions SET first_seen = ? WHERE list_type = ? AND first_seen IS NULL')->execute([gmdate('Y-m-d'), $type]);

    // Remove listings that are no longer in today's file
    $del = db()->prepare('DELETE FROM auctions WHERE list_type = ? AND batch <> ?');
    $del->execute([$type, $batch]);
    db()->prepare('INSERT INTO import_log (source, rows_count, ran_at) VALUES (?,?,?)')
        ->execute(['godaddy_' . $type, $total, gmdate('Y-m-d H:i:s')]);
    logmsg("Imported $total $type listings, removed {$del->rowCount()} old ones.");
    clear_cache();
}

exit($failed ? 1 : 0);

function map_row(array $o, string $type, string $batch): ?array
{
    $domain = strtolower(trim($o['domainName']));
    [$sld, $tld] = split_domain($domain);
    if ($sld === '' || $tld === '') return null;
    $end = !empty($o['auctionEndTime']) ? gmdate('Y-m-d H:i:s', strtotime($o['auctionEndTime'])) : null;
    return [
        $domain, $sld, $tld, strlen($sld),
        preg_match('/\d/', $sld) ? 1 : 0, str_contains($sld, '-') ? 1 : 0,
        $type, $o['auctionType'] ?? null, $end,
        money_to_int($o['price'] ?? 0), (int)($o['numberOfBids'] ?? 0), (int)($o['domainAge'] ?? 0),
        money_to_int($o['valuation'] ?? 0), (int)($o['pageviews'] ?? 0), money_to_int($o['monthlyParkingRevenue'] ?? 0),
        (int)($o['majesticTf'] ?? 0), (int)($o['majesticCf'] ?? 0),
        (int)($o['majesticBacklinks'] ?? 0), (int)($o['majesticReferringDomains'] ?? 0),
        (int)($o['semrushAs'] ?? 0), (int)($o['semrushReferringDomains'] ?? 0), (int)($o['semrushBacklinks'] ?? 0),
        (int)($o['semrushSearchVolume'] ?? 0), (float)($o['semrushCpc'] ?? 0),
        (int)($o['exactMatchTlds'] ?? 0), (int)($o['keywordRegistrations'] ?? 0),
        !empty($o['isAdult']) ? 1 : 0, substr((string)($o['link'] ?? ''), 0, 512), $batch,
    ];
}
