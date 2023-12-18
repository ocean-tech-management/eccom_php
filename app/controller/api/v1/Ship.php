<?php
// +----------------------------------------------------------------------
// |[ 文档说明: 物流模块]
// +----------------------------------------------------------------------
// | Copyright (c) 2018~2024 http://www.mlhcmk.com All rights reserved.
// +----------------------------------------------------------------------
 

namespace app\controller\api\v1;


use app\BaseController;
use app\lib\models\ShippingDetail;
use app\lib\models\Order;

class Ship extends BaseController
{
    protected $middleware = [
        'checkApiToken',
    ];

    /**
     * @title  物流详情
     * @param ShippingDetail $model
     * @return string
     * @throws \Exception
     */
    public function info(ShippingDetail $model)
    {
        $list = $model->list($this->requestData);
        return returnData($list['list'], $list['pageTotal']);
    }

    /**
     * @title  订单物流对应的商品
     * @param Order $model
     * @return string
     * @throws \Exception
     */
    public function infoAndGoods(Order $model)
    {
        $list = $model->shippingCodeAndGoodsInfo($this->requestData);
        return returnData($list);
    }
}