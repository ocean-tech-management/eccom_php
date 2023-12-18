<?php
// +----------------------------------------------------------------------
// |[ 文档说明: 用户模块异常类 异常码:8001]
// +----------------------------------------------------------------------
// | Copyright (c) 2018~2024 http://www.mlhcmk.com All rights reserved.
// +----------------------------------------------------------------------


namespace app\lib\exceptions;


use app\lib\BaseException;

class WithdrawException extends BaseException
{
    public $errorCode = 30001;
    public $msg = '提现异常';
}