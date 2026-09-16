<?php
declare(strict_types=1);

/**
 * PDO/SQLite bootstrap plus the schema migrations.
 *
 * The integrity rules that matter live in the schema itself, not in PHP:
 *  - vote_receipts has UNIQUE(election_id, position_id, user_id), so a double
 *    submit or a refresh cannot produce a second vote even under a race.
 *  - ballots carries no user_id at all, so a published tally cannot be walked
 *    back to an individual voter.
 *  - audit_log has triggers that reject UPDATE and DELETE, so the trail is
 *    append-only from the database's point of view, not just by convention.
 */

function db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $cfg = config();
    $dir = dirname($cfg['db_path']);
    if (!is_dir($dir)) {
        mkdir($dir, 0775, true);
    }

    $pdo = new PDO('sqlite:' . $cfg['db_path'], null, null, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]);

    // WAL lets readers carry on while a ballot is being written; the busy
    // timeout keeps a concurrent vote from failing outright on a locked file.
    $pdo->exec('PRAGMA journal_mode = WAL');
    $pdo->exec('PRAGMA busy_timeout = 5000');
    $pdo->exec('PRAGMA foreign_keys = ON');

    migrate($pdo);

    return $pdo;
}

/*
 * Query helpers.
 *
 * These exist for a specific reason. A PDO statement that is read with fetch()
 * or fetchColumn() and then abandoned keeps its cursor open, and an open cursor
 * keeps a read transaction open. SQLite will not promote a read transaction to
 * a write one: BEGIN IMMEDIATE then fails instantly with "database is locked"
 * and the busy timeout is never even consulted. That turned every concurrent
 * ballot into a server error until these helpers closed the cursors.
 */

function db_row(string $sql, array $params = []): ?array
{
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    $row = $stmt->fetch();
    $stmt->closeCursor();
    return $row ?: null;
}

function db_val(string $sql, array $params = [])
{
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    $value = $stmt->fetchColumn();
    $stmt->closeCursor();
    return $value === false ? null : $value;
}

function db_int(string $sql, array $params = []): int
{
    return (int)db_val($sql, $params);
}

function db_all(string $sql, array $params = []): array
{
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();
    $stmt->closeCursor();
    return $rows;
}

function db_column(string $sql, array $params = []): array
{
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_COLUMN);
    $stmt->closeCursor();
    return $rows;
}

/** Returns the number of affected rows. */
function db_run(string $sql, array $params = []): int
{
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    $n = $stmt->rowCount();
    $stmt->closeCursor();
    return $n;
}

function is_busy_error(Throwable $ex): bool
{
    $msg = $ex->getMessage();
    return str_contains($msg, 'database is locked')
        || str_contains($msg, 'database table is locked');
}

/**
 * Open a write transaction, waiting for another writer to finish.
 *
 * SQLite's own busy timeout covers the ordinary case, but not the promotion
 * case described above, so this retries with a short randomised backoff.
 * Returns false if the lock could not be taken inside the budget; the caller
 * then reports something civil instead of letting a 500 reach a voter.
 */
function begin_write(PDO $pdo, float $budgetSeconds = 6.0): bool
{
    $deadline = microtime(true) + $budgetSeconds;
    $delayUs  = 2000;

    while (true) {
        try {
            $pdo->exec('BEGIN IMMEDIATE');
            return true;
        } catch (PDOException $ex) {
            if (!is_busy_error($ex)) {
                throw $ex;
            }
            if (microtime(true) >= $deadline) {
                return false;
            }
            // Randomised so that a crowd of retriers does not march in step.
            usleep($delayUs + random_int(0, $delayUs));
            $delayUs = min($delayUs * 2, 120000);
        }
    }
}

function migrate(PDO $pdo): void
{
    $pdo->exec('CREATE TABLE IF NOT EXISTS schema_migrations (
        name       TEXT PRIMARY KEY,
        applied_at TEXT NOT NULL
    )');

    $applied = $pdo->query('SELECT name FROM schema_migrations')->fetchAll(PDO::FETCH_COLUMN);
    $applied = array_flip($applied);

    foreach (migrations() as $name => $sql) {
        if (isset($applied[$name])) {
            continue;
        }
        $pdo->exec('BEGIN');
        try {
            $pdo->exec($sql);
            $stmt = $pdo->prepare('INSERT INTO schema_migrations (name, applied_at) VALUES (?, ?)');
            $stmt->execute([$name, gmdate('Y-m-d H:i:s')]);
            $pdo->exec('COMMIT');
        } catch (Throwable $e) {
            $pdo->exec('ROLLBACK');
            throw $e;
        }
    }
}

function migrations(): array
{
    return [
        '001_core' => <<<'SQL'
        CREATE TABLE users (
            id              INTEGER PRIMARY KEY AUTOINCREMENT,
            email           TEXT NOT NULL,
            email_norm      TEXT NOT NULL UNIQUE,
            password_hash   TEXT NOT NULL,
            full_name       TEXT NOT NULL DEFAULT '',
            grad_year       TEXT NOT NULL DEFAULT '',
            role            TEXT NOT NULL DEFAULT 'voter'
                              CHECK (role IN ('admin','voter','auditor')),
            status          TEXT NOT NULL DEFAULT 'pending'
                              CHECK (status IN ('pending','active','suspended')),
            email_verified_at TEXT,
            verify_token    TEXT,
            reset_token     TEXT,
            reset_expires_at TEXT,
            created_at      TEXT NOT NULL,
            updated_at      TEXT NOT NULL
        );
        CREATE INDEX idx_users_verify ON users(verify_token);
        CREATE INDEX idx_users_reset  ON users(reset_token);

        CREATE TABLE alumni_roll (
            id         INTEGER PRIMARY KEY AUTOINCREMENT,
            email_norm TEXT NOT NULL UNIQUE,
            note       TEXT NOT NULL DEFAULT '',
            added_by   INTEGER,
            created_at TEXT NOT NULL
        );

        CREATE TABLE elections (
            id                  INTEGER PRIMARY KEY AUTOINCREMENT,
            title               TEXT NOT NULL,
            description         TEXT NOT NULL DEFAULT '',
            starts_at           TEXT NOT NULL,          -- UTC 'Y-m-d H:i:s'
            ends_at             TEXT NOT NULL,          -- UTC 'Y-m-d H:i:s'
            timezone            TEXT NOT NULL DEFAULT 'UTC',
            results_mode        TEXT NOT NULL DEFAULT 'after_close'
                                  CHECK (results_mode IN ('live','after_close','manual')),
            results_published_at TEXT,
            is_archived         INTEGER NOT NULL DEFAULT 0,
            created_by          INTEGER,
            created_at          TEXT NOT NULL,
            updated_at          TEXT NOT NULL,
            CHECK (ends_at > starts_at)
        );

        CREATE TABLE positions (
            id          INTEGER PRIMARY KEY AUTOINCREMENT,
            election_id INTEGER NOT NULL REFERENCES elections(id) ON DELETE CASCADE,
            title       TEXT NOT NULL,
            description TEXT NOT NULL DEFAULT '',
            sort_order  INTEGER NOT NULL DEFAULT 0,
            created_at  TEXT NOT NULL
        );
        CREATE INDEX idx_positions_election ON positions(election_id);

        CREATE TABLE candidates (
            id          INTEGER PRIMARY KEY AUTOINCREMENT,
            position_id INTEGER NOT NULL REFERENCES positions(id) ON DELETE CASCADE,
            full_name   TEXT NOT NULL,
            headline    TEXT NOT NULL DEFAULT '',
            bio         TEXT NOT NULL DEFAULT '',
            photo_path  TEXT,
            sort_order  INTEGER NOT NULL DEFAULT 0,
            created_at  TEXT NOT NULL
        );
        CREATE INDEX idx_candidates_position ON candidates(position_id);

        -- WHO has voted. Never joined to ballots.
        CREATE TABLE vote_receipts (
            id           INTEGER PRIMARY KEY AUTOINCREMENT,
            election_id  INTEGER NOT NULL REFERENCES elections(id) ON DELETE CASCADE,
            position_id  INTEGER NOT NULL REFERENCES positions(id) ON DELETE CASCADE,
            user_id      INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
            receipt_code TEXT NOT NULL,
            cast_at      TEXT NOT NULL,
            UNIQUE (election_id, position_id, user_id)
        );
        CREATE INDEX idx_receipts_user ON vote_receipts(user_id);

        -- WHAT was voted. No user_id, by design.
        CREATE TABLE ballots (
            id           INTEGER PRIMARY KEY AUTOINCREMENT,
            election_id  INTEGER NOT NULL REFERENCES elections(id) ON DELETE CASCADE,
            position_id  INTEGER NOT NULL REFERENCES positions(id) ON DELETE CASCADE,
            candidate_id INTEGER NOT NULL REFERENCES candidates(id) ON DELETE CASCADE,
            cast_at      TEXT NOT NULL,
            nonce        TEXT NOT NULL,
            prev_hash    TEXT NOT NULL,
            hash         TEXT NOT NULL
        );
        CREATE INDEX idx_ballots_election ON ballots(election_id);
        CREATE INDEX idx_ballots_tally    ON ballots(position_id, candidate_id);

        CREATE TABLE audit_log (
            id           INTEGER PRIMARY KEY AUTOINCREMENT,
            actor_id     INTEGER,
            actor_email  TEXT NOT NULL DEFAULT '',
            action       TEXT NOT NULL,
            entity_type  TEXT NOT NULL DEFAULT '',
            entity_id    INTEGER,
            details      TEXT NOT NULL DEFAULT '',
            ip           TEXT NOT NULL DEFAULT '',
            created_at   TEXT NOT NULL
        );
        CREATE INDEX idx_audit_created ON audit_log(created_at);

        CREATE TRIGGER audit_log_no_update
        BEFORE UPDATE ON audit_log
        BEGIN
            SELECT RAISE(ABORT, 'audit_log is append-only');
        END;

        CREATE TRIGGER audit_log_no_delete
        BEFORE DELETE ON audit_log
        BEGIN
            SELECT RAISE(ABORT, 'audit_log is append-only');
        END;

        CREATE TABLE login_attempts (
            id         INTEGER PRIMARY KEY AUTOINCREMENT,
            email_norm TEXT NOT NULL,
            ip         TEXT NOT NULL DEFAULT '',
            created_at TEXT NOT NULL
        );
        CREATE INDEX idx_attempts ON login_attempts(email_norm, created_at);
        SQL,
    ];
}
