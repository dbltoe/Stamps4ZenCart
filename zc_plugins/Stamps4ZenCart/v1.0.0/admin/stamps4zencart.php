<?php
/**
 * Stamps4ZenCart -- the admin page (Customers > Stamps4ZenCart).
 *
 *   (no action)        connection, balance, sample label, recent labels,
 *                      pickups and SCAN forms
 *   connect            POST: start connecting to Stamps.com
 *   connect_return     GET: back from Stamps.com, finish connecting
 *   disconnect         POST
 *   order&oID=N        Create Label form for one order; POST step=validate|rates|buy
 *   label_print&id=N   a page that prints the label (PNG, fetched fresh)
 *   label_pdf&id=N     the label file to download, fetched fresh from Stamps.com
 *   void&id=N          POST
 *   track&id=N         POST
 *   sample             POST: a test-marked sample label
 *   pickup             the Schedule a USPS Pickup form; POST step=create
 *   pickup_cancel&id=N POST
 *   scan_form          POST: SCAN form for the selected labels
 *
 * Every POST carries Zen Cart's securityToken (checked by core).
 *
 * Notifiers for add-ons (docs/CUSTOMIZING.md):
 *   NOTIFY_S4Z_CREATE_LABEL_ALLOWED  (['oID', 'order'], &$allowed)
 *   NOTIFY_S4Z_CREATE_LABEL_FORM     ($context, &$extraFields)
 *   NOTIFY_S4Z_LABEL_REQUEST         ($context, &$request)   before rates and before buying
 *   NOTIFY_S4Z_LABEL_BOUGHT          ($context + label details)
 *   NOTIFY_S4Z_LABEL_VOIDED          (['label'])
 *
 * @package  Stamps4ZenCart
 * @license  https://www.gnu.org/licenses/gpl-3.0.html GNU General Public License v3.0
 */
require 'includes/application_top.php';
require_once __DIR__ . '/includes/classes/s4z_classes.php';
if (!function_exists('zen_update_orders_history')) {
    require_once DIR_FS_CATALOG . DIR_WS_FUNCTIONS . 'functions_osh_update.php';
}

$s4zSera = new S4zSera();
$s4zEnv = $s4zSera->environment();
$s4zAction = isset($_GET['action']) ? (string)$_GET['action'] : '';
// Numeric ids from the URL, cast once here.
$s4zId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$s4zPrinted = isset($_GET['printed']) ? (int)$_GET['printed'] : 0;
$s4zIsPost = (isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'POST');
$s4zAdminName = substr((string)zen_get_admin_name(isset($_SESSION['admin_id']) ? (int)$_SESSION['admin_id'] : 0), 0, 64);
$s4zReturnUrl = HTTP_SERVER . DIR_WS_ADMIN . 'index.php?cmd=' . FILENAME_STAMPS4ZENCART;

/**
 * Plain value from POST, trimmed.
 */
function s4z_post($name, $default = '')
{
    return isset($_POST[$name]) && is_string($_POST[$name]) ? trim($_POST[$name]) : $default;
}

/**
 * HTML-escape.
 */
function s4z_h($value)
{
    return zen_output_string_protected((string)$value);
}

/**
 * A string as a JavaScript literal, safe inside an HTML attribute.
 */
function s4z_js($value)
{
    return htmlspecialchars(json_encode((string)$value), ENT_QUOTES, 'UTF-8');
}

/**
 * A Stamps.com date or date-time (ISO 8601) in the admin's own short format,
 * keeping the wall-clock time Stamps.com sent. Anything unreadable is shown as is.
 */
function s4z_when($value, $withTime = true)
{
    $value = trim((string)$value);
    $d = ($value === '') ? false : date_create($value);
    if ($d === false) {
        return $value;
    }
    $plain = $d->format('Y-m-d H:i:s');
    return $withTime ? zen_datetime_short($plain) : zen_date_short($plain);
}

/**
 * A pickup window as "11/12/2026, 8:00 AM to 5:00 PM" (the date in the admin's
 * short format, the times as Stamps.com sent them).
 */
function s4z_pickup_window($start, $end)
{
    $a = date_create((string)$start);
    $b = date_create((string)$end);
    if ($a === false || $b === false) {
        return trim($start . ' - ' . $end);
    }
    return s4z_when($start, false) . ', ' . $a->format('g:i A') . ' to ' . $b->format('g:i A');
}

/**
 * Money, always shown in US dollars (SERA's currency here).
 */
function s4z_money($amount)
{
    return '$' . number_format((float)$amount, 2);
}

/**
 * The address the admin edited on the Create Label form, in SERA's shape.
 * The country is the order's own (always US unless an add-on lets other
 * countries through NOTIFY_S4Z_CREATE_LABEL_ALLOWED).
 */
function s4z_posted_ship_to($countryCode = 'US')
{
    $countryCode = strtoupper(preg_replace('/[^A-Za-z]/', '', (string)$countryCode));
    return [
        'name' => s4z_post('to_name'),
        'company_name' => s4z_post('to_company'),
        'address_line1' => s4z_post('to_address1'),
        'address_line2' => s4z_post('to_address2'),
        'city' => s4z_post('to_city'),
        'state_province' => strtoupper(s4z_post('to_state')),
        'postal_code' => s4z_post('to_postcode'),
        'country_code' => strlen($countryCode) === 2 ? $countryCode : 'US',
        'phone' => s4z_post('to_phone'),
        'email' => s4z_post('to_email'),
    ];
}

/**
 * The package the admin described on the Create Label form, in SERA's shape.
 */
function s4z_posted_package()
{
    $unit = s4z_post('weight_unit', 'pound');
    $unit = in_array($unit, ['pound', 'ounce', 'kilogram', 'gram'], true) ? $unit : 'pound';
    $pkg = s4z_post('packaging_type', 'package');
    $package = [
        'packaging_type' => preg_replace('/[^a-z0-9_]/', '', $pkg),
        'weight' => round(max(0, (float)s4z_post('weight', '0')), 2),
        'weight_unit' => $unit,
    ];
    foreach (['length', 'width', 'height'] as $dim) {
        $v = (float)s4z_post($dim, '0');
        if ($v > 0) {
            $package[$dim] = round($v, 2);
        }
    }
    if (isset($package['length'])) {
        $package['dimension_unit'] = 'inch';
    }
    return $package;
}

/**
 * The ship date from the form: today or later, as Y-m-d.
 */
function s4z_posted_ship_date()
{
    $d = s4z_post('ship_date', date('Y-m-d'));
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) || $d < date('Y-m-d')) {
        $d = date('Y-m-d');
    }
    return $d;
}

/**
 * Sends a label file to the browser and stops.
 */
function s4z_send_label($mime, $bytes, $basename)
{
    $ext = ($mime === 'image/png') ? 'png' : 'pdf';
    header('Content-Type: ' . $mime);
    header('Content-Disposition: inline; filename="' . preg_replace('/[^A-Za-z0-9_-]/', '', $basename) . '.' . $ext . '"');
    header('Content-Length: ' . strlen($bytes));
    header('Cache-Control: no-store, private');
    header('X-Content-Type-Options: nosniff');
    echo $bytes;
    exit;
}

/**
 * Sends a page that shows the label at its real size and opens the browser's
 * print dialog as soon as it has loaded, then closes itself. The label is a
 * PNG fetched from Stamps.com for this request only; nothing is saved.
 *
 * @param string $pngBytes
 * @param string $title
 * @param string $pdfUrl link for "Download PDF", or '' for none
 */
function s4z_send_print_page($pngBytes, $title, $pdfUrl, $backUrl = '', $backText = '')
{
    $letter = (s4z_label_size() === 'letter');
    $w = $letter ? '8.5in' : '4in';
    $h = $letter ? '11in' : '6in';
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: no-store, private');
    header('X-Content-Type-Options: nosniff');
    echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><title>' . s4z_h($title) . '</title><style>'
        // No @page size: the printer's own paper decides. The page does set its margins, because
        // label printers can't print to the edge and not every print dialog offers a margins
        // choice (Chrome's does; the system dialog and some embedded browsers don't). Without
        // them, "Default" margins shrink a 4x6 label to about 85%. The 4x6 margins are the DYMO
        // LabelWriter 4XL's own dead zones: its 4x6 paper is 4.11x6.28in, printable 4.00x6.00in
        // from 0.057in left and 0.22in down. So the label prints at 100% there, and at about 95%
        // on an edge-to-edge 4x6 printer such as a Zebra. The label always scales to fit what's left.
        . '@page{margin:' . s4z_h($letter ? '0.25in' : '0.22in 0.06in 0.06in 0.06in') . '}'
        . 'html,body{margin:0;padding:0;background:#fff;color:#1b1f24;font-family:system-ui,"Segoe UI",Arial,sans-serif}'
        . '.s4z-label{display:block;margin:0 auto;object-fit:contain}'
        . '@media screen{.s4z-label{width:' . s4z_h($w) . ';height:' . s4z_h($h) . ';max-width:100%}}'
        . '@media print{.s4z-label{width:100%;height:auto;max-height:100vh;break-inside:avoid;page-break-inside:avoid}}'
        . '.s4z-bar{padding:12px 16px;display:flex;gap:10px;align-items:center;flex-wrap:wrap;border-bottom:1px solid #c8ccd0}'
        . '.s4z-bar button,.s4z-bar a{font:inherit;font-weight:600;padding:8px 14px;border-radius:6px;border:1px solid #0b3d91;cursor:pointer;text-decoration:none}'
        . '.s4z-bar button{background:#0b3d91;color:#fff}.s4z-bar a{background:#fff;color:#0b3d91}'
        . '.s4z-bar button:hover,.s4z-bar button:focus,.s4z-bar a:hover,.s4z-bar a:focus{box-shadow:0 0 0 3px #9ec5ff}'
        . '@media print{.s4z-bar{display:none}}'
        . '</style></head><body>'
        . '<div class="s4z-bar"><button type="button" onclick="window.print()">' . S4Z_BUTTON_PRINT_AGAIN . '</button>'
        . (s4z_h($pdfUrl) !== '' ? '<a href="' . s4z_h($pdfUrl) . '">' . S4Z_BUTTON_DOWNLOAD_PDF . '</a>' : '')
        . (s4z_h($backUrl) !== '' ? '<a href="' . s4z_h($backUrl) . '">' . s4z_h($backText) . '</a>' : '')
        . '<span>' . S4Z_TEXT_PRINT_TIP . '</span></div>'
        . '<img class="s4z-label" id="s4z-label" alt="' . s4z_h($title) . '" src="data:image/png;base64,' . s4z_h(base64_encode($pngBytes)) . '">'
        // After printing, close the tab only when the page has a tab of its own (opened by a
        // Print Label link); a page shown in the admin's own tab stays, with its back link.
        . '<script>(function(){var img=document.getElementById("s4z-label");'
        . 'function go(){window.onafterprint=function(){if(window.opener||history.length<2){window.close();}};window.print();}'
        . 'if(img.complete){go();}else{img.addEventListener("load",go);}})();</script>'
        . '</body></html>';
    exit;
}

/**
 * Redirect back to a view of this page.
 */
function s4z_back($params = '')
{
    zen_redirect(zen_href_link(FILENAME_STAMPS4ZENCART, $params, 'SSL'));
}

// ----------------------------------------------------------------------
// Actions that leave the page (redirects and file downloads)
// ----------------------------------------------------------------------

try {
    switch ($s4zAction) {
        case 'connect':
            if ($s4zIsPost) {
                header('Location: ' . $s4zSera->beginConnect($s4zReturnUrl), true, 303);
                exit;
            }
            break;

        case 'connect_return':
            if (!empty($_GET['s4z_error'])) {
                unset($_SESSION['s4z_connect']);
                $messageStack->add_session(($_GET['s4z_error'] === 'cancelled') ? S4Z_TEXT_CONNECT_CANCELLED : S4Z_ERROR_CONNECT_DENIED, 'warning');
                s4z_back();
            }
            $s4zSera->finishConnect(
                isset($_GET['state']) ? (string)$_GET['state'] : '',
                isset($_GET['pid']) ? (string)$_GET['pid'] : '',
                isset($_GET['code']) ? (string)$_GET['code'] : '',
                $s4zAdminName
            );
            $messageStack->add_session(S4Z_TEXT_CONNECTED, 'success');
            // The redirect also takes the code out of the address bar.
            s4z_back();
            break;

        case 'disconnect':
            if ($s4zIsPost) {
                $s4zSera->disconnect();
                $messageStack->add_session(S4Z_TEXT_DISCONNECTED, 'success');
                s4z_back();
            }
            break;

        case 'label_pdf':
            $row = s4z_label_row($s4zId);
            if ($row === null || $row['environment'] !== $s4zEnv) {
                $messageStack->add_session(S4Z_ERROR_LABEL_NOT_FOUND, 'error');
                s4z_back();
            }
            [$mime, $bytes] = $s4zSera->labelFile($row['label_id']);
            s4z_send_label($mime, $bytes, 'label-' . ($row['tracking_number'] !== '' ? $row['tracking_number'] : $row['s4z_label_id']));
            break;

        case 'label_print':
            // Always a PNG here: the browser prints it at real size through the printer's driver.
            $row = s4z_label_row($s4zId);
            if ($row === null || $row['environment'] !== $s4zEnv) {
                $messageStack->add_session(S4Z_ERROR_LABEL_NOT_FOUND, 'error');
                s4z_back();
            }
            // The first print uses the image that came with the purchase; after that,
            // Stamps.com's reprint endpoint (as its rules require).
            $bytes = s4z_take_first_print((int)$row['s4z_label_id']);
            if ($bytes === '') {
                [$mime, $bytes] = $s4zSera->labelFile($row['label_id'], 'png');
            }
            s4z_send_print_page($bytes, sprintf(S4Z_TEXT_PRINT_TITLE, $row['tracking_number']),
                zen_href_link(FILENAME_STAMPS4ZENCART, 'action=label_pdf&id=' . (int)$row['s4z_label_id'], 'SSL'));
            break;

        case 'sample':
            if ($s4zIsPost) {
                $missing = s4z_missing_from_fields();
                if (!empty($missing)) {
                    throw new S4zError(sprintf(S4Z_ERROR_FROM_MISSING, implode(', ', $missing)));
                }
                $from = s4z_from_address();
                // A PNG, so it goes to the same print page as a real label.
                $format = 'png';
                $result = $s4zSera->createLabel([
                    'from_address' => $from,
                    'return_address' => $from,
                    'to_address' => $from,
                    'service_type' => 'usps_ground_advantage',
                    'package' => ['packaging_type' => 'package', 'weight' => 1, 'weight_unit' => 'ounce',
                                  'length' => 6, 'width' => 4, 'height' => 2, 'dimension_unit' => 'inch'],
                    'delivery_confirmation_type' => 'tracking',
                    'ship_date' => date('Y-m-d'),
                    'label_options' => ['label_size' => s4z_label_size(), 'label_format' => $format, 'label_output_type' => 'base64'],
                    'is_test_label' => true,
                ]);
                [$mime, $bytes] = s4z_decode_label($result, $format);
                // Shown in the admin's own tab (the form has no target): some browsers turn a
                // POST into a new tab into a plain GET, which would print nothing.
                s4z_send_print_page($bytes, S4Z_TEXT_SAMPLE_TITLE, '',
                    zen_href_link(FILENAME_STAMPS4ZENCART, '', 'SSL'), S4Z_BUTTON_DASHBOARD);
            }
            break;

        case 'void':
            if ($s4zIsPost) {
                $row = s4z_label_row($s4zId);
                if ($row === null || $row['environment'] !== $s4zEnv || $row['status'] !== 'active') {
                    throw new S4zError(S4Z_ERROR_LABEL_NOT_FOUND);
                }
                try {
                    $s4zSera->voidLabel($row['label_id']);
                } catch (S4zError $e) {
                    // Stamps.com can't find a label for its first moments after purchase
                    // (staging 2026-11-08: void at 3s failed, the same void minutes later worked).
                    if (s4z_is_just_bought($row) && stripos($e->getMessage(), 'could not be found') !== false) {
                        throw new S4zError(S4Z_ERROR_VOID_TOO_SOON);
                    }
                    throw $e;
                }
                $db->Execute("UPDATE " . TABLE_S4Z_LABELS . " SET status = 'voided' WHERE s4z_label_id = " . (int)$row['s4z_label_id']);
                zen_update_orders_history((int)$row['orders_id'], sprintf(S4Z_HISTORY_VOIDED, $row['tracking_number']), $s4zAdminName, -1, 0);
                // For add-ons: the label is voided and recorded. Read-only.
                $zco_notifier->notify('NOTIFY_S4Z_LABEL_VOIDED', ['label' => $row]);
                $messageStack->add_session(sprintf(S4Z_TEXT_VOIDED, $row['tracking_number']), 'success');
                s4z_back('action=order&oID=' . (int)$row['orders_id']);
            }
            break;

        case 'pickup_cancel':
            if ($s4zIsPost) {
                $p = $db->Execute("SELECT * FROM " . TABLE_S4Z_PICKUPS . " WHERE s4z_pickup_id = " . $s4zId . " LIMIT 1");
                if ($p->EOF || $p->fields['environment'] !== $s4zEnv || $p->fields['status'] !== 'scheduled') {
                    throw new S4zError(S4Z_ERROR_PICKUP_NOT_FOUND);
                }
                $s4zSera->cancelPickup($p->fields['pickup_id']);
                $db->Execute("UPDATE " . TABLE_S4Z_PICKUPS . " SET status = 'cancelled' WHERE s4z_pickup_id = " . (int)$p->fields['s4z_pickup_id']);
                $db->Execute("UPDATE " . TABLE_S4Z_LABELS . " SET pickup_id = '' WHERE pickup_id = '" . zen_db_input($p->fields['pickup_id']) . "'");
                $messageStack->add_session(S4Z_TEXT_PICKUP_CANCELLED, 'success');
                s4z_back();
            }
            break;

        case 'scan_form':
            if ($s4zIsPost) {
                $ids = [];
                $rows = [];
                foreach ((isset($_POST['label']) && is_array($_POST['label'])) ? $_POST['label'] : [] as $id) {
                    $row = s4z_label_row((int)$id);
                    if ($row !== null && $row['environment'] === $s4zEnv && $row['status'] === 'active' && $row['manifest_id'] === '') {
                        $ids[] = $row['label_id'];
                        $rows[] = (int)$row['s4z_label_id'];
                    }
                }
                if (empty($ids)) {
                    throw new S4zError(S4Z_ERROR_SCAN_NONE);
                }
                $m = $s4zSera->createManifest($ids);
                $href = '';
                if (isset($m['labels']) && is_array($m['labels'])) {
                    foreach ($m['labels'] as $doc) {
                        if (is_array($doc) && !empty($doc['href'])) {
                            $href = (string)$doc['href'];
                            break;
                        }
                    }
                }
                $manifestId = isset($m['manifest_id']) ? (string)$m['manifest_id'] : '';
                zen_db_perform(TABLE_S4Z_MANIFESTS, [
                    'environment' => $s4zEnv,
                    'manifest_id' => $manifestId,
                    'carrier' => isset($m['carrier']) ? (string)$m['carrier'] : 'usps',
                    'ship_date' => isset($m['ship_date']) ? substr((string)$m['ship_date'], 0, 20) : date('Y-m-d'),
                    'items' => isset($m['number_of_items_in_manifest']) ? (int)$m['number_of_items_in_manifest'] : count($ids),
                    'document_url' => $href,
                    'created_at' => date('Y-m-d H:i:s'),
                    'created_by' => $s4zAdminName,
                ]);
                $db->Execute("UPDATE " . TABLE_S4Z_LABELS . " SET manifest_id = '" . zen_db_input($manifestId !== '' ? $manifestId : 'scan') . "'
                               WHERE s4z_label_id IN (" . implode(',', $rows) . ")");
                $messageStack->add_session(sprintf(S4Z_TEXT_SCAN_CREATED, count($ids)), 'success');
                s4z_back();
            }
            break;
    }
} catch (S4zError $e) {
    $messageStack->add_session($e->getMessage() . ($e->reconnect ? ' ' . S4Z_TEXT_RECONNECT_HINT : ''), 'error');
    if ($s4zAction === 'label_pdf' || $s4zAction === 'label_print' || $s4zAction === 'sample') {
        s4z_back();
    }
    $back = isset($row['orders_id']) ? 'action=order&oID=' . (int)$row['orders_id'] : '';
    s4z_back($s4zAction === 'pickup' ? 'action=pickup' : $back);
}

// ----------------------------------------------------------------------
// Actions that render a page
// ----------------------------------------------------------------------

$s4zView = 'dashboard';
$s4zOrder = null;
$s4zForm = [];
$s4zRates = [];
$s4zValidation = null;
$s4zTracking = null;
$s4zTrackRow = null;
$s4zBalance = null;
$s4zMissingFrom = s4z_missing_from_fields();

if ($s4zAction === 'order') {
    $s4zView = 'order';
    $oID = isset($_GET['oID']) ? (int)$_GET['oID'] : 0;
    $s4zOrder = s4z_order_ship_to($oID);
    if ($s4zOrder === null) {
        $messageStack->add_session(S4Z_ERROR_ORDER_NOT_FOUND, 'error');
        s4z_back();
    }
    $step = $s4zIsPost ? s4z_post('step') : '';

    // The form starts from the order and keeps whatever the admin changed.
    $s4zForm = [
        'to_name' => $s4zOrder['name'], 'to_company' => $s4zOrder['company_name'],
        'to_address1' => $s4zOrder['address_line1'], 'to_address2' => $s4zOrder['address_line2'],
        'to_city' => $s4zOrder['city'], 'to_state' => $s4zOrder['state_province'],
        'to_postcode' => $s4zOrder['postal_code'], 'to_phone' => $s4zOrder['phone'], 'to_email' => $s4zOrder['email'],
        'weight' => (string)s4z_order_weight($oID), 'weight_unit' => (string)s4z_setting('S4Z_WEIGHT_UNIT', 'pound'),
        'length' => '', 'width' => '', 'height' => '',
        'packaging_type' => (string)s4z_setting('S4Z_DEFAULT_PACKAGE', 'package'),
        'delivery_confirmation' => (string)s4z_setting('S4Z_DELIVERY_CONFIRMATION', 'tracking'),
        'ship_date' => date('Y-m-d'),
    ];
    if ($step !== '') {
        foreach (array_keys($s4zForm) as $k) {
            $s4zForm[$k] = s4z_post($k, $s4zForm[$k]);
        }
        $s4zForm['ship_date'] = s4z_posted_ship_date();
    }
    // Labels are for U.S. addresses. An add-on (international with customs, say)
    // may set $allowed to true for an order it handles.
    $s4zAllowed = ($s4zOrder['country_code'] === 'US');
    $zco_notifier->notify('NOTIFY_S4Z_CREATE_LABEL_ALLOWED', ['oID' => $oID, 'order' => $s4zOrder], $s4zAllowed);
    $s4zAllowed = ($s4zAllowed === true);
    $s4zContext = ['oID' => $oID, 'order' => $s4zOrder, 'form' => $s4zForm, 'step' => $step];

    try {
        if ($step !== '' && !$s4zAllowed) {
            throw new S4zError(S4Z_ERROR_DOMESTIC_ONLY);
        }
        if ($step !== '' && !empty($s4zMissingFrom)) {
            throw new S4zError(sprintf(S4Z_ERROR_FROM_MISSING, implode(', ', $s4zMissingFrom)));
        }
        $confirmation = in_array($s4zForm['delivery_confirmation'], ['tracking', 'signature', 'adult_signature', 'none'], true)
            ? $s4zForm['delivery_confirmation'] : 'tracking';

        if ($step === 'validate') {
            $s4zValidation = $s4zSera->validateAddress(s4z_posted_ship_to($s4zOrder['country_code']));
        } elseif ($step === 'use_matched') {
            foreach (['to_address1' => 'address_line1', 'to_address2' => 'address_line2', 'to_city' => 'city',
                      'to_state' => 'state_province', 'to_postcode' => 'postal_code'] as $field => $key) {
                $s4zForm[$field] = s4z_post('m_' . $key, $s4zForm[$field]);
            }
        } elseif ($step === 'rates') {
            $package = s4z_posted_package();
            if ($package['weight'] <= 0) {
                throw new S4zError(S4Z_ERROR_WEIGHT);
            }
            // Stamps.com needs all three dimensions for a plain package (not for
            // envelopes or flat rate boxes).
            if ($package['packaging_type'] === 'package' && !isset($package['length'], $package['width'], $package['height'])) {
                throw new S4zError(S4Z_ERROR_DIMENSIONS);
            }
            $request = [
                'from_address' => s4z_from_address(),
                'to_address' => s4z_posted_ship_to($s4zOrder['country_code']),
                'package' => $package,
                'delivery_confirmation_type' => $confirmation,
                'ship_date' => $s4zForm['ship_date'],
            ];
            // For add-ons: change what is sent (customs, insurance...), or throw
            // S4zError to stop with a message. Fired again, with step 'buy', before buying.
            $zco_notifier->notify('NOTIFY_S4Z_LABEL_REQUEST', $s4zContext, $request);
            $s4zRates = $s4zSera->rates($request);
            // Return-only services can't ship an outbound package.
            $s4zRates = array_values(array_filter(is_array($s4zRates) ? $s4zRates : [], static function ($r) {
                return is_array($r) && !empty($r['service_type']) && empty($r['is_return_label'])
                    && strpos((string)$r['service_type'], 'return') === false;
            }));
            if (empty($s4zRates)) {
                throw new S4zError(S4Z_ERROR_NO_RATES);
            }
            usort($s4zRates, static function ($a, $b) {
                $x = isset($a['shipment_cost']['total_amount']) ? (float)$a['shipment_cost']['total_amount'] : 0;
                $y = isset($b['shipment_cost']['total_amount']) ? (float)$b['shipment_cost']['total_amount'] : 0;
                return ($x < $y) ? -1 : (($x > $y) ? 1 : 0);
            });
            try {
                $s4zBalance = $s4zSera->balance();
            } catch (S4zError $e) {
                $s4zBalance = null;
            }
        } elseif ($step === 'buy') {
            $pick = explode('|', s4z_post('rate'));
            if (count($pick) !== 2 || $pick[0] === '' || $pick[1] === '') {
                throw new S4zError(S4Z_ERROR_PICK_RATE);
            }
            $package = s4z_posted_package();
            $package['packaging_type'] = preg_replace('/[^a-z0-9_]/', '', $pick[1]);
            if ($package['weight'] <= 0) {
                throw new S4zError(S4Z_ERROR_WEIGHT);
            }
            // Stamps.com needs all three dimensions for a plain package (not for
            // envelopes or flat rate boxes).
            if ($package['packaging_type'] === 'package' && !isset($package['length'], $package['width'], $package['height'])) {
                throw new S4zError(S4Z_ERROR_DIMENSIONS);
            }
            $serviceType = preg_replace('/[^a-z0-9_]/', '', $pick[0]);
            $from = s4z_from_address();
            $request = [
                'from_address' => $from,
                'return_address' => $from,
                'to_address' => s4z_posted_ship_to($s4zOrder['country_code']),
                'service_type' => $serviceType,
                'package' => $package,
                'delivery_confirmation_type' => $confirmation,
                'ship_date' => $s4zForm['ship_date'],
                // The label image comes back with the purchase (a PNG for the print page), so
                // the first print doesn't use up one of Stamps.com's few reprints.
                'label_options' => ['label_size' => s4z_label_size(), 'label_format' => 'png', 'label_output_type' => 'base64'],
                'references' => ['reference1' => 'Order ' . $oID],
                // Stamps.com requires order_date whenever order_details is sent.
                'order_details' => ['order_source' => 'Stamps4ZenCart', 'order_number' => (string)$oID, 'order_date' => $s4zOrder['order_date']],
            ];
            $zco_notifier->notify('NOTIFY_S4Z_LABEL_REQUEST', $s4zContext, $request);
            $label = $s4zSera->createLabel($request);
            if (empty($label['label_id'])) {
                throw new S4zError(S4Z_ERROR_NO_LABEL_ID);
            }
            $tracking = isset($label['tracking_number']) ? (string)$label['tracking_number'] : '';
            $cost = isset($label['shipment_cost']['total_amount']) ? (float)$label['shipment_cost']['total_amount'] : 0;
            zen_db_perform(TABLE_S4Z_LABELS, [
                'orders_id' => $oID,
                'environment' => $s4zEnv,
                'label_id' => (string)$label['label_id'],
                'tracking_number' => $tracking,
                'carrier' => isset($label['carrier']) ? (string)$label['carrier'] : 'usps',
                'service_type' => isset($label['service_type']) ? (string)$label['service_type'] : $serviceType,
                'packaging_type' => isset($label['packaging_type']) ? (string)$label['packaging_type'] : $package['packaging_type'],
                'weight' => $package['weight'],
                'weight_unit' => $package['weight_unit'],
                'cost' => $cost,
                'currency' => isset($label['shipment_cost']['currency']) ? substr((string)$label['shipment_cost']['currency'], 0, 3) : 'usd',
                'ship_date' => $s4zForm['ship_date'],
                'status' => 'active',
                'created_at' => date('Y-m-d H:i:s'),
                'created_by' => $s4zAdminName,
            ]);
            $newId = (int)$db->Insert_ID();
            s4z_hold_first_print($newId, $label);

            // Staging labels are fake: never email a customer one, never move
            // the order along because of one.
            $isLive = ($s4zEnv === 'production');
            $comment = sprintf($isLive ? S4Z_HISTORY_BOUGHT : S4Z_HISTORY_BOUGHT_STAGING, s4z_service_name($serviceType), $tracking)
                . ($isLive && $tracking !== '' ? "\n" . 'https://tools.usps.com/go/TrackConfirmAction?tLabels=' . rawurlencode($tracking) : '');
            $status = (int)s4z_setting('S4Z_ORDER_STATUS', '0');
            zen_update_orders_history(
                $oID,
                $comment,
                $s4zAdminName,
                ($isLive && $status > 0) ? $status : -1,
                ($isLive && (string)s4z_setting('S4Z_NOTIFY_CUSTOMER', 'true') === 'true') ? 1 : 0
            );
            // For add-ons: the label is bought and recorded. Read-only; the label
            // image is left out (it is held for the first print).
            unset($label['labels']);
            $zco_notifier->notify('NOTIFY_S4Z_LABEL_BOUGHT', $s4zContext + [
                's4z_label_id' => $newId, 'environment' => $s4zEnv, 'request' => $request, 'label' => $label,
            ]);
            $messageStack->add_session(sprintf(S4Z_TEXT_BOUGHT, s4z_money($cost), $tracking), 'success');
            s4z_back('action=order&oID=' . $oID . '&printed=' . $newId);
        } elseif ($step === 'track') {
            $s4zTrackRow = s4z_label_row($s4zId);
            if ($s4zTrackRow === null || $s4zTrackRow['environment'] !== $s4zEnv || (int)$s4zTrackRow['orders_id'] !== $oID) {
                throw new S4zError(S4Z_ERROR_LABEL_NOT_FOUND);
            }
            try {
                $s4zTracking = $s4zSera->tracking($s4zTrackRow['carrier'], $s4zTrackRow['tracking_number']);
            } catch (S4zError $e) {
                // A new label has no scans yet; Stamps.com answers that with an
                // error, but for the admin it's just "nothing yet".
                if (stripos($e->getMessage(), 'does not have any tracking information') === false) {
                    throw $e;
                }
                $s4zTracking = ['status_code' => '', 'carrier_status_description' => S4Z_TEXT_NO_TRACKING_YET, 'tracking_events' => []];
            }
            $db->Execute(
                "UPDATE " . TABLE_S4Z_LABELS . "
                    SET track_status = '" . zen_db_input(substr(isset($s4zTracking['status_code']) ? (string)$s4zTracking['status_code'] : '', 0, 32)) . "',
                        track_description = '" . zen_db_input(substr(isset($s4zTracking['carrier_status_description']) ? (string)$s4zTracking['carrier_status_description'] : '', 0, 255)) . "',
                        track_est_delivery = '" . zen_db_input(substr(isset($s4zTracking['estimated_delivery_date']) ? (string)$s4zTracking['estimated_delivery_date'] : '', 0, 32)) . "',
                        tracked_at = now()
                  WHERE s4z_label_id = " . (int)$s4zTrackRow['s4z_label_id']
            );
        }
    } catch (S4zError $e) {
        $messageStack->add($e->getMessage() . ($e->reconnect ? ' ' . S4Z_TEXT_RECONNECT_HINT : ''), 'error');
    }
    $s4zLabels = s4z_order_labels($oID);
} elseif ($s4zAction === 'pickup') {
    $s4zView = 'pickup';
    $s4zPickupDay = s4z_next_pickup_day();
    if ($s4zIsPost && s4z_post('step') === 'create') {
        try {
            $chosen = [];
            $hasPriority = false;
            foreach ((isset($_POST['label']) && is_array($_POST['label'])) ? $_POST['label'] : [] as $id) {
                $row = s4z_label_row((int)$id);
                if ($row !== null && $row['environment'] === $s4zEnv && $row['status'] === 'active' && $row['pickup_id'] === '' && $row['carrier'] === 'usps') {
                    $chosen[(int)$row['s4z_label_id']] = $row['label_id'];
                    $hasPriority = $hasPriority || s4z_is_priority_service($row['service_type']);
                }
            }
            if (empty($chosen)) {
                throw new S4zError(S4Z_ERROR_PICKUP_NONE);
            }
            if (!$hasPriority) {
                throw new S4zError(S4Z_ERROR_PICKUP_PRIORITY);
            }
            if (trim((string)s4z_setting('S4Z_FROM_PHONE')) === '') {
                throw new S4zError(S4Z_ERROR_PICKUP_PHONE);
            }
            $windows = ['morning' => ['08:00', '12:00'], 'afternoon' => ['12:00', '17:00'], 'day' => ['08:00', '17:00']];
            $w = s4z_post('window', 'day');
            $w = $windows[$w] ?? $windows['day'];
            $request = [
                'pickup_address' => s4z_from_address(),
                'carrier' => 'usps',
                'pickup_window' => [
                    'start_at' => date('c', strtotime($s4zPickupDay . ' ' . $w[0])),
                    'end_at' => date('c', strtotime($s4zPickupDay . ' ' . $w[1])),
                ],
                'label_ids' => array_values($chosen),
            ];
            // Stamps.com requires pickup instructions (not marked required in its docs).
            $instructions = substr(s4z_post('pickup_instructions'), 0, 250);
            if ($instructions === '') {
                throw new S4zError(S4Z_ERROR_PICKUP_INSTRUCTIONS);
            }
            $request['pickup_instructions'] = $instructions;
            $p = $s4zSera->createPickup($request);
            $pickupId = isset($p['pickup_id']) ? (string)$p['pickup_id'] : '';
            zen_db_perform(TABLE_S4Z_PICKUPS, [
                'environment' => $s4zEnv,
                'pickup_id' => $pickupId,
                'carrier' => 'usps',
                'window_start' => isset($p['pickup_window']['start_at']) ? substr((string)$p['pickup_window']['start_at'], 0, 40) : $request['pickup_window']['start_at'],
                'window_end' => isset($p['pickup_window']['end_at']) ? substr((string)$p['pickup_window']['end_at'], 0, 40) : $request['pickup_window']['end_at'],
                'items' => isset($p['number_of_items_in_pickup']) ? (int)$p['number_of_items_in_pickup'] : count($chosen),
                'cost' => isset($p['estimated_cost']['amount']) ? (float)$p['estimated_cost']['amount'] : 0,
                'status' => 'scheduled',
                'label_ids' => implode(',', array_keys($chosen)),
                'created_at' => date('Y-m-d H:i:s'),
                'created_by' => $s4zAdminName,
            ]);
            $db->Execute("UPDATE " . TABLE_S4Z_LABELS . " SET pickup_id = '" . zen_db_input($pickupId !== '' ? $pickupId : 'pickup') . "'
                           WHERE s4z_label_id IN (" . implode(',', array_keys($chosen)) . ")");
            $messageStack->add_session(sprintf(S4Z_TEXT_PICKUP_SCHEDULED, zen_date_long($s4zPickupDay), $pickupId), 'success');
            s4z_back();
        } catch (S4zError $e) {
            $messageStack->add($e->getMessage() . ($e->reconnect ? ' ' . S4Z_TEXT_RECONNECT_HINT : ''), 'error');
        }
    }
}

if ($s4zView === 'dashboard' && $s4zSera->isConnected()) {
    try {
        $s4zBalance = $s4zSera->balance();
    } catch (S4zError $e) {
        $messageStack->add($e->getMessage() . ($e->reconnect ? ' ' . S4Z_TEXT_RECONNECT_HINT : ''), 'warning');
    }
}

/**
 * Rows of a simple SELECT, as arrays.
 */
function s4z_rows($sql)
{
    global $db;
    $rows = [];
    $r = $db->Execute($sql, false, false, 0, true);
    while (!$r->EOF) {
        $rows[] = $r->fields;
        $r->MoveNext();
    }
    return $rows;
}

$s4zEnvSql = "'" . zen_db_input($s4zEnv) . "'";
$s4zOpenLabels = s4z_rows(
    "SELECT * FROM " . TABLE_S4Z_LABELS . "
      WHERE environment = $s4zEnvSql AND status = 'active' AND created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
      ORDER BY s4z_label_id DESC"
);

$s4zConfigGid = 0;
$g = $db->Execute("SELECT configuration_group_id FROM " . TABLE_CONFIGURATION_GROUP . " WHERE configuration_group_title = 'Stamps4ZenCart' LIMIT 1");
if (!$g->EOF) {
    $s4zConfigGid = (int)$g->fields['configuration_group_id'];
}
$s4zSettingsUrl = zen_href_link(FILENAME_CONFIGURATION, 'gID=' . $s4zConfigGid, 'SSL');

?>
<!doctype html>
<html <?php echo HTML_PARAMS; ?>>
<head>
<?php require DIR_WS_INCLUDES . 'admin_html_head.php'; ?>
<style>
.s4z-env{display:inline-block;padding:2px 10px;border-radius:12px;font-weight:700;font-size:13px;vertical-align:middle}
.s4z-env-staging{background:#fff3cd;color:#5c4400;border:1px solid #b38600}
.s4z-env-production{background:#d1e7dd;color:#0a3622;border:1px solid #0f5132}
.s4z-box{border:1px solid #c8ccd0;border-radius:6px;padding:14px 18px;margin:0 0 18px;background:#fff}
.s4z-box h2{font-size:18px;margin:0 0 10px;line-height:1.35}
.s4z-box p{line-height:1.5}
.s4z-table{width:100%;border-collapse:collapse;margin:6px 0 10px}
.s4z-table th,.s4z-table td{border:1px solid #c8ccd0;padding:6px 8px;vertical-align:top;text-align:left}
.s4z-table th{background:#eef1f4}
.s4z-form label{display:block;font-weight:700;margin:8px 0 2px}
.s4z-form input[type=text],.s4z-form input[type=number],.s4z-form input[type=date],.s4z-form select{max-width:100%;width:320px}
.s4z-form .s4z-dims input{width:90px;display:inline-block}
.s4z-actions{display:flex;flex-wrap:wrap;gap:8px;margin:12px 0 4px}
.s4z-actions form{display:inline}
.s4z-table .s4z-actions{margin:0}
.s4z-note{color:#3d444d}
.s4z-warn{border-left:4px solid #b38600;background:#fff8e1;padding:10px 14px;margin:0 0 14px}
</style>
</head>
<body>
<?php require DIR_WS_INCLUDES . 'header.php'; ?>
<div class="container-fluid">
<h1><?php echo HEADING_TITLE; ?> <span class="s4z-env s4z-env-<?php echo s4z_h($s4zEnv); ?>"><?php echo s4z_h($s4zEnv === 'production' ? S4Z_TEXT_ENV_PRODUCTION : S4Z_TEXT_ENV_STAGING); ?></span></h1>

<?php if ($s4zEnv !== 'production') { ?>
<div class="s4z-warn"><?php echo S4Z_TEXT_STAGING_WARNING; ?></div>
<?php } ?>

<?php if (!empty($s4zMissingFrom)) { ?>
<div class="s4z-warn"><?php echo sprintf(S4Z_TEXT_FROM_MISSING, s4z_h(implode(', ', $s4zMissingFrom)), $s4zSettingsUrl); ?></div>
<?php } ?>

<?php if ($s4zView === 'order') { ?>
<p><a class="btn btn-default" href="<?php echo zen_href_link(FILENAME_ORDERS, 'oID=' . (int)$oID . '&action=edit', 'SSL'); ?>"><?php echo sprintf(S4Z_BUTTON_BACK_TO_ORDER, (int)$oID); ?></a>
<a class="btn btn-default" href="<?php echo zen_href_link(FILENAME_STAMPS4ZENCART, '', 'SSL'); ?>"><?php echo S4Z_BUTTON_DASHBOARD; ?></a></p>

<?php if ($s4zPrinted > 0) { ?>
<div class="s4z-box">
  <p><?php echo S4Z_TEXT_PRINT_READY; ?></p>
  <p><a class="btn btn-primary" target="_blank" rel="noopener" href="<?php echo zen_href_link(FILENAME_STAMPS4ZENCART, 'action=label_print&id=' . $s4zPrinted, 'SSL'); ?>"><?php echo S4Z_BUTTON_PRINT_LABEL; ?></a></p>
</div>
<?php } ?>

<?php if (!empty($s4zLabels)) { ?>
<div class="s4z-box">
  <h2><?php echo S4Z_HEADING_ORDER_LABELS; ?></h2>
  <table class="s4z-table">
    <thead><tr><th><?php echo S4Z_COL_DATE; ?></th><th><?php echo S4Z_COL_SERVICE; ?></th><th><?php echo S4Z_COL_TRACKING; ?></th><th><?php echo S4Z_COL_COST; ?></th><th><?php echo S4Z_COL_STATUS; ?></th><th><?php echo S4Z_COL_ACTIONS; ?></th></tr></thead>
    <tbody>
<?php foreach ($s4zLabels as $l) { $mine = ($l['environment'] === $s4zEnv); ?>
      <tr>
        <td><?php echo s4z_h(zen_datetime_short($l['created_at'])); ?><?php echo $mine ? '' : ' <span class="s4z-note">(' . s4z_h($l['environment']) . ')</span>'; ?></td>
        <td><?php echo s4z_h(s4z_service_name($l['service_type'])); ?><br><span class="s4z-note"><?php echo s4z_h(s4z_package_name($l['packaging_type'])); ?></span></td>
        <td><?php echo s4z_h($l['tracking_number']); ?><?php if ($l['track_status'] !== '') { ?><br><span class="s4z-note"><?php echo s4z_h($l['track_description'] !== '' ? $l['track_description'] : ucwords(str_replace('_', ' ', $l['track_status']))); ?><?php echo $l['track_est_delivery'] !== '' ? ' &middot; ' . s4z_h(sprintf(S4Z_TEXT_EST_DELIVERY, s4z_when($l['track_est_delivery'], false))) : ''; ?></span><?php } ?></td>
        <td><?php echo s4z_h(s4z_money($l['cost'])); ?></td>
        <td><?php echo s4z_h($l['status'] === 'voided' ? S4Z_STATUS_VOIDED : S4Z_STATUS_ACTIVE); ?></td>
        <td>
<?php if ($mine && $l['status'] === 'active') { ?>
          <div class="s4z-actions">
<?php if (s4z_can_reprint($l)) { ?>
            <a class="btn btn-primary btn-sm" target="_blank" rel="noopener" href="<?php echo zen_href_link(FILENAME_STAMPS4ZENCART, 'action=label_print&id=' . (int)$l['s4z_label_id'], 'SSL'); ?>"><?php echo S4Z_BUTTON_PRINT_LABEL; ?></a>
<?php } else { ?>
            <span class="s4z-note"><?php echo S4Z_TEXT_REPRINT_CLOSED; ?></span>
<?php } ?>
            <?php echo zen_draw_form('s4zTrack' . (int)$l['s4z_label_id'], FILENAME_STAMPS4ZENCART, 'action=order&oID=' . (int)$oID . '&id=' . (int)$l['s4z_label_id'], 'post'); ?>
              <input type="hidden" name="step" value="track"><button type="submit" class="btn btn-default btn-sm"><?php echo S4Z_BUTTON_TRACK; ?></button></form>
            <?php echo zen_draw_form('s4zVoid' . (int)$l['s4z_label_id'], FILENAME_STAMPS4ZENCART, 'action=void&id=' . (int)$l['s4z_label_id'], 'post', 'onsubmit="return confirm(' . s4z_js(S4Z_CONFIRM_VOID) . ');"'); ?>
              <button type="submit" class="btn btn-default btn-sm"><?php echo S4Z_BUTTON_VOID; ?></button></form>
          </div>
<?php } ?>
        </td>
      </tr>
<?php } ?>
    </tbody>
  </table>
<?php if ($s4zTracking !== null) { ?>
  <h2><?php echo sprintf(S4Z_HEADING_TRACKING, s4z_h($s4zTrackRow['tracking_number'])); ?></h2>
  <p><strong><?php echo s4z_h(isset($s4zTracking['carrier_status_description']) && $s4zTracking['carrier_status_description'] !== '' ? $s4zTracking['carrier_status_description'] : ($s4zTracking['status_code'] ?? '')); ?></strong>
  <?php if (!empty($s4zTracking['estimated_delivery_date'])) { echo ' &middot; ' . s4z_h(sprintf(S4Z_TEXT_EST_DELIVERY, s4z_when($s4zTracking['estimated_delivery_date'], false))); } ?></p>
  <?php $events = isset($s4zTracking['tracking_events']) && is_array($s4zTracking['tracking_events']) ? $s4zTracking['tracking_events'] : []; ?>
  <?php if (empty($events)) { ?><p class="s4z-note"><?php echo S4Z_TEXT_NO_EVENTS; ?></p><?php } else { ?>
  <table class="s4z-table"><thead><tr><th><?php echo S4Z_COL_WHEN; ?></th><th><?php echo S4Z_COL_EVENT; ?></th><th><?php echo S4Z_COL_WHERE; ?></th></tr></thead><tbody>
  <?php foreach ($events as $ev) { if (!is_array($ev)) { continue; } ?>
    <tr><td><?php echo s4z_h(s4z_when($ev['occurred_at'] ?? '')); ?></td>
        <td><?php echo s4z_h($ev['event_description'] ?? ''); ?></td>
        <td><?php echo s4z_h(trim(($ev['city'] ?? '') . ' ' . ($ev['state_province'] ?? '') . ' ' . ($ev['postal_code'] ?? ''))); ?></td></tr>
  <?php } ?>
  </tbody></table>
  <?php } ?>
<?php } ?>
</div>
<?php } ?>

<div class="s4z-box">
  <h2><?php echo sprintf(S4Z_HEADING_CREATE_LABEL, (int)$oID); ?></h2>
<?php if (!$s4zSera->isConnected()) { ?>
  <p><?php echo sprintf(S4Z_TEXT_CONNECT_FIRST, zen_href_link(FILENAME_STAMPS4ZENCART, '', 'SSL')); ?></p>
<?php } elseif (!$s4zAllowed) { ?>
  <p><?php echo sprintf(S4Z_TEXT_INTERNATIONAL, s4z_h($s4zOrder['country_name'])); ?></p>
<?php } else { ?>
  <?php echo zen_draw_form('s4zLabel', FILENAME_STAMPS4ZENCART, 'action=order&oID=' . (int)$oID, 'post', 'class="s4z-form"'); ?>
  <div class="row">
    <div class="col-sm-6">
      <h3 style="font-size:16px"><?php echo S4Z_HEADING_SHIP_TO; ?></h3>
<?php foreach (['to_name' => S4Z_FIELD_NAME, 'to_company' => S4Z_FIELD_COMPANY, 'to_address1' => S4Z_FIELD_ADDRESS1, 'to_address2' => S4Z_FIELD_ADDRESS2,
                'to_city' => S4Z_FIELD_CITY, 'to_state' => S4Z_FIELD_STATE, 'to_postcode' => S4Z_FIELD_POSTCODE, 'to_phone' => S4Z_FIELD_PHONE, 'to_email' => S4Z_FIELD_EMAIL] as $f => $title) { ?>
      <label for="s4z-<?php echo $f; ?>"><?php echo $title; ?></label>
      <input type="text" id="s4z-<?php echo $f; ?>" name="<?php echo $f; ?>" value="<?php echo s4z_h($s4zForm[$f]); ?>" class="form-control"<?php echo in_array($f, ['to_address1', 'to_city', 'to_state', 'to_postcode'], true) ? ' required' : ''; ?>>
<?php } ?>
<?php if (is_array($s4zValidation) && !empty($s4zValidation)) {
    $matched = isset($s4zValidation['matched_address']) && is_array($s4zValidation['matched_address']) ? $s4zValidation['matched_address'] : null;
    $result = isset($s4zValidation['validation_results']['result_description']) ? (string)$s4zValidation['validation_results']['result_description'] : '';
?>
      <div class="s4z-box" style="margin-top:12px">
        <p><strong><?php echo S4Z_TEXT_VALIDATION; ?></strong> <?php echo s4z_h($result); ?></p>
<?php if ($matched !== null && !empty($matched['address_line1'])) { ?>
        <p><?php echo S4Z_TEXT_MATCHED; ?><br><?php echo s4z_h($matched['address_line1']); ?><?php echo !empty($matched['address_line2']) ? '<br>' . s4z_h($matched['address_line2']) : ''; ?><br>
           <?php echo s4z_h(($matched['city'] ?? '') . ', ' . ($matched['state_province'] ?? '') . ' ' . ($matched['postal_code'] ?? '')); ?></p>
<?php foreach (['address_line1', 'address_line2', 'city', 'state_province', 'postal_code'] as $mk) { ?>
        <input type="hidden" name="m_<?php echo $mk; ?>" value="<?php echo s4z_h($matched[$mk] ?? ''); ?>">
<?php } ?>
        <button type="submit" name="step" value="use_matched" class="btn btn-default"><?php echo S4Z_BUTTON_USE_MATCHED; ?></button>
<?php } ?>
      </div>
<?php } ?>
    </div>
    <div class="col-sm-6">
      <h3 style="font-size:16px"><?php echo S4Z_HEADING_PACKAGE; ?></h3>
      <label for="s4z-weight"><?php echo S4Z_FIELD_WEIGHT; ?></label>
      <input type="number" step="0.01" min="0" id="s4z-weight" name="weight" value="<?php echo s4z_h($s4zForm['weight']); ?>" class="form-control" style="width:140px;display:inline-block" required>
      <select name="weight_unit" class="form-control" style="width:150px;display:inline-block" aria-label="<?php echo s4z_h(S4Z_FIELD_WEIGHT_UNIT); ?>">
<?php foreach (['pound' => S4Z_UNIT_POUND, 'ounce' => S4Z_UNIT_OUNCE, 'kilogram' => S4Z_UNIT_KILOGRAM, 'gram' => S4Z_UNIT_GRAM] as $u => $ut) { ?>
        <option value="<?php echo $u; ?>"<?php echo $s4zForm['weight_unit'] === $u ? ' selected' : ''; ?>><?php echo $ut; ?></option>
<?php } ?>
      </select>
      <p class="s4z-note"><?php echo S4Z_TEXT_WEIGHT_NOTE; ?></p>
      <label><?php echo S4Z_FIELD_DIMENSIONS; ?></label>
      <div class="s4z-dims">
        <input type="number" step="0.1" min="0" name="length" value="<?php echo s4z_h($s4zForm['length']); ?>" class="form-control" aria-label="<?php echo s4z_h(S4Z_FIELD_LENGTH); ?>" placeholder="<?php echo s4z_h(S4Z_FIELD_LENGTH); ?>"> &times;
        <input type="number" step="0.1" min="0" name="width" value="<?php echo s4z_h($s4zForm['width']); ?>" class="form-control" aria-label="<?php echo s4z_h(S4Z_FIELD_WIDTH); ?>" placeholder="<?php echo s4z_h(S4Z_FIELD_WIDTH); ?>"> &times;
        <input type="number" step="0.1" min="0" name="height" value="<?php echo s4z_h($s4zForm['height']); ?>" class="form-control" aria-label="<?php echo s4z_h(S4Z_FIELD_HEIGHT); ?>" placeholder="<?php echo s4z_h(S4Z_FIELD_HEIGHT); ?>">
      </div>
      <label for="s4z-pkg"><?php echo S4Z_FIELD_PACKAGE; ?></label>
      <select id="s4z-pkg" name="packaging_type" class="form-control">
<?php foreach (['package', 'large_envelope', 'usps_flat_rate_envelope', 'usps_padded_flat_rate_envelope', 'usps_legal_flat_rate_envelope', 'usps_small_flat_rate_box', 'usps_medium_flat_rate_box', 'usps_large_flat_rate_box'] as $pk) { ?>
        <option value="<?php echo $pk; ?>"<?php echo $s4zForm['packaging_type'] === $pk ? ' selected' : ''; ?>><?php echo s4z_h(s4z_package_name($pk)); ?></option>
<?php } ?>
      </select>
      <label for="s4z-conf"><?php echo S4Z_FIELD_CONFIRMATION; ?></label>
      <select id="s4z-conf" name="delivery_confirmation" class="form-control">
<?php foreach (['tracking' => S4Z_CONF_TRACKING, 'signature' => S4Z_CONF_SIGNATURE, 'adult_signature' => S4Z_CONF_ADULT, 'none' => S4Z_CONF_NONE] as $ck => $ct) { ?>
        <option value="<?php echo $ck; ?>"<?php echo $s4zForm['delivery_confirmation'] === $ck ? ' selected' : ''; ?>><?php echo $ct; ?></option>
<?php } ?>
      </select>
      <label for="s4z-date"><?php echo S4Z_FIELD_SHIP_DATE; ?></label>
      <input type="date" id="s4z-date" name="ship_date" min="<?php echo date('Y-m-d'); ?>" value="<?php echo s4z_h($s4zForm['ship_date']); ?>" class="form-control">
    </div>
  </div>
<?php
// For add-ons: HTML blocks shown inside the form (customs details, say). The add-on
// escapes its own output and reads its fields back from $_POST.
$s4zExtraFields = [];
$zco_notifier->notify('NOTIFY_S4Z_CREATE_LABEL_FORM', $s4zContext, $s4zExtraFields);
foreach (is_array($s4zExtraFields) ? $s4zExtraFields : [] as $s4zExtraHtml) {
    echo '  <div class="s4z-extra">' . (string)$s4zExtraHtml . "</div>\n";
}
?>

<?php if (!empty($s4zRates)) { ?>
  <h3 style="font-size:16px;margin-top:18px"><?php echo S4Z_HEADING_RATES; ?></h3>
<?php if (is_array($s4zBalance) && isset($s4zBalance['amount_available'])) { ?>
  <p><?php echo sprintf(S4Z_TEXT_BALANCE, s4z_h(s4z_money($s4zBalance['amount_available']))); ?></p>
<?php } ?>
  <table class="s4z-table">
    <thead><tr><th></th><th><?php echo S4Z_COL_SERVICE; ?></th><th><?php echo S4Z_COL_PACKAGE; ?></th><th><?php echo S4Z_COL_DELIVERY; ?></th><th><?php echo S4Z_COL_COST; ?></th></tr></thead>
    <tbody>
<?php $first = true; $default = (string)s4z_setting('S4Z_DEFAULT_SERVICE', 'usps_ground_advantage');
      foreach ($s4zRates as $i => $r) {
          if (!is_array($r) || empty($r['service_type'])) { continue; }
          $value = $r['service_type'] . '|' . ($r['packaging_type'] ?? $s4zForm['packaging_type']);
          $cost = isset($r['shipment_cost']['total_amount']) ? (float)$r['shipment_cost']['total_amount'] : 0;
          $over = is_array($s4zBalance) && isset($s4zBalance['amount_available']) && $cost > (float)$s4zBalance['amount_available'];
          $checked = ($r['service_type'] === $default) || ($first && strpos(serialize($s4zRates), '"' . $default . '"') === false);
          $first = false; ?>
      <tr>
        <td><input type="radio" name="rate" id="s4z-rate-<?php echo (int)$i; ?>" value="<?php echo s4z_h($value); ?>"<?php echo $checked ? ' checked' : ''; ?>></td>
        <td><label for="s4z-rate-<?php echo (int)$i; ?>" style="margin:0"><?php echo s4z_h(s4z_service_name($r['service_type'])); ?></label></td>
        <td><?php echo s4z_h(s4z_package_name($r['packaging_type'] ?? '')); ?></td>
        <td><?php echo s4z_h(!empty($r['estimated_delivery_days']) ? sprintf(S4Z_TEXT_DAYS, $r['estimated_delivery_days']) : ''); ?></td>
        <td><?php echo s4z_h(s4z_money($cost)); ?><?php echo $over ? ' <strong>' . S4Z_TEXT_OVER_BALANCE . '</strong>' : ''; ?></td>
      </tr>
<?php } ?>
    </tbody>
  </table>
<?php } ?>

  <div class="s4z-actions">
    <button type="submit" name="step" value="validate" class="btn btn-default"><?php echo S4Z_BUTTON_VALIDATE; ?></button>
    <button type="submit" name="step" value="rates" class="btn btn-primary"><?php echo S4Z_BUTTON_GET_RATES; ?></button>
<?php if (!empty($s4zRates)) { ?>
    <button type="submit" name="step" value="buy" class="btn btn-primary" onclick="return confirm(<?php echo s4z_js(S4Z_CONFIRM_BUY); ?>);"><?php echo S4Z_BUTTON_BUY; ?></button>
<?php } ?>
  </div>
  </form>
<?php } ?>
</div>

<?php } elseif ($s4zView === 'pickup') { ?>
<p><a class="btn btn-default" href="<?php echo zen_href_link(FILENAME_STAMPS4ZENCART, '', 'SSL'); ?>"><?php echo S4Z_BUTTON_DASHBOARD; ?></a></p>
<div class="s4z-box">
  <h2><?php echo S4Z_HEADING_PICKUP; ?></h2>
  <p><?php echo sprintf(S4Z_TEXT_PICKUP_INTRO, s4z_h(zen_date_long($s4zPickupDay))); ?></p>
<?php $eligible = array_values(array_filter($s4zOpenLabels, static function ($l) { return $l['pickup_id'] === '' && $l['carrier'] === 'usps'; })); ?>
<?php if (empty($eligible)) { ?>
  <p><?php echo S4Z_TEXT_PICKUP_NO_LABELS; ?></p>
<?php } else { ?>
  <?php echo zen_draw_form('s4zPickup', FILENAME_STAMPS4ZENCART, 'action=pickup', 'post', 'class="s4z-form"'); ?>
  <input type="hidden" name="step" value="create">
  <table class="s4z-table">
    <thead><tr><th><?php echo S4Z_COL_INCLUDE; ?></th><th><?php echo S4Z_COL_ORDER; ?></th><th><?php echo S4Z_COL_SERVICE; ?></th><th><?php echo S4Z_COL_TRACKING; ?></th></tr></thead>
    <tbody>
<?php foreach ($eligible as $l) { ?>
      <tr><td><input type="checkbox" name="label[]" value="<?php echo (int)$l['s4z_label_id']; ?>" checked aria-label="<?php echo s4z_h(sprintf(S4Z_TEXT_INCLUDE_LABEL, $l['tracking_number'])); ?>"></td>
          <td><?php echo (int)$l['orders_id']; ?></td><td><?php echo s4z_h(s4z_service_name($l['service_type'])); ?></td><td><?php echo s4z_h($l['tracking_number']); ?></td></tr>
<?php } ?>
    </tbody>
  </table>
  <label for="s4z-window"><?php echo S4Z_FIELD_WINDOW; ?></label>
  <select id="s4z-window" name="window" class="form-control">
    <option value="day"><?php echo S4Z_WINDOW_DAY; ?></option>
    <option value="morning"><?php echo S4Z_WINDOW_MORNING; ?></option>
    <option value="afternoon"><?php echo S4Z_WINDOW_AFTERNOON; ?></option>
  </select>
  <label for="s4z-instr"><?php echo S4Z_FIELD_INSTRUCTIONS; ?></label>
  <input type="text" id="s4z-instr" name="pickup_instructions" maxlength="250" class="form-control" required placeholder="<?php echo s4z_h(S4Z_PLACEHOLDER_INSTRUCTIONS); ?>">
  <p class="s4z-note"><?php echo S4Z_TEXT_PICKUP_RULES; ?></p>
  <div class="s4z-actions"><button type="submit" class="btn btn-primary"><?php echo S4Z_BUTTON_SCHEDULE_PICKUP; ?></button></div>
  </form>
<?php } ?>
</div>

<?php } else { ?>
<div class="row">
  <div class="col-md-6">
    <div class="s4z-box">
      <h2><?php echo S4Z_HEADING_CONNECTION; ?></h2>
<?php if ($s4zSera->isConnected()) { ?>
      <p><?php echo sprintf(S4Z_TEXT_CONNECTED_SINCE, s4z_h(zen_datetime_short($s4zSera->connectedAt())), s4z_h($s4zSera->connectedBy())); ?></p>
<?php if (is_array($s4zBalance) && isset($s4zBalance['amount_available'])) { ?>
      <p><?php echo sprintf(S4Z_TEXT_BALANCE, s4z_h(s4z_money($s4zBalance['amount_available']))); ?> <span class="s4z-note"><?php echo S4Z_TEXT_ADD_FUNDS; ?></span></p>
<?php } ?>
      <div class="s4z-actions">
        <?php echo zen_draw_form('s4zSample', FILENAME_STAMPS4ZENCART, 'action=sample', 'post'); ?><button type="submit" class="btn btn-default"><?php echo S4Z_BUTTON_SAMPLE; ?></button></form>
        <?php echo zen_draw_form('s4zDisconnect', FILENAME_STAMPS4ZENCART, 'action=disconnect', 'post', 'onsubmit="return confirm(' . s4z_js(S4Z_CONFIRM_DISCONNECT) . ');"'); ?><button type="submit" class="btn btn-default"><?php echo S4Z_BUTTON_DISCONNECT; ?></button></form>
        <a class="btn btn-default" href="<?php echo $s4zSettingsUrl; ?>"><?php echo S4Z_BUTTON_SETTINGS; ?></a>
      </div>
<?php } else { ?>
      <p><?php echo S4Z_TEXT_NOT_CONNECTED; ?></p>
      <div class="s4z-actions">
        <?php echo zen_draw_form('s4zConnect', FILENAME_STAMPS4ZENCART, 'action=connect', 'post'); ?><button type="submit" class="btn btn-primary"><?php echo S4Z_BUTTON_CONNECT; ?></button></form>
        <a class="btn btn-default" href="<?php echo $s4zSettingsUrl; ?>"><?php echo S4Z_BUTTON_SETTINGS; ?></a>
      </div>
<?php } ?>
    </div>
  </div>
  <div class="col-md-6">
    <div class="s4z-box">
      <h2><?php echo S4Z_HEADING_HOW; ?></h2>
      <p><?php echo S4Z_TEXT_HOW; ?></p>
    </div>
  </div>
</div>

<div class="s4z-box">
  <h2><?php echo S4Z_HEADING_RECENT; ?></h2>
<?php if (empty($s4zOpenLabels)) { ?>
  <p><?php echo S4Z_TEXT_NO_RECENT; ?></p>
<?php } else { ?>
  <?php echo zen_draw_form('s4zScan', FILENAME_STAMPS4ZENCART, 'action=scan_form', 'post'); ?>
  <table class="s4z-table">
    <thead><tr><th><?php echo S4Z_COL_SCAN; ?></th><th><?php echo S4Z_COL_ORDER; ?></th><th><?php echo S4Z_COL_DATE; ?></th><th><?php echo S4Z_COL_SERVICE; ?></th><th><?php echo S4Z_COL_TRACKING; ?></th><th><?php echo S4Z_COL_COST; ?></th><th><?php echo S4Z_COL_PICKUP_SCAN; ?></th></tr></thead>
    <tbody>
<?php foreach ($s4zOpenLabels as $l) { $canScan = ($l['manifest_id'] === ''); ?>
      <tr>
        <td><?php if ($canScan) { ?><input type="checkbox" name="label[]" value="<?php echo (int)$l['s4z_label_id']; ?>"<?php echo substr($l['created_at'], 0, 10) === date('Y-m-d') ? ' checked' : ''; ?> aria-label="<?php echo s4z_h(sprintf(S4Z_TEXT_INCLUDE_LABEL, $l['tracking_number'])); ?>"><?php } ?></td>
        <td><a href="<?php echo zen_href_link(FILENAME_STAMPS4ZENCART, 'action=order&oID=' . (int)$l['orders_id'], 'SSL'); ?>"><?php echo (int)$l['orders_id']; ?></a></td>
        <td><?php echo s4z_h(zen_datetime_short($l['created_at'])); ?></td>
        <td><?php echo s4z_h(s4z_service_name($l['service_type'])); ?></td>
        <td><?php echo s4z_h($l['tracking_number']); ?></td>
        <td><?php echo s4z_h(s4z_money($l['cost'])); ?></td>
        <td><?php if ($l['pickup_id'] !== '') { echo s4z_h(S4Z_TEXT_IN_PICKUP); } if ($l['pickup_id'] !== '' && $l['manifest_id'] !== '') { echo '<br>'; } if ($l['manifest_id'] !== '') { echo s4z_h(S4Z_TEXT_ON_SCAN); } ?></td>
      </tr>
<?php } ?>
    </tbody>
  </table>
  <div class="s4z-actions">
    <button type="submit" class="btn btn-primary"><?php echo S4Z_BUTTON_SCAN_FORM; ?></button>
    <a class="btn btn-default" href="<?php echo zen_href_link(FILENAME_STAMPS4ZENCART, 'action=pickup', 'SSL'); ?>"><?php echo S4Z_BUTTON_PICKUP; ?></a>
  </div>
  </form>
<?php } ?>
</div>

<?php
$s4zPickups = s4z_rows("SELECT * FROM " . TABLE_S4Z_PICKUPS . " WHERE environment = $s4zEnvSql ORDER BY s4z_pickup_id DESC LIMIT 10");
$s4zScans = s4z_rows("SELECT * FROM " . TABLE_S4Z_MANIFESTS . " WHERE environment = $s4zEnvSql ORDER BY s4z_manifest_id DESC LIMIT 10");
?>
<div class="row">
  <div class="col-md-6">
    <div class="s4z-box">
      <h2><?php echo S4Z_HEADING_PICKUPS; ?></h2>
<?php if (empty($s4zPickups)) { ?><p><?php echo S4Z_TEXT_NO_PICKUPS; ?></p><?php } else { ?>
      <table class="s4z-table"><thead><tr><th><?php echo S4Z_COL_WINDOW; ?></th><th><?php echo S4Z_COL_ITEMS; ?></th><th><?php echo S4Z_COL_STATUS; ?></th><th></th></tr></thead><tbody>
<?php foreach ($s4zPickups as $p) { ?>
        <tr><td><?php echo s4z_h(s4z_pickup_window($p['window_start'], $p['window_end'])); ?></td>
            <td><?php echo (int)$p['items']; ?></td>
            <td><?php echo s4z_h($p['status'] === 'cancelled' ? S4Z_STATUS_CANCELLED : S4Z_STATUS_SCHEDULED); ?></td>
            <td><?php if ($p['status'] === 'scheduled') { echo zen_draw_form('s4zPc' . (int)$p['s4z_pickup_id'], FILENAME_STAMPS4ZENCART, 'action=pickup_cancel&id=' . (int)$p['s4z_pickup_id'], 'post', 'onsubmit="return confirm(' . s4z_js(S4Z_CONFIRM_CANCEL_PICKUP) . ');"'); ?><button type="submit" class="btn btn-default btn-sm"><?php echo S4Z_BUTTON_CANCEL_PICKUP; ?></button></form><?php } ?></td></tr>
<?php } ?>
      </tbody></table>
<?php } ?>
    </div>
  </div>
  <div class="col-md-6">
    <div class="s4z-box">
      <h2><?php echo S4Z_HEADING_SCANS; ?></h2>
<?php if (empty($s4zScans)) { ?><p><?php echo S4Z_TEXT_NO_SCANS; ?></p><?php } else { ?>
      <table class="s4z-table"><thead><tr><th><?php echo S4Z_COL_DATE; ?></th><th><?php echo S4Z_COL_ITEMS; ?></th><th></th></tr></thead><tbody>
<?php foreach ($s4zScans as $m) { ?>
        <tr><td><?php echo s4z_h(zen_datetime_short($m['created_at'])); ?></td><td><?php echo (int)$m['items']; ?></td>
            <td><?php if (!empty($m['document_url']) && preg_match('~^https://~', $m['document_url'])) { ?><a class="btn btn-default btn-sm" target="_blank" rel="noopener noreferrer" href="<?php echo s4z_h($m['document_url']); ?>"><?php echo S4Z_BUTTON_OPEN_SCAN; ?></a><?php } ?></td></tr>
<?php } ?>
      </tbody></table>
<?php } ?>
    </div>
  </div>
</div>
<?php } ?>
</div>
<?php require DIR_WS_INCLUDES . 'footer.php'; ?>
</body>
</html>
<?php require DIR_WS_INCLUDES . 'application_bottom.php'; ?>
