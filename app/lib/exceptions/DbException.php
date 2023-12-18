<?php
// +----------------------------------------------------------------------
// |[ 文档说明: 数据库异常类]
// +----------------------------------------------------------------------
// | Copyright (c) 2018~2024 http://www.mlhcmk.com All rights reserved.
// +----------------------------------------------------------------------


namespace app\lib\exceptions;


use app\lib\BaseException;

class DbException extends BaseException
{
    public $errorCode = 9001;
    public $msg = '参数有误';
}