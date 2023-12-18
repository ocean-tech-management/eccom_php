<?php
// +----------------------------------------------------------------------
// |[ 文档说明: 用户端令牌判断中间件]
// +----------------------------------------------------------------------
// | Copyright (c) 2018~2024 http://www.mlhcmk.com All rights reserved.
// +----------------------------------------------------------------------


namespace app\lib\middleware;


use app\lib\exceptions\TokenException;
use app\lib\services\Safe;
use app\lib\services\Token;

class CheckApiToken
{
    protected $aWhite = [
        'login',
        'token',
        'wx',
        'code',
        'home',
        'banner',
        'alibaba',
        'test',
        'lecturer',
        'shipping'
    ];

    protected $sWhite = [
        'Coupon/list',
        'Coupon/info',
        'subject/list',
        'subject/info',
        'category/list'
    ];

    public function handle($request, \Closure $next)
    {
        //本地调试环境不检测token
        $env = env('ENV');
        if (!empty($env) && $env == 'local') {
            $request->uid = $request->param('uid') ?? null;
            return $next($request);
        }

        //限流
        $limitRes = (new Safe())->limitFrequency(['ip'=>$request->ip()]);

        //$baseUrl = explode('/',trim(str_replace('index.php','',$request->BaseUrl()),'/'));
        $baseUrl = explode('/', trim($request->pathinfo()));
        $root = $baseUrl[0];
        if ($root == 'api') {
            $version = $baseUrl[1];
            $controller = $baseUrl[2];
            $action = $baseUrl[3];
            $route = $controller . '/' . $action;
            $uid = $request->param('uid');
            if (($root == 'api') && (!in_array($controller, $this->aWhite)) && (!in_array($route, $this->sWhite))) {
                $aUser = (new Token())->verifyToken();
                if (empty($aUser)) {
                    throw new TokenException(['errorCode' => 1000101]);
                }
//                if(!empty($uid) && ($aUser['uid'] != $uid)) {throw new TokenException(['errorCode'=>1000101]);}
                $uid = $aUser['uid'] ?? null;
            }
            $request->uid = $uid;
            $request->tokenUid = $uid;
        }

        return $next($request);
    }
}