<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Core\Validator;

final class ValidatorTest extends TestCase {
    
    public function testInstanceCanBeCreatedFromValidJsonSchema(): void {
        $this->assertInstanceOf(
            Validator::class,
            Validator::createInstance()
        );
    }

    public function testInstanceCanBeReloaded(): void {
        $this->assertInstanceOf(
            Validator::class,
            Validator::reloadInstance()
        );
    }

    /**
     * @dataProvider validDataProvider
     */
    public function testCanValidateDataIsValid(array $input, array $expected): void {
        $this->assertSame(
            $expected,
            Validator::validate($input[0], $input[1])
        );
    }

    public function validDataProvider(): array {
        return [
            // case 0
            [
                [
                    'env',
                    [
                        'MAILER_PROTO' => 'tcp',
                        'MAILER_ADDR' => '127.0.0.1',
                        'MAILER_PORT' => 3000,
                        'MAILER_SSL_CERT' => '',
                        'MAILER_SSL_KEY' => '',
                        'MAILER_WORKERS' => 4,
                        'MAILER_MAX_MEMORY' => 64,
                        'MAILER_LOG' => true,
                        'MAILER_LOG_LEVEL' => 'notice',
                        'MAILER_LOG_OUTPUT' => 'error_log',
                        'MAILER_AUTH' => false,
                        'MAILER_AUTH_HASH_METHOD' => 'bcrypt',
                        'MAILER_QUEUE' => true,
                        'MAILER_QUEUE_API_READ_ONLY' => true,
                        'QUEUE_SCAN_INTERVAL' => 60,
                        'QUEUE_MAX_BATCH_SIZE' => 20,
                        'QUEUE_MAX_FAILED_RETRY' => 1,
                        'QUEUE_DIR' => __DIR__ . '/../Queue/mail/',
                        'QUEUE_PROCESS_DIR' => __DIR__ . '/../Queue/temp/',
                        'QUEUE_FULL_ENCRYPT' => false,
                        'QUEUE_ENCRYPT_METHOD' => 'AES128',
                        'SMTP_HOST' => 'smtp.example.com',
                        'SMTP_USER' => 'test@example.com',
                        'SMTP_PASSWORD' => '',
                        'SMTP_ENCRYPTION' => 'tls',
                        'SMTP_PORT' => 587,
                        'MAIL_HTML' => true,
                        'MAIL_CHARSET' => 'utf-8',
                        'MAIL_ENCODING' => '8bit',
                        'MAIL_FROM_ADDR' => 'test@example.com',
                        'MAIL_FROM_NAME' => 'Test',
                        'EMAIL_TEMPLATE' => true,
                        'EMAIL_TEMPLATE_DIR' => __DIR__ . '/../Email/template/',
                        'EMAIL_TEMPLATE_API_READ_ONLY' => true
                    ]
                ],
                [true, ''] // expected
            ],
            // case 1
            [
                [
                    'sendMail',
                    [
                        'to' => ['test@example.com'],
                        'ccList' => [],
                        'bccList' => [
                            ['test@example.com', 'name']
                        ],
                        'attachments' => [],
                        'embedded' => [
                            ['/path/to/img', 'logo', 'logo.png']
                        ],
                        'subject' => 'abc',
                        'body' => '<html>This is content</html>'
                    ]
                ],
                [true, ''] // expected
            ],
            // case 2
            [
                [
                    'sendMail',
                    [
                        'to' => [],
                        'ccList' => [
                            ['test@example.com', 'name']
                        ],
                        'bccList' => ['test@example.com'],
                        'attachments' => [
                            ['/path/to/file', 'doc.pdf']
                        ],
                        'embedded' => [],
                        'subject' => 'abc',
                        'useTemplate' => 'abc.html',
                        'replaceContent' => [
                            'name' => 'My Name',
                            'time' => time()
                        ]
                    ]
                ],
                [true, ''] // expected
            ],
            // case 3
            [
                [
                    'updateQueuedMail',
                    [
                        'subject' => 'abc',
                        'body' => '<html>This is content</html>'
                    ]
                ],
                [true, ''] // expected
            ],
            // case 4
            [
                [
                    'updateQueuedMail',
                    [
                        'to' => ['test@example.com'],
                    ]
                ],
                [true, ''] // expected
            ]
        ];
    }

    /**
     * @dataProvider invalidDataProvider
     */
    public function testCanValidateDataIsInvalid(array $input, array $expected): void {
        $this->assertSame(
            $expected,
            Validator::validate($input[0], $input[1])
        );
    }

    public function invalidDataProvider(): array {
        return [
            // case 0
            [
                [
                    'env',
                    []
                ],
                [
                    false,
                    [
                        'payload' => ['The data (array) must match the type: object']
                    ]
                ] // expected
            ],
            // case 1
            [
                [
                    'env',
                    [
                        'MAILER_ADDR' => 'tcp://127.0.0.1:3000',
                        'MAILER_WORKERS' => 4,
                        'MAILER_MAX_MEMORY' => 64
                    ]
                ],
                [
                    false,
                    [
                        'payload' => ['The required properties (SMTP_HOST, SMTP_USER, SMTP_PASSWORD) are missing']
                    ]
                ] // expected
            ],
            // case 2
            [
                [
                    'env',
                    [
                        'MAILER_ADDR' => 3000,
                        'MAILER_SSL' => 'false',
                        'MAILER_WORKERS' => '4',
                        'MAILER_MAX_MEMORY' => 0,
                        'MAILER_AUTH' => false,
                        'MAILER_QUEUE' => true,
                        'SMTP_HOST' => '',
                        'SMTP_USER' => '',
                        'SMTP_PASSWORD' => '',
                        'MAIL_CHARSET' => 'utf-8',
                        'EMAIL_TEMPLATE' => true
                    ]
                ],
                [
                    false,
                    [
                        'MAILER_ADDR' => ['The data (integer) must match the type: string'],
                        'MAILER_WORKERS' => ['The data (string) must match the type: integer'],
                        'MAILER_MAX_MEMORY' => ['Number must be greater than or equal to 16'],
                        'SMTP_HOST' => ['The data must match the \'hostname\' format'],
                        'SMTP_USER' => ['Minimum string length is 1, found 0']
                    ]
                ] // expected
            ],
            // case 3
            [
                [
                    'sendMail',
                    [
                        'to' => ['test@example.com'],
                        'ccList' => [],
                        'bccList' => [
                            ['test@example.com', 'name']
                        ],
                        'attachments' => [],
                        'embedded' => [
                            ['/path/to/img', 'logo', 'logo.png']
                        ],
                        'subject' => 'abc',
                    ]
                ],
                [
                    false,
                    [
                        'payload' => [
                            'The required properties (body) are missing',
                            'The required properties (useTemplate) are missing'
                        ]
                    ]
                ] // expected
            ],
            // case 4
            [
                [
                    'sendMail',
                    [
                        'to' => ['test@example.com'],
                        'ccList' => [],
                        'bccList' => [
                            ['test@example.com', 'name']
                        ],
                        'attachments' => [],
                        'embedded' => [
                            ['/path/to/img', 'logo', 'logo.png']
                        ],
                        'subject' => 'abc',
                    ]
                ],
                [
                    false,
                    [
                        'payload' => [
                            'The required properties (body) are missing',
                            'The required properties (useTemplate) are missing'
                        ]
                    ]
                ] // expected
            ],
            // case 6
            [
                [
                    'updateQueuedMail',
                    null
                ],
                [
                    false,
                    [
                        'payload' => ['The data (null) must match the type: object']
                    ]
                ] // expected
            ]
        ];
    }
}
