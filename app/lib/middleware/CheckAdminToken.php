<?php
// +----------------------------------------------------------------------
// |[ 文档说明: 后台令牌判断中间件]
// +----------------------------------------------------------------------
// | Copyright (c) 2018~2024 http://www.mlhcmk.com All rights reserved.
// +----------------------------------------------------------------------


namespace app\lib\middleware;


use app\lib\exceptions\TokenException;
use app\lib\services\Safe;
use app\lib\services\Token;

class CheckAdminToken
{
    protected $aWhite = [
        'login',
        'token',
        'code',
        'analysis',
        'index'
    ];
    protected $sWhite = [
        'index/hello',
        'index/test',
        'index/connect',
        'file/importExcel',
        'ship/syncTime',
        'goods/spuSaleList1',
        'goods/spuSaleSummary1',
        'supplier/payList1',
        'summary/goodsSale',
        'reputation/create',
    ];

    public function handle($request, \Closure $next)
    {
        //本地调试环境不检测token
//        $env = env('ENV');
//        if(!empty($env) && $env == 'local'){
//            return $next($request);
//        }

        //限流
        $limitRes = (new Safe())->limitFrequency(['ip'=>$request->ip()]);

        //$baseUrl = explode('/',trim(str_replace('index.php','',$request->BaseUrl()),'/'));
        $baseUrl = explode('/', trim($request->pathinfo()));
        $root = $baseUrl[0];
        if ($root == 'admin' || $root == 'manager') {
            $controller = $baseUrl[1];
            $action = $baseUrl[2];
            $route = $controller . '/' . $action;
            if ((!in_array($controller, $this->aWhite)) && (!in_array($route, $this->sWhite))) {
                $aUser = (new Token())->verifyToken();
                if (empty($aUser)) {
                    throw new TokenException(['errorCode' => 1000101]);
                }
                if (!empty($id) && ($aUser['id'] != $id)) {
                    throw new TokenException(['errorCode' => 1000102]);
                }
                $request->adminId = $aUser['id'];
                $request->adminInfo = $aUser;
            }
        }

        return $next($request);
    }
}