<?php

namespace Cybex\Protector\Classes;

use Cybex\Protector\Contracts\CrypterContract;
use Cybex\Protector\Contracts\DiskHelperContract;
use Cybex\Protector\Exceptions\EmptyDumpDirectoryException;
use Cybex\Protector\Exceptions\EmptyFileWrittenException;
use Cybex\Protector\Exceptions\FailedReadingFromDiskException;
use Cybex\Protector\Exceptions\FailedRemoteDatabaseFetchingException;
use Cybex\Protector\Exceptions\FailedWritingMetadataFileException;
use Cybex\Protector\Exceptions\FailedWritingToDiskException;
use Cybex\Protector\Exceptions\InvalidConfigurationException;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Psr\Http\Message\StreamInterface;
use Throwable;

class DiskHelper implements DiskHelperContract
{
    protected const string LOCAL_DISK_NAME = 'protector_local';
    protected const string STORAGE_DISK_NAME = 'protector_storage';
    protected const string METADATA_FILE_SUFFIX = '.meta';

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
    public function moveLocalToStorage(
        string $localFilePath,
        string $storageFilePath,
        ?Filesystem $storageDisk = null,
        bool $keepLocalFile = false,
    ): void
    {
        $storageDisk ??= $this->getStorageDisk();
        $localFileStream = $this->getLocalDisk()->readStream($localFilePath);

        try {
            if (!is_resource($localFileStream)) {
                throw new FailedReadingFromDiskException($localFilePath, 'local');
            }

            if (!$storageDisk->writeStream($storageFilePath, $localFileStream)) {
                throw new FailedWritingToDiskException($storageFilePath, 'storage');
            }

            if ($storageDisk->size($storageFilePath) === 0) {
                throw new EmptyFileWrittenException($storageFilePath, 'storage');
            }
        } catch (Throwable $throwable) {
            $this->deleteLocalFile($localFilePath);
            $this->deleteStorageFile($storageFilePath, $storageDisk);

            throw $throwable;
        } finally {
            fclose($localFileStream);

            if (!$keepLocalFile) {
                $this->deleteLocalFile($localFilePath);
            }
        }
    }

    /**
     * @throws FailedReadingFromDiskException
     * @throws FailedWritingToDiskException
     */
    public function copyStorageToLocal(string $storageFilePath, ?Filesystem $storageDisk = null): string
    {
        $storageDisk ??= $this->getStorageDisk();
        $localDisk = $this->getLocalDisk();
        $localFilePath = $this->getLocalPath();

        $stream = $storageDisk->readStream($storageFilePath);

        if (!is_resource($stream)) {
            throw new FailedReadingFromDiskException($storageFilePath, 'storage');
        }

        try {
            if (!$localDisk->writeStream($localFilePath, $stream)) {
                $this->deleteLocalFile($localFilePath);

                throw new FailedWritingToDiskException($localFilePath, 'local');
            }
        } finally {
            fclose($stream);
        }

        return $localFilePath;
    }

    /**
     * @throws FailedRemoteDatabaseFetchingException
     * @throws Throwable
     */
    public function writeStreamToLocalFile(
        StreamInterface $stream,
        string $localFilePath,
        int $chunkSize,
        bool $shouldEncrypt = false,
        ?string $privateKey = null,
        ?string $emptyResponseContext = null,
    ): void
    {
        try {
            while (!$stream->eof() && ($chunk = $stream->read($chunkSize)) !== '') {
                if ($shouldEncrypt) {
                    $chunk = app(CrypterContract::class)->decrypt($chunk, $privateKey);

                    if ($chunk === false) {
                        throw new InvalidConfigurationException(
                            'There was an error decrypting the provided string. This might be due to mismatching crypto keys.'
                        );
                    }
                }

                // Separator needs to be null, else each chunk will start on a new line.
                $this->getLocalDisk()->append($localFilePath, $chunk, separator: null);
            }

            if (!$this->getLocalDisk()->exists($localFilePath) || $this->getLocalDisk()->size($localFilePath) === 0) {
                throw new FailedRemoteDatabaseFetchingException('Retrieved empty response from remote dump endpoint.');
            }
        } catch (Throwable $throwable) {
            $this->deleteLocalFile($localFilePath);

            throw $throwable;
        }
    }

    /**
     * @inheritDoc
     */
    public function writeMetadataFile(string $dumpFilePath, array $metadataPayload, ?Filesystem $storageDisk = null): void
    {
        $storageDisk ??= $this->getStorageDisk();
        $metadataFilePath = $this->metadataFilePath($dumpFilePath);

        try {
            $encodedMetadata = json_encode(['meta' => $metadataPayload], flags: JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

            if ($storageDisk->put($metadataFilePath, $encodedMetadata) === false) {
                throw new FailedWritingMetadataFileException($dumpFilePath);
            }
        } catch (Throwable $throwable) {
            $this->deleteStorageFile($dumpFilePath, $storageDisk);

            throw $throwable;
        }
    }

    /**
     * @inheritDoc
     */
    public function deleteLocalFile(string $path): void
    {
        $this->deleteDumpAndMetaFiles($path, $this->getLocalDisk());
    }

    /**
     * @inheritDoc
     */
    public function deleteStorageFile(string $path, ?Filesystem $storageDisk = null): void
    {
        $this->deleteDumpAndMetaFiles($path, $storageDisk ?? $this->getStorageDisk());
    }

    /**
     * @inheritDoc
     */
    public function flushDumps(?string $excludeFile = null): void
    {
        $this->deleteDumpAndMetaFiles($this->dumpFiles(excludeFile: $excludeFile), $this->getStorageDisk());
    }

    public function getLocalPath(): string
    {
        return sprintf('%s.sql', uniqid('protector_', true));
    }

    public function getDownloadDestinationFilePath(string $contentDispositionHeader): string
    {
        if (preg_match('/filename="(?P<filename>.+)"/i', $contentDispositionHeader, $matches)) {
            $destinationFileName = $matches['filename'];
        }

        return $destinationFileName ?? 'remote_dump.sql';
    }

    public function metadataFilePath(string $dumpFilePath): string
    {
        return sprintf('%s%s', $dumpFilePath, static::METADATA_FILE_SUFFIX);
    }

    public function dumpFiles(?string $excludeFile = null): Collection
    {
        $allFiles = $this->getStorageDisk()->allFiles();

        return collect($allFiles)
            ->reject(fn(string $filePath) => $this->isMetadataFile($filePath))
            ->when($excludeFile, fn($collection) => $collection->diff([$excludeFile]));
    }

    public function dumpFilesWithMetadata(): Collection
    {
        return $this->dumpFiles()->mapWithKeys(
            fn(string $dumpFilePath) => [$dumpFilePath => $this->getMetadataFileContents($dumpFilePath) ?? []]
        );
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

        $disk = $this->getStorageDisk();

        return $files->sortByDesc(fn($file) => $disk->lastModified($file))->values()->firstOrFail();
    }

    public function isAbsolutePath(string $filePath): bool
    {
        return Str::startsWith($filePath, DIRECTORY_SEPARATOR);
    }

    protected function isMetadataFile(string $filePath): bool
    {
        return Str::endsWith($filePath, static::METADATA_FILE_SUFFIX);
    }

    protected function getMetadataFileContents(string $dumpFilePath): ?array
    {
        $metadataFilePath = $this->metadataFilePath($dumpFilePath);
        $disk = $this->getStorageDisk();

        if (!$disk->exists($metadataFilePath)) {
            return null;
        }

        if (!$metadata = $disk->get($metadataFilePath)) {
            return null;
        }

        $decodedMetadata = json_decode($metadata, associative: true);

        return is_array($decodedMetadata) ? $decodedMetadata : null;
    }

    protected function deleteDumpAndMetaFiles(string|array|Collection $paths, Filesystem $disk): void
    {
        $pathsToDelete = collect($paths)
            ->flatMap(fn(string $filePath) => [$filePath, $this->metadataFilePath($filePath)])
            ->toArray();

        $disk->delete($pathsToDelete);
    }
}
