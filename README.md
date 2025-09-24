# 7 Cats – WooCommerce to Mautic Plugin

Capture WooCommerce order events and send enriched customer data to Mautic. Optionally notify customers on WhatsApp via Zender, so they don’t rely on email alone.

## What it does in one line
Creates or updates a Mautic contact on every new order and on order status changes, filling a complete set of custom fields that you can create with one click in Settings. Optionally, it can also send the order through a WhatsApp channel powered by Zender.

## Key Features

### Contact Sync to Mautic
- Creates the contact if it doesn’t exist; updates it if it does.
- Keeps the contact profile fresh on every order and on any order status change.

### One-Click Custom Fields in Mautic
- From the plugin Settings, create all required Mautic fields with a single click.
- No manual field setup required.

### Rich Data Pushed to Mautic
- Customer email, first name, last name, phone
- Last order ID
- Last purchase date and time
- Last order amount and currency
- Historic purchase amount (lifetime revenue)
- Historic purchase count (lifetime orders)
- Last order categories (comma-separated)
- Last order items as a serialized list (product name, product URL, image URL, quantity, price)
- Order status (e.g., pending, processing, completed, cancelled)

### WhatsApp Notifications via Zender (Optional)
- Send order notifications to the customer over WhatsApp using your Zender server.
- Complements email with a channel customers actually read.

### File Channel (Optional)
- Persist each order payload to protected files in uploads for auditing or external integrations.

## How It Works
1. Hooks into the WooCommerce order lifecycle for new orders and status transitions.
2. Builds a normalized payload and upserts the Mautic contact using your API token.
3. Generates the recommended custom fields in Mautic from a single action in Settings.
4. Optionally delivers the same payload to Zender (WhatsApp) and to a local file channel.

## Requirements
- WordPress with WooCommerce
- PHP 8.1 or newer
- Mautic (v5 or v6) with API access token (Bearer)
- Zender server if you enable the WhatsApp channel

## Installation
1. Place the plugin folder inside `wp-content/plugins`.
2. Activate “7C WooCommerce Orders to WhatsApp” in the WordPress admin.
3. Open `Settings → Orders to WhatsApp`.

## Configuration

### Mautic Channel
- Set your Mautic Base URL.
- Provide a Bearer token with write permissions.
- Use the *Create Mautic custom fields* button to generate all required fields.
- Enable the Mautic channel to start syncing on order events.

### WhatsApp via Zender (Optional)
- Provide the Zender Base URL and API key, and select device or route if needed.
- Enable the Zender channel to send customer WhatsApp notifications on order events.

### File Channel (Optional)
- Enable to write each order payload to a protected uploads subfolder for debugging or hand-off to other systems.

## Data Dictionary (Recommended Mautic Fields)
| Field Key                | Type     | Description                                    |
|--------------------------|----------|------------------------------------------------|
| email                    | Email    | Customer email (used for contact upsert)       |
| firstname                | Text     | First name                                     |
| lastname                 | Text     | Last name                                      |
| phone                    | Text     | Phone number                                   |
| last_order_id            | Number   | Last WooCommerce order ID                      |
| last_purchase_date       | Datetime | Timestamp of the last order                    |
| last_order_amount        | Number   | Monetary amount of the last order              |
| last_order_currency      | Text     | Currency code                                  |
| historic_purch_amount    | Number   | Lifetime revenue (sum of paid orders)          |
| historic_purch_count     | Number   | Lifetime order count                           |
| last_order_categories    | Text     | Comma-separated category names from the last order |
| last_order_items_json    | Text     | Serialized list of items and attributes for the last order |
| order_status             | Text     | Current order status (processing, completed, etc.) |

These fields are generated for you by the *Create fields* button in Settings.

## Privacy and Compliance
- Sync and message only contacts for whom you have valid consent under GDPR, CCPA, and local regulations.
- For WhatsApp messaging, ensure content type and timing comply with WhatsApp Business rules and your jurisdiction.
- The file channel writes to a protected uploads subfolder intended for debugging and integrations.

## Troubleshooting
- Enable WordPress debugging and check the error log for entries from this plugin.
- Verify your Mautic Base URL and Bearer token if you see authorization errors.
- If WhatsApp via Zender fails, confirm Base URL, API key, and device or route status.

## Roadmap
- Per-status message templates for WhatsApp
- Deeper consent mapping from WooCommerce to Mautic
- Admin logs UI with resend actions
- WordPress.org `readme.txt` for public distribution

## Contributing
Issues and PRs are welcome. By contributing, you agree your changes are licensed under Apache-2.0. Please don’t commit secrets or live tokens.

## License
Apache License 2.0 — see `LICENSE`.  
(Attribution travels via `NOTICE` if present.)

**Note**: Ensure your `LICENSE` file content matches this license selection so the repository and documentation stay consistent.

## Credits
Built by Renato Carabelli – 7 Cats Studio Corp.
https://www.7catstudio.com / info@7catstudio.com
