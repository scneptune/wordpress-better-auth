=== Better Auth WordPress Plugin ===
Contributors: scneptune
Donate link: https://scneptune.com/
Tags: headless, authentication, rest-api, woocommerce, user-sync
Requires at least: 5.8
Tested up to: 6.7
Requires PHP: 7.4
Stable tag: 2.0.3
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Connect Better Auth with WordPress by creating required schema tables and exposing secure user sync REST endpoints.

== Description ==

Better Auth WordPress Plugin is designed for headless or hybrid WordPress projects that use Better Auth for authentication.

IMPORTANT:

**This plugin does not handle user credential checks, password login, session issuance, or frontend authentication UI.**

**Better Auth is responsible for sign up and sign in.** This plugin is responsible for post-signup/post-signin syncing and WordPress-side data storage.

This plugin helps by:

1. Creating and maintaining Better Auth schema tables in WordPress.
2. Exposing secure REST endpoints to sync Better Auth users to WordPress users.
3. Supporting WooCommerce customer profile sync (billing and shipping).
4. Protecting sync routes with HMAC request signing and replay protection.

Who this is for:

- Teams running a separate frontend app and WordPress as content/backend.
- Projects that want Better Auth identity flows while still leveraging WordPress users and WooCommerce customer data.

Developer documentation:

- Full technical guide and HMAC request examples are available in README.md.

Authentication vs Sync flow:

[User]
	|
	| 1) Sign up / Sign in
	v
[Your App + Better Auth]
	|  (Better Auth authenticates and manages sessions)
	|
	| 2) HMAC-signed sync request
	v
[WordPress Better Auth Plugin]
	|-- creates/maintains Better Auth schema tables
	|-- links Better Auth users to WordPress users
	|-- syncs WooCommerce customer profile/address data when available
	v
[WordPress/WooCommerce sidecar data]

In short: Better Auth authenticates. This plugin synchronizes related WordPress and WooCommerce data.

== Installation ==

1. Upload the plugin folder to /wp-content/plugins/ (or install via Composer if your project supports it).
2. Activate the plugin in WordPress Admin > Plugins.
3. Go to Settings > General and find the Better Auth section.
4. Generate API credentials (key id and secret).
5. Configure your backend/service to call the plugin REST endpoints with HMAC headers.

Optional:

- If you use WooCommerce, billing and shipping sync routes will be available automatically when WooCommerce is active.

== Frequently Asked Questions ==

= What database tables does this plugin create? =

On activation, the plugin creates:

- {prefix}ba_user
- {prefix}ba_session
- {prefix}ba_account
- {prefix}ba_verification

= Which REST API routes are available? =

All routes are under namespace: better-auth/v1

- POST /create-user
- PATCH /sync/billing
- PATCH /sync/shipping (WooCommerce required)

= How are requests authenticated? =

Routes use HMAC signing headers:

- X-BA-Key-Id
- X-BA-Timestamp
- X-BA-Nonce
- X-BA-Signature

The plugin verifies signature validity, timestamp drift, and nonce replay.

= Is WooCommerce required? =

No. WooCommerce is only required for billing/shipping sync routes. Core user sync can run without WooCommerce.

= Is this a breaking release? =

Yes. Version 2.0.0 removed the legacy sync-user route and standardizes sync integrations on HMAC-signed routes.

= Where can developers find implementation details? =

See README.md and CHANGELOG.md in the plugin repository.

== Screenshots ==

1. Better Auth settings in WordPress General Settings.
2. Generated key id and one-time secret display.
3. Credential management actions (generate, revoke, rotate).

== Changelog ==

= 2.0.3 =

- Maintenance release with workflow and documentation refinements.

= 2.0.2 =

- Version bump and release metadata alignment.

= 2.0.1 =

- Updated WordPress.org readme structure and consumer-facing documentation.
- Refined release documentation and metadata consistency.

= 2.0.0 =

- Added keyring-based HMAC signing and verification for sync routes.
- Added secure REST sync routes for user creation and WooCommerce billing/shipping sync.
- Removed legacy sync-user REST route.
- Added release workflow (tag-triggered PHPUnit) and improved developer documentation.

= 1.0.1 =

- Updated Better Auth schema table naming to ba_ prefix.

= 1.0.0 =

- Initial release with Better Auth schema migrations and foundational sync behavior.

== Upgrade Notice ==

= 2.0.3 =

Maintenance release with no breaking API changes.

= 2.0.2 =

Maintenance release with version and release metadata updates.

= 2.0.1 =

Maintenance release with documentation and metadata updates.

= 2.0.0 =

This release removes the legacy sync-user endpoint. Update integrations to use HMAC-signed routes (/create-user, /sync/billing, /sync/shipping) before upgrading.
