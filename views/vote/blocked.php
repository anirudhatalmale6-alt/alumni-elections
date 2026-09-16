<?php $title = 'Cannot vote'; ?>

<div class="card" style="text-align:center;padding:44px 24px">
  <div style="font-size:40px">🚫</div>
  <h1>This ballot is not open to you</h1>
  <p class="lede" style="margin:0 auto"><?= e($reason) ?></p>
  <p style="margin-top:26px">
    <a class="btn btn-secondary" href="/elections/<?= (int)$e['id'] ?>">Back to the election</a>
    <a class="btn btn-secondary" href="/">All elections</a>
  </p>
</div>
