<?php

namespace Cybex\Protector\Classes\SchemaState\MariaDb;

use Cybex\Protector\Contracts\ProtectorConfigContract;
use Cybex\Protector\Contracts\SchemaStateProxyContract;
use Illuminate\Database\Connection;
use Illuminate\Database\Schema\MariaDbSchemaState;
use Symfony\Component\Process\Process;

/**
 * This abstract class provides proxies to protected methods within the related MariaDbSchemaState.
 */
abstract class AbstractMariaDbSchemaStateProxy extends MariaDbSchemaState implements SchemaStateProxyContract
{
    public function __construct(protected MariaDbSchemaState $schemaState, protected ProtectorConfigContract $config)
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
