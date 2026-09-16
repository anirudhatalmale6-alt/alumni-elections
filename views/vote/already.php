<?php $title = 'Already voted'; ?>

<div class="card" style="text-align:center;padding:44px 24px">
  <div style="font-size:40px">✅</div>
  <h1>You have already voted</h1>
  <p class="lede" style="margin:0 auto 20px">
    Your ballot for <strong><?= e($e['title']) ?></strong> was recorded on
    <?= e(fmt_dt($receipt['cast_at'], $e['timezone'])) ?>. Each alumnus votes once.
  </p>

  <div class="receipt"><?= e($receipt['receipt_code']) ?></div>

  <p style="margin-top:26px">
    <a class="btn btn-secondary" href="/elections/<?= (int)$e['id'] ?>">Back to the election</a>
  </p>
</div>
