---
name: review-changes
description: Review, undo or redo content changes made through SiteGraph SEO Agent on WordPress. Use when the user asks what the agent changed, wants to revert something ("undo the links from yesterday"), wants to re-apply an undone change, or asks whether a change worked.
---

# Review, undo and redo SiteGraph changes

Tools are named `sitegraph-*` (in Claude Code: `mcp__<server>__sitegraph-…`).

## What changed?

Call `sitegraph-list-changesets`. Summarize each relevant changeset in one line: number, title, status (`proposed`, `applied`, `undone`, `discarded`), who and when. Use `sitegraph-get-changeset` to show the operations and before/after previews of a specific one.

## Undo

1. Identify the changeset. If the user is vague ("undo the last links"), list candidates and confirm the number.
2. Call `sitegraph-undo-changeset`. It restores every field to its exact earlier value.
3. If it returns `sitegraph_conflict`, a page was edited after the changeset was applied. The message names the newer changeset if there is one:
   - Newer SiteGraph changeset: offer to undo that one first, then this one (undo in reverse order).
   - Edit made outside SiteGraph: explain that undoing would overwrite that edit. Only pass `force: true` if the user explicitly says to overwrite it.

## Redo

`sitegraph-redo-changeset` re-applies an undone changeset with the same conflict protection.

## Pending proposals

A `proposed` changeset has not changed anything yet. Apply it with `sitegraph-apply-changeset` only after the user approves it, or remove it with `sitegraph-discard-changeset`.

## Did it work?

`sitegraph-get-changeset` returns `impact`: each measured page's score and search numbers when the change was applied versus now. Search numbers only move after fresh Search Console data is imported (`sitegraph-import-search-data`), typically 2–4 weeks later. Be honest about small samples and seasonality; do not claim causation from one data point.
