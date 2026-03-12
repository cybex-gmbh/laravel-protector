<?php

namespace Cybex\Protector\Classes;

use Cybex\Protector\Contracts\Crypter;

class SodiumCrypter implements Crypter
{
    public function createPrivateKey(): string
    {
        return sodium_bin2hex(sodium_crypto_box_keypair());
    }

    public function getPublicKeyFromPrivateKey(string $privateKey): string
    {
        return sodium_bin2hex(sodium_crypto_box_publickey(sodium_hex2bin($privateKey)));
    }

    public function getPublicKeyFromUser($user): ?string
    {
        return $user?->protector_public_key;
    }

    public function encrypt(string $data, string $publicKey): string
    {
        return sodium_crypto_box_seal($data, sodium_hex2bin($publicKey));
    }

    public function decrypt(string $data, string $privateKey): string|false
    {
        return sodium_crypto_box_seal_open($data, sodium_hex2bin($privateKey));
    }
}
