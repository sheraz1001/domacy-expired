<?php
// Settings come from environment variables (set in Coolify), with safe defaults.
// Secrets (CZDS password) are never stored in the repo.

$envFile = __DIR__ . '/data/env.json'; // written at container start so cron jobs see the same env
$fileEnv = is_file($envFile) ? (json_decode((string)file_get_contents($envFile), true) ?: []) : [];
$env = function (string $k, $default = null) use ($fileEnv) {
    $v = getenv($k);
    if ($v === false || $v === '') $v = $fileEnv[$k] ?? null;
    return ($v === null || $v === '') ? $default : $v;
};

return [
    'site_name' => $env('SITE_NAME', 'Domacy Expired'),
    'site_url' => rtrim($env('SITE_URL', 'https://expired.domacy.com'), '/'),
    'main_site' => rtrim($env('MAIN_SITE', 'https://domacy.com'), '/'),
    'allow_indexing' => $env('ALLOW_INDEXING', '1') === '1',

    'db_dsn'  => $env('DB_DSN', 'sqlite:' . __DIR__ . '/data/app.sqlite'),
    'db_user' => $env('DB_USER'),
    'db_pass' => $env('DB_PASS'),

    'data_dir' => $env('DATA_DIR', __DIR__ . '/data'),

    // ICANN CZDS login (https://czds.icann.org)
    'czds_username' => $env('CZDS_USERNAME', ''),
    'czds_password' => $env('CZDS_PASSWORD', ''),
    'czds_zones' => array_filter(array_map('trim', explode(',', $env('CZDS_ZONES', 'all')))),

    // GoDaddy Auctions free inventory files (https://inventory.auctions.godaddy.com/)
    'godaddy_files' => [
        'expiring' => 'all_expiring_auctions.json.zip',
        'closeout' => 'closeout_listings.json.zip',
    ],
    'godaddy_skip_adult' => true,

    // 'all' = every zone ICANN has approved for this account
    'rdap_per_run' => (int)$env('RDAP_PER_RUN', 4000),      // names checked per run (runs every 5 min)
    'rdap_parallel' => (int)$env('RDAP_PARALLEL', 12),      // requests in flight at once
    'rdap_delay_ms' => (int)$env('RDAP_DELAY_MS', 0),
    'deleted_keep_days' => (int)$env('DELETED_KEEP_DAYS', 30),
    'cron_key' => '',
];
