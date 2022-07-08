<?php

/**
 * Entry file for email sending worker
 * use terminal command to start service
 * ---------
 * php start-mailer.php start -d
 * php start-mailer.php restart -d
 * php start-mailer.php reload
 * php start-mailer.php stop
 * php start-mailer.php status
 * php start-mailer.php connections
 * ---------
 * This file is part of the SMTP Mailer service.
 *
 * @license MIT
 * (c) 2022 Eric Chow <https://cmchow.com>
 * License at https://opensource.org/licenses/MIT
 */

require_once __DIR__ . '/vendor/autoload.php';

use Core\Config;
use Core\Logger;
use Core\Mailer;
use Core\Validator;
use Workerman\Worker;
use Workerman\Connection\TcpConnection;
use Workerman\Timer;
use Workerman\Crontab\Crontab;

const MAILER_VERSION = '1.1.1';
const MAILER_NAME = 'SMTPMailer';
const QUEUE_NAME = 'SMTPMailQueue';

// init Config and Validator instance
$env = null;
$basePath = null;
$validator = null;
$config = null;

if (isset($argv[1])) {
    echo MAILER_NAME . ' - version ' .  MAILER_VERSION . PHP_EOL;
    echo '---------------------------' . PHP_EOL;

    // accept CLI params
    if (($argv[1] === 'start' || $argv[1] === 'restart')) {
        $cmdEnv = array_search('--env', $argv);
        if ($cmdEnv !== false && isset($argv[$cmdEnv+1])) {
            $env = $argv[$cmdEnv+1];
        }
        $baseEnv = array_search('--basepath', $argv);
        if ($baseEnv !== false && isset($argv[$baseEnv+1])) {
            $basePath = $argv[$baseEnv+1];
        }
        $validator = Validator::createInstance();
        $config = Config::createInstance($env, $basePath);
    }
}

// check service is running in PHAR
if (!empty(\Phar::running(false))) {
    $parts = explode('/', \Phar::running(false));
    array_pop($parts);
    $pharPath = implode('/', $parts) . '/';
    Worker::$logFile = $pharPath . 'smtp-mailer-workerman.log';
    Worker::$pidFile = $pharPath . 'smtp-mailer-workerman.pid';
    Worker::$statusFile = $pharPath . 'smtp-mailer-workerman.status';
}

$mailer = null; // server instance

// enable service SSL if needed
if (Config::isSslEnabled()) {
    $sslCert = Config::getEnv('MAILER_SSL_CERT');
    $sslKey = Config::getEnv('MAILER_SSL_KEY');

    if (is_readable($sslCert) && is_readable($sslKey)) {
        $context = [
            'ssl' => [
                'local_cert' => $sslCert,
                'local_pk' => $sslKey,
                'verify_peer' => false,
                'verify_peer_name' => false,
                'allow_self_signed' => true
            ]
        ];
        $mailer = new Worker(Config::serviceListenAddress(), $context);
        $mailer->transport = 'ssl';
    } else {
        throw new \Exception('unable to read SSL cert/key file');
    }
} else {
    $mailer = new Worker(Config::serviceListenAddress());
}

$mailer->count = Config::getEnv('MAILER_WORKERS'); // worker process count

$mailer->name = MAILER_NAME; // worker name

// enable Redis connections
if (Config::isRedisEnabled()) {
    $mailer->redisConnection = [];
}

$mailer->onWorkerStart = function (Worker $worker) use ($mailer) {
    $id = $worker->id;

    $maxMemory = Config::getEnv('MAILER_MAX_MEMORY');
    Timer::add(60, function () use ($worker, $maxMemory) {
        if (memory_get_usage(true) > $maxMemory * 1024 * 1024 && count($worker->connections) == 0) {
            // Restart current process if memory leak is detected.
            Worker::stopAll();
        }
    });

    $restartCron = Config::getEnv('MAILER_RESTART_CRON');
    if (!empty($restartCron)) {
        // Restart current process according to Cron schedule
        new Crontab($restartCron, function () use ($id) {
            // avoid restarting all workers at the same time
            Timer::add(60 * $id, function () {
                Worker::stopAll();
            }, [], false);
        });
    }

    $mailer->redisConnection[$id] = null;
    if (Config::isRedisEnabled()) {
        try {
            // init Redis server connection
            $mailer->redisConnection[$id] = new \Redis();
            if (!Config::connectRedis($mailer->redisConnection[$id])) {
                Logger::log('error', "Redis connection failed");
            }
        } catch (\Throwable $e) {
            Logger::log('error', "Redis connection exception {$e->getMessage()}");
        }
    }

    if ($id === 0) {
        // init Redis data
        if (Config::isRedisEnabled() && !is_null($mailer->redisConnection[$id])) {
            Mailer::resetRedisStats($mailer->redisConnection[$id]);
        }
    }
};

$mailer->onMessage = function (TcpConnection $connection, $payload) use ($mailer) {
    $id = $connection->worker->id;
    $response = Mailer::response('error', null, null);
    try {
        $data = json_decode($payload, true); // decode incoming payload
        $response = Mailer::authenticateRequest($data, $mailer->redisConnection[$id]);
    } catch (\Throwable $e) {
        Logger::log('error', "service worker exception: {$e->getMessage()}");
        $response = Mailer::response('error', null, 'exception occurred');
    }
    $connection->send(json_encode($response)); // return response

    $maxRequest = Config::getEnv('MAILER_MAX_REQUEST');
    if ($maxRequest > 0) {
        static $requestCount = 0;
        if (++$requestCount >= $maxRequest) {
            // Restart current process if max request is exceeded
            Worker::stopAll();
        }
    }
};

$mailer->onError = function (TcpConnection $connection, $code, $msg) {
    Logger::log('error', "service worker error ({$code}): {$msg}");
};


// queue worker (if enabled)
if (Config::getEnv('MAILER_QUEUE')) {
    $queueManager = new Worker();  // omit address for isolated worker

    $queueManager->count = 1; // single worker to avoid duplicate task

    $queueManager->name = QUEUE_NAME; // mailer worker name

    // enable Redis connections
    $queueManager->redisConnection = null;

    $queueManager->onWorkerStart = function (Worker $worker) use ($queueManager) {
        if (Config::isRedisEnabled()) {
            try {
                // init Redis server connection
                $queueManager->redisConnection = new \Redis();
                if (!Config::connectRedis($queueManager->redisConnection)) {
                    Logger::log('error', "Redis connection failed");
                }
            } catch (\Throwable $e) {
                Logger::log('error', "Redis connection exception {$e->getMessage()}");
            }
        }

        if (Config::getEnv('QUEUE_SCAN_MODE') === 'cron' && !empty(Config::getEnv('QUEUE_SCAN_CRON'))) {
            new Crontab(Config::getEnv('QUEUE_SCAN_CRON'), function () use ($queueManager) {
                Logger::log('debug', "start processing queue");
                Mailer::processQueue(-1, $queueManager->redisConnection);
                Logger::log('debug', "queue processed");
            });
        } else {
            Timer::add(Config::getEnv('QUEUE_SCAN_INTERVAL'), function () use ($queueManager) {
                Logger::log('debug', "start processing queue");
                Mailer::processQueue(-1, $queueManager->redisConnection);
                Logger::log('debug', "queue processed");
            });
        }
    };

    $queueManager->onError = function (TcpConnection $connection, $code, $msg) {
        Logger::log('error', "queue worker error ({$code}): {$msg}");
    };
}

// when service is reloaded
Worker::$onMasterReload = function () use ($env, $basePath) {
    $validator = Validator::reloadInstance();
    $config = Config::reloadInstance($env, $basePath);

    // get active workers
    $activeWorkers = [];
    foreach (Worker::getAllWorkers() as $worker) {
        $activeWorkers[$worker->name] = $worker;
    }

    // change old worker config
    foreach ($activeWorkers as $service => $worker) {
        if ($worker->name === MAILER_NAME) {
            $worker->count = Config::getEnv('MAILER_WORKERS');
        }
    }
};

Worker::runAll();
