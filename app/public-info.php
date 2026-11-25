<?php
declare(strict_types=1);
function dmo_info_headers(): void
{
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: no-store');
    header('X-Robots-Tag: noindex, follow');
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('Referrer-Policy: no-referrer');
    header("Content-Security-Policy: default-src 'none'; style-src 'self'; script-src 'self'; img-src 'self'; base-uri 'none'; frame-ancestors 'none'; form-action 'none'");
    if(($_SERVER['REQUEST_METHOD']??'GET')!=='GET'){http_response_code(405);header('Allow: GET');exit;}
}
function dmo_info_escape(string $value): string
{
    return htmlspecialchars($value,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
}
function dmo_info_head(string $title,string $description): void
{
    $title=dmo_info_escape($title);$description=dmo_info_escape($description);
    echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,follow"><meta name="description" content="'.$description.'"><title>'.$title.' | Draw Me One</title><link rel="icon" href="/favicon.svg" type="image/svg+xml"><link rel="stylesheet" href="/public-info.css?v=3"><meta name="theme-color" content="#151318"><script src="./public-theme.js?v=1"></script></head><body><a class="skip" href="#main">Skip to content</a><header><a href="/" aria-label="Draw Me One homepage">drawmeone<span>.</span></a><nav aria-label="Main navigation"><a href="/portfolio.php">Portfolio</a><a href="/services.php">Services</a><a href="/commission.html">My commissions</a><a href="/account.html">Account</a><button type="button" class="public-theme-toggle" data-public-theme hidden>Light mode</button></nav></header><main id="main" tabindex="-1">';
}
function dmo_info_footer(): void
{
    echo '</main><footer><a href="/">Draw Me One</a><nav aria-label="Footer links"><a href="/policy.php">Terms &amp; privacy</a><a href="mailto:support@drawmeone.com">Email the studio</a></nav></footer></body></html>';
}
