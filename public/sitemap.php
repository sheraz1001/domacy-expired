<?php
require __DIR__ . '/../lib/bootstrap.php';
require __DIR__ . '/../lib/search.php';
require __DIR__ . '/../lib/seo.php';
header('Content-Type: application/xml; charset=utf-8');
header('Cache-Control: public, max-age=3600');
$today = gmdate('Y-m-d');
$urls = [];
foreach (array_keys(TABS) as $tab) {
    $urls[] = [seo_url($tab), $tab === 'deleted' ? '1.0' : '0.9'];
    foreach (array_slice(top_tlds($tab), 0, SEO_TLD_PAGES, true) as $tld => $c) $urls[] = [seo_url($tab, (string)$tld), '0.7'];
}
echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n" . '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
foreach ($urls as [$loc, $pri]) echo '  <url><loc>' . htmlspecialchars($loc, ENT_XML1) . "</loc><lastmod>$today</lastmod><changefreq>daily</changefreq><priority>$pri</priority></url>\n";
echo "</urlset>\n";
