<?php
// Rebuilds index.html from admin/data/content.json. Run from a terminal: php admin/build.php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__ . '/lib.php';
$c = clean_content(load_content());
file_put_contents(SITE_ROOT . '/index.html', render_site($c));
echo "index.html rebuilt\n";
