<?php
$title = 'Turnout — ' . $e['title'];
$peak  = 0;
foreach ($byHour as $h) { $peak = max($peak, (int)$h['n']); }
?>

<p class="faint"><a href="/admin">← Admin</a></p>

<div class="page-head">
  <span class="badge badge-<?= e($phase) ?>"><?= e(phase_label($phase)) ?></span>
  <h1 style="margin-top:8px">Turnout</h1>
  <p class="lede"><?= e($e['title']) ?></p>
</div>

<div class="grid grid-3" style="margin-bottom:26px">
  <div class="stat">
    <div class="stat-value"><?= (int)$turnout['voted'] ?></div>
    <div class="stat-label">Ballots submitted</div>
  </div>
  <div class="stat">
    <div class="stat-value"><?= (int)$turnout['eligible'] ?></div>
    <div class="stat-label">Eligible voters</div>
  </div>
  <div class="stat">
    <div class="stat-value"><?= e((string)$turnout['pct']) ?>%</div>
    <div class="stat-label">Turnout</div>
    <div class="bar"><span style="width:<?= e((string)min(100, (float)$turnout['pct'])) ?>%"></span></div>
  </div>
  <div class="stat">
    <div class="stat-value"><?= max(0, (int)$turnout['eligible'] - (int)$turnout['voted']) ?></div>
    <div class="stat-label">Yet to vote</div>
  </div>
</div>

<div class="alert alert-info">
  This page counts ballots. It cannot show who voted for whom — that link is not stored, so no
  administrator, including you, can produce it.
</div>

<div class="card">
  <h2>By position</h2>
  <?php if (!$perPosition): ?>
    <p class="faint">No positions on this ballot.</p>
  <?php else: ?>
    <div class="table-scroll">
      <table>
        <thead>
          <tr><th>Position</th><th class="num">Ballots</th><th class="num">Votes cast</th><th class="num">Abstained</th></tr>
        </thead>
        <tbody>
        <?php foreach ($perPosition as $p): ?>
          <tr>
            <td><?= e($p['position']['title']) ?></td>
            <td class="num"><?= (int)$p['submitted'] ?></td>
            <td class="num"><?= (int)$p['cast'] ?></td>
            <td class="num"><?= (int)$p['abstentions'] ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</div>

<div class="card">
  <h2>When people voted</h2>
  <?php if (!$byHour): ?>
    <p class="faint">Nobody has voted yet.</p>
  <?php else: ?>
    <div class="table-scroll">
      <table>
        <thead><tr><th>Hour (<?= e($e['timezone']) ?>)</th><th class="num">Voters</th><th style="width:45%"></th></tr></thead>
        <tbody>
        <?php foreach ($byHour as $h):
            // 'hour' comes back as 'YYYY-MM-DD HH' in UTC.
            $label = fmt_dt($h['hour'] . ':00:00', $e['timezone'], 'j M Y, H:00');
            $w = $peak > 0 ? round((int)$h['n'] * 100 / $peak) : 0;
        ?>
          <tr>
            <td class="faint"><?= e($label) ?></td>
            <td class="num"><?= (int)$h['n'] ?></td>
            <td><div class="bar"><span style="width:<?= (int)$w ?>%"></span></div></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</div>

<div class="card card-tight">
  <h3>Ballot integrity</h3>
  <?php if ($integrity['ok']): ?>
    <p class="muted" style="margin:0">
      <?= (int)$integrity['checked'] ?> ballots checked, hash chain intact.
    </p>
  <?php else: ?>
    <div class="alert alert-error" style="margin:0">
      Chain verification failed at ballot #<?= (int)$integrity['broken_at'] ?> after
      <?= (int)$integrity['checked'] ?> good ballots.
    </div>
  <?php endif; ?>
</div>
