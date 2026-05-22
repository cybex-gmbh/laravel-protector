<?php

namespace Cybex\Protector\Tests\Feature;

use Cybex\Protector\Tests\TestCase;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
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
    }
}

