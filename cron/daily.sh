#!/usr/bin/env bash
# Runs the daily jobs in order. Add to crontab (see README).
cd "$(dirname "$0")/.." || exit 1
mkdir -p data/logs
php cron/czds_deleted.php   >> data/logs/czds.log 2>&1
php cron/import_godaddy.php >> data/logs/godaddy.log 2>&1
