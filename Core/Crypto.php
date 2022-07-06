<?php

/**
 * Cryptography functions
 * This file is part of the SMTP Mailer service.
 *
 * @license MIT
 * (c) 2022 Eric Chow <https://cmchow.com>
 * License at https://opensource.org/licenses/MIT
 */

namespace Core;

use Core\Config;

class Crypto {

    /**
     * Tag length for OpenSSL encryption
     * @var int
     */
    private const OPENSSL_TAG_LENGTH = 16;

    /**
     * Authenticated OpenSSL Cryptography (AES-128-GCM)
     * @param bool $isEncrypt encrypt or decrypt
     * @param string $data  string to be encrypted or decrypted
     * @return string|false return a base64 encoded encrypted string or decrypted text, false when failed
     */
    private static function crypto_AES128_GCM(bool $isEncrypt, string $data) {
        $output = '';
        $method = 'AES-128-GCM';
        $key = base64_decode(Config::getSecretKey());
        $iv_length = openssl_cipher_iv_length($method);

        if ($isEncrypt) {
            $iv = openssl_random_pseudo_bytes($iv_length);
            $tag = ''; // openssl_encrypt will fill this
            $result = openssl_encrypt($data, $method, $key, OPENSSL_RAW_DATA, $iv, $tag, '', self::OPENSSL_TAG_LENGTH);
            if ($result !== false) {
                $output = base64_encode($iv . $tag . $result);
            } else {
                $output = $result;
            }
        } else {
            $data = base64_decode($data);
            $iv = substr($data, 0, $iv_length);
            $tag = substr($data, $iv_length, self::OPENSSL_TAG_LENGTH);
            $text = substr($data, $iv_length + self::OPENSSL_TAG_LENGTH);
            $output = openssl_decrypt($text, $method, $key, OPENSSL_RAW_DATA, $iv, $tag);
        }
        return $output;
    }

    /**
     * Authenticated OpenSSL Cryptography (AES-256-GCM)
     * @param bool $isEncrypt encrypt or decrypt
     * @param string $data  string to be encrypted or decrypted
     * @return string|false return a base64 encoded encrypted string or decrypted text, false when failed
     */
    private static function crypto_AES256_GCM(bool $isEncrypt, string $data) {
        $output = '';
        $method = 'AES-256-GCM';
        $key = base64_decode(Config::getSecretKey());
        $iv_length = openssl_cipher_iv_length($method);

        if ($isEncrypt) {
            $iv = openssl_random_pseudo_bytes($iv_length);
            $tag = ''; // openssl_encrypt will fill this
            $result = openssl_encrypt($data, $method, $key, OPENSSL_RAW_DATA, $iv, $tag, '', self::OPENSSL_TAG_LENGTH);
            if ($result !== false) {
                $output = base64_encode($iv . $tag . $result);
            } else {
                $output = $result;
            }
        } else {
            $data = base64_decode($data);
            $iv = substr($data, 0, $iv_length);
            $tag = substr($data, $iv_length, self::OPENSSL_TAG_LENGTH);
            $text = substr($data, $iv_length + self::OPENSSL_TAG_LENGTH);
            $output = openssl_decrypt($text, $method, $key, OPENSSL_RAW_DATA, $iv, $tag);
        }
        return $output;
    }

    /**
     * Cryptography with Sodium extension (XChaCha20-Poly1305 - IETF)
     * @param bool $isEncrypt encrypt or decrypt
     * @param string $data  string to be encrypted or decrypted
     * @return string|false return a base64 encoded encrypted string or decrypted text, false when failed
     */
    private static function crypto_XChaCha20_Poly1305(bool $isEncrypt, string $data) {
        $output = '';
        $key = sodium_base642bin(Config::getSecretKey(), SODIUM_BASE64_VARIANT_ORIGINAL);
        $nonceLength = \SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES;

        try {
            if ($isEncrypt) {
                $nonce = \random_bytes($nonceLength);
                $result = sodium_crypto_aead_xchacha20poly1305_ietf_encrypt($data, '', $nonce, $key);
                $output = sodium_bin2base64($nonce . $result, SODIUM_BASE64_VARIANT_ORIGINAL);
            } else {
                $data = sodium_base642bin($data, SODIUM_BASE64_VARIANT_ORIGINAL);
                $nonce = substr($data, 0, $nonceLength);
                $text = substr($data, $nonceLength);
                $output = sodium_crypto_aead_xchacha20poly1305_ietf_decrypt($text, '', $nonce, $key);
            }
        } catch (\Throwable $e) {
            Logger::log('debug', "crypto exception: {$e->getMessage()}");
            $output = false;
        }
        return $output;
    }

    /**
     * Authenticated OpenSSL Encryption (AES-128-GCM)
     * @param string $data string to be encrypted
     * @return string|false return a base64 encoded encrypted string, false when failed
     */
    private static function encryptAES128(string $data) {
        return self::crypto_AES128_GCM(true, $data);
    }

    /**
     * Authenticated OpenSSL Decryption (AES-128-GCM)
     * @param string $data string to be decrypted
     * @return string|false return a decrypted text, false when failed
     */
    private static function decryptAES128(string $data) {
        return self::crypto_AES128_GCM(false, $data);
    }

    /**
     * Authenticated OpenSSL Encryption (AES-256-GCM)
     * @param string $data string to be encrypted
     * @return string|false return a base64 encoded encrypted string, false when failed
     */
    private static function encryptAES256(string $data) {
        return self::crypto_AES256_GCM(true, $data);
    }

    /**
     * Authenticated OpenSSL Decryption (AES-256-GCM)
     * @param string $data string to be decrypted
     * @return string|false return a decrypted text, false when failed
     */
    private static function decryptAES256(string $data) {
        return self::crypto_AES256_GCM(false, $data);
    }

    /**
     * Encryption with Sodium extension (XChaCha20-Poly1305 - IETF)
     * @param string $data string to be encrypted
     * @return string|false return a base64 encoded encrypted string, false when failed
     */
    private static function encryptXChaCha20(string $data) {
        return self::crypto_XChaCha20_Poly1305(true, $data);
    }

    /**
     * Decryption with Sodium extension (XChaCha20-Poly1305 - IETF)
     * @param string $data string to be decrypted
     * @return string|false return a decrypted text, false when failed
     */
    private static function decryptXChaCha20(string $data) {
        return self::crypto_XChaCha20_Poly1305(false, $data);
    }


    /**
     * Data Encryption
     * @param string $data string to be encrypted
     * @return string|false return a base64 encoded encrypted string, false when failed
     */
    public static function encrypt(string $data) {
        switch (Config::getEnv('QUEUE_ENCRYPT_METHOD')) {
            case 'AES256':
                return self::encryptAES256($data);
                break;
            case 'XChaCha20':
                return self::encryptXChaCha20($data);
                break;
            default:
                return self::encryptAES128($data);
        }
    }

    /**
     * Data Decryption
     * @param string $data string to be decrypted
     * @return string|false return a decrypted text, false when failed
     */
    public static function decrypt(string $data) {
        switch (Config::getEnv('QUEUE_ENCRYPT_METHOD')) {
            case 'AES256':
                return self::decryptAES256($data);
                break;
            case 'XChaCha20':
                return self::decryptXChaCha20($data);
                break;
            default:
                return self::decryptAES128($data);
        }
    }
}
