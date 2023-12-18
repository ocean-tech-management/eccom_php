<?php
// +----------------------------------------------------------------------
// |[ 文档说明: 运费模版模块验证器]
// +----------------------------------------------------------------------
// | Copyright (c) 2018~2024 http://www.mlhcmk.com All rights reserved.
// +----------------------------------------------------------------------


namespace app\lib\validates;


use app\lib\BaseValidate;
use app\lib\models\Brand as BrandModel;

class Postage extends BaseValidate
{
    public $errorCode = 500115;

    protected $rule = [
        'code' => 'require',
        'title' => 'require',
        'ship_province' => 'require',
        'ship_city' => 'require',
//        'ship_area' => 'require',
//        'ship_time' => 'require|number',
        'unit' => 'require',

    ];

    protected $message = [
        'code.require' => '运费模版编码有误',
        'title.require' => '运费模板名称必填',
        'ship_province.require' => '宝贝地址省份必填',
        'ship_city.require' => '宝贝地址城市必填',
        'ship_area.require' => '宝贝地址区/镇必填',
        'ship_time.require' => '发货时间必填',
        'ship_time.number' => '发货时间必须为正整数',
        'unit.number' => '计件方式必选',
    ];

    protected $scene = [
        'create' => ['title', 'ship_province', 'ship_city', 'unit'],
        'edit' => ['code', 'title', 'ship_province', 'ship_city', 'unit'],
    ];

}