<?php
// +----------------------------------------------------------------------
// |[ 文档说明: 售后模块Controller]
// +----------------------------------------------------------------------
// | Copyright (c) 2018~2024 http://www.mlhcmk.com All rights reserved.
// +----------------------------------------------------------------------


namespace app\controller\admin;


use app\BaseController;
use app\lib\exceptions\OrderException;
use app\lib\exceptions\ServiceException;
use app\lib\models\AfterSale as AfterSaleModel;

class AfterSale extends BaseController
{
    protected $middleware = [
        'checkAdminToken',
        'checkRule',
        'OperationLog',
    ];

    /**
     * @title  售后申请列表
     * @param AfterSaleModel $model
     * @return string
     * @throws \Exception
     */
    public function list(AfterSaleModel $model)
    {
        $list = $model->list($this->requestData);
        return returnData($list['list'], $list['pageTotal'], 0, '成功', $list['total'] ?? 0);
    }

    /**
     * @title  售后申请详情
     * @param AfterSaleModel $model
     * @return string
     * @throws \Exception
     */
    public function info(AfterSaleModel $model)
    {
        $info = $model->info($this->requestData);
        return returnData($info);
    }

    /**
     * @title  审核售后申请
     * @param AfterSaleModel $model
     * @return string
     */
    public function verify(AfterSaleModel $model)
    {
        $res = $model->verify($this->requestData);
        return returnMsg($res);
    }

    /**
     * @title  商家确认退款
     * @param AfterSaleModel $model
     * @return string
     */
    public function refund(AfterSaleModel $model)
    {
        $res = $model->sellerConfirmWithdrawPrice($this->requestData);
        return returnMsg($res);
    }

    /**
     * @title  商家填写换货物流
     * @param AfterSaleModel $model
     * @return string
     */
    public function sellerFillInShip(AfterSaleModel $model)
    {
        $res = $model->sellerFillInShip($this->requestData);
        return returnMsg($res);
    }

    /**
     * @title  商家确认收到换货
     * @param AfterSaleModel $model
     * @return string
     */
    public function confirmReceiveGoods(AfterSaleModel $model)
    {
        $info = $model->SellerConfirmReceiveGoods($this->requestData);
        return returnData($info);
    }

    /**
     * @title  商家拒绝收货
     * @param AfterSaleModel $model
     * @return string
     */
    public function refuseReceiveGoods(AfterSaleModel $model)
    {
        $info = $model->SellerRefuseReceiveGoods($this->requestData);
        return returnData($info);
    }

    /**
     * @title  帮助用户填写退/换货物流
     * @param AfterSaleModel $model
     * @return string
     */
    public function helpUserFillInShip(AfterSaleModel $model)
    {
        $res = $model->userFillInShip($this->requestData);
        return returnMsg($res);
    }

    /**
     * @title  系统取消退售后
     * @param AfterSaleModel $model
     * @return string
     */
    public function systemCancelAfterSale(AfterSaleModel $model)
    {
        $data = $this->requestData;
        $data['adminInfo'] = $this->request->adminInfo;
        $res = $model->systemCancelAfterSale($data);
        return returnMsg($res);
    }

    /**
     * @title  帮助用户确认收到换货
     * @param AfterSaleModel $model
     * @return string
     * @throws \Exception
     */
    public function HelpUserConfirmReceiveChangeGoods(AfterSaleModel $model)
    {
        $info = $model->userConfirmReceiveChangeGoods($this->requestData);
        return returnMsg($info);
    }

    /**
     * @title  售后留言
     * @param AfterSaleModel $model
     * @return string
     */
    public function afterSaleMessage(AfterSaleModel $model)
    {
        $data = $this->requestData;
        $data['userType'] = 2;
        $res = $model->afterSaleMessage($data);
        return returnMsg($res);
    }

    /**
     * @title  重新打开退售后
     * @param AfterSaleModel $model
     * @return string
     */
    public function openAfterSaleAgain(AfterSaleModel $model)
    {
        $data = $this->requestData;
        $data['adminInfo'] = $this->request->adminInfo ?? [];
        $res = $model->openAfterSaleAgain($data);
        return returnMsg($res);
    }

    /**
     * @title  帮助用户发起售后申请
     * @param  AfterSaleModel $model
     * @throws \Exception
     * @return string
     */
    public function HelpUserInitiateAfterSale(AfterSaleModel $model)
    {
        $data = $this->requestData;
        $res = $model->initiateAfterSale($data);
        return returnMsg($res);
    }
}