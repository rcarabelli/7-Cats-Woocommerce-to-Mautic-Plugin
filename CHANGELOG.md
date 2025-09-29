# Changelog

All notable changes to this project will be documented in this file.

## [Unreleased]
- Automatic import for Magento (currently manual, future roadmap).
- Admin UI improvements and logs.

## [2025-09-28] Major rewrite and rename
- **Renamed plugin** from `7c-wc-orders2whatsapp` to `7c-shop2mautic`.
- **Full refactor**: code reorganized, simplified, and standardized in English for easier maintenance and future upgrades.
- **WooCommerce → Mautic**
  - Fixed bug where **auto-sync** missed product list and category list fields (manual backfill worked fine, auto didn’t).
  - Now both manual and automatic sync push the full enriched dataset (products, categories, totals, lifetime stats).
- **Magento → Mautic**
  - Added support for Magento orders via a new import bridge.
  - Current flow is **manual**: import orders/customers/items/categories step by step, then push to Mautic.
  - Roadmap: future versions will automate the Magento import process.
- **General improvements**
  - Unified language across code and UI (English).
  - Error handling improved.
  - Codebase is cleaner and ready for new features.

## [2025-09-27] Legacy snapshot
- Previous plugin preserved in branch `legacy-main` and tag `v1-legacy`.
- Codebase included WooCommerce-only sync with partial fields on auto events.
