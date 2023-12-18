<?php
// +----------------------------------------------------------------------
// |[ 文档说明: 快商回调模块控制器]
// +----------------------------------------------------------------------



namespace app\controller\callback\v1;


use app\BaseController;
use app\lib\exceptions\JoinPayException;
use app\lib\exceptions\ServiceException;
use app\lib\services\JoinPay;
use app\lib\services\KuaiShangPay;
use app\lib\services\Log;
use think\facade\Request;

class KuaiShangCallback extends BaseController
{
    public function contract()
    {
        $callbackParam = Request::param();
        //校验签名参数
        $checkSignRes = $this->checkSign($callbackParam);

        if (!$checkSignRes) {
            $log['callbackParam'] = $callbackParam;
            $this->log($log, 'error');
            throw new ServiceException(['msg' => '签名校验有误']);
        }
    }

    /**
     * @title  回调校验签名
     * @param array $callbackParam
     * @return bool
     */
    public function checkSign(array $callbackParam)
    {
        if (empty($callbackParam) || empty($callbackParam['corpId'])) {
            return false;
        }
        $checkSign = $callbackParam;
        unset($checkSign['signature']);

        ksort($checkSign);
        $str = config('system.kuaishang.secret');
        foreach ($checkSign as $key => $value) {
            $str .= (strtoupper($key).$value);
        }
        $signature = md5($str);

        if ($signature != $callbackParam['signature']) {
            return false;
        }

        return true;
    }

    /**
     * @title  记录日志
     * @param array $data 数据信息
     * @param string $level 日志信息
     * @param string $channel 日志通道
     * @return mixed
     */
    public function log(array $data, string $level = 'error', string $channel = 'callback')
    {
        return (new Log())->setChannel($channel)->record($data, $level);
    }
}