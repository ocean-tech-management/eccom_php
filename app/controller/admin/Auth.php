<?php
// +----------------------------------------------------------------------
// |[ 文档说明: 权限模块Controller]
// +----------------------------------------------------------------------
// | Copyright (c) 2018~2024 http://www.mlhcmk.com All rights reserved.
// +----------------------------------------------------------------------
 

namespace app\controller\admin;


use app\BaseController;
use app\lib\models\AdminUser;
use app\lib\models\AuthGroup;
use app\lib\models\AuthGroupAccess;
use app\lib\models\AuthRule;

class Auth extends BaseController
{
    protected $middleware = [
        'checkAdminToken',
        'checkRule',
        'OperationLog',
    ];

    /**
     * @title  权限列表
     * @param AuthRule $model
     * @return string
     * @throws \Exception
     */
    public function ruleList(AuthRule $model)
    {
        $list = $model->list($this->requestData);
        return returnData($list['list'], $list['pageTotal']);
    }

    /**
     * @title  新增权限规则
     * @param AuthRule $model
     * @return string
     */
    public function ruleCreate(AuthRule $model)
    {
        $msg = $model->DBNew($this->requestData);
        return returnMsg($msg);
    }

    /**
     * @title  编辑权限规则
     * @param AuthRule $model
     * @return string
     */
    public function ruleUpdate(AuthRule $model)
    {
        $msg = $model->DBEdit($this->requestData);
        return returnMsg($msg);
    }

    /**
     * @title  删除权限规则
     * @param AuthRule $model
     * @return string
     */
    public function ruleDelete(AuthRule $model)
    {
        $msg = $model->DBDelete($this->request->param('id'));
        return returnMsg($msg);
    }

    /**
     * @title  用户组列表
     * @param AuthGroup $model
     * @return string
     * @throws \Exception
     */
    public function groupList(AuthGroup $model)
    {
        $list = $model->list($this->requestData);
        return returnData($list['list'], $list['pageTotal']);
    }

    /**
     * @title  用户组详情
     * @param AuthGroup $model
     * @return string
     * @throws \Exception
     */
    public function groupInfo(AuthGroup $model)
    {
        $info = $model->info($this->requestData);
        return returnData($info);
    }

    /**
     * @title  新增用户组
     * @param AuthGroup $model
     * @return string
     */
    public function groupCreate(AuthGroup $model)
    {
        $res = $model->DBNew($this->requestData);
        return returnMsg($res);
    }

    /**
     * @title  编辑用户组
     * @param AuthGroup $model
     * @return string
     */
    public function groupUpdate(AuthGroup $model)
    {
        $res = $model->DBEdit($this->requestData);
        return returnMsg($res);
    }

    /**
     * @title  删除用户组
     * @param AuthGroup $model
     * @return string
     */
    public function groupDelete(AuthGroup $model)
    {
        $res = $model->DBDelete($this->request->param('id'));
        return returnMsg($res);
    }


}