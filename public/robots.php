<?php
require __DIR__ . '/../lib/bootstrap.php';
header('Content-Type: text/plain; charset=utf-8');
header('Cache-Control: public, max-age=3600');
if (!config('allow_indexing')) { echo "User-agent: *\nDisallow: /\n"; exit; }
$site = config('site_url');
echo "User-agent: *\nAllow: /\nDisallow: /*export=\nDisallow: /*page=\nDisallow: /*sort=\n\nSitemap: $site/sitemap.xml\n";
