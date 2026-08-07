<?php
/**
 * Aggregate queue Improve run summaries into an M5 cohort scorecard.
 *
 * Works offline on existing JSON (no WordPress bootstrap required).
 *
 * Usage:
 *   php bin/cohort-scorecard.php
 *   php bin/cohort-scorecard.php tmp-queue-runs/
 *   php bin/cohort-scorecard.php tmp-queue-runs/awpt-queue-848.json tmp-queue-runs/awpt-queue-853.json
 *   php bin/cohort-scorecard.php --label=post-m3 --out=tmp-queue-runs/cohort-post-m3-summary.json tmp-queue-runs/
 *
 * Defaults to plugins/.../tmp-queue-runs/awpt-queue-*.json next to this bin.
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
	define('ABSPATH', dirname(__DIR__) . '/');
}

// Minimal WordPress polyfills so this script works offline (no WP bootstrap).
if (!function_exists('sanitize_key')) {
	/**
	 * @param string $key
	 */
	function sanitize_key($key): string {
		$key = strtolower((string) $key);

		return (string) preg_replace('/[^a-z0-9_\-]/', '', $key);
	}
}

require dirname(__DIR__) . '/vendor/autoload.php';

use AWPT\Support\QueueImproveScorecard;

$argv_list = array_values(array_slice($argv ?? [], 1));
$label = '';
$note = '';
$out = '';
$inputs = [];

foreach ($argv_list as $arg) {
	if (str_starts_with($arg, '--label=')) {
		$label = substr($arg, 8);
		continue;
	}
	if (str_starts_with($arg, '--note=')) {
		$note = substr($arg, 7);
		continue;
	}
	if (str_starts_with($arg, '--out=')) {
		$out = substr($arg, 6);
		continue;
	}
	if ('--help' === $arg || '-h' === $arg) {
		fwrite(STDOUT, "Usage: php bin/cohort-scorecard.php [--label=] [--note=] [--out=path.json] [files|dirs|globs...]\n");
		exit(0);
	}
	$inputs[] = $arg;
}

if ([] === $inputs) {
	$inputs[] = dirname(__DIR__) . '/tmp-queue-runs';
}

$scorecard = new QueueImproveScorecard();
$files = $scorecard->resolve_input_paths($inputs);

if ([] === $files) {
	fwrite(STDERR, "No awpt-queue-*.json summaries found in inputs.\n");
	exit(1);
}

$cohort = $scorecard->from_files($files, [
	'label' => '' !== $label ? $label : 'cohort-' . gmdate('Ymd'),
	'note' => $note,
]);
$cohort['source_files'] = array_map('basename', $files);
$cohort['source_count'] = count($files);

$encoded = json_encode($cohort, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
if (false === $encoded) {
	fwrite(STDERR, "Failed to encode scorecard JSON.\n");
	exit(1);
}

if ('' === $out) {
	$dir = dirname($files[0]);
	$slug = '' !== $label ? preg_replace('/[^a-z0-9._-]+/i', '-', $label) : gmdate('Ymd-His');
	$out = rtrim($dir, '/') . '/cohort-' . $slug . '-summary.json';
}

if (false === file_put_contents($out, $encoded . "\n")) {
	fwrite(STDERR, "Failed to write {$out}\n");
	exit(1);
}

$s = $cohort['structural'] ?? [];
$rates = is_array($s['rates'] ?? null) ? $s['rates'] : [];

echo "cohort={$cohort['label']}\n";
echo "n={$cohort['n']} structural={$cohort['n_structural_eligible']}\n";
echo 'server_materialized_structural=' . ($rates['server_materialized']['count'] ?? 0)
	. '/' . ($rates['server_materialized']['denominator'] ?? 0)
	. ' rate=' . ($rates['server_materialized']['rate'] ?? 'null') . "\n";
echo 'prepare_success_structural=' . ($rates['prepare_change_success']['count'] ?? 0)
	. '/' . ($rates['prepare_change_success']['denominator'] ?? 0)
	. ' rate=' . ($rates['prepare_change_success']['rate'] ?? 'null') . "\n";
echo 'replace_success_structural=' . ($rates['propose_replace_success']['count'] ?? 0)
	. '/' . ($rates['propose_replace_success']['denominator'] ?? 0)
	. ' rate=' . ($rates['propose_replace_success']['rate'] ?? 'null') . "\n";
echo 'freehand_structural=' . ($rates['freehand_provenance']['count'] ?? 0)
	. '/' . ($rates['freehand_provenance']['denominator'] ?? 0)
	. ' rate=' . ($rates['freehand_provenance']['rate'] ?? 'null') . "\n";
echo 'path_counts=' . json_encode($cohort['path_counts'] ?? []) . "\n";
echo "OUT {$out}\n";
echo "DONE\n";
