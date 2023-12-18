<?php
// +----------------------------------------------------------------------
// |[ 文档说明: 区代会员升级队列]
// +----------------------------------------------------------------------
// | Copyright (c) 2018~2024 http://www.mlhcmk.com All rights reserved.
// +----------------------------------------------------------------------


namespace app\lib\job;

use app\lib\models\ShareholderMember;
use app\lib\models\TeamMember;
use app\lib\services\AreaMember as AreaMemberService;
use think\facade\Queue;
use think\queue\Job;

class AreaMemberUpgrade
{
    public function fire(Job $job, $data)
    {
        if (!empty($data)) {
            //1为团队会员升级 默认 2为成为团队会员 3为判断是否需要降级 4为判断是否可以升级为股东
            switch ($data['type'] ?? 1) {
                case 1:
                    $res = (new AreaMemberService())->memberUpgrade($data['uid']);
                    //处理完升级后释放升级订单(团长大礼包订单)的缓存锁
                    cache($data['uid'] . ((new AreaMemberService())->memberUpgradeOrderKey), null);
                    break;
                case 2:
                    unset($data['type']);
                    $res = (new AreaMemberService())->becomeMember($data);
                    break;
                case 3:
                    unset($data['type']);
                    $res = (new AreaMemberService())->checkUserLevel($data);
                    break;
            }

        }
        $job->delete();
    }

}