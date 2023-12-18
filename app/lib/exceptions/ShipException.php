<?php
// +----------------------------------------------------------------------
// |[ 文档说明: 物流模块异常类 异常码:19001]
// +----------------------------------------------------------------------
// | Copyright (c) 2018~2024 http://www.mlhcmk.com All rights reserved.
// +----------------------------------------------------------------------
 

namespace app\lib\exceptions;


use app\lib\BaseException;

class ShipException extends BaseException
{
    public $errorCode = 19001;
}