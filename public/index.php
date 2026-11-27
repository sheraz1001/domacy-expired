<?php
require __DIR__ . '/../lib/bootstrap.php';
require __DIR__ . '/../lib/search.php';
require __DIR__ . '/../lib/seo.php';
require __DIR__ . '/../lib/auth.php';

$tab = current_tab();
$table = TABS[$tab]['table'];
[$where, $params, $order] = build_query($tab);
$perPage = in_array((int)f('per', '100'), [50, 100, 200], true) ? (int)f('per', '100') : 100;
$page = max(1, min(500, (int)f('page', '1')));
$offset = ($page - 1) * $perPage;
$main = config('main_site');

// CSV export (max 10,000 rows)
if (f('export') === 'csv') {
    $stmt = db()->prepare("SELECT * FROM $table $where ORDER BY $order LIMIT 10000");
    $stmt->execute($params);
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="domacy-' . $tab . '-domains-' . date('Y-m-d') . '.csv"');
    header('X-Robots-Tag: noindex');
    $out = fopen('php://output', 'w');
    $first = true;
    while ($r = $stmt->fetch()) {
        unset($r['id'], $r['batch'], $r['sld'], $r['list_type'], $r['is_adult']);
        if ($first) { fputcsv($out, array_keys($r)); $first = false; }
        fputcsv($out, $r);
    }
    exit;
}

$cnt = db()->prepare("SELECT COUNT(*) FROM $table $where");
$cnt->execute($params);
$total = (int)$cnt->fetchColumn();
$stmt = db()->prepare("SELECT * FROM $table $where ORDER BY $order LIMIT $perPage OFFSET $offset");
$stmt->execute($params);
$rows = $stmt->fetchAll();

// Signed-in visitor: which of the shown domains are on their watchlist
$user = current_user();
$watched = [];
if ($user && $rows) {
    $doms = array_column($rows, 'domain');
    $wq = db()->prepare('SELECT domain FROM watchlist WHERE user_id = ? AND domain IN (' . implode(',', array_fill(0, count($doms), '?')) . ')');
    $wq->execute(array_merge([(int)$user['id']], $doms));
    $watched = array_flip($wq->fetchAll(PDO::FETCH_COLUMN));
}
$csrf = $user ? csrf_token() : '';
if ($user) header('Cache-Control: private, no-store');
$flash = $user ? flash() : null;
$here = '/' . ltrim((string)($_SERVER['REQUEST_URI'] ?? '/'), '/');
$pages = max(1, min(500, (int)ceil($total / $perPage)));
$tlds = top_tlds($tab);
$isDel = $tab === 'deleted';
$isAe = $tab === 'ae';
$stats = site_stats();
$dropDates = $isDel ? db()->query('SELECT DISTINCT dropped_on FROM deleted_domains ORDER BY dropped_on DESC LIMIT 14')->fetchAll(PDO::FETCH_COLUMN) : [];

$lastUpd = $isDel ? ($stats['upd_deleted'] ?? null) : ($stats['upd_' . $tab] ?? null);
$aeView = f('view', 'watch');
$filtersOpen = (bool)array_filter(array_intersect_key($_GET, array_flip(['minlen','maxlen','nodigits','nohyphen','status','dropped','view','min_as','min_tf','min_bl','min_rd','min_age','max_price','ending','min_sv'])));

function th(string $key, string $label, string $title = ''): string
{
    $cur = f('sort', default_sort(current_tab()));
    $dir = f('dir');
    $next = ($cur === $key && $dir !== 'asc') ? 'asc' : 'desc';
    if ($cur !== $key && in_array($key, ['domain', 'len', 'price', 'end', 'tld'])) $next = 'asc';
    $arrow = $cur === $key ? ($dir === 'asc' ? ' ↑' : ' ↓') : '';
    return '<th><a class="' . ($cur === $key ? 'cur' : '') . '" href="' . h(url_with(['sort' => $key, 'dir' => $next, 'page' => null])) . '" title="' . h($title) . '">' . h($label) . $arrow . '</a></th>';
}
function star_btn(string $domain, bool $on, bool $loggedIn, string $csrf, string $here): string
{
    if (!$loggedIn) {
        return '<a class="star" href="login.php?return=' . h(rawurlencode($here)) . '" title="Sign in to watch this domain" rel="nofollow">☆</a>';
    }
    return '<form method="post" action="action.php" class="star-form"><input type="hidden" name="csrf" value="' . h($csrf) . '">'
        . '<input type="hidden" name="do" value="' . ($on ? 'unwatch' : 'watch') . '"><input type="hidden" name="domain" value="' . h($domain) . '">'
        . '<input type="hidden" name="back" value="' . h($here) . '">'
        . '<button type="submit" class="star' . ($on ? ' on' : '') . '" title="' . ($on ? 'On your watchlist' : 'Add to watchlist') . '">' . ($on ? '★' : '☆') . '</button></form>';
}
function num($n): string { return $n === null ? '–' : number_format((float)$n); }
function ago(?string $t): string
{
    if (!$t) return 'not yet';
    $s = time() - strtotime($t . ' UTC');
    if ($s < 3600) return max(1, intdiv($s, 60)) . ' min ago';
    if ($s < 86400) return intdiv($s, 3600) . ' h ago';
    return intdiv($s, 86400) . ' days ago';
}
$check = '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"/></svg>';
$seoTld = seo_tld();
$title = seo_title($tab, $seoTld);
$indexable = seo_indexable();
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= h($title) ?> | Domacy</title>
<meta name="description" content="<?= h(seo_description($tab, $seoTld)) ?>">
<?php if (!config('allow_indexing')): ?><meta name="robots" content="noindex, nofollow"><?php elseif (!$indexable): ?><meta name="robots" content="noindex, follow"><?php else: ?><meta name="robots" content="index, follow, max-snippet:-1, max-image-preview:large"><?php endif; ?>
<link rel="canonical" href="<?= h(seo_url($tab, $seoTld)) ?>">
<meta property="og:type" content="website">
<meta property="og:site_name" content="Domacy Expired Domains">
<meta property="og:title" content="<?= h($title) ?>">
<meta property="og:description" content="<?= h(seo_description($tab, $seoTld)) ?>">
<meta property="og:url" content="<?= h(seo_url($tab, $seoTld)) ?>">
<meta name="twitter:card" content="summary">
<?= seo_jsonld($tab, $seoTld) ?>
<link rel="icon" href="assets/favicon.svg" type="image/svg+xml">
<link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="assets/style.css?v=6">
</head>
<body>
<header class="site-header">
  <div class="container">
    <a class="logo" href="./" aria-label="Domacy Expired Domains home">
      <span class="word"><span class="name">domacy</span><span class="dot"></span></span>
      <span class="sub">Expired domains</span>
    </a>
    <nav class="nav" id="nav">
      <a href="./">Deleted</a>
      <a href="?tab=expiring">Auctions</a>
      <a href="?tab=closeout">Closeouts</a>
      <a href="?tab=ae">.ae</a>
      <a href="<?= h($main) ?>/guides">Guides</a>
      <?php if ($user): ?><a href="account.php">My account</a><?php else: ?><a href="login.php?return=<?= h(rawurlencode($here)) ?>">Sign in</a><?php endif; ?>
      <a class="btn-pill" href="<?= h($main) ?>/">Register a domain</a>
    </nav>
    <button class="menu-btn" aria-label="Menu" onclick="document.getElementById('nav').classList.toggle('open')">
      <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M4 7h16M4 12h16M4 17h16"/></svg>
    </button>
  </div>
</header>

<section class="hero">
  <div class="hero-bg"><div class="hero-glow g1"></div><div class="hero-glow g2"></div><div class="hero-glow g3"></div><div class="hero-grid"></div></div>
  <div class="container hero-inner">
    <span class="badge"><i></i><?= num($stats['deleted'] + $stats['expiring'] + $stats['closeout']) ?> domains · updated daily · free</span>
    <h1><?= seo_h1($tab, $seoTld) ?></h1>
    <p class="lead">Deleted names you can register today, plus expiring auctions with authority, age and backlinks. Search them all at once.</p>

    <form class="card" method="get" action="./">
      <div class="card-top">
        <div class="chips" role="tablist">
          <?php foreach (TABS as $k => $t): ?>
            <a class="chip <?= $k === $tab ? 'on' : '' ?>" href="<?= $k === 'deleted' ? './' : '?tab=' . $k ?>"><?= h($t['label']) ?> <span class="n"><?= num($stats[$k]) ?></span></a>
          <?php endforeach; ?>
        </div>
        <input type="hidden" name="tab" value="<?= h($tab) ?>">
        <div class="searchrow">
          <div class="searchbox">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/></svg>
            <input type="search" name="q" value="<?= h(f('q')) ?>" placeholder="Keyword, like dubai" autocomplete="off" aria-label="Keyword">
          </div>
          <select class="match" name="match" aria-label="Match">
            <?php foreach (['contains' => 'Contains', 'starts' => 'Starts with', 'ends' => 'Ends with', 'exact' => 'Exact name'] as $v => $l): ?>
              <option value="<?= $v ?>" <?= f('match', 'contains') === $v ? 'selected' : '' ?>><?= $l ?></option>
            <?php endforeach; ?>
          </select>
          <select class="match" name="tld" aria-label="Ending">
            <option value="">All endings</option>
            <?php foreach ($tlds as $t => $c): ?>
              <option value="<?= h($t) ?>" <?= f('tld') === $t ? 'selected' : '' ?>>.<?= h($t) ?> (<?= num($c) ?>)</option>
            <?php endforeach; ?>
          </select>
          <button class="btn-search" type="submit">Search <?= h(strtolower(TABS[$tab]['label'])) ?></button>
        </div>
      </div>
      <div class="card-filters">
        <div class="frow">
          <label>Length <input type="number" name="minlen" min="1" value="<?= h(f('minlen')) ?>" placeholder="min"> – <input type="number" name="maxlen" min="1" value="<?= h(f('maxlen')) ?>" placeholder="max"></label>
          <label><input type="checkbox" name="nodigits" value="1" <?= f('nodigits') ? 'checked' : '' ?>> No numbers</label>
          <label><input type="checkbox" name="nohyphen" value="1" <?= f('nohyphen') ? 'checked' : '' ?>> No hyphens</label>
          <?php if ($isAe): ?>
            <label>Show <select name="view">
              <?php foreach (['watch' => 'Expiring + free now', 'pending' => 'Pending delete', 'released' => 'Just released', 'available' => 'All free names', 'all' => 'Everything tracked'] as $v => $l): ?>
                <option value="<?= $v ?>" <?= $aeView === $v ? 'selected' : '' ?>><?= $l ?></option>
              <?php endforeach; ?>
            </select></label>
          <?php elseif ($isDel): ?>
            <label><input type="checkbox" name="status" value="available" <?= f('status') === 'available' ? 'checked' : '' ?>> Available only</label>
            <label>Dropped <select name="dropped"><option value="">Any day</option>
              <?php foreach ($dropDates as $d): ?><option <?= f('dropped') === $d ? 'selected' : '' ?>><?= h($d) ?></option><?php endforeach; ?>
            </select></label>
          <?php else: ?>
            <label>Authority ≥ <input type="number" name="min_as" min="0" max="100" value="<?= h(f('min_as')) ?>"></label>
            <label>Ref. domains ≥ <input type="number" name="min_rd" min="0" value="<?= h(f('min_rd')) ?>"></label>
            <label>Age ≥ <input type="number" name="min_age" min="0" value="<?= h(f('min_age')) ?>"></label>
            <label>Search vol ≥ <input type="number" name="min_sv" min="0" value="<?= h(f('min_sv')) ?>"></label>
            <label>Price ≤ $ <input type="number" name="max_price" min="0" value="<?= h(f('max_price')) ?>"></label>
            <label>Ending <select name="ending">
              <option value="">Any time</option>
              <option value="24h" <?= f('ending') === '24h' ? 'selected' : '' ?>>Next 24 hours</option>
              <option value="today" <?= f('ending') === 'today' ? 'selected' : '' ?>>Today (UTC)</option>
            </select></label>
          <?php endif; ?>
          <?php if ($filtersOpen || f('q') !== '' || f('tld') !== ''): ?><a class="reset" href="?tab=<?= h($tab) ?>">Clear filters</a><?php endif; ?>
        </div>
      </div>
    </form>

    <div class="ticks">
      <span><?= $check ?> Updated every day</span>
      <span><?= $check ?> Authority, age and backlinks</span>
      <span><?= $check ?> Register available names at Domacy</span>
    </div>
  </div>
</section>

<section class="results">
  <div class="container">
    <div class="res-head">
      <div>
        <h2><?= num($total) ?> <?= h(strtolower(TABS[$tab]['label'])) ?></h2>
        <p><?= h(TABS[$tab]['hint']) ?> Last updated <?= h(ago($lastUpd)) ?>.</p>
      </div>
      <span class="spacer"></span>
      <?php if ($user): ?>
        <form method="post" action="action.php" class="inline-form">
          <input type="hidden" name="csrf" value="<?= h($csrf) ?>"><input type="hidden" name="do" value="save_search">
          <input type="hidden" name="back" value="<?= h(url_with(['tab' => $tab, 'page' => null, 'sort' => null, 'dir' => null])) ?>">
          <button type="submit" class="btn-outline save-btn">🔔 Save this search</button>
        </form>
      <?php else: ?>
        <a class="btn-outline save-btn" href="login.php?return=<?= h(rawurlencode($here)) ?>" rel="nofollow">🔔 Save this search</a>
      <?php endif; ?>
      <a class="btn-outline" href="<?= h(url_with(['export' => 'csv', 'page' => null])) ?>" rel="nofollow">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"><path d="M12 4v11m0 0 4-4m-4 4-4-4M5 20h14"/></svg> Download CSV</a>
    </div>

    <?php if ($flash): ?><p class="flash"><?= h($flash) ?></p><?php endif; ?>
    <div class="tablecard"><div class="tablewrap">
      <table>
        <thead><tr>
          <?= th('domain', 'Domain') ?><?= th('len', 'Length', 'Name length without the ending') ?>
          <?php if ($isAe): ?>
            <?= th('tld', 'Ending') ?><?= th('status', 'Status') ?><?= th('since', 'Since', 'Date we first saw this status') ?><?= th('checked', 'Checked') ?><th></th>
          <?php elseif ($isDel): ?>
            <?= th('tld', 'Ending') ?><?= th('dropped', 'Dropped') ?><?= th('status', 'Status') ?><th></th>
          <?php else: ?>
            <?= th('price', 'Price') ?><?= th('bids', 'Bids') ?><?= th('age', 'Age', 'Years since first registered') ?>
            <?= th('as', 'Authority', 'Semrush Authority Score (0–100)') ?><?= th('rd', 'Ref. domains', 'Referring domains') ?>
            <?= th('sv', 'Search vol', 'Monthly searches for the keyword') ?><?= th('val', 'Est. value', 'GoDaddy estimated value') ?>
            <?= th('end', 'Ends (UTC)') ?><th></th>
          <?php endif; ?>
        </tr></thead>
        <tbody>
        <?php if (!$rows): ?>
          <tr><td colspan="12" class="empty"><?= $isAe && !$stats['ae'] ? 'We are building the .ae watch list: names are collected from SSL certificate logs and top-site lists, then checked at the .ae registry. Expiring and freed names appear here as they are found.' : ($isDel && !$stats['deleted'] ? 'The first list of deleted domains appears the day after the first ICANN zone download. Meanwhile, browse expiring auctions and closeouts.' : 'No domains match these filters. Try a shorter keyword or clear some filters.') ?></td></tr>
        <?php endif; ?>
        <?php foreach ($rows as $r): $d = $r['domain']; [$sld, $tl] = split_domain($d); ?>
          <tr>
            <td class="dom"><?= star_btn($d, isset($watched[$d]), $user !== null, $csrf, $here) ?><?= h($sld) ?><span class="t">.<?= h($tl) ?></span></td>
            <td class="mut"><?= (int)$r['sld_len'] ?></td>
            <?php if ($isAe):
                $lbl = ['available' => $r['dropped_on'] ? 'Just released' : 'Available', 'pending' => 'Pending delete', 'registered' => 'Registered', 'unknown' => 'Not checked'][$r['status']] ?? $r['status'];
                $cls = ['available' => 'available', 'pending' => 'unknown', 'registered' => 'registered'][$r['status']] ?? 'no_rdap'; ?>
              <td class="mut">.<?= h($r['tld']) ?></td>
              <td><span class="st st-<?= h($cls) ?>" title="<?= h($r['whois_status']) ?>"><?= h($lbl) ?></span></td>
              <td class="mut"><?= h($r['dropped_on'] ?: $r['status_since']) ?></td>
              <td class="mut"><?= h(ago($r['checked_at'])) ?></td>
              <td class="links">
                <a class="act ghost" href="https://web.archive.org/web/*/<?= h(rawurlencode($d)) ?>" target="_blank" rel="noopener nofollow">History</a>
                <?php if ($r['status'] === 'available'): ?><a class="act" href="<?= h($main) ?>/search?q=<?= h(rawurlencode($d)) ?>">Register</a>
                <?php else: ?><a class="act ghost" href="<?= h($main) ?>/whois?q=<?= h(rawurlencode($d)) ?>">WHOIS</a><?php endif; ?>
              </td>
            <?php elseif ($isDel): ?>
              <td class="mut">.<?= h($r['tld']) ?></td>
              <td class="mut"><?= h($r['dropped_on']) ?></td>
              <td><span class="st st-<?= h($r['status']) ?>"><?= h(['available' => 'Available', 'registered' => 'Taken', 'unknown' => 'Checking', 'no_rdap' => 'Unknown'][$r['status']] ?? $r['status']) ?></span></td>
              <td class="links">
                <a class="act ghost" href="https://web.archive.org/web/*/<?= h(rawurlencode($d)) ?>" target="_blank" rel="noopener nofollow">History</a>
                <a class="act" href="<?= h($main) ?>/search?q=<?= h(rawurlencode($d)) ?>">Register</a>
              </td>
            <?php else: ?>
              <td>$<?= num($r['price']) ?></td>
              <td class="mut"><?= num($r['bids']) ?></td>
              <td class="mut"><?= num($r['age']) ?></td>
              <td class="<?= $r['semrush_as'] >= 20 ? 'hi' : '' ?>"><?= num($r['semrush_as']) ?></td>
              <td class="mut"><?= num($r['semrush_rd']) ?></td>
              <td class="mut"><?= num($r['search_volume']) ?></td>
              <td class="mut">$<?= num($r['valuation']) ?></td>
              <td class="mut"><?= h(substr((string)$r['end_time'], 0, 16)) ?></td>
              <td class="links">
                <a class="act ghost" href="https://web.archive.org/web/*/<?= h(rawurlencode($d)) ?>" target="_blank" rel="noopener nofollow">History</a>
                <a class="act" href="<?= h($r['link']) ?>" target="_blank" rel="noopener nofollow sponsored"><?= $tab === 'closeout' ? 'Buy now' : 'Bid' ?></a>
              </td>
            <?php endif; ?>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div></div>

    <?php if ($pages > 1): ?>
    <nav class="pager">
      <?php if ($page > 1): ?><a class="btn-outline" href="<?= h(url_with(['page' => $page - 1])) ?>">← Previous</a><?php endif; ?>
      <span>Page <?= num($page) ?> of <?= num($pages) ?></span>
      <?php if ($page < $pages): ?><a class="btn-outline" href="<?= h(url_with(['page' => $page + 1])) ?>">Next →</a><?php endif; ?>
    </nav>
    <?php endif; ?>
  </div>
</section>

<section class="why">
  <div class="container why-grid">
    <div><h3>Deleted every day</h3><p>We compare the official registry zone files each morning, so you see names the moment they drop.</p></div>
    <div><h3>Checked before you look</h3><p>Every deleted name is checked against the registry, so "Available" means you can register it now.</p></div>
    <div><h3>Real metrics</h3><p>Authority score, referring domains, search volume and age, so you can tell a gem from junk.</p></div>
    <div><h3>Register in one click</h3><p>Found one? Register it at Domacy with the first-year and renewal price shown up front.</p></div>
  </div>
</section>

<section class="seo">
  <div class="container seo-grid">
    <div class="seo-main">
      <h2>Popular expired domain endings</h2>
      <p class="seo-lead">Browse <?= h(strtolower(TABS[$tab]['label'])) ?> by ending. Each list is updated every day.</p>
      <ul class="tldlinks">
        <?php foreach (array_slice($tlds, 0, SEO_TLD_PAGES, true) as $t => $c): ?>
          <li><a href="?<?= h(http_build_query(['tab' => $tab, 'tld' => $t])) ?>">Expired .<?= h($t) ?> domains <span><?= num($c) ?></span></a></li>
        <?php endforeach; ?>
      </ul>

      <h2>About expired domains</h2>
      <p>Every day thousands of domain names reach the end of their registration and are not renewed. After a grace period and a redemption period, the registry deletes them and they become free to register again. Domacy Expired Domains lists those <strong>deleted domains</strong> the morning they drop, along with <strong>expiring domain auctions</strong> and <strong>closeout domains</strong> you can buy before they are deleted.</p>
      <p>People look for expired domains for a short name that was out of reach, for a brand that matches their business, or for a name that already has links and visitors. Use the filters for length, numbers, hyphens, authority, referring domains, age and search volume to find the good ones, and always check a name’s history before you build on it.</p>
      <p>Learn more in our guides: <a href="<?= h($main) ?>/guides/what-happens-when-a-domain-expires">what happens when a domain expires</a>, <a href="<?= h($main) ?>/help/domains/expired-domains">how expiry works</a> and <a href="<?= h($main) ?>/help/domains/backorders">backorders for taken names</a>. Check any name with the free <a href="<?= h($main) ?>/whois">WHOIS lookup</a>, <a href="<?= h($main) ?>/tools/domain-history-checker">domain history checker</a> and <a href="<?= h($main) ?>/tools/domain-reputation-checker">domain reputation checker</a>.</p>
    </div>
    <div class="seo-faq">
      <h2>Expired domains: questions and answers</h2>
      <?php foreach (seo_faq() as [$q, $a]): ?>
        <details><summary><?= h($q) ?></summary><p><?= h($a) ?></p></details>
      <?php endforeach; ?>
    </div>
  </div>
</section>

<footer class="site-footer">
  <div class="container">
    <div class="cta">
      <div><h2>Ready to claim your name?</h2><p>Search every ending with the first-year and renewal price shown before you pay.</p></div>
      <a class="btn-glow" href="<?= h($main) ?>/">Search domains at Domacy</a>
    </div>
    <div class="fcols">
      <div class="fbrand">
        <a class="logo" href="./"><span class="word"><span class="name">domacy</span><span class="dot"></span></span><span class="sub">Expired domains</span></a>
        <p>Domains, websites and email with the full price shown first.</p>
      </div>
      <div><h4>Expired domains</h4><a href="./">Deleted domains</a><a href="?tab=expiring">Expiring auctions</a><a href="?tab=closeout">Closeouts</a></div>
      <div><h4>Domains</h4><a href="<?= h($main) ?>/search">Search domains</a><a href="<?= h($main) ?>/pricing">Domain prices</a><a href="<?= h($main) ?>/whois">WHOIS lookup</a><a href="<?= h($main) ?>/tools">Free domain tools</a><a href="<?= h($main) ?>/marketplace">Marketplace</a></div>
      <div><h4>Help</h4><a href="<?= h($main) ?>/help">Help center</a><a href="<?= h($main) ?>/guides">Domain guides</a><a href="mailto:admin@domacy.com">admin@domacy.com</a></div>
      <div><h4>Your account</h4><a href="<?= h($main) ?>/account">My account</a><a href="<?= h($main) ?>/login">Sign in</a></div>
    </div>
    <div class="fbottom">
      <span>© <?= date('Y') ?> Domacy. All rights reserved. Data: ICANN zone files, GoDaddy Auctions, Semrush, RDAP.</span>
      <span><a href="<?= h($main) ?>/terms">Terms of Service</a><a href="<?= h($main) ?>/privacy">Privacy</a></span>
    </div>
  </div>
</footer>
<script>
// Watchlist stars without reloading the page
document.querySelectorAll('.star-form').forEach(function (f) {
  f.addEventListener('submit', function (e) {
    e.preventDefault();
    var fd = new FormData(f); fd.append('ajax', '1');
    var btn = f.querySelector('button'), doIn = f.querySelector('[name=do]');
    fetch('action.php', { method: 'POST', body: fd, credentials: 'same-origin' }).then(function (r) { return r.json(); }).then(function (j) {
      if (!j.ok) return;
      btn.classList.toggle('on', j.watching); btn.textContent = j.watching ? '★' : '☆';
      btn.title = j.watching ? 'On your watchlist' : 'Add to watchlist';
      doIn.value = j.watching ? 'unwatch' : 'watch';
    }).catch(function () { f.submit(); });
  });
});
</script>
</body>
</html>
