<?php
// +----------------------------------------------------------------------
// |[ 文档说明: 活动模块Service]
// +----------------------------------------------------------------------
// | Copyright (c) 2018~2024 http://www.mlhcmk.com All rights reserved.
// +----------------------------------------------------------------------
 

namespace app\lib\services;


use app\lib\exceptions\ActivityException;
use app\lib\models\PtActivity;
use app\lib\models\Order;

class Activity
{
    public function startPt(array $data)
    {
        $activityInfo = (new PtActivity())->info($data['activity_code']);
        if (empty($activityInfo)) {
            throw new ActivityException(['errorCode' => 1800101]);
        }
        //检查开团对象
        if ($activityInfo['start_user_type'] != 1) {
            $userOrder = (new Order())->checkUserOrder($data['uid']);
            $canNotStart = false;
            switch ($activityInfo['start_user_type']) {
                case 2:
                    if (!empty($userOrder)) {
                        $canNotStart = true;
                    }
                    break;
                case 3:
                    if (empty($userOrder)) {
                        $canNotStart = true;
                    }
                    break;
            }
            if ($canNotStart) {
                throw new ActivityException(['errorCode' => 1800102]);
            }
        }

        $userJoinNumber = 1;

    }
}