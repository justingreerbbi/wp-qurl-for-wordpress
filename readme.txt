=== WP qURL ===
Contributors: justingreerbbi
Tags: qurl, layerv, security, access control, secure links, protected pages
Requires at least: 6.3
Tested up to: 7.1
Requires PHP: 8.0
Stable tag: 1.5.22
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Protect pages with private qURL links. Visitors need a link from you to see them.

== Description ==

WP qURL lets you hide a WordPress page and share it with a private [LayerV](https://layerv.ai/) qURL. Direct visits stay blocked. Someone who opens a valid qURL sees the full page, including the theme header and footer. You can also view the page yourself when you are logged in as the author.

= Features =

* Hide a page so only a qURL (or you, when logged in) can open it
* Make a qURL from the admin or from the page editor
* Copy the link when you create it — it is not stored for later
* Set how long a link lasts, whether it works once, and how many people can use it at once
* See your protected pages and their qURLs in one list
* Keep a visit history in your site timezone
* Add a **Mint qURL** button to a page if you want people to make their own link

= Requirements =

* A [LayerV](https://layerv.ai/) account and API key
* WordPress 6.3+
* PHP 8.0+
* HTTPS on your site

== Installation ==

1. Upload the `wp-qurl` folder to `/wp-content/plugins/`
2. Activate the plugin
3. Go to **qURLs → Settings** and paste your LayerV API key
4. Click **Test key**
5. Edit a page, turn on **Protect this page with qURL**, and save
6. Make a qURL for that page under **qURLs → Mint qURL** (or use **Mint qURL for this page** in the editor)
7. Share the qURL. Visiting the page URL directly stays blocked.

= Visit history (optional) =

1. Go to **qURLs → Webhooks**
2. Pick what you want recorded
3. Click **Turn on logging**
4. Open a qURL and check **qURLs → Access Log**

== Frequently Asked Questions ==

= Why is the qURL shown only once? =

The link is only available when you create it. After you leave or reload the page, it cannot be shown again. Make a new qURL if you need another copy.

= Who can make qURLs? =

Administrators, editors, and authors. Administrators can manage everything; authors can make and manage their own links.

= Who can see a protected page without a qURL? =

You can, when you are logged in as the page author. People with full qURL admin access can too. Everyone else needs a qURL.

= How do I protect a page? =

1. Edit the page and turn on **Protect this page with qURL**
2. Make a qURL for that page
3. Share the qURL

Visiting the page URL directly stays blocked.

= Can I put a “make a qURL” button on a page? =

Yes. Add the **Mint qURL** block, choose the page the link should open, and publish. Defaults and who can use the button are under **qURLs → Settings**.

= Do I need a new LayerV API key after an update? =

No. Your saved key is kept. Leave the key field blank when you save other settings.

== Developer Hooks ==

* `wp_qurl_mint_args` — filter mint arguments before the API call
* `wp_qurl_pre_mint` — short-circuit minting
* `wp_qurl_minted` — action after a successful mint
* `wp_qurl_user_can_mint_self` — filter frontend mint permission
* `wp_qurl_resolve_endpoint` / `wp_qurl_is_endpoint_allowed` / `wp_qurl_allowed_mint_hosts` — endpoint validation
* `wp_qurl_visitor_endpoints` — visitor-mint block endpoints
* `wp_qurl_webhook_received` — action after a verified webhook event

== Screenshots ==

1. List of protected pages and their qURLs
2. Mint qURL form
3. Page detail with qURLs and visit history
4. Settings and plan usage
5. Page editor box to turn on protection

== Changelog ==

= 1.5.22 =
* Rewrite admin, block, and blocked-page copy into short language for site editors

= 1.5.21 =
* Remove Active Sessions from resource logging, the page activity box, and the resource detail tabs. URL-type LayerV resources do not report origin sessions.

= 1.5.20 =
* Rename Create qURL to Mint qURL across admin, metaboxes, the Gutenberg block, and documentation
* Use LayerV terms: mint a qURL for a resource, not create an access token
* Show access log, session, expiration, and webhook delivery times in the WordPress site timezone

= 1.5.19 =
* Log a qURL access when a verified LayerV visit unlocks the page, even if LayerV does not send qurl.accessed for URL-type resources
* Deduplicate origin unlocks against webhook qurl.accessed events
* Subscribe to qurl.created by default and keep webhook event subscriptions in sync
* Accept webhook deliveries when CGI strips QURL-* headers but the signed body is valid
* Return a simple health response for GET/HEAD on the webhook receiver

= 1.5.18 =
* Fix Account Quota percent display: LayerV already returns 4.5 for 4.5%, so values are no longer multiplied by 100
* Show active protected resources against the plan cap (for example 3 / 10) instead of a misleading “Active qURLs” percentage

= 1.5.17 =
* Show minted access links once at creation, then discard them so reloads cannot redisplay the portal URL
* Remove CRIDs, resource keys, stored portal URLs, IPs, user agents, and webhook receiver URLs from admin screens
* Add a qURL Activity meta box on protected pages listing issued links and active sessions
* Stop returning stored portal URLs from the frontend mint status endpoint

= 1.5.16 =
* Block protected page content on REST (`/wp-json/wp/v2/pages/{id}`), search, excerpts, feeds, oEmbed, and sitemaps
* Keep protected pages out of public queries so titles and excerpts no longer leak in search results
* REST still returns 403 for guests and for logged-in users who are not the page author or a qURL admin
* Yoast and Rank Math sitemaps no longer list protected permalinks
* LayerV Referer is accepted only on the HTML permalink, not on API or search surfaces

= 1.5.15 =
* Authorize on the real page permalink instead of minting a separate `/qurl-in/{token}` WordPress URL
* Mint LayerV URL resources with the page permalink as `target_url`
* Always 403 leftover `/qurl-in/` requests (that path was a direct-access bypass)
* Keep a signed grant cookie for return visits after a verified LayerV arrival
* Render the full theme (header, navigation, footer) for authorized viewers

= 1.5.14 =
* Distinguish a LayerV arrival from a typed address-bar visit (`qurl.link` / `*.qurl.site` Referer plus `Sec-Fetch-Site: cross-site`)
* Avoid content-only unlocks so authorized viewers get the normal page template
* Send no-cache / private headers so LiteSpeed does not store Access Denied for granted visitors

= 1.5.2 =
* Receive LayerV webhooks on a front-end query URL so plugins that disable the REST API cannot drop `qurl.accessed` events
* Keep the signed REST webhook route as a fallback
* Ignore browser password autofill when saving the LayerV API key

= 1.5.1 =
* Add a local grants table (`wp_qurl_grants`) for WordPress-side viewing grants
* Issue and expire grants independently of the LayerV API schema

= 1.5.0 =
* Rewrite page unlock around WordPress-side grants and a signed access cookie
* Stop relying on LayerV origin sessions for URL-type resources (those redirects often never create a session on this host)
* Protect pages for visitors who used a minted qURL while keeping direct permalink visits blocked
* Record `qurl.accessed` / revoke webhook events against local grants

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

= 1.5.21 =
Removes the unused Active Sessions views. Access Log remains the source of visit history.

= 1.5.20 =
Admin copy uses LayerV mint/resource naming. Access log and other records display in the WordPress site timezone.

= 1.5.19 =
Access Log records verified page visits even when LayerV does not emit qurl.accessed for URL resources.

= 1.5.18 =
Fixes Account Quota % by treating LayerV percent fields as already-scaled percentages.

= 1.5.17 =
Access links are shown only at mint time and are no longer stored for later copy. Protected pages now include a qURL activity overview.

= 1.5.16 =
Protected pages are hidden from REST, search, excerpts, and sitemaps. Remint any qURL that still targets a `/qurl-in/` URL against the real permalink.

= 1.5.15 =
Authorization now runs on the page permalink. Leftover `/qurl-in/` links are denied. Create a new qURL whose target is the real permalink.

= 1.5.14 =
Verified qURL visits render the full theme. Typed visits to leftover admit URLs stay blocked.

= 1.5.2 =
Webhooks use a front-end receiver so REST-blocking plugins no longer drop LayerV events. Re-register or sync the webhook URL after updating.

= 1.5.0 =
Page unlock is handled in WordPress after LayerV verification. Existing API keys are unchanged.

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
