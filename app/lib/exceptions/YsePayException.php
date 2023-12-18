<?php
// +----------------------------------------------------------------------
// |[ 文档说明: 银盛支付模块异常类 异常编码:23001]
// +----------------------------------------------------------------------
// | Copyright (c) 2018~2024 http://www.mlhcmk.com All rights reserved.
// +----------------------------------------------------------------------
 

namespace app\lib\exceptions;


use app\lib\BaseException;

class YsePayException extends BaseException
{
    public $errorCode = 32001;
}