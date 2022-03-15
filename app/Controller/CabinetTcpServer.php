<?php
declare(strict_types=1);

namespace App\Controller;

use App\Helpers\Crc16;
use App\Helpers\Log;
use Hyperf\Contract\OnReceiveInterface;
use Hyperf\Utils\ApplicationContext;
use Hyperf\DB\DB;
use Swoole\Http\Request;
use function Symfony\Component\Translation\t;

class CabinetTcpServer implements OnReceiveInterface
{
    /**
     * 监听消息
     * @param \Swoole\Coroutine\Server\Connection|\Swoole\Server $server
     * @param int $fd
     * @param int $reactorId
     * @param string $data
     * @throws \Psr\Container\ContainerExceptionInterface
     * @throws \Psr\Container\NotFoundExceptionInterface
     */
    public function onReceive($server, int $fd, int $reactorId, string $data): void
    {
        var_dump($data);
        if (is_array(json_decode($data, true))) {

            $redis = ApplicationContext::getContainer()->get(\Hyperf\Redis\Redis::class);
            $data = json_decode($data, true);
            $json_data = $redis->get($data['devid']);
            if ($json_data) {
                $send_fd = $json_data;
                $code = str_pad((string)mt_rand(0, 999999), 6, "0", STR_PAD_BOTH);

                $result_msg = [];
                $result_msg['func'] = '5';
                $result_msg['id'] = '5';
                $result_msg['No'] = $code;  //6位寄存码

                $redis->setex($code, 60 * 10, '5');

                $bt_16_str = $this->getSendData(json_encode($result_msg), '0001');

                var_dump($bt_16_str);
                $server->send($send_fd, $bt_16_str);
            }
            return;
        } else {
            $data = bin2hex($data);
            Log::get('app')->info(json_encode($data));
            $data = str_replace(',', '', $data);
            $key = substr($data, -4);
            $d_data = substr($data, 0, -4);
            if ($key != Crc16::dechex(Crc16::make('hex', $d_data, Crc16::MCRF4XX), true)) {
                $server->send($fd, '11111');
                return;
            }
            $head = substr($d_data, 0, 4);
            $length = substr($d_data, 4, 4);
            $seq_no = substr($d_data, 8, 4);
            $msg = json_decode(hex2bin(substr($d_data, 12)), true);
            var_dump($msg);
            var_dump($head);
            var_dump($length);
            var_dump($seq_no);

            $func = $msg['func'];   //状态码
            $result_msg = [];

            $db = ApplicationContext::getContainer()->get(DB::class);
            $redis = ApplicationContext::getContainer()->get(\Hyperf\Redis\Redis::class);

            //设备认证
            if ($func == '1') {
                $devid = $msg['devid']; //设备id
                $password = $msg['pass'];
                $result = $this->getCabinet($devid, $password, $db);
                $result_msg['func'] = '13';
                if ($result) {
                    $result_msg['Asw'] = 'ok';
                } else {
//                $result_msg['Asw'] = 'error';
                    $result_msg['id'] = '1';
                    $result_msg['No'] = '3927776756';
//                    $result_msg['No'] = 'zhxy000011';
//                $result_msg['grade'] = '10';
//                $result_msg['class'] = '6771';
//                $result_msg['page'] = '1';
                }
                var_dump($result_msg);
                $bt_16_str = $this->getSendData(json_encode($result_msg), $seq_no);
                var_dump($bt_16_str);
                $server->send($fd, $bt_16_str);

                //记录devid
                $this->recordDevid($devid, $server, $fd, $redis);
                return;
            }
            $devid_fd = $this->getDevid($server, $fd, $redis);

            if (!$devid_fd){
                $result_msg['func'] = '1';
                $result_msg['Asw'] = 'error';
                $bt_16_str = $this->getSendData(json_encode($result_msg), $seq_no);
                var_dump($bt_16_str);
                $server->send($fd, $bt_16_str);
                return;
            }
            //心跳包
            if ($func == '2') {
                var_dump('心跳包');
                $list = explode('-', $msg['list']); //空闲柜子列表
                $new_list = [];
                foreach ($list as $k => $v) {
                    $new_list[$v]['is_online'] = 1;
                    $new_list[$v]['is_free'] = 1;
                }

                $devid_fd = $this->getDevid($server, $fd, $redis);
                $devid = json_decode($devid_fd, true);
                var_dump($devid);
                $select_data = $db->fetch('select * from agency_list where devid = ?;', [$devid['devid']]);
                if ($select_data) {
                    $db->execute('update agency_list set devid = ?, agency_list = ?;', [$devid['devid'], json_encode($new_list)]);
                } else {
                    $db->insert('insert into agency_list set devid = ?, agency_list = ?;', [$devid['devid'], json_encode($new_list)]);
                }

                $result_msg['func'] = '2';
                $result_msg['time'] = date('Y-m-d H:i:s', time());

                $bt_16_str = $this->getSendData(json_encode($result_msg), $seq_no);
                $server->send($fd, $bt_16_str);

                return;
            }
            //上传读卡数据
            if ($func == '3') {
                $card = $msg['card'];
                $result = $this->getCard($card, $db);

                $result_msg['func'] = '3';
                if ($result == 1) {
                    $result_msg['Asw'] = 'ok';
                } else if ($result == 2) {
                    $result_msg['Asw'] = 'error';
                } else if ($result == 3) {
                    $result_msg['Asw'] = 'admin';
                }
                $bt_16_str = $this->getSendData(json_encode($result_msg), $seq_no);
                $server->send($fd, $bt_16_str);

                return;
            }
            //柜子上锁(用户通过UI操作后进行的上锁)
            if ($func == '4') {
                $id = $msg['id'];
                $mode = $msg['mode'];
                $devid_fd = $this->getDevid($server, $fd, $redis);
                $devid = json_decode($devid_fd, true);
                $this->lockCabinet($devid['devid'], $id, $mode, $server, $seq_no, $fd, $redis, $db);
                return;
            }

            //输入寄存码开锁
            if ($func == '6') {
                $id = $msg['id'];   //柜号
                $no = $msg['No'];   //6位寄存码
                $devid_fd = $this->getDevid($server, $fd, $redis);
                $devid = json_decode($devid_fd, true);
                $this->openLock($devid['devid'], $id, $no, $seq_no, $server, $fd, $redis, $db);
                return;
            }
            //发送学号获取学生信息
            if ($func == '7') {
                $no = $msg['No'];   //学号
                $this->getStudent($no, $server, $fd, $redis, $db, $seq_no);
                return;
            }
            //获取年级列表
            if ($func == '8') {
                $this->getGrade($server, $fd, $db, $redis, $seq_no);
                return;
            }
            //获取班级列表
            if ($func == '9') {
                $grade = $msg['grade'];
                $this->getClass($grade, $server, $fd, $db, $redis, $seq_no);
                return;
            }
            //获取学生列表
            if ($func == '10') {
                $grade = $msg['grade'];
                $class = $msg['class'];
                $page = $msg['page'];

                $this->getStudentList($grade, $class, $page, $server, $fd, $db, $redis, $seq_no);
                return;
            }
            //选中学生后开锁
            if ($func == '11') {
                $id = $msg['id'];   //柜号
                $No = $msg['No'];   //学号
                $devid_fd = $this->getDevid($server, $fd, $redis);
                $devid = json_decode($devid_fd, true);
                $this->studentOpenLock($devid['devid'], $id, $No, $server, $fd, $redis, $db, $seq_no);
                return;
            }
            //刷卡后获取所属柜号
            if ($func == '12') {
                $id = $msg['id'];   //卡号

                $this->getCardAbinet($id, $server, $fd, $db, $redis, $seq_no);
                return;
            }
            //刷卡后选取柜号用卡号开锁
            if ($func == '13') {
                $id = $msg['id'];   //柜号
                $no = $msg['No'];   //卡号
                $devid_fd = $this->getDevid($server, $fd, $redis);
                $devid = json_decode($devid_fd, true);
                $this->getCardOpenAbinet($devid['devid'], $id, $no, $server, $fd, $redis, $db, $seq_no);
                return;
            }
            //用亲情号和学号获取所属柜号
            if ($func == '14') {
                $stunoo = $msg['stuno'];   //学号
                $phone = $msg['phone'];   //亲情号
                $devid_fd = $this->getDevid($server, $fd, $redis);
                $devid = json_decode($devid_fd, true);
                $this->getStunooAbinet($devid['devid'], $stunoo, $phone, $server, $fd, $redis, $db,$db, $seq_no);
                return;
            }
            //用亲情号和学生信息获取所属柜号
            if ($func == '15') {
                $grade = $msg['grade'];   //年级
                $class = $msg['class'];   //班级
                $name = $msg['name'];   //姓名
                $phone = $msg['phone'];   //亲情号
                $devid = $this->getDevid($server, $fd, $redis);
                $this->getqsStunooAbinet($devid, $grade, $class, $name, $phone, $server, $fd, $redis, $db);
                return;
            }
            //服务器通知中转柜显示使用柜子的学生列表，走马灯高亮显示
            if ($func == '16') {
                $devid = $this->getDevid($server, $fd, $redis);
                $this->getzzStunooAbinet($devid, $server, $fd, $redis, $db);
                return;
            }
            //用亲情号和学号挂失所属卡
            if ($func == '17') {
                $stuno = $msg['stuno'];
                $phone = $msg['phone'];
                $devid = $this->getDevid($server, $fd, $redis);
                $this->getReportCard($devid, $stuno, $phone, $server, $fd, $redis, $db);
                return;
            }
            //用亲情号和学生信息挂失所属卡
            if ($func == '18') {
                $grade = $msg['grade'];
                $class = $msg['class'];
                $name = $msg['name'];
                $phone = $msg['phone'];
                $devid = $this->getDevid($server, $fd, $redis);
                $this->getStudentReportCard($devid, $grade, $class, $name, $phone, $server, $fd, $redis, $db);
                return;
            }
            //解挂卡号
            if ($func == '19') {
                $no = $msg['No'];
                $devid = $this->getDevid($server, $fd, $redis);
                $this->getOpenReportCard($devid, $no, $server, $fd, $redis, $db);
                return;
            }
            //管理卡设置柜子信息
            if ($func == '20') {
                $no = $msg['No'];
                $key = $msg['key'];
                $mode = $msg['mode'];
                $devid = $this->getDevid($server, $fd, $redis);
                $this->getCabinetSetting($devid, $no, $key, $mode, $server, $fd, $redis, $db);
                return;
            }
        }


        $server->send($fd, 'recv:' . implode(',', $msg));
    }

    /**
     * 拼接字符串
     * @param $data
     * @param $seq_no
     * @return string
     */
    public function getSendData($data, $seq_no)
    {

        $head = 'ABCD';
        $length = sprintf("%04X", count($this->mbStrSplit(strtoupper(bin2hex($data)), 2)));
        $new_data = $head . $length . $seq_no . bin2hex($data);

        var_dump(pack("H*", bin2hex($data)));
        var_dump($new_data);
        $crc_key = Crc16::dechex(Crc16::make('hex', $new_data, Crc16::MCRF4XX), true);


//
//        var_dump($seq_no);
//        var_dump($seq_no.bin2hex($data));
//        var_dump(bin2hex($data));
//        var_dump($this->mbStrSplit(bin2hex($data),2));
//        var_dump($length);
//        var_dump($crc_key);
//        var_dump($data);

        $pack_str = implode('', $this->mbStrSplit(strtoupper($new_data . $crc_key), 2));
        return pack("H*", $pack_str);
    }

    /**
     * 按照字节分割
     * @param $str
     * @param $blen
     * @return array
     */
    public function mbStrSplit($str, $blen): array
    {
        $result = [];
        $clen = mb_strlen($str, 'utf-8');    //字符数量
        $b = 0;
        $e = 0;
        $i = 0;
        while ($i < $clen - 2) {
            for ($j = $i + 1; $j < $clen - 1; $j++) {
                $new = mb_substr($str, $i, $j - $i + 1, 'utf-8');
                $new1 = mb_substr($str, $i, $j - $i + 2, 'utf-8');
                if (mb_strwidth($new, 'utf-8') <= $blen && mb_strwidth($new1, 'utf-8') > $blen) {
                    for ($k = 0; $k < $blen - mb_strwidth($new, 'utf-8'); $k++) {
                        $new .= ' ';
                    }
                    $result[] = $new;
                    $i = $j + 1;
                    break;
                }
                $e = $j;
            }
            $b = $i;
            if ($e + 1 == $clen - 1) {
                break;
            }
        }
        $last = mb_substr($str, $b, $clen - $b + 1, 'utf-8');
        //填充空白，可以省略
        $lenght = iconv("UTF-8", "GBK//IGNORE", $last);
        $val = $blen - strlen($lenght);
        //等同于 mb_strwidth($last,'utf-8')
        for ($k = 0; $k < $val; $k++) {
            $last .= ' ';
        }
        $result[] = $last;
        return $result;
    }


    /**
     * 设备是否存在
     * @param $devid
     * @param $password
     * @return bool
     * @throws \Psr\Container\ContainerExceptionInterface
     * @throws \Psr\Container\NotFoundExceptionInterface
     */
    public function getCabinet($devid, $password, $db)
    {

        $cabinet = $db->fetch('SELECT * FROM `cabinet`WHERE devid = ?;', [$devid]);
        if ($cabinet) {
            if ($cabinet['password'] == $password) {

                return true;
            } else {

                return false;
            }
        }

        return false;
    }

    /**
     * 卡片是否存在
     * @param $card
     * @return bool
     * @throws \Psr\Container\ContainerExceptionInterface
     * @throws \Psr\Container\NotFoundExceptionInterface
     */
    public function getCard($card, $db)
    {
        $kaku = $db->fetch('SELECT * FROM `kaku`WHERE tel_id = ?;', [$card]);
        if ($kaku) {

            return 1;

        }

        return 2;
    }

    /**
     * 柜子上锁
     * @param $devid
     * @param $id
     * @param $mode
     * @param $fd
     * @throws \Psr\Container\ContainerExceptionInterface
     * @throws \Psr\Container\NotFoundExceptionInterface
     */
    public function lockCabinet($devid, $id, $mode, $server, $seq_no, $fd, $redis, $db)
    {
        $free_abinet = $db->fetch('select * from agency_list where devid = ?;', [$devid]);

        if ($free_abinet) {
            $agency_list = json_decode($free_abinet['agency_list'], true);

            foreach ($agency_list as $k => $v) {
                if (intval($id) == $k) {
                    if ($agency_list[$k]['is_free'] == 2) {
                        $msg = ['func' => '4', 'Asw' => '柜子已上锁,无需重复操作'];
                        var_dump($msg);
                        $bt_16_str = $this->getSendData(json_encode($msg), $seq_no);
                        $server->send($fd, $bt_16_str);
                        break;
                    } else {
                        $agency_list[$k]['is_free'] = 2; //上锁
                        $db->execute('update agency_list set devid = ?, agency_list = ?;', [$devid, json_encode($agency_list)]);
                    }

                }
            }
            var_dump($id);
            var_dump($agency_list);
        }

    }

    /**
     * 输入寄存码开锁
     * @param $devid
     * @param $id 柜号
     * @param $no 6位寄存码
     * @param $server
     * @param $fd
     * @throws \Psr\Container\ContainerExceptionInterface
     * @throws \Psr\Container\NotFoundExceptionInterface
     */
    public function openLock($devid, $id, $no, $seq_no, $server, $fd, $redis, $db)
    {
        //寄存码是否过期
        $code = $redis->get($no);
        if (!$code) {
            $msg = ['func' => '6', 'Asw' => '寄存码过期'];
            var_dump($msg);
            $bt_16_str = $this->getSendData(json_encode($msg), $seq_no);
            $server->send($fd, $bt_16_str);
            return;
        }
        //柜子是否被占用
        $select_abinet = $db->fetch('select * from agency_list where devid = ?;', [$devid]);
        if ($select_abinet) {
            $free_abinet = json_decode($select_abinet['agency_list'], true);
            if ($free_abinet[$id]) {
                $is_free = $free_abinet[$id]['is_free'];
                if ($is_free == 2) {
                    //被占用
                    $msg = ['func' => '6', 'Asw' => '柜子被占用'];
                    var_dump($msg);
                    $bt_16_str = $this->getSendData(json_encode($msg), $seq_no);
                    $server->send($fd, $bt_16_str);

                } else {
                    //开锁
                    $msg = ['func' => '6', 'id' => $id];
                    $bt_16_str = $this->getSendData(json_encode($msg), $seq_no);
                    $server->send($fd, $bt_16_str);

                    $free_abinet[$id]['is_free'] = 2;
                    var_dump($msg);
                    $db->execute('update agency_list set devid = ?, agency_list = ?;', [$devid, json_encode($free_abinet)]);
                }

            }
        }
    }

    /**
     * 获取学生信息
     * @param $no 学号
     * @param $server
     * @param $fd
     * @throws \Psr\Container\ContainerExceptionInterface
     * @throws \Psr\Container\NotFoundExceptionInterface
     */
    public function getStudent($no, $server, $fd, $redis, $db, $seq_no)
    {
        $student = $db->fetch('SELECT stuname,class_id,grade_id FROM `student`WHERE stunoo = ?;', [$no]);
        $new_data = [];
        if ($student) {

            $class = $db->fetch('SELECT name FROM `class`WHERE class_id = ?;', [$student['class_id']]);
            $grade = $db->fetch('SELECT name FROM `grade`WHERE grade_id = ?;', [$student['grade_id']]);
            $new_data['func'] = '7';
            $new_data['grade'] = $grade['name'];
            $new_data['class'] = $class['name'];
            $new_data['name'] = $student['stuname'];
        } else {
            $new_data['func'] = '7';
            $new_data['Asw'] = 'null';
        }
        var_dump($new_data);
        $bt_16_str = $this->getSendData(json_encode($new_data), $seq_no);
        $server->send($fd, $bt_16_str);
    }

    /**
     * 获取年级列表
     * @param $server
     * @param $fd
     * @throws \Psr\Container\ContainerExceptionInterface
     * @throws \Psr\Container\NotFoundExceptionInterface
     */
    public function getGrade($server, $fd, $db, $redis, $seq_no)
    {
        $grade = $db->query('SELECT grade_id,name FROM `grade`;', []);
        if ($grade) {
//            $name_arr = array_column($grade,'name');
//            $name = implode('-',$name_arr);

            var_dump($grade);
            $bt_16_str = $this->getSendData(json_encode(['func' => '8', 'grade' => $grade]), $seq_no);
            $server->send($fd, $bt_16_str);
        } else {
            $bt_16_str = $this->getSendData(json_encode(['func' => '8', 'Asw' => 'null']), $seq_no);
            $server->send($fd, $bt_16_str);
        }
    }

    /**
     * 获取班级列表
     * @param $grade 年级
     * @param $server
     * @param $fd
     * @throws \Psr\Container\ContainerExceptionInterface
     * @throws \Psr\Container\NotFoundExceptionInterface
     */
    public function getClass($grade, $server, $fd, $db, $redis, $seq_no)
    {
        $devid_fd = $this->getDevid($server, $fd, $redis);
        $devid = json_decode($devid_fd, true);
        $school_id = $db->fetch('SELECT school_id FROM `cabinet` where devid = ?;', [$devid['devid']]);

        $grade = $db->fetch('SELECT grade_id FROM `grade` where grade_id = ?;', [$grade]);
        if ($grade) {
            var_dump($grade['grade_id']);
            var_dump($school_id['school_id']);
            $class = $db->query('SELECT class_id,name FROM `class` where grade_id = ? and school_id = ?;', [$grade['grade_id'], $school_id['school_id']]);
            if ($class) {
//                $name_arr = array_column($class,'name');
//                $name = implode('-',$name_arr);
                var_dump($class);
                $bt_16_str = $this->getSendData(json_encode(['func' => '9', 'grade' => $class]), $seq_no);
                $server->send($fd, $bt_16_str);
            } else {
                $bt_16_str = $this->getSendData(json_encode(['func' => '9', 'Asw' => 'null']), $seq_no);
                $server->send($fd, $bt_16_str);
            }
        } else {
            $bt_16_str = $this->getSendData(json_encode(['func' => '9', 'Asw' => 'null']), $seq_no);
            $server->send($fd, $bt_16_str);
        }
    }

    /**
     * 获取学生列表
     * @param $grade 年级
     * @param $class 班级
     * @param $page  页码
     * @param $server
     * @param $fd
     * @throws \Psr\Container\ContainerExceptionInterface
     * @throws \Psr\Container\NotFoundExceptionInterface
     */
    public function getStudentList($grade, $class, $page, $server, $fd, $db, $redis, $seq_no)
    {
        $limit = 5;
        $offset = ($page - 1) * $limit;

        $class_data = $db->fetch('SELECT class_id FROM `class` where class_id = ?;', [$class]);
        if ($class_data) {
            $student = $db->query("SELECT stunoo,stuname FROM `student` where class_id = ? limit $limit offset $offset;", [$class]);
            var_dump($student);
            if ($student) {
                $count = $db->fetch("SELECT count(1) as count FROM `student` where class_id = ?;", [$class]);
                var_dump($count);
                $bt_16_str = $this->getSendData(json_encode(['func' => '10', 'count' => $count['count'], 'page' => '', 'limit' => $student]), $seq_no);
                $server->send($fd, $bt_16_str);
            } else {
                $bt_16_str = $this->getSendData(json_encode(['func' => '10', 'Asw' => 'null']), $seq_no);
                $server->send($fd, $bt_16_str);
            }
        } else {
            $bt_16_str = $this->getSendData(json_encode(['func' => '10', 'Asw' => 'null']), $seq_no);
            $server->send($fd, $bt_16_str);
        }
    }

    /**
     * 选中学生后开锁
     * @param $devid 设备编号
     * @param $id 柜号
     * @param $No 学号
     * @param $server
     * @param $fd
     * @throws \Psr\Container\ContainerExceptionInterface
     * @throws \Psr\Container\NotFoundExceptionInterface
     */
    public function studentOpenLock($devid, $id, $No, $server, $fd, $redis, $db, $seq_no)
    {
        $free_abinet = $db->fetch("SELECT * FROM `agency_list` where devid = ?;", [$devid]);
        var_dump($free_abinet);
        if ($free_abinet) {
            $free_abinet = json_decode($free_abinet['agency_list'], true);
            if (!$free_abinet) {
                $bt_16_str = $this->getSendData(json_encode(['func' => '11', 'Asw' => '没有空闲柜子']), $seq_no);
                var_dump(['func' => '11', 'Asw' => '没有空闲柜子']);
                $server->send($fd, $bt_16_str);
                return;
            }
            foreach ($free_abinet as $k => $v) {
                //2 被人占用 1空闲
                if ($k == $id) {
                    if ($v['is_free'] == 2) {
                        $bt_16_str = $this->getSendData(json_encode(['func' => '11', 'Asw' => '柜子被占用']), $seq_no);
                        var_dump(['func' => '11', 'Asw' => '柜子被占用']);
                        $server->send($fd, $bt_16_str);
                        break;
                    } else {
                        //执行开锁
                        $free_abinet[$k]['is_free'] = 2;
                        $db->execute('update agency_list set devid = ?, agency_list = ?;', [$devid, json_encode($free_abinet)]);
                        $bt_16_str = $this->getSendData(json_encode(['func' => '11', 'id' => $id]), $seq_no);
                        $server->send($fd, $bt_16_str);

                        $student_abinet = $db->fetch("SELECT * FROM `agency_student_list` where stunoo = ?;", [$No]);
                        if ($student_abinet && $student_abinet['agency_list']) {
                            $agency_list_arr = explode('-', $student_abinet['agency_list']);
                            if ($agency_list_arr) {

                                array_push($agency_list_arr, $id);
                                $db->execute('update agency_student_list set devid = ?, agency_list = ? where stunoo = ?;', [$devid, implode('-', $agency_list_arr), $No]);
                            }
                        } else {
                            $arr = [$id];
                            $db->insert('insert into agency_student_list set devid = ?, agency_list = ?,stunoo = ?;', [$devid, implode('-', $arr), $No]);
                        }
                    }
                }
            }


        }

    }

    /**
     * 刷卡后获取所属柜号
     * @param $id 卡号
     * @param $server
     * @param $fd
     * @throws \Psr\Container\ContainerExceptionInterface
     * @throws \Psr\Container\NotFoundExceptionInterface
     */
    public function getCardAbinet($id, $server, $fd, $db, $redis, $seq_no)
    {
        $ka = $db->fetch('SELECT epc FROM `kaku`WHERE tel_id = ?;', [$id]);
        if (!$ka) {
            $bt_16_str = $this->getSendData(json_encode(['func' => '12', 'Asw' => '卡片无效']), $seq_no);
            $server->send($fd, $bt_16_str);
            return;
        }
        var_dump($ka);
        $student = $db->fetch('SELECT stunoo FROM `student`WHERE epcid = ?;', [$ka['epc']]);
        if ($student) {
            $student_abinet = $db->fetch('SELECT * FROM `agency_student_list`WHERE stunoo = ?;', [$student['stunoo']]);
            if ($student_abinet && $student_abinet['agency_list']) {
                var_dump($student_abinet);
                $abinet = $student_abinet['agency_list'];
                $bt_16_str = $this->getSendData(json_encode(['func' => '12', 'list' => $abinet]), $seq_no);
                $server->send($fd, $bt_16_str);
            } else {

                $bt_16_str = $this->getSendData(json_encode(['func' => '12', 'Asw' => 'null']), $seq_no);
                $server->send($fd, $bt_16_str);
            }
        } else {
            $bt_16_str = $this->getSendData(json_encode(['func' => '12', 'Asw' => '学生不存在']), $seq_no);
            $server->send($fd, $bt_16_str);
        }
    }

    /**
     * 刷卡后选取柜号用卡号开锁
     * @param $devid
     * @param $id 柜号
     * @param $no 卡号
     * @param $server
     * @param $fd
     * @throws \Psr\Container\ContainerExceptionInterface
     * @throws \Psr\Container\NotFoundExceptionInterface
     */
    public function getCardOpenAbinet($devid, $id, $no, $server, $fd, $redis, $db, $seq_no)
    {
        $ka = $db->fetch('SELECT epc FROM `kaku`WHERE tel_id = ?;', [$no]);
        if (!$ka) {
            $bt_16_str = $this->getSendData(json_encode(['func' => '13', 'Asw' => '卡号无效']), $seq_no);
            $server->send($fd, $bt_16_str);
        }

        $student = $db->fetch('SELECT stunoo FROM `student`WHERE epcid = ?;', [$ka['epc']]);
        if (!$student) {
            $bt_16_str = $this->getSendData(json_encode(['func' => '13', 'Asw' => '学生无效']), $seq_no);
            $server->send($fd, $bt_16_str);
        }
        var_dump($devid);
        $free_abinet = $db->fetch("SELECT * FROM `agency_list` where devid = ?;", [$devid]);
        if ($free_abinet) {
            $free_abinet = json_decode($free_abinet['agency_list'], true);

            foreach ($free_abinet as $k => $v) {
                if ($k == $id) {
                    if ($v['is_free'] == 2) {
                        $free_abinet[$k]['is_free'] = 1;
                        var_dump(11111);
                        $db->execute('update agency_list set agency_list = ? where devid = ?;', [json_encode($free_abinet), $devid]);
                        $bt_16_str = $this->getSendData(json_encode(['func' => '13', 'id' => $id]), $seq_no);
                        $server->send($fd, $bt_16_str);

                        $agency_student_list = $db->fetch('SELECT * FROM `agency_student_list`WHERE stunoo = ?;', [$student['stunoo']]);
                        if ($agency_student_list) {
                            if (!$agency_student_list['agency_list']){
                                break;
                            }
                            $arr_data = explode('-', $agency_student_list['agency_list']);

                            if (!empty($arr_data)) {
                                $key = array_search($id, $arr_data);
                                if ($key) {
                                    array_splice($arr_data, $key, 1);
                                    $db->execute('update agency_student_list set agency_list = ? where stunoo = ?;', [implode('-', $arr_data), $student['stunoo']]);
                                }
                            } else {
                                $db->execute('delete agency_student_list from where stunoo = ?;', [$student['stunoo']]);
                            }

                        }
                        break;
                    }
                }
            }
        }


    }

    /**
     * 用亲情号和学号获取所属柜号
     * @param $devid 设备编号
     * @param $stunoo 学号
     * @param $phone 亲情号
     * @param $server
     * @param $fd
     * @param $redis
     * @param $db
     */
    public function getStunooAbinet($devid, $stunoo, $phone, $server, $fd, $redis, $db, $seq_no)
    {
        $is_bind = $db->fetch('SELECT stunoo FROM `qq_tel`WHERE tel = ?;', [$phone]);
        if (!$is_bind) {
            $bt_16_str = $this->getSendData(json_decode(['func' => '14', 'Asw' => '亲情号无效']), $seq_no);
            $server->send($fd, $bt_16_str);
        }

        $student_abinet = $db->fetch('SELECT * FROM `agency_student_list`WHERE stunoo = ?;', [$is_bind['stunoo']]);
        if ($student_abinet && $student_abinet['agency_list']) {
            $bt_16_str = $this->getSendData(json_decode(['func' => '14', 'list' => $student_abinet['agency_list']]), $seq_no);
            $server->send($fd, $bt_16_str);
        } else {
            $bt_16_str = $this->getSendData(json_decode(['func' => '14', 'Asw' => 'null']), $seq_no);
            $server->send($fd, $bt_16_str);
        }

    }

    /**
     * 用亲情号和学生信息获取所属柜号
     * @param $devid 设备编号
     * @param $grade 年级
     * @param $class 班级
     * @param $name 姓名
     * @param $phone 亲情号
     * @param $server
     * @param $fd
     * @param $redis
     * @param $db
     */
    public function getqsStunooAbinet($devid, $grade, $class, $name, $phone, $server, $fd, $redis, $db,$seq_no)
    {
        $stunoo = $db->fetch('SELECT stunoo FROM `qq_tel`WHERE tel = ?;', [$phone]);
        if (!$stunoo) {
            $stunoo = $db->fetch('SELECT stunoo FROM `student`WHERE stuname = ?;', [$name]);
            if ($stunoo) {
                $server->send($fd, json_decode(['func' => '15', 'Asw' => '学生信息无效']));
                return;
            }
        }

        $student_abinet = $redis->get($stunoo['stunoo']);
        if ($student_abinet) {
            $student_abinet = json_decode($student_abinet, true);
            $abinet = implode('-', $student_abinet['student_abinet']);
            $server->send($fd, json_decode(['func' => '15', 'list' => $abinet]));
        } else {
            $server->send($fd, json_decode(['func' => '15', 'Asw' => 'null']));
        }
    }

    /**
     * 服务器通知中转柜显示使用柜子的学生列表
     * @param $devid 设备编号
     * @param $server
     * @param $fd
     * @param $redis
     * @param $db
     */
    public function getzzStunooAbinet($devid, $server, $fd, $redis, $db)
    {
        $free_abinet = $redis->get($devid);
        $stuname = [];
        if ($free_abinet) {
            $free_abinet = json_decode($free_abinet, true);
            foreach ($free_abinet as $k => $v) {
                if ($v['stunoo']) {
                    $stunoo = $db->fetch('SELECT stuname FROM `student`WHERE stunoo = ?;', [$v['stunoo']]);
                    $stuname[] = $stunoo['stuname'];
                }
            }
        }
        $count = count($stuname);
        $server->send($fd, json_decode(['func' => '16', 'count' => $count, 'page' => '', 'stu' => implode('-', $stuname)]));
    }

    /**
     *用亲情号和学号挂失所属卡
     * @param $devid 设备编号
     * @param $stuno 学号
     * @param $phone 亲情号
     * @param $server
     * @param $fd
     * @param $redis
     * @param $db
     */
    public function getReportCard($devid, $stuno, $phone, $server, $fd, $redis, $db)
    {
        $qq_tel = $db->fetch('SELECT stunoo FROM `qq_tel`WHERE tel = ?;', [$phone]);
        if (!$qq_tel) {
            $server->send($fd, json_decode(['func' => '27', 'Asw' => '亲情号无效']));
        }
        $student = $db->fetch('SELECT epcid FROM `student`WHERE stunoo = ?;', [$qq_tel['stunoo']]);
        if (!$student) {
            $server->send($fd, json_decode(['func' => '27', 'Asw' => '学号无效']));
        }
        $card = $db->fetch('SELECT is_normal FROM `kaku`WHERE epc = ?;', [$student['epcid']]);
        if (!$card) {
            $server->send($fd, json_decode(['func' => '27', 'Asw' => '卡片无效']));
        }
        if ($card['is_normal'] == 1) {
            $server->send($fd, json_decode(['func' => '27', 'Asw' => '卡片已挂失']));
        }

        $is_update = $db->execute('update kaku set is_normal = ? WHERE epc = ?;', [1, $student['epcid']]);
        if ($is_update) {
            $server->send($fd, json_decode(['func' => '27', 'Asw' => '挂失成功']));
        } else {
            $server->send($fd, json_decode(['func' => '27', 'Asw' => '挂失失败']));
        }
    }

    /**
     * @param $devid
     * @param $grade 年级
     * @param $class 班级
     * @param $name 姓名
     * @param $phone 亲情号
     * @param $server
     * @param $fd
     * @param $redis
     * @param $db
     */
    public function getStudentReportCard($devid, $grade, $class, $name, $phone, $server, $fd, $redis, $db)
    {
        $qq_tel = $db->fetch('SELECT stunoo FROM `qq_tel`WHERE tel = ?;', [$phone]);
        if ($qq_tel) {
            $stunoo = $qq_tel['stunoo'];
        } else {
            $student = $db->fetch('SELECT stunoo FROM `student`WHERE name = ?;', [$name]);
            if ($student) {
                $stunoo = $student['stunoo'];
            } else {
                $server->send($fd, json_decode(['func' => '28', 'Asw' => '学生信息无效']));
            }
        }
        $student = $db->fetch('SELECT epcid FROM `student`WHERE stunoo = ?;', [$stunoo]);
        if (!$student) {
            $server->send($fd, json_decode(['func' => '28', 'Asw' => '学号无效']));
        }
        $card = $db->fetch('SELECT is_normal FROM `kaku`WHERE epc = ?;', [$student['epcid']]);
        if (!$card) {
            $server->send($fd, json_decode(['func' => '28', 'Asw' => '卡片无效']));
        }
        if ($card['is_normal'] == 1) {
            $server->send($fd, json_decode(['func' => '28', 'Asw' => '卡片已挂失']));
        }

        $is_update = $db->execute('update kaku set is_normal = ? WHERE epc = ?;', [1, $student['epcid']]);
        if ($is_update) {
            $server->send($fd, json_decode(['func' => '28', 'Asw' => '挂失成功']));
        } else {
            $server->send($fd, json_decode(['func' => '28', 'Asw' => '挂失失败']));
        }

    }

    /**
     * 解挂卡号
     * @param $devid
     * @param $no 卡号
     * @param $server
     * @param $fd
     * @param $redis
     * @param $db
     */
    public function getOpenReportCard($devid, $no, $server, $fd, $redis, $db)
    {
        $card = $db->fetch('SELECT is_normal FROM `kaku`WHERE tel_id = ?;', [$no]);
        if (!$card) {
            $server->send($fd, json_decode(['func' => '19', 'Asw' => '卡片无效']));
        }
        if ($card['is_normal'] == 0) {
            $server->send($fd, json_decode(['func' => '19', 'Asw' => '卡片正常,无需挂失']));
        }

        $is_update = $db->execute('update kaku set is_normal = ? WHERE tel_id = ?;', [0, $no]);
        if ($is_update) {
            $server->send($fd, json_decode(['func' => '19', 'Asw' => 'ok']));
        } else {
            $server->send($fd, json_decode(['func' => '19', 'Asw' => '解挂失败']));
        }
    }

    /**
     * @param $devid 设备id
     * @param $no 管理卡号
     * @param $key 读卡密码
     * @param $mode 柜子类型1中转2寄存
     * @param $server
     * @param $fd
     * @param $redis
     * @param $db
     */
    public function getCabinetSetting($devid, $no, $key, $mode, $server, $fd, $redis, $db)
    {
        $setting = $db->fetch('SELECT * FROM `cabinet_setting_msg`WHERE devid = ?;', [$devid]);
        if ($setting) {
            $server->send($fd, json_decode(['func' => '20', 'Asw' => '信息已存在']));
        }
        $id = $db->insert('Insert INTO `cabinet_setting_msg` SET devid = ?,no = ?,key = ?,mode = ?;', [$devid, $no, $key, $mode]);
        if ($id) {
            $server->send($fd, json_decode(['func' => '20', 'Asw' => 'ok']));
        } else {
            $server->send($fd, json_decode(['func' => '20', 'Asw' => '设置失败']));
        }
    }

    /**
     * 记录devid
     * @param $devid
     * @param $server
     * @param $fd
     * @param $redis
     */
    public function recordDevid($devid, $server, $fd, $redis)
    {
        $res = $server->getClientInfo($fd);
        $remote_ip = $res['remote_ip'];
        $remote_port = $res['remote_port'];
        $redis->set($remote_ip . ':' . $remote_port, json_encode(['devid' => $devid, 'fd' => $fd]));
        $redis->set($devid, $fd);
        var_dump($redis->get($remote_ip . ':' . $remote_port));
        var_dump($redis->get($devid));
    }

    /**
     * 获取devid
     * @param $devid
     * @param $server
     * @param $fd
     * @param $redis
     * @return mixed
     */
    public function getDevid($server, $fd, $redis)
    {
        $res = $server->getClientInfo($fd);
        $remote_ip = $res['remote_ip'];
        $remote_port = $res['remote_port'];
        var_dump($redis->get($remote_ip . ':' . $remote_port));
        return $redis->get($remote_ip . ':' . $remote_port);
    }


    public function onClose($server, int $fd, int $reactorId): void
    {
        //监听连接关闭事件
        var_dump('监听连接关闭事件');
        $redis = ApplicationContext::getContainer()->get(\Hyperf\Redis\Redis::class);
        $devid = $this->getDevid($server, $fd, $redis);
        $redis->del($devid);
    }

    public function onConnect($server, int $fd): void
    {
//        $server->push($request->fd, 'Opened');
        var_dump('监听连接打开事件');

    }


}

