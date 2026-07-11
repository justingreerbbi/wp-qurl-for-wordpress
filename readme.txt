=== WP qURL ===
Contributors: justingreer
Tags: qurl, layerv, security, access control, secure links
Requires at least: 6.3
Tested up to: 7.0
Requires PHP: 8.0
Stable tag: 1.2.3
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Create and manage LayerV qURL secure access links from the WordPress admin.

== Description ==

WP qURL integrates [LayerV](https://layerv.ai/) qURL secure access links into your WordPress site. Authors and administrators can create time-limited, policy-bound portal links to protected URLs directly from the WordPress admin area.

**qURL** protects resources behind cryptographic, just-in-time access — ideal for sharing internal dashboards, staging environments, contractor access, and sensitive documents.

= Features =

* **Page protection** — Unlock protected pages via LayerV `qurl.accessed` IP/UA grants, with live session fallback
* **qURL Access Link block** — Gutenberg block for logged-in users or visitors to mint one-time qURL links
* **Public REST API** — Secure frontend minting via `/wp-json/wp-qurl/v1/mint` (no admin functions)
* **Create qURLs** — Protect any HTTPS URL with configurable expiration, session duration, and one-time use
* **Dashboard** — View protected URLs with status filters
* **Portal link storage** — Locally stores ephemeral portal links (the API returns them only once)
* **Access log** — View active sessions via the LayerV API and log access events via webhooks
* **Role-based access** — Administrators manage all qURLs; authors can create and view their own
* **Encrypted API key** — API credentials stored encrypted using WordPress salts
* **Webhook management** — Register and manage LayerV webhooks from the admin
* **Troubleshooting tools** — Optional administrator-only blocked-page diagnostics

= Requirements =

* A [LayerV](https://layerv.ai/) account with an API key (`qurl:read` and `qurl:write` scopes)
* WordPress 6.3+
* PHP 8.0+ with the OpenSSL extension

= API Documentation =

This plugin uses the [LayerV qURL Service API](https://layerv.ai/docs/). Production API base: `https://api.layerv.ai`

== Installation ==

1. Upload the `wp-qurl` folder to `/wp-content/plugins/`
2. Activate the plugin through the **Plugins** menu in WordPress
3. Go to **qURLs → Settings** and enter your LayerV API key
4. Click **Test Connection** to verify credentials
5. Create your first qURL under **qURLs → Create qURL**

= Webhook Setup =

1. Go to **qURLs → Webhooks**
2. Select the events you want (qurl.accessed, qurl.access_denied, etc.)
3. Click **Register Webhook with LayerV**
4. Use a qURL portal link and check **qURLs → Access Log** for events

Your site must use HTTPS and expose the WordPress REST API to the internet.
A LayerV webhook with `qurl.accessed` is recommended for protected WordPress pages. Without it, WordPress can still unlock via live LayerV sessions.

== Frequently Asked Questions ==

= Why are portal links stored locally? =

The LayerV API returns `qurl_link` only once at creation time. It cannot be recovered later. WP qURL encrypts and stores links so you can copy them again from the dashboard.

= Who can create qURLs? =

By default: Administrators (full access), Editors and Authors (create access). Administrators can revoke resources; authors can create and manage their own tokens.

= Does this work with private/local URLs? =

For URLs not directly routable on the internet, use the [qURL Connector](https://layerv.ai/docs/connectors/) alongside this plugin.

= How do I protect a WordPress page? =

1. Register the LayerV webhook under **qURLs → Webhooks** (recommended)
2. Edit the page, enable **Protect this page with qURL**, and save
3. Use **Create qURL for this page** or the qURL Access Link block
4. Share the portal link — LayerV sends the visitor to the page; WordPress unlocks by matching IP and browser

Editors and administrators can still preview protected pages while logged in.

= How do I add self-service qURL links to a page? =

1. Edit a page or post in the block editor
2. Add the **qURL Access Link** block (search for "qURL")
3. Set the **Endpoint** to the protected path or URL (e.g. `/members-only/`)
4. Publish — logged-in users with the `mint_qurl_self` capability can generate a personal one-time link

Configure block defaults and allowed roles under **qURLs → Settings → Frontend Block**.

= Why does a valid qURL link show Access Denied on a protected page? =

Open the qURL portal link first so LayerV records the access, then land on the protected page from the same browser and network. WordPress matches the visitor IP and user agent from the `qurl.accessed` webhook (or live LayerV sessions as a fallback).

For diagnosis, enable **Debug Mode** under **qURLs → Settings → Troubleshooting**. Diagnostics are shown only to signed-in qURL administrators.

== Developer Hooks ==

* `wp_qurl_mint_args` — filter mint arguments before API call
* `wp_qurl_pre_mint` — short-circuit minting
* `wp_qurl_minted` — action after successful mint
* `wp_qurl_user_can_mint_self` — filter frontend mint permission
* `wp_qurl_resolve_endpoint` / `wp_qurl_is_endpoint_allowed` — endpoint validation
* `wp_qurl_trust_proxy_headers` — override automatic reverse-proxy IP detection
* `wp_qurl_client_ip` — filter detected visitor IP used for unlock matching
* `wp_qurl_block_debug_context` — filter the blocked-page debug payload when debug mode is enabled

== Screenshots ==

1. Dashboard listing protected URLs and stored portal links
2. Create qURL form with expiration and session options
3. Resource detail with qURL tokens and active sessions
4. Settings page with API configuration

== Changelog ==

= 1.2.3 =
* Initial release
* Create, list, and revoke qURLs via the LayerV API
* Protect WordPress pages with webhook IP/UA grants and live session fallback
* qURL Access Link Gutenberg block and public mint REST API
* Dashboard with status filters; quota display on Settings
* Webhook management, access log, encrypted credentials, and role-based capabilities
* Optional administrator-only blocked-page diagnostics

== Upgrade Notice ==

= 1.2.3 =
Initial release of WP qURL.
