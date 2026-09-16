<?php $title = 'Vote recorded'; ?>

<div class="card" style="text-align:center;padding:48px 24px">
  <div style="font-size:44px">🗳️</div>
  <h1>Your vote is recorded</h1>
  <p class="lede" style="margin:0 auto 22px">
    Thank you for voting in <strong><?= e($e['title']) ?></strong>.
  </p>

  <div class="receipt"><?= e($receipt) ?></div>

  <p class="faint" style="max-width:56ch;margin:20px auto 0">
    Keep this receipt code. It is proof that your ballot was accepted and counted. It contains no
    record of who you voted for — the ballot itself is stored with no link back to your account.
  </p>

  <p style="margin-top:26px">
    <a class="btn btn-secondary" href="/elections/<?= (int)$e['id'] ?>">Back to the election</a>
    <a class="btn btn-secondary" href="/account">My voting record</a>
  </p>
</div>
