=== LN Builder Connector ===
Contributors: livenetworks, dalibor
Tags: page-builder, bricks, elementor, mcp, ai, rest-api
Requires at least: 6.4
Tested up to: 6.7
Requires PHP: 8.1
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Connect WordPress page builders (Bricks, Elementor) securely to AI clients and MCP servers with cryptographic Ed25519 authentication and atomic rollback.

== Description ==

**LN Builder Connector** provides a safe, bidirectional, authenticated REST interface bridging WordPress page builders with AI assistants and Model Context Protocol (MCP) servers.

Rather than dumping unstyled, hallucinated JSON onto your WordPress site, LN Builder Connector extracts your site's complete design context (brand colors, typography scales, CSS variables, and builder components) and provides it to AI clients in a builder-neutral format. When AI generates content, the plugin validates, sanitizes, and writes atomic templates and pages directly into your active builder.

### Key Features

* **Cryptographic Security (Ed25519 / RFC 9421):** Every API request from AI agents is signed with modern Ed25519 asymmetric cryptography and HTTP Message Signatures.
* **Dual Page Builder Support:** Native adapters for **Bricks Builder** and **Elementor Pro**.
* **Design Context & Token Preservation:** Exposes design tokens and CSS custom variables without leaking internal builder IDs or hardcoded styles.
* **Atomic Snapshots & One-Click Rollback:** Automatically captures pre-write snapshots of pages, templates, and design configurations. Easily roll back to any previous revision.
* **Audit Trail & Attribution:** Detailed audit log recording which AI agent, user, session, and organization performed every modification.
* **Idempotency & Replay Protection:** Full nonce, replay-window, and JTI tracking guarantees operations are executed exactly once.

== Installation ==

1. Upload the `ln-builder-connector` folder to `/wp-content/plugins/` or install via the WordPress Plugins menu.
2. Activate the plugin through the **Plugins** screen in WordPress.
3. Navigate to **LN Builder** in the admin sidebar.
4. Click **Connect to MCP** to securely pair your site with your AI environment.

== Frequently Asked Questions ==

= Does this plugin work without an MCP client? =
The plugin exposes standard REST endpoints for external automation. To take full advantage of AI features, pair it with `ln-builder-mcp` or any compatible AI tool.

= Which page builders are supported? =
Currently Bricks Builder 2.2+ and Elementor Pro 3.x are actively supported.

= Is my site secure when connected? =
Yes. All incoming write operations require Ed25519 cryptographic signatures and scoped tokens. Interactive login for the background connector user is strictly disabled.

== Changelog ==

= 1.0.0 =
* Official public release.
* Renamed to LN Builder Connector (`ln-builder-connector`).
* Added Contract v0.4.0 JWKS dynamic key discovery.
* Added User, Session, and Organization attribution on all writes and snapshots.
* Automated hourly cleanup of expired JTIs and rate-limiting counters.
* Full Bricks 2.4+ and Elementor Pro compatibility.
