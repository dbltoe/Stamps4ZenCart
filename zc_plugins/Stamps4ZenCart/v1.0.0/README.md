# Stamps4ZenCart

Version 1.0.0 · Zen Cart 1.5.8 through 3.0.0 · PHP 7.4 through 8.5 · GPL v3 ·
by My Zen Cart Host (dbltoe)

Stamps4ZenCart lets you buy and print USPS shipping labels from your own Stamps.com account
without leaving the Zen Cart admin. Open an order, check the address, get rates, buy the label
and print it. The tracking number goes into the order's history, and the customer can be
emailed automatically.

## What It Does

- **Address check** against USPS records, with a one-click fix when USPS suggests a correction.
- **Rates** for the USPS services available to your account, cheapest first, with your
  Stamps.com balance shown alongside.
- **One-click printing:** Print Label opens the label at its real size and starts your
  browser's print dialog straight away. A Download PDF link is there too. Label files are
  never stored on your server.
- **Void** an unused label; Stamps.com refunds the postage once USPS confirms it wasn't used.
- **Tracking** status and scan history for each label.
- **USPS pickups** for the next business day, and **SCAN forms** for the day's labels.
- **Sample label** to check printer alignment, free of charge.

Version 1.0.0 makes labels for addresses in the United States.

## What You Need

- Zen Cart 1.5.8 through 3.0.0, on PHP 7.4 through 8.5, with the curl extension (standard on
  almost every host).
- A Stamps.com account with postage funds. You don't need a Stamps.com developer account.
- HTTPS on your store's admin, which every live store should have anyway.

## Installing

1. Copy the `zc_plugins/Stamps4ZenCart` folder into your store's `zc_plugins` folder.
2. In the admin, go to **Modules > Plugin Manager**, select **Stamps4ZenCart** and click
   **Install**.
3. Go to **Configuration > Stamps4ZenCart** and fill in your ship-from address. The street
   address, city, state and ZIP code are required; a phone number is required for USPS pickups.

Rather not install it yourself? myZenCartZone can install it for you; contact us for a quote at
https://myzencartzone.com/index.php?main_page=contact_us

Once it's installed, a superuser can open this guide from the **ReadMe** button in Plugin
Manager.

## Connecting to Stamps.com

1. Go to **Customers > Stamps4ZenCart Labels** and click **Connect to Stamps.com**.
2. A myZenCartZone page shows the store being connected. Check it's your store and click
   **Continue to Stamps.com**.
3. Sign in on the Stamps.com page. Your password goes only to Stamps.com.
4. You're returned to your admin, which shows **Connected to Stamps.com** and your balance.

One Stamps.com account can be connected to as many of your stores as you like; connecting one
store doesn't disconnect another. Each store connects once and stays connected. If Stamps.com
ever stops accepting the connection, the admin says so, and you click Connect again.

## Daily Use

### Making a Label

1. Open an order (**Customers > Orders**, then Edit) and click **Stamps.com Label**.
2. The ship-to address is filled in from the order. Click **Validate Address** if you'd like
   USPS to check it.
3. Check the weight; it starts from the products' weights, so add the box and packing. For the
   *Package* type, enter the length, width and height.
4. Click **Get Rates**, pick a service and click **Buy Label**, then **Print Label**.

### Printing

**Print Label** opens a page showing the label at its real size and starts your browser's print
dialog. The first time, choose your label printer (for example a DYMO LabelWriter 4XL or a
Zebra), and set the paper size to 4x6. Leave the margins and scale as they are: the page sets
its own margins so the label fits the area a label printer can reach. Your browser remembers
these, so after that it's Print Label, then Enter. Prefer a file? Use **Download PDF** on the
same page.

The first print uses the image that came with the purchase. After that, each print fetches the
label from Stamps.com again, which Stamps.com allows a limited number of times and only within
one day of buying it. After a day the Print Label button is replaced by a note; if a label is
lost after that, void it (if it's unused) and buy a new one.

The order's history gets a comment with the service and tracking number. If you set **Order
Status After Label**, the order moves to that status, and if **Email Tracking to Customer** is
on, the customer gets the tracking number by email.

### Tracking, Reprinting and Voiding

Every label appears under the order's products and on the Stamps.com Label page, with **Print
Label** (for one day), **Track** and **Void** buttons. A brand-new label shows "No tracking yet"
until USPS scans the package.

### End of Day

On **Customers > Stamps4ZenCart Labels**, the last seven days of labels are listed. Tick the
ones going out and click **Create SCAN Form**: USPS scans that one form instead of each
package. Or click **Schedule a USPS Pickup**. A free pickup needs at least one Priority Mail or
Priority Mail Express label, and USPS doesn't pick up on Sundays or federal holidays.

## Settings

- **Stamps.com Environment:** *Staging* makes test labels that must never be mailed.
  *Production* makes real, paid labels. Each environment has its own connection.
- **Connection Service URL:** the myZenCartZone service that connects your store. Leave it as
  is.
- **Ship From:** the sender name, company and address printed on labels and used for pickups.
- **Default Service, Package Type, Delivery Confirmation:** what the Create Label form starts
  with.
- **Product Weight Unit:** the unit your product weights are entered in.
- **Label Size, Label Format:** 4x6 or letter. The format (PDF or PNG) is what Download PDF
  gives you; Print Label always prints the label as an image at its real size, on any printer,
  thermal label printers included.
- **Order Status After Label:** the status an order moves to when its label is bought. Choose
  *Default* to leave the status alone.
- **Email Tracking to Customer:** email the tracking number when a label is bought.
- **Log Stamps.com Errors:** writes failed requests to `logs/stamps4zencart-YYYYMM.log`. Never
  tokens, keys or addresses.

> **Staging:** while the environment is Staging, labels are test labels only. Never put one on
> a package; destroy any you print. Customers aren't emailed and order statuses aren't changed.

## Privacy and Security

- Your Stamps.com password is entered only on Stamps.com's own sign-in page.
- Stamps4ZenCart is a registered Stamps.com integration owned by myZenCartZone. Its client
  secret lives only on the small myZenCartZone connection service, never in this code or in
  your store, and the service never stores your Stamps.com sign-in. When your store connects,
  the service exchanges Stamps.com's one-time code only for the store that started the request
  (proven with a secret that never leaves your server), hands the sign-in to your store, and
  keeps only a scrambled copy (a hash) of your store's key.
- Your store keeps its connection in its own database table, `s4z_auth`, which is removed when
  you uninstall.
- Label files are never saved on your server. A just-bought label's image is held in your admin
  session for its first print only, then dropped; every reprint fetches the label from
  Stamps.com.
- Rates, labels, tracking, pickups and SCAN forms go directly between your store and
  Stamps.com. myZenCartZone sees only the connect step and the sign-in refresh about every 15
  minutes while you work.

## For Developers

Other plugins can extend the Create Label page without editing Stamps4ZenCart. It fires five
notifiers:

- `NOTIFY_S4Z_CREATE_LABEL_ALLOWED`: let an order outside the U.S. through.
- `NOTIFY_S4Z_CREATE_LABEL_FORM`: add fields to the form.
- `NOTIFY_S4Z_LABEL_REQUEST`: change what is sent to Stamps.com before rates and before buying.
- `NOTIFY_S4Z_LABEL_BOUGHT` and `NOTIFY_S4Z_LABEL_VOIDED`: react to a bought or voided label.

The `S4zSera` class can also make any Stamps.com call with the store's connection. Arguments
and an example are in `docs/CUSTOMIZING.md` in the download.

## Uninstalling

In Plugin Manager, click **Uninstall**. The settings, the admin pages and the Stamps.com
connection are removed. The label, pickup and SCAN form records are kept as shipping history,
so a reinstall picks them up. Order history comments are part of your orders and stay.

## Troubleshooting

- **"Fill in the ship-from address in Settings first":** complete Configuration >
  Stamps4ZenCart.
- **"Stamps.com no longer accepts this store's sign-in":** click Connect to Stamps.com again.
- **"Stamps.com reprints a USPS label only within 1 day":** the label is more than a day old.
  If it's unused and lost, void it and buy a new one.
- **The print starts partway down a label and runs onto the next:** the label roll isn't lined
  up with the print head. Press the feed button on the front of the printer once; it moves the
  roll to the start of the next label. If that doesn't line it up, take the roll out and load
  it again, and the printer finds the first label itself.
- **The top or bottom of the label is cut off:** in the print dialog, leave the margins on
  Default (not None). Label printers can't reach the very edge of the label.
- **The label prints small, shifted or on two labels:** in the print dialog, choose the label
  printer and paper size 4x6, leave the margins on Default and the scale at 100%, and turn off
  headers and footers.
- **"Stamps.com is still recording this label, so it can't be voided yet":** Stamps.com needs a
  moment after a purchase before it can void the label. Wait a minute or two and click Void
  again.
- **"Stamps.com allows only a few prints of each label":** the first Print Label after buying
  uses the image that came with the purchase. After that, each Print Label and Download PDF
  fetches the label from Stamps.com again, and Stamps.com limits how often that can happen. To
  print another copy, use Print Again on the print page that's already open. For printer setup,
  use the free Sample Label instead of a real label.
- **"Enter the length, width and height":** Stamps.com needs dimensions for the Package type.
  Flat rate boxes and envelopes don't.
- **"Stamps.com said: ...":** Stamps.com's own message; usually an address or package detail
  to fix.
- **"The connection service couldn't be reached":** try again in a minute. If it keeps
  happening, ask in the support thread.

## Changelog

See `CHANGELOG.md`. Version 1.0.0 is the first release, with notifiers for add-ons (see For
Developers).

## License

Stamps4ZenCart is free software under the GNU General Public License, version 3
(https://www.gnu.org/licenses/gpl-3.0.html); the full text is in `LICENSE`. Copyright © 2026
My Zen Cart Host (dbltoe). Stamps.com is a trademark of its owner; this plugin is an
independent integration that uses the Stamps.com API.
