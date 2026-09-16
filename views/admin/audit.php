<?php
$title = 'Audit trail';
$pages = max(1, (int)ceil($total / $per));
?>

<p class="faint"><a href="/admin">← Admin</a></p>

<div class="page-head">
  <h1>Audit trail</h1>
  <p class="lede">
    Every administrative action, in order, with who did it and when.
    The table rejects updates and deletes at the database level, so entries cannot be edited or
    removed afterwards — not through this site and not by an administrator with a SQL client.
  </p>
</div>

<div class="alert alert-info">
  Ballot entries record that a vote was accepted and its receipt code. They deliberately do not
  record the voter or the choice.
</div>

<?php if (!$entries): ?>
  <div class="empty"><p>Nothing recorded yet.</p></div>
<?php else: ?>
  <div class="card card-tight table-scroll">
    <table>
      <thead>
        <tr><th>#</th><th>When (UTC)</th><th>Who</th><th>Action</th><th>Target</th><th>Detail</th></tr>
      </thead>
      <tbody>
      <?php foreach ($entries as $a): ?>
        <tr>
          <td class="faint"><?= (int)$a['id'] ?></td>
          <td class="faint" style="white-space:nowrap"><?= e($a['created_at']) ?></td>
          <td>
            <?= e($a['actor_email']) ?>
            <?php if ($a['ip']): ?><br><span class="faint mono"><?= e($a['ip']) ?></span><?php endif; ?>
          </td>
          <td class="mono"><?= e($a['action']) ?></td>
          <td class="faint">
            <?= e($a['entity_type']) ?><?= $a['entity_id'] ? ' #' . (int)$a['entity_id'] : '' ?>
          </td>
          <td class="faint mono" style="max-width:320px;word-break:break-word"><?= e($a['details']) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>

  <?php if ($pages > 1): ?>
    <div class="btn-row" style="margin-top:14px">
      <?php if ($page > 1): ?>
        <a class="btn btn-secondary btn-sm" href="/admin/audit?page=<?= $page - 1 ?>">← Newer</a>
      <?php endif; ?>
      <span class="faint">Page <?= (int)$page ?> of <?= (int)$pages ?> · <?= (int)$total ?> entries</span>
      <?php if ($page < $pages): ?>
        <a class="btn btn-secondary btn-sm" href="/admin/audit?page=<?= $page + 1 ?>">Older →</a>
      <?php endif; ?>
    </div>
  <?php endif; ?>
<?php endif; ?>
