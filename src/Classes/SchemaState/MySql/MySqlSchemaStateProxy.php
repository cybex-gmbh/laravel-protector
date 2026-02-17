<?php

namespace Cybex\Protector\Classes\SchemaState\MySql;

use Illuminate\Database\Connection;

/**
 * This is a proxy to the MySqlSchemaState class which allows us to override methods to match our own requirements.
 * Unfortunately, the class is not bound to the IOC container and thus cannot be switched out at framework level.
 * However, you may extend this proxy, and override its app container binding with your custom implementation.
 */
class MySqlSchemaStateProxy extends AbstractMySqlSchemaStateProxy
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
                ['LARAVEL_LOAD_PATH' => $path,]
            )
        );

        if ($this->protector->shouldRemoveAutoIncrementingState()) {
            $this->removeAutoIncrementingState($path);
        }
    }

    /**
     * Get the dump command for MySQL as a string.
     */
    protected function getCommandString(): string
    {
        $command = 'mysqldump ' . $this->schemaState->connectionString() . ' ';

        $parameters = [
            '--add-locks',
            '--routines',
            '--tz-utc',
            '--column-statistics=0',
            '--result-file="${:LARAVEL_LOAD_PATH}"',
            '--max-allowed-packet=' . $this->protector->getMaxPacketLength(),
            ...array_keys(array_filter($this->getConditionalParameters())),
            '"${:LARAVEL_LOAD_DATABASE}"',
        ];

        return $command . implode(' ', $parameters);
    }

    public function getConditionalParameters(): array
    {
        return [
            '--set-gtid-purged=OFF' => !$this->schemaState->connection->isMaria(),
            '--no-create-db' => !$this->protector->shouldCreateDb(),
            '--skip-comments' => !$this->protector->shouldDumpComments(),
            '--skip-set-charset' => !$this->protector->shouldDumpCharsets(),
            '--no-data' => !$this->protector->shouldDumpData(),
            '--no-tablespaces' => !$this->protector->shouldUseTablespaces(),
        ];
    }
}
