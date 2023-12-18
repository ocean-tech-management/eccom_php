<?php
// +----------------------------------------------------------------------
// |[ 文档说明: 验证码模块异常类 异常码:6001]
// +----------------------------------------------------------------------
// | Copyright (c) 2018~2024 http://www.mlhcmk.com All rights reserved.
// +----------------------------------------------------------------------


namespace app\lib\exceptions;


use app\lib\BaseException;

class CodeException extends BaseException
{
    public $errorCode = 6001;
    public $msg = '验证码有误';
}