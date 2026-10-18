=== WiliWeb Cashback ===
Contributors: wiliweb
Tags: woocommerce, cashback, wallet
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 0.1.7
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Persian WooCommerce cashback and expiring store credit, with a separate IPPanel SMS outbox.

== Description ==
Default 5% cashback, 30-day independent grants, rounding up to 500 toman and 1,500,000 toman redemption minimum. Every value is configurable. Wallet-funded merchandise earns no cashback. Native WordPress settings, Users wallet column/manual audited adjustments, account history, checkout controls and WooCommerce order notes are included.

Each independent site owns its settings, database tables and SMS credentials. Network activation is intentionally unavailable. This release supports IRT (toman), integer prices and tax-disabled stores. Shipping is not part of the cashback base. Guest orders do not earn wallet credit; customers need an account.

Uses WooCommerce CRUD for orders and InnoDB transactions with per-user row locks for the wallet. Failed/cancelled pending payments release reservations. Late callbacks after a released reservation require manual order reconciliation. A refund (including a partial refund) voids all cashback earned by that order; wallet redemption is restored for returned item quantities with original expiry. Monetary refunds with zero quantity restore the proportional wallet share of the refunded cash. Amount-only refunds without lines use the order merchandise cash base. Cumulative per-item returns are capped.

Data and settings remain when the plugin is removed, to preserve financial history. Deactivation/removal stops the plugin's scheduled jobs. Reinstalling resumes the same ledger.

== Installation ==
1. Activate WooCommerce and use IRT/toman currency with zero decimal places and taxes disabled.
2. Upload the ZIP under Plugins > Add New > Upload Plugin and activate.
3. Open WooCommerce > کش‌بک و کیف پول. Review the rules and save.
4. Optional: configure and enable IPPanel SMS. The plugin uses https://edge.ippanel.com/v1/api/send and transmits recipient phone, configured message variables/text and the API key only when SMS is enabled and due credit jobs exist. Provider terms: https://ippanel.com/
5. Users > All Users includes the wallet credit column; click a balance to view the ledger and adjust credit with a reason.
6. Keep WordPress cron/Action Scheduler running. Production sites should run WordPress cron regularly. No real SMS delivery or live payment gateway is certified by local tests.

== Compatibility ==
Locally tested: WordPress 7.1, WooCommerce 11.1.0, PHP 8.1/8.2, MariaDB 11.4.5; HPOS and legacy order storage without synchronization during tests. Earlier WordPress/WooCommerce versions are not runtime-verified.
Classic checkout and block Store API full-wallet checkout tested. ParsKala 4.1.3 menu/renderer contracts inspected/tested; full theme/AJAX testing requires a licensed staging environment with ionCube. No theme or licensed font is bundled.
Font inherits the site/admin panel; ParsKala frontend uses its --prk-font-family variable. Optional family name must refer to a font already loaded by the site. PHP Intl enables Persian calendar dates; otherwise dates are explicitly labelled Gregorian.
Shortcodes: [wwcb_wallet], [wwcb_cashback]. Account endpoint: wwcb-wallet.

== Changelog ==
= 0.1.7 =
Center the wallet popup explicitly despite global theme resets; constrain height and width on mobile.

= 0.1.6 =
Public GitHub Releases updater integrated with native WordPress plugin updates.

= 0.1.5 =
Add a user search form to the Users wallet menu, with links to each selected user ledger and credit adjustment form.

= 0.1.4 =
Show active payment reservations separately from usable balance, explain the automatic release window, and expose reservation expiry in the wallet response. Existing failed/cancelled hooks and the scheduled processor release reservations; ParsKala account menu and the wallet shortcode remain available.

= 0.1.3 =
Compact cart/checkout credit strip with inline wallet controls, collapsible terms and responsive guest/empty/applied states. Existing calculations and payment handling are unchanged.

= 0.1.2 =
Show cashback estimates to guests and signed-in customers outside the legacy cart/payment regions hidden by ParsKala. Support theme shortcodes and refresh quotes on classic, block and ParsKala cart updates. Wallet details and spending remain authenticated.

= 0.1.1 =
Redesigned RTL settings with four sections, contextual tutorials, IPPanel variable mapping builder, pattern examples, text previews, reminder timeline and inline validation. Existing financial rules and settings are retained.

= 0.1.0 =
Initial installable release.


