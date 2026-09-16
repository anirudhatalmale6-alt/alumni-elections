<?php $title = 'Set a new password'; ?>

<div class="form-narrow">
  <h1>Set a new password</h1>

  <?php if ($error): ?>
    <div class="alert alert-error"><?= e($error) ?></div>
  <?php endif; ?>

  <?php if (!$valid): ?>
    <div class="card">
      <p class="lede">This reset link is not valid any more. They expire after an hour and can only be used once.</p>
      <a class="btn" href="/forgot">Request a new link</a>
    </div>
  <?php else: ?>
    <div class="card">
      <form method="post" action="/reset">
        <?= csrf_field() ?>
        <input type="hidden" name="token" value="<?= e($token) ?>">

        <div class="field">
          <label for="password">New password</label>
          <input id="password" name="password" type="password" required autocomplete="new-password" autofocus>
          <div class="hint">At least 8 characters.</div>
        </div>

        <div class="field">
          <label for="password_confirm">Repeat it</label>
          <input id="password_confirm" name="password_confirm" type="password" required autocomplete="new-password">
        </div>

        <button class="btn btn-lg" type="submit">Save the new password</button>
      </form>
    </div>
  <?php endif; ?>
</div>
