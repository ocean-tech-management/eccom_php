<?php
// +----------------------------------------------------------------------
// |[ 文档说明: 核心服务模块异常类 异常码:4001]
// +----------------------------------------------------------------------
// | Copyright (c) 2018~2024 http://www.mlhcmk.com All rights reserved.
// +----------------------------------------------------------------------


namespace app\lib\exceptions;


use app\lib\BaseException;

class ServiceException extends BaseException
{
    public $errorCode = 4001;
    public $msg = '网络异常';
}