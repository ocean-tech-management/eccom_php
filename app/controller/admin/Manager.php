<?php
// +----------------------------------------------------------------------
// |[ 文档说明: 管理员模块Controller]
// +----------------------------------------------------------------------
// | Copyright (c) 2018~2024 http://www.mlhcmk.com All rights reserved.
// +----------------------------------------------------------------------
 

namespace app\controller\admin;


use app\BaseController;
use app\lib\models\AdminUser;
use app\lib\models\AuthGroupAccess;

class Manager extends BaseController
{
    protected $middleware = [
        'checkAdminToken',
        'checkRule',
        'OperationLog',
    ];

    /**
     * @title  编辑管理员用户组
     * @param AuthGroupAccess $model
     * @return string
     */
    public function updateUserGroup(AuthGroupAccess $model)
    {
        $res = $model->updateUserGroup($this->requestData);
        return returnMsg($res);
    }

    /**
     * @title  管理员拥有的权限列表
     * @param AuthGroupAccess $model
     * @return string
     * @throws \Exception
     */
    public function userAuthList(AuthGroupAccess $model)
    {
        $adminId = $this->request->adminId;
        $data['admin_id'] = $adminId;
        $list = $model->userAuthList($data);
        return returnData($list);
    }

    /**
     * @title  管理员列表
     * @param AdminUser $model
     * @return string
     * @throws \Exception
     */
    public function adminUserList(AdminUser $model)
    {
        $list = $model->list($this->requestData);
        return returnData($list['list'], $list['pageTotal']);
    }

    /**
     * @title  创建管理员
     * @param AdminUser $model
     * @return string
     */
    public function adminUserCreate(AdminUser $model)
    {
        $res = $model->DBNew($this->requestData);
        return returnMsg($res);
    }

    /**
     * @title  编辑管理员
     * @param AdminUser $model
     * @return string
     */
    public function adminUserUpdate(AdminUser $model)
    {
        $data = $this->requestData;
        $data['scene'] = 'edit';
        $data['nowAdminId'] = $this->request->adminId;
        $res = $model->DBEdit($data);
        return returnMsg($res);
    }

    /**
     * @title  修改密码
     * @param AdminUser $model
     * @return string
     */
    public function updateAdminUserPwd(AdminUser $model)
    {
        $data = $this->requestData;
        $data['scene'] = 'pwd';
        $data['nowAdminId'] = $this->request->adminId;
        $res = $model->DBEdit($data);
        return returnMsg($res);
    }

    /**
     * @title  修改自己密码
     * @param AdminUser $model
     * @return string
     */
    public function updateMyselfPwd(AdminUser $model)
    {
        $data = $this->requestData;
        $data['scene'] = 'pwd';
        $data['nowAdminId'] = $this->request->adminId;
        $res = $model->DBEdit($data);
        return returnMsg($res);
    }

    /**
     * @title  删除管理员
     * @param AdminUser $model
     * @return string
     */
    public function adminUserDelete(AdminUser $model)
    {
        $res = $model->DBDelete($this->request->param('id'));
        return returnMsg($res);
    }

    /**
     * @title  管理员启/禁用
     * @param AdminUser $model
     * @return string
     */
    public function adminUserStatus(AdminUser $model)
    {
        $res = $model->upOrDown($this->requestData);
        return returnMsg($res);
    }
}