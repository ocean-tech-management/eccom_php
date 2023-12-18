<?php
// +----------------------------------------------------------------------
// |[ 文档说明: 商户移动端-登录模块]
// +----------------------------------------------------------------------
// | Copyright (c) 2018~2024 http://www.mlhcmk.com All rights reserved.
// +----------------------------------------------------------------------


namespace app\controller\manager;


use app\BaseController;
use app\lib\models\AdminUser;

class Login extends BaseController
{
    /**
     * @title  商户移动端管理员登录
     * @param AdminUser $model
     * @return string
     */
    public function login(AdminUser $model)
    {
        $data = $this->requestData;
        $data['login_way'] = 2;
        $res = $model->login($this->requestData);
        return returnData($res);
    }
}