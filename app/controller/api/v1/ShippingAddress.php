<?php
// +----------------------------------------------------------------------
// |[ 文档说明: 收货地址模块Controller]
// +----------------------------------------------------------------------
// | Copyright (c) 2018~2024 http://www.mlhcmk.com All rights reserved.
// +----------------------------------------------------------------------


namespace app\controller\api\v1;


use app\BaseController;
use app\lib\models\ShippingAddress as ShippingAddressModel;

class ShippingAddress extends BaseController
{
    protected $middleware = [
        'checkUser',
        'checkApiToken'
    ];

    /**
     * @title  收货地址列表
     * @param ShippingAddressModel $model
     * @return string
     * @throws \Exception
     */
    public function list(ShippingAddressModel $model)
    {
        $list = $model->list($this->requestData);
        return returnData($list['list'], $list['pageTotal']);
    }

    /**
     * @title  收货地址详情
     * @param ShippingAddressModel $model
     * @return string
     */
    public function info(ShippingAddressModel $model)
    {
        $info = $model->info($this->requestData);
        return returnData($info);
    }

    /**
     * @title  新增收货地址
     * @param ShippingAddressModel $model
     * @return string
     */
    public function create(ShippingAddressModel $model)
    {
        $data = $this->requestData;
        $data['uid'] = $data['uid'] ?? $this->request->uid;
        $res = $model->new($data);
        return returnMsg($res);
    }

    /**
     * @title  编辑收货地址
     * @param ShippingAddressModel $model
     * @return string
     */
    public function update(ShippingAddressModel $model)
    {
        $data = $this->requestData;
        $data['uid'] = $data['uid'] ?? $this->request->uid;
        $res = $model->edit($data);
        return returnMsg($res);
    }

    /**
     * @title  删除收货地址
     * @param ShippingAddressModel $model
     * @return string
     */
    public function delete(ShippingAddressModel $model)
    {
        $res = $model->del($this->request->param('id'));
        return returnMsg($res);
    }
}