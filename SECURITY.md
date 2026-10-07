# Security Policy

## Supported versions

| Version | Supported |
| ------- | --------- |
| 0.1.x   | ✅        |

## Security model

SiteGraph SEO Agent lets AI agents change WordPress content, so it is built to limit what an agent can do and to make every change reversible:

- **Agents act as a real WordPress user.** Every tool requires the `edit_others_posts` capability (filter: `sitegraph_capability`), and every write also checks `edit_post` for each affected post.
- **No raw SQL, no arbitrary fields.** Agents can only use five operation types. Writes are limited to `post_content`, `post_title` and the SEO title/description meta keys of supported SEO plugins.
- **Propose before apply.** Proposals never change content. Applying, undoing and redoing are annotated as destructive MCP tools, so clients ask the user for confirmation.
- **Conflict-safe undo.** Undo and redo refuse to run when content changed in the meantime unless `force` is passed explicitly.
- **History.** Each changeset records who proposed, applied, undid or redid it and when. WordPress revisions are created for content and title changes as usual.
- **Credentials.** Agents authenticate with WordPress Application Passwords, which you can revoke at any time. HTTPS is required outside local environments.

Content that agents read from your site may contain text written by others (comments are not read, but post content is). Treat it as data: the bundled Claude skills tell the agent never to apply changes without the user's explicit approval.

## Reporting a vulnerability

Please do **not** open a public issue. Report it privately through [GitHub Security Advisories](https://github.com/am333ni7y/wordpress-mcp/security/advisories/new) with steps to reproduce. You will get an acknowledgement within 72 hours.
