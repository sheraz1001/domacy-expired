<?php
// One-click unsubscribe from daily emails (link in every alert email, and List-Unsubscribe header).
require __DIR__ . '/../lib/bootstrap.php';
require __DIR__ . '/../lib/search.php';
require __DIR__ . '/../lib/auth.php';
require __DIR__ . '/../lib/layout.php';

$id = (int)($_GET['u'] ?? $_POST['u'] ?? 0);
$token = (string)($_GET['k'] ?? $_POST['k'] ?? '');
$st = db()->prepare('SELECT * FROM users WHERE id = ?');
$st->execute([$id]);
$user = $st->fetch();
$valid = $user && hash_equals($user['unsub_token'], $token);

// Mail clients send a POST for one-click unsubscribe (RFC 8058)
if ($valid && $_SERVER['REQUEST_METHOD'] === 'POST') {
    db()->prepare('UPDATE users SET alerts_on = 0 WHERE id = ?')->execute([$id]);
    $done = true;
}

page_head('Unsubscribe');
?>
<section class="hero hero-sm">
  <div class="hero-bg"><div class="hero-glow g1"></div><div class="hero-grid"></div></div>
  <div class="container hero-inner">
    <div class="card auth-card"><div class="card-top">
      <?php if (!$valid): ?>
        <h1 class="auth-h">This link is not valid</h1>
        <p class="auth-p">You can turn emails off from <a href="account.php">your account</a>.</p>
      <?php elseif (!empty($done)): ?>
        <h1 class="auth-h">You are unsubscribed</h1>
        <p class="auth-p">We won't send you daily emails any more. You can turn them back on in <a href="account.php">your account</a>.</p>
      <?php else: ?>
        <h1 class="auth-h">Stop daily emails?</h1>
        <p class="auth-p">You will stop getting alerts for <?= h($user['email']) ?>. Your saved searches and watchlist stay in your account.</p>
        <form method="post"><input type="hidden" name="u" value="<?= $id ?>"><input type="hidden" name="k" value="<?= h($token) ?>">
          <button class="btn-search" type="submit">Unsubscribe</button></form>
      <?php endif; ?>
    </div></div>
  </div>
</section>
<?php page_foot();
