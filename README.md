# expired.domacy.com

Domacy Expired Domains: deleted domains, expiring auctions and closeouts, updated daily from free sources.

| Tab | Source | When (UTC) |
|---|---|---|
| Deleted domains | ICANN CZDS zone files (today's zone compared with yesterday's) | 07:30 |
| Expiring auctions / Closeouts | GoDaddy Auctions free inventory files (Semrush authority, age, search volume) | 15:30 |
| Availability | RDAP lookups mark deleted names Available / Taken | every 15 min |

## Deploy (Coolify)
- Build pack: **Dockerfile**, port **80**, domain `https://expired.domacy.com`
- Persistent storage: mount a volume at `/var/www/html/data` (database, zone snapshots, logs). Needs ~20 GB free for .com processing.
- Environment variables:
  - `CZDS_USERNAME`, `CZDS_PASSWORD`: ICANN CZDS login (required for the Deleted tab)
  - `CZDS_ZONES` (optional): comma list of zones to process, default `com,net,org,info,biz,xyz,...`
  - `ALLOW_INDEXING=1` when you want Google to index the site (default: noindex)
  - `MAIN_SITE` (default `https://domacy.com`), `SITE_URL` (default `https://expired.domacy.com`)
- The container runs cron itself (see `docker/crontab`). On first start it imports the GoDaddy files in the background (~2 min).
- Logs: `data/logs/*.log`

## Local
`php cron/install.php && php cron/import_godaddy.php && php -S localhost:8080 -t public`
