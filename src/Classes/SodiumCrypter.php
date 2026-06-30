<?php

namespace Cybex\Protector\Classes;

use Cybex\Protector\Contracts\CrypterContract;
use Illuminate\Contracts\Auth\Authenticatable;
use SodiumException;

class SodiumCrypter implements CrypterContract
{
    /**
     * @throws SodiumException
     */
    public function createPrivateKey(): string
    {
        return sodium_bin2hex(sodium_crypto_box_keypair());
    }

    /**
     * @throws SodiumException
     */
    public function getPublicKeyFromPrivateKey(string $privateKey): string
    {
        return sodium_bin2hex(sodium_crypto_box_publickey(sodium_hex2bin($privateKey)));
    }

    public function getPublicKeyFromUser(?Authenticatable $user): ?string
    {
        return $user?->protector_public_key;
    }

    /**
     * @throws SodiumException
     */
    public function encrypt(string $data, string $publicKey): string
    {
        return sodium_crypto_box_seal($data, sodium_hex2bin($publicKey));
    }

    /**
     * @throws SodiumException
     */
    public function decrypt(string $data, string $privateKey): string|false
    {
        return sodium_crypto_box_seal_open($data, sodium_hex2bin($privateKey));
    }

    /**
     * @throws SodiumException
     */
    public function determineEncryptionOverhead(int $chunkSize, string $publicKey): int
    {
        $chunk = str_repeat('0', $chunkSize);
        $encryptedChunk = $this->encrypt($chunk, $publicKey);

        return strlen($encryptedChunk) - $chunkSize;
    }
}
