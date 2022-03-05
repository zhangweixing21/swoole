<?php
declare(strict_types=1);

namespace App\Controller;

use App\Helpers\Log;
use Hyperf\Contract\OnReceiveInterface;
use Hyperf\Utils\ApplicationContext;
use Hyperf\DB\DB;
use function Symfony\Component\Translation\t;

class TcpServer implements OnReceiveInterface
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
        $msg = json_decode($data,true);
        $func = $msg['func'];
        $devid = $msg['devid'];
        $result_msg = [];

        $db = ApplicationContext::getContainer()->get(DB::class);
        $redis = ApplicationContext::getContainer()->get(\Hyperf\Redis\Redis::class);

        //设备认证
        if ($func == '1'){

            $password = $msg['pass'];

            $result = $this->getCabinet($devid,$password,$db);
            $result_msg['func'] = '1';
            if ($result){
                $result_msg['Asw'] = 'ok';
            }else{
                $result_msg['Asw'] = 'error';
            }
            $server->send($fd, json_decode($result_msg));

            return;
        }
        //心跳包
        if ($func == '2'){

            $list = explode('-',$msg['list']); //空闲柜子列表
            $new_list = [];
            foreach ($list as $k => $v){
                $new_list[$v]['is_online'] = 1;
                $new_list[$v]['is_free'] = 1;
            }

            $redis->setex($devid, 60 * 5, json_encode(['fd' => $fd, 'free_abinet' => $new_list]));

            $result_msg['func'] = '2';
            $result_msg['time'] = date('Y-m-d H:i:s',time());
            $server->send($fd, json_decode($result_msg));

            return;
        }
        //上传读卡数据
        if ($func == '3'){
            $card = $msg['card'];
            $result = $this->getCard($card,$db);

            $result_msg['func'] = '3';
            if ($result == 1){
                $result_msg['Asw'] = 'ok';
            }else if($result == 2){
                $result_msg['Asw'] = 'error';
            }else if($result == 3){
                $result_msg['Asw'] = 'admin';
            }
            $server->send($fd, json_decode($result_msg));

            return;
        }
        //柜子上锁(用户通过UI操作后进行的上锁)
        if ($func == '4'){
            $id = $msg['id'];
            $mode = $msg['mode'];
            $this->lockCabinet($devid,$id,$mode,$fd,$redis);
            return;
        }
        //输入寄存码开锁
        if ($func == '6'){
            $id = $msg['id'];   //柜号
            $no = $msg['no'];   //6位寄存码

            $this->openLock($devid,$id,$no,$server,$fd);
            return;
        }
        //发送学号获取学生信息
        if ($func == '7'){
            $no = $msg['no'];   //学号
            $this->getStudent($no,$server,$fd);
            return;
        }
        //获取年级列表
        if ($func == '8'){
            $this->getGrade($server,$fd);
            return;
        }
        //获取班级列表
        if ($func == '9'){
            $grade = $msg['grade'];
            $this->getClass($grade,$server,$fd);
            return;
        }
        //获取学生列表
        if ($func == '10'){
            $grade = $msg['grade'];
            $class = $msg['class'];
            $page = $msg['page'];

            $this->getStudentList($grade,$class,$page,$server,$fd);
            return;
        }
        //选中学生后开锁
        if ($func == '11'){
            $id = $msg['id'];   //柜号
            $No = $msg['No'];   //学号

            $this->studentOpenLock($devid,$id,$No,$server,$fd,$redis);
            return;
        }
        //刷卡后获取所属柜号
        if ($func == '12'){
            $id = $msg['id'];   //卡号

            $this->getCardAbinet($id,$server,$fd,$db);
            return;
        }
        //刷卡后获取所属柜号
        if ($func == '13'){
            $id = $msg['id'];   //柜号
            $no = $msg['No'];   //卡号

            $this->getCardOpenAbinet($devid,$id,$no,$server,$fd,$redis,$db);
            return;
        }
        //用亲情号和学号获取所属柜号
        if ($func == '14'){
            $stunoo = $msg['stuno'];   //学号
            $phone = $msg['phone'];   //亲情号

            $this->getStunooAbinet($devid,$stunoo,$phone,$server,$fd,$redis,$db);
            return;
        }
        //用亲情号和学生信息获取所属柜号
        if ($func == '15'){
            $grade = $msg['grade'];   //年级
            $class = $msg['class'];   //班级
            $name = $msg['name'];   //姓名
            $phone = $msg['phone'];   //亲情号

            $this->getqsStunooAbinet($devid,$grade,$class,$name,$phone,$server,$fd,$redis,$db);
            return;
        }
        //服务器通知中转柜显示使用柜子的学生列表，走马灯高亮显示
        if ($func == '16'){
            $this->getzzStunooAbinet($devid,$server,$fd,$redis,$db);
            return;
        }
        //用亲情号和学号挂失所属卡
        if ($func == '17'){
            $stuno = $msg['stuno'];
            $phone = $msg['phone'];
            $this->getReportCard($devid,$stuno,$phone,$server,$fd,$redis,$db);
            return;
        }
        //用亲情号和学生信息挂失所属卡
        if ($func == '18'){
            $grade = $msg['grade'];
            $class = $msg['class'];
            $name = $msg['name'];
            $phone = $msg['phone'];
            $this->getStudentReportCard($devid,$grade,$class,$name,$phone,$server,$fd,$redis,$db);
            return;
        }
        //解挂卡号
        if ($func == '19'){
            $no = $msg['No'];
            $this->getOpenReportCard($devid,$no,$server,$fd,$redis,$db);
            return;
        }
        //管理卡设置柜子信息
        if ($func == '20'){
            $no = $msg['No'];
            $key = $msg['key'];
            $mode = $msg['mode'];
            $this->getCabinetSetting($devid,$no,$key,$mode,$server,$fd,$redis,$db);
            return;
        }



        $server->send($fd, 'recv:' . implode(',', $msg));
    }

    /**
     * 设备是否存在
     * @param $devid
     * @param $password
     * @return bool
     * @throws \Psr\Container\ContainerExceptionInterface
     * @throws \Psr\Container\NotFoundExceptionInterface
     */
    public function getCabinet($devid,$password,$db){

        $cabinet = $db->fetch('SELECT * FROM `cabinet`WHERE devid = ?;', [$devid]);
        if ($cabinet){
            if ($cabinet['password'] == $password){

                return true;
            }else{

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
    public function getCard($card,$db){
        $kaku = $db->fetch('SELECT * FROM `kaku`WHERE tel_id = ?;', [$card]);
        if ($kaku){

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
    public function lockCabinet($devid,$id,$mode,$fd,$redis){
        $free_abinet = $redis->get($devid);
        if ($free_abinet){
            $free_abinet = json_decode($free_abinet,true);
            foreach ($free_abinet as $k => $v){
                if ($id == $k){
                    $free_abinet[$k]['is_free'] = 2; //上锁
                }
            }
            $new_list = json_encode($free_abinet);
            $redis->setex($devid, 60 * 5, json_encode(['fd' => $fd, 'free_abinet' => $new_list]));
        }

    }

    /**
     * 开锁
     * @param $devid
     * @param $id 柜号
     * @param $no 6位寄存码
     * @param $server
     * @param $fd
     * @throws \Psr\Container\ContainerExceptionInterface
     * @throws \Psr\Container\NotFoundExceptionInterface
     */
    public function openLock($devid,$id,$no,$server,$fd,$redis){

        $free_abinet = $redis->get($devid);
        if ($free_abinet){
            $free_abinet = json_decode($free_abinet,true);
            if ($free_abinet[$id]){
                $is_free = $free_abinet[$id]['is_free'];
            }

            if ($is_free == 2){
                //执行开锁
                $server->send($fd, json_decode(['func' => '6','id' => $id]));

                $free_abinet[$id]['is_free'] = 1;
            }

            $new_list = json_encode($free_abinet);
            $redis->setex($devid, 60 * 5, json_encode(['fd' => $fd, 'free_abinet' => $new_list]));
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
    public function getStudent($no,$server,$fd){
        $db = ApplicationContext::getContainer()->get(DB::class);
        $student = $db->fetch('SELECT stuname,class_id,grade_id FROM `student`WHERE stunoo = ?;', [$no]);
        $new_data = [];
        if ($student){

            $class = $db->fetch('SELECT name FROM `class`WHERE stunoo = ?;', [$student['class_id']]);
            $grade = $db->fetch('SELECT name FROM `grade`WHERE stunoo = ?;', [$student['grade_id']]);
            $new_data['func'] = '7';
            $new_data['grade'] = $grade['name'];
            $new_data['class'] = $class['name'];
            $new_data['name'] = $student['stuname'];
        }else{
            $new_data['func'] = '7';
            $new_data['Asw'] = 'null';
        }

        $server->send($fd, json_decode($new_data));
    }

    /**
     * 获取年级列表
     * @param $server
     * @param $fd
     * @throws \Psr\Container\ContainerExceptionInterface
     * @throws \Psr\Container\NotFoundExceptionInterface
     */
    public function getGrade($server,$fd){
        $db = ApplicationContext::getContainer()->get(DB::class);
        $grade = $db->query('SELECT name FROM `grade`;', []);
        if ($grade){
            $name_arr = array_column($grade,'name');
            $name = implode('-',$name_arr);

            $server->send($fd, json_decode(['func' => '8', 'grade' => $name]));
        }else{
            $server->send($fd, json_decode(['func' => '8', 'Asw' => 'null']));
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
    public function getClass($grade,$server,$fd){
        $db = ApplicationContext::getContainer()->get(DB::class);
        $grade = $db->fetch('SELECT grade_id FROM `grade` where name = ?;', [$grade]);
        if ($grade){
            $class = $db->query('SELECT class_id,name FROM `class` where grade_id = ?;', [$grade['grade_id']]);
            if ($class){
                $name_arr = array_column($class,'name');
                $name = implode('-',$name_arr);

                $server->send($fd, json_decode(['func' => '9', 'grade' => $name]));
            }else{
                $server->send($fd, json_decode(['func' => '9', 'Asw' => 'null']));
            }
        }else{
            $server->send($fd, json_decode(['func' => '9', 'Asw' => 'null']));
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
    public function getStudentList($grade,$class,$page,$server,$fd){
        $limit = 20;
        $offset = ($page - 1) * $limit;

        $db = ApplicationContext::getContainer()->get(DB::class);
        $class = $db->fetch('SELECT class_id FROM `class` where name = ?;', [$class]);
        if ($class){
            $student = $db->query('SELECT stuname FROM `student` where grade_id = ?;', [$grade['grade_id']]);
            if ($student){
                $name_arr = array_column($class,'stuname');
                $name = implode('-',$name_arr);

                $server->send($fd, json_decode(['func' => '9', 'grade' => $name]));
            }else{
                $server->send($fd, json_decode(['func' => '9', 'Asw' => 'null']));
            }
        }else{
            $server->send($fd, json_decode(['func' => '9', 'Asw' => 'null']));
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
    public function studentOpenLock($devid,$id,$No,$server,$fd){
        $container = ApplicationContext::getContainer();
        $redis = $container->get(\Hyperf\Redis\Redis::class);
        $free_abinet = $redis->get($devid);
        if ($free_abinet){
            $free_abinet = json_decode($free_abinet,true);
            if ($free_abinet[$id]){
                $is_free = $free_abinet[$id]['is_free'];
            }
            //2 被人占用 1空闲
            if ($is_free == 2){
                //执行开锁
                $server->send($fd, json_decode(['func' => '11','Asw' => '柜子被占用']));

                return;
            }else{
                $server->send($fd, json_decode(['func' => '11','id' => $id]));
                $free_abinet[$id]['is_free'] = 2;
                $free_abinet[$id]['stunoo'] = $No;
            }

            $new_list = json_encode($free_abinet);
            $redis->setex($devid, 60 * 5, json_encode(['fd' => $fd, 'free_abinet' => $new_list]));
        }

        //学生所有柜子列表
        $student_abinet = $redis->get($No);
        if ($student_abinet){
            $student_abinet = json_decode($student_abinet,true);
            array_push($student_abinet,$id);
        }else{
            $student_abinet = [];
            array_push($student_abinet,$id);
        }

        $redis->setex($No, 60 * 5, json_encode(['fd' => $fd, 'student_abinet' => $student_abinet]));
    }

    /**
     * 刷卡后获取所属柜号
     * @param $id 卡号
     * @param $server
     * @param $fd
     * @throws \Psr\Container\ContainerExceptionInterface
     * @throws \Psr\Container\NotFoundExceptionInterface
     */
    public function getCardAbinet($id,$server,$fd,$db){
        $ka = $db->fetch('SELECT epc FROM `kaku`WHERE tel_id = ?;', [$id]);
        $student = $db->fetch('SELECT stunoo FROM `student`WHERE epcid = ?;', [$ka['epc']]);
        if ($student){
            $container = ApplicationContext::getContainer();
            $redis = $container->get(\Hyperf\Redis\Redis::class);
            $student_abinet = $redis->get($student['stunoo']);
            if ($student_abinet){
                $student_abinet = json_decode($student_abinet,true);
                $abinet = implode('-',$student_abinet['student_abinet']);
                $server->send($fd, json_decode(['func' => '12','list' => $abinet]));
            }else{
                $server->send($fd, json_decode(['func' => '12','Asw' => 'null']));
            }
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
    public function getCardOpenAbinet($devid,$id,$no,$server,$fd,$redis,$db){
        $free_abinet = $redis->get($devid);
        if ($free_abinet){
            $free_abinet = json_decode($free_abinet,true);
            if ($free_abinet[$id]){
                $is_free = $free_abinet[$id]['is_free'];
            }
            //2 被人占用 1空闲
            if ($is_free == 2){
                //执行开锁
                $server->send($fd, json_decode(['func' => '13','id' => $id]));
                $free_abinet[$id]['is_free'] = 1;
                $free_abinet[$id]['stunoo'] = '';
                return;
            }else{

            }

            $new_list = json_encode($free_abinet);
            $redis->setex($devid, 60 * 5, json_encode(['fd' => $fd, 'free_abinet' => $new_list]));
        }

        $ka = $db->fetch('SELECT epc FROM `kaku`WHERE tel_id = ?;', [$no]);
        if (!$ka){
            $server->send($fd, json_decode(['func' => '13','Asw' => '卡号无效']));
        }
        $student = $db->fetch('SELECT stunoo FROM `student`WHERE epcid = ?;', [$ka['epc']]);
        $student_abinet = $redis->get($student['stunoo']);
        if ($student_abinet){
            $student_abinet = json_decode($student_abinet,true);
            $key = array_search($id ,$student_abinet);
            array_splice($student_abinet,$key,1);

            $redis->setex($student['stunoo'], 60 * 5, json_encode(['fd' => $fd, 'student_abinet' => $student_abinet]));
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
    public function getStunooAbinet($devid,$stunoo,$phone,$server,$fd,$redis,$db){
        $is_bind = $db->fetch('SELECT stunoo FROM `qq_tel`WHERE tel = ?;', [$phone]);
        if (!$is_bind){
            $server->send($fd, json_decode(['func' => '14','Asw' => '亲情号无效']));
        }

        $student_abinet = $redis->get($is_bind['stunoo']);
        if ($student_abinet){
            $student_abinet = json_decode($student_abinet,true);
            $abinet = implode('-',$student_abinet['student_abinet']);
            $server->send($fd, json_decode(['func' => '14','list' => $abinet]));
        }else{
            $server->send($fd, json_decode(['func' => '14','Asw' => 'null']));
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
    public function getqsStunooAbinet($devid,$grade,$class,$name,$phone,$server,$fd,$redis,$db){
        $stunoo = $db->fetch('SELECT stunoo FROM `qq_tel`WHERE tel = ?;', [$phone]);
        if (!$stunoo){
            $stunoo = $db->fetch('SELECT stunoo FROM `student`WHERE stuname = ?;', [$name]);
            if ($stunoo){
                $server->send($fd, json_decode(['func' => '15','Asw' => '学生信息无效']));
                return;
            }
        }

        $student_abinet = $redis->get($stunoo['stunoo']);
        if ($student_abinet){
            $student_abinet = json_decode($student_abinet,true);
            $abinet = implode('-',$student_abinet['student_abinet']);
            $server->send($fd, json_decode(['func' => '15','list' => $abinet]));
        }else{
            $server->send($fd, json_decode(['func' => '15','Asw' => 'null']));
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
    public function getzzStunooAbinet($devid,$server,$fd,$redis,$db){
        $free_abinet = $redis->get($devid);
        $stuname = [];
        if ($free_abinet){
            $free_abinet = json_decode($free_abinet,true);
            foreach ($free_abinet as $k => $v){
                if ($v['stunoo']){
                    $stunoo = $db->fetch('SELECT stuname FROM `student`WHERE stunoo = ?;', [$v['stunoo']]);
                    $stuname[] = $stunoo['stuname'];
                }
            }
        }
        $count = count($stuname);
        $server->send($fd, json_decode(['func' => '16','count' => $count,'page' => '','stu' => implode('-',$stuname)]));
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
    public function getReportCard($devid,$stuno,$phone,$server,$fd,$redis,$db){
        $qq_tel = $db->fetch('SELECT stunoo FROM `qq_tel`WHERE tel = ?;', [$phone]);
        if (!$qq_tel){
            $server->send($fd, json_decode(['func' => '27','Asw' => '亲情号无效']));
        }
        $student = $db->fetch('SELECT epcid FROM `student`WHERE stunoo = ?;', [$qq_tel['stunoo']]);
        if (!$student){
            $server->send($fd, json_decode(['func' => '27','Asw' => '学号无效']));
        }
        $card = $db->fetch('SELECT is_normal FROM `kaku`WHERE epc = ?;', [$student['epcid']]);
        if (!$card){
            $server->send($fd, json_decode(['func' => '27','Asw' => '卡片无效']));
        }
        if ($card['is_normal'] == 1){
            $server->send($fd, json_decode(['func' => '27','Asw' => '卡片已挂失']));
        }

        $is_update = $db->execute('update kaku set is_normal = ? WHERE epc = ?;', [1,$student['epcid']]);
        if ($is_update){
            $server->send($fd, json_decode(['func' => '27','Asw' => '挂失成功']));
        }else{
            $server->send($fd, json_decode(['func' => '27','Asw' => '挂失失败']));
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
    public function getStudentReportCard($devid,$grade,$class,$name,$phone,$server,$fd,$redis,$db){
        $qq_tel = $db->fetch('SELECT stunoo FROM `qq_tel`WHERE tel = ?;', [$phone]);
        if ($qq_tel){
            $stunoo = $qq_tel['stunoo'];
        }else{
            $student = $db->fetch('SELECT stunoo FROM `student`WHERE name = ?;', [$name]);
           if ($student){
               $stunoo = $student['stunoo'];
           }else{
               $server->send($fd, json_decode(['func' => '28','Asw' => '学生信息无效']));
           }
        }
        $student = $db->fetch('SELECT epcid FROM `student`WHERE stunoo = ?;', [$stunoo]);
        if (!$student){
            $server->send($fd, json_decode(['func' => '28','Asw' => '学号无效']));
        }
        $card = $db->fetch('SELECT is_normal FROM `kaku`WHERE epc = ?;', [$student['epcid']]);
        if (!$card){
            $server->send($fd, json_decode(['func' => '28','Asw' => '卡片无效']));
        }
        if ($card['is_normal'] == 1){
            $server->send($fd, json_decode(['func' => '28','Asw' => '卡片已挂失']));
        }

        $is_update = $db->execute('update kaku set is_normal = ? WHERE epc = ?;', [1,$student['epcid']]);
        if ($is_update){
            $server->send($fd, json_decode(['func' => '28','Asw' => '挂失成功']));
        }else{
            $server->send($fd, json_decode(['func' => '28','Asw' => '挂失失败']));
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
    public function getOpenReportCard($devid,$no,$server,$fd,$redis,$db){
        $card = $db->fetch('SELECT is_normal FROM `kaku`WHERE tel_id = ?;', [$no]);
        if (!$card){
            $server->send($fd, json_decode(['func' => '19','Asw' => '卡片无效']));
        }
        if ($card['is_normal'] == 0){
            $server->send($fd, json_decode(['func' => '19','Asw' => '卡片正常,无需挂失']));
        }

        $is_update = $db->execute('update kaku set is_normal = ? WHERE tel_id = ?;', [0,$no]);
        if ($is_update){
            $server->send($fd, json_decode(['func' => '19','Asw' => 'ok']));
        }else{
            $server->send($fd, json_decode(['func' => '19','Asw' => '解挂失败']));
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
    public function getCabinetSetting($devid,$no,$key,$mode,$server,$fd,$redis,$db){
        $setting = $db->fetch('SELECT * FROM `cabinet_setting_msg`WHERE devid = ?;', [$devid]);
        if ($setting){
            $server->send($fd, json_decode(['func' => '20','Asw' => '信息已存在']));
        }
        $id = $db->insert('Insert INTO `cabinet_setting_msg` SET devid = ?,no = ?,key = ?,mode = ?;', [$devid, $no,$key,$mode]);
        if ($id){
            $server->send($fd, json_decode(['func' => '20','Asw' => 'ok']));
        }else{
            $server->send($fd, json_decode(['func' => '20','Asw' => '设置失败']));
        }
    }

}