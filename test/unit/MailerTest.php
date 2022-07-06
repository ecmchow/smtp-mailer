<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Core\Config;
use Core\Crypto;
use Core\Mailer;

define('UNITTEST_MOCK_TIME', time());

final class MailerTest extends TestCase {

    private const QUEUE_DIR = __DIR__ . '/../env/Queue/mail/';
    private const TEMP_DIR = __DIR__ . '/../env/Queue/temp/';
    private const TEMPLATE_DIR = __DIR__ . '/../env/Template/html/';
    private const QUEUE_FILE_REGEX = "/^mail_([0-9]+)\.([0-9]+)_([a-z0-9]+)\.json/";
    private const TEMPLATE_FILE_REGEX = "/^[^.].*$/";

    private static $config = null;
    private static $capture = null;
    private static $currentLogSetting = null;
    private static $templateHTML = '<!doctype html>
    <html lang="en">
    <head>
      <title>Test Email</title>
      <meta http-equiv="X-UA-Compatible" content="IE=edge">
      <meta http-equiv="Content-Type" content="text/html; charset=UTF-8">
      <meta name="viewport" content="width=device-width,initial-scale=1">
    </head>
    <body style="word-spacing:normal;">
      <h1>Test Email</h1>
    </body>
    </html>';

    private static function expectedResponse(string $status, $data, $message): array {
        return [
            'status' => $status,
            'data' => $data,
            'message' => $message
        ];
    }

    private static function clearTestJsonFiles() {
        $queueFiles = self::scanQueueJsonFiles();
        $tempFiles = self::scanTempJsonFiles();
        foreach ($queueFiles as $file) {
            if (is_file(self::QUEUE_DIR . $file)) {
                @unlink(self::QUEUE_DIR . $file);
            }
        }
        foreach ($tempFiles as $file) {
            if (is_file(self::TEMP_DIR . $file)) {
                @unlink(self::TEMP_DIR . $file);
            }
        }
    }

    private static function clearTestTemplateFiles() {
        $files = self::scanTemplateFiles();
        foreach ($files as $file) {
            if (is_file(self::TEMPLATE_DIR . $file)) {
                @unlink(self::TEMPLATE_DIR . $file);
            }
        }
    }

    private static function scanQueueJsonFiles() {
        return array_values(preg_grep(self::QUEUE_FILE_REGEX, scandir(self::QUEUE_DIR)));
    }

    private static function scanTempJsonFiles() {
        return array_values(preg_grep(self::QUEUE_FILE_REGEX, scandir(self::TEMP_DIR)));
    }

    private static function scanTemplateFiles() {
        return array_values(preg_grep(self::TEMPLATE_FILE_REGEX, scandir(self::TEMPLATE_DIR)));
    }

    public static function setUpBeforeClass(): void {
        self::$currentLogSetting = ini_get('error_log');

        self::$capture = tmpfile();
        ini_set('error_log', stream_get_meta_data(self::$capture)['uri']);

        self::clearTestTemplateFiles();
        self::clearTestJsonFiles();
        file_put_contents(self::TEMPLATE_DIR . 'test-1.html', self::$templateHTML);
        file_put_contents(self::TEMPLATE_DIR . 'test-2.html', self::$templateHTML);
    }

    /**
     * @dataProvider invalidDataProvider
     */
    public function testCanValidateRequestIsInvalid($input, array $expected): void {
        self::$config = Config::reloadInstance(__DIR__ . '/../env/auth-disabled.env');
        $this->assertSame(
            $expected,
            Mailer::authenticateRequest($input, null)
        );
    }

    public function invalidDataProvider(): array {
        return [
            // case 0
            [
                [],
                self::expectedResponse('error', null, 'payload cannot be empty') // expected
            ],
            // case 1
            [
                null,
                self::expectedResponse('error', null, 'payload cannot be empty') // expected
            ],
            // case 2
            [
                [
                    'auth' => 'abc12345'
                ],
                self::expectedResponse('error', null, 'invalid request') // expected
            ],
            // case 3
            [
                [
                    'sendMail' => '',
                    'auth' => 'abc12345'
                ],
                self::expectedResponse('error', [
                    'payload' => ['The data (null) must match the type: object']
                ], 'invalid payload') // expected
            ]
        ];
    }

    /**
     * @dataProvider unauthorizedDataProvider
     */
    public function testCanAuthenticateRequestIsUnauthorized($input, array $expected): void {
        self::$config = Config::reloadInstance(__DIR__ . '/../env/sodium.env');
        $this->assertSame(
            $expected,
            Mailer::authenticateRequest($input, null)
        );
    }

    public function unauthorizedDataProvider(): array {
        return [
            // case 0
            [
                [
                    'sendMail' => '',
                    'auth' => ''
                ],
                self::expectedResponse('error', null, 'unauthorized request') // expected
            ],
            // case 1
            [
                [
                    'processQueueFile' => 'abc',
                ],
                self::expectedResponse('error', null, 'unauthorized request') // expected
            ],
            // case 2
            [
                [
                    'clearTemplate' => '',
                    'auth' => 'wrong-password'
                ],
                self::expectedResponse('error', null, 'unauthorized request') // expected
            ],
            // case 3
            [
                [
                    'clearTemplate' => '',
                    'auth' => 'abc12345'
                ],
                self::expectedResponse('error', null, 'template service is read-only') // expected
            ]
        ];
    }

    /**
     * @dataProvider disableAuthDataProvider
     */
    public function testCanDisableAuthentication($input, array $expected): void {
        self::$config = Config::reloadInstance(__DIR__ . '/../env/auth-disabled.env');
        $this->assertSame(
            $expected,
            Mailer::authenticateRequest($input, null)
        );
    }

    public function disableAuthDataProvider(): array {
        return [
            // case 0
            [
                [
                    'sendMail' => ''
                ],
                self::expectedResponse('error', [
                    'payload' => ['The data (null) must match the type: object']
                ], 'invalid payload') // expected
            ],
            // case 1
            [
                [
                    'clearTemplate' => ''
                ],
                self::expectedResponse('error', null, 'template service is read-only') // expected
            ]
        ];
    }

    public function testPingResponseIsValid(): void {
        $this->assertSame(
            [
                'status' => 'success',
                'data' => null,
                'message' => 'pong'
            ],
            Mailer::authenticateRequest(['ping' => ''], null)
        );
    }

    /**
     * @dataProvider sendEmailDataProvider
     */
    public function testCanSendMockEmail($input, array $expected): void {

        $this->expectOutputRegex('/SMTP Error: Could not connect to SMTP host./');
        
        // clear previous stream
        $temp = stream_get_contents(self::$capture);

        $this->assertSame(
            $expected,
            Mailer::authenticateRequest($input, null)
        );

        var_dump(stream_get_contents(self::$capture));
    }

    public function sendEmailDataProvider(): array {
        return [
            // case 0
            [
                [
                    'sendMail' => [
                        'to' => ['user@example.test'],
                        'ccList' => [],
                        'bccList' => [],
                        'subject' => 'This is subject',
                        'body' => self::$templateHTML
                    ]
                ],
                self::expectedResponse('error', 'SMTP Error: Could not connect to SMTP host.', 'failed to send mail') // expected
            ],
            // case 1
            [
                [
                    'sendMail' => [
                        'to' => ['user@example.test'],
                        'ccList' => [],
                        'bccList' => [],
                        'replyTo' => ['user@example.test'],
                        'attachments' => [],
                        'embedded' => [],
                        'subject' => 'This is subject',
                        'useTemplate' => 'test-1.html'
                    ]
                ],
                self::expectedResponse('error', 'SMTP Error: Could not connect to SMTP host.', 'failed to send mail') // expected
            ],
            // case 2
            [
                [
                    'sendMail' => [
                        'to' => [],
                        'ccList' => [
                            ['user@example.test', 'Name']
                        ],
                        'bccList' => [],
                        'replyTo' => [
                            ['user@example.test', 'Name']
                        ],
                        'attachments' => [],
                        'embedded' => [],
                        'subject' => 'This is subject',
                        'useTemplate' => 'test-1.html'
                    ]
                ],
                self::expectedResponse('error', 'SMTP Error: Could not connect to SMTP host.', 'failed to send mail') // expected
            ],
            // case 3
            [
                [
                    'sendMail' => [
                        'to' => ['user@example.test'],
                        'ccList' => [],
                        'bccList' => [],
                        'subject' => 'This is subject',
                        'body' => self::$templateHTML,
                        'timeout' => 3
                    ]
                ],
                self::expectedResponse('error', 'SMTP Error: Could not connect to SMTP host.', 'failed to send mail') // expected
            ]
        ];
    }

    public function testCanMoveFailedSmtpEmailToQueue(): void {
        $queueFiles = self::scanQueueJsonFiles();
        $expectedData = self::sendEmailDataProvider();

        $queueCount = count($queueFiles);
        $expectedCount = count($expectedData);

        if ($queueCount !== $expectedCount) {
            throw new \Exception("Expected output JSON files number not matched (Expected: {$expectedCount}, Found: {$queueCount})");
        }

        for ($i = 0; $i < count($queueFiles); $i++) {
            $content = json_decode(file_get_contents(self::QUEUE_DIR . $queueFiles[$i]), true);
            $expected = $expectedData[$i][0]['sendMail'];
            $expected['failToDelivered'] = 1;

            ksort($expected);
            ksort($content);

            $this->assertSame(
                $expected,
                $content
            );
        }

        self::clearTestJsonFiles();
    }

    /**
     * @dataProvider queueEmailDataProvider
     */
    public function testCanQueueSmtpEmail($input, array $expected): void {
        $this->assertSame(
            $expected,
            Mailer::authenticateRequest($input, null)
        );
    }

    public function queueEmailDataProvider(): array {
        return [
            // case 0
            [
                [
                    'queueMail' => [
                        'to' => ['user@example.test'],
                        'ccList' => [],
                        'bccList' => [],
                        'attachments' => [],
                        'embedded' => [],
                        'subject' => 'This is subject',
                        'body' => self::$templateHTML
                    ]
                ],
                self::expectedResponse('success', null, 'mail added to queue') // expected
            ],
            // case 1
            [
                [
                    'queueMail' => [
                        'to' => ['user@example.test'],
                        'ccList' => [],
                        'bccList' => [],
                        'attachments' => [],
                        'embedded' => [],
                        'subject' => 'This is subject',
                        'useTemplate' => 'test-1.html',
                        'smtpUser' => 'user2@example.test',
                        'smtpPassword' => 'abc12345'
                    ]
                ],
                self::expectedResponse('success', null, 'mail added to queue') // expected
            ]
        ];
    }

    public function testVerifyQueuedMailIsValid(): void {
        $queueFiles = self::scanQueueJsonFiles();
        $expectedData = self::queueEmailDataProvider();

        if (count($queueFiles) !== count($expectedData)) {
            throw new \Exception("Expected output JSON files number not matched");
        }

        for ($i = 0; $i < count($queueFiles); $i++) {
            $content = json_decode(file_get_contents(self::QUEUE_DIR . $queueFiles[$i]), true);
            $expected = $expectedData[$i][0]['queueMail'];

            if (isset($expected['smtpPassword'])) {
                $content['smtpPassword'] = Crypto::decrypt($content['smtpEncryptPassword']);
                unset($content['smtpEncryptPassword']);
            }

            $expected['failToDelivered'] = 0;

            ksort($expected);
            ksort($content);

            $this->assertSame(
                $expected,
                $content
            );
        }
        self::clearTestJsonFiles();
    }

    /**
     * @dataProvider processQueueEmailDataProvider
     */
    public function testCanProcessQueue($input, array $expected): void {
        $this->assertSame(
            $expected,
            Mailer::authenticateRequest($input, null)
        );

        $this->assertSame(
            true,
            Mailer::processQueue($input['queueMail']['scheduleTime'])
        );

        $tempFiles = self::scanTempJsonFiles();
        if (count($tempFiles) !== 1) {
            throw new \Exception("Expected output JSON files number not matched" . count($tempFiles));
        }

        $this->expectOutputRegex('/SMTP Error: Could not connect to SMTP host./');

        // clear previous stream
        $temp = stream_get_contents(self::$capture);

        $this->assertSame(
            self::expectedResponse('error', 'SMTP Error: Could not connect to SMTP host.', 'failed to send mail and added back to queue'),
            Mailer::authenticateRequest([
                'processQueueFile' => Crypto::encrypt($tempFiles[0])
            ], null)
        );

        var_dump(stream_get_contents(self::$capture));
        self::clearTestJsonFiles();
    }

    public function processQueueEmailDataProvider(): array {
        return [
            // case 0
            [
                [
                    'queueMail' => [
                        'scheduleTime' => UNITTEST_MOCK_TIME - 60,
                        'to' => ['user@example.test'],
                        'ccList' => [],
                        'bccList' => [],
                        'attachments' => [],
                        'embedded' => [],
                        'subject' => 'This is subject',
                        'body' => self::$templateHTML
                    ]
                ],
                self::expectedResponse('success', null, 'mail added to queue') // expected
            ],
            // case 1
            [
                [
                    'queueMail' => [
                        'scheduleTime' => UNITTEST_MOCK_TIME + 60,
                        'to' => ['user@example.test'],
                        'ccList' => [],
                        'bccList' => [],
                        'attachments' => [],
                        'embedded' => [],
                        'subject' => 'This is subject',
                        'body' => self::$templateHTML
                    ]
                ],
                self::expectedResponse('success', null, 'mail added to queue') // expected
            ]
        ];
    }

    /**
     * @dataProvider scheduleEmailDataProvider
     */
    public function testCanScheduleSmtpEmail($input, array $expected): void {
        $this->assertSame(
            $expected,
            Mailer::authenticateRequest($input, null)
        );
    }

    public function scheduleEmailDataProvider(): array {
        return [
            // case 0
            [
                [
                    'queueMail' => [
                        'scheduleTime' => UNITTEST_MOCK_TIME + 300,
                        'to' => ['user@example.test'],
                        'ccList' => [],
                        'bccList' => [],
                        'attachments' => [],
                        'embedded' => [],
                        'subject' => 'This is subject',
                        'body' => self::$templateHTML
                    ]
                ],
                self::expectedResponse('success', null, 'mail added to queue') // expected
            ],
            // case 1
            [
                [
                    'queueMail' => [
                        'scheduleTime' => UNITTEST_MOCK_TIME + 3600,
                        'to' => ['user@example.test'],
                        'ccList' => [],
                        'bccList' => [],
                        'attachments' => [],
                        'embedded' => [],
                        'subject' => 'This is subject',
                        'useTemplate' => 'test-1.html',
                        'smtpUser' => 'user2@example.test',
                        'smtpPassword' => 'abc12345'
                    ]
                ],
                self::expectedResponse('success', null, 'mail added to queue') // expected
            ]
        ];
    }

    public function testVerifyScheduledMailIsValid(): void {
        $queueFiles = self::scanQueueJsonFiles();
        $expectedData = self::scheduleEmailDataProvider();

        if (count($queueFiles) !== count($expectedData)) {
            throw new \Exception("Expected output JSON files number not matched");
        }

        for ($i = 0; $i < count($queueFiles); $i++) {
            $content = json_decode(file_get_contents(self::QUEUE_DIR . $queueFiles[$i]), true);
            $expected = $expectedData[$i][0]['queueMail'];

            if (isset($expected['smtpPassword'])) {
                $content['smtpPassword'] = Crypto::decrypt($content['smtpEncryptPassword']);
                unset($content['smtpEncryptPassword']);
            }

            $expected['failToDelivered'] = 0;

            ksort($expected);
            ksort($content);

            $this->assertSame(
                $expected,
                $content
            );

            $createTime = intval(explode('_', explode('.', $queueFiles[$i])[0])[1]);

            $this->assertSame(
                $expected['scheduleTime'],
                $createTime
            );
        }
    }

    public function testCanGetQueueList(): void {
        $queueFiles = self::scanQueueJsonFiles();
        $expectedData = self::scheduleEmailDataProvider();

        if (count($queueFiles) !== count($expectedData)) {
            throw new \Exception("Expected output JSON files number not matched");
        }

        $this->assertSame(
            self::expectedResponse('success', ['items' => $queueFiles, 'total' => count($queueFiles)], 'found ' . strval(count($queueFiles)) . ' mails in queue'),
            Mailer::authenticateRequest([
                'getQueueList' => null
            ], null)
        );

        $this->assertSame(
            self::expectedResponse('success', ['items' => [$queueFiles[0]], 'total' => count($queueFiles)], 'found ' . strval(count($queueFiles)) . ' mails in queue'),
            Mailer::authenticateRequest([
                'getQueueList' => ['limit' => 1]
            ], null)
        );
    }

    public function testCanGetQueueMailContent(): void {
        $queueFiles = self::scanQueueJsonFiles();
        $expectedData = json_decode(file_get_contents(self::QUEUE_DIR . $queueFiles[0]), true);

        $this->assertSame(
            self::expectedResponse('success', $expectedData, $queueFiles[0]),
            Mailer::authenticateRequest([
                'getQueuedMail' => $queueFiles[0]
            ], null)
        );
    }

    public function testCanUpdateQueueMailContent(): void {
        self::$config = Config::reloadInstance(__DIR__ . '/../env/api-allow-edit.env');
        $queueFiles = self::scanQueueJsonFiles();
        $expectedData = json_decode(file_get_contents(self::QUEUE_DIR . $queueFiles[0]), true);
        $expectedData['subject'] = 'Edited Subject';

        $response = Mailer::authenticateRequest([
            'updateQueuedMail' => $queueFiles[0],
            'content' => [
                'subject' => 'Edited Subject'
            ]
        ], null);
        unset($response['message']['updated']);

        $this->assertSame(
            self::expectedResponse('success', $expectedData, ['original' => $queueFiles[0]]),
            $response
        );
    }

    public function testCanRemoveQueuedMail(): void {
        $queueFiles = self::scanQueueJsonFiles();

        $this->assertSame(
            self::expectedResponse('success', $queueFiles[0], 'queued mail removed'),
            Mailer::authenticateRequest([
                'removeQueuedMail' => $queueFiles[0]
            ], null)
        );

        $this->expectWarning();
        $this->expectWarningMessageMatches('/Failed to open stream: No such file or directory/i');

        file_get_contents(self::QUEUE_DIR . $queueFiles[0]);
    }

    public function testCanClearAllQueueMails(): void {
        $queueFiles = self::scanQueueJsonFiles();

        $this->assertSame(
            self::expectedResponse('success', null, 'removed ' . strval(count($queueFiles)) . ' mails in queue'),
            Mailer::authenticateRequest([
                'clearQueue' => null
            ], null)
        );

        $this->assertSame(
            0,
            count(self::scanQueueJsonFiles())
        );

        self::clearTestJsonFiles();
    }

    public function testCanGetTemplateList(): void {
        $templateFiles = self::scanTemplateFiles();
        $expectedData = ['test-1.html', 'test-2.html'];

        if (count($templateFiles) !== count($expectedData)) {
            throw new \Exception("Expected template files number not matched");
        }

        $this->assertSame(
            self::expectedResponse('success', ['items' => $expectedData, 'total' => count($expectedData)], 'found ' . strval(count($expectedData)) . ' templates'),
            Mailer::authenticateRequest([
                'getTemplateList' => null
            ], null)
        );

        $this->assertSame(
            self::expectedResponse('success', ['items' => [$expectedData[0]], 'total' => count($expectedData)], 'found ' . strval(count($expectedData)) . ' templates'),
            Mailer::authenticateRequest([
                'getTemplateList' => ['limit' => 1]
            ], null)
        );
    }

    public function testCanGetTemplateContent(): void {
        $expectedData = file_get_contents(self::TEMPLATE_DIR . 'test-1.html');

        $this->assertSame(
            self::expectedResponse('success', $expectedData, 'template found'),
            Mailer::authenticateRequest([
                'getTemplate' => 'test-1.html'
            ], null)
        );
    }

    public function testCanAddTemplate(): void {
        $expectedData = file_get_contents(self::TEMPLATE_DIR . 'test-1.html');

        $this->assertSame(
            self::expectedResponse('success', 'test-3.html', 'template added'),
            Mailer::authenticateRequest([
                'addTemplate' => 'test-3.html',
                'content' => $expectedData
            ], null)
        );

        $content = file_get_contents(self::TEMPLATE_DIR . 'test-3.html');
        if ($content !== false) {
            $this->assertSame(
                $expectedData,
                $content
            );
        } else {
            throw new \Exception("Unable to get new template file");
        }
    }

    public function testCannotAddTemplateWithSameFilename(): void {
        $this->assertSame(
            self::expectedResponse('error', 'file already exists', 'unable to add template'),
            Mailer::authenticateRequest([
                'addTemplate' => 'test-3.html',
                'content' => ''
            ], null)
        );
    }

    public function testCanUpdateTemplate(): void {
        $this->assertSame(
            self::expectedResponse('success', 'test-3.html', 'template updated'),
            Mailer::authenticateRequest([
                'updateTemplate' => 'test-3.html',
                'content' => '12345'
            ], null)
        );

        $content = file_get_contents(self::TEMPLATE_DIR . 'test-3.html');
        if ($content !== false) {
            $this->assertSame(
                '12345',
                $content
            );
        } else {
            throw new \Exception("Unable to get new template file");
        }
    }

    public function testCanRemoveTemplate(): void {
        $this->assertSame(
            self::expectedResponse('success', 'test-3.html', 'template removed'),
            Mailer::authenticateRequest([
                'removeTemplate' => 'test-3.html'
            ], null)
        );

        $this->expectWarning();
        $this->expectWarningMessageMatches('/Failed to open stream: No such file or directory/i');

        file_get_contents(self::TEMPLATE_DIR . 'test-3.html');
    }

    public function testCanClearAllTemplate(): void {
        $this->assertSame(
            self::expectedResponse('success', null, 'removed 2 templates'),
            Mailer::authenticateRequest([
                'clearTemplate' => null
            ], null)
        );

        $this->assertSame(
            0,
            count(self::scanTemplateFiles())
        );
    }

    /**
     * @dataProvider fullEncryptDataProvider
     */
    public function testCanFullyEncryptQueueFile($input, array $expected): void {
        self::$config = Config::reloadInstance(__DIR__ . '/../env/full-encrypt-enabled.env');

        $this->assertSame(
            $expected,
            Mailer::authenticateRequest($input, null)
        );

        $queueFiles = self::scanQueueJsonFiles();
        $decryptedData = json_decode(Crypto::decrypt(file_get_contents(self::QUEUE_DIR . $queueFiles[0])), true);

        $expected = $input['queueMail'];
        $expected['failToDelivered'] = 0;

        ksort($expected);
        ksort($decryptedData);

        $this->assertSame(
            $expected,
            $decryptedData
        );

        self::clearTestJsonFiles();
    }

    public function fullEncryptDataProvider(): array {
        return [
            // case 0
            [
                [
                    'queueMail' => [
                        'to' => ['user@example.test'],
                        'ccList' => [],
                        'bccList' => [],
                        'attachments' => [],
                        'embedded' => [],
                        'subject' => 'This is subject',
                        'useTemplate' => 'test-1.html',
                        'smtpUser' => 'user2@example.test',
                        'smtpPassword' => 'abc12345'
                    ]
                ],
                self::expectedResponse('success', null, 'mail added to queue') // expected
            ],
            // case 1
            [
                [
                    'queueMail' => [
                        'to' => [],
                        'ccList' => [],
                        'bccList' => ['bcc@example.test'],
                        'attachments' => [],
                        'embedded' => [],
                        'subject' => 'This is subject',
                        'body' => 'abc'
                    ]
                ],
                self::expectedResponse('success', null, 'mail added to queue') // expected
            ]
        ];
    }

    /**
     * @dataProvider disableQueueDataProvider
     */
    public function testCanDisableQueue($input, array $expected): void {
        self::$config = Config::reloadInstance(__DIR__ . '/../env/queue-template-disabled.env');

        $this->assertSame(
            $expected,
            Mailer::authenticateRequest($input, null)
        );
    }

    public function disableQueueDataProvider(): array {
        return [
            // case 0
            [
                [
                    'queueMail' => [
                        'to' => ['user@example.test'],
                        'ccList' => [],
                        'bccList' => [],
                        'attachments' => [],
                        'embedded' => [],
                        'subject' => 'This is subject',
                        'useTemplate' => 'test-1.html',
                        'smtpUser' => 'user2@example.test',
                        'smtpPassword' => 'abc12345'
                    ]
                ],
                self::expectedResponse('error', null, 'queue service is not enabled') // expected
            ],
            // case 1
            [
                [
                    'processQueueFile' => ''
                ],
                self::expectedResponse('error', null, 'queue service is not enabled') // expected
            ]
        ];
    }

    /**
     * @dataProvider disableTemplateDataProvider
     */
    public function testCanDisableTemplate($input, array $expected): void {
        $this->assertSame(
            $expected,
            Mailer::authenticateRequest($input, null)
        );
    }

    public function disableTemplateDataProvider(): array {
        return [
            // case 0
            [
                [
                    'sendMail' => [
                        'to' => ['user@example.test'],
                        'ccList' => [],
                        'bccList' => [],
                        'attachments' => [],
                        'embedded' => [],
                        'subject' => 'This is subject',
                        'useTemplate' => 'test-1.html',
                        'smtpUser' => 'user2@example.test',
                        'smtpPassword' => 'abc12345'
                    ]
                ],
                self::expectedResponse('error', null, 'template service is not enabled') // expected
            ],
            // case 1
            [
                [
                    'getTemplate' => 'test.html'
                ],
                self::expectedResponse('error', null, 'template service is not enabled') // expected
            ]
        ];
    }

    public function testStatusResponseIsValid(): void {
        $this->assertSame(
            [
                'status' => 'success',
                'data' => [
                    'sent' => 0,
                    'failed' => 6
                ],
                'message' => null
            ],
            Mailer::authenticateRequest(['status' => ''], null)
        );
    }

    public static function tearDownAfterClass(): void {
        ini_set('error_log', self::$currentLogSetting);
        self::clearTestJsonFiles();
        self::clearTestTemplateFiles();
    }
}
