<?php
// +----------------------------------------------------------------------
// |[ 文档说明: 权限控制中间件]
// +----------------------------------------------------------------------
// | Copyright (c) 2018~2024 http://www.mlhcmk.com All rights reserved.
// +----------------------------------------------------------------------
 

namespace app\lib\middleware;


use app\lib\exceptions\AuthException;
use app\lib\services\Auth;
use app\lib\services\Log;

class CheckRule
{
    //不同应用的路由不可重复!
    //权限白名单模块
    protected $aWhite = [
        'login',
    ];

    //权限白名单路由
    protected $sWhite = [
        'member/level',
        'vdc/list',
        'teamVdc/list',
        'areaVdc/list',
        'expVdc/list',
        'teamShareholderVdc/list',
        'sku/list',
        'coupon/used',
        'coupon/type',
        'coupon/userType',
        'ship/company',
        'ship/info',
        'auth/ruleList',
        'manager/userRule',
        'ship/city',
        'ship/syncTime',
        'order/spuList',
        'order/skuList',
        'manager/updateMyPwd',
        'user/excelInfo',
        'teamMember/level',
        'device/deviceUser',
        'excel/ExportField'
    ];

    public function handle($request, \Closure $next)
    {
        //$baseUrl = explode('/',trim(str_replace('index.php','',$request->BaseUrl()),'/'));
        $baseUrl = explode('/', trim($request->pathinfo()));
        $root = $baseUrl[0];
        if ($root == 'admin' || $root == 'manager') {
            $controller = $baseUrl[1];
            $action = $baseUrl[2];
            $route = $controller . '/' . $action;
            $id = $request->adminId;
            $adminIfo = $request->adminInfo;

            //超管,在白名单的路由不需要检查权限
            if ((!empty($adminIfo) && $adminIfo['type'] != 88) && ((!in_array($controller, $this->aWhite)) && (!in_array($route, $this->sWhite)))) {

                switch ($root) {
                    case 'admin':
                        $checkStatus = (new Auth())->check($route, $id);
                        break;
                    case 'manager':
                        $checkStatus = (new Auth())->check($route, $id, 'or', 2);
                        break;
                    default :
                        throw new AuthException(['errorCode' => 2200104]);
                }

                if (empty($checkStatus)) {
                    throw new AuthException(['errorCode' => 2200102]);
                }
            }
        }

        return $next($request);
    }
}