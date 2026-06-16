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
        string $localFileName,
        string $storageFileName,
        Filesystem $storageDisk,
        bool $keepLocalFile = false,
    ): void
    {
        $localFileStream = $this->getLocalDisk()->readStream($localFileName);

        try {
            if (!is_resource($localFileStream)) {
                throw new FailedReadingFromDiskException($localFileName, 'local');
            }

            if (!$storageDisk->writeStream($storageFileName, $localFileStream)) {
                throw new FailedWritingToDiskException($storageFileName, 'storage');
            }

            if ($storageDisk->size($storageFileName) === 0) {
                throw new EmptyFileWrittenException($storageFileName, 'storage');
            }
        } catch (Throwable $throwable) {
            $this->deleteLocalFile($localFileName);
            $this->deleteStorageFile($storageFileName, $storageDisk);

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
    public function copyStorageToLocal(string $storageFilePath, Filesystem $storageDisk): string
    {
        $localDisk = $this->getLocalDisk();
        $localFileName = $this->createLocalFileName();

        $stream = $storageDisk->readStream($storageFilePath);

        if (!is_resource($stream)) {
            throw new FailedReadingFromDiskException($storageFilePath, 'storage');
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
    public function writeMetadataFile(string $dumpFileName, array $metadataPayload, Filesystem $storageDisk): void
    {
        $metadataFileName = $this->metadataFileName($dumpFileName);

        try {
            $encodedMetadata = json_encode(['meta' => $metadataPayload], flags: JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

            if ($storageDisk->put($metadataFileName, $encodedMetadata) === false) {
                throw new FailedWritingMetadataFileException($dumpFileName);
            }
        } catch (Throwable $throwable) {
            $this->deleteStorageFile($dumpFileName, $storageDisk);

            throw $throwable;
        }
    }

    /**
     * @inheritDoc
     */
    public function deleteLocalFile(string $name): void
    {
        $this->deleteDumpAndMetaFiles($name, $this->getLocalDisk());
    }

    /**
     * @inheritDoc
     */
    public function deleteStorageFile(string $name, Filesystem $storageDisk): void
    {
        $this->deleteDumpAndMetaFiles($name, $storageDisk);
    }

    /**
     * @inheritDoc
     */
    public function flushDumps(?string $excludeFile = null): void
    {
        // Only delete files which have a .meta file and are not in a directory.
        $this->deleteDumpAndMetaFiles(
            names: $this->dumpFiles(excludeFile: $excludeFile),
            disk: $this->getStorageDisk());
    }

    public function createLocalFileName(): string
    {
        return sprintf('%s.sql', uniqid('protector_', true));
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
        $dumpFiles = $this->getStorageDisk()->files();

        return collect($dumpFiles)
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

        $disk = $this->getStorageDisk();

        return $files->sortByDesc($disk->lastModified(...))->values()->firstOrFail();
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

    protected function deleteDumpAndMetaFiles(string|array|Collection $names, Filesystem $disk): void
    {
        $filesToDelete = collect($names)
            ->flatMap($this->getDumpAndMetadataFile(...))
            ->toArray();

        $disk->delete($filesToDelete);
    }

    protected function getDumpAndMetadataFile(string $fileName): array
    {
        return [$fileName, $this->metadataFileName($fileName)];
    }
}
