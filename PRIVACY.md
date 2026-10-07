# Privacy Policy

_Last updated: October 7, 2026_

This policy covers the **SiteGraph SEO Agent** WordPress plugin and the **SiteGraph SEO** plugin for Claude ("SiteGraph"), published by Amin Zahed.

## Summary

SiteGraph runs entirely on your own WordPress site and in your own Claude client. **The author does not operate any server for SiteGraph and receives no data from it** — no analytics, telemetry, accounts or tracking.

## Data collection practices

- **WordPress plugin.** When you scan, it reads your published posts and pages (titles, content, links, modification dates) and SEO title, description and focus keyword fields from your database. It stores the results — a link index, page scores and issues — in tables in your own WordPress database.
- **Search Console data.** If you upload a Search Console export or an agent sends one, SiteGraph stores page URLs, clicks, impressions, CTR and average position in your WordPress database. It does not connect to Google itself.
- **Changesets.** Proposed and applied changes, their before/after values, and the WordPress username and time of each action are stored in your WordPress database so they can be reviewed, undone and redone.
- **Claude plugin.** It stores the MCP URL and access token you enter in your Claude client's configuration and secure credential store. It contains no code that collects data.

## Usage and storage

Data is used only to show reports, build the action plan and apply, undo or redo the changes you approve. It stays in your WordPress database and your Claude client.

## Third-party sharing

SiteGraph does not send data to the author or to any third party. When you use SiteGraph through Claude, the content Claude reads from your site through the SiteGraph tools is processed by Anthropic as part of your conversation, under Anthropic's own terms and privacy policy.

## Data retention

Data is kept until you delete it. Re-scanning replaces the link index; importing search data in "replace" mode replaces previous search data. Uninstalling the WordPress plugin deletes its tables and options. Content changes you applied stay in your posts. Revoke the Application Password under **Users → Profile** to stop agent access at any time.

## Contact

Questions about this policy: open an issue at <https://github.com/am333ni7y/sitegraph-seo-agent/issues>, or report a security problem through [GitHub Security Advisories](https://github.com/am333ni7y/sitegraph-seo-agent/security/advisories/new).
