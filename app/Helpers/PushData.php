<?php
/**
 * Created by PhpStorm.
 * User: Lenovo
 * Date: 2022/2/21
 * Time: 18:01
 */
use Hyperf\Utils\ApplicationContext;

class PushData{
    static $COM_MB_RUL = "https://api.weixin.qq.com/cgi-bin/message/template/send?access_token=";
    static $COM_PIC_URL = "/upload/syspic/msg.jpg";

    static $temp_id_1 = "Pv7lns9OYPsIvig5QgsVoIA534KbAMBqkW8-HIHhHU4";//危险预警通知

    //获取模板id
    static function getTempid($index) {
        switch ($index){
            case 0:
                return self::$temp_id_1;//购买成功
                break;
        }

    }

    static function getUrl() {
        $access_token = self::getAccessToken();
        dump($access_token);
        $url = self::$COM_MB_RUL . $access_token;
        return $url;
    }

    //获取微信公众号access_token
    static function getAccessToken() {
        //正梵教育
        $appId = "wx493a78f45def8ac1";
        $appSecret = "c84bee3887f595b8183b215bf22e373b";

        $token_url = "https://api.weixin.qq.com/cgi-bin/token?grant_type=client_credential&appid=" . $appId . "&secret=" . $appSecret;

        $container = ApplicationContext::getContainer();
        $redis = $container->get(\Hyperf\Redis\Redis::class);

        $result = $redis->connect('127.0.0.1', 6379);
        if ($result) {
            $acc_token = $redis->get("my_access_token");

            if (!$acc_token) {
                $json = file_get_contents($token_url);
                $result = json_decode($json);
                $acc_token = $result->access_token;
                $redis->set("my_access_token", $acc_token, 7100);
            }
        } else {
            $json = file_get_contents($token_url);
            $result = json_decode($json);
            $acc_token = $result->access_token;
        }

        return $acc_token;
    }

    //危险人员预警通知
    static function createTempMsg($openid, $tempid, $title, $name, $position, $time, $remark, $url = "")
    {

        return '{
                       "touser":"' . $openid . '",
                       "template_id":"' . $tempid . '",
                       "url":"' . $url . '",
                       "topcolor":"#FF6666",
                       "data":{
                           "first":{
                               "value":"' . $title . '",
                               "color":"#000000"
                           },
                           "keyword1":{
                               "value":"' . $name . '",
                               "color":"#000000"
                           },
                           "keyword2":{
                               "value":"' . $position . '",
                               "color":"#000000"
                           },
                           "keyword3":{
                               "value":"' . $time . '",
                               "color":"#000000"
                           },
                          "remark":{
                               "value":"' . $remark . '",
                               "color":"#000000"
                           }
                       }
              }';

    }

    //curl
    static function singlePostMsg($url, $data) {
        $curl = curl_init();
        curl_setopt($curl, CURLOPT_URL, $url);
        curl_setopt($curl, CURLOPT_HEADER, FALSE);
        curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, FALSE);
        curl_setopt($curl, CURLOPT_SSL_VERIFYHOST, FALSE);
        curl_setopt($curl, CURLOPT_POST, 1);
        curl_setopt($curl, CURLOPT_POSTFIELDS, $data);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($curl, CURLOPT_IPRESOLVE, CURL_IPRESOLVE_V4);
        curl_setopt($curl, CURLOPT_HTTP_VERSION, CURL_HTTP_VERSION_1_0);
        $result = curl_exec($curl);
        if (curl_errno($curl)) {
            return array("errcode" => -1, "errmsg" => '发送错误号' . curl_errno($curl) . '错误信息' . curl_error($curl));
        }
        curl_close($curl);
        return $result;
    }

}