<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Core\Config;
use Core\Crypto;

final class CryptoTest extends TestCase {

    private static $config = null;

    public function testCanPerformEncryptionUsingOpensslAes128(): void {
        self::$config = Config::reloadInstance(__DIR__ . '/../env/openssl-aes128-bcrypt.env');
        $this->assertSame(
            'abc12345',
            Crypto::decrypt(Crypto::encrypt('abc12345'))
        );
    }

    public function testCanPerformEncryptionUsingOpensslAes256(): void {
        self::$config = Config::reloadInstance(__DIR__ . '/../env/openssl-aes256-argon2i.env');
        $this->assertSame(
            'abc12345',
            Crypto::decrypt(Crypto::encrypt('abc12345'))
        );
    }

    public function testCanPerformEncryptionUsingSodiumXchacha20(): void {
        self::$config = Config::reloadInstance(__DIR__ . '/../env/sodium.env');
        $this->assertSame(
            'abc12345',
            Crypto::decrypt(Crypto::encrypt('abc12345'))
        );
    }
    
}
