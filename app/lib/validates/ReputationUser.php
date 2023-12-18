<?php
// +----------------------------------------------------------------------
// |[ 文档说明: 商品系统评价官(口碑评价官)模块验证器]
// +----------------------------------------------------------------------



namespace app\lib\validates;


use app\lib\BaseValidate;

class ReputationUser extends BaseValidate
{
    public $errorCode = 500118;

    protected $rule = [
        'uid' => 'require',
        'user_name' => 'require',
        'user_avatarUrl' => 'require',
        'user_code' => 'require',
    ];

    protected $message = [
        'uid.require' => '评价官关联人标识必需',
        'user_name.require' => '评价官姓名必填',
        'user_avatarUrl.require' => '评价官头像不能为空哦',
        'user_code.require' => '缺少唯一标识',
    ];

    protected $scene = [
        'create' => ['user_name', 'user_avatarUrl'],
        'edit' => ['user_code', 'user_name', 'user_avatarUrl'],
    ];
}