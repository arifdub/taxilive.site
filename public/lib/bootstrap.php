<?php
/*
 * Taxi Live – shared server code (config, database, sessions, security, mail).
 * This folder is blocked from the web by lib/.htaccess.
 */
declare(strict_types=1);

const TL_POST_LIFETIME_DAYS = 30;
const TL_REPORTS_TO_HIDE = 3;
const TL_MAX_IMAGE_BYTES = 4 * 1024 * 1024;
const TL_TYPES = ['advert', 'notice'];
const TL_CATEGORIES = ['rent', 'sale', 'wanted', 'service', 'other'];
const TL_SCHEMA_VERSION = 1;

define('TL_WEBROOT', dirname(__DIR__));             // public_html

/** Private folder next to public_html (falls back to a locked folder inside it). */
function tl_data_dir(): string {
    static $dir = null;
    if ($dir) return $dir;
    $outside = dirname(TL_WEBROOT) . '/taxilive-data';
    if ((is_dir($outside) || @mkdir($outside, 0750, true)) && is_writable($outside)) {
        return $dir = $outside;
    }
    $inside = TL_WEBROOT . '/data';
    if (!is_dir($inside)) @mkdir($inside, 0750, true);
    if (!is_file("$inside/.htaccess")) @file_put_contents("$inside/.htaccess", "Require all denied\n");
    return $dir = $inside;
}

function tl_config_path(): string { return tl_data_dir() . '/config.php'; }

function tl_config(): ?array {
    static $cfg = false;
    if ($cfg !== false) return $cfg;
    $p = tl_config_path();
    $c = is_file($p) ? (require $p) : null;
    return $cfg = (is_array($c) && !empty($c['db'])) ? $c : null;
}

function tl_installed(): bool { return tl_config() !== null; }

function tl_connect(array $db): PDO {
    $host = $db['host'] ?: 'localhost';
    $port = null;
    if (strpos($host, ":") !== false) { [$host, $port] = explode(":", $host, 2); }
    $dsn = "mysql:host=$host;dbname={$db['name']};charset=utf8mb4" . ($port ? ";port=$port" : '');
    $pdo = new PDO($dsn, $db['user'], $db['pass'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
        PDO::ATTR_TIMEOUT => 5,
    ]);
    $pdo->exec("SET time_zone = '+00:00'");
    return $pdo;
}

function tl_db(): PDO {
    static $pdo = null;
    if ($pdo) return $pdo;
    $cfg = tl_config();
    if (!$cfg) tl_json(503, ['error' => 'Site is being set up. Please try again soon.']);
    try {
        return $pdo = tl_connect($cfg['db']);
    } catch (Throwable $e) {
        error_log('Taxi Live DB: ' . $e->getMessage());
        tl_json(503, ['error' => 'Database unavailable. Please try again shortly.']);
    }
}

function tl_json(int $code, array $data): void {
    if (!headers_sent()) {
        http_response_code($code);
        header('Content-Type: application/json; charset=utf-8');
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: no-store');
    }
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/** Run an API handler and turn unexpected errors into a clean JSON 500. */
function tl_run(callable $fn): void {
    try {
        $fn();
    } catch (Throwable $e) {
        error_log('Taxi Live: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
        tl_json(500, ['error' => 'Something went wrong. Please try again.']);
    }
}

function tl_require_post(): void {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') tl_json(405, ['error' => 'POST required']);
}

function tl_secret(): string { return (string)(tl_config()['secret'] ?? 'taxilive'); }

function tl_ip_hash(): string {
    return hash_hmac('sha256', (string)($_SERVER['REMOTE_ADDR'] ?? ''), tl_secret());
}

/* ---------- Sessions & CSRF ---------- */

function tl_session(): void {
    if (session_status() === PHP_SESSION_ACTIVE) return;
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['SERVER_PORT'] ?? '') == 443);
    session_name('TLSESS');
    session_set_cookie_params(['lifetime' => 0, 'path' => '/', 'secure' => $https, 'httponly' => true, 'samesite' => 'Lax']);
    $dir = tl_data_dir() . '/sessions';
    if (is_dir($dir) || @mkdir($dir, 0700, true)) session_save_path($dir);
    session_start();
}

function tl_csrf(): string {
    tl_session();
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(32));
    return $_SESSION['csrf'];
}

function tl_check_csrf(): void {
    tl_session();
    $sent = (string)($_SERVER['HTTP_X_CSRF_TOKEN'] ?? $_POST['csrf'] ?? '');
    if (empty($_SESSION['csrf']) || !hash_equals($_SESSION['csrf'], $sent)) {
        tl_json(403, ['error' => 'Your session expired. Please refresh the page.']);
    }
}

/* ---------- Rate limiting (stored in the database) ---------- */

/** Returns false when $key has already happened $limit times within $seconds. Records the event otherwise. */
function tl_rate(string $action, string $key, int $limit, int $seconds, bool $record = true): bool {
    $db = tl_db();
    if (random_int(1, 50) === 1) $db->exec("DELETE FROM tl_rate_events WHERE created_at < (UTC_TIMESTAMP() - INTERVAL 1 DAY)");
    $q = $db->prepare("SELECT COUNT(*) FROM tl_rate_events WHERE action = ? AND subject = ? AND created_at > (UTC_TIMESTAMP() - INTERVAL ? SECOND)");
    $q->execute([$action, $key, $seconds]);
    if ((int)$q->fetchColumn() >= $limit) return false;
    if ($record) $db->prepare("INSERT INTO tl_rate_events (action, subject, created_at) VALUES (?, ?, UTC_TIMESTAMP())")->execute([$action, $key]);
    return true;
}

/* ---------- Input helpers ---------- */

function tl_clean(?string $s, int $max, bool $multiline = false): string {
    $s = str_replace("\r\n", "\n", (string)$s);
    $s = $multiline ? preg_replace('/[^\P{C}\n]/u', '', $s) : preg_replace('/\p{C}/u', '', $s);
    $s = trim(preg_replace('/\n{3,}/', "\n\n", (string)$s));
    return mb_substr($s, 0, $max);
}

function tl_email(?string $s): string {
    $s = strtolower(trim((string)$s));
    return (strlen($s) <= 190 && filter_var($s, FILTER_VALIDATE_EMAIL)) ? $s : '';
}

function tl_valid_phone(string $phone): bool {
    if ($phone === '') return true;
    $digits = preg_replace('/\D/', '', $phone);
    return (bool)preg_match('/^\+?[\d\s\-()]+$/', $phone) && strlen($digits) >= 7 && strlen($digits) <= 15;
}

function tl_valid_url(string $url): bool {
    return $url === '' || (bool)preg_match('#^https?://[^\s<>"]+$#i', $url) && filter_var($url, FILTER_VALIDATE_URL);
}

/**
 * Validate post fields shared by public create, member edit and admin edit.
 * Returns [fields, error]. $admin allows notice extras (link, deadline, long body).
 */
function tl_post_fields(array $in, bool $admin = false): array {
    $f = [
        'type' => (string)($in['type'] ?? ''),
        'category' => (string)($in['category'] ?? ''),
        'title' => tl_clean($in['title'] ?? '', $admin ? 160 : 80),
        'body' => tl_clean($in['body'] ?? '', $admin ? 5000 : 1000, true),
        'price' => tl_clean($in['price'] ?? '', 30),
        'name' => tl_clean($in['name'] ?? '', 60),
        'phone' => tl_clean($in['phone'] ?? '', 20),
        'area' => tl_clean($in['area'] ?? '', 60),
    ];
    if (!in_array($f['type'], TL_TYPES, true)) return [null, 'Choose a post type.'];
    if ($f['type'] === 'advert') {
        if (!in_array($f['category'], TL_CATEGORIES, true)) return [null, 'Choose an advert category.'];
    } else {
        $f['category'] = ''; $f['price'] = '';
    }
    if (mb_strlen($f['title']) < 3) return [null, 'Please add a title.'];
    if (mb_strlen($f['body']) < 5) return [null, 'Please add some details.'];
    if ($f['name'] === '') return [null, 'Please add your name.'];
    if (!tl_valid_phone($f['phone'])) return [null, "Phone number doesn't look right."];
    if ($admin) {
        $f['link_url'] = tl_clean($in['link_url'] ?? '', 500);
        $f['link_label'] = tl_clean($in['link_label'] ?? '', 60);
        $f['deadline_text'] = tl_clean($in['deadline_text'] ?? '', 80);
        if (!tl_valid_url($f['link_url'])) return [null, 'Link must start with http:// or https://'];
        if ($f['link_url'] !== '' && $f['link_label'] === '') $f['link_label'] = 'Open link';
    }
    return [$f, null];
}

/** Public shape of a post (never includes email or IP). */
function tl_public_post(array $p): array {
    return [
        'id' => $p['public_id'], 'type' => $p['type'], 'category' => $p['category'],
        'title' => $p['title'], 'body' => $p['body'], 'price' => $p['price'],
        'name' => $p['name'], 'phone' => $p['phone'], 'area' => $p['area'],
        'image' => $p['image'], 'created' => (int)$p['created_ts'],
        'pinned' => (bool)$p['pinned'], 'link_url' => $p['link_url'], 'link_label' => $p['link_label'],
        'deadline' => $p['deadline_text'],
    ];
}

const TL_POST_COLS = "id, public_id, type, category, title, body, price, name, email, phone, area, image,
    link_url, link_label, deadline_text, status, pinned, report_count,
    UNIX_TIMESTAMP(created_at) AS created_ts, UNIX_TIMESTAMP(updated_at) AS updated_ts,
    UNIX_TIMESTAMP(expires_at) AS expires_ts";

/* ---------- Images ---------- */

function tl_delete_image(?string $img): void {
    if ($img && preg_match('#^uploads/[a-f0-9]{24}\.(jpg|png|webp)$#', $img)) @unlink(TL_WEBROOT . '/' . $img);
}

/** Saves an uploaded photo (re-encoded to strip metadata). Returns relative path, null when none, or throws message. */
function tl_save_image(?array $f): ?string {
    if (!$f || ($f['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) return null;
    if ($f['error'] !== UPLOAD_ERR_OK) tl_json(400, ['error' => 'Photo upload failed. Try a smaller photo.']);
    if ($f['size'] > TL_MAX_IMAGE_BYTES) tl_json(400, ['error' => 'Photo is too large (max 4 MB).']);
    $info = @getimagesize($f['tmp_name']);
    $map = [IMAGETYPE_JPEG => 'jpg', IMAGETYPE_PNG => 'png', IMAGETYPE_WEBP => 'webp'];
    if (!$info || !isset($map[$info[2]])) tl_json(400, ['error' => 'Photo must be JPG, PNG or WEBP.']);
    $dir = TL_WEBROOT . '/uploads';
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    $base = bin2hex(random_bytes(12));
    if (function_exists('imagecreatefromstring')) {
        $im = @imagecreatefromstring((string)file_get_contents($f['tmp_name']));
        if ($im) {
            $w = imagesx($im); $h = imagesy($im);
            $k = min(1, 1600 / max($w, $h));
            if ($k < 1) { $im2 = imagescale($im, (int)round($w * $k), (int)round($h * $k)); imagedestroy($im); $im = $im2; }
            $ok = imagejpeg($im, "$dir/$base.jpg", 82);
            imagedestroy($im);
            if ($ok) return "uploads/$base.jpg";
        }
    }
    $name = "$base.{$map[$info[2]]}";
    if (!move_uploaded_file($f['tmp_name'], "$dir/$name")) tl_json(500, ['error' => 'Could not save photo.']);
    return "uploads/$name";
}

/* ---------- Mail ---------- */

/** Rewrite the private settings file (atomic). */
function tl_save_config(array $config): void {
    $php = "<?php\n// Taxi Live settings – keep private.\nreturn " . var_export($config, true) . ";\n";
    $tmp = tl_config_path() . '.tmp';
    if (@file_put_contents($tmp, $php, LOCK_EX) === false || !@rename($tmp, tl_config_path())) {
        throw new RuntimeException('Could not save the settings file.');
    }
    @chmod(tl_config_path(), 0600);
    tl_forget_config_cache();
}

/** PHP's opcache can keep serving an old copy of config.php – clear it after every change. */
function tl_forget_config_cache(): void {
    clearstatcache(true, tl_config_path());
    if (function_exists('opcache_invalidate')) @opcache_invalidate(tl_config_path(), true);
}

function tl_mail_from(): string {
    $cfg = tl_config() ?? [];
    return $cfg['mail_from'] ?? ('noreply@' . preg_replace('/^www\./', '', $_SERVER['HTTP_HOST'] ?? 'taxilive.site'));
}

/** Build a plain-text email (headers + base64 body). */
function tl_build_message(string $from, string $to, string $subject, string $text, bool $withTo): array {
    $domain = substr(strrchr($from, '@'), 1) ?: 'taxilive.site';
    $headers = [
        'Date: ' . date('r'),
        'From: Taxi Live <' . $from . '>',
        'Reply-To: ' . $from,
        'Message-ID: <' . bin2hex(random_bytes(12)) . '@' . $domain . '>',
        'MIME-Version: 1.0',
        'Content-Type: text/plain; charset=UTF-8',
        'Content-Transfer-Encoding: base64',
        'X-Mailer: TaxiLive',
    ];
    if ($withTo) array_splice($headers, 2, 0, ['To: <' . $to . '>', 'Subject: =?UTF-8?B?' . base64_encode($subject) . '?=']);
    return [$headers, chunk_split(base64_encode(str_replace("\n", "\r\n", str_replace("\r\n", "\n", $text))))];
}

/**
 * Send an email. Uses the SMTP login saved in Admin → Email settings when there is one,
 * otherwise the server's built-in mail. $error gets a readable reason on failure.
 */
function tl_mail(string $to, string $subject, string $text, ?string &$error = null): bool {
    $cfg = tl_config() ?? [];
    $from = tl_mail_from();
    $smtp = $cfg['smtp'] ?? null;
    if ($smtp && !empty($smtp['host'])) return tl_smtp_send($smtp, $from, $to, $subject, $text, $error);
    [$headers, $body] = tl_build_message($from, $to, $subject, $text, false);
    $ok = @mail($to, '=?UTF-8?B?' . base64_encode($subject) . '?=', $body, implode("\r\n", $headers), '-f' . $from);
    if (!$ok) $error = 'The server could not send the email (PHP mail() failed).';
    return $ok;
}

function tl_smtp_send(array $smtp, string $from, string $to, string $subject, string $text, ?string &$error = null): bool {
    $host = (string)$smtp['host'];
    $port = (int)($smtp['port'] ?? 465);
    $secure = (string)($smtp['secure'] ?? 'ssl');   // ssl (465) | tls (587, STARTTLS) | none
    $ctx = stream_context_create(['ssl' => ['verify_peer' => true, 'verify_peer_name' => true, 'SNI_enabled' => true, 'peer_name' => $host]]);
    $fp = @stream_socket_client(($secure === 'ssl' ? 'ssl://' : 'tcp://') . $host . ':' . $port, $errno, $errstr, 15, STREAM_CLIENT_CONNECT, $ctx);
    if (!$fp) {
        $error = "Could not connect to $host:$port ($errstr). Check the server name and port"
            . ($secure !== 'none' ? ', and that the server\'s SSL certificate matches that name.' : '.');
        return false;
    }
    stream_set_timeout($fp, 20);
    $read = function () use ($fp) {
        $out = '';
        while (($line = fgets($fp, 1024)) !== false) { $out .= $line; if (strlen($line) < 4 || $line[3] !== '-') break; }
        return $out;
    };
    $cmd = function (?string $c, array $ok, string $what) use ($fp, $read, &$error) {
        if ($c !== null) fwrite($fp, $c . "\r\n");
        $r = $read();
        if (!in_array((int)substr($r, 0, 3), $ok, true)) { $error = "$what failed: " . trim($r ?: 'no reply from server'); return false; }
        return true;
    };
    $ehlo = preg_replace('/[^a-z0-9.-]/i', '', $_SERVER['SERVER_NAME'] ?? 'taxilive.site') ?: 'taxilive.site';
    $ok = $cmd(null, [220], 'Connecting') && $cmd("EHLO $ehlo", [250], 'Greeting');
    if ($ok && $secure === 'tls') {
        $ok = $cmd('STARTTLS', [220], 'STARTTLS');
        if ($ok && !@stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) { $error = "Secure connection (STARTTLS) failed – check the server's SSL certificate."; $ok = false; }
        $ok = $ok && $cmd("EHLO $ehlo", [250], 'Greeting');
    }
    if ($ok && !empty($smtp['user'])) {
        $ok = $cmd('AUTH LOGIN', [334], 'Login') && $cmd(base64_encode((string)$smtp['user']), [334], 'Login')
            && $cmd(base64_encode((string)($smtp['pass'] ?? '')), [235], 'Login (check the email address and password)');
    }
    if ($ok) {
        [$headers, $body] = tl_build_message($from, $to, $subject, $text, true);
        $data = implode("\r\n", $headers) . "\r\n\r\n" . $body;
        $data = preg_replace('/^\./m', '..', $data);
        $ok = $cmd("MAIL FROM:<$from>", [250], 'Sender') && $cmd("RCPT TO:<$to>", [250, 251], 'Recipient')
            && $cmd('DATA', [354], 'Sending') && $cmd(rtrim($data, "\r\n") . "\r\n.", [250], 'Sending');
    }
    @fwrite($fp, "QUIT\r\n");
    fclose($fp);
    if (!$ok) error_log('Taxi Live SMTP: ' . $error);
    return $ok;
}

function tl_new_public_id(): string { return bin2hex(random_bytes(6)); }
