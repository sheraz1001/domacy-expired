<?php
// Shared header/footer for the account pages (same look as the main page).

function page_head(string $title): void
{
    $u = current_user();
    $main = config('main_site');
    ?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= h($title) ?> | Domacy Expired Domains</title>
<meta name="robots" content="noindex, nofollow">
<link rel="icon" href="assets/favicon.svg" type="image/svg+xml">
<link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="assets/style.css?v=<?= h((string)@filemtime(__DIR__ . '/../public/assets/style.css')) ?>">
</head>
<body>
<header class="site-header">
  <div class="container">
    <a class="logo" href="./" aria-label="Domacy Expired Domains home">
      <span class="word"><span class="name">domacy</span><span class="dot"></span></span>
      <span class="sub">Expired domains</span>
    </a>
    <nav class="nav" id="nav">
      <a href="/">Deleted</a>
      <a href="/?tab=expiring">Auctions</a>
      <a href="/?tab=ae">.ae</a>
      <?php if ($u): ?><a href="account.php">My account</a><?php else: ?><a href="login.php">Sign in</a><?php endif; ?>
      <a class="btn-pill" href="<?= h($main) ?>/">Register a domain</a>
    </nav>
    <button class="menu-btn" aria-label="Menu" onclick="document.getElementById('nav').classList.toggle('open')">
      <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M4 7h16M4 12h16M4 17h16"/></svg>
    </button>
  </div>
</header>
<?php
}

function page_foot(): void
{
    ?>
<footer class="site-footer">
  <div class="container">
    <div class="fbottom">
      <span>© <?= date('Y') ?> Domacy. All rights reserved.</span>
      <span><a href="<?= h(config('main_site')) ?>/terms">Terms of Service</a><a href="<?= h(config('main_site')) ?>/privacy">Privacy</a></span>
    </div>
  </div>
</footer>
</body>
</html>
<?php
}
