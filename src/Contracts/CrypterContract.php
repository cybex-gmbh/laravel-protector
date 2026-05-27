<?php

namespace Cybex\Protector\Contracts;

use Illuminate\Contracts\Auth\Authenticatable;

interface CrypterContract
{
    public function createPrivateKey(): string;

    public function getPublicKeyFromPrivateKey(string $privateKey): string;

    public function getPublicKeyFromUser(Authenticatable $user): ?string;

    public function encrypt(string $data, string $publicKey): string;

    public function decrypt(string $data, string $privateKey): string|false;

    public function determineEncryptionOverhead(int $chunkSize, string $publicKey): int;
}
