=== SiteGraph SEO Agent ===
Contributors: aminzahed
Tags: seo, internal links, search console, ai agent, mcp
Requires at least: 6.9
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 0.1.1
License: MIT
License URI: https://opensource.org/licenses/MIT

Find your weak pages, get a prioritized SEO action plan, and let AI agents fix issues as reviewable changesets with undo and redo.

== Description ==

SiteGraph SEO Agent maps how every published page on your site links to the others, combines that with the Google Search Console export you upload, and scores each page from 0 to 100 with the reasons it is weak.

It turns those findings into a short action plan — what to do, why, on which pages, and how long it takes — and exposes everything to AI agents such as Claude through the official WordPress [MCP Adapter](https://wordpress.org/plugins/mcp-adapter/).

Agents never edit content directly. Every change is proposed as a changeset with a before/after preview, applied in one step after you approve it, and can be undone or redone. Undo refuses to overwrite edits made after the change, so a later edit is never replaced without you noticing. You can do all of this from wp-admin without any agent, too.

= What it finds =

* Orphan pages, pages that cannot be reached by clicking, and pages buried four or more clicks deep
* Broken internal links and dead-end pages
* Thin and short content, stale content, missing meta descriptions and title length problems
* With Search Console data: low click-through rate for the position, striking-distance pages (positions 8–20) and pages with no impressions
* Pages that compete for the same topic

= What you get =

* **Overview** — average page score, weak and critical pages, orphans, broken links and the most common issues
* **Weak pages** — every page ranked weakest first, with the reasons and the fix
* **Page report** — inbound and outbound links, signals, search data, and existing sentences on other pages that could link here
* **Action plan** — ranked tasks with impact, effort and whether an agent can do them
* **Link graph** — a map of click depth and page strength
* **Changes** — every change with a diff, history, Apply, Undo and Redo

= What agents can do =

* Read the site overview, weak pages, page reports and the action plan
* Suggest internal links using sentences that already exist on related pages
* Propose changesets that add internal links, edit text, or set post titles, SEO titles and meta descriptions (stored in Yoast SEO, Rank Math or SEOPress when active, or printed by SiteGraph itself)
* Apply, undo, redo and discard changesets after you approve them, and compare search metrics before and after

Agents connect over MCP as your WordPress user, with an Application Password you can revoke at any time. A ready-made [SiteGraph SEO plugin for Claude Code](https://github.com/am333ni7y/sitegraph-seo-agent/tree/main/claude-plugin) teaches Claude a review-first workflow.

= Credits =

SiteGraph SEO Agent is made by [AMEEEN ZED](https://aminzahed.ir/) and is part of [WP Needs](https://wp-needs.com/), a home for WordPress plugins, themes and support. Source code, documentation and issues are on [GitHub](https://github.com/am333ni7y/sitegraph-seo-agent).

SiteGraph SEO Agent is an independent project. It is not affiliated with or endorsed by Google, Anthropic, Yoast, Rank Math or SEOPress.

== Installation ==

1. Install and activate the **MCP Adapter** plugin if you want AI agents to connect. SiteGraph's own screens work without it.
2. Install SiteGraph SEO Agent from **Plugins → Add New**, or upload the `sitegraph-seo-agent` folder to `/wp-content/plugins/`, and activate it.
3. Open **SiteGraph SEO** in wp-admin and click **Scan site**.
4. Optional but recommended: in Search Console open **Performance → Search results → Export → Download CSV** and upload `Pages.csv` on the SiteGraph Overview screen.
5. To use an agent, open **Connect an agent**, click **Generate a connection**, and enter the MCP URL and access token in your MCP client.

== Frequently Asked Questions ==

= Does scanning change my content? =

No. Scanning only reads published content. Content changes only happen when someone applies a changeset. An applied changeset can be undone unless the post was edited afterwards; then Undo stops and tells you instead of overwriting that edit.

= Do I need an AI agent? =

No. Reports, the action plan, link suggestions and changesets with undo and redo all work from wp-admin. An agent adds the ability to ask questions in plain language and to draft fixes for you.

= Who can use it? =

Users with the `edit_others_posts` capability (Editors and Administrators). Each change also checks that the user can edit that post. Use the `sitegraph_capability` filter to change it.

= Does the plugin send data anywhere? =

No. SiteGraph makes no outbound requests, has no telemetry and stores everything in your own database. When you connect an AI agent, the agent reads data from your site through the tools you allow, under that agent provider's own terms. The links to the author's websites on the Plugins screen and in SiteGraph's footer are plain links with UTM campaign parameters; nothing is loaded unless you click them.

= Where are SEO titles and descriptions stored? =

In your SEO plugin's fields when Yoast SEO, Rank Math or SEOPress is active. Otherwise SiteGraph stores them and prints the meta description and title tag itself.

= What happens when I uninstall it? =

SiteGraph's own tables and options are removed. Content changes you applied stay in your posts, including SEO titles and meta descriptions SiteGraph stored in post meta; without an SEO plugin they are no longer printed once SiteGraph is gone. The Application Password created for an agent is not removed automatically: revoke it under **Users → Profile**.

== Screenshots ==

1. Overview: average page score, weak pages, orphans, broken links, top actions and the weakest pages.
2. Weak pages ranked weakest first, with the reasons, links, depth, words and search data.
3. Page report with existing sentences on other pages that could link to this page.
4. Action plan with impact, effort and whether an agent can do each task.
5. Review a proposed changeset before anything on the site changes.
6. Every change with Apply, Discard, Undo and Redo.
7. An undone changeset with its history and before/after impact.
8. Link graph: click depth from the home page and page strength.
9. Connect an AI agent over MCP.

== Changelog ==

= 0.1.1 =
* Connect screen shows the MCP URL and access token for MCP clients and the SiteGraph SEO plugin for Claude Code.
* Plugin and action links, credits. The `sitegraph_show_credits` filter hides the credits line.
* Prepared database queries use identifier placeholders throughout; passes Plugin Check with no errors or warnings.

= 0.1.0 =
* First release: link graph, page scores, action plan, link suggestions, Search Console CSV import, changesets with undo and redo, MCP server, wp-admin screens.

== Upgrade Notice ==

= 0.1.1 =
Connection details for MCP clients and code-quality fixes. No data changes.
