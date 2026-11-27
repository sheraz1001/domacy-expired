<?php
// Daily housekeeping: old sign-in links and expired sessions.
require __DIR__ . '/../lib/bootstrap.php';
cli_only();
$st = db()->prepare('DELETE FROM login_tokens WHERE created_at < ?');
$st->execute([gmdate('Y-m-d H:i:s', time() - 7 * 86400)]);
$n = 0;
foreach (glob(data_dir('sessions') . '/sess_*') ?: [] as $f) {
    if (filemtime($f) < time() - 60 * 86400) { @unlink($f); $n++; }
}
logmsg("Removed {$st->rowCount()} old sign-in links and $n old sessions.");
