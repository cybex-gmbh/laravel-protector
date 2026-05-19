<?php

namespace Cybex\Protector\Contracts;

use Illuminate\Database\Connection;

interface SchemaStateProxyContract
{
    public function load(string $path): void;

    public function dump(Connection $connection, string $path): void;

    public function getParameters(): array;

    public function getBaseParameters(): array;

    public function getConditionalParameters(): array;
}
