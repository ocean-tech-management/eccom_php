<?php
// +----------------------------------------------------------------------
// |[ 文档说明: ]
// +----------------------------------------------------------------------
// | Copyright (c) 2018~2024 http://www.mlhcmk.com All rights reserved.
// +----------------------------------------------------------------------
 

namespace app\lib\models;


use app\BaseModel;
use app\lib\exceptions\AuthException;
use app\lib\services\Auth;
use Exception;
use think\facade\Db;

class AuthGroupAccess extends BaseModel
{
    protected $validateFields = ['admin_id', 'group_id'];

    /**
     * @title  用户权限列表
     * @param array $sear
     * @return array
     * @throws \Exception
     */
    public function userAuthList(array $sear)
    {
        $adminId = $sear['admin_id'];

        $map[] = ['admin_id', '=', $adminId];
        $map[] = ['status', '=', 1];

        $info = $this->with(['group', 'admin'])->where($map)->field('admin_id,group_id')->findOrEmpty()->toArray();

        if (empty($info) || ($info['admin_type'] != 88 && (empty($info['rules'])))) {
            throw new AuthException(['errorCode' => 2200101]);
        }
        //超管拥有全部权限
        $authRule = [];
        if (!empty($info)) {
            $mapR[] = ['status', '=', 1];
            if ($info['admin_type'] != 88) {
                $rules = explode(',', $info['rules']);
                $mapR[] = ['id', 'in', $rules];
            }
            $authRule = AuthRule::where($mapR)->withoutField('update_time')->order('level asc,sort asc,create_time desc')->select()->toArray();
            if (empty($authRule)) {
                throw new AuthException();
            }

            if (empty($sear['newRawData'])) {
                $authRule = (new Auth())->getGenreTree($authRule);
            }

        }

        return $authRule;
    }

    /**
     * @title  编辑用户的用户组
     * @param array $data
     * @return AuthGroupAccess
     */
    public function updateUserGroup(array $data)
    {
        return $this->validate()->updateOrCreate(['admin_id' => $data['admin_id'] ?? null], $data);
    }

    public function group()
    {
        return $this->hasOne('AuthGroup', 'id', 'group_id')->where(['status' => 1])->bind(['group_title' => 'title', 'rules']);
    }

    public function admin()
    {
        return $this->hasOne('AdminUser', 'id', 'admin_id')->where(['status' => 1])->bind(['admin_type' => 'type']);
    }

}