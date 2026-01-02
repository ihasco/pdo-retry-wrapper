<?php

namespace Tests\Database;

use PDOException;
use PDOStatement;

class StatementExceptionThrower extends PDOStatement
{
    private ?string $queryException;

    public function __construct(string $queryException = null)
    {
        if ($queryException) {
            $this->queryException = $queryException;
        }
    }

    #[\ReturnTypeWillChange]
    public function execute($bindings = null)
    {
        throw new PDOException($this->queryException);
    }
}
