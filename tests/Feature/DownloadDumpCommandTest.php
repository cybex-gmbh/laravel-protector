<?php

namespace Cybex\Protector\Tests\Feature;

use Cybex\Protector\Classes\DiskHelper;
use Cybex\Protector\Contracts\DiskHelperContract;
use Cybex\Protector\Exceptions\FailedImportException;
use Cybex\Protector\Tests\TestCase;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Mockery;
use PHPUnit\Framework\Attributes\Test;

class DownloadDumpCommandTest extends TestCase
{
    protected Filesystem $disk;

    protected string $dumpEndpointUrl;

    protected static string $baseDirectory = 'dumps';

    protected function setUp(): void
    {
        parent::setUp();

        $this->dumpEndpointUrl = 'protector.invalid/protector/exportDump';

        Config::set('protector.client.dumpEndpointUrl', $this->dumpEndpointUrl);
        Config::set('protector.server.routeMiddleware', []);
        Config::set('protector.client.basicAuthCredentials', '1234:1234');
        Config::set('protector.dump.disks.storage.baseDirectory', static::$baseDirectory);

        $this->disk = $this->getDumpDisk();
    }

    #[Test]
    public function canDownloadRemoteDump(): void
    {
        $dump = file_get_contents(__DIR__ . '/../dumps/dump.sql');

        Http::fake([
            $this->dumpEndpointUrl => Http::response($dump, 200, ['Chunk-Size' => 1024]),
        ]);

        $this->artisan('protector:download')->assertSuccessful();

        $this->assertFileExists($this->disk->path(static::$baseDirectory . '/remote_dump.sql'));
        $this->assertFileExists($this->disk->path(static::$baseDirectory . '/remote_dump.sql.meta'));
    }

    #[Test]
    public function canDownloadAndImportRemoteDump(): void
    {
        $dump = file_get_contents(__DIR__ . '/../dumps/dump.sql');

        Http::fake([
            $this->dumpEndpointUrl => Http::response($dump, 200, ['Chunk-Size' => 1024]),
        ]);

        $this->artisan('protector:download --import --force')->assertSuccessful();

        $this->assertFileExists($this->disk->path(static::$baseDirectory . '/remote_dump.sql'));
        $this->assertCount(
            0,
            $this->diskHelper->getLocalDisk()->allFiles($this->diskHelper->getLocalBaseDirectory())
        );
    }

    #[Test]
    public function importFlagDoesNotStageBackFromStorageForImport(): void
    {
        $diskHelper = Mockery::mock(DiskHelper::class)->makePartial();
        $diskHelper->shouldReceive('copyStorageToLocal')->never();

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
        $existingDumpPath = static::$baseDirectory . '/existing_dump.sql';
        $existingMetadataPath = $existingDumpPath . '.meta';

        $this->disk->put($existingDumpPath, '-- existing dump');
        $this->disk->put($existingMetadataPath, '{"meta":{"database":{"connection":"sqlite"}}}');

        $invalidDumpWithMetadata = implode(PHP_EOL, [
            'INVALID SQL STATEMENT;',
            '-- meta:{"database":{"connection":"sqlite","dumpedAtDate":"2026-05-26T00:00:00+00:00"}}',
        ]);

        Http::fake([
            $this->dumpEndpointUrl => Http::response($invalidDumpWithMetadata, 200, ['Chunk-Size' => 1024]),
        ]);

        $this->expectException(FailedImportException::class);

        try {
            $this->artisan('protector:download --import --force --flush');
        } finally {
            $this->assertTrue($this->disk->exists($existingDumpPath));
            $this->assertTrue($this->disk->exists($existingMetadataPath));
        }
    }
}

