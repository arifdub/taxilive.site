<?php
/*
 * Taxi Live – public noticeboard API.
 *   GET  ?action=list                 live posts (pinned first)
 *   POST action=create (multipart)    new member post (email required, kept private)
 *   POST action=report  id            report a post (3 reports hides it)
 * Before /setup/ has been run, the old JSON-file version answers instead.
 */
declare(strict_types=1);
require dirname(__DIR__) . '/lib/bootstrap.php';

if (!tl_installed()) { require dirname(__DIR__) . '/lib/legacy_posts.php'; exit; }

tl_run(function () {
    $action = $_GET['action'] ?? $_POST['action'] ?? 'list';
    $db = tl_db();

    if ($action === 'list') {
        $rows = $db->query("SELECT " . TL_POST_COLS . " FROM tl_posts
            WHERE status = 'active' AND (expires_at IS NULL OR expires_at > UTC_TIMESTAMP())
            ORDER BY pinned DESC, created_at DESC LIMIT 200")->fetchAll();
        tl_json(200, ['db' => true, 'posts' => array_map('tl_public_post', $rows)]);
    }

    tl_require_post();

    if ($action === 'create') {
        if (!empty($_POST['website'])) tl_json(201, ['ok' => true]); // honeypot
        [$f, $err] = tl_post_fields($_POST);
        if ($err) tl_json(400, ['error' => $err]);
        $email = tl_email($_POST['email'] ?? '');
        if ($email === '') tl_json(400, ['error' => 'Please add your email – you need it to edit or remove your post later.']);
        if (!tl_rate('post', tl_ip_hash(), 3, 3600)) tl_json(429, ['error' => "You've posted a lot recently. Please try again later."]);
        $image = tl_save_image($_FILES['image'] ?? null);
        $id = tl_new_public_id();
        $db->prepare("INSERT INTO tl_posts (public_id, type, category, title, body, price, name, email, phone, area, image,
                status, ip_hash, created_at, updated_at, expires_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'active', ?, UTC_TIMESTAMP(), UTC_TIMESTAMP(), UTC_TIMESTAMP() + INTERVAL ? DAY)")
            ->execute([$id, $f['type'], $f['category'], $f['title'], $f['body'], $f['price'], $f['name'], $email,
                       $f['phone'], $f['area'], $image, tl_ip_hash(), TL_POST_LIFETIME_DAYS]);
        tl_json(201, ['ok' => true, 'id' => $id]);
    }

    if ($action === 'report') {
        $q = $db->prepare("SELECT id, pinned FROM tl_posts WHERE public_id = ?");
        $q->execute([(string)($_POST['id'] ?? '')]);
        $post = $q->fetch();
        if ($post && !$post['pinned']) {
            $ins = $db->prepare("INSERT IGNORE INTO tl_post_reports (post_id, ip_hash, created_at) VALUES (?, ?, UTC_TIMESTAMP())");
            $ins->execute([$post['id'], tl_ip_hash()]);
            if ($ins->rowCount()) {
                $db->prepare("UPDATE tl_posts SET report_count = report_count + 1,
                        status = IF(report_count >= ?, 'hidden', status) WHERE id = ?") // MySQL applies SET left to right
                    ->execute([TL_REPORTS_TO_HIDE, $post['id']]);
            }
        }
        tl_json(200, ['ok' => true]);
    }

    tl_json(400, ['error' => 'Unknown action']);
});
