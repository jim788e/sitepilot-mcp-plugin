# SitePilot MCP Security Model & Threat Assessment

This document specifies the security architecture, cryptographic invariants, trust boundaries, and threat mitigation models for **SitePilot MCP** (`0.4.16`).

---

## 1. Core Trust Boundaries

```
┌───────────────────────────────────────────────────────────┐
│                      MCP Client (AI)                      │
│                  (Untrusted / Non-Admin)                  │
└─────────────────────────────┬─────────────────────────────┘
                              │ JSON-RPC (MCP) / REST
                              ▼
┌───────────────────────────────────────────────────────────┐
│          Client or Optional Orchestration Service         │
│              (Untrusted Policy Requester)                 │
└─────────────────────────────┬─────────────────────────────┘
                              │ Authenticated Signed Transport
                              ▼
┌───────────────────────────────────────────────────────────┐
│                WordPress SitePilot Plugin                 │
│         ★★ Policy Enforcement Point (PEP) ★★              │
│  - OAuth 2.1 PKCE S256 Validation                         │
│  - 4-Tier Risk Engine & Scope Guards                      │
│  - Two-Phase Commit State Machine                         │
│  - Fail-Closed Sandboxed Adapters                         │
│  - SHA-256 Change Set & Version Locking                   │
└───────────────────────────────────────────────────────────┘
```

The WordPress plugin is the **sole authoritative Policy Enforcement Point (PEP)**. No external service or client agent can bypass:
1. WordPress core capability checks (`current_user_can`).
2. Consented OAuth 2.1 scopes.
3. 4-Tier risk policy gates.
4. Cryptographic site-version locking (`expected_version`).
5. The dedicated approval capability and channel guard.

---

## 2. Authentication & Credential Hygiene

- **OAuth 2.1 with PKCE**:
  - Implements RFC 7636 with mandatory `code_challenge_method = S256`.
  - Exact redirect URI matching (wildcards, fragments, and non-HTTPS URIs are strictly rejected; loopback HTTP is permitted solely for local development).
  - Single-use authorization codes with a 5-minute lifespan.
- **OAuth Discovery and Dynamic Client Registration**:
  - An unauthenticated request to the canonical `/wp-json/sitepilot-mcp/v2/mcp` resource returns a `WWW-Authenticate` challenge naming its RFC 9728 protected-resource metadata. The metadata points clients to the authorization server and its RFC 7591 Dynamic Client Registration endpoint.
  - An RFC 7591 Dynamic Client Registration that omits `scope` receives a `site:read` registered ceiling. An explicit registration scope must be a non-empty, supported, space-separated set; SitePilot persists and returns that exact ceiling.
  - Registration is public, so it is bounded: at most 30 new registrations per hour for the whole site (HTTP 429 with `Retry-After` beyond that), at most five redirect URIs of at most 512 characters each, and a client name of at most 100 characters. The count and the insert run under a MySQL/MariaDB named lock, which cannot expire while its holder runs, so parallel requests cannot exceed the cap. If the database does not grant the lock, or the hourly count fails, registration is refused with HTTP 503 rather than run unchecked. The daily retention job deletes registered clients older than 30 days that never produced a grant or an authorization code.
  - Before consent and token issue, SitePilot intersects an authorization request with the dynamic client's registered ceiling. A request with no permitted scope fails with `invalid_scope`; consent never widens the registration.
  - Client ID Metadata Document clients remain outside this DCR-specific ceiling because their metadata is retrieved rather than stored as a dynamic registration. Their requested scopes, the WordPress user's grant authority, and the consent screen remain authoritative.
  - Existing dynamic client rows acquire the `site:read` ceiling during the `0.4.10` schema upgrade for future authorization requests, but the migration does not narrow already-issued access or refresh tokens. Those grants retain their consented scopes until expiry or revocation.
  - After upgrading, a site owner must revoke any existing overbroad OAuth connection. A client that legitimately needs broader authority must then register again with an explicit supported `scope` and receive fresh consent.
- **Token Security**:
  - Access tokens expire after 15 minutes.
  - Refresh tokens rotate upon use (token family tracking). If a previously used refresh token is presented, the entire token family is immediately revoked to mitigate replay attacks.
  - Raw tokens, client secrets, and credentials are never written to logs or audit tables; all tokens are stored as irreversible SHA-256 hashes.
- **Claimed Application Passwords**:
  - SitePilot stores the WordPress Application Password UUID and its immutable scope grant, never the password value.
  - An unclaimed Application Password is restricted to the least-privilege fallback; a re-claim cannot silently widen its scopes.
- **Zero WordPress Password Exposure**:
  - SitePilot never requests or stores WordPress account passwords and never returns an Application Password value.

---

## 3. Strict Fail-Closed Invariants

To eliminate entire classes of arbitrary execution vulnerabilities:

| Category | Policy |
| :--- | :--- |
| **Raw Code Execution** | Raw PHP execution, eval, `create_function`, and arbitrary callbacks are excluded from all tool schemas. |
| **Database Access** | Direct SQL queries and arbitrary database modifications are prohibited. All data modifications use WordPress/WooCommerce high-level APIs. |
| **Filesystem / Shell** | Shell execution, `exec`, `proc_open`, WP-CLI invocations, and filesystem root operations are not exposed. |
| **Configuration** | Modification or reading of `wp-config.php`, `.htaccess`, or server environment files is blocked. |
| **Self-Protection** | The active SitePilot MCP plugin, its audit log subsystem, and active recovery snapshot tables cannot be deactivated or deleted via MCP. |

---

## 4. Mutation Governance & Approvals

- **Immutable Change Sets**:
  - A change set payload is hashed (`SHA-256`) upon planning.
  - An approval is cryptographically bound to `actor_id`, `site_id`, `site_version`, `change_set_hash`, and expires in **30 minutes**.
  - Any drift in site state or change set input invalidates the approval, requiring re-inspection and re-planning.
- **Controlled Deletion**:
  - Standard deletions move content to the reversible WordPress Trash.
  - Permanent deletion is disabled by default. If enabled by an administrator, each permanent deletion is restricted to an isolated Tier-3 change set requiring explicit, fresh admin approval with no rollback.
- **Separated Approval Channel**:
  - The `/ops/{ability}` operation bridge does not register `approve-change`.
  - Approval requires `sitepilot_approve` plus the approval guard. Ordinary OAuth credentials are refused.
  - Cookie-authenticated wp-admin approval requires a valid nonce. Delegated credential approval is disabled in production and requires both an explicit credential grant and an opt-in constant elsewhere.

---

## 5. Private Updater Cryptography

- Auto-updates use an Ed25519 public-key signature scheme:
  - The updater requires both an HTTPS manifest URL and a Base64-encoded Ed25519 public key configured by an administrator.
  - Manifest signatures are verified using `libsodium` / `sodium_crypto_sign_verify_detached`.
  - The downloaded update ZIP file's SHA-256 checksum is verified against the signed manifest before passing to WordPress's native upgrader.

---

## 6. STRIDE Threat Analysis

| Threat | Description | SitePilot MCP Mitigation |
| :--- | :--- | :--- |
| **Spoofing** | Rogue agent posing as authorized client | PKCE S256 OAuth 2.1, hashed tokens, exact credential grants, and token-family revocation. |
| **Tampering** | Modifying change set inputs post-approval | Approval strictly bound to immutable SHA-256 change set hash; input mutation invalidates approval. |
| **Repudiation** | Denying an unauthorized site mutation | Database-backed audit trail logging actor, IP, timestamp, diff, and approval IDs. |
| **Information Disclosure** | Leakage of sensitive site data or secrets | Masked audit logs, nonces/passwords excluded, sandboxed network-disabled DOM parsing. |
| **Denial of Service** | Resource exhaustion via large HTML/CSS payloads | Bounded payloads (HTML $\le 2\text{ MB}$, CSS $\le 500\text{ KB}$, max 1,500 DOM elements), timeout constraints. |
| **Elevation of Privilege** | Agent executing admin commands without consent | 4-tier risk engine, scoped credentials, a distinct approval capability, and production approval through the nonce-protected WordPress Admin UI. |

---

## 7. Responsible Vulnerability Disclosure

If you discover a security vulnerability in SitePilot MCP, please report it privately to the maintainers at **security@sitepilot.tools** or through a private GitHub Security Advisory. Do not open public issues for security vulnerabilities.
