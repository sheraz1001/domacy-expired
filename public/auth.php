<?php
require __DIR__ . '/../lib/bootstrap.php';
require __DIR__ . '/../lib/search.php';
require __DIR__ . '/../lib/auth.php';
require __DIR__ . '/../lib/layout.php';

header('Referrer-Policy: no-referrer');
$token = (string)($_GET['t'] ?? '');

// Email scanners open links automatically, which would use up the one-time token.
// So the link shows a button, and only the click (POST) signs the person in.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $return = preg_match('/^[a-f0-9]{64}$/', (string)($_POST['t'] ?? '')) ? consume_login_token($_POST['t']) : null;
    if ($return !== null) { header('Location: ' . safe_return($return)); exit; }
    $failed = true;
}

page_head('Sign in');
?>
<section class="hero hero-sm">
  <div class="hero-bg"><div class="hero-glow g1"></div><div class="hero-glow g2"></div><div class="hero-grid"></div></div>
  <div class="container hero-inner">
    <div class="card auth-card"><div class="card-top">
      <?php if (!empty($failed) || !preg_match('/^[a-f0-9]{64}$/', $token)): ?>
        <h1 class="auth-h">This link has expired</h1>
        <p class="auth-p">Sign-in links work once and expire after 30 minutes.</p>
        <p><a class="btn-search inline" href="login.php">Get a new link</a></p>
      <?php else: ?>
        <h1 class="auth-h">Sign in to Domacy Expired Domains</h1>
        <form method="post">
          <input type="hidden" name="t" value="<?= h($token) ?>">
          <button class="btn-search" type="submit">Continue</button>
        </form>
      <?php endif; ?>
    </div></div>
  </div>
</section>
<?php page_foot();
