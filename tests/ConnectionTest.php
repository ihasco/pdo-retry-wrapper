<?php

namespace Tests;

use PDO;
use Throwable;
use PDOException;
use PDOStatement;
use BadMethodCallException;
use Tests\Database\Migrator;
use PHPUnit\Framework\TestCase;
use iHasco\PdoRetryWrapper\Connection;
use Tests\Database\PDOExceptionThrower;
use iHasco\PdoRetryWrapper\ConnectionException;

class ConnectionTest extends TestCase
{
    /**
    * @test
    */
    public function it_instantiates()
    {
        $db = $this->realConnection();
        $this->assertInstanceOf(Connection::class, $db);
    }

    /**
     * @test
     */
    public function it_returns_a_pdo_compatible_response_on_query()
    {
        $result = $this->realConnection()
                       ->runQuery('select * from users');
        $this->assertInstanceOf(PDOStatement::class, $result);
        $users = $result->fetchAll();
        $this->assertCount(2, $users);
        $this->assertSame('two@example.com', $users[1]['email']);
    }

    /**
     * @test
     */
    public function it_throws_a_pdo_exception_on_bad_query()
    {
        $this->expectException(PDOException::class);
        $result = $this->realConnection()
                       ->runQuery('select * from notatable');
    }

    /**
     * @test
     */
    public function it_throws_a_connection_exception_on_connection_error()
    {
        $expectedException = 'server has gone away';
        $this->expectException(ConnectionException::class);
        $this->expectExceptionMessage($expectedException);
        $result = $this->mockedConnection($expectedException)
                        ->runQuery('select * from users');
    }

    /**
     * @test
     */
    public function it_throws_a_connection_exception_on_query_run()
    {
        $expectedException = 'SQLSTATE[HY000] [2002] Connection timed out';
        $this->expectException(ConnectionException::class);
        $this->expectExceptionMessage($expectedException);
        $result = $this->mockedConnection(null, $expectedException)
        ->runQuery('select * from users');
    }

    /**
     * @test
     */
    public function connection_exceptions_run_callbacks()
    {
        $_ENV['marker'] = 'original';
        $callBack = function (Throwable $e) {
            $_ENV['marker'] = 'edited to '.$e->getMessage();
        };

        try {
            $expectedException = 'server has gone away';
            $this->mockedConnection($expectedException, null, $callBack)
            ->runQuery('select * from users');
            $this->assertTrue(false);
        } catch (ConnectionException $e) {
            $this->assertSame('edited to server has gone away', $_ENV['marker']);
        }
    }

    /**
     * @test
     */
    public function connection_failures_are_retried_up_to_the_limit()
    {
        try {
            $this->mockedConnection(null, 'server has gone away')
            ->runQuery('select * from users');
            $this->assertTrue(false);
        } catch (ConnectionException $e) {
            $this->assertSame(3, $e->getAttempts());
        }
    }

    /**
    * @test
    */
    public function adusted_max_attempts_value_is_applied()
    {
        try {
            $conn = $this->mockedConnection(null, 'server has gone away');
            $conn->setMaxAttempts(5);
            $conn->runQuery('select * from users');
            $this->assertTrue(false);
        } catch (ConnectionException $e) {
            $this->assertSame(5, $e->getAttempts());
        }
    }

    /**
     * @test
     */
    public function does_not_reconnect_if_transaction_active()
    {
        $connection = $this->mockedConnection(null, 'server has gone away');
        $connection->setMaxAttempts(5);
        $connection->beginTransaction();
        
        try {
            $connection->runQuery('select * from users');
        } catch (ConnectionException $exception) {
            $this->assertSame(1, $exception->getAttempts());
        }
    }

    /**
     * @test
     *
     * If an error occurs during a transaction, an exception will be thrown.
     * However, once the exception has been handled, it's not impossible that
     * more queries may be executed. To that end, the failure of a transaction
     * should not interfere with any future queries.
     */
    public function transaction_failure_does_not_affect_future_queries()
    {
        $mock = new PDOExceptionThrower('sqlite::memory:');

        $connection = new Connection(fn () => $mock);

        $connection->setMaxAttempts(5);

        // First we'll initiate a transaction - which should fail.

        try {
            $mock->throwOnQuery('(not a connection issue)');
            $connection->beginTransaction();
            $connection->runQuery('select * from users');
            $this->assertTrue(false, 'runQuery did not throw an exception');
        } catch (PDOException $exception) {
            // (We just want to suppress this.)
        }
        
        // Now we'll try another query - which should retry as usual.

        try {
            $mock->throwOnQuery('server has gone away');
            $connection->runQuery('select * from users');
        } catch (ConnectionException $exception) {
            $this->assertSame(5, $exception->getAttempts());
        }
    }

    /**
    * @test
    */
    public function it_implements_pdo_helpers()
    {
        $pdo = $this->realConnection();
        $this->assertSame('00000', $pdo->errorCode());

        $this->assertIsArray($pdo->errorInfo());
        $this->assertSame('sqlite', $pdo->getAttribute(PDO::ATTR_DRIVER_NAME));

        $statement = $pdo->prepare('UPDATE users set email = ? where id = ?');
        $this->assertInstanceOf(PDOStatement::class, $statement);

        $this->assertIsString($pdo->quote('Some O\'Thing'));
        $pdo->setAttribute(PDO::ATTR_TIMEOUT, 10);

        $pdo->exec('insert into users(email) values("three@example.com")');
        $insertId = $pdo->lastInsertId();
        $this->assertNotEmpty($insertId);
    }

    /**
     * @test
     */
    public function it_implements_the_transaction_interface()
    {
        $sql = 'insert into users(email) values("four@example.com")';
        $pdo = $this->realConnection();
        $pdo->beginTransaction();
        $pdo->exec($sql);
        $this->assertTrue($pdo->inTransaction());
        $pdo->rollBack();
        $this->assertFalse($pdo->inTransaction());
        $result = $pdo->runQuery('select * from users where email = ?', ['four@example.com'])->fetch();
        $this->assertEmpty($result);

        $pdo->beginTransaction();
        $pdo->exec($sql);
        $this->assertTrue($pdo->inTransaction());
        $pdo->commit();
        $this->assertFalse($pdo->inTransaction());

        $result = $pdo->runQuery('select * from users where email = ?', ['four@example.com'])->fetch();
        $this->assertNotEmpty($result);
    }

    /**
     * @test
     */
    public function it_does_not_impelment_direct_queries()
    {
        $pdo = $this->realConnection();
        $this->expectException(BadMethodCallException::class);
        $pdo->query('select * from users');
    }

    private function realConnection($database = 'sqlite::memory:')
    {
        return new Connection(
            (new Migrator)($database)
        );
    }

    private function mockedConnection($connectionMessage, $queryMessage = null, $callback = null)
    {
        $mock = new PDOExceptionThrower('sqlite::memory:');
        if ($connectionMessage) {
            $mock->throwOnConnection($connectionMessage);
        }
        if ($queryMessage) {
            $mock->throwOnQuery($queryMessage);
        }
        return new Connection(function () use ($mock) {
            return $mock;
        }, $callback);
    }
}
