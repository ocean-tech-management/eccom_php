<?php
// +----------------------------------------------------------------------
// |[ 文档说明: 分润规则逻辑Service]
// +----------------------------------------------------------------------
// | Copyright (c) 2018~2024 http://www.mlhcmk.com All rights reserved.
// +----------------------------------------------------------------------
 

namespace app\lib\services;

use app\lib\models\CrowdfundingBalanceDetail;
use app\lib\models\CrowdfundingFreezeCustom;
use app\lib\models\CrowdfundingWithdrawCustom;
use app\lib\models\FundsWithdraw;
use app\lib\models\OrderGoods;
use app\lib\models\PpylCvipOrder;
use app\lib\models\PpylOrder;
use app\lib\models\PpylWaitOrder;
use app\lib\models\RechargeTopLinkRecord;
use app\lib\models\SystemConfig;
use app\lib\models\User;
use app\lib\models\Withdraw;
use think\facade\Db;

class Summary
{
    /**
     * 获取订单汇总数据
     * @param array $status
     * @param array $times
     * @param array $afterStatus
     * @param array $cache
     * @return int
     */
    public function getOrder(array $status = [], array $times = [], array $afterStatus = [], array $cache = [])
    {
        $cacheKey = false;
        $cacheExpire = 0;

        $model = new \app\lib\models\Order();
        if (!empty($afterStatus)) {
            $map[] = ['after_status', 'in', $afterStatus];
        } else {
            if (!empty($status)) {
                $map[] = ['order_status', 'in', $status];
            } else {
                $map[] = ['order_status', '>', -1];
            }
            if (!empty($times)) {
                $map[] = ['create_time', 'between', $times];
            }
        }
        $map[] = ['order_type', 'in', [1, 2, 3, 4, 5, 7, 8]];
        if (!empty($cache)) {
            if (!empty($cache['cache'])) {
                $cacheKey = $cache['cache'];
                $cacheExpire = $cache['cache_expire'];
            }
        }


        $count = $model->where($map)->when($cacheKey, function ($query) use ($cacheKey, $cacheExpire) {
            $query->cache($cacheKey, $cacheExpire);
        })->count();

        return $count;
    }


    /**
     * 获取销售汇总数据
     * @param array $times
     * @param array $cache
     * @return float|int
     */
    public function getSale(array $times = [], array $cache = [])
    {
        $cacheKey = false;
        $cacheExpire = 0;

        $orderModel = new \app\lib\models\Order();
        $map[] = ['order_type', 'in', [1, 2, 3, 4, 5, 6]];
        $map[] = ['order_status', '>', 1];
        if (!empty($times)) {
            $map[] = ['create_time', 'between', $times];
        }
        if (!empty($cache)) {
            if (!empty($cache['cache'])) {
                $cacheKey = $cache['cache'];
                $cacheExpire = $cache['cache_expire'];
            }
        }

        $price = $orderModel->where($map)->when($cacheKey, function ($query) use ($cacheKey, $cacheExpire) {
            $query->cache($cacheKey, $cacheExpire);
        })->sum('total_price');

        return $price;
    }

    /**
     * @title  获取销售月份数据（图表用）
     * @param array $sear
     * @return array|mixed
     * @throws \Exception
     */
    public function getSaleRows(array $sear)
    {
        switch ($sear['saleRowsTimeType'] ?? 2) {
            case 1:
                $list = $this->getSaleRowsByMonth($sear['order'] ?? '', ['cache' => 'saleRowsMonth', 'cache_expire' => (60 * 60 * 5)]);
                break;
            case 2:
                $list = $this->getSaleRowsByDay(['time' => $sear['time'] ?? null, 'order' => $sear['order'] ?? ''], ['cache' => 'saleRowsDay', 'cache_expire' => (60 * 60 * 5)]);
                break;
        }
        return $list ?? [];
    }


    /**
     * 获取销售月份数据（图表用）年统计
     * @param string $order
     * @param array $cache
     * @return array
     */
    public function getSaleRowsByMonth(string $order = '', array $cache = [])
    {
        $orderModel = new \app\lib\models\Order();
        $year = date('Y');

        $cacheKey = false;
        $cacheExpire = 0;

        if (!empty($cache)) {
            if (!empty($cache['cache'])) {
                $cacheKey = $cache['cache'];
                $cacheExpire = $cache['cache_expire'];
                if (!empty(cache($cacheKey))) {
                    return cache($cacheKey);
                }
            }
        }

        $rows = [];
        for ($i = 1; $i <= 12; $i++) {
            $map = [];
            $map[] = ['order_status', '>', 1];
            if ($i == 12) {
                $map[] = ['create_time', 'between', [
                    strtotime($year . '-' . $i), strtotime(($year + 1) . '-1')
                ]];
            } else {
                $map[] = ['create_time', 'between', [
                    strtotime($year . '-' . $i), strtotime($year . '-' . ($i + 1))
                ]];
            }
            $item = $orderModel->where($map)->sum('total_price');
            $rows[] = [
                'label' => $i . '月',
                'value' => $item
            ];
        }

        if (trim($order)) {
            $temp = [];
            foreach ($rows as $key => &$item) {
                $temp[] = $item['value'];
            }

            arsort($temp);
            $tempArr = [];
            foreach ($temp as $k => $i) {
                array_push($tempArr, $rows[$k]);
            }
            $rows = $tempArr;
        }

        if (!empty($rows) && !empty($cacheKey)) {
            cache($cacheKey, $rows, $cacheExpire);
        }

        return $rows;
    }

    /**
     * @title  获取代理数据集（图表用）月统计
     * @param array $sear
     * @param array $cache
     * @return array|mixed
     * @throws \Exception
     */
    public function getSaleRowsByDay(array $sear = [], array $cache = [])
    {
        $order = $sear['order'] ?? '';
        $cacheKey = false;
        $cacheExpire = 0;
        if (!empty($sear['time'])) {
            $searTime = strtotime($sear['time']);
        } else {
            $searTime = time();
        }

        if (!empty($cache)) {
            if (!empty($cache['cache'])) {
                $cacheKey = $cache['cache'];
                $cacheExpire = $cache['cache_expire'];
                if (!empty(cache($cacheKey))) {
                    return cache($cacheKey);
                }
            }
        }
        $orderModel = new \app\lib\models\Order();

        $monthStart = date('Ym01', $searTime);
        $monthEnd = date('Ymd', strtotime("{$monthStart} + 1 month -1 day"));
        $allDays = date("t", $searTime);
        $map[] = ['order_status', '>', 1];

        $map[] = ['create_time', 'between', [strtotime($monthStart . ' 00:00:00'), strtotime($monthEnd . ' 23:59:59')]];
        $orders = $orderModel->where($map)->field('total_price,create_time')->select()->toArray();
        if (!empty($orders)) {
            foreach ($orders as $key => $value) {
                $day = intval(date('d', strtotime($value['create_time'])));
                if (!isset($timeOrder[$day])) {
                    $timeOrder[$day] = 0;
                }
                $timeOrder[$day] += $value['total_price'];
            }
        }

        $rows = [];
        for ($i = 1; $i <= $allDays; $i++) {
            if (!empty($timeOrder[$i])) {
                $rows[] = [
                    'label' => $i . '号',
                    'value' => priceFormat($timeOrder[$i])
                ];
            } else {
                $rows[] = [
                    'label' => $i . '号',
                    'value' => 0
                ];
            }

        }

        if (trim($order)) {
            $temp = [];
            foreach ($rows as $key => &$item) {
                $temp[] = $item['value'];
            }

            arsort($temp);
            $tempArr = [];
            foreach ($temp as $k => $i) {
                array_push($tempArr, $rows[$k]);
            }
            $rows = $tempArr;
        }

        if (!empty($rows) && !empty($cacheKey)) {
            cache($cacheKey, $rows, $cacheExpire);
        }

        return $rows;
    }


    /**
     * 获取注册用户数据
     * @param array $times
     * @param array $cache
     * @return int
     */
    public function getRegisterUser(array $times = [], array $cache = [])
    {
        $cacheKey = false;
        $cacheExpire = 0;

        if (!empty($cache)) {
            if (!empty($cache['cache'])) {
                $cacheKey = $cache['cache'];
                $cacheExpire = $cache['cache_expire'];
            }
        }

        $model = new User();
        $map[] = ['status', '>', -1];
        if (!empty($times)) {
            $map[] = ['create_time', 'between', $times];
        }
        $count = $model->where($map)->when($cacheKey, function ($query) use ($cacheKey, $cacheExpire) {
            $query->cache($cacheKey, $cacheExpire);
        })->count();
        return $count;
    }


    /**
     * 获取代理数据
     * @param int $level
     * @param array $times
     * @return int
     */
    public function getMember(int $level = 0, array $times = [])
    {
        $model = new \app\lib\models\Member();
        $map[] = ['status', '>', -1];
        if (!empty($times)) {
            $map[] = ['upgrade_time', 'between', $times];
        }
        if ($level) {
            $map[] = ['level', '=', $level];
        }
        $map[] = ['status', '>', -1];

        $count = $model->where($map)->count();
        return $count;
    }


    /**
     * 获取代理数据集（图表用）
     * @return array
     */
    public function getMemberRowsByMonth()
    {
        $model = new \app\lib\models\Member();
        $year = date('Y');
        $data = [];
        for ($i = 0; $i < 12; $i++) {
            $map = [];
            $map[] = ['status', '>', -1];
            if ($i == 12) {
                $map[] = ['update_time', 'between', [
                    strtotime($year . '-' . $i), strtotime(($year + 1) . '-1')
                ]];
            } else {
                $map[] = ['create_time', 'between', [
                    strtotime($year . '-' . $i), strtotime($year . '-' . ($i + 1))
                ]];
            }
            $partner = $seniorLeader = $leader = $map;
            $partner[] = ['level', '=', 1];
            $seniorLeader[] = ['level', '=', 2];
            $leader[] = ['level', '=', 3];

            $item = [
                'month' => ($i + 1) . '月',
                'partner' => $model->where($partner)->count(),
                'seniorLeader' => $model->where($seniorLeader)->count(),
                'leader' => $model->where($leader)->count(),
            ];

            array_push($data, $item);
        }

        return $data;
    }

    /**
     * 获取注册用户数据（图表用）
     * @param array $cache
     * @return array
     */
    public function getRegisterUserRowsByMonth(array $cache = [])
    {
        $cacheKey = false;
        $cacheExpire = 0;

        if (!empty($cache)) {
            if (!empty($cache['cache'])) {
                $cacheKey = $cache['cache'];
                $cacheExpire = $cache['cache_expire'];
                if (!empty(cache($cacheKey))) {
                    return cache($cacheKey);
                }
            }
        }

        $model = new User();
        $year = date('Y');
        $data = [];
        for ($i = 0; $i < 12; $i++) {
            $map = [];
            $map[] = ['status', '>', -1];
            if ($i == 12) {
                $map[] = ['create_time', 'between', [
                    strtotime($year . '-' . $i), strtotime(($year + 1) . '-1')
                ]];
            } else {
                $map[] = ['create_time', 'between', [
                    strtotime($year . '-' . $i), strtotime($year . '-' . ($i + 1))
                ]];
            }

            $item = [
                'month' => ($i + 1) . '月',
                'value' => $model->where($map)->count()
            ];

            array_push($data, $item);
        }

        if (!empty($cacheKey)) {
            cache($cacheKey, $data, $cacheExpire);
        }


        return $data;
    }

    /**
     * 获取退售后订单汇总数据
     * @param array $status
     * @param array $times
     * @param array $afterStatus
     * @param array $cache
     * @return int
     */
    public function getAfterSale(array $status = [], array $times = [], array $afterStatus = [], array $cache = [])
    {
        $cacheKey = false;
        $cacheExpire = 0;

        $model = new \app\lib\models\AfterSale();
        if (!empty($afterStatus)) {
            $map[] = ['after_status', 'in', $afterStatus];
        }
        if (!empty($status)) {
            $map[] = ['verify_status', 'in', $status];
        } else {
            $map[] = ['verify_status', '=', 1];
        }
        if (!empty($times)) {
            $map[] = ['create_time', 'between', $times];
        }

        if (!empty($cache)) {
            if (!empty($cache['cache'])) {
                $cacheKey = $cache['cache'];
                $cacheExpire = $cache['cache_expire'];
            }
        }


        $count = $model->where($map)->when($cacheKey, function ($query) use ($cacheKey, $cacheExpire) {
            $query->cache($cacheKey, $cacheExpire);
        })->count();

        return $count;
    }

    /**
     * @title  资金池数据面板
     * @param array $sear
     * @throws \Exception
     * @return mixed
     */
    public function ppylBalanceSummary(array $sear)
    {
        $map = [];
        if (!empty($sear['start_time']) && !empty($sear['end_time'])) {
            $map[] = ['create_time', '>=', strtotime($sear['start_time'])];
            $map[] = ['create_time', '<=', strtotime($sear['end_time'])];
        }

        //拼拼订单发货订单金额(拼拼收款)
        $ppylShippingTotal = 0;
        $orderList = [];
        $orderPayNoList = [];

        $shipMap = $map;
        $shipMap[] = ['status','=',1];
        $shipMap[] = ['shipping_status','=',1];
        $shipOrderList = PpylOrder::where($shipMap)->field('order_sn,pay_no,real_pay_price')->select()->toArray();
        if(!empty($shipOrderList)){
            foreach ($shipOrderList as $key => $value) {
                $ppylShippingTotal += $value['real_pay_price'];
                $orderList[] = $value['order_sn'];
                $orderPayNoList[] = $value['pay_no'];
            }
        }

        //未退款总额
        $notRefundOrderTotal = 0;
        $notRefundMap = $map;
        $CustomerPrincipal = 0;
        $existPayNo = [];
        $notRefundMap[] = ['refund_status','<>',1];
        $notRefundMap[] = ['', 'exp', Db::raw('pay_status not in (1,-1,-2)')];
        $notRefundOrder = PpylOrder::with(['refundPayNo','refundWaitPayNo'])->where($notRefundMap)->field('pay_no,pay_status,refund_status,real_pay_price')->group('pay_no')
            ->select()->each(function ($item) {
            if (!empty($item['refundPayNo'] ?? null)) {
                $item['pay_status'] = -2;
            }
            //排队订单存在退款复用该流水号的全部拼拼订单也显示退款
            if (!empty($item['refundWaitPayNo'] ?? null)) {
                $item['pay_status'] = -2;
            }
        })->toArray();

        //剔除复用流水号退款的订单
        if(!empty($notRefundOrder)){
            foreach ($notRefundOrder as $key => $value) {
//                if($value['pay_status'] != -2){
//                    $notRefundOrderTotal += $value['real_pay_price'];
//                    $existPayNoT[] = $value['pay_no'];
//                }
                if($value['pay_status'] != -2 && !isset($existPayNo[$value['pay_no']])){
                    $existPayNo[$value['pay_no']] = $value['real_pay_price'];
                }
            }
        }

        $notRefundWaitOrderTotal = 0;
        $notRefundWaitOrder = PpylWaitOrder::with(['refundPayNo','refundWaitPayNo'])->where($notRefundMap)->group('pay_no')->field('pay_no,pay_status,real_pay_price')->select()->each(function ($item) {
            if (!empty($item['refundPayNo'] ?? null)) {
                $item['pay_status'] = -2;
            }
            //排队订单存在退款复用该流水号的全部拼拼订单也显示退款
            if (!empty($item['refundWaitPayNo'] ?? null)) {
                $item['pay_status'] = -2;
            }
        })->toArray();

        //剔除复用流水号退款的订单
        if (!empty($notRefundWaitOrder)) {
            foreach ($notRefundWaitOrder as $key => $value) {
                if($value['pay_status'] != -2 && !isset($existPayNo[$value['pay_no']])){
                    $existPayNo[$value['pay_no']] = $value['real_pay_price'];
                }
            }
        }

        $notRefundTotal = 0;
        if(!empty($existPayNo)){
            foreach ($existPayNo as $key => $value) {
                $notRefundTotal += $value;
            }
        }

        //客户本金总额
        $CustomerPrincipal = $notRefundTotal - ($ppylShippingTotal ?? 0);

        //生成收款总额
        $orderTotal = 0;
        $orderMap = $map;
        $orderMap[] = ['status','=',1];
        $orderMap[] = ['pay_status','in',[2]];
        $orderMap[] = ['', 'exp', Db::raw('refund_price < real_pay_price')];
        $orderAllList = OrderGoods::where($orderMap)->field('real_pay_price,refund_price,order_sn')->select()->toArray();

        //订单编号可能会因为拼拼发货的订单导致重复计算,所以要剔除上面发货拼拼订单的订单号
        if (!empty($orderAllList)) {
            foreach ($orderAllList as $key => $value) {
                if (!in_array($value['order_sn'], $orderList)) {
                    $orderTotal += ($value['real_pay_price'] - $value['refund_price']);
                }
            }
        }

        //CVIP充值订单
        $CVIPOrderMap = $map;
        $CVIPOrderMap[] = ['pay_status','=',2];
        $CVIPOrderMap[] = ['status','=',1];
        $CVIPOrder = PpylCvipOrder::where($CVIPOrderMap)->sum('real_pay_price');
        $orderTotal += ($CVIPOrder ?? 0);

        //提现金额
        $withdrawTotal = 0;
        $withdrawMap = $map;
        $withdrawMap[] = ['status','=',1];
        $withdrawTotal = FundsWithdraw::where($withdrawMap)->sum('price');

        //总余额
        $balanceTotal = $ppylShippingTotal + $CustomerPrincipal + $orderTotal- $withdrawTotal;

        $finally['ppylShippingTotal'] = priceFormat($ppylShippingTotal);
        $finally['CustomerPrincipal'] = priceFormat($CustomerPrincipal);
        $finally['orderTotal'] = priceFormat($orderTotal);
        $finally['withdrawTotal'] = priceFormat($withdrawTotal);
        $finally['balanceTotal'] = priceFormat($balanceTotal);
        return $finally;

    }

    /**
     * @title  用户新增业绩汇总数据面板
     * @param array $data
     * @return mixed
     * @remark 用户可提现总余额 = (本金1.3倍 - 提现) + ( (2.23后的直推30%+H5个人余额转入) - 需冻结收益) + (-)自定义额度
     */
    public function userWithdrawDataPanel(array $data)
    {
        //财务号
        $financeUid = SystemConfig::where(['id' => 1])->value('finance_uid');
        $withdrawFinanceAccount = !empty($financeUid) ? explode(',', $financeUid) : ['notFinanceUid'];

        //查看用户累计转出金额,2.23后
        $aatMap[] = ['uid', '=', $data['uid']];
        $aatMap[] = ['change_type', '=', 7];
        $aatMap[] = ['status', '=', 1];
        $aatMap[] = ['create_time', '>=', 1677081600];
        $historyTransfer = CrowdfundingBalanceDetail::where($aatMap)->sum('price');
        $info['historyTransfer'] = priceFormat(abs($historyTransfer ?? 0));


        //查看用户累计提现金额,2.23后
        $hwMap[] = ['uid', '=', $data['uid']];
        $hwMap[] = ['withdraw_type', '=', 7];
        $hwMap[] = ['payment_status', '=', 1];
        $hwMap[] = ['check_status', '=', 1];
        $hwMap[] = ['status', '=', 1];
        $hwMap[] = ['create_time', '>=', 1677081600];
        $historyWithdraw = Withdraw::where($hwMap)->sum('total_price');
        $info['historyWithdraw'] = priceFormat($historyWithdraw ?? 0);

        //查看用户累计充值金额,2.23后
        $rMap[] = ['uid', '=', $data['uid']];
        $rMap[] = ['type', '=', 1];
        $rMap[] = ['status', '=', 1];
        $rMap[] = ['create_time', '>=', 1677081600];
        $historyRecharge = CrowdfundingBalanceDetail::where($rMap)->where(function ($query) use ($withdrawFinanceAccount){
            $map1[] = ['change_type', '=', 1];
            $map2[] = ['is_transfer', '=', 1];
            $map2[] = ['transfer_from_uid', 'in', $withdrawFinanceAccount];
            $query->whereOr([$map1, $map2]);
        })->sum('price');
        $info['historyRecharge'] = priceFormat($historyRecharge ?? 0);

        //查看今日直推新增充值业绩,30%
        $cMap[] = ['link_uid', '=', $data['uid']];
        $cMap[] = ['status', '=', 1];
        $cMap[] = ['create_time', '>=', strtotime(date('Y-m-d', time()))];
        $cMap[] = ['create_time', '<', strtotime(date('Y-m-d', time())) + 24 * 3600];
        $historyLinkRecharge = RechargeTopLinkRecord::where($cMap)->sum('price');


        //查看用户2.23后 今日自行转入的余额
        $utpMap[] = ['uid', '=', $data['uid']];
        $utpMap[] = ['change_type', '=', 9];
        $utpMap[] = ['create_time', '>=', 1677081600];
        $utpMap[] = ['create_time', '>=', strtotime(date('Y-m-d', time()))];
        $utpMap[] = ['create_time', '<', strtotime(date('Y-m-d', time())) + 24 * 3600];
        $utpMap[] = ['status', '=', 1];
        $utpMap[] = ['transfer_type', '<>', 1];
        $userTransformPrice = CrowdfundingBalanceDetail::where($utpMap)->sum('price');

        //今日新增业绩
        $info['historyLinkRecharge'] = priceFormat(($historyLinkRecharge ?? 0) + ($userTransformPrice ?? 0));

        //总共的新增业绩, 暂时关闭, 但是冗余记录还是有记录, 后续需要开启请注意筛选时间条件
//        $cAMap[] = ['link_uid', '=', $data['uid']];
//        $cAMap[] = ['create_time', '>=', 1677081600];
//        $cAMap[] = ['status', '=', 1];
//        $historyAllLinkRecharge = RechargeTopLinkRecord::where($cAMap)->sum('price');
        $historyAllLinkRecharge = 0;

        //查看用户2.23后自行转入的余额
        $utpAMap[] = ['uid', '=', $data['uid']];
        $utpAMap[] = ['change_type', '=', 9];
        $utpAMap[] = ['create_time', '>=', 1677081600];
        $utpAMap[] = ['status', '=', 1];
        $utpAMap[] = ['transfer_type', '<>', 1];
        $userAllTransformPrice = CrowdfundingBalanceDetail::where($utpAMap)->sum('price');

        $info['historyAllLinkRecharge'] = priceFormat(($historyAllLinkRecharge ?? 0) + ($userAllTransformPrice ?? 0));

        //查看用户冻结金额
        //计算用户历史总收入=总充值+总收益(感恩奖部分收益仅计算2.23前)+2.23前H5个人余额转入(转换)-2.23前美丽金余额转出(转赠)---第一版
        //计算用户历史总收入=( (2.23前的总充值+2.23前的总收益- 2.23前充值本金*1.3)+2.23前H5个人余额转入(转换)- 2.23前美丽金余额转出(转赠)) + (2.23后的总充值+2.23后总收益(不包含感恩奖)-2.23前充值本金*1.3) ) - (2.23后的直推30%+H5个人余额转入) + 自定义冻结额度  --第二版
//        $uiMap[] = ['uid', '=', $data['uid']];
//        $userAllIncome = CrowdfundingBalanceDetail::where($uiMap)->where(function ($query) {
//            $map1[] = ['change_type', 'in', [1, 10]];
//            $map2[] = ['change_type', '=', 9];
//            $map2[] = ['create_time', '<', 1677081600];
//            $map3[] = ['change_type', '=', 4];
//            $map3[] = ['is_grateful', '=', 1];
//            $map3[] = ['create_time', '<', 1677081600];
//            $map4[] = ['change_type', '=', 4];
//            $map4[] = ['is_grateful', '=', 2];
//            $map5[] = ['change_type', '=', 7];
//            $map5[] = ['create_time', '<', 1677081600];
//            $query->whereOr([$map1, $map2, $map3, $map4,$map5]);
//        })->where(['status' => 1])->field('price')->sum('price');

        //计算2.23前的总本金+总收益+2.23前H5个人余额转入(转换)-2.23前美丽金余额转出(转赠)
        $ubMap[] = ['uid', '=', $data['uid']];
        $ubMap[] = ['change_type', 'in', [1, 4, 7, 9, 10]];
        $ubMap[] = ['create_time', '<', 1677081600];
        $userBeforeIncome = CrowdfundingBalanceDetail::where($ubMap)
//            ->where(function ($query) {
//            $map1[] = ['change_type', '=', 10];
//            $map6[] = ['change_type', '=', 1];
//            $map6[] = ['create_time', '<', 1677081600];
//            $map2[] = ['change_type', '=', 9];
//            $map2[] = ['create_time', '<', 1677081600];
//            $map3[] = ['change_type', '=', 4];
//            $map3[] = ['create_time', '<', 1677081600];
//            $map5[] = ['change_type', '=', 7];
//            $map5[] = ['create_time', '<', 1677081600];
//            $query->whereOr([$map1, $map2, $map3,$map5,$map6]);
//        })
            ->where(['status' => 1])->field('price')->sum('price');

        //计算用户总本金*1.3倍
        //如果是财务号将别人直接转给他的记录也作为充值本金, 其他人的则仅记录财务经传的记录
        $uhcMap[] = ['uid', '=', $data['uid']];
        $uhcMap[] = ['type', '=', 1];
        $uhcMap[] = ['status', '=', 1];
//        $uhcMap[] = ['create_time', '<', 1677081600];
        $userHisCapital = CrowdfundingBalanceDetail::where(function ($query) use ($withdrawFinanceAccount, $data){
            $map1[] = ['change_type', '=', 1];
            $map2[] = ['is_transfer', '=', 1];
            $map2[] = ['transfer_from_uid', 'in', $withdrawFinanceAccount];
            if (!empty($withdrawFinanceAccount) && in_array($data['uid'], $withdrawFinanceAccount)) {
                $map3[] = ['is_transfer', '=', 1];
                $map3[] = ['is_finance', '=', 2];
                $map3[] = ['transfer_for_real_uid', '=', $data['uid']];
                $query->whereOr([$map1, $map2, $map3]);
            } else {
                $query->whereOr([$map1, $map2]);
            }
        })->where($uhcMap)->sum('price');

        //计算用户2.23前总充值金额, 不管是否提现超过1.3倍都按照总本金的1.3倍算历史总本金1.3
        //如果是财务号将别人直接转给他的记录也作为充值本金, 其他人的则仅记录财务经传的记录
        $urcMap[] = ['uid', '=', $data['uid']];
        $urcMap[] = ['type', '=', 1];
        $urcMap[] = ['status', '=', 1];
        $urcMap[] = ['create_time', '<', 1677081600];
        $userRegularCapital = CrowdfundingBalanceDetail::where(function ($query) use ($withdrawFinanceAccount, $data){
            $map1[] = ['change_type', '=', 1];
            $map2[] = ['is_transfer', '=', 1];
            $map2[] = ['transfer_from_uid', 'in', $withdrawFinanceAccount];
            if (!empty($withdrawFinanceAccount) && in_array($data['uid'], $withdrawFinanceAccount)) {
                $map3[] = ['is_transfer', '=', 1];
                $map3[] = ['is_finance', '=', 2];
                $map3[] = ['transfer_for_uid', '=', $data['uid']];
                $query->whereOr([$map1, $map2, $map3]);
            } else {
                $query->whereOr([$map1, $map2]);
            }
        })->where($urcMap)->sum('price');

        //计算用户2.23前提现成功总金额, 如果超过2.23前充值总金额1.3, 则需要在计算冻结收益时扣除超出的部分
        $ucrWMap[] = ['uid', '=', $data['uid']];
        $ucrWMap[] = ['withdraw_type', '=', 7];
        $ucrWMap[] = ['payment_status', '=', 1];
        $ucrWMap[] = ['check_status', '=', 1];
        $ucrWMap[] = ['status', '=', 1];
        $ucrWMap[] = ['create_time', '<', 1677081600];
        $userRegularWithdrawCapital = Withdraw::where($ucrWMap)->sum('total_price');
        $userRegularWithdrawCapitalBefore = $userRegularWithdrawCapital;
        if ($userRegularWithdrawCapital - ($userRegularCapital * 1.3) > 0) {
            $userRegularWithdrawCapital -= ($userRegularCapital * 1.3);
        } else {
            $userRegularWithdrawCapital = 0;
        }

        $uaMap[] = ['uid', '=', $data['uid']];
        $uaMap[] = ['create_time', '>=', 1677081600];
        //计算用户2.23后总收入, 本金+收益, 收益不包含感恩奖
        $userAfterIncome = CrowdfundingBalanceDetail::where($uaMap)->where(function ($query) {
            $map1[] = ['change_type', 'in', [1,9]];
            $map2[] = ['change_type', '=', 4];
            $map2[] = ['is_grateful', '=', 2];
            $query->whereOr([$map1, $map2]);
        })->where(['status' => 1])->field('price')->sum('price');

        //计算用户2.23后总充值金额
        $userRegularAfterCapital = $historyRecharge;

//        dump($userRegularWithdrawCapital,'多提了多少?');
//        dump($userAllIncome,'上边的是总收入');
//        dump($userRegularCapital * 1.3,'上边的是2.23前的充值1.3杯');
//        dump($info['historyAllLinkRecharge'],'上边的是总直推30%+自己H5转入的');
        //冻结收益, 如果为负则表示无冻结收益, 直接显示为0
        $freezePrice = (($userBeforeIncome - ($userRegularCapital * 1.3)) + ($userAfterIncome - ($userRegularAfterCapital * 1.3))) - $info['historyAllLinkRecharge'] - $userRegularWithdrawCapital;
        $info['freezePrice'] = priceFormat($freezePrice);
        $info['freezePrice'] = (string)$info['freezePrice'] < 0 ? 0 : $info['freezePrice'];

        //自定义冻结额度
        $cufMap[] = ['uid', '=', $data['uid']];
        $cufMap[] = ['status', '=', 1];
        $customFreezePrice = CrowdfundingFreezeCustom::where($cufMap)->sum('price');
        $info['freezePrice'] += ($customFreezePrice ?? 0);
        $info['freezePrice'] = priceFormat($info['freezePrice']);

        //查看用户总提现
        $hwAMap[] = ['uid', '=', $data['uid']];
        $hwAMap[] = ['withdraw_type', '=', 7];
//        $hwAMap[] = ['payment_status', '=', 1];
        $hwAMap[] = ['check_status', 'in', [1, 3]];
        $hwAMap[] = ['status', '=', 1];
        $hwAMap[] = ['create_time', '>=', 1677081600];
        $laterAllWithdraw = Withdraw::where($hwAMap)->sum('total_price');
        if ($userRegularWithdrawCapitalBefore - ($userRegularCapital * 1.3) > 0) {
            $before = ($userRegularCapital * 1.3);
        }else{
            $before = $userRegularWithdrawCapitalBefore;
        }
        $historyAllWithdraw = $before + $laterAllWithdraw;
        //可提现总额度
        //如果总本金额度1.3倍比总提现还要多是因为业务规定了允许超过, 这种情况直接让本金可提现为0
        //自定义提现额度
        $cusMap[] = ['uid', '=', $data['uid']];
        $cusMap[] = ['status', '=', 1];
        $cusMap[] = ['custom_type', '=', 1];
        $customPrice = CrowdfundingWithdrawCustom::where($cusMap)->sum('price');
//        dump($customPrice,'这是自定义');
        $canWithdrawCapital = ((($userHisCapital + $customPrice) * 1.3) - $historyAllWithdraw);
        if ((double)$canWithdrawCapital < 0) {
            $canWithdrawCapital = 0;
        }
//        dump(($userHisCapital + $customPrice) * 1.3,'上边是总本金+自定义的1.3倍');
//        dump($historyAllWithdraw,'上边是总提现');
//        dump($canWithdrawCapital,'上边是用户本金可提现总额');
//        dump($info['historyAllLinkRecharge'],'上边是历史直推30%+H5个人转入');
        //自定义直推奖励额度
        $cusdMap[] = ['uid', '=', $data['uid']];
        $cusdMap[] = ['status', '=', 1];
        $cusdMap[] = ['custom_type', '=', 2];
        $customDirPrice = CrowdfundingWithdrawCustom::where($cusdMap)->sum('price');

        //3.5后转出的美丽金
        $afcMap[] = ['uid', '=', $data['uid']];
        $afcMap[] = ['change_type', '=', 7];
        $afcMap[] = ['status', '=', 1];
        $afcMap[] = ['create_time', '>=', 1677945600];
        $afterTransferPrice = CrowdfundingBalanceDetail::where($afcMap)->sum('price');

        $canWithdrawPrice = $canWithdrawCapital + ($info['historyAllLinkRecharge'] + ($customDirPrice ?? 0)) + ($afterTransferPrice ?? 0);
//        dump($canWithdrawPrice,'这是总可提现余额');die;
        $info['canWithdrawPrice'] = priceFormat($canWithdrawPrice);

        //如果冻结额度大于0则自动开启美丽金转赠, 小于则关闭
        if (!in_array($data['uid'], $withdrawFinanceAccount)) {
            $userTransferStatus = User::where(['uid' => $data['uid'], 'status' => 1])->value('ban_crowd_transfer');
            if ($info['freezePrice'] <= 0) {
                if ($userTransferStatus = 2) {
                    User::update(['ban_crowd_transfer' => 1], ['uid' => $data['uid'], 'status' => 1]);
                }
            } else {
                if ($userTransferStatus = 1) {
                    User::update(['ban_crowd_transfer' => 2], ['uid' => $data['uid'], 'status' => 1]);
                }
            }
        }
        return $info;
    }

    /**
     * @title  用户历史充值和提现
     * @param array $data
     * @return mixed
     * @remark
     */
    public function userHisWithdrawAndRecharge(array $data)
    {
        //查看用户累计提现金额,2.23前
        $hwMap[] = ['uid', '=', $data['uid']];
        $hwMap[] = ['withdraw_type', '=', 7];
        $hwMap[] = ['payment_status', '=', 1];
        $hwMap[] = ['check_status', '=', 1];
        $hwMap[] = ['status', '=', 1];
        $hwMap[] = ['create_time', '<', 1677081600];
        $historyWithdraw = Withdraw::where($hwMap)->sum('total_price');
        $info['historyWithdraw'] = priceFormat($historyWithdraw ?? 0);

        //财务号
        $financeUid = SystemConfig::where(['id' => 1])->value('finance_uid');
        $withdrawFinanceAccount = !empty($financeUid) ? explode(',', $financeUid) : ['notFinanceUid'];

        //查看用户累计充值金额,2.23前
        $rMap[] = ['uid', '=', $data['uid']];
        $rMap[] = ['type', '=', 1];
        $rMap[] = ['status', '=', 1];
        $rMap[] = ['create_time', '<', 1677081600];
        $historyRecharge = CrowdfundingBalanceDetail::where($rMap)->where(function ($query) use ($withdrawFinanceAccount){
            $map1[] = ['change_type', '=', 1];
            $map2[] = ['is_transfer', '=', 1];
            $map2[] = ['transfer_from_uid', 'in', $withdrawFinanceAccount];
            $query->whereOr([$map1, $map2]);
        })->sum('price');
        $info['historyRecharge'] = priceFormat($historyRecharge ?? 0);

        return $info;
    }

}