<?php
// +----------------------------------------------------------------------
// |[ 文档说明: 自动化处理业务逻辑队列]
// +----------------------------------------------------------------------
// | Copyright (c) 2018~2024 http://www.mlhcmk.com All rights reserved.
// +----------------------------------------------------------------------


namespace app\lib\job;


use app\lib\models\Device;
use app\lib\models\DeviceOrder;
use app\lib\models\MemberTest;
use app\lib\models\ShippingDetail;
use app\lib\services\AfterSale;
use app\lib\services\DevicePower;
use app\lib\services\Mqtt;
use app\lib\services\Pay;
use app\lib\services\PropagandaReward;
use think\queue\Job;
use \app\lib\services\Device as DeviceService;

class Auto
{
    /**
     * @title  fire
     * @param Job $job
     * @param $data
     * @return void
     * @throws \Exception
     */
    public function fire(Job $job, $data)
    {
        //自动处理类型 1为自动售后 2为快递100回调信息修改 3为退售后订单重新进入退售后队列 4为发放广宣奖奖励 5为发放股东奖奖励 6为设备订单分润 7给兑换商品自动发货确认收货等操作 8为操作设备智能开关 9为设备智能开关消息回调重发
        $type = $data['autoType'] ?? 1;
        switch ($type) {
            case 1:
                $res = (new AfterSale())->autoVerify($data);
                break;
            case 2:
                $res = (new ShippingDetail())->subscribeNewOrEdit($data);
                break;
            case 3:
                $res = (new Pay())->completeRefund($data);
                break;
            case 4:
                $res = (new PropagandaReward())->reward($data);
                break;
            case 5:
                $res = (new PropagandaReward())->shareholderReward($data);
                break;
            case 6:
                $res = (new Device())->divideForDevice($data);
                break;
            case 7:
                $res = (new Pay())->checkExchangeAutoShip($data);
                break;
            case 8:
                $res = (new DeviceService())->closePower($data);
                break;
            case 9:
                $res = (new DevicePower())->recordMsg($data);
                break;
            default:
                $res = false;
        }
        $job->delete();
    }
}