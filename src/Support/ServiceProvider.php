<?php

/**
 * Centralised service wiring for AWPT.
 *
 * @package AWPT
 */

declare(strict_types=1);

namespace AWPT\Support;

use AWPT\Abilities\RegisterAbilities;
use AWPT\Admin\Page;
use AWPT\Agent\AgentRuntime;
use AWPT\MCP\WordPressMcpBridge;
use AWPT\REST\ActionsController;
use AWPT\REST\AttachmentsController;
use AWPT\REST\CapturesController;
use AWPT\REST\ChatController;
use AWPT\REST\IncidentsController;
use AWPT\REST\KnowledgeController;
use AWPT\REST\RestController;
use AWPT\REST\SessionsController;
use AWPT\REST\ToolsController;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Provides shared service instances so the dependency graph is explicit and testable.
 */
final class ServiceProvider {
    private Page $page;

    private RegisterAbilities $register_abilities;

    private WordPressMcpBridge $mcp_bridge;

    private AgentRuntime $agent_runtime;

    public function __construct(
        ?Page $page = null,
        ?RegisterAbilities $register_abilities = null,
        ?WordPressMcpBridge $mcp_bridge = null,
        ?AgentRuntime $agent_runtime = null,
    ) {
        $this->page = $page ?? new Page();
        $this->register_abilities = $register_abilities ?? new RegisterAbilities();
        $this->mcp_bridge = $mcp_bridge ?? new WordPressMcpBridge();
        $this->agent_runtime = $agent_runtime ?? new AgentRuntime();
    }

    public function page(): Page {
        return $this->page;
    }

    public function register_abilities(): RegisterAbilities {
        return $this->register_abilities;
    }

    public function mcp_bridge(): WordPressMcpBridge {
        return $this->mcp_bridge;
    }

    public function agent_runtime(): AgentRuntime {
        return $this->agent_runtime;
    }

    /**
     * @return list<RestController>
     */
    public function rest_controllers(): array {
        return [
            new SessionsController(),
            new AttachmentsController(),
            new ChatController($this->agent_runtime),
            new CapturesController(),
            new KnowledgeController(),
            new ActionsController(),
            new IncidentsController(),
            new ToolsController(),
        ];
    }
}
