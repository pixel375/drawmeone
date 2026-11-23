<?php
declare(strict_types=1);

// Public DNS only; outputs no addresses, credentials, account or client data.
if (PHP_SAPI !== 'cli') exit(2);
$domain = 'drawmeone.com';
$dns = static function (string $name, int $type): array {
    $rows = dns_get_record($name, $type);
    return is_array($rows) ? $rows : [];
};
$mx = [];
foreach ($dns($domain, DNS_MX) as $record) {
    $host = strtolower((string) ($record['target'] ?? ''));
    if (preg_match('/^[a-z0-9.-]{1,253}$/D', $host)) $mx[] = $host;
}
sort($mx);
echo 'mx_host_count=' . count($mx) . "\n";
echo 'mx_targets=' . ($mx ? implode(',', array_unique($mx)) : 'none') . "\n";

$spf = [];
foreach ($dns($domain, DNS_TXT) as $record) {
    $txt = trim((string) ($record['txt'] ?? ''));
    if (stripos($txt, 'v=spf1') === 0) $spf[] = strtolower($txt);
}
echo 'spf_record_count=' . count($spf) . "\n";
echo 'spf_hostinger_include=' .
    (count(array_filter($spf, static fn($v) =>
        str_contains($v, '_spf.mail.hostinger.com'))) > 0 ? 'yes' : 'no') . "\n";
echo 'spf_titan_include=' .
    (count(array_filter($spf, static fn($v) =>
        str_contains($v, 'spf.titan.email'))) > 0 ? 'yes' : 'no') . "\n";

$dmarc = [];
foreach ($dns('_dmarc.' . $domain, DNS_TXT) as $record) {
    $txt = trim((string) ($record['txt'] ?? ''));
    if (stripos($txt, 'v=DMARC1') === 0) $dmarc[] = $txt;
}
echo 'dmarc_record_count=' . count($dmarc) . "\n";
$policy = 'unavailable';
if (count($dmarc) === 1 && preg_match('/(?:^|;)\s*p\s*=\s*(none|quarantine|reject)(?:;|$)/i',$dmarc[0],$m)) {
    $policy = strtolower($m[1]);
}
echo 'dmarc_policy=' . $policy . "\n";

$selectors = ['hostingermail-a','hostingermail-b','hostingermail-c','default','selector1','selector2','titan'];
foreach ($selectors as $selector) {
    $key = $selector . '._domainkey.' . $domain;
    $rows = $dns($key, DNS_CNAME) ?: $dns($key, DNS_TXT);
    echo 'dkim_selector_' . $selector . '=' . ($rows ? 'present' : 'not_found') . "\n";
}
echo "MAIL_DNS_PUBLIC_CHECK_COMPLETED\n";
