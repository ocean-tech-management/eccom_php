<?php
// +----------------------------------------------------------------------
// |[ 文档说明: 会员升级队列]
// +----------------------------------------------------------------------
// | Copyright (c) 2018~2024 http://www.mlhcmk.com All rights reserved.
// +----------------------------------------------------------------------
 

namespace app\lib\job;

use app\lib\services\Member;
use think\facade\Queue;
use think\queue\Job;

class MemberUpgrade
{
    public function fire(Job $job, $data)
    {
        if (!empty($data)) {
            //1为商城会员升级 默认 2为拼拼有礼渠道会员升级 3为成为初级会员
            switch ($data['type'] ?? 1){
                case 1:
                    $res = (new Member())->memberUpgrade($data['uid']);
                    //处理完升级后释放升级订单(团长大礼包订单)的缓存锁
                    cache($data['uid'] . ((new Member())->memberUpgradeOrderKey), null);
                    break;
                case 2:
                    $res = (new Member())->memberUpgradeByPpyl($data['uid']);
                    break;
                case 3:
                    $res = (new Member())->becomeMember($data);
                    break;
            }

        }
        $job->delete();
    }

}