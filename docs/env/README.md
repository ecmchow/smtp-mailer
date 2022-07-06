<p align="center"><img src="../../docs/mailer.svg?raw=true" width="128"></p>

<h3 align="center">SMTP Mailer</h3>

<p align="center">
    Env documentation
    <br />
    <a href="../../README.md"><strong>Back to Home »</strong></a>
    <br />
</p>

<!-- TABLE OF CONTENTS -->
<details>
  <summary>Table of Contents</summary>
  <ol>
    <li><a href="#introduction">Introduction</a></li>
    <li>
      <a href="#env-parameters">Env parameters</a>
      <ul>
        <li><a href="#basic-service-configuration">Basic service configuration</a></li>
        <li><a href="#service-authentication">Service authentication</a></li>
        <li><a href="#redis-settings">Redis settings</a></li>
        <li><a href="#service-queue-settings">Service queue settings</a></li>
        <li><a href="#default-smtp-credentials">Default SMTP credentials</a></li>
        <li><a href="#default-mail-settings">Default Mail settings</a></li>
        <li><a href="#mail-template-settings">Mail template settings</a></li>
      </ul>
    </li>
  </ol>
</details>

<br/>

## Introduction

SMTP Mailer service require an env file in INI format. An example env file is included:
* [.env.example](../../.env.example)

<br/>

## Env parameters

### Basic service configuration

| Parameter | Type | Default | Description |
| :--- | :--- | :--- | :--- |
| `MAILER_PROTO` | `string` | "tcp" | **Required**. Service listening protocol. (`tcp` or `ssl`)  |
| `MAILER_ADDR` | `string` | "127.0.0.1" | **Required**. Service listening address.  |
| `MAILER_PORT` | `int` | 3000 | **Required**. Service listening port number. |
| `MAILER_SSL_CERT` | `string` | "" | **Optional**. SSL certificate filepath. E.g. "/path/to/selfsigned.crt"  |
| `MAILER_SSL_KEY` | `string` | "" | **Optional**. SSL private key filepath. E.g. "/path/to/selfsigned.key"  |
| `MAILER_WORKERS` | `int` | 4 | **Required**. Number of spawned service workers. |
| `MAILER_MAX_MEMORY` | `int` | 64 (in MB) | **Optional**. Service will auto-restart if memory usage exceeded this value (in MB)  |
| `MAILER_MAX_REQUEST` | `int` | -1 | **Optional**. Service will auto-restart if number of processed request exceeded this value. -1 to disable limit  |
| `MAILER_RESTART_CRON` | `string` | "" | **Optional**. Service will auto-restart according to Cron pattern ("0 1 * * *" to auto-restart on every 01:00). empty to disable  |
| `MAILER_TIMEOUT` | `int` | 300 (in secs) | **Optional**. SMTP Timeout. PHPMailer default is 5 mins with compliance to RFC2821 |
| `MAILER_LOG` | `bool` | true | **Optional**. Enable service logging  |
| `MAILER_LOG_LEVEL` | `string` | "notice" | **Optional**. Service logging level. ("debug", "info", "notice", "warning" or "error") |
| `MAILER_LOG_OUTPUT` | `string` | "error_log" | **Optional**. Service logging output. "error_log" to use PHP default error_log settings or "syslog" to output log directly to system log  |

### Service authentication

| Parameter | Type | Default | Description |
| :--- | :--- | :--- | :--- |
| `MAILER_AUTH` | `bool` | false | **Required**. Enable password authentication on service API  |
| `MAILER_AUTH_HASH_METHOD` | `string` | "bcrypt" | **Optional**. Authentication password hash method ("bcrypt", "argon2i" or "sodium")  |
| `MAILER_AUTH_HASH` | `string` | "" | **Optional**. Authentication password hash  |

For compatibility, `bcrypt` should be supported on all PHP installation. Use `sodium` which use argon2id hash method if your PHP installation supports sodium extension or alternatively `argon2i` for better security.

To generate password hash, simply use PHP CLI with your selected method.

Sodium argon2id
```console
php -r "echo sodium_crypto_pwhash_str('yourPassword', SODIUM_CRYPTO_PWHASH_OPSLIMIT_INTERACTIVE, SODIUM_CRYPTO_PWHASH_MEMLIMIT_INTERACTIVE);"
```

PHP argon2i
```console
php -r "echo password_hash('yourPassword', PASSWORD_ARGON2I);"
```

PHP bcrypt (use cost >= 10 as recommended by [OWASP](https://cheatsheetseries.owasp.org/cheatsheets/Password_Storage_Cheat_Sheet.html))
```console
php -r "echo password_hash('yourPassword', PASSWORD_BCRYPT, ['cost' => 11]);"
```

### Redis settings

| Parameter | Type | Default | Description |
| :--- | :--- | :--- | :--- |
| `REDIS_ENABLE` | `bool` | true | **Required**. Enable Redis integration  |
| `REDIS_PROTO` | `string` | "tcp" | **Optional**. Redis listening protocol. (`tcp` or `tls`)  |
| `REDIS_ADDR` | `string` | "127.0.0.1" | **Optional**. Redis listening address.  |
| `REDIS_PORT` | `int` | 6379 | **Optional**. Redis listening port number. |
| `REDIS_KEY_PREFIX` | `string` | "SMTP_MAILER:" | **Optional**. Redis key prefix.  |
| `REDIS_TIMEOUT` | `int` | 0 (in secs) | **Optional**. Redis connection timeout. 0 for unlimited |
| `REDIS_RETRY_INTERVAL` | `int` | 100 (in ms) | **Optional**. Redis retry interval. |
| `REDIS_READ_TIMEOUT` | `int` | 10 (in secs) | **Optional**. Redis read timeout. 0 for unlimited |
| `REDIS_STORE_QUEUE` | `bool` | false | **Optional**. Use Redis to store queue data  |
| `REDIS_STORE_TEMPLATE` | `bool` | false | **Optional**. Use Redis to store template data  |
| `REDIS_RESET_STATS_ON_START` | `bool` | true | **Optional**. Reset all service status on worker start  |
| `REDIS_USER` | `string` | "" | **Optional**. Redis ACL user name |
| `REDIS_PASSWORD` | `string` | "" | **Optional**. Redis ACL user password |

### Service queue settings

| Parameter | Type | Default | Description |
| :--- | :--- | :--- | :--- |
| `MAILER_QUEUE` | `bool` | true | **Required**. Enable queue service for mail queuing and scheduling functions. Queue service is required if you wish to enable auto retry on failed mail delivery  |
| `MAILER_QUEUE_API_READ_ONLY` | `bool` | true | **Optional**. API can perform read operations only other than adding mail to queue (i.e. cannot edit or remove mail in queue)  |
| `QUEUE_SCAN_MODE` | `string` | "interval" | **Optional**. Queue scanning mode ("interval" for QUEUE_SCAN_INTERVAL or "cron" for QUEUE_SCAN_CRON, see below notes) |
| `QUEUE_SCAN_CRON` | `string` | "" | **Optional**. Scan according to Cron pattern ("*/1 * * * *" to scan on every minute) |
| `QUEUE_SCAN_INTERVAL` | `int` | 60 (in secs) | **Optional**. Interval for scanning queue directory (in secs, min. 10 secs) |
| `QUEUE_MAX_FAILED_RETRY` | `int` | 1 | **Optional**. Maximum retry of failed mail. 0 to disable, -1 for infinite retry (this may block new queued mail from sending out)  |
| `QUEUE_DIR` | `string` | "Queue/mail/" | **Optional**. Directory for storing queued mail. Absolute path or relative path (relative to start-mailer.php or PHAR file directory)   |
| `QUEUE_PROCESS_DIR` | `string` | "Queue/temp/" | **Optional**. Directory for storing processing mail. Absolute path or relative path (relative to start-mailer.php or PHAR file directory)   |
| `QUEUE_FULL_ENCRYPT` | `bool` | false | **Optional**. Enable full document encryption on queue JSON files. SMTP password will still be encrypted when disabled |
| `QUEUE_ENCRYPT_METHOD` | `string` | "AES128" | **Optional**. Encryption method ("AES128", "AES256" or "XChaCha20")  |
| `SECRET_KEY` | `string` | "" | **Optional**. Base64 encoded encryption secret key |


#### Queue scan interval

You can choose to use a interval-based or Cron-based `QUEUE_SCAN_MODE`. For example, if you start the worker at 12:30:25 (HH:MM:SS), the next scan will be at 12:31:25, 12:32:25... in "interval" mode (60 secs in `QUEUE_SCAN_INTERVAL`), while the next scan will be at 12:31:00, 12:32:00... in "cron" mode ("*/1 * * * *" in `QUEUE_SCAN_CRON`). "interval" mode is best suited for a single server setup or when you need secs level precision, while "cron" mode can be useful when you have a multi-node setup and need to coordinate the scanning schedule of mailing service with minutes level precision.

**Please noted that the increase of scanning interval will delay the sending of scheduled mail, 30 - 120 secs interval is recommended to send your mail as close as its scheduled time**

#### Queue encryption

`AES128` and `AES256` uses OpenSSL AES encryption which most hardware nowadays support AES acceleration. Use `XChaCha20` for XChaCha20-poly1305 IETF encryption if your PHP installation supports sodium extension and you wish to use a more performant encryption method on a device without AES acceleration.

To generate a base64 encryption secret key, simply use PHP CLI with your selected method.

OpenSSL AES128
```console
php -r "echo base64_encode(openssl_random_pseudo_bytes(16));""
```

OpenSSL AES256
```console
php -r "echo base64_encode(openssl_random_pseudo_bytes(32));""
```

Sodium XChaCha20-poly1305 IETF
```console
php -r "echo sodium_bin2base64(sodium_crypto_aead_xchacha20poly1305_ietf_keygen(), SODIUM_BASE64_VARIANT_ORIGINAL);"
```

### Default SMTP credentials

| Parameter | Type | Default | Description |
| :--- | :--- | :--- | :--- |
| `SMTP_HOST` | `string` | "" | **Required**. SMTP host (e.g. smtp.gmail.com)  |
| `SMTP_USER` | `string` | "" | **Required**. SMTP user (e.g. example@gmail.com) |
| `SMTP_PASSWORD` | `string` | "" | **Required**. SMTP password  |
| `SMTP_ENCRYPTION` | `string` | "tls" | **Optional**. SMTP encryption protocol ("tls" or "ssl")  |
| `SMTP_PORT` | `int` | 587 | **Optional**. SMTP port (587 for "tls" or 465 for "ssl")  |

### Default Mail settings

| Parameter | Type | Default | Description |
| :--- | :--- | :--- | :--- |
| `MAIL_HTML` | `bool` | true | **Optional**. Is mail in HTML or plaintext format |
| `MAIL_CHARSET` | `string` | "utf-8" | **Required**. Mail charset ("utf-8", "us-ascii", "iso-8859-1")  |
| `MAIL_ENCODING` | `string` | "8bit" | **Optional**. Mail encoding ("7bit", "8bit", "base64", "binary", "quoted-printable")  |
| `MAIL_FROM_ADDR` | `string` | "" | **Optional**. FROM address |
| `MAIL_FROM_NAME` | `string` | "" | **Optional**. FROM name  |

### Mail template settings

| Parameter | Type | Default | Description |
| :--- | :--- | :--- | :--- |
| `EMAIL_TEMPLATE` | `bool` | true | **Required**. Enable template service for `useTemplate` in mail sending API  |
| `EMAIL_TEMPLATE_DIR` | `string` | "Template/html/" | **Optional**. Directory for storing template files. Absolute path or relative path (relative to start-mailer.php or PHAR file directory) |
| `EMAIL_TEMPLATE_API_READ_ONLY` | `bool` | true | **Optional**. API can perform read operations only (i.e. cannot add, edit or remove template)  |
| `EMAIL_TEMPLATE_STRING_TAG_OPEN` | `string` | "{{" | **Optional**. Opening tags for template string to be replaced |
| `EMAIL_TEMPLATE_STRING_TAG_CLOSE` | `string` | "}}" | **Optional**. Closing tags for template string to be replaced |
