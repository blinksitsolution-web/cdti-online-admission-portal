<?php
/**
 * tests/run_tests.php — KIMTECH Portal Test Suite
 * Run from CLI only: php tests/run_tests.php
 *
 * Covers: CSRF, AES-256-GCM secrets, magic byte validation,
 *         input sanitization, index/enrolment validators, CSP nonce.
 */

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit('Tests must be run from CLI only.');
}

define('APP_ROOT', dirname(__DIR__));

$_SERVER['HTTP_HOST']   = 'localhost';
$_SERVER['SCRIPT_NAME'] = '/tests/run_tests.php';
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';

require_once APP_ROOT . '/includes/helpers.php';
require_once APP_ROOT . '/includes/secrets.php';

// ── Tiny test runner ─────────────────────────────────────────────
$passed = 0;
$failed = 0;

function test(string $name, bool $result): void {
    global $passed, $failed;
    if ($result) {
        echo "\033[32m  PASS $name\033[0m\n";
        $passed++;
    } else {
        echo "\033[31m  FAIL $name\033[0m\n";
        $failed++;
    }
}

function section(string $title): void {
    echo "\n\033[1;34m$title\033[0m\n";
}

// ── 1. Input sanitization ────────────────────────────────────────
section('Input Sanitization');
test('strips HTML tags',       sanitize('<b>hello</b>') === 'hello');
test('encodes double quotes',  str_contains(sanitize('"test"'), '&quot;'));
test('trims whitespace',       sanitize('  hi  ') === 'hi');

// ── 2. Validators ────────────────────────────────────────────────
section('Validators');
test('index: accepts 11-digit',   validateIndexNumber('41700600925'));
test('index: accepts 12-digit',   validateIndexNumber('417006009251'));
test('index: rejects 3-digit',    !validateIndexNumber('123'));
test('index: rejects symbols',    !validateIndexNumber('417-006-009'));
test('enrolment: accepts 5-digit',validateEnrolmentCode('12345'));
test('enrolment: rejects alpha',  !validateEnrolmentCode('abc12'));
test('enrolment: rejects short',  !validateEnrolmentCode('12'));

// ── 3. CSRF ──────────────────────────────────────────────────────
section('CSRF Token');
$_SESSION = [];
$token = generateCsrfToken();
test('token is 64-char hex',          strlen($token) === 64);
test('generateCsrfToken idempotent',  generateCsrfToken() === $token);
test('verify accepts correct token',  verifyCsrfToken($token));
$rotated = $_SESSION['csrf_token'] ?? '';
test('token rotates after verify',    $rotated !== $token && strlen($rotated) === 64);
test('verify rejects wrong token',    !verifyCsrfToken('deadbeef'));
test('verify rejects empty string',   !verifyCsrfToken(''));

// ── 4. AES-256-GCM secrets ──────────────────────────────────────
section('Secrets Encryption (AES-256-GCM)');
$plain = 'sk_live_supersecretkey12345';
$enc   = encryptSecret($plain);
test('returns enc:: prefix',          str_starts_with($enc, 'enc::'));
test('ciphertext differs from plain', $enc !== $plain);
test('decrypts round-trip correctly', decryptSecret($enc) === $plain);
test('passes through plain value',    decryptSecret($plain) === $plain);
test('handles empty string',          decryptSecret('') === '');
$enc2 = encryptSecret($plain);
test('unique IV per encryption',      $enc !== $enc2);
test('second ciphertext decrypts',    decryptSecret($enc2) === $plain);
$masked = maskSecret($plain);
test('maskSecret hides middle',       str_contains($masked, '****'));
test('maskSecret keeps first 4 chars',str_starts_with($masked, substr($plain, 0, 4)));

// ── 5. Magic byte validation ─────────────────────────────────────
section('Upload Magic Byte Validation');
$jpegTmp = tempnam(sys_get_temp_dir(), 'kt_');
file_put_contents($jpegTmp, "\xFF\xD8\xFF\xE0" . str_repeat('x', 100));
$pngTmp = tempnam(sys_get_temp_dir(), 'kt_');
file_put_contents($pngTmp, "\x89PNG\r\n\x1A\n" . str_repeat('x', 100));
$fakeTmp = tempnam(sys_get_temp_dir(), 'kt_');
file_put_contents($fakeTmp, "<?php echo 'evil'; ?>" . str_repeat('x', 100));

test('accepts valid JPEG',            validateUploadMagicBytes($jpegTmp, 'jpeg'));
test('accepts valid PNG',             validateUploadMagicBytes($pngTmp,  'png'));
test('rejects PHP file as JPEG',      !validateUploadMagicBytes($fakeTmp, 'jpeg'));
test('rejects PHP file as PNG',       !validateUploadMagicBytes($fakeTmp, 'png'));
test('CSV passes (no magic bytes)',   validateUploadMagicBytes($fakeTmp, 'csv'));

unlink($jpegTmp); unlink($pngTmp); unlink($fakeTmp);

// ── 6. CSP Nonce ─────────────────────────────────────────────────
section('CSP Nonce');
$nonce = generateCspNonce();
test('returns non-empty string',      strlen($nonce) > 0);
test('idempotent per request',        generateCspNonce() === $nonce);
test('is valid base64',               base64_decode($nonce, true) !== false);

// ── Summary ──────────────────────────────────────────────────────
$total = $passed + $failed;
echo "\n" . str_repeat('-', 45) . "\n";
if ($failed > 0) {
    echo "Results: $passed/$total passed | \033[31m$failed FAILED\033[0m\n";
} else {
    echo "\033[32mAll $total tests passed.\033[0m\n";
}

exit($failed > 0 ? 1 : 0);
