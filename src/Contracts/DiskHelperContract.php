<?php

namespace Cybex\Protector\Contracts;

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
use Psr\Http\Message\StreamInterface;
use Throwable;

interface DiskHelperContract
{
    public function getLocalDisk(): Filesystem;

    public function getStorageDisk(): Filesystem;

    public function getLocalDiskName(): string;

    public function getStorageDiskName(): string;

    public function getLocalBaseDirectory(): string;

    public function getStorageBaseDirectory(): string;

    /**
     * This will by default delete local files after completing or on error.
     * If $keepLocalFile is set to true, it will keep the local file on success.
     *
     * @throws FailedReadingFromDiskException
     * @throws FailedWritingToDiskException
     * @throws EmptyFileWrittenException
     * @throws Throwable
     */
    public function moveLocalToStorage(
        string $localFilePath,
        string $destinationFilePath,
        ?Filesystem $storageDisk = null,
        bool $keepLocalFile = false,
    ): void;

    /**
     * @throws FailedReadingFromDiskException
     * @throws FailedWritingToDiskException
     * @throws FailedCreatingDestinationPathException
     */
    public function copyStorageToLocal(string $storageFilePath, ?Filesystem $storageDisk = null): string;

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
    ): void;

    /**
     * @throws FailedWritingMetadataFileException
     * @throws Throwable
     */
    public function writeMetadataFile(string $dumpFilePath, array $metadataPayload, ?Filesystem $storageDisk = null): void;

    /**
     * This will delete a dump file including its .meta file on the local disk.
     */
    public function deleteLocalFile(string $path): void;

    /**
     * This will delete a dump file including its .meta file on the passed disk (defaults to the storage disk).
     */
    public function deleteStorageFile(string $path, ?Filesystem $storageDisk = null): void;

    /**
     * Deletes all files in the configured dump directory on the storage disk.
     * Can optionally exclude a file from deletion (including its .meta file).
     *
     * @param string|null $excludeFile The relative file path on the storage disk to exclude from deletion.
     */
    public function flushDumps(?string $excludeFile = null): void;

    public function storagePath(string $filePath): string;

    public function localPath(?string $fileName = null): string;

    public function getDownloadDestinationFilePath(string $contentDispositionHeader): string;

    public function metadataFilePath(string $dumpFilePath): string;

    /**
     * @throws FileNotFoundException
     */
    public function dumpFile(string $fileName): string;

    public function dumpFiles(?string $excludeFile = null): Collection;

    public function dumpFilesWithMetadata(): Collection;

    /**
     * @throws EmptyBaseDirectoryException
     */
    public function latestDumpName(): string;

    public function isAbsolutePath(string $filePath): bool;
}
