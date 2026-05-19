<?php

namespace Cybex\Protector\Classes\SchemaState\Postgres;

use Cybex\Protector\Contracts\ProtectorConfigContract;
use Cybex\Protector\Contracts\SchemaStateProxyContract;
use Illuminate\Database\Connection;
use Illuminate\Database\Schema\PostgresSchemaState;

/**
 * This abstract class provides proxies to protected methods within the related PostgresSchemaState.
 */
abstract class AbstractPostgresSchemaStateProxy extends PostgresSchemaState implements SchemaStateProxyContract
{
    public function __construct(protected PostgresSchemaState $schemaState, protected ProtectorConfigContract $config)
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
