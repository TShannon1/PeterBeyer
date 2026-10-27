<?php
// Shared helpers for the Peter Beyer website admin.
declare(strict_types=1);

const SITE_ROOT   = __DIR__ . '/..';
const DATA_DIR    = __DIR__ . '/data';
const CONTENT     = DATA_DIR . '/content.json';
const USERS_FILE  = DATA_DIR . '/users.php';
const LOCKS_FILE  = DATA_DIR . '/logins.php';
const BACKUP_DIR  = DATA_DIR . '/backups';
const UPLOAD_DIR  = SITE_ROOT . '/uploads';
const MAX_BACKUPS = 40;
const PHP_GUARD   = "<?php http_response_code(404); exit; ?>\n";

require_once __DIR__ . '/render.php';

function h(?string $s): string { return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }

/* ---------- guarded JSON files (users, login attempts) ---------- */
function read_guarded(string $file, array $default): array {
    if (!is_file($file)) return $default;
    $raw = (string)file_get_contents($file);
    $raw = preg_replace('/^<\?php.*?\?>\s*/s', '', $raw);
    $data = json_decode($raw, true);
    return is_array($data) ? $data : $default;
}
function write_guarded(string $file, array $data): void {
    write_atomic($file, PHP_GUARD . json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
}
function write_atomic(string $file, string $body): void {
    $tmp = $file . '.tmp' . bin2hex(random_bytes(4));
    if (file_put_contents($tmp, $body, LOCK_EX) === false || !rename($tmp, $file)) {
        @unlink($tmp);
        throw new RuntimeException('Could not write ' . basename($file) . '. Check the folder is writable on the server.');
    }
}

/* ---------- session & auth ---------- */
function start_session(): void {
    if (session_status() === PHP_SESSION_ACTIVE) return;
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    session_name('pbadmin');
    session_set_cookie_params(['lifetime' => 0, 'path' => dirname($_SERVER['SCRIPT_NAME']) ?: '/', 'secure' => $https, 'httponly' => true, 'samesite' => 'Strict']);
    session_start();
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(32));
    // Sign out after 8 hours idle
    if (!empty($_SESSION['user']) && time() - ($_SESSION['seen'] ?? 0) > 8 * 3600) { $_SESSION = ['csrf' => bin2hex(random_bytes(32))]; }
    $_SESSION['seen'] = time();
}
function users(): array { return read_guarded(USERS_FILE, ['users' => []])['users']; }
function save_users(array $u): void { write_guarded(USERS_FILE, ['users' => array_values($u)]); }
function find_user(string $name): ?array {
    foreach (users() as $u) if (strcasecmp($u['username'], $name) === 0) return $u;
    return null;
}
function current_user(): ?array {
    if (empty($_SESSION['user'])) return null;
    $u = find_user($_SESSION['user']);
    if (!$u) { unset($_SESSION['user']); return null; }
    return $u;
}
function valid_username(string $s): bool { return (bool)preg_match('/^[A-Za-z0-9._@-]{3,60}$/', $s); }
function password_problem(string $p): ?string {
    if (strlen($p) < 10) return 'Passwords need at least 10 characters.';
    if (strlen($p) > 200) return 'That password is too long.';
    return null;
}

/* login throttling: 5 failures per IP+username → 15 minute lock */
function throttle_key(string $user): string { return hash('sha256', ($_SERVER['REMOTE_ADDR'] ?? '') . '|' . strtolower($user)); }
function login_locked(string $user): int {
    $all = read_guarded(LOCKS_FILE, []);
    $e = $all[throttle_key($user)] ?? null;
    if (!$e) return 0;
    if ($e['n'] >= 5 && time() - $e['t'] < 900) return 900 - (time() - $e['t']);
    return 0;
}
function login_failed(string $user): void {
    $all = read_guarded(LOCKS_FILE, []);
    foreach ($all as $k => $e) if (time() - $e['t'] > 3600) unset($all[$k]);
    $k = throttle_key($user);
    $n = (($all[$k]['n'] ?? 0) >= 5) ? 1 : ($all[$k]['n'] ?? 0) + 1;
    $all[$k] = ['n' => $n, 't' => time()];
    write_guarded(LOCKS_FILE, $all);
}
function login_ok(string $user): void {
    $all = read_guarded(LOCKS_FILE, []);
    unset($all[throttle_key($user)]);
    write_guarded(LOCKS_FILE, $all);
}

/* ---------- content ---------- */
function load_content(): array {
    $c = json_decode((string)@file_get_contents(CONTENT), true);
    if (!is_array($c)) throw new RuntimeException('content.json is missing or damaged. Restore a backup from admin/data/backups.');
    return $c;
}
function content_version(): string { return is_file(CONTENT) ? md5_file(CONTENT) : ''; }

function publish(array $c, string $by): void {
    if (!is_dir(BACKUP_DIR)) @mkdir(BACKUP_DIR, 0755, true);
    if (is_file(CONTENT)) {
        @copy(CONTENT, BACKUP_DIR . '/content-' . date('Ymd-His') . '-' . preg_replace('/[^a-z0-9]/i', '', $by) . '.json');
        $old = glob(BACKUP_DIR . '/content-*.json') ?: [];
        sort($old);
        while (count($old) > MAX_BACKUPS) @unlink(array_shift($old));
    }
    $c['updated'] = ['at' => date('c'), 'by' => $by];
    write_atomic(CONTENT, json_encode($c, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    write_atomic(SITE_ROOT . '/index.html', render_site($c));
}

/* Clean the whole content tree that came from the editor. */
function clean_content(array $in): array {
    $s = $in['site'] ?? [];
    $site = [];
    foreach (['name', 'credentials', 'tagline', 'mobile', 'phone', 'location', 'footer'] as $k) $site[$k] = clean_text($s[$k] ?? '', 300);
    $email = trim((string)($s['email'] ?? ''));
    $site['email'] = filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : '';
    $site['intro'] = clean_html((string)($s['intro'] ?? ''));
    if ($site['name'] === '') $site['name'] = 'Peter Beyer Architect';

    $sections = [];
    $ids = [];
    foreach (($in['sections'] ?? []) as $sec) {
        if (!is_array($sec)) continue;
        $type = in_array($sec['type'] ?? '', ['gallery', 'page', 'contact'], true) ? $sec['type'] : 'page';
        $id = slug((string)($sec['id'] ?? $sec['title'] ?? 'section'));
        while (isset($ids[$id])) $id .= '-2';
        $ids[$id] = 1;
        $o = [
            'id' => $id, 'type' => $type,
            'title' => clean_text($sec['title'] ?? '', 120),
            'menu' => clean_text($sec['menu'] ?? '', 30),
            'visible' => !empty($sec['visible']),
            'body' => clean_html((string)($sec['body'] ?? '')),
        ];
        if ($type === 'gallery') {
            $o['projects'] = [];
            $pids = [];
            foreach (($sec['projects'] ?? []) as $p) {
                $pid = slug((string)($p['id'] ?? $p['title'] ?? 'project'));
                while (isset($pids[$pid])) $pid .= '-2';
                $pids[$pid] = 1;
                $o['projects'][] = ['id' => $pid, 'title' => clean_text($p['title'] ?? '', 120), 'text' => clean_text($p['text'] ?? '', 2000, true), 'chip' => !array_key_exists('chip', $p) || !empty($p['chip']), 'images' => clean_images($p['images'] ?? [])];
            }
            $o['across'] = in_array((int)($sec['across'] ?? 3), [0, 2, 3], true) ? (int)($sec['across'] ?? 3) : 3; // 0 = visitors choose
            $o['allLabel'] = clean_text($sec['allLabel'] ?? '', 40) ?: 'Highlights';
            $q = slug((string)($sec['quickAdd'] ?? 'recent-work'));
            $o['quickAdd'] = isset($pids[$q]) ? $q : ($o['projects'][0]['id'] ?? '');
        } elseif ($type === 'page') {
            $o['layout'] = ($sec['layout'] ?? '') === 'side' ? 'side' : 'grid';
            $o['images'] = clean_images($sec['images'] ?? []);
            $o['videos'] = [];
            foreach (($sec['videos'] ?? []) as $v) {
                if (youtube_id((string)($v['url'] ?? ''))) $o['videos'][] = ['url' => trim((string)$v['url']), 'title' => clean_text($v['title'] ?? '', 200)];
            }
        }
        $sections[] = $o;
    }
    return ['site' => $site, 'sections' => $sections];
}
function clean_text($v, int $max, bool $multiline = false): string {
    $v = (string)$v;
    $v = $multiline ? preg_replace("/\r\n?/", "\n", $v) : preg_replace('/\s+/', ' ', $v);
    $v = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $v) ?? '';
    return mb_substr(trim($v), 0, $max);
}
function clean_images($list): array {
    $out = [];
    foreach ((array)$list as $im) {
        $src = (string)($im['src'] ?? '');
        if (!preg_match('#^(assets|uploads)/[A-Za-z0-9._-]+\.(jpe?g|png|webp|gif)$#i', $src)) continue;
        if (!is_file(SITE_ROOT . '/' . $src)) continue;
        $out[] = ['src' => $src, 'caption' => clean_text($im['caption'] ?? '', 300)];
    }
    return $out;
}
function slug(string $s): string {
    $s = strtolower(trim(preg_replace('/[^A-Za-z0-9]+/', '-', $s), '-'));
    return $s === '' ? 'section' : substr($s, 0, 40);
}
function youtube_id(string $url): ?string {
    if (preg_match('#(?:youtube\.com/(?:watch\?(?:.*&)?v=|embed/|shorts/)|youtu\.be/)([A-Za-z0-9_-]{11})#', $url, $m)) return $m[1];
    return null;
}

/* Allow-list HTML sanitiser for the rich-text editor. */
function clean_html(string $html): string {
    $html = trim($html);
    if ($html === '') return '';
    $allowed = ['p' => [], 'br' => [], 'strong' => [], 'b' => [], 'em' => [], 'i' => [], 'u' => [], 'h3' => [], 'ul' => [], 'ol' => [], 'li' => [], 'blockquote' => [], 'a' => ['href']];
    $doc = new DOMDocument();
    libxml_use_internal_errors(true);
    $doc->loadHTML('<?xml encoding="utf-8"?><div id="root">' . $html . '</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
    libxml_clear_errors();
    $root = $doc->getElementById('root') ?? $doc->documentElement;
    $walk = function (DOMNode $node) use (&$walk, $allowed, $doc) {
        foreach (iterator_to_array($node->childNodes) as $child) {
            if ($child instanceof DOMElement) {
                $tag = strtolower($child->tagName);
                if (in_array($tag, ['script', 'style', 'iframe', 'object', 'embed', 'form', 'input', 'button', 'textarea', 'select', 'svg', 'math', 'img', 'video', 'audio'], true)) { $node->removeChild($child); continue; }
                $map = ['div' => 'p', 'h1' => 'h3', 'h2' => 'h3', 'h4' => 'h3', 'h5' => 'h3', 'h6' => 'h3', 'b' => 'strong', 'i' => 'em'];
                if (isset($map[$tag])) {
                    $new = $doc->createElement($map[$tag]);
                    while ($child->firstChild) $new->appendChild($child->firstChild);
                    if ($tag === 'div' && $child->parentNode && in_array(strtolower($child->parentNode->nodeName), ['p', 'li', 'h3'], true)) {
                        // unwrap nested divs instead of creating nested paragraphs
                        $frag = $doc->createDocumentFragment();
                        while ($new->firstChild) $frag->appendChild($new->firstChild);
                        $node->replaceChild($frag, $child);
                        $walk($node);
                        return;
                    }
                    $node->replaceChild($new, $child);
                    $child = $new; $tag = $map[$tag];
                }
                if (!isset($allowed[$tag])) {
                    // unwrap unknown tags (span, font, etc.) but keep their text
                    $frag = $doc->createDocumentFragment();
                    while ($child->firstChild) $frag->appendChild($child->firstChild);
                    $node->replaceChild($frag, $child);
                    $walk($node);
                    return;
                }
                foreach (iterator_to_array($child->attributes) as $attr) {
                    if (!in_array($attr->name, $allowed[$tag], true)) $child->removeAttribute($attr->name);
                }
                if ($tag === 'a') {
                    $href = trim($child->getAttribute('href'));
                    if (!preg_match('#^(https?://|mailto:|tel:|\#)#i', $href)) { $child->removeAttribute('href'); }
                    elseif (preg_match('#^https?://#i', $href)) { $child->setAttribute('rel', 'noopener'); $child->setAttribute('target', '_blank'); }
                }
                $walk($child);
            } elseif ($child instanceof DOMComment || $child instanceof DOMProcessingInstruction) {
                $node->removeChild($child);
            }
        }
    };
    $walk($root);
    $out = '';
    foreach ($root->childNodes as $c) $out .= $doc->saveHTML($c);
    $out = preg_replace('#<p>(\s|&nbsp;|<br>)*</p>#', '', $out);
    return trim((string)$out);
}

/* ---------- uploads ---------- */
function handle_upload(array $f): string {
    if (($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) throw new RuntimeException('Upload failed (the file may be too large for the server).');
    if ($f['size'] > 15 * 1024 * 1024) throw new RuntimeException('Photos must be under 15 MB.');
    $info = @getimagesize($f['tmp_name']);
    $types = [IMAGETYPE_JPEG => 'jpg', IMAGETYPE_PNG => 'png', IMAGETYPE_WEBP => 'webp', IMAGETYPE_GIF => 'gif'];
    if (!$info || !isset($types[$info[2]])) throw new RuntimeException('Only JPG, PNG, WebP or GIF photos can be uploaded.');
    if (!is_dir(UPLOAD_DIR)) @mkdir(UPLOAD_DIR, 0755, true);
    $base = slug(pathinfo((string)$f['name'], PATHINFO_FILENAME));
    $name = date('Ymd') . '-' . substr($base, 0, 30) . '-' . bin2hex(random_bytes(3));
    $ext = $types[$info[2]];
    $dest = UPLOAD_DIR . "/$name.$ext";

    // Resize big photos to 2000px and re-save (also strips hidden data). Needs GD; otherwise keep the original.
    if (function_exists('imagecreatefromstring') && $ext !== 'gif') {
        $img = @imagecreatefromstring((string)file_get_contents($f['tmp_name']));
        if ($img) {
            if ($ext === 'jpg' && function_exists('exif_read_data')) {
                $o = (int)(@exif_read_data($f['tmp_name'])['Orientation'] ?? 1);
                if ($o === 3) $img = imagerotate($img, 180, 0);
                if ($o === 6) $img = imagerotate($img, -90, 0);
                if ($o === 8) $img = imagerotate($img, 90, 0);
            }
            $w = imagesx($img); $hgt = imagesy($img); $max = 2000;
            if (max($w, $hgt) > $max) {
                $r = $max / max($w, $hgt);
                $img = imagescale($img, (int)round($w * $r), (int)round($hgt * $r), IMG_BICUBIC);
            }
            $ok = match ($ext) {
                'jpg' => imagejpeg($img, $dest, 85),
                'png' => (function () use ($img, $dest) { imagesavealpha($img, true); return imagepng($img, $dest, 7); })(),
                'webp' => imagewebp($img, $dest, 85),
            };
            imagedestroy($img);
            if ($ok) return "uploads/$name.$ext";
        }
    }
    if (!move_uploaded_file($f['tmp_name'], $dest)) throw new RuntimeException('Could not save the photo. Check the uploads folder is writable.');
    return "uploads/$name.$ext";
}
function image_library(): array {
    $out = [];
    foreach (['uploads', 'assets'] as $dir) {
        foreach (glob(SITE_ROOT . "/$dir/*.{jpg,jpeg,png,webp,gif,JPG,JPEG,PNG}", GLOB_BRACE) ?: [] as $f) {
            if (basename($f) === '001-header.jpg') continue;
            $out[] = ['src' => "$dir/" . basename($f), 't' => filemtime($f)];
        }
    }
    usort($out, fn($a, $b) => $b['t'] <=> $a['t']);
    return array_column($out, 'src');
}
