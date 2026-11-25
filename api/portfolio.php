<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/app/artwork.php';
dmo_reject_non_get();

try {
    $db = dmo_database();
    $stmt = $db->query(
        "SELECT a.id, a.alt_text, d.pixel_width, d.pixel_height " .
        "FROM dmo_portfolio_artworks a " .
        "JOIN dmo_artwork_files d ON d.artwork_id = a.id AND d.variant = 'display' " .
        "JOIN dmo_artwork_files t ON t.artwork_id = a.id AND t.variant = 'thumb' " .
        "WHERE a.visibility = 'published' " .
        "ORDER BY a.is_featured DESC, a.sort_order ASC, a.id DESC LIMIT 501"
    );
    $rows=$stmt->fetchAll();
    $hasMore=count($rows)>500;
    $works = [];
    foreach (array_slice($rows,0,500) as $row) {
        $id = (int) $row['id'];
        $works[] = [
            'src' => 'api/artwork.php?id=' . $id . '&v=display',
            'thumb' => 'api/artwork.php?id=' . $id . '&v=thumb',
            'width' => (int) $row['pixel_width'],
            'height' => (int) $row['pixel_height'],
            'alt' => trim((string) ($row['alt_text'] ?: 'Original drawing by Draw Me One')),
        ];
    }
    dmo_json(200, ['ok' => true, 'works' => $works,'has_more'=>$hasMore]);
} catch (Throwable $error) {
    dmo_log_problem($error, 'public portfolio');
    dmo_json(503, ['ok' => false, 'error' => 'portfolio_unavailable']);
}
