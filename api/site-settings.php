<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/app/bootstrap.php';

dmo_reject_non_get();

try {
    $database = dmo_database();
    $statement = $database->prepare(
        "SELECT settings_json, version_number FROM dmo_site_settings WHERE context = 'published' LIMIT 1"
    );
    $statement->execute();
    $row = $statement->fetch();

    // NEVER return the entire settings JSON: future versions can contain
    // internal administrative configuration. Copy only public-safe keys.
    $defaults = [
        'site_style' => 'studio',
        'color_theme' => 'noir',
        'gallery_layout' => 'masonry',
        'default_mode' => 'dark',
    ];
    $settings = $defaults;
    if (is_array($row)) {
        $raw = json_decode((string) $row['settings_json'], true);
        if (is_array($raw)) {
            $allowed = [
                'site_style' => ['studio', 'archive', 'journal', 'cinema'],
                'color_theme' => ['noir', 'canvas', 'editorial', 'aurora', 'ember'],
                'gallery_layout' => ['masonry', 'mosaic', 'rows'],
                'default_mode' => ['light', 'dark'],
            ];
            foreach ($allowed as $key => $values) {
                if (isset($raw[$key]) &&
                    is_string($raw[$key]) &&
                    in_array($raw[$key], $values, true)) {
                    $settings[$key] = $raw[$key];
                }
            }
        }
    }
    dmo_json(200, [
        'ok' => true,
        'published' => is_array($row),
        'settings' => $settings,
    ]);
} catch (Throwable $error) {
    dmo_log_problem($error, 'published site settings');
    dmo_json(503, [
        'ok' => false,
        'error' => 'service_unavailable',
    ]);
}
