# Elementor support

SitePilot supports a guarded Elementor workflow with an explicit generation boundary: classic documents can be constructed and structurally edited; mixed Elementor 3/4 documents can be inspected without failing the whole page; existing atomic regions permit updates only to existing, type-preserving settings. SitePilot does not construct atomic elements or write Elementor 4 atomic Global Classes or Variables.

This guide describes behavior shipped through SitePilot MCP `0.4.16` and Elementor 3.20+. The verification environment pins Elementor 4.2.1. SitePilot checks Elementor's own APIs on every site rather than assuming a version is compatible.

## What SitePilot supports

| Capability | Support |
| --- | --- |
| Detect Elementor, Elementor Pro, and the active kit | Yes, through `inspect-site` |
| Read a page's element tree, ids and settings | Yes, through `inspect-design` |
| Discover registered widget types and their accepted setting keys | Yes, through `inspect-design` |
| Read global colours and typography | Yes, through `inspect-design` |
| Create or update draft Elementor pages from a supplied document | Yes, through `design.stage` |
| Edit, insert, move, remove, duplicate or label a single element | Yes, through `design.edit_elements` |
| HTML/CSS compilation to native Elementor containers and widgets | Yes, through `design.compile_elementor_html` |
| Media Library image mapping and reuse | Yes; images and CSS backgrounds must map to existing attachments |
| Save and apply reusable `elementor_library` templates | Yes, through `design.save_template` and `design.apply_template` |
| Classic global colour and typography changes | Yes on the classic kit model, Tier 2 approval |
| Theme-builder header, footer, single, archive, search and 404 documents | Yes, Tier 2 approval, draft only, through `design.stage_theme_document` |
| Draft-preview screenshot evidence | Yes through the optional Cloud gateway and Browser Rendering |
| Publish and rollback | Yes; publishing remains a separate Tier 2 `content.publish` change set |
| Elementor 4.x atomic elements (`e-flexbox`, `e-div-block`) | Mixed pages inspect successfully; existing known typed settings can update; insertion and structural edits are refused |
| Elementor 4 atomic Global Classes and Variables | Read boundary is reported; writes fail closed with `atomic_global_styles_unsupported` |
| Popups and display conditions | No — an administrator assigns these in Elementor |

SitePilot never publishes while staging or compiling. It never runs arbitrary PHP, SQL, shell, or WP-CLI, and never writes `_elementor_data` directly on the happy path: every write goes through Elementor's own `Document::save()`.

## Required preflight

1. Use a single-site WordPress 6.9+ installation over HTTPS with PHP 8.2+.
2. Activate Elementor and confirm the editor opens for an administrator.
3. Install and activate SitePilot MCP, then resolve every item under **SitePilot → Diagnostics**.
4. Connect the MCP client with `site:read`, `content:write`, `media:write`, and `design:write` scopes.
5. Call `inspect-site` and continue only when `builders.elementor` reports a version.

## Inspect before you write

`inspect-design` is the read-only entry point, and the only new MCP tool Elementor support adds. Call it before planning any element edit: element ids and widget setting keys are site-specific, and guessing them is how malformed documents reach Elementor.

```json
{ "builder": "elementor", "target": 31, "include": ["tree", "widgets", "globals"] }
```

It returns:

- `page.outline` — every element with its path and `full`, `known_settings_only`, or `read_only` editability.
- `page.unmapped_regions` — runtime-only or unknown regions that SitePilot preserves but cannot fully construct.
- `page.tree` — the full element tree as Elementor stores it.
- `widgets` — the registered widget catalog, bounded to 200 entries; pass `widget_type` to filter.
- `widget_controls` — when `widget_type` is given, the flattened control list with types, defaults and allowed options. These are the setting keys `design.edit_elements` will accept.
- `globals` — classic kit colours/typography plus the Elementor 4 atomic store names and whether writes are supported.

## The Elementor document shape

Every element is an object with four required keys, plus `widgetType` on widgets:

```json
[
  {
    "id": "a1b2c3d4",
    "elType": "container",
    "settings": { "content_width": "boxed" },
    "elements": [
      {
        "id": "e5f6a7b8",
        "elType": "widget",
        "widgetType": "heading",
        "settings": { "title": "Hello", "header_size": "h1" },
        "elements": []
      }
    ]
  }
]
```

New documents and inserted elements must use `container`, `section`, `column`, or `widget`. Stored mixed-version documents may also contain runtime-discovered atomic types and unknown future types. Runtime-only types are parseable but not constructible; unknown types are read-only. Ids match `^[A-Za-z0-9_-]{1,64}$`, and documents nest at most 12 levels. The published construction contract remains deliberately classic-only.

## Edit one element at a time

`design.edit_elements` (Tier 1) applies an ordered edit list to an existing page without rewriting the document. Edits apply atomically — if any edit fails, the page is untouched and the error names the failing index.

| `op` | Meaning |
| --- | --- |
| `set_settings` | Classic: merge settings, with widget controls validated. Runtime-only atomic: update existing keys only, preserve `$$type`, and never use `replace`. Unknown regions are read-only. |
| `insert` | Add `element` under `parent_id` at `position`. Ids are regenerated so they cannot collide. |
| `move` | Reparent or reorder `id`. Moving an element into its own subtree is rejected. |
| `remove` | Delete `id` and its subtree. Removing the last root element is rejected. |
| `duplicate` | Clone `id` next to itself with fresh ids throughout. |
| `set_label` | Set the element's editor label (`_title`). |

The dry run returns the exact draft `preview_url`, the before and after outlines, an `outline_diff`, and a per-edit change list, so a reviewer can see exactly which elements move before anything is written. Set `visual_verification: true` to ask the optional Cloud gateway for authenticated desktop/mobile before-and-after evidence. Its threshold result is advisory: it never blocks the edit or later publication.

## Compile HTML and CSS into Elementor

`design.compile_elementor_html` (Tier 1) turns bounded source HTML and CSS into separately editable native elements — never one opaque HTML widget. It accepts the same source contract as the Enfold compiler:

- `source_html` up to 2 MB, `source_css` up to 500 KB including any inline `<style>` blocks.
- `media_mappings` — every `<img>` and CSS background must map to an existing Media Library attachment id. Remote URLs are never fetched.
- `link_mappings` — every internal link must map to `sitepilot://page/<target-slug>`, resolved later by `design.resolve_links` with `builder: "elementor"`.
- `component_mappings` — optional `card`, `grid`, `gallery` and similar hints keyed by tag, `.class` or `#id`.

Layout compiles to containers; `h1`–`h6` become `heading`, `p` becomes `text-editor`, `img` becomes `image`, `a` and `button` become `button`, and `ul`/`ol` become `icon-list`. CSS maps onto element settings rather than a scoped stylesheet: typography, colours, padding and margin as Elementor's `{unit, top, right, bottom, left, isLinked}` objects, and flex direction, alignment and gap on containers.

`script`, `style`, `noscript`, `template` and inline `svg` are never compiled. `video`, `audio`, `canvas`, `iframe`, `object` and `embed` have no safe native mapping and fail coverage rather than disappearing.

**Coverage is enforced.** The compiler counts sections, layout groups, cards, headings, text blocks, images, buttons and every visible text fragment in the source, compares them with what it produced, and rejects the compilation with `sitepilot_elementor_design_coverage_failed` if anything was flattened or dropped. `allow_code_block_fallback` exists only to be refused.

Compilation is deterministic: identical source produces identical element ids and the same `document_sha256`.

## Visual acceptance before publishing

When `visual_verification: true` is set on an edit through the optional Cloud gateway, the existing Elementor draft is captured at 1440×1000 and 390×844 during planning and compared with the executed result. Elementor pages are anchored on their own `.elementor-<post_id>` wrapper. The returned similarity and artifacts are advisory evidence; they do not block `design.edit_elements` or `content.publish`.

## Global styles and theme documents

`design.update_global_kit` and `design.stage_theme_document` are **Tier 2**: they change how the whole site looks, so each requires a fresh, hash-bound approval that expires after 30 minutes.

- The classic kit update accepts only `system_colors`, `custom_colors`, `system_typography` and `custom_typography`. On Elementor 4, Global Classes and Variables live in `_elementor_global_classes*` and `_elementor_global_variables`; SitePilot returns `atomic_global_styles_unsupported` before touching any of those stores or `_elementor_page_settings`.
- Theme documents stage as **drafts** with the requested `_elementor_template_type`. SitePilot does not assign display conditions; an administrator applies them in Elementor. Popups are out of scope.

## Templates

`design.save_template` copies an existing page's tree into an `elementor_library` post without touching the source page. `design.apply_template` merges a library template into a target page, in `append` or `replace` mode. Element ids are regenerated on every application, so the same template can be applied to many pages without collisions.

## Rollback

Every Elementor mutation snapshots the full post record plus `_elementor_data`, `_elementor_edit_mode`, `_elementor_page_settings`, `_elementor_version`, `_elementor_template_type`, `_elementor_controls_usage`, `_elementor_element_cache` and the featured image before writing. Rolling back restores that snapshot byte for byte and regenerates the page CSS. A newly created draft is deleted instead.

## Cache and CSS correctness

Two failure modes are handled explicitly, because both produce a page that looks unchanged after a successful write:

- Elementor 4.2 caches rendered HTML in `_elementor_element_cache` and clears it only inside `Document::save()`. SitePilot additionally clears it on every `_elementor_data` write, including rollbacks, so a stale render cannot survive on a host with a persistent object cache.
- Per-post Elementor CSS is regenerated after every write, so a tree written outside the editor does not render with stale styles.

## Troubleshooting

| Error code | Meaning |
| --- | --- |
| `sitepilot_elementor_unavailable` | Elementor is inactive, or still booting. |
| `sitepilot_elementor_document_invalid` | The supplied document failed structural validation. Check `elType`, ids, `settings`, `elements` and `widgetType`. |
| `sitepilot_elementor_document_empty` | The target page has no Elementor document to edit. |
| `sitepilot_elementor_element_missing` | An edit referenced an element id that is not on the page. Re-read `inspect-design`. |
| `sitepilot_elementor_setting_unknown` / `_invalid` | A widget setting key or value is not registered by that widget. |
| `sitepilot_elementor_media_unmapped` | An image or CSS background has no Media Library mapping. |
| `sitepilot_elementor_link_unmapped` | An internal link has no `sitepilot://page/<slug>` mapping. |
| `sitepilot_elementor_design_coverage_failed` | Compilation would flatten or omit source structure. The error data lists every lost category. |
| `sitepilot_elementor_kit_settings_invalid` | A global-kit update named a key outside the four supported ones. |
| `atomic_global_styles_unsupported` | Elementor 4 atomic global-style stores are present; SitePilot made no global-style write. |
| `sitepilot_elementor_runtime_setting_unsupported` | A runtime-only edit introduced a key, replaced settings, or changed a typed setting's `$$type`. |
| `sitepilot_elementor_structure_unsupported` | A structural edit targeted a runtime-only or unmapped region. |

## Verification

```sh
cd plugins/sitepilot-mcp && composer lint && composer analyse && composer test
```

```sh
npx wp-env start --config .wp-env.7.0.json
```

```sh
npx wp-env run tests-cli wp eval-file /var/www/html/wp-content/plugins/sitepilot-mcp/tests/Integration/wp-env-elementor.php
```

## Attribution

Five Elementor-internals techniques were derived from `msrbuilds/elementor-mcp` (GPL-2.0) and re-implemented for SitePilot's guarded model. See [NOTICE.md](../NOTICE.md).
