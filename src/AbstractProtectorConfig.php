<?php

namespace Cybex\Protector;

abstract class AbstractProtectorConfig
{
    protected string $connectionName;

    protected array|false $connectionConfig;

    protected string $dumpEndpointUrl;

    protected string $authToken;

    protected string $basicAuth;

    protected string $privateKey;

    protected string $maxPacketLength;

    protected int $chunkSize;

    protected int $httpTimeout;

    protected array $metadataProviders;

    /**
     * Defines whether dumps should include a DB creation statement.
     */
    protected bool $createDb = false;

    /**
     * Defines whether existing databases should be dropped before importing a dump.
     * Only works if used together with the $createDb option.
     * (PostgreSQL only, controls the --clean flag)
     */
    protected bool $dropDb = true;

    /**
     * If set to false, the --no-tablespaces dump option will be used.
     */
    protected bool $tablespaces = false;

    /**
     * Specifies whether comments should be added to the dump file.
     */
    protected bool $dumpComments = true;

    /**
     * If false, no SET NAMES statements will be written to the dump.
     */
    protected bool $dumpCharsets = true;

    /**
     * Specifies whether table data should be dumped.
     */
    protected bool $dumpData = true;

    /**
     * If true, the auto-increment state will be stripped from the dump.
     */
    protected bool $removeAutoIncrementingState = false;

    /**
     * Returns a config value for a specific key and checks for Callables.
     */
    protected function getConfigValueForKey(string $key, mixed $default = null): mixed
    {
        $value = config(sprintf('protector.%s', $key), $default);

        return is_callable($value) ? $value() : $value;
    }
}
