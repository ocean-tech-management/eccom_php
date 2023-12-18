<?php
// +----------------------------------------------------------------------
// |[ 文档说明: ]
// +----------------------------------------------------------------------
// | Copyright (c) 2018~2024 http://www.mlhcmk.com All rights reserved.
// +----------------------------------------------------------------------
 

namespace app\controller\api\v1;


use app\BaseController;
use app\lib\exceptions\PpylException;
use app\lib\models\PpylAuto;
use app\lib\models\PpylOrder as PpylOrderModel;
use app\lib\models\PpylWaitOrder;
use app\lib\services\Ppyl as PpylService;
use app\lib\models\PtOrder;

class PpylOrder extends BaseController
{
    /**
     * @title  拼拼有礼订单
     * @param PpylOrderModel $model
     * @throws \Exception
     * @return string
     */
    public function ppylOrder(PpylOrderModel $model)
    {
        $list = $model->userPtList($this->requestData);
        return returnData($list['list'], $list['pageTotal']);
    }

    /**
     * @title  拼拼有礼订单数据面板
     * @param PpylOrderModel $model
     * @throws \Exception
     * @return string
     */
    public function ppylOrderSummary(PpylOrderModel $model)
    {
        $info = $model->userPpylSummary($this->requestData);
        return returnData($info);
    }

    /**
    * @title  用户拼团情况
    * @param PpylOrderModel $model
    * @return string
    * @throws \Exception
    */
    public function ppylInfo(PpylOrderModel $model)
    {
        $info = $model->ptInfo($this->requestData);
        return returnData($info);
    }

    /**
     * @title  拼拼有礼中奖订单
     * @param PpylOrderModel $model
     * @throws \Exception
     * @return string
     */
    public function userWinPpylOrderList(PpylOrderModel $model)
    {
        $data = $this->requestData;
        $list = $model->userWinPpylOrderList($data);
        return returnData($list['list'], $list['pageTotal']);
    }


    /**
     * @title  拼拼有礼中奖订单详情
     * @param PpylOrderModel $model
     * @throws \Exception
     * @return string
     */
    public function userWinPpylOrderInfo(PpylOrderModel $model)
    {
        $data = $this->requestData;
        $list = $model->userWinPpylOrderInfo($data);
        return returnData($list['list'], $list['pageTotal']);
    }

    /**
     * @title  拼拼有礼中奖订单寄出或寄售
     * @param PpylOrderModel $model
     * @throws \Exception
     * @return string
     */
    public function winShipping(PpylOrderModel $model)
    {
//        throw new PpylException(['msg'=>'该服务维护中，请稍后再试']);
        $data = $this->requestData;
        $info = $model->winOrderShipping($data);
        return returnMsg($info);
    }

    /**
     * @title  用户排队订单列表
     * @param PpylWaitOrder $model
     * @throws \Exception
     * @return string
     */
    public function waitOrderList(PpylWaitOrder $model)
    {
        $list = $model->userWaitList($this->requestData);
        return returnData($list['list'], $list['pageTotal']);
    }


    /**
     * @title  用户自动订单计划列表
     * @param PpylAuto $model
     * @throws \Exception
     * @return string
     */
    public function autoOrderList(PpylAuto $model)
    {
        $list = $model->userAutoList($this->requestData);
        return returnData($list['list'], $list['pageTotal']);
    }

    /**
     * @title  取消排队
     * @param PpylService $service
     * @return string
     * @throws \Exception
     */
    public function cancelWait(PpylService $service)
    {
        $res = $service->cancelPpylWait($this->requestData);
        return returnMsg($res);
    }

    /**
     * @title  取消自动计划
     * @param PpylService $service
     * @return string
     * @throws \Exception
     */
    public function cancelAuto(PpylService $service)
    {
        $res = $service->cancelAutoPlan($this->requestData);
        return returnMsg($res);
    }

    /**
     * @title  订单提交退款
     * @param PpylOrderModel $model
     * @return string
     * @throws \Exception
     */
    public function submitRefund(PpylOrderModel $model)
    {
        $res = $model->submitRefund($this->requestData);
        return returnMsg($res);
    }



}