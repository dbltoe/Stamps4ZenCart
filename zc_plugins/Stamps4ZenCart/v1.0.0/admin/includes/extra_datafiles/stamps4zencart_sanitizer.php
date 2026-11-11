<?php
/**
 * Stamps4ZenCart -- request sanitizing rules for the plugin's own admin page.
 *
 * Zen Cart's AdminRequestSanitizer strictly sanitizes every field nobody
 * registered, which mangles addresses ("O'Brien & Sons") and could strip
 * characters from the Stamps.com authorization code. Loaded from
 * extra_datafiles, i.e. after the sanitizer class exists and before it runs,
 * on every release from v1.5.8 to v3.0.0. Scoped to this page only.
 *
 * @package  Stamps4ZenCart
 * @license  https://www.gnu.org/licenses/gpl-3.0.html GNU General Public License v3.0
 */

if (!class_exists('AdminRequestSanitizer')) {
    return;
}

$s4zPages = ['stamps4zencart'];
$s4zRules = [];

// Identifiers coming back from the token service and Stamps.com.
foreach (['code', 'pid', 'state', 's4z_error', 'action', 'env'] as $s4zField) {
    $s4zRules[$s4zField] = ['sanitizerType' => 'ALPHANUM_DASH_UNDERSCORE', 'method' => 'get', 'pages' => $s4zPages];
}

// Free-text address and note fields: the same rule core uses for names.
foreach (['to_name', 'to_company', 'to_address1', 'to_address2', 'to_city', 'to_state', 'to_postcode',
          'to_phone', 'to_email', 'pickup_instructions'] as $s4zField) {
    $s4zRules[$s4zField] = ['sanitizerType' => 'WORDS_AND_SYMBOLS_REGEX', 'method' => 'post', 'pages' => $s4zPages];
}

// Numbers.
foreach (['weight', 'length', 'width', 'height'] as $s4zField) {
    $s4zRules[$s4zField] = ['sanitizerType' => 'FLOAT_VALUE_REGEX', 'method' => 'post', 'pages' => $s4zPages];
}

AdminRequestSanitizer::getInstance()->addComplexSanitization($s4zRules);
unset($s4zPages, $s4zRules, $s4zField);
