# 7 Cats – Shopping2Mautic Plugin

Capture order events from WooCommerce or import them from Magento, then send enriched customer data into Mautic. Optionally notify customers on WhatsApp via Zender, and persist data to file for auditing.

## What it does in one line
Keeps your Mautic contacts up to date with the latest order data from WooCommerce or Magento, with optional WhatsApp notifications.

## Key Features

### WooCommerce → Mautic (automatic)
- Hooks directly into WooCommerce order lifecycle.
- Creates or updates Mautic contacts on every new order and order status change.
- Updates rich custom fields (last order, categories, items, totals, lifetime revenue, etc.).
- Fully automatic once enabled.

### Magento → Mautic (manual, for now)
- Connects to Magento REST API with read-only user credentials.
- Step-based import into WordPress local tables:
  - IDs → Seeds local order IDs for processing.
  - Details → Fetches each order’s info (customer, totals, dates).
  - Customers → Loads Magento customers into local table.
  - Items → Loads order line items (products).
  - Categories → Loads product categories.
- Once imported, you can push these orders to Mautic (latest order per customer).
- Currently manual (via admin pages), roadmap includes automation.

### One-Click Custom Fields in Mautic
- From plugin Settings, create all required Mautic fields with one click.
- Fields cover customer data, last order, items, categories, lifetime revenue, etc.

### WhatsApp Notifications via Zender (Optional)
- Send order notifications via your Zender server.
- Complements email with a channel customers actually read.

### File Channel (Optional)
- Persist each order payload to protected files in uploads for auditing or external integrations.

### Rich Data Pushed to Mautic
- Email, first name, last name, phone
- Last order ID, status, amount, currency, timestamp
- Lifetime purchase amount and count
- Last order categories (CSV)
- Last order items (list with name, product URL, image URL, qty, price)

## How It Works

### WooCommerce flow
- Plugin listens to WooCommerce order created/updated events.
- Schedules a push to Mautic (small delay ensures line items are saved).
- Builds payload, remaps to Mautic fields, and upserts the contact.
- Optionally sends via Zender (WhatsApp) and/or writes a file.

### Magento flow
- Configure Magento REST URL + credentials.
- Use the Import Magento page in WordPress to pull data step by step: IDs → Details → Customers → Items → Categories.
- After local tables are filled, go to Step 7: Send Orders to Mautic to push them in batches (latest order per customer).
- Roadmap: automation of this import + sync.

## Requirements
- WordPress with WooCommerce (if using Woo sync).
- Magento REST API access (if using Magento import).
- PHP 8.1+
- Mautic 5 or 6 with API access (Bearer token).
- Zender server (optional, if WhatsApp channel enabled).

## Installation
1. Place the plugin folder inside `wp-content/plugins`.
2. Activate 7C Shopping2Mautic in WordPress admin.
3. You’ll see menu items under Shopping2Mautic for Settings, Magento Import, and Send to Mautic.

## Configuration

### Mautic Channel
- Set Base URL + Bearer token.
- Click *Create fields in Mautic* to auto-create all required fields.
- Enable channel to sync automatically.

### WooCommerce
- No manual steps; auto sync is active when Mautic channel is enabled.

### Magento
- Go to Shopping2Mautic → Magento Import.
- Enter REST URL, user, password.
- Use *Probar conexión* to validate.
- Import each phase manually (IDs, Details, Customers, Items, Categories).
- Finally, go to Step 7: Send Orders to Mautic and run background process.

### WhatsApp (optional)
- Configure Zender Base URL + API key.
- Enable channel to notify customers by WhatsApp.

### File Channel (optional)
- Enable to save every payload in `/uploads/7c-shop2mautic`.

## Data Dictionary (Recommended Mautic Fields)
| Field Key                | Type     | Description                                    |
|--------------------------|----------|------------------------------------------------|
| email                    | Email    | Customer email (used for contact upsert)       |
| firstname                | Text     | First name                                     |
| lastname                 | Text     | Last name                                      |
| phone                    | Text     | Phone number                                   |
| last_order_id            | Number   | Last order ID                                  |
| last_purchase_date       | Datetime | Timestamp of the last order                    |
| last_order_amount        | Number   | Monetary amount of the last order              |
| last_order_currency      | Text     | Currency code                                  |
| historic_purch_amount    | Number   | Lifetime revenue (sum of paid orders)          |
| historic_purch_count     | Number   | Lifetime order count                           |
| last_order_categories    | Text     | Comma-separated category names from the last order |
| last_order_items_json    | Text     | Serialized list of items and attributes for the last order |
| order_status             | Text     | Current order status (processing, completed, etc.) |

These fields are generated by the *Create fields* button in Settings.

## Roadmap
- Automate Magento imports (scheduled tasks).
- Per-status WhatsApp templates.
- Admin log viewer with resend.
- Consent mapping from Woo/Magento → Mautic.

## Privacy & Compliance
- Sync only with consented customers.
- WhatsApp messages must comply with WABA rules.
- File channel is protected under `/uploads`.

## Credits
Built by Renato Carabelli – 7 Cats Studio Corp.  
https://www.7catstudio.com / info@7catstudio.com

## License
Apache License 2.0 — see `LICENSE`.  
(Attribution travels via `NOTICE` if present.)