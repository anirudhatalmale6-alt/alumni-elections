<?php $title = 'My account'; ?>

<div class="page-head">
  <h1>My account</h1>
  <p class="lede"><?= e($user['full_name']) ?> · <?= e($user['email']) ?></p>
</div>

<div class="card card-tight">
  <div class="grid grid-3">
    <div>
      <div class="stat-label">Role</div>
      <div><span class="role role-<?= e($user['role']) ?>"><?= e($user['role']) ?></span></div>
    </div>
    <div>
      <div class="stat-label">Status</div>
      <div><?= e(ucfirst($user['status'])) ?></div>
    </div>
    <div>
      <div class="stat-label">Email confirmed</div>
      <div><?= $user['email_verified_at'] ? e(fmt_dt($user['email_verified_at'])) : 'Not yet' ?></div>
    </div>
  </div>
</div>

<div class="section">
  <h2>My voting record</h2>
  <p class="muted">
    This lists the elections you have voted in and the receipt code you were given. It deliberately
    does not show who you voted for — that link is not stored anywhere in the system.
  </p>

  <?php if (!$receipts): ?>
    <div class="empty"><p>You have not voted in any election yet.</p></div>
  <?php else: ?>
    <div class="card card-tight table-scroll">
      <table>
        <thead>
          <tr><th>Election</th><th>Position</th><th>Submitted</th><th>Receipt</th></tr>
        </thead>
        <tbody>
        <?php foreach ($receipts as $r): ?>
          <tr>
            <td><?= e($r['election_title']) ?></td>
            <td><?= e($r['position_title']) ?></td>
            <td class="faint"><?= e(fmt_dt($r['cast_at'], $r['timezone'])) ?></td>
            <td class="mono"><?= e($r['receipt_code']) ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</div>
