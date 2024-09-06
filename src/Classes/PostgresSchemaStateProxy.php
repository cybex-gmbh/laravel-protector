<?php

namespace Cybex\Protector\Classes;

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
    public function dump(Connection $connection, $path)
    {
        $this->makeProcess($this->baseDumpCommand() . ' > ' . $path)
            ->mustRun(
                $this->output,
                array_merge($this->baseVariables($this->connection->getConfig()
                ), [
                    'LARAVEL_LOAD_PATH' => $path,
                ]));
    }

    /**
     * {@inheritDoc}
     */
    public function load($path)
    {
        $this->schemaState->load(...func_get_args());
    }

    protected function getBaseDumpArguments(): array
    {
        $conditionalArguments = [
            '--create' => $this->protector->shouldCreateDb(),
            '--clean' => $this->protector->shouldCreateDb() && $this->protector->shouldDropDb(),
            '--verbose' => $this->protector->shouldDumpComments(),
            '--schema-only' => !$this->protector->shouldDumpData(),
        ];

        return [
            ...parent::getBaseDumpArguments(),
            ...array_keys(array_filter($conditionalArguments)),
        ];
    }
}
