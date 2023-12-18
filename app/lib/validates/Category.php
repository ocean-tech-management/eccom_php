<?php
// +----------------------------------------------------------------------
// |[ 文档说明: 分类模块验证器]
// +----------------------------------------------------------------------
// | Copyright (c) 2018~2024 http://www.mlhcmk.com All rights reserved.
// +----------------------------------------------------------------------


namespace app\lib\validates;


use app\lib\BaseValidate;
use app\lib\models\Category as CategoryModel;

class Category extends BaseValidate
{
    public $errorCode = 500104;

    protected $rule = [
        'code' => 'require',
        'name' => 'require|checkNameC'
    ];

    protected $message = [
        'code.require' => '分类编码有误',
        'name.require' => '分类名称必填',
    ];

    protected $scene = [
        'create' => ['code', 'name'],
        'edit' => ['code', 'name'],
    ];

    public function sceneEdit()
    {
        return $this->remove('name', 'checkNameC')
            ->append('name', 'checkNameE');
    }

    public function checkNameC($value, $rule, $data, $fieldName)
    {
        $exist = (new CategoryModel())->where([$fieldName => trim($value), $this->statusField => [1, 2]])->count();
        return empty($exist) ? true : '该分类名称已存在';
    }

    public function checkNameE($value, $rule, $data, $fieldName)
    {
        $map[] = ['code', '<>', $data['code']];
        $exist = (new CategoryModel())->where($map)->where([$fieldName => trim($value), $this->statusField => [1, 2]])->count();
        return empty($exist) ? true : '该分类名称已存在';
    }
}