<?php
// +----------------------------------------------------------------------
// |[ 文档说明: ]
// +----------------------------------------------------------------------
// | Copyright (c) 2018~2024 http://www.mlhcmk.com All rights reserved.
// +----------------------------------------------------------------------

namespace app\lib\models;


use app\BaseModel;
use think\facade\Request;

class AppUser extends BaseModel
{
    public function uid()
    {
        return $this->hasOne('User', 'openid', 'openid')->bind(['uid']);
    }

    public function userAuthType()
    {
        return $this->hasOne('UserAuthType','tid','tid')->bind(['uid','status']);
    }

}