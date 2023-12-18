<?php
// +----------------------------------------------------------------------
// |[ 文档说明: 商品属性Key模块验证器]
// +----------------------------------------------------------------------
// | Copyright (c) 2018~2024 http://www.mlhcmk.com All rights reserved.
// +----------------------------------------------------------------------


namespace app\lib\validates;


use app\lib\BaseValidate;
use app\lib\models\AttributeKey as AttributeKeyModel;

class AttributeKey extends BaseValidate
{
    public $errorCode = 500108;

    protected $rule = [
        'category_code' => 'require',
        'attribute_code' => 'require',
        'attribute_name' => 'require',
        'attr_sn' => 'checkSnC',

    ];

    protected $message = [
        'attribute_code.require' => '属性编码有误',
        'attribute_name.require' => '属性名称必填',
        'category_code.require' => '属性分类必需',
    ];

    protected $scene = [
        'create' => ['attribute_code', 'attribute_name', 'category_code', 'attr_sn'],
        'edit' => ['attribute_code', 'attribute_name', 'category_code', 'attr_sn'],
    ];

    public function sceneEdit()
    {
//        return $this->remove('attribute_name','checkNameC')
//                ->append('attribute_name','checkNameE');
        return $this->remove('attr_sn', 'checkSnC')
            ->append('attr_sn', 'checkSnE');
    }

    public function checkNameC($value, $rule, $data, $fieldName)
    {
        $exist = (new AttributeKeyModel())->where([$fieldName => trim($value), $this->statusField => [1, 2], 'category_code' => $data['category_code']])->count();
        return empty($exist) ? true : '该分类下该属性名称已存在';
    }

    public function checkNameE($value, $rule, $data, $fieldName)
    {
        $map[] = ['attribute_code', '<>', $data['attribute_code']];
        $exist = (new AttributeKeyModel())->where($map)->where([$fieldName => trim($value), $this->statusField => [1, 2], 'category_code' => $data['category_code']])->count();
        return empty($exist) ? true : '该分类下该属性名称已存在';
    }

    public function checkSnC($value, $rule, $data, $fieldName)
    {
        $exist = (new AttributeKeyModel())->where([$fieldName => trim($value), $this->statusField => [1, 2], 'category_code' => $data['category_code'], 'attribute_name' => trim($data['attribute_name'])])->count();
        return empty($exist) ? true : '该分类下该属性名称的编码已存在';
    }

    public function checkSnE($value, $rule, $data, $fieldName)
    {
        $map[] = ['attribute_code', '<>', $data['attribute_code']];
        $exist = (new AttributeKeyModel())->where($map)->where([$fieldName => trim($value), $this->statusField => [1, 2], 'category_code' => $data['category_code'], 'attribute_name' => trim($data['attribute_name'])])->count();
        return empty($exist) ? true : '该分类下该属性名称的编码已存在';
    }
}