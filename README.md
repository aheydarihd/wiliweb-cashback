# WiliWeb Cashback

Persian WooCommerce cashback and expiring wallet credit, by [WiliWeb](https://wiliweb.com).

Features include configurable cashback, independent credit expiry, wallet application at checkout, customer history, audited administrator adjustments, and optional IPPanel reminders. Supports IRT (toman), integer prices and stores with taxes disabled. Wallet-funded purchases earn cashback only on the remaining paid merchandise amount.

## Install

Download `wiliweb-cashback-VERSION.zip` from [Releases](https://github.com/aheydarihd/wiliweb-cashback/releases/latest), then upload it in WordPress → Plugins → Add New. For an existing installation, choose replacement. Settings and wallet history are retained.

From version 0.1.6 onward, the plugin checks public GitHub Releases for updates through WordPress's native plugin update system. It sends an unauthenticated request for public release metadata; no wallet records or SMS credentials are sent. Release metadata is cached for one hour. WordPress's normal update check schedule also applies. A code push alone does not publish an update.

## Release

Update the version in the plugin header, `WWCB_VERSION`, and `readme.txt`. Commit, push, then create and push a matching tag such as `v0.1.7`. The GitHub Actions workflow creates an installable ZIP with a stable `wiliweb-cashback/` root, checksum, and GitHub Release. Only stable semantic versions are accepted by the updater.

## Account and theme integration

Customer account endpoint: `wwcb-wallet`. Wallet shortcode: `[wwcb_wallet]`. Product cashback hint: `[wwcb_cashback]`. ParsKala integration supports the account v4 menu and known cart/checkout shortcode contracts. Full licensed theme and live gateway testing should be performed on the target site.

## Requirements and license

PHP 8.1+, WordPress 6.5+, WooCommerce, InnoDB. See the bundled plugin readme for financial behavior and integration limits. GPL-2.0-or-later. No theme files, commercial fonts, test databases or credentials are distributed.
