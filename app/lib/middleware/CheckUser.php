<?php
// +----------------------------------------------------------------------
// |[ 文档说明: 检查用户唯一标识中间件]
// +----------------------------------------------------------------------
// | Copyright (c) 2018~2024 http://www.mlhcmk.com All rights reserved.
// +----------------------------------------------------------------------


namespace app\lib\middleware;

use \app\lib\validates\CheckUser as checkUserValidate;

class CheckUser
{
    public function handle($request, \Closure $next)
    {
        (new checkUserValidate())->goCheck();
        $request->uid = $request->param('uid');
        return $next($request);
    }
}