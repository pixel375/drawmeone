<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

/**
 * First-party, dependency-free SMTP transport for a small set of
 * transactional messages (signup confirmation, password reset, admin test).
 * No arbitrary HTML, attachments, CC/BCC or user-selected recipient.
 */
function dmo_mail_from(): string
{
    $from = trim(dmo_config('DMO_MAIL_FROM'));
    return filter_var($from, FILTER_VALIDATE_EMAIL) !== false &&
        str_ends_with(strtolower($from), '@drawmeone.com') &&
        !preg_match('/[\r\n\x00-\x1f\x7f]/', $from) ? $from : '';
}

function dmo_mail_transport(): string
{
    return strtolower(trim(dmo_config('DMO_MAIL_TRANSPORT')));
}

function dmo_mail_smtp_secret(): string
{
    $encoded = dmo_config('DMO_SMTP_PASSWORD_B64');
    if ($encoded !== '') {
        $secret = base64_decode($encoded, true);
        return is_string($secret) ? $secret : '';
    }
    return dmo_config('DMO_SMTP_PASSWORD');
}

function dmo_mail_smtp_ready(): bool
{
    $host = dmo_config('DMO_SMTP_HOST');
    $port = dmo_config('DMO_SMTP_PORT');
    $user = dmo_config('DMO_SMTP_USER');
    $password = dmo_mail_smtp_secret();
    // Accept an ordinary DNS hostname, never a URL, IP literal or userinfo.
    // Port 465 uses implicit TLS; 587 requires STARTTLS.
    return extension_loaded('curl') &&
        preg_match('/^[a-z0-9](?:[a-z0-9.-]{0,251}[a-z0-9])?$/iD', $host) === 1 &&
        str_contains($host, '.') && !str_contains($host, '..') &&
        in_array($port, ['465','587'], true) &&
        filter_var($user, FILTER_VALIDATE_EMAIL) !== false &&
        !preg_match('/[\r\n\x00-\x1f\x7f]/', $user) &&
        strcasecmp($user, dmo_mail_from()) === 0 &&
        strlen($password) >= 8 && strlen($password) < 1024;
}

function dmo_mail_ready(): bool
{
    if (dmo_config('DMO_MAIL_ENABLED') !== '1' || dmo_mail_from() === '') {
        return false;
    }
    // Require a deliberate transport selection, not a silent mail() fallback.
    return match (dmo_mail_transport()) {
        'smtp' => dmo_mail_smtp_ready(),
        // Explicit legacy option. PHP's mail() does not guarantee delivery.
        'php' => dmo_config('DMO_ALLOW_PHP_MAIL') === '1' && function_exists('mail'),
        default => false,
    };
}

function dmo_mail_message(string $recipient, string $subject, string $content, string $from): string
{
    $id = bin2hex(random_bytes(12));
    // Headers never contain any untrusted or CR/LF-bearing values.
    $headers = [
        'Date: ' . gmdate('D, d M Y H:i:s') . ' +0000',
        'From: Draw Me One <' . $from . '>',
        'To: <' . $recipient . '>',
        'Subject: ' . $subject,
        'Message-ID: <' . $id . '@drawmeone.com>',
        'MIME-Version: 1.0',
        'Content-Type: text/plain; charset=UTF-8',
        'Content-Transfer-Encoding: 8bit',
        'Auto-Submitted: auto-generated',
    ];
    $clean = str_replace(["\r\n", "\r"], "\n", $content);
    $clean = str_replace("\n", "\r\n", $clean);
    return implode("\r\n", $headers) . "\r\n\r\n" .
        rtrim($clean, "\r\n") . "\r\n";
}

function dmo_mail_send(string $recipient, string $subject, string $content, ?array &$diagnostic = null): bool
{
    $from = dmo_mail_from();
    if (!dmo_mail_ready() || $from === '' ||
        filter_var($recipient, FILTER_VALIDATE_EMAIL) === false ||
        strlen($subject) < 1 || strlen($subject) > 150 ||
        strlen($content) < 1 || strlen($content) > 12000 ||
        preg_match('/[^\x20-\x7e]/', $subject) ||
        preg_match('/[\r\n\x00-\x1f\x7f]/', $recipient) ||
        preg_match('//u', $content) !== 1) {
        return false;
    }

    if (dmo_mail_transport() === 'php') {
        return mail($recipient, $subject, $content, [
            'From' => 'Draw Me One <' . $from . '>',
            'Reply-To' => $from,
            'MIME-Version' => '1.0',
            'Content-Type' => 'text/plain; charset=UTF-8',
            'Auto-Submitted' => 'auto-generated',
        ]);
    }

    $host = dmo_config('DMO_SMTP_HOST');
    $port = dmo_config('DMO_SMTP_PORT');
    $user = dmo_config('DMO_SMTP_USER');
    $password = dmo_mail_smtp_secret();
    $payload = dmo_mail_message($recipient, $subject, $content, $from);
    $offset = 0;
    $handle = curl_init();
    if ($handle === false) return false;
    $scheme = $port === '465' ? 'smtps' : 'smtp';
    curl_setopt_array($handle, [
        CURLOPT_URL => $scheme . '://' . $host . ':' . $port,
        CURLOPT_USERNAME => $user,
        CURLOPT_PASSWORD => $password,
        CURLOPT_MAIL_FROM => '<' . $from . '>',
        CURLOPT_MAIL_RCPT => ['<' . $recipient . '>'],
        CURLOPT_USE_SSL => CURLUSESSL_ALL,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_TIMEOUT => 25,
        CURLOPT_UPLOAD => true,
        CURLOPT_INFILESIZE => strlen($payload),
        CURLOPT_READFUNCTION => static function ($curl, $stream, int $length) use (
            $payload, &$offset
        ): string {
            $chunk = substr($payload, $offset, $length);
            $offset += strlen($chunk);
            return $chunk;
        },
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_VERBOSE => false,
    ]);
    $result = curl_exec($handle);
    $errorNumber = curl_errno($handle);
    // For SMTP, libcurl exposes the last SMTP server reply code as well.
    $replyCode = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
    curl_close($handle);
    $accepted = $result !== false &&
        in_array($replyCode, [250, 251], true);
    // Expose safe numeric diagnostic codes, never SMTP server text,
    // message bytes, credentials, message IDs or recipient addresses.
    $diagnostic = [
        'curl_code' => $errorNumber,
        'smtp_code' => $replyCode,
        'accepted' => $accepted,
    ];
    if (!$accepted) {
        error_log('[DrawMeOne] Transactional SMTP not accepted: curl=' .
            $errorNumber . ', smtp=' . $replyCode);
        return false;
    }
    return true;
}
