<?php
declare(strict_types=1);

namespace App\Controller;

use Hyperf\Contract\OnReceiveInterface;
use Hyperf\Utils\ApplicationContext;
use Hyperf\DB\DB;

class TcpServer implements OnReceiveInterface
{
    public function onReceive($server, int $fd, int $reactorId, string $data): void
    {
        var_dump($data);
        $server->send($fd, $data);
        return;
        $explodeData = explode("\r\n", $data);
        $msg = explode(',', $explodeData[0]);
        $db = ApplicationContext::getContainer()->get(DB::class);
        $student_data = $db->fetch('SELECT stunoo FROM `student`WHERE location_id = ?;', [$msg[2]]);

        if (count($msg) == 3) {
            //心跳
            if ($msg[1] == 6) {
                $server->send($fd, 'ZF');
                return;
            }
            //注册
            if ($msg[1] == 7) {
                $str = '';
                //获取亲情号和上传间隔
                $str .= $this->getQingQinTel($msg[2]);
                //获取免打扰时间
                $str .= $this->getDisturbTime($msg[2]);
                //获取电话白名单
                $str .= $this->getLocationWhite($student_data['stunoo']);

                $server->send($fd, $str);
                return;
            }

        }else{
//            if (count($msg) != 8) {
//                return;
//            }
            //定位时间段设置
//            $is_location = 0;
            $db = ApplicationContext::getContainer()->get(DB::class);
//            $location_locreport = $db->query('SELECT start_time,end_time FROM `location_locreport`WHERE stunoo = ?;', [$student_data['stunoo']]);
//            if ($location_locreport){
//                foreach ($location_locreport as $k => $v) {
//                    $start = strtotime(date('Y-m-d', time()) . ' ' . $v['start_time']);
//                    $end = strtotime(date('Y-m-d', time()) . ' ' . $v['end_time']);
//                    if (time() > $start && time() < $end) {
//                        $is_location = 1;
//                    }
//                }
//                if ($is_location = 0) {
//                    return;
//                }
//            }
            $location_id = $msg[2];
            $container = ApplicationContext::getContainer();
            $redis = $container->get(\Hyperf\Redis\Redis::class);

            $redis_student = $redis->get($location_id);
            if ($redis_student) {
                $redis_student = json_decode($redis_student, true);
                $stunoo = $redis_student['stunoo'];
                if (!$stunoo) {
                    $stunoo = $db->query('SELECT stunoo FROM `student`WHERE location_id = ?;', [$location_id]);
                }

                if ($redis_student['fd'] != $fd) {
                    $redis->setex($location_id, 60 * 5, json_encode(['fd' => $fd, 'stunoo' => $stunoo['stunoo']]));
                }

            }else{

                $stunoo = $db->query('SELECT stunoo FROM `student`WHERE location_id = ?;', [$location_id]);
                $redis->setex($location_id, 60 * 5, json_encode(['fd' => $fd, 'stunoo' => $stunoo['stunoo']]));

            }

            $server->send($fd, 'recv:' . json_encode($result));
        }
        $server->send($fd, 'recv:' . $msg);
    }

    /**
     * 返回亲情号
     * @param $location_id
     * @return string
     * @throws \Psr\Container\ContainerExceptionInterface
     * @throws \Psr\Container\NotFoundExceptionInterface
     */
    public function getQingQinTel($location_id){

        $db = ApplicationContext::getContainer()->get(DB::class);
        $tel_data = $db->query('SELECT b.name_type,b.tel FROM student as a left join qq_tel as b on a.stunoo = b.stunoo WHERE a.location_id = ?;', [$location_id]);

        $student_data = $db->fetch('SELECT stunoo FROM `student`WHERE location_id = ?;', [$location_id]);

        //上传间隔时间
        $interval = '10';
        if ($student_data) {
            $location_interval = $db->fetch('SELECT `interval` FROM `location_interval`WHERE stunoo = ?;', [$student_data['stunoo']]);
            if ($location_interval) {
                $interval = $location_interval['interval'];
            }
        }
        $str = 'ZF7' . $interval;

        //亲情号
        if ($tel_data) {
            if (count($tel_data) >= 1 && $tel_data[0]['tel']) {
                $str .= $tel_data[0]['tel'] . 1;
            } else {
                $str .= '000000000000';
            }
            if (count($tel_data) >= 2 && $tel_data[1]['tel']) {
                $str .= $tel_data[1]['tel'] . 1;
            } else {
                $str .= '000000000000';
            }
        } else {
            $str .= '000000000000000000000000';
        }

        return $str;
    }

    /**
     * 获取免打扰时间
     * @param $location_id
     * @return string
     * @throws \Psr\Container\ContainerExceptionInterface
     * @throws \Psr\Container\NotFoundExceptionInterface
     */
    public function getDisturbTime($location_id){
        $db = ApplicationContext::getContainer()->get(DB::class);
        $disturb_time = $db->query('SELECT b.start_time,b.end_time,b.is_open FROM student as a left join open_location_tel as b on a.stunoo = b.stunoo WHERE a.location_id = ?;', [$location_id]);
        $str = '';
        if ($disturb_time) {
            if (count($disturb_time) >= 1 && $disturb_time[0]['start_time'] && $disturb_time[0]['end_time']) {
                $start_time = str_replace(':', '', $disturb_time[0]['start_time']);
                $end_time = str_replace(':', '', $disturb_time[0]['end_time']);
                $str .= $start_time . $end_time . 1;
            } else {
                $str .= '000000000';
            }

            if (count($disturb_time) >= 2 && $disturb_time[1]['start_time'] && $disturb_time[1]['end_time']) {
                $start_time = str_replace(':', '', $disturb_time[1]['start_time']);
                $end_time = str_replace(':', '', $disturb_time[1]['end_time']);
                $str .= $start_time . $end_time . 1;
            } else {
                $str .= '000000000';
            }

            if (count($disturb_time) >= 3 && $disturb_time[2]->start_time && $disturb_time[2]['end_time']) {
                $start_time = str_replace(':', '', $disturb_time[2]['start_time']);
                $end_time = str_replace(':', '', $disturb_time[2]['end_time']);
                $str .= $start_time . $end_time . 1;
            } else {
                $str .= '000000000';
            }

            if (count($disturb_time) >= 4 && $disturb_time[3]['start_time'] && $disturb_time[3]['end_time']) {
                $start_time = str_replace(':', '', $disturb_time[3]['start_time']);
                $end_time = str_replace(':', '', $disturb_time[3]['end_time']);
                $str .= $start_time . $end_time . 1;
            } else {
                $str .= '000000000';
            }

        } else {
            $str .= '000000000000000000000000000000000000';
        }
        return $str;
    }

    /**
     * 电话白名单
     * @param $stunoo
     * @return string
     * @throws \Psr\Container\ContainerExceptionInterface
     * @throws \Psr\Container\NotFoundExceptionInterface
     */
    public function getLocationWhite($stunoo){
        $db = ApplicationContext::getContainer()->get(DB::class);
        $location_white = $db->query('SELECT * FROM location_white WHERE stunoo = ?;', [$stunoo]);

        $white = '';
        $str = '';
        if ($location_white) {
            $white .= $location_white['white1'] ? $location_white['white1'] . '1' : '000000000000';
            $white .= $location_white['white2'] ? $location_white['white2'] . '1' : '000000000000';
            $white .= $location_white['white3'] ? $location_white['white3'] . '1' : '000000000000';
            $white .= $location_white['white4'] ? $location_white['white4'] . '1' : '000000000000';
            $white .= $location_white['white5'] ? $location_white['white5'] . '1' : '000000000000';
            $white .= $location_white['white6'] ? $location_white['white6'] . '1' : '000000000000';
            $white .= $location_white['white7'] ? $location_white['white7'] . '1' : '000000000000';
            $white .= $location_white['white8'] ? $location_white['white8'] . '1' : '000000000000';
        } else {
            $white = '000000000000000000000000000000000000000000000000000000000000000000000000000000000000000000000000';
        }
        $str .= $white;

        return $str;
    }
}