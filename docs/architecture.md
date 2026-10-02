# Architecture

## Trust boundary

The WordPress plugin is the policy enforcement point for every site operation. Clients and optional orchestration services can request work, but cannot bypass WordPress capabilities, credential scopes, risk classification, approvals, optimistic version checks, or audit logging.

The canonical MCP endpoint is `/wp-json/sitepilot-mcp/v2/mcp`; `/v1/mcp` remains compatible. The split-clean REST bridge for trusted orchestration is `POST /wp-json/sitepilot-mcp/v1/ops/{ability}`. The former `/gateway/{ability}` path remains a compatibility alias. New callers must use `/ops`; approval is deliberately absent from that operation route.

## Mutation state machine

Every mutation follows this lifecycle:

`planned -> awaiting_approval | approved -> executing -> validating -> completed | rolled_back | failed`

- Tier 0 is read-only.
- Tier 1 may execute automatically within the credential's scopes.
- Tier 2 requires an unexpired standard approval.
- Tier 3 requires a fresh approval by a WordPress administrator.

Approval is bound to the immutable change-set hash and expires after 30 minutes. An API credential cannot approve unless the dedicated approval guard accepts its channel and delegated authority; ordinary OAuth credentials are denied.

## Components

- OAuth 2.1 Authorization Code with PKCE S256 and opaque, hashed tokens.
- Application Password claim records with immutable scopes.
- Official `wordpress/mcp-adapter`, loaded with Jetpack Autoloader.
- Database-backed change sets, approvals, audit events, tokens, grants, and artifacts.
- Optional fail-closed private updater using an Ed25519-signed strict manifest and a SHA-256 binding to the release ZIP.
- Gutenberg, Elementor, Enfold, and WooCommerce adapters that use public WordPress and extension APIs only.

Raw PHP, SQL, shell, WP-CLI, WordPress-root deletion, server-secret access, and `wp-config.php` are not represented in any input schema.

## Persistence and recovery

Custom WordPress tables are authoritative for change sets, approvals, pre-mutation snapshots, credential grants, tokens, audit events, artifacts, and idempotency records. Transients are limited to recomputable caches.

WordPress does not provide a cross-table transaction over posts, metadata, and terms. SitePilot therefore snapshots each affected resource before execution and records ordered compensating operations. Rollback replays those operations in reverse order; a partial rollback is `failed`, never reported as `rolled_back`.

## Builder boundary

Elementor writes pass through `ElementorDocumentStore` and Elementor's own `Document::save()`. Enfold writes require a site-specific calibrated metadata profile and pass through the bounded `EnfoldDocument` parser. Request-provided HTML and CSS enter only through the named compiler operations and cannot bypass the normal plan, approval, execution, validation, snapshot, and rollback pipeline.

Detailed builder contracts live in [elementor.md](./elementor.md) and [enfold.md](./enfold.md).

## Contract versioning

`@instantbuild-sitepilot/contracts` uses semantic versions. Its npm version and the literal `CONTRACT_VERSION` move together. The shared mutation envelope intentionally permits omission of `site_id` for standalone WordPress calls; an orchestration service that routes among sites must require `site_id` at its own boundary.

All mutating requests require an `idempotency_key` and `expected_version`. The WordPress site version is a deterministic digest of core, theme, plugin, and relevant content state.
