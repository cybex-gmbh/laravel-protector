<?php

namespace Cybex\Protector\Classes\SchemaState\Postgres;

use Cybex\Protector\Protector;
use Illuminate\Database\Connection;
use Illuminate\Database\Schema\PostgresSchemaState;

/**
 * This abstract class provides proxies to protected methods within the related MySqlSchemaState.
 */
abstract class AbstractPostgresSchemaStateProxy extends PostgresSchemaState
{
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

    /**
     * Get the base dump command as a string.
     */
    protected function baseDumpCommand(): string
    {
        return 'pg_dump ' . implode(' ', $this->getBaseDumpArguments());
    }

    /**
     * Get the required base dump arguments as an array.
     */
    protected function getBaseDumpArguments(): array
    {
        return [
            '--no-owner',                               // do not output commands to set ownership of objects to match the original database.
            '--no-acl',                                 // do not output commands to set access privileges of objects to match the original database.
            '--host="${:LARAVEL_LOAD_HOST}"',
            '--port="${:LARAVEL_LOAD_PORT}"',
            '--username="${:LARAVEL_LOAD_USER}"',
            '--dbname="${:LARAVEL_LOAD_DATABASE}"',
        ];
    }

    public function getConditionalParameters(): array
    {
        return [];
    }
}
