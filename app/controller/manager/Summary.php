<?php
// +----------------------------------------------------------------------
// |[ 文档说明: 汇总模块Controller]
// +----------------------------------------------------------------------
// | Copyright (c) 2018~2024 http://www.mlhcmk.com All rights reserved.
// +----------------------------------------------------------------------


namespace app\controller\manager;


use app\BaseController;
use app\lib\models\OrderGoods;
use app\Request;
use think\facade\Cache;
use app\lib\models\Divide;

class Summary extends BaseController
{
    protected $middleware = [
        'checkAdminToken',
        'checkRule',
        'OperationLog',
    ];

    /**
     * @title  控制台全量数据汇总
     * @param Request $request
     * @return string
     */
    public function adminSummary(Request $request)
    {
        $service = new \app\lib\services\Summary();
        $monthDays = date('t');
        $divideModel = (new Divide());
        $divideModel->console(['start_time' => strtotime(date('Y-m-d', time())), 'end_time' => strtotime(date('Y-m-d', time())) + 24 * 3600]);
        $data = [
            'order' => [
                'total' => $service->getOrder(),
                'today' => $service->getOrder([], [strtotime(date('Y-m-d', time())), strtotime(date('Y-m-d', time())) + 24 * 3600]),
                'month' => $service->getOrder([], [strtotime(date('Y-m', time())), strtotime(date('Y-m', time())) + $monthDays * 24 * 3600]),
                'un_deliver' => $service->getOrder([2]),
                'un_pay' => $service->getOrder([1]),
                'un_refund' => $service->getOrder([5, 6]),
            ],
            'sale' => [
                'total' => $service->getSale(),
                'today' => $service->getSale([strtotime(date('Y-m-d', time())), strtotime(date('Y-m-d', time())) + 24 * 3600]),
                'month' => $service->getOrder([strtotime(date('Y-m', time())), strtotime(date('Y-m', time())) + $monthDays * 24 * 3600]),
                'rows' => $service->getSaleRowsByMonth()
            ],
            'profit' => [
                'total' => $divideModel->console()['total_profit'],
                'today' => $divideModel->console(['start_time' => strtotime(date('Y-m-d', time())), 'end_time' => strtotime(date('Y-m-d', time())) + 24 * 3600])['total_profit'],
                'month' => $divideModel->console(['start_time' => strtotime(date('Y-m', time())), 'end_time' => strtotime(date('Y-m', time())) + $monthDays * 24 * 3600])['total_profit'],
            ],
            'view' => [
                'pv' => rand(1, 9999),
                'uv' => rand(1, 9999),
                'pv_growth_rate' => rand(1, 99) . '%',
                'uv_growth_rate' => rand(1, 99) . '%',
            ],
            'ranking' => $service->getSaleRowsByMonth('desc'),
            'user' => [
                'total' => $service->getRegisterUser(),
                'today' => $service->getRegisterUser([strtotime(date('Y-m-d', time())), strtotime(date('Y-m-d', time())) + 24 * 3600]),
                'rows' => $service->getRegisterUserRowsByMonth()
            ],
            'agent' => [
                'partner' => [
                    'total' => $service->getMember(1),
                    'month' => $service->getMember(2, [strtotime(date('Y-m', time())), strtotime(date('Y-m', time())) + $monthDays * 24 * 3600]),
                    'today' => $service->getMember(3, [strtotime(date('Y-m-d', time())), strtotime(date('Y-m-d', time())) + 24 * 3600]),
                ],
                'seniorLeader' => [
                    'total' => $service->getMember(2),
                    'month' => $service->getMember(2, [strtotime(date('Y-m', time())), strtotime(date('Y-m', time())) + $monthDays * 24 * 3600]),
                    'today' => $service->getMember(2, [strtotime(date('Y-m-d', time())), strtotime(date('Y-m-d', time())) + 24 * 3600]),
                ],
                'leader' => [
                    'total' => $service->getMember(3),
                    'month' => $service->getMember(3, [strtotime(date('Y-m', time())), strtotime(date('Y-m', time())) + $monthDays * 24 * 3600]),
                    'today' => $service->getMember(3, [strtotime(date('Y-m-d', time())), strtotime(date('Y-m-d', time())) + 24 * 3600]),
                ],
                'rows' => $service->getMemberRowsByMonth()
            ],
            'ip' => $this->request->ip()
        ];

        return returnData($data);
    }

    /**
     * @title  商品销售排行榜
     * @param OrderGoods $model
     * @return string
     * @throws \Exception
     */
    public function goodsSummary(OrderGoods $model)
    {
        $list = $model->hotSaleList($this->requestData);
        return returnData($list['list'], $list['pageTotal']);
    }

}