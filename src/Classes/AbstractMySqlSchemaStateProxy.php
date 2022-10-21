<?php

namespace Cybex\Protector\Classes;

use Cybex\Protector\Protector;
use Exception;
use Illuminate\Database\Connection;
use Illuminate\Database\Schema\MySqlSchemaState;
use Illuminate\Database\Schema\SchemaState;
use Symfony\Component\Process\Process;

/**
 * This is a proxy class to the MySqlSchemaState class to be able to override methods to match our requirements.
 * Unfortunately, the class can not be switched out on framework level, because it is not pulled from the IOC.
 * However, you may extend this class, and override the app container binding with your own implementation.
 */
abstract class AbstractMySqlSchemaStateProxy extends MySqlSchemaState
{
    public function __construct(protected MySqlSchemaState $schemaState, protected Protector $protector)
    {
    }

    /**
     * @inheritDoc
     */
    public function dump(Connection $connection, $path)
    {
        $this->schemaState->dump(...func_get_args());
    }

    /**
     * @inheritDoc
     */
    public function load($path)
    {
        $this->schemaState->load(...func_get_args());
    }

    protected function executeDumpProcess(Process $process, $output, array $variables): Process
    {
        return $this->schemaState->executeDumpProcess(...func_get_args());
    }

    protected function baseDumpCommand(): string
    {
        return $this->schemaState->baseDumpCommand();
    }

    protected function baseVariables(mixed $config): array
    {
        return $this->schemaState->baseVariables(...func_get_args());
    }

    protected function removeAutoIncrementingState(string $path): void
    {
        $this->schemaState->removeAutoIncrementingState(...func_get_args());
    }

    protected function appendMigrationData(string $path): void
    {
        $this->schemaState->appendMigrationData(...func_get_args());
    }
}
