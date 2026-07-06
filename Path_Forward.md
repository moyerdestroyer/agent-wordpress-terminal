# Path Forward — AWPT Agent Capabilities

This document describes where AWPT's knowledge, site-context, editing, and visual-verification capabilities are today, why they need to evolve, what the optimal end state looks like, and a phased implementation path with concrete steps.

Related docs: `plan.txt` (product spec), `PRODUCT.md` (design principles), `AGENTS.md` (architecture and conventions), `todos.md` (tracked items).

Use `plan.txt` for product requirements and scope boundaries. Use this document for the
implementation sequence, dependency map, and capability gaps that decide what to build
next.

---

## 1. Current baseline

AWPT today is a strong **agent cockpit** — chat, tool registry, staged actions, human approval, and a frontend preview drawer for admins. The gaps are in **what the agent can know** and **how precisely it can act** on real site content.

### Knowledge & retrieval

| Area | Status |
|------|--------|
| Chunking | Fixed 3,000-character windows, 250-char overlap; whitespace collapsed |
| Embeddings | Schema placeholder only (`embedding_json` always null) |
| Search | Keyword `LIKE` + token scoring; MySQL `FULLTEXT` index unused |
| Sources | Core Knowledge, legacy guidelines, WP posts/pages (200 cap), filesystem text files |
| Auto-retrieval | Last user message → keyword search → system prompt injection; transcript evidence trace is now recorded when excerpts are used |

**Not indexed or poorly indexed:** PDF body text, image pixels/alt text, CSS/theme files, HTML structure, block attributes, template/global styles.

### Content understanding

| Capability | Status |
|------------|--------|
| Read serialized block markup | Yes — `awpt/read-block-tree` (`parse_blocks`) |
| Read raw HTML / plain text | Yes — `awpt/read-content`, `awpt/analyze-page` |
| Gutenberg editor UI | No — no block editor mount, no `wp.data` commands |
| Post search by slug/title | Yes — `awpt/search-content` resolves ID, URL, slug, title, post type, templates, template parts, and reusable blocks |
| Human focus/preview shortcuts | Improved — `/focus` and `/preview` accept title, slug, URL, or ID, but slash shortcuts remain secondary to natural-language prompts |
| Surgical block edits | First vertical slice shipped — `awpt/read-block-tree` returns paths/fingerprints and `awpt/propose-block-attrs-update` stages one block attr merge |
| Full-content staging | Gutenberg delimiters preserved through raw staged save paths; still reserved for full-document/classic edits |

### Visual verification

| Capability | Status |
|------------|--------|
| Admin preview iframe | Yes — `PreviewPane` renders frontend URL for humans |
| Agent-visible screenshot / DOM / CSS | No — `awpt/preview-post` returns URL metadata only |
| Before/after compare | Text/markup diff only (block comments stripped) |
| Headless browser / remote workers | Explicitly out of MVP scope (`plan.txt`, `AGENTS.md`) |

### Representative failure mode

A request like *"make the SEM icon on the about page a little bit bigger"* is now much more likely to resolve the page and stage a targeted block-attribute proposal. It can still fail or guess when the sizing lives in theme CSS, template/global styles, custom block rendering, or purely visual relationships the agent cannot see.

---

## 2. Rationale

### Why change

1. **User expectations** — Admins ask in visual and editorial terms ("bigger icon", "fix the hero spacing"). AWPT must bridge natural language and WordPress's block/theme model.
2. **Evidence over guessing** — PRODUCT.md requires showing evidence. Visual verification closes the loop between proposed edits and observable outcomes.
3. **WordPress-native over brittle automation** — Driving `post.php?action=edit` with Playwright is fragile and hostile to generic hosting. WordPress exposes block data APIs and (optionally) the block editor store for a reason.
4. **Plugin deployability** — Core improvements must run on typical shared hosting (PHP + admin JS). Heavy infrastructure (headless Chromium on-server) should be optional, not required.
5. **Layered intelligence** — No single technique suffices. Reliable agent work needs **theme knowledge**, **WordPress domain knowledge**, **live site context**, and **visual verification** working together.

### Design constraints (carry forward)

- Destructive/write actions remain **staged and approved** (`PRODUCT.md`).
- Agent is **untrusted** — capability checks on every tool (`AGENTS.md`).
- Prefer **Abilities + REST** (`awpt/v1`) over ad-hoc endpoints.
- Keep the direct provider stack small; optional accelerators (WP AI Client, Connectors) stay feature-detected.

### Tier choice: WordPress-native (Tier 2) over UI automation (Tier 3)

| Approach | Verdict |
|----------|---------|
| **Tier 2a** — Server block APIs (`parse_blocks`, `serialize_blocks`, `render_block`) | **Default path** — fits AWPT architecture, works everywhere |
| **Tier 2b** — Embedded `@wordpress/block-editor` in AWPT admin | **Later** — true co-editing UX, still no server browser |
| **Tier 3** — Playwright/Puppeteer driving wp-admin or frontend | **Optional add-on** — E2E-style; deferred as core dependency |
| **Client-side capture** from existing preview iframe | **Best first visual step** — reuses preview drawer, no Chromium on server |

---

## 3. Optimal end state

AWPT becomes a cockpit where an agent can **understand**, **propose**, **verify**, and **explain** site changes with evidence — without autonomous publishing.

### Four knowledge layers (working together)

```txt
┌─────────────────────────────────────────────────────────────┐
│ 1. Theme & best-practice knowledge (durable, indexed)       │
│    theme.json, guidelines, brand voice, block patterns      │
├─────────────────────────────────────────────────────────────┤
│ 2. WordPress domain knowledge (abilities + prompts)         │
│    capabilities, block types, staging flow, FSE concepts    │
├─────────────────────────────────────────────────────────────┤
│ 3. Live site context (read tools, session focus)            │
│    block tree, templates, settings, resolved post IDs       │
├─────────────────────────────────────────────────────────────┤
│ 4. Visual analysis & verification (capture + optional diff) │
│    screenshot, a11y tree, computed metrics, before/after    │
└─────────────────────────────────────────────────────────────┘
         │                              │
         ▼                              ▼
   Retrieval (hybrid)            Staged proposals + preview
```

### Agent capabilities at end state

**Find & understand**

- Resolve posts/pages/templates by slug, title, or URL — not only by ID.
- Read block trees with stable paths (e.g. `blocks[2].attrs.width`).
- Maintain a visible context stack: focused content, retrieved Knowledge, staged action evidence, preview/capture records, and selected block when available.
- Ingest `theme.json`, global styles, and template parts as first-class context.
- Hybrid Knowledge search (keyword + embeddings) across text, markdown, and extracted PDF text.
- Attachment metadata (alt, caption, description) indexed and searchable.

**Propose & edit**

- Surgical block operations: update attrs, insert/remove/replace block by path.
- Full-document updates when appropriate, with Gutenberg markup preserved (no destructive kses on block comments).
- Template and template-part staging where the change lives outside page `post_content`.
- Session `focus_post_id` injected into provider context automatically.

**Verify & explain**

- Capture screenshot + DOM snapshot + selected computed styles from the preview iframe (admin client).
- Optional before/after pixel or structural diff on staged actions.
- Multimodal models receive images for "does this look right?" loops.
- Transcript shows which layers were used (Knowledge hit, block path, capture ID).
- Human-facing workflows stay natural-language first; slash shortcuts are precise escape hatches, not the primary path.

**Optional (power users / self-hosted)**

- Remote browser worker for unattended audits and CI-style visual regression.
- Embedded block editor panel for human+agent co-editing.

### Non-goals (unchanged)

- Fully autonomous publishing without approval.
- Multi-agent orchestration as a core product surface.
- Replacing wp-admin or the Site Editor — AWPT complements them.

---

## 4. Implementation path

Phases are ordered by **impact × deployability**. Each phase lists concrete deliverables mapped to AWPT's existing layers (`src/Abilities/`, `src/Knowledge/`, `src/REST/`, `assets/components/`).

---

### Phase 0 — Foundation fixes (mostly shipped)

**Goal:** Make existing paths safer and more useful without new infrastructure.

| Step | Work | Location / notes |
|------|------|------------------|
| 0.1 | Inject `focus_post_id` and active session context into provider system instructions | Shipped: `src/Agent/ProviderMessageBuilder.php` |
| 0.2 | Add post/page discovery ability: search by `s`, post_type, slug | Shipped: `src/Abilities/SearchContent.php`, `ContentSearchService.php` |
| 0.3 | Preserve Gutenberg block delimiters on staged content — audit `wp_kses_post` impact; use `wp_slash` + raw block save path or `content` filtered for block posts | Shipped for content update/new-post staging and appliers |
| 0.4 | Switch Knowledge search to MySQL `FULLTEXT` where available; keep keyword fallback | `KnowledgeIndexRepository::search_chunks()` |
| 0.5 | Structure-aware chunking: split on `\n\n` and heading boundaries before char windows | `KnowledgeIndexer::chunk_text()` |
| 0.6 | Index attachment alt/caption/description into Knowledge sources | `KnowledgeRepository::post_to_source()` |
| 0.7 | Read block tree with paths/fingerprints and stage a targeted block attrs update | Shipped: `ReadBlockTree.php`, `ProposeBlockAttrsUpdate.php`, block path helpers |

**Exit criteria:** Agent can find "About" page by name; staged block posts don't corrupt on save; Knowledge search measurably improves.

---

### Phase 0A — Workflow hardening (current priority)

**Goal:** Make the existing abilities dependable in real conversations before adding heavier infrastructure.

| Step | Work | Location / notes |
|------|------|------------------|
| 0A.1 | Keep AWPT natural-language first: empty states and help text should suggest tasks, not numeric IDs | Shipped in `Transcript.tsx`; keep auditing new copy |
| 0A.2 | Resolve `/focus` and `/preview` by title, slug, URL, or ID with ambiguity feedback | Shipped in `ContentTargetResolver.php`, `ContentCommandRouter.php` |
| 0A.3 | Show focused content as a real object in session/header UI: title, type, status, slug/URL | Shipped via enriched session summaries and `Terminal.tsx`; next: clickable preview/edit affordances |
| 0A.4 | Record automatic Knowledge retrieval as visible transcript evidence | Shipped as `awpt/knowledge-auto-retrieval`; next: richer evidence detail UI |
| 0A.5 | Add deterministic content-intent fallback when the model defers instead of calling tools | Shipped for `awpt/search-content`; next: target-aware read/block-tree fallback |
| 0A.6 | Add readable fallback summaries for important tools instead of raw JSON | Shipped for Knowledge, search, read content, read block tree, proposals |
| 0A.7 | Strengthen staged-action evidence contracts: target, source version/fingerprint, block path, attrs, later capture ID | Partly shipped for block attr cards; continue in action payload sanitizers/appliers |
| 0A.8 | Add a first-class context stack/pinned context model, not just one `focus_post_id` | New persistence + REST/UI work |
| 0A.9 | Add smoke prompts for natural workflows: "focus About", "preview homepage", "make SEM icon bigger" | PHP + browser/UI tests |
| 0A.10 | Keep slash shortcuts secondary in UI, help text, provider instructions, and docs | Shipped; continue auditing new copy |

**Exit criteria:** A normal admin can work by naming content naturally; the transcript shows what context/evidence was used; common provider tool-call failures recover without a dead-end answer.

---

### Phase 1 — Site context layer (Tier 2a block APIs)

**Goal:** Precise read/write on Gutenberg data without mounting the editor.

| Step | Work | Location / notes |
|------|------|------------------|
| 1.1 | `awpt/get-block` — resolve block by path index or clientId if present | Partly covered by `ReadBlockTree` output; dedicated ability still useful |
| 1.2 | `awpt/list-blocks` — flat or tree listing with paths, names, attrs summary | Partly covered by `ReadBlockTree`; add filters/search |
| 1.3 | `awpt/update-block-attrs` — merge attrs on one block, re-serialize | First staged version shipped as `awpt/propose-block-attrs-update` |
| 1.4 | `awpt/insert-block` / `awpt/remove-block` — surgical tree edits | Uses `serialize_blocks()` |
| 1.5 | `awpt/render-block` — `render_block()` output for one block or full post | Helps agent see HTML output without browser |
| 1.6 | Template & template-part read abilities (`wp/v2/templates` parity in PHP) | New `ReadTemplate.php`, `ListTemplates.php` |
| 1.7 | Extend `ToolCatalogFormatter` and provider instructions for block-path edit workflow | `src/Agent/ToolCatalogFormatter.php`, `ProviderMessageBuilder.php` |
| 1.8 | PHP tests for block path resolution and round-trip serialize | `tests/` |

**Exit criteria:** *"Set width on core/image block 3 to 180"* works end-to-end with staged approval and preview.

---

### Phase 2 — Theme & extended Knowledge

**Goal:** Theme-aware context and richer indexed sources.

| Step | Work | Location / notes |
|------|------|------------------|
| 2.1 | Read and expose active theme `theme.json` (settings, styles, custom templates) | New `ReadThemeJson.php` ability |
| 2.2 | Index `theme.json`, pattern docs, and allowed markdown under theme (read-only) | `FilesystemAccessPolicy`, `KnowledgeIndexer` |
| 2.3 | PDF text extraction for Media Library attachments and filesystem `.pdf` | New `src/Knowledge/PdfTextExtractor.php`; optional `smalot/pdfparser` via Composer |
| 2.4 | Embedding pipeline via OpenRouter `/embeddings` with keyword fallback (hybrid RRF) | New `src/Knowledge/EmbeddingService.php`; populate `embedding_json`; see `todos.md` |
| 2.5 | Incremental Knowledge rebuild by `content_hash` (avoid full wipe) | `KnowledgeIndexer::rebuild()`, `KnowledgeIndexRepository` |
| 2.6 | Guideline + theme context sections in provider prompt (bounded token budget) | `ProviderMessageBuilder.php` |
| 2.7 | Knowledge settings UI: embedding model, chunk strategy, PDF toggle | `KnowledgeController`, `KnowledgePanel.tsx` |

**Exit criteria:** Brand/theme constraints appear in agent context; PDFs in Media Library are searchable; hybrid retrieval improves paraphrase queries.

---

### Phase 3 — Visual capture & verification (client-first)

**Goal:** Give the agent (via multimodal models) evidence of rendered appearance.

| Step | Work | Location / notes |
|------|------|------------------|
| 3.1 | REST endpoint `POST /awpt/v1/capture` — accepts screenshot (base64), URL, post_id, viewport, optional element selector metadata | `src/REST/CaptureController.php` |
| 3.2 | Client capture hook in `PreviewPane.tsx` — `html2canvas` on iframe or postMessage bridge from same-origin preview | `assets/components/PreviewPane.tsx`, new `assets/lib/capture.ts` |
| 3.3 | `awpt/capture-page` ability — returns capture record ID + metadata; attaches image for vision providers | New ability; provider adapter passes image parts where supported |
| 3.4 | Optional DOM/a11y snapshot (element roles, bounding rects, computed font-size/width for selector) | JS collector + stored JSON alongside capture |
| 3.5 | Wire capture into staged action flow: auto "before" on propose, "after" on preview refresh | `Terminal.tsx`, action apply flow |
| 3.6 | Compare tab upgrade: side-by-side thumbnails when captures exist | `PreviewPane.tsx` CompareView |
| 3.7 | Provider instruction: use capture evidence when judging layout/size requests | `ProviderMessageBuilder.php` |

**Exit criteria:** Size/layout requests can reference screenshot evidence; admin sees visual before/after on staged content actions.

---

### Phase 4 — Embedded block editor (Tier 2b)

**Goal:** Optional co-editing canvas inside AWPT for humans and structured agent commands.

| Step | Work | Location / notes |
|------|------|------------------|
| 4.1 | Spike: mount minimal `@wordpress/block-editor` post editor in new panel/tab | `assets/components/BlockEditorPane.tsx`; enqueue WP editor scripts from `Admin/Page.php` |
| 4.2 | Load post by ID into editor store; sync saves to staging draft only (never live publish) | Mirror `StagedPostPreview` patterns |
| 4.3 | Agent command bridge — REST or postMessage: `updateBlockAttributes`, `selectBlock` | `src/REST/EditorCommandController.php` |
| 4.4 | UI: "Open in editor" from focus/context; show block selection in transcript | `Terminal.tsx` |
| 4.5 | Document security model (nonce, capability, no arbitrary PHP execution) | `AGENTS.md` update when shipped |

**Exit criteria:** Admin can open focused post in AWPT editor panel; agent can apply a structured attr change reflected live in canvas before staging.

---

### Phase 5 — Optional remote browser worker

**Goal:** Unattended verification and audits for hosts that opt in — not required for core value.

| Step | Work | Location / notes |
|------|------|------------------|
| 5.1 | Define worker protocol (URLs in, screenshots + DOM JSON out); authenticate via short-lived tokens | New `docs/browser-worker.md` or section in this file |
| 5.2 | AWPT settings: worker URL, API key, enable/disable | `Admin/Page.php`, options |
| 5.3 | `awpt/capture-page-remote` ability delegating to worker | Falls back to Phase 3 client capture |
| 5.4 | Optional Playwright worker package (separate repo or `workers/` — not bundled in main plugin zip) | Keeps main plugin host-agnostic |
| 5.5 | Visual regression job: cron + compare baselines for configured URLs | Future; align with `plan.txt` deferred items |

**Exit criteria:** Sites with a worker configured get server-side captures without client admin session; default installs unaffected.

---

## 5. Phase dependency map

```txt
Phase 0 (foundation)
    │
    ├──► Phase 0A (workflow hardening)
    │       ├──► Phase 1 (block APIs) ──► Phase 4 (embedded editor)
    │       ├──► Phase 2 (Knowledge + theme)
    │       └──► Phase 3 (visual capture) ──► Phase 5 (remote worker, optional)
    │
```

Phases 1, 2, and 3 can proceed **in parallel** after Phase 0A, with different owners. Phase 4 depends on Phase 1 block path conventions. Phase 5 depends on Phase 3 capture schema.

---

## 6. Success metrics

| Metric | Target |
|--------|--------|
| Page resolution | Agent finds correct post by title/slug in ≥90% of smoke-test prompts without user-supplied ID |
| Block edit safety | Staged block posts validate in block editor after apply (no delimiter corruption) |
| Knowledge recall | Hybrid search top-5 includes relevant doc for paraphrased queries (manual eval set) |
| Visual loop | Size/layout requests produce before/after captures on staged actions |
| Hosting | Phases 0–3 require no binaries beyond PHP + browser admin session |

---

## 7. Open decisions

Record resolutions here as phases ship.

| Decision | Options | Notes |
|----------|---------|-------|
| Block edit ability shape | Extend `propose-content-update` vs new `propose-block-update` | Favor single staging pipeline initially |
| Capture storage | Custom table vs post meta vs transient | Large base64 images → table or object storage |
| Vision provider | Require multimodal model vs text-only degradation | Document in settings when capture tools used |
| PDF library | `smalot/pdfparser` vs external service | Prefer pure-PHP for plugin portability |
| Embedding storage | JSON in MySQL vs sqlite-vec sidecar vs external vector DB | Start with MySQL JSON + PHP cosine; revisit at scale |

---

## 8. Immediate next steps

If starting now, finish **Phase 0A** before expanding infrastructure:

1. **0A.8** — Add a real context stack/pinned context model beyond `focus_post_id`.
2. **0A.9** — Add natural-workflow smoke tests and browser/UI checks.
3. **0A.5 next slice** — Make content fallback target-aware: search → read content → read block tree when unambiguous.
4. **0.4–0.6** — Improve Knowledge retrieval: FULLTEXT, structure-aware chunks, attachment metadata.
5. **1.1–1.5** — Complete block read/render operations beyond attrs updates.

Then begin **Phase 3.1–3.3** in parallel if multimodal provider is already configured — visual verification is the other half of layout/size requests.

---

*Last updated: 2026-07-05 — synthesizes knowledge/RAG, content-type, Gutenberg, and visual-capability analysis.*
