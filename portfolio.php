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
$indexable = !$failed && count($rows)>0 && $search==='' && $filter==='all' && $page===1 && count($_GET)===0;
header('X-Robots-Tag: '.($indexable?'index, follow':'noindex, follow'));
function dmo_directory_escape(string $value): string{return htmlspecialchars($value,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}
?>
<!doctype html>
<html lang="en" data-public-mode="dark">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="<?= $indexable?'index,follow':'noindex,follow' ?>">
<meta name="theme-color" content="#151318">
<script src="/public-theme.js?v=4"></script>
<meta name="description" content="Browse original drawings published by the Draw Me One artist.">
<title>Published drawings | Draw Me One</title>
<link rel="icon" href="/favicon.svg" type="image/svg+xml">
<link rel="canonical" href="https://drawmeone.com<?= dmo_directory_escape(dmo_directory_url($filter,$page,$search)) ?>">
<link rel="stylesheet" href="/styles.css?v=208">
</head>
<body class="secondary">
<a class="skip" href="#main">Skip to drawings</a>
<header class="masthead"><a class="wordmark" href="/" aria-label="Draw Me One homepage">drawmeone<span>.</span></a><nav aria-label="Main navigation"><a href="/portfolio.php" aria-current="page">Portfolio</a><a href="/#contact">Contact</a><button type="button" class="public-theme-toggle" data-public-theme hidden>Light mode</button></nav></header>
<main id="main" tabindex="-1">
<h1>Published drawings</h1>
<p class="intro">Select a drawing to see the full image.</p>
<details class="gallery-tools"<?= $filter!=='all'||$search!==''?' open':'' ?>><summary>Search or filter drawings</summary>
<form class="portfolio-search" method="get" action="/portfolio.php" role="search" aria-label="Search published drawings">
<label for="portfolio-query">Search drawing descriptions</label>
<div><?php if($filter!=='all'): ?><input type="hidden" name="view" value="<?= dmo_directory_escape($filter) ?>"><?php endif; ?>
<input id="portfolio-query" name="q" type="search" maxlength="240" value="<?= dmo_directory_escape($search) ?>" placeholder="e.g. portrait, pencil"><button type="submit">Search</button><?php if($search!==''): ?><a href="<?= dmo_directory_escape(dmo_directory_url($filter)) ?>">Clear search</a><?php endif; ?></div>
<p class="intro">Search uses optional artist descriptions; not every drawing has one.</p></form>
<nav class="portfolio-views" aria-label="Filter published drawings"><?php foreach($filters as $key=>$label): ?><a href="<?= dmo_directory_escape(dmo_directory_url($key,1,$search)) ?>"<?= $filter===$key?' aria-current="page"':'' ?>><?= dmo_directory_escape($label) ?></a><?php endforeach; ?></nav></details>
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
