<?php $title = 'Check your email'; ?>

<div class="form-narrow">
  <div class="card" style="text-align:center;padding:40px 24px">
    <div style="font-size:40px">✉️</div>
    <h1>Check your email</h1>
    <p class="lede">
      We sent a confirmation link to <strong><?= e($user['email']) ?></strong>.
      Open it to activate your account.
    </p>
    <?php if ($mode === 'approval'): ?>
      <div class="alert alert-info" style="text-align:left">
        After you confirm the address, an administrator still has to approve the account before
        you can vote. You will get a second email when that happens.
      </div>
    <?php endif; ?>
    <p class="faint">The link does not expire. If it never arrives, check your spam folder.</p>
    <p><a class="btn btn-secondary" href="/login">Go to sign in</a></p>
  </div>
</div>
