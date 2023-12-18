<?php
// +----------------------------------------------------------------------
// |[ 文档说明: 定时任务执行方法模块]
// +----------------------------------------------------------------------
// | Copyright (c) 2018~2024 http://www.mlhcmk.com All rights reserved.
// +----------------------------------------------------------------------


namespace app\lib\subscribe;

use app\lib\models\GoodsSpu;
use app\lib\models\MemberIncentives;
use app\lib\models\Order;
use app\lib\models\PtOrder;
use app\lib\services\Incentives;
use app\lib\services\Log;
use think\facade\Db;
use think\facade\Queue;

class Timer
{
    private $timeOutSecond = 7200;                      //订单未支付超时时间
    private $receiveTimeOutSecond = 3600 * 24 * 12;     //订单未签收超时时间

    /**
     * @title  onTimer
     * @return void
     * @remark 命名规范是on+事件标识,所以该方法的事件名称为event('Timer')
     */
    public function onTimer()
    {
        var_dump(date('Y-m-d H:i:s', time()) . '推送异步任务');
        //$test = $server->task(['type'=>'divide']);
        //dump($test);
    }


    /**
     * @title  超时订单检测
     * @return void
     * @remark 命名规范是on+事件标识,所以该方法的事件名称为event('OrderTimeOutTimer')
     * @throws \Exception
     */
    public function onOrderTimeOutTimer()
    {
        $log['msg'] = '查找超时订单';
        $log['time'] = date('Y-m-d H:i:s');

        $timeoutOrderQueue = Queue::push('app\lib\job\TimerForOrder', ['order_sn' => [], 'type' => 1], config('system.queueAbbr') . 'TimeOutOrder');

        $log['delRes'] = $timeoutOrderQueue;

        //查看当前时间是否为凌晨三点,如果是则执行统计用户剩余可操作提现额度
        if ((string)date('H:i:s', time()) == '23:58:00') {
            $Queue = Queue::push('app\lib\job\CrowdFunding', ['dealType' => 8], config('system.queueAbbr') . 'CrowdFunding');
        }
//        $this->log($log);

    }

    /**
     * @title  拼团超时订单检测
     * @return void
     * @remark 命名规范是on+事件标识,所以该方法的事件名称为event('PtOrderTimeOutTimer')
     * @throws \Exception
     */
    public function onPtOrderTimeOutTimer()
    {
        $log['msg'] = '查找拼团超时订单';
        $log['time'] = date('Y-m-d H:i:s');

        $timeoutPtOrderQueue = Queue::push('app\lib\job\TimerForPtOrder', ['activity_sn' => [], 'type' => 1], config('system.queueAbbr') . 'TimeOutPtOrder');

        $log['delRes'] = $timeoutPtOrderQueue;
//        $this->log($log);

    }

    /**
     * @title  拼拼有礼超时订单检测
     * @return void
     * @remark 命名规范是on+事件标识,所以该方法的事件名称为event('PpylOrderTimeOutTimer')
     * @throws \Exception
     */
    public function onPpylOrderTimeOutTimer()
    {
        $log['msg'] = '查找拼拼有礼超时订单';
        $log['time'] = date('Y-m-d H:i:s');

        $timeoutPpylOrderQueue = Queue::push('app\lib\job\TimerForPpylOrder', ['activity_sn' => [], 'type' => 1], config('system.queueAbbr') . 'TimeOutPpylOrder');

        $timeoutPpylOrderQueue = Queue::push('app\lib\job\TimerForPpylOrder', ['activity_sn' => [], 'type' => 3], config('system.queueAbbr') . 'TimeOutPpylOrder');

        $log['delRes'] = $timeoutPpylOrderQueue;
//        $this->log($log);

    }

    /**
     * @title  拼拼有礼排队超时订单检测
     * @return void
     * @remark 命名规范是on+事件标识,所以该方法的事件名称为event('PpylOrderTimeOutTimer')
     * @throws \Exception
     */
    public function onPpylWaitOrderTimeOutTimer()
    {
        $log['msg'] = '查找拼拼有礼排队超时订单';
        $log['time'] = date('Y-m-d H:i:s');

        $timeoutPpylOrderQueue = Queue::push('app\lib\job\TimerForPpyl', [ 'type' => 1], config('system.queueAbbr') . 'TimeOutPpyl');

        $notPayPpylWaitOrderQueue = Queue::push('app\lib\job\TimerForPpyl', [ 'type' => 4], config('system.queueAbbr') . 'TimeOutPpyl');

        $log['delRes'] = $timeoutPpylOrderQueue;
//        $this->log($log);

    }

    /**
     * @title  拼拼有礼排队超时订单检测(数据库检测,防漏)
     * @return void
     * @remark 命名规范是on+事件标识,所以该方法的事件名称为event('PpylWaitOrderTimeOutByDBTimer')
     * @throws \Exception
     */
    public function onPpylWaitOrderTimeOutByDBTimer()
    {
        $log['msg'] = '查找拼拼有礼排队超时订单-数据库防漏';
        $log['time'] = date('Y-m-d H:i:s');

        $notPayPpylWaitOrderQueue = Queue::push('app\lib\job\TimerForPpyl', [ 'type' => 5], config('system.queueAbbr') . 'TimeOutPpyl');

        $log['delRes'] = $notPayPpylWaitOrderQueue;
//        $this->log($log);

    }



    /**
     * @title  拼拼有礼超时检测
     * @return void
     * @remark 命名规范是on+事件标识,所以该方法的事件名称为event('PpylAuto')
     * @throws \Exception
     */
    public function onPpylAuto()
    {
        $log['msg'] = '查找拼拼有礼超时逻辑';
        $log['time'] = date('Y-m-d H:i:s');

        $timeoutOneQueue = Queue::push('app\lib\job\PpylAuto', [ 'autoType' => 1], config('system.queueAbbr') . 'PpylAuto');

        $timeoutTwoQueue = Queue::push('app\lib\job\PpylAuto', [ 'autoType' => 2], config('system.queueAbbr') . 'PpylAuto');

        $timeoutFourQueue = Queue::push('app\lib\job\PpylAuto', [ 'autoType' => 4], config('system.queueAbbr') . 'PpylAuto');

        //凌晨0-1点才执行定时任务的超时
        if (in_array(intval(date('i', time())), [0, 1])) {
            $timeoutFourQueue = Queue::push('app\lib\job\PpylAuto', ['autoType' => 5], config('system.queueAbbr') . 'PpylAuto');
        }


//        $log['delRes'] = $timeoutPpylOrderQueue;
//        $this->log($log);

    }

    /**
     * @title  超时签收订单检测
     * @return void
     * @remark 命名规范是on+事件标识,所以该方法的事件名称为event('ReceiveTimeOutTimer')
     */
    public function onReceiveTimeOutTimer()
    {
        $log['msg'] = '查找超时签收订单';
        $log['time'] = date('Y-m-d H:i:s');

        $timeoutOrderQueue = Queue::push('app\lib\job\TimerForOrder', ['order_sn' => [], 'type' => 2], config('system.queueAbbr') . 'TimeOutOrder');

        $log['delRes'] = $timeoutOrderQueue;
//        $this->log($log);
    }

    /**
     * @title  定时上架产品
     * @return void
     */
    public function onGoodsStatusTimer()
    {
        $log['msg'] = '查找需要定时上架的产品';
        $log['time'] = date('Y-m-d H:i:s');
        $upGoodsQueue = Queue::push('app\lib\job\TimerForGoods', ['order_sn' => [], 'type' => 1], config('system.queueAbbr') . 'GoodsStatus');
        $log['delRes'] = $upGoodsQueue;
//        $this->log($log);

        $dLog['msg'] = '查找需要定时下架的产品';
        $dLog['time'] = date('Y-m-d H:i:s');
        $downGoodsQueue = Queue::push('app\lib\job\TimerForGoods', ['order_sn' => [], 'type' => 2], config('system.queueAbbr') . 'GoodsStatus');
        $dLog['delRes'] = $downGoodsQueue;
//        $this->log($dLog);
    }

    /**
     * @title  定时检查众筹活动是否完成众筹目标和冻结中的是否可以解冻
     * @return void
     */
    public function onCrowdCheckTimer()
    {
        $log['msg'] = '查找众筹活动是否完成众筹目标';
        $log['time'] = date('Y-m-d H:i:s');
        $checkCompleteQueue = Queue::push('app\lib\job\CrowdFunding', ['dealType' => 2], config('system.queueAbbr') . 'CrowdFunding');
        $log['delRes'] = $checkCompleteQueue;
//        $this->log($log);

        $dLog['msg'] = '查找是否有到期需要开奖的抽奖计划';
        $dLog['time'] = date('Y-m-d H:i:s');
        $checkPlanQueue = Queue::push('app\lib\job\CrowdFunding', ['dealType' => 5], config('system.queueAbbr') . 'CrowdFunding');
        $dLog['delRes'] = $checkPlanQueue;

        //查看是否有超前提前购冻结待释放的本/奖金
        $dLog['msg'] = '查看是否有超前提前购冻结待释放的本/奖金';
        $checkDelay = Queue::push('app\lib\job\CrowdFunding', ['dealType' => 9, 'searType' => 1], config('system.queueAbbr') . 'CrowdFunding');

//        $this->log($dLog);
    }

    /**
     * @title  结算/清算教育基金
     * @return void
     * @throws \Exception
     */
    public function onIncentives()
    {
        $log['msg'] = '查找是否为教育基金的发放日';
        $log['time'] = date('Y-m-d H:i:s');
        $res = false;
        //查看当前时间是否为凌晨三点,如果是则执行查询任务
        if (intval(date('H', time())) == 3) {
            $list = MemberIncentives::where(['status' => 1])->field('level,settlement_date')->select()->toArray();
            if (!empty($list)) {
                foreach ($list as $key => $value) {
                    //判断今天是否为该等级的结算/清算日,是则开始进入清算/结算流程
                    if (intval(date('d', time())) == $value['settlement_date']) {
                        $needArrival[] = $value['level'];
                    }
                }
                if (!empty($needArrival)) {
                    $service = (new Incentives());
                    if (count($needArrival) > 1) {
                        $res = $service->rewardIncentives();
                    } else {
                        $res = $service->rewardIncentives(['level' => current($needArrival)]);
                    }

                    $log['res'] = $res;
                    $log['data'] = $list ?? [];
                    $log['resMsg'] = !empty($res) ? '有需要结算/清算的教育基金' : '暂无需要结算/清算的教育基金';
                    $this->log($log);
                }
            }
        }

    }

    /**
     * @title  记录日志
     * @param array $data 数据信息
     * @param string $level 日志等级
     * @return mixed
     */
    public function log(array $data, string $level = 'info')
    {
        return (new Log())->setChannel('timer')->record($data, $level);
    }
}