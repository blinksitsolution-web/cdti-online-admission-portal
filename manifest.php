<?php
/**
 * Web app manifest for the registration flow only (PWA "Add to Home Screen").
 * Scoped to /register — not the admin panel, not the whole origin.
 * Icon is generated from whatever logo is currently configured in
 * Admin -> Settings, so it never goes stale when the school updates it.
 */
require_once __DIR__ . '/includes/helpers.php';

header('Content-Type: application/manifest+json');

$s = getSettings();
$schoolName = $s['school_name'] ?? 'CHARLOTTE DOLPHYNE TECHNICAL INSTITUTE';
$logoPath   = !empty($s['school_logo_path']) ? $s['school_logo_path'] : 'assets/img/logo.png';
$logoExt    = strtolower(pathinfo($logoPath, PATHINFO_EXTENSION));
$logoType   = match ($logoExt) {
    'png'         => 'image/png',
    'jpg', 'jpeg' => 'image/jpeg',
    'webp'        => 'image/webp',
    default       => 'image/png',
};

echo json_encode([
    'name'             => $schoolName . ' — Admission Registration',
    'short_name'       => 'Admission',
    'start_url'        => BASE_URL . '/dashboard',
    'scope'            => BASE_URL . '/register',
    'display'          => 'standalone',
    'background_color' => '#0f1e2d',
    'theme_color'      => '#006fa0',
    'icons'            => [
        [
            'src'   => asset($logoPath),
            'sizes' => 'any',
            'type'  => $logoType,
        ],
    ],
], JSON_UNESCAPED_SLASHES);
