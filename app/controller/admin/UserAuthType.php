<?php
// +----------------------------------------------------------------------
// |[ 文档说明: 数据分析模块Controller]
// +----------------------------------------------------------------------
// | Copyright (c) 2018~2024 http://www.mlhcmk.com All rights reserved.
// +----------------------------------------------------------------------


namespace app\controller\admin;


use app\BaseController;
use app\lib\models\UserAuthType as UserAuthTypeModel;

class UserAuthType extends BaseController
{
    protected $middleware = [
        'checkAdminToken',
        'checkRule',
        'OperationLog',
    ];

    /**
     * @title  登录方式数据
     * @param UserAuthType $service
     * @return string
     */
    public function list(UserAuthTypeModel $model)
    {
        $list = $model->list($this->requestData);
        return returnData($list['list'], $list['pageTotal']);
    }

    /**
     * @description: 授权信息总数据
     * @param {UserAuthTypeModel} $model
     * @return {*}
     */    
    public function allList(UserAuthTypeModel $model)
    {
        $list = $model->allList($this->requestData);
        return returnData($list['list'], $list['pageTotal']);
    }
}