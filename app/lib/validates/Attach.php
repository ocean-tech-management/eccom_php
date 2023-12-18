<?php
// +----------------------------------------------------------------------
// |[ 文档说明: 订单附加条件验证器]
// +----------------------------------------------------------------------
// | Copyright (c) 2018~2024 http://www.mlhcmk.com All rights reserved.
// +----------------------------------------------------------------------


namespace app\lib\validates;


use app\lib\BaseValidate;

class Attach extends BaseValidate
{
    public $errorCode = 500116;

    protected $rule = [
        'order_sn' => 'require',
        'id_card' => 'idCard',
        'real_name' => 'chsAlpha',

    ];

    protected $message = [
        'order_sn.require' => '订单标号有误',
        'id_card.idCard' => '身份证号码有误',
        'real_name.chsAlpha' => '真实姓名仅支持中文和英文',
    ];

    protected $scene = [
        'create' => ['order_sn', 'id_card', 'real_name'],
        'edit' => ['order_sn', 'id_card', 'real_name'],
    ];

}