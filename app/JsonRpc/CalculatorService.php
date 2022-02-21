<?php
/**
 * Created by PhpStorm.
 * User: Lenovo
 * Date: 2022/2/21
 * Time: 9:20
 */
namespace App\JsonRpc;
use Hyperf\RpcServer\Annotation\RpcService;


/**
 * 注意，如希望通过服务中心来管理服务，需在注解内增加 publishTo 属性
 * @RpcService(name="CalculatorService",protocol="jsonrpc-http",server="jsonrpc-http",publishTo="consul")
 */
class CalculatorService implements CalculatorServiceInterface
{

    public function add(int $v1, int $v2): int
    {
        return $v1 + $v2 +100;
        // TODO: Implement add() method.
    }

}