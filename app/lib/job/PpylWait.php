<?php
// +----------------------------------------------------------------------
// |[ 文档说明: 拼拼有礼排队队伍处理消费队列]
// +----------------------------------------------------------------------



namespace app\lib\job;


use app\lib\BaseException;
use app\lib\services\Log;
use app\lib\services\Ppyl;
use think\queue\Job;

class PpylWait
{
    /**
     * @title  fire
     * @param Job $job
     * @param  $data
     * @return void
     * @throws \Exception
     */
    public function fire(Job $job, $data)
    {
        if(!empty($data)){
            //尝试捕获异常
            $errorMsg = null;
            $errorCode = null;
            try {
                $res = (new Ppyl())->dealWaitOrder($data);
            } catch (BaseException $e) {
                $errorMsg = $e->msg;
                $errorCode = $e->errorCode;
                $log['msg'] = '排队处理订单出现了错误';
                $log['errorMsg'] = $errorMsg;
                $log['errorCode'] = $errorCode;
                $log['data'] = $data;
                (new Log())->setChannel('ppylWait')->record($log, 'error');
            }

        }
        $job->delete();
    }
}