<?php
declare(strict_types=1);
require_once __DIR__ . '/app/artwork.php';
header('Cache-Control: no-store');

// Search-engine-readable detail pages are created ONLY for art that the
// artist has explicitly published. Drafts and demo art never get pages.
$idText = $_GET['id'] ?? null;
if (!is_string($idText) || !preg_match('/^[1-9][0-9]{0,17}$/D', $idText) ||
    ($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    http_response_code(404);
    header('X-Robots-Tag: noindex');
    echo 'Drawing not found.';
    exit;
}
try {
    $db = dmo_database();
    $query = $db->prepare(
        "SELECT a.id, a.alt_text, f.pixel_width, f.pixel_height, f.storage_key,f.mime_type,f.byte_size,f.variant " .
        "FROM dmo_portfolio_artworks a JOIN dmo_artwork_files f " .
        "ON f.artwork_id = a.id AND f.variant = 'display' " .
        "WHERE a.id = :id AND a.visibility = 'published' LIMIT 1"
    );
    $query->execute(['id' => (int) $idText]);
    $work = $query->fetch(PDO::FETCH_ASSOC);
    if (!is_array($work) ||
        !dmo_artwork_variant_valid($work)) {
        http_response_code(404);
        header('X-Robots-Tag: noindex');
        echo 'Drawing not found.';
        exit;
    }
} catch (Throwable $error) {
    dmo_log_problem($error, 'public artwork detail');
    http_response_code(503);
    header('Cache-Control: no-store');
    echo 'The portfolio is temporarily unavailable.';
    exit;
}

function dmo_artwork_page_escape(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
$label = trim((string) ($work['alt_text'] ?? ''));
if ($label === '') $label = 'Original drawing by Draw Me One';
$label = function_exists('mb_substr')
    ? mb_substr($label, 0, 260, 'UTF-8')
    : substr($label, 0, 260);
$artworkId = (int) $work['id'];
$width = max(1, (int) $work['pixel_width']);
$height = max(1, (int) $work['pixel_height']);
$canonical = 'https://drawmeone.com/artwork.php?id=' . $artworkId;
$image = 'https://drawmeone.com/api/artwork.php?id=' . $artworkId . '&v=display';
$title = 'Original drawing — Draw Me One';
$description = 'View an original drawing published in the Draw Me One artist portfolio.';
$schema = json_encode([
    '@context' => 'https://schema.org',
    '@type' => 'ImageObject',
    'name' => $label,
    'url' => $canonical,
    'contentUrl' => $image,
    'width' => $width,
    'height' => $height,
    'creator' => [
        '@type' => 'Organization',
        'name' => 'Draw Me One',
        'url' => 'https://drawmeone.com/',
    ],
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE |
   JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: public, no-cache, must-revalidate');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: strict-origin-when-cross-origin');
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="index,follow,max-image-preview:large">
<meta name="description" content="<?= dmo_artwork_page_escape($description) ?>">
<title><?= dmo_artwork_page_escape($title) ?></title>
<link rel="canonical" href="<?= dmo_artwork_page_escape($canonical) ?>">
<link rel="icon" href="/favicon.svg" type="image/svg+xml">
<meta name="theme-color" content="#141318">
<meta property="og:type" content="website">
<meta property="og:site_name" content="Draw Me One">
<meta property="og:title" content="<?= dmo_artwork_page_escape($title) ?>">
<meta property="og:description" content="<?= dmo_artwork_page_escape($description) ?>">
<meta property="og:url" content="<?= dmo_artwork_page_escape($canonical) ?>">
<meta property="og:image" content="<?= dmo_artwork_page_escape($image) ?>">
<meta name="twitter:card" content="summary_large_image">
<script type="application/ld+json"><?= $schema ?></script>
<style>
:root{color-scheme:dark;--bg:#151318;--card:#211e24;--ink:#faf3ed;--muted:#c6b8bf;--line:#514550;--accent:#edb9ab}
*{box-sizing:border-box}
body{margin:0;background:radial-gradient(ellipse at 65% -10%,rgba(237,185,171,.1),transparent 65%),var(--bg);color:var(--ink);font:14px/1.65 system-ui,-apple-system,Segoe UI,sans-serif;min-height:100vh}
a{color:inherit;text-decoration:none}
a:hover{text-decoration:underline}
a:focus-visible{outline:2px solid var(--accent);outline-offset:4px}
header,footer{width:min(1200px,100% - 38px);margin:auto;display:flex;align-items:center;justify-content:space-between;gap:18px}
header{min-height:78px;border-bottom:1px solid var(--line)}
.wordmark{font-weight:900;font-size:24px;letter-spacing:-.07em}
.wordmark span{color:var(--accent)}
nav{display:flex;flex-wrap:wrap;gap:22px;font-size:12px;font-weight:700;color:var(--muted)}
main{width:min(1100px,100% - 38px);margin:clamp(25px,5vw,65px) auto}
.eyebrow{font-size:10px;letter-spacing:.15em;font-weight:900;color:var(--accent)}
h1{font:normal clamp(29px,5vw,48px)/1.12 Georgia,serif;letter-spacing:-.05em;margin:12px 0}
.intro{max-width:690px;color:var(--muted)}
figure{margin:28px 0 0;padding:clamp(12px,2.7vw,27px);background:var(--card);border:1px solid var(--line);border-radius:14px;text-align:center}
figure img{display:block;max-width:100%;max-height:min(80dvh,1100px);width:auto;height:auto;object-fit:contain;margin:0 auto;border-radius:3px}
figcaption{color:var(--muted);font-size:12px;text-align:left;margin-top:18px;overflow-wrap:anywhere}
footer{padding:24px 0 45px;border-top:1px solid var(--line);font-size:12px;color:var(--muted);flex-wrap:wrap}
@media(max-width:600px){header{min-height:65px}.wordmark{font-size:21px}nav{gap:13px}figure{padding:12px}}
</style>
</head>
<body>
<header>
  <a class="wordmark" href="/" aria-label="Draw Me One homepage">drawmeone<span>.</span></a>
  <nav aria-label="Main navigation"><a href="/#portfolio">All drawings</a><a href="/commission.html">Commissions</a></nav>
</header>
<main>
  <span class="eyebrow">FROM THE ARTIST'S PORTFOLIO</span>
  <h1>Original drawing</h1>
  <p class="intro">A piece from the Draw Me One portfolio, published by the artist.</p>
  <figure>
    <img src="<?= dmo_artwork_page_escape('/api/artwork.php?id=' . $artworkId . '&v=display') ?>"
         alt="<?= dmo_artwork_page_escape($label) ?>"
         width="<?= $width ?>" height="<?= $height ?>">
    <figcaption><?= dmo_artwork_page_escape($label) ?></figcaption>
  </figure>
</main>
<footer><a href="/#portfolio">← Back to the gallery</a><a href="/services.php">Commission services</a><span>Draw Me One · Original artwork</span></footer>
</body>
</html>
