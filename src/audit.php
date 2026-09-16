<?php
declare(strict_types=1);

/**
 * Append-only audit trail.
 *
 * There is deliberately no update or delete helper here, and the database
 * refuses both with a trigger, so an admin cannot quietly rewrite history
 * even with direct table access through the app.
 */
function audit(string $action, string $entityType = '', ?int $entityId = null, array $details = []): void
{
    $user = current_user();
    db_run(
        'INSERT INTO audit_log (actor_id, actor_email, action, entity_type, entity_id, details, ip, created_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
        [
            $user['id']    ?? null,
            $user['email'] ?? 'system',
            $action,
            $entityType,
            $entityId,
            $details ? json_encode($details, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : '',
            client_ip(),
            now(),
        ]
    );
}

function audit_page(int $limit = 200, int $offset = 0): array
{
    $stmt = db()->prepare(
        'SELECT * FROM audit_log ORDER BY id DESC LIMIT :lim OFFSET :off'
    );
    $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
    $stmt->bindValue(':off', $offset, PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchAll();
}

function audit_count(): int
{
    return db_int('SELECT COUNT(*) FROM audit_log');
}
