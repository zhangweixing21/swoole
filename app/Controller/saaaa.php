<?php
/**
 * Created by PhpStorm.
 * User: Lenovo
 * Date: 2022/3/8
 * Time: 10:37
 */
/**
 * TCP协议的端口收到数据
 * @param swoole_server $serv
 * @param $fd
 * @param $from_id
 * @param $data
 * @return bool
 */

public function onTcpReceive(swoole_server $serv, $fd, $from_id, $data)

{

    $receiveStr = $data;

    $num = rand(0, 10);// 随机数(保留)

    $address = $receive[4];// 控制器地址(保留)

    $door = $receive[5];// 门编号

    $command = $receive[3];//指令先用控制器里发来的

// 包头

    $pack_head = [

        "02",

        $num,

        $command,

        $address,

        $door,

    ];

// 包体

    $pack_body = [

        $command

    ];

    $sendData = getSendData($pack_head, $pack_body);

    return $sendData;

}

function getSendData(array $head, array $body = array())

{


    $length = count($body);// 包体长度

    $hight = ($length & 0xff00) >> 8;// 高位右移8个位

    $low = $length & 0x00ff;// 低位

    $head[] = $low;// 数据长度低位

    $head[] = $hight;// 数据长度高位

// 将包头和包体 合并

    $data = $body ? array_merge_recursive($head, $body) : $head;

    $check = getCheckVal($data);// 获取校验值

    $data[] = $check;

    $data[] = "03";

    return get_pack($data);

}

function get_pack($data)

{


    $pack = "";

    foreach ($data as $k => $d) {


        $pack .= pack("C*", $d);// 十六进制应答

    }

//        echo "十六进制转为字符串应答的数据：" . bin2hex($pack) . PHP_EOL;

    return $pack;

}

/**
 * 根据数组中所有值 获取校验值
 * @param array $data
 * @return float|int|string
 */

function getCheckVal(array $data)

{


// 将第一个放进循环中会报错，单独将第一个字节先取出来

    $check = hexdec("0x" . $data[0]);

// 所有数据 进行异或运算，从数组中的第二个开始 (测试数据，减掉包尾)

    $i = 0;

// 这里的data是十六进制的字符串，要先将它以0x组合出来16进制的字符串，再转换为10进制的数字用以运算

// 得到的结果是十进制的校验值

    foreach ($data as $d) {

        $i++;

//            echo "打印加入运算的字符：" . $d.PHP_EOL;

        if ($i == 1) {

            $check = $d;

        } else {

            $check ^= $d;

        }

    }

//    $check = dechex($check); // 结果：是十进制的，最后，将十进制转十六进制字符，这里不需要转换十六进制，因为最后还需要将数据放到pack方法中转换

    return $check;

}

//TCP receive的数据，转换：
//
//1、将二进制解包为十进制：
//
//PHP Code复制内容到剪贴板

$data = unpack("C*", $data);// 从二进制字符流对数据进行解包，打印出来的数据是十进制的展示

//2、把 二进制字节流 (机器发来的)转换为十六进制值：(可以用pack转换回去)
//
//PHP Code复制内容到剪贴板

$data = bin2hex($data);// 将原始数据转换成16进制的字符串，就是机器发送过来的原数据了

//机器发来的都需要用bin2hex转换为十六进制我们常用的字符串，如果需要机器发送命令可以先写成字符串，再转换：
//
//PHP Code复制内容到剪贴板

pack("H*", bin2hex($data));

//场景：
//
//塔吊发送过来的命令是：a55a3101010671714ac9130403102a2b01f40000000013380bb8a4000000000000020000000000000000010056cc33c33c
//
//我们在client中模拟发送命令：
//
//PHP Code复制内容到剪贴板

$str = 'a55a3101010671714ac9130403102a2b01f40000000013380bb8a4000000000000020000000000000000010056cc33c33c';

// 模拟机器发送命令

$DeviceSendData = pack("H*", $str);
//
//接收服务器时对数据转换为正常显示的十六进制字符串，打印原始数据时，会显示乱码：
//
//PHP Code复制内容到剪贴板

public function onReceive($serv, $fd, $from_id, $data)

{

    echodate("Y-m-d H:i:s") . "原始数据 => " . $data . "\n";

    echodate("Y-m-d H:i:s") . "转换后数据 => " . bin2hex($data) . "\n\n\n";

}

//3、如果需要对数据进行异或运算，要使用十进制的数据
//
//1) 十六进制转十进制：
//
//PHP Code复制内容到剪贴板

$byte = "fe";

$data = hexdec("0x" . $byte);

//2)转换完毕后 再进行异或运算：
//
//注意点：如果是数组循环，请将第一组先取出来，否则会出现warning
//
//PHP Code复制内容到剪贴板

$data = array("02", "df", "2c", "00", "01", "01", "00", "01", "f0", "03");

function get_pack_header(array $data)
{

    $length = count($data);

// 将第一个放进循环中会报错，单独将第一个字节先取出来

    $header = hexdec("0x" . $data[0]);

// 去掉包尾的两字节，把所有校验位字节前面的所有数据 进行异或运算，从数组中的第二个开始

    for ($i = 1; $i) {

        $header ^= hexdec("0x" . $data[$i]);// 十六进制转换为十进制，再进行异或计算

    }

    $header = dechex($header);// 结果：f0 ， 把十进制转换为十六进制。

    return $header;

}