<?php
// +----------------------------------------------------------------------
// |[ 文档说明: 发货模块订单商品表]
// +----------------------------------------------------------------------
// | Copyright (c) 2018~2024 http://www.mlhcmk.com All rights reserved.
// +----------------------------------------------------------------------


namespace app\lib\models;


use app\BaseModel;

class ShipOrderGoods extends BaseModel
{

    public function allGoods()
    {
        return $this->hasOne('GoodsSpu', 'goods_sn', 'goods_sn');
    }
}