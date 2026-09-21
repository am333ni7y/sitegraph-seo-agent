# 🚀 WordPress Model Context Protocol (MCP) Server

[![TypeScript](https://img.shields.io/badge/TypeScript-5.7-blue.svg?logo=typescript)](https://www.typescriptlang.org/)
[![Model Context Protocol](https://img.shields.io/badge/MCP-Protocol-7057ff.svg)](https://modelcontextprotocol.io/)
[![License: MIT](https://img.shields.io/badge/License-MIT-green.svg)](LICENSE)
[![PRs Welcome](https://img.shields.io/badge/PRs-welcome-brightgreen.svg)](CONTRIBUTING.md)
[![GitHub Stars](https://img.shields.io/github/stars/am333ni7y/wordpress-mcp.svg?style=social)](https://github.com/am333ni7y/wordpress-mcp)

**English Documentation** | [راهنمای فارسی (Persian)](README.fa.md)

A high-performance, enterprise-grade **Model Context Protocol (MCP)** server connecting WordPress sites directly to modern AI agents (**Claude Code**, **Cursor**, **Google Antigravity**, **Windsurf**, etc.).

Designed with a **Dual-Engine Architecture** and **Zero-Trust Security Guard**, it allows AI agents to inspect site health, query posts and pages, explore ACF & custom post types, optimize SEO, and manage content safely without exposing your site to hacks or prompt injection vulnerabilities.

---

## 🏗 Architecture Overview

```
                   ┌─────────────────────────────────────────┐
                   │  AI Agents (Claude Code, Cursor, AGY)   │
                   └────────────────────┬────────────────────┘
                                        │  JSON-RPC / Stdio
                                        ▼
             ┌─────────────────────────────────────────────────────┐
             │                wordpress-mcp Server                 │
             │   ┌──────────────────────────────────────────────┐  │
             │   │    Zero-Trust Security Guard (Safe Mode)     │  │
             │   └──────────────────────┬───────────────────────┘  │
             └──────────────────────────┼──────────────────────────┘
                                        │
                    ┌───────────────────┴───────────────────┐
                    ▼                                       ▼
        ┌───────────────────────┐               ┌───────────────────────┐
        │     WpCliEngine       │               │      WpRestEngine     │
        │   (LocalWP / CLI)     │               │ (Remote REST + Auth)  │
        └───────────┬───────────┘               └───────────┬───────────┘
                    │                                       │
                    ▼                                       ▼
         [ Local Development ]                    [ Live Production ]
       LocalWP, Docker, Valet                    WordPress REST API
```

---

## 🌟 Key Features

- **Dual-Engine Flexibility**:
  - **Local Engine (`cli`)**: Connects with zero latency to local installations (LocalWP, Valet, Docker, native WP-CLI) with automatic PHP error and notice filtering.
  - **Remote Engine (`rest`)**: Securely interfaces with remote production or staging sites over HTTPS via standard WordPress **Application Passwords**.
- **Zero-Trust Security Guard**:
  - **Read-Only by Default**: All state-mutating actions (`wp_create_post`, `wp_update_post`) are blocked unless explicitly allowed via `--allow-write` or `SAFE_MODE=false`.
  - **No Raw Eval / No Raw SQL**: The AI interacts exclusively through strictly validated, typed Zod schemas.
  - **Input Sanitization**: Automatically strips null bytes and malicious control characters.
  - **Audit Logging**: Optional audit trail logs every single invocation and argument set with timestamps.
- **Built-in Interactive Web Playground**:
  - Includes an embedded web playground (`npm run dashboard`) running on `http://localhost:3300` for visual verification and multi-site toggling.

---

## 🛠 Available Tools

| Tool | Description | Safe Mode Status |
| :--- | :--- | :--- |
| `wp_get_site_info` | Retrieves site URL, WordPress core version, active theme, name, and security status. | Allowed |
| `wp_list_posts` | Searches and filters posts/pages by keyword, post type, status, and pagination. | Allowed |
| `wp_get_post` | Fetches full post content (including Gutenberg blocks), metadata, and custom fields by ID. | Allowed |
| `wp_create_post` | Creates a new post or page with title, body, status, categories, and meta fields. | **Blocked in Safe Mode** |
| `wp_update_post` | Updates existing post content, title, excerpt, status, or meta fields. | **Blocked in Safe Mode** |
| `wp_list_plugins` | Lists installed plugins, active/inactive states, version numbers, and update status. | Allowed |
| `wp_get_taxonomies_and_types` | Inspects all registered Custom Post Types (CPTs) and Taxonomies. Ideal for coding templates. | Allowed |
| `wp_get_debug_log` | Tails recent errors and warnings from `debug.log` for real-time PHP troubleshooting. | Allowed (CLI only) |

---

## 🚀 Quick Start

### 1. Installation

```bash
git clone https://github.com/am333ni7y/wordpress-mcp.git
cd wordpress-mcp
npm install
npm run build
```

---

## ⚙️ Client Configurations

### A. Cursor (`~/.cursor/mcp.json`)

Add the server to your Cursor MCP settings:

**For Local Sites (LocalWP / WP-CLI):**
```json
{
  "mcpServers": {
    "wordpress-local": {
      "command": "node",
      "args": [
        "/absolute/path/to/wordpress-mcp/dist/index.js",
        "--path", "/path/to/wordpress/site",
        "--adapter", "cli"
      ]
    }
  }
}
```

**For Remote Sites (REST API):**
```json
{
  "mcpServers": {
    "wordpress-live": {
      "command": "node",
      "args": [
        "/absolute/path/to/wordpress-mcp/dist/index.js",
        "--adapter", "rest",
        "--url", "https://your-site.com",
        "--username", "your_admin_user",
        "--password", "xxxx xxxx xxxx xxxx"
      ]
    }
  }
}
```

> **How to create an Application Password:** In your WordPress admin, go to **Users > Profile**, scroll to **Application Passwords**, enter a name (e.g. `Cursor-MCP`), and click **Add New Application Password**.

---

### B. Claude Code CLI

```bash
# For Local Sites
claude mcp add wp-local node /absolute/path/to/wordpress-mcp/dist/index.js -- --path "/path/to/site" --adapter cli

# For Remote Sites
claude mcp add wp-live node /absolute/path/to/wordpress-mcp/dist/index.js -- --adapter rest --url "https://your-site.com" --username "your_user" --password "xxxx xxxx xxxx xxxx"
```

---

### C. Google Antigravity

Add to `~/.gemini/config/mcp_config.json`:
```json
{
  "mcpServers": {
    "wordpress": {
      "command": "node",
      "args": [
        "/absolute/path/to/wordpress-mcp/dist/index.js",
        "--adapter", "rest",
        "--url", "https://your-site.com",
        "--username", "your_user",
        "--password", "xxxx xxxx xxxx xxxx"
      ]
    }
  }
}
```

---

## 🎮 Interactive Web Playground

Need to verify tool responses or test connectivity before configuring your AI agent?
Run the built-in visual tester:

```bash
npm run dashboard
```

Then visit **`http://localhost:3300`** in your browser. You can execute tools with a single click, view raw JSON-RPC responses, switch sites, and toggle Safe Mode interactively.

---

## 🔒 Security Architecture

1. **Safe Mode Enforcement**:
   - Out of the box, `wordpress-mcp` is read-only.
   - Any attempt to invoke `wp_create_post` or `wp_update_post` returns a clear security error.
   - To permit write actions, pass `--allow-write` in your command args or set `SAFE_MODE=false`.
2. **Dedicated Credentials**:
   - Use WordPress Application Passwords that can be instantly revoked at any moment.
3. **Audit Trail**:
   - Pass `--audit-log /path/to/audit.log` to record every tool call with timestamps and arguments.

---

## 🤝 Contributing

Contributions, issues, and feature requests are welcome!
Please read our [Contributing Guide](CONTRIBUTING.md) and [Security Policy](SECURITY.md).

---

## 📄 License

This project is licensed under the [MIT License](LICENSE).  
Created with ❤️ by **Amin Zahed ([Ameeen](https://github.com/am333ni7y))**.
