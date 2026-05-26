<?php

namespace Cybex\Protector\Classes;

use Cybex\Protector\Contracts\CrypterContract;
use Cybex\Protector\Contracts\DiskHelperContract;
use Cybex\Protector\Exceptions\DumpFileOperationException;
use Cybex\Protector\Exceptions\EmptyBaseDirectoryException;
use Cybex\Protector\Exceptions\FailedCreatingDestinationPathException;
use Cybex\Protector\Exceptions\FailedWritingMetadataFileException;
use Cybex\Protector\Exceptions\FileNotFoundException;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Psr\Http\Message\StreamInterface;

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
        return $this->getConfigValueForKey('dump.disks.local.disk', 'local');
    }

    public function getStorageDiskName(): string
    {
        return $this->getConfigValueForKey('dump.disks.storage.disk', config('filesystems.default'));
    }

    public function getLocalBaseDirectory(): string
    {
        return $this->getConfigValueForKey('dump.disks.local.baseDirectory') ?? '';
    }

    public function getStorageBaseDirectory(): string
    {
        return $this->getConfigValueForKey('dump.disks.storage.baseDirectory') ?? '';
    }

    public function storagePath(string $filePath): string
    {
        return implode(DIRECTORY_SEPARATOR, [$this->getStorageBaseDirectory(), $filePath]);
    }

    public function localPath(?string $fileName = null): string
    {
        $localDisk = $this->getLocalDisk();
        $baseDirectory = $this->getLocalBaseDirectory();

        $this->createDirectory($baseDirectory, $localDisk);

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

    public function isAbsolutePath(string $filePath): bool
    {
        return Str::startsWith($filePath, DIRECTORY_SEPARATOR)
            || preg_match('/^[A-Za-z]:[\\\\\/]/', $filePath) === 1;
    }

    public function deleteLocalFiles(string|array $paths): void
    {
        $this->getLocalDisk()->delete($paths);
    }

    public function deleteStorageFiles(string|array $paths): void
    {
        $this->getStorageDisk()->delete($paths);
    }

    public function copyStorageToLocal(string $storageFilePath, ?Filesystem $storageDisk = null): string
    {
        $storageDisk ??= $this->getStorageDisk();
        $localDisk = $this->getLocalDisk();
        $localFilePath = $this->localPath();

        $stream = $storageDisk->readStream($storageFilePath);

        if (!is_resource($stream)) {
            throw new FileNotFoundException($storageFilePath);
        }

        if (!$localDisk->writeStream($localFilePath, $stream)) {
            $localDisk->delete($localFilePath);
        }

        fclose($stream);

        return $localFilePath;
    }

    public function dumpFiles(?string $excludeFile = null): Collection
    {
        $allFiles = $this->getStorageDisk()
            ->allFiles($this->getStorageBaseDirectory());

        return collect($allFiles)
            ->reject(fn(string $filePath) => $this->isMetadataFile($filePath))
            ->when($excludeFile, fn($collection) => $collection->diff([$excludeFile]));
    }

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

    public function dumpFilesWithMetadata(): Collection
    {
        return $this->dumpFiles()->mapWithKeys(
            fn(string $dumpFilePath) => [$dumpFilePath => $this->getMetadataFileContents($dumpFilePath) ?? []]
        );
    }

    public function writeMetadataFile(?Filesystem $disk, string $dumpFilePath, array $metadataPayload): void
    {
        $disk ??= $this->getStorageDisk();
        $metadataFilePath = $this->metadataFilePath($dumpFilePath);
        $encodedMetadata = json_encode(['meta' => $metadataPayload], JSON_UNESCAPED_UNICODE);

        if ($disk->put($metadataFilePath, $encodedMetadata) === false) {
            $disk->delete([$dumpFilePath, $metadataFilePath]);

            throw new FailedWritingMetadataFileException($dumpFilePath);
        }
    }

    public function latestDumpName(): string
    {
        $files = $this->dumpFiles();

        if ($files->isEmpty()) {
            throw new EmptyBaseDirectoryException();
        }

        $disk = $this->getStorageDisk();

        return $files->sortByDesc(fn($file) => $disk->lastModified($file))->values()->firstOrFail();
    }

    public function flushDumps(?string $excludeFile = null): void
    {
        $files = $this->dumpFiles($excludeFile)
            ->flatMap(fn(string $dumpFilePath) => [$dumpFilePath, $this->metadataFilePath($dumpFilePath)]);

        $this->deleteStorageFiles($files->toArray());
    }

    public function writeStreamToLocalFile(
        StreamInterface $stream,
        string $destinationFilePath,
        int $chunkSize,
        bool $shouldEncrypt = false,
        ?string $privateKey = null,
        ?string $emptyResponseContext = null,
    ): void
    {
        $outputHandle = fopen($this->getLocalDisk()->path($destinationFilePath), 'wb');

        while (!$stream->eof() && ($chunk = $stream->read($chunkSize)) !== '') {
            if ($shouldEncrypt) {
                $chunk = app(CrypterContract::class)->decrypt($chunk, $privateKey);
            }

            fwrite($outputHandle, $chunk);
        }

        fclose($outputHandle);

        if ($this->getLocalDisk()->size($destinationFilePath) === 0) {
            $this->deleteLocalFiles($destinationFilePath);

            throw DumpFileOperationException::emptyStreamResponse();
        }
    }

    public function copyLocalFileToDisk(
        string $localFilePath,
        string $destinationFilePath,
        ?Filesystem $disk = null,
    ): void
    {
        $disk ??= $this->getStorageDisk();
        $localDisk = $this->getLocalDisk();
        $localFileStream = $localDisk->readStream($localFilePath);

        if (!is_resource($localFileStream)) {
            throw DumpFileOperationException::couldNotReadLocalFile();
        }

        try {
            if (!$disk->writeStream($destinationFilePath, $localFileStream)) {
                $disk->delete($destinationFilePath);

                throw DumpFileOperationException::couldNotWriteFileToDisk();
            }
        } finally {
            fclose($localFileStream);
        }

        if ($disk->size($destinationFilePath) === 0) {
            $disk->delete($destinationFilePath);

            throw DumpFileOperationException::destinationFileIsEmptyAfterWrite();
        }
    }

    protected function isMetadataFile(string $filePath): bool
    {
        return Str::endsWith($filePath, static::METADATA_FILE_SUFFIX);
    }

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

    protected function getConfigValueForKey(string $key, mixed $default = null): mixed
    {
        $value = config(sprintf('protector.%s', $key), $default);

        return is_callable($value) ? $value() : $value;
    }
}
