<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

define('UNITTEST_MOCK_TIME', time());

final class RedisBasicTest extends TestCase {

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

    private static $redis = null;
    private static $getQueueFilename = null;

    private static function expectedResponse(string $status, $data, $message): array {
        return [
            'status' => $status,
            'data' => $data,
            'message' => $message
        ];
    }

    private static function clearTestData() {
        $it = null;
        while ($keys = static::$redis->scan($it)) {
            foreach ($keys as $key) {
                static::$redis->del(substr($key, 17));
            }
        }
    }

    private static function clearQueueData() {
        $it = null;
        while ($keys = static::$redis->scan($it, 'SMTP_MAILER_TEST:queue:*')) {
            foreach ($keys as $key) {
                static::$redis->del(substr($key, 17));
            }
        }
        static::$redis->del('index:queue');
    }

    private static function clearTempData() {
        $it = null;
        while ($keys = static::$redis->scan($it, 'SMTP_MAILER_TEST:temp:*')) {
            foreach ($keys as $key) {
                static::$redis->del(substr($key, 17));
            }
        }
    }

    private static function scanQueueData() {
        $keyList = [];
        $it = null;
        while ($keys = static::$redis->scan($it, 'SMTP_MAILER_TEST:queue:*')) {
            foreach ($keys as $key) {
                $keyList[] = $key;
            }
        }
        sort($keyList);
        return $keyList;
    }

    private static function scanTempData() {
        $keyList = [];
        $it = null;
        while ($keys = static::$redis->scan($it, 'SMTP_MAILER_TEST:temp:*')) {
            foreach ($keys as $key) {
                $keyList[] = $key;
            }
        }
        sort($keyList);
        return $keyList;
    }

    private static function scanTemplateData() {
        $keyList = [];
        $it = null;
        while ($keys = static::$redis->scan($it, 'SMTP_MAILER_TEST:template:*')) {
            foreach ($keys as $key) {
                $keyList[] = $key;
            }
        }
        sort($keyList);
        return $keyList;
    }

    public static function setUpBeforeClass(): void {
        static::$redis = new \Redis();
        static::$redis->connect('tcp://127.0.0.1', 6379, 0, null, 100, 10);
        static::$redis->setOption(\Redis::OPT_SERIALIZER, \Redis::SERIALIZER_JSON);
        static::$redis->setOption(\Redis::OPT_PREFIX, 'SMTP_MAILER_TEST:');
        static::$redis->setOption(\Redis::OPT_SCAN, \Redis::SCAN_RETRY);
        static::$redis->setOption(\Redis::OPT_SCAN, \Redis::SCAN_NOPREFIX);
        self::clearTestData();
        static::$redis->set('template:test1', self::$templateHTML);
        static::$redis->zAdd('index:template', 0, 'test1');
    }

    private static function connect($payload): array {
        try {
            $response = '';

            $context = stream_context_create();
            $fp = stream_socket_client('tcp://127.0.0.1:3333', $errno, $errstr, 10, STREAM_CLIENT_CONNECT, $context);

            if (stream_set_timeout($fp, 3)) {
                if (!$fp) {
                    throw new \Exception("open TCP sock failed ({$errno}: {$errstr})");
                } else {
                    fwrite($fp, json_encode($payload));
                    while (!feof($fp)) {
                        $buffer = fread($fp, 1024);
                        $response .= $buffer;
                        if (strlen($buffer) < 1024) {
                            break;
                        }
                    }
                    fclose($fp);
                }
                $result = json_decode($response, true);
                return $result;
            } else {
                throw new \Exception("stream_set_timeout failed");
            }
        } catch (\Throwable $e) {
            throw new \Exception("send exception: {$e->getMessage()})");
        }
    }
        
    public function testCanConnectToServiceAndReceiveResponse(): void {
        $this->assertSame(
            self::expectedResponse('error', null, 'payload cannot be empty'), // expected
            self::connect(null)
        );
    }

    /**
     * @dataProvider invalidDataProvider
     */
    public function testCanConnectWithInvalidData($input, array $expected): void {
        $this->assertSame(
            $expected,
            self::connect($input)
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

    public function testPingResponseIsValid(): void {
        $this->assertSame(
            [
                'status' => 'success',
                'data' => null,
                'message' => 'pong'
            ],
            self::connect(['ping' => ''])
        );
    }

    /**
     * @dataProvider retrieveTemplateDataProvider
     */
    public function testCanRetrieveTemplate($input, array $expected): void {
        $this->assertSame(
            $expected,
            self::connect($input)
        );
    }

    public function retrieveTemplateDataProvider(): array {
        return [
            // case 0
            [
                ['getTemplate' => 'test1'],
                self::expectedResponse('success', self::$templateHTML, 'template found') // expected
            ],
            // case 1
            [
                ['getTemplate' => 'test2'],
                self::expectedResponse('error', null, 'unable to get template') // expected
            ]
        ];
    }

    public function testCanAddTemplate(): void {
        $templateName = 'test123';
        $templateContent = '<html>This is template content</html>';

        $this->assertSame(
            self::expectedResponse('success', $templateName, 'template added'),
            self::connect([
                'addTemplate' => $templateName,
                'content' => $templateContent
            ])
        );

        $templateFiles = self::scanTemplateData();

        $this->assertSame(
            2,
            count($templateFiles)
        );

        $this->assertSame(
            true,
            in_array('SMTP_MAILER_TEST:template:' . $templateName, $templateFiles, true)
        );
    }

    public function testCanUpdateTemplate(): void {
        $templateName = 'test123';
        $templateContent = '<html>This is new content</html>';

        $this->assertSame(
            self::expectedResponse('success', $templateName, 'template updated'),
            self::connect([
                'updateTemplate' => $templateName,
                'content' => $templateContent
            ])
        );

        $this->assertSame(
            self::expectedResponse('success', $templateContent, 'template found'),
            self::connect([
                'getTemplate' => $templateName
            ])
        );
    }

    public function testCanGetTemplateList(): void {
        $this->assertSame(
            self::expectedResponse('success', [
                'items' => ['test1','test123'],
                'total' => 2,
            ], 'found 2 templates'),
            self::connect([
                'getTemplateList' => null
            ])
        );
    }

    public function testCanGetTemplateListWithPagination(): void {
        $this->assertSame(
            self::expectedResponse('success', [
                'items' => ['test123'],
                'total' => 2,
            ], 'found 2 templates'),
            self::connect([
                'getTemplateList' => [
                    'limit' => 1,
                    'page' => 1
                ]
            ])
        );
    }

    public function testCanRemoveTemplate(): void {
        $templateName = 'test123';

        $this->assertSame(
            self::expectedResponse('success', $templateName, 'template removed'),
            self::connect([
                'removeTemplate' => $templateName
            ])
        );

        $templateFiles = self::scanTemplateData();

        $this->assertSame(
            1,
            count($templateFiles)
        );

        $this->assertSame(
            false,
            in_array('SMTP_MAILER_TEST:template:' . $templateName, $templateFiles, true)
        );
    }

    /**
     * @dataProvider sendEmailDataProvider
     */
    public function testCanConnectAndSendMockEmail($input, array $expected): void {
        $this->assertSame(
            $expected,
            self::connect($input)
        );
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
                        'body' => '<html>This is content</html>'
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
                        'useTemplate' => 'test1'
                    ]
                ],
                self::expectedResponse('error', 'SMTP Error: Could not connect to SMTP host.', 'failed to send mail') // expected
            ]
        ];
    }

    public function testCanMoveFailedSmtpEmailToQueue(): void {
        $queueFiles = self::scanQueueData();
        $expectedData = self::sendEmailDataProvider();

        if (count($queueFiles) !== count($expectedData)) {
            throw new \Exception("Expected output JSON files number not matched");
        }

        for ($i = 0; $i < count($queueFiles); $i++) {
            $content = static::$redis->get(substr($queueFiles[$i], 17));
            $expected = $expectedData[$i][0]['sendMail'];
            $expected['failToDelivered'] = 1;

            ksort($expected);
            ksort($content);

            $this->assertSame(
                $expected,
                $content
            );
        }

        self::clearQueueData();
        self::clearTempData();
    }

    /**
     * @dataProvider queueEmailDataProvider
     */
    public function testCanQueueSmtpEmail($input, array $expected): void {
        $this->assertSame(
            $expected,
            self::connect($input)
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
                        'useTemplate' => 'test1',
                        'smtpUser' => 'user2@example.test',
                        'smtpPassword' => 'abc12345'
                    ]
                ],
                self::expectedResponse('success', null, 'mail added to queue') // expected
            ]
        ];
    }

    public function testVerifyQueuedMailIsValid(): void {
        $queueFiles = self::scanQueueData();
        $expectedData = self::queueEmailDataProvider();

        if (count($queueFiles) !== count($expectedData)) {
            throw new \Exception("Expected output JSON files number not matched");
        }

        for ($i = 0; $i < count($queueFiles); $i++) {
            $content = static::$redis->get(substr($queueFiles[$i], 17));
            $expected = $expectedData[$i][0]['queueMail'];

            unset($expected['smtpPassword']);
            unset($content['smtpEncryptPassword']);

            $expected['failToDelivered'] = 0;

            ksort($expected);
            ksort($content);

            $this->assertSame(
                $expected,
                $content
            );
        }
        self::clearQueueData();
        self::clearTempData();
    }

    /**
     * @dataProvider scheduleEmailDataProvider
     */
    public function testCanScheduleSmtpEmail($input, array $expected): void {
        $this->assertSame(
            $expected,
            self::connect($input)
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
                        'useTemplate' => 'test1',
                        'smtpUser' => 'user2@example.test',
                        'smtpPassword' => 'abc12345'
                    ]
                ],
                self::expectedResponse('success', null, 'mail added to queue') // expected
            ]
        ];
    }

    public function testVerifyScheduledMailIsValid(): void {
        $queueFiles = self::scanQueueData();
        $expectedData = self::scheduleEmailDataProvider();

        if (count($queueFiles) !== count($expectedData)) {
            throw new \Exception("Expected output JSON files number not matched");
        }

        for ($i = 0; $i < count($queueFiles); $i++) {
            $content = static::$redis->get(substr($queueFiles[$i], 17));
            $expected = $expectedData[$i][0]['queueMail'];

            unset($expected['smtpPassword']);
            unset($content['smtpEncryptPassword']);

            $expected['failToDelivered'] = 0;

            ksort($expected);
            ksort($content);

            $this->assertSame(
                $expected,
                $content
            );

            $createTime = intval(explode('.', substr($queueFiles[$i], 17 + 6))[0], 10);

            $this->assertSame(
                $expected['scheduleTime'],
                $createTime
            );
        }
    }

    public function testCanGetQueueList(): void {
        $queueFiles = self::scanQueueData();
        $queueFiles = str_replace('SMTP_MAILER_TEST:queue:', '', $queueFiles);

        $this->assertSame(
            self::expectedResponse('success', [
                'items' => $queueFiles,
                'total' => 2,
            ], 'found 2 mails in queue'),
            self::connect([
                'getQueueList' => null
            ])
        );
    }

    public function testCanGetQueuedListWithPagination(): void {
        $queueFiles = self::scanQueueData();
        $queueFiles = str_replace('SMTP_MAILER_TEST:queue:', '', $queueFiles);

        $this->assertSame(
            self::expectedResponse('success', [
                'items' => [$queueFiles[1]],
                'total' => 2,
            ], 'found 2 mails in queue'),
            self::connect([
                'getQueueList' => [
                    'limit' => 1,
                    'page' => 1
                ]
            ])
        );

        static::$getQueueFilename = $queueFiles[1];
    }

    public function testCanGetQueuedMail(): void {
        $content = static::$redis->get('queue:' . static::$getQueueFilename);
        unset($content['smtpEncryptPassword']);

        $this->assertSame(
            self::expectedResponse('success', $content, static::$getQueueFilename),
            self::connect([
                'getQueuedMail' => static::$getQueueFilename
            ])
        );
    }

    public function testCanUpdateQueuedMail(): void {
        $newSubject = 'new subject';
        $newTime = UNITTEST_MOCK_TIME + 4000;

        $content = static::$redis->get('queue:' . static::$getQueueFilename);
        unset($content['smtpEncryptPassword']);
        $content['scheduleTime'] = $newTime;
        $content['subject'] = $newSubject;

        $response = self::connect([
            'updateQueuedMail' => static::$getQueueFilename,
            'content' => [
                'scheduleTime' => $newTime,
                'subject' => $newSubject
            ]
        ]);
        $newFilename = $response['message']['updated'];
        unset($response['message']['updated']);

        $this->assertSame(
            self::expectedResponse('success', $content, ['original' => static::$getQueueFilename]),
            $response
        );

        $newContent = static::$redis->get('queue:' . $newFilename);
        unset($newContent['smtpEncryptPassword']);

        $this->assertSame(
            $newContent,
            $content
        );

        static::$getQueueFilename = $newFilename;
    }

    public function testCanRemoveQueuedMail(): void {
        $this->assertSame(
            self::expectedResponse('success', static::$getQueueFilename, 'queued mail removed'),
            self::connect([
                'removeQueuedMail' => static::$getQueueFilename
            ])
        );

        $queueFiles = self::scanQueueData();

        $this->assertSame(
            1,
            count($queueFiles)
        );

        $this->assertSame(
            false,
            in_array(static::$getQueueFilename, $queueFiles, true)
        );
    }

    public function testStatusResponseIsValid(): void {
        $this->assertSame(
            [
                'status' => 'success',
                'data' => [
                    'sent' => 0,
                    'failed' => 2
                ],
                'message' => null
            ],
            self::connect(['status' => ''])
        );
    }

    public function testCanClearAllTemplate(): void {
        $templateFiles = self::scanTemplateData();

        $this->assertSame(
            1,
            count($templateFiles)
        );

        $this->assertSame(
            self::expectedResponse('success', ['removed' => 1], 'removed 1 templates'),
            self::connect([
                'clearTemplate' => null
            ])
        );

        $templateFiles = self::scanTemplateData();

        $this->assertSame(
            0,
            count($templateFiles)
        );
    }

    public function testCanClearAllQueuedMail(): void {
        $queueFiles = self::scanQueueData();

        $this->assertSame(
            1,
            count($queueFiles)
        );

        $this->assertSame(
            self::expectedResponse('success', ['removed' => 1], 'removed 1 mails in queue'),
            self::connect([
                'clearQueue' => null
            ])
        );

        $queueFiles = self::scanQueueData();

        $this->assertSame(
            0,
            count($queueFiles)
        );
    }

    public static function tearDownAfterClass(): void {
        self::clearTestData();
    }
}
