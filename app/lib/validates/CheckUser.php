<?php
// +----------------------------------------------------------------------
// |[ 文档说明: ]
// +----------------------------------------------------------------------
// | Copyright (c) 2018~2024 http://www.mlhcmk.com All rights reserved.
// +----------------------------------------------------------------------


namespace app\lib\validates;


use app\lib\BaseValidate;
use app\lib\models\User;

class CheckUser extends BaseValidate
{
    public $errorCode = 500101;

    protected $rule = [
        'uid' => 'require|checkLicitUser',
    ];

    protected $message = [
        'uid.require' => '用户异常',
    ];

    protected function checkLicitUser($value, $rule, $data, $fieldName)
    {
        $aUser = User::where([$fieldName => $value, 'status' => 1])->count();
        return !empty($aUser) ? true : '用户不存在';
    }
}