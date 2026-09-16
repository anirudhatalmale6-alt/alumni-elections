<?php $title = 'Ballot — ' . $e['title']; ?>

<div class="page-head">
  <p class="faint"><a href="/elections/<?= (int)$e['id'] ?>">← <?= e($e['title']) ?></a></p>
  <h1>Your ballot</h1>
  <p class="lede">
    One choice per position. When you submit, your ballot is final — you cannot come back and change it,
    and positions you leave blank are recorded as abstentions.
  </p>
</div>

<div class="alert alert-warn">
  Voting closes <strong><?= e(fmt_dt($e['ends_at'], $e['timezone'])) ?></strong>.
</div>

<form method="post" action="/elections/<?= (int)$e['id'] ?>/vote" id="ballot-form">
  <?= csrf_field() ?>

  <?php foreach ($structure as $pos): ?>
    <div class="card">
      <h2><?= e($pos['title']) ?></h2>
      <?php if ($pos['description']): ?>
        <p class="muted"><?= e($pos['description']) ?></p>
      <?php endif; ?>

      <?php if (!$pos['candidates']): ?>
        <p class="faint">No candidates stood for this position.</p>
      <?php else: ?>
        <div style="margin-top:14px">
        <?php foreach ($pos['candidates'] as $c):
            $rid = 'p' . (int)$pos['id'] . 'c' . (int)$c['id'];
        ?>
          <div class="option">
            <input type="radio" id="<?= e($rid) ?>"
                   name="position[<?= (int)$pos['id'] ?>]" value="<?= (int)$c['id'] ?>">
            <div class="option-body">
              <?php /* The label covers the photo and the name, but deliberately not the
                        profile link: inside a label, clicking the link would also change
                        the selection, which must never happen by accident on a ballot. */ ?>
              <label class="option-main" for="<?= e($rid) ?>">
                <?php $url = candidate_photo_url($c['photo_path']); ?>
                <?php if ($url): ?>
                  <img class="option-photo" src="<?= e($url) ?>" alt="">
                <?php else: ?>
                  <div class="option-photo-empty"><?= e(mb_substr($c['full_name'], 0, 1, 'UTF-8')) ?></div>
                <?php endif; ?>
                <span class="option-text">
                  <strong><?= e($c['full_name']) ?></strong>
                  <?php if ($c['headline']): ?>
                    <span class="muted"><?= e($c['headline']) ?></span>
                  <?php endif; ?>
                </span>
              </label>
              <a class="option-link" href="/candidates/<?= (int)$c['id'] ?>"
                 target="_blank" rel="noopener">Read the full profile →</a>
            </div>
          </div>
        <?php endforeach; ?>

          <div class="option option-abstain">
            <input type="radio" id="p<?= (int)$pos['id'] ?>abstain"
                   name="position[<?= (int)$pos['id'] ?>]" value="0" checked>
            <div class="option-body">
              <label class="option-main" for="p<?= (int)$pos['id'] ?>abstain">
                <span class="option-text">
                  <strong>Abstain</strong>
                  <span class="muted">Record no preference for <?= e($pos['title']) ?>.</span>
                </span>
              </label>
            </div>
          </div>
        </div>
      <?php endif; ?>
    </div>
  <?php endforeach; ?>

  <div class="card" style="border-color:var(--navy)">
    <h2>Submit your ballot</h2>
    <p class="muted">
      Check your selections above. Once submitted, the vote is recorded and cannot be changed or withdrawn.
    </p>
    <button class="btn btn-lg" type="submit" id="submit-ballot">Cast my vote</button>
  </div>
</form>

<script>
// Guard against an accidental double submit producing a second request. The
// real protection is the unique constraint in the database; this only saves the
// voter from seeing a confusing error after a double click.
document.getElementById('ballot-form').addEventListener('submit', function (ev) {
  var btn = document.getElementById('submit-ballot');
  if (btn.dataset.sent === '1') { ev.preventDefault(); return; }
  if (!window.confirm('Submit your ballot? This cannot be undone.')) { ev.preventDefault(); return; }
  btn.dataset.sent = '1';
  btn.textContent = 'Recording your vote…';
  // The submission is already under way at this point, so disabling the button
  // here blocks a second click without cancelling the first request.
  btn.disabled = true;
});
</script>
