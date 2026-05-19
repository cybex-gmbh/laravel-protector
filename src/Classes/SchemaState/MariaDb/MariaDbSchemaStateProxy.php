<?php

namespace Cybex\Protector\Classes\SchemaState\MariaDb;

use Illuminate\Database\Connection;

/**
 * This is a proxy to the MariaDbSchemaState class which allows us to override methods to match our own requirements.
 * Unfortunately, the class is not bound to the IOC container and thus cannot be switched out at framework level.
 * However, you may extend this proxy, and override its app container binding with your custom implementation.
 */
class MariaDbSchemaStateProxy extends AbstractMariaDbSchemaStateProxy
{
    /**
     * @inheritDoc
     */
    public function dump(Connection $connection, $path): void
    {
        $this->executeDumpProcess(
            $this->schemaState->makeProcess(
                $this->getCommandString()
            ),
            $this->schemaState->output,
            array_merge(
                $this->baseVariables($this->schemaState->connection->getConfig()),
                ['LARAVEL_LOAD_PATH' => $path]
            )
        );

        if ($this->config->shouldRemoveAutoIncrementingState()) {
            $this->removeAutoIncrementingState($path);
        }
    }

    /**
     * Get the dump command for MariaDB as a string.
     */
    protected function getCommandString(): string
    {
        // Laravel added a required parameter in v12.52.0.
        $clientVersion = method_exists($this, 'detectClientVersion') ? $this->detectClientVersion() : [];

        $command = 'mariadb-dump ' . $this->schemaState->connectionString($clientVersion) . ' ';

        return $command . implode(' ', $this->getParameters()) . ' --databases "${:LARAVEL_LOAD_DATABASE}"';
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
            '--add-locks',
            '--routines',
            '--tz-utc',
            '--result-file="${:LARAVEL_LOAD_PATH}"',
            '--max-allowed-packet=' . $this->config->getMaxPacketLength(),
        ];
    }

    public function getConditionalParameters(): array
    {
        return [
            '--no-create-db' => !$this->config->shouldCreateDb(),
            '--skip-comments' => !$this->config->shouldDumpComments(),
            '--skip-set-charset' => !$this->config->shouldDumpCharsets(),
            '--no-data' => !$this->config->shouldDumpData(),
            '--no-tablespaces' => !$this->config->shouldUseTablespaces(),
        ];
    }
}
