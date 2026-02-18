<?php

namespace Cybex\Protector\Classes\SchemaState\MySql;

use Cybex\Protector\Protector;
use Illuminate\Database\Connection;
use Illuminate\Database\Schema\MySqlSchemaState;
use Symfony\Component\Process\Process;

/**
 * This abstract class provides proxies to protected methods within the related MySqlSchemaState.
 */
abstract class AbstractMySqlSchemaStateProxy extends MySqlSchemaState
{
    abstract public function getParameters(): array;

    abstract public function getBaseParameters(): array;

    abstract public function getConditionalParameters(): array;

    public function __construct(protected MySqlSchemaState $schemaState, protected Protector $protector)
    {
        parent::__construct($schemaState->connection);
    }

    /**
     * @inheritDoc
     */
    public function dump(Connection $connection, $path): void
    {
        $this->schemaState->dump(...func_get_args());
    }

    /**
     * @inheritDoc
     */
    public function load($path): void
    {
        $this->schemaState->load(...func_get_args());
    }

    protected function executeDumpProcess(Process $process, $output, array $variables, int $depth = 0): Process
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
