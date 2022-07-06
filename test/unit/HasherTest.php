<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Core\Config;
use Core\Hasher;

final class HasherTest extends TestCase {

    private static $config = null;

    public function testCanVerifyHashUsingPhpBcrypt(): void {
        self::$config = Config::reloadInstance(__DIR__ . '/../env/openssl-aes128-bcrypt.env');
        $this->assertSame(
            true,
            Hasher::verify('abc12345')
        );
    }

    public function testCanVerifyHashUsingPhpArgon2i(): void {
        self::$config = Config::reloadInstance(__DIR__ . '/../env/openssl-aes256-argon2i.env');
        $this->assertSame(
            true,
            Hasher::verify('abc12345')
        );
    }

    public function testCanVerifyHashUsingSodiumArgon2id(): void {
        self::$config = Config::reloadInstance(__DIR__ . '/../env/sodium.env');
        $this->assertSame(
            true,
            Hasher::verify('abc12345')
        );
    }

}
