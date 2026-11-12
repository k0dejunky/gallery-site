<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Core\Database;
use PDOException;
use PHPUnit\Framework\TestCase;

/**
 * The site must tell "MySQL is restarting" apart from "the query is wrong".
 *
 * On 2026-11-12 unattended-upgrades stopped mysqld for 13 seconds. Six
 * requests hit the gap, were classified as application errors, answered 500
 * and landed in the fatal error card. These cases pin the classification that
 * makes them retryable (503 / one more attempt) instead.
 */
final class DatabaseAvailabilityTest extends TestCase
{
    private function pdo(string $message, array $errorInfo = []): PDOException
    {
        $e = new PDOException($message);
        $e->errorInfo = $errorInfo;

        return $e;
    }

    public function testConnectionRefusedDuringARestartIsUnavailable(): void
    {
        $e = $this->pdo('SQLSTATE[HY000] [2002] Connection refused', ['HY000', 2002, 'Connection refused']);

        $this->assertTrue(Database::isUnavailable($e));
        $this->assertTrue(Database::isLostConnection($e), 'a refused connection may be retried once');
    }

    public function testServerShutdownInProgressIsUnavailable(): void
    {
        $e = $this->pdo(
            'SQLSTATE[08S01]: Communication link failure: 1053 Server shutdown in progress',
            ['08S01', 1053, 'Server shutdown in progress']
        );

        $this->assertTrue(Database::isUnavailable($e));
        $this->assertTrue(Database::isLostConnection($e));
    }

    public function testGoneAwayAndLostConnectionStayRetryable(): void
    {
        foreach ([2006, 2013] as $code) {
            $e = $this->pdo('SQLSTATE[HY000] [' . $code . '] gone', ['HY000', $code, 'gone']);
            $this->assertTrue(Database::isUnavailable($e), 'code ' . $code);
            $this->assertTrue(Database::isLostConnection($e), 'code ' . $code);
        }
    }

    public function testDeadlockIsRetryable(): void
    {
        $e = $this->pdo('SQLSTATE[40001]: Serialization failure: 1213 Deadlock found', ['40001', 1213, 'Deadlock']);

        $this->assertTrue(Database::isLostConnection($e));
    }

    public function testAnOrdinarySqlErrorIsNotUnavailable(): void
    {
        $e = $this->pdo(
            "SQLSTATE[42S02]: Base table or view not found: 1146 Table 'gallery_mvc.nope' doesn't exist",
            ['42S02', 1146, "Table doesn't exist"]
        );

        $this->assertFalse(Database::isUnavailable($e));
        $this->assertFalse(Database::isLostConnection($e), 'a bad query must not be retried');
    }

    public function testANonPdoThrowableIsNotUnavailable(): void
    {
        $this->assertFalse(Database::isUnavailable(new \RuntimeException('boom')));
        $this->assertFalse(Database::isLostConnection(new \RuntimeException('boom')));
    }

    public function testTheMessageAloneCanMarkARestart(): void
    {
        // Some drivers report the message without a usable errorInfo.
        $this->assertTrue(Database::isUnavailable($this->pdo('SQLSTATE[HY000] [2002] Connection refused')));
        $this->assertTrue(Database::isUnavailable($this->pdo('MySQL server has gone away')));
        $this->assertTrue(Database::isUnavailable($this->pdo('Server shutdown in progress')));
    }
}
