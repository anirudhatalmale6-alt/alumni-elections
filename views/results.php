<?php $title = 'Results — ' . $e['title']; ?>

<div class="page-head">
  <p class="faint"><a href="/elections/<?= (int)$e['id'] ?>">← <?= e($e['title']) ?></a></p>
  <div class="btn-row" style="margin-bottom:8px">
    <span class="badge badge-<?= e($phase) ?>"><?= e(phase_label($phase)) ?></span>
    <?php if ($phase === 'open'): ?>
      <span class="badge badge-upcoming">Provisional — voting still open</span>
    <?php endif; ?>
  </div>
  <h1>Results</h1>
  <p class="lede"><?= e($e['title']) ?></p>
</div>

<?php if ($phase === 'open'): ?>
  <div class="alert alert-warn">
    Voting is still open until <?= e(fmt_dt($e['ends_at'], $e['timezone'])) ?>.
    These numbers are provisional and will keep moving.
  </div>
<?php endif; ?>

<div class="grid grid-3" style="margin-bottom:24px">
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
</div>

<?php foreach ($sections as $s):
    $p = $s['position'];
    $t = $s['tally'];
?>
  <div class="card">
    <div class="btn-row" style="justify-content:space-between">
      <h2 style="margin:0"><?= e($p['title']) ?></h2>
      <span class="faint"><?= (int)$t['votes'] ?> vote<?= $t['votes'] === 1 ? '' : 's' ?>
        <?php if ($t['abstentions'] > 0): ?>· <?= (int)$t['abstentions'] ?> abstained<?php endif; ?>
      </span>
    </div>

    <?php if (!$t['rows']): ?>
      <p class="faint">No candidates stood for this position.</p>
    <?php else: ?>
      <?php if ($t['votes'] > 0 && $phase === 'closed'): ?>
        <p class="muted" style="margin-top:8px">
          <?php if ($t['tied']): ?>
            <strong>Tied:</strong> <?= e(implode(' and ', $t['leaders'])) ?>
            — <?= (int)$t['rows'][0]['votes'] ?> votes each.
          <?php else: ?>
            <strong>Elected:</strong> <?= e($t['leaders'][0]) ?>
            with <?= (int)$t['rows'][0]['votes'] ?> of <?= (int)$t['votes'] ?> votes.
          <?php endif; ?>
        </p>
      <?php endif; ?>

      <div style="margin-top:10px">
      <?php foreach ($t['rows'] as $i => $r): ?>
        <div class="result-row">
          <?php $url = candidate_photo_url($r['photo_path']); ?>
          <?php if ($url): ?>
            <img class="result-photo" src="<?= e($url) ?>" alt="">
          <?php else: ?>
            <div class="result-photo-empty"><?= e(mb_substr($r['full_name'], 0, 1, 'UTF-8')) ?></div>
          <?php endif; ?>

          <div class="result-main">
            <div><strong><?= e($r['full_name']) ?></strong>
              <?php if ($i === 0 && $t['votes'] > 0 && !$t['tied']): ?>
                <span class="badge badge-open" style="margin-left:6px">Leading</span>
              <?php endif; ?>
            </div>
            <div class="bar <?= ($i === 0 && $t['votes'] > 0) ? 'bar-win' : '' ?>">
              <span style="width:<?= e((string)$r['pct']) ?>%"></span>
            </div>
          </div>

          <div class="result-count"><?= (int)$r['votes'] ?>
            <span class="faint" style="font-weight:400">(<?= e((string)$r['pct']) ?>%)</span>
          </div>
        </div>
      <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>
<?php endforeach; ?>

<div class="card card-tight">
  <h3>Ballot integrity</h3>
  <?php if ($integrity['ok']): ?>
    <p class="muted" style="margin:0">
      All <strong><?= (int)$integrity['checked'] ?></strong> recorded ballots verify against the hash
      chain. Each ballot is hashed together with the one before it, so editing, inserting or removing a
      ballot directly in the database breaks every hash after it and is detected here.
    </p>
  <?php else: ?>
    <div class="alert alert-error" style="margin:0">
      <strong>Chain verification failed at ballot #<?= (int)$integrity['broken_at'] ?>.</strong>
      <?= (int)$integrity['checked'] ?> ballots verified before that point. These results should not be
      published until the cause is established.
    </div>
  <?php endif; ?>
</div>
