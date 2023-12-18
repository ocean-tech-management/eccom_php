<?php
// +----------------------------------------------------------------------
// |[ 文档说明: 品牌模块验证器]
// +----------------------------------------------------------------------
// | Copyright (c) 2018~2024 http://www.mlhcmk.com All rights reserved.
// +----------------------------------------------------------------------


namespace app\lib\validates;


use app\lib\BaseValidate;
use app\lib\models\Brand as BrandModel;

class Brand extends BaseValidate
{
    public $errorCode = 500107;

    protected $rule = [
        'brand_code' => 'require',
        'category_code' => 'require',
        'brand_name' => 'require|checkNameC',

    ];

    protected $message = [
        'brand_code.require' => '品牌编码有误',
        'brand_name.require' => '品牌名称必填',
        'category_code.require' => '品牌分类必需',
    ];

    protected $scene = [
        'create' => ['brand_code', 'brand_name', 'category_code'],
        'edit' => ['brand_code', 'brand_name', 'category_code'],
    ];

    public function sceneEdit()
    {
        return $this->remove('brand_name', 'checkNameC')
            ->append('brand_name', 'checkNameE');
    }

    public function checkNameC($value, $rule, $data, $fieldName)
    {
        $exist = (new BrandModel())->where([$fieldName => trim($value), $this->statusField => [1, 2], 'category_code' => $data['category_code']])->count();
        return empty($exist) ? true : '该分类下该品牌名称已存在';
    }

    public function checkNameE($value, $rule, $data, $fieldName)
    {
//        $map[] = ['brand_code','<>',$data['brand_code']];
        $exist = (new BrandModel())->where([$fieldName => trim($value), $this->statusField => [1, 2], 'category_code' => $data['category_code']])->count();
        return empty($exist) ? true : '该分类下该品牌名称已存在';
    }
}