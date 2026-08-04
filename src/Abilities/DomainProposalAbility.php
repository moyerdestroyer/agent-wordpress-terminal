<?php

declare(strict_types=1);

namespace AWPT\Abilities;

use AWPT\Database\SessionRepository;
use AWPT\Domain\DomainProposalManager;
use AWPT\Support\ArrayKey;

if (!defined('ABSPATH')) {
    exit();
}

/** Registers one proposal Ability from a trusted Domain Pack operation contract. */
final class DomainProposalAbility {
    /** @param array<string, mixed> $operation */
    public function __construct(
        private array $operation,
    ) {}

    public function register(): void {
        AbilityRegistrar::register([
            'name' => (string) $this->operation['ability_name'],
            'label' => sanitize_text_field((string) ($this->operation['label'] ?? 'Domain Proposal')),
            'description' => sanitize_textarea_field((string) ($this->operation['description'] ?? '')),
            'input_schema' => ArrayKey::as_map($this->operation['input_schema'] ?? null),
            'output_schema' => ['type' => 'object'],
            'permission_callback' => [$this, 'can_propose'],
            'execute_callback' => [$this, 'execute'],
            'annotations' => ['readonly' => false, 'destructive' => false, 'requires_approval' => true],
        ]);
    }

    /** @param array<string, mixed> $input */
    public function can_propose(array $input): bool {
        $permission = $this->operation['permission_callback'] ?? null;

        return is_callable($permission) && true === $permission($input, 'stage');
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>|\WP_Error
     */
    public function execute(array $input): array|\WP_Error {
        $session_id = (int) ($input['session_id'] ?? 0);

        if ($session_id <= 0 || !new SessionRepository()->exists($session_id)) {
            return new \WP_Error('awpt_session_not_found', __('Session not found.', 'agent-wordpress-terminal'), [
                'status' => 404,
            ]);
        }

        return new DomainProposalManager()->stage($this->operation, $input);
    }
}
