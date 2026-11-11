<?php
/**
 * Stamps4ZenCart -- adds a Stamps.com Label button and a labels panel to the
 * admin order page (Customers > Orders > Edit).
 *
 * Both notifiers exist, with these arguments, on every release from v1.5.8 to
 * v3.0.0:
 *   NOTIFY_ADMIN_ORDERS_EDIT_BUTTONS          ($oID, $order, &$extra_buttons)
 *   NOTIFY_ADMIN_ORDERS_CONTENT_UNDER_PRODUCTS (['oID' => $oID], &$extra_content)
 *
 * Auto-discovered by core (file name auto.s4z_orders.php, class
 * zcObserverS4zOrders); never registered in an auto_loader.
 *
 * @package  Stamps4ZenCart
 * @license  https://www.gnu.org/licenses/gpl-3.0.html GNU General Public License v3.0
 */

class zcObserverS4zOrders extends base
{
    public function __construct()
    {
        $this->attach($this, ['NOTIFY_ADMIN_ORDERS_EDIT_BUTTONS', 'NOTIFY_ADMIN_ORDERS_CONTENT_UNDER_PRODUCTS']);
    }

    public function update(&$class, $eventID, $p1 = null, &$p2 = null, &$p3 = null, &$p4 = null, &$p5 = null)
    {
        if (!defined('FILENAME_STAMPS4ZENCART') || !defined('TABLE_S4Z_LABELS')) {
            return;
        }
        if ($eventID === 'NOTIFY_ADMIN_ORDERS_EDIT_BUTTONS') {
            $oID = (int)$p1;
            $p3 = (string)$p3 . ' <a href="' . zen_href_link(FILENAME_STAMPS4ZENCART, 'action=order&oID=' . $oID, 'SSL')
                . '" class="btn btn-primary" role="button">' . S4Z_BUTTON_ORDER_LABEL . '</a>';
            return;
        }
        if ($eventID === 'NOTIFY_ADMIN_ORDERS_CONTENT_UNDER_PRODUCTS') {
            $oID = is_array($p1) && isset($p1['oID']) ? (int)$p1['oID'] : 0;
            if ($oID > 0) {
                $p2 = (string)$p2 . $this->panel($oID);
            }
        }
    }

    /**
     * The order's labels, or nothing when it has none.
     */
    protected function panel($oID)
    {
        global $db;
        $r = $db->Execute(
            "SELECT s4z_label_id, environment, tracking_number, service_type, cost, status, track_status, track_description, created_at
               FROM " . TABLE_S4Z_LABELS . " WHERE orders_id = " . (int)$oID . " ORDER BY s4z_label_id DESC"
        );
        if ($r->EOF) {
            return '';
        }
        $env = defined('S4Z_ENVIRONMENT') && S4Z_ENVIRONMENT === 'production' ? 'production' : 'staging';
        $h = static function ($v) {
            return zen_output_string_protected((string)$v);
        };
        $rows = '';
        while (!$r->EOF) {
            $f = $r->fields;
            // Stamps.com reprints USPS labels only within 1 day of purchase.
            $print = ($f['environment'] === $env && $f['status'] === 'active' && strtotime((string)$f['created_at']) > time() - 86400 + 600)
                ? '<a href="' . zen_href_link(FILENAME_STAMPS4ZENCART, 'action=label_print&id=' . (int)$f['s4z_label_id'], 'SSL')
                  . '" target="_blank" rel="noopener" class="btn btn-default btn-sm">' . S4Z_BUTTON_PRINT_LABEL . '</a>'
                : '';
            $status = ($f['status'] === 'voided') ? S4Z_STATUS_VOIDED : S4Z_STATUS_ACTIVE;
            if ($f['environment'] !== 'production') {
                $status .= ' (' . S4Z_TEXT_ENV_STAGING . ')';
            }
            $rows .= '<tr><td>' . $h(zen_datetime_short($f['created_at'])) . '</td>'
                . '<td>' . $h(ucwords(str_replace(['usps_', '_'], ['USPS ', ' '], $f['service_type']))) . '</td>'
                . '<td>' . $h($f['tracking_number']) . ($f['track_status'] !== '' ? '<br>' . $h($f['track_description'] !== '' ? $f['track_description'] : ucwords(str_replace('_', ' ', $f['track_status']))) : '') . '</td>'
                . '<td>$' . $h(number_format((float)$f['cost'], 2)) . '</td>'
                . '<td>' . $h($status) . '</td>'
                . '<td>' . $print . '</td></tr>';
            $r->MoveNext();
        }
        return '<div class="row noprint" style="margin:12px 0"><h4>' . S4Z_HEADING_ORDER_LABELS . '</h4>'
            . '<table class="table table-condensed table-bordered" style="max-width:900px"><thead><tr>'
            . '<th>' . S4Z_COL_DATE . '</th><th>' . S4Z_COL_SERVICE . '</th><th>' . S4Z_COL_TRACKING . '</th>'
            . '<th>' . S4Z_COL_COST . '</th><th>' . S4Z_COL_STATUS . '</th><th></th></tr></thead><tbody>' . $rows . '</tbody></table>'
            . '<a href="' . zen_href_link(FILENAME_STAMPS4ZENCART, 'action=order&oID=' . (int)$oID, 'SSL') . '" class="btn btn-default btn-sm">'
            . S4Z_BUTTON_MANAGE_LABELS . '</a></div>';
    }
}
