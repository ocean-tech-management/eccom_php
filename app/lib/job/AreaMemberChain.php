<?php
// +----------------------------------------------------------------------
// |[ 文档说明: 区代会员冗余结构消费队列]
// +----------------------------------------------------------------------
// | Copyright (c) 2018~2021 http://www.mlhcmk.com All rights reserved.
// +----------------------------------------------------------------------
 

namespace app\lib\job;


use app\lib\services\AreaMember;
use app\lib\services\Member;
use app\lib\services\TeamMember;
use think\queue\Job;

class AreaMemberChain
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
        //handleType 1为处理分润第一人冗余结构 2为订单分润冗余结构
        switch ($data['handleType'] ?? 1) {
            case 1:
                $res = (new AreaMember())->refreshDivideChain($data);
                break;
            case 2:
                $res = (new AreaMember())->recordOrderChain($data);
                break;
        }

        $job->delete();

    }
}