PETER BEYER ARCHITECT — WEBSITE (modernised, one page + easy editor)
=====================================================================

WHAT'S HERE
  index.html        The public website (one page: Gallery first, Contact last).
                    It is generated automatically — don't edit it by hand.
  css/, js/         Website styling and behaviour (filter, photo viewer, videos).
  assets/           The original photos.
  uploads/          Photos added through the editor.
  contact.php       Sends the enquiry form by email (only shown once an
                    enquiry email is set in the editor).
  admin/            The editor ("yoursite.com.au/admin").
  admin/data/       content.json (all text/photos), users, backups. Private.
  _old-site/        The previous multi-page version, kept for reference.

HOSTING
  Any ordinary PHP web hosting (PHP 8.0+). No database needed.
  Upload the whole folder, then make these writable by PHP (usually already
  the case on cPanel hosting):  index.html, uploads/, admin/data/
  On Apache the .htaccess files protect admin/data and uploads. On nginx,
  add rules to deny /admin/data/ and stop PHP running in /uploads/.
  Use HTTPS so admin passwords are sent securely.

  Without PHP (plain static host) the website still works and looks the
  same, but the editor and the contact form won't.

FIRST TIME
  1. Go to  yoursite/admin/  — you'll be asked to create the first admin.
  2. Sign in. Use "Admins" to add more admins (each gets their own login).

EDITING (no HTML needed)
  Add new work: the first button in the editor. Add photos (or drag them in),
                type a caption under each, then Publish. New photos go to the
                front of "Recent work", so they lead the gallery. Works on a phone.
  Edit website: Business details (name, phones, enquiry email, welcome text)
                and each page section, top to bottom. Sections can be moved,
                hidden/shown (e.g. Links is hidden for now), added or deleted.
  Gallery:      projects = filter buttons. Upload photos, add captions,
                reorder, or pick existing photos from the library.
  Text:         toolbar for bold, italic, headings, lists, quotes, links.
  Publish changes rebuilds the website straight away.
  History:      the last 40 published versions; one click restores.

SECURITY NOTES
  Passwords are hashed (never stored in plain text). Five wrong attempts
  locks that login for 15 minutes. Admin pages need a signed-in session and
  every change is checked against a per-session token. Text from the editor
  is cleaned to a safe list of formatting before publishing.

Command-line rebuild (optional):  php admin/build.php
