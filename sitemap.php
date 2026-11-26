<?php
declare(strict_types=1);
require_once __DIR__ . '/app/bootstrap.php';

// Crawlable sitemap includes public portfolio entries only.
// Never exposes drafts, private references, messages or customer accounts.
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    http_response_code(405);
    header('Allow: GET');
    exit;
}
try {
    $stmt = dmo_database()->query(
        "SELECT a.id FROM dmo_portfolio_artworks a " .
        "JOIN dmo_artwork_files f ON f.artwork_id = a.id AND f.variant = 'display' " .
        "JOIN dmo_artwork_files t ON t.artwork_id=a.id AND t.variant='thumb' " .
        "WHERE a.visibility = 'published' " .
        "ORDER BY a.id DESC LIMIT 2000"
    );
    $ids = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $id = (int) $row['id'];
        if ($id > 0) $ids[] = $id;
    }
} catch (Throwable $error) {
    dmo_log_problem($error, 'public dynamic sitemap');
    http_response_code(503);
    header('Cache-Control: no-store');
    exit;
}
header('Content-Type: application/xml; charset=utf-8');
header('X-Robots-Tag: noindex');
header('Cache-Control: public, no-cache, must-revalidate');
header('X-Content-Type-Options: nosniff');
echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" ' .
    'xmlns:image="http://www.google.com/schemas/sitemap-image/1.1">' . "\n";
echo "  <url><loc>https://drawmeone.com/</loc></url>\n";
foreach ($ids as $id) {
    echo '  <url><loc>https://drawmeone.com/artwork.php?id=' . $id . '</loc>';
    echo '<image:image><image:loc>https://drawmeone.com/api/artwork.php?id=' .
        $id . '&amp;v=display</image:loc></image:image></url>' . "\n";
}
echo "</urlset>\n";
