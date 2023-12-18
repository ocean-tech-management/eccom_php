<?php
// +----------------------------------------------------------------------
// |[ 文档说明: 开放平台请求日志记录中间件]
// +----------------------------------------------------------------------
// | Copyright (c) 2018~2024 http://www.mlhcmk.com All rights reserved.
// +----------------------------------------------------------------------


namespace app\lib\middleware;

use app\lib\exceptions\ServiceException;
use app\lib\models\OpenRequestLog as OpenRequestLogModel;

class OpenRequestLog
{
    //需重点关注分析的模块
    protected $aFocus = [

    ];

    //需重点关注分析的具体操作
    protected $sFocus = [

    ];


    //后置中间件
    public function handle($request, \Closure $next)
    {
        $response = $next($request);
        //$baseUrl = explode('/',trim(str_replace('index.php','',$request->BaseUrl()),'/'));
        $baseUrl = explode('/', trim($request->pathinfo()));

        $decodeParam = $request->openParam;
        $log['appId'] = $decodeParam['appId'] ?? null;
        $log['request_scheme'] = $request->scheme();
        $log['request_port'] = $request->port();
        $log['request_host'] = $request->host();
        $log['request_method'] = $request->method();
        $log['request_full_path'] = $request->url(true);
        $log['request_path_info'] = $request->pathinfo();
        $log['request_content'] = json_encode($request->param(), 256) ?? null;
        $log['request_time'] = $request->time();
        $log['request_type'] = $request->type();
        $log['request_device'] = !empty($request->server('HTTP_USER_AGENT')) ? $request->server('HTTP_USER_AGENT') : null;
        $log['request_header'] = json_encode($request->header(), 256) ?? null;
        $log['request_ip'] = $request->ip() ?? null;
        $log['return_content'] = json_encode($response->getData(), 256);
        OpenRequestLogModel::create($log);

        return $response;

    }
}