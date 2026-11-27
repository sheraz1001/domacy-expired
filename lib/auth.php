<?php
// Accounts: passwordless sign-in by email link, sessions, CSRF, and email sending.

require_once __DIR__ . '/vendor/phpmailer/Exception.php';
require_once __DIR__ . '/vendor/phpmailer/PHPMailer.php';
require_once __DIR__ . '/vendor/phpmailer/SMTP.php';

function session_boot(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) return;
    $dir = data_dir('sessions');
    session_save_path($dir);
    ini_set('session.gc_maxlifetime', (string)(60 * 86400));
    ini_set('session.use_strict_mode', '1');
    session_name('dx_sess');
    session_set_cookie_params([
        'lifetime' => 60 * 86400,
        'path' => '/',
        'secure' => (($_SERVER['HTTPS'] ?? '') === 'on') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https'),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

function current_user(): ?array
{
    static $u = false;
    if ($u !== false) return $u;
    // Only open a session if the visitor already has one (keeps anonymous pages cacheable)
    if (!isset($_COOKIE['dx_sess']) && session_status() !== PHP_SESSION_ACTIVE) return $u = null;
    session_boot();
    $id = (int)($_SESSION['uid'] ?? 0);
    if (!$id) return $u = null;
    $st = db()->prepare('SELECT * FROM users WHERE id = ?');
    $st->execute([$id]);
    return $u = ($st->fetch() ?: null);
}

function require_login(): array
{
    $u = current_user();
    if (!$u) {
        header('Location: login.php?return=' . rawurlencode($_SERVER['REQUEST_URI'] ?? '/'));
        exit;
    }
    return $u;
}

function csrf_token(): string
{
    session_boot();
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));
    return $_SESSION['csrf'];
}

function csrf_check(): void
{
    session_boot();
    if (!hash_equals($_SESSION['csrf'] ?? '', (string)($_POST['csrf'] ?? ''))) {
        http_response_code(400);
        exit('Your session expired. Please go back and try again.');
    }
}

function client_ip(): string
{
    return substr((string)($_SERVER['HTTP_CF_CONNECTING_IP'] ?? $_SERVER['REMOTE_ADDR'] ?? ''), 0, 45);
}

/** Only allow returning to a path on this site. */
function safe_return(?string $r): string
{
    $r = (string)$r;
    if ($r === '' || $r[0] !== '/' || str_starts_with($r, '//') || str_contains($r, '\\')) return '/';
    return substr($r, 0, 500);
}

function mail_configured(): bool
{
    return config('smtp_host') !== '' && config('smtp_user') !== '' && config('smtp_pass') !== '';
}

/** Sends an HTML email. Returns '' on success or an error message. */
function send_mail(string $to, string $subject, string $html, string $text, array $headers = []): string
{
    if (!mail_configured()) return 'Email is not set up yet.';
    $m = new PHPMailer\PHPMailer\PHPMailer(true);
    try {
        $m->isSMTP();
        $m->Host = config('smtp_host');
        $m->Port = config('smtp_port');
        $m->SMTPAuth = true;
        $m->Username = config('smtp_user');
        $m->Password = config('smtp_pass');
        $sec = config('smtp_secure');
        if ($sec === 'none') { $m->SMTPSecure = ''; $m->SMTPAutoTLS = false; $m->SMTPAuth = false; } // local testing only
        else $m->SMTPSecure = $sec === 'tls' ? PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS : PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS;
        $m->Timeout = 20;
        $m->CharSet = 'UTF-8';
        $m->setFrom(config('mail_from'), config('mail_from_name'));
        $m->addAddress($to);
        foreach ($headers as $k => $v) $m->addCustomHeader($k, $v);
        $m->isHTML(true);
        $m->Subject = $subject;
        $m->Body = $html;
        $m->AltBody = $text;
        $m->send();
        return '';
    } catch (Throwable $e) {
        return 'Could not send email: ' . $m->ErrorInfo;
    }
}

/** Creates a sign-in link and emails it. Returns '' on success or an error message for the user. */
function send_login_link(string $email, string $returnTo): string
{
    $email = strtolower(trim($email));
    if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 190) return 'Please enter a valid email address.';

    // Rate limits: 3 links per email per hour, 10 per IP per hour
    $hourAgo = gmdate('Y-m-d H:i:s', time() - 3600);
    $c = db()->prepare('SELECT COUNT(*) FROM login_tokens WHERE email = ? AND created_at > ?');
    $c->execute([$email, $hourAgo]);
    if ($c->fetchColumn() >= 3) return 'We have already sent you a few links. Please check your inbox (and spam folder), or try again in an hour.';
    $c = db()->prepare('SELECT COUNT(*) FROM login_tokens WHERE ip = ? AND created_at > ?');
    $c->execute([client_ip(), $hourAgo]);
    if ($c->fetchColumn() >= 10) return 'Too many sign-in requests. Please try again in an hour.';

    $token = bin2hex(random_bytes(32));
    db()->prepare('INSERT INTO login_tokens (token_hash, email, ip, return_to, created_at, expires_at) VALUES (?,?,?,?,?,?)')
        ->execute([hash('sha256', $token), $email, client_ip(), safe_return($returnTo), gmdate('Y-m-d H:i:s'), gmdate('Y-m-d H:i:s', time() + 1800)]);

    $link = config('site_url') . '/auth.php?t=' . $token;
    $html = email_layout('Your sign-in link', '
        <p style="margin:0 0 16px">Click the button below to sign in to Domacy Expired Domains. The link works once and expires in 30 minutes.</p>
        <p style="margin:24px 0"><a href="' . h($link) . '" style="display:inline-block;background:#082d6b;color:#fff;text-decoration:none;font-weight:700;padding:13px 26px;border-radius:999px">Sign in</a></p>
        <p style="margin:0;color:#5b6b84;font-size:13px">If you did not ask for this, you can ignore this email.</p>');
    $text = "Sign in to Domacy Expired Domains:\n$link\n\nThe link works once and expires in 30 minutes. If you did not ask for this, ignore this email.";
    $err = send_mail($email, 'Your sign-in link for Domacy Expired Domains', $html, $text);
    return $err === '' ? '' : 'Sorry, we could not send the email right now. Please try again later.';
}

/** Validates a sign-in token, creates the account if needed, starts the session. Returns return path or null. */
function consume_login_token(string $token): ?string
{
    $st = db()->prepare('SELECT * FROM login_tokens WHERE token_hash = ? AND used_at IS NULL AND expires_at > ?');
    $st->execute([hash('sha256', $token), gmdate('Y-m-d H:i:s')]);
    $row = $st->fetch();
    if (!$row) return null;
    db()->prepare('UPDATE login_tokens SET used_at = ? WHERE id = ?')->execute([gmdate('Y-m-d H:i:s'), $row['id']]);

    $u = db()->prepare('SELECT * FROM users WHERE email = ?');
    $u->execute([$row['email']]);
    $user = $u->fetch();
    if (!$user) {
        db()->prepare('INSERT INTO users (email, created_at, unsub_token) VALUES (?,?,?)')
            ->execute([$row['email'], gmdate('Y-m-d H:i:s'), bin2hex(random_bytes(16))]);
        $u->execute([$row['email']]);
        $user = $u->fetch();
    }
    db()->prepare('UPDATE users SET last_login = ? WHERE id = ?')->execute([gmdate('Y-m-d H:i:s'), $user['id']]);
    session_boot();
    session_regenerate_id(true);
    $_SESSION['uid'] = (int)$user['id'];
    $_SESSION['csrf'] = bin2hex(random_bytes(16));
    return $row['return_to'] ?: '/';
}

/** Domacy-styled email wrapper. */
function email_layout(string $title, string $bodyHtml, string $footerHtml = ''): string
{
    $site = h(config('site_url'));
    return '<!DOCTYPE html><html><body style="margin:0;background:#f4f7fb;font-family:Arial,Helvetica,sans-serif;color:#0c2556">
<table width="100%" cellpadding="0" cellspacing="0" style="background:#f4f7fb;padding:24px 0"><tr><td align="center">
<table width="600" cellpadding="0" cellspacing="0" style="max-width:600px;width:100%;background:#fff;border-radius:16px;overflow:hidden">
<tr><td style="background:#082d6b;padding:22px 28px">
  <a href="' . $site . '" style="text-decoration:none"><span style="font-size:24px;font-weight:800;color:#fff;letter-spacing:-1px">domacy</span><span style="display:inline-block;width:8px;height:8px;border-radius:8px;background:#1266d6;margin-left:2px"></span>
  <div style="font-size:10px;font-weight:700;letter-spacing:2px;color:#d6ff3d;margin-top:3px">EXPIRED DOMAINS</div></a>
</td></tr>
<tr><td style="padding:28px">
  <h1 style="margin:0 0 16px;font-size:22px;color:#0c2556">' . h($title) . '</h1>
  ' . $bodyHtml . '
</td></tr>
<tr><td style="padding:18px 28px;background:#f4f7fb;color:#5b6b84;font-size:12px;line-height:1.5">' .
        ($footerHtml ?: 'Domacy Expired Domains · <a href="' . $site . '" style="color:#1266d6">expired.domacy.com</a>') . '
</td></tr></table></td></tr></table></body></html>';
}
