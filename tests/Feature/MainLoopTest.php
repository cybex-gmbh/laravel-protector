<?php

namespace Cybex\Protector\Tests\Feature;

use Cybex\Protector\Facades\CrypterFacade;
use Cybex\Protector\Tests\Support\Models\TestUser;
use Cybex\Protector\Tests\TestCase;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Depends;
use PHPUnit\Framework\Attributes\Test;

class MainLoopTest extends TestCase
{
    protected static bool $migrationsLoaded = false;
    protected string $storageBaseDirectory = 'dumps';
    protected Filesystem $disk;
    protected const string MOCK_PRIVATE_KEY = 'MOCKED_PRIVATE_KEY';
    protected const string MOCK_PUBLIC_KEY = 'MOCKED_PUBLIC_KEY';

    protected function setUp(): void
    {
        parent::setUp();

        if (!static::$migrationsLoaded) {
            if (!Schema::hasTable('users')) {
                $this->loadLaravelMigrations();
            }

            if (!Schema::hasTable('personal_access_tokens')) {
                $this->loadMigrationsFrom(__DIR__ . '/../../vendor/laravel/sanctum/database/migrations');
            }

            if (!Schema::hasColumn('users', 'protector_public_key')) {
                $this->loadMigrationsFrom(__DIR__ . '/../../Migrations');
            }

            static::$migrationsLoaded = true;
        }

        if (Schema::hasTable('users') && !Schema::hasColumn('users', 'protector_public_key')) {
            Schema::table('users', fn($table) => $table->longText('protector_public_key')->nullable());
        }

        if (Schema::hasTable('personal_access_tokens') && !Schema::hasColumn('personal_access_tokens', 'expires_at')) {
            Schema::table('personal_access_tokens', fn($table) => $table->timestamp('expires_at')->nullable());
        }

        Config::set('protector.dump.disks.storage.baseDirectory', $this->storageBaseDirectory);
        Config::set('auth.providers.users.model', TestUser::class);

        $this->disk = $this->getFakeDumpDisk();
    }

    #[Test]
    public function canCreateUserAndGenerateTokenWithMockedKeys(): array
    {
        $user = TestUser::query()->create([
            'name' => 'Protector Tester',
            'email' => 'protector+' . uniqid() . '@example.test',
            'password' => 'secret',
            'protector_public_key' => null,
        ]);

        CrypterFacade::shouldReceive('createPrivateKey')->once()->andReturn(static::MOCK_PRIVATE_KEY);
        CrypterFacade::shouldReceive('getPublicKeyFromPrivateKey')->once()->andReturn(static::MOCK_PUBLIC_KEY);

        $this->artisan('protector:keys')
            ->expectsOutputToContain(static::MOCK_PRIVATE_KEY)
            ->expectsOutputToContain(static::MOCK_PUBLIC_KEY)
            ->assertSuccessful();

        $this->assertSame(0, Artisan::call('protector:token', [
            'userId' => $user->id,
            '--publicKey' => static::MOCK_PUBLIC_KEY,
        ]));

        $tokenOutput = Artisan::output();

        return [
            'authToken' => $this->extractByPattern('/PROTECTOR_CLIENT_AUTH_TOKEN="(.+)"$/m', $tokenOutput),
            'dumpEndpointUrl' => $this->extractByPattern('/PROTECTOR_CLIENT_DUMP_ENDPOINT_URL=(.+)$/m', $tokenOutput),
            'privateKey' => static::MOCK_PRIVATE_KEY,
        ];
    }

    #[Test]
    #[Depends('canCreateUserAndGenerateTokenWithMockedKeys')]
    public function canDownloadRemoteDumpWithUserAuthentication(array $context): array
    {
        Config::set('protector.client.privateKey', $context['privateKey']);
        Config::set('protector.client.authToken', $context['authToken']);
        Config::set('protector.client.dumpEndpointUrl', $context['dumpEndpointUrl']);

        Http::fake([
            $context['dumpEndpointUrl'] => fn() => Http::response(file_get_contents(__DIR__ . '/../dumps/dump.sql'), 200, ['Chunk-Size' => 1024]),
        ]);

        $this->artisan('protector:download')->assertSuccessful();

        $downloadedRemoteDump = sprintf('%s%sremote_dump.sql', $this->storageBaseDirectory, DIRECTORY_SEPARATOR);
        $this->assertContains($downloadedRemoteDump, $this->protector->getDumpFiles()->toArray());

        Http::assertSent(fn($request) => $request->hasHeader('Authorization', 'Bearer ' . $context['authToken']));

        return $context;
    }

    #[Test]
    public function canExportAndImportExportedDump(): void
    {
        $existingDumps = collect($this->protector->getDumpFiles()->toArray());

        $this->artisan('protector:export')->assertSuccessful();

        $allDumps = collect($this->protector->getDumpFiles()->toArray());
        $exportedDump = $allDumps->diff($existingDumps)->first();

        $this->assertNotNull($exportedDump);

        $this->artisan('protector:import', [
            '--file' => basename($exportedDump),
            '--force' => true,
        ])->assertSuccessful();

        $this->assertContains($exportedDump, $this->protector->getDumpFiles()->toArray());
    }

    #[Test]
    #[Depends('canCreateUserAndGenerateTokenWithMockedKeys')]
    public function importingRemoteDumpWithFlushLeavesStorageEmpty(array $context): void
    {
        Config::set('protector.server.routeMiddleware', []);
        Config::set('protector.client.basicAuthCredentials', '1234:1234');
        Config::set('protector.client.dumpEndpointUrl', $context['dumpEndpointUrl']);

        Http::fake([
            $context['dumpEndpointUrl'] => fn() => Http::response(file_get_contents(__DIR__ . '/../dumps/dump.sql'), 200, ['Chunk-Size' => 1024]),
        ]);

        $this->assertNotEmpty($this->protector->getDumpFiles()->toArray());

        $this->artisan('protector:import', [
            '--remote' => true,
            '--flush' => true,
            '--force' => true,
        ])->assertSuccessful();

        $this->assertCount(0, $this->protector->getDumpFiles());
    }

    protected function extractByPattern(string $pattern, string $subject): string
    {
        $subject = preg_replace('/\e\[[\d;]*m/', '', $subject) ?? $subject;

        preg_match($pattern, $subject, $matches);

        return trim($matches[1] ?? '');
    }

}
















