<?php
/**
 * Shared Auth & Helper functions
 */

// ── Base URL (auto-detected) ─────────────────────────────────────────────────
if (!defined('BASE_URL')) {
    $scheme   = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host     = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $scriptDir = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? ''));
    $appRoot  = rtrim(str_replace('/admin','', str_replace('/pdf','',$scriptDir)), '/');
    $appRoot  = preg_replace('#/+#', '/', $appRoot);
    $base     = $scheme . '://' . $host . $appRoot;
    define('BASE_URL', rtrim($base, '/'));
}

// ── App root path (filesystem) ───────────────────────────────────────────────
if (!defined('APP_ROOT')) {
    define('APP_ROOT', dirname(__DIR__));
}

// ── CSP Nonce ────────────────────────────────────────────────────────────────
// Generate once per request; call generateCspNonce() in every page <head> and
// emit the nonce attribute on every <script> / <style> tag.
function generateCspNonce(): string {
    static $nonce = null;
    if ($nonce === null) {
        $nonce = base64_encode(random_bytes(16));
    }
    return $nonce;
}

// Emit Content-Security-Policy header with the per-request nonce.
// Call once before any output, typically at the top of each page.
//
// $allowPaystackInlineScript: Paystack's Inline JS (v1/inline.js and v2/popup.js)
// builds its checkout modal by injecting its own <script> elements into the
// DOM at runtime. Those elements can never carry our nonce (we don't control
// Paystack's code), and per the CSP spec, 'unsafe-inline' in script-src is
// ignored outright once a nonce-source is present in that same directive —
// so with a nonce'd script-src, Paystack's popup script is always blocked and
// the modal silently never opens. Pages that call PaystackPop.setup() (i.e.
// payment.php) must pass true here to drop the nonce and use 'unsafe-inline'
// for script-src instead.
function emitCspHeader(bool $allowPaystackInlineScript = false): void {
    $n = generateCspNonce();
    $scriptSrc = $allowPaystackInlineScript
        ? "script-src 'self' https://cdn.jsdelivr.net https://cdnjs.cloudflare.com https://js.paystack.co https://checkout.paystack.com https://code.jquery.com 'unsafe-inline'; "
        : "script-src 'self' 'nonce-{$n}' https://cdn.jsdelivr.net https://cdnjs.cloudflare.com https://js.paystack.co https://checkout.paystack.com https://code.jquery.com; ";
    header(
        "Content-Security-Policy: default-src 'self'; "
        . $scriptSrc
        . "style-src 'self' 'nonce-{$n}' https://fonts.googleapis.com https://cdn.jsdelivr.net https://cdnjs.cloudflare.com https://paystack.com 'unsafe-inline'; "
        . "img-src 'self' data: https://*.paystack.co; "
        . "font-src https://fonts.gstatic.com https://cdnjs.cloudflare.com; "
        . "connect-src 'self' https://api.paystack.co https://standard.paystack.co https://*.pusher.com wss://*.pusher.com; "
        . "frame-src https://js.paystack.co https://checkout.paystack.com https://standard.paystack.co;"
    );
}

// ── Upload magic-byte validation ─────────────────────────────────────────────
// Allowed: CSV / plain-text (no magic bytes — checked by extension + MIME).
// For image uploads: validates actual file header bytes.
function validateUploadMagicBytes(string $tmpPath, string $allowedType): bool {
    $handle = fopen($tmpPath, 'rb');
    if (!$handle) return false;
    $header = fread($handle, 8);
    fclose($handle);

    return match ($allowedType) {
        'csv'   => true, // CSV has no magic bytes; rely on extension + MIME
        'jpeg'  => str_starts_with($header, "\xFF\xD8\xFF"),
        'png'   => str_starts_with($header, "\x89PNG\r\n\x1A\n"),
        'gif'   => str_starts_with($header, 'GIF87a') || str_starts_with($header, 'GIF89a'),
        'webp'  => substr($header, 0, 4) === 'RIFF' && substr($header, 8, 4) === 'WEBP',
        default => false,
    };
}

// ── Session helpers ─────────────────────────────────────────────────────────
function startSecureSession(): void {
    if (session_status() === PHP_SESSION_NONE) {
        // S10: Secure flag is always true in production (HTTPS); false only on localhost
        $isLocal = (DIRECTORY_SEPARATOR === '\\')
            || str_contains($_SERVER['HTTP_HOST'] ?? '', 'localhost')
            || str_contains($_SERVER['HTTP_HOST'] ?? '', '127.0.0.1')
            || str_contains($_SERVER['HTTP_HOST'] ?? '', '.test');
        $secure = !$isLocal;
        // Also honour X-Forwarded-Proto from a trusted reverse proxy
        if (!$secure && isset($_SERVER['HTTP_X_FORWARDED_PROTO'])) {
            $secure = strtolower($_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https';
        }
        session_set_cookie_params([
            'lifetime' => 0,
            'path'     => '/',
            'secure'   => $secure,
            'httponly' => true,
            'samesite' => 'Strict',
        ]);
        session_start();
    }
}

function requireStudentAuth(): void {
    startSecureSession();
    if (empty($_SESSION['student_id'])) { redirect(BASE_URL . '/admissions'); }
    if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity']) > 3600) {
        session_destroy(); redirect(BASE_URL . '/admissions?timeout=1');
    }
    $_SESSION['last_activity'] = time();
}

function requireAdminAuth(): void {
    startSecureSession();
    if (empty($_SESSION['admin_id'])) { redirect(BASE_URL . '/admin/index'); }
    if (isset($_SESSION['admin_last_activity']) && (time() - $_SESSION['admin_last_activity']) > 3600) {
        session_destroy(); redirect(BASE_URL . '/admin/index?timeout=1');
    }
    $_SESSION['admin_last_activity'] = time();
}

function isAdminLoggedIn(): bool { startSecureSession(); return !empty($_SESSION['admin_id']); }
function isStudentLoggedIn(): bool { startSecureSession(); return !empty($_SESSION['student_id']); }

function isSuperAdmin(): bool {
    startSecureSession();
    return ($_SESSION['admin_role'] ?? '') === 'superadmin';
}

function hasPermission(string $perm): bool {
    if (isSuperAdmin()) return true;
    $perms = array_filter(array_map('trim', explode(',', $_SESSION['admin_permissions'] ?? '')));
    return in_array($perm, $perms, true);
}

function requirePermission(string $perm): void {
    requireAdminAuth();
    if (!hasPermission($perm)) {
        http_response_code(403);
        $name = htmlspecialchars($_SESSION['admin_full_name'] ?? 'Admin');
        die("<div style='font-family:sans-serif;background:#050e1a;color:#fff;min-height:100vh;display:flex;align-items:center;justify-content:center;'>
            <div style='text-align:center;padding:2rem;'>
            <div style='font-size:3rem;margin-bottom:1rem;'>🔒</div>
            <h2 style='color:#e63946;'>Access Denied</h2>
            <p style='color:#a0aec0;'>You do not have permission to access this page.</p>
            <a href='" . BASE_URL . "/admin/dashboard' style='color:#4dd8ff;'>← Back to Dashboard</a>
            </div></div>");
    }
}

// ── Input helpers ────────────────────────────────────────────────────────────
function sanitize(string $val): string { return htmlspecialchars(strip_tags(trim($val)), ENT_QUOTES, 'UTF-8'); }
function validateIndexNumber(string $idx): bool {
    $idx = trim($idx);
    // Accept 6–20 alphanumeric characters (digits, letters)
    // Covers: 11-digit CSSPS (e.g. 41700600925), 12-digit, older formats
    return preg_match('/^[A-Za-z0-9]{6,20}$/', $idx) === 1;
}

function validateEnrolmentCode(string $code): bool { return preg_match('/^\d{4,10}$/', $code) === 1; }
function hashIndexNumber(string $idx): string { return substr($idx,0,4).'****'.substr($idx,8); }

// ── CSRF ─────────────────────────────────────────────────────────────────────
// S03: Token is generated once per session but ROTATED after every successful
// verification, preventing replay attacks if a token is ever observed.
function generateCsrfToken(): string {
    startSecureSession();
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}
function verifyCsrfToken(string $token): bool {
    startSecureSession();
    if (!isset($_SESSION['csrf_token'])) {
        return false;
    }
    $valid = hash_equals($_SESSION['csrf_token'], $token);
    if ($valid) {
        // Rotate immediately after successful use
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $valid;
}
function csrfField(): string { return '<input type="hidden" name="csrf_token" value="'.generateCsrfToken().'">'; }

// ── Settings ─────────────────────────────────────────────────────────────────
function getSettings(): array {
    static $settings = null;
    if ($settings === null) {
        require_once __DIR__ . '/db.php';
        $pdo  = getDB();
        $rows = $pdo->query("SELECT setting_key, setting_val FROM system_settings")->fetchAll();
        $settings = array_column($rows, 'setting_val', 'setting_key');
    }
    return $settings;
}
function getSetting(string $key, string $default = ''): string { return getSettings()[$key] ?? $default; }

// ── Audit log ────────────────────────────────────────────────────────────────
function logAction(string $actorType, ?int $actorId, string $action, string $details = ''): void {
    try {
        require_once __DIR__ . '/db.php';
        $pdo = getDB();
        $ip  = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        $pdo->prepare("INSERT INTO audit_logs (actor_type,actor_id,action,details,ip_address) VALUES (?,?,?,?,?)")
            ->execute([$actorType,$actorId,$action,$details,$ip]);
    } catch (Exception $e) {
        // Strip filesystem paths from logged messages to prevent path disclosure
        $safe = preg_replace('#[A-Za-z]?[:/\\\\][^\s:]+#', '[path]', $e->getMessage());
        error_log('Audit: ' . $safe);
    }
}

// ── Flash ────────────────────────────────────────────────────────────────────
function setFlash(string $key, string $msg): void { startSecureSession(); $_SESSION['flash'][$key] = $msg; }
function getFlash(string $key): ?string {
    startSecureSession();
    $m = $_SESSION['flash'][$key] ?? null;
    unset($_SESSION['flash'][$key]);
    return $m;
}

// ── Redirect ─────────────────────────────────────────────────────────────────
function redirect(string $url): void {
    if (str_starts_with($url, '/') && !str_starts_with($url, '//')) { $url = BASE_URL . $url; }
    header("Location: $url"); exit;
}

// ── Asset URL ────────────────────────────────────────────────────────────────
function asset(string $path): string { return BASE_URL . '/' . ltrim($path, '/'); }

// ── File path (filesystem) ───────────────────────────────────────────────────
// Use this in PDF generators instead of DOCUMENT_ROOT
function appPath(string $relativePath): string {
    return APP_ROOT . DIRECTORY_SEPARATOR . ltrim(str_replace('/', DIRECTORY_SEPARATOR, $relativePath), DIRECTORY_SEPARATOR);
}

// ── Encode file as base64 data URI (for PDF inline images) ───────────────────
function fileToDataUri(string $relativePath): string {
    $fullPath = appPath($relativePath);
    if (!file_exists($fullPath)) return '';
    $mime = mime_content_type($fullPath) ?: 'image/png';
    return 'data:' . $mime . ';base64,' . base64_encode(file_get_contents($fullPath));
}

// ── Residency / gender normalisation ─────────────────────────────────────────
function isBoarderResidency(?string $residency): bool {
    $r = strtolower(trim((string) $residency));
    return in_array($r, ['boarder', 'boarding', 'board'], true);
}

function normalizeStudentGender(?string $gender): string {
    $g = strtolower(trim($gender ?? ''));
    return match (true) {
        in_array($g, ['m', 'male', 'boy'], true)   => 'Male',
        in_array($g, ['f', 'female', 'girl'], true) => 'Female',
        default                                     => ucfirst($g),
    };
}

// ── Houses ───────────────────────────────────────────────────────────────────
function getHouseOccupancy(PDO $pdo, int $houseId, ?int $excludeStudentId = null): int {
    $sql = "SELECT COUNT(*) FROM students WHERE house_id = ? AND registration_status = 'completed'";
    $params = [$houseId];
    if ($excludeStudentId) {
        $sql .= " AND id != ?";
        $params[] = $excludeStudentId;
    }
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return (int) $stmt->fetchColumn();
}

function getAvailableHouses(PDO $pdo, string $studentGender, ?int $excludeStudentId = null): array {
    $gender = normalizeStudentGender($studentGender);
    if (!in_array($gender, ['Male', 'Female'], true)) {
        return [];
    }

    $houses = $pdo->prepare("SELECT id, name, gender, capacity FROM houses WHERE gender = ? ORDER BY name");
    $houses->execute([$gender]);

    $available = [];
    foreach ($houses->fetchAll() as $house) {
        $occupied = getHouseOccupancy($pdo, (int) $house['id'], $excludeStudentId);
        if ($occupied < (int) $house['capacity']) {
            $house['occupied'] = $occupied;
            $house['remaining'] = (int) $house['capacity'] - $occupied;
            $available[] = $house;
        }
    }
    return $available;
}

function isHouseAvailableForStudent(PDO $pdo, int $houseId, string $studentGender, ?int $studentId = null): bool {
    $stmt = $pdo->prepare("SELECT id, gender, capacity FROM houses WHERE id = ?");
    $stmt->execute([$houseId]);
    $house = $stmt->fetch();
    if (!$house) {
        return false;
    }

    $gender = normalizeStudentGender($studentGender);
    if ($house['gender'] !== $gender) {
        return false;
    }

    return getHouseOccupancy($pdo, $houseId, $studentId) < (int) $house['capacity'];
}

// ── Hubtel SMS ───────────────────────────────────────────────────────────────
function sendSmsDirect(string $phone, string $message, array $settings = []): bool {
    if (empty($settings)) { $settings = getSettings(); }
    require_once __DIR__ . '/secrets.php';
    $clientId     = decryptSecret($settings['hubtel_client_id']     ?? '');
    $clientSecret = decryptSecret($settings['hubtel_client_secret'] ?? '');
    $senderId     = $settings['hubtel_sender_id']     ?? 'CDTI';

    if (empty($clientId) || empty($clientSecret) || empty($phone)) return false;

    // Clean phone number — ensure starts with 233
    $phone = preg_replace('/[^0-9]/', '', $phone);
    if (strlen($phone) === 10 && $phone[0] === '0') { $phone = '233' . substr($phone, 1); }
    if (strlen($phone) < 11) return false;

    $payload = json_encode([
        'From'    => $senderId,
        'To'      => $phone,
        'Content' => $message,
    ]);

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => "https://smsc.hubtel.com/v1/messages/send",
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_USERPWD        => "$clientId:$clientSecret",
        CURLOPT_HTTPHEADER     => ["Content-Type: application/json"],
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);

    error_log("Hubtel SMS to {$phone} — HTTP {$code}" . ($err ? " cURL: $err" : ''));
    
    if ($code >= 200 && $code < 300) {
        return true;
    }
    
    throw new Exception("HTTP $code: $resp" . ($err ? " | cURL Error: $err" : ""));
}

function sendSms(string $phone, string $message, array $settings = []): bool {
    try {
        require_once __DIR__ . '/db.php';
        $pdo = getDB();
        $stmt = $pdo->prepare("INSERT INTO sms_queue (phone, message, status) VALUES (?, ?, 'pending')");
        return $stmt->execute([$phone, $message]);
    } catch (Exception $e) {
        error_log("Failed to queue SMS: " . $e->getMessage());
        return false;
    }
}

function getStudentParentPhones(PDO $pdo, int $studentId): array {
    $phones = [];
    try {
        $stmt = $pdo->prepare("SELECT father_phone, mother_phone, guardian_phone FROM parent_guardian_info WHERE student_id = ? LIMIT 1");
        $stmt->execute([$studentId]);
        $row = $stmt->fetch();
        if ($row) {
            $father = trim($row['father_phone'] ?? '');
            $mother = trim($row['mother_phone'] ?? '');
            $guardian = trim($row['guardian_phone'] ?? '');
            
            if ($father !== '') $phones[] = $father;
            if ($mother !== '') $phones[] = $mother;
            if (empty($phones) && $guardian !== '') $phones[] = $guardian;
        }
    } catch (Exception $e) {
        error_log("Error in getStudentParentPhones: " . $e->getMessage());
    }

    if (empty($phones)) {
        try {
            $stmt = $pdo->prepare("SELECT phone FROM students WHERE id = ? LIMIT 1");
            $stmt->execute([$studentId]);
            $studentPhone = trim($stmt->fetchColumn() ?: '');
            if ($studentPhone !== '') {
                $phones[] = $studentPhone;
            }
        } catch (Exception $e) {
            error_log("Error fetching student fallback phone: " . $e->getMessage());
        }
    }

    $cleaned = [];
    foreach ($phones as $p) {
        $p = preg_replace('/[^0-9]/', '', $p);
        if ($p !== '') {
            $cleaned[] = $p;
        }
    }
    
    return array_unique($cleaned);
}
