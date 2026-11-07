<?php
/*
 * Taxi Live – one-time setup.
 * Connects to the MySQL database created in cPanel, creates the tables,
 * imports existing posts and numbers, and creates the first admin.
 * Locks itself once finished.
 */
declare(strict_types=1);
require dirname(__DIR__) . '/lib/bootstrap.php';
require dirname(__DIR__) . '/lib/schema.php';

header('X-Frame-Options: DENY');
header('Cache-Control: no-store');
header('X-Robots-Tag: noindex');
tl_session();

function h($s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

$attemptsFile = tl_data_dir() . '/setup-attempts.json';
function setup_failures(string $file): array {
    $a = is_file($file) ? json_decode((string)file_get_contents($file), true) : [];
    return array_values(array_filter(is_array($a) ? $a : [], function ($t) { return $t > time() - 3600; }));
}

$installed = tl_installed();
$errors = [];
$done = null;
$v = [
    'db_host' => 'localhost', 'db_name' => '', 'db_user' => '',
    'admin_user' => '', 'admin_email' => '',
    'mail_from' => 'noreply@' . preg_replace('/^www\./', '', preg_replace('/:\d+$/', '', $_SERVER['HTTP_HOST'] ?? 'taxilive.site')),
];

if (!$installed && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    foreach ($v as $k => $_) $v[$k] = trim((string)($_POST[$k] ?? ''));
    $dbPass = (string)($_POST['db_pass'] ?? '');
    $pw = (string)($_POST['admin_pass'] ?? '');
    $pw2 = (string)($_POST['admin_pass2'] ?? '');

    if (!hash_equals(tl_csrf(), (string)($_POST['csrf'] ?? ''))) $errors[] = 'Your session expired. Please submit the form again.';
    if (count(setup_failures($attemptsFile)) >= 10) $errors[] = 'Too many failed attempts. Please wait an hour and try again.';
    if (!preg_match('/^(localhost|127\.0\.0\.1)(:\d{2,5})?$/', $v['db_host'])) $errors[] = 'Database host must be "localhost" (that is what cPanel uses).';
    if (!preg_match('/^[A-Za-z0-9_]{1,64}$/', $v['db_name'])) $errors[] = 'Enter the database name exactly as cPanel shows it (e.g. abc123_taxilive).';
    if (!preg_match('/^[A-Za-z0-9_.-]{1,80}$/', $v['db_user'])) $errors[] = 'Enter the database user exactly as cPanel shows it.';
    if (!preg_match('/^[A-Za-z0-9_.-]{3,40}$/', $v['admin_user'])) $errors[] = 'Admin username: 3–40 letters, numbers, dots, dashes or underscores.';
    if (!tl_email($v['admin_email'])) $errors[] = 'Enter a valid admin email address.';
    if (!tl_email($v['mail_from'])) $errors[] = 'Enter a valid "send emails from" address.';
    if (strlen($pw) < 10) $errors[] = 'Admin password must be at least 10 characters.';
    elseif ($pw !== $pw2) $errors[] = "The two admin passwords don't match.";

    if (!$errors) {
        $dbCfg = ['host' => $v['db_host'], 'name' => $v['db_name'], 'user' => $v['db_user'], 'pass' => $dbPass];
        try {
            $pdo = tl_connect($dbCfg);
        } catch (Throwable $e) {
            $fails = setup_failures($attemptsFile); $fails[] = time();
            @file_put_contents($attemptsFile, json_encode($fails));
            $errors[] = 'Could not connect to the database. Check the name, user and password, and that the user is added to the database with ALL PRIVILEGES in cPanel.';
        }
        if (!$errors) {
            try {
                $log = tl_install_schema($pdo);
                $pdo->prepare("DELETE FROM tl_admins WHERE username = ?")->execute([$v['admin_user']]);
                $pdo->prepare("INSERT INTO tl_admins (username, email, password_hash, created_at) VALUES (?, ?, ?, UTC_TIMESTAMP())")
                    ->execute([$v['admin_user'], tl_email($v['admin_email']), password_hash($pw, PASSWORD_DEFAULT)]);
                $pdo->prepare("REPLACE INTO tl_meta (k, v) VALUES ('installed_at', UTC_TIMESTAMP())")->execute();
                $log[] = 'Admin account "' . $v['admin_user'] . '" created';

                $config = [
                    'db' => $dbCfg,
                    'secret' => bin2hex(random_bytes(32)),
                    'mail_from' => tl_email($v['mail_from']),
                    'installed_at' => gmdate('c'),
                ];
                $php = "<?php\n// Taxi Live settings – created by /setup/. Keep private.\nreturn " . var_export($config, true) . ";\n";
                $tmp = tl_config_path() . '.tmp';
                if (@file_put_contents($tmp, $php, LOCK_EX) === false || !@rename($tmp, tl_config_path())) {
                    throw new RuntimeException('Could not save the settings file in ' . tl_data_dir());
                }
                @chmod(tl_config_path(), 0600);
                @unlink($attemptsFile);
                $done = $log;
                $installed = true;
            } catch (Throwable $e) {
                $errors[] = 'Setup failed: ' . $e->getMessage();
            }
        }
    }
}
?><!doctype html>
<html lang="en-IE">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="robots" content="noindex, nofollow">
  <title>Taxi Live Setup</title>
  <link rel="icon" href="../assets/favicon.svg" type="image/svg+xml">
  <link rel="stylesheet" href="../assets/style.css?v=13">
</head>
<body class="page-post">
  <header class="topbar">
    <div class="wrap topbar__inner">
      <a href="../" class="brand"><img src="../assets/logo.svg" alt="" class="brand__logo" width="40" height="40"><span class="brand__name">Taxi<span>Live</span></span></a>
      <span class="backlink">Setup</span>
    </div>
  </header>
  <main class="postpage">
<?php if ($done): ?>
    <div class="success">
      <div class="success__icon">✅</div>
      <h1>Setup complete</h1>
      <ul class="setup-log"><?php foreach ($done as $line): ?><li>✔ <?= h($line) ?></li><?php endforeach; ?></ul>
      <p class="muted">This setup page is now locked.</p>
      <div class="success__actions">
        <a class="btn btn--primary btn--block" href="../admin/">Open the admin panel</a>
        <a class="btn btn--dark btn--block" href="../">View the site</a>
      </div>
    </div>
<?php elseif ($installed): ?>
    <div class="success">
      <div class="success__icon">🔒</div>
      <h1>Already set up</h1>
      <p class="muted">Taxi Live is connected to its database. This page is locked.</p>
      <div class="success__actions"><a class="btn btn--primary btn--block" href="../admin/">Go to the admin panel</a></div>
    </div>
<?php else: ?>
    <div class="postpage__head">
      <h1>Set up Taxi Live</h1>
      <p class="muted">Connect the site to the MySQL database you created in cPanel and choose your admin login. Takes one minute.</p>
    </div>
    <?php if ($errors): ?><div class="form-error" role="alert"><?php foreach ($errors as $e): ?><div><?= h($e) ?></div><?php endforeach; ?></div><?php endif; ?>
    <form class="formcard" method="post" autocomplete="off">
      <input type="hidden" name="csrf" value="<?= h(tl_csrf()) ?>">
      <div class="fsection" style="border:0;padding-top:0">1 · Database (from cPanel → MySQL® Databases)</div>
      <div class="fgroup">
        <label class="flabel" for="db_name">Database name</label>
        <input class="finput" id="db_name" name="db_name" value="<?= h($v['db_name']) ?>" placeholder="e.g. abc123_taxilive" required>
        <p class="fhint">Include the prefix cPanel adds (your account name and an underscore).</p>
      </div>
      <div class="frow">
        <div class="fgroup">
          <label class="flabel" for="db_user">Database user</label>
          <input class="finput" id="db_user" name="db_user" value="<?= h($v['db_user']) ?>" placeholder="e.g. abc123_taxi" required>
        </div>
        <div class="fgroup">
          <label class="flabel" for="db_pass">Database password</label>
          <input class="finput" id="db_pass" name="db_pass" type="password" required>
        </div>
      </div>
      <div class="fgroup">
        <label class="flabel" for="db_host">Database host</label>
        <input class="finput" id="db_host" name="db_host" value="<?= h($v['db_host']) ?>" required>
        <p class="fhint">Leave as <b>localhost</b> on cPanel.</p>
      </div>

      <div class="fsection">2 · Your admin login</div>
      <div class="frow">
        <div class="fgroup">
          <label class="flabel" for="admin_user">Username</label>
          <input class="finput" id="admin_user" name="admin_user" value="<?= h($v['admin_user']) ?>" autocomplete="username" required>
        </div>
        <div class="fgroup">
          <label class="flabel" for="admin_email">Email</label>
          <input class="finput" id="admin_email" name="admin_email" type="email" value="<?= h($v['admin_email']) ?>" required>
        </div>
      </div>
      <div class="frow">
        <div class="fgroup">
          <label class="flabel" for="admin_pass">Password <span class="opt">10+ characters</span></label>
          <input class="finput" id="admin_pass" name="admin_pass" type="password" autocomplete="new-password" required minlength="10">
        </div>
        <div class="fgroup">
          <label class="flabel" for="admin_pass2">Repeat password</label>
          <input class="finput" id="admin_pass2" name="admin_pass2" type="password" autocomplete="new-password" required minlength="10">
        </div>
      </div>

      <div class="fsection">3 · Emails to members</div>
      <div class="fgroup">
        <label class="flabel" for="mail_from">Send emails from</label>
        <input class="finput" id="mail_from" name="mail_from" type="email" value="<?= h($v['mail_from']) ?>" required>
        <p class="fhint">Members get their 6-digit edit code from this address. Use an address on your own domain – create it in cPanel → Email Accounts so emails aren't marked as spam.</p>
      </div>

      <button class="btn btn--primary btn--block btn--lg" type="submit">Set up Taxi Live</button>
      <p class="fineprint">Existing noticeboard posts and the important numbers are copied into the database automatically.</p>
    </form>
<?php endif; ?>
  </main>
</body>
</html>
