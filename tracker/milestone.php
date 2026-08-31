<?php
require_once __DIR__ . '/config.php';

$id = preg_replace('/[^0-9]/', '', $_GET['id'] ?? '');
$id = str_pad($id, 2, '0', STR_PAD_LEFT);

// Find milestone
$milestone = null;
$milestone_index = 0;
foreach ($MILESTONES as $i => $m) {
    if ($m['id'] === $id) { $milestone = $m; $milestone_index = $i; break; }
}
if (!$milestone) { header('Location: index.php'); exit; }

$progress = load_progress();
$tasks    = parse_checklist(MILESTONES_DIR . $milestone['file']);
$stats    = milestone_stats($id, $progress, $tasks);
$pb       = priority_badge($milestone['priority']);
$mc       = month_config($milestone['month']);

// Build task states (merge saved progress with defaults)
$task_states = [];
foreach ($tasks as $i => $t) {
    $saved = $progress[$id][(string)$i] ?? null;
    $task_states[$i] = ($saved === null) ? $t['default'] : (bool)$saved;
}

// Prev / Next navigation
$prev = $milestone_index > 0                       ? $MILESTONES[$milestone_index - 1] : null;
$next = $milestone_index < count($MILESTONES) - 1  ? $MILESTONES[$milestone_index + 1] : null;
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= htmlspecialchars($milestone['title']) ?> — FDE Tracker</title>
<style>
:root {
    --bg:         #0f172a;
    --surface:    #1e293b;
    --surface2:   #273549;
    --border:     #334155;
    --text:       #e2e8f0;
    --text-muted: #94a3b8;
    --accent:     #38bdf8;
    --p1:         #ef4444;
    --p2:         #f97316;
    --p3:         #eab308;
    --green:      #22c55e;
    --radius:     12px;
    --month-color: <?= $mc['color'] ?>;
}
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
body { font-family: system-ui, -apple-system, sans-serif; background: var(--bg); color: var(--text); min-height: 100vh; }
a    { color: var(--accent); text-decoration: none; }
a:hover { text-decoration: underline; }
code { background: rgba(255,255,255,.08); padding: 1px 5px; border-radius: 4px; font-family: monospace; font-size: 0.88em; }

.container { max-width: 860px; margin: 0 auto; padding: 24px 20px; }

/* Nav bar */
.topnav { display: flex; align-items: center; justify-content: space-between; margin-bottom: 28px; flex-wrap: wrap; gap: 10px; }
.topnav .back { display: flex; align-items: center; gap: 6px; color: var(--text-muted); font-size: 0.85rem; }
.topnav .back:hover { color: var(--accent); text-decoration: none; }
.ms-nav { display: flex; gap: 8px; }
.ms-nav a { display: inline-flex; align-items: center; gap: 4px; font-size: 0.8rem; padding: 5px 12px;
             background: var(--surface); border: 1px solid var(--border); border-radius: 8px;
             color: var(--text-muted); transition: border-color .2s; }
.ms-nav a:hover { border-color: var(--accent); color: var(--accent); text-decoration: none; }

/* Header card */
.ms-header { background: var(--surface); border: 1px solid var(--border); border-radius: var(--radius);
              padding: 28px; margin-bottom: 24px; border-top: 4px solid var(--month-color); }
.ms-header-top { display: flex; align-items: flex-start; justify-content: space-between; gap: 12px; margin-bottom: 16px; flex-wrap: wrap; }
.ms-meta  { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }
.tag      { font-size: 0.7rem; font-weight: 600; padding: 3px 9px; border-radius: 20px; letter-spacing: 0.04em; border: 1px solid; }
.tag-month  { color: var(--month-color); border-color: var(--month-color); background: transparent; }
.tag-weeks  { color: var(--text-muted); border-color: var(--border); }
.tag-domain { color: var(--text-muted); border-color: var(--border); }
.tag-p1  { color: var(--p1); border-color: var(--p1); background: rgba(239,68,68,.08); }
.tag-p2  { color: var(--p2); border-color: var(--p2); background: rgba(249,115,22,.08); }
.tag-p3  { color: var(--p3); border-color: var(--p3); background: rgba(234,179,8,.08); }

.ms-num-title { display: flex; align-items: baseline; gap: 10px; margin-bottom: 12px; }
.ms-num-title .num { font-size: 0.8rem; font-weight: 700; color: var(--text-muted); }
.ms-num-title h1  { font-size: 1.5rem; font-weight: 800; letter-spacing: -0.02em; }

.ms-info { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; margin-top: 20px; }
.ms-info-block { }
.ms-info-block .label { font-size: 0.68rem; text-transform: uppercase; letter-spacing: 0.1em; color: var(--text-muted); margin-bottom: 4px; font-weight: 600; }
.ms-info-block .value { font-size: 0.88rem; line-height: 1.4; }
.ms-info-block .value.deliverable { color: var(--accent); }

/* Progress section */
.progress-section { background: var(--surface); border: 1px solid var(--border); border-radius: var(--radius);
                    padding: 24px; margin-bottom: 24px; }
.progress-header { display: flex; align-items: center; justify-content: space-between; margin-bottom: 14px; }
.progress-header h2 { font-size: 1rem; font-weight: 700; }
.progress-pct { font-size: 1.6rem; font-weight: 800; color: var(--accent); }
.progress-pct.done { color: var(--green); }
.prog-bar { height: 10px; background: var(--border); border-radius: 5px; overflow: hidden; margin-bottom: 10px; }
.prog-fill { height: 100%; border-radius: 5px; transition: width .5s ease; background: linear-gradient(90deg, var(--accent), #818cf8); }
.prog-fill.done { background: linear-gradient(90deg, var(--green), #10b981); }
.prog-sub { display: flex; justify-content: space-between; font-size: 0.78rem; color: var(--text-muted); }

/* Bulk actions */
.bulk-actions { display: flex; gap: 8px; margin-bottom: 18px; }
.btn { font-size: 0.78rem; font-weight: 600; padding: 6px 14px; border-radius: 7px; cursor: pointer;
       border: 1px solid var(--border); background: var(--surface2); color: var(--text-muted);
       transition: all .15s; }
.btn:hover { border-color: var(--accent); color: var(--accent); }
.btn.primary { background: var(--accent); color: #0f172a; border-color: var(--accent); }
.btn.primary:hover { opacity: .85; color: #0f172a; }
.btn.danger  { border-color: var(--p1); color: var(--p1); }
.btn.danger:hover  { background: rgba(239,68,68,.1); }

/* Checklist */
.checklist { display: flex; flex-direction: column; gap: 4px; }
.task-item { display: flex; align-items: flex-start; gap: 12px; padding: 12px 14px;
             border: 1px solid var(--border); border-radius: 9px; cursor: pointer;
             transition: border-color .15s, background .15s; }
.task-item:hover { border-color: var(--accent); background: rgba(56,189,248,.04); }
.task-item.checked { background: rgba(34,197,94,.06); border-color: rgba(34,197,94,.3); }
.task-item input[type=checkbox] { display: none; }
.task-checkbox { width: 20px; height: 20px; border-radius: 5px; border: 2px solid var(--border);
                  flex-shrink: 0; margin-top: 1px; display: flex; align-items: center; justify-content: center;
                  transition: all .15s; background: var(--surface); }
.task-item.checked .task-checkbox { background: var(--green); border-color: var(--green); }
.checkmark { display: none; color: white; font-size: 12px; font-weight: 800; }
.task-item.checked .checkmark { display: block; }
.task-text { font-size: 0.88rem; line-height: 1.5; flex: 1; }
.task-item.checked .task-text { color: var(--text-muted); text-decoration: line-through; }

/* Toast */
#toast { position: fixed; bottom: 24px; right: 24px; background: var(--green); color: #000;
          font-weight: 600; font-size: 0.82rem; padding: 10px 18px; border-radius: 8px;
          opacity: 0; transform: translateY(8px); transition: all .2s; pointer-events: none; z-index: 9999; }
#toast.show { opacity: 1; transform: translateY(0); }
#toast.error { background: var(--p1); color: #fff; }

/* Footer nav */
.footer-nav { display: flex; justify-content: space-between; margin-top: 32px; flex-wrap: wrap; gap: 12px; }
.footer-nav a { display: flex; align-items: center; gap: 6px; padding: 12px 18px; background: var(--surface);
                 border: 1px solid var(--border); border-radius: var(--radius); color: var(--text-muted);
                 font-size: 0.85rem; transition: border-color .2s, color .2s; }
.footer-nav a:hover { border-color: var(--accent); color: var(--accent); text-decoration: none; }
.footer-nav a .nav-title { font-weight: 600; color: var(--text); font-size: 0.9rem; display: block; }
.footer-nav a .nav-label { font-size: 0.7rem; display: block; margin-bottom: 2px; }

@media (max-width: 600px) {
    .ms-info { grid-template-columns: 1fr; }
    .ms-num-title h1 { font-size: 1.2rem; }
}
</style>
</head>
<body>
<div class="container">

<!-- Top navigation -->
<nav class="topnav">
    <a href="index.php" class="back">← Dashboard</a>
    <div class="ms-nav">
        <?php if ($prev): ?>
            <a href="milestone.php?id=<?= $prev['id'] ?>">← #<?= $prev['id'] ?></a>
        <?php endif; ?>
        <?php if ($next): ?>
            <a href="milestone.php?id=<?= $next['id'] ?>">#<?= $next['id'] ?> →</a>
        <?php endif; ?>
    </div>
</nav>

<!-- Milestone Header -->
<div class="ms-header">
    <div class="ms-meta">
        <span class="tag tag-month"><?= htmlspecialchars($milestone['month']) ?></span>
        <span class="tag tag-weeks"><?= htmlspecialchars($milestone['weeks']) ?></span>
        <span class="tag tag-domain"><?= htmlspecialchars($milestone['domain']) ?></span>
        <span class="tag tag-<?= strtolower($milestone['priority']) ?>"><?= $pb['label'] ?></span>
    </div>

    <div class="ms-num-title" style="margin-top:14px">
        <span class="num">Milestone <?= $milestone['id'] ?></span>
        <h1><?= htmlspecialchars($milestone['title']) ?></h1>
    </div>

    <div class="ms-info">
        <div class="ms-info-block">
            <div class="label">Objective</div>
            <div class="value"><?= htmlspecialchars($milestone['objective']) ?></div>
        </div>
        <div class="ms-info-block">
            <div class="label">Key Deliverable</div>
            <div class="value deliverable"><?= htmlspecialchars($milestone['deliverable']) ?></div>
        </div>
    </div>
</div>

<!-- Progress Card -->
<div class="progress-section">
    <div class="progress-header">
        <h2>Checklist Progress</h2>
        <div class="progress-pct <?= $stats['pct'] === 100 ? 'done' : '' ?>" id="pct-display">
            <?= $stats['pct'] ?>%
        </div>
    </div>
    <div class="prog-bar">
        <div class="prog-fill <?= $stats['pct'] === 100 ? 'done' : '' ?>"
             id="prog-fill"
             style="width:<?= $stats['pct'] ?>%"></div>
    </div>
    <div class="prog-sub">
        <span id="tasks-done"><?= $stats['done'] ?>/<?= $stats['total'] ?> tasks completed</span>
        <span><?php if($stats['pct']===100): ?>🎉 Milestone Complete!</php elseif($stats['done']>0): ?><?= $stats['total']-$stats['done'] ?> left<?php else: ?>Not started<?php endif; ?></span>
    </div>
</div>

<!-- Bulk Actions -->
<div class="bulk-actions">
    <button class="btn primary" onclick="markAll(true)">✓ Mark All Done</button>
    <button class="btn danger"  onclick="markAll(false)">✗ Clear All</button>
</div>

<!-- Checklist -->
<?php if (empty($tasks)): ?>
<div style="background:var(--surface);border:1px solid var(--border);border-radius:var(--radius);padding:24px;text-align:center;color:var(--text-muted)">
    No checklist items found in this milestone.
</div>
<?php else: ?>
<div class="checklist" id="checklist">
    <?php foreach ($tasks as $i => $task): ?>
    <?php $checked = $task_states[$i]; ?>
    <label class="task-item <?= $checked ? 'checked' : '' ?>" data-index="<?= $i ?>">
        <input type="checkbox" <?= $checked ? 'checked' : '' ?> onchange="toggle(<?= $i ?>, this.checked)">
        <div class="task-checkbox">
            <span class="checkmark">✓</span>
        </div>
        <span class="task-text"><?= htmlspecialchars($task['text']) ?></span>
    </label>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<!-- Footer Navigation -->
<div class="footer-nav">
    <?php if ($prev): ?>
    <a href="milestone.php?id=<?= $prev['id'] ?>">
        <div>
            <span class="nav-label">← Previous</span>
            <span class="nav-title"><?= htmlspecialchars($prev['title']) ?></span>
        </div>
    </a>
    <?php else: ?><div></div><?php endif; ?>

    <?php if ($next): ?>
    <a href="milestone.php?id=<?= $next['id'] ?>" style="text-align:right">
        <div>
            <span class="nav-label">Next →</span>
            <span class="nav-title"><?= htmlspecialchars($next['title']) ?></span>
        </div>
    </a>
    <?php endif; ?>
</div>

</div><!-- /container -->

<div id="toast"></div>

<script>
const MS_ID    = '<?= $id ?>';
const TOTAL    = <?= count($tasks) ?>;

function showToast(msg, isError = false) {
    const t = document.getElementById('toast');
    t.textContent = msg;
    t.className = 'show' + (isError ? ' error' : '');
    clearTimeout(t._timer);
    t._timer = setTimeout(() => t.className = '', 2000);
}

function updateUI() {
    const items  = document.querySelectorAll('.task-item');
    let done = 0;
    items.forEach(el => { if (el.classList.contains('checked')) done++; });
    const pct = TOTAL > 0 ? Math.round(done / TOTAL * 100) : 0;

    document.getElementById('pct-display').textContent = pct + '%';
    document.getElementById('pct-display').className   = 'progress-pct' + (pct === 100 ? ' done' : '');
    document.getElementById('prog-fill').style.width   = pct + '%';
    document.getElementById('prog-fill').className     = 'prog-fill' + (pct === 100 ? ' done' : '');
    document.getElementById('tasks-done').textContent  = done + '/' + TOTAL + ' tasks completed';
}

function toggle(index, checked) {
    const label = document.querySelector(`.task-item[data-index="${index}"]`);
    if (!label) return;

    label.classList.toggle('checked', checked);
    const cb = label.querySelector('input[type=checkbox]');
    if (cb) cb.checked = checked;
    updateUI();

    fetch('save.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ milestone: MS_ID, index: index, checked: checked }),
    })
    .then(r => r.json())
    .then(d => {
        if (!d.ok) showToast('Save failed', true);
    })
    .catch(() => showToast('Network error', true));
}

function markAll(state) {
    const items = document.querySelectorAll('.task-item');
    items.forEach((el, i) => {
        const cb = el.querySelector('input[type=checkbox]');
        if (cb) cb.checked = state;
        el.classList.toggle('checked', state);
    });
    updateUI();

    // Build payload of all indices
    const indices = Array.from(items).map((_, i) => i);
    fetch('save.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ milestone: MS_ID, bulk: true, checked: state, total: TOTAL }),
    })
    .then(r => r.json())
    .then(d => showToast(state ? '✓ All marked done!' : 'Cleared all tasks'))
    .catch(() => showToast('Network error', true));
}

// Click on label toggles via the hidden checkbox
document.querySelectorAll('.task-item').forEach(label => {
    label.addEventListener('click', function(e) {
        if (e.target.tagName === 'INPUT') return; // let default handle it
        e.preventDefault();
        const cb = this.querySelector('input[type=checkbox]');
        cb.checked = !cb.checked;
        toggle(parseInt(this.dataset.index), cb.checked);
    });
});
</script>
</body>
</html>
