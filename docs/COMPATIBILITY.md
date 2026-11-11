# Compatibility

| | Supported |
|---|---|
| Zen Cart | 1.5.8, 2.0, 2.1, 2.2, 3.0.0 |
| PHP | 7.4 through 8.5 (within each Zen Cart release's own PHP range) |
| PHP extensions | curl, json |
| Templates | Any. Stamps4ZenCart is admin-only and changes nothing on the storefront. |

## What It Relies On

Each of these was checked in the source of every supported Zen Cart release:

- **`NOTIFY_ADMIN_ORDERS_EDIT_BUTTONS`**: the Stamps.com Label button on the order page.
- **`NOTIFY_ADMIN_ORDERS_CONTENT_UNDER_PRODUCTS`**: the labels panel under the order's products.
- **`zen_update_orders_history()`**: the history comment and the optional customer email.
- **`admin/includes/extra_datafiles/`**: page and table names, and request-sanitizer rules.
- **Array-format language files** in `extra_definitions/`.

It needs no core file edits and no template overrides.

## Other Plugins

Stamps4ZenCart has its own tables (`s4z_auth`, `s4z_labels`, `s4z_pickups`, `s4z_manifests`).
It writes to core only through `zen_update_orders_history()`, so it doesn't conflict with
order-editing or shipping-module plugins.
