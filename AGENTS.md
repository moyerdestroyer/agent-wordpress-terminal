# AGENTS.md — Agent WordPress Terminal (AWPT)

Brief guide for humans and coding agents working in this repo.

## What this is

AWPT is a WordPress admin app: a terminal-style cockpit for chatting with an agent, pinning site context, inspecting Abilities/MCP tools, previewing content, and approving proposed actions. See `plan.txt` for product intent and non-goals.

## Architecture

**PHP loader:** Classic singleton. `agent-wordpress-terminal.php` defines constants, requires `vendor/autoload.php`, registers activation hooks, then calls `AWPT\Plugin::instance()->boot()`.

**Autoloading:** PSR-4 via Composer — namespace `AWPT\` maps to `src/`.

**Layers:**

- `src/Admin/` — settings page, asset enqueue (Vite)
- `src/REST/` — `awpt/v1` API (sessions, chat, context, tools)
- `src/Abilities/` — WordPress Abilities (`awpt/*`)
- `src/Agent/` — runtime, providers, tool execution
- `src/MCP/` — MCP status adapter + in-process WordPress MCP Adapter bridge (auto-detect, not a network client)
- `src/Database/` — custom tables on activation
- `assets/` — React TSX admin UI, built to `build/`

**Frontend:** Vite + `@kucrut/vite-for-wp`, React 18, `@wordpress/components`. Entry: `assets/admin.tsx`.

## Setup

```bash
composer install
npm install
npm run build          # production assets
# npm run dev          # HMR while developing TSX
```

Activate the plugin in WP admin → **Settings → Agent Terminal**.

Requires WordPress 6.9+, PHP 8.4+. Abilities API must be available for tool registration.

### AI provider architecture

AWPT's core chat/tool-calling functionality never requires WordPress Core Connectors, the
WP AI Client, or a companion AI plugin — those are optional accelerators for sites that
already have them configured. The guaranteed baseline on every supported WordPress
version is the small set of direct-key providers in `src/Agent/` (`OpenRouterProvider`,
`OpenAIProvider`), thin subclasses of `ChatCompletionsProvider` talking to OpenAI-compatible
chat completions endpoints. `OpenAIProvider` has no manual model field (it uses OpenAI's
evergreen `chat-latest` alias) and transparently reuses an already-configured `openai`
WordPress Connector key when AWPT's own `awpt_openai_api_key` option is empty
(`ConnectorInspector::resolve_default_provider_api_key()`), so a key never has to be
entered twice. `WordPressAIClientProvider` is a separate, fully optional adapter that only
activates when a site has WordPress 7.0+ (Core Connectors API, shipped March 2026) or an
`AI`/`wp-ai-client` companion plugin with a ready connector selected — every call site is
feature-detected (`function_exists()`/`class_exists()`/`method_exists()`) and
`ProviderFactory` falls back to the direct providers otherwise. `Admin/Page.php` excludes
any installed Connector whose ID matches a direct provider (currently `openai`) from the
separate "WordPress Connectors" list, so the same provider is never offered as two
different radio options. Keep the direct-provider list intentionally small (OpenRouter +
OpenAI) — additional native providers (Anthropic, Google, self-hosted/local) were tried
and deliberately removed as unnecessary surface area; OpenRouter already reaches those
models for anyone who wants them. When adding a new AI integration, prefer extending
`ChatCompletionsProvider` over adding another Connectors-only code path.

## Commands

| Task | Command |
|------|---------|
| PHP lint | `composer run lint` |
| PHP format | `composer run format` |
| PHP analyze | `composer run analyze` |
| PHP check (lint + analyze) | `composer run check` |
| PHP tests (bootstrap-free) | `composer run test` |
| TS/TSX lint | `npm run lint` |
| TS/TSX fix | `npm run lint:fix` |
| Build assets | `npm run build` |
| Plugin zip (local) | `./bin/build-plugin-zip.sh` |

**PHP tooling:** [Mago](https://mago.carthage.software) via `composer install` dev dep (`carthage-software/mago`). Config: `mago.toml`. Analyzer uses `analyzer-baseline.toml` for WordPress stub gaps — shrink the baseline as types improve.

**JS tooling:** [Biome](https://biomejs.dev). Config: `biome.json`.

## Conventions

- PHP: `declare(strict_types=1)`, namespace `AWPT\`, `final` classes where appropriate. WordPress globals (`$wpdb`) are expected — Mago `no-global` is disabled.
- REST namespace: `AWPT_REST_NAMESPACE` (`awpt/v1`). Permission: `manage_options` for MVP.
- Abilities: register on `wp_abilities_api_init`, category `awpt`, prefix `awpt/`.
- TSX: match existing terminal UI patterns in `assets/components/`. Use `@wordpress/i18n` for user-facing strings.
- Do not store large model payloads in post meta/options — use custom tables (`wp_awpt_*`).
- Treat the agent as untrusted: ability `permission_callback`s use minimum WordPress caps (no redundant `manage_options` double-gates on content/media/theme/plugin ops). Diagnostics and site-settings stay admin-only. `awpt/apply-action` stays human-only. Discovered abilities/MCP tools are auto-offered unless disabled in the Tools UI (`awpt_disabled_tools`).

## Layers and plugin/theme boundaries

| Layer | Owns |
|-------|------|
| `src/Domain/` | Domain Packs, pack-aware pattern semantics, composition validation gate, theme vocabulary |
| `src/Support/` | WordPress primitives (blocks, posts, media, connectors) without hardcoding a product theme |
| `src/Agent/` | Provider loop, tools, turn policy |
| Active theme Domain Pack | Pattern namespaces, thrash aliases, rules, guidance, optional PHP validators |

**Theme vocabulary never belongs in AWPT core.** Pattern name aliases and theme-specific thrash recovery live in the active pack’s manifest (`patterns.namespace`, `patterns.aliases`). AWPT only applies generic, namespace-agnostic heuristics.

**Pattern-native redesign (Ollie-inspired, AWPT tools only):** Prefer **`prepare-pattern-change` → `propose-pattern-replace`** for existing section swaps and **`propose-pattern-insert`** for additions (server-materialized). Full-document freehand `propose-content-update` remains available when needed; claiming `pattern_name` still requires structure evidence (`read-pattern` / prep). Reject dishonest `pattern_unfit_code: no_recommendations` when recommendations were non-empty. Create: `prepare-pattern-draft` → `propose-patterned-post`. Surgical block ops stay exempt.

**Review-queue Improve:** Two **internal** AWPT turns (evaluate plan → act), still **one button**. Prompts live in `ImprovePagePrompt` (PHP SoT). Dufresne only mounts `AWPTReviewAssistant` — no tools, prompts, or plan schema in Dufresne. Evaluate turns are read-only (`[awpt:improve_evaluate]` marker → investigate tools). CLI: `bin/queue-improve-one.php` (default two-step; `one-shot` for legacy).

**Dufresne contracts** (string-stable; prefer `Hooks` constants on the Dufresne side):

| Hook / surface | Purpose |
|----------------|---------|
| `dufresne_wp_plugin_run_completed` | Schedule knowledge rebuild after successful import |
| `dufresne_wp_plugin_after_rollback` | Mark knowledge stale / coalesce rebuild after rollback |
| `dufresne_wp_plugin_enqueue_review_assets` | Enqueue AWPT review bridge (preferred over hard-coded admin page hooks) |
| `window.AWPTReviewAssistant` | Versioned mount API (`version`, `mount`) for the Review queue UI |

**Stack PHP:** AWPT requires PHP 8.4+. Sites running AWPT + Dufresne together must use 8.4+.

**Ollie / `ol/`:** Offline reference and parity evaluation only. Not a product dependency; never import or require it from runtime code. See `evaluation/README.md`.

**Schema upgrades:** Bump `Installer::SCHEMA_VERSION` only with either pure additive `dbDelta` table definitions or an explicit `version_compare` step in `maybe_upgrade()`. Do not rely on silent full recreate for data renames or backfills.

**Domain Pack schemas:** v1 manifests still load; new packs must use v2 (`schemas/awpt-domain-v2.schema.json`). Do not remove v1 before 0.4.0 without a deprecation notice in release notes.

## Key files

| File | Role |
|------|------|
| `agent-wordpress-terminal.php` | Plugin bootstrap |
| `src/Plugin.php` | Singleton, wires hooks |
| `src/Agent/AgentRuntime.php` | Message dispatch, secondary slash shortcuts |
| `assets/components/Terminal.tsx` | Main UI shell |
| `mago.toml` / `biome.json` | Lint/format config |

## MVP scope (0.1)

In scope: admin UI, sessions (per-admin), tool registry display, read/analyze/preview abilities, staged action cards (content updates, new posts, settings, theme switch), secondary slash shortcuts, knowledge auto-retrieval.

Out of scope for now: full autonomous editing, multi-agent orchestration, remote browser workers, context picker UI.

## When changing things

- New REST route → add controller in `src/REST/`, register in `Plugin::register_rest_routes()`.
- New ability → class in `src/Abilities/`, hook from `RegisterAbilities`.
- New UI panel → component under `assets/components/`, wire in `Terminal.tsx`.
- Schema change → update `Database/Installer.php` (`SCHEMA_VERSION` + additive `dbDelta` and/or explicit `version_compare` step).
- Domain Pack theme vocabulary (aliases, namespaces) → theme `awpt-domain.json`, not AWPT core constants.
- After PHP edits: `composer run format` then `composer run check`.
- After TSX edits: `npm run lint:fix` then `npm run build` before testing in WP admin.
