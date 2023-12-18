<?php
// +----------------------------------------------------------------------
// |[ 文档说明: 后台操作日志记录中间件]
// +----------------------------------------------------------------------
// | Copyright (c) 2018~2024 http://www.mlhcmk.com All rights reserved.
// +----------------------------------------------------------------------


namespace app\lib\middleware;

use app\lib\models\AuthRule;
use app\lib\models\OperationLog as OperationLogModel;
use app\lib\services\Log;

class OperationLog
{
    //需重点关注分析的模块
    protected $aFocus = [
        'login',
        'goods',
        'file',
        'order',
        'finance',
        'afterSale',
        'ship',
        'pt',
        'auth',
        'manager',
        'activity',
        'excel',
        'system',
        'category',
        'branch',
        'growthValue',
        'reputation',
        'brandSpace',
        'supplier',
        'afterSale',
        'ppyl',
        'ppylMember',
        'ppylCVIP',
        'ppylBanner',
        'specialArea',
        'propagandaReward',
        'propagandaRewardRule',
        'propagandaRewardPlan',
        'handselStand',
        'teamDivide',
        'teamMember',
        'areaMember',
        'shareholderRewardPlan',
        'shareholderDivide',
        'shareholderRewardRule',
        'crowdFunding',
        'exchange',
        'device',
        'exp',
        'teamShareholder',
        'userExtra',
        'UserAuthClear',
        'userBalanceLimit',
    ];
    //需重点关注分析的具体操作
    protected $sFocus = [
        'coupon/create',
        'coupon/update',
        'coupon/operation',
        'member/assign',
        'user/assign',
        'user/updateUserPhone',
        'growthValue/grant',
        'member/importConfusion',
        'user/banCrowdTransfer',
        'user/removeThirdId',
        'integral/recharge',
        'user/banBuy',
        'user/clearUser',
        'user/removeThirdId',
        'user/updateUserPhone',
        'user/clearUserSync',
        'user/banCrowdTransfer',
    ];


    //不在权限表中的操作
    protected $otherRoute = [
        'login/login' => '登录',
        'manager/userRule' => '获取角色权限',
        'vdc/list' => '会员分销规则列表',
        'coupon/used' => '优惠券可用场景列表',
        'coupon/type' => '优惠券类型列表',
        'coupon/userType' => '优惠券面向对象列表',
        'goods/attrKey' => '商品属性规格列表',
        'goods/attrValue' => '商品属性规格值列表',
        'ship/company' => '物流公司列表',
        'ship/info' => '物流详情',
        'file/read' => '检查文件是否存在上传记录',
        'file/upload' => '上传文件',
        'member/level' => '会员等级列表',
        'ship/city' => '城市列表',
        'ship/syncTime' => '订单同步时间',
        'manager/updateMyPwd' => '修改自己的密码',

    ];

    //后置中间件
    public function handle($request, \Closure $next)
    {
        $response = $next($request);
        //$baseUrl = explode('/',trim(str_replace('index.php','',$request->BaseUrl()),'/'));
        $baseUrl = explode('/', trim($request->pathinfo()));
        $root = $baseUrl[0];
        if ($root == 'admin' || $root == 'manager') {
            $controller = $baseUrl[1];
            $action = $baseUrl[2];
            $route = $controller . '/' . $action;
            $authRule = AuthRule::with(['parent'])->where(['name' => $route])->field('id,title,pid')->findOrEmpty()->toArray();
            if (empty($authRule)) {
                if (isset($this->otherRoute[$route])) {
                    $authName = $this->otherRoute[$route];
                }
            } else {
                if (!empty($authRule['parent'])) {
                    $authName = $authRule['parent']['title'] . ' - ' . $authRule['title'];
                } else {
                    $authName = $authRule['title'];
                }
            }

            //过滤部分查询的操作不要记录日志
            //需要过滤的方法名类型
            $arr = ['list', 'List', 'info', 'Info', 'Detail'];
            //正则匹配
            preg_match_all('#(' . implode('|', $arr) . ')#', $action, $wordsFound);

            //获取匹配到的字符串，array_unique()函数去重。如需获取总共出现次数，则不需要去重
            $wordsFound = array_unique($wordsFound[0]);

            //如果包含了以上的查询操作则不记录日志,不包含才记录
            if (empty(count($wordsFound))) {
                $adminInfo = $request->adminInfo ?? [];
                $log['admin_id'] = $adminInfo['id'] ?? '0';
                $log['admin_name'] = $adminInfo['name'] ?? '未知管理员';
                $log['path'] = $route;
                $log['path_name'] = $authName ?? '未知操作';
                $log['content'] = ($root == 'manager') ? '商户移动端操作' : (!empty($request->param()) ? json_encode($request->param(), JSON_UNESCAPED_UNICODE) : null); //此处留给以后需要重点关注操作的操作数据说明
                $log['device_information'] = !empty($request->server('HTTP_USER_AGENT')) ? $request->server('HTTP_USER_AGENT') : null;
                $log['ip'] = $request->ip() ?? null;

                //区分日志记录位置,重点关注的操作记录到数据库,其他操作记录到普通日志文件
                if (in_array($controller, $this->aFocus) || in_array($route, $this->sFocus)) {
                    OperationLogModel::create($log);
                } else {
                    (new Log())->setChannel('operation')->record($log, 'info');
                }

            }

        }

        return $response;

    }
}