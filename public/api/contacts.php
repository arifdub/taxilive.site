<?php
/* Taxi Live – public list of important numbers, grouped by category. */
declare(strict_types=1);
require dirname(__DIR__) . '/lib/bootstrap.php';

if (!tl_installed()) tl_json(503, ['error' => 'Not set up yet']);

tl_run(function () {
    $db = tl_db();
    $cats = $db->query("SELECT id, slug, title, icon FROM tl_contact_categories ORDER BY sort_order, id")->fetchAll();
    $items = $db->query("SELECT category_id, name, phone, note, url FROM tl_contacts ORDER BY sort_order, id")->fetchAll();
    $out = [];
    foreach ($cats as $c) {
        $list = [];
        foreach ($items as $i) {
            if ($i['category_id'] == $c['id']) $list[] = ['name' => $i['name'], 'phone' => $i['phone'], 'note' => $i['note'], 'url' => $i['url']];
        }
        $out[] = ['id' => $c['slug'], 'title' => $c['title'], 'icon' => $c['icon'], 'items' => $list];
    }
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: public, max-age=60');
    echo json_encode(['categories' => $out], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
});
