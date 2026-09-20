# 🚀 WordPress MCP Server (`wordpress-mcp`)

[![TypeScript](https://img.shields.io/badge/TypeScript-5.7-blue.svg)](https://www.typescriptlang.org/)
[![Model Context Protocol](https://img.shields.io/badge/MCP-Protocol-purple.svg)](https://modelcontextprotocol.io/)
[![License: MIT](https://img.shields.io/badge/License-MIT-green.svg)](LICENSE)

A high-performance, secure **Model Context Protocol (MCP)** server connecting WordPress websites to modern AI agents (**Claude Code**, **Google Antigravity**, **Cursor**, **Windsurf**, etc.).

Designed with a **Dual-Engine Architecture** and **Zero-Trust Security Guard**, it allows AI agents to inspect site health, explore taxonomies, query posts/pages, audit SEO, and perform content operations safely without exposing your site to hacks or prompt injection attacks.

---

[English Documentation](#overview) | [راهنمای فارسی](#راهنمای-فارسی)

---

## 🌟 Key Features

- **Dual-Engine Architecture**:
  - **WP-CLI Engine**: Connects instantly to local WordPress environments (LocalWP, Docker, Valet, native WP-CLI) with zero network overhead.
  - **REST API Engine**: Connects to remote or staging sites over HTTPS using WordPress standard **Application Passwords**.
- **Zero-Trust Security & Safe Mode**:
  - **Read-Only by Default**: Blocks `create`, `update`, and destructive actions unless explicitly permitted with `--allow-write` or `SAFE_MODE=false`.
  - **No Raw Eval / No Raw SQL**: AI agents only interact through structured, validated schemas (Zod-enforced).
  - **Audit Logging**: Logs every tool execution, parameters, and outcomes.
- **Rich Context for Developers & Content Teams**:
  - Full visibility into Custom Post Types (CPTs), ACF fields, Taxonomies, and Plugins.
  - Live `debug.log` inspection for diagnosing PHP errors and theme bugs.

---

## 🛠 Available MCP Tools

| Tool | Description | Safe Mode Behavior |
| :--- | :--- | :--- |
| `wp_get_site_info` | Site URL, WordPress version, active theme, server info, and safe mode status. | Allowed |
| `wp_list_posts` | Filter, search, and paginate posts/pages with metadata. | Allowed |
| `wp_get_post` | Retrieve full post content, custom fields, taxonomies, and metadata by ID. | Allowed |
| `wp_create_post` | Create a new post or page with title, body, status, categories, and meta. | **Blocked in Safe Mode** |
| `wp_update_post` | Update existing post content, title, excerpt, status, or meta fields. | **Blocked in Safe Mode** |
| `wp_list_plugins` | Check installed plugins, active states, versions, and update availability. | Allowed |
| `wp_get_taxonomies_and_types` | Inspect all registered Custom Post Types (CPTs) and Taxonomies. | Allowed |
| `wp_get_debug_log` | Read recent errors from WordPress `debug.log` for real-time debugging. | Allowed (CLI only) |

---

## 🚀 Quick Start

### 1. Installation

```bash
git clone https://github.com/your-username/wordpress-mcp.git
cd wordpress-mcp
npm install
npm run build
```

### 2. Configuration for AI Clients

#### A. Claude Code / Antigravity / Cursor Configuration

Add this to your MCP configuration file (e.g. `~/.cursor/mcp.json` or `.mcp.json`):

**For Local Development (LocalWP / WP-CLI):**
```json
{
  "mcpServers": {
    "wordpress": {
      "command": "node",
      "args": [
        "/path/to/wordpress-mcp/dist/index.js",
        "--path", "/path/to/your/wordpress/site",
        "--adapter", "cli"
      ]
    }
  }
}
```

**For Remote WordPress Sites (REST API):**
```json
{
  "mcpServers": {
    "wordpress": {
      "command": "node",
      "args": [
        "/path/to/wordpress-mcp/dist/index.js",
        "--adapter", "rest",
        "--url", "https://your-site.com",
        "--username", "your_admin_user",
        "--password", "xxxx xxxx xxxx xxxx"
      ]
    }
  }
}
```

> **Note:** Generate the Application Password in WordPress admin: **Users > Profile > Application Passwords**.

---

## 🔒 Security Blueprint

1. **Safe Mode (`SAFE_MODE=true`)**:
   By default, the server operates strictly in **Read-Only Mode**. All state-mutating tools (`wp_create_post`, `wp_update_post`) throw a security exception. To enable write operations:
   ```bash
   node dist/index.js --path /path/to/site --allow-write
   ```
2. **Sanitization**:
   All string parameters are stripped of malicious control characters and null bytes prior to dispatch.
3. **Audit Log**:
   Pass `--audit-log /path/to/audit.log` to record every single agent invocation with timestamps.

---

## 🧪 Running Tests

Run the built-in test suite against your local WordPress site:

```bash
npm test
```

---

<div dir="rtl">

# راهنمای فارسی

### 💡 این پروژه چیست؟
این پروژه یک سرور استاندارد بر پایه پروتکل **MCP (Model Context Protocol)** است که وبسایت‌های وردپرسی را به عنوان یک منبع داده و جعبه‌ابزار هوشمند به ایجنت‌های هوش مصنوعی نظیر **Cursor**، **Claude Code** و **Google Antigravity** متصل می‌کند.

### 🛡 چرا امنیت آن تضمین شده است؟
- **عدم امکان اجرای کدهای خام (No Eval / No Raw SQL):** هیچ ابزاری برای اجرای مستقیم کد PHP یا دستورات خام SQL در این سرور وجود ندارد. تمام ارتباطات از طریق متدهای امن و استاندارد وردپرس یا WP-CLI انجام می‌شود.
- **حالت امن پیش‌فرض (Safe Mode):** سرور به‌صورت پیش‌فرض در حالت Read-Only (فقط خواندنی) اجرا می‌شود تا هیچ ایجنتی نتواند بدون اجازه شما پستی را ویرایش یا ایجاد کند. برای اجازه نوشتن باید فلگ `--allow-write` داده شود.
- **لاگین و مانیتورینگ:** امکان فعال‌سازی فایل لاگ برای رصد لحظه‌ای تمامی دستورات ارسال‌شده از سمت هوش مصنوعی.

### 🎯 کاربردهای عملیاتی
1. **توسعه قالب و افزونه:** هوش مصنوعی بدون نیاز به حدس زدن، ساختار فیلدهای ACF و Custom Post Types سایت را می‌خواند و کدهای هماهنگ با دیتابیس سایت می‌نویسد.
2. **سئو و بهینه‌سازی محتوا:** بررسی وضعیت مقالات، عنوان‌ها، تگ‌ها و فیلدهای متای سئو.
3. **دیباگ هوشمند:** خواندن خطاهای فایل `debug.log` و ارائه راه‌حل برای ارورهای PHP و تداخل افزونه‌ها.
4. **مدیریت افزونه‌ها:** بررسی افزونه‌های نیازمند آپدیت و گزارش وضعیت سلامت سایت.

</div>

---

## 📄 License
MIT License. Created with ❤️ by Ameeen.
