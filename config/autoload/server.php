<?php

declare(strict_types=1);
/**
 * This file is part of Hyperf.
 *
 * @link     https://www.hyperf.io
 * @document https://hyperf.wiki
 * @contact  group@hyperf.io
 * @license  https://github.com/hyperf/hyperf/blob/master/LICENSE
 */
use Hyperf\Server\Event;
use Hyperf\Server\Server;
use Swoole\Constant;

return [
    'mode' => SWOOLE_PROCESS,
    'servers' => [
        [
            'name' => 'http',
            'type' => Server::SERVER_HTTP,
            'host' => '0.0.0.0',
            'port' => 45105,
            'sock_type' => SWOOLE_SOCK_TCP,
            'callbacks' => [
                Event::ON_REQUEST => [Hyperf\HttpServer\Server::class, 'onRequest'],
            ],
        ],
        [
            'name' => 'tcp',
            'type' => Server::SERVER_BASE,
            'host' => '0.0.0.0',
            'port' => 45104,
            'sock_type' => SWOOLE_SOCK_TCP,
            'callbacks' => [
                Event::ON_RECEIVE => [App\Controller\TcpServer::class, 'onReceive'],
            ],
            'settings' => [
                // 按需配置
                'open_eof_split' => true, // 启用 EOF 自动分包
                'package_eof' => "\r\n", // 设置 EOF 字符串
                'heartbeat_check_interval' => 600,//秒
                'heartbeat_idle_time' => 1500,
            ],
        ],
        [
            'name' => 'tcp',
            'type' => Server::SERVER_BASE,
            'host' => '0.0.0.0',
            'port' => 45106,
            'sock_type' => SWOOLE_SOCK_TCP,
            'callbacks' => [
                Event::ON_RECEIVE => [App\Controller\TcpServer::class, 'onReceive'],
            ],
            'settings' => [
                // 按需配置
                'open_eof_split' => true, // 启用 EOF 自动分包
                'package_eof' => "\r\n", // 设置 EOF 字符串
                'heartbeat_check_interval' => 600,//秒
                'heartbeat_idle_time' => 1500,
            ],
        ],
//        [
//            'name' => 'jsonrpc-http',
//            'type' => Server::SERVER_HTTP,
//            'host' => '0.0.0.0',
//            'port' => 9604,
//            'sock_type' => SWOOLE_SOCK_TCP,
//            'callbacks' => [
//                Event::ON_REQUEST => [\Hyperf\JsonRpc\HttpServer::class, 'onRequest'],
//            ],
//        ],
//        [
//            'name' => 'jsonrpc',
//            'type' => Server::SERVER_BASE,
//            'host' => '0.0.0.0',
//            'port' => 9503,
//            'sock_type' => SWOOLE_SOCK_TCP,
//            'callbacks' => [
//                Event::ON_RECEIVE => [\Hyperf\JsonRpc\TcpServer::class, 'onReceive'],
//            ],
//            'settings' => [
//                'open_eof_split' => true,
//                'package_eof' => "\r\n",
//                'package_max_length' => 1024 * 1024 * 2,
//            ],
//        ],
    ],
    'settings' => [
        'daemonize' => false, //后台作为守护进程
        Constant::OPTION_ENABLE_COROUTINE => true,
        Constant::OPTION_WORKER_NUM => swoole_cpu_num(),
        Constant::OPTION_PID_FILE => BASE_PATH . '/runtime/hyperf.pid',
        Constant::OPTION_OPEN_TCP_NODELAY => true,
        Constant::OPTION_MAX_COROUTINE => 100000,
        Constant::OPTION_OPEN_HTTP2_PROTOCOL => true,
        Constant::OPTION_MAX_REQUEST => 100000,
        Constant::OPTION_SOCKET_BUFFER_SIZE => 2 * 1024 * 1024,
        Constant::OPTION_BUFFER_OUTPUT_SIZE => 2 * 1024 * 1024,
    ],
    'callbacks' => [
        Event::ON_WORKER_START => [Hyperf\Framework\Bootstrap\WorkerStartCallback::class, 'onWorkerStart'],
        Event::ON_PIPE_MESSAGE => [Hyperf\Framework\Bootstrap\PipeMessageCallback::class, 'onPipeMessage'],
        Event::ON_WORKER_EXIT => [Hyperf\Framework\Bootstrap\WorkerExitCallback::class, 'onWorkerExit'],
    ],
];
