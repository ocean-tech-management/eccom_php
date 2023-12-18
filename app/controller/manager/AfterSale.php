<?php
// +----------------------------------------------------------------------
// |[ 文档说明: ]
// +----------------------------------------------------------------------
// | Copyright (c) 2018~2024 http://www.mlhcmk.com All rights reserved.
// +----------------------------------------------------------------------


namespace app\controller\manager;


use app\BaseController;
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
        return returnData($list['list'], $list['pageTotal']);
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
}