<?php

/** @package AWPT */

declare(strict_types=1);

namespace AWPT\Agent;

if (!defined('ABSPATH')) {
    exit();
}

final class SessionTurnLock {
    private const TTL = 900;

    private const DEVELOPMENT_TTL = 31_536_000;

    public function acquire(int $session_id, string $turn_id): bool {
        $key = $this->key($session_id);
        $value = ['turn_id' => sanitize_key($turn_id), 'expires' => time() + $this->ttl()];

        if (add_option($key, $value, '', false)) {
            return true;
        }

        $current = get_option($key, []);

        if (is_array($current) && (int) ($current['expires'] ?? 0) >= time()) {
            return false;
        }

        delete_option($key);

        return add_option($key, $value, '', false);
    }

    public function refresh(int $session_id, string $turn_id): void {
        update_option(
            $this->key($session_id),
            [
                'turn_id' => sanitize_key($turn_id),
                'expires' => time() + $this->ttl(),
            ],
            false,
        );
    }

    public function release(int $session_id): void {
        delete_option($this->key($session_id));
    }

    private function key(int $session_id): string {
        return 'awpt_session_turn_lock_' . max(0, $session_id);
    }

    private function ttl(): int {
        $unbounded = true === apply_filters('awpt_unbounded_agent_runtime', true);
        $default = $unbounded ? self::DEVELOPMENT_TTL : self::TTL;

        return max(60, (int) apply_filters('awpt_session_turn_lock_ttl', $default));
    }
}
