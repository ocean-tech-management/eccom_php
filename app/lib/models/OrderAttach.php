<?php
// +----------------------------------------------------------------------
// |[ 文档说明: 订单附加条件Model]
// +----------------------------------------------------------------------
// | Copyright (c) 2018~2024 http://www.mlhcmk.com All rights reserved.
// +----------------------------------------------------------------------


namespace app\lib\models;


use app\BaseModel;
use app\lib\validates\Attach;

class OrderAttach extends BaseModel
{
    protected $validateFields = ['order_sn'];

    /**
     * @title  新增
     * @param array $data
     * @return mixed
     */
    public function DBNew(array $data)
    {
        (new Attach())->goCheck($data, 'create');
        return $this->baseCreate($data);
    }
}