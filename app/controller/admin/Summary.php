<?php
// +----------------------------------------------------------------------
// |[ 文档说明: 汇总模块Controller]
// +----------------------------------------------------------------------
// | Copyright (c) 2018~2024 http://www.mlhcmk.com All rights reserved.
// +----------------------------------------------------------------------


namespace app\controller\admin;


use app\BaseController;
use app\lib\models\OrderGoods;
use app\Request;
use think\facade\Cache;
use app\lib\models\Divide;
use app\lib\services\Summary as SummaryService;

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
     * @throws \Exception
     */
    public function adminSummary(Request $request)
    {
        set_time_limit(0);
        ini_set('memory_limit', '3072M');
        ini_set('max_execution_time', '0');
        $service = new \app\lib\services\Summary();
        $data = $this->requestData;
        $monthDays = date('t');
        $divideModel = (new Divide());
//        $divideModel->console(['start_time' => strtotime(date('Y-m-d', time())), 'end_time' => strtotime(date('Y-m-d', time())) + 24 * 3600]);
        $data = [
            'order' => [
                'today' => $service->getOrder([], [strtotime(date('Y-m-d', time())), strtotime(date('Y-m-d', time())) + 24 * 3600], [], ['cache' => 'todayOrderSummary', 'cache_expire' => 3600]),
                'yesterday' => 0,
                'total' => 0,
                'month' => 0,
//                'yesterday' => $service->getOrder([], [strtotime(date('Y-m-d', strtotime("-1 day"))), strtotime(date('Y-m-d', time()))], [], ['cache' => 'yesterdayOrderSummary', 'cache_expire' => 300]),
//                'total' => $service->getOrder([], [], [], ['cache' => 'allOrderSummary', 'cache_expire' => (60 * 60 * 5)]),
//                'month' => $service->getOrder([], [strtotime(date('Y-m', time())), strtotime(date('Y-m', time())) + $monthDays * 24 * 3600], [], ['cache' => 'monthOrderSummary', 'cache_expire' => (60 * 60 * 5)]),
                'un_deliver' => $service->getOrder([2], [], [], ['cache' => 'todayUnDeliverOrderSummary', 'cache_expire' => 1200]),
                'un_pay' => $service->getOrder([1], [], [], ['cache' => 'todayUnPayOrderSummary', 'cache_expire' => 300]),
                'un_refund' => $service->getAfterSale([1], [], [1], ['cache' => 'todayUnRefundOrderSummary', 'cache_expire' => 300]),
            ],
            //销售数据汇总 已拆分接口
//            'sale' => [
//                'today' => $service->getSale([strtotime(date('Y-m-d', time())), strtotime(date('Y-m-d', time())) + 24 * 3600], ['cache' => 'todaySaleSummary', 'cache_expire' => 300]),
//                'yesterday' => $service->getSale([strtotime(date('Y-m-d', strtotime("-1 day"))), strtotime(date('Y-m-d', time()))], ['cache' => 'yesterdaySaleSummary', 'cache_expire' => 300]),
//                'total' => $service->getSale([], ['cache' => 'allSaleSummary', 'cache_expire' => (60 * 60 * 5)]),
//                'month' => $service->getSale([strtotime(date('Y-m', time())), strtotime(date('Y-m', time())) + $monthDays * 24 * 3600], ['cache' => 'monthSaleSummary', 'cache_expire' => (60 * 60 * 5)]),
//                'rows' => $service->getSaleRows($data)
//            ],
            //收益汇总数据已拆分接口
//            'profit' => [
//                'today' => $divideModel->console(['start_time' => strtotime(date('Y-m-d', time())), 'end_time' => strtotime(date('Y-m-d', time())) + 24 * 3600], ['cache_expire' => (60 * 5)])['total_profit'],
//                'total' => $divideModel->console([], ['cache_expire' => (60 * 60 * 12)])['total_profit'],
//                'month' => $divideModel->console(['start_time' => strtotime(date('Y-m', time())), 'end_time' => strtotime(date('Y-m', time())) + $monthDays * 24 * 3600], ['cache_expire' => (60 * 60 * 5)])['total_profit'],
//            ],
            'view' => [
                'pv' => rand(1, 9999),
                'uv' => rand(1, 9999),
                'pv_growth_rate' => rand(1, 99) . '%',
                'uv_growth_rate' => rand(1, 99) . '%',
            ],
            'ranking' => $service->getSaleRowsByMonth('desc', ['cache' => 'rankingSummary', 'cache_expire' => (3600 * 24)]),
            'user' => [
                'total' => $service->getRegisterUser([], ['cache' => 'allUserSummary', 'cache_expire' => (60 * 60 * 5)]),
                'today' => $service->getRegisterUser([strtotime(date('Y-m-d', time())), strtotime(date('Y-m-d', time())) + 24 * 3600], ['cache' => 'todayUserSummary', 'cache_expire' => (60 * 5)]),
                'rows' => $service->getRegisterUserRowsByMonth(['cache' => 'monthUserSummary', 'cache_expire' => (60 * 60 * 5)])
            ],
            'agent' => [
                'partner' => [
                    'total' => $service->getMember(1),
                    'yesterday' => $service->getMember(1, [strtotime(date("Y-m-d", strtotime("-1 day")) . ' 00:00:00'), strtotime(date("Y-m-d", strtotime("-1 day")) . ' 23:59:59')]),
                    'month' => $service->getMember(1, [strtotime(date('Y-m', time())), strtotime(date('Y-m', time())) + $monthDays * 24 * 3600]),
                    'today' => $service->getMember(1, [strtotime(date('Y-m-d', time())), strtotime(date('Y-m-d', time())) + 24 * 3600]),
                ],
                'seniorLeader' => [
                    'total' => $service->getMember(2),
                    'yesterday' => $service->getMember(2, [strtotime(date("Y-m-d", strtotime("-1 day")) . ' 00:00:00'), strtotime(date("Y-m-d", strtotime("-1 day")) . ' 23:59:59')]),
                    'month' => $service->getMember(2, [strtotime(date('Y-m', time())), strtotime(date('Y-m', time())) + $monthDays * 24 * 3600]),
                    'today' => $service->getMember(2, [strtotime(date('Y-m-d', time())), strtotime(date('Y-m-d', time())) + 24 * 3600]),
                ],
                'leader' => [
                    'total' => $service->getMember(3),
                    'yesterday' => $service->getMember(3, [strtotime(date("Y-m-d", strtotime("-1 day")) . ' 00:00:00'), strtotime(date("Y-m-d", strtotime("-1 day")) . ' 23:59:59')]),
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
     * @title  其他汇总数据(目前有收益)
     * @return string
     * @throws \Exception
     */
    public function summaryHard()
    {
        $monthDays = date('t');
        $divideModel = (new Divide());
        //        $requestData['returnDataType'] = 2;
//        $requestData['needCache'] = true;
//        $requestData['dontNeedList'] = true;
//
//        $requestDataToday = $requestData;
//        $requestDataTotal = $requestData;
//        $requestDataMonth = $requestData;
//
//        $requestDataToday['start_time'] = strtotime(date('Y-m-d', time()));
//        $requestDataToday['end_time'] = strtotime(date('Y-m-d', time())) + 24 * 3600;
//        $requestDataToday['cache_expire'] = (60 * 5);
//
//        $requestDataTotal['cache_expire'] = (60 * 60 * 12);
//
//        $requestDataMonth['start_time'] = strtotime(date('Y-m', time()));
//        $requestDataMonth['end_time'] = strtotime(date('Y-m', time())) + $monthDays * 24 * 3600;
//        $requestDataMonth['cache_expire'] = (60 * 60 * 5);
//        $data = [
//            'profit' => [
//                'today' => $divideModel->list($requestDataToday)['summary']['total_profit'],
//                'total' => $divideModel->list($requestDataTotal)['summary']['total_profit'],
//                'month' => $divideModel->list($requestDataMonth)['summary']['total_profit'],
//            ],
//        ];
        $data = [
            'profit' => [
                'today'=>0,
                'total'=>0,
                'month'=>0,
//                'today' => $divideModel->console(['start_time' => strtotime(date('Y-m-d', time())), 'end_time' => strtotime(date('Y-m-d', time())) + 24 * 3600], ['cache_expire' => (60 * 5)])['total_profit'],
//                'total' => $divideModel->console([], ['cache_expire' => (60 * 60 * 12)])['total_profit'],
//                'month' => $divideModel->console(['start_time' => strtotime(date('Y-m', time())), 'end_time' => strtotime(date('Y-m', time())) + $monthDays * 24 * 3600], ['cache_expire' => (60 * 60 * 5)])['total_profit'],
            ],
        ];
        return returnData($data);
    }

//    /**
//     * @title  销售汇总数据
//     * @return string
//     * @throws \Exception
//     */
//    public function saleSummary()
//    {
//        $service = new \app\lib\services\Summary();
//        $data = $this->requestData;
//        $monthDays = date('t');
//        $data = [
//            'profit' => [
//                'today' => $divideModel->console(['start_time' => strtotime(date('Y-m-d', time())), 'end_time' => strtotime(date('Y-m-d', time())) + 24 * 3600], ['cache_expire' => (60 * 5)])['total_profit'],
//                'total' => $divideModel->console([], ['cache_expire' => (60 * 60 * 12)])['total_profit'],
//                'month' => $divideModel->console(['start_time' => strtotime(date('Y-m', time())), 'end_time' => strtotime(date('Y-m', time())) + $monthDays * 24 * 3600], ['cache_expire' => (60 * 60 * 5)])['total_profit'],
//            ],
//        ];
//        return returnData($data);
//    }

    /**
     * @title  销售汇总数据
     * @return string
     * @throws \Exception
     */
    public function saleSummary()
    {
        $service = new \app\lib\services\Summary();
        $data = $this->requestData;
        $monthDays = date('t');
        $finally = [
            'sale' => [
                'today' => $service->getSale([strtotime(date('Y-m-d', time())), strtotime(date('Y-m-d', time())) + 24 * 3600], ['cache' => 'todaySaleSummary', 'cache_expire' => 3600]),
//                'total' => $service->getSale([], ['cache' => 'allSaleSummary', 'cache_expire' => (60 * 60 * 5)]),
//                'month' => $service->getSale([strtotime(date('Y-m', time())), strtotime(date('Y-m', time())) + $monthDays * 24 * 3600], ['cache' => 'monthSaleSummary', 'cache_expire' => (60 * 60 * 5)]),
                'rows' => $service->getSaleRows($data),
                'total'=>0,
                'month'=>0,
            ],
            'ranking' => $service->getSaleRowsByMonth('desc', ['cache' => 'rankingSummary', 'cache_expire' => (3600 * 24)]),

        ];
        return returnData($finally);
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

    /**
     * @title  商品销售数据
     * @param OrderGoods $model
     * @return string
     * @throws \Exception
     */
    public function goodsSale(OrderGoods $model)
    {
        $data = $this->requestData;
        $data['clearCache'] = true;
        $list = $model->spuSaleList($data);
        return returnData($list['list'], $list['pageTotal']);
    }

    /**
     * @title  会员个人业绩汇总
     * @param Divide $model
     * @return string
     * @throws \Exception
     */
    public function memberPerformance(Divide $model)
    {
        $list = $model->memberSummary($this->requestData);
        return returnData($list['list'], $list['pageTotal'], 0, '成功', $list['total']);
    }

    /**
     * @title  资金池汇总数据面板
     * @param SummaryService $service
     * @return string
     */
    public function balanceSummary(SummaryService $service)
    {
        $info = $service->ppylBalanceSummary($this->requestData);
        return returnData($info);
    }

}