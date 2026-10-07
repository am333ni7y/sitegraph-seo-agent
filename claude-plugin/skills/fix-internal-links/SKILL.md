---
name: fix-internal-links
description: Add internal links on a WordPress site through SiteGraph SEO Agent, for orphan pages, pages buried deep, pages with one inbound link, or striking-distance pages (positions 8–20). Use when the user wants links added, orphans fixed, or a page pushed up. Always proposes a reviewable changeset first and applies only after explicit approval.
---

# Fix internal links with SiteGraph

Tools are named `sitegraph-*` (in Claude Code: `mcp__<server>__sitegraph-…`). Run `sitegraph-scan-site` first if other tools say the site has not been scanned.

## 1. Pick the target pages

- If the user named pages, use those (`sitegraph-get-page-report` accepts `url`).
- Otherwise call `sitegraph-list-weak-pages` with `issue: "orphan"` (then `striking_distance`, `weak_inbound`, `deep`). Prefer pages with search impressions: they already have demand.
- Work on at most 5 target pages per changeset so the review stays readable.

## 2. Find sources and anchors

For each target call `sitegraph-suggest-internal-links`. Every suggestion has the exact anchor text, the sentence it sits in, and a ready-made `operation`.

Choose links like a careful editor:

- The anchor must read naturally in its sentence and describe the target page. Reject vague anchors ("this guide", "here") and anchors whose sentence is about something else.
- Prefer source pages that are strong (high score, more inbound links, impressions) and topically related (`same_category`).
- At most one new link from a given source to a given target, and at most 3 new links added to any one source page in one changeset.
- 2–3 new links per target page is usually enough.

If there are no good suggestions for a target, you may propose one `replace_text` operation that adds a single natural sentence containing the link to a closely related page. Keep it short, factual and in the site's voice, and point it out clearly in the review.

## 3. Propose

Call `sitegraph-propose-changeset` with:
- `title`: what it does, e.g. "Link 3 orphan tent guides from related pages"
- `rationale`: the evidence, e.g. "Orphans with 18,700 impressions combined; two rank on page 2."
- `operations`: the chosen `add_internal_link` operations (and any `replace_text`).

If it returns `sitegraph_invalid_operations`, fix or drop the failing operations and propose again. Do not apply a partial set without telling the user.

## 4. Review with the user

Show each operation as: source page → target page, the anchor, and the sentence before/after (from `operations[].preview`). Mention the changeset number and that it can also be reviewed in **wp-admin → SiteGraph SEO → Changes**.

Ask for approval. Accept edits ("drop the second one"): discard and propose again rather than applying something different from what the user saw.

## 5. Apply only after a clear yes

Call `sitegraph-apply-changeset` with the changeset ID. Then report what changed, the new score of each target page (`sitegraph-get-page-report`), and how to revert: "Say 'undo changeset #12', or click Undo in wp-admin."

If apply returns `sitegraph_conflict`, someone edited a page after the proposal. Explain which page, propose again from the current content, and never pass `force: true` unless the user explicitly asks you to overwrite after you explained what would be lost.

## Afterwards

Suggest re-importing Search Console data in 2–4 weeks; `sitegraph-get-changeset` then shows impressions at apply time versus now for the pages you linked.
