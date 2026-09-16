<?php $title = 'Voter accounts'; ?>

<p class="faint"><a href="/admin">← Admin</a></p>

<div class="page-head">
  <h1>Accounts</h1>
  <p class="lede">
    Approve, suspend and set roles. An account has to be active and email-confirmed before it
    counts towards the eligible roll or can cast a ballot.
  </p>
</div>

<div class="card card-tight">
  <form method="get" action="/admin/users" class="form-row" style="align-items:end">
    <div class="field" style="margin:0">
      <label for="q">Search</label>
      <input id="q" name="q" type="text" value="<?= e($q) ?>" placeholder="name or email">
    </div>
    <div class="field" style="margin:0">
      <label for="status">Status</label>
      <select id="status" name="status">
        <option value="">Any</option>
        <?php foreach (['pending', 'active', 'suspended'] as $s): ?>
          <option value="<?= e($s) ?>" <?= $status === $s ? 'selected' : '' ?>><?= e(ucfirst($s)) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="field" style="margin:0">
      <button class="btn" type="submit">Filter</button>
      <a class="btn btn-secondary" href="/admin/users">Clear</a>
    </div>
  </form>
</div>

<?php if (!$users): ?>
  <div class="empty"><p>No accounts match.</p></div>
<?php else: ?>
  <div class="card card-tight table-scroll">
    <table>
      <thead>
        <tr><th>Account</th><th>Confirmed</th><th>Status</th><th>Role</th></tr>
      </thead>
      <tbody>
      <?php foreach ($users as $u): ?>
        <tr>
          <td>
            <strong><?= e($u['full_name'] ?: '—') ?></strong>
            <?php if ($u['grad_year']): ?><span class="faint"> · <?= e($u['grad_year']) ?></span><?php endif; ?>
            <br><span class="faint"><?= e($u['email']) ?></span>
          </td>
          <td>
            <?php if ($u['email_verified_at']): ?>
              <span class="badge badge-open">Yes</span>
            <?php else: ?>
              <span class="badge badge-upcoming">Not yet</span>
            <?php endif; ?>
          </td>
          <td>
            <form method="post" action="/admin/users/<?= (int)$u['id'] ?>/status" class="btn-row">
              <?= csrf_field() ?>
              <select name="status" style="width:auto">
                <?php foreach (['pending', 'active', 'suspended'] as $s): ?>
                  <option value="<?= e($s) ?>" <?= $u['status'] === $s ? 'selected' : '' ?>><?= e(ucfirst($s)) ?></option>
                <?php endforeach; ?>
              </select>
              <button class="btn btn-secondary btn-sm" type="submit">Set</button>
            </form>
          </td>
          <td>
            <form method="post" action="/admin/users/<?= (int)$u['id'] ?>/role" class="btn-row">
              <?= csrf_field() ?>
              <select name="role" style="width:auto">
                <?php foreach (['voter', 'auditor', 'admin'] as $r): ?>
                  <option value="<?= e($r) ?>" <?= $u['role'] === $r ? 'selected' : '' ?>><?= e(ucfirst($r)) ?></option>
                <?php endforeach; ?>
              </select>
              <button class="btn btn-secondary btn-sm" type="submit">Set</button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
<?php endif; ?>

<div class="card card-tight">
  <h3>What the roles mean</h3>
  <table>
    <tr><td><span class="role role-admin">admin</span></td>
        <td>Creates elections, manages candidates and accounts, publishes results. Cannot vote.</td></tr>
    <tr><td><span class="role role-auditor">auditor</span></td>
        <td>Read-only oversight: turnout and the audit trail, at any time. Cannot vote or change anything.</td></tr>
    <tr><td><span class="role">voter</span></td>
        <td>Reads candidate profiles and casts one ballot per election. Sees results when they are published.</td></tr>
  </table>
</div>
