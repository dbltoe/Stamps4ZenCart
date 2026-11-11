<?php
/**
 * Stamps4ZenCart -- Plugin Manager installer.
 *
 * Uses only the installer API present in every supported release (v1.5.8 to
 * v3.0.0): executeInstallerSql() for all database work, and an
 * executeUpgrade() whose argument is optional (v1.5.8 passes none). Every step
 * is idempotent, so an upgrade is a re-run of the install.
 *
 * Tables:
 *   s4z_auth       the store's Stamps.com connection, one row per environment
 *   s4z_labels     labels bought (IDs, tracking, cost; never the label file)
 *   s4z_pickups    USPS pickups scheduled
 *   s4z_manifests  SCAN forms created
 *
 * @package  Stamps4ZenCart
 * @license  https://www.gnu.org/licenses/gpl-3.0.html GNU General Public License v3.0
 */

// The table constants come from this file, which core only auto-loads once the
// plugin is installed.
require_once dirname(__DIR__) . '/admin/includes/extra_datafiles/stamps4zencart_filenames.php';

use Zencart\PluginSupport\ScriptedInstaller as ScriptedInstallBase;

class ScriptedInstaller extends ScriptedInstallBase
{
    public const CONFIG_GROUP_TITLE = 'Stamps4ZenCart';

    public const ADMIN_PAGE_KEYS = ['configStamps4ZenCart', 'customersStamps4ZenCart'];

    protected function executeInstall()
    {
        $groupId = $this->s4zGetOrCreateConfigGroup();
        if ($groupId === 0) {
            return false;
        }
        $this->s4zCreateTables();
        $this->s4zAddConfigurationKeys($groupId);
        $this->s4zRegisterAdminPages($groupId);
        return true;
    }

    /**
     * v1.5.8 calls this with no argument; v2.x/v3.x pass $oldVersion.
     */
    protected function executeUpgrade($oldVersion = null)
    {
        return $this->executeInstall();
    }

    protected function executeUninstall()
    {
        zen_deregister_admin_pages(self::ADMIN_PAGE_KEYS);

        $groupId = $this->s4zGetConfigGroupId();
        if ($groupId > 0) {
            $this->executeInstallerSql("DELETE FROM " . TABLE_CONFIGURATION . " WHERE configuration_group_id = " . $groupId);
            $this->executeInstallerSql("DELETE FROM " . TABLE_CONFIGURATION_GROUP . " WHERE configuration_group_id = " . $groupId);
        }

        // The connection holds Stamps.com sign-in tokens: never leave them behind.
        $this->executeInstallerSql("DROP TABLE IF EXISTS " . TABLE_S4Z_AUTH);

        // Label, pickup and SCAN form records are the store's shipping history
        // (tracking numbers, costs). They stay, so a reinstall picks them up.
        return true;
    }

    protected function s4zCreateTables()
    {
        $this->executeInstallerSql(
            "CREATE TABLE IF NOT EXISTS " . TABLE_S4Z_AUTH . " (
                environment VARCHAR(16) NOT NULL,
                store_id VARCHAR(64) NOT NULL DEFAULT '',
                store_key VARCHAR(128) NOT NULL DEFAULT '',
                refresh_token TEXT,
                access_token TEXT,
                access_expires INT NOT NULL DEFAULT 0,
                connected_at DATETIME NULL DEFAULT NULL,
                connected_by VARCHAR(64) NOT NULL DEFAULT '',
                PRIMARY KEY (environment)
            ) ENGINE=InnoDB"
        );

        $this->executeInstallerSql(
            "CREATE TABLE IF NOT EXISTS " . TABLE_S4Z_LABELS . " (
                s4z_label_id INT NOT NULL AUTO_INCREMENT,
                orders_id INT NOT NULL DEFAULT 0,
                environment VARCHAR(16) NOT NULL DEFAULT '',
                label_id VARCHAR(64) NOT NULL DEFAULT '',
                tracking_number VARCHAR(64) NOT NULL DEFAULT '',
                carrier VARCHAR(32) NOT NULL DEFAULT '',
                service_type VARCHAR(64) NOT NULL DEFAULT '',
                packaging_type VARCHAR(64) NOT NULL DEFAULT '',
                weight DECIMAL(10,2) NOT NULL DEFAULT 0,
                weight_unit VARCHAR(12) NOT NULL DEFAULT '',
                cost DECIMAL(10,2) NOT NULL DEFAULT 0,
                currency VARCHAR(3) NOT NULL DEFAULT 'usd',
                ship_date DATE NULL DEFAULT NULL,
                status VARCHAR(16) NOT NULL DEFAULT 'active',
                track_status VARCHAR(32) NOT NULL DEFAULT '',
                track_description VARCHAR(255) NOT NULL DEFAULT '',
                track_est_delivery VARCHAR(32) NOT NULL DEFAULT '',
                tracked_at DATETIME NULL DEFAULT NULL,
                manifest_id VARCHAR(64) NOT NULL DEFAULT '',
                pickup_id VARCHAR(64) NOT NULL DEFAULT '',
                created_at DATETIME NOT NULL,
                created_by VARCHAR(64) NOT NULL DEFAULT '',
                PRIMARY KEY (s4z_label_id),
                KEY idx_s4z_orders (orders_id),
                KEY idx_s4z_label (label_id),
                KEY idx_s4z_ship_date (ship_date)
            ) ENGINE=InnoDB"
        );

        $this->executeInstallerSql(
            "CREATE TABLE IF NOT EXISTS " . TABLE_S4Z_PICKUPS . " (
                s4z_pickup_id INT NOT NULL AUTO_INCREMENT,
                environment VARCHAR(16) NOT NULL DEFAULT '',
                pickup_id VARCHAR(64) NOT NULL DEFAULT '',
                carrier VARCHAR(32) NOT NULL DEFAULT '',
                window_start VARCHAR(40) NOT NULL DEFAULT '',
                window_end VARCHAR(40) NOT NULL DEFAULT '',
                items INT NOT NULL DEFAULT 0,
                cost DECIMAL(10,2) NOT NULL DEFAULT 0,
                status VARCHAR(16) NOT NULL DEFAULT 'scheduled',
                label_ids TEXT,
                created_at DATETIME NOT NULL,
                created_by VARCHAR(64) NOT NULL DEFAULT '',
                PRIMARY KEY (s4z_pickup_id)
            ) ENGINE=InnoDB"
        );

        $this->executeInstallerSql(
            "CREATE TABLE IF NOT EXISTS " . TABLE_S4Z_MANIFESTS . " (
                s4z_manifest_id INT NOT NULL AUTO_INCREMENT,
                environment VARCHAR(16) NOT NULL DEFAULT '',
                manifest_id VARCHAR(64) NOT NULL DEFAULT '',
                carrier VARCHAR(32) NOT NULL DEFAULT '',
                ship_date VARCHAR(20) NOT NULL DEFAULT '',
                items INT NOT NULL DEFAULT 0,
                document_url TEXT,
                created_at DATETIME NOT NULL,
                created_by VARCHAR(64) NOT NULL DEFAULT '',
                PRIMARY KEY (s4z_manifest_id)
            ) ENGINE=InnoDB"
        );
    }

    /**
     * Inserts each key only if it's missing (an upgrade keeps the owner's
     * values), then refreshes its title, description and sort order.
     */
    protected function s4zAddConfigurationKeys($groupId)
    {
        $storeName = defined('STORE_NAME') ? html_entity_decode(STORE_NAME, ENT_QUOTES, 'UTF-8') : '';
        $storeEmail = defined('STORE_OWNER_EMAIL_ADDRESS') ? STORE_OWNER_EMAIL_ADDRESS : '';

        $keys = [
            ['S4Z_ENVIRONMENT', 'Stamps.com Environment', 'staging',
                '<b>Staging</b> makes test labels only (never mail them). Switch to <b>Production</b> only after Stamps4ZenCart is approved for production. Each environment has its own connection.',
                "zen_cfg_select_option(array('staging', 'production'), "],
            ['S4Z_SERVICE_URL', 'Connection Service URL', 'https://myzencartzone.com/stamps-connect',
                'The myZenCartZone service that connects this store to Stamps.com. Leave as is unless support asks you to change it.', ''],
            ['S4Z_FROM_NAME', 'Ship From: Name', '', 'Sender name printed on labels.', ''],
            ['S4Z_FROM_COMPANY', 'Ship From: Company', $storeName, 'Company name printed on labels.', ''],
            ['S4Z_FROM_ADDRESS1', 'Ship From: Street Address', '', 'Street address packages ship from.', ''],
            ['S4Z_FROM_ADDRESS2', 'Ship From: Address Line 2', '', 'Suite, unit or building (optional).', ''],
            ['S4Z_FROM_CITY', 'Ship From: City', '', 'City packages ship from.', ''],
            ['S4Z_FROM_STATE', 'Ship From: State', '', 'Two-letter state code, e.g. TX.', ''],
            ['S4Z_FROM_POSTCODE', 'Ship From: ZIP Code', '', 'Five-digit ZIP code or ZIP+4, e.g. 78701.', ''],
            ['S4Z_FROM_PHONE', 'Ship From: Phone', '', 'Required by USPS for pickups.', ''],
            ['S4Z_FROM_EMAIL', 'Ship From: Email', $storeEmail, 'Sender email sent to Stamps.com with each label (optional).', ''],
            ['S4Z_DEFAULT_SERVICE', 'Default Service', 'usps_ground_advantage', 'Service selected first on the Create Label form.',
                "zen_cfg_select_option(array('usps_ground_advantage', 'usps_priority_mail', 'usps_priority_mail_express', 'usps_first_class_mail', 'usps_media_mail', 'usps_parcel_select'), "],
            ['S4Z_DEFAULT_PACKAGE', 'Default Package Type', 'package', 'Packaging selected first on the Create Label form.',
                "zen_cfg_select_option(array('package', 'large_envelope', 'usps_flat_rate_envelope', 'usps_padded_flat_rate_envelope', 'usps_legal_flat_rate_envelope', 'usps_small_flat_rate_box', 'usps_medium_flat_rate_box', 'usps_large_flat_rate_box'), "],
            ['S4Z_DELIVERY_CONFIRMATION', 'Delivery Confirmation', 'tracking', 'Tracking is included with most USPS services; signature options cost extra.',
                "zen_cfg_select_option(array('tracking', 'signature', 'adult_signature', 'none'), "],
            ['S4Z_WEIGHT_UNIT', 'Product Weight Unit', 'pound', 'The unit your product weights are entered in.',
                "zen_cfg_select_option(array('pound', 'ounce', 'kilogram', 'gram'), "],
            ['S4Z_LABEL_SIZE', 'Label Size', '4x6', '4x6 for label printers, letter for a sheet printer.',
                "zen_cfg_select_option(array('4x6', 'letter'), "],
            ['S4Z_LABEL_FORMAT', 'Label Format', 'pdf', 'The file Download PDF gives you. Print Label always prints the label at real size, on any printer, including thermal label printers.',
                "zen_cfg_select_option(array('pdf', 'png'), "],
            ['S4Z_ORDER_STATUS', 'Order Status After Label', '0', 'Set the order to this status when a label is bought. Choose Default to leave the status alone.',
                'zen_cfg_pull_down_order_statuses(', 'zen_get_order_status_name'],
            ['S4Z_NOTIFY_CUSTOMER', 'Email Tracking to Customer', 'true', 'Email the customer the tracking number when a label is bought.',
                "zen_cfg_select_option(array('true', 'false'), "],
            ['S4Z_LOG_ERRORS', 'Log Stamps.com Errors', 'true', 'Write failed Stamps.com requests to the logs folder (never tokens or keys).',
                "zen_cfg_select_option(array('true', 'false'), "],
        ];

        $sort = 10;
        foreach ($keys as $k) {
            [$key, $title, $value, $desc, $set] = $k;
            $use = $k[5] ?? '';
            $this->executeInstallerSql(
                "INSERT IGNORE INTO " . TABLE_CONFIGURATION . "
                    (configuration_title, configuration_key, configuration_value, configuration_description,
                     configuration_group_id, sort_order, date_added, use_function, set_function)
                 VALUES
                    ('" . $this->s4zEsc($title) . "', '" . $this->s4zEsc($key) . "', '" . $this->s4zEsc($value) . "',
                     '" . $this->s4zEsc($desc) . "', " . (int)$groupId . ", " . $sort . ", now(),
                     " . ($use === '' ? 'NULL' : "'" . $this->s4zEsc($use) . "'") . ",
                     " . ($set === '' ? 'NULL' : "'" . $this->s4zEsc($set) . "'") . ")"
            );
            $this->executeInstallerSql(
                "UPDATE " . TABLE_CONFIGURATION . "
                    SET configuration_title = '" . $this->s4zEsc($title) . "',
                        configuration_description = '" . $this->s4zEsc($desc) . "',
                        configuration_group_id = " . (int)$groupId . ",
                        sort_order = " . $sort . ",
                        use_function = " . ($use === '' ? 'NULL' : "'" . $this->s4zEsc($use) . "'") . ",
                        set_function = " . ($set === '' ? 'NULL' : "'" . $this->s4zEsc($set) . "'") . "
                  WHERE configuration_key = '" . $this->s4zEsc($key) . "'
                  LIMIT 1"
            );
            $sort += 10;
        }
    }

    protected function s4zRegisterAdminPages($groupId)
    {
        // zen_register_admin_page() is a plain INSERT, so clear first.
        zen_deregister_admin_pages(self::ADMIN_PAGE_KEYS);
        zen_register_admin_page('configStamps4ZenCart', 'BOX_CONFIGURATION_STAMPS4ZENCART', 'FILENAME_CONFIGURATION',
            'gID=' . (int)$groupId, 'configuration', 'Y', (int)$groupId);
        zen_register_admin_page('customersStamps4ZenCart', 'BOX_CUSTOMERS_STAMPS4ZENCART', 'FILENAME_STAMPS4ZENCART',
            '', 'customers', 'Y', 99);
    }

    protected function s4zGetConfigGroupId()
    {
        $check = $this->dbConn->Execute(
            "SELECT configuration_group_id FROM " . TABLE_CONFIGURATION_GROUP . "
              WHERE configuration_group_title = '" . $this->s4zEsc(self::CONFIG_GROUP_TITLE) . "'
              LIMIT 1"
        );
        return ($check->EOF) ? 0 : (int)$check->fields['configuration_group_id'];
    }

    protected function s4zGetOrCreateConfigGroup()
    {
        $groupId = $this->s4zGetConfigGroupId();
        if ($groupId > 0) {
            return $groupId;
        }
        $created = $this->executeInstallerSql(
            "INSERT INTO " . TABLE_CONFIGURATION_GROUP . "
                (configuration_group_title, configuration_group_description, sort_order, visible)
             VALUES ('" . $this->s4zEsc(self::CONFIG_GROUP_TITLE) . "', 'Stamps.com label printing settings.', 0, 1)"
        );
        if ($created === false) {
            return 0;
        }
        $groupId = $this->s4zGetConfigGroupId();
        if ($groupId > 0) {
            $this->executeInstallerSql("UPDATE " . TABLE_CONFIGURATION_GROUP . " SET sort_order = $groupId WHERE configuration_group_id = $groupId LIMIT 1");
        }
        return $groupId;
    }

    protected function s4zEsc($value)
    {
        return $this->dbConn->prepare_input((string)$value);
    }
}
