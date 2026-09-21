<div dir="rtl">

# 🚀 سرور وردپرس برای پروتکل کانتکست مدل (`wordpress-mcp`)

[![TypeScript](https://img.shields.io/badge/TypeScript-5.7-blue.svg)](https://www.typescriptlang.org/)
[![Model Context Protocol](https://img.shields.io/badge/MCP-Protocol-purple.svg)](https://modelcontextprotocol.io/)
[![License: MIT](https://img.shields.io/badge/License-MIT-green.svg)](LICENSE)
[![GitHub Stars](https://img.shields.io/github/stars/am333ni7y/wordpress-mcp.svg?style=social)](https://github.com/am333ni7y/wordpress-mcp)

[English Documentation](README.md) | **راهنمای فارسی**

یک سرور متن‌باز، فوق‌العاده سریع و با امنیت کامل بر پایه **Model Context Protocol (MCP)** برای اتصال بی‌واسطه وبسایت‌های وردپرسی به ایجنت‌های هوش مصنوعی پیشرفته نظیر **Claude Code**، **Cursor**، **Google Antigravity** و **Windsurf**.

این پروژه با **معماری موتور دوگانه (Dual-Engine)** و **گارد امنیتی بدون نفوذ (Zero-Trust)** طراحی شده است تا به ایجنت‌های هوش مصنوعی اجازه دهد به راحتی سلامت سایت را بررسی کنند، مقالات و برگه‌ها را بخوانند و بهینه‌سازی کنند، ساختار تکسونومی‌ها و فیلدهای سفارشی (ACF) را استخراج نمایند و عملیات سئو و محتوا را به صورت مطمئن و بدون ریسک هک شدن انجام دهند.

---

## 🌟 ویژگی‌های کلیدی

- **معماری دوگانه (Dual-Engine Architecture):**
  - **موتور محلی (WP-CLI Engine):** اتصال مستقیم و بدون تأخیر به محیط‌های توسعه لوکال (مانند LocalWP، Docker، Valet یا WP-CLI بومی) با حذف خودکار هشدارهای PHP برای پایداری داده‌ها.
  - **موتور ریموت (REST API Engine):** اتصال به وبسایت‌های لایو و پروداکشن بر بستر امن HTTPS با استفاده از سیستم رسمی **رمز عبور برنامه‌ها (Application Passwords)** وردپرس بدون نیاز به نصب هیچ افزونه جدید.
- **گارد امنیتی پیشرفته (Zero-Trust Safe Mode):**
  - **فقط‌خواندنی به صورت پیش‌فرض (Read-Only by Default):** تمامی دستورات تغییر وضعیت (ایجاد، ویرایش، حذف) به صورت خودکار مسدود می‌شوند مگر اینکه با سوییچ صریح `--allow-write` یا `SAFE_MODE=false` فعال شوند.
  - **عدم امکان اجرای کدهای خام (No Eval / No Raw SQL):** هوش مصنوعی هیچ ابزاری برای اجرای مستقیم کدهای PHP یا کوئری‌های SQL آزاد ندارد و تمامی تعاملات از فیلترهای اعتبارسنجی قوی (Zod) عبور می‌کنند.
  - **ثبت لاگ حسابرسی (Audit Logging):** امکان ضبط دقیق هر فراخوانی، پارامترهای ورودی و خروجی‌ها همراه با برچسب زمانی.
- **داشبورد تعاملی وب (Built-in Web Playground):**
  - دارای یک رابط کاربری وب در `http://localhost:3300` برای بررسی لحظه‌ای اتصال، تست تک‌تک ابزارها با یک کلیک و سوییچ بین سایت‌های مختلف.

---

## 🛠 ابزارهای ارائه‌شده (MCP Tools)

| نام ابزار | توضیحات | رفتار در حالت Safe Mode |
| :--- | :--- | :--- |
| `wp_get_site_info` | دریافت آدرس سایت، نام، نسخه هسته وردپرس، قالب فعال و وضعیت امنیت. | مجاز |
| `wp_list_posts` | جستجو، فیلتر و صفحه‌بندی مقالات بر اساس وضعیت، دسته یا کلمات کلیدی. | مجاز |
| `wp_get_post` | دریافت محتوای کامل، بلوک‌های گوتنبرگ، متادیتاها و تکسونومی‌ها با شناسه پست. | مجاز |
| `wp_create_post` | ایجاد پست یا برگه جدید با عنوان، محتوا، متادیتاها و وضعیت انتشار. | **مسدود در Safe Mode** |
| `wp_update_post` | ویرایش محتوا، عنوان، خلاصه، متادیتاها یا وضعیت یک پست موجود. | **مسدود در Safe Mode** |
| `wp_list_plugins` | مشاهده لیست تمام افزونه‌های فعال و غیرفعال، نسخه آن‌ها و آپدیت‌های موجود. | مجاز |
| `wp_get_taxonomies_and_types` | استخراج تمام Post Typeهای سفارشی (CPT) و تکسونومی‌ها (بسیار کاربردی برای کدنویسی قالب). | مجاز |
| `wp_get_debug_log` | خواندن آخرین خطاهای ثبت‌شده در فایل `debug.log` وردپرس برای عیب‌یابی سریع. | مجاز (موتور CLI) |

---

## 🚀 راهنمای نصب و راه‌اندازی سریع

### ۱. پیش‌نیازها
- **Node.js** نسخه ۱۸ یا بالاتر
- یک سایت وردپرسی فعال (لوکال با LocalWP / WP-CLI یا ریموت بر بستر HTTPS)

### ۲. کلون و بیلد پروژه
```bash
git clone https://github.com/am333ni7y/wordpress-mcp.git
cd wordpress-mcp
npm install
npm run build
```

---

## ⚙️ نحوه اتصال در ادیتورهای هوش مصنوعی

### الف) اتصال در Cursor (`~/.cursor/mcp.json`)

فایل تنظیمات MCP ادیتور Cursor را باز کنید و کانفیگ مربوط به سایت خود را قرار دهید:

**برای سایت‌های محلی (LocalWP / WP-CLI):**
```json
{
  "mcpServers": {
    "wordpress-local": {
      "command": "node",
      "args": [
        "/مسیر/کامل/پروژه/wordpress-mcp/dist/index.js",
        "--path", "/مسیر/پروژه/وردپرس",
        "--adapter", "cli"
      ]
    }
  }
}
```

**برای سایت‌های لایو (REST API با Application Password):**
```json
{
  "mcpServers": {
    "wordpress-live": {
      "command": "node",
      "args": [
        "/مسیر/کامل/پروژه/wordpress-mcp/dist/index.js",
        "--adapter", "rest",
        "--url", "https://your-site.com",
        "--username", "your_username",
        "--password", "xxxx xxxx xxxx xxxx"
      ]
    }
  }
}
```

> **نکته دریافت پسورد برنامه:** در مدیریت وردپرس به مسیر **کاربران > شناسنامه شما > رمزهای عبور برنامه** رفته و یک رمز جدید بسازید.

---

### ب) اتصال در Claude Code CLI

```bash
# برای سایت لوکال
claude mcp add wp-local node /مسیر/wordpress-mcp/dist/index.js -- --path "/مسیر/سایت" --adapter cli

# برای سایت لایو
claude mcp add wp-live node /مسیر/wordpress-mcp/dist/index.js -- --adapter rest --url "https://your-site.com" --username "admin" --password "xxxx xxxx xxxx xxxx"
```

---

### ج) اتصال در Google Antigravity

در فایل پیکربندی جهانی `~/.gemini/config/mcp_config.json`:
```json
{
  "mcpServers": {
    "wordpress": {
      "command": "node",
      "args": [
        "/مسیر/کامل/پروژه/wordpress-mcp/dist/index.js",
        "--adapter", "rest",
        "--url", "https://your-site.com",
        "--username", "admin",
        "--password", "xxxx xxxx xxxx xxxx"
      ]
    }
  }
}
```

---

## 🎮 داشبورد تعاملی تست (Web Playground)

برای آزمایش عملکرد و تست بصری ابزارها پیش از اتصال به چت ایجنت:
```bash
npm run dashboard
```
سپس مرورگر خود را در آدرس **`http://localhost:3300`** باز کنید. این داشبورد به شما اجازه می‌دهد:
- ورودی ابزارها را فرم‌بندی کنید.
- خروجی‌های خام و ساختاریافته JSON پروتکل MCP را در لحظه ببینید.
- بین سایت‌های لوکال و ریموت جابجا شوید.
- سوییچ Safe Mode را به شکل گرافیکی فعال یا غیرفعال کنید.

---

## 🔒 امنیت و مدل Zero-Trust

1. **حالت Safe Mode پیش‌فرض:** هیچ داده‌ای بدون موافقت شما بازنویسی نمی‌شود. برای مجاز کردن ویرایش در خط فرمان فلگ `--allow-write` را اضافه کنید.
2. **پاک‌سازی داده‌ها (Sanitization):** تمامی رشته‌ها قبل از پردازش از کاراکترهای مخرب و null-byte پاکسازی می‌شوند.
3. **لاگ حسابرسی (Audit Log):** با افزودن `--audit-log /path/to/log.txt` تمامی فعالیت‌های ایجنت هوش مصنوعی با جزئیات کامل ذخیره می‌شوند.

---

## 📄 لایسنس
این پروژه تحت لایسنس آزاد **MIT** منتشر شده است.  
توسعه‌داده‌شده با ❤️ توسط **امین زاهد (Ameeen)**.

</div>
