<?php
declare(strict_types=1);
require_once __DIR__ . '/app/artwork.php';
require_once __DIR__ . '/app/gallery-navigation.php';
header('Cache-Control: no-store');
header('X-Robots-Tag: noindex, follow');

// Search-engine-readable detail pages are created ONLY for art that the
// artist has explicitly published. Drafts and demo art never get pages.
$idText = $_GET['id'] ?? null;
$view=$_GET['view']??'all';$fromPage=$_GET['from_page']??'1';$search=dmo_gallery_search($_GET['q']??'')??'';
if(!is_string($view)||!isset(dmo_gallery_views()[$view]))$view='all';
$fromPage=is_string($fromPage)&&preg_match('/^[1-9][0-9]{0,3}$/D',$fromPage)&&(int)$fromPage<=1000?(int)$fromPage:1;
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
        "SELECT a.id, a.alt_text, a.is_featured,a.sort_order,f.pixel_width, f.pixel_height, f.storage_key,f.mime_type,f.byte_size,f.variant " .
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
    // Use the same stable gallery ordering as the public directory. Only
    // published records with both expected variants may become neighbours.
    // A stale filter must not hide a still-published drawing.
    if(!dmo_gallery_matches($view,$work)){$view='all';$fromPage=1;}
    if($search!==''){
        $match=$db->prepare("SELECT 1 FROM dmo_portfolio_artworks a WHERE a.id=:id AND a.visibility='published'".dmo_gallery_search_condition($search));
        $match->execute(['id'=>(int)$work['id'],'search'=>$search]);
        if($match->fetchColumn()===false)$search='';
    }
    $condition=dmo_gallery_condition($view).dmo_gallery_search_condition($search);
    $selection=" FROM dmo_portfolio_artworks a
            JOIN dmo_artwork_files t ON t.artwork_id=a.id AND t.variant='thumb'
            JOIN dmo_artwork_files d ON d.artwork_id=a.id AND d.variant='display'
            WHERE a.visibility='published' $condition";
    $context=$search===''?[]:['search'=>$search];
    $rankParams=$context+['featured'=>(int)$work['is_featured'],'sort'=>-(int)$work['sort_order'],'id'=>(int)$work['id']];
    $rank=$db->prepare("SELECT COUNT(*)".$selection." AND (a.is_featured,-a.sort_order,a.id) > (:featured,:sort,:id)");
    $rank->execute($rankParams);$position=(int)$rank->fetchColumn()+1;
    $totalQuery=$db->prepare('SELECT COUNT(*)'.$selection);$totalQuery->execute($context);$total=(int)$totalQuery->fetchColumn();
    $fromPage=max(1,min(1000,(int)ceil($position/24)));
    $neighbours=['previous'=>null,'next'=>null];
    foreach(['previous'=>['>','ASC','DESC'],'next'=>['<','DESC','ASC']] as $direction=>[$op,$featureOrder,$sortOrder]){
        $q=$db->prepare("SELECT a.id FROM dmo_portfolio_artworks a
            JOIN dmo_artwork_files t ON t.artwork_id=a.id AND t.variant='thumb'
            JOIN dmo_artwork_files d ON d.artwork_id=a.id AND d.variant='display'
            WHERE a.visibility='published' $condition AND (a.is_featured,-a.sort_order,a.id) $op (:featured,:sort,:id)
            ORDER BY a.is_featured $featureOrder,a.sort_order $sortOrder,a.id $featureOrder LIMIT 1");
        $q->execute($rankParams);
        $adjacent=$q->fetchColumn();
        if($adjacent!==false)$neighbours[$direction]=(int)$adjacent;
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
<meta name="robots" content="noindex,follow">
<meta name="description" content="<?= dmo_artwork_page_escape($description) ?>">
<title><?= dmo_artwork_page_escape($title) ?></title>
<link rel="canonical" href="<?= dmo_artwork_page_escape($canonical) ?>">
<link rel="icon" href="/favicon.svg" type="image/svg+xml">
<meta name="theme-color" content="#141318">
<script src="./public-theme.js?v=2"></script>
<meta property="og:type" content="website">
<meta property="og:site_name" content="Draw Me One">
<meta property="og:title" content="<?= dmo_artwork_page_escape($title) ?>">
<meta property="og:description" content="<?= dmo_artwork_page_escape($description) ?>">
<meta property="og:url" content="<?= dmo_artwork_page_escape($canonical) ?>">
<meta property="og:image" content="<?= dmo_artwork_page_escape($image) ?>">
<meta property="og:image:width" content="<?= $width ?>">
<meta property="og:image:height" content="<?= $height ?>">
<meta property="og:image:alt" content="<?= dmo_artwork_page_escape($label) ?>">
<meta name="twitter:card" content="summary_large_image">
<script type="application/ld+json"><?= $schema ?></script>
<style>
:root{color-scheme:dark;--bg:#151318;--card:#211e24;--ink:#faf3ed;--muted:#c6b8bf;--line:#514550;--accent:#edb9ab}
@media(prefers-color-scheme:light){:root{color-scheme:light;--bg:#faf7f3;--card:#fffdfa;--ink:#242027;--muted:#615662;--line:#d6c9ce;--accent:#783e53}}
:root[data-public-mode="dark"]{color-scheme:dark;--bg:#151318;--ink:#faf3ed;--muted:#c6b8bf;--line:#514550;--accent:#edb9ab;--card:#211e24}:root[data-public-mode="light"]{color-scheme:light;--bg:#faf7f3;--ink:#242027;--muted:#615662;--line:#d6c9ce;--accent:#783e53;--card:#fffdfa}
.public-theme-toggle{font:inherit;color:var(--ink);background:transparent;border:1px solid var(--line);border-radius:6px;min-height:44px;padding:8px 12px;cursor:pointer}.public-theme-toggle:focus-visible{outline:2px solid var(--accent);outline-offset:3px}.public-theme-toggle[hidden]{display:none}
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
.skip{position:absolute;left:12px;top:-100px;padding:12px;background:var(--bg);z-index:3}.skip:focus{top:12px}.artwork-share{display:flex;align-items:center;gap:10px;flex-wrap:wrap;margin:20px 0}.artwork-share button{min-height:44px;padding:8px 14px;font:inherit;color:var(--ink);border:1px solid var(--line);background:var(--card);border-radius:8px;cursor:pointer}.artwork-share button:focus-visible{outline:2px solid var(--accent);outline-offset:3px}.artwork-share button[hidden]{display:none}.artwork-share p{margin:0;color:var(--muted)}.artwork-share details{flex-basis:100%;overflow-wrap:anywhere}.artwork-share summary{cursor:pointer;padding:8px 0}.artwork-share summary:focus-visible{outline:2px solid var(--accent);outline-offset:3px}.artwork-nav{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:10px;margin:22px 0 0}.artwork-nav a,.artwork-nav span{min-height:44px;display:flex;align-items:center;justify-content:center;text-align:center;padding:10px;border:1px solid var(--line);border-radius:8px;overflow-wrap:anywhere}.artwork-nav span{border-color:transparent;color:var(--muted)}
@media(max-width:600px){header{min-height:65px;flex-wrap:wrap;padding-block:12px}.wordmark{font-size:21px}nav{gap:13px}figure{padding:12px}.artwork-nav{gap:6px}.artwork-nav a,.artwork-nav span{font-size:11px;padding:6px}}
header nav a{padding-block:8px}
</style>
</head>
<body>
<a class="skip" href="#main">Skip to drawing</a>
<header>
  <a class="wordmark" href="/" aria-label="Draw Me One homepage">drawmeone<span>.</span></a>
  <nav aria-label="Main navigation"><a href="/portfolio.php">Portfolio</a><a href="/#contact">Contact</a><button type="button" class="public-theme-toggle" data-public-theme hidden>Light mode</button></nav>
</header>
<main id="main" tabindex="-1">
  <span class="eyebrow">FROM THE ARTIST'S PORTFOLIO</span>
  <h1>Original drawing</h1>
  <p class="intro">A piece from the Draw Me One portfolio, published by the artist.</p>
  <figure>
    <img src="<?= dmo_artwork_page_escape('/api/artwork.php?id=' . $artworkId . '&v=display') ?>"
         alt="<?= dmo_artwork_page_escape($label) ?>"
         width="<?= $width ?>" height="<?= $height ?>">
    <figcaption><?= dmo_artwork_page_escape($label) ?></figcaption>
  </figure>
  <div class="artwork-share" aria-label="Share this drawing"><button type="button" data-share-artwork hidden>Share drawing</button><button type="button" data-copy-artwork hidden>Copy drawing link</button><p data-share-status role="status" aria-live="polite"></p><details><summary>Permanent drawing link</summary><a href="<?= dmo_artwork_page_escape($canonical) ?>"><?= dmo_artwork_page_escape($canonical) ?></a></details></div>
  <p class="intro">Drawing <?= $position ?> of <?= max($position,$total) ?><?= $search!==''?' matching your search':'' ?></p>
  <nav class="artwork-nav" aria-label="Browse published drawings">
    <?php if($neighbours['previous']!==null): ?><a rel="prev" href="<?= dmo_artwork_page_escape(dmo_gallery_detail_url($neighbours['previous'],$view,max(1,min(1000,(int)ceil(($position-1)/24))),$search)) ?>">Previous drawing</a><?php else: ?><span>First drawing</span><?php endif; ?>
    <a href="<?= dmo_artwork_page_escape(dmo_directory_url($view,$fromPage,$search)) ?>"><?= $search!==''?'Back to search':($view==='all'?'All drawings':'Back to '.dmo_artwork_page_escape(strtolower(dmo_gallery_views()[$view]))) ?></a>
    <?php if($neighbours['next']!==null): ?><a rel="next" href="<?= dmo_artwork_page_escape(dmo_gallery_detail_url($neighbours['next'],$view,max(1,min(1000,(int)ceil(($position+1)/24))),$search)) ?>">Next drawing</a><?php else: ?><span>Last drawing</span><?php endif; ?>
  </nav>
</main>
<footer><a href="/#portfolio">← Back to the gallery</a><a href="/#contact">Contact</a><span>Draw Me One · Original artwork</span></footer>
</body>
</html>
