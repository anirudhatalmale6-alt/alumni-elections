<?php $title = 'Alumni roll'; ?>

<p class="faint"><a href="/admin">← Admin</a></p>

<div class="page-head">
  <h1>Alumni roll</h1>
  <p class="lede">
    The list of email addresses allowed to hold an account.
    <?php if ($mode === 'roll'): ?>
      Registration is currently restricted to this list — an address that is not here cannot sign up.
    <?php else: ?>
      Registration is currently set to "<?= e($mode) ?>", so this list is informational only. Switch
      <span class="mono">registration</span> to <span class="mono">roll</span> in the config to enforce it.
    <?php endif; ?>
  </p>
</div>

<div class="card">
  <h2>Add addresses</h2>
  <form method="post" action="/admin/roll">
    <?= csrf_field() ?>
    <div class="field">
      <label for="emails">Email addresses</label>
      <textarea id="emails" name="emails" required style="min-height:130px"
                placeholder="one per line, or comma separated&#10;anita@example.org&#10;ben@example.org"></textarea>
      <div class="hint">
        Paste a column straight out of a spreadsheet. Line breaks, commas, semicolons and spaces all
        work as separators. Duplicates are ignored rather than rejected.
      </div>
    </div>
    <div class="field">
      <label for="note">Note <span class="faint">(optional, applied to this batch)</span></label>
      <input id="note" name="note" type="text" placeholder="e.g. 2015 cohort, imported from registry">
    </div>
    <button class="btn" type="submit">Add to the roll</button>
  </form>
</div>

<div class="section">
  <h2><?= count($rows) ?> address<?= count($rows) === 1 ? '' : 'es' ?> on the roll</h2>

  <?php if (!$rows): ?>
    <div class="empty"><p>The roll is empty. Add the alumni email addresses above.</p></div>
  <?php else: ?>
    <div class="card card-tight table-scroll">
      <table>
        <thead><tr><th>Email</th><th>Registered?</th><th>Note</th><th>Added</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($rows as $r): ?>
          <tr>
            <td class="mono"><?= e($r['email_norm']) ?></td>
            <td>
              <?php if (isset($registered[$r['email_norm']])): ?>
                <span class="badge badge-open">Account created</span>
              <?php else: ?>
                <span class="badge">Not yet</span>
              <?php endif; ?>
            </td>
            <td class="faint"><?= e($r['note']) ?></td>
            <td class="faint"><?= e(fmt_dt($r['created_at'], 'UTC', 'j M Y')) ?></td>
            <td>
              <form method="post" action="/admin/roll/<?= (int)$r['id'] ?>/delete"
                    onsubmit="return confirm('Remove <?= e($r['email_norm']) ?> from the roll?')">
                <?= csrf_field() ?>
                <button class="btn btn-secondary btn-sm" type="submit">Remove</button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</div>
