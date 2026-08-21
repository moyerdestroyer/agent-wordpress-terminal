#!/usr/bin/env php
<?php
/**
 * Human-readable Improve cohort report (HTML + Markdown).
 *
 * Usage:
 *   php bin/queue-improve-report.php tmp-queue-runs/autonomy-v1
 *   php bin/queue-improve-report.php --site=http://import-testing.totem:8080 tmp-queue-runs/autonomy-v1
 *
 * Opens as: <dir>/REPORT.html
 */

declare(strict_types=1);

$argv_list = array_values(array_slice($argv ?? [], 1));
$site = 'http://import-testing.totem:8080';
$dir = '';

foreach ($argv_list as $arg) {
	if (str_starts_with($arg, '--site=')) {
		$site = rtrim(substr($arg, 7), '/');
		continue;
	}
	if ('--help' === $arg || '-h' === $arg) {
		fwrite(STDOUT, "Usage: php bin/queue-improve-report.php [--site=URL] <cohort-dir>\n");
		exit(0);
	}
	$dir = $arg;
}

if ('' === $dir || !is_dir($dir)) {
	fwrite(STDERR, "Provide an existing cohort directory (e.g. tmp-queue-runs/autonomy-v1).\n");
	exit(1);
}

$dir = realpath($dir) ?: $dir;
$files = glob($dir . '/awpt-queue-*.json') ?: [];
usort($files, static function (string $a, string $b): int {
	$sa = json_decode((string) file_get_contents($a), true);
	$sb = json_decode((string) file_get_contents($b), true);
	$pa = (int) ($sa['post_id'] ?? 0);
	$pb = (int) ($sb['post_id'] ?? 0);

	return $pa <=> $pb ?: strcmp($a, $b);
});

// Prefer latest summary per post_id.
$by_post = [];
foreach ($files as $file) {
	$summary = json_decode((string) file_get_contents($file), true);
	if (!is_array($summary)) {
		continue;
	}
	$post_id = (int) ($summary['post_id'] ?? 0);
	if ($post_id <= 0) {
		continue;
	}
	$mtime = (int) filemtime($file);
	$prev = $by_post[$post_id] ?? null;
	if (null === $prev || $mtime >= (int) ($prev['mtime'] ?? 0)) {
		$by_post[$post_id] = ['file' => $file, 'mtime' => $mtime, 'summary' => $summary];
	}
}

$matrix_notes = [];
$matrix_path = $dir . '/matrix.json';
if (is_file($matrix_path)) {
	$matrix = json_decode((string) file_get_contents($matrix_path), true);
	if (is_array($matrix)) {
		foreach ($matrix as $row) {
			if (!is_array($row)) {
				continue;
			}
			$matrix_notes[(int) ($row['post_id'] ?? 0)] = [
				'notes' => (string) ($row['notes'] ?? ''),
				'class' => (string) ($row['class'] ?? ''),
			];
		}
	}
}

$rows = [];
$pass = 0;
$fail = 0;

foreach ($by_post as $post_id => $entry) {
	$s = $entry['summary'];
	$actions = is_array($s['actions'] ?? null) ? $s['actions'] : [];
	$outcome = is_array($s['turn_outcome'] ?? null) ? $s['turn_outcome'] : [];
	$outcome_status = (string) ($outcome['status'] ?? '');
	$error_code = (string) ($outcome['error_code'] ?? '');
	$applied = [];
	$filler = false;
	$preview = '';
	$ops = [];
	$patterns = [];
	$rolled_back = false;

	foreach ($actions as $action) {
		if (!is_array($action)) {
			continue;
		}
		$ops[] = (string) ($action['operation'] ?? '');
		if ('' !== (string) ($action['pattern_name'] ?? '')) {
			$patterns[] = (string) $action['pattern_name'];
		}
		$audit = is_array($action['content_audit'] ?? null) ? $action['content_audit'] : [];
		if (!empty($audit['instructional_filler'])) {
			$filler = true;
		}
		if ('' === $preview && !empty($audit['preview'])) {
			$preview = (string) $audit['preview'];
		}
		if (!empty($action['rolled_back'])) {
			$rolled_back = true;
		}
		if (!empty($action['applied']) || in_array((string) ($action['status'] ?? ''), ['applied', 'rolled_back'], true)) {
			$applied[] = $action;
		}
	}

	$tools = is_array($s['tools'] ?? null) ? $s['tools'] : [];
	$failed_tools = array_values(array_filter(
		$tools,
		static fn($t): bool => is_string($t) && (str_ends_with($t, ':failed') || str_contains($t, ':error')),
	));

	$ok = [] !== $applied && !$filler && 'failed' !== $outcome_status;
	$quality_fail = false;
	foreach ($actions as $action) {
		if (!is_array($action)) {
			continue;
		}
		$audit = is_array($action['content_audit'] ?? null) ? $action['content_audit'] : [];
		if (array_key_exists('quality_ok', $audit) && empty($audit['quality_ok'])) {
			$quality_fail = true;
		}
		if (!empty($audit['duplication_suspect']) || !empty($audit['instructional_filler'])) {
			$quality_fail = true;
		}
	}
	if ($quality_fail) {
		$ok = false;
	}
	if ($ok) {
		++$pass;
	} else {
		++$fail;
	}

	$notes = trim((string) ($s['notes'] ?? ''));
	if ('' === $notes && isset($matrix_notes[$post_id])) {
		$notes = (string) ($matrix_notes[$post_id]['notes'] ?? '');
	}

	$class = (string) ($matrix_notes[$post_id]['class'] ?? ($s['meta']['scenario_class'] ?? ''));

	$rows[] = [
		'post_id' => $post_id,
		'title' => (string) ($s['title'] ?? ''),
		'notes' => $notes,
		'class' => $class,
		'ok' => $ok,
		'path' => (string) ($s['path_used'] ?? ''),
		'outcome' => $outcome_status,
		'error_code' => $error_code,
		'elapsed_s' => $s['elapsed_s'] ?? null,
		'ops' => array_values(array_filter($ops)),
		'patterns' => array_values(array_unique($patterns)),
		'filler' => $filler,
		'rolled_back' => $rolled_back,
		'failed_tools' => $failed_tools,
		'tools' => $tools,
		'plan' => trim((string) ($s['plan_excerpt'] ?? '')),
		'assistant' => trim((string) ($s['assistant_excerpt'] ?? '')),
		'preview' => $preview,
		'session_id' => (int) ($s['session_id'] ?? 0),
		'file' => basename((string) $entry['file']),
		'action_titles' => array_values(array_filter(array_map(
			static fn($a): string => is_array($a) ? (string) ($a['title'] ?? '') : '',
			$applied,
		))),
	];
}

usort($rows, static fn(array $a, array $b): int => $a['post_id'] <=> $b['post_id']);

$h = static function (string $value): string {
	return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
};

$generated = gmdate('Y-m-d H:i:s') . ' UTC';
$label = basename($dir);

ob_start();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Improve cohort — <?= $h($label) ?></title>
<style>
  :root {
    --bg: #f7f4ef;
    --ink: #1c1917;
    --muted: #57534e;
    --line: #d6d3d1;
    --pass: #166534;
    --pass-bg: #dcfce7;
    --fail: #991b1b;
    --fail-bg: #fee2e2;
    --card: #fffdf8;
    --accent: #0f766e;
  }
  * { box-sizing: border-box; }
  body {
    margin: 0;
    font: 15px/1.45 "Iowan Old Style", "Palatino Linotype", Palatino, Georgia, serif;
    color: var(--ink);
    background:
      radial-gradient(1200px 600px at 10% -10%, #e7f0ee 0%, transparent 55%),
      linear-gradient(180deg, #f3efe8, var(--bg));
  }
  main { max-width: 980px; margin: 0 auto; padding: 2rem 1.25rem 4rem; }
  h1 { font-size: 1.85rem; margin: 0 0 .35rem; letter-spacing: -0.02em; }
  .sub { color: var(--muted); margin: 0 0 1.5rem; }
  .summary {
    display: grid;
    grid-template-columns: repeat(3, minmax(0, 1fr));
    gap: .75rem;
    margin-bottom: 1.5rem;
  }
  .stat {
    background: var(--card);
    border: 1px solid var(--line);
    border-radius: 10px;
    padding: .9rem 1rem;
  }
  .stat b { display: block; font-size: 1.6rem; line-height: 1.1; }
  .stat span { color: var(--muted); font-size: .9rem; }
  .filters { display: flex; gap: .5rem; flex-wrap: wrap; margin-bottom: 1rem; }
  .filters button {
    border: 1px solid var(--line);
    background: #fff;
    border-radius: 999px;
    padding: .35rem .8rem;
    cursor: pointer;
    font: inherit;
  }
  .filters button[aria-pressed="true"] { background: #134e4a; color: #fff; border-color: #134e4a; }
  .card {
    background: var(--card);
    border: 1px solid var(--line);
    border-radius: 12px;
    padding: 1rem 1.1rem 1.15rem;
    margin-bottom: .9rem;
  }
  .card.fail { border-color: #fca5a5; }
  .card.pass { border-color: #86efac; }
  .head { display: flex; justify-content: space-between; gap: 1rem; align-items: flex-start; }
  .badge {
    display: inline-block;
    font: 600 12px/1 ui-sans-serif, system-ui, sans-serif;
    letter-spacing: .04em;
    text-transform: uppercase;
    padding: .35rem .55rem;
    border-radius: 6px;
  }
  .badge.pass { background: var(--pass-bg); color: var(--pass); }
  .badge.fail { background: var(--fail-bg); color: var(--fail); }
  .title { margin: 0; font-size: 1.15rem; }
  .meta { color: var(--muted); font-size: .92rem; margin: .2rem 0 .7rem; }
  .notes {
    background: #f5f1ea;
    border-left: 3px solid var(--accent);
    padding: .55rem .75rem;
    margin: 0 0 .75rem;
  }
  .grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: .75rem 1rem;
  }
  @media (max-width: 720px) {
    .summary, .grid { grid-template-columns: 1fr; }
    .head { flex-direction: column; }
  }
  dt { font: 600 11px/1.2 ui-sans-serif, system-ui, sans-serif; text-transform: uppercase; letter-spacing: .05em; color: var(--muted); }
  dd { margin: .15rem 0 .55rem; }
  .preview, .plan {
    font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
    font-size: 12.5px;
    background: #1c1917;
    color: #e7e5e4;
    border-radius: 8px;
    padding: .7rem .8rem;
    white-space: pre-wrap;
    max-height: 9.5rem;
    overflow: auto;
  }
  .links a { color: var(--accent); margin-right: .9rem; }
  .tools { font-size: .88rem; color: var(--muted); word-break: break-word; }
</style>
</head>
<body>
<main>
  <h1>Improve cohort: <?= $h($label) ?></h1>
  <p class="sub">Generated <?= $h($generated) ?> · <?= count($rows) ?> pages · site <?= $h($site) ?></p>

  <div class="summary">
    <div class="stat"><b><?= (int) $pass ?></b><span>Passed (staged, no filler)</span></div>
    <div class="stat"><b><?= (int) $fail ?></b><span>Needs attention</span></div>
    <div class="stat"><b><?= count($rows) ?></b><span>Pages audited</span></div>
  </div>

  <div class="filters">
    <button type="button" data-filter="all" aria-pressed="true">All</button>
    <button type="button" data-filter="fail" aria-pressed="false">Needs attention</button>
    <button type="button" data-filter="pass" aria-pressed="false">Passed</button>
  </div>

<?php foreach ($rows as $row): ?>
  <article class="card <?= $row['ok'] ? 'pass' : 'fail' ?>" data-status="<?= $row['ok'] ? 'pass' : 'fail' ?>">
    <div class="head">
      <div>
        <h2 class="title">#<?= (int) $row['post_id'] ?> — <?= $h($row['title'] !== '' ? $row['title'] : '(untitled)') ?></h2>
        <p class="meta">
          path <strong><?= $h($row['path'] !== '' ? $row['path'] : '—') ?></strong>
          · outcome <?= $h($row['outcome'] !== '' ? $row['outcome'] : '—') ?>
          <?php if ('' !== $row['error_code']): ?>· <?= $h($row['error_code']) ?><?php endif; ?>
          · <?= null !== $row['elapsed_s'] ? $h((string) $row['elapsed_s']) . 's' : '?' ?>
          <?php if ($row['rolled_back']): ?>· rolled back<?php endif; ?>
          <?php if ('' !== $row['class']): ?>· class <?= $h($row['class']) ?><?php endif; ?>
        </p>
      </div>
      <span class="badge <?= $row['ok'] ? 'pass' : 'fail' ?>"><?= $row['ok'] ? 'Pass' : 'Needs attention' ?></span>
    </div>

    <?php if ('' !== $row['notes']): ?>
      <div class="notes"><strong>Request:</strong> <?= $h($row['notes']) ?></div>
    <?php endif; ?>

    <div class="grid">
      <div>
        <dl>
          <dt>What staged</dt>
          <dd><?php
			$bits = $row['action_titles'];
			if ([] === $bits && [] !== $row['ops']) {
				$bits = $row['ops'];
			}
			echo $h([] !== $bits ? implode(' · ', $bits) : 'Nothing staged');
			?></dd>
          <dt>Pattern</dt>
          <dd><?= $h([] !== $row['patterns'] ? implode(', ', $row['patterns']) : '—') ?></dd>
          <dt>Failed tools</dt>
          <dd><?= $h([] !== $row['failed_tools'] ? implode(', ', $row['failed_tools']) : 'none') ?></dd>
        </dl>
        <p class="links">
          <a href="<?= $h($site . '/?p=' . $row['post_id']) ?>" target="_blank" rel="noreferrer">View page</a>
          <a href="<?= $h($site . '/wp-admin/post.php?post=' . $row['post_id'] . '&action=edit') ?>" target="_blank" rel="noreferrer">Edit in WP</a>
          <a href="<?= $h($site . '/wp-admin/admin.php?page=agent-wordpress-terminal') ?>" target="_blank" rel="noreferrer">Agent Terminal</a>
        </p>
      </div>
      <div>
        <dt>Content after apply (preview)</dt>
        <div class="preview"><?= $h('' !== $row['preview'] ? $row['preview'] : '(no apply preview — nothing applied or rolled back before capture)') ?></div>
      </div>
    </div>

    <details style="margin-top:.75rem">
      <summary>Plan / assistant / tools</summary>
      <p><strong>Plan excerpt</strong></p>
      <div class="plan"><?= $h('' !== $row['plan'] ? $row['plan'] : '(empty)') ?></div>
      <p><strong>Assistant excerpt</strong></p>
      <div class="plan"><?= $h('' !== $row['assistant'] ? $row['assistant'] : '(empty)') ?></div>
      <p class="tools"><?= $h(implode(' · ', array_map('strval', $row['tools']))) ?></p>
      <p class="tools">Source: <?= $h($row['file']) ?> · session <?= (int) $row['session_id'] ?></p>
    </details>
  </article>
<?php endforeach; ?>

<script>
document.querySelectorAll('.filters button').forEach((btn) => {
  btn.addEventListener('click', () => {
    const filter = btn.dataset.filter;
    document.querySelectorAll('.filters button').forEach((b) => b.setAttribute('aria-pressed', String(b === btn)));
    document.querySelectorAll('.card').forEach((card) => {
      card.hidden = filter !== 'all' && card.dataset.status !== filter;
    });
  });
});
</script>
</main>
</body>
</html>
<?php
$html = ob_get_clean();
$html_path = $dir . '/REPORT.html';
file_put_contents($html_path, $html);

$md = [];
$md[] = '# Improve cohort: ' . $label;
$md[] = '';
$md[] = sprintf('Generated %s · **%d pass** / **%d needs attention** / %d pages', $generated, $pass, $fail, count($rows));
$md[] = '';
$md[] = '| Post | Verdict | Path | Request | Staged |';
$md[] = '|---:|---|---|---|---|';
foreach ($rows as $row) {
	$staged = [] !== $row['action_titles'] ? implode('; ', $row['action_titles']) : implode(', ', $row['ops']);
	if ('' === $staged) {
		$staged = '—';
	}
	$md[] = sprintf(
		'| %d | %s | `%s` | %s | %s |',
		$row['post_id'],
		$row['ok'] ? 'Pass' : 'Needs attention',
		$row['path'] !== '' ? $row['path'] : '—',
		str_replace('|', '/', $row['notes'] !== '' ? $row['notes'] : '—'),
		str_replace('|', '/', $staged),
	);
}
$md[] = '';
$md[] = 'Open `REPORT.html` in a browser for previews and WP links.';
$md_path = $dir . '/REPORT.md';
file_put_contents($md_path, implode("\n", $md) . "\n");

fwrite(STDOUT, "HTML {$html_path}\n");
fwrite(STDOUT, "MD   {$md_path}\n");
fwrite(STDOUT, "pass={$pass} fail={$fail} pages=" . count($rows) . "\n");
