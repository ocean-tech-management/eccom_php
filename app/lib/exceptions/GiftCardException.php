<?php
// +----------------------------------------------------------------------
// |[ 文档说明: 礼品卡模块异常类 异常码:25001]
// +----------------------------------------------------------------------
// | Copyright (c) 2018~2024 http://www.mlhcmk.com All rights reserved.
// +----------------------------------------------------------------------


namespace app\lib\exceptions;


use app\lib\BaseException;

class GiftCardException extends BaseException
{
    public $errorCode = 25001;
}