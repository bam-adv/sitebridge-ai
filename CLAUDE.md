# SiteBridge AI — repo notes for Claude

Single-file WordPress plugin (`sitebridge-ai.php`, **v1.10.0**) that bridges AI tooling to any
WordPress site over REST. Scope: **JSON-LD schema**, **desktop ACF navigation**, **managed
redirects**. Self-updates from GitHub releases. Host- and site-agnostic by design.

## Where this sits (3 layers — don't conflate them)

1. **This plugin** — installed per site; exposes the REST endpoints below; self-updates.
2. **`wp-mcp-hosted`** — a separate hosted MCP server (on Railway) that *calls* this plugin's REST
   endpoints (and core WP/ACF REST). Different repo/deploy; changing routes or payloads can break it.
3. **Claude chat skills** (`content-publish`, `schema-deploy`, …) — live in Devon's Claude settings,
   not in any repo; they drive layer 2.

Posts / media / Yoast / ACF-field reads the connector does are **core WP + ACF REST**, not this
plugin — this plugin only owns schema + nav + redirects. (The theme's hero meta-box→block migration
is unrelated to this plugin; its notes live in the content-publish skill.)

## Heritage / compatibility (don't break)

Evolution of the old **`bam-schema-field`** plugin. The REST namespaces (`bam/*`) and storage keys
(`bam_*` / `_bam_*` — e.g. the `bam_redirects` option, ACF nav field `main_nav_settings_version_2`)
are **intentionally preserved** as a drop-in replacement, so the deployed connector and the data
already on live sites keep working. **Don't rename namespaces/keys** without a data migration + a
coordinated connector release. The connector's tool docs reference this plugin inconsistently
(`v1.4+` / `v1.5+` / `v1.9.0+`) — all the same lineage; normalize those in the connector repo when
you next touch tool descriptions, not here.

## REST surface (v1.10.0)

Namespaces: `SITEBRIDGE_NS` / `SITEBRIDGE_SCHEMA_NS` (both `bam/*`).
- **Schema**: `…/template/(post_type)` per-post-type JSON-LD templates + per-post schema.
- **Nav**: `/nav`, `/nav/add-link`, `/nav/remove-link`, `/nav/replace-link` — edits the desktop ACF
  nav option `main_nav_settings_version_2`; bumps `main_nav_version`.
- **Redirects**: `/redirects` — `GET` list, `POST` add (`source`, `target`, `type`), `DELETE` remove
  by `source`; `/redirects/import` (`POST`: `redirects`/`csv`, `replace_all`). Stored in the
  `bam_redirects` option.

Site-/theme-specific tailoring is centralized in the **CONFIG/PROFILE** block at the top of the
file, overridable via `wp-config` constants / filters. Keep new tailoring there, not scattered
through the code.

## Self-updater

`pre_set_site_transient_update_plugins` → polls `api.github.com/repos/{SITEBRIDGE_GH_REPO}/releases/latest`,
installs the release's `.zip` asset (falls back to the source zipball), renames the unpacked
`repo-tag/` dir to the plugin slug. `SITEBRIDGE_GH_REPO = 'bam-adv/sitebridge-ai'`.
**To ship an update:** bump `Version:` + `SITEBRIDGE_VERSION`, tag, publish a GitHub release (ideally
with a built `.zip` asset). Cleanup: line ~34 still has a leftover `// <-- REPLACE with your repo`.

## Deployment / fleet — intentionally NOT listed here

This plugin is host-agnostic and runs on whatever sites/environments it's installed on (WP Engine,
Kinsta, staging, new dealerships, …). That roster — domains, hosting, SSH slugs — **changes over
time, so it is deliberately not hardcoded in this repo.** For the current live roster, enumerate it
from the connector's **`list_sites`** (the source of truth). Any host-specific access detail (e.g.
WP Engine SSH slug conventions) belongs with fleet operations, not with the plugin code.
