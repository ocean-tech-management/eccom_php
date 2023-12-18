<?php
// +----------------------------------------------------------------------
// |[ 文档说明: 商品SPU验证器]
// +----------------------------------------------------------------------
// | Copyright (c) 2018~2024 http://www.mlhcmk.com All rights reserved.
// +----------------------------------------------------------------------


namespace app\lib\validates;


use app\lib\BaseValidate;

class GoodsSpu extends BaseValidate
{
    public $errorCode = 500105;

    protected $rule = [
        'goods_sn' => 'require',
        'main_image' => 'require',
        'title' => 'require',
        'category_code' => 'require',
        //'attribute_list' => 'require',
        //'desc' => 'require',
        'belong' => 'require|number',
    ];

    protected $message = [
        'goods_sn.require' => '商品编号必需',
        'main_image.require' => '商品主图必需',
        'title.require' => '商品标题必需',
        'category_code.require' => '商品分类必需',
        'desc.require' => '商品详情必需',
        'belong.require' => '归属平台必需',
    ];

    protected $scene = [
        'create' => ['main_image', 'title', 'category_code', 'attribute_list', 'belong'],
        'edit' => ['goods_sn', 'main_image', 'title', 'category_code', 'attribute_list', 'belong'],
    ];
}