<?php $title = $user['role'] === 'admin' ? 'Admin' : 'Oversight'; ?>

<div class="page-head">
  <div class="btn-row" style="justify-content:space-between">
    <div>
      <h1><?= $user['role'] === 'admin' ? 'Administration' : 'Oversight' ?></h1>
      <p class="lede" style="margin:0">
        <?php if ($user['role'] === 'admin'): ?>
          Create elections, manage candidates and the alumni roll, and publish results.
        <?php else: ?>
          Read-only. You can watch turnout and read the audit trail, but you cannot change an
          election or cast a vote.
        <?php endif; ?>
      </p>
    </div>
    <?php if ($user['role'] === 'admin'): ?>
      <a class="btn" href="/admin/elections/new">New election</a>
    <?php endif; ?>
  </div>
</div>

<div class="grid grid-3" style="margin-bottom:26px">
  <div class="stat">
    <div class="stat-value"><?= (int)$counts['eligible'] ?></div>
    <div class="stat-label">Eligible voters</div>
  </div>
  <div class="stat">
    <div class="stat-value"><?= (int)$counts['pending'] ?></div>
    <div class="stat-label">Accounts pending</div>
  </div>
  <div class="stat">
    <div class="stat-value"><?= (int)$counts['roll'] ?></div>
    <div class="stat-label">On the alumni roll</div>
  </div>
  <div class="stat">
    <div class="stat-value"><?= (int)$counts['audit'] ?></div>
    <div class="stat-label">Audit entries</div>
  </div>
</div>

<div class="btn-row" style="margin-bottom:22px">
  <?php if ($user['role'] === 'admin'): ?>
    <a class="btn btn-secondary btn-sm" href="/admin/users">Voter accounts</a>
    <a class="btn btn-secondary btn-sm" href="/admin/roll">Alumni roll</a>
  <?php endif; ?>
  <a class="btn btn-secondary btn-sm" href="/admin/audit">Audit trail</a>
</div>

<h2>Elections</h2>

<?php if (!$rows): ?>
  <div class="empty">
    <p><strong>No elections yet.</strong></p>
    <?php if ($user['role'] === 'admin'): ?>
      <p><a class="btn" href="/admin/elections/new">Create the first one</a></p>
    <?php endif; ?>
  </div>
<?php else: ?>
  <div class="card card-tight table-scroll">
    <table>
      <thead>
        <tr>
          <th>Election</th><th>Status</th><th>Window</th>
          <th class="num">Positions</th><th class="num">Turnout</th><th></th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($rows as $r):
          $e = $r['e'];
      ?>
        <tr>
          <td>
            <strong><?= e($e['title']) ?></strong>
            <?php if ((int)$e['is_archived']): ?><span class="badge">Archived</span><?php endif; ?>
            <?php if ($e['results_published_at']): ?><span class="badge badge-open">Published</span><?php endif; ?>
          </td>
          <td><span class="badge badge-<?= e($r['phase']) ?>"><?= e(phase_label($r['phase'])) ?></span></td>
          <td class="faint">
            <?= e(fmt_dt($e['starts_at'], $e['timezone'], 'j M Y H:i')) ?><br>
            → <?= e(fmt_dt($e['ends_at'], $e['timezone'], 'j M Y H:i')) ?>
          </td>
          <td class="num"><?= (int)$r['positions'] ?></td>
          <td class="num">
            <?= (int)$r['turnout']['voted'] ?>/<?= (int)$r['turnout']['eligible'] ?><br>
            <span class="faint"><?= e((string)$r['turnout']['pct']) ?>%</span>
          </td>
          <td>
            <div class="btn-row">
              <a class="btn btn-secondary btn-sm" href="/admin/elections/<?= (int)$e['id'] ?>/turnout">Turnout</a>
              <?php if ($user['role'] === 'admin'): ?>
                <a class="btn btn-secondary btn-sm" href="/admin/elections/<?= (int)$e['id'] ?>">Manage</a>
              <?php endif; ?>
            </div>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
<?php endif; ?>
