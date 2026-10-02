# Enfold Advanced Layout Builder support

SitePilot supports the complete guarded Enfold workflow: inspect, calibrate, compile or stage a draft, validate it with Enfold’s native save pipeline, collect visual evidence, publish only through a separate approved change set, and roll back.

This guide describes behavior shipped through SitePilot MCP `0.4.16` and Enfold 7.x. The live acceptance environment uses Enfold 7.1.6. SitePilot verifies the active theme and builder APIs on every site rather than assuming a theme version is compatible.

## What SitePilot supports

| Capability | Support |
| --- | --- |
| Detect Enfold or an Enfold child theme | Yes |
| Inspect ALB availability and verified profile state | Yes |
| Read a page's bounded ALB outline and current UID/path identities | Yes, through `sitepilot.inspect_design` |
| Discover runtime Enfold elements, accepted attributes and parent rules | Yes, through `sitepilot.inspect_design` |
| Read Enfold colour, typography and page settings | Yes, through `sitepilot.inspect_design` |
| Save and apply reusable ALB templates | Yes; whole tree or selected subtree, with fresh `av_uid` values on every application |
| Update Enfold global colours and typography | Yes, Tier 2 approval with exact `avia_options` snapshot rollback |
| Set protected page-scoped element styles | Yes, on one unique `custom_class` in a calibrated ALB draft |
| Stage Elementor-style header/footer documents | No; Enfold uses theme options and returns `sitepilot_unsupported_for_builder` explicitly |
| Calibrate against one real ALB page and one normal page | Yes, Tier 3 approval |
| Create or update draft ALB pages | Yes |
| Native sections, rows, columns, headings, text, images, buttons, galleries, tabs, accordions and contact fields | Yes, when registered by the active Enfold installation |
| Page title, slug, excerpt, parent, order, template and featured image | Yes, verified after Enfold normalization |
| Media Library image mapping and reuse | Yes; images and CSS backgrounds must map to existing attachments |
| HTML/CSS compilation to ALB | Yes, through `design.compile_enfold_html` |
| Exact semantic HTML grids, cards and galleries | Yes, through `structure_mode: "preserve"` when native columns would flatten required wrappers |
| Draft-preview screenshot evidence | Yes through the optional Cloud gateway and Browser Rendering |
| Publish, menu assignment and rollback | Yes; publish/menu changes remain separate Tier 2 operations |

SitePilot never publishes while compiling or staging an Enfold page. It also never runs arbitrary PHP, SQL, shell, WP-CLI, or direct database changes.

## Required preflight

1. Use a single-site WordPress 6.9+ installation over HTTPS with PHP 8.2+.
2. Activate Enfold or an Enfold child theme and confirm the Advanced Layout Builder works for an administrator in WordPress.
3. Install and activate SitePilot MCP, then resolve every item under **SitePilot → Diagnostics**.
4. Connect the MCP client with `site:read`, `content:write`, `media:write`, and `design:write` scopes.
5. Inspect the site. Continue only when Enfold reports `active: true`, `alb_available: true`, and `profile_verified: true`.

A theme, child-theme, or Enfold-version change invalidates calibration deliberately. Calibrate again; do not copy metadata from another page or site.

## Calibrate the site safely

Calibration proves SitePilot can call the site’s own Enfold save APIs. It discovers required metadata keys and types without copying values from the example page.

1. Create and save a small **draft** page manually in the Advanced Layout Builder. Include a section, a column, a heading, and a text block.
2. Create a second, ordinary WordPress draft page without ALB.
3. Record both page IDs.
4. Plan `design.calibrate_enfold` with `alb_page_id` and `normal_page_id`.
5. Approve the Tier 3 calibration in WordPress, then execute it.
6. Inspect the site again. Proceed only after `profile_verified: true` and `native_pipeline: true` are reported.

No calibration page is changed or published by this process.

## Inspect an Enfold design

Call the read-only `sitepilot.inspect_design` tool with `builder: "enfold"` before planning Enfold work:

```json
{
  "builder": "enfold",
  "target": 84,
  "include": ["tree", "elements", "globals", "page_settings"]
}
```

`tree` returns the target page's metadata-free `EnfoldDocument` outline. `elements` returns the active runtime shortcode set, registry definitions, parent rules, accepted attributes, drag levels, support classification, and the Enfold-version fingerprint. `globals` returns only scalar colour and typography values from the active Enfold theme options; unrelated and secret-like stored options are excluded. `page_settings` reports the page template, ALB active and clean-data state, calibration status, and the identity scheme.

Each element definition reports `support: "curated"` or `support: "runtime_only"`. Runtime-only elements remain parseable, round-trippable, and valid in existing pages; their permissive parent metadata and common attributes are not a construction contract. The Enfold editor must reject inserting runtime-only tags and restrict their attribute updates to `av_uid`, `custom_class`, and `sc_version`, while structural removal, movement, and duplication remain possible.

A default inspection of a normal non-ALB page succeeds and reports `alb_active: false` while omitting the outline. An explicit `include: ["tree"]` request still returns `sitepilot_enfold_document_empty`, so callers can choose strict tree acquisition when required.

An Enfold node's non-empty `uid` is the preferred identity and `path` is the fallback for the current document snapshot. Re-inspect after structural changes because sibling paths can change. Reading works without a verified calibration profile; staging or editing still requires the relevant guarded write checks.

## Stage native ALB content

Use `design.stage` when you already have allowed Enfold `av_*` shortcodes. SitePilot creates or updates a **draft** page, then runs Enfold’s native normalization/save pipeline.

For each staged page, SitePilot verifies:

- `_aviaLayoutBuilder_active = active`;
- canonical content and `_aviaLayoutBuilderCleanData` describe the same shortcode tree;
- Enfold generated the page-specific shortcode tree from the normalized page content, plus parser state and element-state metadata;
- every staged `av_*` element is indexed by Enfold;
- approved page fields remain unchanged after native normalization; and
- assets, page links, rollback snapshot and draft-only status are valid.

An issue fails closed with a machine-readable error such as `sitepilot_enfold_state_mismatch`, `sitepilot_enfold_element_state_refresh_failed`, or `sitepilot_enfold_page_metadata_mismatch`. The automatic rollback path restores the prior page or removes a newly created draft.

### ALB document identity and round trips

`EnfoldDocument` is the shared parser behind ALB validation. It enforces the existing 250 KB, 768-token, and 20-level limits while producing a typed tree of registered layout, column, and content elements. Each node exposes its `av_uid`, structural path, tag, parsed attributes, direct text, and children. Its serializer preserves canonical ALB byte for byte, including shortcode quoting, spacing, HTML content, and Enfold-normalized UIDs.

The native-save spike confirms that Enfold preserves an existing non-empty `av_uid` and generates a value only when the attribute is blank. SitePilot therefore treats the normalized UID as the stable primary identity. Structural paths remain the secondary identity and the fallback while newly compiled content still contains blank UIDs.

## Edit Enfold elements

Use `design.edit_elements` with `builder: "enfold"` and an existing calibrated ALB draft page ID. The operation supports `set_attributes`, `set_content`, `insert`, `move`, `remove`, and `duplicate`, with at most 100 ordered edits per atomic change action. Target an element with its non-empty `uid`; send its current `path` as the fallback. Structural edits accept `parent_uid` or `parent_path` plus an optional zero-based `position`.

`set_attributes` validates every changed key against the active shortcode definition. `set_content` is limited to childless `av_textblock`, `av_heading`, and `av_toggle` elements. Inserted elements must use curated definitions, receive blank `av_uid` values for Enfold to normalize, and satisfy the registered parent hierarchy. Runtime-only elements can still be moved, removed, or duplicated, but cannot be inserted; their attribute updates are restricted to `av_uid`, `custom_class`, and `sc_version`.

Every edit is applied to a working tree and reparsed before the next edit, so paths always describe the current intermediate document. Reparse also enforces a tag-sequence invariant that rejects shortcode injection through direct content. Attribute rewrites fail closed when the source token contains bare, duplicate, or unparsed attributes. A dry run returns the exact draft `preview_url`, before/after outlines, a compact `outline_diff`, and per-edit changes without writing. Execute snapshots the post and ALB metadata, reloads and reparses clean data immediately before serialization, and fails with `sitepilot_enfold_edit_conflict` before writing if Avia changed the source. Successful execution calls Enfold's native save pipeline, verifies the saved metadata, and automatically rolls back any failed write.

## Reuse templates and protected styles

`design.save_template` with `builder: "enfold"` reads an editable active-ALB page and stores either its full tree or the subtree selected by `uid` or `path` in the private `sitepilot_template` post type. Saving a template does not modify the source page and may read a published source. `design.apply_template` remains a calibrated draft-page write: append or replace the stored tree, optionally under a selected parent, then pass the result through the same native ALB save and verification pipeline. Every stored `av_uid` is blanked recursively before application so Enfold generates fresh identities each time.

`design.set_element_style` is a Tier 1 draft-only write keyed by one `custom_class` token that occurs on exactly one element in the current page tree. SitePilot stores only allowlisted property/value pairs in protected page metadata and emits page-scoped CSS. URL-bearing values, executable protocols, selector boundaries, ambiguous classes, and missing classes fail closed. Rollback restores the prior page metadata exactly.

## Update Enfold global design settings

`design.update_global_kit` with `builder: "enfold"` is Tier 2 and requires approval. Send exact existing colour or typography keys returned by `inspect-design.globals`; SitePilot refuses unrelated, secret-like, unknown, or non-scalar Enfold options. Execution snapshots the complete active `avia_options` value, merges only the approved public keys, and records an exact option rollback.

**Known limitation (O11):** nested Enfold 7.x structures inside `avia_options_enfold` have not been calibrated for safe read/write behavior. SitePilot supports only the inspected scalar colour and typography keys described above; nested global options remain unsupported and must be changed in Enfold by an administrator.

`design.stage_theme_document` deliberately returns `sitepilot_unsupported_for_builder` for Enfold. Enfold headers and footers are governed by theme options, not standalone builder documents, so SitePilot does not fabricate an Elementor-compatible document shape.

## Compile HTML/CSS to Enfold

Use `design.compile_enfold_html` to seed a page from a bounded, clean, static HTML/CSS prototype. It is a best-effort starting point, not a browser or JavaScript migration engine. The compiler parses locally in PHP DOM with networking disabled; it does not fetch URLs, run scripts, or upload files.

Before DOM parsing, a cheap preflight rejects sources that are clearly unsuitable. `sitepilot_enfold_source_unsupported` returns one or more reasons (`script_driven_layout`, `framework_class_soup`, `node_count_exceeded`, `nesting_depth_exceeded`, `css_custom_property_theming`, or `unmapped_asset_count`) and recommends the incremental editor path.

The compiler turns ordinary semantic structures into editable ALB elements where this is faithful:

- `section` → Enfold section;
- responsive grid/flex groups → Enfold rows and columns;
- headings, paragraphs, images and links → individual heading, text, image and button elements;
- galleries, tabs, accordions and contact fields → their registered native Enfold elements.

Use `media_mappings` for images that should be emitted and for every CSS `url(...)`; mappings must point to existing WordPress Media Library attachments. Mapped files are rewritten to canonical attachment URLs and verified before staging continues. An HTML image or inline preserved-structure background with no mapping is omitted from the draft and returned as `asset_mapping_missing`, with `media.stage` and `design.edit_elements:insert av_image` suggestions. Invalid supplied mappings still fail closed.

Coverage loss no longer rejects an otherwise safe draft. The result includes `compiled` counts, a numeric `coverage.ratio`, and `unmapped` entries with source path, reason, visible text, and suggested follow-up operations. Unsafe ALB, malformed input, invalid supplied attachment mappings, and unsupported CSS remain hard failures.

### Choose native or preserved structure mode

`component_mappings` accepts a `structure_mode` value:

| Mode | Use it when | Editing experience |
| --- | --- | --- |
| `native` (default) | Enfold rows/columns can represent the layout without losing the source structure | Individual ALB elements remain separately editable |
| `preserve` | CSS relies on nested HTML wrappers, card shells, figure markup, CSS Grid, or other structure that nested Enfold columns would flatten | The safe HTML group is retained inside one Enfold Text Block, while the surrounding page stays native ALB |

For example:

```json
{
  ".destination-card-grid": {
    "type": "cards",
    "desktop_columns": 4,
    "tablet_columns": 2,
    "mobile_columns": 1,
    "structure_mode": "preserve"
  }
}
```

Mappings are required for project-specific card layouts, preserved wrappers, and non-ARIA control relationships. For project-specific tab controls, declare the target attribute instead of adding its class or attribute convention to PHP:

```json
{
  ".day-tabs": {
    "type": "tabs",
    "target_attribute": "data-day"
  }
}
```

Use `preserve` for fidelity, not as a bypass. The compiler still sanitizes the HTML, rewrites mapped media, checks visible-text and structural coverage, and blocks unsafe styles or missing assets. The retained group is intentionally one editable Text Block because Enfold does not safely support arbitrarily nested native column trees.

## Quality gates and publication

Successful compilation returns a draft preview URL, compiled and unmapped manifests, coverage report, asset report, and rollback state. It is a draft-seeding operation and has no pixel-similarity acceptance gate.

Visual comparison is an opt-in post-edit check. Set `visual_verification: true` on `design.edit_elements` through the Cloud gateway. Planning captures Enfold's stable `#main` draft wrapper at desktop and mobile sizes; execution captures the edited draft and returns private before/after/diff evidence. `visual_similarity_threshold` defaults to `0.85`. Failed or unavailable evidence is reported, but always carries `blocks_write: false` and `blocks_publish: false`.

Publishing and menu assignment are separate Tier 2 changes. Review the exact draft and visual evidence first, approve the publish change in WordPress, then execute it. Rollback remains available only while the recorded recovery data is valid.

## Supported safety limits

- Draft-only staging; compilation never publishes.
- Registered Enfold shortcodes only; unsupported or malformed shortcode structures are rejected.
- Scripts, PHP, iframes, local paths, `.html` file references, unsafe CSS protocols, arbitrary remote assets, and unapproved media are rejected.
- HTML, CSS, shortcode count, rendered screenshots, artifact retention, and browser time are bounded.
- The compiler never silently falls back to an Enfold Code Block unless a separately approved operation explicitly permits a supported fallback.

## Troubleshooting

| Symptom | What to do |
| --- | --- |
| `alb_available: false` | Confirm Enfold ALB is enabled and works in the WordPress editor; re-run SitePilot diagnostics. |
| `profile_verified: false` | Repeat the two-page calibration after confirming the active theme/version. |
| `sitepilot_enfold_state_mismatch` | Do not hand-build Enfold metadata. Re-stage through the native pipeline; it will refresh the element index from normalized ALB content. |
| `sitepilot_enfold_source_unsupported` | Use the returned `reasons` to simplify the source, or build against an existing page once Enfold element editing is available. |
| A grid has the right images but wrong layout | Map the wrapper with `structure_mode: "preserve"` so the CSS-targeted DOM remains intact. |
| `sitepilot_enfold_css_asset_unmapped` | Map the exact CSS image URL/path to a valid Media Library attachment. Missing HTML media is instead listed in the partial result's `unmapped` manifest. |
| Optional visual verification failed | Review the before/after evidence and outline diff, then revise or roll back the edit. Publication is not blocked automatically. |
| `site_version` changes while ALB is open | Enfold or WordPress autosave/revision activity can legitimately advance the optimistic version. Re-inspect and re-plan against the new version; treat it as a concurrency bug only if it reproduces with the editor closed and no autosave. |

For connection setup and the complete SitePilot workflow, see the [plugin guide](./plugin-guide.md). For implementation boundaries, see [architecture.md](./architecture.md).
