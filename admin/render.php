<?php
// Builds the public one-page website (index.html) from content.json.
declare(strict_types=1);

if (!function_exists('h')) {
    function h(?string $s): string { return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
}

function tel_link(string $n): string { $d = preg_replace('/[^0-9+]/', '', $n); return str_starts_with($d, '0') ? '+61' . substr($d, 1) : $d; }
function img_size(string $src): string {
    $f = __DIR__ . '/../' . $src;
    $s = is_file($f) ? @getimagesize($f) : false;
    return $s ? ' width="' . $s[0] . '" height="' . $s[1] . '"' : '';
}
function paras(string $text): string {
    $out = '';
    foreach (preg_split("/\n\s*\n/", trim($text)) as $p) if (trim($p) !== '') $out .= '<p>' . nl2br(h(trim($p)), false) . '</p>';
    return $out;
}

function render_site(array $c): string {
    $h = 'h';
    $s = $c['site'];
    $sections = array_values(array_filter($c['sections'], fn($x) => !empty($x['visible'])));
    $name = h($s['name']);
    $mobile = h($s['mobile']);
    $telM = h(tel_link($s['mobile']));
    $year = date('Y');
    $desc = h($s['tagline']);

    $nav = '';
    foreach ($sections as $sec) {
        if (trim($sec['menu'] ?? '') === '') continue;
        // The gallery is the home page: its menu link reloads the page and shows the full gallery again.
        $home = $sec['type'] === 'gallery';
        $nav .= '<a href="' . ($home ? './' : '#' . h($sec['id'])) . '"' . ($home ? ' class="home" data-home' : '') . '>' . h($sec['menu']) . '</a>';
    }

    $body = '';
    foreach ($sections as $sec) {
        $body .= match ($sec['type']) {
            'gallery' => render_gallery($sec, $s),
            'contact' => render_contact($sec, $s),
            default => render_page($sec),
        };
    }

    $ld = json_encode([
        '@context' => 'https://schema.org', '@type' => 'ProfessionalService', 'name' => $s['name'],
        'description' => $s['tagline'], 'telephone' => tel_link($s['mobile']),
        'address' => ['@type' => 'PostalAddress', 'addressLocality' => 'Perth', 'addressRegion' => 'WA', 'addressCountry' => 'AU'],
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG);

    $ogImg = '';
    foreach ($c['sections'] as $sec) if (($sec['type'] ?? '') === 'gallery' && !empty($sec['projects'][0]['images'][0])) { $ogImg = $sec['projects'][0]['images'][0]['src']; break; }

    return <<<HTML
<!doctype html>
<html lang="en-AU">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>{$name} | Perth architect</title>
<meta name="description" content="{$desc}">
<meta property="og:title" content="{$name}">
<meta property="og:description" content="{$desc}">
<meta property="og:image" content="{$ogImg}">
<meta name="theme-color" content="#f6f3ee">
<link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Instrument+Serif:ital@0;1&family=Inter:wght@400;500;600&display=swap" rel="stylesheet">
<link rel="stylesheet" href="css/site.css?v=7">
<script src="js/site.js?v=3" defer></script>
<script type="application/ld+json">{$ld}</script>
</head>
<body>
<a class="skip" href="#main">Skip to content</a>
<header class="top">
  <div class="wrap top-in">
    <a class="brand" href="./" data-home><span class="brand-name">{$name}</span><span class="brand-sub">{$h($s['credentials'])}</span></a>
    <nav class="menu" aria-label="Sections">{$nav}</nav>
    <a class="call" href="tel:{$telM}"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M6.6 10.8a15.1 15.1 0 0 0 6.6 6.6l2.2-2.2a1 1 0 0 1 1-.25 11.4 11.4 0 0 0 3.6.57 1 1 0 0 1 1 1V20a1 1 0 0 1-1 1A17 17 0 0 1 3 4a1 1 0 0 1 1-1h3.5a1 1 0 0 1 1 1c0 1.25.2 2.45.57 3.57a1 1 0 0 1-.25 1z"/></svg><span>{$mobile}</span></a>
  </div>
</header>
<main id="main" tabindex="-1">
<span id="top"></span>
{$body}
</main>
<footer class="foot">
  <div class="wrap foot-in">
    <p><strong>{$name}</strong> · {$h($s['credentials'])}<br>{$h($s['footer'])}</p>
    <p>{$h($s['location'])}<br><a href="tel:{$telM}">{$mobile}</a></p>
    <p class="small">© {$year} {$name} · <a href="admin/" rel="nofollow">Admin</a></p>
  </div>
</footer>
<dialog id="lightbox" aria-label="Photo viewer">
  <button class="lb-close" type="button" aria-label="Close">×</button>
  <button class="lb-prev" type="button" aria-label="Previous photo">‹</button>
  <figure><img alt=""><figcaption><strong></strong><span></span></figcaption></figure>
  <button class="lb-next" type="button" aria-label="Next photo">›</button>
</dialog>
</body>
</html>
HTML;
}


function render_gallery(array $sec, array $s): string {
    $h = 'h';
    $id = h($sec['id']);
    $chips = '<button type="button" class="chip" aria-pressed="true" data-filter="all">' . h($sec['allLabel'] ?? 'Highlights') . '</button>';
    $notes = '';
    $tiles = '';
    $n = 0;
    foreach ($sec['projects'] as $p) {
        if (empty($p['images'])) continue;
        $pid = h($p['id']);
        if ($p['chip'] ?? true) $chips .= '<button type="button" class="chip" aria-pressed="false" data-filter="' . $pid . '">' . h($p['title']) . '</button>';
        $notes .= '<div class="note" data-note="' . $pid . '" hidden><h2>' . h($p['title']) . '</h2>' . paras($p['text']) . '</div>';
        foreach ($p['images'] as $im) {
            $cap = h($im['caption']);
            $alt = h($im['caption'] !== '' ? $im['caption'] : $p['title']);
            $eager = $n++ < 6 ? 'eager' : 'lazy';
            $tiles .= '<figure class="tile" data-project="' . $pid . '"><a href="' . h($im['src']) . '" data-title="' . h($p['title']) . '" data-caption="' . $cap . '" data-text="' . h($p['text']) . '">'
                . '<img src="' . h($im['src']) . '" alt="' . $alt . '" loading="' . $eager . '"' . img_size($im['src']) . '></a>'
                . '<figcaption><span class="tile-proj">' . h($p['title']) . '</span>' . ($cap !== '' ? '<span>' . $cap . '</span>' : '') . '</figcaption></figure>';
        }
    }
    $across = (int)($sec['across'] ?? 3);
    $wide = $across === 2 ? ' two' : ($across === 0 ? ' auto' : '');
    $sizer = '';
    if ($across === 0) {
        $btns = '';
        foreach ([1, 2, 3, 4] as $n) {
            $w = (18 - ($n - 1) * 2) / $n; $rects = '';
            for ($i = 0; $i < $n; $i++) $rects .= '<rect x="' . round(1 + $i * ($w + 2), 2) . '" y="3" width="' . round($w, 2) . '" height="14" rx="1.5"/>';
            $btns .= '<button type="button" data-cols="' . $n . '" aria-pressed="false" aria-label="' . $n . ' per row" title="' . $n . ' per row"><svg viewBox="0 0 20 20" aria-hidden="true">' . $rects . '</svg></button>';
        }
        $sizer = '<div class="sizer" role="group" aria-label="Photos per row"><span>Photos per row</span>' . $btns . '</div>';
    }
    $title = h($sec['title']);
    $extra = trim($sec['body'] ?? '') !== '' ? '<div class="prose">' . $sec['body'] . '</div>' : '';
    return <<<HTML
<section id="{$id}" class="gallery" aria-labelledby="{$id}-h">
  <div class="wrap hero">
    <h2 class="vh" id="{$id}-h">{$title}</h2>
    <h1>{$h($s['tagline'])}</h1>
    <div class="hero-text prose">{$s['intro']}{$extra}</div>
  </div>
  <div class="wrap">
    <div class="gbar"><div class="chips" role="group" aria-label="Filter projects">{$chips}</div>{$sizer}</div>
    <div class="notes" aria-live="polite">{$notes}</div>
    <div class="masonry{$wide}">{$tiles}</div>
  </div>
</section>

HTML;
}

function render_page(array $sec): string {
    $id = h($sec['id']);
    $title = h($sec['title']);
    $imgs = '';
    foreach ($sec['images'] ?? [] as $im) {
        $cap = h($im['caption']);
        $imgs .= '<figure class="pic"><a href="' . h($im['src']) . '" data-title="' . $title . '" data-caption="' . $cap . '"><img src="' . h($im['src']) . '" alt="' . ($cap ?: $title) . '" loading="lazy"' . img_size($im['src']) . '></a>' . ($cap !== '' ? '<figcaption>' . $cap . '</figcaption>' : '') . '</figure>';
    }
    $vids = '';
    foreach ($sec['videos'] ?? [] as $v) {
        $yt = youtube_id($v['url']);
        if (!$yt) continue;
        $vt = h($v['title']);
        $vids .= '<figure class="video"><button type="button" class="yt" data-yt="' . h($yt) . '" aria-label="Play video: ' . $vt . '"><img src="https://i.ytimg.com/vi/' . h($yt) . '/hqdefault.jpg" alt="" loading="lazy" width="480" height="360"><span class="play" aria-hidden="true"></span></button>' . ($vt !== '' ? '<figcaption>' . $vt . '</figcaption>' : '') . '</figure>';
    }
    $layout = ($sec['layout'] ?? 'grid') === 'side' ? 'side' : 'grid';
    $media = ($imgs || $vids) ? '<div class="media media-' . $layout . '">' . $imgs . $vids . '</div>' : '';
    $alt = $id === 'about' ? ' band' : '';
    return <<<HTML
<section id="{$id}" class="page page-{$layout}{$alt}" aria-labelledby="{$id}-h">
  <div class="wrap">
    <h2 class="sec-title" id="{$id}-h">{$title}</h2>
    <div class="page-in"><div class="prose">{$sec['body']}</div>{$media}</div>
  </div>
</section>

HTML;
}

function render_contact(array $sec, array $s): string {
    $h = 'h';
    $id = h($sec['id']);
    $telM = h(tel_link($s['mobile']));
    $phoneLine = trim($s['phone']) !== '' ? '<li><span>Office</span><a href="tel:' . h(tel_link($s['phone'])) . '">' . h($s['phone']) . '</a></li>' : '';
    $emailLine = $s['email'] !== '' ? '<li><span>Email</span><a href="mailto:' . h($s['email']) . '">' . h($s['email']) . '</a></li>' : '';
    $form = '';
    if ($s['email'] !== '') {
        $form = <<<HTML
<form class="enquiry" action="contact.php" method="post">
  <h3>Send an enquiry</h3>
  <label>Name<input name="name" autocomplete="name" required maxlength="120"></label>
  <label>Email<input type="email" name="email" autocomplete="email" required maxlength="200"></label>
  <label>Phone <em>(optional)</em><input type="tel" name="phone" autocomplete="tel" maxlength="40"></label>
  <label>About your project<textarea name="message" rows="5" required maxlength="5000"></textarea></label>
  <label class="hp" aria-hidden="true">Leave this empty<input name="website" tabindex="-1" autocomplete="off"></label>
  <input type="hidden" name="t" value="">
  <button type="submit">Send enquiry</button>
  <p class="form-status" role="status"></p>
</form>
HTML;
    }
    $details = '<ul class="details"><li><span>Mobile</span><a href="tel:' . $telM . '">' . h($s['mobile']) . '</a></li>' . $phoneLine . $emailLine . '<li><span>Based in</span>' . h($s['location']) . '</li></ul>';
    $detailsLeft = $form !== '' ? $details : '';
    $right = $form !== '' ? $form : '<div class="contact-side">' . $details . '</div>';
    return <<<HTML
<section id="{$id}" class="contact" aria-labelledby="{$id}-h">
  <div class="wrap contact-in">
    <div>
      <p class="eyebrow">Contact</p>
      <h2 id="{$id}-h">{$h($sec['title'])}</h2>
      <div class="prose">{$sec['body']}</div>
      <a class="big-call" href="tel:{$telM}">{$h($s['mobile'])}</a>
      {$detailsLeft}
    </div>
    {$right}
  </div>
</section>

HTML;
}
