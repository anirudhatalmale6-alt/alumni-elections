<?php
$title  = 'Manage — ' . $e['title'];
$tzList = DateTimeZone::listIdentifiers();
$cur    = $e['timezone'];
$locked = ballot_is_locked($e);
?>

<p class="faint"><a href="/admin">← Admin</a></p>

<div class="page-head">
  <div class="btn-row" style="margin-bottom:8px">
    <span class="badge badge-<?= e($phase) ?>"><?= e(phase_label($phase)) ?></span>
    <?php if ($e['results_published_at']): ?>
      <span class="badge badge-open">Results published</span>
    <?php endif; ?>
    <?php if ((int)$e['is_archived']): ?><span class="badge">Archived</span><?php endif; ?>
  </div>
  <h1><?= e($e['title']) ?></h1>
  <p class="lede">
    <?= (int)$turnout['voted'] ?> of <?= (int)$turnout['eligible'] ?> eligible voters have submitted
    a ballot (<?= e((string)$turnout['pct']) ?>%).
    <a href="/admin/elections/<?= (int)$e['id'] ?>/turnout">Turnout detail →</a>
  </p>
</div>

<?php if ($locked): ?>
  <div class="alert alert-warn">
    <strong>The ballot paper is locked.</strong>
    Ballots have already been cast, so positions and candidates can no longer be added or removed.
    Changing the paper underneath people who have already voted would make the result impossible to
    defend. Candidate wording and photos can still be corrected below.
  </div>
<?php elseif ($phase === 'open'): ?>
  <div class="alert alert-warn">
    <strong>Voting is already open.</strong>
    Nobody has voted yet, so you can still change the ballot — but any alumnus could vote at this
    moment, and the paper locks permanently as soon as the first ballot arrives. If you are still
    setting this election up, push the opening time back until you are ready.
  </div>
<?php elseif (!$structure): ?>
  <div class="alert alert-info">
    This election has no positions yet, so there is nothing to vote on. Add the positions and their
    candidates below before voting opens.
  </div>
<?php endif; ?>

<div class="btn-row" style="margin-bottom:22px">
  <a class="btn btn-secondary btn-sm" href="/elections/<?= (int)$e['id'] ?>">View as a voter</a>
  <a class="btn btn-secondary btn-sm" href="/elections/<?= (int)$e['id'] ?>/results">Results page</a>

  <form method="post" action="/admin/elections/<?= (int)$e['id'] ?>/publish" class="inline">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="<?= $e['results_published_at'] ? 'unpublish' : 'publish' ?>">
    <button class="btn btn-sm <?= $e['results_published_at'] ? 'btn-secondary' : '' ?>" type="submit">
      <?= $e['results_published_at'] ? 'Hide results again' : 'Publish results now' ?>
    </button>
  </form>

  <form method="post" action="/admin/elections/<?= (int)$e['id'] ?>/archive" class="inline"
        onsubmit="return confirm('<?= (int)$e['is_archived'] ? 'Restore' : 'Archive' ?> this election?')">
    <?= csrf_field() ?>
    <button class="btn btn-secondary btn-sm" type="submit">
      <?= (int)$e['is_archived'] ? 'Restore' : 'Archive' ?>
    </button>
  </form>
</div>

<!-- ------------------------------------------------------------ settings -->

<div class="card">
  <h2>Election settings</h2>
  <form method="post" action="/admin/elections/<?= (int)$e['id'] ?>">
    <?= csrf_field() ?>

    <div class="field <?= isset($errors['title']) ? 'field-error' : '' ?>">
      <label for="title">Title</label>
      <input id="title" name="title" type="text" required value="<?= e($e['title']) ?>">
      <?php if (isset($errors['title'])): ?><div class="err"><?= e($errors['title']) ?></div><?php endif; ?>
    </div>

    <div class="field">
      <label for="description">Description</label>
      <textarea id="description" name="description"><?= e($e['description']) ?></textarea>
    </div>

    <div class="field">
      <label for="timezone">Timezone</label>
      <select id="timezone" name="timezone">
        <?php foreach ($tzList as $tz): ?>
          <option value="<?= e($tz) ?>" <?= $tz === $cur ? 'selected' : '' ?>><?= e($tz) ?></option>
        <?php endforeach; ?>
      </select>
    </div>

    <div class="form-row">
      <div class="field <?= isset($errors['starts_at']) ? 'field-error' : '' ?>">
        <label for="starts_at">Voting opens</label>
        <input id="starts_at" name="starts_at" type="datetime-local" required
               value="<?= e(utc_to_local_input($e['starts_at'], $cur)) ?>">
        <?php if (isset($errors['starts_at'])): ?><div class="err"><?= e($errors['starts_at']) ?></div><?php endif; ?>
      </div>
      <div class="field <?= isset($errors['ends_at']) ? 'field-error' : '' ?>">
        <label for="ends_at">Voting closes</label>
        <input id="ends_at" name="ends_at" type="datetime-local" required
               value="<?= e(utc_to_local_input($e['ends_at'], $cur)) ?>">
        <?php if (isset($errors['ends_at'])): ?><div class="err"><?= e($errors['ends_at']) ?></div><?php endif; ?>
      </div>
    </div>

    <div class="field">
      <label for="results_mode">When are results public?</label>
      <select id="results_mode" name="results_mode">
        <option value="after_close" <?= $e['results_mode'] === 'after_close' ? 'selected' : '' ?>>Automatically when voting closes</option>
        <option value="live"        <?= $e['results_mode'] === 'live'        ? 'selected' : '' ?>>Live, while voting is open</option>
        <option value="manual"      <?= $e['results_mode'] === 'manual'      ? 'selected' : '' ?>>Only when I press Publish</option>
      </select>
    </div>

    <button class="btn" type="submit">Save settings</button>
    <?php if ($turnout['voted'] > 0): ?>
      <p class="faint" style="margin-top:10px">
        Ballots have already been cast. Every change here is written to the audit trail with the old
        and the new value.
      </p>
    <?php endif; ?>
  </form>
</div>

<!-- --------------------------------------------------------- the ballot -->

<div class="section">
  <div class="btn-row" style="justify-content:space-between">
    <h2 style="margin:0">Positions and candidates</h2>
  </div>

  <?php if (!$structure): ?>
    <div class="empty"><p>No positions yet. Add the first one below.</p></div>
  <?php endif; ?>

  <?php foreach ($structure as $pos): ?>
    <div class="card">
      <div class="btn-row" style="justify-content:space-between;align-items:flex-start">
        <div>
          <h2 style="margin:0"><?= e($pos['title']) ?></h2>
          <?php if ($pos['description']): ?>
            <p class="faint" style="margin:2px 0 0"><?= e($pos['description']) ?></p>
          <?php endif; ?>
        </div>
        <?php if (!$locked): ?>
          <form method="post" action="/admin/positions/<?= (int)$pos['id'] ?>/delete"
                onsubmit="return confirm('Delete the position &quot;<?= e($pos['title']) ?>&quot; and all of its candidates?')">
            <?= csrf_field() ?>
            <button class="btn btn-danger btn-sm" type="submit">Delete position</button>
          </form>
        <?php endif; ?>
      </div>

      <?php if (!$pos['candidates']): ?>
        <p class="faint" style="margin-top:14px">No candidates yet for this position.</p>
      <?php else: ?>
        <div class="table-scroll" style="margin-top:14px">
          <table>
            <thead><tr><th style="width:60px"></th><th>Candidate</th><th>Edit</th></tr></thead>
            <tbody>
            <?php foreach ($pos['candidates'] as $c): ?>
              <tr>
                <td>
                  <?php $url = candidate_photo_url($c['photo_path']); ?>
                  <?php if ($url): ?>
                    <img src="<?= e($url) ?>" alt="" style="width:46px;height:46px;border-radius:8px;object-fit:cover">
                  <?php else: ?>
                    <div class="option-photo-empty" style="width:46px;height:46px">
                      <?= e(mb_substr($c['full_name'], 0, 1, 'UTF-8')) ?>
                    </div>
                  <?php endif; ?>
                </td>
                <td>
                  <strong><?= e($c['full_name']) ?></strong>
                  <?php if ($c['headline']): ?><br><span class="faint"><?= e($c['headline']) ?></span><?php endif; ?>
                </td>
                <td>
                  <details class="admin-add">
                    <summary>Edit profile</summary>
                    <form method="post" action="/admin/candidates/<?= (int)$c['id'] ?>/update"
                          enctype="multipart/form-data" style="margin-top:10px;max-width:460px">
                      <?= csrf_field() ?>
                      <div class="field">
                        <label>Name</label>
                        <input name="full_name" type="text" required value="<?= e($c['full_name']) ?>">
                      </div>
                      <div class="field">
                        <label>Headline</label>
                        <input name="headline" type="text" value="<?= e($c['headline']) ?>">
                      </div>
                      <div class="field">
                        <label>Manifesto / bio</label>
                        <textarea name="bio"><?= e($c['bio']) ?></textarea>
                      </div>
                      <div class="field">
                        <label>Replace photo</label>
                        <input name="photo" type="file" accept="image/*">
                        <div class="hint">Leave empty to keep the current photo.</div>
                      </div>
                      <div class="btn-row">
                        <button class="btn btn-sm" type="submit">Save</button>
                      </div>
                    </form>

                    <form method="post" action="/admin/candidates/<?= (int)$c['id'] ?>/delete"
                          style="margin-top:10px"
                          onsubmit="return confirm('Remove <?= e($c['full_name']) ?> from the ballot?')">
                      <?= csrf_field() ?>
                      <button class="btn btn-danger btn-sm" type="submit">Delete candidate</button>
                    </form>
                  </details>
                </td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>

      <?php if (!$locked): ?>
        <hr class="sep">
        <details class="admin-add">
          <summary>Add a candidate to <?= e($pos['title']) ?></summary>
          <form method="post" action="/admin/positions/<?= (int)$pos['id'] ?>/candidates"
                enctype="multipart/form-data" style="margin-top:10px;max-width:460px">
            <?= csrf_field() ?>
            <div class="field">
              <label>Full name</label>
              <input name="full_name" type="text" required>
            </div>
            <div class="field">
              <label>Headline <span class="faint">(optional)</span></label>
              <input name="headline" type="text" placeholder="e.g. Class of 2009 · Chapter secretary">
            </div>
            <div class="field">
              <label>Manifesto / bio <span class="faint">(optional)</span></label>
              <textarea name="bio"></textarea>
            </div>
            <div class="field">
              <label>Photo <span class="faint">(optional)</span></label>
              <input name="photo" type="file" accept="image/*">
              <div class="hint">
                JPEG, PNG, GIF or WebP, up to <?= (int)config('photo_max_mb') ?> MB. It is re-encoded
                and resized to <?= (int)config('photo_max_px') ?> px on upload.
              </div>
            </div>
            <button class="btn" type="submit">Add candidate</button>
          </form>
        </details>
      <?php endif; ?>
    </div>
  <?php endforeach; ?>

  <?php if (!$locked): ?>
    <div class="card">
      <details class="admin-add" <?= $structure ? '' : 'open' ?>>
        <summary>Add a position</summary>
        <form method="post" action="/admin/elections/<?= (int)$e['id'] ?>/positions"
              style="margin-top:10px;max-width:460px">
          <?= csrf_field() ?>
          <div class="field">
            <label>Position title</label>
            <input name="title" type="text" required placeholder="e.g. President">
          </div>
          <div class="field">
            <label>Description <span class="faint">(optional)</span></label>
            <textarea name="description"></textarea>
          </div>
          <button class="btn" type="submit">Add position</button>
        </form>
      </details>
    </div>
  <?php endif; ?>
</div>
