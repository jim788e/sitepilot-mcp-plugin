# Third-party notices

## EMCP Tools (`msrbuilds/elementor-mcp`)

- Source: <https://github.com/msrbuilds/elementor-mcp>
- Licence: GNU General Public License v2.0 or later (GPL-2.0-or-later)

SitePilot's WordPress plugin (`plugins/sitepilot-mcp`) is licensed
`GPL-2.0-or-later`, the same licence as EMCP Tools; no EMCP Tools
source file is included in SitePilot.

SitePilot's Elementor support is an independent implementation built around
SitePilot's guarded change-set model, which differs fundamentally from EMCP Tools' direct-mutation
ability surface. The following **techniques** were studied in EMCP Tools'
`includes/class-elementor-data.php` and re-implemented here, and are credited
accordingly:

| Technique | Where it lives in SitePilot | Why it matters |
| --- | --- | --- |
| Invalidating `_elementor_element_cache` whenever `_elementor_data` is written | `ElementorDocumentStore::register_cache_invalidation()` | Elementor 4.2 caches rendered HTML in post meta and clears it only inside `Document::save()`. A rollback or recovery path writes the meta directly, so on a host with a persistent object cache a stale empty render could survive every later write. |
| Guarding `\Elementor\Plugin::$instance->documents` before dereferencing it | `ElementorDocumentStore::documents_ready()` | Elementor inserts its default kit during its own activation, before the document manager exists. Dereferencing it in that window is a fatal error. |
| Falling back to raw `_elementor_data` when `Document::get_elements_data()` returns empty | `ElementorDocumentStore::get_elements()` | The document API returns empty under WP-CLI and REST contexts, which is how SitePilot's gateway and integration scenarios run. |
| Regenerating the per-post Elementor CSS file after a write | `ElementorDocumentStore::regenerate_css()` | Without it, a tree written outside the editor persists but renders with stale styles. |
| Reading widget controls to describe and validate settings | `ElementorWidgetRegistry` | Lets an agent author valid widget settings instead of guessing, so malformed documents are rejected before `Document::save()`. |

## Archivo (bundled font)

See `plugins/sitepilot-mcp/assets/fonts/Archivo-OFL.txt` for the SIL Open Font
License covering the bundled Archivo subset.
