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

## Commands

| Task | Command |
|------|---------|
| PHP lint | `composer run lint` |
| PHP format | `composer run format` |
| PHP analyze | `composer run analyze` |
| PHP check (lint + analyze) | `composer run check` |
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
| `src/Agent/AgentRuntime.php` | Message dispatch, slash commands |
| `assets/components/Terminal.tsx` | Main UI shell |
| `mago.toml` / `biome.json` | Lint/format config |

## MVP scope (0.1)

In scope: admin UI, sessions, context picker, tool registry display, read/analyze/preview abilities, slash commands, action card stubs.

Out of scope for now: full autonomous editing, multi-agent orchestration, remote browser workers.

## When changing things

- New REST route → add controller in `src/REST/`, register in `Plugin::register_rest_routes()`.
- New ability → class in `src/Abilities/`, hook from `RegisterAbilities`.
- New UI panel → component under `assets/components/`, wire in `Terminal.tsx`.
- Schema change → update `Database/Installer.php` (migration strategy TBD).
- After PHP edits: `composer run format` then `composer run check`.
- After TSX edits: `npm run lint:fix` then `npm run build` before testing in WP admin.
