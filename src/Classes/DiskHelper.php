<?php

namespace Cybex\Protector\Classes;

use Cybex\Protector\Contracts\CrypterContract;
use Cybex\Protector\Contracts\DiskHelperContract;
use Cybex\Protector\Enums\ExecutionMode;
use Cybex\Protector\Exceptions\EmptyDumpDirectoryException;
use Cybex\Protector\Exceptions\EmptyFileWrittenException;
use Cybex\Protector\Exceptions\FailedReadingFromDiskException;
use Cybex\Protector\Exceptions\FailedRemoteDatabaseFetchingException;
use Cybex\Protector\Exceptions\FailedWritingMetadataFileException;
use Cybex\Protector\Exceptions\FailedWritingToDiskException;
use Cybex\Protector\Exceptions\InvalidConfigurationException;
use GuzzleHttp\Psr7\StreamWrapper;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Filesystem\Filesystem as LocalFilesystem;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Psr\Http\Message\StreamInterface;
use SplFileInfo;
use Throwable;

class DiskHelper implements DiskHelperContract
{
    protected const string LOCAL_DISK_NAME = 'protector_local';
    protected const string STORAGE_DISK_NAME = 'protector_storage';
    protected const string METADATA_FILE_SUFFIX = '.meta';
    protected const string LOCAL_TEMP_FILE_PREFIX = 'protector_';

    public function __construct(protected LocalFilesystem $filesystem)
    {
    }

    public function getLocalDisk(): Filesystem
    {
        return Storage::disk($this->getLocalDiskName());
    }

    public function getStorageDisk(): Filesystem
    {
        return Storage::disk($this->getStorageDiskName());
    }

    public function getLocalDiskName(): string
    {
        return static::LOCAL_DISK_NAME;
    }

    public function getStorageDiskName(): string
    {
        return static::STORAGE_DISK_NAME;
    }

    /**
     * @inheritDoc
     *
     * @throws FailedReadingFromDiskException
     * @throws FailedWritingToDiskException
     * @throws EmptyFileWrittenException
     * @throws Throwable
     */
    public function moveFromLocal(
        string $localFileName,
        string $targetFileName,
        Filesystem $targetDisk,
        bool $keepLocalFile = false,
    ): void
    {
        $localFileStream = $this->getLocalDisk()->readStream($localFileName);

        try {
            if (!is_resource($localFileStream)) {
                throw new FailedReadingFromDiskException($localFileName, 'local');
            }

            if (!$targetDisk->writeStream($targetFileName, $localFileStream)) {
                throw new FailedWritingToDiskException($targetFileName, 'target');
            }

            if ($targetDisk->size($targetFileName) === 0) {
                throw new EmptyFileWrittenException($targetFileName, 'target');
            }
        } catch (Throwable $throwable) {
            $this->deleteLocalFile($localFileName);
            $this->deleteFileOnDisk($targetFileName, $targetDisk);

            throw $throwable;
        } finally {
            fclose($localFileStream);

            if (!$keepLocalFile) {
                $this->deleteLocalFile($localFileName);
            }
        }
    }

    /**
     * @throws FailedReadingFromDiskException
     * @throws FailedWritingToDiskException
     */
    public function copyToLocal(string $sourceFilePath, Filesystem $sourceDisk): string
    {
        $localDisk = $this->getLocalDisk();
        $localFileName = $this->createLocalFileName();

        $stream = $sourceDisk->readStream($sourceFilePath);

        if (!is_resource($stream)) {
            throw new FailedReadingFromDiskException($sourceFilePath, 'source');
        }

        try {
            if (!$localDisk->writeStream($localFileName, $stream)) {
                $this->deleteLocalFile($localFileName);

                throw new FailedWritingToDiskException($localFileName, 'local');
            }
        } finally {
            fclose($stream);
        }

        return $localFileName;
    }

    /**
     * @throws FailedRemoteDatabaseFetchingException
     * @throws Throwable
     */
    public function writeStreamToLocalFile(
        StreamInterface $stream,
        string $localFileName,
        int $chunkSize,
        bool $shouldEncrypt = false,
        ?string $privateKey = null,
        ?string $emptyResponseContext = null,
    ): void
    {
        try {
            $resource = StreamWrapper::getResource($stream);

            while (!feof($resource) && ($chunk = stream_get_contents($resource, $chunkSize)) !== '') {
                if ($shouldEncrypt) {
                    $chunk = app(CrypterContract::class)->decrypt($chunk, $privateKey);

                    if ($chunk === false) {
                        throw new InvalidConfigurationException(
                            'There was an error decrypting the provided string. This might be due to mismatching crypto keys.'
                        );
                    }
                }

                // Separator needs to be null, else each chunk will start on a new line.
                $this->getLocalDisk()->append($localFileName, $chunk, separator: null);
            }

            if (!$this->getLocalDisk()->exists($localFileName) || $this->getLocalDisk()->size($localFileName) === 0) {
                throw new FailedRemoteDatabaseFetchingException('Retrieved empty response from remote dump endpoint.');
            }
        } catch (Throwable $throwable) {
            $this->deleteLocalFile($localFileName);

            throw $throwable;
        }
    }

    /**
     * @inheritDoc
     */
    public function writeMetadataFile(string $dumpFileName, array $metadataPayload, Filesystem $targetDisk): void
    {
        $metadataFileName = $this->metadataFileName($dumpFileName);

        try {
            $encodedMetadata = json_encode(['meta' => $metadataPayload], flags: JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

            if ($targetDisk->put($metadataFileName, $encodedMetadata) === false) {
                throw new FailedWritingMetadataFileException($dumpFileName);
            }
        } catch (Throwable $throwable) {
            $this->deleteFileOnDisk($dumpFileName, $targetDisk);

            throw $throwable;
        }
    }

    /**
     * @inheritDoc
     */
    public function deleteLocalFile(string $name): void
    {
        $this->deleteDumpAndMetaFiles($name, $this->getLocalDisk());

        $mode = ExecutionMode::fromConfig();

        if (!$mode->shouldSchedule()) {
            $mode->run(config('protector.cleanup.local_disk.invokable'));
        }
    }

    /**
     * @inheritDoc
     */
    public function deleteFileOnDisk(string $name, Filesystem $disk): void
    {
        $this->deleteDumpAndMetaFiles($name, $disk);
    }

    /**
     * @inheritDoc
     */
    public function cleanupStorage(?string $excludeFile = null): void
    {
        $this->deleteDumpAndMetaFiles(
            names: $this->dumpFiles(excludeFile: $excludeFile),
            disk: $this->getStorageDisk());
    }

    /**
     * @inheritDoc
     */
    public function cleanupOldLocalFiles(): bool
    {
        $filesToDelete = collect($this->filesystem->files($this->getLocalDisk()->path('')))
            ->filter($this->isLocalTempFile(...))
            ->filter($this->isOlderThanOneDay(...))
            ->map->getFilename();

        return $this->deleteDumpAndMetaFiles(
            names: $filesToDelete,
            disk: $this->getLocalDisk());
    }

    public function createLocalFileName(): string
    {
        return sprintf('%s.sql', uniqid(static::LOCAL_TEMP_FILE_PREFIX, true));
    }

    public function getDownloadDestinationFileName(string $contentDispositionHeader): string
    {
        if (preg_match('/filename="(?P<filename>.+)"/i', $contentDispositionHeader, $matches)) {
            $destinationFileName = $matches['filename'];
        }

        return $destinationFileName ?? 'remote_dump.sql';
    }

    public function metadataFileName(string $dumpFileName): string
    {
        return sprintf('%s%s', $dumpFileName, static::METADATA_FILE_SUFFIX);
    }

    public function dumpFiles(?string $excludeFile = null): Collection
    {
        // Only retrieve files which have a .meta file and are not in a directory.
        return collect($this->getStorageDisk()->files())
            ->filter($this->metadataFileExists(...))
            ->reject($this->isMetadataFile(...))
            ->when($excludeFile, fn(Collection $collection) => $collection->diff([$excludeFile]))
            ->values();
    }

    public function dumpFilesWithMetadata(): Collection
    {
        return $this->dumpFiles()->mapWithKeys($this->getKeyedMetadataFileContents(...));
    }

    public function allStorageFiles(): Collection
    {
        return collect($this->getStorageDisk()->allFiles())
            ->reject($this->isMetadataFile(...))
            ->values();
    }

    public function allStorageFilesWithMetadata(): Collection
    {
        return $this->allStorageFiles()->mapWithKeys($this->getKeyedMetadataFileContents(...));
    }

    /**
     * @throws EmptyDumpDirectoryException
     */
    public function latestDumpName(): string
    {
        $files = $this->dumpFiles();

        if ($files->isEmpty()) {
            throw new EmptyDumpDirectoryException();
        }

        return $files->sortByDesc($this->getStorageDisk()->lastModified(...))->values()->firstOrFail();
    }

    public function isBaseName(?string $file): bool
    {
        // basename does not accept "null".
        $file ??= '';

        return $file === basename($file);
    }

    protected function isMetadataFile(string $fileName): bool
    {
        return Str::endsWith($fileName, static::METADATA_FILE_SUFFIX);
    }

    protected function metadataFileExists(string $dumpFileName): bool
    {
        return $this->getStorageDisk()->exists($this->metadataFileName($dumpFileName));
    }

    protected function getMetadataFileContents(string $dumpFileName): ?array
    {
        $metadataFileName = $this->metadataFileName($dumpFileName);
        $disk = $this->getStorageDisk();

        if (!$disk->exists($metadataFileName)) {
            return null;
        }

        if (!$metadata = $disk->get($metadataFileName)) {
            return null;
        }

        $decodedMetadata = json_decode($metadata, associative: true);

        return is_array($decodedMetadata) ? $decodedMetadata : null;
    }

    protected function getKeyedMetadataFileContents(string $dumpFileName): array
    {
        return [$dumpFileName => $this->getMetadataFileContents($dumpFileName) ?? []];
    }

    protected function deleteDumpAndMetaFiles(string|array|Collection $names, Filesystem $disk): bool
    {
        $filesToDelete = collect($names)
            ->flatMap($this->getDumpAndMetadataFile(...))
            ->toArray();

        return $disk->delete($filesToDelete);
    }

    protected function getDumpAndMetadataFile(string $fileName): array
    {
        return [$fileName, $this->metadataFileName($fileName)];
    }

    protected function isLocalTempFile(SplFileInfo $file): bool
    {
        return str_starts_with($file->getFilename(), static::LOCAL_TEMP_FILE_PREFIX);
    }

    protected function isOlderThanOneDay(SplFileInfo $file): bool
    {
        return $file->getMTime() < now()->subDay()->timestamp;
    }
}
