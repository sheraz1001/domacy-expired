#!/bin/sh
set -e
cd /var/www/html
mkdir -p data/logs data/cache data/zones data/godaddy data/sessions
cp -n docker/data.htaccess data/.htaccess 2>/dev/null || true

# Make settings from Coolify visible to cron jobs (cron starts with an empty environment)
php -r '$k=["SITE_NAME","SITE_URL","MAIN_SITE","ALLOW_INDEXING","DB_DSN","DB_USER","DB_PASS","DATA_DIR","CZDS_USERNAME","CZDS_PASSWORD","CZDS_ZONES","RDAP_PER_RUN","RDAP_DELAY_MS","DELETED_KEEP_DAYS","RDAP_PARALLEL","AE_WHOIS_HOST","AE_WHOIS_PER_RUN","AE_WHOIS_DELAY_MS","AE_CRT_QUERIES_PER_RUN","SMTP_HOST","SMTP_PORT","SMTP_SECURE","SMTP_USER","SMTP_PASS","MAIL_FROM","MAIL_FROM_NAME"];$o=[];foreach($k as $x){$v=getenv($x);if($v!==false)$o[$x]=$v;}file_put_contents("data/env.json",json_encode($o));'
chmod 600 data/env.json
chown -R www-data:www-data data

su -s /bin/sh www-data -c "php cron/install.php"

# First start: load GoDaddy data in the background so the site has listings straight away
if [ ! -f data/.first_import_done ]; then
  su -s /bin/sh www-data -c "flock -n /tmp/godaddy.lock php cron/import_godaddy.php >> data/logs/godaddy.log 2>&1 && touch data/.first_import_done" &
fi

# If ICANN login is set, take today's zone snapshots now (skips zones already done today),
# so the first "Deleted" list appears one day sooner after a fresh deploy.
if grep -q '"CZDS_PASSWORD"' data/env.json; then
  ( sleep 120; su -s /bin/sh www-data -c "flock -n /tmp/czds.lock php cron/czds_deleted.php >> data/logs/czds.log 2>&1" ) &
fi

# Start collecting .ae names straight away
( sleep 60; su -s /bin/sh www-data -c "flock -n /tmp/ae_seed.lock php cron/ae_seed.php >> data/logs/ae.log 2>&1" ) &

cron
exec apache2-foreground
