<?php
$title = 'New election';
$tzList = DateTimeZone::listIdentifiers();
$cur    = $e['timezone'] ?? 'UTC';
?>

<p class="faint"><a href="/admin">← Admin</a></p>
<h1>New election</h1>
<p class="lede">Set the voting window first. Positions and candidates come next.</p>

<div class="card" style="max-width:620px">
  <form method="post" action="/admin/elections">
    <?= csrf_field() ?>

    <div class="field <?= isset($errors['title']) ? 'field-error' : '' ?>">
      <label for="title">Title</label>
      <input id="title" name="title" type="text" required
             value="<?= e($e['title'] ?? '') ?>" placeholder="e.g. Alumni Association Executive 2026">
      <?php if (isset($errors['title'])): ?><div class="err"><?= e($errors['title']) ?></div><?php endif; ?>
    </div>

    <div class="field">
      <label for="description">Description <span class="faint">(optional)</span></label>
      <textarea id="description" name="description"><?= e($e['description'] ?? '') ?></textarea>
    </div>

    <div class="field <?= isset($errors['timezone']) ? 'field-error' : '' ?>">
      <label for="timezone">Timezone</label>
      <select id="timezone" name="timezone">
        <?php foreach ($tzList as $tz): ?>
          <option value="<?= e($tz) ?>" <?= $tz === $cur ? 'selected' : '' ?>><?= e($tz) ?></option>
        <?php endforeach; ?>
      </select>
      <div class="hint">The opening and closing times below are read in this timezone and stored in UTC.</div>
    </div>

    <div class="form-row">
      <div class="field <?= isset($errors['starts_at']) ? 'field-error' : '' ?>">
        <label for="starts_at">Voting opens</label>
        <input id="starts_at" name="starts_at" type="datetime-local" required
               value="<?= e(utc_to_local_input($e['starts_at'] ?? null, $cur)) ?>">
        <?php if (isset($errors['starts_at'])): ?><div class="err"><?= e($errors['starts_at']) ?></div><?php endif; ?>
      </div>

      <div class="field <?= isset($errors['ends_at']) ? 'field-error' : '' ?>">
        <label for="ends_at">Voting closes</label>
        <input id="ends_at" name="ends_at" type="datetime-local" required
               value="<?= e(utc_to_local_input($e['ends_at'] ?? null, $cur)) ?>">
        <?php if (isset($errors['ends_at'])): ?><div class="err"><?= e($errors['ends_at']) ?></div><?php endif; ?>
      </div>
    </div>

    <div class="field">
      <label for="results_mode">When are results public?</label>
      <select id="results_mode" name="results_mode">
        <?php $rm = $e['results_mode'] ?? 'after_close'; ?>
        <option value="after_close" <?= $rm === 'after_close' ? 'selected' : '' ?>>
          Automatically when voting closes
        </option>
        <option value="live" <?= $rm === 'live' ? 'selected' : '' ?>>
          Live, while voting is open
        </option>
        <option value="manual" <?= $rm === 'manual' ? 'selected' : '' ?>>
          Only when I press Publish
        </option>
      </select>
      <div class="hint">
        Administrators and auditors always see turnout, whichever option is chosen.
      </div>
    </div>

    <button class="btn btn-lg" type="submit">Create the election</button>
  </form>
</div>
