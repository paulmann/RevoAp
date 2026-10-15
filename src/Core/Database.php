<?php
/**
 * Database: Resilient PDO wrapper that survives idle disconnects.
 *
 * Long-running pipeline runs (parallel L1 calls + L4 synthesis) can keep
 * the PDO handle idle for tens of seconds. MySQL's `wait_timeout` (or a
 * connection-level firewall) may then drop the TCP socket. The next
 * write fails with the famous SQLSTATE[HY000] 2006 "MySQL server has
 * gone away" / 2013 "Lost connection during query".
 *
 * This class:
 *  - Lazily opens a PDO connection from the same DSN/credentials.
 *  - Detects "gone away" / "lost connection" / "no connection" errors,
 *    including an operator-initiated restart (1053 "Server shutdown in
 *    progress") and the SQLSTATE 08S01 connection class.
 *  - Reconnects transparently and retries the closure once.
 *  - Records the last db error for the diagnostic report.
 *  - Counts losses/reconnects and can report each transition through an
 *    observer, so a long-lived worker can log `worker.db.lost` /
 *    `worker.db.reconnected` instead of failing silently.
 *
 * The wrapper is intentionally small: storage classes still build their
 * own prepared statements via `pdo()` (or use `run()` for the auto-retry
 * path). New code should always go through `run()`; legacy direct-PDO
 * code keeps working because `pdo()` returns a real `PDO` instance —
 * but such code CANNOT be healed by a reconnect, because it caches the
 * handle it was given. That is exactly why every repository that a
 * long-lived process holds for hours must accept a `Database` rather
 * than a raw `PDO`.
 *
 * `fromPdo()` wraps an externally-owned handle. Such a wrapper can only
 * rebuild the connection when the caller also supplies the DSN (see the
 * optional arguments); without one `run()` re-raises the original
 * PDOException instead of pretending it can recover.
 *
 * @project  RevoAp
 * @author   Mikhail Deynekin <mikhail@deynekin.com>
 * @site     https://Deynekin.com
 * @email    mikhail@deynekin.com
 * @version  1.1.0
 * @since    2026-05-07
 */
declare(strict_types=1);

namespace App\Core;

use PDO;
use PDOException;
use Throwable;

final class Database
{
    /**
     * Driver error codes meaning "the connection we HAD is gone". A
     * retry on a fresh handle is worthwhile, because the server is
     * reachable enough to have accepted the previous one.
     *
     *   1053 ER_SERVER_SHUTDOWN      — `systemctl restart mysql` (the
     *                                  production incident this list was
     *                                  extended for)
     *   1077 ER_NORMAL_SHUTDOWN
     *   1152 ER_ABORTING_CONNECTION
     *   1927 ER_CONNECTION_KILLED
     *   2006 CR_SERVER_GONE_ERROR    — the canonical idle-disconnect
     *   2013 CR_SERVER_LOST          — lost during query
     */
    private const GONE_DRIVER_CODES = [1053, 1077, 1152, 1927, 2006, 2013];

    /**
     * Driver error codes meaning the connection could not be
     * ESTABLISHED at all. Reconnecting immediately is pointless — the
     * server is down, the host is wrong, or the network is gone — and
     * actively harmful: every operation would pay the connect timeout
     * twice. The caller must back off instead.
     *
     *   2002 CR_CONNECTION_ERROR     — host/socket refused
     *   2003 CR_CONN_HOST_ERROR      — TCP connect refused
     *   2005 CR_UNKNOWN_HOST         — DNS failure
     */
    private const CONNECT_DRIVER_CODES = [2002, 2003, 2005];

    /**
     * Server error codes returned as the FIRST element of
     * `errorInfo`. SQLSTATE class 08 is "connection exception" and is
     * the one class where a rebuild is safe.
     *
     * @var list<string>
     */
    private const GONE_SQLSTATES = ['08S01', '08006', '08003', '08007'];

    /**
     * SQLSTATE values that mean "could not establish a connection".
     *
     * @var list<string>
     */
    private const CONNECT_SQLSTATES = ['08001', '08004'];

    /**
     * Substrings of the driver message for an ESTABLISHED connection
     * that went away. Lowercased before matching so a server reporting
     * the condition without a numeric code is still recognised.
     *
     * @var list<string>
     */
    private const GONE_NEEDLES = [
        'gone away',
        'lost connection',
        'no connection',
        'connection was killed',
        'server has gone away',
        'server shutdown in progress',
        'normal shutdown',
        'shutdown in progress',
        'connection reset',
        'broken pipe',
        'error while sending',
        'error while reading',
        'mysql server has gone away',
        'aborting connection',
    ];

    /**
     * Substrings for a connection that could never be established.
     *
     * @var list<string>
     */
    private const CONNECT_NEEDLES = [
        'connection refused',
        'can\'t connect to',
        'cannot connect to',
        'unable to connect to',
        'is not allowed to connect to',
        'network is unreachable',
        'unknown host',
        'unknown mysql server host',
        'getaddrinfo',
    ];

    private ?PDO $pdo = null;

    /** Successful reconnects performed by this instance. */
    private int $reconnects = 0;

    /** Closure re-executions after a successful reconnect. */
    private int $retries = 0;

    /** Connection-loss errors observed by this instance. */
    private int $losses = 0;

    /** UTC ISO-8601 stamp of the most recent connection-loss error. */
    private ?string $lastErrorAt = null;

    /** Sanitized message of the most recent connection-loss error. */
    private ?string $lastErrorMessage = null;

    /**
     * Observers notified on every loss / reconnect transition. Kept as a
     * list so several consumers (log sink + metrics) can subscribe
     * without knowing about each other.
     *
     * @var list<callable(string,array<string,mixed>):void>
     */
    private array $observers = [];

    /**
     * @param array<int|string,mixed> $options
     */
    public function __construct(
        public readonly string $dsn,
        public readonly string $user = '',
        public readonly string $password = '',
        public readonly array  $options = [],
    ) {}

    /**
     * Build a Database from a pre-existing PDO.
     *
     * A wrapper built this way has no credentials of its own, so it
     * cannot rebuild a dead handle. Pass `$dsn` (and credentials) when
     * the handle is owned by a long-lived process that must survive a
     * server restart — then `run()` heals exactly like a natively
     * constructed wrapper.
     *
     * @param array<int|string,mixed> $options
     */
    public static function fromPdo(
        PDO $pdo,
        string $dsn = '',
        string $user = '',
        string $password = '',
        array $options = [],
    ): self {
        $self = new self($dsn, $user, $password, $options);
        $self->pdo = $pdo;
        return $self;
    }

    /**
     * Whether a handle is currently held. Note this is a *cheap* check:
     * a non-null handle may already have been closed by the server. Use
     * {@see ensureConnected()} when the answer has to be trustworthy.
     */
    public function isConnected(): bool
    {
        return $this->pdo !== null;
    }

    /** Whether this wrapper is allowed to rebuild the connection. */
    public function canReconnect(): bool
    {
        return $this->dsn !== '';
    }

    /**
     * Live PDO handle. Caller MUST tolerate the handle being replaced
     * after a reconnect — never cache it across boundaries.
     */
    public function pdo(): PDO
    {
        if ($this->pdo === null) {
            $this->pdo = $this->connect();
        }
        return $this->pdo;
    }

    /**
     * Register a transition observer. Called with `'db.lost'` when a
     * connection-loss error is recognised, and `'db.reconnected'` after
     * a successful rebuild. Observer exceptions are swallowed: an
     * observability hook must never break the caller's query path.
     *
     * Context keys: `class`, `message`, `sqlstate`, `driver_code`,
     * `reconnects`, `losses`.
     *
     * @param callable(string,array<string,mixed>):void $observer
     */
    public function addObserver(callable $observer): void
    {
        $this->observers[] = $observer;
    }

    /**
     * Verify the connection is usable, rebuilding it when it is not.
     *
     * This is the entry point a long-lived process should call before
     * starting a unit of work: `isConnected()` only proves a handle is
     * held, not that the server is still on the other end.
     *
     * Never throws. Returns true when the connection is usable.
     */
    public function ensureConnected(): bool
    {
        if ($this->pdo === null) {
            try {
                $this->pdo = $this->connect();
                return true;
            } catch (Throwable $e) {
                Diagnostics::recordDatabaseError($e);
                // A server we cannot reach is an infrastructure event the
                // operator must see, even though retrying now is useless.
                if ($e instanceof PDOException && self::isConnectFailure($e)) {
                    $this->noteLoss($e);
                }
                return false;
            }
        }
        try {
            // Cheapest possible round-trip. A socket the server already
            // closed fails here immediately instead of on the first real
            // statement of the next job.
            $stmt = $this->pdo->query('SELECT 1');
            if ($stmt !== false) {
                $stmt->closeCursor();
            }
            return true;
        } catch (PDOException $e) {
            if (!self::isGoneAway($e)) {
                Diagnostics::recordDatabaseError($e);
                if (self::isConnectFailure($e)) {
                    // The server is down: record it, but do NOT spend two
                    // connect timeouts per call by reconnecting here. The
                    // caller's backoff is what makes progress.
                    $this->noteLoss($e);
                }
                return false;
            }
            $this->noteLoss($e);
            return $this->reconnect($e);
        }
    }

    /**
     * Drop the current handle and build a fresh one.
     *
     * Never throws: returns false when the server is still unreachable
     * (or when the wrapper has no DSN to rebuild from), leaving the
     * wrapper in a "no handle" state so the NEXT call retries the
     * connection instead of reusing a socket that is known to be dead.
     */
    public function reconnect(?PDOException $cause = null): bool
    {
        if ($this->dsn === '') {
            // fromPdo() without a DSN: the handle cannot be rebuilt. Keep
            // it so callers keep receiving the original PDOException
            // rather than a RuntimeException from connect().
            Diagnostics::recordDatabaseError($cause ?? new \RuntimeException('reconnect without DSN'));
            return false;
        }
        $this->pdo = null;
        try {
            $this->pdo = $this->connect();
        } catch (Throwable $e) {
            Diagnostics::recordDatabaseError($e);
            return false;
        }
        $this->reconnects++;
        $at = gmdate('Y-m-d\TH:i:s\Z');
        DbResilienceJournal::recordReconnect($at, $this->lastErrorMessage);
        Logger::info('db.reconnected', [
            'reconnects' => $this->reconnects,
            'losses'     => $this->losses,
        ]);
        $this->notify('db.reconnected', [
            'reconnects'  => $this->reconnects,
            'losses'      => $this->losses,
            'last_error'  => $this->lastErrorMessage,
        ]);
        return true;
    }

    /**
     * Run a write/update closure with one transparent reconnect on a
     * lost connection. The closure receives the live PDO and must be
     * idempotent — typically a single INSERT/UPDATE/SELECT with
     * prepared parameters.
     *
     * A second consecutive loss is re-raised: a genuine outage must
     * surface fast so the caller can decide to back off, rather than
     * blocking a request behind a retry storm.
     *
     * The handle is resolved OUTSIDE the retry block on purpose. A
     * failure to CONNECT is not a retryable event: the server is down,
     * so reconnecting here would only pay the connect timeout a second
     * time — measured at ~2s per refused connect on the reference host,
     * i.e. every single statement would cost double for the whole
     * outage. Connect failures propagate to the caller, which is
     * expected to back off ({@see ensureConnected()} +
     * {@see isConnectFailure()}).
     *
     * @template T
     * @param  callable(PDO): T $fn
     * @return T
     */
    public function run(callable $fn): mixed
    {
        $pdo = $this->pdo();
        try {
            return $fn($pdo);
        } catch (PDOException $e) {
            if (!self::isGoneAway($e)) {
                throw $e;
            }
            $this->noteLoss($e);
            if (!$this->reconnect($e)) {
                // No DSN, or the server went away again. Re-raise the
                // ORIGINAL driver error so callers that catch
                // PDOException keep working unchanged.
                throw $e;
            }
            $this->retries++;
            try {
                return $fn($this->pdo());
            } catch (PDOException $e2) {
                $this->noteLoss($e2);
                // The retry lost the NEW connection too — the outage is
                // ongoing. Clear the handle so the next call reconnects
                // instead of writing into a dead socket.
                if (self::isConnectionError($e2)) {
                    $this->pdo = null;
                }
                throw $e2;
            }
        }
    }

    /**
     * Did the error look like a connection we can retry on a new handle?
     *
     * This answers the narrow question `run()` and `ensureConnected()`
     * need: "was an ESTABLISHED connection lost?" A server that refuses
     * the connection in the first place is deliberately NOT included —
     * reconnecting there would only pay the connect timeout a second
     * time. Classify those with {@see isConnectFailure()} and back off.
     */
    public static function isGoneAway(PDOException $e): bool
    {
        // Mutually exclusive with isConnectFailure() on purpose. A
        // refused connect reads "No connection could be made because the
        // target machine actively refused it", which contains a retryable
        // phrase — but the server was never reached, so retrying is
        // useless and expensive.
        if (self::isConnectFailure($e)) {
            return false;
        }
        return self::matchesErrorClass($e, self::GONE_DRIVER_CODES, self::GONE_SQLSTATES, self::GONE_NEEDLES)
            || str_contains($e->getMessage(), '2006')
            || str_contains($e->getMessage(), '2013')
            || str_contains($e->getMessage(), '1053');
    }

    /**
     * Could the connection not be ESTABLISHED at all (server down, host
     * wrong, network gone)? Retrying immediately cannot help; the caller
     * is expected to wait and try again later.
     */
    public static function isConnectFailure(PDOException $e): bool
    {
        return self::matchesErrorClass($e, self::CONNECT_DRIVER_CODES, self::CONNECT_SQLSTATES, self::CONNECT_NEEDLES);
    }

    /**
     * Any connection-class fault, retryable or not. Use this for
     * CLASSIFICATION (should this job be requeued rather than failed?
     * should this write failure be reported as an infrastructure
     * problem?), and the narrower predicates for deciding whether an
     * immediate reconnect is worth attempting.
     */
    public static function isConnectionError(PDOException $e): bool
    {
        return self::isGoneAway($e) || self::isConnectFailure($e);
    }

    /**
     * Shared matcher for the code/sqlstate/needle triples.
     *
     * @param list<int>    $driverCodes
     * @param list<string> $sqlStates
     * @param list<string> $needles
     */
    private static function matchesErrorClass(PDOException $e, array $driverCodes, array $sqlStates, array $needles): bool
    {
        $msg = $e->getMessage();

        // Driver code, when the driver supplies it. Preferred over
        // message sniffing because a query can legitimately contain a
        // four-digit number.
        $driverCode = $e->errorInfo[1] ?? null;
        if (is_int($driverCode) && in_array($driverCode, $driverCodes, true)) {
            return true;
        }
        $sqlState = $e->errorInfo[0] ?? null;
        if (is_string($sqlState) && in_array(strtoupper($sqlState), $sqlStates, true)) {
            return true;
        }

        $needle = strtolower($msg);
        foreach ($needles as $n) {
            if (str_contains($needle, $n)) {
                return true;
            }
        }
        // `SQLSTATE[08001]` / `SQLSTATE[08S01]` embedded in the text. The
        // class-08 subcodes are alphanumeric (`S` is not a hex digit),
        // hence [0-9A-Z] rather than [0-9A-F].
        if (preg_match('/SQLSTATE\[(08[0-9A-Z]{3})\]/i', $msg, $m) === 1) {
            return in_array(strtoupper($m[1]), $sqlStates, true);
        }
        return false;
    }

    /** Successful reconnects performed by this instance. */
    public function reconnects(): int
    {
        return $this->reconnects;
    }

    /** Connection-loss errors observed by this instance. */
    public function losses(): int
    {
        return $this->losses;
    }

    /** UTC ISO-8601 stamp of the most recent connection loss, if any. */
    public function lastErrorAt(): ?string
    {
        return $this->lastErrorAt;
    }

    /** Sanitized message of the most recent connection loss, if any. */
    public function lastErrorMessage(): ?string
    {
        return $this->lastErrorMessage;
    }

    /**
     * @return array{reconnects:int,retries:int,losses:int,connected:bool,
     *               dsn_configured:bool,last_error_at:?string,last_error:?string}
     */
    public function stats(): array
    {
        return [
            'reconnects'     => $this->reconnects,
            'retries'        => $this->retries,
            'losses'         => $this->losses,
            'connected'      => $this->pdo !== null,
            'dsn_configured' => $this->dsn !== '',
            'last_error_at'  => $this->lastErrorAt,
            'last_error'     => $this->lastErrorMessage,
        ];
    }

    /**
     * Record a recognised connection loss: bump the counter, remember
     * the error for the diagnostic report, and tell every observer.
     */
    private function noteLoss(PDOException $e): void
    {
        $this->losses++;
        $this->lastErrorAt      = gmdate('Y-m-d\TH:i:s\Z');
        $this->lastErrorMessage = Logger::sanitizeScalar($e->getMessage());
        Diagnostics::recordDatabaseError($e);
        $context = $this->transitionContext($e);
        DbResilienceJournal::recordLoss($context);
        Logger::warn('db.lost', $context);
        $this->notify('db.lost', $context);
    }

    /**
     * @return array{class:string,message:string,sqlstate:?string,driver_code:?int,
     *                lost_at:?string,reconnects:int,losses:int}
     */
    private function transitionContext(PDOException $e): array
    {
        $sqlState   = $e->errorInfo[0] ?? null;
        $driverCode = $e->errorInfo[1] ?? null;
        return [
            'class'       => (new \ReflectionClass($e))->getShortName(),
            'message'     => (string)$this->lastErrorMessage,
            'sqlstate'    => is_string($sqlState) ? $sqlState : null,
            'driver_code' => is_int($driverCode) ? $driverCode : null,
            'lost_at'     => $this->lastErrorAt,
            'reconnects'  => $this->reconnects,
            'losses'      => $this->losses,
        ];
    }

    /** @param array<string,mixed> $context */
    private function notify(string $event, array $context): void
    {
        foreach ($this->observers as $observer) {
            try {
                $observer($event, $context);
            } catch (Throwable) {
                // Observability must never break the query path.
            }
        }
    }

    private function connect(): PDO
    {
        if ($this->dsn === '') {
            throw new \RuntimeException(
                'Database has no DSN — fromPdo() handle was lost and cannot be re-created.'
            );
        }
        try {
            $pdo = new PDO($this->dsn, $this->user, $this->password, $this->options);
            Diagnostics::recordDatabaseError(null);
            return $pdo;
        } catch (PDOException $e) {
            Diagnostics::recordDatabaseError($e);
            throw $e;
        }
    }
}
