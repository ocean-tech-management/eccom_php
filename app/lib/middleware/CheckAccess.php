<?php
/*
  * @LastEditTime: 2023-03-16 15:54:38
 * @FilePath: \php-lyz\app\lib\middleware\CheckAccess.php
 * @Description: 用户端来源判断中间件（所属某公众号或小程序）]
 * 
 * Copyright (c) 2023 by ${git_name_email}, All Rights Reserved. 
 */

namespace app\lib\middleware;

use think\facade\Request;
use app\lib\exceptions\AccessException;

class CheckAccess
{
    public function handle($request, \Closure $next)
    {
        $baseUrl = explode('/', trim($request->pathinfo()));
        $root = $baseUrl[0];
        $access_key = getAccessKey();
        if ($root == 'api') {
            //查询是否存在该来源配置
            if (empty(config("system.clientConfig.$access_key"))) {
                throw new AccessException(['errorCode' => 3100101]);
            }
            // $request->access_id = $access_id;
        }
        return $next($request);
    }
}
