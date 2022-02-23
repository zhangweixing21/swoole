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
        if (is_array(json_decode($data, true))) {
            $msg = json_decode(explode("\r\n", $data)[0], true);

            var_dump($msg);
            $server->send($msg['fd'], $msg['zf']);
        } else {
            var_dump($data);
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

            } else {
                if (count($msg) != 8) {
                    return;
                }
                //定位时间段设置
                $is_location = 0;
                $db = ApplicationContext::getContainer()->get(DB::class);
                $location_locreport = $db->query('SELECT start_time,end_time FROM `location_locreport`WHERE stunoo = ?;', [$student_data['stunoo']]);
                if ($location_locreport) {
                    foreach ($location_locreport as $k => $v) {
                        $start = strtotime(date('Y-m-d', time()) . ' ' . $v['start_time']);
                        $end = strtotime(date('Y-m-d', time()) . ' ' . $v['end_time']);
                        if (time() > $start && time() < $end) {
                            $is_location = 1;
                        }
                    }
                    if ($is_location = 0) {
                        return;
                    }
                }
                $location_id = $msg[2];
                var_dump($location_id);
                $container = ApplicationContext::getContainer();
                $redis = $container->get(\Hyperf\Redis\Redis::class);

                $redis_student = $redis->get($location_id);
                $redis->del($location_id);
                if ($redis_student) {
                    $redis_student = json_decode($redis_student, true);
                    $stunoo = $redis_student['stunoo'];
                    if (!$stunoo) {
                        $stu_data = $db->fetch('SELECT stunoo FROM `student`WHERE location_id = ?;', [$location_id]);
                        $stunoo = $stu_data['stunoo'];
                    }

                    if ($redis_student['fd'] != $fd) {
                        $redis->setex($location_id, 60 * 5, json_encode(['fd' => $fd, 'stunoo' => $stunoo]));
                    }

                } else {
                    $stu_data = $db->fetch('SELECT stunoo FROM `student`WHERE location_id = ?;', [$location_id]);

                    $stunoo = $stu_data['stunoo'];
                    $redis->setex($location_id, 60 * 5, json_encode(['fd' => $fd, 'stunoo' => $stunoo]));
                }

                var_dump($stunoo);
                if (!$stunoo) {
                    var_dump('学生不存在');
                    return;
                }
                $pow = hexdec(bin2hex($msg[3]));
                //获取位置
                $address_data = $this->getAddress($msg[4], $msg[5], $location_id, $msg[6]);
                if ($address_data['result']['type'] == 0) {
                    return;
                }
                $latlon = $address_data['result']['location'];
                $lon = explode(',', $latlon)[0];
                $lat = explode(',', $latlon)[1];
//                $lon = '113.717947';
//                $lat = '34.801657';
                var_dump($lat);
                var_dump($lon);
                $address = preg_replace('# #', '', $address_data['result']['desc']);
                if ($msg[7] == 1) {
                    //报警数据
                    $this->insertdata($stunoo, '', 1, 1, $address, $pow, $lon, $lat);
                    return;
                }
                //是否是进出围栏数据
                $this->enclosure($stunoo, $lon, $lat, $address, $pow, $server, $fd);
            }
        }

        $server->send($fd, 'recv:' . implode(',', $msg));
    }

    /**
     * 围栏数据
     * @param $stunoo
     * @param $lon
     * @param $lat
     * @param $address
     * @param $pow
     * @param $server
     * @param $fd
     * @throws \Psr\Container\ContainerExceptionInterface
     * @throws \Psr\Container\NotFoundExceptionInterface
     */
    public function enclosure($stunoo, $lon, $lat, $address, $pow, $server, $fd)
    {
        $container = ApplicationContext::getContainer();
        $redis = $container->get(\Hyperf\Redis\Redis::class);
        $db = ApplicationContext::getContainer()->get(DB::class);
        $enclosure_data = $db->query('SELECT id,accuracy,dimension,name,distance,is_enter,is_out FROM `enclosure`WHERE stunoo = ?;', [$stunoo]);
        //获取围栏
        if ($enclosure_data) {
            var_dump('有围栏');

            $is_enter = [];
            $is_ent_out = '';
            $is_send = 0;
            $convert = new \Convert();
            foreach ($enclosure_data as $k => $v) {
                $point = ['lng' => $lon, 'lat' => $lat];
                $circle = [
                    'center' => ['lng' => $v['accuracy'], 'lat' => $v['dimension']],
                    'radius' => $v['distance']
                ];
                //判断是否进出围栏,true 围栏内  false 围栏外
                $bool = $convert->is_point_in_circle($point, $circle);
                if ($bool) {
                    var_dump('进围栏');
                    $is_enter = $redis->get($stunoo);
                    if ($is_enter){
                        $is_enter = json_decode($is_enter, true);
                        if ($is_enter && $is_enter['is_enter_out'] == 1) {
                            var_dump($is_enter);
                            $is_send = $is_enter['is_send_number'] ? 0 : 1;
                        }else{
                            $is_send = 1;
                        }
                    }


                    $is_enter['id'] = $v['id'];
                    $is_enter['is_enter_out'] = 1; //0出  1进
                    $is_enter['is_send_number'] = 1; //是否已发送进出数据
                    $is_enter['enclosure_name'] = $v['name'];
                    $is_ent_out = '进';

                    $redis->setex($stunoo, 60 * 60 * 24, json_encode($is_enter));

                    //围栏数据
                    $this->insertdata($stunoo, $is_ent_out . $v['name'], $is_send, 0, $address, $pow, $lon, $lat);
                } else {
                    var_dump('出围栏');
                    $is_enter = $redis->get($stunoo);

                    if ($is_enter) {
                        $is_enter = json_decode($is_enter, true);
                        if ($is_enter && $is_enter['is_enter_out'] == 0) {
                            $is_send = $is_enter['is_send_number'] ? 0 : 1;
                        }else{
                            $is_send = 1;
                        }
                    }
                    //围栏数据
                    $this->insertdata($stunoo, $is_ent_out . $is_enter['enclosure_name'], $is_send, 0, $address, $pow, $lon, $lat);
                    $is_ent_out = '出';
                    $is_enter['id'] = $v['id'];
                    $is_enter['is_enter_out'] = 0; //0出  1进
                    $is_enter['is_send_number'] = 1; //是否已发送进出数据
                    $is_enter['enclosure_name'] = $v['name'];
                    $redis->setex($stunoo, 60 * 60 * 24, json_encode($is_enter));
                }

            }

        } else {
            //普通数据
            var_dump('没有围栏');
            $this->insertdata($stunoo, '', 0, 0, $address, $pow, $lon, $lat);
        }

    }

    /**
     * 插入定位数据
     * @param $stunoo
     * @param $name
     * @param $is_send
     * @param $is_po
     * @param $address
     * @param $pow
     * @param $lon
     * @param $lat
     * @throws \Psr\Container\ContainerExceptionInterface
     * @throws \Psr\Container\NotFoundExceptionInterface
     */
    public function insertdata($stunoo, $name, $is_send, $is_po, $address, $pow, $lon, $lat)
    {
        var_dump($name);
        if ($stunoo) {
            if ($lon && $lat) {

                $db = ApplicationContext::getContainer()->get(DB::class);
                $id = $db->insert('Insert INTO `location_data` SET stunoo = ?,en_name = ?,accuracy = ?,dimension = ?,power = ?,is_police = ?,address = ?,is_gps = ?,is_base = ?,created_at = ?,updated_at = ?;', [$stunoo, $name, $lon, $lat, $pow, $is_po, $address, 0, 1, time(), time()]);

                if ($is_send == 1) {
                    var_dump('推送数据');
                    $student_data = $db->fetch('SELECT stuname FROM `student`WHERE stunoo = ?;', [$stunoo]);
                    if ($student_data) {
                        // no  0围栏  1报警
                        $this->sendMsg($is_po, $stunoo, $student_data['stuname'], $lon, $lat, $id, $address);
                    }
                }
            }


        }
    }

    /**
     * 发送报警信息
     * @param $no
     * @param $stunoo
     */
    public function sendMsg($no, $stunoo, $name, $accuracy, $dimension, $id, $address)
    {

        $time = date('Y-m-d H:i:s', time());
        $position = $address;

        if ($no == 1) {
            //一键报警
            $remark = '您的学生' . $name . '于' . $time . '发送了报警信息,请注意!';
            $content = '您的学生' . $name . '于' . $time . '发送了报警信息!';
        } else {
            //进出围栏
            $remark = '您的学生' . $name . '于' . $time . '处于围栏区域,请注意!';
            $content = '您的学生' . $name . '于' . $time . '处于围栏区域!';
        }

        $href_url = 'http://api.zfzhxy.com/api/locationwx?stunoo=' . $stunoo;
        $db = ApplicationContext::getContainer()->get(DB::class);
        $user_data = $db->query('SELECT * FROM `student` AS a  left join bound_student as b on a.stunoo = b.stunoo left join `user` as c on b.user_id = c.id WHERE a.stunoo = ?;', [$stunoo]);

        if (!empty($user_data)) {
            foreach ($user_data as $k => $v) {
                if ($v['open_id']) {
                    $tempId = \PushData::getTempid(0);
                    $url = \PushData::getUrl();
                    $weixin_data = \PushData::createTempMsg($v['open_id'], $tempId, $content, $v['stuname'], $position, $time, $remark, $href_url);
                    $result = \PushData::singlePostMsg($url, $weixin_data);
                    var_dump($result);
                }
            }
            $id = $db->insert('Insert INTO `system_message` SET school_id = ?,stunoo = ?,title = ?,content = ?,created_at = ?,updated_at = ?;', ['10000', $stunoo, '危险警告', $remark, $time, $time]);
        }
    }

    /**
     * 获取位置
     * @param $lon
     * @param $lat
     * @param $location_id
     * @param $signal
     * @return mixed
     */
    public function getAddress($lon, $lat, $location_id, $signal)
    {
        //获取位置
        $lon = hexdec($lon);
        $lat = hexdec($lat);
        //信号强度, 取值范围：0 到-113dbm.(如获得信号强度为正数，则请按照以下公式进行转换：获得的正信号强度 * 2 – 113)
        $sig = $signal * 2 - 113;
        $url = "https://apilocate.amap.com/position?accesstype=0&imei=" . $location_id . "&network=GPRS&cdma=0&bts=460,1," . $lon . "," . $lat . "," . $sig . "&key=97509bb13f02a18faf682d0f3853635e";
//                    var_dump($url);
        $result = $this->PostCurl($url);
        return $result;
    }

    /**
     * @param $url
     * @return mixed
     */
    protected function PostCurl($url)
    {
        $curl = curl_init();
        curl_setopt($curl, CURLOPT_URL, $url);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
        //设置头文件的信息作为数据流输出
        curl_setopt($curl, CURLOPT_HEADER, 0);
        //执行命令
        $data = curl_exec($curl);

        // 显示错误信息
        if (curl_error($curl)) {
            Log::error("定位接口Error: " . curl_error($curl));
        } else {
            // 打印返回的内容
            curl_close($curl);

            return json_decode($data, true);
        }

    }

    /**
     * 返回亲情号
     * @param $location_id
     * @return string
     * @throws \Psr\Container\ContainerExceptionInterface
     * @throws \Psr\Container\NotFoundExceptionInterface
     */
    public function getQingQinTel($location_id)
    {

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
    public function getDisturbTime($location_id)
    {
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
    public function getLocationWhite($stunoo)
    {
        $db = ApplicationContext::getContainer()->get(DB::class);
        $location_white = $db->fetch('SELECT * FROM location_white WHERE stunoo = ?;', [$stunoo]);

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

    /**
     * 计算两点地理坐标之间的距离
     * @param  Decimal $longitude1 起点经度
     * @param  Decimal $latitude1 起点纬度
     * @param  Decimal $longitude2 终点经度
     * @param  Decimal $latitude2 终点纬度
     * @param  Int $unit 单位 1:米 2:公里
     * @param  Int $decimal 精度 保留小数位数
     * @return Decimal
     */
    public function getDistance($longitude1, $latitude1, $longitude2, $latitude2, $unit = 2, $decimal = 2)
    {

        $EARTH_RADIUS = 6370.996; // 地球半径系数
        $PI = 3.1415926;

        $radLat1 = $latitude1 * $PI / 180.0;
        $radLat2 = $latitude2 * $PI / 180.0;

        $radLng1 = $longitude1 * $PI / 180.0;
        $radLng2 = $longitude2 * $PI / 180.0;

        $a = $radLat1 - $radLat2;
        $b = $radLng1 - $radLng2;

        $distance = 2 * asin(sqrt(pow(sin($a / 2), 2) + cos($radLat1) * cos($radLat2) * pow(sin($b / 2), 2)));
        $distance = $distance * $EARTH_RADIUS * 1000;

        if ($unit == 2) {
            $distance = $distance / 1000;
        }

        return round($distance, $decimal);

    }

    /**
     * 获取二维数组某个键的最大值或最小值
     *
     * @param array $arr
     * @param string $keys
     * @param array $data
     */
    public function phpMaxMin($arr = [], $keys = '')
    {

        $min['key'] = '';
        $min['value'] = '';

        foreach ($arr as $key => $val) {

            if ($min['key'] === '') {
                $min['key'] = $val['id'];
                $min['value'] = $val[$keys];

            }
            if ((int)$min['value'] > $val[$keys]) {

                $min['key'] = $val['id'];
                $min['value'] = $val[$keys];
            }

        }
        return $min;

    }
}