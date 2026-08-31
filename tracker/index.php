<?php
require_once __DIR__ . '/config.php';
$progress = load_progress();

// Pre-compute stats for all milestones
$all_stats   = [];
$grand_done  = 0;
$grand_total = 0;

foreach ($MILESTONES as $m) {
    $tasks = parse_checklist(MILESTONES_DIR . $m['file']);
    $s     = milestone_stats($m['id'], $progress, $tasks);
    $all_stats[$m['id']] = $s;
    $grand_done  += $s['done'];
    $grand_total += $s['total'];
}
$grand_pct = $grand_total > 0 ? round($grand_done / $grand_total * 100) : 0;

// Count milestone-level completion
$ms_completed   = array_filter($all_stats, fn($s) => $s['pct'] === 100);
$ms_in_progress = array_filter($all_stats, fn($s) => $s['pct'] > 0 && $s['pct'] < 100);
$ms_not_started = array_filter($all_stats, fn($s) => $s['pct'] === 0);

// Group by month
$by_month = [];
foreach ($MILESTONES as $m) {
    $by_month[$m['month']][] = $m;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>FDE Roadmap — Progress Tracker</title>
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
}
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
body { font-family: system-ui, -apple-system, sans-serif; background: var(--bg); color: var(--text); min-height: 100vh; }

/* Layout */
.container { max-width: 1280px; margin: 0 auto; padding: 24px 20px; }

/* Header */
header { text-align: center; padding: 40px 0 32px; }
header h1 { font-size: 2rem; font-weight: 800; letter-spacing: -0.03em; }
header h1 span { color: var(--accent); }
header p  { color: var(--text-muted); margin-top: 8px; font-size: 0.95rem; }

/* Overall progress ring */
.hero { display: flex; align-items: center; justify-content: center; gap: 40px; flex-wrap: wrap;
        background: var(--surface); border: 1px solid var(--border); border-radius: var(--radius);
        padding: 32px; margin-bottom: 32px; }
.ring-wrap { position: relative; width: 140px; height: 140px; flex-shrink: 0; }
.ring-wrap svg { transform: rotate(-90deg); }
.ring-pct { position: absolute; inset: 0; display: flex; flex-direction: column; align-items: center; justify-content: center; }
.ring-pct strong { font-size: 2rem; font-weight: 800; color: var(--accent); line-height: 1; }
.ring-pct span   { font-size: 0.7rem; color: var(--text-muted); margin-top: 2px; }
.hero-stats { display: grid; grid-template-columns: repeat(3, 120px); gap: 16px; }
.stat-card  { background: var(--surface2); border-radius: 10px; padding: 16px 12px; text-align: center; border: 1px solid var(--border); }
.stat-card .num { font-size: 1.6rem; font-weight: 800; line-height: 1; }
.stat-card .lbl { font-size: 0.72rem; color: var(--text-muted); margin-top: 4px; text-transform: uppercase; letter-spacing: 0.05em; }
.num.green  { color: var(--green); }
.num.yellow { color: var(--accent); }
.num.gray   { color: var(--text-muted); }
.hero-detail { display: flex; flex-direction: column; gap: 8px; }
.hero-detail p  { color: var(--text-muted); font-size: 0.85rem; }
.hero-detail strong { color: var(--text); }

/* Section label */
.section-label { font-size: 0.75rem; text-transform: uppercase; letter-spacing: 0.1em; color: var(--text-muted);
                 margin-bottom: 12px; font-weight: 600; }

/* Month sections */
.month-section { margin-bottom: 32px; }
.month-header { display: flex; align-items: center; gap: 12px; margin-bottom: 14px; }
.month-dot { width: 10px; height: 10px; border-radius: 50%; flex-shrink: 0; }
.month-title { font-weight: 700; font-size: 1.05rem; }
.month-subtitle { color: var(--text-muted); font-size: 0.82rem; }
.month-progress { margin-left: auto; font-size: 0.8rem; color: var(--text-muted); white-space: nowrap; }

/* Milestone grid */
.milestone-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)); gap: 14px; }

/* Milestone card */
.ms-card { background: var(--surface); border: 1px solid var(--border); border-radius: var(--radius);
           padding: 18px; text-decoration: none; color: inherit; display: flex; flex-direction: column;
           gap: 10px; transition: border-color .2s, transform .15s, box-shadow .2s; position: relative; overflow: hidden; }
.ms-card:hover { border-color: var(--accent); transform: translateY(-2px); box-shadow: 0 8px 24px rgba(0,0,0,.4); }
.ms-card.done { border-color: rgba(34,197,94,.35); }
.ms-card.done::before { content:''; position:absolute; top:0; left:0; right:0; height:3px; background: var(--green); }
.ms-card.in-progress::before { content:''; position:absolute; top:0; left:0; right:0; height:3px; background: var(--accent); }

.ms-card-top { display: flex; align-items: flex-start; justify-content: space-between; gap: 8px; }
.ms-num   { font-size: 0.7rem; font-weight: 700; color: var(--text-muted); letter-spacing: 0.05em; }
.ms-badge { font-size: 0.65rem; font-weight: 700; padding: 2px 7px; border-radius: 4px; text-transform: uppercase; letter-spacing: 0.05em; }
.ms-badge.p1 { background: rgba(239,68,68,.15);  color: var(--p1); }
.ms-badge.p2 { background: rgba(249,115,22,.15); color: var(--p2); }
.ms-badge.p3 { background: rgba(234,179,8,.15);  color: var(--p3); }

.ms-title  { font-weight: 700; font-size: 0.95rem; line-height: 1.3; }
.ms-domain { font-size: 0.75rem; color: var(--text-muted); }
.ms-weeks  { font-size: 0.72rem; color: var(--text-muted); }

/* Mini progress bar */
.ms-prog { }
.ms-prog-bar { height: 4px; background: var(--border); border-radius: 2px; overflow: hidden; }
.ms-prog-fill { height: 100%; border-radius: 2px; transition: width .4s ease; }
.ms-prog-label { display: flex; justify-content: space-between; font-size: 0.7rem; color: var(--text-muted); margin-top: 4px; }

/* Status icon */
.ms-status { font-size: 1rem; }

/* Footer */
footer { text-align: center; padding: 32px 0; color: var(--text-muted); font-size: 0.78rem; }
footer a { color: var(--accent); text-decoration: none; }

/* Responsive */
@media (max-width: 600px) {
    header h1 { font-size: 1.5rem; }
    .hero { flex-direction: column; gap: 20px; }
    .hero-stats { grid-template-columns: repeat(3, 90px); }
}
</style>
</head>
<body>
<div class="container">

<header>
    <h1>SWE → <span>FDE</span> Roadmap Tracker</h1>
    <p>6-Month Execution Plan · 24 Milestones · <?= $grand_total ?> Tasks</p>
</header>

<!-- Overall Progress Hero -->
<div class="hero">
    <!-- SVG Ring -->
    <div class="ring-wrap">
        <?php
        $r   = 60;
        $circ= 2 * M_PI * $r;
        $dash= $circ * $grand_pct / 100;
        ?>
        <svg width="140" height="140" viewBox="0 0 140 140">
            <circle cx="70" cy="70" r="<?=$r?>" fill="none" stroke="#334155" stroke-width="10"/>
            <circle cx="70" cy="70" r="<?=$r?>" fill="none" stroke="#38bdf8" stroke-width="10"
                    stroke-dasharray="<?=round($dash,2)?> <?=round($circ,2)?>"
                    stroke-linecap="round"/>
        </svg>
        <div class="ring-pct">
            <strong><?=$grand_pct?>%</strong>
            <span>Overall</span>
        </div>
    </div>

    <div class="hero-stats">
        <div class="stat-card">
            <div class="num green"><?=count($ms_completed)?></div>
            <div class="lbl">Completed</div>
        </div>
        <div class="stat-card">
            <div class="num yellow"><?=count($ms_in_progress)?></div>
            <div class="lbl">In Progress</div>
        </div>
        <div class="stat-card">
            <div class="num gray"><?=count($ms_not_started)?></div>
            <div class="lbl">Not Started</div>
        </div>
        <div class="stat-card">
            <div class="num" style="color:var(--green)"><?=$grand_done?></div>
            <div class="lbl">Tasks Done</div>
        </div>
        <div class="stat-card">
            <div class="num gray"><?=$grand_total - $grand_done?></div>
            <div class="lbl">Tasks Left</div>
        </div>
        <div class="stat-card">
            <div class="num" style="color:var(--accent)"><?=$grand_total?></div>
            <div class="lbl">Total Tasks</div>
        </div>
    </div>
</div>

<!-- Month-by-Month Breakdown -->
<?php foreach ($by_month as $month_key => $milestones): ?>
<?php
$mc       = month_config($month_key);
$m_done   = array_sum(array_map(fn($m) => $all_stats[$m['id']]['done'],  $milestones));
$m_total  = array_sum(array_map(fn($m) => $all_stats[$m['id']]['total'], $milestones));
$m_pct    = $m_total > 0 ? round($m_done / $m_total * 100) : 0;
?>
<div class="month-section">
    <div class="month-header">
        <div class="month-dot" style="background:<?= $mc['color'] ?>"></div>
        <div>
            <div class="month-title" style="color:<?= $mc['color'] ?>"><?= $mc['label'] ?></div>
            <div class="month-subtitle"><?= $mc['subtitle'] ?></div>
        </div>
        <div class="month-progress"><?= $m_done ?>/<?= $m_total ?> tasks &nbsp;·&nbsp; <?= $m_pct ?>%</div>
    </div>

    <div class="milestone-grid">
        <?php foreach ($milestones as $m): ?>
        <?php
        $s    = $all_stats[$m['id']];
        $pb   = priority_badge($m['priority']);
        $cls  = $s['pct'] === 100 ? 'done' : ($s['pct'] > 0 ? 'in-progress' : '');
        $icon = $s['pct'] === 100 ? '✅' : ($s['pct'] > 0 ? '⏳' : '○');
        $fill = $s['pct'] === 100 ? 'var(--green)' : ($s['pct'] > 0 ? 'var(--accent)' : 'transparent');
        ?>
        <a href="milestone.php?id=<?= $m['id'] ?>" class="ms-card <?= $cls ?>">
            <div class="ms-card-top">
                <span class="ms-num">#<?= $m['id'] ?> · <?= htmlspecialchars($m['weeks']) ?></span>
                <span class="ms-badge <?= $pb['class'] ?>"><?= $pb['label'] ?></span>
            </div>
            <div style="display:flex;align-items:flex-start;gap:8px">
                <div class="ms-status"><?= $icon ?></div>
                <div>
                    <div class="ms-title"><?= htmlspecialchars($m['title']) ?></div>
                    <div class="ms-domain"><?= htmlspecialchars($m['domain']) ?></div>
                </div>
            </div>
            <div class="ms-prog">
                <div class="ms-prog-bar">
                    <div class="ms-prog-fill" style="width:<?=$s['pct']?>%;background:<?=$fill?>"></div>
                </div>
                <div class="ms-prog-label">
                    <span><?=$s['done']?>/<?=$s['total']?> tasks</span>
                    <span><?=$s['pct']?>%</span>
                </div>
            </div>
        </a>
        <?php endforeach; ?>
    </div>
</div>
<?php endforeach; ?>

<footer>
    <p>Progress saved locally · <a href="reset.php" onclick="return confirm('Reset ALL progress? This cannot be undone.')">Reset all progress</a></p>
</footer>

</div>
</body>
</html>
