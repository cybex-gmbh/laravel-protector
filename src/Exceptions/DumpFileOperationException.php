<?php

namespace Cybex\Protector\Exceptions;

use Exception;

class DumpFileOperationException extends Exception
{
    public static function missingPrivateKeyForEncryptedStream(): self
    {
        return new self('Private key is required to decrypt encrypted dump chunks.');
    }

    public static function couldNotDecryptStreamChunk(): self
    {
        return new self('Could not decrypt remote dump chunk.');
    }

    public static function emptyStreamResponse(): self
    {
        return new self('Retrieved empty response');
    }

    public static function couldNotReadLocalFile(): self
    {
        return new self('Could not read file from local disk.');
    }

    public static function couldNotWriteFileToDisk(): self
    {
        return new self('Could not write file to destination disk.');
    }

    public static function destinationFileIsEmptyAfterWrite(): self
    {
        return new self('Writing to destination disk resulted in an empty file.');
    }
}

