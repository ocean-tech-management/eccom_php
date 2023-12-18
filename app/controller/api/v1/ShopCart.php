<?php
// +----------------------------------------------------------------------
// |[ 文档说明: ]
// +----------------------------------------------------------------------
// | Copyright (c) 2018~2024 http://www.mlhcmk.com All rights reserved.
// +----------------------------------------------------------------------
 

namespace app\controller\api\v1;


use app\BaseController;
use app\lib\models\ShopCart as ShopCartModel;

class ShopCart extends BaseController
{
    /**
     * @title  购物车列表
     * @param ShopCartModel $model
     * @return string
     * @throws \Exception
     */
    public function list(ShopCartModel $model)
    {
        $data = $this->requestData;
        $data['cache'] = 'userShopCart' . $data['uid'];
        $data['cache_expire'] = 600;
        $list = $model->list($data);
        return returnData($list['list'], $list['pageTotal']);
    }

    /**
     * @title  添加购物车记录
     * @param ShopCartModel $model
     * @return string
     */
    public function create(ShopCartModel $model)
    {
        $res = $model->DBNew($this->requestData);
        return returnMsg($res);
    }

    /**
     * @title  修改购物车记录
     * @param ShopCartModel $model
     * @return string
     */
    public function update(ShopCartModel $model)
    {
        $res = $model->DBEdit($this->requestData);
        return returnMsg($res);
    }

    /**
     * @title  删除购物车记录
     * @param ShopCartModel $model
     * @return string
     * @throws \Exception
     */
    public function delete(ShopCartModel $model)
    {
        $res = $model->DBDelete($this->request->param('cart_sn'));
        return returnMsg($res);
    }
}