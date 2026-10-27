<?php
// Sends the website enquiry form to the email address set in Admin → Business details.
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

function out(int $code, array $j): never { http_response_code($code); echo json_encode($j); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') out(405, ['ok' => false, 'error' => 'POST only']);

$c = json_decode((string)@file_get_contents(__DIR__ . '/admin/data/content.json'), true);
$to = $c['site']['email'] ?? '';
if (!filter_var($to, FILTER_VALIDATE_EMAIL)) out(503, ['ok' => false, 'error' => 'No enquiry email is set up.']);

// Spam traps: hidden field must be empty and the form must have been open a few seconds.
if (trim((string)($_POST['website'] ?? '')) !== '' || (int)($_POST['t'] ?? 0) < 3) out(200, ['ok' => true]);

$clean = fn($k, $max) => mb_substr(trim(str_replace(["\r", "\0"], '', (string)($_POST[$k] ?? ''))), 0, $max);
$name = preg_replace('/[\n]+/', ' ', $clean('name', 120));
$email = $clean('email', 200);
$phone = preg_replace('/[\n]+/', ' ', $clean('phone', 40));
$msg = $clean('message', 5000);
if ($name === '' || $msg === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) out(422, ['ok' => false, 'error' => 'Please fill in your name, a valid email and a message.']);
if (preg_match_all('#https?://#i', $msg) > 3) out(422, ['ok' => false, 'error' => 'Too many links in the message.']);

// Simple rate limit: 5 enquiries per hour per visitor
$rl = sys_get_temp_dir() . '/pb-contact-' . hash('sha256', $_SERVER['REMOTE_ADDR'] ?? '');
$hits = array_filter(array_map('intval', explode(',', (string)@file_get_contents($rl))), fn($t) => $t > time() - 3600);
if (count($hits) >= 5) out(429, ['ok' => false, 'error' => 'Too many messages. Please call instead.']);
$hits[] = time();
@file_put_contents($rl, implode(',', $hits));

$host = preg_replace('/^www\./', '', preg_replace('/[^a-z0-9.-]/i', '', $_SERVER['HTTP_HOST'] ?? 'localhost'));
$body = "New enquiry from the website\n\nName: $name\nEmail: $email\nPhone: " . ($phone ?: '-') . "\n\n$msg\n";
$headers = [
    'From: Website <no-reply@' . $host . '>',
    'Reply-To: ' . $email,
    'Content-Type: text/plain; charset=UTF-8',
    'X-Mailer: PHP',
];
$sent = @mail($to, '=?UTF-8?B?' . base64_encode("Website enquiry from $name") . '?=', $body, implode("\r\n", $headers));
if (!$sent) out(500, ['ok' => false, 'error' => 'Mail server unavailable.']);
out(200, ['ok' => true]);
