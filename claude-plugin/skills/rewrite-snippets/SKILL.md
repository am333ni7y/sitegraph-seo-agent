---
name: rewrite-snippets
description: Rewrite SEO titles and meta descriptions on WordPress through SiteGraph SEO Agent, for pages with low click-through rate, missing meta descriptions, or titles that are too long or short. Use when the user wants better search snippets or more clicks from existing rankings. Proposes options, applies only the approved ones as an undoable changeset.
---

# Rewrite search snippets with SiteGraph

Tools are named `sitegraph-*` (in Claude Code: `mcp__<server>__sitegraph-…`).

## 1. Find the pages

Use the pages the user named, or call `sitegraph-list-weak-pages` with `issue: "low_ctr"` (needs Search Console data), then `no_meta_description` and `title_length`. Start with the most impressions.

## 2. Understand each page

Call `sitegraph-get-page-report`. Note the current SEO title, meta description, focus keyword, position, impressions and CTR. If the content matters for accuracy, read the page at its `url`.

## 3. Write two options per page

- **SEO title:** 30–60 characters, the main topic early, a concrete reason to click (a number, a test, the year only if the content is genuinely current). No clickbait and no claims the page does not support.
- **Meta description:** 140–155 characters, answers "why this result?", matches the search intent.
- Keep the site's tone. Do not stuff keywords.

Show the options side by side with the current text and the character counts. Ask the user to pick or edit.

## 4. Propose and apply

Put only the chosen texts into one `sitegraph-propose-changeset` call with `set_seo_title` and `set_meta_description` operations (`post_id`, `value`). The values go to the active SEO plugin (Yoast, Rank Math, SEOPress) or to SiteGraph's own meta tags when none is installed; `get-page-report` shows which.

Show the proposal, then call `sitegraph-apply-changeset` only after the user confirms. Report the changeset number and that `sitegraph-undo-changeset` reverts it exactly.

Changing the visible post title (`set_post_title`) also changes the H1 most themes display. Only do it when the user asks for it specifically.
