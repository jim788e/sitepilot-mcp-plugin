# SitePilot MCP API & Tool Reference

> **Definitive Technical Reference for Model Context Protocol (MCP), REST Endpoints, and Guarded WordPress Operations**  
> **Specification Version**: `1.0.0` | **Plugin Version**: `0.4.16`

---

## Table of Contents

1. [Protocol Overview & Envelope Specification](#1-protocol-overview--envelope-specification)
   - [Standard Mutation Envelope](#standard-mutation-envelope)
   - [Idempotency & Version Digest Guarantees](#idempotency--version-digest-guarantees)
2. [Risk Governance & Scopes](#2-risk-governance--scopes)
   - [4-Tier Classification Matrix](#4-tier-classification-matrix)
   - [OAuth 2.1 Scope Taxonomy](#oauth-21-scope-taxonomy)
   - [Approval Lifecycle & Cryptographic Binding](#approval-lifecycle--cryptographic-binding)
3. [MCP Tool Catalog](#3-mcp-tool-catalog)
   - [`sitepilot/inspect-site`](#sitepilotinspect-site)
   - [`sitepilot/search-capabilities`](#sitepilotsearch-capabilities)
   - [`sitepilot/describe-operations`](#sitepilotdescribe-operations)
   - [`sitepilot/inspect-navigation`](#sitepilotinspect-navigation)
   - [`sitepilot/inspect-design`](#sitepilotinspect-design)
   - [`sitepilot/plan-change`](#sitepilotplan-change)
   - [`sitepilot/execute-change`](#sitepilotexecute-change)
   - [`sitepilot/get-change-status`](#sitepilotget-change-status)
   - [`sitepilot/rollback-change`](#sitepilotrollback-change)
   - [`sitepilot/manage-artifact`](#sitepilotmanage-artifact)
4. [Guarded Operations Reference](#4-guarded-operations-reference)
   - [Content Operations](#content-operations)
   - [Media Operations](#media-operations)
   - [Elementor Builder Operations](#elementor-builder-operations)
   - [Enfold ALB Builder Operations](#enfold-alb-builder-operations)
   - [Navigation & Site Settings](#navigation--site-settings)
   - [WooCommerce Operations](#woocommerce-operations)
   - [System, Extension & Security Operations](#system-extension--security-operations)
5. [Elementor Document & Editor AST Contract](#5-elementor-document--editor-ast-contract)
6. [Enfold ALB Compiler & Calibration Contract](#6-enfold-alb-compiler--calibration-contract)
7. [Machine-Readable Error Codes](#7-machine-readable-error-codes)

---

## 1. Protocol Overview & Envelope Specification

SitePilot MCP implements the Model Context Protocol over Streamable HTTP (`/wp-json/sitepilot-mcp/v2/mcp`) and REST operation endpoints. The `/v1/mcp` route remains backward compatible, but clients should reconnect after plugin upgrades to refresh cached tool schemas. All mutating actions follow a guarded state machine with pre-mutation snapshots and ordered reverse compensation. WordPress does not provide a cross-table transaction over posts, metadata, terms, and extension storage; a partial rollback is `failed`, never `rolled_back`.

### Standard Mutation Envelope

All mutating requests (`sitepilot/plan-change`, `sitepilot/execute-change`, `sitepilot/rollback-change`, and mutating modes of `sitepilot/manage-artifact`) must include the following envelope parameters:

| Parameter | Type | Required | Constraints | Description |
| :--- | :--- | :---: | :--- | :--- |
| `contract_version` | `string` | **Yes** | Constant `"1.0.0"` | Schema version contract. Rejects unsupported major versions. |
| `idempotency_key` | `string` | **Yes** | Min 16, Max 128 chars | Cryptographically random unique key preventing duplicate execution. |
| `expected_version` | `string` | **Yes** | Min 16, Max 128 chars | Cryptographic site version digest obtained via `sitepilot/inspect-site`. |
| `site_id` | `string` (UUID) | Conditional | Valid UUIDv4 | Required when routing through Cloudflare Edge Gateway. |

```json
{
  "contract_version": "1.0.0",
  "idempotency_key": "c8a6f23d-49d1-4be1-91a5-e23f8b9101ab",
  "expected_version": "9f83c1e8...d42a",
  "site_id": "00000000-0000-0000-0000-000000000000"
}
```

### Idempotency & Version Digest Guarantees

- **Idempotency**: Execution results are cached in `wp_sitepilot_idempotency` for 24 hours. Re-sending an identical `idempotency_key` returns the cached response without re-executing actions. Reusing a key with a mismatched payload returns `sitepilot_idempotency_conflict` (`HTTP 409`).
- **Optimistic Locking**: Every mutation verifies `expected_version`. If a concurrent change alters WordPress core, themes, plugins, or published content, the site version changes and the request is rejected with `sitepilot_version_mismatch` (`HTTP 409`).

---

## 2. Risk Governance & Scopes

### 4-Tier Classification Matrix

| Tier | Category | Approval Policy | Default Expiry | Description & Typical Actions |
| :--- | :--- | :--- | :---: | :--- |
| **Tier 0** | **Read-Only** | None (Automatic) | Immediate | Inspection, capability discovery, navigation analysis, design reading. |
| **Tier 1** | **Safe Staging** | Automatic within scope | Immediate | Draft creation, media staging, HTML/CSS compilation, template saving. |
| **Tier 2** | **Sensitive Mutation** | Standard Approval | 30 Minutes | Publishing content, trashing items, stock/price changes, global kit styles. |
| **Tier 3** | **High Risk** | Fresh Admin Approval | 30 Minutes | Theme/plugin management, WooCommerce refunds, core updates, role changes. |

### OAuth 2.1 Scope Taxonomy

| Scope | Risk Clearance | Grants Authority For |
| :--- | :---: | :--- |
| `site:read` | Tier 0 | Site inspection, navigation hierarchy, design element introspection, capability queries. |
| `content:write` | Tiers 1–3 | Staging drafts, bulk updates, content publishing, trashing, and permanent deletion. |
| `media:write` | Tier 1 | Media Library staging, base64 uploads, and artifact imports. |
| `design:write` | Tiers 1–3 | Enfold ALB & Elementor staging, compilation, element edits, templates, global kits, menus. |
| `commerce:write`| Tiers 2–3 | WooCommerce product catalog prices, inventory counts, order updates, and refunds. |
| `extensions:manage`| Tier 3 | Installing, activating, and updating plugins and themes. |
| `core:update` | Tier 3 | WordPress core software updates. |
| `users:write` | Tier 3 | User role assignments and privilege management. |
| `security:manage` | Tier 3 | Security policy configuration and access settings. |

### Approval Lifecycle & Cryptographic Binding

When a planned change set requires approval (Tier 2 or 3):
1. A deterministic SHA-256 hash of the normalized actions array is generated.
2. An approval record is created in `wp_sitepilot_approvals` in state `pending`.
3. The WordPress user reviews the diff and approves or rejects the exact hash.
4. Approval tokens are single-use and expire after 30 minutes. Modifying planned actions invalidates the hash.

---

## 3. MCP Tool Catalog

### `sitepilot/inspect-site`

Inspects core WordPress runtime environment, active theme, builder support, WooCommerce configuration, and current cryptographic site version digest.

- **Risk Tier**: `Tier 0` (Read-Only)
- **Required Scope**: `site:read`
- **Annotations**: `readonly: true`, `destructive: false`, `idempotent: true`
- **Input Schema**:
  ```json
  {
    "type": "object",
    "properties": {},
    "additionalProperties": false
  }
  ```

#### Response Example:
```json
{
  "site_version": "a4f89d31e8c9b2075f11e4d89a2b5c6e7f8a9b0c",
  "wordpress": {
    "version": "6.9.1",
    "multisite": false,
    "environment_type": "production",
    "site_url": "https://example.com",
    "home_url": "https://example.com"
  },
  "theme": {
    "name": "Enfold",
    "version": "7.1.6",
    "is_child_theme": false,
    "template": "enfold"
  },
  "builders": {
    "gutenberg": true,
    "elementor": false,
    "enfold": {
      "active": true,
      "alb_available": true,
      "profile_verified": true
    }
  },
  "woocommerce": {
    "active": true,
    "version": "9.4.0",
    "hpos_enabled": true
  }
}
```

---

### `sitepilot/search-capabilities`

Searches registered WordPress abilities and allowlisted REST API routes matching a keyword.

- **Risk Tier**: `Tier 0`
- **Required Scope**: `site:read`
- **Input Schema**:
  | Property | Type | Required | Constraints | Description |
  | :--- | :--- | :---: | :--- | :--- |
  | `query` | `string` | No | Max length 200 | Search keyword filter. |

#### Request Example:
```json
{
  "query": "elementor"
}
```

#### Response Example:
```json
{
  "capabilities": [
    {
      "name": "sitepilot/inspect-design",
      "label": "Inspect design",
      "description": "Returns a page-builder page structure and builder capabilities.",
      "category": "sitepilot",
      "scope": "site:read"
    },
    {
      "name": "design.compile_elementor_html",
      "label": "Compile Elementor HTML",
      "description": "Compiles bounded HTML/CSS into native Elementor container widgets.",
      "category": "sitepilot",
      "scope": "design:write"
    }
  ]
}
```

---

### `sitepilot/describe-operations`

Lists every guarded write operation with its OAuth scope, risk tier, target semantics, and accepted input schemas.

- **Risk Tier**: `Tier 0`
- **Required Scope**: `site:read`
- **Input Schema**:
  | Property | Type | Required | Description |
  | :--- | :--- | :---: | :--- |
  | `operation` | `string` | No | Specific operation name. When omitted, returns full catalog. |

#### Response Example:
```json
{
  "operations": {
    "content.create_draft": {
      "risk_tier": 1,
      "scope": "content:write",
      "target": "Stable label for the new draft.",
      "input_fields": ["post_type", "post_title", "post_content", "post_excerpt", "post_name", "post_parent", "parent_slug", "menu_order", "page_template", "featured_media_id"],
      "notes": "Creates a draft only."
    }
  }
}
```

---

### `sitepilot/inspect-navigation`

Returns registered theme navigation locations, assigned navigation menus, and hierarchical menu item trees.

- **Risk Tier**: `Tier 0`
- **Required Scope**: `site:read`
- **Input Schema**: `{}` (Empty object)

#### Response Example:
```json
{
  "locations": {
    "primary": {
      "label": "Main Navigation",
      "assigned_menu_id": 4,
      "assigned_menu_name": "Header Menu"
    }
  },
  "menus": [
    {
      "id": 4,
      "name": "Header Menu",
      "slug": "header-menu",
      "items": [
        {
          "id": 101,
          "title": "Home",
          "url": "/",
          "type": "custom",
          "menu_order": 1,
          "parent_id": 0
        }
      ]
    }
  ]
}
```

---

### `sitepilot/inspect-design`

Inspects builder-specific structures, registered elements, global styles, and page settings for **Elementor** and **Enfold**. This is a read-only operation.

- **Risk Tier**: `Tier 0`
- **Required Scope**: `site:read`
- **Input Schema**:
  | Property | Type | Required | Default | Description |
  | :--- | :--- | :---: | :---: | :--- |
  | `builder` | `string` | No | `"elementor"` | Builder identifier (`"elementor"` or `"enfold"`). |
  | `target` | `integer` | No | `0` | Numeric page ID. When omitted or `0`, returns site-wide builder capabilities only. |
  | `widget_type` | `string` | No | `null` | Elementor only: filter the widget catalog and retrieve its full control dictionary. |
  | `include` | `array<string>` | No | All | Subset of `["tree", "widgets", "elements", "globals", "page_settings"]`; unsupported sections are ignored for the selected builder. |

#### Request Example:
```json
{
  "builder": "elementor",
  "target": 42,
  "include": ["tree", "globals"]
}
```

```json
{
  "builder": "enfold",
  "target": 84,
  "include": ["tree", "elements", "globals", "page_settings"]
}
```

#### Response Structure:
- `page.outline`: A bounded hierarchy. Elementor nodes expose ids, element/widget types, settings, and children. Enfold nodes expose `uid`, current-snapshot `path`, shortcode `tag`, registry type, attributes, truncated direct text, and children; parser-private round-trip metadata is never returned.
- `widgets`: Elementor's registered widgets and accepted controls.
- `elements`: Enfold's runtime-registered shortcode set, registry definitions (`parents`, `attributes`, `drag_level`, type, and `support`), and version fingerprint. `support` is `curated` when SitePilot has a construction contract and `runtime_only` when the element is discoverable for existing-page reads but is not safe to construct from the advertised common attributes alone.
- `globals`: Elementor kit palettes/typography or Enfold's scalar colour/typography theme options. Enfold inspection filters out unrelated options and secrets.
- `page_settings`: Elementor document settings or Enfold page template, ALB active/clean-data flags, calibration status, and UID/path identity scheme.

For an Enfold target without active ALB clean data, the default request succeeds, omits `page.outline`, and reports `page_settings.alb_active: false`. Supplying `include: ["tree"]` explicitly still fails closed with `sitepilot_enfold_document_empty`.

---

### `sitepilot/plan-change`

Creates an immutable dry-run change set, validates all action schemas, calculates the composite risk tier, and generates pre-mutation diffs.

- **Risk Tier**: `Tier 0` (Planning is non-destructive)
- **Required Scope**: Consented scope for every planned action
- **Input Schema**:
  | Property | Type | Required | Constraints | Description |
  | :--- | :--- | :---: | :--- | :--- |
  | `contract_version` | `string` | **Yes** | `"1.0.0"` | Contract version. |
  | `idempotency_key` | `string` | **Yes** | Min 16 chars | Execution lock key. |
  | `expected_version` | `string` | **Yes** | Site digest | Optimistic concurrency check. |
  | `intent` | `string` | **Yes** | Max 4000 chars | Human-readable explanation of the plan. |
  | `actions` | `array<Action>` | **Yes** | 1 to 250 items | Array of atomic operation objects. |

#### Action Object Structure:
```json
{
  "operation": "design.compile_elementor_html",
  "target": "page:about-us",
  "input": {
    "source_html": "<section class=\"hero\"><h1>About Us</h1></section>",
    "source_css": ".hero { padding: 40px; }",
    "post_title": "About Us",
    "post_name": "about-us"
  }
}
```

#### Response Example:
```json
{
  "change_set_id": "7b6a5c4d-3e2f-1a0b-9c8d-7e6f5a4b3c2d",
  "risk_tier": 1,
  "status": "planned",
  "approval_state": "not_required",
  "diff": [
    {
      "operation": "design.compile_elementor_html",
      "target": "page:about-us",
      "action_summary": "Compile HTML/CSS into Elementor draft 'About Us'"
    }
  ],
  "rollback_available": true,
  "site_version": "a4f89d31e8c9b2075f11e4d89a2b5c6e7f8a9b0c"
}
```

---

### `sitepilot/execute-change`

Executes a planned change set against the WordPress database and builders.

- **Risk Tier**: Dynamic (Matches highest tier in change set: 1, 2, or 3)
- **Input Schema**:
  | Property | Type | Required | Description |
  | :--- | :--- | :---: | :--- |
  | `contract_version` | `string` | **Yes** | `"1.0.0"` |
  | `idempotency_key` | `string` | **Yes** | Execution lock key. |
  | `expected_version` | `string` | **Yes** | Optimistic locking digest. |
  | `change_set_id` | `string` (UUID) | **Yes** | Planned change set ID. |
  | `approval_id` | `string` (UUID) | Conditional | Required if `risk_tier >= 2`. |

#### Response Example:
```json
{
  "change_set_id": "7b6a5c4d-3e2f-1a0b-9c8d-7e6f5a4b3c2d",
  "status": "completed",
  "executed_at": "2026-08-16T21:30:00Z",
  "results": [
    {
      "operation": "design.compile_elementor_html",
      "target": "page:about-us",
      "post_id": 84,
      "preview_url": "https://example.com/?p=84&preview=true",
      "status": "draft"
    }
  ],
  "site_version": "f3b2a1098e7d6c5b4a3f2e1d0c9b8a7f6e5d4c3b"
}
```

---

### `sitepilot/get-change-status`

Retrieves real-time status, validation results, diff summary, and rollback availability for a change set.

- **Risk Tier**: `Tier 0`
- **Required Scope**: `site:read`
- **Input Schema**:
  | Property | Type | Required | Description |
  | :--- | :--- | :---: | :--- |
  | `change_set_id` | `string` (UUID) | **Yes** | Target change set ID. |

---

### `sitepilot/rollback-change`

Deterministically reverses a completed change set by restoring pre-mutation database and builder snapshots.

- **Risk Tier**: Dynamic (Matches original change set tier)
- **Input Schema**:
  | Property | Type | Required | Description |
  | :--- | :--- | :---: | :--- |
  | `contract_version` | `string` | **Yes** | `"1.0.0"` |
  | `idempotency_key` | `string` | **Yes** | Execution lock key. |
  | `expected_version` | `string` | **Yes** | Current site version digest. |
  | `change_set_id` | `string` (UUID) | **Yes** | Target completed change set ID to revert. |
  | `approval_id` | `string` (UUID) | Conditional | Required for Tier 2/3 rollbacks. |

---

### `sitepilot/manage-artifact`

Manages staged design assets, media archives, and compilation artifacts.

- **Risk Tier**: `Tier 1`
- **Required Scope**: `design:write`
- **Commands**:
  - `start`: Initializes an artifact session.
  - `status`: Checks upload and validation progress.
  - `verify`: Triggers SHA-256 and MIME integrity validation.
  - `abort`: Cancels and discards uncommitted artifacts.

---

## 4. Guarded Operations Reference

### Content Operations

#### `content.create_draft`
- **Tier**: `Tier 1` | **Scope**: `content:write`
- **Target**: Stable draft label (e.g. `page:contact`).
- **Fields**: `post_type`, `post_title`, `post_content`, `post_excerpt`, `post_name`, `post_parent`, `parent_slug`, `menu_order`, `page_template`, `featured_media_id`.

#### `content.publish`
- **Tier**: `Tier 2` | **Scope**: `content:write` | **Approval Required**
- **Target**: Numeric post/page ID.
- **Fields**: `post_id`. Changes post status from `draft`/`pending` to `publish`.

#### `content.bulk_update`
- **Tier**: `Tier 2` | **Scope**: `content:write` | **Approval Required**
- **Target**: Numeric post/page ID.
- **Fields**: Any subset of `post_title`, `post_content`, `post_excerpt`, `post_name`, `post_parent`, `menu_order`, `page_template`, `featured_media_id`.

#### `content.trash`
- **Tier**: `Tier 2` | **Scope**: `content:write` | **Approval Required**
- **Target**: Numeric post ID. Moves item to WordPress Trash.

#### `content.permanent_delete`
- **Tier**: `Tier 3` | **Scope**: `content:write` | **Admin Approval Required**
- **Target**: Numeric post ID in Trash. Permanently removes row and metadata.

---

### Media Operations

#### `media.stage`
- **Tier**: `Tier 1` | **Scope**: `media:write`
- **Target**: Stable media label or filename.
- **Fields**: `filename`, `mime_type` (`image/jpeg`, `image/png`, `image/webp`, `application/pdf`), `content_base64` (max 25 MB), `title`, `alt`, `caption`, `description`.

#### `media.import_artifact`
- **Tier**: `Tier 1` | **Scope**: `media:write`
- **Target**: Stable media label.
- **Fields**: `source_url`, `filename`, `sha256`, `alt`, `title`.
- **Standalone behavior**: Refuses with `sitepilot_media_import_unavailable` unless explicit opt-in and an exact HTTPS external-service origin are configured. Use `media.stage` for a direct upload.

---

### Elementor Builder Operations

#### `design.compile_elementor_html`
- **Tier**: `Tier 1` | **Scope**: `design:write`
- **Target**: `0` or stable draft label for new page; numeric ID to update draft.
- **Input Fields**:
  | Field | Type | Required | Description |
  | :--- | :--- | :---: | :--- |
  | `source_html` | `string` | **Yes** | Bounded HTML markup (max 2 MB). |
  | `source_css` | `string` | No | Accompanying CSS rules (max 500 KB). |
  | `media_mappings` | `object` | No | Map of image src paths to Media Library `attachment_id`s. |
  | `link_mappings` | `object` | No | Map of hrefs to canonical URLs or `sitepilot://page/<slug>`. |
  | `post_title` | `string` | **Yes** | Title of staged page. |
  | `post_name` | `string` | No | URL slug. |

#### `design.edit_elements`
- **Tier**: `Tier 1` | **Scope**: `design:write`
- **Target**: Numeric page ID containing an Elementor document or calibrated Enfold ALB draft.
- **Input Fields**:
  | Field | Type | Required | Description |
  | :--- | :--- | :---: | :--- |
  | `builder` | `string` | No | `elementor` (default) or `enfold`. |
  | `edits` | `array<Edit>` | **Yes** | Array of 1–100 ordered element operations. |
  | `visual_verification` | `boolean` | No | Default `false`. Without a configured external service it returns `status: unavailable`, `reason: no_gateway_configured`, and does not block writing or publication. |
  | `visual_similarity_threshold` | `float` | No | Default `0.85` (Range `0.50`–`1.00`), used only by optional edit verification. |

The dry run returns the exact draft `preview_url`, full outlines, and an `outline_diff` summary with added, removed, modified, and (for Enfold UID identities) moved nodes.

#### `design.save_template`
- **Tier**: `Tier 1` | **Scope**: `design:write`
- **Target**: Stable template label.
- **Fields**: `source_post_id`, `title`, `template_type` (`page`, `section`, `container`).

#### `design.apply_template`
- **Tier**: `Tier 1` | **Scope**: `design:write`
- **Target**: Numeric page ID.
- **Fields**: `template_id`, `mode` (`"append"` or `"replace"`). Automatically regenerates element IDs.

#### `design.update_global_kit`
- **Tier**: `Tier 2` | **Scope**: `design:write` | **Approval Required**
- **Target**: Elementor kit post ID (`0` for active kit).
- **Fields**: `settings` (Accepts `system_colors`, `custom_colors`, `system_typography`, `custom_typography`).
- **Elementor 4 boundary**: Atomic Global Classes and Variables use separate stores. SitePilot does not write them and returns `atomic_global_styles_unsupported` without mutation.

#### `design.stage_theme_document`
- **Tier**: `Tier 2` | **Scope**: `design:write` | **Approval Required**
- **Target**: `0` or theme document ID.
- **Fields**: `document_type` (`header`, `footer`, `single`, `archive`, `search-results`, `error-404`), `title`, `document`.

---

### Enfold ALB Builder Operations

#### `design.compile_enfold_html`
- **Tier**: `Tier 1` | **Scope**: `design:write`
- **Target**: `0` or stable draft label.
- **Contract**: Creates a best-effort native first draft from clean static HTML. It returns `compiled`, detailed `coverage` (including `ratio` and `status`), and an actionable `unmapped` manifest; coverage gaps do not reject an otherwise safe draft.
- **Input Fields**:
  | Field | Type | Required | Description |
  | :--- | :--- | :---: | :--- |
  | `source_html` | `string` | **Yes** | Clean HTML markup (max 2 MB). |
  | `source_css` | `string` | No | CSS stylesheet (max 500 KB). |
  | `media_mappings` | `object` | No | Map of `img src` to attachment IDs. |
  | `link_mappings` | `object` | No | Map of `href` to `sitepilot://page/<slug>`. |
  | `component_mappings` | `object` | No | Authoritative mapping of CSS selectors to ALB elements (e.g. `{ ".card-grid": "cards" }`). Mapped tabs may set `target_attribute` for project-specific `data-*` panel references. |

#### `design.calibrate_enfold`
- **Tier**: `Tier 3` | **Scope**: `design:write` | **Admin Approval Required**
- **Target**: `"enfold-profile"`.
- **Fields**: `alb_page_id` (ALB draft post ID), `normal_page_id` (Standard page post ID).

#### `design.resolve_links`
- **Tier**: `Tier 1` | **Scope**: `design:write`
- **Target**: Slug of staged Enfold page.
- **Fields**: `page_slugs` (Array of internal slugs to link).

#### `design.set_element_style`
- **Tier**: `Tier 1` | **Scope**: `design:write`
- **Target**: Existing numeric calibrated Enfold ALB draft page ID.
- **Fields**: `builder` (`"enfold"`), `custom_class` (one class token used by exactly one element), `styles` (allowlisted property/value pairs), `replace` (boolean).
- **Safety boundary**: Stores protected page-scoped CSS only. URL-bearing values, at-rules, selector boundaries, executable protocols, ambiguous classes, missing classes, published posts, and non-page targets fail closed. A `null` style value removes the corresponding protected property, while an empty string is rejected; rollback restores the prior page metadata exactly.

---

### Navigation & Site Settings

#### `site.create_menu`
- **Tier**: `Tier 1` | **Scope**: `design:write`
- **Target**: Stable menu label.
- **Fields**: `name` (Menu display title).

#### `site.update_menu`
- **Tier**: `Tier 2` | **Scope**: `design:write` | **Approval Required**
- **Target**: Numeric menu item ID (`0` to create).
- **Fields**: `menu_id`, `menu_name`, `title`, `url`, `object_id`, `parent_id`, `position`.

#### `site.assign_menu`
- **Tier**: `Tier 2` | **Scope**: `design:write` | **Approval Required**
- **Target**: Registered theme menu location.
- **Fields**: `location`, `menu_id`, `menu_name`.

#### `site.update_setting`
- **Tier**: `Tier 2` | **Scope**: `design:write` | **Approval Required**
- **Target**: Allowlisted option name (`blogname`, `blogdescription`, `page_on_front`, `page_for_posts`).
- **Fields**: `value`.

---

### WooCommerce Operations

#### `commerce.update_price`
- **Tier**: `Tier 2` | **Scope**: `commerce:write` | **Approval Required**
- **Target**: Product or variation ID.
- **Fields**: `regular_price`, `sale_price` (optional).

#### `commerce.update_stock`
- **Tier**: `Tier 2` | **Scope**: `commerce:write` | **Approval Required**
- **Target**: Product ID.
- **Fields**: `stock_quantity`, `stock_status` (`instock`, `outofstock`, `onbackorder`).

#### `commerce.update_order`
- **Tier**: `Tier 3` | **Scope**: `commerce:write` | **Admin Approval Required**
- **Target**: Order ID.
- **Fields**: `status` (`processing`, `completed`, `cancelled`, `on-hold`).

#### `commerce.refund`
- **Tier**: `Tier 3` | **Scope**: `commerce:write` | **Admin Approval Required**
- **Target**: Order ID.
- **Fields**: `amount`, `reason`, `restock_items` (boolean).

---

### System, Extension & Security Operations

#### `extension.install`
- **Tier**: `Tier 3` | **Scope**: `extensions:manage` | **Admin Approval Required**
- **Target**: Plugin/theme slug on WordPress.org.

#### `extension.activate` / `theme.activate`
- **Tier**: `Tier 3` | **Scope**: `extensions:manage` | **Admin Approval Required**
- **Target**: Installed plugin file (e.g. `woocommerce/woocommerce.php`) or theme stylesheet.

#### `core.update`
- **Tier**: `Tier 3` | **Scope**: `core:update` | **Admin Approval Required**
- **Target**: Target core version string.

#### `user.change_role`
- **Tier**: `Tier 3` | **Scope**: `users:write` | **Admin Approval Required**
- **Target**: Numeric user ID.
- **Fields**: `role` (`subscriber`, `contributor`, `author`, `editor`, `sitepilot_operator`, `administrator`).

#### `security.update_setting`
- **Tier**: `Tier 3` | **Scope**: `security:manage` | **Admin Approval Required**
- **Target**: Allowlisted security preference key.

---

## 5. Elementor Document & Editor AST Contract

### Element Tree Node Schema

```json
{
  "id": "e8a12b3c",
  "elType": "container",
  "settings": {
    "content_width": "boxed",
    "background_background": "classic",
    "background_color": "#F8FAFC"
  },
  "elements": [
    {
      "id": "w9f01a2d",
      "elType": "widget",
      "widgetType": "heading",
      "settings": {
        "title": "Welcome to SitePilot",
        "header_size": "h1",
        "align": "center"
      },
      "elements": []
    }
  ]
}
```

### Granular Edit Operations (`design.edit_elements`)

| `op` | Required Fields | Optional Fields | Description |
| :--- | :--- | :--- | :--- |
| `set_settings` | `id`, `settings` | `replace` (`bool`) | Merges (or replaces if `replace: true`) widget/container settings. |
| `insert` | `element` | `parent_id`, `position`, `seed` | Inserts a new element. Auto-regenerates IDs to avoid collisions. |
| `move` | `id` | `parent_id`, `position` | Reorders or reparents an existing element within the document. |
| `remove` | `id` | None | Deletes an element and all child elements. |
| `duplicate` | `id` | `parent_id`, `position`, `seed` | Clones an element tree with freshly minted IDs. |
| `set_label` | `id`, `label` | None | Updates the element title/label displayed in the Elementor Navigator. |

---

## 6. Enfold ALB Compiler & Calibration Contract

### `EnfoldDocument` AST

All staged ALB content is parsed through the same bounded document model used by validation. A node contains `uid`, structural `path`, shortcode `tag`, registry-derived `type` (`layout`, `column`, or `content`), parsed `attrs`, direct `text`, and nested `children`. Canonical input must serialize byte for byte; Enfold-normalized input is equivalent after normalization-only UID assignment. Existing non-empty `av_uid` values survive the native save pipeline, so normalized UIDs are primary identities and paths are secondary/fallback identities.

### Calibration Profile
Enfold utilizes custom dynamic postmeta keys (`_aviaLayoutBuilder_active`, `_avia_builder_clean_char`, etc.). To establish safety without guessing:
1. `design.calibrate_enfold` performs an administrator-gated diff between an ALB post and a classic post.
2. Verified keys are cached in `wp_sitepilot_enfold_profile`.
3. Changes to active theme name or version invalidate the profile and require re-calibration.

### Structural Preservation (`structure_mode: preserve`)
When converting complex nested grids that cannot map directly to native ALB shortcodes:
- Set `"component_mappings": { ".custom-box": { "structure_mode": "preserve" } }`.
- Retains HTML structure safely inside an Enfold Text Block (`[av_textblock]`) without dropping DOM depth.

---

## 7. Machine-Readable Error Codes

| Error Code | HTTP Status | Root Cause & Meaning | Recommended Remediation |
| :--- | :---: | :--- | :--- |
| `sitepilot_unauthorized` | 401 | Missing, malformed, or expired OAuth 2.1 token. | Re-authenticate client via OAuth flow. |
| `sitepilot_insufficient_scope` | 403 | Token lacks required OAuth scope for the action. | Re-request consent with missing scope. |
| `sitepilot_approval_required` | 403 | Tier 2 or 3 mutation lacks unexpired approval. | Obtain approval hash from operator. |
| `sitepilot_version_mismatch` | 409 | `expected_version` does not match live site digest. | Call `sitepilot/inspect-site` and re-plan. |
| `sitepilot_idempotency_conflict` | 409 | `idempotency_key` reused with differing payload. | Generate fresh UUIDv4 idempotency key. |
| `sitepilot_operation_blocked` | 400 | Operation is unexposed or violates security policy. | Check `describe-operations` for valid ops. |
| `sitepilot_invalid_envelope` | 400 | Missing required envelope parameters. | Supply `contract_version`, keys, and version. |
| `sitepilot_enfold_profile_unverified` | 422 | Enfold theme active but lacks verified profile. | Run `design.calibrate_enfold` in WP Admin. |
| `sitepilot_enfold_source_unsupported` | 422 | Preflight found a script-driven, framework-heavy, oversized, deeply nested, custom-property-heavy, or asset-heavy source. | Inspect `reasons`; simplify the static source or use incremental element editing. |
| `sitepilot_enfold_background_layers_unsupported` | 422 | Multiple CSS background images on one element. | Separate background layers into nested divs. |
| `sitepilot_elementor_invalid_edit` | 422 | Target element ID not found or control key invalid. | Call `inspect-design` to refresh element IDs. |
| `sitepilot_elementor_control_invalid` | 422 | Setting key rejected by widget control whitelist. | Use control names listed in `inspect-design`. |
| `sitepilot_rollback_unavailable` | 422 | Change set has no snapshot or already rolled back. | Review `wp_sitepilot_changesets` record. |
