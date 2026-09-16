<?php $title = 'Sign in'; ?>

<div class="form-narrow">
  <h1>Sign in</h1>
  <p class="lede">Use the email address you registered with.</p>

  <?php if ($error): ?>
    <div class="alert alert-error"><?= e($error) ?></div>
  <?php endif; ?>

  <div class="card">
    <form method="post" action="/login" novalidate>
      <?= csrf_field() ?>

      <div class="field">
        <label for="email">Email address</label>
        <input id="email" name="email" type="email" value="<?= e(old('email')) ?>" required autocomplete="email" autofocus>
      </div>

      <div class="field">
        <label for="password">Password</label>
        <input id="password" name="password" type="password" required autocomplete="current-password">
      </div>

      <button class="btn btn-lg" type="submit">Sign in</button>
    </form>
  </div>

  <p class="faint">
    <a href="/forgot">Forgotten your password?</a> · No account yet? <a href="/register">Register</a>.
  </p>
</div>
