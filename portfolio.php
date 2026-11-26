<?php
declare(strict_types=1);
require_once __DIR__.'/app/artwork.php';
require_once __DIR__.'/app/gallery-navigation.php';
header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store');
header('X-Robots-Tag: noindex, follow');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: strict-origin-when-cross-origin');
if(($_SERVER['REQUEST_METHOD']??'GET')!=='GET'){http_response_code(405);header('Allow: GET');exit;}
$raw=$_GET['page']??'1';
$filter=$_GET['view']??'all';
$filters=dmo_gallery_views();
if(!is_string($filter)||!isset($filters[$filter])){http_response_code(404);echo 'Portfolio view not found.';exit;}
$search=dmo_gallery_search($_GET['q']??'');
if($search===null){http_response_code(422);echo 'Invalid portfolio search.';exit;}
$condition=dmo_gallery_condition($filter).dmo_gallery_search_condition($search);
$params=$search===''?[]:['search'=>$search];
if(!is_string($raw)||!preg_match('/^[1-9][0-9]{0,3}$/D',$raw)||((int)$raw)>1000){
    http_response_code(404);echo 'Portfolio page not found.';exit;
}
$page=(int)$raw;$size=24;$offset=($page-1)*$size;$rows=[];$next=false;$failed=false;$total=0;
try{
    $db=dmo_database();
    $source=" FROM dmo_portfolio_artworks a
        JOIN dmo_artwork_files t ON t.artwork_id=a.id AND t.variant='thumb'
        JOIN dmo_artwork_files d ON d.artwork_id=a.id AND d.variant='display'
        WHERE a.visibility='published' ".$condition;
    $count=$db->prepare('SELECT COUNT(*)'.$source);$count->execute($params);$total=(int)$count->fetchColumn();
    $q=$db->prepare("SELECT a.id,a.alt_text,t.pixel_width,t.pixel_height".$source." ORDER BY a.is_featured DESC,a.sort_order ASC,a.id DESC LIMIT 25 OFFSET ".$offset);
    $q->execute($params);$rows=$q->fetchAll(PDO::FETCH_ASSOC);
    $next=count($rows)>$size&&$page<1000;
    $rows=array_slice($rows,0,$size);
    if(!$rows&&$page>1)http_response_code(404);
}catch(Throwable $e){dmo_log_problem($e,'public portfolio directory');http_response_code(503);$failed=true;}
function dmo_directory_escape(string $value): string{return htmlspecialchars($value,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex,follow">
<meta name="theme-color" content="#151318">
<script src="./public-theme.js?v=2"></script>
<meta name="description" content="Browse original drawings published by the Draw Me One artist.">
<title>Published drawings | Draw Me One</title>
<link rel="icon" href="/favicon.svg" type="image/svg+xml">
<link rel="canonical" href="https://drawmeone.com<?= dmo_directory_escape(dmo_directory_url($filter,$page,$search)) ?>">
<style>
:root{color-scheme:dark;--bg:#151318;--ink:#faf3ed;--muted:#c6b8bf;--line:#514550;--accent:#edb9ab}
@media(prefers-color-scheme:light){:root{color-scheme:light;--bg:#faf7f3;--ink:#242027;--muted:#615662;--line:#d6c9ce;--accent:#783e53}}
:root[data-public-mode="dark"]{color-scheme:dark;--bg:#151318;--ink:#faf3ed;--muted:#c6b8bf;--line:#514550;--accent:#edb9ab}:root[data-public-mode="light"]{color-scheme:light;--bg:#faf7f3;--ink:#242027;--muted:#615662;--line:#d6c9ce;--accent:#783e53}
.public-theme-toggle{font:inherit;color:var(--ink);background:transparent;border:1px solid var(--line);border-radius:6px;min-height:44px;padding:8px 12px;cursor:pointer}.public-theme-toggle:focus-visible{outline:2px solid var(--accent);outline-offset:3px}.public-theme-toggle[hidden]{display:none}
*{box-sizing:border-box}body{margin:0;background:var(--bg);color:var(--ink);font:15px/1.6 system-ui,sans-serif}
header,main,footer{width:min(1200px,calc(100% - 32px));margin:auto}
header,footer{display:flex;align-items:center;justify-content:space-between;gap:16px;flex-wrap:wrap;padding:24px 0;border-bottom:1px solid var(--line)}
a{color:inherit;text-underline-offset:3px}a:focus-visible{outline:2px solid var(--accent);outline-offset:4px}
header>a{font-size:24px;font-weight:800;text-decoration:none;letter-spacing:-.06em}
nav{display:flex;gap:16px;flex-wrap:wrap}.skip{position:absolute;top:-100px;left:12px;padding:12px;background:var(--bg);z-index:3}.skip:focus{top:12px}
main{padding:38px 0 56px}h1{font:normal clamp(32px,5vw,58px)/1.15 Georgia,serif;margin:0 0 12px}.intro{color:var(--muted);max-width:660px}
.works{display:grid;grid-template-columns:repeat(auto-fit,minmax(min(180px,100%),1fr));gap:12px;margin:28px 0}
.works a{display:flex;align-items:center;justify-content:center;border:1px solid var(--line);border-radius:6px;overflow:hidden;min-height:120px}
.works img{width:100%;height:auto;max-height:320px;object-fit:contain;display:block}
.portfolio-views{gap:8px;margin:22px 0}.portfolio-views a{display:inline-flex;align-items:center;min-height:44px;padding:8px 14px;border:1px solid var(--line);border-radius:8px;text-decoration:none}.portfolio-views a[aria-current="page"]{border-color:var(--accent);color:var(--accent);font-weight:700}.pages{align-items:center;justify-content:space-between;margin-top:30px}.pages a{padding:10px 15px;border:1px solid var(--line);border-radius:6px}footer{border-top:1px solid var(--line);border-bottom:0;color:var(--muted)}
.portfolio-search{margin:22px 0}.portfolio-search label{display:block;font-weight:700;margin-bottom:7px}.portfolio-search>div{display:flex;flex-wrap:wrap;gap:8px;align-items:center}.portfolio-search input[type=search]{flex:1 1 200px;min-width:0;max-width:100%;min-height:44px;font:inherit;color:var(--ink);background:var(--bg);border:1px solid var(--line);border-radius:8px;padding:8px 12px}.portfolio-search button{min-height:44px;font:inherit;color:var(--ink);background:var(--bg);border:1px solid var(--line);border-radius:8px;padding:8px 14px;cursor:pointer}.portfolio-search input:focus-visible,.portfolio-search button:focus-visible{outline:2px solid var(--accent);outline-offset:3px}.portfolio-search .intro{font-size:12px;margin:8px 0}
@media(max-width:500px){.works{grid-template-columns:repeat(2,minmax(0,1fr));gap:8px}}
header nav a[aria-current="page"]{font-weight:750;text-decoration-thickness:2px}header nav a{padding-block:8px}
</style>
</head>
<body>
<a class="skip" href="#main">Skip to drawings</a>
<header><a href="/" aria-label="Draw Me One homepage">drawmeone.</a><nav aria-label="Main navigation"><a href="/portfolio.php" aria-current="page">Portfolio</a><a href="/services.php">Services</a><a href="/commission.html">Commissions</a><a href="/account.html">Account</a><button type="button" class="public-theme-toggle" data-public-theme hidden>Light mode</button></nav></header>
<main id="main" tabindex="-1">
<h1>Published drawings</h1>
<p class="intro">Original artwork shared by the artist. Select a drawing to view it individually.</p>
<form class="portfolio-search" method="get" action="/portfolio.php" role="search" aria-label="Search published drawings">
<label for="portfolio-query">Search drawing descriptions</label>
<div><?php if($filter!=='all'): ?><input type="hidden" name="view" value="<?= dmo_directory_escape($filter) ?>"><?php endif; ?>
<input id="portfolio-query" name="q" type="search" maxlength="240" value="<?= dmo_directory_escape($search) ?>" placeholder="e.g. portrait, pencil"><button type="submit">Search</button><?php if($search!==''): ?><a href="<?= dmo_directory_escape(dmo_directory_url($filter)) ?>">Clear search</a><?php endif; ?></div>
<p class="intro">Search uses optional artist descriptions; not every drawing has one.</p></form>
<nav class="portfolio-views" aria-label="Filter published drawings"><?php foreach($filters as $key=>$label): ?><a href="<?= dmo_directory_escape(dmo_directory_url($key,1,$search)) ?>"<?= $filter===$key?' aria-current="page"':'' ?>><?= dmo_directory_escape($label) ?></a><?php endforeach; ?></nav>
<?php if($failed): ?>
<p role="status">The portfolio is temporarily unavailable. Please check back soon.</p>
<?php elseif(!$rows): ?>
<p><?= $page>1?'No drawings on this page.':($search!==''?'No published drawings match this search. Try another word or clear the search.':($filter==='all'?'Original drawings will appear here when published.':'No published drawings match this view yet.')) ?></p>
<?php else: ?>
<p class="intro" role="status">Showing <?= $offset+1 ?>–<?= $offset+count($rows) ?> of <?= $total ?> published drawing<?= $total===1?'':'s' ?></p>
<div class="works">
<?php foreach($rows as $row):
    $id=(int)$row['id'];$alt=trim((string)($row['alt_text']??''));if($alt==='')$alt='Original drawing by Draw Me One';
?>
<a href="<?= dmo_directory_escape(dmo_gallery_detail_url($id,$filter,$page,$search)) ?>"><img src="/api/artwork.php?id=<?= $id ?>&amp;v=thumb" alt="<?= dmo_directory_escape($alt) ?>" width="<?= max(1,(int)$row['pixel_width']) ?>" height="<?= max(1,(int)$row['pixel_height']) ?>" loading="<?= $row===$rows[0]?'eager':'lazy' ?>"<?= $row===$rows[0]?' fetchpriority="high"':'' ?> decoding="async"></a>
<?php endforeach; ?>
</div>
<?php endif; ?>
<?php if(!$failed&&($page>1||$next)): ?>
<nav class="pages" aria-label="Portfolio pages">
<?php if($page>1): ?><a href="<?= dmo_directory_escape(dmo_directory_url($filter,$page-1,$search)) ?>">Previous page</a><?php endif; ?>
<span>Page <?= $page ?></span>
<?php if($next): ?><a href="<?= dmo_directory_escape(dmo_directory_url($filter,$page+1,$search)) ?>">Next page</a><?php endif; ?>
</nav>
<?php endif; ?>
</main>
<footer><a href="/#portfolio">Back to the gallery</a><span>Draw Me One · Original artwork</span></footer>
</body>
</html>
