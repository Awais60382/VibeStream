<?php
require_once __DIR__ . '/site-branding.php';

header('Content-Type: application/manifest+json; charset=utf-8');
header('Cache-Control: no-cache, must-revalidate');

try {
    $state       = vs_branding_state();
    $name        = vs_branding_app_name($state);
    $description = vs_branding_app_description($state);
    $theme       = vs_branding_default_theme($state);
    $logoFile    = vs_branding_logo_path($state);

    // Changes whenever a new logo is uploaded, so browsers see a "new" icon URL.
    $v = substr(md5((string)($state['logo'] ?? '') . '|' . filemtime($logoFile) . '|' . filesize($logoFile)), 0, 10);

    $icon = function (int $size, bool $maskable) use ($v): array {
        $src = 'branding-icon.php?size=' . $size . ($maskable ? '&maskable=1' : '') . '&v=' . $v;
        return [
            'src'     => $src,
            'sizes'   => $size . 'x' . $size,
            'type'    => 'image/png',
            'purpose' => $maskable ? 'maskable' : 'any',
        ];
    };

    $manifest = [
        'id'               => './',            // keep stable so the browser treats it as the same app
        'name'             => $name,
        'short_name'       => mb_substr($name, 0, 12),
        'description'      => $description,
        'start_url'        => './index.php',
        'scope'            => './',
        'display'          => 'standalone',
        'background_color' => $theme === 'light' ? '#ffffff' : '#0f0f0f',
        'theme_color'      => '#b02a2a',
        'icons'            => [
            $icon(192, false),
            $icon(512, false),
            $icon(192, true),
            $icon(512, true),
        ],
    ];
} catch (Throwable $error) {
    error_log('VibeStream manifest error: ' . $error->getMessage());
    // Safe fallback so the site never ends up without a manifest.
    $manifest = [
        'id'               => './',
        'name'             => VS_DEFAULT_APP_NAME,
        'short_name'       => VS_DEFAULT_APP_NAME,
        'description'      => VS_DEFAULT_APP_DESCRIPTION,
        'start_url'        => './index.php',
        'scope'            => './',
        'display'          => 'standalone',
        'background_color' => '#0f0f0f',
        'theme_color'      => '#b02a2a',
        'icons'            => [[
            'src'     => 'assets/branding/vibestream-logo-mark.png',
            'sizes'   => 'any',
            'type'    => 'image/png',
            'purpose' => 'any',
        ]],
    ];
}

echo json_encode($manifest, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);