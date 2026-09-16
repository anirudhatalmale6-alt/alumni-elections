<?php $title = 'Register'; ?>

<div class="form-narrow">
  <h1>Register</h1>
  <p class="lede">
    <?php if ($mode === 'roll'): ?>
      Sign up with the email address held on the alumni roll. If your address is not on it,
      the elections administrator has to add you first.
    <?php elseif ($mode === 'approval'): ?>
      Sign up with your alumni email address. An administrator checks each account before it can vote.
    <?php else: ?>
      Sign up with your email address. Email only — no social logins.
    <?php endif; ?>
  </p>

  <div class="card">
    <form method="post" action="/register" novalidate>
      <?= csrf_field() ?>

      <div class="field <?= isset($errors['full_name']) ? 'field-error' : '' ?>">
        <label for="full_name">Full name</label>
        <input id="full_name" name="full_name" type="text" value="<?= e(old('full_name')) ?>" required>
        <?php if (isset($errors['full_name'])): ?><div class="err"><?= e($errors['full_name']) ?></div><?php endif; ?>
      </div>

      <div class="field">
        <label for="grad_year">Graduation year <span class="faint">(optional)</span></label>
        <input id="grad_year" name="grad_year" type="text" value="<?= e(old('grad_year')) ?>" placeholder="e.g. 2011">
      </div>

      <div class="field <?= isset($errors['email']) ? 'field-error' : '' ?>">
        <label for="email">Email address</label>
        <input id="email" name="email" type="email" value="<?= e(old('email')) ?>" required autocomplete="email">
        <?php if (isset($errors['email'])): ?><div class="err"><?= e($errors['email']) ?></div><?php endif; ?>
      </div>

      <div class="field <?= isset($errors['password']) ? 'field-error' : '' ?>">
        <label for="password">Password</label>
        <input id="password" name="password" type="password" required autocomplete="new-password">
        <div class="hint">At least 8 characters.</div>
        <?php if (isset($errors['password'])): ?><div class="err"><?= e($errors['password']) ?></div><?php endif; ?>
      </div>

      <button class="btn btn-lg" type="submit">Create my account</button>
    </form>
  </div>

  <p class="faint">Already registered? <a href="/login">Sign in</a>.</p>
</div>
