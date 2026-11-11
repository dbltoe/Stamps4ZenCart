# Customizing

Stamps4ZenCart needs no core file edits and no template overrides, and you shouldn't need
to edit its files either. Everything below works from a separate observer class, so an
update of Stamps4ZenCart never overwrites your changes.

## Language Strings

Every word on the Stamps4ZenCart page comes from
`admin/includes/languages/english/extra_definitions/lang.stamps4zencart_strings.php`.
To use other wording, copy the strings you want to change into your own admin language
file; a definition loaded first wins.

## Settings

Admin > Configuration > Stamps4ZenCart holds the environment, ship-from address, label
size, defaults for the Create Label form, and what happens to the order after a label is
bought. See [CONFIGURATION.md](CONFIGURATION.md).

## Calling Stamps.com Yourself

`S4zSera` (in `admin/includes/classes/s4z_classes.php`) uses the store's connection and
refreshes its token as needed. It is already loaded on the Stamps4ZenCart page; any other
admin code can load it from the installed version's folder:

```php
if (!class_exists('S4zSera', false)) {
    global $installedPlugins;
    $s4zVersion = $installedPlugins['Stamps4ZenCart']['version'] ?? '';
    require_once DIR_FS_CATALOG . 'zc_plugins/Stamps4ZenCart/' . $s4zVersion . '/admin/includes/classes/s4z_classes.php';
}
$sera = new S4zSera();                  // the environment chosen in Configuration
if ($sera->isConnected()) {
    $balance = $sera->balance();
    $rates = $sera->rates($request);    // SERA's own request shape
    // or any SERA endpoint: $sera->call('GET', '/sera/v1/...', $query, $body);
}
```

It throws `S4zError` with a message ready to show the admin; `$e->reconnect` is true when
the store must reconnect.

## Notifiers

The Create Label page fires these. Attach to them from an admin observer, for example
`admin/includes/classes/observers/auto.my_s4z_addon.php` in your own zc_plugin. They exist
from Stamps4ZenCart 1.0.0 on every supported Zen Cart release.

`$context` is the same in every Create Label notifier:

| Key | Value |
|---|---|
| `oID` | The order number. |
| `order` | The order's ship-to address in SERA's shape, plus `country_name` and `order_date`. |
| `form` | The Create Label form's values (address, weight, dimensions, package, confirmation, ship date). |
| `step` | `''` (first view), `validate`, `use_matched`, `rates`, `buy` or `track`. |

### NOTIFY_S4Z_CREATE_LABEL_ALLOWED

`(['oID' => $oID, 'order' => $order], &$allowed)`

`$allowed` starts as true for U.S. addresses only. Set it to true for an order your add-on
handles (an international order you add customs details to, say) and the Create Label form
appears for it. The address sent to Stamps.com then carries the order's own country code.

### NOTIFY_S4Z_CREATE_LABEL_FORM

`($context, &$extraFields)`

Push HTML strings onto `$extraFields`; each one is shown inside the Create Label form, below
the address and package fields, in a `<div class="s4z-extra">`. Escape your own output. Your
fields are posted with the form on every step, so read them back from `$_POST` and show
the admin's values again. Register request-sanitizer rules for them as you would for any
admin field.

### NOTIFY_S4Z_LABEL_REQUEST

`($context, &$request)`

Fired just before rates are fetched (`$context['step'] === 'rates'`) and again just before
a label is bought (`'buy'`). `$request` is the body sent to Stamps.com's rates or labels
endpoint; change it or add to it (customs, insurance, references). To stop with a message
for the admin, `throw new S4zError('...')`; nothing is sent or bought.

### NOTIFY_S4Z_LABEL_BOUGHT

`($context + ['s4z_label_id', 'environment', 'request', 'label'])`

Fired after a label is bought, saved and noted in the order's history. `label` is Stamps.com's
response without the label image. Read-only. Don't throw here: the label is already paid for.

### NOTIFY_S4Z_LABEL_VOIDED

`(['label' => $row])`

Fired after a label is voided and noted in the order's history. `$row` is its
`s4z_labels` row. Read-only.

## Example

```php
<?php
// admin/includes/classes/observers/auto.my_s4z_addon.php
class zcObserverMyS4zAddon extends base
{
    public function __construct()
    {
        $this->attach($this, ['NOTIFY_S4Z_LABEL_REQUEST']);
    }

    public function updateNotifyS4zLabelRequest(&$class, $eventID, $context, &$request)
    {
        // When buying, print the customer's name as reference 2 too.
        if ($context['step'] === 'buy') {
            $request['references']['reference2'] = substr($context['order']['name'], 0, 40);
        }
    }
}
```
