<?php
/*
 * Taxi Live – member posts API (adverts, notices, wanted).
 * Storage: a JSON file kept OUTSIDE public_html when possible.
 *
 *   GET  ?action=list                     public list
 *   POST action=create  (multipart)       new post -> {id, token}
 *   POST action=delete  id, token         owner delete
 *   POST action=report  id                report a post (3 reports hides it)
 *   POST action=admin_list   password
 *   POST action=admin_delete password, id
 *   POST action=admin_toggle password, id (hide / show)
 */
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');

const MAX_POSTS_PER_HOUR = 3;
const REPORTS_TO_HIDE = 3;
const POST_LIFETIME_DAYS = 30;
const MAX_IMAGE_BYTES = 4 * 1024 * 1024;
const TYPES = ['advert', 'notice', 'wanted'];

$ADMIN_HASH = null;
if (is_file(__DIR__ . '/config.php')) {
    $cfg = require __DIR__ . '/config.php';
    $ADMIN_HASH = is_array($cfg) ? ($cfg['admin_hash'] ?? null) : null;
}

function out(int $code, array $data): void {
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function data_dir(): string {
    // Prefer a folder next to public_html (not web-accessible).
    $outside = dirname(__DIR__, 2) . '/taxilive-data';
    if (is_dir($outside) || @mkdir($outside, 0750, true)) {
        if (is_writable($outside)) return $outside;
    }
    $inside = dirname(__DIR__) . '/data';
    if (!is_dir($inside)) @mkdir($inside, 0750, true);
    if (!is_file($inside . '/.htaccess')) @file_put_contents($inside . '/.htaccess', "Require all denied\n");
    return $inside;
}

/** Run $fn with the store locked; $fn receives &$db and may modify it. */
function with_db(callable $fn) {
    $file = data_dir() . '/posts.json';
    $fh = fopen($file, 'c+');
    if (!$fh) out(500, ['error' => 'Storage unavailable']);
    flock($fh, LOCK_EX);
    $raw = stream_get_contents($fh);
    $db = $raw ? json_decode($raw, true) : null;
    if (!is_array($db)) $db = ['posts' => [], 'rate' => []];
    $before = json_encode($db);
    $result = $fn($db);
    if (json_encode($db) !== $before) {
        ftruncate($fh, 0);
        rewind($fh);
        fwrite($fh, json_encode($db, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        fflush($fh);
    }
    flock($fh, LOCK_UN);
    fclose($fh);
    return $result;
}

function client_key(): string {
    return hash('sha256', ($_SERVER['REMOTE_ADDR'] ?? '') . '|taxilive');
}

function clean(string $s, int $max, bool $multiline = false): string {
    $s = str_replace("\r\n", "\n", $s);
    $s = $multiline ? preg_replace('/[^\P{C}\n]/u', '', $s) : preg_replace('/\p{C}/u', '', $s);
    $s = trim(preg_replace('/\n{3,}/', "\n\n", (string)$s));
    return mb_substr($s, 0, $max);
}

function public_post(array $p): array {
    return [
        'id' => $p['id'], 'type' => $p['type'], 'title' => $p['title'], 'body' => $p['body'],
        'name' => $p['name'], 'phone' => $p['phone'], 'area' => $p['area'],
        'image' => $p['image'], 'created' => $p['created'],
    ];
}

function is_admin(?string $hash): bool {
    $pw = (string)($_POST['password'] ?? '');
    return $hash && $pw !== '' && password_verify($pw, $hash);
}

function delete_image(?string $img): void {
    if ($img && preg_match('#^uploads/[a-f0-9]{24}\.(jpg|png|webp)$#', $img)) {
        @unlink(dirname(__DIR__) . '/' . $img);
    }
}

function save_image(array $f): ?string {
    if (($f['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) return null;
    if ($f['error'] !== UPLOAD_ERR_OK) out(400, ['error' => 'Photo upload failed. Try a smaller photo.']);
    if ($f['size'] > MAX_IMAGE_BYTES) out(400, ['error' => 'Photo is too large (max 4 MB).']);
    $info = @getimagesize($f['tmp_name']);
    $map = [IMAGETYPE_JPEG => 'jpg', IMAGETYPE_PNG => 'png', IMAGETYPE_WEBP => 'webp'];
    if (!$info || !isset($map[$info[2]])) out(400, ['error' => 'Photo must be JPG, PNG or WEBP.']);
    $ext = $map[$info[2]];
    $dir = dirname(__DIR__) . '/uploads';
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    $name = bin2hex(random_bytes(12)) . '.' . $ext;
    $dest = "$dir/$name";

    // Re-encode with GD when available (strips metadata such as GPS location).
    if (function_exists('imagecreatefromstring')) {
        $im = @imagecreatefromstring((string)file_get_contents($f['tmp_name']));
        if ($im) {
            $w = imagesx($im); $h = imagesy($im);
            $scale = min(1, 1600 / max($w, $h));
            if ($scale < 1) {
                $im2 = imagescale($im, (int)round($w * $scale), (int)round($h * $scale));
                imagedestroy($im); $im = $im2;
            }
            $name = preg_replace('/\.\w+$/', '.jpg', $name);
            $dest = "$dir/$name";
            $ok = imagejpeg($im, $dest, 82);
            imagedestroy($im);
            if ($ok) return 'uploads/' . $name;
        }
    }
    if (!move_uploaded_file($f['tmp_name'], $dest)) out(500, ['error' => 'Could not save photo.']);
    return 'uploads/' . $name;
}

$action = $_GET['action'] ?? $_POST['action'] ?? 'list';
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($action !== 'list' && $method !== 'POST') out(405, ['error' => 'POST required']);

switch ($action) {
case 'list':
    $cutoff = time() - POST_LIFETIME_DAYS * 86400;
    $posts = with_db(function (&$db) use ($cutoff) {
        $list = [];
        foreach ($db['posts'] as $p) {
            if (!empty($p['hidden']) || $p['created'] < $cutoff) continue;
            $list[] = public_post($p);
        }
        return $list;
    });
    usort($posts, fn($a, $b) => $b['created'] <=> $a['created']);
    out(200, ['posts' => array_slice($posts, 0, 200)]);

case 'create':
    if (!empty($_POST['website'])) out(200, ['ok' => true, 'id' => '', 'token' => '']); // honeypot
    $type = (string)($_POST['type'] ?? '');
    $title = clean((string)($_POST['title'] ?? ''), 80);
    $body = clean((string)($_POST['body'] ?? ''), 1000, true);
    $name = clean((string)($_POST['name'] ?? ''), 40);
    $area = clean((string)($_POST['area'] ?? ''), 40);
    $phone = clean((string)($_POST['phone'] ?? ''), 20);
    if (!in_array($type, TYPES, true)) out(400, ['error' => 'Choose a post type.']);
    if (mb_strlen($title) < 3) out(400, ['error' => 'Please add a title.']);
    if (mb_strlen($body) < 5) out(400, ['error' => 'Please add some details.']);
    if ($name === '') out(400, ['error' => 'Please add your name.']);
    if ($phone !== '') {
        $digits = preg_replace('/\D/', '', $phone);
        if (!preg_match('/^\+?[\d\s\-()]+$/', $phone) || strlen($digits) < 7 || strlen($digits) > 15) {
            out(400, ['error' => 'Phone number doesn\'t look right.']);
        }
    }
    $key = client_key();
    $now = time();
    // Rate limit before touching the upload.
    with_db(function (&$db) use ($key, $now) {
        $recent = array_values(array_filter($db['rate'][$key] ?? [], fn($t) => $t > $now - 3600));
        if (count($recent) >= MAX_POSTS_PER_HOUR) out(429, ['error' => 'You\'ve posted a lot recently. Please try again later.']);
    });
    $image = save_image($_FILES['image'] ?? ['error' => UPLOAD_ERR_NO_FILE]);
    $id = bin2hex(random_bytes(6));
    $token = bin2hex(random_bytes(16));
    with_db(function (&$db) use ($key, $now, $id, $token, $type, $title, $body, $name, $area, $phone, $image) {
        $cutoff = $now - POST_LIFETIME_DAYS * 86400;
        foreach ($db['posts'] as $i => $p) { // purge expired
            if ($p['created'] < $cutoff) { delete_image($p['image'] ?? null); unset($db['posts'][$i]); }
        }
        $db['posts'] = array_values($db['posts']);
        foreach ($db['rate'] as $k => $ts) {
            $db['rate'][$k] = array_values(array_filter($ts, fn($t) => $t > $now - 3600));
            if (!$db['rate'][$k]) unset($db['rate'][$k]);
        }
        $db['rate'][$key][] = $now;
        $db['posts'][] = [
            'id' => $id, 'type' => $type, 'title' => $title, 'body' => $body, 'name' => $name,
            'phone' => $phone, 'area' => $area, 'image' => $image, 'created' => $now,
            'token' => hash('sha256', $token), 'reports' => [], 'hidden' => false, 'by' => $key,
        ];
    });
    out(201, ['ok' => true, 'id' => $id, 'token' => $token]);

case 'delete':
    $id = (string)($_POST['id'] ?? '');
    $token = hash('sha256', (string)($_POST['token'] ?? ''));
    $ok = with_db(function (&$db) use ($id, $token) {
        foreach ($db['posts'] as $i => $p) {
            if ($p['id'] === $id && hash_equals($p['token'], $token)) {
                delete_image($p['image'] ?? null);
                array_splice($db['posts'], $i, 1);
                return true;
            }
        }
        return false;
    });
    $ok ? out(200, ['ok' => true]) : out(403, ['error' => 'Not allowed']);

case 'report':
    $id = (string)($_POST['id'] ?? '');
    $key = client_key();
    with_db(function (&$db) use ($id, $key) {
        foreach ($db['posts'] as &$p) {
            if ($p['id'] !== $id) continue;
            if (!in_array($key, $p['reports'], true)) $p['reports'][] = $key;
            if (count($p['reports']) >= REPORTS_TO_HIDE) $p['hidden'] = true;
        }
    });
    out(200, ['ok' => true]);

case 'admin_list':
    if (!is_admin($ADMIN_HASH)) out(403, ['error' => $ADMIN_HASH ? 'Wrong password' : 'Admin password not set up']);
    $posts = with_db(fn(&$db) => array_map(fn($p) => public_post($p) + [
        'hidden' => !empty($p['hidden']), 'reports' => count($p['reports'] ?? []),
    ], $db['posts']));
    usort($posts, fn($a, $b) => $b['created'] <=> $a['created']);
    out(200, ['posts' => $posts]);

case 'admin_delete':
case 'admin_toggle':
    if (!is_admin($ADMIN_HASH)) out(403, ['error' => 'Wrong password']);
    $id = (string)($_POST['id'] ?? '');
    with_db(function (&$db) use ($id, $action) {
        foreach ($db['posts'] as $i => &$p) {
            if ($p['id'] !== $id) continue;
            if ($action === 'admin_delete') {
                delete_image($p['image'] ?? null);
                unset($db['posts'][$i]);
            } else {
                $p['hidden'] = empty($p['hidden']);
                if (!$p['hidden']) $p['reports'] = [];
            }
        }
        unset($p);
        $db['posts'] = array_values($db['posts']);
    });
    out(200, ['ok' => true]);

default:
    out(400, ['error' => 'Unknown action']);
}
