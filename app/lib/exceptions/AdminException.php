<?php
// +----------------------------------------------------------------------
// |[ 文档说明: 后台管理模块异常类 异常码:13001]
// +----------------------------------------------------------------------
// | Copyright (c) 2018~2024 http://www.mlhcmk.com All rights reserved.
// +----------------------------------------------------------------------


namespace app\lib\exceptions;


use app\lib\BaseException;

class AdminException extends BaseException
{
    public $errorCode = 13001;
}