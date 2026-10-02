# SitePilot MCP, WordPress plugin

Guarded OAuth 2.1 and Model Context Protocol (MCP) operations for WordPress. SitePilot lets an AI assistant inspect a site and propose changes, while WordPress keeps the final say: scoped credentials, risk tiers, human approvals, audit records and rollback.

- Product site: <https://sitepilot.tools/>
- Documentation: <https://docs.sitepilot.tools/>
- Download the signed release ZIP: <https://sitepilot.tools/download/>
- License: GPL-2.0-or-later (see [LICENSE](LICENSE) and [NOTICE.md](NOTICE.md))

## What this repository is

This is the public source of the plugin as released. Development and the full test suite live in a private repository. Each release is published here automatically as a single commit with a matching tag, so the history is one commit per version. Pull requests and issues are welcome, but a pull request is not merged here directly: accepted changes are applied in the private repository and appear in the next release. The files in the repository root are the plugin: the folder you would put in `wp-content/plugins/sitepilot-mcp`.

The `vendor/` directory is not committed. Run `composer install --no-dev` to produce it, or use the release ZIP, which already contains it.

## Documentation

See [docs/](docs/): the [plugin guide](docs/plugin-guide.md), [security model](docs/security.md), [API reference](docs/api-reference.md), [architecture](docs/architecture.md), and the [Elementor](docs/elementor.md) and [Enfold](docs/enfold.md) guides.

## Security

Report vulnerabilities privately to security@sitepilot.tools or through a private GitHub Security Advisory. Please do not open a public issue for a security problem.
