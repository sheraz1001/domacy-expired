<?php
require __DIR__ . '/../lib/bootstrap.php';
require __DIR__ . '/../lib/search.php';
require __DIR__ . '/../lib/auth.php';
require __DIR__ . '/../lib/layout.php';

$user = require_login();
$uid = (int)$user['id'];
$csrf = csrf_token();
$msg = flash();

$searches = db()->prepare('SELECT * FROM saved_searches WHERE user_id = ? ORDER BY created_at DESC');
$searches->execute([$uid]);
$searches = $searches->fetchAll();
$watch = db()->prepare('SELECT * FROM watchlist WHERE user_id = ? ORDER BY created_at DESC');
$watch->execute([$uid]);
$watch = $watch->fetchAll();

function post_btn(string $do, array $fields, string $label, string $class = 'act ghost', string $confirm = ''): string
{
    global $csrf;
    $h = '<form method="post" action="action.php" class="inline-form"' . ($confirm ? ' onsubmit="return confirm(\'' . h($confirm) . '\')"' : '') . '>'
        . '<input type="hidden" name="csrf" value="' . h($csrf) . '"><input type="hidden" name="do" value="' . h($do) . '">'
        . '<input type="hidden" name="back" value="/account.php">';
    foreach ($fields as $k => $v) $h .= '<input type="hidden" name="' . h($k) . '" value="' . h($v) . '">';
    return $h . '<button type="submit" class="' . h($class) . '">' . h($label) . '</button></form>';
}

page_head('My account');
?>
<section class="results acct">
  <div class="container">
    <div class="res-head">
      <div>
        <h2>My account</h2>
        <p>Signed in as <strong><?= h($user['email']) ?></strong></p>
      </div>
      <span class="spacer"></span>
      <?= post_btn('logout', [], 'Sign out', 'btn-outline') ?>
    </div>
    <?php if ($msg): ?><p class="flash"><?= h($msg) ?></p><?php endif; ?>

    <div class="acct-box">
      <div class="acct-row">
        <div>
          <h3>Daily email</h3>
          <p class="muted">Every day around 12:00 UAE time we email you new domains that match your saved searches, and any change to domains on your watchlist. Nothing new means no email.</p>
        </div>
        <?= $user['alerts_on'] ? post_btn('alerts_off', [], 'Turn off emails') : post_btn('alerts_on', [], 'Turn on emails', 'act') ?>
      </div>
    </div>

    <h3 class="acct-h">Saved searches <span class="muted">(<?= count($searches) ?>)</span></h3>
    <?php if (!$searches): ?>
      <p class="muted">No saved searches yet. Search on any tab, then press <strong>Save this search</strong>.</p>
    <?php else: ?>
      <div class="tablecard"><div class="tablewrap"><table>
        <thead><tr><th>Search</th><th>Email alert</th><th>Last alert</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($searches as $s): ?>
          <tr>
            <td class="dom wrap"><a href="./?<?= h($s['params']) ?>"><?= h($s['name']) ?></a></td>
            <td><?= post_btn('toggle_alert', ['id' => $s['id']], $s['alert'] ? 'On' : 'Off', $s['alert'] ? 'act' : 'act ghost') ?></td>
            <td class="mut"><?= h($s['last_alert_at'] ? substr($s['last_alert_at'], 0, 10) : '–') ?></td>
            <td class="links"><a class="act ghost" href="./?<?= h($s['params']) ?>">Open</a><?= post_btn('delete_search', ['id' => $s['id']], 'Delete', 'act ghost', 'Delete this saved search?') ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table></div></div>
    <?php endif; ?>

    <h3 class="acct-h">Watchlist <span class="muted">(<?= count($watch) ?>)</span></h3>
    <p class="muted">We email you when a watched domain is deleted, goes to auction, or becomes free to register. Star domains in any list, or add any domain here, including ones that are still registered.</p>
    <form method="post" action="action.php" class="watch-add">
      <input type="hidden" name="csrf" value="<?= h($csrf) ?>"><input type="hidden" name="do" value="add_watch"><input type="hidden" name="back" value="/account.php">
      <div class="searchbox"><input type="text" name="domains" placeholder="example.com, dubai.ae (up to 100, comma or space separated)" aria-label="Domains to watch"></div>
      <button class="btn-search" type="submit">Add to watchlist</button>
    </form>
    <?php if ($watch): ?>
      <div class="tablecard"><div class="tablewrap"><table>
        <thead><tr><th>Domain</th><th>Status now</th><th>Added</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($watch as $w): $state = domain_state($w['domain']); ?>
          <tr>
            <td class="dom"><?= h($w['domain']) ?></td>
            <td class="mut wrap"><?= h(state_label($state)) ?></td>
            <td class="mut"><?= h(substr($w['created_at'], 0, 10)) ?></td>
            <td class="links">
              <?php if (in_array($state, ['deleted:available', 'ae:available'], true)): ?><a class="act" href="<?= h(config('main_site')) ?>/search?q=<?= h(rawurlencode($w['domain'])) ?>">Register</a><?php endif; ?>
              <?= post_btn('unwatch', ['domain' => $w['domain']], 'Remove') ?>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table></div></div>
    <?php endif; ?>

    <div class="acct-danger">
      <?= post_btn('delete_account', [], 'Delete my account', 'link-danger', 'Delete your account, saved searches and watchlist? This cannot be undone.') ?>
    </div>
  </div>
</section>
<?php page_foot();
