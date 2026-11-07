<?php
/*
 * Taxi Live – database design (MySQL / MariaDB, utf8mb4). All times are UTC.
 *
 *   tl_admins              admin logins for /admin/
 *   tl_posts               noticeboard adverts & notices (member + admin)
 *   tl_post_reports        one row per (post, visitor) report
 *   tl_login_codes         6-digit email codes members use to edit their posts
 *   tl_contact_categories  "Important numbers" groups (Insurance, Carriage Office …)
 *   tl_contacts            the numbers themselves
 *   tl_rate_events         anti-spam counters (posting, codes, logins)
 *   tl_meta                key/value settings (schema version, install date)
 */
declare(strict_types=1);

function tl_schema_sql(): array {
    $t = "ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
    return [
        "CREATE TABLE IF NOT EXISTS tl_admins (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            username VARCHAR(40) NOT NULL,
            email VARCHAR(190) NOT NULL,
            password_hash VARCHAR(255) NOT NULL,
            created_at DATETIME NOT NULL,
            last_login_at DATETIME NULL,
            UNIQUE KEY uq_admin_username (username)
        ) $t",
        "CREATE TABLE IF NOT EXISTS tl_posts (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            public_id VARCHAR(24) NOT NULL,
            type ENUM('advert','notice') NOT NULL,
            category VARCHAR(20) NOT NULL DEFAULT '',
            title VARCHAR(160) NOT NULL,
            body TEXT NOT NULL,
            price VARCHAR(30) NOT NULL DEFAULT '',
            name VARCHAR(60) NOT NULL,
            email VARCHAR(190) NULL,
            phone VARCHAR(20) NOT NULL DEFAULT '',
            area VARCHAR(60) NOT NULL DEFAULT '',
            image VARCHAR(80) NULL,
            link_url VARCHAR(500) NOT NULL DEFAULT '',
            link_label VARCHAR(60) NOT NULL DEFAULT '',
            deadline_text VARCHAR(80) NOT NULL DEFAULT '',
            status ENUM('active','hidden') NOT NULL DEFAULT 'active',
            pinned TINYINT(1) NOT NULL DEFAULT 0,
            report_count INT UNSIGNED NOT NULL DEFAULT 0,
            ip_hash CHAR(64) NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            expires_at DATETIME NULL,
            UNIQUE KEY uq_posts_public (public_id),
            KEY ix_posts_live (status, expires_at),
            KEY ix_posts_email (email)
        ) $t",
        "CREATE TABLE IF NOT EXISTS tl_post_reports (
            post_id INT UNSIGNED NOT NULL,
            ip_hash CHAR(64) NOT NULL,
            created_at DATETIME NOT NULL,
            PRIMARY KEY (post_id, ip_hash),
            CONSTRAINT fk_reports_post FOREIGN KEY (post_id) REFERENCES tl_posts(id) ON DELETE CASCADE
        ) $t",
        "CREATE TABLE IF NOT EXISTS tl_login_codes (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            email VARCHAR(190) NOT NULL,
            code_hash CHAR(64) NOT NULL,
            attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL,
            expires_at DATETIME NOT NULL,
            used_at DATETIME NULL,
            KEY ix_codes_email (email, expires_at)
        ) $t",
        "CREATE TABLE IF NOT EXISTS tl_contact_categories (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            slug VARCHAR(40) NOT NULL,
            title VARCHAR(80) NOT NULL,
            icon VARCHAR(16) NOT NULL DEFAULT '',
            sort_order INT NOT NULL DEFAULT 0,
            UNIQUE KEY uq_cat_slug (slug)
        ) $t",
        "CREATE TABLE IF NOT EXISTS tl_contacts (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            category_id INT UNSIGNED NOT NULL,
            name VARCHAR(100) NOT NULL,
            phone VARCHAR(30) NOT NULL,
            note VARCHAR(120) NOT NULL DEFAULT '',
            url VARCHAR(255) NOT NULL DEFAULT '',
            sort_order INT NOT NULL DEFAULT 0,
            KEY ix_contacts_cat (category_id, sort_order),
            CONSTRAINT fk_contacts_cat FOREIGN KEY (category_id) REFERENCES tl_contact_categories(id) ON DELETE CASCADE
        ) $t",
        "CREATE TABLE IF NOT EXISTS tl_rate_events (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            action VARCHAR(20) NOT NULL,
            subject CHAR(64) NOT NULL,
            created_at DATETIME NOT NULL,
            KEY ix_rate (action, subject, created_at)
        ) $t",
        "CREATE TABLE IF NOT EXISTS tl_meta (
            k VARCHAR(40) PRIMARY KEY,
            v TEXT NOT NULL
        ) $t",
    ];
}

/** Starting list of important numbers (only inserted when the table is empty). */
function tl_seed_contacts(): array {
    return [
        ['ncts-nta', 'NCTS & NTA', '🛠️', [
            ['NCTS booking line', '01 413 5960', '', ''],
            ['NTA & Suitability booking', '0818 064 000', 'nationaltransport.ie', 'https://www.nationaltransport.ie'],
            ['NTA & Suitability booking (alt.)', '01 879 8300', '', ''],
            ['Meter Sealing', '01 413 5951', '', ''],
        ]],
        ['carriage-office', 'Carriage Office', '🚓', [
            ['Irishtown', '01 666 9600', '', ''], ['Store Street', '01 666 8000', '', ''],
            ['Finglas', '01 666 7500', '', ''], ['Tallaght', '01 666 6000', '', ''], ['Shankill', '01 666 5900', '', ''],
        ]],
        ['taxi-apps', 'Taxi Apps', '📱', [
            ['FREENOW', '01 485 4642', '', ''], ['Bolt', '01 233 8911', '', ''],
        ]],
        ['insurance', 'Insurance', '🛡️', [
            ['AXA', '01 892 7578', '', ''], ['Allianz', '01 448 4848', '', ''], ['Liberty', '0494 368 100', '', ''],
            ['Zego', '01 526 0877', '', ''], ['Taxifare', '01 485 1996', '', ''], ['Brian J Pearse', '01 667 4985', '', ''],
            ['First Ireland', '01 882 0834', '', ''],
        ]],
        ['rentals-mechanics', 'Taxi Rentals & Mechanics', '🔧', [
            ['Mechanic 24/7', '01 808 1443', '24 hours', ''], ['Bobo Motors', '087 225 7092', '', ''],
            ['VIP Motors', '087 268885', '', ''], ['Autoclass', '087 115 5530', '', ''],
            ['Global Taxis', '087 240 4702', '', ''], ['Robinson Autos', '01 857 1965', '', ''],
        ]],
        ['meter-installers', 'Meter Installers', '🧾', [
            ['Paul Whelan TMS', '01 895 7567', '', ''], ['Bernard – Belvedere Pl.', '087 258 5353', '', ''],
            ['Ken Skan', '01 830 8877', '', ''], ['Emmett Skan', '087 282 2686', '', ''],
            ['Paul McCormack', '086 257 5345', 'Longford', ''], ['Vinny Murray', '086 823 5773', 'Sligo', ''],
        ]],
    ];
}

/** Create tables, seed numbers, and copy posts from the old JSON store. Safe to run more than once. */
function tl_install_schema(PDO $db): array {
    $log = [];
    foreach (tl_schema_sql() as $sql) $db->exec($sql);
    $log[] = 'Tables ready';

    if ((int)$db->query("SELECT COUNT(*) FROM tl_contact_categories")->fetchColumn() === 0) {
        $ci = $db->prepare("INSERT INTO tl_contact_categories (slug, title, icon, sort_order) VALUES (?, ?, ?, ?)");
        $pi = $db->prepare("INSERT INTO tl_contacts (category_id, name, phone, note, url, sort_order) VALUES (?, ?, ?, ?, ?, ?)");
        $n = 0;
        foreach (tl_seed_contacts() as $i => [$slug, $title, $icon, $items]) {
            $ci->execute([$slug, $title, $icon, ($i + 1) * 10]);
            $cid = (int)$db->lastInsertId();
            foreach ($items as $j => [$name, $phone, $note, $url]) { $pi->execute([$cid, $name, $phone, $note, $url, ($j + 1) * 10]); $n++; }
        }
        $log[] = "Added $n important numbers";
    }

    if ((int)$db->query("SELECT COUNT(*) FROM tl_posts")->fetchColumn() === 0) {
        $ins = $db->prepare("INSERT INTO tl_posts (public_id, type, category, title, body, price, name, email, phone, area, image,
            link_url, link_label, deadline_text, status, pinned, report_count, created_at, updated_at, expires_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, NULL, ?, ?, ?, ?, ?, ?, ?, ?, ?, FROM_UNIXTIME(?), FROM_UNIXTIME(?), FROM_UNIXTIME(?))");
        $moved = 0;
        foreach ([dirname(TL_WEBROOT) . '/taxilive-data/posts.json', TL_WEBROOT . '/data/posts.json'] as $file) {
            if (!is_file($file)) continue;
            $old = json_decode((string)file_get_contents($file), true);
            foreach (($old['posts'] ?? []) as $p) {
                $type = ($p['type'] ?? '') === 'notice' ? 'notice' : 'advert';
                $cat = $type === 'advert' ? (($p['type'] ?? '') === 'wanted' ? 'wanted' : ($p['category'] ?? 'other')) : '';
                if ($type === 'advert' && !in_array($cat, TL_CATEGORIES, true)) $cat = 'other';
                $created = (int)($p['created'] ?? time());
                $ins->execute([
                    substr((string)($p['id'] ?? tl_new_public_id()), 0, 24), $type, $cat,
                    (string)($p['title'] ?? ''), (string)($p['body'] ?? ''), (string)($p['price'] ?? ''), (string)($p['name'] ?? ''),
                    (string)($p['phone'] ?? ''), (string)($p['area'] ?? ''), $p['image'] ?? null, '', '', '',
                    !empty($p['hidden']) ? 'hidden' : 'active', 0, count($p['reports'] ?? []),
                    $created, $created, $created + TL_POST_LIFETIME_DAYS * 86400,
                ]);
                $moved++;
            }
            @rename($file, $file . '.migrated');
        }
        // The pinned NTA consultation notice (previously in assets/pinned.js).
        $ins->execute(['nta-dispatch-2026', 'notice', '',
            '🚕 Taxi drivers: make your voice heard in the NTA Dispatch Operator Consultation',
            "I have submitted my response. Other drivers can use these points as a guide and add their own experiences:\n\n" .
            "1. Fair licence fees and reporting requirements according to operator size.\n" .
            "2. A genuine Irish operating presence and accountable management.\n" .
            "3. Clear annual licence renewal procedures.\n" .
            "4. Transparent commissions and deductions, fair deactivation appeals, and freedom to use multiple apps.\n" .
            "5. Accurate reporting of passenger charges, platform deductions and driver payments.\n" .
            "6. Oppose platform-set fixed taxi fares replacing the meter. Require transparent pricing, no hidden booking charges and fair compensation for drivers affected by late cancellations or no-shows.\n" .
            "7. Effective enforcement and a review of how commissions affect drivers’ earnings.\n\n" .
            "To respond, sign in or register on the NTA consultation portal, select “SPSV driver or vehicle licence holder” if applicable, and answer the relevant questions.\n\n" .
            "Review your answers and click “Submit Now”. Saving a draft alone does not submit it. Keep your confirmation reference.\n\n" .
            "These are suggested changes to the rules. Write in your own words and explain how the issues affect your work.",
            '', 'Arif', '086 206 5942', '', null,
            'https://consult.nationaltransport.ie/en/consultation/spsv-dispatch-operator-consultation', 'Respond on the NTA portal',
            'Deadline: 18 December 2026 at 5pm', 'active', 1, 0,
            strtotime('2026-11-06 02:00:00 UTC'), strtotime('2026-11-06 02:00:00 UTC'), strtotime('2026-12-18 17:00:00 UTC'),
        ]);
        $log[] = "Moved $moved existing posts" . ($moved ? '' : ' (none found)') . ' and the pinned NTA notice';
    }

    $db->prepare("REPLACE INTO tl_meta (k, v) VALUES ('schema_version', ?)")->execute([(string)TL_SCHEMA_VERSION]);
    return $log;
}
