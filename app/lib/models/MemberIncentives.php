<?php
// +----------------------------------------------------------------------
// |[ 文档说明: 会员激励制度模块Model]
// +----------------------------------------------------------------------
// | Copyright (c) 2018~2024 http://www.mlhcmk.com All rights reserved.
// +----------------------------------------------------------------------


namespace app\lib\models;


use app\BaseModel;

class MemberIncentives extends BaseModel
{
    protected $validateFields = ['level'];
}