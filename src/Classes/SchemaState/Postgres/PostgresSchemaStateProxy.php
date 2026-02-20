<?php

namespace Cybex\Protector\Classes\SchemaState\Postgres;

use Illuminate\Database\Connection;

/**
 * This is a proxy to the PostgresSchemaState class which allows us to override methods to match our own requirements.
 * Unfortunately, the class is not bound to the IOC container and thus cannot be switched out at framework level.
 * However, you may extend this proxy, and override its app container binding with your custom implementation.
 */
class PostgresSchemaStateProxy extends AbstractPostgresSchemaStateProxy
{
    /**
     * {@inheritDoc}
     */
    public function dump(Connection $connection, $path): void
    {
        $this->schemaState->makeProcess($this->baseDumpCommand() . ' > ' . $path)
            ->mustRun(
                $this->schemaState->output,
                array_merge($this->baseVariables($this->schemaState->connection->getConfig()
                ), [
                    'LARAVEL_LOAD_PATH' => $path,
                ]));
    }

    public function getParameters(): array
    {
        return [
            ...$this->getBaseParameters(),
            ...array_keys(array_filter($this->getConditionalParameters())),
        ];
    }

    public function getBaseParameters(): array
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
        return [
            '--create' => $this->protector->shouldCreateDb(),
            '--clean' => $this->protector->shouldCreateDb() && $this->protector->shouldDropDb(),
            '--verbose' => $this->protector->shouldDumpComments(),
            '--schema-only' => !$this->protector->shouldDumpData(),
            '--no-tablespaces' => !$this->protector->shouldUseTablespaces(),
        ];
    }

    /**
     * Get the base dump command as a string.
     */
    protected function baseDumpCommand(): string
    {
        return 'pg_dump ' . implode(' ', $this->getParameters());
    }
}
