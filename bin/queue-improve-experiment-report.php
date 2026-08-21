#!/usr/bin/env php
<?php
/**
 * Aggregate experiment cell JSON into EXPERIMENT.html + EXPERIMENT.md.
 *
 * Usage: php bin/queue-improve-experiment-report.php tmp-queue-runs/wave1-rca
 */

declare(strict_types=1);

$dir = $argv[1] ?? '';
if ('' === $dir || !is_dir($dir)) {
	fwrite(STDERR, "Provide experiment output directory.\n");
	exit(1);
}
$dir = realpath($dir) ?: $dir;
$files = glob($dir . '/cell-*.json') ?: [];
$rows = [];
foreach ($files as $file) {
	$s = json_decode((string) file_get_contents($file), true);
	if (!is_array($s)) {
		continue;
	}
	$exp = is_array($s['experiment'] ?? null) ? $s['experiment'] : [];
	$ver = is_array($s['experiment_verdict'] ?? null) ? $s['experiment_verdict'] : [];
	$cache = is_array($s['cache'] ?? null) ? $s['cache'] : [];
	$rows[] = [
		'file' => basename($file),
		'post' => (int) ($s['post_id'] ?? 0),
		'path' => (string) ($exp['path'] ?? ''),
		'provider' => (string) ($exp['provider'] ?? ($s['meta']['provider'] ?? '')),
		'model' => (string) ($exp['model'] ?? ($s['meta']['model'] ?? '')),
		'prompt' => (string) ($exp['prompt'] ?? ($s['meta']['prompt_variant'] ?? '')),
		'rep' => (int) ($exp['rep'] ?? 0),
		'quality_ok' => !empty($ver['quality_ok']),
		'applied' => !empty($ver['applied']),
		'filler' => !empty($ver['filler']),
		'dup' => !empty($ver['duplication']),
		'path_used' => (string) ($ver['path_used'] ?? $s['path_used'] ?? ''),
		'outcome' => (string) ($ver['outcome'] ?? ''),
		'elapsed' => $s['elapsed_s'] ?? null,
		'prompt_tokens' => (int) ($ver['prompt_tokens'] ?? $cache['prompt_tokens'] ?? 0),
		'cached_tokens' => (int) ($ver['cached_tokens'] ?? $cache['cached_tokens'] ?? 0),
		'cache_hit_rate' => $ver['cache_hit_rate'] ?? $cache['cache_hit_rate'] ?? null,
	];
}

usort(
	$rows,
	static fn(array $a, array $b): int => [$a['provider'], $a['path'], $a['model'], $a['prompt'], $a['rep']]
		<=> [$b['provider'], $b['path'], $b['model'], $b['prompt'], $b['rep']],
);

/** @param list<array<string, mixed>> $subset */
$rate = static function (array $subset): array {
	$n = count($subset);
	$ok = count(array_filter($subset, static fn($r) => !empty($r['quality_ok'])));
	$applied = count(array_filter($subset, static fn($r) => !empty($r['applied'])));
	$filler = count(array_filter($subset, static fn($r) => !empty($r['filler'])));
	$dup = count(array_filter($subset, static fn($r) => !empty($r['dup'])));
	$prompt = array_sum(array_map(static fn($r) => (int) ($r['prompt_tokens'] ?? 0), $subset));
	$cached = array_sum(array_map(static fn($r) => (int) ($r['cached_tokens'] ?? 0), $subset));

	return [
		'n' => $n,
		'ok' => $ok,
		'ok_rate' => $n > 0 ? round(100 * $ok / $n, 1) : 0.0,
		'applied_rate' => $n > 0 ? round(100 * $applied / $n, 1) : 0.0,
		'filler_rate' => $n > 0 ? round(100 * $filler / $n, 1) : 0.0,
		'dup_rate' => $n > 0 ? round(100 * $dup / $n, 1) : 0.0,
		'prompt_tokens' => $prompt,
		'cached_tokens' => $cached,
		'cache_hit_rate' => $prompt > 0 ? round(100 * $cached / $prompt, 1) : null,
	];
};

$by = static function (array $rows, string $key) use ($rate): array {
	$groups = [];
	foreach ($rows as $r) {
		$groups[(string) $r[$key]][] = $r;
	}
	ksort($groups);
	$out = [];
	foreach ($groups as $k => $subset) {
		$out[$k] = $rate($subset);
	}

	return $out;
};

$summary = [
	'all' => $rate($rows),
	'by_path' => $by($rows, 'path'),
	'by_provider' => $by($rows, 'provider'),
	'by_model' => $by($rows, 'model'),
	'by_prompt' => $by($rows, 'prompt'),
];

$h = static fn(string $v): string => htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
ob_start();
?>
<!DOCTYPE html>
<html lang="en"><head><meta charset="utf-8"><title>Experiment <?= $h(basename($dir)) ?></title>
<style>
body{font:15px/1.45 Georgia,serif;max-width:1100px;margin:2rem auto;padding:0 1rem;background:#f7f4ef;color:#1c1917}
table{border-collapse:collapse;width:100%;background:#fff;margin:1rem 0}
th,td{border:1px solid #d6d3d1;padding:.45rem .55rem;text-align:left;font-size:13px}
th{background:#ece7df;font:600 12px system-ui,sans-serif;text-transform:uppercase;letter-spacing:.04em}
.ok{background:#dcfce7}.bad{background:#fee2e2}
</style></head><body>
<h1>Experiment: <?= $h(basename($dir)) ?></h1>
<p><?= (int) $summary['all']['n'] ?> runs ·
  quality <?= $h((string) $summary['all']['ok_rate']) ?>% ·
  applied <?= $h((string) $summary['all']['applied_rate']) ?>% ·
  cache hit <?= $h((string) ($summary['all']['cache_hit_rate'] ?? 'n/a')) ?>%</p>

<h2>By provider</h2>
<table><tr><th>Provider</th><th>N</th><th>Quality%</th><th>Cache hit%</th><th>Prompt tok</th><th>Cached tok</th></tr>
<?php foreach ($summary['by_provider'] as $k => $st): ?>
<tr><td><?= $h($k) ?></td><td><?= (int) $st['n'] ?></td><td><?= $h((string) $st['ok_rate']) ?></td>
<td><?= $h((string) ($st['cache_hit_rate'] ?? 'n/a')) ?></td>
<td><?= (int) $st['prompt_tokens'] ?></td><td><?= (int) $st['cached_tokens'] ?></td></tr>
<?php endforeach; ?>
</table>

<h2>By model</h2>
<table><tr><th>Model</th><th>N</th><th>Quality%</th><th>Applied%</th><th>Cache hit%</th></tr>
<?php foreach ($summary['by_model'] as $k => $st): ?>
<tr><td><?= $h($k) ?></td><td><?= (int) $st['n'] ?></td><td><?= $h((string) $st['ok_rate']) ?></td>
<td><?= $h((string) $st['applied_rate']) ?></td><td><?= $h((string) ($st['cache_hit_rate'] ?? 'n/a')) ?></td></tr>
<?php endforeach; ?>
</table>

<h2>All runs</h2>
<table><tr><th>Provider</th><th>Path</th><th>Model</th><th>Rep</th><th>Quality</th><th>Cache%</th><th>Path used</th><th>Elapsed</th></tr>
<?php foreach ($rows as $r): ?>
<tr class="<?= !empty($r['quality_ok']) ? 'ok' : 'bad' ?>">
<td><?= $h($r['provider']) ?></td>
<td><?= $h($r['path']) ?></td>
<td><?= $h($r['model']) ?></td>
<td><?= (int) $r['rep'] ?></td>
<td><?= !empty($r['quality_ok']) ? 'PASS' : 'FAIL' ?></td>
<td><?= $h((string) ($r['cache_hit_rate'] ?? 'n/a')) ?></td>
<td><?= $h($r['path_used']) ?></td>
<td><?= $h((string) ($r['elapsed'] ?? '')) ?></td>
</tr>
<?php endforeach; ?>
</table>
</body></html>
<?php
$html = ob_get_clean();
file_put_contents($dir . '/EXPERIMENT.html', $html);
file_put_contents($dir . '/EXPERIMENT.json', json_encode(['summary' => $summary, 'rows' => $rows], JSON_PRETTY_PRINT));

$md = ["# Experiment " . basename($dir), '', sprintf(
	'%d runs · quality **%s%%** · applied %s%% · cache hit %s%%',
	$summary['all']['n'],
	$summary['all']['ok_rate'],
	$summary['all']['applied_rate'],
	(string) ($summary['all']['cache_hit_rate'] ?? 'n/a'),
), '', '## By provider', ''];
foreach ($summary['by_provider'] as $k => $st) {
	$md[] = sprintf(
		'- `%s`: %s%% quality · cache hit %s%% (n=%d)',
		$k,
		$st['ok_rate'],
		(string) ($st['cache_hit_rate'] ?? 'n/a'),
		$st['n'],
	);
}
$md[] = '';
$md[] = '## By model';
$md[] = '';
foreach ($summary['by_model'] as $k => $st) {
	$md[] = sprintf('- `%s`: %s%% quality (n=%d)', $k, $st['ok_rate'], $st['n']);
}
$md[] = '';
$md[] = 'Open `EXPERIMENT.html` for the full grid.';
file_put_contents($dir . '/EXPERIMENT.md', implode("\n", $md) . "\n");
fwrite(STDOUT, "HTML {$dir}/EXPERIMENT.html\n");
fwrite(STDOUT, "JSON {$dir}/EXPERIMENT.json\n");
