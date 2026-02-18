<?php

namespace Cybex\Protector\Classes\SchemaState\Postgres;

use Cybex\Protector\Protector;
use Illuminate\Database\Connection;
use Illuminate\Database\Schema\PostgresSchemaState;

/**
 * This abstract class provides proxies to protected methods within the related PostgresSchemaState.
 */
abstract class AbstractPostgresSchemaStateProxy extends PostgresSchemaState
{
    abstract public function getParameters(): array;

    abstract public function getBaseParameters(): array;

    abstract public function getConditionalParameters(): array;

    public function __construct(protected PostgresSchemaState $schemaState, protected Protector $protector)
    {
        parent::__construct($schemaState->connection);
    }

    /**
     * {@inheritDoc}
     */
    public function dump(Connection $connection, $path): void
    {
        $this->schemaState->dump(...func_get_args());
    }

    /**
     * {@inheritDoc}
     */
    public function load($path): void
    {
        $this->schemaState->load(...func_get_args());
    }

    protected function baseVariables(mixed $config): array
    {
        return $this->schemaState->baseVariables(...func_get_args());
    }
}
