<?php
// +----------------------------------------------------------------------
// |[ 文档说明: 乐小活回调模块controller]
// +----------------------------------------------------------------------



namespace app\controller\callback\v1;


use app\lib\services\Log;
use think\facade\Request;

class LetfreeCallback
{
    /**
     * @Name   接受异步通知
     * @author  Coder
     */
    public function callBack()
    {
        $callbackParam = Request::param();
        $log['requestData'] = $callbackParam;
        $this->log($log,'info');
        return returnMsg(true);
    }

    /**
     * @title  记录日志
     * @param array $data 数据信息
     * @param string $level 日志信息
     * @param string $channel 日志通道
     * @return mixed
     */
    public function log(array $data, string $level = 'error', string $channel = 'letfree')
    {
        return (new Log())->setChannel($channel)->record($data, $level);
    }
}