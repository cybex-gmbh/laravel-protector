<?php

namespace Cybex\Protector\Classes;

use Illuminate\Database\Connection;

/**
 * This is a proxy class to the MySqlSchemaState class which allows overriding methods to match our requirements.
 * Unfortunately, the class can not be switched out on framework level, because it is not pulled from the IOC.
 * However, you may extend this class, and override its app container binding with your own implementation.
 */
class MySqlSchemaStateProxy extends AbstractMySqlSchemaStateProxy
{
    /**
     * @inheritDoc
     */
    public function dump(Connection $connection, $path)
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
     * @inheritDoc
     */
    public function load($path)
    {
        $this->schemaState->load(...func_get_args());
    }

    /**
     * Get the dump command for MySQL as a string.
     */
    protected function getCommandString(): string
    {
        $command = 'mysqldump '.$this->schemaState->connectionString().' ';

        $conditionalParameters = [
            '--set-gtid-purged=OFF' => !$this->schemaState->connection->isMaria(),
            '--no-create-db'        => !$this->protector->shouldCreateDb(),
            '--skip-comments'       => !$this->protector->shouldDumpComments(),
            '--skip-set-charset'    => !$this->protector->shouldDumpCharsets(),
            '--no-data'             => !$this->protector->shouldDumpData(),
        ];

        $parameters = [
            '--add-locks',
            '--routines',
            '--tz-utc',
            '--column-statistics=0',
            '--result-file="${:LARAVEL_LOAD_PATH}"',
            '--max-allowed-packet='.$this->protector->getMaxPacketLength(),
            ...array_keys(array_filter($conditionalParameters)),
            '"${:LARAVEL_LOAD_DATABASE}"',
        ];

        return $command.implode(' ', $parameters);
    }
}
