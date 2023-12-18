<?php
// +----------------------------------------------------------------------
// |[ 文档说明: ]
// +----------------------------------------------------------------------
// | Copyright (c) 2018~2024 http://www.mlhcmk.com All rights reserved.
// +----------------------------------------------------------------------
 

namespace app\controller\api\v1;


use app\BaseController;
use app\lib\exceptions\OrderException;
use app\lib\exceptions\ServiceException;
use app\lib\models\AfterSale as AfterSaleModel;
use app\lib\models\AfterSaleDetail;
use app\lib\models\ShippingCompany;

class AfterSale extends BaseController
{
    protected $middleware = [
        'checkApiToken',
    ];

    /**
     * @title  用户售后列表
     * @param AfterSaleModel $model
     * @return string
     * @throws \Exception
     */
    public function list(AfterSaleModel $model)
    {
        $list = $model->list($this->requestData);
        return returnData($list['list'], $list['pageTotal']);
    }


    /**
     * @title  售后详情
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
     * @title  用户发起售后申请
     * @param AfterSaleModel $model
     * @return string
     * @throws \Exception
     */
    public function initiateAfterSale(AfterSaleModel $model)
    {
        $data = $this->requestData;
        $data['uid'] = $this->request->tokenUid ?? null;
        $res = $model->initiateAfterSale($data);
        $returnData['afterSaleApplyStatus'] = judge($res);
        $returnData['afterSaleApplyStatus'] = false;
        $returnData['templateId'] = [config('system.templateId.afterSaleRemark')];
        return returnData($returnData);
    }

    /**
     * @title  用户取消售后申请
     * @param AfterSaleModel $model
     * @return string
     * @throws \Exception
     */
    public function cancel(AfterSaleModel $model)
    {
        $res = $model->userCancelAfterSale($this->requestData);
        return returnMsg($res);
    }

    /**
     * @title  用户填写退/换货物流
     * @param AfterSaleModel $model
     * @return string
     */
    public function userFillInShip(AfterSaleModel $model)
    {
        $res = $model->userFillInShip($this->requestData);
        return returnMsg($res);
    }


    /**
     * @title  用户确认收到换货
     * @param AfterSaleModel $model
     * @return string
     * @throws \Exception
     */
    public function userConfirmReceiveChangeGoods(AfterSaleModel $model)
    {
        $info = $model->userConfirmReceiveChangeGoods($this->requestData);
        return returnData($info);
    }

    /**
     * @title  快递100快递公司编码
     * @param ShippingCompany $model
     * @return string
     * @throws \Exception
     */
    public function companyList(ShippingCompany $model)
    {
        $list = $model->list($this->requestData);
        return returnData($list['list'], $list['pageTotal']);
    }

    /**
     * @title  售后消息详情
     * @param AfterSaleModel $model
     * @return string
     * @throws \Exception
     */
    public function afterSaleRemarkInfo(AfterSaleModel $model)
    {
        $info = $model->afterSaleMsgInfo($this->requestData);
        return returnData($info);
    }

    /**
     * @title  用户回复售后信息
     * @param AfterSaleModel $model
     * @return string
     */
    public function submitAfterSaleMsg(AfterSaleModel $model)
    {
        $data = $this->requestData;
        $data['userType'] = 1;
        $res = $model->afterSaleMessage($this->requestData);
        return returnData($res);
    }

    /**
     * @title  用户待回复售后信息列表
     * @param AfterSaleDetail $model
     * @return string
     * @throws \Exception
     */
    public function afterSaleMsgList(AfterSaleDetail $model)
    {
        $res = $model->msgList($this->requestData);
        return returnData($res);
    }


}