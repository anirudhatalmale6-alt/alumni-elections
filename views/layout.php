<?php
/** @var string $content */
$__user  = current_user();
$__flash = take_flashes();
$__title = $title ?? ($pageTitle ?? config('site_name'));
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?= e($__title) ?> · <?= e(config('site_name')) ?></title>
<link rel="stylesheet" href="/assets/app.css">
</head>
<body>

<header class="topbar">
  <div class="wrap topbar-inner">
    <a class="brand" href="/">
      <span class="brand-mark" aria-hidden="true">✓</span>
      <span><?= e(config('site_name')) ?></span>
    </a>

    <nav class="nav">
      <a href="/">Elections</a>
      <?php if ($__user): ?>
        <?php if ($__user['role'] === 'voter'): ?>
          <a href="/account">My votes</a>
        <?php endif; ?>
        <?php if (in_array($__user['role'], ['admin', 'auditor'], true)): ?>
          <a href="/admin"><?= $__user['role'] === 'admin' ? 'Admin' : 'Oversight' ?></a>
        <?php endif; ?>
        <span class="who">
          <span class="role role-<?= e($__user['role']) ?>"><?= e($__user['role']) ?></span>
          <?= e($__user['email']) ?>
        </span>
        <form method="post" action="/logout" class="inline">
          <?= csrf_field() ?>
          <button class="btn btn-ghost btn-sm" type="submit">Sign out</button>
        </form>
      <?php else: ?>
        <a href="/login">Sign in</a>
        <a class="btn btn-sm" href="/register">Register</a>
      <?php endif; ?>
    </nav>
  </div>
</header>

<main class="wrap main">
  <?php foreach ($__flash as $f): ?>
    <div class="alert alert-<?= e($f['type']) ?>"><?= e($f['message']) ?></div>
  <?php endforeach; ?>

  <?= $content ?>
</main>

<footer class="footer">
  <div class="wrap">
    <p><?= e(config('site_name')) ?> — one alumnus, one vote. Ballots are stored separately from the
       record of who voted, so a published result cannot be traced back to any individual.</p>
  </div>
</footer>

</body>
</html>
