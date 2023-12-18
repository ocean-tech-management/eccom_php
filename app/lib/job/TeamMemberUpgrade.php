<?php
// +----------------------------------------------------------------------
// |[ 文档说明: 团队会员升级队列]
// +----------------------------------------------------------------------
// | Copyright (c) 2018~2024 http://www.mlhcmk.com All rights reserved.
// +----------------------------------------------------------------------
 

namespace app\lib\job;

use app\lib\models\RechargeTopLinkRecord;
use app\lib\models\ShareholderMember;
use app\lib\models\TeamMember;
use app\lib\services\Pay;
use app\lib\services\TeamMember as TeamMemberService;
use think\facade\Queue;
use think\queue\Job;

class TeamMemberUpgrade
{
    public function fire(Job $job, $data)
    {
        if (!empty($data)) {
            //1为团队会员升级 默认 2为成为团队会员 3为判断是否需要降级 4为判断是否可以升级为股东 5美丽金充值后记录团队长的充值业绩冗余明细 6为美丽金充值后记录团队长的充值业绩冗余明细-商城部分直推30%的奖励冗余记录
            switch ($data['type'] ?? 1) {
                case 1:
                    $res = (new TeamMemberService())->memberUpgrade($data['uid']);
                    //处理完升级后释放升级订单(团长大礼包订单)的缓存锁
                    cache($data['uid'] . ((new TeamMemberService())->memberUpgradeOrderKey), null);
                    break;
                case 2:
                    unset($data['type']);
                    $res = (new TeamMemberService())->becomeMember($data);
                    break;
                case 3:
                    unset($data['type']);
                    $res = (new TeamMemberService())->checkUserLevel($data);
                    break;
                case 4:
                    unset($data['type']);
                    $res = (new ShareholderMember())->becomeMember($data);
                    break;
                case 5:
                    $res = (new Pay())->recordRechargeTopLinkUser($data);
                    break;
                case 6:
                    $res = (new RechargeTopLinkRecord())->record($data);
                    break;
            }

        }
        $job->delete();
    }

}