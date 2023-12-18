<?php
// +----------------------------------------------------------------------
// |[ 文档说明: 检查开放平台用户标识中间件]
// +----------------------------------------------------------------------
// | Copyright (c) 2018~2024 http://www.mlhcmk.com All rights reserved.
// +----------------------------------------------------------------------


namespace app\lib\middleware;

use app\lib\exceptions\OpenException;
use app\lib\models\OpenAccount;
use app\lib\services\Open;

class CheckOpenUser
{
    public function handle($request, \Closure $next)
    {
        $openService = (new Open());
        //检查参数格式
        $param = $openService->checkParamSave($request->param());

        //检查用户及验签
        $openService->checkParam($param);

        //限流
        $limitRes = $openService->limitFrequency($param);

        //传递请求参数已用于控制器
        $request->openParam = $param;

        //后期是否考虑要加上ip白名单?
        return $next($request);
    }
}