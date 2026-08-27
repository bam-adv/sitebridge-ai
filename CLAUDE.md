# SiteBridge AI — repo notes for Claude

Single-file WordPress plugin (`sitebridge-ai.php`, **v1.18.0**) that bridges AI tooling to any
WordPress site over REST. Scope: **JSON-LD schema**, **desktop ACF navigation**, **managed
redirects**, **byte-exact content search/replace**, **Yoast meta (canonical/robots +
title/description/focus keyword)**, **cache purging**, **safe ACF page-level (meta-box) field
writes**, **read-only capability findings** (site viability discovery). Self-updates from GitHub releases. Host- and site-agnostic by design.

## Where this sits (3 layers — don't conflate them)

1. **This plugin** — installed per site; exposes the REST endpoints below; self-updates.
2. **`wp-mcp-hosted`** — a separate hosted MCP server (on Railway) that *calls* this plugin's REST
   endpoints (and core WP/ACF REST). Different repo/deploy; changing routes or payloads can break it.
3. **Claude chat skills** (`content-publish`, `schema-deploy`, …) — live in Devon's Claude settings,
   not in any repo; they drive layer 2.

Posts / media / ACF-field READS the connector does are **core WP + ACF REST**, not this plugin.
ACF-field WRITES are this plugin's job since v1.17 (`/acf-fields`) — the core-REST `acf` write
path corrupts field reference rows on seamless-clone groups and must not be used (see the route's
docblock). (The theme's hero meta-box→block migration is unrelated to this plugin; its notes live
in the content-publish skill.)

## Heritage / compatibility (don't break)

Evolution of the old **`bam-schema-field`** plugin. The REST namespaces (`bam/*`) and storage keys
(`bam_*` / `_bam_*` — e.g. the `bam_redirects` option, ACF nav field `main_nav_settings_version_2`)
are **intentionally preserved** as a drop-in replacement, so the deployed connector and the data
already on live sites keep working. **Don't rename namespaces/keys** without a data migration + a
coordinated connector release. The connector's tool docs reference this plugin inconsistently
(`v1.4+` / `v1.5+` / `v1.9.0+`) — all the same lineage; normalize those in the connector repo when
you next touch tool descriptions, not here.

## REST surface (v1.18.0)

Namespaces: `SITEBRIDGE_NS` / `SITEBRIDGE_SCHEMA_NS` (both `bam/*`).
- **Schema**: `…/template/(post_type)` per-post-type JSON-LD templates + per-post schema.
- **Nav**: `/nav`, `/nav/add-link`, `/nav/remove-link`, `/nav/replace-link`, `/nav/remove-item` —
  edits the desktop ACF nav option `main_nav_settings_version_2`. (`main_nav_version` is only READ,
  by `GET /nav` — no writer bumps it, and never has. If the theme ever keys a nav cache off that
  field, every one of these writers needs to start bumping it.) URL
  matching is trailing-slash AND whitespace tolerant (`sitebridge_nav_url_eq` trims both sides);
  `GET /nav` also returns `_untrimmed_urls` flagging any stored link URL with stray surrounding
  whitespace. Title matching (`parent_title` / `column_title` / remove-item `title`) goes through
  `sitebridge_nav_title_eq` — case-insensitive and entity-decoding on BOTH sides, so a caller's `&`
  matches a stored `&amp;`; titles written to storage go through `sitebridge_nav_clean_title`
  (entity-decode → `sanitize_text_field` → trim) so `&amp;` is stored as a literal `&`.
- **Top-level nav removal** (v1.14+): `POST /nav/remove-item` — the one destructive nav operation,
  so it is **two-key gated**. `remove-link` still never touches top-level items (unchanged). Body:
  `url` and/or `title` (at least one), `confirm`, `force`. Without `confirm:true` it returns a
  PREVIEW (`removed:0`, full item JSON, nothing written). An item with a populated dropdown also
  needs `force:true` — the 409 names the column/link counts that would be destroyed. A matcher
  hitting >1 item aborts 409 with the match list; 0 matches is a 404 listing all top-level items.
  Dropdown-only parents share `url:"#"`, which is why `title` exists as a matcher. Success returns
  `removed_item` (verbatim, dropdown included — the undo path via `/nav/add-link`) and
  `nav_items_remaining`.
- **Nav quirk fixes** (v1.14): `/nav/replace-link` `new_url` is now OPTIONAL — pass `new_title`
  alone for a title-only rename (response carries `title_only:true`). `/nav/add-link` takes
  `force_new_column:true`, which appends a new column instead of matching one by title — the only
  way to add a second blank-titled column, since a blank/whitespace `column_title` normalizes to
  `""` and either matches the existing `""` column or 409s as ambiguous. It can't be combined with
  `column_index`; `add-link` now also returns the resulting `column_index`.
- **Redirects**: `/redirects` — `GET` list, `POST` add (`source`, `target`, `type`), `DELETE` remove
  by `source`; `/redirects/import` (`POST`: `redirects`/`csv`, `replace_all`). Stored in the
  `bam_redirects` option.
- **Content search/replace** (v1.13+): `POST /search-replace` — byte-exact `str_replace()` on **raw**
  `post_content`. Reads via `$wpdb->get_var` (no `the_content`, no client content), writes via raw
  `$wpdb->update` + `clean_post_cache` — deliberately NOT `wp_update_post()`, which re-normalizes ACF
  block-comment JSON escapes and blanks blocks. Body: `post_id`, `replacements[{old,new,expect?}]`,
  `dry_run` (default true). Per-pair `expect` mismatch aborts the WHOLE request (returned as HTTP 200
  with `aborted:true` so the connector surfaces the per-pair `found` counts). Guards: `old` ≥ 8 bytes,
  `old !== new`, ≤ 20 pairs. Returns `md5`/`bytes` before & after (no revision entry is created).
- **Yoast meta** (v1.13+, extended v1.15): `POST /yoast-meta` — writes `_yoast_wpseo_canonical` and
  `_yoast_wpseo_meta-robots-noindex` via `update_post_meta`/`delete_post_meta` (these two keys aren't
  dependably core-REST-writable). `canonical:""` clears; robots `index|noindex|default` maps to
  `2|1|delete`. Canonical is normalized (one trailing slash stripped unless path is `/`). **v1.15
  adds `seo_title` / `meta_description` / `focus_keyword`** — the core-REST path Yoast exposes for
  these silently drops the write on `page` post types (HTTP 200, meta never persists), so they now
  also go through `update_post_meta` here, post-type-agnostic; empty string clears. Partial update;
  response returns `effective` values **read back from the DB**, not an input echo.
- **ACF fields** (v1.17+): `POST /acf-fields` — body: `post_id`, `fields` `{name-or-key: value}`
  (max 20), optional `clear_stale_rows` (default true). The ONLY safe write path for page-level
  (meta-box) ACF fields: core REST's `acf` key resolves seamless-clone sub-fields with COMPOSITE
  keys (`{cloneKey}_{subKey}`) and stores them into the `_`-prefixed reference rows, which the
  front end can't resolve — repeaters render nothing while REST read-back (which never consults
  the reference rows) reports success (verified against ACF PRO 6.8.4; the July 2026 hero-rollout
  "pointer prefix" corruption is the same bug). The route resolves each selector to its REAL field
  via `acf_get_field()` (composite keys refused, unknown selectors abort the whole request before
  any write), writes through `update_field()` with the field KEY, deletes stale higher-index rows
  when a repeater shrinks, and **repairs** any composite-corrupted reference row on the post
  (self-healing, reported in `repaired_references` — only when the composite's tail resolves to a
  registered field whose name matches the row). Partial update by construction. Response verifies
  **through the reference rows** (the front end's resolution path): per-field `state` with
  `reference_ok` (+ per-row sub-field states for repeaters), plus `content_md5_before/after` +
  `content_untouched` proving `post_content` wasn't touched.
  **v1.17.1** — two fixes from the fleet hero rollout: (a) **`null` is normalized** to ACF's empty
  value (`""`, or `[]` for repeater/group/flexible) everywhere in `fields` before any write —
  passing `null` for an empty image sub-field used to leave a value/reference pair the front end
  resolves to nothing; the count comes back per field as `nulls_normalized`. (b) The stale-row
  counter told the truth but not the whole one: ACF PRO's own repeater `update_value()` deletes
  rows `[new..old)` for sub-fields still in the group *before* this route's sweep runs, so
  `stale_rows_deleted` is legitimately **0** on a clean shrink. The response now also carries
  **`stale_rows_found`** — rows above the incoming row count, measured pre-write — which is the
  number a caller actually wants; `stale_rows_deleted` stays what the route itself removed, i.e.
  what ACF can't see: sub-fields since dropped from the group, and rows orphaned above a stale
  count row (the sweep now scans by real row index, not the old `[after,before)` window). Rows of
  a neighbouring field whose name collides with a row index (`{name}_2_…` vs field `{name}_2`) are
  excluded — the index is skipped when `{name}_{i}` resolves to a registered field. Hero exclusivity guard (CONFIG:
  `SITEBRIDGE_HERO_BLOCK`/`SITEBRIDGE_HERO_TOGGLE`, defaults suit one common theme profile, '' disables): setting the
  toggle true on a post whose content carries the hero block → 409 `hero_conflict` (double
  render); setting it false is the sanctioned state on block pages.
- **Cache purge** (v1.15+): `POST /purge-cache` — optional `url` (path or absolute; per-URL purge
  where the engine supports it) and/or `post_id` (resolves permalink + `clean_post_cache`); neither
  = full-site purge. Detects and fires: WP Engine, Kinsta, W3TC, WP Rocket, WP Super Cache,
  LiteSpeed, SG Optimizer, Breeze, NitroPack (v1.16+), external object cache. Returns
  `{scope, url, detected[], fired[], note}` — empty `detected` means the stale layer is upstream
  (CDN/proxy) and needs a host-level purge; `note` also flags engines where a url-scoped request
  could only fire a full purge. **NitroPack is special**: its drop-in serves pages before WP loads
  and its auto-invalidation only hooks post saves, so options-level changes need this explicit
  purge; the purge calls NitroPack's remote API, so the branch is `try/catch`-guarded — on failure
  (or plugin-installed-but-not-connected) `nitropack` appears in `detected` but not `fired`, and
  the `note` says so. Its per-URL purge is real (local drop-in + remote), so no `partial` there.

- **Capability findings** (v1.18+): `GET sitebridge/v1/capability-findings` — the one route on the
  NEW `sitebridge/v1` namespace (new surface only; `bam/*` stays legacy-frozen). Read-only site
  fingerprint for the connector's `capability_report` tool: environment, hosting signatures,
  plugin inventory (SEO/ACF/builders/cache/security/multilingual), redirect handler + hidden-
  redirect-plugin suspect scan, content-storage classification (raw `get_post_meta()` only — the
  collector NEVER calls `get_field()`, theme value-filters can fatal), empty published pages,
  schema emitters (output-buffer `wp_head` primary, cache-busted self-fetch fallback), nav
  mechanisms, sitemap. The plugin collects, never judges — verdicts live in the connector's
  rulebook. Provably read-only: zero writes/transients, per-request `$wpdb` query monitor ships a
  write count as `read_only_attestation`; rate limiting deliberately omitted (a transient
  throttle would itself write). Sections run in individual try/catch under a 50s deadline —
  failures land in `collection_status.failed_sections`, the run still returns.

Site-/theme-specific tailoring is centralized in the **CONFIG/PROFILE** block at the top of the
file, overridable via `wp-config` constants / filters. Keep new tailoring there, not scattered
through the code.

## Tests

`php tests/nav-acceptance.php` — 89 assertions over the whole nav module, WP/ACF stubbed in-file
(no WordPress, no PHPUnit, no network). Covers remove-item's gates, the byte-for-byte preservation
of untouched siblings, the add/remove round-trip, and regressions on remove-link. Run it after any
nav change. It does NOT replace a live pass on staging — real ACF serialization and the theme's
render are out of its reach.

`php tests/acf-fields-acceptance.php` — 54 assertions over the `/acf-fields` route with an ACF
storage emulation faithful to real persistence (value rows, `_`-reference rows holding field
keys, repeater count + `{name}_{i}_{sub}` rows, **and ACF's own shrink cleanup** — the stub
deletes rows `[new..old)` for registered sub-fields, as `acf-field-repeater.php::delete_row`
does, which is what makes the v1.17.1 counter behavior testable). Covers the five handoff
acceptance criteria in logic form, reference-row repair (healed + skipped), the hero guard,
all-or-nothing input validation, null normalization, and the stale-row sweep (orphans ACF can't
see, the same-prefix sibling field it must not touch, `clear_stale_rows:false`). Its live
assumptions (sub-field keys resolve individually via `acf_get_field()`; composite keys return
false) were verified against ACF PRO 6.8.4 on a live install 2026-08-13.

`php tests/purge-acceptance.php` — the purge route's NitroPack branch, one subprocess per
`function_exists()`/`defined()` state (present/connected, disconnected, API-throws, legacy
`nitropack_purge()`, absent). The absent scenario doubles as the no-behavior-change check for
non-NitroPack hosts (WP Engine sites must return byte-identical purge responses).

`php tests/capability-acceptance.php` — 96 assertions over the capability-findings route, five
subprocess scenarios (profile-a-shaped, no-ACF, builder, schema-fetch fallback, section failure).
Every scenario asserts read-only at the SQL/options layer and that `get_field()` is never called.
Its live assumptions (real query log shows zero writes; pre-audit diffs on two production sites) are the
release acceptance pass.

## Self-updater

`pre_set_site_transient_update_plugins` → polls `api.github.com/repos/{SITEBRIDGE_GH_REPO}/releases/latest`,
offers the release's `.zip` asset, renames the unpacked `repo-tag/` dir to the plugin slug.
`SITEBRIDGE_GH_REPO = 'bam-adv/sitebridge-ai'`. Runs for manual AND background auto-updates.

### Signed releases (required since v1.11.0)

Every release `.zip` is verified against the embedded Ed25519 public key (`SITEBRIDGE_UPDATE_PUBKEY`)
in an `upgrader_pre_download` hook **before install** (`sodium_crypto_sign_verify_detached`). A
missing / invalid / unverifiable signature is **refused** (fail-closed) with an admin notice +
`error_log`; the source-zipball fallback carries no `.sig`, so it's refused too. The **private key is
held offline** by the maintainer (password manager) — never in this repo or CI. (A checksum published
in the same release would not help: whoever can publish the zip can publish its checksum; only a
signature they can't forge defends a compromised release channel.)

**To cut a release (v1.11.0 onward):**
1. Bump `Version:` (header) + `SITEBRIDGE_VERSION`.
2. Build the plugin `.zip` (must unpack to a `sitebridge-ai/` directory).
3. Sign it: `SB_SIGN_KEY='<base64 secret from your password manager>' scripts/sign-release.sh sitebridge-ai.zip` → writes `sitebridge-ai.zip.sig`.
4. Publish the GitHub release with **both** `sitebridge-ai.zip` and `sitebridge-ai.zip.sig` attached.

Every release from v1.11.0 on **must** be signed or sites refuse it. Existing v1.10.0 installs upgrade
to v1.11.0 without verification (old updater) — that's the graceful cutover; they verify everything
after. **Key rotation:** ship a manual plugin update carrying a new `SITEBRIDGE_UPDATE_PUBKEY`, then
sign all later releases with the matching new private key.

## Deployment / fleet — intentionally NOT listed here

This plugin is host-agnostic and runs on whatever sites/environments it's installed on (WP Engine,
Kinsta, staging, new dealerships, …). That roster — domains, hosting, SSH slugs — **changes over
time, so it is deliberately not hardcoded in this repo.** For the current live roster, enumerate it
from the connector's **`list_sites`** (the source of truth). Any host-specific access detail (e.g.
WP Engine SSH slug conventions) belongs with fleet operations, not with the plugin code.
