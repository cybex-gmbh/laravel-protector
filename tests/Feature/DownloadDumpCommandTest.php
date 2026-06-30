<?php

namespace Cybex\Protector\Tests\Feature;

use Cybex\Protector\Classes\DiskHelper;
use Cybex\Protector\Contracts\DiskHelperContract;
use Cybex\Protector\Contracts\ProtectorConfiguratorContract;
use Cybex\Protector\Exceptions\FailedImportException;
use Cybex\Protector\Protector;
use Cybex\Protector\Tests\TestCase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Mockery;
use PHPUnit\Framework\Attributes\Test;

class DownloadDumpCommandTest extends TestCase
{
    protected string $dumpEndpointUrl;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dumpEndpointUrl = 'protector.invalid/protector/exportDump';

        Config::set('protector.client.dumpEndpointUrl', $this->dumpEndpointUrl);
        Config::set('protector.server.routeMiddleware', []);
        Config::set('protector.client.basicAuthCredentials', '1234:1234');
    }

    #[Test]
    public function canDownloadRemoteDump(): void
    {
        $dump = file_get_contents(__DIR__ . '/../dumps/dump.sql');

        Http::fake([
            $this->dumpEndpointUrl => Http::response($dump, 200, ['Chunk-Size' => 1024]),
        ]);

        $this->artisan('protector:download')->assertSuccessful();

        $this->assertFileExists($this->storageDisk->path('remote_dump.sql'));
        $this->assertFileExists($this->storageDisk->path('remote_dump.sql.meta'));
    }

    #[Test]
    public function canDownloadAndImportRemoteDump(): void
    {
        $dump = file_get_contents(__DIR__ . '/../dumps/dump.sql');

        Http::fake([
            $this->dumpEndpointUrl => Http::response($dump, 200, ['Chunk-Size' => 1024]),
        ]);

        $this->artisan('protector:download --import --force')->assertSuccessful();

        $this->assertFileExists($this->storageDisk->path('remote_dump.sql'));
        $this->assertCount(0, $this->localDisk->files());
    }

    #[Test]
    public function importFlagShouldNotRedownloadFromStorage(): void
    {
        $diskHelper = Mockery::mock(DiskHelper::class)->makePartial();
        $diskHelper->shouldReceive('copySourceToLocal')->never();

        $this->app->instance(DiskHelperContract::class, $diskHelper);

        $dump = file_get_contents(__DIR__ . '/../dumps/dump.sql');

        Http::fake([
            $this->dumpEndpointUrl => Http::response($dump, 200, ['Chunk-Size' => 1024]),
        ]);

        $this->artisan('protector:download --import --force')->assertSuccessful();
    }

    #[Test]
    public function flushRunsAfterImportAndIsSkippedWhenImportFails(): void
    {
        $existingDumpPath = 'existing_dump.sql';
        $existingMetadataPath = $this->diskHelper->metadataFileName($existingDumpPath);

        $this->storageDisk->put($existingDumpPath, '-- existing dump');
        $this->storageDisk->put($existingMetadataPath, '{"meta":{"database":{"connection":"sqlite"}}}');

        $protector = Mockery::mock(Protector::class);
        $protector->shouldReceive('downloadAndImport')
            ->once()
            ->andThrow(new FailedImportException('Import failed.'));

        $protectorConfigurator = Mockery::mock(ProtectorConfiguratorContract::class);
        $protectorConfigurator->shouldReceive('makeProtector')->once()->andReturn($protector);

        $this->app->instance(ProtectorConfiguratorContract::class, $protectorConfigurator);

        $this->expectException(FailedImportException::class);

        try {
            $this->artisan('protector:download --import --force --flush-storage');
        } finally {
            $this->assertTrue($this->storageDisk->exists($existingDumpPath));
            $this->assertTrue($this->storageDisk->exists($existingMetadataPath));
        }
    }

    #[Test]
    public function usesPassedDisk(): void
    {
        $fileName = 'usesPassedDisk.sql';
        $disk = Storage::fake('local');

        $dump = file_get_contents(__DIR__ . '/../dumps/dump.sql');
        Http::fake([
            $this->dumpEndpointUrl => Http::sequence()
                ->push($dump, 200, ['Chunk-Size' => 1024])
                ->push($dump, 200, ['Chunk-Size' => 1024])
        ]);

        $disk->assertMissing($fileName);
        $this->storageDisk->assertMissing($fileName);

        $this->artisan(sprintf('protector:download --file=%s', $fileName));
        $disk->assertMissing($fileName);
        $this->storageDisk->assertExists($fileName);

        $this->artisan(sprintf('protector:download --file=%s --disk=local', $fileName));
        $disk->assertExists($fileName);
    }
}

