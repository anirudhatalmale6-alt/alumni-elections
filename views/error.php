<?php $title = $title ?? 'Error'; ?>
<div class="card">
  <h1><?= e($title) ?></h1>
  <p class="lede"><?= e($message ?? 'Something went wrong.') ?></p>
  <?php if (!empty($detail)): ?>
    <pre class="mono" style="white-space:pre-wrap;background:var(--line-soft);padding:12px;border-radius:8px"><?= e($detail) ?></pre>
  <?php endif; ?>
  <p><a class="btn btn-secondary" href="/">Back to the elections</a></p>
</div>
