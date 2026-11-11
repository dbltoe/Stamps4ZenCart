# Installing Stamps4ZenCart

1. **Copy the files:** put `zc_plugins/Stamps4ZenCart/` in your store's `zc_plugins/`
   folder. No core files are changed.
2. **Install:** go to **Modules > Plugin Manager**, select **Stamps4ZenCart** and click
   **Install**. This creates four tables and the settings, and adds
   **Configuration > Stamps4ZenCart** and **Customers > Stamps4ZenCart Labels**.
3. **Ship-from address:** under **Configuration > Stamps4ZenCart**, fill in:
   - **Required:** street address, city, two-letter state and ZIP code.
   - **For USPS pickups:** a phone number.
   - The company name starts as your store name.
4. **Connect:**
   1. On **Customers > Stamps4ZenCart Labels**, click **Connect to Stamps.com**.
   2. Check that the store shown is yours, then click **Continue to Stamps.com**.
   3. Sign in with your Stamps.com account.
5. **Print a sample label** from the same page to check your printer's alignment. It's free.

## Upgrading

Copy the new version folder beside the old one, then click **Upgrade** in Plugin Manager.
Settings, the connection and all records are kept.

## Uninstalling

Click **Uninstall** in Plugin Manager.

- **Removed:** the settings, the admin pages and the connection.
- **Kept, as shipping history:** label, pickup and SCAN form records, and order history
  comments.
