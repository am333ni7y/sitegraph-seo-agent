=== SiteGraph SEO Agent ===
Contributors: aminzahed
Tags: seo, internal links, mcp, ai agent, search console
Requires at least: 6.9
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 0.1.1
License: MIT
License URI: https://opensource.org/licenses/MIT

Find your weak pages, get a prioritized SEO action plan, and let AI agents like Claude fix issues as reviewable changesets with undo and redo.

== Description ==

SiteGraph SEO Agent maps how every published page on your site links to the others, combines that with your Google Search Console data, and scores each page from 0 to 100 with the reasons it is weak.

It then turns those findings into a short action plan (what to do, why, on which pages, how long it takes) and exposes everything to AI agents through the official WordPress MCP Adapter.

Agents never edit content directly. Every change is proposed as a changeset with a before/after preview, applied in one step after you approve it, and can be undone or redone at any time. Undo refuses to overwrite edits made after the change, so nobody's work is lost silently.

**What it finds**

* Orphan pages, pages that cannot be reached by clicking, and pages buried four or more clicks deep
* Broken internal links and dead-end pages
* Thin and short content, stale content, missing meta descriptions, title length problems
* With Search Console data: low click-through rate for the position, striking-distance pages (positions 8–20) and pages with no impressions
* Pages that compete for the same topic

**What agents can do**

* Suggest internal links using sentences that already exist on related pages
* Add internal links, edit text, set post titles, SEO titles and meta descriptions (Yoast SEO, Rank Math, SEOPress, or SiteGraph's own output)
* Review, apply, undo, redo and discard changesets, and compare search metrics before and after

== Installation ==

1. Install and activate the **MCP Adapter** plugin so agents can connect.
2. Upload `sitegraph-seo-agent` to `/wp-content/plugins/` and activate it.
3. Open **SiteGraph SEO** in wp-admin and click **Scan site**.
4. Optional: upload the Pages CSV from Search Console on the Overview screen.
5. Open **Connect an agent**, click **Generate a connection**, and enter the MCP URL and access token in the SiteGraph SEO plugin for Claude Code (or use the claude mcp add command shown there).

== Frequently Asked Questions ==

= Does scanning change my content? =

No. Scanning only reads published content. Content changes only happen when someone applies a changeset.

= Who can use it? =

Users with the `edit_others_posts` capability (Editors and Administrators). Each change also checks that the user can edit that post. Filter `sitegraph_capability` to change it.

= Where are SEO titles and descriptions stored? =

In your SEO plugin's fields when Yoast SEO, Rank Math or SEOPress is active. Otherwise SiteGraph stores them and prints the meta description and title tag itself.

== Changelog ==

= 0.1.1 =
* Connect screen shows the MCP URL and access token for the SiteGraph SEO plugin for Claude Code.
* Project renamed to sitegraph-seo-agent on GitHub.

= 0.1.0 =
* First release: link graph, page scores, action plan, link suggestions, Search Console import, changesets with undo and redo, MCP server, wp-admin screens.
