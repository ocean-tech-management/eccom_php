<?php
// +----------------------------------------------------------------------
// |[ 文档说明: 商品属性Value模块验证器]
// +----------------------------------------------------------------------
// | Copyright (c) 2018~2024 http://www.mlhcmk.com All rights reserved.
// +----------------------------------------------------------------------


namespace app\lib\validates;


use app\lib\BaseValidate;
use app\lib\models\AttributeValue as AttributeValueModel;

class AttributeValue extends BaseValidate
{
    public $errorCode = 500109;

    protected $rule = [
        'id' => 'require',
        'attribute_code' => 'require',
        'attribute_value' => 'require|checkNameC',

    ];

    protected $message = [
        'id.require' => '缺少唯一标识',
        'attribute_code.require' => '属性编码有误',
        'attribute_value.require' => '属性值必填',
    ];

    protected $scene = [
        'create' => ['attribute_code', 'attribute_value'],
        'edit' => ['id', 'attribute_code', 'attribute_value'],
    ];

    public function sceneEdit()
    {
        return $this->remove('attribute_value', 'checkNameC')
            ->append('attribute_value', 'checkNameE');
    }

    public function checkNameC($value, $rule, $data, $fieldName)
    {
        if (mb_strlen(trim($value)) >= 50) {
            return '属性值超出可允许长度!';
        }
        $check['category_code'] = $data['category_code'];
        $check[$fieldName] = trim($value);
        $check['attr_sn'] = $data['attr_sn'] ?? null;
        $exist = (new AttributeValueModel())->checkUnique($check);
        return empty($exist) ? true : $value . ' 该属性值已存在';
    }

    public function checkNameE($value, $rule, $data, $fieldName)
    {
        if (mb_strlen(trim($value)) >= 50) {
            return '属性值超出可允许长度!';
        }
        $check['category_code'] = $data['category_code'];
        $check[$fieldName] = trim($value);
        $check['id'] = $data['id'];
        $check['attr_sn'] = $data['attr_sn'] ?? null;
        $exist = (new AttributeValueModel())->checkUnique($check);
        return empty($exist) ? true : $value . ' 该属性值已存在';
    }
}