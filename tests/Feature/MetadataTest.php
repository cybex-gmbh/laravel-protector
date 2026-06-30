<?php

namespace Cybex\Protector\Tests\Feature;

use Cybex\Protector\Classes\Metadata\Providers\DatabaseMetadataProvider;
use Cybex\Protector\Classes\Metadata\Providers\EnvMetadataProvider;
use Cybex\Protector\Classes\Metadata\Providers\GitMetadataProvider;
use Cybex\Protector\Classes\Metadata\Providers\JsonFileMetadataProvider;
use Cybex\Protector\Contracts\MetadataProviderContract;
use Cybex\Protector\Contracts\ProtectorConfigContract;
use Cybex\Protector\Contracts\ProtectorConfiguratorContract;
use Cybex\Protector\Tests\TestCase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\Test;
use TypeError;

class MetadataTest extends TestCase
{
    protected const string METADATA_PROVIDER_CONFIG_KEY = 'protector.dump.metadata.providers';
    protected const string METADATA_ENV_VALUE_CONFIG_KEY = 'protector.dump.metadata.envValue';
    protected const string METADATA_JSON_FILE_PATH_CONFIG_KEY = 'protector.dump.metadata.jsonFilePath';

    #[Test]
    public function canCreateDumpMetadata(): void
    {
        $dumpDate = now();

        Carbon::setTestNow($dumpDate);

        $metadata = $this->protector->metadata();

        $this->assertIsArray($metadata);
        $this->assertEquals($dumpDate, $metadata['database']['dumpedAtDate']);
    }

    #[Test]
    public function canConfigureProtectorInstanceToUseMetadataProviders(): void
    {
        Config::set(static::METADATA_PROVIDER_CONFIG_KEY, []);

        $this->protector = app(ProtectorConfiguratorContract::class)->setMetadataProviders([GitMetadataProvider::class])->makeProtector();

        $this->assertContains(GitMetadataProvider::class, $this->runProtectedMethod('getConfig')->getMetadataProviders());
    }

    #[Test]
    public function includesDatabaseMetadataProviderByDefault(): void
    {
        Config::set('protector.dump.metadata.providers', []);

        $this->assertContains(DatabaseMetadataProvider::class, $this->runProtectedMethod('getConfig')->getMetadataProviders());
    }

    #[Test]
    public function includesMetadataIfShouldAppend(): void
    {
        Config::set(static::METADATA_PROVIDER_CONFIG_KEY, [GitMetadataProvider::class]);
        $mock = $this->partialMock(GitMetadataProvider::class, fn($mock) => $mock->shouldReceive('shouldAppend')->andReturn(true));
        // Mocking does not work nicely with app()->makeWith()
        app()->offsetSet(GitMetadataProvider::class, $mock);

        $this->assertArrayHasKey('git', $this->protector->metadata());
    }

    #[Test]
    public function excludesMetadataIfShouldNotAppend(): void
    {
        Config::set(static::METADATA_PROVIDER_CONFIG_KEY, [GitMetadataProvider::class]);
        $mock = $this->partialMock(GitMetadataProvider::class, fn($mock) => $mock->shouldReceive('shouldAppend')->andReturn(false));
        // Mocking does not work nicely with app()->makeWith()
        app()->offsetSet(GitMetadataProvider::class, $mock);

        $this->assertArrayNotHasKey('git', $this->protector->metadata());
    }

    #[Test]
    public function includesGitMetadataIfUnderGitVersionControl(): void
    {
        Config::set(static::METADATA_PROVIDER_CONFIG_KEY, [GitMetadataProvider::class]);
        File::shouldReceive('exists')->with(base_path('.git'))->andReturn(true);

        $this->assertArrayHasKey('git', $this->protector->metadata());
    }

    #[Test]
    public function excludesGitMetadataIfNotUnderGitVersionControl(): void
    {
        Config::set(static::METADATA_PROVIDER_CONFIG_KEY, [GitMetadataProvider::class]);
        File::shouldReceive('exists')->with(base_path('.git'))->andReturn(false);

        $this->assertArrayNotHasKey('git', $this->protector->metadata());
    }

    #[Test]
    public function includesEnvMetadataIfSet(): void
    {
        Config::set(static::METADATA_PROVIDER_CONFIG_KEY, [EnvMetadataProvider::class]);
        Config::set(static::METADATA_ENV_VALUE_CONFIG_KEY, 'test metadata');

        $this->assertArrayHasKey('env', $this->protector->metadata());
        $this->assertEquals('test metadata', $this->protector->metadata()['env']);
    }

    #[Test]
    public function excludesEnvMetadataIfNotSet(): void
    {
        Config::set(static::METADATA_PROVIDER_CONFIG_KEY, [EnvMetadataProvider::class]);
        Config::set(static::METADATA_ENV_VALUE_CONFIG_KEY);

        $this->assertArrayNotHasKey('env', $this->protector->metadata());
    }

    #[Test]
    public function includesJsonFileMetadataIfFileExists(): void
    {
        $filePath = base_path('protector_metadata.json');

        Config::set(static::METADATA_PROVIDER_CONFIG_KEY, [JsonFileMetadataProvider::class]);
        Config::set(static::METADATA_JSON_FILE_PATH_CONFIG_KEY, basename($filePath));

        File::shouldReceive('exists')->with($filePath)->andReturn(true);
        File::shouldReceive('get')->with($filePath)->andReturn(json_encode(['foo' => 'bar']));

        $this->assertArrayHasKey('jsonFile', $this->protector->metadata());
        $this->assertEquals(['foo' => 'bar'], $this->protector->metadata()['jsonFile']);
    }

    #[Test]
    public function excludeJsonFileMetadataIfFileNotExists(): void
    {
        $filePath = base_path('protector_metadata.json');

        Config::set(static::METADATA_PROVIDER_CONFIG_KEY, [JsonFileMetadataProvider::class]);
        Config::set(static::METADATA_JSON_FILE_PATH_CONFIG_KEY, basename($filePath));

        File::shouldReceive('exists')->with($filePath)->andReturn(false);

        $this->assertArrayNotHasKey('jsonFile', $this->protector->metadata());
    }

    #[Test]
    public function canConfigureCustomMetadataProviderWithDependencies(): void
    {
        $metadataProviders = [TestCustomFooMetadataProvider::class];
        Config::set(static::METADATA_PROVIDER_CONFIG_KEY, $metadataProviders);

        $metadata = $this->protector->metadata();

        $this->assertArrayHasKey('custom', $metadata);
        $this->assertEquals($metadataProviders, $metadata['custom']['foo']);
        $this->assertInstanceOf(ProtectorConfigContract::class, app(TestCustomFooMetadataProvider::class)->protectorConfig);
        $this->assertInstanceOf(Config::class, app(TestCustomFooMetadataProvider::class)->config);
    }

    #[Test]
    public function canConfigureMultipleMetadataProviders(): void
    {
        Config::set(static::METADATA_PROVIDER_CONFIG_KEY, [TestCustomFooMetadataProvider::class, GitMetadataProvider::class]);
        File::shouldReceive('exists')->with(base_path('.git'))->andReturn(true);

        $metadata = $this->protector->metadata();

        $this->assertArrayHasKey('database', $metadata);
        $this->assertArrayHasKey('custom', $metadata);
        $this->assertArrayHasKey('git', $metadata);
    }

    #[Test]
    public function failsWhenProviderNotImplementingInterfaceIsConfigured(): void
    {
        $this->expectException(TypeError::class);

        Config::set(static::METADATA_PROVIDER_CONFIG_KEY, [File::class]);

        $this->protector->metadata();
    }

    #[Test]
    public function ensureDuplicateProviderKeysAreMerged(): void
    {
        Config::set(static::METADATA_PROVIDER_CONFIG_KEY, [TestCustomFooMetadataProvider::class, TestCustomBarMetadataProvider::class]);

        $fooMetadataProvider = app(TestCustomFooMetadataProvider::class);
        $barMetadataProvider = app(TestCustomBarMetadataProvider::class);

        $this->assertArrayHasKey($fooMetadataProvider->getKey(), $this->protector->metadata());
        $this->assertArrayHasKey($barMetadataProvider->getKey(), $this->protector->metadata());
        $this->assertArrayHasKey('foo', $this->protector->metadata()[$fooMetadataProvider->getKey()]);
        $this->assertArrayHasKey('bar', $this->protector->metadata()[$barMetadataProvider->getKey()]);
    }
}

class TestCustomFooMetadataProvider implements MetadataProviderContract
{
    public function __construct(public ProtectorConfigContract $protectorConfig, public Config $config)
    {
    }

    public function getKey(): string
    {
        return 'custom';
    }

    public function shouldAppend(): bool
    {
        return true;
    }

    public function getMetadata(): array|string
    {
        return [
            'foo' => $this->config::get('protector.dump.metadata.providers'),
        ];
    }
}

class TestCustomBarMetadataProvider implements MetadataProviderContract
{
    public function __construct()
    {
    }

    public function getKey(): string
    {
        return 'custom';
    }

    public function shouldAppend(): bool
    {
        return true;
    }

    public function getMetadata(): array|string
    {
        return [
            'bar' => 'test',
        ];
    }
}
