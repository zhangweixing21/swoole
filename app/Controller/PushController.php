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

class PushController extends AbstractController
{
    public function pushdata()
    {
        $location_id = $this->request->input('location_id', '');
        $data = $this->request->input('data', '');

        if ($location_id && $data){
            $container = ApplicationContext::getContainer();
            $redis = $container->get(\Hyperf\Redis\Redis::class);
            $redis_student = $redis->get($location_id);
//            var_dump($redis_student);
            if ($redis_student) {
                $redis_student = json_decode($redis_student, true);
                $send_data['zf'] = $data;
                $send_data['fd'] = $redis_student['fd'];
//                var_dump(1);
                // 主动推送消息
                $client = new \Swoole\Client(SWOOLE_SOCK_TCP | SWOOLE_KEEP);
                $client->connect('127.0.0.1', 45104);
                $client->send(json_encode($send_data) . "\r\n");
                $ret = $client->recv(); // recv:Hello World.

                return json_encode(['code' => 0,'msg' => 'ok']);
            }

        }
        return json_encode(['code' => 1001,'msg' => 'error']);
    }

}
