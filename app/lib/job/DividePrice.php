<?php
// +----------------------------------------------------------------------
// |[ 文档说明: ]
// +----------------------------------------------------------------------
// | Copyright (c) 2018~2024 http://www.mlhcmk.com All rights reserved.
// +----------------------------------------------------------------------
 

namespace app\lib\job;

use app\lib\models\BalanceDetail;
use app\lib\models\Member;
use app\lib\models\OrderGoods;
use app\lib\models\User;
use app\lib\models\Divide;
use app\lib\services\Log;
use app\lib\services\Wx;
use think\facade\Db;
use think\facade\Queue;
use think\queue\Job;

class DividePrice
{
    /**
     * @title  消费队列
     * @param Job $job
     * @param $data
     * @return void
     * @throws \Exception
     */
    public function fire(Job $job, $data)
    {
        $res = (new \app\lib\services\Divide())->divideForTopUser($data);

        //记录订单分润冗余结构和团队顶级
        $dtRes = Queue::later(10, 'app\lib\job\MemberChain', ['order_sn' => $data['order_sn'] ?? null, 'handleType' => 2], config('system.queueAbbr') . 'MemberChain');

        $job->delete();

    }
}