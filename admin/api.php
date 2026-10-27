<?php
// JSON API used by the admin editor. Every action except login/setup needs a signed-in admin + CSRF token.
declare(strict_types=1);
require __DIR__ . '/lib.php';

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');

function reply(array $j, int $code = 200): never { http_response_code($code); echo json_encode($j, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE); exit; }
function fail(string $msg, int $code = 400): never { reply(['ok' => false, 'error' => $msg], $code); }
function user_out(array $u): array { return ['username' => $u['username'], 'name' => $u['name'] ?? '', 'created' => $u['created'] ?? '', 'lastLogin' => $u['lastLogin'] ?? '', 'createdBy' => $u['createdBy'] ?? '']; }

start_session();
$action = $_GET['action'] ?? '';
$isPost = $_SERVER['REQUEST_METHOD'] === 'POST';
$in = [];
if ($isPost && str_starts_with($_SERVER['CONTENT_TYPE'] ?? '', 'application/json')) {
    $in = json_decode((string)file_get_contents('php://input'), true) ?: [];
} elseif ($isPost) {
    $in = $_POST;
}
if ($isPost && !hash_equals($_SESSION['csrf'], (string)($_SERVER['HTTP_X_CSRF'] ?? ''))) fail('Your session expired. Please reload the page.', 403);

try {
    /* ---------- no login needed ---------- */
    if ($action === 'state') {
        $u = current_user();
        reply(['ok' => true, 'csrf' => $_SESSION['csrf'], 'setup' => count(users()) === 0, 'user' => $u ? user_out($u) : null]);
    }
    if ($action === 'setup' && $isPost) {
        if (count(users()) > 0) fail('Setup has already been done.', 403);
        $name = trim((string)($in['username'] ?? ''));
        if (!valid_username($name)) fail('Usernames are 3–60 letters, numbers, dots, dashes or @.');
        if ($p = password_problem((string)($in['password'] ?? ''))) fail($p);
        save_users([['username' => $name, 'name' => clean_text($in['name'] ?? '', 80), 'hash' => password_hash((string)$in['password'], PASSWORD_DEFAULT), 'created' => date('c'), 'createdBy' => 'setup', 'lastLogin' => date('c')]]);
        session_regenerate_id(true);
        $_SESSION['user'] = $name;
        reply(['ok' => true]);
    }
    if ($action === 'login' && $isPost) {
        $name = trim((string)($in['username'] ?? ''));
        if ($wait = login_locked($name)) fail('Too many attempts. Try again in ' . ceil($wait / 60) . ' minutes.', 429);
        $u = find_user($name);
        if (!$u || !password_verify((string)($in['password'] ?? ''), $u['hash'])) { usleep(300000); login_failed($name); fail('Wrong username or password.', 401); }
        login_ok($name);
        $all = users();
        foreach ($all as &$x) if ($x['username'] === $u['username']) {
            $x['lastLogin'] = date('c');
            if (password_needs_rehash($x['hash'], PASSWORD_DEFAULT)) $x['hash'] = password_hash((string)$in['password'], PASSWORD_DEFAULT);
        }
        save_users($all);
        session_regenerate_id(true);
        $_SESSION['user'] = $u['username'];
        reply(['ok' => true]);
    }

    /* ---------- signed-in admins only ---------- */
    $me = current_user();
    if (!$me) fail('Please sign in.', 401);

    switch ($action) {
        case 'logout':
            $_SESSION = [];
            session_destroy();
            reply(['ok' => true]);

        case 'content':
            reply(['ok' => true, 'content' => load_content(), 'version' => content_version()]);

        case 'save':
            if (!$isPost) fail('POST only', 405);
            if (($in['version'] ?? '') !== content_version()) fail('Someone else saved changes since you opened the editor. Copy any text you need, then reload to get the latest version.', 409);
            $clean = clean_content((array)($in['content'] ?? []));
            publish($clean, $me['username']);
            reply(['ok' => true, 'content' => load_content(), 'version' => content_version()]);

        case 'upload':
            if (!$isPost) fail('POST only', 405);
            if (empty($_FILES['photo'])) fail('No photo received.');
            reply(['ok' => true, 'src' => handle_upload($_FILES['photo'])]);

        case 'library':
            reply(['ok' => true, 'images' => image_library()]);

        case 'backups':
            $list = [];
            foreach (array_reverse(glob(BACKUP_DIR . '/content-*.json') ?: []) as $f) {
                preg_match('/content-(\d{8})-(\d{6})-?([^.]*)\.json$/', basename($f), $m);
                $list[] = ['file' => basename($f), 'at' => $m ? date('c', strtotime($m[1] . 'T' . $m[2])) : '', 'by' => $m[3] ?? ''];
            }
            reply(['ok' => true, 'backups' => $list]);

        case 'restore':
            if (!$isPost) fail('POST only', 405);
            $file = basename((string)($in['file'] ?? ''));
            if (!preg_match('/^content-[A-Za-z0-9-]+\.json$/', $file) || !is_file(BACKUP_DIR . "/$file")) fail('Backup not found.', 404);
            $c = json_decode((string)file_get_contents(BACKUP_DIR . "/$file"), true);
            if (!is_array($c)) fail('That backup is damaged.');
            publish(clean_content($c), $me['username']);
            reply(['ok' => true, 'content' => load_content(), 'version' => content_version()]);

        case 'users':
            reply(['ok' => true, 'users' => array_map('user_out', users()), 'me' => $me['username']]);

        case 'add_user':
            if (!$isPost) fail('POST only', 405);
            $name = trim((string)($in['username'] ?? ''));
            if (!valid_username($name)) fail('Usernames are 3–60 letters, numbers, dots, dashes or @.');
            if (find_user($name)) fail('That username is already taken.');
            if ($p = password_problem((string)($in['password'] ?? ''))) fail($p);
            $all = users();
            $all[] = ['username' => $name, 'name' => clean_text($in['name'] ?? '', 80), 'hash' => password_hash((string)$in['password'], PASSWORD_DEFAULT), 'created' => date('c'), 'createdBy' => $me['username'], 'lastLogin' => ''];
            save_users($all);
            reply(['ok' => true]);

        case 'set_password':
            if (!$isPost) fail('POST only', 405);
            $target = (string)($in['username'] ?? '');
            $self = strcasecmp($target, $me['username']) === 0;
            if ($self && !password_verify((string)($in['current'] ?? ''), $me['hash'])) fail('Your current password is not right.');
            if ($p = password_problem((string)($in['password'] ?? ''))) fail($p);
            $all = users(); $found = false;
            foreach ($all as &$x) if (strcasecmp($x['username'], $target) === 0) { $x['hash'] = password_hash((string)$in['password'], PASSWORD_DEFAULT); $found = true; }
            unset($x);
            if (!$found) fail('Admin not found.', 404);
            save_users($all);
            reply(['ok' => true]);

        case 'delete_user':
            if (!$isPost) fail('POST only', 405);
            $target = (string)($in['username'] ?? '');
            if (strcasecmp($target, $me['username']) === 0) fail('You can’t remove your own account. Ask another admin to do it.');
            $all = users();
            if (count($all) <= 1) fail('There must always be at least one admin.');
            $left = array_values(array_filter($all, fn($x) => strcasecmp($x['username'], $target) !== 0));
            if (count($left) === count($all)) fail('Admin not found.', 404);
            save_users($left);
            reply(['ok' => true]);
    }
    fail('Unknown action.', 404);
} catch (Throwable $e) {
    fail($e->getMessage(), 500);
}
