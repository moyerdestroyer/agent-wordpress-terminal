# Product

## Register

product

## Users

WordPress administrators, developers, and technical site maintainers working inside wp-admin. They use AWPT while inspecting live site state, selecting content, asking an agent for analysis, reviewing tool results, and deciding whether proposed changes are safe to apply.

## Product Purpose

AWPT is a WordPress-native terminal for agent-assisted site work. It gives humans a focused cockpit for chat, Core Knowledge-ready retrieval, WordPress Abilities, MCP tool visibility, previews, and clear action boundaries. Success means admins can understand what knowledge and tool evidence the agent used, what tools are available, what the agent did, and whether a change is staged or was applied by an explicitly initiated review workflow.

## Brand Personality

Precise, technical, restrained. The interface should feel like a trusted developer console inside WordPress admin: compact, legible, explicit about state, and calm under operational pressure.

## Anti-references

Avoid bulky chatbot layouts, marketing-style AI assistant chrome, decorative dashboards, autonomous-agent theater, hidden tool execution, and interfaces that obscure WordPress capability boundaries. The product should not feel like a generic SaaS chat widget dropped into wp-admin.

## Design Principles

- Make knowledge explicit: Core Knowledge, indexed site content, provider state, and tool scope should be visible rather than implied.
- Keep the human in the loop: Terminal writes remain staged for approval. Review-queue Improve may auto-apply one reversible, page-scoped safe content action after an explicit click, with visible context and Undo; destructive or broader writes still require approval.
- Prefer operational density: admins should be able to scan sessions, tools, logs, previews, and actions without navigating away.
- Respect WordPress boundaries: capability checks, nonces, REST permissions, and secure secret handling are part of the product experience.
- Show evidence: agent responses should be connected to tool calls, results, previews, and action records.

## Accessibility & Inclusion

Target WCAG AA contrast for text and controls. Preserve keyboard navigation across the terminal input, sidebars, tabs, action buttons, and preview controls. Do not rely on color alone for state; include labels or icons for connected, pending, approved, rejected, failed, read-only, manage, and destructive states. Respect reduced-motion preferences.
