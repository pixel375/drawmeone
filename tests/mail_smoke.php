<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/app/mailer.php';

// Runs locally on Hostinger through the read-only SSH workflow.
// Does not contact SMTP, create accounts, or print secret configuration.
if (PHP_SAPI !== 'cli') exit(2);
$message = dmo_mail_message(
    'test@example.org',
    'Email transport smoke test',
    "Line one\nLine two\n",
    'no-reply@drawmeone.com'
);
$checks = [
    str_contains($message, "From: Draw Me One <no-reply@drawmeone.com>\r\n"),
    str_contains($message, "To: <test@example.org>\r\n"),
    str_contains($message, "Subject: Email transport smoke test\r\n"),
    str_contains($message, "Content-Type: text/plain; charset=UTF-8\r\n"),
    str_contains($message, "\r\n\r\nLine one\r\nLine two\r\n"),
    preg_match('/Message-ID: <[a-f0-9]{24}@drawmeone\.com>\r\n/', $message) === 1,
    preg_match('/(?<!\r)\n/', $message) !== 1,
];
if (in_array(false, $checks, true)) {
    fwrite(STDERR, "MAIL_FORMAT_SMOKE=FAIL\n");
    exit(1);
}
require_once dirname(__DIR__).'/app/notifications.php';
foreach(['quote','order','message','proof','delivery'] as $kind){
    [$subject,$body]=dmo_notification_template($kind);
    if(!str_starts_with($subject,'Draw Me One - ')||!str_contains($body,'private Draw Me One account')||str_contains($body,'
)){
        fwrite(STDERR,"NOTICE_FORMAT_SMOKE=FAIL\n");exit(1);
    }
}
echo "MAIL_FORMAT_SMOKE=PASS\n";
