# WordPress.org submission guide: SiteGraph SEO Agent 0.1.1

Status: prepared, NOT submitted. Nothing has been uploaded, tagged or released.
Repo: github.com/am333ni7y/sitegraph-seo-agent (branch `main`). Plugin folder: `sitegraph-seo-agent/`.
Facts below were checked against the files named in each section. Items that could not be verified from files are marked "unverified".

## 1. Pre-submission checklist

- [ ] **WordPress.org account** exists, with an email address you check regularly. Whitelist `plugins@wordpress.org` so review emails arrive (per developer.wordpress.org "Planning, submitting and maintaining plugins").
- [ ] **Contributors username is real.** `sitegraph-seo-agent/readme.txt` line 2 currently says `Contributors: aminzahed`. This is a placeholder awaiting the owner's answer. Replace it with the exact WordPress.org username (comma-separated if several) before building the zip. FLAG: do not submit until confirmed.
- [ ] **Version agreement.** Verified: `sitegraph-seo-agent.php` header `Version: 0.1.1`, constant `SITEGRAPH_VERSION` `0.1.1`, `readme.txt` `Stable tag: 0.1.1`, changelog top entry `= 0.1.1 =`. All match. `release.yml` also fails the build if header and Stable tag differ, or if the release tag (minus `v`) differs.
- [ ] **Compatibility headers match.** Header and readme both say `Requires at least: 6.9`, `Requires PHP: 7.4`, `License: MIT`. `readme.txt` says `Tested up to: 7.1`. Verified 2026-10-08: the local test site runs WordPress 7.1.3, and `api.wordpress.org/core/version-check/1.7/` lists 7.1.3 as the current release.
- [ ] **Plugin Check clean.** CI job `plugin-check` (`wordpress/plugin-check-action@v1`, `build-dir: ./sitegraph-seo-agent`) in `.github/workflows/ci.yml`. Verified locally on 2026-10-08 with Plugin Check 2.1.0 on WordPress 7.1.3: `Success: Checks complete. No errors found.` Re-run after any later change.
- [ ] **Integration tests 68/68.** CI job `integration` runs `php tests/run-integration.php` on WordPress latest + SQLite + MCP Adapter, then fails on any PHP notice in `debug.log`. Verified locally on 2026-10-08: `68 passed, 0 failed`, and no `debug.log` was written.
- [ ] **Assets present** in `.wordpress-org/` (sizes measured with System.Drawing):

  | File | Measured size |
  |---|---|
  | banner-772x250.png | 772x250 |
  | banner-1544x500.png | 1544x500 |
  | icon-128x128.png | 128x128 |
  | icon-256x256.png | 256x256 |
  | screenshot-1.png | 1440x1236 |
  | screenshot-2.png | 1440x1065 |
  | screenshot-3.png | 1440x1061 |
  | screenshot-4.png | 1440x1450 |
  | screenshot-5.png | 1440x991 |
  | screenshot-6.png | 1440x576 |
  | screenshot-7.png | 1440x911 |
  | screenshot-8.png | 1440x1086 |
  | screenshot-9.png | 1440x779 |

  Banner and icon names/sizes match the required pattern. Screenshots are free-size PNGs; all are 1440 px wide.
  All 13 files were compressed with [Iminify](https://www.iminify.com/) (level `smart`, PNG kept, metadata stripped) on 2026-10-08: the folder went from about 1.6 MB to 0.52 MB. Text stays sharp; check by eye after any retake. Retakes come from `take-screenshots.ps1` (outside the repo) into `docs/screenshots/` and are then copied to `screenshot-N.png` in the order below.
- [ ] **Screenshot captions match files.** `readme.txt` `== Screenshots ==` lists 9 captions; `.wordpress-org/` has screenshot-1..9. The numbers map one-to-one. Each image was retaken and opened on 2026-10-08 to confirm it shows the captioned SiteGraph screen (not the login page) and no token or password; `screenshot-N.png` is byte-identical to its source in `docs/screenshots/`:

  | # | Caption (readme.txt) | Source image in `docs/screenshots/` |
  |---|---|---|
  | 1 | Overview | overview.png |
  | 2 | Weak pages | weak-pages.png |
  | 3 | Page report | page-report.png |
  | 4 | Action plan | action-plan.png |
  | 5 | Review a proposed changeset | changeset-review.png |
  | 6 | Every change with Apply/Discard/Undo/Redo | changes.png |
  | 7 | Undone changeset with history | changeset-undone.png |
  | 8 | Link graph | link-graph.png |
  | 9 | Connect an AI agent over MCP | connect.png |

  No count mismatch found.
- [ ] **Slug.** WordPress.org derives the slug from the plugin name (`Plugin Name: SiteGraph SEO Agent`), which should give `sitegraph-seo-agent`. This equals the folder name, the text domain (`sitegraph-seo-agent`) and the `SLUG`/`BUILD_DIR` in `release.yml`. The docs say the plugin URL cannot be changed after submission, while the display name can. Reviewers may ask for a change, and the final slug is only known after review.
- [ ] **No outbound requests.** `grep` for `wp_remote` / `curl` in `sitegraph-seo-agent/` finds nothing, which agrees with the readme FAQ "Does the plugin send data anywhere? No."
- [ ] Working tree committed (the zip is built from `HEAD`, not from uncommitted files).

## 2. How to submit

1. Build the zip from the committed state (PowerShell or Git Bash, from the repo root):

   ```
   git -c core.autocrlf=false archive --format=zip --prefix=sitegraph-seo-agent/ -o ../sitegraph-seo-agent-0.1.1.zip HEAD:sitegraph-seo-agent
   ```

   Keep `-c core.autocrlf=false`: this Windows clone has `core.autocrlf=true`, and without the override `git archive` writes CRLF line endings into the zip. The committed files are LF; check that the zip has no `\r` bytes.

   This packages only the plugin folder (`readme.txt`, main file, `includes/`, `assets/`, `uninstall.php`) under a top-level `sitegraph-seo-agent/` directory. `tests/`, `demo/`, `claude-plugin/` and `.wordpress-org/` stay out. Open the zip once and check the top-level folder name and that `readme.txt` has the real Contributors username.
2. Log in and upload at https://wordpress.org/plugins/developers/add/ with a short description of what the plugin does.
3. Wait for the review email. The docs say the code is reviewed "within 14 business days" of being queued; the actual time varies.

### What reviewers commonly ask about
Only items the official plugin guidelines and developer handbook cover; they are all relevant here:

- **Sanitizing, validating, escaping, nonces, capability checks.** The code has many `// phpcs:ignore WordPress.Security.EscapeOutput` lines in `includes/class-admin.php` on echo of helper output (for example `score_pill`, `button_form`). Be ready to explain that those helpers escape internally; if a reviewer is unconvinced, escape at the call site.
- **Prefixing / unique names.** Code lives in the `SiteGraph` namespace; constants use `SITEGRAPH_`; hooks use `sitegraph_`.
- **No external calls without user consent.** None exist (see above).
- **Readme accuracy.** Readme, header, changelog and behavior must agree (the FAQ privacy answer, "Requires at least", the MCP Adapter note).
- **Licensing.** Declared MIT (see decision a).
- **Direct database queries.** See decisions b and c.
- **Promotion/credits.** See decision e.

### How to reply
- Reply to the review email itself (do not open a new thread). Answer each point, say what changed.
- If code changes are needed, upload a fixed zip as the email instructs, keeping the same slug. Bump `Version`/`Stable tag` only if you want a new version number; the same-version resubmission is normal during review, but follow the reviewer's instruction.
- Keep GitHub `main` and the zip identical so the later release matches what was approved.

## 3. After approval

1. WordPress.org emails SVN repository details.
2. In GitHub (Settings, Secrets and variables, Actions) create repository secrets with these exact names, as read by `.github/workflows/release.yml`:
   - `SVN_USERNAME`
   - `SVN_PASSWORD`
3. Publish a GitHub release tagged `v0.1.1` (tag minus `v` must equal the header Version and the Stable tag). The `release` workflow (`on: release: published`, also `workflow_dispatch`) does this:
   - `Check versions match`: compares header `Version`, readme `Stable tag`, and the release tag; fails on any difference.
   - `Build zip`: `zip -rq sitegraph-seo-agent.zip sitegraph-seo-agent` (excludes `.DS_Store`, `Thumbs.db`); uploads it as a workflow artifact.
   - `Attach zip to the release`: `gh release upload ... --clobber` (release events only).
   - `Deploy to WordPress.org`: only when the event is a release AND `SVN_USERNAME` is non-empty. It runs `10up/action-wordpress-plugin-deploy@stable` with `SLUG: sitegraph-seo-agent`, `BUILD_DIR: sitegraph-seo-agent`, `ASSETS_DIR: .wordpress-org`. That action copies the build dir to SVN `trunk`, creates the tag from the version, and copies `.wordpress-org/` to SVN `/assets` (banners, icons, screenshots). Without the secrets, the release still builds and attaches the zip; the deploy step is skipped.
   - Note: this workflow builds the zip with `zip` from the checkout, while step 2 of section 2 uses `git archive`; contents should be equivalent.
4. Screenshots shown on the plugin page come from `/assets` and are numbered to the readme captions, so renaming a file or reordering captions breaks the mapping.
5. Never put the SVN password in the repo, in `release.yml`, or in chat.

## 4. Decision records

### a) MIT license
- **Context:** The plugin must be GPL-compatible to be hosted on WordPress.org.
- **Chosen:** MIT. Evidence: `License: MIT` and `License URI: https://opensource.org/licenses/MIT` in both the plugin header and `readme.txt`; `LICENSE` exists in the repo root. MIT is GPL-compatible and accepted by WordPress.org (HANDOFF section 3).
- **Rejected:** Switching to GPL-2.0-or-later. It is a legal decision that belongs to the owner and is not required.
- **Limits:** The license text must be the same in the header, readme and `LICENSE`. If a reviewer asks for GPL-compatible wording, MIT already satisfies it; keep the three places identical. Bundled third-party code, if any is added later, must also be compatible.

### b) Prepared queries with `%i` identifiers
- **Context:** Table names must be interpolated safely. Plugin Check flags unprepared queries.
- **Chosen:** `$wpdb->prepare( '... FROM %i ...', $table )`. Evidence: `%i` is used in `includes/class-abilities.php`, `class-admin.php`, `class-changesets.php`, `class-link-index.php`, `class-link-suggester.php`, `class-page-analyzer.php`, `class-search-data.php` and `uninstall.php` (for example `class-link-index.php` line 62 and lines 86-87). Changelog 0.1.1: "Prepared database queries use identifier placeholders throughout".
- **Requirement:** `%i` needs WordPress 6.2+. The plugin declares `Requires at least: 6.9` in the header and readme, so this is satisfied.
- **Rejected:** `esc_sql()` or concatenating `$wpdb->prefix . 'name'` into SQL (flagged by the checker, easier to get wrong); raising the minimum is not needed.
- **Limits:** Users on WordPress older than 6.9 cannot install it, which is the intended floor. Do not lower `Requires at least` below 6.2 without replacing `%i`.

### c) File-level `phpcs:disable` for DirectQuery / NoCaching only
- **Context:** The plugin keeps its link index, page scores, search data and changesets in its own tables. They change on every scan or edit, so caching them would return stale data, and there is no WordPress API for custom tables.
- **Chosen:** In each DB-touching file, a comment explains why and then `// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching`. Verified in `class-abilities.php`, `class-admin.php`, `class-changesets.php`, `class-link-index.php`, `class-link-suggester.php`, `class-page-analyzer.php`, `class-search-data.php`. `class-installer.php` additionally disables `WordPress.DB.DirectDatabaseQuery.SchemaChange` (it creates the tables).
- **Rejected:** Per-line ignores without explanation (noisy, unreviewable); disabling all of `WordPress.DB` or all of phpcs (hides real problems); caching with transients (wrong for fast-changing data).
- **Never use a bare `phpcs:enable`.** A bare `// phpcs:enable` re-enables every sniff, not just these two, so it would also switch back on rules disabled elsewhere in the same file. I found no `phpcs:enable` in the plugin, and the disables run to end of file on purpose. If you ever need to close the region, name the same two sniffs: `// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching`.
- **Limit:** The disable covers the whole file, so a new query on a core table in these files is also unflagged. Use the WordPress API (`WP_Query`, `get_post_meta`) for core data.

### d) No `Requires Plugins: mcp-adapter` header
- **Context:** WordPress 6.5+ supports a `Requires Plugins` header that forces dependencies at install.
- **Chosen:** Do not add it. Evidence: no `Requires Plugins` in the header of `sitegraph-seo-agent.php` or in `readme.txt`. The readme Installation step 1 and FAQ say the admin screens work without the MCP Adapter; only the agent connection needs it. Handoff: the screens work without MCP Adapter.
- **Rejected:** A hard dependency, which would make installation harder for users who only want the wp-admin reports and also blocks activation when the adapter is missing.
- **Limits:** A user who wants an agent must install MCP Adapter themselves; the readme links to https://wordpress.org/plugins/mcp-adapter/. Revisit if the Connect screen ever becomes the main feature.

### e) Promotion links
- **Context:** The author wants to promote WP Needs and the author site. WordPress.org guidelines restrict front-end credits, admin-wide nags and misleading upsells.
- **Chosen and where they appear (verified in `includes/class-admin.php` and `readme.txt`):**
  1. Plugin's own admin screen footer, `render_credits()` (called at line 440 inside the SiteGraph page only): "SiteGraph SEO Agent 0.1.1 · Made by AMEEEN ZED · Part of WP Needs · Documentation". The author and WP Needs links go through `campaign_url()` with `utm_source=sitegraph-seo-agent`, `utm_medium=wp-admin`, `utm_campaign=plugin`, `utm_content=credits`. The line is optional: `add_filter( 'sitegraph_show_credits', '__return_false' );` hides it (guideline 10 asks for credit displays to be optional).
  2. Plugins screen: `action_links()` adds "Open SiteGraph" (internal link). `row_meta()` adds Documentation, Support (GitHub) and "WP Needs" (UTM `utm_content=plugins-screen`) under the plugin row, only for this plugin's file.
  3. `readme.txt` `= Credits =` section: made by AMEEEN ZED, part of WP Needs, source on GitHub (plain links, no tracking).
- **Why this is within the guidelines:** No credits or links on the site front end; no notices on the dashboard or other admin screens; no banners, popups or nags; no feature gated behind the links; nothing requests or sends data, and each URL is only followed when the user clicks (consistent with the readme "no outbound requests" claim, and the readme FAQ discloses the UTM parameters). External links use `target="_blank" rel="noopener"` and `esc_url`.
- **Rejected:** Front-end footer credit, dashboard widget or admin notices (guideline risk), and tracking pixels or remote calls to count installs.
- **Limits:** UTM parameters are visible to the destination site only after a click. If a reviewer objects to the UTM tags or to the Plugins-screen "WP Needs" link, remove `campaign_url()` use first; keep the readme credit. Keep the amount of promotion small; reviewers judge it case by case.

### f) Plugin URI
- **Context:** `Plugin URI: https://wp-needs.com/` is the WP Needs home page. This was the owner's choice to promote the brand (HANDOFF section 3).
- **Chosen (current):** keep the homepage for now.
- **Recommendation:** Create a dedicated landing page, for example `https://wp-needs.com/sitegraph-seo-agent/`, with description, screenshots and a link to the WordPress.org listing, then set `Plugin URI` to it in the header. Reason: the Plugin URI is meant to be the plugin's own page; a generic homepage may draw a reviewer comment. It is a header-only change but needs a new version/zip, so do it before submitting if possible.
- **Limit:** Do not link the page back with extra tracking; it will be a normal external link.

## 5. Open items for the owner

1. Real WordPress.org username for `Contributors` (placeholder `aminzahed` in `readme.txt`).
2. Decide on a dedicated Plugin URI landing page (decision f) before building the zip.
3. Re-run the integration tests and Plugin Check (locally and in CI) after any of the above changes, then rebuild the zip.
