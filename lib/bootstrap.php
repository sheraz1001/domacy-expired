<?php
declare(strict_types=1);

const APP_ROOT = __DIR__ . '/..';

function config(?string $key = null)
{
    static $cfg = null;
    if ($cfg === null) {
        $file = APP_ROOT . '/config.php';
        if (!is_file($file)) {
            http_response_code(500);
            exit("Missing config.php\n");
        }
        $cfg = require $file;
    }
    return $key === null ? $cfg : ($cfg[$key] ?? null);
}

function db(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        $pdo = new PDO(config('db_dsn'), config('db_user'), config('db_pass'), [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        if (is_sqlite()) {
            $pdo->exec('PRAGMA journal_mode=WAL; PRAGMA synchronous=NORMAL; PRAGMA busy_timeout=15000; PRAGMA cache_size=-65536; PRAGMA mmap_size=1073741824;');
        }
    }
    return $pdo;
}

function is_sqlite(): bool
{
    return str_starts_with((string)config('db_dsn'), 'sqlite:');
}

function cli_only(): void
{
    if (PHP_SAPI === 'cli') return;
    $key = (string)config('cron_key');
    if ($key === '' || !hash_equals($key, (string)($_GET['key'] ?? ''))) {
        http_response_code(403);
        exit('Forbidden');
    }
    header('Content-Type: text/plain');
}

function logmsg(string $msg): void
{
    echo '[' . date('Y-m-d H:i:s') . '] ' . $msg . "\n";
    @flush();
}

function data_dir(string $sub = ''): string
{
    $dir = rtrim((string)config('data_dir'), '/') . ($sub !== '' ? '/' . $sub : '');
    if (!is_dir($dir)) mkdir($dir, 0775, true);
    return $dir;
}

/** Split "example.co.uk" style names: returns [sld, tld] using the last label as TLD. */
function split_domain(string $domain): array
{
    $domain = strtolower(trim($domain, ". \t"));
    $pos = strpos($domain, '.');
    if ($pos === false) return [$domain, ''];
    return [substr($domain, 0, $pos), substr($domain, $pos + 1)];
}

function money_to_int($v): int
{
    if (is_int($v)) return $v;
    return (int)preg_replace('/[^0-9]/', '', (string)$v);
}

/**
 * Multi-row upsert. $unique = columns forming the unique key.
 */
function bulk_upsert(string $table, array $cols, array $rows, array $unique): void
{
    if (!$rows) return;
    $ph = '(' . implode(',', array_fill(0, count($cols), '?')) . ')';
    $sql = "INSERT INTO $table (" . implode(',', $cols) . ') VALUES ' . implode(',', array_fill(0, count($rows), $ph));
    $upd = array_diff($cols, $unique);
    if (is_sqlite()) {
        $sql .= ' ON CONFLICT(' . implode(',', $unique) . ') DO UPDATE SET '
            . implode(',', array_map(fn($c) => "$c=excluded.$c", $upd));
    } else {
        $sql .= ' ON DUPLICATE KEY UPDATE ' . implode(',', array_map(fn($c) => "$c=VALUES($c)", $upd));
    }
    $params = [];
    foreach ($rows as $r) foreach ($r as $v) $params[] = $v;
    db()->prepare($sql)->execute($params);
}

function http_get(string $url, array $headers = [], ?string $saveTo = null, int $timeout = 60): array
{
    $ch = curl_init($url);
    $fh = null;
    $opts = [
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_USERAGENT => 'DomacyExpired/1.0 (+https://expired.domacy.com)',
        CURLOPT_CONNECTTIMEOUT => 20,
        CURLOPT_TIMEOUT => $timeout,
    ];
    if ($saveTo) {
        $fh = fopen($saveTo, 'wb');
        $opts[CURLOPT_FILE] = $fh;
    } else {
        $opts[CURLOPT_RETURNTRANSFER] = true;
    }
    curl_setopt_array($ch, $opts);
    $body = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    if ($fh) fclose($fh);
    return ['code' => $code, 'body' => $saveTo ? null : $body, 'error' => $err];
}

/** Called after imports so counts refresh straight away. */
function clear_cache(): void
{
    foreach (glob(data_dir('cache') . '/*.json') ?: [] as $f) @unlink($f);
}
