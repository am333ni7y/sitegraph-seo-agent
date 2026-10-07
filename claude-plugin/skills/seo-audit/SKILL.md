---
name: seo-audit
description: Audit a WordPress site connected through the SiteGraph SEO Agent MCP server. Use when the user asks which pages are weak, what is holding the site back, for an SEO audit or health check, or what to work on first. Produces a short, prioritized plan with numbers, not a generic checklist.
---

# SiteGraph SEO audit

You are working with a WordPress site through the **SiteGraph SEO Agent** MCP server. Its tools are named `sitegraph-*` (in Claude Code they appear as `mcp__<server>__sitegraph-…`, usually `mcp__sitegraph__sitegraph-…`). If no `sitegraph-*` tools are available, the connection is not set up: tell the user to open **SiteGraph SEO → Connect an agent** in wp-admin, click **Generate a connection**, and enter the MCP URL and access token when Claude Code asks for this plugin's settings. Users who prefer not to use the plugin's connection can run the `claude mcp add` command shown on the same screen instead. Then stop.

## Steps

1. **Scan.** Call `sitegraph-scan-site`. If `done` is false, call it again until it is true. Scanning reads the database only and changes no content.
2. **Check search data.** Call `sitegraph-get-site-overview`. If `search_data` is null, scores and the plan ignore real demand. Tell the user and offer two ways to fix it:
   - They export **Search Console → Performance → Search results → Export → CSV** and upload `Pages.csv` on the SiteGraph SEO screen, or give you the file. If they give you the file, parse it and call `sitegraph-import-search-data` with rows of `url`, `clicks`, `impressions`, `ctr`, `position`.
   - If a Search Console connector is available in this session, pull page-level data for the last 3 months and pass it to `sitegraph-import-search-data`.
   Continue without search data if the user prefers, and say that results are less precise.
3. **Get the plan.** Call `sitegraph-get-action-plan` (limit 6–8).
4. **Look closer at the top pages.** For the top two or three tasks, call `sitegraph-get-page-report` on the most important page of each, so you can explain concretely why it is weak.

## How to report

Lead with the answer. Keep it short:

- One sentence on overall health: pages analyzed, average score, how many weak or critical pages, orphans, broken links.
- **The top 3–5 actions**, in plan order. For each: what to do, which pages (by title), the evidence (impressions, position, CTR, inbound links, depth), the effort, and whether you can do it (`automation`: `agent` means you can propose the change; `assisted` means you can draft but the user decides; `decision` means the user must choose).
- Offer the next step: "Want me to propose the internal links for the orphan pages? I'll show you every change before anything is applied."

Do not paste raw JSON. Do not list every issue on every page. Use the page titles the user knows, not post IDs, unless you need to disambiguate.

## Rules

- Reading and proposing never change the site. Applying does. Never call `sitegraph-apply-changeset`, `sitegraph-undo-changeset` or `sitegraph-redo-changeset` during an audit.
- Numbers come from the tools. Do not invent traffic estimates or promise ranking changes.
- A score is a prioritization aid, not a Google metric. Say so if the user treats it as one.
