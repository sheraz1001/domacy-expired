<?php
// POST endpoints: save a search, watch/unwatch a domain, account changes, sign out.
require __DIR__ . '/../lib/bootstrap.php';
require __DIR__ . '/../lib/search.php';
require __DIR__ . '/../lib/auth.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: /'); exit; }
$back = safe_return($_POST['back'] ?? '/');
$user = current_user();
if (!$user) { header('Location: login.php?return=' . rawurlencode($back)); exit; }
csrf_check();

$do = (string)($_POST['do'] ?? '');
$uid = (int)$user['id'];
$now = gmdate('Y-m-d H:i:s');

switch ($do) {
    case 'save_search':
        parse_str(ltrim((string)parse_url($back, PHP_URL_QUERY), '?'), $params);
        $params = array_intersect_key($params, array_flip(SAVED_PARAMS));
        $tab = isset(TABS[$params['tab'] ?? '']) ? $params['tab'] : 'deleted';
        $params['tab'] = $tab;
        $count = db()->prepare('SELECT COUNT(*) FROM saved_searches WHERE user_id = ?');
        $count->execute([$uid]);
        if ($count->fetchColumn() >= 50) { flash('You can save up to 50 searches.'); break; }
        $name = trim((string)($_POST['name'] ?? '')) ?: search_label($params);
        db()->prepare('INSERT INTO saved_searches (user_id, name, tab, params, alert, created_at, last_alert_at) VALUES (?,?,?,?,1,?,?)')
            ->execute([$uid, mb_substr($name, 0, 120), $tab, http_build_query($params), $now, $now]);
        flash('Search saved. We will email you when new domains match.');
        break;

    case 'delete_search':
        db()->prepare('DELETE FROM saved_searches WHERE id = ? AND user_id = ?')->execute([(int)$_POST['id'], $uid]);
        flash('Search deleted.');
        break;

    case 'toggle_alert':
        db()->prepare('UPDATE saved_searches SET alert = 1 - alert WHERE id = ? AND user_id = ?')->execute([(int)$_POST['id'], $uid]);
        break;

    case 'watch':
    case 'unwatch':
        $domain = strtolower(trim((string)($_POST['domain'] ?? '')));
        if (!preg_match('/^[a-z0-9-]{1,63}(\.[a-z0-9-]{1,63}){1,3}$/', $domain)) break;
        if ($do === 'watch') {
            $count = db()->prepare('SELECT COUNT(*) FROM watchlist WHERE user_id = ?');
            $count->execute([$uid]);
            if ($count->fetchColumn() >= 500) { flash('Your watchlist is full (500 domains).'); break; }
            $ins = is_sqlite() ? 'INSERT OR IGNORE' : 'INSERT IGNORE';
            db()->prepare("$ins INTO watchlist (user_id, domain, created_at, last_state) VALUES (?,?,?,?)")
                ->execute([$uid, $domain, $now, domain_state($domain)]);
        } else {
            db()->prepare('DELETE FROM watchlist WHERE user_id = ? AND domain = ?')->execute([$uid, $domain]);
        }
        if (($_POST['ajax'] ?? '') === '1') { header('Content-Type: application/json'); echo json_encode(['ok' => true, 'watching' => $do === 'watch']); exit; }
        break;

    case 'add_watch':
        $domains = preg_split('/[\s,]+/', strtolower((string)($_POST['domains'] ?? '')));
        $ins = is_sqlite() ? 'INSERT OR IGNORE' : 'INSERT IGNORE';
        $n = 0;
        foreach (array_slice(array_filter($domains), 0, 100) as $d) {
            if (!preg_match('/^[a-z0-9-]{1,63}(\.[a-z0-9-]{1,63}){1,3}$/', $d)) continue;
            db()->prepare("$ins INTO watchlist (user_id, domain, created_at, last_state) VALUES (?,?,?,?)")->execute([$uid, $d, $now, domain_state($d)]);
            $n++;
        }
        flash("Added $n domain" . ($n === 1 ? '' : 's') . ' to your watchlist.');
        break;

    case 'alerts_on':
    case 'alerts_off':
        db()->prepare('UPDATE users SET alerts_on = ? WHERE id = ?')->execute([$do === 'alerts_on' ? 1 : 0, $uid]);
        flash($do === 'alerts_on' ? 'Daily emails are on.' : 'Daily emails are off.');
        break;

    case 'delete_account':
        db()->prepare('DELETE FROM saved_searches WHERE user_id = ?')->execute([$uid]);
        db()->prepare('DELETE FROM watchlist WHERE user_id = ?')->execute([$uid]);
        db()->prepare('DELETE FROM login_tokens WHERE email = ?')->execute([$user['email']]);
        db()->prepare('DELETE FROM users WHERE id = ?')->execute([$uid]);
        // fall through to sign out
    case 'logout':
        $_SESSION = [];
        session_destroy();
        setcookie('dx_sess', '', time() - 3600, '/');
        header('Location: /');
        exit;
}

header('Location: ' . $back);
