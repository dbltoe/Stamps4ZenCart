<?php
/**
 * Stamps4ZenCart -- this plugin's README.md, for superusers only, behind the admin login
 * (index.php?cmd=stamps4zencart_readme; the ReadMe button in Plugin Manager).
 *
 * Following the Zen Cart discussion on plugin readmes (zencart/zencart#8031):
 * - the guide is Markdown; this page turns it into HTML with s4z_markdown_html(), which
 *   escapes every character first, so nothing in the file can become markup or script,
 *   and any browser shows it formatted without an extension;
 * - opened in its own window (the button has target="_blank" rel="noopener noreferrer");
 * - no scripts at all (CSP), can't be framed (X-Frame-Options, frame-ancestors), sends
 *   no referrer; links in the guide open in a new window with noopener noreferrer.
 *
 * README.md isn't served from zc_plugins/ directly (core's zc_plugins/.htaccess allows
 * only web assets). This page exists only while the plugin is installed and enabled;
 * the same README.md is at the root of the download for everything before that.
 *
 * @package  Stamps4ZenCart
 * @license  https://www.gnu.org/licenses/gpl-3.0.html GNU General Public License v3.0
 */
if (!defined('IS_ADMIN_FLAG')) {
    http_response_code(404);
    exit;
}
// Login and page-permission checks happen in application_top; never skip it.
require 'includes/application_top.php';
require_once __DIR__ . '/includes/classes/s4z_classes.php';

if (!function_exists('zen_is_superuser') || !zen_is_superuser()) {
    zen_redirect(zen_href_link(FILENAME_DENIED, '', 'SSL'));
}

$s4zReadmeFile = dirname(__DIR__) . '/README.md';
$s4zReadmeText = is_file($s4zReadmeFile) ? (string)file_get_contents($s4zReadmeFile) : '';
$s4zReadmeHtml = ($s4zReadmeText !== '') ? s4z_markdown_html($s4zReadmeText) : '<p>' . s4z_markdown_inline(S4Z_TEXT_README_MISSING) . '</p>';

header('Content-Type: text/html; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; frame-ancestors 'none'; base-uri 'none'; form-action 'none'; sandbox allow-popups allow-popups-to-escape-sandbox");
header('Referrer-Policy: no-referrer');
header('Cache-Control: no-store, private');
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="referrer" content="no-referrer">
<title>Stamps4ZenCart ReadMe</title>
<style>
body{font-family:system-ui,-apple-system,"Segoe UI",Arial,sans-serif;color:#1b1f24;background:#fff;margin:0;padding:0 16px;line-height:1.55}
main{max-width:860px;margin:32px auto 64px}
h1{font-size:2rem;line-height:1.25;margin:0 0 6px}
h2{font-size:1.35rem;line-height:1.3;margin:36px 0 10px;padding-top:8px;border-top:1px solid #c8ccd0}
h3{font-size:1.1rem;line-height:1.35;margin:22px 0 8px}
p,li{margin:0 0 10px}
code{background:#f4f6f9;padding:1px 5px;border-radius:4px;font-size:.92em}
blockquote{border-left:4px solid #8a6500;background:#fff8e1;padding:10px 14px;margin:12px 0}
a{color:#0b3d91}
</style>
</head>
<body>
<main>
<?php echo $s4zReadmeHtml; ?>
</main>
</body>
</html>
<?php
exit;
