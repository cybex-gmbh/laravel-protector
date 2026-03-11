<?php

namespace Cybex\Protector\Contracts;

interface Crypter
{
    public function createPrivateKey(): string;

    public function getPublicKeyFromPrivateKey(string $privateKey): string;

    public function getPublicKeyFromUser($user): ?string;

    public function encrypt(string $data, string $publicKey): string;

    public function decrypt(string $data, string $privateKey): string|false;
}
