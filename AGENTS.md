# AGENTS.md — Agent WordPress Terminal (AWPT)

Brief guide for humans and coding agents working in this repo.

## What this is

AWPT is a WordPress admin app: a terminal-style cockpit for chatting with an agent, pinning site context, inspecting Abilities/MCP tools, previewing content, and approving proposed actions. See `plan.txt` for the full product spec.

## Architecture

**PHP loader:** Classic singleton. `agent-wordpress-terminal.php` defines constants, requires `vendor/autoload.php`, registers activation hooks, then calls `AWPT\Plugin::instance()->boot()`.

**Autoloading:** PSR-4 via Composer — namespace `AWPT\` maps to `src/`.

**Layers:**

- `src/Admin/` — settings page, asset enqueue (Vite)
- `src/REST/` — `awpt/v1` API (sessions, chat, context, tools)
- `src/Abilities/` — WordPress Abilities (`awpt/*`)
- `src/Agent/` — runtime, providers, tool execution
- `src/MCP/` — MCP status adapter
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

**PHP tooling:** [Mago](https://mago.carthage.software) via `composer install` dev dep (`carthage-software/mago`). Config: `mago.toml`. Analyzer uses `analyzer-baseline.toml` for WordPress stub gaps — shrink the baseline as types improve.

**JS tooling:** [Biome](https://biomejs.dev). Config: `biome.json`.

## Conventions

- PHP: `declare(strict_types=1)`, namespace `AWPT\`, `final` classes where appropriate. WordPress globals (`$wpdb`) are expected — Mago `no-global` is disabled.
- REST namespace: `AWPT_REST_NAMESPACE` (`awpt/v1`). Permission: `manage_options` for MVP.
- Abilities: register on `wp_abilities_api_init`, category `awpt`, prefix `awpt/`.
- TSX: match existing terminal UI patterns in `assets/components/`. Use `@wordpress/i18n` for user-facing strings.
- Do not store large model payloads in post meta/options — use custom tables (`wp_awpt_*`).
- Treat the agent as untrusted: capability checks on tools, explicit approval for destructive actions.

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
- Schema change → update `Database/Installer.php` (migration strategy TBD).
- After PHP edits: `composer run format` then `composer run check`.
- After TSX edits: `npm run lint:fix` then `npm run build` before testing in WP admin.
