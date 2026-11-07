<?php
/*
 * Taxi Live – admin API (session login, CSRF on every change).
 *   login / logout / me
 *   posts_list, post_save, post_status, post_pin, post_delete
 *   numbers_list, cat_save, cat_delete, contact_save, contact_delete
 *   admins_list, admin_add, admin_delete, password
 */
declare(strict_types=1);
require dirname(__DIR__) . '/lib/bootstrap.php';

tl_run(function () {
    $action = $_GET['action'] ?? $_POST['action'] ?? '';
    $db = tl_db();
    tl_session();

    if ($action === 'login') {
        tl_require_post();
        if (!tl_rate('login', tl_ip_hash(), 10, 900)) tl_json(429, ['error' => 'Too many login attempts. Please wait 15 minutes.']);
        $user = trim((string)($_POST['username'] ?? ''));
        $q = $db->prepare("SELECT id, username, email, password_hash FROM tl_admins WHERE username = ? OR email = ? LIMIT 1");
        $q->execute([$user, strtolower($user)]);
        $a = $q->fetch();
        if (!$a || !password_verify((string)($_POST['password'] ?? ''), $a['password_hash'])) {
            tl_json(401, ['error' => 'Wrong username or password.']);
        }
        if (password_needs_rehash($a['password_hash'], PASSWORD_DEFAULT)) {
            $db->prepare("UPDATE tl_admins SET password_hash = ? WHERE id = ?")->execute([password_hash((string)$_POST['password'], PASSWORD_DEFAULT), $a['id']]);
        }
        $db->prepare("UPDATE tl_admins SET last_login_at = UTC_TIMESTAMP() WHERE id = ?")->execute([$a['id']]);
        session_regenerate_id(true);
        $_SESSION['admin_id'] = (int)$a['id'];
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
        tl_json(200, ['ok' => true, 'user' => $a['username'], 'csrf' => $_SESSION['csrf']]);
    }

    $adminId = (int)($_SESSION['admin_id'] ?? 0);
    $me = null;
    if ($adminId) {
        $q = $db->prepare("SELECT id, username, email FROM tl_admins WHERE id = ?");
        $q->execute([$adminId]);
        $me = $q->fetch() ?: null;
    }
    if (!$me) { unset($_SESSION['admin_id']); tl_json(401, ['error' => 'Please log in.']); }

    if ($action === 'me') tl_json(200, ['user' => $me['username'], 'email' => $me['email'], 'csrf' => tl_csrf()]);

    if ($action === 'posts_list') {
        $where = ['1=1']; $args = [];
        $status = $_GET['status'] ?? 'all';
        if ($status === 'active') $where[] = "status = 'active' AND (expires_at IS NULL OR expires_at > UTC_TIMESTAMP())";
        if ($status === 'hidden') $where[] = "status = 'hidden'";
        if ($status === 'expired') $where[] = "expires_at <= UTC_TIMESTAMP()";
        if ($status === 'reported') $where[] = "report_count > 0";
        if ($status === 'pinned') $where[] = "pinned = 1";
        $type = $_GET['type'] ?? '';
        if (in_array($type, TL_TYPES, true)) { $where[] = "type = ?"; $args[] = $type; }
        $s = trim((string)($_GET['q'] ?? ''));
        if ($s !== '') {
            $where[] = "(title LIKE ? OR body LIKE ? OR name LIKE ? OR email LIKE ? OR phone LIKE ?)";
            $like = '%' . addcslashes($s, '%_\\') . '%';
            array_push($args, $like, $like, $like, $like, $like);
        }
        $q = $db->prepare("SELECT " . TL_POST_COLS . " FROM tl_posts WHERE " . implode(' AND ', $where) . " ORDER BY pinned DESC, created_at DESC LIMIT 500");
        $q->execute($args);
        $rows = array_map(function ($p) {
            return tl_public_post($p) + [
                'dbid' => (int)$p['id'], 'email' => $p['email'], 'status' => $p['status'], 'reports' => (int)$p['report_count'],
                'expires' => $p['expires_ts'] !== null ? (int)$p['expires_ts'] : null,
                'updated' => (int)$p['updated_ts'],
            ];
        }, $q->fetchAll());
        tl_json(200, ['posts' => $rows]);
    }

    if ($action === 'numbers_list') {
        $cats = $db->query("SELECT id, slug, title, icon, sort_order FROM tl_contact_categories ORDER BY sort_order, id")->fetchAll();
        $items = $db->query("SELECT id, category_id, name, phone, note, url, sort_order FROM tl_contacts ORDER BY sort_order, id")->fetchAll();
        foreach ($cats as &$c) {
            $c['items'] = array_values(array_filter($items, function ($i) use ($c) { return $i['category_id'] == $c['id']; }));
        }
        tl_json(200, ['categories' => $cats]);
    }

    if ($action === 'admins_list') {
        $rows = $db->query("SELECT id, username, email, UNIX_TIMESTAMP(created_at) AS created, UNIX_TIMESTAMP(last_login_at) AS last_login FROM tl_admins ORDER BY id")->fetchAll();
        tl_json(200, ['admins' => $rows, 'me' => (int)$me['id']]);
    }

    if ($action === 'mail_settings') {
        $cfg = tl_config(); $smtp = $cfg['smtp'] ?? [];
        tl_json(200, [
            'mail_from' => tl_mail_from(), 'mode' => !empty($smtp['host']) ? 'smtp' : 'server',
            'host' => $smtp['host'] ?? '', 'port' => (int)($smtp['port'] ?? 465), 'secure' => $smtp['secure'] ?? 'ssl',
            'user' => $smtp['user'] ?? '', 'has_pass' => !empty($smtp['pass']), 'admin_email' => $me['email'],
        ]);
    }

    // ---- Everything below changes data ----
    tl_require_post();
    if ($action === 'logout') { session_destroy(); tl_json(200, ['ok' => true]); }
    tl_check_csrf();
    $id = (int)($_POST['id'] ?? 0);

    if ($action === 'post_save') {
        [$f, $err] = tl_post_fields($_POST, true);
        if ($err) tl_json(400, ['error' => $err]);
        $email = trim((string)($_POST['email'] ?? ''));
        if ($email !== '' && !tl_email($email)) tl_json(400, ['error' => 'Email address is not valid.']);
        $email = $email === '' ? null : tl_email($email);
        $status = ($_POST['status'] ?? 'active') === 'hidden' ? 'hidden' : 'active';
        $pinned = !empty($_POST['pinned']) ? 1 : 0;
        $exp = trim((string)($_POST['expires'] ?? ''));        // YYYY-MM-DD or empty (= never)
        if ($exp !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $exp)) tl_json(400, ['error' => 'Expiry date is not valid.']);
        $expires = $exp === '' ? null : $exp . ' 23:59:59';
        $cols = [$f['type'], $f['category'], $f['title'], $f['body'], $f['price'], $f['name'], $email, $f['phone'], $f['area'],
                 $f['link_url'], $f['link_label'], $f['deadline_text'], $status, $pinned, $expires];
        if ($id) {
            $q = $db->prepare("SELECT image FROM tl_posts WHERE id = ?"); $q->execute([$id]);
            $row = $q->fetch();
            if (!$row) tl_json(404, ['error' => 'Post not found.']);
            $image = $row['image'];
            $new = tl_save_image($_FILES['image'] ?? null);
            if ($new || !empty($_POST['remove_image'])) { tl_delete_image($image); $image = $new; }
            $db->prepare("UPDATE tl_posts SET type=?, category=?, title=?, body=?, price=?, name=?, email=?, phone=?, area=?,
                    link_url=?, link_label=?, deadline_text=?, status=?, pinned=?, expires_at=?, image=?, updated_at=UTC_TIMESTAMP(),
                    report_count = IF(? = 'active', 0, report_count) WHERE id=?")
                ->execute(array_merge($cols, [$image, $status, $id]));
            if ($status === 'active') $db->prepare("DELETE FROM tl_post_reports WHERE post_id = ?")->execute([$id]);
        } else {
            $image = tl_save_image($_FILES['image'] ?? null);
            $db->prepare("INSERT INTO tl_posts (type, category, title, body, price, name, email, phone, area, link_url, link_label,
                    deadline_text, status, pinned, expires_at, image, public_id, created_at, updated_at)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP(), UTC_TIMESTAMP())")
                ->execute(array_merge($cols, [$image, tl_new_public_id()]));
            $id = (int)$db->lastInsertId();
        }
        tl_json(200, ['ok' => true, 'id' => $id]);
    }

    if ($action === 'post_status') {
        $st = ($_POST['status'] ?? '') === 'hidden' ? 'hidden' : 'active';
        $db->prepare("UPDATE tl_posts SET status = ?, report_count = IF(? = 'active', 0, report_count) WHERE id = ?")->execute([$st, $st, $id]);
        if ($st === 'active') $db->prepare("DELETE FROM tl_post_reports WHERE post_id = ?")->execute([$id]);
        tl_json(200, ['ok' => true]);
    }

    if ($action === 'post_pin') {
        $db->prepare("UPDATE tl_posts SET pinned = ? WHERE id = ?")->execute([!empty($_POST['pinned']) ? 1 : 0, $id]);
        tl_json(200, ['ok' => true]);
    }

    if ($action === 'post_renew') {
        $db->prepare("UPDATE tl_posts SET expires_at = UTC_TIMESTAMP() + INTERVAL ? DAY WHERE id = ?")->execute([TL_POST_LIFETIME_DAYS, $id]);
        tl_json(200, ['ok' => true]);
    }

    if ($action === 'post_delete') {
        $q = $db->prepare("SELECT image FROM tl_posts WHERE id = ?"); $q->execute([$id]);
        if ($row = $q->fetch()) tl_delete_image($row['image']);
        $db->prepare("DELETE FROM tl_posts WHERE id = ?")->execute([$id]);
        tl_json(200, ['ok' => true]);
    }

    if ($action === 'cat_save') {
        $title = tl_clean($_POST['title'] ?? '', 80);
        $icon = tl_clean($_POST['icon'] ?? '', 16);
        $sort = (int)($_POST['sort_order'] ?? 0);
        if ($title === '') tl_json(400, ['error' => 'Category needs a name.']);
        $slug = trim(preg_replace('/[^a-z0-9]+/', '-', strtolower($title)), '-') ?: 'category';
        if ($id) {
            $db->prepare("UPDATE tl_contact_categories SET title = ?, icon = ?, sort_order = ? WHERE id = ?")->execute([$title, $icon, $sort, $id]);
        } else {
            $base = $slug; $n = 2;
            $chk = $db->prepare("SELECT COUNT(*) FROM tl_contact_categories WHERE slug = ?");
            while (true) { $chk->execute([$slug]); if (!(int)$chk->fetchColumn()) break; $slug = $base . '-' . $n++; }
            if (!$sort) $sort = 10 + (int)$db->query("SELECT COALESCE(MAX(sort_order), 0) FROM tl_contact_categories")->fetchColumn();
            $db->prepare("INSERT INTO tl_contact_categories (slug, title, icon, sort_order) VALUES (?, ?, ?, ?)")->execute([$slug, $title, $icon, $sort]);
            $id = (int)$db->lastInsertId();
        }
        tl_json(200, ['ok' => true, 'id' => $id]);
    }

    if ($action === 'cat_delete') {
        $db->prepare("DELETE FROM tl_contact_categories WHERE id = ?")->execute([$id]);
        tl_json(200, ['ok' => true]);
    }

    if ($action === 'contact_save') {
        $cat = (int)($_POST['category_id'] ?? 0);
        $name = tl_clean($_POST['name'] ?? '', 100);
        $phone = tl_clean($_POST['phone'] ?? '', 30);
        $note = tl_clean($_POST['note'] ?? '', 120);
        $url = tl_clean($_POST['url'] ?? '', 255);
        $sort = (int)($_POST['sort_order'] ?? 0);
        if ($name === '') tl_json(400, ['error' => 'Contact needs a name.']);
        if (!tl_valid_phone($phone) || $phone === '') tl_json(400, ['error' => "Phone number doesn't look right."]);
        if (!tl_valid_url($url)) tl_json(400, ['error' => 'Website must start with http:// or https://']);
        $chk = $db->prepare("SELECT COUNT(*) FROM tl_contact_categories WHERE id = ?"); $chk->execute([$cat]);
        if (!(int)$chk->fetchColumn()) tl_json(400, ['error' => 'Choose a category.']);
        if ($id) {
            $db->prepare("UPDATE tl_contacts SET category_id = ?, name = ?, phone = ?, note = ?, url = ?, sort_order = ? WHERE id = ?")
                ->execute([$cat, $name, $phone, $note, $url, $sort, $id]);
        } else {
            if (!$sort) { $m = $db->prepare("SELECT COALESCE(MAX(sort_order), 0) FROM tl_contacts WHERE category_id = ?"); $m->execute([$cat]); $sort = 10 + (int)$m->fetchColumn(); }
            $db->prepare("INSERT INTO tl_contacts (category_id, name, phone, note, url, sort_order) VALUES (?, ?, ?, ?, ?, ?)")
                ->execute([$cat, $name, $phone, $note, $url, $sort]);
            $id = (int)$db->lastInsertId();
        }
        tl_json(200, ['ok' => true, 'id' => $id]);
    }

    if ($action === 'contact_delete') {
        $db->prepare("DELETE FROM tl_contacts WHERE id = ?")->execute([$id]);
        tl_json(200, ['ok' => true]);
    }

    if ($action === 'mail_save') {
        $from = tl_email($_POST['mail_from'] ?? '');
        if ($from === '') tl_json(400, ['error' => 'Enter a valid "send from" email address.']);
        $cfg = tl_config();
        $cfg['mail_from'] = $from;
        if (($_POST['mode'] ?? '') === 'smtp') {
            $host = strtolower(trim((string)($_POST['host'] ?? '')));
            $port = (int)($_POST['port'] ?? 0);
            $secure = in_array($_POST['secure'] ?? '', ['ssl', 'tls', 'none'], true) ? $_POST['secure'] : 'ssl';
            $user = trim((string)($_POST['user'] ?? ''));
            $pass = (string)($_POST['pass'] ?? '');
            if (!preg_match('/^[a-z0-9.-]{3,253}$/', $host)) tl_json(400, ['error' => 'Enter the outgoing mail server, e.g. mail.taxilive.site']);
            if ($port < 1 || $port > 65535) tl_json(400, ['error' => 'Enter the port (465 for SSL, 587 for TLS).']);
            if ($user === '') tl_json(400, ['error' => 'Enter the email account username (usually the full email address).']);
            if ($pass === '') $pass = (string)($cfg['smtp']['pass'] ?? '');   // keep the saved password
            if ($pass === '') tl_json(400, ['error' => 'Enter the email account password.']);
            $cfg['smtp'] = ['host' => $host, 'port' => $port, 'secure' => $secure, 'user' => $user, 'pass' => $pass];
        } else {
            unset($cfg['smtp']);
        }
        tl_save_config($cfg);
        tl_json(200, ['ok' => true]);
    }

    if ($action === 'mail_test') {
        $to = tl_email($_POST['to'] ?? '') ?: $me['email'];
        if (!tl_rate('mail_test', tl_ip_hash(), 10, 3600)) tl_json(429, ['error' => 'Too many test emails. Try again later.']);
        $err = null;
        $ok = tl_mail($to, 'Taxi Live test email', "This is a test email from the Taxi Live admin panel.\n\nIf you can read this, members will receive their edit codes.\n\n– Taxi Live", $err);
        if (!$ok) tl_json(400, ['error' => $err ?: 'Sending failed.']);
        tl_json(200, ['ok' => true, 'to' => $to]);
    }

    if ($action === 'password') {
        $q = $db->prepare("SELECT password_hash FROM tl_admins WHERE id = ?"); $q->execute([$me['id']]);
        if (!password_verify((string)($_POST['current'] ?? ''), (string)$q->fetchColumn())) tl_json(400, ['error' => 'Current password is wrong.']);
        $new = (string)($_POST['new'] ?? '');
        if (strlen($new) < 10) tl_json(400, ['error' => 'New password must be at least 10 characters.']);
        $db->prepare("UPDATE tl_admins SET password_hash = ? WHERE id = ?")->execute([password_hash($new, PASSWORD_DEFAULT), $me['id']]);
        tl_json(200, ['ok' => true]);
    }

    if ($action === 'admin_add') {
        $u = trim((string)($_POST['username'] ?? ''));
        $e = tl_email($_POST['email'] ?? '');
        $p = (string)($_POST['password'] ?? '');
        if (!preg_match('/^[A-Za-z0-9_.-]{3,40}$/', $u)) tl_json(400, ['error' => 'Username: 3–40 letters, numbers, dots, dashes or underscores.']);
        if ($e === '') tl_json(400, ['error' => 'Enter a valid email.']);
        if (strlen($p) < 10) tl_json(400, ['error' => 'Password must be at least 10 characters.']);
        $chk = $db->prepare("SELECT COUNT(*) FROM tl_admins WHERE username = ?"); $chk->execute([$u]);
        if ((int)$chk->fetchColumn()) tl_json(400, ['error' => 'That username is taken.']);
        $db->prepare("INSERT INTO tl_admins (username, email, password_hash, created_at) VALUES (?, ?, ?, UTC_TIMESTAMP())")
            ->execute([$u, $e, password_hash($p, PASSWORD_DEFAULT)]);
        tl_json(200, ['ok' => true]);
    }

    if ($action === 'admin_delete') {
        if ($id === (int)$me['id']) tl_json(400, ["error" => "You can't remove yourself."]);
        $db->prepare("DELETE FROM tl_admins WHERE id = ?")->execute([$id]);
        tl_json(200, ['ok' => true]);
    }

    tl_json(400, ['error' => 'Unknown action']);
});
