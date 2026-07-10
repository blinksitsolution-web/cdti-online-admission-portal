<?php
/**
 * secrets.php — Encrypt/decrypt sensitive API credentials stored in system_settings.
 *
 * Encryption key resolution order (first found wins):
 *   1. KIMTECH_SECRET_KEY environment variable  (recommended for production)
 *   2. /home/{user}/.kimtech_secret_key file    (Hostinger: outside public_html)
 *   3. APP_ROOT/../.kimtech_secret_key          (one level above web root)
 *   4. APP_ROOT/.kimtech_secret_key             (fallback — still outside uploads/)
 *
 * Generate a key once and store it:
 *   php -r "echo base64_encode(random_bytes(32)) . PHP_EOL;"
 *
 * Keys that are encrypted in the DB are stored as:  enc::<base64(iv.tag.ciphertext)>
 * Plain values (legacy or empty) are returned as-is so existing installs keep working.
 */

if (!defined('APP_ROOT')) {
    define('APP_ROOT', dirname(__DIR__));
}

// ── Resolve encryption key ────────────────────────────────────────────────────
function _secretsGetKey(): string
{
    static $key = null;
    if ($key !== null) {
        return $key;
    }

    // 1. Environment variable
    $env = getenv('KIMTECH_SECRET_KEY');
    if ($env !== false && strlen($env) > 0) {
        $decoded = base64_decode($env, true);
        if ($decoded !== false && strlen($decoded) === 32) {
            return $key = $decoded;
        }
    }

    // 2. File outside public_html (Hostinger: /home/user/.kimtech_secret_key)
    $candidates = [
        '/home/' . (get_current_user() ?: 'user') . '/.kimtech_secret_key',
        dirname(APP_ROOT) . DIRECTORY_SEPARATOR . '.kimtech_secret_key',
        APP_ROOT . DIRECTORY_SEPARATOR . '.kimtech_secret_key',
    ];
    foreach ($candidates as $path) {
        if (is_readable($path)) {
            $raw = trim(file_get_contents($path));
            $decoded = base64_decode($raw, true);
            if ($decoded !== false && strlen($decoded) === 32) {
                return $key = $decoded;
            }
        }
    }

    // No key found — return empty string; callers treat this as "encryption unavailable"
    return $key = '';
}

/**
 * Encrypt a plaintext secret for storage in the DB.
 * Returns the original value unchanged if no encryption key is available,
 * so the system degrades gracefully rather than breaking.
 */
function encryptSecret(string $plaintext): string
{
    if ($plaintext === '') {
        return '';
    }
    $key = _secretsGetKey();
    if ($key === '') {
        // No key configured — store plaintext (same as before, no regression)
        return $plaintext;
    }

    $iv         = random_bytes(12); // 96-bit IV for GCM
    $tag        = '';
    $ciphertext = openssl_encrypt($plaintext, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag, '', 16);
    if ($ciphertext === false) {
        error_log('encryptSecret: openssl_encrypt failed');
        return $plaintext;
    }

    return 'enc::' . base64_encode($iv . $tag . $ciphertext);
}

/**
 * Decrypt a value retrieved from the DB.
 * Values not prefixed with "enc::" are returned as-is (backward compatible).
 */
function decryptSecret(string $stored): string
{
    if ($stored === '' || !str_starts_with($stored, 'enc::')) {
        return $stored; // plaintext legacy value
    }

    $key = _secretsGetKey();
    if ($key === '') {
        error_log('decryptSecret: KIMTECH_SECRET_KEY not configured — cannot decrypt');
        return ''; // return empty rather than leaking ciphertext
    }

    $raw = base64_decode(substr($stored, 5), true);
    if ($raw === false || strlen($raw) < 28) { // 12 IV + 16 tag minimum
        error_log('decryptSecret: malformed ciphertext');
        return '';
    }

    $iv         = substr($raw, 0, 12);
    $tag        = substr($raw, 12, 16);
    $ciphertext = substr($raw, 28);

    $plaintext = openssl_decrypt($ciphertext, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
    if ($plaintext === false) {
        error_log('decryptSecret: decryption failed (wrong key or tampered data)');
        return '';
    }

    return $plaintext;
}

/**
 * Return a masked display value for the settings UI.
 * e.g. "sk_live_AbCdEfGhIjKl" → "sk_live_****KlMn"
 */
function maskSecret(string $plaintext): string
{
    if ($plaintext === '') {
        return '';
    }
    $len = strlen($plaintext);
    if ($len <= 8) {
        return str_repeat('*', $len);
    }
    return substr($plaintext, 0, 8) . str_repeat('*', max(4, $len - 12)) . substr($plaintext, -4);
}
