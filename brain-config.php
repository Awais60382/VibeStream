<?php
/*
 * Gives brain.js the Brain Meter settings from Settings > Brain Meter.
 * Put this file next to brain.js and site-branding.php (the site root).
 */
require_once __DIR__ . '/site-branding.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: public, max-age=60');
try {
    echo json_encode(vs_brain_meter(), JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    echo '{}'; // brain.js then simply uses its built-in defaults
}
