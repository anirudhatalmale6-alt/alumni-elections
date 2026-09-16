<?php $title = 'Results sealed'; ?>

<p class="faint"><a href="/elections/<?= (int)$e['id'] ?>">← <?= e($e['title']) ?></a></p>

<div class="card" style="text-align:center;padding:48px 24px">
  <div style="font-size:42px">🔒</div>
  <h1>Results are not public yet</h1>
  <p class="lede" style="margin:0 auto">
    <?php if ($phase === 'upcoming'): ?>
      Voting has not opened yet. It opens <?= e(fmt_dt($e['starts_at'], $e['timezone'])) ?>.
    <?php elseif ($phase === 'open'): ?>
      Counting stays sealed while voting is open, so early numbers cannot influence anybody still
      to vote. Voting closes <?= e(fmt_dt($e['ends_at'], $e['timezone'])) ?>.
    <?php else: ?>
      Voting has closed. The elections administrator will publish the result.
    <?php endif; ?>
  </p>
  <p style="margin-top:22px"><a class="btn btn-secondary" href="/elections/<?= (int)$e['id'] ?>">Back to the election</a></p>
</div>
