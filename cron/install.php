<?php
// Creates the database tables. Run once:  php cron/install.php
require __DIR__ . '/../lib/bootstrap.php';
cli_only();

$sqlite = is_sqlite();
$pk = $sqlite ? 'INTEGER PRIMARY KEY AUTOINCREMENT' : 'BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY';
// Domain columns are plain ASCII (IDNs are punycode): binary collation makes LIKE ~3x faster.
$asc = $sqlite ? '' : ' CHARACTER SET ascii COLLATE ascii_bin';
$engine = $sqlite ? '' : ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';

$stmts = [
"CREATE TABLE IF NOT EXISTS auctions (
  id $pk,
  domain VARCHAR(253)$asc NOT NULL,
  sld VARCHAR(190)$asc NOT NULL,
  tld VARCHAR(63)$asc NOT NULL,
  sld_len SMALLINT NOT NULL,
  has_digits TINYINT NOT NULL DEFAULT 0,
  has_hyphen TINYINT NOT NULL DEFAULT 0,
  list_type VARCHAR(16)$asc NOT NULL,
  auction_type VARCHAR(16),
  end_time DATETIME,
  price INT,
  bids INT,
  age INT,
  valuation INT,
  pageviews INT,
  parking_rev INT,
  tf INT, cf INT,
  backlinks BIGINT, ref_domains INT,
  semrush_as INT, semrush_rd INT, semrush_bl BIGINT,
  search_volume INT, cpc DECIMAL(10,2),
  exact_tlds INT, keyword_regs INT,
  is_adult TINYINT NOT NULL DEFAULT 0,
  link VARCHAR(512),
  batch VARCHAR(20) NOT NULL,
  UNIQUE (domain, list_type)
)$engine",
"CREATE TABLE IF NOT EXISTS deleted_domains (
  id $pk,
  domain VARCHAR(253)$asc NOT NULL UNIQUE,
  sld VARCHAR(190)$asc NOT NULL,
  tld VARCHAR(63)$asc NOT NULL,
  sld_len SMALLINT NOT NULL,
  has_digits TINYINT NOT NULL DEFAULT 0,
  has_hyphen TINYINT NOT NULL DEFAULT 0,
  dropped_on DATE NOT NULL,
  status VARCHAR(12) NOT NULL DEFAULT 'unknown',
  checked_at DATETIME NULL
)$engine",
"CREATE TABLE IF NOT EXISTS ae_domains (
  id $pk,
  domain VARCHAR(253)$asc NOT NULL UNIQUE,
  sld VARCHAR(190)$asc NOT NULL,
  tld VARCHAR(63)$asc NOT NULL,
  sld_len SMALLINT NOT NULL,
  has_digits TINYINT NOT NULL DEFAULT 0,
  has_hyphen TINYINT NOT NULL DEFAULT 0,
  source VARCHAR(20) NOT NULL,
  first_seen DATE NOT NULL,
  status VARCHAR(12) NOT NULL DEFAULT 'unknown',
  whois_status VARCHAR(120) NULL,
  status_since DATE NULL,
  dropped_on DATE NULL,
  checked_at DATETIME NULL
)$engine",
"CREATE TABLE IF NOT EXISTS users (
  id $pk,
  email VARCHAR(190) NOT NULL UNIQUE,
  created_at DATETIME NOT NULL,
  last_login DATETIME NULL,
  alerts_on TINYINT NOT NULL DEFAULT 1,
  unsub_token VARCHAR(64) NOT NULL
)$engine",
"CREATE TABLE IF NOT EXISTS login_tokens (
  id $pk,
  token_hash VARCHAR(64) NOT NULL UNIQUE,
  email VARCHAR(190) NOT NULL,
  ip VARCHAR(45) NOT NULL,
  return_to VARCHAR(500) NULL,
  created_at DATETIME NOT NULL,
  expires_at DATETIME NOT NULL,
  used_at DATETIME NULL
)$engine",
"CREATE TABLE IF NOT EXISTS saved_searches (
  id $pk,
  user_id INT NOT NULL,
  name VARCHAR(120) NOT NULL,
  tab VARCHAR(16) NOT NULL,
  params TEXT NOT NULL,
  alert TINYINT NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL,
  last_alert_at DATETIME NULL
)$engine",
"CREATE TABLE IF NOT EXISTS watchlist (
  id $pk,
  user_id INT NOT NULL,
  domain VARCHAR(253)$asc NOT NULL,
  created_at DATETIME NOT NULL,
  last_state VARCHAR(40) NULL,
  last_alert_at DATETIME NULL,
  UNIQUE (user_id, domain)
)$engine",
"CREATE TABLE IF NOT EXISTS import_log (
  id $pk,
  source VARCHAR(40) NOT NULL,
  rows_count INT NOT NULL,
  ran_at DATETIME NOT NULL
)$engine",
];

$indexes = [
    'auctions' => ['list_type,tld', 'list_type,end_time', 'list_type,tf', 'list_type,semrush_as', 'list_type,price', 'list_type,sld_len', 'batch'],
    'deleted_domains' => ['dropped_on,tld', 'status', 'sld_len', 'tld'],
    'login_tokens' => ['email,created_at', 'ip,created_at'],
    'saved_searches' => ['user_id'],
    'watchlist' => ['domain'],
    'ae_domains' => ['status,checked_at', 'checked_at', 'dropped_on', 'tld'],
];

foreach ($stmts as $s) db()->exec($s);

// Migrations for tables created by older versions
$migrations = [
    'ALTER TABLE auctions ADD COLUMN first_seen DATE NULL',
];
foreach ($migrations as $m) { try { db()->exec($m); } catch (PDOException $e) { /* already applied */ } }
foreach ($indexes as $table => $list) {
    foreach ($list as $cols) {
        $name = 'ix_' . $table . '_' . str_replace(',', '_', $cols);
        try {
            db()->exec($sqlite ? "CREATE INDEX IF NOT EXISTS $name ON $table ($cols)" : "CREATE INDEX $name ON $table ($cols)");
        } catch (PDOException $e) { /* already exists */ }
    }
}
logmsg('Database tables ready.');
