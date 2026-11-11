<?php
/**
 * Stamps4ZenCart -- Plugin Manager manifest.
 *
 * Buttons under the description: ReadMe | Settings | Forum Thread | GitHub.
 * - ReadMe opens README.md through the plugin's own admin page
 *   (index.php?cmd=stamps4zencart_readme): behind the admin login, superusers only,
 *   in its own window. It is shown only to superusers; the page enforces that too,
 *   because Zen Cart 1.5.8-2.1 keep the description from the first Plugin Manager visit.
 * - Settings opens the plugin's own admin page (a fixed index.php?cmd= link).
 * - Forum Thread and GitHub go off-site, so they open in a new window with
 *   rel="noopener noreferrer" (zencart/zencart#8031). Each appears once its URL is set.
 *
 * @package  Stamps4ZenCart
 * @license  https://www.gnu.org/licenses/gpl-3.0.html GNU General Public License v3.0
 */
$s4zForumUrl = '';
$s4zGithubUrl = 'https://github.com/dbltoe/Stamps4ZenCart';

$s4zButtonStyle = ' class="btn btn-primary" role="button" style="margin:0 4px 0 0;padding-left:8px;padding-right:8px"';
$s4zNewWindow = ' target="_blank" rel="noopener noreferrer"';
// Zen Cart 3.0.0 reads every manifest on every admin page, early (template resolver),
// before admin access is set up: zen_is_superuser() there is a fatal "Undefined constant
// SUPERUSER_PROFILE" that takes down the whole admin. Ask only once that exists.
$s4zShowReadme = true;
if (function_exists('zen_is_superuser') && defined('SUPERUSER_PROFILE')) {
    $s4zShowReadme = zen_is_superuser();
}
$s4zLinks =
    '<div style="margin:10px 0 0;padding:0 0 0 4px">'
    . ($s4zShowReadme ? '<a href="index.php?cmd=stamps4zencart_readme"' . $s4zNewWindow . $s4zButtonStyle . '>ReadMe</a>' : '')
    . '<a href="index.php?cmd=stamps4zencart"' . $s4zButtonStyle . '>Settings</a>'
    . ($s4zForumUrl !== '' ? '<a href="' . $s4zForumUrl . '"' . $s4zNewWindow . $s4zButtonStyle . '>Forum Thread</a>' : '')
    . ($s4zGithubUrl !== '' ? '<a href="' . $s4zGithubUrl . '"' . $s4zNewWindow . $s4zButtonStyle . '>GitHub</a>' : '')
    . '</div>';

return [
    'pluginVersion' => 'v1.0.0',
    'pluginName' => 'Stamps4ZenCart',
    'pluginDescription' => 'Buy and print USPS labels from your Stamps.com account inside the Zen Cart admin: address check, rates, labels, reprint, void, tracking, USPS pickups and SCAN forms. Tracking numbers go into the order history.' . $s4zLinks,
    'pluginAuthor' => 'My Zen Cart Host (dbltoe)',
    'pluginId' => 0,
    'zcVersions' => ['v158', 'v200', 'v210', 'v220', 'v230', 'v300'],
    'changelog' => 'changelog.txt',
    'github_repo' => $s4zGithubUrl,
    'pluginGroups' => [],
];
