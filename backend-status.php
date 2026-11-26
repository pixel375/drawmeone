<?php
declare(strict_types=1);
// Old setup UI retired; use authenticated diagnostics or SSH CLI instead.
header('Cache-Control: no-store');
header('X-Robots-Tag: noindex, nofollow');
header('Location: /studio-admin/index.php', true, 302);
exit;
