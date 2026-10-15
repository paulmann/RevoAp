<?php
declare(strict_types=1);

namespace App\Jobs;

use App\Core\Database;
use PDO;

/**
 * An operator's request that the workers restart themselves.
 *
 * The question this answers: "how can the web panel restart a worker?" It cannot
 * - the unit belongs to systemd, and giving the web process the privilege to
 * restart services would be a far larger decision than this button. It does not
 * need to. The unit is `Restart=always`, so all a restart needs is for the worker
 * to **stop cleanly**: the supervisor then starts a fresh process, which loads
 * the currently deployed code. That is why the button writes a request instead of
 * running `systemctl`, and why dropping the running workers, as the operator
 * guessed, is exactly what happens - except that systemd is the one that brings
 * them back, and it does so after a clean exit rather than a kill.
 *
 * The request is a **timestamp**, and only one newer than the process's own start
 * counts. Without that comparison a single click would keep every future worker
 * exiting as soon as it started: the row has to mean "restart the ones that are
 * running now", which is also what an operator means by it.
 *
 * Storage is one key in `app_config`, written with an UPDATE-then-INSERT pair so
 * the same code works on MySQL and on the SQLite the test suites use. Reads and
 * writes never throw: a worker that cannot see the request simply keeps working,
 * which is the safe direction for this signal.
 */
final class WorkerRestartSignal
{
    public const KEY = 'worker.restart_requested_at';

    private ?PDO $pdo;

    public function __construct(Database|PDO|null $db = null)
    {
        $this->pdo = $db instanceof Database ? self::resolvePdoOf($db) : $db;
    }

    /**
     * Should a worker that started at $processStartedAt stop now?
     *
     * Pure, so the one rule that matters is testable: a request counts only when
     * it is newer than the process asking the question.
     */
    public static function shouldExit(?int $requestedAt, int $processStartedAt): bool
    {
        return $requestedAt !== null && $requestedAt > $processStartedAt;
    }

    /** Record a restart request for every worker that is running now. */
    public function request(): ?int
    {
        $now = time();
        if ($this->pdo === null) {
            return null;
        }
        try {
            $stmt = $this->pdo->prepare('UPDATE app_config SET config_value = :v WHERE config_key = :k');
            $stmt->execute(['v' => (string)$now, 'k' => self::KEY]);
            if ($stmt->rowCount() === 0) {
                $insert = $this->pdo->prepare('INSERT INTO app_config (config_key, config_value) VALUES (:k, :v)');
                $insert->execute(['k' => self::KEY, 'v' => (string)$now]);
            }

            return $now;
        } catch (\Throwable) {
            return null;
        }
    }

    /** The timestamp of the last request, or null when there is none. */
    public function requestedAt(): ?int
    {
        if ($this->pdo === null) {
            return null;
        }
        try {
            $stmt = $this->pdo->prepare('SELECT config_value FROM app_config WHERE config_key = :k LIMIT 1');
            $stmt->execute(['k' => self::KEY]);
            $row = $stmt->fetch();
            $value = is_array($row) ? (string)($row['config_value'] ?? '') : '';

            return $value !== '' && ctype_digit($value) ? (int)$value : null;
        } catch (\Throwable) {
            return null;
        }
    }

    private static function resolvePdoOf(Database $db): ?PDO
    {
        try {
            $pdo = null;
            $db->run(static function (PDO $handle) use (&$pdo): void { $pdo = $handle; });

            return $pdo instanceof PDO ? $pdo : null;
        } catch (\Throwable) {
            return null;
        }
    }
}
