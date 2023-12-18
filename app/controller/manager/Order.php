<?php
// +----------------------------------------------------------------------
// |[ 文档说明: 商家移动端-订单列表]
// +----------------------------------------------------------------------
// | Copyright (c) 2018~2024 http://www.mlhcmk.com All rights reserved.
// +----------------------------------------------------------------------


namespace app\controller\manager;


use app\BaseController;
use app\lib\models\Order as OrderModel;

class Order extends BaseController
{
    protected $middleware = [
        'checkAdminToken',
        'checkRule',
        'OperationLog',
    ];

    /**
     * @title  订单列表
     * @param OrderModel $model
     * @return string
     * @throws \Exception
     */
    public function list(OrderModel $model)
    {
        $data = $this->requestData;
        $data['needDivideDetail'] = true;
        $data['needGoods'] = true;
        $list = $model->list($data);
        return returnData($list['list'], $list['pageTotal']);
    }

    /**
     * @title  订单详情
     * @param OrderModel $model
     * @return string
     * @throws \Exception
     */
    public function info(OrderModel $model)
    {
        $info = $model->info($this->requestData);
        return returnData($info);
    }
}