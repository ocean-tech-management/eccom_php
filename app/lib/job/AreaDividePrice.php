<?php
// +----------------------------------------------------------------------
// |[ 文档说明: 区代分润]
// +----------------------------------------------------------------------
// | Copyright (c) 2018~2024 http://www.mlhcmk.com All rights reserved.
// +----------------------------------------------------------------------
 

namespace app\lib\job;

use app\lib\services\AreaDivide;
use think\queue\Job;

class AreaDividePrice
{
    /**
     * @title  消费队列
     * @param Job $job
     * @param $data
     * @return mixed
     * @throws \Exception
     */
    public function fire(Job $job, $data)
    {
        //自动处理类型 1为商品订单区代分润 2为设备订单区代分润
        $type = $data['dealType'] ?? 1;
        //无区代收益
//        switch ($type) {
//            case 1:
//                $res = (new AreaDivide())->divideForTopUser($data);
//                break;
//            case 2:
//                $res = (new AreaDivide())->divideForDevice($data);
//                break;
//            default:
//                $res = false;
//        }

        $job->delete();

    }
}