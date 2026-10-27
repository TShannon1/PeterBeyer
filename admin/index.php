<?php
declare(strict_types=1);
require __DIR__ . '/lib.php';
start_session();
header('Cache-Control: no-store');
header('X-Frame-Options: DENY');
header('Referrer-Policy: same-origin');
header("Content-Security-Policy: default-src 'self'; img-src 'self' data: https://i.ytimg.com; style-src 'self' 'unsafe-inline'; script-src 'self'; frame-ancestors 'none'");
?><!doctype html>
<html lang="en-AU">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title>Website editor</title>
<link rel="stylesheet" href="admin.css?v=2">
<script src="admin.js?v=4" defer></script>
</head>
<body>
<div id="app" aria-live="polite"><p class="loading">Loading…</p></div>
<div id="toast" role="status" aria-live="polite"></div>
</body>
</html>
