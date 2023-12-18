<?php
// +----------------------------------------------------------------------
// |[ 文档说明: 授权书模块Controller]
// +----------------------------------------------------------------------
// | Copyright (c) 2018~2024 http://www.mlhcmk.com All rights reserved.
// +----------------------------------------------------------------------


namespace app\controller\api\v1;


use app\BaseController;
use app\lib\models\Warrant as WarrantModel;
use app\lib\models\User as UserModel;

class Warrant extends BaseController
{
    /**
     * @title  授权书模版列表
     * @param WarrantModel $model
     * @return string
     * @throws \Exception
     */
    public function list(WarrantModel $model)
    {
        $list = $model->cList($this->requestData);
        return returnData($list['list']);
    }

    /**
     * @title  用户信息
     * @param UserModel $model
     * @return string
     */
    public function userInfo(UserModel $model)
    {
        $info = $model->getUserInfoForWarrant($this->request->param('uid'));
        return returnData($info);
    }
}