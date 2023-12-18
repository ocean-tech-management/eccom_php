<?php
// +----------------------------------------------------------------------
// |[ 文档说明: 拼拼有礼模块抽奖和业务处理模块消费队列]
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
use app\lib\services\Ppyl;
use app\lib\services\Wx;
use think\facade\Db;
use think\facade\Queue;
use think\queue\Job;

class PpylLottery
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
        if (!empty($data)) {
            if (!empty($data['activity_sn'] ?? null)) {
                (new Ppyl())->completePpylOrder(['activity_sn' => $data['activity_sn']]);
            }
        }

        $job->delete();

    }
}