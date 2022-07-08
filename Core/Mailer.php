<?php

/**
 * Email sending functions
 * This file is part of the SMTP Mailer service.
 *
 * @license MIT
 * (c) 2022 Eric Chow <https://cmchow.com>
 * License at https://opensource.org/licenses/MIT
 */

namespace Core;

use Core\Config;
use Core\Crypto;
use Core\Logger;
use Core\Validator;
use PHPMailer\PHPMailer\PHPMailer;
use Workerman\Connection\AsyncTcpConnection;

class Mailer {

    /**
     * max item to return when getQueueList
     * @var int
     */
    private const GET_QUEUE_LIST_LIMIT = 500;

    /**
     * max item to return when getTemplateList
     * @var int
     */
    private const GET_TEMPLATE_LIST_LIMIT = 500;

    /**
     * Redis key for Success mail delivery count
     * @var string
     */
    private const REDIS_KEY_SENT_SUCCESS = 'sent:success';

    /**
     * Redis key for Failed mail delivery count
     * @var string
     */
    private const REDIS_KEY_SENT_FAILED = 'sent:failed';

    /**
     * Redis key for queue secondary index/sorted set
     * @var string
     */
    private const REDIS_KEY_QUEUE_INDEX = 'index:queue';

    /**
     * Redis key for template secondary index/sorted set
     * @var string
     */
    private const REDIS_KEY_TEMPLATE_INDEX = 'index:template';

    /**
     * Redis key for queued/scheduled mail
     * @var string
     */
    private const REDIS_KEY_QUEUE_MAIL = 'queue:';

    /**
     * Redis key for processing mail
     * @var string
     */
    private const REDIS_KEY_QUEUE_PROCESSING = 'temp:';

    /**
     * Redis key for mail template
     * @var string
     */
    private const REDIS_KEY_TEMPLATE = 'template:';

    /**
     * Success mail delivery count
     * Used only when worker count is 1
     * @var int
     */
    private static $sentSuccess = 0;

    /**
     * Failed mail delivery count
     * Used only when worker count is 1
     * @var int
     */
    private static $sentFailed = 0;

    /**
     * Get zero padding length for Redis key name
     */
    private static function getFilenamePadLength(): int {
        return strlen((string)PHP_INT_MAX);
    }

    /**
     * Generating a random bytes string
     * @param int $len bytes length (default 8 bytes)
     */
    private static function genRandomStr(int $len = 8): string {
        return bin2hex(random_bytes($len));
    }

    /**
     * Generating a filename/key name for queue mail
     * @param string $timestamp unix timestamp string
     */
    private static function genFilename(string $timestamp): string {
        return str_pad($timestamp, self::getFilenamePadLength(), '0', STR_PAD_LEFT) . '.' . explode('.', explode(' ', microtime())[0])[1] . '_' . self::genRandomStr();
    }

    /**
     * Get mail queued/scheduled timestamp from filename
     * @param string $filename filename
     */
    private static function getFilenameTime(string $filename): string {
        return explode('_', explode('.', $filename)[0])[1];
    }

    /**
     * Get mail queued/scheduled timestamp from Redis key
     * @param string $filename filename
     */
    private static function getRedisKeyTime(string $key): string {
        return explode('.', $key)[0];
    }

    /**
     * Convert string to base64 encoded MIME with UTF8 charset
     * https://en.wikipedia.org/wiki/MIME#Encoded-Word
     *
     * @param string $title title to be encoded
     */
    private static function encodeMimeHeader(string $title): string {
        if (!empty($title)) {
            return '=?utf-8?B?' . base64_encode($title) . '?=';
        }
        return '';
    }

    /**
     * Read email HTML template file
     * @param string $file path to template file
     * @param array $content template strings to be replaced
     * @param \Redis|null $redis Redis instance
     */
    private static function retrieveTemplate(string $file, array $content = [], $redis = null): string {
        $template = false;

        if (!is_null($redis) && Config::isRedisStoreTemplate()) {
            // Redis stored template
            $template = $redis->get(self::REDIS_KEY_TEMPLATE . $file);
        } else {
            // file-based template
            $template = file_get_contents(Config::getEnv('EMAIL_TEMPLATE_DIR') . $file);
        }

        if ($template !== false) {
            foreach ($content as $key => $value) {
                $template = str_replace(Config::getEnv('EMAIL_TEMPLATE_STRING_TAG_OPEN') . $key . Config::getEnv('EMAIL_TEMPLATE_STRING_TAG_CLOSE'), $value, $template);
            }
            return $template;
        } else {
            Logger::log('error', "mail template not found: {$file}");
        }
        return '';
    }

    /**
     * Get queued mail content
     * @param string $mailName mail filename or redis key
     * @param \Redis|null $redis Redis instance
     */
    private static function retrieveQueuedMailContent(string $mailName, $redis = null) {
        // retrieve mail content
        if (!is_null($redis) && Config::isRedisStoreQueue()) {
            // Redis stored queue
            return $redis->get(self::REDIS_KEY_QUEUE_MAIL . $mailName);
        } elseif (preg_match(Validator::QUEUE_FILE_REGEX, $mailName)) {
            // file-based queue
            $queueDir = Config::getEnv('QUEUE_DIR');
            $filepath = $queueDir . $mailName;

            if (is_file($filepath)) {
                return file_get_contents($filepath);
            }
        }
        return false;
    }

    /**
     * Delete processing mail file
     * @param string $name mail filename or redis key
     * @param \Redis|null $redis Redis instance
     */
    private static function deleteProcessingQueuedMail(string $name, $redis = null): bool {
        if (!is_null($redis) && Config::isRedisStoreQueue()) {
            // Redis stored queue
            if ($redis->del(self::REDIS_KEY_QUEUE_PROCESSING . $name) <= 0) {
                Logger::log('warning', "unable to clear queue mail in Redis ({$name})");
            } else {
                return true;
            }
        } else {
            // file-based queue
            if (!unlink(Config::getEnv('QUEUE_PROCESS_DIR') . $name)) {
                Logger::log('warning', "unable to clear queue mail ({$name})");
            } else {
                return true;
            }
        }
        return false;
    }

    /**
     * Move processing mail back to queue
     * @param string $name mail filename or redis key
     * @param \Redis|null $redis Redis instance
     */
    private static function moveProcessingMailBackToQueue(string $name, $redis = null): bool {
        if (!is_null($redis) && Config::isRedisStoreQueue()) {
            // Redis stored queue
            if ($redis->rename(self::REDIS_KEY_QUEUE_PROCESSING . $name, self::REDIS_KEY_QUEUE_MAIL . $name)) {
                // update sorted sets index
                return $redis->zAdd(self::REDIS_KEY_QUEUE_INDEX, 0, $name) > 0;
            } else {
                Logger::log('warning', "unable to rename processing queue mail in Redis ({$name})");
            }
        } else {
            // file-based queue
            if (!rename(Config::getEnv('QUEUE_PROCESS_DIR') . $name, Config::getEnv('QUEUE_DIR') . $name)) {
                Logger::log('error', "unable to move mail back to queue ({$name})");
            } else {
                return true;
            }
        }
        return false;
    }

    /**
     * Move processing mail back to queue
     * @param int $now current timestamp for unit testing
     */
    private static function processFileBasedQueue(int $now = -1): bool {
        $currentTime = $now > 0 ? $now : time();
        $queueDir = Config::getEnv('QUEUE_DIR');
        $workingDir = Config::getEnv('QUEUE_PROCESS_DIR');
        $jsonList = self::scanQueueDir($queueDir);

        if ($jsonList !== false) {
            if (count($jsonList) > 0) {
                // select a batch of queue mails
                $filesToProcess = array_slice($jsonList, 0, Config::getEnv('QUEUE_MAX_BATCH_SIZE'));
                $fileCount = 0;

                // move mail to temp working directory
                foreach ($filesToProcess as $file) {
                    if (is_file($queueDir . $file)) {
                        $createTime = self::getFilenameTime($file);
                        // check schedule time
                        if (isset($createTime) && intval($createTime, 10) <= $currentTime) {
                            if (!rename($queueDir . $file, $workingDir . $file)) {
                                Logger::log('error', "unable to move queue mail to work dir");
                            } else {
                                $fileCount++;
                            }
                        }
                    }
                }
                Logger::log('info', "moved {$fileCount} queue mail to work dir");

                // send filename to primary worker for mail sending
                foreach ($filesToProcess as $file) {
                    if (is_file($workingDir . $file) && $now < 0) {
                        Logger::log('debug', "passing {$file} queue");
                        $payload = [
                            'processQueueFile' => Crypto::encrypt($file)
                        ];

                        try {
                            $tcp = null;
                            if (Config::isSslEnabled()) {
                                // enable ssl on tcp
                                $context = [
                                    'ssl' => [
                                        'local_cert' => Config::getEnv('MAILER_SSL_CERT'),
                                        'local_pk' => Config::getEnv('MAILER_SSL_KEY'),
                                        'verify_peer' => false,
                                        'allow_self_signed' => true
                                    ]
                                ];
                                $tcp = new AsyncTcpConnection(Config::serviceListenAddress(), $context);
                                $tcp->transport = 'ssl';
                            } else {
                                $tcp = new AsyncTcpConnection(Config::serviceListenAddress());
                            }
                            $tcp->onConnect = function ($connection) use ($payload) {
                                Logger::log('debug', "sending queue mail to primary worker");
                                $connection->send(json_encode($payload));
                            };
                            $tcp->onMessage = function ($connection, $result) {
                                Logger::log('debug', "queue AsyncTcpConnection result: {$result}");
                                $connection->close();
                            };
                            $tcp->onError = function ($connection, $code, $msg) {
                                Logger::log('error', "unable to pass queue mail to primary worker: ({$code}): {$msg}");
                                $connection->close();
                            };
                            $tcp->connect();
                        } catch (\Throwable $e) {
                            Logger::log('error', "unable to pass queue mail to primary worker exception: {$e->getMessage()}, Trace: {$e->getTraceAsString()}");
                        }
                    }
                }
                return true;
            } else {
                Logger::log('debug', "no queue mail to process");
            }
        } else {
            Logger::log('error', "unable to read queue dir");
        }
        return false;
    }

    /**
     * Move processing mail back to queue
     * @param int $now current timestamp for unit testing
     * @param \Redis|null $redis Redis instance
     */
    private static function processRedisBasedQueue(int $now = -1, $redis = null): bool {
        if (!is_null($redis) && $redis->zCard(self::REDIS_KEY_QUEUE_INDEX) > 0) {
            $currentTime = $now > 0 ? $now : time();
            $keysToProcess = $redis->zRange(self::REDIS_KEY_QUEUE_INDEX, 0, Config::getEnv('QUEUE_MAX_BATCH_SIZE'));
            $validKeyCount = 0;

            // rename mail Redis key to temp
            foreach ($keysToProcess as $key) {
                if ($redis->exists(self::REDIS_KEY_QUEUE_MAIL . $key) > 0) {
                    $createTime = self::getRedisKeyTime($key);
                    // check schedule time
                    if (isset($createTime) && intval($createTime, 10) <= $currentTime) {
                        if ($redis->rename(self::REDIS_KEY_QUEUE_MAIL . $key, self::REDIS_KEY_QUEUE_PROCESSING . $key) && $redis->zRem(self::REDIS_KEY_QUEUE_INDEX, 0, $key) > 0) {
                            $validKeyCount++;
                        } else {
                            Logger::log('error', "unable to renamed queue mail to temp");
                        }
                    }
                }
            }
            Logger::log('info', "renamed {$validKeyCount} queue mail to temp");

            // send filename to primary worker for mail sending
            foreach ($keysToProcess as $key) {
                if ($redis->exists(self::REDIS_KEY_QUEUE_PROCESSING . $key) > 0) {
                    Logger::log('debug', "passing {$key} queue");
                    $payload = [
                        'processQueueFile' => Crypto::encrypt($key)
                    ];

                    try {
                        $tcp = null;
                        if (Config::isSslEnabled()) {
                            // enable ssl on tcp
                            $context = [
                                'ssl' => [
                                    'local_cert' => Config::getEnv('MAILER_SSL_CERT'),
                                    'local_pk' => Config::getEnv('MAILER_SSL_KEY'),
                                    'verify_peer' => false,
                                    'allow_self_signed' => true
                                ]
                            ];
                            $tcp = new AsyncTcpConnection(Config::serviceListenAddress(), $context);
                            $tcp->transport = 'ssl';
                        } else {
                            $tcp = new AsyncTcpConnection(Config::serviceListenAddress());
                        }
                        $tcp->onConnect = function ($connection) use ($payload) {
                            Logger::log('debug', "sending queue mail to primary worker");
                            $connection->send(json_encode($payload));
                        };
                        $tcp->onMessage = function ($connection, $result) {
                            Logger::log('debug', "queue AsyncTcpConnection result: {$result}");
                            $connection->close();
                        };
                        $tcp->onError = function ($connection, $code, $msg) {
                            Logger::log('error', "unable to pass queue mail to primary worker: ({$code}): {$msg}");
                            $connection->close();
                        };
                        $tcp->connect();
                    } catch (\Throwable $e) {
                        Logger::log('error', "unable to pass queue mail to primary worker exception: {$e->getMessage()}, Trace: {$e->getTraceAsString()}");
                    }
                }
            }
            return true;
        }
        return false;
    }

    /**
     * Send email
     * @param array $recipients email recipients list
     * @param array $ccList email CC list
     * @param array $bccList email BCC list
     * @param array $attachments email attachments
     * @param array $embedded email embedded images
     * @param string $subject email subject
     * @param string $body email body
     * @param string $fromName FROM header name
     * @param string $fromAddr FROM header address
     * @param string $smtpUser SMTP user
     * @param string $smtpPassword SMTP password
     * @param string $smtpHost SMTP host
     * @param int $smtpPort SMTP port
     * @param string $smtpEncryption SMTP encryption
     */
    private static function sendPhpMail(
        array $recipients,
        array $ccList,
        array $bccList,
        array $replyTo,
        array $attachments,
        array $embedded,
        string $subject,
        string $body,
        string $fromName = '',
        string $fromAddr = '',
        string $smtpUser = '',
        string $smtpPassword = '',
        string $smtpHost = '',
        int $smtpPort = 0,
        string $smtpEncryption = '',
        int $timeout = 300
    ): array {
        $mail = new PHPMailer(true);

        try {
            $mail->SMTPDebug = 0;
            $mail->IsSMTP();

            $mail->SMTPAuth   = true;
            $mail->SMTPSecure = empty($smtpEncryption) ? Config::getEnv('SMTP_ENCRYPTION') : $smtpEncryption;
            $mail->Port       = $smtpPort > 0 ? $smtpPort : Config::getEnv('SMTP_PORT');
            $mail->Host       = empty($smtpHost) ? Config::getEnv('SMTP_HOST') : $smtpHost;
            $mail->Username   = empty($smtpUser) ? Config::getEnv('SMTP_USER') : $smtpUser;
            $mail->Password   = empty($smtpPassword) ? Config::getSmtpPassword() : $smtpPassword;
            $mail->Timeout    = $timeout;

            // Sent from
            $mail->setFrom(
                empty($fromAddr) ? Config::getEnv('MAIL_FROM_ADDR') : $fromAddr,
                self::encodeMimeHeader(empty($fromName) ? Config::getEnv('MAIL_FROM_NAME') : $fromName)
            );

            // Add recipients
            foreach ($recipients as $user) {
                if (is_string($user)) {
                    $mail->addAddress($user);
                } elseif (is_array($user) && count($user) == 2) {
                    $mail->addAddress($user[0], self::encodeMimeHeader($user[1]));
                }
            }
            // Add CC recipients
            foreach ($ccList as $cc) {
                if (is_string($cc)) {
                    $mail->addCC($cc);
                } elseif (is_array($cc) && count($cc) == 2) {
                    $mail->addCC($cc[0], self::encodeMimeHeader($cc[1]));
                }
            }
            // Add BCC recipients
            foreach ($bccList as $bcc) {
                if (is_string($bcc)) {
                    $mail->addBCC($bcc);
                } elseif (is_array($bcc) && count($bcc) == 2) {
                    $mail->addBCC($bcc[0], self::encodeMimeHeader($bcc[1]));
                }
            }
            // Add reply to
            foreach ($replyTo as $reply) {
                if (is_string($reply)) {
                    $mail->addReplyTo($reply);
                } elseif (is_array($reply) && count($reply) == 2) {
                    $mail->addReplyTo($reply[0], self::encodeMimeHeader($reply[1]));
                }
            }


            // Add Attachments
            foreach ($attachments as $file) {
                if (is_array($file) && count($file) >= 1) {
                    $path = $file[0];
                    $name = $file[1] ?? false;

                    if (!$name) {
                        $mail->addAttachment($path);
                    } else {
                        $mail->addAttachment($path, $name);
                    }
                }
            }

            // Add Embedded Image
            foreach ($embedded as $img) {
                if (is_array($img) && count($img) >= 2) {
                    $path = $img[0];
                    $cid = $img[1];
                    $name = $img[2] ?? false;

                    if (!$name) {
                        $mail->AddEmbeddedImage($path, $cid);
                    } else {
                        $mail->AddEmbeddedImage($path, $cid, $name);
                    }
                }
            }

            //Content
            $mail->isHTML(Config::getEnv('MAIL_HTML'));
            $mail->CharSet = Config::getEnv('MAIL_CHARSET');
            $mail->Subject = self::encodeMimeHeader($subject);
            $mail->Body    = $body;

            if ($mail->send() !== false) {
                return [true, ''];
            }
            return [false, $mail->ErrorInfo];
        } catch (\Throwable $e) {
            return [false, $e->getMessage()];
        }
        return [false, 'PHPMailer init error'];
    }

    /**
     * Prepare email content for instant SMTP sending
     * @param mixed $data incoming payload
     * @param \Redis|null $redis Redis instance
     */
    protected static function prepareAndSendMail($data, $redis = null): array {
        $response = self::response('error', null, 'failed to prepare mail');

        [$valid, $error] = Validator::validate('sendMail', $data);

        if ($valid) {
            if (!(empty($data['to']) && empty($data['ccList']) && empty($data['bccList']))) {

                // read template as body and replace string if needed
                if (isset($data['useTemplate'])) {
                    $templateIsEnabled = Config::getEnv('EMAIL_TEMPLATE');
                    if ($templateIsEnabled) {
                        if (isset($data['replaceContent'])) {
                            $data['body'] = self::retrieveTemplate($data['useTemplate'], $data['replaceContent'], $redis);
                        } else {
                            $data['body'] = self::retrieveTemplate($data['useTemplate'], [], $redis);
                        }
                    } else {
                        Logger::log('info', "failed to send mail using template");
                        $response = self::templateNotEnabled();
                        return $response;
                    }
                }

                [$sent, $error] = self::sendPhpMail(
                    $data['to'] ?? [],
                    $data['ccList'] ?? [],
                    $data['bccList'] ?? [],
                    $data['replyTo'] ?? [],
                    $data['attachments'] ?? [],
                    $data['embedded'] ?? [],
                    $data['subject'] ?? '',
                    $data['body'] ?? '',
                    $data['fromName'] ?? '',
                    $data['fromEmail'] ?? '',
                    $data['smtpUser'] ?? '',
                    $data['smtpPassword'] ?? '',
                    $data['smtpHost'] ?? '',
                    $data['smtpPort'] ?? 0,
                    $data['smtpEncryption'] ?? '',
                    $data['timeout'] ?? Config::getEnv('MAILER_TIMEOUT')
                );

                if ($sent) {
                    if (Config::isRedisEnabled() && !is_null($redis)) {
                        $redis->incr(self::REDIS_KEY_SENT_SUCCESS);
                    } elseif (Config::isSingleWorker()) {
                        ++static::$sentSuccess;
                    }
                    Logger::log('info', "mail sent successfully");
                    $response = self::response('success', null, 'mail sent successfully');
                } else {
                    if (Config::isRedisEnabled() && !is_null($redis)) {
                        $redis->incr(self::REDIS_KEY_SENT_FAILED);
                    } elseif (Config::isSingleWorker()) {
                        ++static::$sentFailed;
                    }
                    Logger::log('error', "PHPMailer Error: {$error}");
                    $response = self::response('error', $error, 'failed to send mail');
                }
            } else {
                Logger::log('warning', "mail must have at least one recipient");
                $response = self::response('error', 'To/CC/BCC must have at least one recipient', 'invalid payload');
            }
        } else {
            Logger::log('warning', "invalid mail payload");
            $response = self::response('error', $error, 'invalid payload');
        }
        return $response;
    }

    /**
     * output mail as JSON file for queue processing
     * @param mixed $data incoming payload
     * @param bool $isFailed is failed mail
     * @param \Redis|null $redis Redis instance
     */
    protected static function outputMailToQueue($data, bool $isFailed = false, $redis = null): bool {
        $fullEncrypt = Config::getEnv('QUEUE_FULL_ENCRYPT');
        $queueDir = Config::getEnv('QUEUE_DIR');

        if (!array_key_exists('failToDelivered', $data)) {
            $data['failToDelivered'] = 0;
        }
        if ($isFailed) {
            $data['failToDelivered'] += 1;
        }

        // encrypt SMTP password if needed
        if (!$fullEncrypt && array_key_exists('smtpPassword', $data)) {
            $data['smtpEncryptPassword'] = Crypto::encrypt($data['smtpPassword']);
            unset($data['smtpPassword']);
        }

        // use schedule time or current time for file/key name
        $timestamp = strval(array_key_exists('scheduleTime', $data) ? $data['scheduleTime'] : time());
        $key = self::genFilename($timestamp);

        if (!is_null($redis) && Config::isRedisStoreQueue()) {
            // Redis stored queue
            if ($redis->set(self::REDIS_KEY_QUEUE_MAIL . $key, $fullEncrypt ? Crypto::encrypt(json_encode($data)) : $data)) {
                // update sorted sets index
                return $redis->zAdd(self::REDIS_KEY_QUEUE_INDEX, 0, $key) > 0;
            } else {
                Logger::log('error', "failed to save queued mail to Redis: {$key}");
            }
            return false;
        } else {
            // file-based queue
            $filename = "mail_{$key}.json";

            // encrypt full document if needed and save to disk
            return file_put_contents($queueDir . $filename, $fullEncrypt ? Crypto::encrypt(json_encode($data)) : json_encode($data));
        }
    }

    /**
     * add SMTP email to queue or with scheduled time
     * @param mixed $data incoming payload
     * @param \Redis|null $redis Redis instance
     */
    protected static function queueMail($data, $redis): array {
        $response = self::response('error', null, 'failed to process queueMail');

        [$valid, $error] = Validator::validate('sendMail', $data);

        if ($valid) {
            if (!(empty($data['to']) && empty($data['ccList']) && empty($data['bccList']))) {
                $output = self::outputMailToQueue($data, false, $redis);
                if ($output) {
                    Logger::log('info', "mail added to queue");
                    $response = self::response('success', null, 'mail added to queue');
                } else {
                    Logger::log('error', "failed to add mail to queue");
                    $response = self::response('error', null, 'failed to add mail to queue');
                }
            } else {
                Logger::log('warning', "queueMail must have at least one recipient");
                $response = self::response('error', 'To/CC/BCC must have at least one recipient', 'invalid payload');
            }
        } else {
            Logger::log('warning', "invalid queueMail payload");
            $response = self::response('error', $error, 'invalid payload');
        }
        return $response;
    }

    /**
     * Instant SMTP email sending
     * @param mixed $data incoming payload
     * @param \Redis|null $redis Redis instance
     */
    protected static function sendMail($data, $redis): array {
        $hasQueue = Config::getEnv('MAILER_QUEUE');
        $maxRetry = Config::getEnv('QUEUE_MAX_FAILED_RETRY');

        // send mail via PhpMailer
        $response = self::prepareAndSendMail($data, $redis);

        if ($response['status'] != 'success' && $response['message'] === 'failed to send mail') {
            // push back to queue for retry
            if ($hasQueue && ($maxRetry > 0 || $maxRetry === -1)) {
                $output = self::outputMailToQueue($data, true, $redis);
                if ($output) {
                    Logger::log('info', "failed mail added to queue");
                } else {
                    Logger::log('error', "unable to add failed mail to queue");
                }
            }
        }

        return $response;
    }

    /**
     * queue is not enabled response
     */
    protected static function queueNotEnabled(): array {
        return self::response('error', null, 'queue service is not enabled');
    }

    /**
     * queue is read-only response
     */
    protected static function queueIsReadOnly(): array {
        return self::response('error', null, 'queue service is read-only');
    }

    /**
     * Get list of all queued mails
     * @param mixed $data incoming payload
     * @param \Redis|null $redis Redis instance
     */
    protected static function getQueueList($data, $redis = null): array {
        $limit = self::GET_QUEUE_LIST_LIMIT;
        $page = 0;

        if (isset($data['limit']) && is_int($data['limit']) && $data['limit'] > 0 && $data['limit'] < self::GET_QUEUE_LIST_LIMIT) {
            $limit = $data['limit'];
        }
        if (isset($data['page']) && is_int($data['page']) && $data['page'] >= 0) {
            $page = $data['page'];
        }

        if (!is_null($redis) && Config::isRedisStoreQueue()) {
            // Redis stored queue

            // get total queued mail
            $total = $redis->zCard(self::REDIS_KEY_QUEUE_INDEX);
            if ($total > 0) {
                $slicedList = $redis->zRange(self::REDIS_KEY_QUEUE_INDEX, $page * $limit, $page * $limit + ($limit - 1));

                if (count($slicedList) > 0) {
                    return self::response('success', ['items' => $slicedList, 'total' => $total], 'found ' . strval($total) . ' mails in queue');
                } else {
                    return self::response('success', ['items' => [], 'total' => $total], 'found ' . strval($total) . ' mails in queue');
                }
            } else {
                return self::response('success', ['items' => [], 'total' => 0], 'queue list is empty');
            }
        } else {
            // file-based queue
            $queueDir = Config::getEnv('QUEUE_DIR');
            $jsonList = self::scanQueueDir($queueDir);

            if ($jsonList !== false) {
                if (count($jsonList) > 0) {

                    // limit display items or negative to display all
                    $slicedList = $jsonList;
                    if ($limit > 0) {
                        $slicedList = array_slice($jsonList, $page * $limit, $limit);
                    }
                    $total = count($jsonList);

                    if (count($slicedList) > 0) {
                        return self::response('success', ['items' => $slicedList, 'total' => $total], 'found ' . strval($total) . ' mails in queue');
                    } else {
                        return self::response('success', ['items' => [], 'total' => $total], 'found ' . strval($total) . ' mails in queue');
                    }
                } else {
                    return self::response('success', ['items' => [], 'total' => 0], 'queue list is empty');
                }
            } else {
                Logger::log('warning', "unable to read queue dir");
            }
        }
        return self::response('error', null, 'unable to get queue list');
    }

    /**
     * Get queued mail content
     * @param mixed $data incoming payload
     * @param \Redis|null $redis Redis instance
     */
    protected static function getQueuedMail($data, $redis = null): array {
        if (isset($data) && is_string($data)) {
            $fullEncrypt = Config::getEnv('QUEUE_FULL_ENCRYPT');
            $content = self::retrieveQueuedMailContent($data, $redis);

            if ($content !== false) {
                // decrypt full document if needed
                $json = $fullEncrypt ? Crypto::decrypt($content) : $content;

                if ($json !== false) {
                    $mail = is_string($json) ? json_decode($json, true) : $json;

                    // remove sensitive fields
                    if (array_key_exists('smtpPassword', $mail)) {
                        unset($mail['smtpPassword']);
                    }
                    if (array_key_exists('smtpEncryptPassword', $mail)) {
                        unset($mail['smtpEncryptPassword']);
                    }

                    return self::response('success', $mail, $data);
                } else {
                    Logger::log('error', "failed to decrypt queue mail ({$data})");
                }
            } else {
                Logger::log('error', "failed to read queue mail ({$data})");
            }
        } else {
            Logger::log('warning', "invalid getQueuedMail payload");
            return self::response('error', 'invalid file path string', 'invalid payload');
        }
        return self::response('error', null, 'unable to get queued mail');
    }

    /**
     * update queued mail content
     * @param mixed $payload incoming payload
     * @param \Redis|null $redis Redis instance
     */
    protected static function updateQueuedMail($payload, $redis = null): array {
        $data = $payload['updateQueuedMail'];
        if (isset($data) && is_string($data) && isset($payload['content'])) {
            [$valid, $error] = Validator::validate('updateQueuedMail', $payload['content']);

            if ($valid) {
                $fullEncrypt = Config::getEnv('QUEUE_FULL_ENCRYPT');
                $content = self::retrieveQueuedMailContent($data, $redis);

                if ($content !== false) {
                    // decrypt full document if needed
                    $json = $fullEncrypt ? Crypto::decrypt($content) : $content;

                    if ($json !== false) {
                        $mail = is_string($json) ? json_decode($json, true) : $json;
                        $update = $payload['content'];

                        // update queue mail data
                        if (array_key_exists('smtpPassword', $update)) {
                            if ($fullEncrypt) {
                                $mail['smtpPassword'] = $update['smtpPassword'];
                            } else {
                                $mail['smtpEncryptPassword'] = Crypto::encrypt($update['smtpPassword']);
                            }
                            unset($update['smtpPassword']);
                        }

                        foreach ($update as $key => $value) {
                            $mail[$key] = $value;
                        }

                        // use new / old schedule time / current time for file name
                        $timestamp = strval(array_key_exists('scheduleTime', $update) ? $update['scheduleTime'] : (array_key_exists('scheduleTime', $mail) ? $mail['scheduleTime'] : time()));
                        $key = self::genFilename($timestamp);

                        if (!is_null($redis) && Config::isRedisStoreQueue()) {
                            // Redis stored queue
                            // remove old queued mail
                            if ($redis->del(self::REDIS_KEY_QUEUE_MAIL . $data) > 0 && $redis->zRem(self::REDIS_KEY_QUEUE_INDEX, $data) > 0) {
                                // save updated mail
                                if ($redis->set(self::REDIS_KEY_QUEUE_MAIL . $key, $fullEncrypt ? Crypto::encrypt(json_encode($mail)) : $mail)) {
                                    // update sorted sets index
                                    if ($redis->zAdd(self::REDIS_KEY_QUEUE_INDEX, 0, $key) > 0) {
                                        if (array_key_exists('smtpPassword', $mail)) {
                                            unset($mail['smtpPassword']);
                                        }
                                        if (array_key_exists('smtpEncryptPassword', $mail)) {
                                            unset($mail['smtpEncryptPassword']);
                                        }
                                        return self::response('success', $mail, [
                                            'original' => $data,
                                            'updated' => $key
                                        ]);
                                    } else {
                                        Logger::log('error', "updateQueuedMail unable to add new mail to Redis index ({$key})");
                                    }
                                } else {
                                    Logger::log('error', "updateQueuedMail unable to save new mail to Redis ({$key})");
                                }
                            } else {
                                Logger::log('error', "updateQueuedMail unable to delete old mail in Redis ({$key})");
                            }
                            return self::response('error', null, 'update failed');
                        } else {
                            // file-based queue
                            $queueDir = Config::getEnv('QUEUE_DIR');
                            $filepath = $queueDir . $data;
                            $filename = "mail_{$key}.json";

                            // remove old queued mail
                            if (unlink($filepath)) {
                                // save updated mail
                                if (file_put_contents($queueDir . $filename, $fullEncrypt ? Crypto::encrypt(json_encode($mail)) : json_encode($mail))) {
                                    Logger::log('notice', 'updated mail in queue');
                                    if (array_key_exists('smtpPassword', $mail)) {
                                        unset($mail['smtpPassword']);
                                    }
                                    if (array_key_exists('smtpEncryptPassword', $mail)) {
                                        unset($mail['smtpEncryptPassword']);
                                    }
                                    return self::response('success', $mail, [
                                        'original' => $data,
                                        'updated' => $filename
                                    ]);
                                } else {
                                    Logger::log('error', "updateQueuedMail unable to save new file ({$filename})");
                                }
                            } else {
                                Logger::log('error', "updateQueuedMail unable to delete old file ({$data})");
                            }
                            return self::response('error', null, 'update failed');
                        }
                    } else {
                        Logger::log('error', "failed to decrypt queue mail ({$data})");
                    }
                } else {
                    Logger::log('error', "failed to read queue mail ({$data})");
                }
            } else {
                Logger::log('warning', "invalid updateQueuedMail payload");
                return self::response('error', $error, 'invalid payload');
            }
        } else {
            Logger::log('warning', "invalid updateQueuedMail payload");
            return self::response('error', 'invalid file path string or missing content', 'invalid payload');
        }
        return self::response('error', null, 'unable to update queued mail');
    }

    /**
     * delete queue mail
     * @param mixed $data incoming payload
     * @param \Redis|null $redis Redis instance
     */
    protected static function removeQueuedMail($data, $redis = null): array {
        if (isset($data) && is_string($data)) {
            if (!is_null($redis) && Config::isRedisStoreQueue()) {
                // Redis stored queue
                if ($redis->del(self::REDIS_KEY_QUEUE_MAIL . $data) > 0 && $redis->zRem(self::REDIS_KEY_QUEUE_INDEX, $data) > 0) {
                    return self::response('success', $data, 'queued mail removed');
                } else {
                    Logger::log('error', "failed to remove queued mail ({$data})");
                }
            } elseif (preg_match(Validator::QUEUE_FILE_REGEX, $data)) {
                // file-based queue
                $queueDir = Config::getEnv('QUEUE_DIR');
                $filepath = $queueDir . $data;

                if (is_file($filepath)) {
                    if (unlink($filepath)) {
                        return self::response('success', $data, 'queued mail removed');
                    } else {
                        Logger::log('error', "failed to remove queued mail ({$data})");
                    }
                }
            } else {
                Logger::log('warning', "invalid removeQueuedMail payload");
                return self::response('error', null, 'invalid filepath string');
            }
        } else {
            Logger::log('warning', "invalid removeQueuedMail payload");
            return self::response('error', null, 'invalid payload');
        }
        return self::response('error', null, 'unable to remove queued mail');
    }

    /**
     * clear all queued mail
     * @param mixed $data incoming payload
     * @param \Redis|null $redis Redis instance
     */
    protected static function clearQueue($data, $redis = null): array {
        if (!is_null($redis) && Config::isRedisStoreQueue()) {
            // Redis stored queue

            // delete using sorted sets index
            $totalMail = $redis->zCard(self::REDIS_KEY_QUEUE_INDEX);
            if ($totalMail > 0) {
                $keyList = $redis->zRange(self::REDIS_KEY_QUEUE_INDEX, 0, -1);

                $multi = $redis->multi();
                foreach ($keyList as $key) {
                    $multi->del(self::REDIS_KEY_QUEUE_MAIL . $key);
                    $multi->zRem(self::REDIS_KEY_QUEUE_INDEX, $key);
                }
                $multi->exec();
            }

            return self::response('success', ['removed' => $totalMail], 'removed ' . strval($totalMail) . ' mails in queue');
        } else {
            // file-based queue
            $queueDir = Config::getEnv('QUEUE_DIR');
            $jsonList = self::scanQueueDir($queueDir);

            if ($jsonList !== false) {
                $totalMail = count($jsonList);
                if (count($jsonList) > 0) {
                    foreach ($jsonList as $file) {
                        if (is_file($queueDir . $file)) {
                            @unlink($queueDir . $file);
                        }
                    }

                    return self::response('success', ['removed' => $totalMail], 'removed ' . strval($totalMail) . ' mails in queue');
                } else {
                    return self::response('success', [], 'queue list is empty');
                }
            } else {
                Logger::log('warning', "unable to read queue dir");
            }
        }
        return self::response('error', null, 'unable to read queue dir');
    }

    /**
     * template is not enabled response
     */
    protected static function templateNotEnabled(): array {
        return self::response('error', null, 'template service is not enabled');
    }

    /**
     * template is read-only response
     */
    protected static function templateIsReadOnly(): array {
        return self::response('error', null, 'template service is read-only');
    }

    /**
     * Get list of all email templates
     * @param mixed $data incoming payload
     * @param \Redis|null $redis Redis instance
     */
    protected static function getTemplateList($data, $redis = null): array {
        $limit = self::GET_TEMPLATE_LIST_LIMIT;
        $page = 0;

        if (isset($data['limit']) && is_int($data['limit']) && $data['limit'] > 0 && $data['limit'] < self::GET_TEMPLATE_LIST_LIMIT) {
            $limit = $data['limit'];
        }
        if (isset($data['page']) && is_int($data['page']) && $data['page'] >= 0) {
            $page = $data['page'];
        }

        if (!is_null($redis) && Config::isRedisStoreQueue()) {
            // Redis stored template

            // get total template
            $total = $redis->zCard(self::REDIS_KEY_TEMPLATE_INDEX);
            if ($total > 0) {
                $slicedList = $redis->zRange(self::REDIS_KEY_TEMPLATE_INDEX, $page * $limit, $page * $limit + ($limit - 1));

                if (count($slicedList) > 0) {
                    return self::response('success', ['items' => $slicedList, 'total' => $total], 'found ' . strval($total) . ' templates');
                } else {
                    return self::response('success', ['items' => [], 'total' => $total], 'found ' . strval($total) . ' templates');
                }
            } else {
                return self::response('success', ['items' => [], 'total' => 0], 'template list is empty');
            }
        } else {
            // file-based template
            $templateDir = Config::getEnv('EMAIL_TEMPLATE_DIR');
            $templateList = self::scanTemplateDir($templateDir);

            if ($templateList !== false) {
                if (count($templateList) > 0) {

                    // limit display items or negative to display all
                    $slicedList = $templateList;
                    if ($limit > 0) {
                        $slicedList = array_slice($templateList, $page * $limit, $limit);
                    }
                    $total = count($templateList);
                    return self::response('success', ['items' => $slicedList, 'total' => $total], 'found ' . strval($total) . ' templates');
                } else {
                    return self::response('success', ['items' => [], 'total' => 0], 'template list is empty');
                }
            } else {
                Logger::log('warning', "unable to read template dir");
            }
        }
        return self::response('error', null, 'unable to get template list');
    }

    /**
     * Get template content
     * @param mixed $data incoming payload
     * @param \Redis|null $redis Redis instance
     */
    protected static function getTemplate($data, $redis): array {
        if (isset($data) && is_string($data)) {
            if (!is_null($redis) && Config::isRedisStoreTemplate()) {
                // Redis stored template
                $template = $redis->get(self::REDIS_KEY_TEMPLATE . $data);
                if ($template !== false) {
                    return self::response('success', $template, 'template found');
                } else {
                    Logger::log('error', "failed to read template ({$data})");
                }
            } else {
                // file-based template
                $templateDir = Config::getEnv('EMAIL_TEMPLATE_DIR');
                $filepath = $templateDir . $data;

                if (is_file($filepath)) {
                    $content = file_get_contents($filepath);
                    if ($content !== false) {
                        return self::response('success', $content, 'template found');
                    } else {
                        Logger::log('error', "failed to read template ({$data})");
                    }
                }
            }
        } else {
            Logger::log('warning', "invalid getTemplate payload");
            return self::response('error', 'invalid file path string', 'invalid payload');
        }
        return self::response('error', null, 'unable to get template');
    }

    /**
     * add template file
     * @param mixed $data incoming payload
     * @param \Redis|null $redis Redis instance
     */
    protected static function addTemplate($payload, $redis): array {
        $data = $payload['addTemplate'];
        if (isset($data) && is_string($data) && isset($payload['content']) && is_string($payload['content'])) {
            $content = $payload['content'];

            if (!is_null($redis) && Config::isRedisStoreTemplate()) {
                // Redis stored template
                if ($redis->exists(self::REDIS_KEY_TEMPLATE . $data) === 0) {
                    if ($redis->set(self::REDIS_KEY_TEMPLATE . $data, $content)) {
                        // update sorted sets index
                        if ($redis->zAdd(self::REDIS_KEY_TEMPLATE_INDEX, 0, $data) > 0) {
                            Logger::log('notice', "added template ({$data})");
                            return self::response('success', $data, "template added");
                        } else {
                            Logger::log('error', "failed to add template to Redis index ({$data})");
                        }
                    } else {
                        Logger::log('error', "failed to add template to Redis ({$data})");
                    }
                } else {
                    Logger::log('error', "failed to add template ({$data})");
                    return self::response('error', 'file already exists', 'unable to add template');
                }
            } else {
                // file-based template
                $templateDir = Config::getEnv('EMAIL_TEMPLATE_DIR');
                $filepath = $templateDir . $data;

                if (!is_file($filepath)) {
                    if (file_put_contents($filepath, $content)) {
                        Logger::log('notice', "added template ({$data})");
                        return self::response('success', $data, "template added");
                    } else {
                        Logger::log('error', "failed to add template ({$data})");
                    }
                } else {
                    Logger::log('error', "failed to add template ({$data})");
                    return self::response('error', 'file already exists', 'unable to add template');
                }
            }
        } else {
            Logger::log('warning', "invalid addTemplate payload");
            return self::response('error', 'invalid file path string or missing content', 'invalid payload');
        }
        return self::response('error', null, 'unable to add template');
    }

    /**
     * update template content
     * @param mixed $payload incoming payload
     * @param \Redis|null $redis Redis instance
     */
    protected static function updateTemplate($payload, $redis = null): array {
        $data = $payload['updateTemplate'];
        if (isset($data) && is_string($data) && isset($payload['content']) && is_string($payload['content'])) {
            $content = $payload['content'];

            if (!is_null($redis) && Config::isRedisStoreTemplate()) {
                // Redis stored template
                if ($redis->exists(self::REDIS_KEY_TEMPLATE . $data) > 0) {
                    if ($redis->set(self::REDIS_KEY_TEMPLATE . $data, $content)) {
                        Logger::log('notice', "updated template ({$data})");
                        return self::response('success', $data, "template updated");
                    } else {
                        Logger::log('error', "failed to save template to Redis ({$data})");
                    }
                }
            } else {
                // file-based template
                $templateDir = Config::getEnv('EMAIL_TEMPLATE_DIR');
                $filepath = $templateDir . $data;

                if (is_file($filepath)) {
                    if (file_put_contents($filepath, $content)) {
                        Logger::log('notice', "updated template ({$data})");
                        return self::response('success', $data, "template updated");
                    } else {
                        Logger::log('error', "failed to write template ({$data})");
                    }
                }
            }
        } else {
            Logger::log('warning', "invalid updateTemplate payload");
            return self::response('error', 'invalid file path string or missing content', 'invalid payload');
        }
        return self::response('error', null, 'unable to update template');
    }

    /**
     * delete template
     * @param mixed $data incoming payload
     * @param \Redis|null $redis Redis instance
     */
    protected static function removeTemplate($data, $redis = null): array {
        if (isset($data) && is_string($data)) {
            if (!is_null($redis) && Config::isRedisStoreTemplate()) {
                // Redis stored template
                if ($redis->del(self::REDIS_KEY_TEMPLATE . $data) > 0 && $redis->zRem(self::REDIS_KEY_TEMPLATE_INDEX, $data) > 0) {
                    return self::response('success', $data, 'template removed');
                } else {
                    Logger::log('error', "failed to remove template from Redis ({$data})");
                }
            } else {
                // file-based template
                $templateDir = Config::getEnv('EMAIL_TEMPLATE_DIR');
                $filepath = $templateDir . $data;

                if (is_file($filepath)) {
                    if (unlink($filepath)) {
                        return self::response('success', $data, 'template removed');
                    } else {
                        Logger::log('error', "failed to remove template ({$data})");
                    }
                }
            }
        } else {
            Logger::log('warning', "invalid removeTemplate payload");
            return self::response('error', 'invalid file path string', 'invalid payload');
        }
        return self::response('error', null, 'unable to remove template');
    }

    /**
     * clear all template
     * @param mixed $data incoming payload
     * @param \Redis|null $redis Redis instance
     */
    protected static function clearTemplate($data, $redis = null): array {
        if (!is_null($redis) && Config::isRedisStoreTemplate()) {
            // Redis stored template

            // delete using sorted sets index
            $totalTemplate = $redis->zCard(self::REDIS_KEY_TEMPLATE_INDEX);
            if ($totalTemplate > 0) {
                $keyList = $redis->zRange(self::REDIS_KEY_TEMPLATE_INDEX, 0, -1);

                $multi = $redis->multi();
                foreach ($keyList as $key) {
                    $multi->del(self::REDIS_KEY_TEMPLATE . $key);
                    $multi->zRem(self::REDIS_KEY_TEMPLATE_INDEX, $key);
                }
                $multi->exec();
            }

            return self::response('success', ['removed' => $totalTemplate], 'removed ' . strval($totalTemplate) . ' templates');
        } else {
            // file-based template
            $templateDir = Config::getEnv('EMAIL_TEMPLATE_DIR');
            $templateList = self::scanTemplateDir($templateDir);

            if ($templateList !== false) {
                $totalTemplate = count($templateList);
                if (count($templateList) > 0) {
                    foreach ($templateList as $file) {
                        if (is_file($templateDir . $file)) {
                            @unlink($templateDir . $file);
                        }
                    }

                    return self::response('success', ['removed' => $totalTemplate], 'removed ' . strval($totalTemplate) . ' templates');
                } else {
                    return self::response('success', [], 'no template found');
                }
            } else {
                Logger::log('warning', "unable to read template dir");
            }
        }
        return self::response('error', null, 'unable to read template dir');
    }

    /**
     * Process queue file
     * @param string $file queue JSON filename
     * @param \Redis|null $redis Redis instance
     */
    protected static function processQueueFile(string $file, $redis): array {
        $response = self::response('error', null, 'unable to find or read queue mail');

        $maxRetry = Config::getEnv('QUEUE_MAX_FAILED_RETRY');
        $fullEncrypt = Config::getEnv('QUEUE_FULL_ENCRYPT');
        $filepath = Config::getEnv('QUEUE_PROCESS_DIR') . $file;
        $content = false;

        if (!is_null($redis) && Config::isRedisStoreQueue()) {
            // Redis stored queue
            $content = $redis->get(self::REDIS_KEY_QUEUE_PROCESSING . $file);
        } else {
            // file-based queue
            if (is_file($filepath)) {
                $content = file_get_contents($filepath);
            } else {
                Logger::log('warning', "queue mail not found");
            }
        }

        if ($content !== false) {
            // decrypt full document if needed
            $json = $fullEncrypt ? Crypto::decrypt($content) : $content;

            if ($json !== false) {
                $data = is_string($json) ? json_decode($json, true) : $json;
                // decrypt SMTP password if needed
                if (!$fullEncrypt && array_key_exists('smtpEncryptPassword', $data)) {
                    $pw = Crypto::decrypt($data['smtpEncryptPassword']);
                    if ($pw !== false) {
                        $data['smtpPassword'] = $pw;
                        unset($data['smtpEncryptPassword']);
                    } else {
                        Logger::log('error', "failed to decrypt queue mail ({$file})");
                        return $response;
                    }
                }

                // send mail via PhpMailer
                $response = self::prepareAndSendMail($data, $redis);

                if ($response['status'] === 'success') {
                    Logger::log('info', "queue mail sent successfully ({$file})");
                    // delete queue file
                    self::deleteProcessingQueuedMail($file, $redis);
                } elseif ($response['message'] === 'failed to send mail') {
                    Logger::log('error', "failed to send queue mail ({$file})");
                    // push back to queue for retry
                    if (array_key_exists('failToDelivered', $data)) {
                        $data['failToDelivered'] += 1;
                    } else {
                        $data['failToDelivered'] = 1;
                    }

                    // encrypt SMTP password if needed
                    if (!$fullEncrypt && array_key_exists('smtpPassword', $data)) {
                        $data['smtpEncryptPassword'] = Crypto::encrypt($data['smtpPassword']);
                        unset($data['smtpPassword']);
                    }

                    // update and encrypt full document if needed
                    if (!is_null($redis) && Config::isRedisStoreQueue()) {
                        // Redis stored queue
                        $redis->set(self::REDIS_KEY_QUEUE_PROCESSING . $file, $fullEncrypt ? Crypto::encrypt(json_encode($data)) : $data);
                    } else {
                        // file-based queue
                        file_put_contents($filepath, $fullEncrypt ? Crypto::encrypt(json_encode($data)) : json_encode($data));
                    }

                    if ($data['failToDelivered'] <= $maxRetry || $maxRetry === -1) {
                        // move back to queue folder
                        if (self::moveProcessingMailBackToQueue($file, $redis)) {
                            $response['message'] = 'failed to send mail and added back to queue';
                        }
                    } else {
                        // delete file
                        if (self::deleteProcessingQueuedMail($file, $redis)) {
                            $response['message'] = 'failed to send mail and discarded';
                        }
                    }
                }
            } else {
                Logger::log('error', "failed to decrypt queue mail ({$file})");
            }
        } else {
            Logger::log('error', "failed to read queue mail ({$file})");
        }
        return $response;
    }

    /**
     * Ping request
     */
    protected static function ping(): array {
        return self::response('success', null, 'pong');
    }

    /**
     * Status request
     * @param \Redis|null $redis Redis instance
     */
    protected static function status($redis): array {
        $sent = 0;
        $failed = 0;

        if (Config::isRedisEnabled() && !is_null($redis)) {
            $sent = $redis->get(self::REDIS_KEY_SENT_SUCCESS);
            $failed = $redis->get(self::REDIS_KEY_SENT_FAILED);
        } elseif (Config::isSingleWorker()) {
            $sent = static::$sentSuccess;
            $failed = static::$sentFailed;
        } else {
            return self::response('error', null, 'Redis store or running in single worker is required');
        }

        return self::response('success', [
            'sent' => $sent !== false ? $sent : 0,
            'failed' => $failed !== false ? $failed : 0
        ], null);
    }

    /**
     * Process incoming request
     * @param string $request request method name
     * @param mixed $payload incoming payload
     * @param \Redis|null $redis Redis instance
     */
    protected static function processRequest(string $request, $data, $redis): array {
        $response = self::response('error', null, 'invalid request');

        $queueEnabled = Config::getEnv('MAILER_QUEUE');
        $queueApiReadOnly = Config::getEnv('MAILER_QUEUE_API_READ_ONLY');
        $templateEnabled = Config::getEnv('EMAIL_TEMPLATE');
        $templateApiReadOnly = Config::getEnv('EMAIL_TEMPLATE_API_READ_ONLY');

        switch ($request) {
            case 'ping':
                $response = self::ping();
                break;
            case 'status':
                $response = self::status($redis);
                break;
            case 'sendMail':
                $response = self::sendMail($data, $redis);
                break;
            case 'queueMail':
                $response = $queueEnabled ? self::queueMail($data, $redis) : self::queueNotEnabled();
                break;
            case 'getQueueList':
                $response = $queueEnabled ? self::getQueueList($data, $redis) : self::queueNotEnabled();
                break;
            case 'getQueuedMail':
                $response = $queueEnabled ? self::getQueuedMail($data, $redis) : self::queueNotEnabled();
                break;
            case 'updateQueuedMail':
                $response = $queueEnabled ? ($queueApiReadOnly ? self::queueIsReadOnly() : self::updateQueuedMail($data, $redis)) : self::queueNotEnabled();
                break;
            case 'removeQueuedMail':
                $response = $queueEnabled ? ($queueApiReadOnly ? self::queueIsReadOnly() : self::removeQueuedMail($data, $redis)) : self::queueNotEnabled();
                break;
            case 'clearQueue':
                $response = $queueEnabled ? ($queueApiReadOnly ? self::queueIsReadOnly() : self::clearQueue($data, $redis)) : self::queueNotEnabled();
                break;
            case 'getTemplateList':
                $response = $templateEnabled ? self::getTemplateList($data, $redis) : self::templateNotEnabled();
                break;
            case 'getTemplate':
                $response = $templateEnabled ? self::getTemplate($data, $redis) : self::templateNotEnabled();
                break;
            case 'addTemplate':
                $response = $templateEnabled ? ($templateApiReadOnly ? self::templateIsReadOnly() : self::addTemplate($data, $redis)) : self::templateNotEnabled();
                break;
            case 'updateTemplate':
                $response = $templateEnabled ? ($templateApiReadOnly ? self::templateIsReadOnly() : self::updateTemplate($data, $redis)) : self::templateNotEnabled();
                break;
            case 'removeTemplate':
                $response = $templateEnabled ? ($templateApiReadOnly ? self::templateIsReadOnly() : self::removeTemplate($data, $redis)) : self::templateNotEnabled();
                break;
            case 'clearTemplate':
                $response = $templateEnabled ? ($templateApiReadOnly ? self::templateIsReadOnly() : self::clearTemplate($data, $redis)) : self::templateNotEnabled();
                break;
            default:
                Logger::log('warning', "invalid request");
        }

        Logger::log('debug', "request processed: {$request}");
        return $response;
    }

    /**
     * Scan queue directory for JSON
     * @return array|bool return a file list, false when failed
     */
    protected static function scanQueueDir(string $dir) {
        $fileList = scandir($dir);
        if ($fileList !== false) {
            // filter out non-JSON files
            return array_values(preg_grep(Validator::QUEUE_FILE_REGEX, $fileList));
        }
        return false;
    }

    /**
     * Scan template directory for files
     * @return array|bool return a file list, false when failed
     */
    protected static function scanTemplateDir(string $dir) {
        $fileList = scandir($dir);
        if ($fileList !== false) {
            return array_values(preg_grep(Validator::TEMPLATE_FILE_REGEX, $fileList));
        }
        return false;
    }

    /**
     * Process queue
     * @param int $now current timestamp for unit testing
     * @param \Redis|null $redis Redis instance
     */
    public static function processQueue(int $now = -1, $redis = null): bool {
        if (!is_null($redis) && Config::isRedisStoreQueue()) {
            // Redis stored queue
            return self::processRedisBasedQueue($now, $redis);
        } else {
            // file-based queue
            return self::processFileBasedQueue($now);
        }
    }

    /**
     * Authenticate incoming request
     * @param mixed $data incoming payload
     * @param \Redis|null $redis Redis instance
     */
    public static function authenticateRequest($data, $redis): array {
        $response = self::response('error', null, 'invalid request');

        $requireAuth = Config::getEnv('MAILER_AUTH');
        $queueIsEnabled = Config::getEnv('MAILER_QUEUE');

        $auth = false;

        if ($requireAuth) {
            if (!empty($data) && is_array($data) && array_key_exists('auth', $data) && Hasher::verify($data['auth'])) { // verify auth password
                $auth = true;
                unset($data['auth']);
            }
        } else {
            $auth = true;
        }

        if ($queueIsEnabled) {
            if (!empty($data) && is_array($data) && array_key_first($data) === 'processQueueFile' && isset($data['processQueueFile'])) { // allow request from queue worker with verified encryption
                $file = Crypto::decrypt($data['processQueueFile']);
                if ($file !== false && preg_match((!is_null($redis) && Config::isRedisStoreQueue()) ? Validator::REDIS_QUEUE_REGEX : Validator::QUEUE_FILE_REGEX, $file)) {
                    $auth = true;
                    $data['processQueueFile'] = $file;
                    Logger::log('debug', "processQueueFile request received");
                } else {
                    $auth = false;
                    Logger::log('info', "invalid processQueueFile request");
                }
            }
        }

        if ($auth) {
            if (!empty($data) && is_array($data)) {
                $request = array_key_first($data);
                if ($request !== null) {
                    if ($request === 'processQueueFile') {
                        $response = $queueIsEnabled ? self::processQueueFile($data[$request], $redis) : self::queueNotEnabled();
                    } elseif ($request === 'addTemplate' || $request === 'updateTemplate' || $request === 'updateQueuedMail') {
                        $response = self::processRequest($request, $data, $redis);
                    } else {
                        $response = self::processRequest($request, $data[$request], $redis);
                    }
                } else {
                    Logger::log('warning', "request with invalid payload");
                    $response = self::response('error', null, 'invalid payload');
                }
            } else {
                Logger::log('warning', "request with empty payload");
                $response = self::response('error', null, 'payload cannot be empty');
            }
        } else {
            Logger::log('warning', "unauthorized request");
            $response = self::response('error', null, 'unauthorized request');
        }

        return $response;
    }

    /**
     * API response object
     * @param string $status status string
     * @param mixed $data response data (null if none)
     * @param mixed $message additional message (null if none)
     */
    public static function response(string $status, $data, $message): array {
        return [
            'status' => $status,
            'data' => $data,
            'message' => $message
        ];
    }

    /**
     * Reset Redis count
     * @param \Redis|null $redis Redis instance
     */
    public static function resetRedisStats($redis): bool {
        if (Config::getEnv('REDIS_RESET_STATS_ON_START')) {
            $redis->set(self::REDIS_KEY_SENT_SUCCESS, 0);
            $redis->set(self::REDIS_KEY_SENT_FAILED, 0);
        } else {
            $redis->setNx(self::REDIS_KEY_SENT_SUCCESS, 0);
            $redis->setNx(self::REDIS_KEY_SENT_FAILED, 0);
        }
        return false;
    }
}
