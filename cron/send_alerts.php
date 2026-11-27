<?php
// Daily email: new domains for each saved search + changes on the watchlist.
// Runs once a day at 08:00 UTC (12:00 UAE):  php cron/send_alerts.php
// Options: --user=email@x.com (one user only)  --dry (print instead of sending)
require __DIR__ . '/../lib/bootstrap.php';
require __DIR__ . '/../lib/search.php';
require __DIR__ . '/../lib/auth.php';
cli_only();
@set_time_limit(0);

$args = getopt('', ['user:', 'dry']);
$dry = isset($args['dry']);
if (!mail_configured() && !$dry) { logmsg('Email is not set up (SMTP_HOST / SMTP_USER / SMTP_PASS). No alerts sent.'); exit(0); }

$site = config('site_url');
$main = config('main_site');
$now = gmdate('Y-m-d H:i:s');
$interesting = ['deleted:available', 'deleted:unknown', 'deleted:no_rdap', 'auction:expiring', 'auction:closeout', 'ae:pending', 'ae:available'];

$sql = 'SELECT * FROM users WHERE alerts_on = 1' . (isset($args['user']) ? ' AND email = ' . db()->quote(strtolower($args['user'])) : '');
$sent = 0;
foreach (db()->query($sql)->fetchAll() as $user) {
    $sections = [];
    $textParts = [];

    // --- Saved searches ---
    $ss = db()->prepare('SELECT * FROM saved_searches WHERE user_id = ? AND alert = 1');
    $ss->execute([$user['id']]);
    foreach ($ss->fetchAll() as $s) {
        parse_str($s['params'], $params);
        $tab = $s['tab'];
        if (!isset(TABS[$tab])) continue;
        $_GET = $params;
        [$where, $p, $order] = build_query($tab);
        $since = substr($s['last_alert_at'] ?: $s['created_at'], 0, 10);
        $newCol = ['deleted' => 'dropped_on', 'ae' => 'status_since'][$tab] ?? 'first_seen';
        $extra = "$newCol > ?";
        if ($tab === 'ae') $extra .= " AND status IN ('pending','available')";
        $where = $where ? "$where AND $extra" : "WHERE $extra";
        $p[] = $since;
        $table = TABS[$tab]['table'];
        $c = db()->prepare("SELECT COUNT(*) FROM $table $where");
        $c->execute($p);
        $total = (int)$c->fetchColumn();
        if (!$dry) db()->prepare('UPDATE saved_searches SET last_alert_at = ? WHERE id = ?')->execute([$now, $s['id']]);
        if (!$total) continue;
        $q = db()->prepare("SELECT * FROM $table $where ORDER BY $order LIMIT 20");
        $q->execute($p);
        $rows = $q->fetchAll();
        $url = $site . '/?' . $s['params'];
        $sections[] = email_search_section($s['name'], $total, $rows, $tab, $url, $main);
        $textParts[] = $s['name'] . " ($total new)\n" . implode("\n", array_map(fn($r) => '  ' . $r['domain'], $rows)) . "\n  See all: $url\n";
    }

    // --- Watchlist ---
    $wl = db()->prepare('SELECT * FROM watchlist WHERE user_id = ?');
    $wl->execute([$user['id']]);
    $changes = [];
    foreach ($wl->fetchAll() as $w) {
        $state = domain_state($w['domain']);
        if ($state === $w['last_state']) continue;
        if (!$dry) db()->prepare('UPDATE watchlist SET last_state = ?, last_alert_at = ? WHERE id = ?')->execute([$state, $now, $w['id']]);
        if (in_array($state, $interesting, true)) $changes[] = [$w['domain'], $state];
    }
    if ($changes) {
        $rowsHtml = '';
        foreach ($changes as [$d, $st]) {
            $btn = in_array($st, ['deleted:available', 'ae:available'], true)
                ? '<a href="' . h($main) . '/search?q=' . h(rawurlencode($d)) . '" style="color:#1266d6;font-weight:700">Register</a>'
                : '<a href="' . h($site) . '/?q=' . h(rawurlencode(explode('.', $d)[0])) . '&match=exact&tab=' . (str_starts_with($st, 'auction:') ? substr($st, 8) : (str_starts_with($st, 'ae:') ? 'ae' : 'deleted')) . '" style="color:#1266d6;font-weight:700">View</a>';
            $rowsHtml .= '<tr><td style="padding:8px 0;border-bottom:1px solid #dce3ec;font-weight:700">' . h($d) . '</td><td style="padding:8px;border-bottom:1px solid #dce3ec;color:#5b6b84">' . h(state_label($st)) . '</td><td style="padding:8px 0;border-bottom:1px solid #dce3ec;text-align:right">' . $btn . '</td></tr>';
        }
        array_unshift($sections, '<h2 style="font-size:17px;margin:0 0 8px">⭐ Watchlist updates</h2><table width="100%" cellpadding="0" cellspacing="0" style="font-size:14px;margin-bottom:24px">' . $rowsHtml . '</table>');
        array_unshift($textParts, "Watchlist updates:\n" . implode("\n", array_map(fn($c) => "  {$c[0]}: " . state_label($c[1]), $changes)) . "\n");
    }

    if (!$sections) continue;

    $unsub = $site . '/unsubscribe.php?u=' . $user['id'] . '&k=' . $user['unsub_token'];
    $footer = 'You get this because you saved searches or watched domains on Domacy Expired Domains.<br>'
        . '<a href="' . h($site) . '/account.php" style="color:#1266d6">Manage alerts</a> · <a href="' . h($unsub) . '" style="color:#1266d6">Unsubscribe</a>';
    $subject = $changes ? 'Watchlist update: ' . $changes[0][0] . (count($changes) > 1 ? ' and ' . (count($changes) - 1) . ' more' : '')
        : 'New expired domains for your saved searches';
    $html = email_layout('Your daily expired domains', implode('', $sections), $footer);
    $text = implode("\n", $textParts) . "\nManage alerts: $site/account.php\nUnsubscribe: $unsub\n";

    if ($dry) { echo "=== {$user['email']}: $subject\n$text\n"; continue; }
    $err = send_mail($user['email'], $subject, $html, $text, [
        'List-Unsubscribe' => "<$unsub>",
        'List-Unsubscribe-Post' => 'List-Unsubscribe=One-Click',
    ]);
    if ($err) logmsg("{$user['email']}: $err"); else $sent++;
    usleep(300000);
}
logmsg("Daily alerts sent: $sent");

function email_search_section(string $name, int $total, array $rows, string $tab, string $url, string $main): string
{
    $tr = '';
    foreach ($rows as $r) {
        $d = $r['domain'];
        if ($tab === 'deleted' || $tab === 'ae') {
            $info = $tab === 'ae' ? ($r['status'] === 'pending' ? 'Pending delete' : 'Free to register') : (['available' => 'Available', 'registered' => 'Taken', 'unknown' => 'Checking'][$r['status']] ?? '');
            $act = ($r['status'] === 'available' || $r['status'] === 'unknown')
                ? '<a href="' . h($main) . '/search?q=' . h(rawurlencode($d)) . '" style="color:#1266d6;font-weight:700">Register</a>' : '';
        } else {
            $info = '$' . number_format((float)$r['price']) . ($r['semrush_as'] ? ' · AS ' . (int)$r['semrush_as'] : '') . ($r['age'] ? ' · ' . (int)$r['age'] . ' yrs' : '');
            $act = '<a href="' . h($r['link']) . '" style="color:#1266d6;font-weight:700">' . ($tab === 'closeout' ? 'Buy' : 'Bid') . '</a>';
        }
        $tr .= '<tr><td style="padding:7px 0;border-bottom:1px solid #dce3ec;font-weight:700">' . h($d) . '</td>'
            . '<td style="padding:7px 8px;border-bottom:1px solid #dce3ec;color:#5b6b84">' . h($info) . '</td>'
            . '<td style="padding:7px 0;border-bottom:1px solid #dce3ec;text-align:right">' . $act . '</td></tr>';
    }
    $more = $total > count($rows) ? 'See all ' . number_format($total) . ' →' : 'Open search →';
    return '<h2 style="font-size:17px;margin:0 0 4px">' . h($name) . '</h2>'
        . '<p style="margin:0 0 8px;color:#5b6b84;font-size:13px">' . number_format($total) . ' new since your last email</p>'
        . '<table width="100%" cellpadding="0" cellspacing="0" style="font-size:14px">' . $tr . '</table>'
        . '<p style="margin:10px 0 26px"><a href="' . h($url) . '" style="color:#1266d6;font-weight:700">' . $more . '</a></p>';
}
