<?php

namespace Cybex\Protector\Classes;

use Cybex\Protector\Contracts\CrypterContract;
use Cybex\Protector\Contracts\DiskHelperContract;
use Cybex\Protector\Exceptions\EmptyBaseDirectoryException;
use Cybex\Protector\Exceptions\EmptyFileWrittenException;
use Cybex\Protector\Exceptions\FailedCreatingDestinationPathException;
use Cybex\Protector\Exceptions\FailedReadingFromDiskException;
use Cybex\Protector\Exceptions\FailedRemoteDatabaseFetchingException;
use Cybex\Protector\Exceptions\FailedWritingMetadataFileException;
use Cybex\Protector\Exceptions\FailedWritingToDiskException;
use Cybex\Protector\Exceptions\FileNotFoundException;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Psr\Http\Message\StreamInterface;
use Throwable;

class DiskHelper implements DiskHelperContract
{
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
        return $this->getConfigValueForKey('dump.disks.local.disk');
    }

    public function getStorageDiskName(): string
    {
        return $this->getConfigValueForKey('dump.disks.storage.disk') ?? config('filesystems.default');
    }

    public function getLocalBaseDirectory(): string
    {
        return $this->getConfigValueForKey('dump.disks.local.baseDirectory') ?? '';
    }

    public function getStorageBaseDirectory(): string
    {
        return $this->getConfigValueForKey('dump.disks.storage.baseDirectory') ?? '';
    }

    /**
     * @throws FailedReadingFromDiskException
     * @throws FailedWritingToDiskException
     * @throws EmptyFileWrittenException
     * @throws Throwable
     */
    public function copyLocalToStorage(
        string $localFilePath,
        string $destinationFilePath,
        ?Filesystem $disk = null,
        bool $keepLocalFile = false,
    ): void
    {
        $disk ??= $this->getStorageDisk();
        $localDisk = $this->getLocalDisk();
        $localFileStream = $localDisk->readStream($localFilePath);

        try {
            if (!is_resource($localFileStream)) {
                throw new FailedReadingFromDiskException($localFilePath, 'local');
            }

            if (!$disk->writeStream($destinationFilePath, $localFileStream)) {
                throw new FailedWritingToDiskException($destinationFilePath, 'storage');
            }

            if ($disk->size($destinationFilePath) === 0) {
                throw new EmptyFileWrittenException($destinationFilePath, 'storage');
            }
        } catch (Throwable $throwable) {
            $this->deleteLocalFiles($localFilePath);
            $this->deleteStorageFiles($destinationFilePath, $disk);

            throw $throwable;
        } finally {
            fclose($localFileStream);

            if (!$keepLocalFile) {
                $this->deleteLocalFiles($localFilePath);
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
        $localFilePath = $this->localPath();

        $stream = $storageDisk->readStream($storageFilePath);

        if (!is_resource($stream)) {
            throw new FailedReadingFromDiskException($storageFilePath, 'storage');
        }

        try {
            if (!$localDisk->writeStream($localFilePath, $stream)) {
                $localDisk->delete($localFilePath);

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
        string $destinationFilePath,
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
                }

                // Separator needs to be null, else each chunk will start on a new line.
                $this->getLocalDisk()->append($destinationFilePath, $chunk, separator: null);
            }

            if (!$this->getLocalDisk()->exists($destinationFilePath) || $this->getLocalDisk()->size($destinationFilePath) === 0) {
                throw new FailedRemoteDatabaseFetchingException('Retrieved empty response from remote dump endpoint.');
            }
        } catch (Throwable $throwable) {
            $this->deleteLocalFiles($destinationFilePath);

            throw $throwable;
        }
    }

    /**
     * @throws FailedWritingMetadataFileException
     * @throws Throwable
     */
    public function writeMetadataFile(string $dumpFilePath, array $metadataPayload, ?Filesystem $disk = null): void
    {
        $disk ??= $this->getStorageDisk();
        $metadataFilePath = $this->metadataFilePath($dumpFilePath);

        try {
            $encodedMetadata = json_encode(['meta' => $metadataPayload], flags: JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

            if ($disk->put($metadataFilePath, $encodedMetadata) === false) {
                throw new FailedWritingMetadataFileException($dumpFilePath);
            }
        } catch (Throwable $throwable) {
            $this->deleteStorageFiles($dumpFilePath, $disk);

            throw $throwable;
        }
    }

    public function deleteLocalFiles(string|array|Collection $paths): void
    {
        $this->deleteFilesWithMetadata($paths, $this->getLocalDisk());
    }

    public function deleteStorageFiles(string|array|Collection $paths, ?Filesystem $disk = null): void
    {
        $this->deleteFilesWithMetadata($paths, $disk ?? $this->getStorageDisk());
    }

    public function flushDumps(?string $excludeFile = null): void
    {
        $this->deleteStorageFiles($this->dumpFiles(excludeFile: $excludeFile));
    }

    public function storagePath(string $filePath): string
    {
        return implode(DIRECTORY_SEPARATOR, [$this->getStorageBaseDirectory(), $filePath]);
    }

    public function localPath(?string $fileName = null): string
    {
        $baseDirectory = $this->getLocalBaseDirectory();

        // Shell commands will not create directories automatically.
        $this->createDirectory($baseDirectory, $this->getLocalDisk());

        return implode(DIRECTORY_SEPARATOR, [$baseDirectory, $fileName ?? uniqid('protector_', true) . '.sql']);
    }

    public function getDownloadDestinationFilePath(string $contentDispositionHeader): string
    {
        if (preg_match('/filename="(?P<filename>.+)"/i', $contentDispositionHeader, $matches)) {
            $destinationFileName = $matches['filename'];
        }

        return sprintf(
            '%s%s%s',
            $this->getStorageBaseDirectory(),
            DIRECTORY_SEPARATOR,
            ($destinationFileName ?? 'remote_dump.sql')
        );
    }

    public function metadataFilePath(string $dumpFilePath): string
    {
        return $dumpFilePath . static::METADATA_FILE_SUFFIX;
    }

    /**
     * @throws FileNotFoundException
     */
    public function dumpFile(string $fileName): string
    {
        $filePathOnDisk = implode(DIRECTORY_SEPARATOR, [$this->getStorageBaseDirectory(), $fileName]);

        $file = $this->dumpFiles()->firstWhere(
            fn($file) => $filePathOnDisk === $file
        );

        if (!$file) {
            throw new FileNotFoundException($filePathOnDisk);
        }

        return $file;
    }

    public function dumpFiles(?string $excludeFile = null): Collection
    {
        $allFiles = $this->getStorageDisk()
            ->allFiles($this->getStorageBaseDirectory());

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
     * @throws EmptyBaseDirectoryException
     */
    public function latestDumpName(): string
    {
        $files = $this->dumpFiles();

        if ($files->isEmpty()) {
            throw new EmptyBaseDirectoryException();
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

    protected function deleteFilesWithMetadata(string|array|Collection $paths, Filesystem $disk): void
    {
        $pathsToDelete = collect($paths)
            ->flatMap(fn(string $filePath) => [$filePath, $this->metadataFilePath($filePath)])
            ->toArray();

        $disk->delete($pathsToDelete);
    }

    /**
     * @throws FailedCreatingDestinationPathException
     */
    protected function createDirectory(string $destinationPath, Filesystem $disk): void
    {
        if ($disk->missing($destinationPath)) {
            if ($disk->makeDirectory($destinationPath) === false) {
                throw new FailedCreatingDestinationPathException(
                    sprintf('Could not create the non-existing destination path %s on given disk.', $destinationPath)
                );
            }
        }
    }

    protected function getConfigValueForKey(string $key, mixed $default = null): mixed
    {
        $value = config(sprintf('protector.%s', $key), $default);

        return is_callable($value) ? $value() : $value;
    }
}
