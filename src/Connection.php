<?php

namespace iHasco\PdoRetryWrapper;

use PDO;
use Closure;
use Exception;
use Throwable;
use PDOStatement;
use BadMethodCallException;
use Illuminate\Database\DetectsLostConnections;

class Connection extends PDO
{
    use DetectsLostConnections;

    private ?PDO $pdo = null;
    private ?Closure $connector = null;
    private int $maxAttempts = 3;
    private int $currentAttempts = 1;
    private ?Closure $exceptionCallback = null;

    protected bool $transactionActive = false;

    public function __construct(Closure $connector, ?Closure $exceptionCallback = null)
    {
        $this->connector = $connector;
        $this->exceptionCallback = $exceptionCallback;
    }

    public function setMaxAttempts(int $value)
    {
        $this->maxAttempts = $value;
    }

    public function runQuery(string $sql, ?array $bindings = null, ?array $options = []): PDOStatement
    {
        $this->currentAttempts = 1;
        $forceReconnect = false;

        while ($this->currentAttempts < $this->maxAttempts) {
            try {
                return $this->connectAndPerformQuery($sql, $bindings, $options, $forceReconnect);
            } catch (Throwable $e) {
                if (!$this->causedByLostConnection($e)) {
                    $this->throw($e);
                }

                if ($this->transactionActive) {
                    break;
                }

                $forceReconnect = true;
                $this->currentAttempts ++;
            }
        }
        return $this->throwConnectionException($e, $sql, $bindings);
    }

    protected function throw(Exception $exception): void
    {
        $this->transactionActive = false;

        throw $exception;
    }

    private function throwConnectionException(Throwable $originalException, string $sql, ?array $bindings)
    {
        $connectionException = new ConnectionException(
            $originalException,
            $this->currentAttempts,
            $sql,
            $bindings
        );
        if ($this->exceptionCallback) {
            call_user_func($this->exceptionCallback, $connectionException);
        }
        $this->throw($connectionException);
    }

    private function connectAndPerformQuery(string $sql, ?array $bindings, ?array $options = [], bool $forceReconnect = false): PDOStatement
    {
        if ($forceReconnect) {
            $this->reconnect();
        } else {
            $this->reconnectIfMissingConnection();
        }
        $query = $this->pdo->prepare($sql, $options);
        $query->execute($bindings);

        return $query;
    }

    private function reconnectIfMissingConnection(): void
    {
        if (!$this->pdo instanceof PDO) {
            $this->reconnect();
        }
    }

    private function reconnect(): void
    {
        $this->pdo = call_user_func($this->connector);
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    }

    private function getPdo(): PDO
    {
        $this->reconnectIfMissingConnection();
        return $this->pdo;
    }

    /**
     * Down here we provide the full interface to PDO so that
     * we can provide this class as a drop-in replacement
     */

    public function beginTransaction(): bool
    {
        $value = $this->getPdo()->beginTransaction();

        $this->transactionActive = true;

        return $value;
    }
    public function commit(): bool
    {
        $value = $this->getPdo()->commit();

        $this->transactionActive = false;

        return $value;
    }
    public function errorCode(): string
    {
        return $this->getPdo()->errorCode();
    }
    public function errorInfo(): array
    {
        return $this->getPdo()->errorInfo();
    }
    #[\ReturnTypeWillChange]
    public function exec($statement)
    {
        return $this->getPdo()->exec($statement);
    }
    #[\ReturnTypeWillChange]
    public function getAttribute($attribute)
    {
        return $this->getPdo()->getAttribute($attribute);
    }
    public function inTransaction(): bool
    {
        return $this->getPdo()->inTransaction();
    }
    #[\ReturnTypeWillChange]
    public function lastInsertId($name = null)
    {
        return $this->getPdo()->lastInsertId($name);
    }
    #[\ReturnTypeWillChange]
    public function prepare($statement, $driver_options = [])
    {
        return $this->getPdo()->prepare($statement, $driver_options);
    }
    #[\ReturnTypeWillChange]
    public function quote($string, $parameter_type = PDO::PARAM_STR)
    {
        return $this->getPdo()->quote($string, $parameter_type);
    }
    public function rollBack(): bool
    {
        $value = $this->getPdo()->rollBack();

        $this->transactionActive = false;

        return $value;
    }
    public function setAttribute($attribute, $value): bool
    {
        return $this->getPdo()->setAttribute($attribute, $value);
    }
    /**
     * I don't need this, so I'm not putting in the legwork
     * Use runQuery() instead
     */
    #[\ReturnTypeWillChange]
    public function query($statement, $fetchMode = null, ...$fetchModeArgs)
    {
        $this->throw(
            new BadMethodCallException('Not implemented')
        );
    }
}
