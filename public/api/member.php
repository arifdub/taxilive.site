<?php
/*
 * Taxi Live – members manage their own posts by email code.
 *   POST action=send_code  email           emails a 6-digit code (only if that email has posts)
 *   POST action=verify     email, code     signs the member in for this browser session
 *   GET  ?action=me                        {email, csrf} when signed in
 *   GET  ?action=mine                      the member's posts
 *   POST action=update  id + fields [+ image | remove_image]   (needs X-CSRF-Token)
 *   POST action=delete  id                                       (needs X-CSRF-Token)
 *   POST action=logout
 */
declare(strict_types=1);
require dirname(__DIR__) . '/lib/bootstrap.php';

tl_run(function () {
    $action = $_GET['action'] ?? $_POST['action'] ?? '';
    $db = tl_db();
    tl_session();
    $email = $_SESSION['member_email'] ?? null;

    if ($action === 'send_code') {
        tl_require_post();
        $addr = tl_email($_POST['email'] ?? '');
        if ($addr === '') tl_json(400, ['error' => 'Please enter a valid email address.']);
        $ok = tl_rate('code_ip', tl_ip_hash(), 6, 3600)
            && tl_rate('code_mail', hash_hmac('sha256', $addr, tl_secret()), 3, 900);
        if (!$ok) tl_json(429, ['error' => 'Too many codes requested. Please wait 15 minutes and try again.']);
        $q = $db->prepare("SELECT COUNT(*) FROM tl_posts WHERE email = ?");
        $q->execute([$addr]);
        $sent = false;
        if ((int)$q->fetchColumn() > 0) {
            $code = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
            $db->prepare("UPDATE tl_login_codes SET used_at = UTC_TIMESTAMP() WHERE email = ? AND used_at IS NULL")->execute([$addr]);
            $db->prepare("INSERT INTO tl_login_codes (email, code_hash, created_at, expires_at)
                VALUES (?, ?, UTC_TIMESTAMP(), UTC_TIMESTAMP() + INTERVAL 15 MINUTE)")
                ->execute([$addr, hash_hmac('sha256', $code, tl_secret())]);
            $sent = tl_mail($addr, "Your Taxi Live code: $code",
                "Hi,\n\nYour Taxi Live code is: $code\n\nEnter it on the \"Manage my posts\" page to edit or remove your posts. " .
                "It expires in 15 minutes.\n\nIf you didn't ask for this, you can ignore this email.\n\n– Taxi Live\nhttps://taxilive.site");
            if (!$sent) error_log("Taxi Live: mail() failed for code email");
        }
        // Same answer either way, so nobody can test which emails have posts.
        tl_json(200, ['ok' => true]);
    }

    if ($action === 'verify') {
        tl_require_post();
        $addr = tl_email($_POST['email'] ?? '');
        $code = preg_replace('/\D/', '', (string)($_POST['code'] ?? ''));
        if (!tl_rate('verify_ip', tl_ip_hash(), 20, 3600)) tl_json(429, ['error' => 'Too many attempts. Please try again later.']);
        $q = $db->prepare("SELECT id, code_hash, attempts FROM tl_login_codes
            WHERE email = ? AND used_at IS NULL AND expires_at > UTC_TIMESTAMP() ORDER BY id DESC LIMIT 1");
        $q->execute([$addr]);
        $row = $q->fetch();
        if (!$row || $row['attempts'] >= 5) tl_json(400, ['error' => 'That code has expired. Please ask for a new one.']);
        if (strlen($code) !== 6 || !hash_equals($row['code_hash'], hash_hmac('sha256', $code, tl_secret()))) {
            $db->prepare("UPDATE tl_login_codes SET attempts = attempts + 1 WHERE id = ?")->execute([$row['id']]);
            tl_json(400, ['error' => "That code isn't right. Check the email and try again."]);
        }
        $db->prepare("UPDATE tl_login_codes SET used_at = UTC_TIMESTAMP() WHERE id = ?")->execute([$row['id']]);
        session_regenerate_id(true);
        $_SESSION['member_email'] = $addr;
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
        tl_json(200, ['ok' => true, 'email' => $addr, 'csrf' => $_SESSION['csrf']]);
    }

    if ($action === 'logout') {
        tl_require_post();
        unset($_SESSION['member_email']);
        tl_json(200, ['ok' => true]);
    }

    if (!$email) tl_json(401, ['error' => 'Please sign in with your email code first.']);

    if ($action === 'me') tl_json(200, ['email' => $email, 'csrf' => tl_csrf()]);

    if ($action === 'mine') {
        $q = $db->prepare("SELECT " . TL_POST_COLS . " FROM tl_posts WHERE email = ? ORDER BY created_at DESC");
        $q->execute([$email]);
        $posts = array_map(function ($p) {
            return tl_public_post($p) + [
                'status' => $p['status'],
                'expired' => $p['expires_ts'] !== null && (int)$p['expires_ts'] < time(),
                'expires' => $p['expires_ts'] !== null ? (int)$p['expires_ts'] : null,
            ];
        }, $q->fetchAll());
        tl_json(200, ['posts' => $posts]);
    }

    tl_require_post();
    tl_check_csrf();
    $q = $db->prepare("SELECT id, image, status FROM tl_posts WHERE public_id = ? AND email = ?");
    $q->execute([(string)($_POST['id'] ?? ''), $email]);
    $post = $q->fetch();
    if (!$post) tl_json(404, ['error' => 'Post not found.']);

    if ($action === 'delete') {
        tl_delete_image($post['image']);
        $db->prepare("DELETE FROM tl_posts WHERE id = ?")->execute([$post['id']]);
        tl_json(200, ['ok' => true]);
    }

    if ($action === 'update') {
        [$f, $err] = tl_post_fields($_POST);
        if ($err) tl_json(400, ['error' => $err]);
        $image = $post['image'];
        $new = tl_save_image($_FILES['image'] ?? null);
        if ($new || !empty($_POST['remove_image'])) { tl_delete_image($image); $image = $new; }
        // Editing renews the post for another 30 days.
        $db->prepare("UPDATE tl_posts SET type = ?, category = ?, title = ?, body = ?, price = ?, name = ?, phone = ?, area = ?,
                image = ?, updated_at = UTC_TIMESTAMP(), expires_at = UTC_TIMESTAMP() + INTERVAL ? DAY WHERE id = ?")
            ->execute([$f['type'], $f['category'], $f['title'], $f['body'], $f['price'], $f['name'], $f['phone'], $f['area'],
                       $image, TL_POST_LIFETIME_DAYS, $post['id']]);
        tl_json(200, ['ok' => true]);
    }

    tl_json(400, ['error' => 'Unknown action']);
});
