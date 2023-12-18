<?php
// +----------------------------------------------------------------------
// |[ 文档说明: 管理员模块验证器]
// +----------------------------------------------------------------------
// | Copyright (c) 2018~2024 http://www.mlhcmk.com All rights reserved.
// +----------------------------------------------------------------------
 

namespace app\lib\validates;


use app\lib\BaseValidate;
use app\lib\models\UserAuthClear as UserAuthClearModel;

class UserAuthClear extends BaseValidate
{
    public $errorCode = 500120;

    protected $rule = [
        'id' => 'require',
        'adminId' => 'require',
        'image_url' => 'require',
        'desc' => 'require',
    ];

    protected $message = [
        'id.require' => '授权表id不能为空',
        'adminId.require' => '管理员id不能为空',
        'image_url.require' => '证明图片不能为空',
        'desc.require' => '清除备注说明不能为空',
    ];

    // protected $scene = [
    //     'create' => ['id', 'adminId', 'image_url', 'desc'],
    // ];
}