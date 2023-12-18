<?php
// +----------------------------------------------------------------------
// |[ 文档说明: 管理员模块验证器]
// +----------------------------------------------------------------------
// | Copyright (c) 2018~2024 http://www.mlhcmk.com All rights reserved.
// +----------------------------------------------------------------------
 

namespace app\lib\validates;


use app\lib\BaseValidate;
use app\lib\models\AdminUser as AdminUserModel;

class AdminUser extends BaseValidate
{
    public $errorCode = 500114;

    protected $rule = [
        'name' => 'require',
        'account' => 'require|checkAccountC',
        'pwd' => 'require|alphaDash',
        'rePwd' => 'requireWith:pwd|confirm:pwd',
        'email' => 'email',
        'id' => 'require',
        'type' => 'require|number|checkType'

    ];

    protected $message = [
        'name.require' => '名称必填',
        'account.require' => '帐号必填',
        'pwd.require' => '密码必填',
        'pwd.alphaDash' => '密码仅允许包含字母和数字,下划线 _ 及破折号 - ',
        'rePwd.requireWith' => '请确认密码',
        'rePwd.confirm' => '两次密码输入不一致',
        'email.email' => '邮箱格式有误',
        'id.require' => '唯一标识必需',
        'type.require' => '管理员类型必选',
        'type.number' => '管理员类型为数字',
    ];

    protected $scene = [
        'create' => ['name', 'account', 'pwd', 'rePwd', 'type'],
        'edit' => ['name', 'account', 'pwd', 'rePwd', 'id', 'type'],
        'pwd' => ['id', 'pwd', 'rePwd'],
    ];

    public function sceneEdit()
    {
        return $this->remove('account', 'checkAccountC')
            ->append('account', 'checkAccountE')
            ->remove('pwd', 'require')
            ->remove('rePwd', 'require');
    }

    public function checkAccountC($value, $rule, $data, $fieldName)
    {
        $exist = (new AdminUserModel())->where([$fieldName => trim($value), $this->statusField => [1, 2]])->count();
        return empty($exist) ? true : '该帐号已存在';
    }

    public function checkAccountE($value, $rule, $data, $fieldName)
    {
        $map[] = ['id', '<>', $data['id']];
        $exist = (new AdminUserModel())->where($map)->where([$fieldName => trim($value), $this->statusField => [1, 2]])->count();
        $msg = !empty($exist) ? '该帐号已存在' : true;
        return $msg;
    }

    public function checkType($value, $rule, $data, $fieldName)
    {
        //供应商模式下必须供应商编码
        if ($value == 3) {
            if (empty($data['supplier_code'])) {
                return '请选择供应商';
            }
        }
        return true;
    }


}