<?php

namespace Cybex\Protector\Tests\feature;

use Cybex\Protector\Classes\Metadata\Providers\DefaultMetadataProvider;
use Cybex\Protector\Classes\Metadata\Providers\EnvMetadataProvider;
use Cybex\Protector\Classes\Metadata\Providers\GitMetadataProvider;
use Cybex\Protector\Contracts\MetadataProvider;
use Cybex\Protector\Exceptions\InvalidMetadataProviderException;
use Cybex\Protector\Protector;
use Cybex\Protector\Tests\TestCase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\File;

class MetadataTest extends TestCase
{
    /**
     * @test
     */
    public function canCreateDumpMetadata()
    {
        $dumpDate = now();

        Carbon::setTestNow($dumpDate);

        $metadata = $this->protector->getMetadata();

        $this->assertIsArray($metadata);
        $this->assertEquals($dumpDate, $metadata['default']['dumpedAtDate']);
    }

    /**
     * @test
     */
    public function includesDatabaseMetadataProviderByDefault()
    {
        Config::set('protector.metadataProviders', []);

        $this->assertContains(DefaultMetadataProvider::class, $this->protector->getMetadataProviders());
    }

    /**
     * @test
     */
    public function canConfigureProtectorInstanceToUseMetadataProviders()
    {
        Config::set('protector.metadataProviders', []);

        $this->protector->withMetadataProviders([GitMetadataProvider::class]);

        $this->assertContains(GitMetadataProvider::class, $this->protector->getMetadataProviders());
    }

    /**
     * @test
     */
    public function includesMetadataIfShouldAppend()
    {
        Config::set('protector.metadataProviders', [GitMetadataProvider::class]);

        $mock = $this->partialMock(GitMetadataProvider::class, fn($mock) => $mock->shouldReceive('shouldAppend')->andReturn(true));
        app()->offsetSet(GitMetadataProvider::class, $mock);

        $this->assertArrayHasKey('git', $this->protector->getMetadata());
    }

    /**
     * @test
     */
    public function excludesMetadataIfShouldNotAppend()
    {
        Config::set('protector.metadataProviders', [GitMetadataProvider::class]);

        $mock = $this->partialMock(GitMetadataProvider::class, fn($mock) => $mock->shouldReceive('shouldAppend')->andReturn(false));
        app()->offsetSet(GitMetadataProvider::class, $mock);

        $this->assertArrayNotHasKey('git', $this->protector->getMetadata());
    }

    /**
     * @test
     */
    public function includesGitMetadataIfUnderGitVersionControl()
    {
        Config::set('protector.metadataProviders', [GitMetadataProvider::class]);
        File::shouldReceive('exists')->with(base_path('.git'))->andReturn(true);

        $this->assertArrayHasKey('git', $this->protector->getMetadata());
    }

    /**
     * @test
     */
    public function excludesGitMetadataIfNotUnderGitVersionControl()
    {
        Config::set('protector.metadataProviders', [GitMetadataProvider::class]);
        File::shouldReceive('exists')->with(base_path('.git'))->andReturn(false);

        $this->assertArrayNotHasKey('git', $this->protector->getMetadata());
    }

    /**
     * @test
     */
    public function includesEnvMetadataIfSet()
    {
        Config::set('protector.metadataProviders', [EnvMetadataProvider::class]);
        Config::set('protector.additionalEnvMetadata', 'test metadata');

        $this->assertArrayHasKey('env', $this->protector->getMetadata());
        $this->assertEquals('test metadata', $this->protector->getMetadata()['env']);
    }

    /**
     * @test
     */
    public function excludesEnvMetadataIfNotSet()
    {
        Config::set('protector.metadataProviders', [EnvMetadataProvider::class]);
        Config::set('protector.additionalEnvMetadata', null);


        $this->assertArrayNotHasKey('env', $this->protector->getMetadata());
    }

    /**
     * @test
     */
    public function canConfigureCustomMetadataProviderWithDependencies()
    {
        $metadataProviders = [TestCustomMetadataProvider::class];
        Config::set('protector.metadataProviders', $metadataProviders);

        $metadata = $this->protector->getMetadata();

        $this->assertArrayHasKey('custom', $metadata);
        $this->assertEquals($metadataProviders, $metadata['custom']['foo']);
        $this->assertInstanceOf(Protector::class, app(TestCustomMetadataProvider::class)->protector);
        $this->assertInstanceOf(Config::class, app(TestCustomMetadataProvider::class)->config);
    }

    /**
     * @test
     */
    public function canConfigureMultipleMetadataProviders()
    {
        Config::set('protector.metadataProviders', [TestCustomMetadataProvider::class, GitMetadataProvider::class]);
        File::shouldReceive('exists')->with(base_path('.git'))->andReturn(true);

        $metadata = $this->protector->getMetadata();

        $this->assertArrayHasKey('default', $metadata);
        $this->assertArrayHasKey('custom', $metadata);
        $this->assertArrayHasKey('git', $metadata);
    }

    /**
     * @test
     */
    public function canCreateDumpMetadataUsingCache()
    {
        $metadata = $this->protector->getMetadata();

        $metadata['hello'] = 'world';

        $this->setProtectedProperty('metadataCache', $metadata);

        $cachedMetadata = $this->protector->getMetadata();

        $this->assertEquals($metadata, $cachedMetadata);

        $newMetadata = $this->protector->getMetadata(refresh: true);

        $this->assertNotEquals($metadata, $newMetadata);
    }

    /**
     * @test
     */
    public function failsWhenProviderNotImplementingInterfaceIsConfigured(): void
    {
        $this->expectException(InvalidMetadataProviderException::class);

        Config::set('protector.metadataProviders', [File::class]);

        $this->protector->getMetadata();
    }
}

class TestCustomMetadataProvider implements MetadataProvider
{
    public const METADATA_KEY = 'custom';

    public function __construct(public Protector $protector, public Config $config)
    {
    }

    public function shouldAppend(): bool
    {
        return true;
    }

    public function getMetadata(): array
    {
        return [
            static::METADATA_KEY => [
                'foo' => $this->config::get('protector.metadataProviders')
            ]
        ];
    }
}
