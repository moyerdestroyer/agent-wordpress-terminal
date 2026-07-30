<?php

/**
 * Ordered tool-batch execution with parallel-safe waves.
 *
 * @package AWPT
 */

declare(strict_types=1);

namespace AWPT\Agent;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Runs a provider tool-call batch: parallel-safe reads first (original relative
 * order, chunked by concurrency), then serial-only tools. Results are always
 * reassembled in the original provider call order.
 *
 * PHP cannot multi-thread pure WordPress ability work in one request. Waves run
 * in-process sequentially today; partition/order contracts stay ready for a
 * multi-worker transport later.
 */
final class ToolBatchRunner {
    private ToolParallelism $parallelism;

    public function __construct(?ToolParallelism $parallelism = null) {
        $this->parallelism = $parallelism ?? new ToolParallelism();
    }

    public function parallelism(): ToolParallelism {
        return $this->parallelism;
    }

    /**
     * @param list<array{index: int, tool_name: string|null, raw: array<string, mixed>}> $items
     * @param callable(array<string, mixed>, int): array{tool_call: array<string, mixed>, message: array<string, mixed>, visual_message: array<string, mixed>|null} $execute_one
     * @param callable(int, int, string, string): void $on_progress completed, total, tool_name, wave_kind
     * @return list<array{tool_call: array<string, mixed>, message: array<string, mixed>, visual_message: array<string, mixed>|null}>
     */
    public function run(array $items, callable $execute_one, callable $on_progress): array {
        $total = count($items);

        if (0 === $total) {
            return [];
        }

        $parallel = [];
        $serial = [];

        foreach ($items as $item) {
            if ($this->parallelism->is_parallel_safe($item['tool_name'])) {
                $parallel[] = $item;
            } else {
                $serial[] = $item;
            }
        }

        /** @var array<int, array{tool_call: array<string, mixed>, message: array<string, mixed>, visual_message: array<string, mixed>|null}> $by_index */
        $by_index = [];
        $completed = 0;

        foreach ($this->chunk($parallel, $this->parallelism->max_concurrency()) as $wave) {
            foreach ($wave as $item) {
                $result = $execute_one($item['raw'], $item['index']);
                $by_index[$item['index']] = $result;
                ++$completed;
                $tool = (string) ($result['tool_call']['tool'] ?? $item['tool_name'] ?? '');
                $on_progress($completed, $total, $tool, 'batch');
            }
        }

        foreach ($serial as $item) {
            $result = $execute_one($item['raw'], $item['index']);
            $by_index[$item['index']] = $result;
            ++$completed;
            $tool = (string) ($result['tool_call']['tool'] ?? $item['tool_name'] ?? '');
            $on_progress($completed, $total, $tool, 'serial');
        }

        $ordered = [];

        for ($i = 0; $i < $total; ++$i) {
            if (!array_key_exists($i, $by_index)) {
                continue;
            }

            $ordered[] = $by_index[$i];
        }

        return $ordered;
    }

    /**
     * @template T
     * @param list<T> $items
     * @return list<list<T>>
     */
    private function chunk(array $items, int $size): array {
        if ([] === $items) {
            return [];
        }

        $size = max(1, $size);
        $chunks = [];

        for ($offset = 0, $count = count($items); $offset < $count; $offset += $size) {
            $chunks[] = array_slice($items, $offset, $size);
        }

        return $chunks;
    }
}
