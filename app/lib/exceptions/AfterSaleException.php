<?php
// +----------------------------------------------------------------------
// |[ 文档说明: 售后模块异常类 异常码:20001]
// +----------------------------------------------------------------------
// | Copyright (c) 2018~2024 http://www.mlhcmk.com All rights reserved.
// +----------------------------------------------------------------------
 

namespace app\lib\exceptions;


use app\lib\BaseException;

class AfterSaleException extends BaseException
{
    public $errorCode = 20001;
}