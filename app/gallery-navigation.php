<?php
declare(strict_types=1);
/** Fixed public portfolio views; never accept SQL fragments from a visitor. */
function dmo_gallery_views(): array{return ['all'=>'All drawings','featured'=>'Featured','portrait'=>'Portrait','landscape'=>'Landscape','square'=>'Square'];}
function dmo_gallery_condition(string $view): string{return match($view){'featured'=>' AND a.is_featured=1','portrait'=>' AND d.pixel_height>d.pixel_width','landscape'=>' AND d.pixel_width>d.pixel_height','square'=>' AND d.pixel_width=d.pixel_height',default=>''};}
function dmo_gallery_matches(string $view,array $work): bool{
    $w=(int)$work['pixel_width'];$h=(int)$work['pixel_height'];
    return match($view){'featured'=>(bool)$work['is_featured'],'portrait'=>$h>$w,'landscape'=>$w>$h,'square'=>$w===$h,default=>true};
}
/** Optional literal search of published accessibility descriptions. */
function dmo_gallery_search(mixed $raw): ?string {
    if(!is_string($raw)||strlen($raw)>240||preg_match('//u',$raw)!==1||preg_match('/[\x00-\x1f\x7f]/',$raw))return null;
    return trim($raw);
}
function dmo_gallery_search_condition(string $search): string{return $search===''?'':' AND LOCATE(:search,COALESCE(a.alt_text,\'\'))>0';}
function dmo_directory_url(string $filter,int $page=1,string $search=''): string{
    $params=[];if($filter!=='all')$params['view']=$filter;if($search!=='')$params['q']=$search;if($page>1)$params['page']=$page;
    return '/portfolio.php'.($params?'?'.http_build_query($params):'');
}
function dmo_gallery_detail_url(int $id,string $view='all',int $page=1,string $search=''): string{
    return '/artwork.php?'.http_build_query(['id'=>$id]+($view!=='all'?['view'=>$view]:[])+($search!==''?['q'=>$search]:[])+($page>1?['from_page'=>$page]:[]));
}
