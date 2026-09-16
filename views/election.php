<?php $title = $e['title']; ?>

<div class="page-head">
  <p class="faint"><a href="/">← All elections</a></p>
  <div class="btn-row" style="margin-bottom:8px">
    <span class="badge badge-<?= e($phase) ?>"><?= e(phase_label($phase)) ?></span>
    <?php if ($receipts): ?><span class="badge badge-voted">You have voted</span><?php endif; ?>
  </div>
  <h1><?= e($e['title']) ?></h1>
  <?php if ($e['description']): ?>
    <p class="lede"><?= nl2br(e($e['description'])) ?></p>
  <?php endif; ?>
</div>

<div class="card card-tight">
  <div class="grid grid-3">
    <div>
      <div class="stat-label">Voting opens</div>
      <div><?= e(fmt_dt($e['starts_at'], $e['timezone'])) ?></div>
    </div>
    <div>
      <div class="stat-label">Voting closes</div>
      <div><?= e(fmt_dt($e['ends_at'], $e['timezone'])) ?></div>
    </div>
    <div>
      <div class="stat-label">Results</div>
      <div><?php
        echo match ($e['results_mode']) {
            'live'        => 'Published live during voting',
            'after_close' => 'Published when voting closes',
            default       => 'Published by the administrator',
        };
      ?></div>
    </div>
  </div>
</div>

<?php if ($turnout !== null): ?>
  <div class="alert alert-info">
    Oversight view — turnout so far: <strong><?= (int)$turnout['voted'] ?></strong> of
    <strong><?= (int)$turnout['eligible'] ?></strong> eligible voters
    (<?= e((string)$turnout['pct']) ?>%).
    <a href="/admin/elections/<?= (int)$e['id'] ?>/turnout">Full turnout breakdown →</a>
  </div>
<?php endif; ?>

<?php if ($phase === 'open' && $user && can_vote($user) && !$receipts): ?>
  <div class="card" style="border-color:var(--navy);background:var(--navy-tint)">
    <h2>Voting is open</h2>
    <p class="muted">You have one vote per position. Once you submit, your ballot is final.</p>
    <a class="btn btn-lg" href="/elections/<?= (int)$e['id'] ?>/vote">Go to the ballot</a>
  </div>
<?php elseif ($phase === 'open' && !$user): ?>
  <div class="alert alert-info">Voting is open. <a href="/login">Sign in</a> to cast your ballot.</div>
<?php elseif ($phase === 'upcoming'): ?>
  <div class="alert alert-warn">
    Voting opens <?= e(fmt_dt($e['starts_at'], $e['timezone'])) ?>. You can read the candidate profiles now.
  </div>
<?php elseif ($phase === 'closed'): ?>
  <div class="alert">
    Voting closed <?= e(fmt_dt($e['ends_at'], $e['timezone'])) ?>.
    <?php if ($results_ok): ?>
      <a href="/elections/<?= (int)$e['id'] ?>/results">See the results →</a>
    <?php else: ?>
      Results will be published by the elections administrator.
    <?php endif; ?>
  </div>
<?php endif; ?>

<?php if ($receipts): ?>
  <div class="alert alert-success">
    Your ballot was recorded. Receipt
    <span class="mono"><strong><?= e(reset($receipts)['receipt_code']) ?></strong></span>.
    It confirms that you voted; it does not record who you voted for.
  </div>
<?php endif; ?>

<?php if (!$structure): ?>
  <div class="empty"><p>No positions have been added to this election yet.</p></div>
<?php endif; ?>

<?php foreach ($structure as $pos): ?>
  <div class="section">
    <h2><?= e($pos['title']) ?></h2>
    <?php if ($pos['description']): ?><p class="muted"><?= e($pos['description']) ?></p><?php endif; ?>

    <?php if (!$pos['candidates']): ?>
      <div class="empty"><p class="faint">No candidates listed for this position yet.</p></div>
    <?php else: ?>
      <div class="cand-grid">
        <?php foreach ($pos['candidates'] as $c): ?>
          <a class="cand" href="/candidates/<?= (int)$c['id'] ?>" style="text-decoration:none;color:inherit">
            <?php $url = candidate_photo_url($c['photo_path']); ?>
            <?php if ($url): ?>
              <img class="cand-photo" src="<?= e($url) ?>" alt="<?= e($c['full_name']) ?>">
            <?php else: ?>
              <div class="cand-photo-empty"><?= e(mb_substr($c['full_name'], 0, 1, 'UTF-8')) ?></div>
            <?php endif; ?>
            <div class="cand-body">
              <h3><?= e($c['full_name']) ?></h3>
              <?php if ($c['headline']): ?>
                <p class="cand-headline"><?= e($c['headline']) ?></p>
              <?php endif; ?>
            </div>
          </a>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>
<?php endforeach; ?>

<?php if ($results_ok && $structure): ?>
  <p style="margin-top:28px">
    <a class="btn btn-secondary" href="/elections/<?= (int)$e['id'] ?>/results">View results</a>
  </p>
<?php endif; ?>
