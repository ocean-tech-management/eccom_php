<?php
// +----------------------------------------------------------------------
// |[ 文档说明: 数据分析模块Controller]
// +----------------------------------------------------------------------
// | Copyright (c) 2018~2024 http://www.mlhcmk.com All rights reserved.
// +----------------------------------------------------------------------


namespace app\controller\admin;


use app\BaseController;
use app\lib\models\UserAuthClear as UserAuthClearModel;

class UserAuthClear extends BaseController
{
    protected $middleware = [
        'checkAdminToken',
        'checkRule',
        'OperationLog',
    ];

    /**
     * @title  清除数据列表
     * @param UserAuthClear $service
     * @return string
     */
    public function list(UserAuthClearModel $model)
    {
        $list = $model->list($this->requestData);
        return returnData($list['list'], $list['pageTotal']);
    }

    /**
     * @title  清除数据列表
     * @param UserAuthClear $service
     * @return string
     */
    public function create(UserAuthClearModel $model)
    {
        $res = $model->new($this->requestData);
        return returnMsg($res);
    }
}