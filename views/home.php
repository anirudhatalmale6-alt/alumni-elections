<?php $title = 'Elections'; ?>

<div class="page-head">
  <h1>Alumni elections</h1>
  <p class="lede">
    Every registered alumnus gets one vote per position. Ballots are recorded separately from the
    list of who has voted, so results can be published without exposing anybody's choice.
  </p>
</div>

<?php if (!$user): ?>
  <div class="alert alert-info">
    <a href="/login">Sign in</a> to vote, or <a href="/register">register</a> with your alumni email address.
  </div>
<?php endif; ?>

<?php if (!$cards): ?>
  <div class="empty">
    <p><strong>No elections have been set up yet.</strong></p>
    <p class="faint">When the elections administrator opens one, it will appear here.</p>
  </div>
<?php endif; ?>

<div class="grid grid-2">
<?php foreach ($cards as $c):
    $e = $c['e'];
    $phase = $c['phase'];
?>
  <div class="card">
    <div class="btn-row" style="justify-content:space-between;margin-bottom:8px">
      <span class="badge badge-<?= e($phase) ?>"><?= e(phase_label($phase)) ?></span>
      <?php if ($c['voted']): ?><span class="badge badge-voted">You have voted</span><?php endif; ?>
    </div>

    <h2><a href="/elections/<?= (int)$e['id'] ?>" style="text-decoration:none"><?= e($e['title']) ?></a></h2>

    <?php if ($e['description']): ?>
      <p class="muted"><?= e(mb_strimwidth($e['description'], 0, 170, '…', 'UTF-8')) ?></p>
    <?php endif; ?>

    <table style="margin:12px 0">
      <tr><th style="width:90px">Opens</th><td><?= e(fmt_dt($e['starts_at'], $e['timezone'])) ?></td></tr>
      <tr><th>Closes</th><td><?= e(fmt_dt($e['ends_at'], $e['timezone'])) ?></td></tr>
      <tr><th>Positions</th><td><?= (int)$c['positions'] ?></td></tr>
    </table>

    <div class="btn-row">
      <a class="btn btn-secondary btn-sm" href="/elections/<?= (int)$e['id'] ?>">Candidates</a>
      <?php if ($phase === 'open' && !$c['voted'] && can_vote()): ?>
        <a class="btn btn-sm" href="/elections/<?= (int)$e['id'] ?>/vote">Vote now</a>
      <?php endif; ?>
      <?php if ($c['results_ok']): ?>
        <a class="btn btn-secondary btn-sm" href="/elections/<?= (int)$e['id'] ?>/results">Results</a>
      <?php endif; ?>
    </div>
  </div>
<?php endforeach; ?>
</div>
