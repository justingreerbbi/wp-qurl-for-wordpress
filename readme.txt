=== WP qURL ===
Contributors: justingreer
Tags: qurl, layerv, security, access control, secure links
Requires at least: 6.3
Tested up to: 7.0
Requires PHP: 8.0
Stable tag: 2.2.0
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
* **Dashboard** — View all protected URLs and their associated qURL tokens
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
4. Share the portal link — LayerV sends the visitor to the real page; WordPress unlocks by matching IP and browser

Editors and administrators can still preview protected pages while logged in.

= How do I add self-service qURL links to a page? =

1. Edit a page or post in the block editor
2. Add the **qURL Access Link** block (search for "qURL")
3. Set the **Endpoint** to the protected path or URL (e.g. `/members-only/`)
4. Publish — logged-in users with the `mint_qurl_self` capability can generate a personal one-time link

Configure block defaults and allowed roles under **qURLs → Settings → Frontend Block**.

= Why does a valid qURL link show Access Denied on a protected page? =

Open the qURL portal link first so LayerV records the access, then land on the protected page from the same browser and network. WordPress matches the visitor IP and user agent from the `qurl.accessed` webhook (or live LayerV sessions as a fallback).

After upgrading from the handoff flow, remint protected-page links so LayerV targets the real page URL.

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

= 2.2.0 =
* Simplify protected-page unlock: LayerV targets the real page URL again
* Unlock via webhook IP/UA grants, with live LayerV session fallback
* Soft-retire browser handoff claim flow; legacy `?wp_qurl_handoff=` links redirect to the page
* Store page grants with page/resource IDs for direct matching

= 2.1.0 =
* Align create requests and stored responses with the LayerV qURL API schema
* Support LayerV reverse-proxy callback delivery before first-party WordPress cookie issuance
* Validate documented webhook headers and payload fields, with deterministic delivery deduplication
* Remove reliance on undocumented webhook session IDs and expiry fields
* Harden exact qURL-to-handoff correlation, recover failed cookie claims, and revoke exhausted grants
* Add daily cleanup, schema migration, administrator-only diagnostics, and contract-focused tests

= 2.0.0 =
* Remove legacy direct-target page access (IP/session matching, global access-grant cookies, manual page resource IDs)
* Protected pages now require the webhook-confirmed browser handoff flow exclusively
* Remove Trust Proxy IP Headers setting; reverse-proxy IP detection remains automatic for optional handoff IP binding
* Simplify blocked-page messages and debug output around handoffs and page grants

= 1.4.2 =
* Auto-detect reverse-proxy headers (DreamHost, nginx, etc.) so visitor IP matches LayerV sessions without manual settings
* Grant access after qURL portal redirects when a recent qurl.accessed webhook exists for the page resources
* Debug panel now reports auto-detected proxy trust and qURL portal referer checks

= 1.4.1 =
* Add optional blocked-page debug mode with LayerV session, request header, cookie, and access-check diagnostics
* Add Trust Proxy IP Headers setting for sites behind load balancers and reverse proxies
* Fix protected-page access denied when LayerV session IP differs from REMOTE_ADDR on proxied hosts
* Prefer X-Real-IP over X-Forwarded-For when proxy headers are trusted
* Add PROJECT.md agent reference documentation for plugin maintenance

= 1.4.0 =
* Replace proxy-sensitive IP authorization with webhook-confirmed browser handoffs for protected WordPress pages
* Add page-specific signed cookies backed by locally revocable grants
* Prevent full-page caching for both allowed and denied protected-page responses
* Require registered LayerV webhooks before creating new protected-page links
* Retain legacy direct-target session checks temporarily so existing links can be reissued

= 1.3.4 =
* Fix protected-page access checks for block-generated links by accepting all resource IDs tied to the page URL
* Mint block links against the page's configured LayerV resource when available instead of creating a new resource each time
* Restore signed access-grant cookies and webhook-based grants so verified visitors are not blocked by IP mismatches
* Improve client IP detection behind Cloudflare and support additional LayerV session IP fields

= 1.3.3 =
* Admin revoke actions now always update WordPress, even when LayerV returns errors such as "qURL not active"
* Treat already-inactive LayerV links as successfully synced during admin revocation
* Sync local token status from LayerV webhook revoke/expiry events

= 1.3.2 =
* Add an **Allow visitors** block option so logged-out users can generate access links when enabled
* Verify visitor minting server-side against published blocks with visitor access enabled
* Track anonymous visitor links with a secure cookie and per-visitor rate limits

= 1.3.1 =
* Fix plugin activation fatal caused by resolving the REST URL before WordPress initializes rewrite rules
* Register block scripts and styles on `init` in accordance with the WordPress asset lifecycle
* Defer frontend REST configuration and nonce generation until the qURL block renders
* Load translations after WordPress initializes the current locale
* Restrict author-level resource, dashboard, token, and access-log operations to resources they created
* Return explicit REST authorization errors and validate endpoint parameters
* Add automatic database schema upgrades and safe PHP 8 date parsing
* Use authenticated AES-256-GCM encryption while retaining support for existing encrypted values
* Stop trusting spoofable proxy IP headers unless explicitly enabled
* Declare compatibility with WordPress 7.0 and PHP 8.x

= 1.3.0 =
* qURL Access Link Gutenberg block for frontend self-service minting
* Shared `Service` core used by admin and public REST API
* Public REST endpoints: `POST /wp-json/wp-qurl/v1/mint`, `GET /wp-json/wp-qurl/v1/mint/status`
* `mint_qurl_self` capability and frontend block settings (roles, rate limits, hosts)

= 1.2.1 =
* Fix webhook registration button remaining disabled with a configured API key
* Preserve periods in LayerV webhook event names
* Synchronize signing secrets when linking an existing webhook

= 1.2.0 =
* Webhook management UI — register, update, sync, and unregister via LayerV API
* LayerV signing secret stored automatically on registration
* Webhook receiver uses QURL-Event header and delivery deduplication
* Recent delivery log for troubleshooting

= 1.1.1 =
* Page protection now validates against live LayerV sessions on every request
* Removed cookie, transient, and webhook-based access grants

= 1.1.0 =
* Page protection meta box on page editor
* Front-end access gate validates LayerV sessions, cookies, and webhook grants
* Create qURL form pre-fills target URL from page editor

= 1.0.0 =
* Initial release
* Create, list, and revoke qURLs via LayerV API
* Dashboard with resource and token management
* Active session viewing via API
* Webhook-based access event logging
* Encrypted API key storage
* Role-based capabilities

== Upgrade Notice ==

= 2.2.0 =
Protected pages unlock via webhook IP/UA matching again. Remint protected-page links so LayerV targets the real page URL instead of the old handoff callback.

= 2.0.0 =
Legacy direct-target qURL links no longer grant protected-page access. Reissue links from **qURLs → Create qURL** or the page editor so visitors use the browser handoff flow.

= 1.4.2 =
Protected-page access now auto-detects load-balancer proxy headers on DreamHost and similar hosts. Deploy this update if valid qURL links are still denied after 1.4.1.

= 1.4.1 =
If valid qURL links are denied on protected pages, enable Trust Proxy IP Headers under qURLs → Settings → Troubleshooting. This is commonly required on DreamHost and other load-balanced hosting.

= 1.4.0 =
Register or update the LayerV webhook with the `qurl.accessed` event, then reissue protected-page links to use reliable browser handoffs. Existing direct-target links remain on the legacy compatibility path.

= 1.3.3 =
Admin revocation now succeeds in WordPress even when LayerV reports a link is already inactive.

= 1.3.2 =
Adds an optional block setting to let visitors generate qURL access links without logging in.

= 1.3.1 =
Fixes activation errors on current WordPress releases and adds WordPress 7.0/PHP 8.x compatibility.

= 1.3.0 =
Adds the qURL Access Link block and public mint REST API for logged-in users.

= 1.1.0 =
Adds per-page qURL protection in the page editor.

= 1.0.0 =
Initial release of WP qURL.
