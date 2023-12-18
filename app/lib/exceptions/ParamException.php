<?php
// +----------------------------------------------------------------------
// |[ 文档说明: 参数模块异常类 异常码:5001]
// +----------------------------------------------------------------------
// | Copyright (c) 2018~2024 http://www.mlhcmk.com All rights reserved.
// +----------------------------------------------------------------------


namespace app\lib\exceptions;


use app\lib\BaseException;

class ParamException extends BaseException
{
    public $errorCode = 5001;
    public $msg = '参数格式有误';
}