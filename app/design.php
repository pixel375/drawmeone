<?php
declare(strict_types=1);
require_once __DIR__ . '/admin.php';

function dmo_design_defaults(): array
{
    return [
        'site_style' => 'studio',
        'color_theme' => 'noir',
        'gallery_layout' => 'masonry',
        'default_mode' => 'dark',
        'logo' => 'original',
    ];
}

function dmo_design_normalize(mixed $raw): ?array
{
    if (!is_array($raw) || array_is_list($raw)) {
        return null;
    }
    $allowed = [
        // Only Studio is an approved launch design. The other three
        // abandoned alternatives remain development-only previews.
        'site_style' => ['studio'],
        'color_theme' => ['noir', 'canvas', 'editorial', 'aurora', 'ember'],
        'gallery_layout' => ['masonry', 'mosaic', 'rows'],
        'default_mode' => ['dark', 'light'],
        'logo' => ['original', '01-minimal-pencil', '02-signature',
            '03-dmo-monogram', '04-portrait-seal', '05-editorial',
            '06-brushstroke', '07-portrait', '08-paintbrush-badge',
            '09-pen-lettering', '10-creative-palette'],
    ];
    // Explicitly reject unrecognized keys so secrets or dangerous
    // content cannot be stored in what will become a public document.
    foreach ($raw as $key => $value) {
        if (!is_string($key) || !isset($allowed[$key])) {
            return null;
        }
        if (!is_string($value) || !in_array($value, $allowed[$key], true)) {
            return null;
        }
    }
    return array_replace(dmo_design_defaults(), $raw);
}

function dmo_design_load(PDO $db, string $context): array
{
    $stmt = $db->prepare(
        'SELECT version_number, settings_json, updated_at FROM dmo_site_settings ' .
        'WHERE context = :context LIMIT 1'
    );
    $stmt->execute(['context' => $context]);
    $row = $stmt->fetch();
    if (!is_array($row)) {
        return [
            'exists' => false,
            'version' => 0,
            'settings' => dmo_design_defaults(),
            'updated_at' => null,
        ];
    }
    $decoded = json_decode((string) $row['settings_json'], true);
    $settings = dmo_design_normalize($decoded);
    if ($settings === null) {
        throw new RuntimeException('Site settings row has an invalid schema.');
    }
    return [
        'exists' => true,
        'version' => (int) $row['version_number'],
        'settings' => $settings,
        'updated_at' => $row['updated_at'],
    ];
}

function dmo_design_require_write_flag(): void
{
    if (dmo_config('DMO_DESIGN_WRITES_ENABLED') !== '1') {
        dmo_json(503, ['ok' => false, 'error' => 'design_editing_disabled']);
    }
}
