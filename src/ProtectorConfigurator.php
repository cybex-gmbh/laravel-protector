<?php

namespace Cybex\Protector;

use Cybex\Protector\Contracts\ProtectorConfigContract;
use Cybex\Protector\Contracts\ProtectorConfiguratorContract;
use Cybex\Protector\Exceptions\InvalidConnectionException;

class ProtectorConfigurator extends AbstractProtectorConfig implements ProtectorConfiguratorContract
{
    public function createProtector(): Protector
    {
        return Protector::withConfig(app()->makeWith(ProtectorConfigContract::class, get_object_vars($this)));
    }

    /**
     * @throws InvalidConnectionException
     */
    public function setConnectionName(string $connectionName): static
    {
        if ((config(sprintf('database.connections.%s', $connectionName), false)) === false) {
            throw new InvalidConnectionException('Invalid database configuration');
        }

        $this->connectionName = $connectionName;

        return $this;
    }

    public function setAuthToken(string $authToken): static
    {
        $this->authToken = $authToken;

        return $this;
    }


    /** {@inheritDoc} */
    public function setBasicAuthCredentials(string $credentials): static
    {
        $this->basicAuth = $credentials;

        return $this;
    }

    public function setPrivateKey(string $privateKey): static
    {
        $this->privateKey = $privateKey;

        return $this;
    }


    public function setDumpEndpointUrl(string $dumpEndpointUrl): static
    {
        $this->dumpEndpointUrl = $dumpEndpointUrl;

        return $this;
    }


    /** {@inheritDoc} */
    public function setMaxPacketLength(string $maxPacketLength): static
    {
        $this->maxPacketLength = $maxPacketLength;

        return $this;
    }

    public function setChunkSize(int $chunkSize): static
    {
        $this->chunkSize = $chunkSize;

        return $this;
    }


    public function setHttpTimeout(int $httpTimeout): static
    {
        $this->httpTimeout = $httpTimeout;

        return $this;
    }


    public function setMetadataProviders(array $metadataProviders): static
    {
        $this->metadataProviders = $metadataProviders;

        return $this;
    }


    public function withoutAutoIncrementingState(): static
    {
        $this->removeAutoIncrementingState = true;

        return $this;
    }

    public function withoutCharsets(): static
    {
        $this->dumpCharsets = false;

        return $this;
    }

    public function withoutComments(): static
    {
        $this->dumpComments = false;

        return $this;
    }

    public function withCreateDb(): static
    {
        $this->createDb = true;

        return $this;
    }

    public function withoutData(): static
    {
        $this->dumpData = false;

        return $this;
    }

    /** {@inheritDoc} */
    public function withoutDropDb(): static
    {
        $this->dropDb = false;

        return $this;
    }

    public function withTablespaces(): static
    {
        $this->tablespaces = true;

        return $this;
    }
}
