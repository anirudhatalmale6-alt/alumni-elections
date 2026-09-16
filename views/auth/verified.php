<?php $title = $ok ? 'Email confirmed' : 'Link not valid'; ?>

<div class="form-narrow">
  <div class="card" style="text-align:center;padding:40px 24px">
    <?php if ($ok): ?>
      <div style="font-size:40px">✅</div>
      <h1>Email confirmed</h1>
      <?php if ($user['status'] === 'active'): ?>
        <p class="lede"><?= e($user['email']) ?> is confirmed and your account is active. You can sign in and vote.</p>
      <?php else: ?>
        <p class="lede">
          <?= e($user['email']) ?> is confirmed. An administrator now has to approve the account
          before you can vote — you will get an email when that is done.
        </p>
      <?php endif; ?>
      <p><a class="btn btn-lg" href="/login">Sign in</a></p>
    <?php else: ?>
      <div style="font-size:40px">⚠️</div>
      <h1>That link is not valid</h1>
      <p class="lede">
        The confirmation link has already been used, or it was not copied in full.
        If your account is already confirmed, just sign in.
      </p>
      <p><a class="btn btn-secondary" href="/login">Sign in</a></p>
    <?php endif; ?>
  </div>
</div>
