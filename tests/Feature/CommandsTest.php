<?php

namespace Cybex\Protector\Tests\Feature;

use Cybex\Protector\Facades\CrypterFacade;
use Cybex\Protector\Tests\Support\Models\TestUser;
use Cybex\Protector\Tests\TestCase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Orchestra\Testbench\Attributes\WithMigration;
use PHPUnit\Framework\Attributes\Depends;
use PHPUnit\Framework\Attributes\Test;
use function Orchestra\Testbench\artisan;
use function Orchestra\Testbench\package_path;

#[WithMigration]
class CommandsTest extends TestCase
{
    protected static bool $migrated = false;

    protected function defineDatabaseMigrations(): void
    {
        if (static::$migrated) return;

        artisan($this, 'migrate:fresh', ['--database' => env('DB_CONNECTION')]);

        $this->loadMigrationsFrom(package_path() . '/Migrations');
        $this->loadMigrationsFrom(package_path() . '/vendor/laravel/sanctum/database/migrations');

        static::$migrated = true;
    }

    protected function setUp(): void
    {
        parent::setUp();

        Config::set('auth.providers.users.model', TestUser::class);
    }

    #[Test]
    public function canCreateUserAndGenerateTokenAndKeys(): array
    {
        $user = TestUser::create([
            'name' => 'Protector Tester',
            'email' => 'protector+' . uniqid() . '@example.test',
            'password' => 'secret',
        ]);

        $privateKey = CrypterFacade::createPrivateKey();
        $publicKey = CrypterFacade::getPublicKeyFromPrivateKey($privateKey);
        CrypterFacade::shouldReceive('createPrivateKey')->once()->andReturn($privateKey);
        CrypterFacade::shouldReceive('getPublicKeyFromPrivateKey')->once()->andReturn($publicKey);

        $this->artisan('protector:keys')
            ->expectsOutputToContain($privateKey)
            ->expectsOutputToContain($publicKey)
            ->assertSuccessful();

        // Need to use the Artisan facade, to be able to fetch the command output.
        $this->assertSame(0, Artisan::call('protector:token', [
            'userId' => $user->id,
            '--publicKey' => $publicKey,
        ]));

        $tokenOutput = Artisan::output();

        return [
            'authToken' => $this->extractByPattern('/PROTECTOR_CLIENT_AUTH_TOKEN="(.+)"$/m', $tokenOutput),
            'dumpEndpointUrl' => $this->extractByPattern('/PROTECTOR_CLIENT_DUMP_ENDPOINT_URL=(.+)$/m', $tokenOutput),
            'privateKey' => $privateKey,
            'publicKey' => $publicKey,
        ];
    }

    #[Test]
    #[Depends('canCreateUserAndGenerateTokenAndKeys')]
    public function canDownloadRemoteDumpWithUserAuthentication(array $context): array
    {
        Config::set('protector.client.privateKey', $context['privateKey']);
        Config::set('protector.client.authToken', $context['authToken']);
        Config::set('protector.client.dumpEndpointUrl', $context['dumpEndpointUrl']);
        Config::set('protector.server.routeMiddleware', ['auth:sanctum']);

        $chunkSize = 1024;
        $encryptedPayload = '';

        $dumpHandle = fopen(__DIR__ . '/../dumps/dump.sql', 'rb');

        while (!feof($dumpHandle)) {
            $chunk = fread($dumpHandle, $chunkSize);

            $encryptedPayload .= CrypterFacade::encrypt($chunk, $context['publicKey']);
        }

        fclose($dumpHandle);

        $encryptionOverhead = CrypterFacade::determineEncryptionOverhead($chunkSize, $context['publicKey']);

        Http::fake([
            $context['dumpEndpointUrl'] => fn() => Http::response($encryptedPayload, 200, [
                'Sanctum-Enabled' => true,
                'Chunk-Size' => $chunkSize + $encryptionOverhead,
                'Content-Disposition' => 'attachment; filename="fake_dump.sql"',
            ]),
        ]);

        $this->artisan('protector:download')->assertSuccessful();

        $this->assertContains('fake_dump.sql', $this->protector->dumpFiles());

        Http::assertSent(fn($request) => $request->hasHeader('Authorization', 'Bearer ' . $context['authToken']));

        return $context;
    }

    #[Test]
    public function canExportAndImportExportedDump(): void
    {
        $existingDumps = $this->protector->dumpFiles();

        $this->artisan('protector:export')->assertSuccessful();

        $allDumps = $this->protector->dumpFiles();
        $exportedDump = $allDumps->diff($existingDumps)->first();

        $this->assertNotNull($exportedDump);

        $this->artisan('protector:import', [
            '--file' => basename($exportedDump),
            '--force' => true,
        ])->assertSuccessful();

        $this->assertContains($exportedDump, $this->protector->dumpFiles());
    }

    protected function extractByPattern(string $pattern, string $subject): string
    {
        $subject = preg_replace('/\e\[[\d;]*m/', '', $subject) ?? $subject;

        preg_match($pattern, $subject, $matches);

        return trim($matches[1] ?? '');
    }
}
