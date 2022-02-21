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
namespace App\Controller;

use Hyperf\Utils\ApplicationContext;
use App\JsonRpc\CalculatorService;

class IndexController extends AbstractController
{
    public function index()
    {
        $user = $this->request->input('user', 'Hyperf');
        $method = $this->request->getMethod();

        return [
            'method' => $method,
            'message' => "Hello {$user}.",
        ];
    }

    public function add()
    {
//        $client = ApplicationContext::getContainer()->get(CalculatorService::class);
//        $value = $client->add(10, 20);
//        return $value;

        $client = new \Swoole\Client(SWOOLE_SOCK_TCP);
        $client->connect('127.0.0.1', 9504);
        $client->send('ZF,6,867959034543669,0'."\r\n");
        $ret = $client->recv(); // recv:Hello World.
        var_dump($ret);
        return $ret;
    }

}
