<?php
declare(strict_types=1);
/** Fixed public portfolio views; never accept SQL fragments from a visitor. */
function dmo_gallery_views(): array{return ['all'=>'All drawings','featured'=>'Featured','portrait'=>'Portrait','landscape'=>'Landscape','square'=>'Square'];}
function dmo_gallery_condition(string $view): string{return match($view){'featured'=>' AND a.is_featured=1','portrait'=>' AND d.pixel_height>d.pixel_width','landscape'=>' AND d.pixel_width>d.pixel_height','square'=>' AND d.pixel_width=d.pixel_height',default=>''};}
function dmo_gallery_matches(string $view,array $work): bool{
    $w=(int)$work['pixel_width'];$h=(int)$work['pixel_height'];
    return match($view){'featured'=>(bool)$work['is_featured'],'portrait'=>$h>$w,'landscape'=>$w>$h,'square'=>$w===$h,default=>true};
}
function dmo_directory_url(string $filter,int $page=1): string{$params=[];if($filter!=='all')$params['view']=$filter;if($page>1)$params['page']=$page;return '/portfolio.php'.($params?'?'.http_build_query($params):'');}
function dmo_gallery_detail_url(int $id,string $view='all',int $page=1): string{
    return '/artwork.php?'.http_build_query(['id'=>$id]+($view!=='all'?['view'=>$view]:[])+($page>1?['from_page'=>$page]:[]));
}
