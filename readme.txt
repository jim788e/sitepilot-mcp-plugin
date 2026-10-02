=== SitePilot MCP ===
Contributors: dmisios
Tags: mcp, oauth, automation, ai, approvals
Requires at least: 6.9
Tested up to: 7.1
Requires PHP: 8.2
Stable tag: 0.4.15
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Guarded OAuth and MCP operations for WordPress with dry-run change sets, approvals, audit history, and rollback.

== Description ==

Beta: working and security-audited, but young. Feedback is welcome at https://github.com/jim788e/sitepilot-mcp-plugin/issues.

SitePilot MCP exposes guarded operations through the WordPress Abilities API and the official WordPress MCP Adapter. Direct MCP remains self-hosted and works without SitePilot Cloud.

Mutations require idempotency keys and an inspected site version. Publishing, settings, commerce, extension, user, and security operations are risk classified. Higher-risk operations require a fresh, hash-bound approval that expires after 30 minutes.

The optional SitePilot Cloud service adds a multi-site MCP gateway, retained chat, encrypted BYO OpenAI configuration, artifact validation, and visual QA. It is disabled until an administrator explicitly opts in. The plugin never accepts or stores a WordPress password.

Source code, documentation and support: the plugin source is public at https://github.com/jim788e/sitepilot-mcp-plugin, the documentation is at https://docs.sitepilot.tools/, and the product site is https://sitepilot.tools/. Report security issues privately to security@sitepilot.tools.

== Installation ==

1. Upload the release ZIP in Plugins > Add New > Upload Plugin.
2. Activate SitePilot MCP on a single-site WordPress 6.9+ HTTPS installation.
3. Open SitePilot > Diagnostics and confirm all checks pass.
4. Connect an MCP client through OAuth. Review the exact requested scopes on the consent page.
5. Optionally enable the cloud connection under SitePilot > Security.

== External services ==

The self-hosted MCP server does not require SitePilot Cloud. When an administrator explicitly enables and configures optional cloud features:

* Cloudflare may process account identity, encrypted connection grants, change metadata, artifacts, screenshots, and chat transport to provide Workers, Access, D1, R2, Workflows, Durable Objects, Sandbox, and Browser Rendering. Cloudflare privacy policy: https://www.cloudflare.com/privacypolicy/
* OpenAI receives prompts and selected site context only when the account owner supplies an API key and uses chat/generation. OpenAI privacy policy: https://openai.com/policies/privacy-policy/
* WordPress.org endpoints may be contacted when an administrator explicitly requests a plugin or core update.
* HTTPS Client ID Metadata Document URLs are fetched only as part of an explicit OAuth authorization flow.
* A configured private-beta update host receives standard HTTPS requests for the signed manifest and selected plugin ZIP. Site content, credentials, and OAuth tokens are never sent to the update host. No private update request occurs until an administrator configures both the channel URL and its Ed25519 public key.

No cloud request is made before opt-in. Secrets are not written to logs or returned after storage.

== Privacy ==

Persistent OAuth token records contain only hashes. During refresh rotation, the exact response is cached for at most 30 seconds in authenticated encrypted form using a key derived from the site's WordPress salts; this makes duplicate client requests idempotent without creating a second refresh-token branch. Staged artifacts and revoked OAuth grant metadata are removed after 30 days; audit/security metadata is retained for 180 days. WordPress privacy erasure removes a user's grants and artifacts immediately while retaining minimal audit metadata for security accountability. Uninstalling the plugin removes its local tables, options, capabilities, and staged files.

== Frequently Asked Questions ==

= Can SitePilot run arbitrary PHP, SQL, shell, or WP-CLI commands? =

No. Those capabilities are intentionally absent from every public schema.

= Can it permanently delete content? =

Normal deletion uses the reversible WordPress trash workflow with standard approval. Permanent deletion is disabled by default and, if an administrator explicitly enables it in Security, is limited to a single-item Tier-3 change with fresh administrator approval and no rollback.

= Does the plugin store my WordPress password? =

No. Clients use OAuth Authorization Code with PKCE S256, short-lived opaque access tokens, and rotating refresh tokens.

= What scopes does a newly registered OAuth client receive? =

A Dynamic Client Registration request may include a space-separated `scope` value. When it omits that metadata, SitePilot registers the client with the read-only `site:read` default. Authorization cannot exceed the registered ceiling: a broader request is reduced before the consent page is shown and before tokens are issued.

Upgrading adds the read-only ceiling to existing dynamic client registration records for future authorization requests. It does not silently change a grant that a user already consented to: existing access and refresh tokens keep that grant's scopes until expiry or revocation. After upgrading, revoke any overbroad connection under SitePilot > Connections. To reconnect with broader authority intentionally, use a client that sends the required scopes in Dynamic Client Registration; otherwise the new connection remains read-only.

= How are private-beta updates verified? =

The optional updater verifies a strict Ed25519-signed manifest and then verifies the downloaded ZIP SHA-256 before WordPress can install it. The channel is disabled unless its HTTPS manifest URL and public key are explicitly configured.

== Changelog ==

= 0.4.15 =
* Rate limit public OAuth client registration to 30 per hour, bound the redirect URI count and length and the client name, and remove registered clients that never produced a grant or code after 30 days.
* Mark the plugin as beta in the description.

= 0.4.14 =
* Link the public source repository, documentation and security contact from the plugin description.

= 0.4.13 =
* Point the Plugin URI at sitepilot.tools and add an Author URI.
* Unslash and sanitize remaining request values in the admin handlers, escape two table names, and run uninstall in a prefixed function, resolving the genuine Plugin Check warnings.
* Include composer.json in the WordPress.org package.

= 0.4.12 =
* Protect the artifact staging directory with an index file and an access deny, including on existing installations after an upgrade.
* Unslash `REQUEST_URI` and `HTTP_AUTHORIZATION` before use and document the Enfold normalized-data handling flagged by Plugin Check.
* Add a WordPress.org package variant.

= 0.4.11 =
* Add a Connect tab to the SitePilot admin screen with the site MCP endpoint and paste-ready setup for Claude, Claude Code, Cursor, and a read-only terminal fallback.

= 0.4.10 =
* Enforce RFC 7591 Dynamic Client Registration scope ceilings, default omitted scope metadata to read-only `site:read`, and reduce overbroad authorization requests before consent and token issue.
* Show each OAuth connection's registered client ceiling separately from its granted scopes. Existing grants retain their previously consented scopes until expiry or revocation; revoke and re-register any connection that should use the new ceiling.

= 0.4.9 =
* Accept RFC 8252 ephemeral ports for otherwise exact registered loopback OAuth callbacks, including Claude Code Client ID Metadata without an `application_type` field.
* Restore a Dashboard submenu entry ahead of Playbooks so the SitePilot control panel remains reachable from WordPress navigation.

= 0.4.8 =
* Add site-served MCP playbooks and explicit approval cancellation/revocation lifecycle controls.

= 0.4.7 =
* Inspect mixed Elementor 3/4 documents per region and allow only existing, type-preserving setting updates on runtime-discovered atomic elements. Atomic construction, structural edits, and Global Classes/Variables writes remain fail-closed.
* Make optional orchestration capabilities depend on explicit opt-in plus an exact HTTPS origin; standalone visual verification is advisory-unavailable and artifact import points operators to direct `media.stage` upload.
* Mark partial compensation as failed, add restart-boundary idempotency coverage, and expand the live WooCommerce matrix to price, stock, HPOS orders, and irreversible refunds.
* Keep Enfold nested global options explicitly unsupported; only calibrated scalar colour and typography keys are writable.

= 0.4.6 =
* Add secret-free credential status and authorization-header probe endpoints for the standalone MCP server's preflight and diagnostics.
* Echo the effective credential type, state, and bounded scopes from site inspection without exposing a credential UUID or secret.

= 0.4.5 =
* Separate change-set approval from operation credentials with an administrator-only capability and a nonce-authenticated wp-admin channel.
* Keep credential delegation off by default, constant-gated, and unavailable in production environments.
* Record the approval actor, credential identity, change-set hash, and approval channel in the audit event.

= 0.4.4 =
* Bound Application Password authentication to an immutable per-credential scope grant instead of inheriting every SitePilot scope from the WordPress user.
* Default unclaimed Application Passwords to read-only `site:read` and add a one-shot v2 claim route that excludes administrator-only scopes.
* Add administrator controls to inspect, narrow, widen, and revoke claimed credentials while enforcing the credential owner's OAuth scope ceiling.

= 0.4.3 =
* Return the exact draft preview URL and a structured outline diff from `design.edit_elements` dry runs for Elementor and Enfold.
* Reload and reparse Enfold clean data immediately before serialization; reject a concurrent Avia edit with `sitepilot_enfold_edit_conflict` before SitePilot writes anything.
* Move Cloud Browser Rendering away from automatic compiler/publication gating. `visual_verification: true` now requests optional desktop/mobile before-and-after edit evidence whose result never blocks writing or publishing.
* Remove compiler-facing visual-threshold/status placeholders so HTML compilation remains a draft seeder with coverage and unmapped manifests, not a pixel-similarity import gate.

= 0.4.2 =
* Replace the 0.4.1 duplicate-refresh implementation with an encrypted, exact-response replay cache. Any number of duplicate requests inside the 30-second grace period receive the same access and refresh tokens, so client retries cannot fork the token family.
* Fail closed when the replay cache is absent or invalid, parse stored OAuth timestamps explicitly as UTC, reject revoked grants before replay, and roll back rotation if the encrypted response cannot be cached.
* Automatically revoke any live refresh families already forked by 0.4.1 and add repository-level regressions for exact replay, missing or corrupt cache state, delayed reuse, revoked grants, UTC handling, and transaction rollback.

= 0.4.1 =
* Accept one duplicate refresh request within a 30-second rotation grace period so Codex retries do not revoke an otherwise healthy connection. Any additional or delayed reuse still revokes the complete token family.
* Run database schema upgrades automatically, record OAuth grant revocation reasons, show connection creation/revocation timestamps, audit administrator revocations and accepted refresh retries, and remove revoked grant metadata after 30 days.
* Keep the approval action visible above each pending change and collapse the complete, scrollable change details so long diffs do not bury the button.

= 0.4.0 =
* Add Enfold parity for reusable templates, guarded theme colour/typography updates with exact option rollback, and page-scoped protected element styles keyed by a unique `custom_class`.
* Return an explicit unsupported-for-builder response for Enfold theme documents because Enfold headers and footers are theme-option driven rather than standalone documents.
* Split `EnfoldHtmlCompiler.php` from 3,037 physical lines (2,890 nonblank) into focused collaborators, leaving the entry point at 1,795 physical lines (1,695 nonblank). The entry point plus five extracted collaborators totals 2,832 nonblank lines, a net deletion of 58 rather than a claim that extraction alone shrank the whole subsystem.
* Remove nine bespoke detectors—split card, vertical card stack, styled composite wrapper, tab control, tab strip, active tab control, accordion button, accordion panel, and active tab panel—in favor of request mappings and semantic HTML/ARIA contracts.
* Keep Enfold document writes calibrated and draft-only. Template saving may read an editable ALB page without changing it; template application and protected element styling still require a draft target.
* Advertise `/wp-json/sitepilot-mcp/v2/mcp` as the canonical direct endpoint. Reconnect MCP clients after an upgrade so cached tool schemas refresh; update saved `Sitepilot:` connector-qualified references to `SitePilot:`.

= 0.3.1 =
* Add atomic Enfold support to `design.edit_elements`: registered attribute updates, bounded direct-content edits, insert, move, remove, and duplicate, dispatched by `builder: enfold` while preserving the existing Elementor default.
* Reparse after every edit to refresh structural paths and reject content-driven shortcode-tree changes. Preserve document tails across root mutations and fail closed before rewriting tokens with bare, duplicate, or unparsed attributes.
* Run successful edits through Enfold's calibrated native ALB save, metadata verification, snapshot, and automatic rollback pipeline. Runtime-only elements retain their documented restricted mutation policy.

= 0.3.0 =
* Extend the read-only `sitepilot/inspect-design` tool to Enfold. It returns a bounded ALB outline, runtime shortcode definitions and fingerprint, filtered theme-option colours and typography, and page template, ALB, calibration, and UID/path identity settings.
* Label shortcode definitions as `curated` or `runtime_only` without narrowing existing-page validation, let default inspection describe non-ALB pages without failing, and exclude secret-like theme-option keys from design globals.

= 0.2.1 =
* Add `EnfoldDocument`, a bounded ALB parser, lossless serializer, and agent-facing outline model extracted from the existing shortcode validator. Canonical saved-page and regression fixtures now round-trip byte for byte with stable structural paths.
* Confirm the native Enfold save pipeline preserves assigned `av_uid` values and fills only blank UIDs. Normalized UIDs are therefore stable primary element identities, with structural paths retained as the fallback for pre-normalized content.

= 0.2.0 =
* Reframe HTML-to-Enfold compilation as a best-effort draft seeder: a fast static-source preflight rejects clearly script-driven or framework-heavy input with machine-readable reasons, while safe partial compilations return native elements plus an actionable unmapped-region manifest instead of failing on coverage loss.
* Make request-supplied component mappings authoritative over fallback heuristics and move bespoke tab target attributes into the mapping contract. Code Block fallback remains disabled.

= 0.1.38 =
* Fix a generic HTML-to-Enfold compilation regression that could silently flatten or lose nested sections, composite-owned content, and grid/card/image layout groups behind an outer wrapper. Semantic coverage matching is now scoped per top-level section instead of a single document-wide cursor, tab/accordion ownership tracking is stable across DOM re-queries, native layout groups and cards inside a styled wrapper are no longer swallowed into one opaque preserved block, and coverage accounting correctly credits layout groups, cards, and images compiled inside preserved composite content (tab panels, accordions) so future imports of real-world multi-section, nested-tab pages compile without unnecessary Code Block fallbacks or reported coverage loss.

= 0.1.37 =
* Release-number correction for the Enfold interaction compiler improvements.

= 0.1.36 =
* Compile declaratively linked button/panel controls and exclusive grid or flex switchers into native Enfold tabs, restoring safe click behaviour for future HTML imports without executing source JavaScript.
* Preserve generic styled composite wrappers and a restricted, non-executable subset of decorative inline SVG so future imports retain visual grouping and icons without project-specific mappings.

= 0.1.35 =
* Fix HTML-to-Enfold compilation for legacy alternating button/panel accordions, paragraph-wrapped triggers, and nested span titles, compiling them to native Enfold toggles with initial open state instead of flattening them into an unreactive preserved text block.
* Add specificity scoring and scoped selector support to component mappings in the Enfold HTML compiler.

= 0.1.34 =
* Accept dynamically assigned ports on verified native-app loopback OAuth callbacks, as required for Codex and other native clients, while retaining exact redirect URI validation for all other clients.

= 0.1.33 =
* Fix widget-settings validation being disabled for any widget registering more controls than the agent-facing display cap, which let an unregistered setting key reach Elementor unchecked.

= 0.1.32 =
* Add `sitepilot/inspect-design`, a read-only tool returning an Elementor page's element tree, the registered widget types with their accepted setting keys, the global colour and typography kit, and page settings.
* Add `design.edit_elements` (Tier 1) to change, insert, move, remove, duplicate or label individual Elementor elements without rewriting the document. Edits apply atomically and widget settings are validated against that widget's registered controls before any mutation.
* Add `design.compile_elementor_html` (Tier 1), compiling bounded HTML and CSS into native, separately editable Elementor containers and widgets under the same coverage rejection, Media Library mapping and link mapping rules as the Enfold compiler. There is no opaque HTML-widget fallback.
* Add `design.save_template` and `design.apply_template` (Tier 1) for reusable `elementor_library` templates, regenerating element ids on every application so one template can be applied to many pages.
* Add `design.update_global_kit` and `design.stage_theme_document` (Tier 2, approval required) for global colours and typography and for draft header, footer, single, archive, search-results and 404 theme documents.
* Invalidate Elementor's rendered-element cache and regenerate per-post Elementor CSS on every write, including rollback, so a page written outside the editor cannot serve a stale render or stale styles.
* Guard Elementor's document manager during Elementor's own activation, and fall back to raw `_elementor_data` when the document API returns empty under WP-CLI or REST contexts.
* Document the Elementor `document` and `settings` fields in `describe-operations`, which previously made Elementor staging undiscoverable.

= 0.1.31 =
* Fix preserved-structure inline `background-image` URLs being silently stripped by WordPress's post-save sanitizer, which rejected the double-quoted `url()` value once serialized into the HTML attribute.
* Fix accordion and tab detection deferring to an explicit component mapping and requiring real `<details>` panels, so a grid-styled element that only looked like an accordion or tabs by class name no longer had its content discarded into an empty toggle container.

= 0.1.30 =
* Compile nested cards as content owned by their enclosing Enfold column, preventing invalid `av_layout_row` nesting and retaining native editable image elements.

= 0.1.29 =
* Keep CSS-grid card collections inside their owning accordion panels instead of promoting them into unrelated Enfold columns.
* Preserve complex accordion HTML safely in one editable native Enfold text block when native toggles cannot retain the source panel classes needed for visual fidelity.

= 0.1.28 =
* Enfold 7.1.x native shortcode-tree validation now recognizes `av_toggle` as an implicit child record while still validating its canonical clean data and page-specific element state.

= 0.1.27 =
* Enfold HTML compilation now consistently excludes `aria-hidden="true"` decorative content from visible-text coverage and native accordion titles, preventing icons such as disclosure chevrons from causing false coverage failures.

= 0.1.26 =
* Rebuild Enfold's page-specific shortcode tree from normalized ALB content after its native save pipeline, preserving editable accordion/toggle elements and preventing false tree-mismatch rollbacks.

= 0.1.25 =
* Add an explicit structure-preserving compiler mode for semantic grids, cards, and galleries that Enfold cannot represent without flattening their CSS-targeted wrappers.
* Resolve mapped images inside preserved interactive structures while retaining strict coverage validation and safe native ALB containment.

= 0.1.24 =
* Explicitly refresh Enfold's page-specific element index from normalized ALB content after native REST/MCP staging, preventing stale column metadata such as a missing `av_one_fourth`.
* Keep strict element-state verification and return a dedicated machine-readable error if Enfold's native index refresh fails.

= 0.1.23 =
* Preserve leading breadcrumbs, introductions, and hero media when an HTML page wraps its remaining content in a semantic section.
* Distinguish native gallery containers from image elements whose CSS class includes “gallery”, preventing false coverage failures on complex Enfold imports.
* Preserve layout wrappers represented directly by ALB columns and support bounded destination pages with up to 384 editable shortcode elements.

= 0.1.22 =
* Reconcile approved page title, excerpt, slug, parent, menu order, and draft status after Enfold's native ALB normalization pipeline.
* Fail closed with machine-readable metadata diagnostics and automatic rollback if WordPress does not persist the approved page fields.

= 0.1.21 =
* Emit canonical WordPress attachment URLs with attachment IDs for compiled Enfold images and fail safely when an asset cannot render.
* Preserve page-scoped compiler CSS when design staging omits a replacement and validate that its scope remains attached to the page.
* Preserve root hero ownership, nested composite content, and complete native contact-form context without flattening later siblings.
* Validate mapped preview images at desktop and mobile sizes as part of the real screenshot acceptance gate.

= 0.1.20 =
* Add a versioned v2 MCP resource endpoint so clients can refresh the complete guarded-operation schema while legacy v1 connections remain valid.
* Run Browser Rendering source/preview comparison after both immediate and human-approved compiler executions, with desktop/mobile scores and private evidence artifacts.
* Keep approved and historical change sets out of the Pending approvals screen.

= 0.1.19 =
* Follow WordPress admin-menu inactive, hover, and selected colors for the SitePilot icon while retaining the full-color brand mark inside SitePilot screens.
* Treat CSS rules aimed only at intentionally omitted SVG nodes as reported omissions, preserve strict rejection for unsupported declarations on retained nodes, and rewrite removed source-root selectors to the generated page scope.
* Preserve structured compiler rejection codes and data through MCP tools/call, advertise versioned input-schema metadata, and verify the refreshed operation enum through the real MCP transport.
* Produce private desktop/mobile source and draft-preview screenshots, visual diff evidence, and similarity scores through SitePilot Cloud after successful Enfold staging; block acceptance when rendering fails or falls below the requested threshold.

= 0.1.18 =
* Use Enfold 7.1.6's native `av_layout_row`, runtime shortcode registry fingerprint, and parent/child hierarchy validation instead of trusting a static tag name.
* Preserve safe residual CSS as page-scoped protected metadata with Media Library URL rewriting and rollback coverage; reject unsafe selectors, declarations, at-rules, and unmapped assets.
* Add semantic node manifests, nested-section ownership, deterministic 4+1 and 3+2 row chunking, native contact-field attributes, responsive component mapping objects, and structured compiler diagnostics.

= 0.1.17 =
* Compile semantic card stacks and grids into native editable Enfold card compositions with responsive image/content Grid Row cells, without misclassifying BEM child elements as extra cards.
* Preserve structured compiler error codes, coverage diagnostics, and visual-validation state through direct MCP instead of reducing failures to generic text.
* Document exact media, link, and component mapping schemas, keep the plan-change operation enum synchronized, and accept safe `sitepilot://page/<slug>` references without mistaking them for Windows paths.

= 0.1.16 =
* Add guarded `design.compile_enfold_html` compilation from bounded HTML/CSS into separately editable native Enfold sections, columns, cards, headings, text, images, buttons, galleries, accordions, tabs, and contact fields.
* Reject structural, asset, or visible-text loss with machine-readable design-coverage diagnostics; Code Block fallback remains disabled.
* Preserve the verified Enfold native normalization pipeline and return desktop/mobile visual-regression requirements with every compiled draft.

= 0.1.15 =
* Run Enfold's native normalization and post-save pipeline for staged ALB drafts, including page-specific element IDs, clean data, shortcode trees, parser state, and element usage metadata.
* Validate normalized clean data against canonical content without rejecting Enfold-generated `av_uid` values, and return machine-readable recognition diagnostics with rollback status.

= 0.1.14 =
* Use Enfold 7.x's public builder instance methods for ALB status and clean-data persistence, while retaining compatibility with older static declarations.
* Complete Enfold's builder initialization when a REST or MCP request first loads it after WordPress `init`.

= 0.1.13 =
* Bootstrap Enfold's native builder framework for REST and MCP requests before calibration or ALB staging.

= 0.1.12 =
* Allow execution to securely resolve the newest valid approval for the exact change set and input hash when an MCP client omits the approval ID.
* Generate Enfold ALB clean data, shortcode trees, element state, parser state, and related metadata through Enfold's native page-specific save pipeline instead of copying calibration values.
* Verify staged ALB metadata against the supplied shortcode document, support registered Enfold `av_*` elements such as contact forms, and hide valid approvals while returning expired approvals to the pending list.

= 0.1.11 =
* Return the newest valid, unused approval ID and expiry from change-set status so approved MCP changes can execute without weakening human approval controls.

= 0.1.10 =
* Advertises the exact guarded write-operation enum through direct MCP and adds a read-only operation catalog with scopes, risk tiers, targets, and validated input fields.
* Makes draft pages, Enfold ALB staging and calibration, Media Library uploads, page fields, and navigation mutations safely discoverable without weakening change-set approvals or rollback.

= 0.1.7 =
* Adds fail-closed Enfold Advanced Layout Builder staging based on an administrator-verified metadata profile, strict ALB shortcode validation, complete snapshots, and rollback.
* Adds safe page hierarchy, template, order, and featured-image fields across supported builders.
* Adds navigation inspection, menu creation/location assignment, same-build page reference resolution, SHA-256 media reuse, and validated artifact-to-Media-Library import.

= 0.1.6 =
* Require and validate the OAuth `resource` parameter in authorization-code and refresh-token requests, binding direct MCP tokens to this site's canonical endpoint.

= 0.1.5 =
* Advertise the OAuth protected-resource metadata URL in unauthenticated direct-MCP responses, so standards-compliant clients can reliably begin OAuth discovery.

= 0.1.4 =
* Validates Elementor documents before mutation and safely recovers when Elementor rejects a staged document.
* Generates editable Elementor container/widget documents when an agent supplies malformed builder data.
* Shows live agent progress and provider errors instead of leaving an empty response.

= 0.1.3 =
* Adds durable multi-page site-build planning, draft preview URLs, approval-gated publication, and complete rollback evidence.
* Records bounded per-action execution results so clients can reliably associate staged WordPress pages with their previews.
* Verifies compatibility with WooCommerce 11.0.1 and HPOS in the release matrix.

= 0.1.2 =
* Apply the production SitePilot identity to the WordPress control plane and OAuth authorization screen, including self-hosted Archivo typography and versioned admin assets.

= 0.1.1 =
* Use stable database-backed post metadata for the optimistic site version, allowing inspection and planning to run safely in separate API requests without a persistent object cache.

= 0.1.0 =
* Private-beta foundation with OAuth, seven MCP abilities, risk-based change sets, approvals, audit, rollback, adapters, optional cloud gateway, and fail-closed signed updates.
