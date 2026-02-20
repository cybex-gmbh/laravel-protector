<?php

namespace Cybex\Protector\Tests\Feature;

use Cybex\Protector\Classes\Metadata\Providers\DatabaseMetadataProvider;
use Cybex\Protector\Classes\Metadata\Providers\EnvMetadataProvider;
use Cybex\Protector\Classes\Metadata\Providers\GitMetadataProvider;
use Cybex\Protector\Classes\Metadata\Providers\JsonFileMetadataProvider;
use Cybex\Protector\Contracts\MetadataProvider;
use Cybex\Protector\Protector;
use Cybex\Protector\Tests\TestCase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\File;
use TypeError;

class MetadataTest extends TestCase
{
    const METADATA_PROVIDER_CONFIG_KEY = 'protector.metadata.providers';
    const METADATA_ENV_VALUE_CONFIG_KEY = 'protector.metadata.envValue';
    const METADATA_JSON_FILE_PATH_CONFIG_KEY = 'protector.metadata.jsonFilePath';

    /**
     * @test
     */
    public function canCreateDumpMetadata()
    {
        $dumpDate = now();

        Carbon::setTestNow($dumpDate);

        $metadata = $this->protector->getMetadata();

        $this->assertIsArray($metadata);
        $this->assertEquals($dumpDate, $metadata['database']['dumpedAtDate']);
    }

    /**
     * @test
     */
    public function canConfigureProtectorInstanceToUseMetadataProviders()
    {
        Config::set(static::METADATA_PROVIDER_CONFIG_KEY, []);

        $this->protector->withMetadataProviders([GitMetadataProvider::class]);

        $this->assertContains(GitMetadataProvider::class, $this->protector->getMetadataProviders());
    }

    /**
     * @test
     */
    public function includesDatabaseMetadataProviderByDefault()
    {
        Config::set('protector.metadataProviders', []);

        $this->assertContains(DatabaseMetadataProvider::class, $this->protector->getMetadataProviders());
    }

    /**
     * @test
     */
    public function includesMetadataIfShouldAppend()
    {
        Config::set(static::METADATA_PROVIDER_CONFIG_KEY, [GitMetadataProvider::class]);
        $this->partialMock(GitMetadataProvider::class, fn($mock) => $mock->shouldReceive('shouldAppend')->andReturn(true));

        $this->assertArrayHasKey('git', $this->protector->getMetadata());
    }

    /**
     * @test
     */
    public function excludesMetadataIfShouldNotAppend()
    {
        Config::set(static::METADATA_PROVIDER_CONFIG_KEY, [GitMetadataProvider::class]);
        $this->partialMock(GitMetadataProvider::class, fn($mock) => $mock->shouldReceive('shouldAppend')->andReturn(false));

        $this->assertArrayNotHasKey('git', $this->protector->getMetadata());
    }

    /**
     * @test
     */
    public function includesGitMetadataIfUnderGitVersionControl()
    {
        Config::set(static::METADATA_PROVIDER_CONFIG_KEY, [GitMetadataProvider::class]);
        File::shouldReceive('exists')->with(base_path('.git'))->andReturn(true);

        $this->assertArrayHasKey('git', $this->protector->getMetadata());
    }

    /**
     * @test
     */
    public function excludesGitMetadataIfNotUnderGitVersionControl()
    {
        Config::set(static::METADATA_PROVIDER_CONFIG_KEY, [GitMetadataProvider::class]);
        File::shouldReceive('exists')->with(base_path('.git'))->andReturn(false);

        $this->assertArrayNotHasKey('git', $this->protector->getMetadata());
    }

    /**
     * @test
     */
    public function includesEnvMetadataIfSet()
    {
        Config::set(static::METADATA_PROVIDER_CONFIG_KEY, [EnvMetadataProvider::class]);
        Config::set(static::METADATA_ENV_VALUE_CONFIG_KEY, 'test metadata');

        $this->assertArrayHasKey('env', $this->protector->getMetadata());
        $this->assertEquals('test metadata', $this->protector->getMetadata()['env']);
    }

    /**
     * @test
     */
    public function excludesEnvMetadataIfNotSet()
    {
        Config::set(static::METADATA_PROVIDER_CONFIG_KEY, [EnvMetadataProvider::class]);
        Config::set(static::METADATA_ENV_VALUE_CONFIG_KEY, null);

        $this->assertArrayNotHasKey('env', $this->protector->getMetadata());
    }

    /**
     * @test
     */
    public function includesJsonFileMetadataIfFileExists()
    {
        $filePath = base_path('protector_metadata.json');

        Config::set(static::METADATA_PROVIDER_CONFIG_KEY, [JsonFileMetadataProvider::class]);
        Config::set(static::METADATA_JSON_FILE_PATH_CONFIG_KEY, basename($filePath));

        File::put($filePath, json_encode(['foo' => 'bar']));

        $this->assertArrayHasKey('jsonFile', $this->protector->getMetadata());
        $this->assertEquals(['foo' => 'bar'], $this->protector->getMetadata()['jsonFile']);
    }

    /**
     * @test
     */
    public function excludeJsonFileMetadataIfFileNotExists()
    {
        $filePath = base_path('protector_metadata.json');

        Config::set(static::METADATA_PROVIDER_CONFIG_KEY, [JsonFileMetadataProvider::class]);
        Config::set(static::METADATA_JSON_FILE_PATH_CONFIG_KEY, basename($filePath));

        File::delete($filePath);

        $this->assertArrayNotHasKey('jsonFile', $this->protector->getMetadata());
    }

    /**
     * @test
     */
    public function canConfigureCustomMetadataProviderWithDependencies()
    {
        $metadataProviders = [TestCustomFooMetadataProvider::class];
        Config::set(static::METADATA_PROVIDER_CONFIG_KEY, $metadataProviders);

        $metadata = $this->protector->getMetadata();

        $this->assertArrayHasKey('custom', $metadata);
        $this->assertEquals($metadataProviders, $metadata['custom']['foo']);
        $this->assertInstanceOf(Protector::class, app(TestCustomFooMetadataProvider::class)->protector);
        $this->assertInstanceOf(Config::class, app(TestCustomFooMetadataProvider::class)->config);
    }

    /**
     * @test
     */
    public function canConfigureMultipleMetadataProviders()
    {
        Config::set(static::METADATA_PROVIDER_CONFIG_KEY, [TestCustomFooMetadataProvider::class, GitMetadataProvider::class]);
        File::shouldReceive('exists')->with(base_path('.git'))->andReturn(true);

        $metadata = $this->protector->getMetadata();

        $this->assertArrayHasKey('database', $metadata);
        $this->assertArrayHasKey('custom', $metadata);
        $this->assertArrayHasKey('git', $metadata);
    }

    /**
     * @test
     */
    public function failsWhenProviderNotImplementingInterfaceIsConfigured(): void
    {
        $this->expectException(TypeError::class);

        Config::set(static::METADATA_PROVIDER_CONFIG_KEY, [File::class]);

        $this->protector->getMetadata();
    }

    /**
     * @test
     */
    public function ensureDuplicateProviderKeysAreMerged(): void
    {
        Config::set(static::METADATA_PROVIDER_CONFIG_KEY, [TestCustomFooMetadataProvider::class, TestCustomBarMetadataProvider::class]);

        $fooMetadataProvider = app(TestCustomFooMetadataProvider::class);
        $barMetadataProvider = app(TestCustomBarMetadataProvider::class);

        $this->assertArrayHasKey($fooMetadataProvider->getKey(), $this->protector->getMetadata());
        $this->assertArrayHasKey($barMetadataProvider->getKey(), $this->protector->getMetadata());
        $this->assertArrayHasKey('foo', $this->protector->getMetadata()[$fooMetadataProvider->getKey()]);
        $this->assertArrayHasKey('bar', $this->protector->getMetadata()[$barMetadataProvider->getKey()]);
    }
}

class TestCustomFooMetadataProvider implements MetadataProvider
{
    public function __construct(public Protector $protector, public Config $config)
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
            'foo' => $this->config::get('protector.metadata.providers')
        ];
    }
}

class TestCustomBarMetadataProvider implements MetadataProvider
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
            'bar' => 'test'
        ];
    }
}
