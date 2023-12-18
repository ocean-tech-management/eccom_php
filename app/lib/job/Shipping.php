<?php
// +----------------------------------------------------------------------
// |[ 文档说明: 物流信息回调处理业务逻辑队列]
// +----------------------------------------------------------------------
// | Copyright (c) 2018~2024 http://www.mlhcmk.com All rights reserved.
// +----------------------------------------------------------------------


namespace app\lib\job;


use app\lib\models\ShippingDetail;
use think\queue\Job;

class Shipping
{
    public function fire(Job $job, $data)
    {
        //1为快递100回调信息修改
        $type = $data['autoType'] ?? 1;
        switch ($type) {
            case 1:
                $res = (new ShippingDetail())->subscribeNewOrEdit($data);
                break;
            default:
                $res = false;
        }
        $job->delete();
    }
}