<?php
// +----------------------------------------------------------------------
// |[ 文档说明: 开放平台模块异常类 异常码:26001]
// +----------------------------------------------------------------------
// | Copyright (c) 2018~2024 http://www.mlhcmk.com All rights reserved.
// +----------------------------------------------------------------------
 

namespace app\lib\exceptions;


use app\lib\BaseException;

class OpenException extends BaseException
{
    public $errorCode = 26001;
}