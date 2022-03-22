<?php

/**************************************************************
 *
 * 使用特定function对数组中所有元素做处理
 * @param string &$array 要处理的字符串
 * @param string $tocode 编码后
 * @param string $oldcode 编码前
 * @param string $function 要执行的函数
 * @return boolean $apply_to_keys_also 是否也应用到key上
 * @return array $array 是否也应用到key上
 * @access public
 *
 *************************************************************/

function encodeOperations(&$array, $function, $tocode = false, $oldcode = false, $apply_to_keys_also = false)

{

    foreach ($array as $key => $value) {

        if (is_array($value)) {

            encodeOperations($array[$key], $function, $apply_to_keys_also);

        } else {

            if ($tocode && $oldcode) {

                if (function_exists(mb_convert_encoding)) {

                    $value = mb_convert_encoding($value, $tocode, $oldcode);

                } else {

                    return "error";

                }

            }

            $array[$key] = $function($value);

        }


        if ($apply_to_keys_also && is_string($key)) {

            $new_key = $function($key);

            if ($new_key != $key) {

                $array[$new_key] = $array[$key];

                unset($array[$key]);

            }

        }

    }

    return $array;

}


/****from:https://www.jb51.net/phper/31/66729.htm**********************************************************
 *
 * 将数组转换为JSON字符串（兼容中文）
 * @param array $array 要转换的数组
 * @return string 转换得到的json字符串
 * @access public
 *
 *************************************************************/

function JSON($array)
{

    arrayRecursive($array, 'urlencode', true);

    $json = json_encode($array);

    return urldecode($json);

}