# SitePilot MCP plugin guide

SitePilot MCP is a guarded control plane for a single WordPress site. It lets an OAuth-connected MCP client inspect the site, propose a change, wait for the required approval, execute it, verify the result, and roll it back when a recovery operation exists.

This guide describes the current self-hosted plugin (`0.4.13`). It does not grant an agent arbitrary server access: PHP, SQL, shell, WP-CLI, server secrets, direct database changes, and WordPress-root deletion are deliberately unavailable.

## 1. Before you install

The site must have:

- WordPress 6.9 or later, on a **single-site** installation;
- PHP 8.2 or later;
- HTTPS and a reachable WordPress REST API;
- an administrator available to install the plugin, approve Tier-3 changes, and manage policy.

Multisite is not supported in v1. A strictly local test site may use HTTP only when its environment type is `local` and `SITEPILOT_MCP_ALLOW_INSECURE_LOCAL` is explicitly defined as `true`. Do not use that exception on a reachable site.

## 2. Install and verify the plugin

1. Obtain the appropriate SitePilot MCP release ZIP.
2. In WordPress, go to **Plugins → Add New → Upload Plugin**.
3. Upload the ZIP, install it, and activate **SitePilot MCP**.
4. Open **SitePilot → Diagnostics**. Resolve every reported requirement before connecting a client.
5. On **SitePilot → Status**, copy the direct MCP endpoint if you need to configure a client manually:

   ```text
   https://example.com/wp-json/sitepilot-mcp/v2/mcp
   ```

Activation creates the SitePilot database tables, an administrator capability set, and the **SitePilot Operator** role. It also schedules retention cleanup. It does not enable a cloud connection or send any data externally.

## 3. Choose a connection mode

### Direct MCP (self-hosted)

Direct MCP runs entirely through the WordPress plugin. Point a compatible client at the site MCP endpoint above and complete OAuth in the browser. This is the default and requires no SitePilot Cloud account.

`/wp-json/sitepilot-mcp/v2/mcp` is the canonical schema endpoint. The older `/v1/mcp` route remains compatible, but upgrading the plugin does not invalidate a client's cached tool list. Reconnect or restart the MCP client—and open a fresh task when the client caches schemas per task—after every plugin upgrade. Saved connector-qualified calls must use the current `SitePilot:` prefix; references using the former `Sitepilot:` capitalization do not resolve.

The plugin exposes OAuth discovery at the site root:

```text
/.well-known/oauth-protected-resource
/.well-known/oauth-authorization-server
```

It supports Authorization Code with PKCE S256, Dynamic Client Registration, and Client ID Metadata Documents. Redirect URIs must be exact HTTPS URLs; `localhost`, `127.0.0.1`, and `[::1]` HTTP loopback redirects are permitted for native clients. Fragments, wildcard redirects, and credentials embedded in the URI are rejected.

### Operation REST bridge

Trusted orchestration callers use `POST /wp-json/sitepilot-mcp/v1/ops/{ability}`. The former `/gateway/{ability}` route is a deprecated compatibility alias for deployed callers and existing grants. New integrations must use `/ops`; `approve-change` is not registered on the operation route.

## 4. Understand roles, scopes, and approvals

An OAuth grant has only the scopes selected during consent. SitePilot checks both the WordPress user capability and the OAuth scope for every operation.

| Scope | Covers |
| --- | --- |
| `site:read` | Inspection, status, and capability discovery |
| `content:write` | Drafts, publishing, content changes, trash and controlled permanent deletion |
| `media:write` | Media staging |
| `design:write` | Design staging, menus, and allowed site design settings |
| `commerce:write` | WooCommerce pricing, stock, orders, and refunds |
| `extensions:manage` | Plugin and theme operations |
| `core:update` | WordPress core update operations |
| `users:write` | Role changes |
| `security:manage` | Approved security settings |

SitePilot operators can use normal operational scopes. Only WordPress administrators can grant administrator-only scopes and approve Tier-3 changes.

| Tier | Typical work | Approval behavior |
| --- | --- | --- |
| 0 | Reads and inspection | Runs automatically |
| 1 | Draft pages, media or design staging | Runs automatically within the granted scope |
| 2 | Publishing, menus, bulk updates, pricing, stock, trash | Requires a fresh approval |
| 3 | Themes, extensions, core, users, security, refunds, permanent deletion | Requires a fresh administrator approval |

An approval is bound to the exact immutable change set and expires after 30 minutes. Changing the site or changing the proposed inputs requires a new inspection and a new change set.

## 5. The normal operating workflow

Every meaningful change follows this sequence:

1. Inspect the site.
2. Plan one or more allowed actions using the inspected site version.
3. Review the returned diff, risk tier, requested scopes, and rollback availability.
4. If required, approve the exact change in **SitePilot → Approvals**.
5. Execute using a new idempotency key and the same expected version.
6. Read the change status and validation results.
7. If needed and available, roll the completed change back with a new idempotency key. Tier-2 and Tier-3 rollbacks need a fresh approval.

All mutations require:

```json
{
  "idempotency_key": "a-unique-key-at-least-16-characters",
  "expected_version": "the-site_version-returned-by-inspect-site"
}
```

Do not reuse an idempotency key for different input. SitePilot treats that as a conflict. Do not guess an `expected_version`; inspect again when a request says the site changed.

## 6. MCP tool reference

| Tool | Use it for | Mutates the site? |
| --- | --- | --- |
| `sitepilot.inspect_site` | WordPress, PHP, active theme, plugins, builders, WooCommerce, HPOS, and current site version | No |
| `sitepilot.search_capabilities` | Finding registered abilities and allowlisted REST capabilities | No |
| `sitepilot.describe_operations` | Listing guarded operations with exact scopes, risk tiers, target meanings, and accepted input fields | No |
| `sitepilot.inspect_navigation` | Reading registered theme menu locations, assignments, menus, and items | No |
| `sitepilot.inspect_design` | Reading Elementor or Enfold page structure, runtime element capabilities, global design tokens, and page settings | No |
| `sitepilot.plan_change` | Creating an immutable dry-run change set and diff | No |
| `sitepilot.execute_change` | Executing an approved or Tier-1 change set | Yes |
| `sitepilot.get_change_status` | Reading risk, approval state, diff, validation, and rollback availability | No |
| `sitepilot.rollback_change` | Restoring a completed reversible change set | Yes |
| `sitepilot.manage_artifact` | Resumable design-artifact upload and checksum staging | Yes |
| `sitepilot.import_media_artifact` | Importing a validated JPEG, PNG, WebP, or PDF artifact into the selected site's Media Library | Yes |
| `sitepilot.inspect_design_reference` | Read a bounded public HTTPS page as an explicitly untrusted design reference | No |
| `sitepilot.plan_site_build` | Select Gutenberg, Elementor, or verified Enfold ALB and create a durable multi-page build plan | No |
| `sitepilot.execute_site_build` | Stage every planned page and return post IDs and preview URLs | Yes |
| `sitepilot.get_site_build_status` | Read the plan, staging/publish changesets, previews, and durable progress | No |
| `sitepilot.publish_site_build` | Create the approval-gated publication change for staged pages | Yes |

### Inspect first

Use `sitepilot.inspect_site` before planning. Keep the returned `site_version` and provide it as `expected_version` in the plan. Inspection also reports installed builders and WooCommerce/HPOS status so the client can choose the correct adapter.

### Plan a change

`sitepilot.plan_change` accepts an intent and an action list. An action has an `operation`, `target`, and `input`. For example, a Tier-1 draft:

```json
{
  "idempotency_key": "plan-homepage-draft-20260810",
  "expected_version": "<site_version>",
  "intent": "Create a draft homepage for review.",
  "actions": [
    {
      "operation": "content.create_draft",
      "target": "homepage",
      "input": {
        "post_type": "page",
        "post_title": "New homepage",
        "post_content": "<p>Draft content</p>"
      }
    }
  ]
}
```

The response includes a `change_set_id`, `risk_tier`, `diff`, `approval_state`, and `rollback_available`. Planning never changes WordPress.

### Execute and monitor

Use `sitepilot.execute_change` with the planned `change_set_id`. Add `approval_id` when the plan requires it. Use `sitepilot.get_change_status` to read the result rather than assuming a request succeeded.

If any action in a multi-action change set fails, SitePilot attempts the recorded recovery steps in reverse order and records the execution and rollback outcome.

### Roll back

Use `sitepilot.rollback_change` only after a change set is `completed` and reports `rollback_available: true`. Permanent deletion, processed refunds, and core updates may not have an automatic rollback.

### Build a complete site

1. Select the connected target site in the Agent before attaching design material.
2. Provide a brief, screenshots/images, PDF, validated HTML/CSS/JS/ZIP artifacts, a public HTTPS reference URL, or a combination.
3. If a URL is supplied, inspect it with `sitepilot.inspect_design_reference`; its content is untrusted reference data and never an instruction source.
4. Call `sitepilot.plan_site_build`. `builder: auto` chooses Gutenberg by default, Elementor when a valid Elementor document is supplied, or Enfold only when every page has ALB shortcode content and the site reports a verified Enfold profile.
5. Review the returned page list, selected builder, source references, and staging changeset without mutating WordPress.
6. Call `sitepilot.execute_site_build` to create drafts. Read every returned post ID and preview URL through `sitepilot.get_site_build_status`.
7. Complete responsive/browser quality checks and have the site owner review the previews.
8. Call `sitepilot.publish_site_build`. Publishing is Tier 2 and pauses until the exact publish changeset is approved in WordPress.
9. After approval, retry publication with the approval ID and a new idempotency key, then verify the durable build status and live pages.
10. To undo the build, use `sitepilot.rollback_change` for the publish changeset and then for the staging changeset, in that order.

The Agent accepts image and PDF attachments inline up to 20 MiB. HTML, CSS, JavaScript, and ZIP attachments use resumable 5 MiB chunks, have a 100 MiB total limit, are SHA-256 bound, and cannot be used until artifact validation reports `validated`.

### Calibrate and test Enfold ALB

For the complete Enfold capability matrix, compiler modes, optional edit-verification flow, and error guide, see the dedicated [Enfold Advanced Layout Builder guide](./enfold.md).

SitePilot deliberately does not guess or copy private Enfold metadata. Complete this after installing `0.4.13`, and again after changing the Enfold parent/child theme or Enfold version. SitePilot loads Enfold's native builder framework after theme setup, uses the public builder instance APIs introduced by current Enfold releases, completes late initialization when the first REST or MCP request runs after WordPress `init`, and runs Enfold's normal ALB normalization and metadata save stages for each staged draft.

After Enfold normalizes the clean data, SitePilot calls Enfold's own `ShortcodeHelper::build_shortcode_tree()` for that page's normalized content and persists the result before verification. This retains nested composite elements such as individual `av_toggle` accordion items without copying calibration metadata or constructing serialized trees itself.

1. In WordPress, manually create a small draft page with the Advanced Layout Builder. Use only a simple section, column, heading, and text block, then save it.
2. Create a second normal WordPress draft page without ALB. Do not reuse the same page.
3. Record both numeric page IDs.
4. Inspect the site and plan one `design.calibrate_enfold` action with `alb_page_id` and `normal_page_id`. This is Tier 3 and requires a fresh administrator approval.
5. Execute the approved calibration and inspect the site again. Calibration verifies that Enfold's native ALB save, shortcode-tree, and element-state APIs are available; it stores field names and compatibility evidence, never metadata values from the sample page. Continue only when `builders.enfold.active`, `alb_available`, and `profile_verified` are all `true`.
6. Plan and execute one Enfold draft containing allowed `av_*` shortcodes. Open its preview and confirm it remains editable in ALB.
7. Roll back that staging change and confirm the test draft or prior snapshot is fully restored before planning the complete site.

Enfold imports accept ALB shortcodes, not a complete HTML document. Malformed or unregistered shortcodes, scripts, PHP, iframes, local paths, and `.html` references are rejected. Registered Enfold `av_*` elements, including `av_contact` and `av_contact_field`, are accepted. Every staged page is persisted through Enfold's native save pipeline so clean data, shortcode tree, element state, parser state, and related metadata are generated from that page's own `alb_content`. Validation fails when these values do not describe the same elements. Every imported page is forced to `draft`; publishing and menu activation remain a separate Tier-2 approval. Page parents are staged before children, internal `sitepilot://page/{slug}` references are resolved only after all draft IDs exist, and template values must be registered template filenames rather than display labels.

### Compile HTML into native Enfold elements

Use `design.compile_enfold_html` when the source is an HTML/CSS design rather than existing ALB shortcodes. The Tier-1 operation compiles bounded, untrusted input into native, separately editable Enfold elements and then hands the result to the unchanged `design.stage` native save pipeline. It never fetches assets, uploads media, evaluates scripts, publishes a page, changes a menu, or silently uses a Code Block.

Required input is `source_html`. Optional input includes `source_css`, `media_mappings`, `link_mappings`, `component_mappings`, `post_name`, `parent_slug`, `page_template`, `menu_order`, and `featured_media_id`. Every image and CSS background image must map its exact source value to an existing Media Library attachment ID. Component mappings use simple selectors such as `.hero`, `.trip-grid`, or `#faq` and values such as `hero`, `grid`, `cards`, `gallery`, `accordion`, or `tabs`. Add `"structure_mode": "preserve"` when nested semantic wrappers are required by scoped CSS; SitePilot retains that bounded group in one safe Text Block instead of flattening it into invalid nested Enfold columns.

Example action:

```json
{
  "operation": "design.compile_enfold_html",
  "target": "group-trips",
  "input": {
    "source_html": "<section class=\"hero\"><h1>Group trips</h1><a href=\"/trips\">Explore</a></section>",
    "source_css": ".hero{background-image:url('hero.jpg')}",
    "media_mappings": {
      "hero.jpg": { "attachment_id": 890, "alt": "Travellers" }
    },
    "post_title": "Group trips",
    "post_name": "group-trips"
  }
}
```

The dry-run and execution result include `source_hash`, `alb_hash`, `fallback_used: false`, and source/generated coverage for sections, layout groups, cards, headings, text blocks, images, buttons/links, form fields, galleries, and visible-text fragments. They also list unsupported elements, intentionally omitted executable/style nodes, external assets, malformed assets, missing visible text, and CSS features that need a custom ALB element or separately reviewed stylesheet. Safe coverage gaps remain visible in the actionable manifest instead of becoming an all-or-nothing visual import gate.

Browser screenshot comparison is optional verification for `design.edit_elements`, not a compiler or publication gate. A configured orchestration service may request it with `visual_verification: true` (and optionally `visual_similarity_threshold`, default `0.85`). A failed or unavailable comparison never changes the write or publication decision.

### Build and edit Elementor pages

Elementor support is read-first. Call `sitepilot.inspect_design` before planning any Elementor mutation: element ids and widget setting keys are site-specific, and the tool returns the page's element outline, the registered widget catalog with each widget's accepted setting keys, and the global colour and typography kit.

| Operation | Tier | Use it for |
| --- | --- | --- |
| `design.stage` with `builder: "elementor"` | 1 | Staging a draft from a complete supplied `document` (plus optional `settings`). |
| `design.compile_elementor_html` | 1 | Compiling bounded HTML/CSS into native containers and widgets. |
| `design.edit_elements` | 1 | Changing, inserting, moving, removing, duplicating or labelling individual elements on an existing page. |
| `design.save_template` / `design.apply_template` | 1 | Saving a page as a reusable `elementor_library` template and merging it into other pages. |
| `design.update_global_kit` | 2 | Changing global colours and typography. Requires a fresh approval. |
| `design.stage_theme_document` | 2 | Staging a header, footer, single, archive, search-results or 404 theme-builder document as a draft. Requires a fresh approval. |

An Elementor `document` is an array of elements, each `{ id, elType, settings, elements }`, with `widgetType` on widgets. SitePilot constructs only classic `container`, `section`, `column`, and `widget` elements. Stored mixed Elementor 3/4 documents inspect per region: runtime-discovered atomic elements are readable and may update only existing type-preserving settings, while unknown regions are read-only. Atomic insertion, structural edits, and Global Classes/Variables writes remain unsupported and fail closed.

`design.edit_elements` applies an ordered `edits` list atomically — if any edit fails, the page is untouched and the error names the failing index. Its dry run returns `preview_url`, complete before/after outlines, and a compact `outline_diff`. Widget settings are validated against the widget's own registered controls before anything is written, so an unknown setting key is rejected during planning rather than silently persisted. Enfold reparses clean data immediately before serialization and returns `sitepilot_enfold_edit_conflict` without writing if Avia changed the document in that window.

`design.compile_elementor_html` takes the same bounded source contract as the Enfold compiler: `source_html`, `source_css`, `media_mappings`, `link_mappings` and `component_mappings`. Every image and CSS background needs a Media Library mapping, every internal link needs `sitepilot://page/<target-slug>`, and a compilation that would flatten or drop source structure fails with `sitepilot_elementor_design_coverage_failed`. There is no opaque HTML-widget fallback.

Every Elementor mutation snapshots the full post record and all Elementor metadata before writing, clears Elementor's rendered-element cache, and regenerates the page CSS. Publishing remains a separate Tier-2 `content.publish` change set.

For the complete capability matrix, document shape, edit operations, error guide, and verification steps, see the dedicated [Elementor guide](./elementor.md).

## 7. Artifact uploads

`sitepilot.manage_artifact` supports resumable uploads:

1. `start` with a filename and allowed MIME type.
2. `append` base64 data at the exact returned offset. Each direct-plugin chunk is limited to 5 MiB.
3. `complete` with the SHA-256 of the full file.
4. `status` to retrieve metadata and state.

Allowed MIME types are ZIP, PDF, JPEG, PNG, WebP, HTML, CSS, and JavaScript. Artifacts are stored in a private plugin-owned staging directory and removed after 30 days, or immediately when a WordPress privacy erasure request removes the user’s SitePilot data.

For a validated JPEG, PNG, WebP, or PDF supplied by a configured orchestration service, use its media-import flow instead of embedding binary data in change-set JSON. WordPress revalidates the exact origin, SHA-256, MIME type, extension, size, and image metadata before creating an attachment.

## 8. WordPress admin guide

Open **SitePilot** in the WordPress admin menu.

| Tab | What to do there |
| --- | --- |
| Status | Copy the direct endpoint and site version; confirm environment status |
| Connections | Review OAuth clients and revoke an active grant |
| Permissions | Review the available OAuth scopes and who may grant them |
| Approvals | Separate awaiting, approved, rollback-available, and archived records; approve, cancel, or revoke where allowed |
| Change History | Review audit events, actors, timestamps, and results |
| Security | Review optional-service access; permanent deletion remains off by default |
| Diagnostics | Check WordPress, PHP, HTTPS, Abilities API, and MCP Adapter availability |

Use the WordPress Connections tab to revoke a client immediately. Revocation also invalidates its access and refresh tokens.

Open **SitePilot → Playbooks** to edit the three seeded operating playbooks or add a site-specific one. Connected MCP clients see only playbook names and descriptions until they request a body. Retrieved bodies are bounded plain text labelled as untrusted site-authored instruction; editing a playbook never grants a credential scope or approves a change.

## 9. Safety rules and things SitePilot will refuse

SitePilot intentionally refuses raw PHP, raw SQL, shell commands, WP-CLI, direct database access, server secrets, `wp-config.php` changes, WordPress-root deletion, and arbitrary plugin code generation.

Normal content deletion moves a post to the WordPress trash and can be rolled back. Permanent deletion is disabled by default. Enabling it under **Security** does not remove the safeguards: it still requires one target, one Tier-3 change set, a fresh administrator approval, and has no rollback.

The active SitePilot plugin, audit subsystem, and active recovery records cannot be disabled or removed through SitePilot.

## 10. Privacy and retention

- The plugin does not store a WordPress password.
- Local OAuth access and refresh tokens are stored as hashes, not in plaintext.
- Staged artifacts are retained for 30 days; audit and security metadata are retained for 180 days.
- WordPress personal-data erasure removes a user’s grants and artifacts immediately while retaining minimal security-accountability metadata.
- Uninstall removes SitePilot tables, options, capabilities, and staged files.

Read the external-services disclosure in [plugins/sitepilot-mcp/readme.txt](../readme.txt) before enabling Cloud, OpenAI, or private updates. The WordPress.org distribution/update channel is not used.

## 11. Troubleshooting

### The plugin displays an environment error

Open **SitePilot → Diagnostics**. Verify WordPress 6.9+, PHP 8.2+, HTTPS, a single-site installation, and the Abilities API. Reinstall the complete plugin ZIP if the MCP Adapter is missing.

### The client cannot discover or connect

Confirm that the MCP endpoint and both root `.well-known` OAuth documents are reachable over HTTPS. Check the exact redirect URI registered by the client; even a trailing-slash mismatch is rejected. For a native client, use PKCE S256.

### A request says the site version changed

Call `sitepilot.inspect_site` again and build a new plan with the new `site_version`. SitePilot does this to avoid applying a diff against an out-of-date site.

### A change is waiting for approval

Open **SitePilot → Approvals**, verify the intent and diff, then approve. The approval lasts 30 minutes and is valid only for that exact change set. It moves to **Approved** until execution; completed reversible work appears under **Rollback available**. You may revoke an unused approval or cancel an unexecuted change set, and both actions remain audited. Expired, cancelled, and rolled-back records remain under **Expired / archived** instead of disappearing. Execution may use the returned approval ID, or SitePilot may securely resolve the newest valid approval bound to the exact change set and input hash when an MCP client omits the ID.

### A rollback is unavailable

The operation is intentionally irreversible or has no safe recovery data. This is expected for permanent deletion, processed refunds, and core updates. Restore from the verified external backup where applicable.

### Enfold is active but unavailable to the site builder

Inspect `builders.enfold`. If `alb_available` is false, confirm the Advanced Layout Builder shortcodes load on the active Enfold parent or child theme. If `profile_verified` is false, repeat the two-page calibration above. A theme or Enfold version change invalidates the profile intentionally; do not bypass this check or copy guessed metadata from another site.

## 12. Updates and support

Private-beta updates are disabled until the site owner configures both an HTTPS update manifest URL and a Base64 Ed25519 public key in private WordPress configuration. The updater verifies the signed manifest and the ZIP SHA-256 before WordPress installs anything.

For production rollout requirements, current gates, and known limits, see [release-gates.md](https://docs.sitepilot.tools/release-gates/). Report a suspected security issue privately to the project owner; do not publish credentials, tokens, site content, or exploit details in an issue.
