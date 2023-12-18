<?php
// +----------------------------------------------------------------------
// |[ 文档说明: 权限模块用户组验证器]
// +----------------------------------------------------------------------
// | Copyright (c) 2018~2024 http://www.mlhcmk.com All rights reserved.
// +----------------------------------------------------------------------
 

namespace app\lib\validates;


use app\lib\BaseValidate;
use app\lib\models\AuthGroup as AuthGroupModel;

class AuthGroup extends BaseValidate
{
    public $errorCode = 500113;

    protected $rule = [
        'title' => 'require|checkNameC',
        'rules' => 'require|array',
        'id' => 'require'

    ];

    protected $message = [
        'title.require' => '名称必填',
        'rules.require' => '权限必需',
        'rules.array' => '权限参数类型必须为数组',
        'id.require' => '唯一标识必需',
    ];

    protected $scene = [
        'create' => ['title', 'rules'],
        'edit' => ['title', 'rules', 'id'],
    ];

    public function sceneEdit()
    {
        return $this->remove('title', 'checkNameC')
            ->append('title', 'checkNameE');
    }

    public function checkNameC($value, $rule, $data, $fieldName)
    {
        $exist = (new AuthGroupModel())->where([$fieldName => trim($value), $this->statusField => [1, 2]])->count();
        return empty($exist) ? true : '该用户组名称已存在';
    }

    public function checkNameE($value, $rule, $data, $fieldName)
    {
        $map[] = ['id', '<>', $data['id']];
        $exist = (new AuthGroupModel())->where($map)->where([$fieldName => trim($value), $this->statusField => [1, 2]])->count();
        $msg = !empty($exist) ? '该用户组名称已存在' : true;
        return $msg;
    }


}