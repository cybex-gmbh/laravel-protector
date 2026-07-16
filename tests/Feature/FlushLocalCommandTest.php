<?php

namespace Cybex\Protector\Tests\Feature;

use Cybex\Protector\Enums\FlushMode;
use Cybex\Protector\Facades\DiskHelperFacade;
use Cybex\Protector\Tests\TestCase;
use Illuminate\Support\Facades\Config;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

class FlushLocalCommandTest extends TestCase
{
    #[Test]
    #[DataProvider('provideFilesWithTimestamps')]
    public function deletesCorrectFiles(array $filesWithTimestamp, array $remainingFiles): void
    {
        foreach ($filesWithTimestamp as $file => $timestamp) {
            $this->localDisk->put($file, 'content');
            $this->manipulateFileDate($this->localDisk->path($file), $timestamp);

            $this->localDisk->assertExists($file);
        }

        $this->artisan('protector:flush-local');

        $this->assertEqualsCanonicalizing($remainingFiles, $this->localDisk->files());
    }

    #[Test]
    public function ensureDeletedInSyncWhenEnabled(): void
    {
        Config::set('protector.automatic_flush.local.mode', FlushMode::SYNC->value);
        $diskHelper = DiskHelperFacade::partialMock();

        $diskHelper->shouldReceive('flushOldLocalFiles')->once();
        $diskHelper->deleteLocalFile('');
    }

    #[Test]
    public function ensureNotDeletedInSyncWhenDisabled(): void
    {
        Config::set('protector.automatic_flush.local.mode', FlushMode::SCHEDULE->value);
        $diskHelper = DiskHelperFacade::partialMock();

        $diskHelper->shouldReceive('flushOldLocalFiles')->never();
        $diskHelper->deleteLocalFile('');
    }

    public static function provideFilesWithTimestamps(): array
    {
        return [
            [
                'filesWithTimestamp' => [
                    'protector_recent_file.txt' => now()->timestamp,
                    'protector_old_file.txt' => now()->subDay()->subMinute()->timestamp,
                    'protector_almost_old_file.txt' => now()->subDay()->addMinute()->timestamp,
                    'non_protector_old_file.txt' => now()->subDay()->timestamp,
                ],
                'remainingFiles' => [
                    'protector_recent_file.txt',
                    'protector_almost_old_file.txt',
                    'non_protector_old_file.txt',
                ]
            ],
        ];
    }

    protected function manipulateFileDate(string $filePath, int $timestamp): void
    {
        touch($filePath, $timestamp);
    }
}
