<?php

namespace Cybex\Protector\Contracts;

use Cybex\Protector\Exceptions\EmptyDumpDirectoryException;
use Cybex\Protector\Exceptions\EmptyFileWrittenException;
use Cybex\Protector\Exceptions\FailedReadingFromDiskException;
use Cybex\Protector\Exceptions\FailedRemoteDatabaseFetchingException;
use Cybex\Protector\Exceptions\FailedWritingMetadataFileException;
use Cybex\Protector\Exceptions\FailedWritingToDiskException;
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
        string $localFileName,
        string $storageFileName,
        Filesystem $storageDisk,
        bool $keepLocalFile = false,
    ): void;

    /**
     * @throws FailedReadingFromDiskException
     * @throws FailedWritingToDiskException
     */
    public function copyStorageToLocal(string $storageFilePath, Filesystem $storageDisk): string;

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
    ): void;

    /**
     * @throws FailedWritingMetadataFileException
     * @throws Throwable
     */
    public function writeMetadataFile(string $dumpFileName, array $metadataPayload, Filesystem $storageDisk): void;

    /**
     * This will delete a dump file including its .meta file on the local disk.
     */
    public function deleteLocalFile(string $name): void;

    /**
     * This will delete a dump file including its .meta file on the passed disk (defaults to the storage disk).
     */
    public function deleteStorageFile(string $name, Filesystem $storageDisk): void;

    /**
     * Deletes all files in the configured dump directory on the storage disk.
     * Can optionally exclude a file from deletion (including its .meta file).
     *
     * @param string|null $excludeFile The relative file path on the storage disk to exclude from deletion.
     */
    public function flushDumps(?string $excludeFile = null): void;

    public function createLocalFileName(): string;

    public function getDownloadDestinationFileName(string $contentDispositionHeader): string;

    public function metadataFileName(string $dumpFileName): string;

    public function dumpFiles(?string $excludeFile = null): Collection;

    public function dumpFilesWithMetadata(): Collection;

    /**
     * @throws EmptyDumpDirectoryException
     */
    public function latestDumpName(): string;

    public function isBaseName(string $file): bool;
}
