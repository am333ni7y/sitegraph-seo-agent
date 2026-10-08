# SiteGraph SEO for Claude

Skills and an MCP connection that let Claude audit a WordPress site running the **SiteGraph SEO Agent** plugin, explain which pages are weak and why, and fix internal links and search snippets. Every change is proposed as a changeset you review first, and can be undone or redone at any time.

## What you need

- A WordPress 6.9+ site with the [SiteGraph SEO Agent](https://github.com/am333ni7y/sitegraph-seo-agent) plugin and the [MCP Adapter](https://wordpress.org/plugins/mcp-adapter/) plugin active.
- In wp-admin, open **SiteGraph SEO → Connect an agent** and click **Generate a connection**. Copy the **MCP URL** and **access token** it shows.
- When you enable this plugin, Claude Code asks for those two values. The token is stored in your system's secure credential store.

## Skills

| Skill | What it does |
|---|---|
| `seo-audit` | Scans the site, checks Search Console coverage and reports the 3–5 changes that would help most, with evidence |
| `fix-internal-links` | Finds existing sentences that can link to orphan, buried or page-2 pages, proposes the links, applies them after you approve |
| `rewrite-snippets` | Drafts SEO titles and meta descriptions for low-CTR pages, applies only the options you pick |
| `review-changes` | Lists what changed, undoes or redoes changesets, and compares search metrics before and after |

Try: *"Audit my site with SiteGraph and tell me the five changes that would help most."*

## What this plugin runs and sends

- It declares one remote MCP server: the URL you enter, which is your own WordPress site. It sends requests only there, authenticated with the token you enter.
- It contains no executable code, hooks or scripts — only the skill instructions above and the MCP server declaration.
- Reading reports and proposing changes never modify your site. Applying, undoing and redoing a changeset do, and the skills tell Claude to do that only after you explicitly approve.
- If you give Claude a Search Console export, the skills send its page rows to your site's `import-search-data` tool and nowhere else.

The plugin author receives no data. See the [privacy policy](https://github.com/am333ni7y/sitegraph-seo-agent/blob/main/PRIVACY.md).

## Credits

Made by [AMEEEN ZED](https://aminzahed.ir/). Part of [WP Needs](https://wp-needs.com/) — WordPress plugins, themes and support.

## License

MIT — see [LICENSE](LICENSE).
