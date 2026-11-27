<?php
// Shared query builder for the listing page and CSV export.

const TABS = [
    'deleted'  => ['label' => 'Deleted domains', 'table' => 'deleted_domains', 'hint' => 'Names removed from the registry in the last 30 days. "Available" means you can register it now.'],
    'expiring' => ['label' => 'Expiring auctions', 'table' => 'auctions', 'hint' => 'Expired names in auction, with authority, age and search data.'],
    'closeout' => ['label' => 'Closeouts', 'table' => 'auctions', 'hint' => 'Unsold expired names you can buy instantly, from $5.'],
    'ae'       => ['label' => '.ae domains', 'table' => 'ae_domains', 'hint' => 'UAE domains we track at the .ae registry: names about to be deleted, and names that have just become free.'],
];

const SORTS = [
    'ae'       => ['domain' => 'domain', 'len' => 'sld_len', 'tld' => 'tld', 'status' => 'status', 'since' => 'status_since', 'released' => 'dropped_on', 'checked' => 'checked_at'],
    'deleted'  => ['domain' => 'domain', 'len' => 'sld_len', 'tld' => 'tld', 'dropped' => 'dropped_on', 'status' => 'status'],
    'expiring' => ['domain' => 'domain', 'len' => 'sld_len', 'price' => 'price', 'bids' => 'bids', 'age' => 'age', 'tf' => 'tf', 'cf' => 'cf',
                   'bl' => 'backlinks', 'rd' => 'semrush_rd', 'as' => 'semrush_as', 'sv' => 'search_volume', 'val' => 'valuation', 'end' => 'end_time'],
];

function current_tab(): string
{
    $t = $_GET['tab'] ?? 'deleted';
    return isset(TABS[$t]) ? $t : 'deleted';
}

function f(string $k, $default = '')
{
    $v = $_GET[$k] ?? $default;
    return is_string($v) ? trim($v) : $default;
}

function like_escape(string $s): string
{
    return str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $s);
}

/** Returns [whereSql, params, orderSql] */
function build_query(string $tab): array
{
    $w = [];
    $p = [];
    $q = strtolower(preg_replace('/[^a-z0-9\-]/i', '', f('q')));
    if ($tab !== 'deleted' && $tab !== 'ae') {
        // list_type splits the table roughly in half, so walking its index means millions of random reads.
        // A plain table scan is ~10x faster, so stop the DB from using that index here.
        $lt = is_sqlite() ? '+list_type' : "CONCAT(list_type,'')";
        $w[] = "$lt = ?"; $p[] = $tab;
    }
    if ($q !== '') {
        $match = f('match', 'contains');
        if ($match === 'exact') { $w[] = 'sld = ?'; $p[] = $q; }
        else {
            $pat = like_escape($q);
            $pat = match ($match) { 'starts' => "$pat%", 'ends' => "%$pat", default => "%$pat%" };
            $w[] = "sld LIKE ? ESCAPE '!'"; $p[] = $pat;
        }
    }
    $tlds = array_filter(array_map(fn($t) => strtolower(preg_replace('/[^a-z0-9\-.]/i', '', $t)), explode(',', f('tld'))));
    if ($tlds) { $w[] = 'tld IN (' . implode(',', array_fill(0, count($tlds), '?')) . ')'; array_push($p, ...$tlds); }

    foreach (['minlen' => ['sld_len', '>='], 'maxlen' => ['sld_len', '<=']] as $k => [$col, $op]) {
        if (f($k) !== '' && ctype_digit(f($k))) { $w[] = "$col $op ?"; $p[] = (int)f($k); }
    }
    if (f('nodigits')) $w[] = 'has_digits = 0';
    if (f('nohyphen')) $w[] = 'has_hyphen = 0';

    if ($tab === 'ae') {
        $view = f('view', 'watch');
        if ($view === 'watch') $w[] = "(status = 'pending' OR (status = 'available' AND checked_at IS NOT NULL))";
        elseif ($view === 'pending') $w[] = "status = 'pending'";
        elseif ($view === 'released') $w[] = "status = 'available' AND dropped_on IS NOT NULL";
        elseif ($view === 'available') $w[] = "status = 'available'";
    } elseif ($tab === 'deleted') {
        if (f('status') === 'available') $w[] = "status = 'available'";
        if (f('dropped') !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', f('dropped'))) { $w[] = 'dropped_on = ?'; $p[] = f('dropped'); }
    } else {
        $mins = ['min_tf' => 'tf', 'min_as' => 'semrush_as', 'min_bl' => 'backlinks', 'min_rd' => 'semrush_rd', 'min_age' => 'age', 'min_sv' => 'search_volume'];
        foreach ($mins as $k => $col) {
            if (f($k) !== '' && ctype_digit(f($k))) { $w[] = "$col >= ?"; $p[] = (int)f($k); }
        }
        if (f('max_price') !== '' && ctype_digit(f('max_price'))) { $w[] = 'price <= ?'; $p[] = (int)f('max_price'); }
        if (f('ending') === 'today') { $w[] = 'end_time < ?'; $p[] = gmdate('Y-m-d 23:59:59'); }
        if (f('ending') === '24h') { $w[] = 'end_time < ?'; $p[] = gmdate('Y-m-d H:i:s', time() + 86400); }
    }

    $sorts = SORTS[in_array($tab, ['deleted', 'ae'], true) ? $tab : 'expiring'];
    $sort = f('sort', default_sort($tab));
    $col = $sorts[$sort] ?? reset($sorts);
    $dir = f('dir', in_array($sort, ['domain', 'len', 'price', 'end', 'tld']) ? 'asc' : 'desc') === 'asc' ? 'ASC' : 'DESC';
    // With a keyword filter, a full scan + sort is far faster than walking the sort index
    // (MySQL otherwise reads the whole table in index order). "+0" stops index use for ORDER BY.
    $sortExpr = (!in_array($col, ['domain', 'tld', 'status', 'dropped_on', 'end_time', 'status_since', 'checked_at'], true)) ? "($col+0)" : $col;
    $order = "$sortExpr $dir" . ($col === 'domain' ? '' : ', sld_len ASC, domain ASC');

    return [$w ? 'WHERE ' . implode(' AND ', $w) : '', $p, $order];
}

function default_sort(string $tab): string
{
    return match ($tab) { 'deleted' => 'dropped', 'ae' => 'since', default => 'as' };
}

function top_tlds(string $tab): array
{
    $cache = data_dir('cache') . "/tlds_$tab.json";
    if (is_file($cache) && filemtime($cache) > time() - 3600) return json_decode(file_get_contents($cache), true);
    $sql = in_array($tab, ['deleted', 'ae'], true)
        ? 'SELECT tld, COUNT(*) c FROM ' . TABS[$tab]['table'] . ' GROUP BY tld ORDER BY c DESC LIMIT 40'
        : 'SELECT tld, COUNT(*) c FROM auctions WHERE list_type = ' . db()->quote($tab) . ' GROUP BY tld ORDER BY c DESC LIMIT 40';
    $rows = db()->query($sql)->fetchAll(PDO::FETCH_KEY_PAIR);
    file_put_contents($cache, json_encode($rows));
    return $rows;
}

function h($s): string
{
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

function url_with(array $changes): string
{
    $q = array_merge($_GET, $changes);
    foreach ($q as $k => $v) if ($v === null || $v === '') unset($q[$k]);
    return '?' . http_build_query($q);
}

/** Counts and last-update times for the header badge and tabs (cached 10 min). */
function site_stats(): array
{
    $cache = data_dir('cache') . '/stats.json';
    if (is_file($cache) && filemtime($cache) > time() - 600) return json_decode(file_get_contents($cache), true);
    $s = ['deleted' => 0, 'expiring' => 0, 'closeout' => 0];
    $s['deleted'] = (int)db()->query('SELECT COUNT(*) FROM deleted_domains')->fetchColumn();
    $s['ae'] = (int)db()->query("SELECT COUNT(*) FROM ae_domains WHERE status = 'pending' OR (status = 'available' AND checked_at IS NOT NULL)")->fetchColumn();
    $s['ae_tracked'] = (int)db()->query('SELECT COUNT(*) FROM ae_domains')->fetchColumn();
    foreach (db()->query('SELECT list_type, COUNT(*) c FROM auctions GROUP BY list_type') as $r) $s[$r['list_type']] = (int)$r['c'];
    foreach (db()->query('SELECT source, MAX(ran_at) t FROM import_log GROUP BY source') as $r) {
        if (str_starts_with($r['source'], 'czds_')) $s['upd_deleted'] = max($s['upd_deleted'] ?? '', $r['t']);
        elseif (str_starts_with($r['source'], 'ae_')) $s['upd_ae'] = max($s['upd_ae'] ?? '', $r['t']);
        else $s['upd_' . substr($r['source'], 8)] = $r['t'];
    }
    @file_put_contents($cache, json_encode($s));
    return $s;
}

