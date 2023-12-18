<?php
// +----------------------------------------------------------------------
// |[ 文档说明: 权限模块验证器]
// +----------------------------------------------------------------------
// | Copyright (c) 2018~2024 http://www.mlhcmk.com All rights reserved.
// +----------------------------------------------------------------------
 

namespace app\lib\validates;


use app\lib\BaseValidate;
use app\lib\models\AuthRule;

class Auth extends BaseValidate
{
    public $errorCode = 500112;

    protected $rule = [
        'title' => 'require',
        'name' => 'require|checkNameC',
        'condition' => 'require|checkConditionC',
        'type' => 'number',
        'level' => 'require|number',
        'id' => 'require'

    ];

    protected $message = [
        'title.require' => '中文名称必填',
        'name.require' => '规则唯一标识必填',
        'condition.require' => '规则必需',
        'type.require' => '类型必需',
        'type.number' => '类型必须为数字',
        'level.require' => '权限等级必需',
        'level.number' => '权限等级必须为数字',
        'id.require' => '唯一标识必需',
    ];

    protected $scene = [
        'create' => ['title', 'name', 'condition', 'type', 'level'],
        'edit' => ['title', 'name', 'condition', 'type', 'level', 'id'],
    ];

    public function sceneEdit()
    {
        return $this->remove('name', 'checkNameC')
            ->append('name', 'checkNameE')
            ->remove('condition', 'checkConditionC')
            ->append('condition', 'checkConditionE');
    }

    public function checkNameC($value, $rule, $data, $fieldName)
    {
        $exist = (new AuthRule())->where([$fieldName => trim($value), $this->statusField => [1, 2]])->count();
        return empty($exist) ? true : '该权限名称已存在';
    }

    public function checkNameE($value, $rule, $data, $fieldName)
    {
        $map[] = ['id', '<>', $data['id']];
        $exist = (new AuthRule())->where($map)->where([$fieldName => trim($value), $this->statusField => [1, 2]])->count();
        return empty($exist) ? true : '该权限名称已存在';
    }

    public function checkConditionC($value, $rule, $data, $fieldName)
    {
        $exist = (new AuthRule())->where([$fieldName => trim($value), $this->statusField => [1, 2]])->count();
        return empty($exist) ? true : '该权限规则已存在';
    }

    public function checkConditionE($value, $rule, $data, $fieldName)
    {
        $map[] = ['id', '<>', $data['id']];
        $exist = (new AuthRule())->where($map)->where([$fieldName => trim($value), $this->statusField => [1, 2]])->count();
        return empty($exist) ? true : '该权限规则已存在';
    }

}