<?php
require __DIR__ . '/../lib/bootstrap.php';
require __DIR__ . '/../lib/search.php';
require __DIR__ . '/../lib/auth.php';
require __DIR__ . '/../lib/layout.php';

$return = safe_return($_GET['return'] ?? $_POST['return'] ?? '/');
if (current_user()) { header('Location: ' . $return); exit; }

$sent = false;
$error = '';
$email = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $email = trim((string)($_POST['email'] ?? ''));
    // Honeypot: bots fill every field
    if (($_POST['website'] ?? '') !== '') { $sent = true; }
    else {
        $error = send_login_link($email, $return);
        $sent = $error === '';
    }
}

page_head('Sign in');
?>
<section class="hero hero-sm">
  <div class="hero-bg"><div class="hero-glow g1"></div><div class="hero-glow g2"></div><div class="hero-grid"></div></div>
  <div class="container hero-inner">
    <div class="card auth-card">
      <div class="card-top">
        <?php if ($sent): ?>
          <h1 class="auth-h">Check your email</h1>
          <p class="auth-p">We sent a sign-in link to <strong><?= h($email) ?></strong>. Click it to sign in. It expires in 30 minutes.</p>
          <p class="auth-p muted">Nothing there? Check your spam folder, or <a href="login.php?return=<?= h(rawurlencode($return)) ?>">try again</a>.</p>
        <?php else: ?>
          <h1 class="auth-h">Sign in or create a free account</h1>
          <p class="auth-p">Save searches, star domains to your watchlist, and get a daily email when new names match.</p>
          <?php if ($error): ?><p class="auth-err"><?= h($error) ?></p><?php endif; ?>
          <form method="post" class="auth-form">
            <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
            <input type="hidden" name="return" value="<?= h($return) ?>">
            <input type="text" name="website" value="" class="hp" tabindex="-1" autocomplete="off" aria-hidden="true">
            <div class="searchbox"><input type="email" name="email" value="<?= h($email) ?>" placeholder="you@example.com" required autofocus aria-label="Email address"></div>
            <button class="btn-search" type="submit">Email me a sign-in link</button>
          </form>
          <p class="auth-p muted small">No password needed. By signing in you agree to Domacy's <a href="<?= h(config('main_site')) ?>/terms">terms</a> and <a href="<?= h(config('main_site')) ?>/privacy">privacy policy</a>.</p>
        <?php endif; ?>
      </div>
    </div>
  </div>
</section>
<?php page_foot();
