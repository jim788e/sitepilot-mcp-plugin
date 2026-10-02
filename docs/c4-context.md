# C4 system context: SitePilot MCP product

## System overview

SitePilot MCP is a self-hosted WordPress policy-enforcement plugin and MCP server. It lets an authorized AI client inspect a site and propose guarded changes without receiving PHP, SQL, shell, filesystem, or server-secret access.

## People and systems

| Actor or system | Relationship to SitePilot MCP |
|---|---|
| WordPress administrator | Installs the plugin, grants credentials and scopes, reviews high-risk changes, and performs rollback. |
| Site operator | Inspects sites, stages drafts, and reviews changes within assigned WordPress capabilities. |
| MCP client or AI agent | Calls the published tool schemas through OAuth or another claimed credential. |
| WordPress Core and database | Authenticate users and persist content, settings, change sets, approvals, snapshots, and audit events. |
| Builder extensions | Elementor, Enfold, Gutenberg, and WooCommerce provide their public runtime APIs and native document formats. |
| Optional orchestration service | Routes explicitly authorized operations through `/ops/{ability}` but remains outside the WordPress trust boundary. |
| Signed update origin | Serves immutable plugin ZIPs and a signed manifest after the Owner configures the updater. |

## Primary journeys

### Inspect and stage

1. A client authenticates to the WordPress MCP endpoint.
2. The plugin checks the WordPress capability and the credential's scopes.
3. The client inspects site and builder capabilities.
4. The client submits a bounded change plan with an idempotency key and expected site version.
5. Tier 0 and Tier 1 work remains read-only or draft-only; higher tiers wait for approval.

### Approve, execute, and recover

1. WordPress stores an immutable change set and pre-mutation recovery data.
2. An eligible WordPress actor approves the exact change-set hash when approval is required.
3. The plugin rechecks scope, capability, approval, expiry, and site version before execution.
4. Adapters write through public WordPress or extension APIs.
5. Validation records the result. Rollback replays compensating operations in reverse order.

## Context diagram

```mermaid
flowchart LR
  Agent["MCP client / AI agent"] -->|OAuth or claimed credential| Plugin["SitePilot MCP WordPress plugin"]
  Admin["WordPress administrator"] -->|Capabilities, approvals, audit review| Plugin
  Orchestrator["Optional orchestration service"] -->|POST /ops/{ability}| Plugin
  Plugin --> Core["WordPress Core and database"]
  Plugin --> Builders["Gutenberg / Elementor / Enfold / WooCommerce"]
  Plugin -.->|Signed manifest and immutable ZIP| Updates["Configured update origin"]
```

## Related documentation

- [Architecture](./architecture.md)
- [API reference](./api-reference.md)
- [Plugin guide](./plugin-guide.md)
- [Security model](./security.md)
- [Release gates](https://docs.sitepilot.tools/release-gates/)
