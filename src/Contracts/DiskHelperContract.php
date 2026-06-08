<?php

namespace Cybex\Protector\Contracts;

use Cybex\Protector\Exceptions\EmptyBaseDirectoryException;
use Cybex\Protector\Exceptions\EmptyFileWrittenException;
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
        ?Filesystem $disk = null,
        bool $keepLocalFile = false,
    ): void;

    /**
     * @throws FailedReadingFromDiskException
     * @throws FailedWritingToDiskException
     */
    public function copyStorageToLocal(string $storageFilePath, ?Filesystem $storageDisk = null): string;

    /**
     * @throws FailedRemoteDatabaseFetchingException
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
     */
    public function writeMetadataFile(string $dumpFilePath, array $metadataPayload, ?Filesystem $disk = null): void;

    public function deleteLocalFiles(string|array|Collection $paths): void;

    public function deleteStorageFiles(string|array|Collection $paths, ?Filesystem $disk = null): void;

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
