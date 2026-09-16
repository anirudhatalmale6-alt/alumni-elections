<?php $title = 'Reset your password'; ?>

<div class="form-narrow">
  <h1>Reset your password</h1>

  <?php if ($sent): ?>
    <div class="card" style="text-align:center;padding:36px 24px">
      <div style="font-size:38px">✉️</div>
      <h2>Check your email</h2>
      <p class="lede">
        If that address has an account, a reset link is on its way. The link works for one hour.
      </p>
      <p class="faint">
        We show this message whether or not the address is registered — otherwise this form could be
        used to find out who holds an account.
      </p>
      <p><a class="btn btn-secondary" href="/login">Back to sign in</a></p>
    </div>
  <?php else: ?>
    <p class="lede">Enter your email address and we will send you a link to set a new password.</p>
    <div class="card">
      <form method="post" action="/forgot">
        <?= csrf_field() ?>
        <div class="field">
          <label for="email">Email address</label>
          <input id="email" name="email" type="email" required autocomplete="email" autofocus>
        </div>
        <button class="btn btn-lg" type="submit">Send the link</button>
      </form>
    </div>
    <p class="faint"><a href="/login">Back to sign in</a></p>
  <?php endif; ?>
</div>
