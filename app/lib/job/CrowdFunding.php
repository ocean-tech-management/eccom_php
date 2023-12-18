<?php
// +----------------------------------------------------------------------
// |[ 文档说明: 众筹模式业务处理消费队列]
// +----------------------------------------------------------------------



namespace app\lib\job;


use app\lib\BaseException;
use app\lib\models\CrowdfundingLottery;
use app\lib\models\RechargeLink;
use app\lib\services\Log;
use app\lib\services\CrowdFunding as CrowdFundingService;
use think\queue\Job;

class CrowdFunding
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
        if (!empty($data)) {
            //尝试捕获异常
            $errorMsg = null;
            $errorCode = null;
            try {
                //autoType 1为判断期是否完成 2为判断期是否到期失效 3为判断时候有可以释放的冻结众筹期 4为实际处理失败的期的业务逻辑 5为判断是否有到期需要开奖的抽奖计划 6为释放冻结中的历史熔断分期返回美丽金 7为根据认购成功的期生成冻结的历史熔断分期返回美丽金 8统计用户剩余可操作的提现额度 9为查询是否有超级提前购(预售)强行冻结到时间再释放奖励的订单
                $type = $data['dealType'] ?? 1;
                unset($data['dealType']);
                switch ($type) {
                    case 1:
                        $res = (new CrowdFundingService())->completeCrowFundingNew($data);
                        break;
                    case 2:
                        $res = (new CrowdFundingService())->checkExpireUndonePeriod($data);
                        break;
                    case 3:
                        $res = (new CrowdFundingService())->checkSuccessPeriod($data);
                        break;
                    case 4:
                        $res = (new CrowdFundingService())->checkExpireUndonePeriodDeal($data);
                        break;
                    case 5:
                        $res = (new CrowdfundingLottery())->timeForLottery([]);
                        break;
                    case 6:
                        $res = (new CrowdFundingService())->releaseFuseRecordDetail($data);
                        break;
                    case 7:
                        $res = (new CrowdFundingService())->crowdFusePlanDivide($data);
                        break;
                    case 8:
                        $res = (new RechargeLink())->summaryAllUserTodayLastRechargeRate(['time_type' => 1]);
                        break;
                    case 9:
                        $res = (new CrowdFundingService())->delayRewardOrderCanRelease(($data ?? []));
                        break;
                    default:
                        $res = false;
                }

            } catch (BaseException $e) {
                $errorMsg = $e->msg;
                $errorCode = $e->errorCode;
                $log['msg'] = '众筹模式业务处理订单出现了错误';
                $log['errorMsg'] = $errorMsg;
                $log['errorCode'] = $errorCode;
                $log['data'] = $data;
                (new Log())->setChannel('crowd')->record($log, 'error');
            }

        }
        $job->delete();
    }
}