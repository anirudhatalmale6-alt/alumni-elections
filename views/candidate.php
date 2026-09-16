<?php $title = $c['full_name']; ?>

<p class="faint"><a href="/elections/<?= (int)$c['election_id'] ?>">← <?= e($c['election_title']) ?></a></p>

<div class="card">
  <div style="display:flex;gap:24px;flex-wrap:wrap;align-items:flex-start">
    <?php $url = candidate_photo_url($c['photo_path']); ?>
    <?php if ($url): ?>
      <img src="<?= e($url) ?>" alt="<?= e($c['full_name']) ?>"
           style="width:190px;height:190px;object-fit:cover;border-radius:12px;flex:none">
    <?php else: ?>
      <div class="cand-photo-empty" style="width:190px;height:190px;border-radius:12px;flex:none;font-size:56px">
        <?= e(mb_substr($c['full_name'], 0, 1, 'UTF-8')) ?>
      </div>
    <?php endif; ?>

    <div style="flex:1;min-width:260px">
      <span class="badge">Standing for <?= e($c['position_title']) ?></span>
      <h1 style="margin-top:10px"><?= e($c['full_name']) ?></h1>
      <?php if ($c['headline']): ?>
        <p class="lede"><?= e($c['headline']) ?></p>
      <?php endif; ?>
    </div>
  </div>

  <?php if (trim((string)$c['bio']) !== ''): ?>
    <hr class="sep">
    <h2>Manifesto</h2>
    <div style="max-width:70ch"><?= nl2br(e($c['bio'])) ?></div>
  <?php endif; ?>
</div>
