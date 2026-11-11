<?php
/**
 * Stamps4ZenCart -- admin page name and table names.
 *
 * admin/includes/extra_datafiles/ is loaded for every installed plugin on
 * every release from v1.5.8 to v3.0.0 (a plugin-root filenames.php only from
 * v2.2.0), so this is the one place they're defined.
 *
 * @package  Stamps4ZenCart
 * @license  https://www.gnu.org/licenses/gpl-3.0.html GNU General Public License v3.0
 */

if (!defined('FILENAME_STAMPS4ZENCART')) {
    define('FILENAME_STAMPS4ZENCART', 'stamps4zencart');
}

// Every table is a TABLE_* constant so stores with a DB_PREFIX work.
if (!defined('TABLE_S4Z_AUTH')) {
    define('TABLE_S4Z_AUTH', DB_PREFIX . 's4z_auth');
}
if (!defined('TABLE_S4Z_LABELS')) {
    define('TABLE_S4Z_LABELS', DB_PREFIX . 's4z_labels');
}
if (!defined('TABLE_S4Z_PICKUPS')) {
    define('TABLE_S4Z_PICKUPS', DB_PREFIX . 's4z_pickups');
}
if (!defined('TABLE_S4Z_MANIFESTS')) {
    define('TABLE_S4Z_MANIFESTS', DB_PREFIX . 's4z_manifests');
}
