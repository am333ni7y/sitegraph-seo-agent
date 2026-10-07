# Contributing

Thanks for helping improve SiteGraph SEO Agent. Bug reports, ideas and pull requests are welcome.

## Local setup

You need PHP 7.4+ and a WordPress 6.9+ site you can throw away. The quickest setup uses SQLite, so no MySQL server is needed. The CI workflow (`.github/workflows/ci.yml`) is the reference:

1. Download WordPress, the [SQLite Database Integration](https://wordpress.org/plugins/sqlite-database-integration/) plugin and the [MCP Adapter](https://wordpress.org/plugins/mcp-adapter/) plugin.
2. Create `wp-content/db.php` from the SQLite plugin's `db.copy` and a `wp-config.php` like the one in the workflow (`WP_ENVIRONMENT_TYPE` must be `local`).
3. Link or copy `sitegraph-seo-agent/` into `wp-content/plugins/`.
4. Install, seed and test:

   ```bash
   php tests/install-wordpress.php /path/to/wordpress
   php demo/seed-demo-site.php /path/to/wordpress --yes-wipe
   php tests/run-integration.php /path/to/wordpress
   ```

5. Serve it with `php -S localhost:8080` from the WordPress directory (add a small router so `/wp-json/` and pretty permalinks work) to use wp-admin and the MCP endpoint at `http://localhost:8080/wp-json/sitegraph/v1/mcp`.

`demo/demo-changesets.php` puts the demo site into the state shown in the README screenshots.

## Guidelines

- **Every content change goes through `Changesets`.** New write capabilities must be new operation types with before/after values, so undo and redo keep working.
- **Read-only tools must stay read-only.** Annotate every ability honestly (`readonly`, `destructive`, `idempotent`).
- **Add a test** to `tests/run-integration.php` for behavior you add or fix. The suite must pass and leave no PHP notices in `debug.log`.
- Keep PHP 7.4 compatible and follow the WordPress coding standards.
- Keep pull requests focused, and describe how you verified the change.
