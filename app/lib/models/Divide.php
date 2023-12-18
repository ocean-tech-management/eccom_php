<?php
// +----------------------------------------------------------------------
// |[ 文档说明: ]
// +----------------------------------------------------------------------
// | Copyright (c) 2018~2024 http://www.mlhcmk.com All rights reserved.
// +----------------------------------------------------------------------


namespace app\lib\models;


use app\BaseModel;
use app\lib\exceptions\ParamException;
use app\lib\exceptions\ServiceException;
use app\lib\models\Divide as DivideModel;
use think\facade\Cache;
use think\facade\Db;
use function GuzzleHttp\Promise\all;

class Divide extends BaseModel
{
    //第三方金融服务(微信支付,汇聚支付等)的手续费比例
    private $financialHandlingFee = 0.004;
    private $kuaiShangFinancialHandlingFee = 0.068;

    public function user()
    {
        return $this->hasOne('user', 'uid', 'link_uid')->bind(['username' => 'name']);
    }


    public function leader()
    {
        return $this->hasOne('divide', 'order_sn', 'order_sn')
            ->where('status', 'in', [1, 2])
            ->order('level', 'desc')->bind(['level', 'divide_type', 'purchase_price' => 'purchase_price', 'cost_price' => 'cost_price'])->hidden([
                'id', 'order_sn', 'order_uid', 'belong', 'type', 'goods_sn', 'sku_sn', 'price', 'count', 'vdc', 'vdc_genre', 'integral'
            ]);
    }

    public function goodsCost()
    {
        return $this->hasOne('goodsSku', 'sku_sn', 'sku_sn')->bind(['cost_price' => 'cost_price']);
    }


    public function farePrice()
    {
        return $this->hasOne('order', 'order_sn', 'order_sn')->bind(['fare_price', 'real_pay_price']);
    }

    public function linkUser()
    {
        return $this->hasOne('user', 'uid', 'link_uid')->bind(['link_user_name' => 'name', 'link_user_phone' => 'phone', 'link_user_avatarUrl' => 'avatarUrl']);
    }

    /**
     * @title  订单收益列表汇总
     * @param array $sear
     * @param array $cache
     * @return array|mixed
     * @throws \Exception
     */
    public function console(array $sear = [], array $cache = [])
    {
        $cacheSear = $sear;
        if (!empty($sear['start_time']) && !empty($sear['end_time'])) {
            $cacheSear['start_time'] = strtotime(date('Y-m-d', substr($sear['start_time'], 0, 10)) . ' 00:00:00');
            $cacheSear['end_time'] = strtotime(date('Y-m-d', substr($sear['end_time'], 0, 10)) . ' 23:59:59');
        }

        $sign = md5(json_encode($cacheSear));
        $data = Cache::get($sign);
        $data = [];
        if ($data) return $data;
        $divideOrder = [];
        $notDivideOrders = [];
        $map = [];
        if (!empty($sear['order_sn'])) {
            $map[] = ['a.order_sn', 'like', ['%' . $sear['order_sn'] . '%']];
        }

        if (!empty($sear['username'])) {
            $map[] = ['b.link_uid', 'in', $this->getIdsByTeamLeaderName($sear['username'])];
        }

        if (!empty($sear['start_time']) && !empty($sear['end_time'])) {
            $map[] = ['a.create_time', 'between', [substr($sear['start_time'], 0, 10), substr($sear['end_time'], 0, 10)]];
        }

        //搜索商品
        if (!empty($sear['goods_sku'])) {
            if (is_array($sear['goods_sku'])) {
                $map[] = ['a.sku_sn', 'in', $sear['goods_sku']];
            } else {
                $map[] = ['a.sku_sn', '=', trim($sear['goods_sku'])];
            }
        }
        //按供应商搜索商品
        if (!empty($sear['supplier_code'])) {
            $supplierGoodsSku = GoodsSku::where(['supplier_code' => trim($sear['supplier_code'])])->column('sku_sn');
            if (!empty($supplierGoodsSku)) {
                $map[] = ['a.sku_sn', 'in', $sear['goods_sku']];
            }
        }


//        $map[] = ['b.status', 'in', $this->getStatusByRequestModule($sear['searType'] ?? 1)];

        //第一种查询方法(新)
//        $aList = $this->where($map)->order('level desc')->buildSql();
//        $nowField = 'a.id,a.type,a.order_sn,a.total_price,a.link_uid,SUM( a.real_divide_price ) AS divide_price,a.arrival_status,a.create_time,a.is_vip_divide,a.LEVEL,a.divide_type,a.purchase_price,a.sku_sn,b.fare_price,b.real_pay_price,c.cost_price';
//        $list = Db::table($aList . ' a')
//            ->join('sp_order b', 'a.order_sn = b.order_sn', 'left')
//            ->join('sp_goods_sku c', 'a.sku_sn = c.sku_sn', 'left')
//            ->field($nowField)->group('order_sn')->select()->each(function ($item) {
//                if (!$item['fare_price']) $item['fare_price'] = 0;
//                //服务费为实际支付金额服务费，如有运费，运费的支付服务费也计算入成本
//                $item['server_price'] = number_format($item['real_pay_price'] * 0.007, 2);
//                $item['profit'] = number_format($item['total_price'] - $item['server_price'] - $item['cost_price'] - $item['divide_price'], 2);
//                return $item;
//            })->toArray();

        if (empty($sear['username'])) {
            //获取没有分润订单的订单号,再计算利润
            $aqMap = $map;
            foreach ($aqMap as $key => $value) {
                foreach ($value as $k => $v) {
                    if (is_string($v)) {
                        $aqMap[$key][$k] = str_replace('a.sku_sn', 'd.sku_sn', $aqMap[$key][$k]);
                    }
                }
            }
            $aqMap[] = ['a.pay_status', '=', 2];
            $allOrderList = Order::alias('a')->join('sp_order_goods d', 'a.order_sn = d.order_sn')->where($aqMap)->column('a.order_sn');
            if (!empty($allOrderList)) {
                $allOrderList = array_unique(array_filter($allOrderList));
            }

            $dqMap = $map;
            $dqMap[] = ['a.status', 'in', [1, 2, 3]];
            $allDivideList = Divide::alias('a')->where($dqMap)->column('order_sn');
            if (!empty($allDivideList)) {
                $allDivideList = array_unique(array_filter($allDivideList));
            }

            $notHaveDivideOrderSn = array_diff($allOrderList, $allDivideList);
            if (!empty($notHaveDivideOrderSn)) {
                $allOrderMap = $map;
                foreach ($allOrderMap as $key => $value) {
                    foreach ($value as $k => $v) {
                        if (is_string($v)) {
                            $allOrderMap[$key][$k] = str_replace('a.sku_sn', 'd.sku_sn', $allOrderMap[$key][$k]);
                        }
                    }
                }
                $allOrderMap[] = ['a.order_sn', 'in', $notHaveDivideOrderSn];
                $allOrderMap[] = ['a.pay_status', '=', 2];

                $allList = Order::alias('a')->where($allOrderMap)->field('a.order_sn,d.total_price,b.order_sn as divide_order_sn,b.id,b.type,b.link_uid,b.real_divide_price,b.order_uid,b.count,b.arrival_status,b.create_time,b.is_vip_divide,b.level,b.divide_type,b.purchase_price,d.goods_sn,d.sku_sn,d.price,d.total_fare_price as fare_price,a.real_pay_price as order_real_pay_price,a.discount_price,d.coupon_dis,(c.cost_price * d.count) as cost_price,d.real_pay_price,d.refund_price,d.shipping_status,c.title as goods_title,c.specs as goods_specs,d.status as order_goods_status,d.after_status as order_goods_after_status')
                    ->join('sp_divide b', 'a.order_sn = b.order_sn and b.status in (1,2,3)', 'left')
                    ->join('sp_order_goods d', 'a.order_sn = d.order_sn', 'left')
                    ->join('sp_goods_sku c', 'c.sku_sn = d.sku_sn', 'left')->order('a.create_time desc,b.level desc')
                    ->select()
                    ->each(function ($item) {
                        if (empty($item['divide_type'])) {
                            $item['divide_price'] = 0;
                            $item['direct_price'] = 0;
                            $item['indirect_price'] = 0;
                            $item['original_cost_price'] = $item['cost_price'];
                            if (!$item['fare_price']) $item['fare_price'] = 0;
                            //服务费为实际支付金额服务费，如有运费，运费的支付服务费,折扣费用也计算入成本
                            if (!empty(doubleval($item['refund_price'])) && ((string)$item['refund_price'] >= (string)($item['real_pay_price'] - ($item['fare_price'] ?? 0))) && (in_array($item['shipping_status'], [1, 2]))) {
                                $item['server_price'] = 0;
                                $item['profit'] = 0;
                                $item['cost_price'] = 0;
//                                $item['coupon_dis'] = 0;
                                $item['total_price'] += $item['fare_price'];
                            } else {
                                $item['server_price'] = priceFormat(($item['real_pay_price'] - ($item['refund_price'] ?? 0)) * $this->financialHandlingFee);
                                $item['profit'] = priceFormat($item['total_price'] - ($item['server_price'] ?? 0) - ($item['cost_price'] ?? 0) - ($item['divide_price'] ?? 0) - ($item['coupon_dis'] ?? 0) - ($item['refund_price'] ?? 0));
                            }
                        }
                        return $item;
                    })
                    ->toArray();
//                if(!empty($allList)){
//                    $allList = $this->summaryAndProfit($allList,1);
//                }
            }
        }

        if (!empty($allList)) {
            foreach ($allList as $key => $value) {
                if (!empty($value['divide_type'])) {
                    $divideOrder[] = $value['order_sn'];
                } else {
                    //没有分润的订单
                    $notDivideOrders[] = $value['order_sn'];
                }
            }
        }
//        dump($allList);
//        dump($notDivideOrders);
        //计算分润订单的利润
        $dMap = $map;
        foreach ($dMap as $key => $value) {
            foreach ($value as $k => $v) {
                if (is_string($v)) {
                    $dMap[$key][$k] = str_replace('a.', '', $dMap[$key][$k]);
                    $dMap[$key][$k] = str_replace('b.', '', $dMap[$key][$k]);
                }
            }
        }
//        $dMap[] = ['status', 'in', $this->getStatusByRequestModule($sear['searType'] ?? 1)];
        //剔除上面查询到的没有分润的订单,避免重复统计
        if (!empty($notDivideOrders)) {
            $dMap[] = ['order_sn', 'not in', array_unique(array_filter($notDivideOrders))];
        }

        $aList = $this->where($dMap)->order('level desc')->buildSql();

        $nowField = 'a.type,a.order_sn,d.total_price,a.link_uid,SUM( IF ( a.status in (1,2,3), a.real_divide_price, 0)) AS divide_price,SUM( IF ( a.divide_type = 1 and a.status in (1,2,3), a.real_divide_price, 0 ) ) AS direct_price,SUM( IF ( a.divide_type = 2 and a.status in (1,2,3), a.real_divide_price, 0 ) ) AS indirect_price,a.price,a.order_uid,a.count,a.arrival_status,a.create_time,a.is_vip_divide,a.LEVEL,a.divide_type,a.purchase_price,a.sku_sn,b.real_pay_price as order_real_pay_price,b.discount_price,d.coupon_dis,(c.cost_price * a.count) as cost_price,d.real_pay_price,d.status as order_goods_status,d.refund_price,d.total_fare_price as fare_price,d.shipping_status,d.goods_sn,c.title as goods_title,c.specs as goods_specs,d.status as order_goods_status,d.after_status as order_goods_after_status';
        //$bList
        $list = Db::table($aList . ' a')
            ->join('sp_order b', 'a.order_sn = b.order_sn', 'left')
            ->join('sp_goods_sku c', 'a.sku_sn = c.sku_sn', 'left')
            ->join('sp_order_goods d', 'd.sku_sn = c.sku_sn and a.order_sn = d.order_sn', 'left')
            ->field($nowField)->group('order_sn,sku_sn')->order('create_time desc')
            ->select()
            ->each(function ($item) {
                if (!$item['fare_price']) $item['fare_price'] = 0;
                $item['original_cost_price'] = $item['cost_price'];
                //服务费为实际支付金额服务费，如有运费，运费的支付服务费,折扣费用也计算入成本
                if (!empty(doubleval($item['refund_price'])) && ((string)$item['refund_price'] >= (string)($item['real_pay_price'] - ($item['fare_price'] ?? 0))) && in_array($item['shipping_status'], [1, 2])) {
                    $item['server_price'] = 0;
                    $item['profit'] = 0;
                    $item['cost_price'] = 0;
//                    $item['coupon_dis'] = 0;
                    $item['total_price'] += $item['fare_price'];
                } else {
                    $item['server_price'] = priceFormat(($item['real_pay_price'] - ($item['refund_price'] ?? 0)) * $this->financialHandlingFee);
                    $profit = $item['total_price'] - ($item['server_price'] ?? 0) - ($item['cost_price'] ?? 0) - ($item['divide_price'] ?? 0) - ($item['coupon_dis'] ?? 0) - ($item['refund_price'] ?? 0);
                    $item['profit'] = priceFormat($profit);
                }
                return $item;
            })
            ->toArray();
//            if(!empty($list)){
//                $list = $this->summaryAndProfit($list,2);
//            }
//        //以下为二合一方法
//        $list = Db::table($bList . ' b')
//            ->join('sp_user d','b.order_uid = d.uid','left')
//            ->field('b.*,sum(b.total_price) as total_price,sum(b.cost_price) as cost_price,sum(b.fare_price) as fare_price,sum(b.purchase_price) as purchase_price,d.name,d.phone as order_user_phone,d.avatarUrl as order_user_avatarUrl,sum(b.coupon_dis) as coupon_dis,sum(b.refund_price) as refund_price')
//            ->group('order_sn')
//            ->order('create_time desc')
//            ->select()->each(function ($item) {
//                if (!$item['fare_price']) $item['fare_price'] = 0;
//                //服务费为实际支付金额服务费，如有运费，运费的支付服务费,折扣费用也计算入成本
//                if(!empty($item['refund_price']) && ((string)$item['refund_price'] >= (string)($item['real_pay_price'] - ($item['fare_price'] ?? 0)))) {
//                    $item['server_price'] = 0;
//                    $item['profit'] = 0;
//                }else{
//                    $item['server_price'] = number_format($item['real_pay_price'] * 0.007, 2);
//                    $item['profit'] = number_format($item['total_price'] - ($item['server_price'] ?? 0) - ($item['cost_price'] ?? 0) - ($item['divide_price'] ?? 0) - ($item['coupon_dis'] ?? 0) - ($item['refund_price'] ?? 0), 2);
//                }
//                return $item;
//            })->toArray();

        //第二种查询方法(旧)
//        $field = $this->getListFieldByModule('list');
//        $list = $this->where($map)->field($field)->group('order_sn')
//            ->with(['leader', 'leader.goodsCost', 'farePrice'])
//            ->select()->each(function ($item) {
//                if (!$item['fare_price']) $item['fare_price'] = 0;
////               服务费为实际支付金额服务费，如有运费，运费的支付服务费也计算入成本
//                $item['server_price'] = number_format($item['real_pay_price'] * 0.007, 2);
//                $item['profit'] = number_format($item['total_price'] - $item['server_price'] - $item['cost_price'] - $item['divide_price'], 2);
//                return $item;
//            })->toArray();

        $totalPrice = 0;
        $realTotalPrice = 0;
        $totalCost = 0;
        $totalDivide = 0;
        $totalProfit = 0;
        $totalRefund = 0;
        $totalDirectPrice = 0;
        $totalIndirectPrice = 0;
        $totalServerPrice = 0;

        if (!empty($list)) {
            foreach ($list as $item) {
                $totalPrice += ($item['total_price']);
                $totalCost += ($item['cost_price']);
                $totalDivide += ($item['divide_price']);
                $totalProfit += ($item['profit']);
                $totalRefund += ($item['refund_price']);
                $totalDirectPrice += ($item['direct_price']);
                $totalIndirectPrice += ($item['indirect_price']);
                $realTotalPrice += ($item['real_pay_price']);
                $totalServerPrice += ($item['server_price']);
            }
        }

        if (!empty($allList)) {
            foreach ($allList as $item) {
                if (empty($item['divide_type'])) {
                    $totalPrice += ($item['total_price']);
                    $totalCost += ($item['cost_price']);
                    $totalDivide += ($item['divide_price'] ?? 0);
                    $totalProfit += ($item['profit']);
                    $totalRefund += ($item['refund_price']);
                    $totalDirectPrice += ($item['direct_price']);
                    $totalIndirectPrice += ($item['indirect_price']);
                    $realTotalPrice += ($item['real_pay_price']);
                    $totalServerPrice += ($item['server_price']);
                }
            }
        }

        $data = [
            'total_price' => priceFormat($totalPrice),
            'total_cost' => priceFormat($totalCost),
            'total_divide' => priceFormat($totalDivide),
            'total_profit' => priceFormat($totalProfit),
            'total_refund' => priceFormat($totalRefund),
            'total_direct_price' => priceFormat($totalDirectPrice),
            'total_indirect_price' => priceFormat($totalIndirectPrice),
            'real_total_price' => priceFormat($realTotalPrice - ($totalRefund ?? 0)),
            'real_total_price_not_refund' => priceFormat($realTotalPrice ?? 0),
            'total_server_price' => priceFormat($totalServerPrice ?? 0),
        ];

        Cache::set($sign, $data, !empty($cache['cache_expire']) ? $cache['cache_expire'] : 10800);

        return $data;
    }

    /**
     * @title  收益列表
     * @param array $sear
     * @return array
     * @throws \Exception
     */
    public function list(array $sear)
    {
//        $cacheSear = $sear;
//        if (!empty($sear['start_time']) && !empty($sear['end_time'])) {
//            $cacheSear['start_time'] = strtotime(date('Y-m-d', substr($sear['start_time'], 0, 10)) . ' 00:00:00');
//            $cacheSear['end_time'] = strtotime(date('Y-m-d', substr($sear['end_time'], 0, 10)) . ' 23:59:59');
//        }
//        $cache['cache_expire'] = $sear['cache_expire'] ?? (3600 * 3);
//        if (!empty($sear['needCache'] ?? false)) {
//            $sign = md5(json_encode($cacheSear));
//            $data = Cache::get($sign);
//            if (!empty($data)) {
//                return $data;
//            }
//        }

        $map = [];
        //分组类型 1为按照订单分组 2为按照订单+sku分组
        $groupType = 2;
        if (!empty($sear['order_sn'])) {
            $map[] = ['a.order_sn', 'like', ['%' . $sear['order_sn'] . '%']];
        }

        if (!empty($sear['start_time']) && !empty($sear['end_time'])) {
            $map[] = ['a.create_time', 'between', [substr($sear['start_time'], 0, 10), substr($sear['end_time'], 0, 10)]];
        }
        //搜索商品
        if (!empty($sear['goods_sku'])) {
            if (is_array($sear['goods_sku'])) {
                $map[] = ['a.sku_sn', 'in', $sear['goods_sku']];
            } else {
                $map[] = ['a.sku_sn', '=', trim($sear['goods_sku'])];
            }
        }
        //按供应商搜索商品
        if (!empty($sear['supplier_code'])) {
            $supplierGoodsSku = GoodsSku::where(['supplier_code' => trim($sear['supplier_code'])])->column('sku_sn');
            if (!empty($supplierGoodsSku)) {
                $map[] = ['a.sku_sn', 'in', $sear['goods_sku']];
            }
        }

        if (!empty($sear['username'])) {
            $map[] = ['b.link_uid', 'in', $this->getIdsByTeamLeaderName($sear['username'])];
        }


//        $map[] = ['status', 'in', $this->getStatusByRequestModule($sear['searType'] ?? 1)];
        $page = intval($sear['page'] ?? 0) ?: null;

        $field = $this->getListFieldByModule('list');
        $pageNumber = !empty($sear['pageNumber']) ? intval($sear['pageNumber']) : $this->pageNumber;

        if (!empty($page)) {
            if (!empty($sear['username'])) {
                if (!empty($map)) {
                    //查询订单状态, 因为订单状态归属于订单,为了能直接查询到单个商品的状态(如发货,售后等),这里对查询条件做调整
                    if (!empty($sear['searType'])) {
                        switch ($sear['searType'] ?? 1) {
                            case 2:
                                $map[] = ['a.shipping_status', 'in', [1,2]];
                                break;
                            case 3:
                                $map[] = ['a.shipping_status', 'in', 3];
                                $map[] = ['d.after_status', 'in', [-1,1]];
                                break;
                            case 5:
                                $map[] = ['d.after_status', '=', 2];
                                break;
                            case 6:
                                $map[] = ['d.after_status', '=', 3];
                                break;
                            case 7:
                                $map[] = ['d.after_status', '=', 4];
                                break;
                        }
                    }
                    foreach ($map as $key => $value) {
                        foreach ($value as $k => $v) {
                            if (is_string($v)) {
                                $map[$key][$k] = str_replace('a.', '', $map[$key][$k]);
                                $map[$key][$k] = str_replace('b.', '', $map[$key][$k]);
                            }
                        }
                    }
                }
                if (empty($sear['searType'] ?? null) && empty($sear['activity_sign'])) {
                    $total = self::where($map)->group('order_sn,sku_sn')->count();
                }else{
                    //订单状态
                    $totalSql = self::where($map)->group('order_sn,sku_sn')->buildSql();
                    //活动
                    if (!empty($sear['activity_sign'] ?? null) && !empty($sear['searType'] ?? null)) {
                        if ($sear['searType'] != 2) {
                            $qMap[] = ['d.activity_sign', '=', $sear['activity_sign']];
                            $qMap[] = ['b.order_type', '=', $sear['order_type']];
                        } else {
                            $qMap[] = ['b.order_status', '=', $sear['searType']];
                        }

                        $total = Db::table($totalSql . ' a')
                            ->join('sp_order b', 'a.order_sn = b.order_sn', 'left')
                            ->join('sp_order_goods d', 'a.order_sn = d.order_sn', 'left')
                            ->where($qMap)->count();
                    } elseif (empty($sear['activity_sign'] ?? null) && !empty($sear['searType'] ?? null)) {
                        $qMap = [];
                        if ($sear['searType'] == 2) {
                            $qMap[] = ['b.order_status', '=', $sear['searType']];
                        }
                        $total = Db::table($totalSql . ' a')
                            ->join('sp_order b', 'a.order_sn = b.order_sn', 'left')->where($qMap)->count();
                    } else {
                        $qMap[] = ['d.activity_sign', '=', $sear['activity_sign']];
                        $qMap[] = ['b.order_type', '=', $sear['order_type']];

                        $total = Db::table($totalSql . ' a')
                            ->join('sp_order b', 'a.order_sn = b.order_sn', 'left')
                            ->join('sp_order_goods d', 'a.order_sn = d.order_sn', 'left')
                            ->where($qMap)->count();
                    }

                }

            } else {
                $ocMap = $map;
                $ocMap[] = ['a.pay_status', '=', 2];
                //退全款的商品不查询
                $ocMap[] = ['', 'exp', Db::raw('a.refund_price < a.real_pay_price')];
                //查询订单状态,因为订单状态归属于订单,为了能直接查询到单个商品的状态(如发货,售后等),这里对查询条件做调整
                if (!empty($sear['searType'] ?? null)) {
                    switch ($sear['searType'] ?? 1) {
                        case 2:
                            $ocMap[] = ['a.shipping_status', 'in', [1,2]];
                            break;
                        case 3:
                            $ocMap[] = ['b.order_status', 'in', $sear['searType']];
                            $ocMap[] = ['a.shipping_status', '=', 3];
                            $ocMap[] = ['a.after_status', 'in', [-1,1]];
                            break;
                        case 5:
                            $ocMap[] = ['a.after_status', '=', 2];
                            break;
                        case 6:
                            $ocMap[] = ['a.after_status', '=', 3];
                            break;
                        case 7:
                            $ocMap[] = ['a.after_status', '=', 4];
                            break;
                        default:
                            $ocMap[] = ['b.order_status', 'in', $sear['searType']];
                            break;
                    }
                }

                //活动
                if (!empty($sear['activity_sign'] ?? null)) {
                    $ocMap[] = ['a.activity_sign', 'in', $sear['activity_sign']];
                    $ocMap[] = ['b.order_type', '=', $sear['order_type']];
                }

                $total = OrderGoods::alias('a')
                    ->join('sp_order b', 'a.order_sn = b.order_sn', 'left')
                    ->where($ocMap)->group('a.order_sn,a.sku_sn')->count();

            }
            $pageTotal = ceil($total / $this->pageNumber);
        }

        if (empty($sear['username'])) {
            $oMap = $map;
//            $oMap[] = ['a.status','=',1];
            $oMap[] = ['a.pay_status', '=', 2];
            //退全款的商品不查询
            $oMap[] = ['', 'exp', Db::raw('a.refund_price < a.real_pay_price')];
            //查询订单状态
            if (!empty($sear['searType'] ?? null)) {
                switch ($sear['searType'] ?? 1) {
                    case 2:
                        $oMap[] = ['a.shipping_status', 'in', [1,2]];
                        break;
                    case 3:
                        $oMap[] = ['b.order_status', 'in', $sear['searType']];
                        $oMap[] = ['a.shipping_status', '=', 3];
                        $oMap[] = ['a.after_status', 'in', [-1,1]];
                        break;
                    case 5:
                        $oMap[] = ['a.after_status', '=', 2];
                        break;
                    case 6:
                        $oMap[] = ['a.after_status', '=', 3];
                        break;
                    case 7:
                        $oMap[] = ['a.after_status', '=', 4];
                        break;
                    default:
                        $oMap[] = ['b.order_status', 'in', $sear['searType']];
                        break;
                }
            }
            //活动
            if (!empty($sear['activity_sign'] ?? null)) {
                $oMap[] = ['a.activity_sign', 'in', $sear['activity_sign']];
                $oMap[] = ['b.order_type', '=', $sear['order_type']];
            }
            $allOrderList = OrderGoods::alias('a')
                ->join('sp_order b', 'a.order_sn = b.order_sn', 'left')
                ->group('a.order_sn,a.sku_sn')->order('a.create_time desc')->where($oMap)->when($page, function ($query) use ($page, $pageNumber) {
                $query->page($page, $pageNumber);
            })->column('a.order_sn');
            if(!empty($allOrderList)){
                $allOrderList = array_unique($allOrderList);
            }

//            $allDivideList = Divide::where(['status'=>[1,2,3],'order_sn'=>$allOrderList])->column('order_sn');
//            $notHaveDivideOrderSn = array_diff($allOrderList,$allDivideList);
            $notHaveDivideOrderSn = $allOrderList;
            if (!empty($notHaveDivideOrderSn)) {
                $allOrderMap = $map;
                if (!empty($allOrderMap)) {
                    foreach ($allOrderMap as $key => $value) {
                        foreach ($value as $k => $v) {
                            if (is_string($v)) {
                                $allOrderMap[$key][$k] = str_replace('a.sku_sn', 'd.sku_sn', $allOrderMap[$key][$k]);
                            }
                        }
                    }
                }
                $allOrderMap[] = ['a.order_sn', 'in', $notHaveDivideOrderSn];
                //退全款的订单不展示
                $allOrderMap[] = ['', 'exp', Db::raw('d.refund_price < d.real_pay_price')];
                //查询订单状态,因为订单状态归属于订单,为了能直接查询到单个商品的状态(如发货,售后等),这里对查询条件做调整
                if (!empty($sear['searType'] ?? null)) {
                    switch ($sear['searType'] ?? 1) {
                        case 2:
                            $allOrderMap[] = ['d.shipping_status', 'in', [1,2]];
                            break;
                        case 3:
                            $allOrderMap[] = ['a.order_status', 'in', $sear['searType']];
                            $allOrderMap[] = ['d.shipping_status', '=', 3];
                            $allOrderMap[] = ['d.after_status', 'in', [-1,1]];
                            break;
                        case 5:
                            $allOrderMap[] = ['d.after_status', '=', 2];
                            break;
                        case 6:
                            $allOrderMap[] = ['d.after_status', '=', 3];
                            break;
                        case 7:
                            $allOrderMap[] = ['d.after_status', '=', 4];
                            break;
                        default:
                            $allOrderMap[] = ['a.order_status', 'in', $sear['searType']];
                            break;
                    }
                }
                //活动
                if (!empty($sear['activity_sign'] ?? null)) {
                    $allOrderMap[] = ['d.activity_sign', 'in', $sear['activity_sign']];
                    $allOrderMap[] = ['a.order_type', '=', $sear['order_type']];
                }
                $allListSql = Order::alias('a')->where($allOrderMap)->field('a.order_sn,d.total_price,b.order_sn as divide_order_sn,b.id,b.type,b.link_uid,b.real_divide_price,a.uid as order_uid,b.arrival_status,b.is_vip_divide,b.level,a.user_level,b.divide_type,b.purchase_price,d.sku_sn,d.price,d.total_fare_price as fare_price,a.real_pay_price as order_real_pay_price,a.discount_price,d.count,d.coupon_dis,(d.cost_price * d.count) as cost_price,c.cost_price as goods_cost_price,d.real_pay_price,a.create_time,a.uid as order_old_uid,d.specs as goods_specs,d.title as goods_title,d.refund_price,a.order_status,a.create_time as order_create_time,d.goods_sn,d.status as order_goods_status,d.after_status as order_goods_after_status,d.supplier_code,d.shipping_status,a.coder_remark,a.end_time,d.activity_sign,a.order_type')
                    ->join('sp_divide b', 'a.order_sn = b.order_sn and b.status in (1,2,3)', 'left')
                    ->join('sp_order_goods d', 'a.order_sn = d.order_sn', 'left')
                    ->join('sp_goods_sku c', 'c.sku_sn = d.sku_sn', 'left')->order('a.create_time desc,b.level desc')
                    ->group('a.order_sn,d.sku_sn')
                    ->order('a.create_time desc')
                    ->select()
                    ->each(function($item){
                        $item['correction_fare'] = 0;
                        $item['correction_supplier'] = 0;
                        $item['correction_cost'] = 0;
                        $orderCorrectionSql = OrderCorrection::where(['order_sn'=>$item['order_sn'],'sku_sn'=>$item['sku_sn'],'status'=>1])->order('create_time desc,id desc')->limit(0,1000)->buildSql();
                        $orderCorrectionList = Db::table($orderCorrectionSql . ' a')->group('order_sn,sku_sn,type')->select()->toArray();
                        if(!empty($orderCorrectionList)){
                            foreach ($orderCorrectionList as $key => $value) {
                                switch ($value['type']){
                                    case 1:
                                        $item['correction_fare'] = $value['price'];
                                        break;
                                    case 2:
                                        $item['correction_supplier'] = $value['price'];
                                        break;
                                    case 3:
                                        $item['correction_cost'] = $value['price'];
                                        break;
                                    default:
                                        break;
                                }
                            }
                        }
                        if (!empty($item['end_time'] ?? null) && is_numeric($item['end_time'])) {
                            $item['end_time'] = timeToDateFormat($item['end_time']);
                        }
                        $item['activity_name'] = null;
                        $item['order_user_top_info'] = [];
                        return $item;
                    })
                    ->toArray();

                if (!empty($allListSql)) {
                    $allList = $this->summaryAndProfit($allListSql, 1);
                }
            }
        }

        if (!empty($allList)) {
            foreach ($allList as $key => $value) {
                if (!empty($value['divide_type'])) {
                    $divideOrder[] = $value['order_sn'];
                }
            }
        }

        if (empty($divideOrder) && !empty($allList)) {
            $list = $allList;
        }

        if (!empty($divideOrder) || !empty($sear['username'])) {
            $dMap = $map;
            if (!empty($dMap)) {
                foreach ($dMap as $key => $value) {
                    foreach ($value as $k => $v) {
                        if (is_string($v)) {
//                            //因为分润时间跟订单创建时间存在一点时间差,所以在查分润的时候剔除时间,按照订单下单时间判断
                            if (strstr($v, 'create_time')) {
                                unset($dMap[$key]);
                                continue 2;
                            }
                            $dMap[$key][$k] = str_replace('a.', '', $dMap[$key][$k]);
                            $dMap[$key][$k] = str_replace('b.', '', $dMap[$key][$k]);
                        }
                    }
                }
            }
            if (empty($dMap)) {
                $dMap = [];
            }else{
                $dMap = array_values($dMap);
            }
//            $dMap[] = ['status', 'in', $this->getStatusByRequestModule($sear['searType'] ?? 1)];
            if (!empty($divideOrder)) {
                $dMap[] = ['order_sn', 'in', array_unique(array_filter($divideOrder))];
            }
            //第一种查询方法(新)
            $aList = $this->where($dMap)->order('level desc')->buildSql();

            //退全款的订单不展示
            $ddMap[] = ['', 'exp', Db::raw('d.refund_price < d.real_pay_price')];
            //查询订单状态,因为订单状态归属于订单,为了能直接查询到单个商品的状态(如发货,售后等),这里对查询条件做调整
            if (!empty($sear['searType'] ?? null)) {
                switch ($sear['searType'] ?? 1) {
                    case 2:
                        $ddMap[] = ['d.shipping_status', 'in', [1,2]];
                        break;
                    case 3:
                        $ddMap[] = ['b.order_status', 'in', $sear['searType']];
                        $ddMap[] = ['d.shipping_status', '=', 3];
                        $ddMap[] = ['d.after_status', 'in', [-1,1]];
                        break;
                    case 5:
                        $ddMap[] = ['d.after_status', '=', 2];
                        break;
                    case 6:
                        $ddMap[] = ['d.after_status', '=', 3];
                        break;
                    case 7:
                        $ddMap[] = ['d.after_status', '=', 4];
                        break;
                    default:
                        $ddMap[] = ['b.order_status', 'in', $sear['searType']];
                        break;
                }
            }
            //活动
            if (!empty($sear['activity_sign'] ?? null)) {
                $ddMap[] = ['d.activity_sign', 'in', $sear['activity_sign']];
                $ddMap[] = ['b.order_type', '=', $sear['order_type']];
            }
            $nowField = 'a.id,a.type,a.order_sn,d.total_price,a.link_uid,SUM( IF ( a.status in (1,2,3), a.real_divide_price, 0)) AS divide_price,SUM( IF ( a.divide_type = 1 and a.status in (1,2,3), a.real_divide_price, 0 ) ) AS direct_price,SUM( IF ( a.divide_type = 2 and a.status in (1,2,3), a.real_divide_price, 0 ) ) AS indirect_price,a.price,a.order_uid,a.order_uid as order_old_uid,a.count,a.arrival_status,a.create_time,a.is_vip_divide,a.level,a.divide_type,a.purchase_price,a.goods_sn,a.sku_sn,d.total_fare_price as fare_price,b.real_pay_price as order_real_pay_price,b.discount_price,d.coupon_dis,c.cost_price as goods_cost_price,(d.cost_price * d.count) as cost_price,d.real_pay_price,d.title as goods_title,d.specs as goods_specs,b.create_time as order_create_time,b.order_status,d.refund_price,d.status as order_goods_status,d.after_status as order_goods_after_status,d.supplier_code,b.coder_remark,b.end_time,d.activity_sign,b.order_type';
            //$bList
            $bList = Db::table($aList . ' a')
                ->join('sp_order b', 'a.order_sn = b.order_sn', 'left')
                ->join('sp_goods_sku c', 'a.sku_sn = c.sku_sn', 'left')
                ->join('sp_order_goods d', 'd.sku_sn = c.sku_sn and a.order_sn = d.order_sn', 'left')
                ->field($nowField)->where($ddMap)->group('order_sn,sku_sn')->order('create_time desc')
                ->when($page && !empty($sear['username']), function ($query) use ($page, $pageNumber) {
                    $query->page($page, $pageNumber);
                })
                ->select()
                ->each(function($item){
                    $item['correction_fare'] = 0;
                    $item['correction_supplier'] = 0;
                    $item['correction_cost'] = 0;
                    $orderCorrectionSql = OrderCorrection::where(['order_sn'=>$item['order_sn'],'sku_sn'=>$item['sku_sn'],'status'=>1])->order('create_time desc,id desc')->limit(0,1000)->buildSql();
                    $orderCorrectionList = Db::table($orderCorrectionSql . ' a')->group('order_sn,sku_sn,type')->select()->toArray();
                    if(!empty($orderCorrectionList)){
                        foreach ($orderCorrectionList as $key => $value) {
                            switch ($value['type']){
                                case 1:
                                    $item['correction_fare'] = $value['price'];
                                    break;
                                case 2:
                                    $item['correction_supplier'] = $value['price'];
                                    break;
                                case 3:
                                    $item['correction_cost'] = $value['price'];
                                    break;
                                default:
                                    break;
                            }
                        }
                    }
                    if(!empty($item['end_time'] ?? null) && is_numeric($item['end_time'])){
                        $item['end_time'] = timeToDateFormat($item['end_time']);
                    }
                    $item['activity_name'] = null;
                    $item['order_user_top_info'] = [];
                    return $item;
                })
                ->toArray();

            if (!empty($bList)) {
                $divideList = $this->summaryAndProfit($bList, 2);
            }

            if (!empty($divideList)) {
                if (!empty($allList)) {
                    foreach ($allList as $key => $value) {
                        foreach ($divideList as $dKey => $dValue) {
                            //如果是按照sku分组则需要判断订单和商品完全一致,如果只是订单则按照订单
                            if($groupType == 2){
                                if ($dValue['order_sn'] == $value['order_sn'] && $dValue['all_goods_sku'] == $value['all_goods_sku']) {
                                    $allList[$key] = $dValue;
                                }
                            }else{
                                if ($dValue['order_sn'] == $value['order_sn']) {
                                    $allList[$key] = $dValue;
                                }
                            }
                        }
                    }
                    $list = $allList;
                } else {
                    $list = $divideList;
                }
            }
        }


        //第二种查询方法(旧)
//        $list = $this->where($map)->field($field)->when($page, function ($query) use ($page) {
//            $query->page($page, $this->pageNumber);
//        })->group('order_sn')
//            ->with(['leader','leader.user', 'leader.goodsCost', 'farePrice'])
//            ->order('create_time desc')
//            ->select()->each(function ($item) {
//                $item['level'] = $this->getLevelText($item['level']);
//                $item['type'] = $this->getTypeText($item['type']);
//                $item['arrival_status'] = $this->getArrivalStatusText($item['arrival_status']);
//                if (!$item['fare_price']) $item['fare_price'] = 0;
////               服务费为实际支付金额服务费，如有运费，运费的支付服务费也计算入成本
//                $item['server_price'] = number_format($item['real_pay_price'] * 0.007, 2);
//
//                $item['profit'] = number_format($item['total_price'] - $item['server_price'] - $item['cost_price'] - $item['divide_price'], 2);
//
//                if(!empty($item['user'])){
//                    if (!trim($item['user']['name'])) $item['user']['name'] = '默认用户';
//                }
//
//                return $item;
//            })->toArray();
        $allActivitySn = [];
        $activityInfo = [];
        $allSupplierCode = [];
        $supplierInfo = [];
        //展示参与分润的顶级用户信息
        if (!empty($list)) {
            $aOrderSn = array_unique(array_column($list, 'order_sn'));
            $allDivideSql = Divide::where(['order_sn' => $aOrderSn, 'status' => [1, 2]])->field('link_uid,order_sn,order_uid,level,real_divide_price,create_time')->order('level asc,divide_type desc,vdc_genre desc')->buildSql();
            $allDivide = Db::table($allDivideSql . " a")->join('sp_user b', 'a.link_uid = b.uid', 'left')->field('a.*,b.name as link_user_name,b.avatarUrl as link_user_avatarUrl,b.phone as link_user_phone')->group('a.order_sn')->order('a.create_time desc')->select()->toArray();

            if (!empty($allDivide)) {
                foreach ($list as $key => $value) {
                    if (!isset($list[$key]['divideTopUser'])) {
                        $list[$key]['divideTopUser'] = [];
                    }
                    foreach ($allDivide as $dKey => $vValue) {
                        if ($vValue['order_sn'] == $value['order_sn']) {
                            $list[$key]['divideTopUser'] = $vValue;
                        }
                    }

                }
            }

            foreach ($list as $key => $value) {
                if (!empty($value['goods'])) {
                    foreach ($value['goods'] as $gKey => $gValue) {
                        if (!empty($gValue['activity_sign'] ?? null)) {
                            $allActivitySn[$value['order_type']][] = $gValue['activity_sign'];
                        }
                        if (!empty($gValue['supplier_code'] ?? null)) {
                            $allSupplierCode[] = $gValue['supplier_code'];
                        }
                    }
                }
            }
            //获取商品归属活动名称
            if(!empty($allActivitySn)){
                foreach ($allActivitySn as $key => $value) {
                    switch ($key){
                        case 1:
                        case 3:
                            $activityInfo[$key] = Activity::where(['id'=>$value])->column('title','id');
                            break;
                        case 2:
                            $activityInfo[$key] = PtActivity::where(['activity_code'=>$value])->column('activity_title','activity_code');
                            break;
                        case 4:
                            $activityInfo[$key] = PpylActivity::where(['activity_code'=>$value])->column('activity_title','activity_code');
                            break;
                    }
                }
                if (!empty($activityInfo)) {
                    foreach ($list as $key => $value) {
                        if (!empty($value['goods'])) {
                            foreach ($value['goods'] as $gKey => $gValue) {
                                if (!empty($gValue['activity_sign'] ?? null) && !empty($activityInfo[$value['order_type']]) && !empty($activityInfo[$value['order_type']][$gValue['activity_sign']] ?? null)) {
                                    $list[$key]['goods'][$gKey]['activity_name'] = $activityInfo[$value['order_type']][$gValue['activity_sign']];
                                    $list[$key]['activity_name'][] = $activityInfo[$value['order_type']][$gValue['activity_sign']];
                                }
                            }
                        }
                    }
                }
            }

            //获取供应商名称
            if (!empty($allSupplierCode)) {
                $supplierInfo = Supplier::where(['supplier_code' => $allSupplierCode])->column('name', 'supplier_code');
                if (!empty($supplierInfo)) {
                    foreach ($list as $key => $value) {
                        if (!empty($value['goods'])) {
                            foreach ($value['goods'] as $gKey => $gValue) {
                                $list[$key]['goods'][$gKey]['supplier_name'] = null;
                                if (!empty($gValue['supplier_code'] ?? null) && !empty($supplierInfo[$gValue['supplier_code']] ?? null)) {
                                    $list[$key]['goods'][$gKey]['supplier_name'] = $supplierInfo[$gValue['supplier_code']];
                                }
                            }
                        }
                    }
                }
            }

            //查找订单用户直推上级用户信息
            $allUid = array_unique(array_column($list, 'order_uid'));
            if (!empty($allUid)) {
                $allUser = User::with(['topLink'])->where(['uid' => $allUid])->field('uid,link_superior_user,name,phone,vip_level')->select()->toArray();
                if (!empty($allUser)) {
                    foreach ($allUser as $key => $value) {
                        $allUserTop[$value['uid']] = $value['topLink'] ?? [];
                    }
                    foreach ($list as $key => $value) {
                        if (!empty($allUserTop[$value['order_uid']] ?? null)) {
                            $list[$key]['order_user_top_info']['name'] = $allUserTop[$value['order_uid']]['name'] ?? null;
                            $list[$key]['order_user_top_info']['phone'] = $allUserTop[$value['order_uid']]['phone'] ?? null;
                            $list[$key]['order_user_top_info']['vip_level'] = $allUserTop[$value['order_uid']]['vip_level'] ?? null;
                        }
                    }
                }
            }

        }

        $totalPrice = 0;
        $realTotalPrice = 0;
        $totalCost = 0;
        $totalDivide = 0;
        $totalProfit = 0;
        $totalRefund = 0;
        $totalDirectPrice = 0;
        $totalIndirectPrice = 0;
        $totalAfterSaleIngPrice = 0;
        $totalFarePrice = 0;
        $totalServerPrice = 0;
        $totalCouponDis = 0;

        //汇总数据累加
        if (!empty($list)) {
            foreach ($list as $item) {
                $totalPrice += ($item['total_price']);
                $realTotalPrice += ($item['real_pay_price']);
                $totalCost += ($item['cost_price']);
                $totalDivide += ($item['divide_price'] ?? 0);
                $totalProfit += ($item['profit']);
                $totalRefund += ($item['refund_price']);
                $totalDirectPrice += ($item['direct_price']);
                $totalIndirectPrice += ($item['indirect_price']);
                $totalAfterSaleIngPrice += ($item['after_sale_ing_price']);
                $totalFarePrice += ($item['fare_price']);
                $totalServerPrice += ($item['server_price']);
                $totalCouponDis += ($item['coupon_dis']);
            }
        }

        $summary = [
            'total_price' => !empty($totalProfit ?? 0) ? priceFormat($totalPrice) : $totalPrice,
            'total_cost' => !empty($totalProfit ?? 0) ? priceFormat($totalCost) : $totalCost,
            'total_divide' => !empty($totalProfit ?? 0) ? priceFormat($totalDivide) : $totalDivide,
            'total_profit' => !empty($totalProfit ?? 0) ? priceFormat($totalProfit) : $totalProfit,
            'total_refund' => !empty($totalProfit ?? 0) > 0 ? priceFormat($totalRefund) : $totalRefund,
            'total_direct_price' => $totalDirectPrice > 0 ? priceFormat($totalDirectPrice) : $totalDirectPrice,
            'total_indirect_price' => $totalIndirectPrice > 0 ? priceFormat($totalIndirectPrice) : $totalIndirectPrice,
            'real_total_price' => priceFormat($realTotalPrice - ($totalRefund ?? 0)),
            'real_total_price_not_refund' => priceFormat($realTotalPrice ?? 0),
            'total_after_sale_ing_price' => $totalAfterSaleIngPrice > 0 ? priceFormat($totalAfterSaleIngPrice) : $totalAfterSaleIngPrice,
            'total_fare_price' => $totalFarePrice > 0 ? priceFormat($totalFarePrice) : $totalFarePrice,
            'total_server_price' => $totalServerPrice > 0 ? priceFormat($totalServerPrice) : $totalServerPrice,
            'total_coupon_dis' => $totalCouponDis > 0 ? priceFormat($totalCouponDis) : $totalCouponDis,
        ];

        $finally = (new Activity())->dataFormat($list ?? [], $pageTotal ?? 0);

        if (!empty($finally)) {
            if (!empty($finally['pageTotal'])) {
                $finally['summary'] = $summary ?? [];
            } else {
                $finallyS['list'] = $finally;
                $finallyS['summary'] = $summary ?? [];
                unset($finally);
                $finally = $finallyS;
            }
        } else {
            if (!empty($sear['returnDataType'] ?? null) && $sear['returnDataType'] == 2) {
                $finally['list'] = $list ?? [];
                $finally['summary'] = $summary ?? [];
            }
        }

        if (!empty($sign)) {
            Cache::set($sign, $finally ?? [], !empty($cache['cache_expire']) ? $cache['cache_expire'] : 10800);
        }
        if (!empty($finally) && !empty($sear['dontNeedList'] ?? false)) {
            unset($finally['list']);
        }

        return $finally ?? [];
    }

    /**
     * @title  订单收益详情
     * @param array $data
     * @return array
     * @throws \Exception
     */
    public function incomeInfo(array $data)
    {
        $orderSn = $data['order_sn'];

        if (!empty($orderSn)) {
            $map[] = ['', 'exp', Db::raw($this->getFuzzySearSql('order_sn', trim($orderSn)))];
        }
        $map[] = ['status', 'in', $this->getStatusByRequestModule($sear['searType'] ?? 1)];

        $aList = $this->where($map)->order('level desc')->buildSql();
        $nowField = 'a.id,a.type,a.order_sn,a.total_price,a.link_uid,SUM(a.real_divide_price) AS divide_price,a.arrival_status,a.create_time,a.is_vip_divide,a.LEVEL,a.divide_type,a.purchase_price,a.sku_sn,b.fare_price,b.real_pay_price,b.discount_price,d.cost_price,c.title,c.image,c.specs,supplier_code';
        $list = Db::table($aList . ' a')
            ->join('sp_order b', 'a.order_sn = b.order_sn', 'left')
            ->join('sp_goods_sku c', 'a.sku_sn = c.sku_sn', 'left')
            ->join('sp_order_goods d', 'c.sku_sn = d.sku_sn and a.order_sn = d.order_sn', 'left')
            ->field($nowField)->group('order_sn,sku_sn')->order('create_time desc')
            ->select()->each(function ($item) {
                $item['level'] = $this->getLevelText($item['level']);
                $item['type'] = $this->getTypeText($item['type']);
                $item['arrival_status'] = $this->getArrivalStatusText($item['arrival_status']);
                if (!$item['fare_price']) $item['fare_price'] = 0;
                //服务费为实际支付金额服务费，如有运费，运费的支付服务费,折扣费用也计算入成本
                $item['server_price'] = number_format($item['real_pay_price'] * 0.007, 2);
                $item['profit'] = number_format($item['total_price'] - ($item['server_price'] ?? 0) - ($item['cost_price'] ?? 0) - ($item['divide_price'] ?? 0) - ($item['discount_price'] ?? 0), 2);
                if (!empty($item['create_time'])) {
                    $item['create_time'] = timeToDateFormat($item['create_time']);
                }
                return $item;
            })->toArray();

        return $list ?? [];
    }


    /**
     * @title  获取分润记录列表
     * @param array $sear 搜索条件
     * @return array
     * @throws \Exception
     */
    public function recordList(array $sear)
    {
        $map = [];
        if (!empty($sear['order_sn'])) {
            $map[] = ['order_sn', 'like', ['%' . $sear['order_sn'] . '%']];
        }

        if (!empty($sear['type'])) {
            $map[] = ['type', '=', $sear['type']];
        }
        if (!empty($sear['uid'])) {
            $map[] = ['link_uid', '=', $sear['uid']];
        }

        if (!empty($sear['status'])) {
            $map[] = ['arrival_status', '=', $sear['status']];
        }

        if (!empty($sear['username'])) {
            $map[] = ['link_uid', 'in', $this->getIdsByTeamLeaderName($sear['username'])];
        }
        if (!empty($sear['start_time']) && !empty($sear['end_time'])) {
            $map[] = ['create_time', '>=', strtotime($sear['start_time'])];
            $map[] = ['create_time', '<=', strtotime($sear['end_time'])];
        }

        $map[] = ['status', 'in', $this->getStatusByRequestModule($sear['searType'] ?? 1)];
        if (!empty($sear['pageNumber'] ?? null)) {
            $this->pageNumber = intval($sear['pageNumber']);

        }
        $page = intval($sear['page'] ?? 0) ?: null;

        $field = $this->getListFieldByModule();

        if (!empty($page)) {
            $total = Db::name('divide')->where($map)->count();
            $pageTotal = ceil($total / $this->pageNumber);
        }

        $list = $this->where($map)->field($field)->when($page, function ($query) use ($page) {
            $query->page($page, $this->pageNumber);
        })->group('order_sn')
            ->with(['linkUser','orderUser'])
            ->order('create_time desc')
            ->select()->each(function ($item) {
                $item['level'] = $this->getLevelText($item['level'],$item['type']);
//                $item['type'] = $this->getTypeText($item['is_vip_divide'],$item['type']);
                $item['arrival_status'] = $this->getArrivalStatusText($item['arrival_status']);

                if(!empty($item['refund_price'] ?? null)){
                    $item['divide_price'] = priceFormat($item['divide_price'] - $item['refund_price']);
                }
                return $item;
            })->toArray();

        //展示参与分润的顶级用户信息
//        if (!empty($list)) {
//            $aOrderSn = array_column($list, 'order_sn');
//            $allSku = array_unique(array_column($list, 'sku_sn'));
//            $allDivideSql = Divide::with(['linkUser'])->where(['order_sn' => $aOrderSn, 'status' => [1, 2]])->field('link_uid,order_sn,order_uid,level,real_divide_price,level_sort,divide_sort,create_time,arrival_status,is_vip_divide,vdc_genre,divide_type')
////                ->order('level asc,divide_type desc,vdc_genre desc,real_divide_price asc')
//                ->order('level desc,divide_type asc,vdc_genre asc,id asc')
//                ->select()->each(function ($item) {
//                    $item['level_name'] = $this->getLevelText($item['level']);
//                    $item['arrival_status'] = $this->getArrivalStatusText($item['arrival_status']);
//                    return $item;
//                })->toArray();
//            if (!empty($allDivideSql)) {
//
//                foreach ($allDivideSql as $key => $value) {
//                    $allDivideSqls[$value['order_sn']][] = $value;
//                }
//                $userDividePrice = [];
//                foreach ($allDivideSql as $key => $value) {
//                    if(!isset($userDividePrice[$value['order_sn']][$value['link_uid']])){
//                        $userDividePrice[$value['order_sn']][$value['link_uid']] = 0;
//                    }
//                    $userDividePrice[$value['order_sn']][$value['link_uid']] += priceFormat($value['real_divide_price']);
//                }
//
//                foreach ($allDivideSqls as $key => $value) {
//                    foreach ($value as $oKey => $oValue) {
//                        if(!isset($priceGroup[$oValue['order_sn']][$oValue['link_uid']])){
//                            $priceGroup[$oValue['order_sn']][$oValue['link_uid']] = false;
//                        }
//                        if(empty($allDivideSqls[$key][$oKey]['summary_all_price']) && empty($priceGroup[$oValue['order_sn']][$oValue['link_uid']])){
//                            $allDivideSqls[$key][$oKey]['summary_all_price'] =  $userDividePrice[$oValue['order_sn']][$oValue['link_uid']];
//                            $allDivideSqls[$key][$oKey]['real_divide_price'] =  $userDividePrice[$oValue['order_sn']][$oValue['link_uid']];
//                            $priceGroup[$oValue['order_sn']][$oValue['link_uid']] = true;
//                        }else{
//                            unset( $allDivideSqls[$key][$oKey]);
//                        }
//                    }
//                }
//
//                //2021/11/17的数据需要补齐分润排序
//                foreach ($allDivideSqls as $oKey => $oValue) {
//                    foreach ($oValue as $key => $value) {
//                        if(strtotime($value['create_time']) <= 1637078400 && empty($value['level_sort'])){
//                            if (!isset($levelSort[$value['order_sn']][$value['level']])) {
//                                $levelSort[$value['order_sn']][$value['level']] = 1;
//                            }
//                            if (!isset($sort[$value['order_sn']])) {
//                                $sort[$value['order_sn']] = 1;
//                            }
//                            $allDivideSqls[$oKey][$key]['level_sort'] = $levelSort[$value['order_sn']][$value['level']];
//                            $allDivideSqls[$oKey][$key]['divide_sort'] = $sort[$value['order_sn']] ?? 1;
//                            $levelSort[$value['order_sn']][$value['level']] += 1;
//                            $sort[$value['order_sn']] += 1;
//                        }
//                    }
//                }
//
//                foreach ($list as $key => $value) {
//                    if(!empty($allDivideSqls[$value['order_sn']] ?? [])){
//                        $list[$key]['divide'] = array_values($allDivideSqls[$value['order_sn']]);
//                    }
//                }
//                foreach ($allDivideSqls as $key => $value) {
////                    $allDivide[] = current($value);
//                    $allDivide[] = end($value);
//                }
//            }
//
////                $allDivide = Db::table($allDivideSql . " a")->join('sp_user b', 'a.link_uid = b.uid', 'left')->field('a.*,b.name as link_user_name,b.avatarUrl as link_user_avatarUrl,b.phone as link_user_phone')->group('a.order_sn')->select()->toArray();
//
//            if (!empty($allDivide)) {
//                foreach ($list as $key => $value) {
//                    if (!isset($list[$key]['divideTopUser'])) {
//                        $list[$key]['divideTopUser'] = [];
//                    }
//                    foreach ($allDivide as $dKey => $vValue) {
//                        if ($vValue['order_sn'] == $value['order_sn']) {
//                            $list[$key]['divideTopUser'] = $vValue;
//                        }
//                    }
//
//                }
//            }
//        }

        return ['list' => $list, 'pageTotal' => $pageTotal ?? 0];
    }


    /**
     * @title  获取分润详情
     * @param array $data
     * @return array
     * @throws \Exception
     */
    public function recordDetail(array $data)
    {
        $map = [];
        $field = $this->getListFieldByModule();
        $map[] = ['status', 'in', $this->getStatusByRequestModule($sear['searType'] ?? 1)];
        $map[] = ['order_sn', '=', $data['order_sn']];
        $row = $this->with(['user'])->where($map)
            ->field($field)
            ->findOrEmpty()->toArray();

//        sum 会导致无数据也返回模型
        if (!trim($row['divide_price'])) return [];

        $row['records'] = $this->with(['user', 'orderGoods' => function ($query) {
            $query->field('goods_sn,sku_sn,title,images,specs');
        }])->where($map)
            ->field('divide_type,level,link_uid,divide_price,dis_reduce_price,refund_reduce_price,real_divide_price,is_vip_divide,remark,goods_sn,sku_sn,type,is_grateful')
            ->select()->each(function (&$item) {
                $item['level'] = $this->getLevelText($item['level'],$item['type']);
                $item['type_name'] = $this->getDivideTypeText($item['type'],$item['is_grateful']);
                if (!empty($item['user'])) {
                    if (!trim($item['user']['name'])) $item['user']['name'] = '默认用户';
                }

                return $item;
            })->toArray();

        $row['arrival_status'] = $this->getArrivalStatusText($row['arrival_status']);
        $row['type'] = $this->getTypeText($row['is_vip_divide']);

        return $row;
    }

    /**
     * @title  获取讲师全部收益
     * @param array $data
     * @return array
     * @throws \Exception
     */
    public function getAllIncomeByUser(array $data)
    {
        $uid = $data['uid'];
        $lecturerInfo = (new Lecturer())->getLecturerInfoByLinkUid($data['uid']);
        if (empty($lecturerInfo)) {
            throw new ParamException(['msg' => '关联用户不存在,无法查看']);
        }

        $map[] = ['link_uid', '=', $uid];
        $map[] = ['status', 'in', [1,]];
        $map[] = ['create_time', '>=', $lecturerInfo['bind_time']];

        $list = $this->where($map)->field('id,divide_price,integral,create_time,type')->select()->toArray();
        $allIncome = 0;
        $monthIncome = 0;
        $dayIncome = 0;
        $firstDay = date('Y-m-01', time()) . ' 00:00:00';
        $lastDay = date('Y-m-d', strtotime("$firstDay +1 month -1 day")) . ' 23:59:59';

        $nowDayStart = date('Y-m-d') . ' 00:00:00';
        $nowDayEnd = date('Y-m-d') . ' 23:59:59';

        foreach ($list as $key => $value) {
            $allIncome += doubleval($value['divide_price']);
            if ($value['create_time'] >= $firstDay && $value['create_time'] <= $lastDay) {
                $monthIncome += doubleval($value['divide_price']);
            }
            if ($value['create_time'] >= $nowDayStart && $value['create_time'] <= $nowDayEnd) {
                $dayIncome += doubleval($value['divide_price']);
            }
        }
        $userBalance = (new User())->getUserInfo($data['uid']);

        return ['all' => priceFormat($allIncome), 'month' => priceFormat($monthIncome), 'day' => priceFormat($dayIncome), 'avaliable_balance' => $userBalance['total_balance'] ?? '0.00', 'withdraw_total' => $userBalance['withdraw_total'] ?? '0.00'];

    }

    /**
     * @title  获取用户分润列表
     * @param array $sear
     * @return array
     * @throws \Exception
     */
    public function userDivideList(array $sear)
    {
        if (!empty($sear['order_sn'])) {
            $map[] = ['a.order_sn', 'like', ['%' . $sear['order_sn'] . '%']];
        }

        if (!empty($sear['status'])) {
            $map[] = ['a.arrival_status', '=', $sear['status']];
        }

        if (!empty($sear['username'])) {
            $map[] = ['', 'exp', Db::raw($this->getFuzzySearSql('b.name', $sear['username']))];
        }

        if (!empty($sear['start_time']) && !empty($sear['end_time'])) {
            $map[] = ['a.create_time', '>=', strtotime($sear['start_time'])];
            $map[] = ['a.create_time', '<=', strtotime($sear['end_time'])];
        }

        if (!empty($sear['type'] ?? null)) {
            if (is_array($sear['type'])) {
                $map[] = ['a.type', 'in', $sear['type']];
            } else {
                $map[] = ['a.type', '=', $sear['type']];
            }
        }
        if (!empty($sear['is_grateful'] ?? null)) {
            $map[] = ['a.is_grateful', '=', $sear['is_grateful']];
        } else {
            if ($sear['type'] == 5) {
                $map[] = ['a.is_grateful', '=', 2];
            }
        }
        if (!empty($sear['is_exp'] ?? null)) {
            $map[] = ['a.is_exp', '=', $sear['is_exp']];
            if (!empty($sear['exp_level'])) {
//                $map[] = ['a.level', '=', $sear['exp_level'] ?? 4];
                $map[] = ['a.level', 'in', [3, 4]];
            }
        } else {
            if ($sear['type'] == 5) {
                $map[] = ['a.is_exp', '=', 2];
            }
        }
        if ($sear['type'] == 7) {
            if (!empty($sear['is_device'] ?? null)) {
                if ($sear['is_device'] == 1) {
                    $map[] = ['a.is_device', '=', $sear['is_device']];
                    $map[] = ['a.device_divide_type', '=', 1];
                } else {
                    $map[] = ['', 'exp', Db::raw('(a.device_divide_type = 2 or a.device_divide_type is null)')];
                }
            }
        }

        if (!empty($sear['team_shareholder_level'] ?? null)) {
            $map[] = ['a.team_shareholder_level', '=', $sear['team_shareholder_level'] ?? 4];
        }else{
            $map[] = ['a.team_shareholder_level', '=', 0];
        }
//        if (!empty($sear['is_device'] ?? null)) {
//            $map[] = ['a.is_device', '=', $sear['is_device']];
//            if ($sear['is_device'] == 1) {
//                $map[] = ['a.device_divide_type', '=', 1];
//            }
//        } else {
//            if ($sear['type'] == 7) {
//                $map[] = ['', 'exp', Db::raw('(a.device_divide_type = 2 or a.device_divide_type is null)')];
//            }
//        }
        if (!empty($sear['allot_type'] ?? null)) {
            $map[] = ['a.allot_type', '=', $sear['allot_type']];
        }


        $map[] = ['a.status','=',1];
        $map[] = ['a.link_uid', '=', $sear['uid']];
        $map[] = ['a.real_divide_price', '<>', 0];

        //$map[] = ['a.status', 'in', $this->getStatusByRequestModule($sear['searType'] ?? 1)];
        $page = intval($sear['page'] ?? 0) ?: null;

        if (!empty($page)) {
            $aTotal = Db::name('divide')->alias('a')
                ->join('sp_user b', 'a.order_uid = b.uid', 'left')
                ->where($map)
                ->count();
            //$aTotal = Db::table($aTotals ." a")->value('count(*)');
            $pageTotal = ceil($aTotal / $this->pageNumber);
        }

        $list = Db::name('divide')->alias('a')
            ->join('sp_user b', 'a.order_uid = b.uid', 'left')
            ->join('sp_crowdfunding_activity c', 'a.crowd_code = c.activity_code', 'left')
            ->where($map)
            ->field('a.order_sn,a.type,a.order_uid,a.type,a.vdc,a.vdc_genre,a.divide_type,a.level,a.real_divide_price,a.is_vip_divide,a.arrival_status,a.create_time,a.arrival_time,a.remark,b.name as order_user_name,c.title as activity_name,a.crowd_code,a.crowd_round_number,a.crowd_period_number,a.is_grateful,a.is_exp,a.is_device,a.device_sn')->order('a.create_time desc')
            ->when($page, function ($query) use ($page) {
                $query->page($page, $this->pageNumber);
            })->select()->each(function ($item) {
                if (!empty($item['create_time'])) {
                    $item['create_time'] = timeToDateFormat($item['create_time']);
                }
                if (!empty($item['arrival_time'])) {
                    $item['arrival_time'] = timeToDateFormat($item['arrival_time']);
                }
                $item['device_name'] = null;
                return $item;
            })->toArray();
        if (!empty($sear['is_device'] ?? null)) {
            $allDeviceSn = array_unique(array_column($list, 'device_sn'));
            if (!empty($allDeviceSn)) {
                $allDeviceInfo = Device::where(['device_sn' => $allDeviceSn])->column('device_name', 'device_sn');
                if (!empty($allDeviceInfo)) {
                    foreach ($list as $key => $value) {
                        if (!empty($value['device_sn'] ?? null) && !empty($allDeviceInfo[$value['device_sn'] ?? null])) {
                            $list[$key]['device_name'] = $allDeviceInfo[$value['device_sn']];
                        }
                    }
                }
            }
        }
        return ['list' => $list, 'pageTotal' => $pageTotal ?? 0];
    }

    /**
     * @title  会员个人业绩(分润业绩)汇总
     * @param array $sear
     * @return array
     * @throws \Exception
     */
    public function memberSummary(array $sear)
    {
        $divideCacheKey = null;
        $divideCacheExpire = 600;
        $orderCacheKey = null;
        $orderCacheExpire = 600;

        if (!empty($sear['keyword'])) {
            $map[] = ['', 'exp', Db::raw($this->getFuzzySearSql('name|phone', trim($sear['keyword'])))];
        }
        if (!empty($sear['level'])) {
            if (is_array($sear['level'])) {
                $map[] = ['vip_level', 'in', $sear['level']];
            } else {
                $map[] = ['vip_level', '=', $sear['level']];
            }
        } else {
            $map[] = ['vip_level', 'in', [1]];
        }

        if (!empty($sear['topUserPhone'])) {
            $topUser = User::where(['phone' => trim($sear['topUserPhone']), 'status' => $this->getStatusByRequestModule($sear['searType'] ?? 1)])->order('create_time desc')->column('uid');
            if (empty($topUser)) {
                throw new ServiceException(['msg' => '查无该上级用户']);
            }
            //topUserType 1为查找直属下级 2为团队全部下级 3为分润下级
            switch ($sear['topUserType'] ?? 1) {
                case 1:
                    $map[] = ['link_superior_user', 'in', $topUser];
                    break;
                case 2:
                    $topUser = current($topUser);
                    $tMap[] = ['', 'exp', Db::raw("find_in_set('$topUser',team_chain)")];
                    $tMap[] = ['status', '=', 1];
                    if (!empty($sear['level'])) {
                        if (is_array($sear['level'])) {
                            $tMap[] = ['level', 'in', $sear['level']];
                        } else {
                            $tMap[] = ['level', '=', $sear['level']];
                        }
                    } else {
                        $tMap[] = ['level', 'in', [1]];
                    }
                    $allLinkUserCacheKey2 = md5(json_encode($tMap, 256)) . $sear['topUserType'];
                    $allUid = Member::where($tMap)->cache($allLinkUserCacheKey2, 15)->column('uid');
                    if (!empty($allUid)) {
                        $map[] = ['uid', 'in', $allUid];
                    }else{
                        throw new ServiceException(['msg' => '查无符合条件的下级哦']);
                    }
                    break;
                case 3:
                    $topUser = implode('|', $topUser);
                    //正则查询,不用find_in_set是因为divide_chain字段不是用逗号分隔的
                    //支持多个人,只要$divideTopUser用|分割开就可以了
                    $dtMap[] = ['', 'exp', Db::raw('divide_chain REGEXP ' . "'" . $topUser . "'")];
                    $dtMap[] = ['status', '=', 1];
                    if (!empty($sear['level'])) {
                        if (is_array($sear['level'])) {
                            $dtMap[] = ['level', 'in', $sear['level']];
                        } else {
                            $dtMap[] = ['level', '=', $sear['level']];
                        }
                    } else {
                        $dtMap[] = ['level', 'in', [1]];
                    }
                    $allLinkUserCacheKey3 = md5(json_encode($dtMap, 256)) . $sear['topUserType'];
                    $allDUid = Member::where($dtMap)->cache($allLinkUserCacheKey3, 15)->column('uid');
                    if (!empty($allDUid)) {
                        $map[] = ['uid', 'in', $allDUid];
                    }else{
                        throw new ServiceException(['msg' => '查无符合条件的下级哦']);
                    }
                    break;
            }
        }


        $map[] = ['status', '=', 1];
        $page = intval($sear['page'] ?? 0) ?: null;

        if (empty($sear['clearCache'])) {
            $divideCacheKey = 'adminUserDivideList-' . http_build_query($sear);
            $divideCacheExpire = 600;
            $orderCacheKey = 'adminUserOrderList-' . http_build_query($sear);
            $orderCacheExpire = 600;
        }

        if (!empty($page)) {
            $aTotal = User::where($map)->count();
            $pageTotal = ceil($aTotal / $this->pageNumber);
        }

        $userList = User::with(['link'])->where($map)->field('uid,name,phone,vip_level,growth_value,link_superior_user,total_balance,divide_balance')->when($page, function ($query) use ($page) {
            $query->page($page, $this->pageNumber);
        })->select()->each(function ($item) {
            $item['divide_chain_info'] = [];
            $item['topTeamUserInfo'] = [];
        })->toArray();
        if (empty($userList)) {
            throw new ServiceException(['errorCode' => 400102]);
        }

        $memberTitle = MemberVdc::where(['status' => 1])->column('name', 'level');

        //补充会员信息
        $memberInfo = Member::where(['uid' => array_column($userList, 'uid'), 'status' => 1])->field('uid,team_chain,divide_chain,level')->select()->each(function ($item) use ($memberTitle) {
            if (!empty($item['divide_chain'])) {
                $item['divide_chain'] = json_decode($item['divide_chain'], true);
            }
            $item['vip_name'] = $memberTitle[$item['vip_level']] ?? '普通用户';
        })->toArray();


        if (!empty($memberInfo)) {
            foreach ($memberInfo as $key => $value) {

                if (!empty($value['divide_chain'])) {

                    if (empty($divideTopUserList)) {
                        $divideTopUserList = array_keys($value['divide_chain']);
                    } else {
                        $divideTopUserList = array_merge_recursive($divideTopUserList, array_keys($value['divide_chain']));
                    }
                }

                if (!empty($value['team_chain'])) {
                    $teamChain = explode(',', $value['team_chain']);
                    $teamChainTop = end($teamChain);
                    $memberInfo[$key]['topTeamUid'] = $teamChainTop;
                    $teamTopUserList[] = $teamChainTop;
                } else {
                    $memberInfo[$key]['topTeamUid'] = null;
                }
            }

            //查找顶级团队长的用户信息
            if (!empty($teamTopUserList)) {
                $allTeamUidList = array_unique($teamTopUserList);
                $allTeamTopUserInfos = User::where(['uid' => $allTeamUidList, 'status' => [1, 2]])->field('uid,name,phone,avatarUrl,vip_level')->select()->each(function ($item) use ($memberTitle) {
                    $item['vip_name'] = $memberTitle[$item['vip_level']] ?? '普通用户';
                })->toArray();

                if (!empty($allTeamTopUserInfos)) {
                    foreach ($allTeamTopUserInfos as $key => $value) {
                        $allTeamTopUserInfo[$value['uid']] = $value;
                    }
                    foreach ($memberInfo as $key => $value) {
                        $memberInfo[$key]['topTeamUserInfo'] = $allTeamTopUserInfo[$value['topTeamUid']] ?? [];
                    }
                }
            }

            //查找全部分润第一人的用户信息
            if (!empty($divideTopUserList)) {
                $allDivideUidList = array_unique($divideTopUserList);

                $allDivideUserInfos = User::where(['uid' => $allDivideUidList, 'status' => [1, 2]])->field('uid,name,phone,avatarUrl,vip_level')->select()->each(function ($item) use ($memberTitle) {
                    $item['vip_name'] = $memberTitle[$item['vip_level']] ?? '普通用户';
                })->toArray();

                if (!empty($allDivideUserInfos)) {
                    foreach ($allDivideUserInfos as $key => $value) {
                        $allDivideUserInfo[$value['uid']] = $value;
                    }
                    foreach ($memberInfo as $key => $value) {
                        $memberInfo[$key]['divide_chain_info'] = [];
                        if (!empty($value['divide_chain'])) {
                            foreach ($value['divide_chain'] as $cK => $cV) {
                                if (!empty($allDivideUserInfo[$cK])) {
                                    $memberInfo[$key]['divide_chain_info'][$cK] = $allDivideUserInfo[$cK];
                                    $memberInfo[$key]['divide_chain_info'][$cK]['level'] = $cV;
                                }
                            }
                            $memberInfo[$key]['divide_chain_info'] = array_values($memberInfo[$key]['divide_chain_info']);
                        }
                    }
                }
            }

            foreach ($memberInfo as $key => $value) {
                $memberInfos[$value['uid']] = $value;
            }

            foreach ($userList as $key => $value) {
                if (!empty($memberInfos[$value['uid']] ?? [])) {
                    $userList[$key]['divide_chain_info'] = $memberInfos[$value['uid']]['divide_chain_info'] ?? [];
                    $userList[$key]['topTeamUserInfo'] = $memberInfos[$value['uid']]['topTeamUserInfo'] ?? [];
                }
            }
        }

        $dirOrderPrice = [];
        $dirPurchasePrice = [];
        $dirWillInComePrice = [];
        $dirAllOrder = [];
        $indOrderPrice = [];
        $indPurchasePrice = [];
        $indWillInComePrice = [];
        $indAllOrder = [];
        $userSelfOrder = [];
        $userFreezePrice = [];
        $withdrawLists = [];

        foreach ($userList as $key => $value) {
            if (!isset($dirOrderPrice[$value['uid']])) {
                $dirOrderPrice[$value['uid']] = 0;
            }
            //销售利润(直推)
            if (!isset($dirOrderPrice[$value['uid']])) {
                $dirOrderPrice[$value['uid']] = 0;
            }
            if (!isset($dirPurchasePrice[$value['uid']])) {
                $dirPurchasePrice[$value['uid']] = 0;
            }
            if (!isset($dirWillInComePrice[$value['uid']])) {
                $dirWillInComePrice[$value['uid']] = 0;
            }
            if (!isset($dirAllOrder[$value['uid']])) {
                $dirAllOrder[$value['uid']] = 0;
            }
            //教育奖金(间推)
            if (!isset($indOrderPrice[$value['uid']])) {
                $indOrderPrice[$value['uid']] = 0;
            }
            if (!isset($indPurchasePrice[$value['uid']])) {
                $indPurchasePrice[$value['uid']] = 0;
            }
            if (!isset($indWillInComePrice[$value['uid']])) {
                $indWillInComePrice[$value['uid']] = 0;
            }
            if (!isset($indAllOrder[$value['uid']])) {
                $indAllOrder[$value['uid']] = 0;
            }

            //自购
            if (!isset($userSelfOrder[$value['uid']])) {
                $userSelfOrder[$value['uid']] = 0;
            }

            //冻结奖金
            if (!isset($userFreezePrice[$value['uid']])) {
                $userFreezePrice[$value['uid']] = 0;
            }
        }

        $uid = array_unique(array_filter(array_column($userList, 'uid')));
        if (empty($sear['clearCache'])) {
            $divideCacheKey .= md5(implode(',', $uid));
        }

        //参与分润订单
        $dMap[] = ['link_uid', 'in', $uid];
        $dMap[] = ['arrival_status', 'in', [1, 2, 4]];
        $dMap[] = ['status', 'in', [1, 2]];
        $dMap[] = ['type', 'in', [1]];
        if (!empty($sear['start_time']) && !empty($sear['end_time'])) {
            $dMap[] = ['create_time', '>=', strtotime($sear['start_time'])];
            $dMap[] = ['create_time', '<=', strtotime($sear['end_time'])];
        }
        $aDivide = DivideModel::where($dMap)->field('real_divide_price,arrival_status,total_price,purchase_price,divide_type,link_uid')
            ->when($divideCacheKey, function ($query) use ($divideCacheKey, $divideCacheExpire) {
                $query->cache($divideCacheKey, $divideCacheExpire);
            })->select()->toArray();

        if (empty($sear['clearCache'])) {
            $orderCacheKey .= md5(implode(',', $uid));
        }
        //自购订单
        $mMap[] = ['uid', 'in', $uid];
        $mMap[] = ['pay_status', '=', 2];
        $mMap[] = ['order_status', 'in', [2, 3, 4, 8]];
        if (!empty($sear['start_time']) && !empty($sear['end_time'])) {
            $mMap[] = ['create_time', '>=', strtotime($sear['start_time'])];
            $mMap[] = ['create_time', '<=', strtotime($sear['end_time'])];
        }
        $myselfOrder = Order::with(['goods' => function ($query) {
            $query->where(['status' => 1]);
        }])->where($mMap)->field('order_sn,uid,real_pay_price')
            ->when($orderCacheKey, function ($query) use ($orderCacheKey, $orderCacheExpire) {
                $query->cache($orderCacheKey, $orderCacheExpire);
            })->select()->toArray();

        if (!empty($myselfOrder)) {
            foreach ($myselfOrder as $key => $value) {
                if (!isset($userSelfOrder[$value['uid']])) {
                    $userSelfOrder[$value['uid']] = 0;
                }
                foreach ($value['goods'] as $gKey => $gValue) {
                    $userSelfOrder[$value['uid']] += $gValue['total_price'];
                }
            }
        }

        if (!empty($aDivide)) {
            foreach ($aDivide as $key => $value) {
                $aDivideUser[$value['link_uid']][] = $value;
            }
            foreach ($aDivideUser as $key => $value) {
                foreach ($value as $dKey => $dValue) {
                    if (!empty(doubleval($dValue['real_divide_price']))) {
                        if ($dValue['divide_type'] == 1) {
                            $dirOrderPrice[$dValue['link_uid']] += $dValue['total_price'];
                            $dirPurchasePrice[$dValue['link_uid']] += $dValue['purchase_price'];
                            $dirWillInComePrice[$dValue['link_uid']] += $dValue['real_divide_price'];
                            $dirAllOrder[$dValue['link_uid']] += 1;
                        }
                        if ($dValue['divide_type'] == 2) {
                            $indOrderPrice[$dValue['link_uid']] += $dValue['total_price'];
                            $indPurchasePrice[$dValue['link_uid']] += $dValue['purchase_price'];
                            $indWillInComePrice[$dValue['link_uid']] += $dValue['real_divide_price'];
                            $indAllOrder[$dValue['link_uid']] += 1;
                        }
                        //冻结金额
                        if ($dValue['arrival_status'] == 2) {
                            $userFreezePrice[$dValue['link_uid']] += $dValue['real_divide_price'];
                        }
                    }
                }
            }
        }

        //提现记录
        $wMap[] = ['uid', 'in', $uid];
        $wMap[] = ['payment_status', '=', 1];
        $wMap[] = ['status', '=', 1];
        $wMap[] = ['check_status', '=', 1];
        if (!empty($sear['start_time']) && !empty($sear['end_time'])) {
            $wMap[] = ['payment_time', '>=', strtotime($sear['start_time'])];
            $wMap[] = ['payment_time', '<=', strtotime($sear['end_time'])];
        }
        $withdrawList = Withdraw::where($wMap)->field('uid,sum(total_price) as total_price,sum(handing_fee) as total_handing_fee,sum(price) as real_price')->group('uid')->select()->toArray();
        if (!empty($withdrawList)) {
            foreach ($withdrawList as $key => $value) {
                $withdrawLists[$value['uid']] = $value;
            }
        }

        $memberList = MemberVdc::where(['status' => 1])->column('name', 'level');
        foreach ($userList as $key => $value) {
            $userList[$key]['vip_name'] = '未知等级';
            $userList[$key]['link_user_vip_name'] = ' ';
            if (!empty($memberList[$value['vip_level']])) {
                $userList[$key]['vip_name'] = $memberList[$value['vip_level']];
            }
            if (!empty($value['link_user_level'])) {
                if (!empty($memberList[$value['link_user_level']])) {
                    $userList[$key]['link_user_vip_name'] = $memberList[$value['link_user_level']];
                }
            }


            //销售佣金(直推)
            $userList[$key]['direct']['order_price'] = priceFormat((string)((priceFormat($dirOrderPrice[$value['uid']] ?? 0)) + $userSelfOrder[$value['uid']] ?? 0));
            $userList[$key]['direct']['purchase_price'] = priceFormat((string)((priceFormat($dirPurchasePrice[$value['uid']] ?? 0)) + $userSelfOrder[$value['uid']] ?? 0));
            $userList[$key]['direct']['will_income_total'] = priceFormat((string)((priceFormat($dirWillInComePrice[$value['uid']] ?? 0))));
            $userList[$key]['direct']['pay_order_number'] = intval($dirAllOrder[$value['uid']] ?? 0);
            //教育奖金(间推)
            $userList[$key]['indirect']['order_price'] = priceFormat((string)((priceFormat($indOrderPrice[$value['uid']] ?? 0))));
            $userList[$key]['indirect']['purchase_price'] = priceFormat((string)((priceFormat($indPurchasePrice[$value['uid']] ?? 0))));
            $userList[$key]['indirect']['will_income_total'] = priceFormat((string)((priceFormat($indWillInComePrice[$value['uid']] ?? 0))));
            $userList[$key]['indirect']['pay_order_number'] = intval($indAllOrder[$value['uid']] ?? 0);

            //总收益
            $userList[$key]['all']['will_income_total'] = priceFormat($userList[$key]['direct']['will_income_total'] + $userList[$key]['indirect']['will_income_total']);
            $userList[$key]['all']['freeze_price'] = priceFormat($userFreezePrice[$value['uid']] ?? 0);
            $userList[$key]['all']['total_withdraw_price'] = '0.00';
            $userList[$key]['all']['real_withdraw_price'] = '0.00';
            if (!empty($withdrawLists[$value['uid']])) {
                $userList[$key]['all']['total_withdraw_price'] = priceFormat($withdrawLists[$value['uid']]['total_price'] ?? 0);
                $userList[$key]['all']['real_withdraw_price'] = priceFormat($withdrawLists[$value['uid']]['real_price'] ?? 0);
            }
            $userList[$key]['all']['total_balance'] = priceFormat($value['divide_balance'] ?? 0);
        }

        return ['list' => $userList ?? [], 'pageTotal' => $pageTotal ?? 0, 'total' => $aTotal ?? 0];
    }


    private function getListFieldByModule(string $default = '')
    {
        switch ($default ? $default : $this->module) {
            case 'admin':
            case 'manager':
                $field = 'type,order_sn,total_price,link_uid,SUM(divide_price) as divide_price,arrival_status,is_vip_divide,create_time,order_uid,sku_sn,SUM(refund_reduce_price) as refund_price,SUM(real_divide_price) as real_divide_price,level,type';
                break;
            case 'api':
                $field = 'a.after_sale_sn,a.order_sn,a.order_real_price,a.uid,b.name as user_name,a.type,a.apply_reason,a.apply_status,a.apply_price,a.buyer_received_goods,a.verify_status,a.verify_reason,a.apply_time,a.verify_time,a.create_time,a.after_status,a.status,c.title as goods_title,c.images as goods_images,c.specs as goods_specs,c.count as goods_count,a.is_vip_divide';
                break;
            case 'list':
                $field = 'id,type,order_sn,total_price,link_uid,SUM(divide_price) as divide_price,arrival_status,create_time,is_vip_divide';
                break;
            default:
                $field = 'type,order_sn,total_price,link_uid,SUM(divide_price) as divide_price,arrival_status,is_vip_divide,create_time';
        }
        return $field;
    }


    private function getLevelText(int $level, int $type = 1)
    {
        switch ($type) {
            case 1:
            case 2:
            case 3:
            case 4:
            case 6:
            case 8:
                switch ($level) {
                    case 0:
                        $text = '普通用户';
                        break;
                    case 1:
                        $text = '二星盟主';
                        break;
                    case 2:
                        $text = '一星盟主';
                        break;
                    case 3:
                        $text = '普通盟主';
                        break;
                    default:
                        $text = '未知等级';
                }
                break;
            case 5:
            case 9:
                switch ($level) {
                    case 0:
                        $text = '非团队会员';
                        break;
                    case 1:
                        $text = '总裁';
                        break;
                    case 2:
                        $text = '总监';
                        break;
                    case 3:
                        $text = '经理';
                        break;
                    case 4:
                        $text = '主管';
                        break;
                    default:
                        $text = '未知等级';
                }
                break;
            case 7:
                switch ($level) {
                    case 0:
                        $text = '普通用户';
                        break;
                    case 1:
                        $text = '省代';
                        break;
                    case 2:
                        $text = '市代';
                        break;
                    case 3:
                        $text = '区代';
                        break;
                    default:
                        $text = '未知等级';
                }
                break;
        }


        return $text;
    }

    private function getDivideTypeText(int $type = 1,$isGrateful=2)
    {
        switch ($type ?? 1) {
            case 1:
                $text = '商城分润';
                break;
            case 2:
                $text = '广宣奖';
                break;
            case 3:
                $text = '套餐感恩奖';
                break;
            case 4:
                $text = '转售订单奖励';
                break;
            case 5:
                $text = $isGrateful == 2 ? '团队业绩奖' : '团队业绩感恩奖';
                break;
            case 6:
                $text = '股东奖';
                break;
            case 7:
                $text = '区代奖';
                break;
            case 8:
                $text = $isGrateful == 2 ? '福利活动奖' : '福利活动感恩奖';
                break;
            case 9:
                $text = $isGrateful == 2 ? '股票奖' : '股票感恩奖';
                break;
            default:
                $text = '未知类型';
        }
        return $text;
    }

    private function getArrivalStatusText(int $status)
    {
        switch ($status) {
            case -1:
                $text = '整单被删除';
                break;
            case 1:
                $text = '到账';
                break;
            case 2:
                $text = '冻结中';
                break;
            case 3:
                $text = '为退款取消分润';
                break;
            default:
                $text = '暂无分润';
        }

        return $text;
    }

    private function getTypeText(int $type, int $divideType = 1)
    {
        if (!in_array($divideType, [2, 8, 6])) {
            switch ($type) {
                case 1:
                    $text = '同级推荐奖';
                    break;
                case 2:
                    $text = '利差佣金奖';
                    break;
                default:
                    $text = '暂无奖金';
            }
        } else {
            switch ($divideType) {
                case 2:
                    $text = '广宣奖';
                    break;
                case 6:
                    $text = '股东奖';
                    break;
                case 8:
                    $text = '福利活动奖';
                    break;
                default:
                    $text = '未知奖金类型';

            }
        }


        return $text;
    }

    public function getIdsByTeamLeaderName(string $username)
    {
        $userList = Db::name('user')
            ->alias('u')
            ->where('u.name', 'like', '%' . $username . '%')
            ->join('member m', 'u.uid=m.uid')
            ->field(['u.uid as uid'])
            ->cache('getIdsByTeamLeaderName' . $username, 600)
            ->select()
            ->toArray();
        $ids = [];
        foreach ($userList as $item) {
            $ids[] = $item['uid'];
        }

        return $ids;
    }

    /**
     * @title  汇总收益列表数据和计算利润
     * @param array $dataList
     * @param int $type 类型 1为无分润订单 2为有分润订单
     * @param int $groupType 分组类型 1为按照订单分组 2为按照订单+sku分组
     * @return array
     * @throws \Exception
     */
    public function summaryAndProfit(array $dataList, int $type = 2,int $groupType= 2)
    {
        $allList = [];
        if (empty($dataList)) {
            return [];
        }
        $allOrderList = [];
        $goodsNeedFindAfterSaleApplyPrice = [];
        $afterSaleIngCount = 0;
        if($groupType == 1){
            foreach ($dataList as $key => $value) {
                if (!isset($allOrderList[$value['order_sn']])) {
                    $allOrderList[$value['order_sn']] = $value;
                    $allOrderList[$value['order_sn']]['total_price'] = 0;
                    $allOrderList[$value['order_sn']]['cost_price'] = 0;
                    $allOrderList[$value['order_sn']]['fare_price'] = 0;
                    $allOrderList[$value['order_sn']]['purchase_price'] = 0;
                    $allOrderList[$value['order_sn']]['coupon_dis'] = 0;
                    $allOrderList[$value['order_sn']]['direct_price'] = 0;
                    $allOrderList[$value['order_sn']]['indirect_price'] = 0;
                    $allOrderList[$value['order_sn']]['refund_price'] = 0;
                    $allOrderList[$value['order_sn']]['goods'] = [];
                    $allOrderList[$value['order_sn']]['real_pay_price'] = 0;
                    $allOrderList[$value['order_sn']]['divide_price'] = 0;
                    $allOrderList[$value['order_sn']]['profit'] = 0;
                    $allOrderList[$value['order_sn']]['after_sale_ing_price'] = 0;
                    $allOrderList[$value['order_sn']]['server_price'] = 0;
                    $allOrderList[$value['order_sn']]['original_cost_price'] = 0;
                    $allOrderList[$value['order_sn']]['ks_server_price'] = 0;
                    $allOrderList[$value['order_sn']]['correction_fare'] = 0;
                    $allOrderList[$value['order_sn']]['correction_supplier'] = 0;
                    $allOrderList[$value['order_sn']]['correction_cost'] = 0;
                    $allOrderList[$value['order_sn']]['VAT'] = 0;
                    $allOrderList[$value['order_sn']]['financial_income'] = 0;
                    $allOrderList[$value['order_sn']]['total_payable'] = 0;

                    $allOrderList[$value['order_sn']]['exist_before_shipping_after_sale'] = false;
                    $allOrderList[$value['order_sn']]['supplement_fare_price'] = 0;
                }
                $allOrderList[$value['order_sn']]['total_price'] += $value['total_price'];
//            if((string)$value['refund_price'] < (string)($value['total_price'] - $value['fare_price'])){
//                $allOrderList[$value['order_sn']]['cost_price'] += $value['cost_price'];
//            }
                $allOrderList[$value['order_sn']]['cost_price'] += $value['cost_price'];
                $allOrderList[$value['order_sn']]['original_cost_price'] += $value['cost_price'];
                $allOrderList[$value['order_sn']]['fare_price'] += $value['fare_price'];
                $allOrderList[$value['order_sn']]['purchase_price'] += ($value['purchase_price'] ?? 0);
                $allOrderList[$value['order_sn']]['coupon_dis'] += ($value['coupon_dis'] ?? 0);
                $allOrderList[$value['order_sn']]['direct_price'] += ($value['direct_price'] ?? 0);
                $allOrderList[$value['order_sn']]['indirect_price'] += ($value['indirect_price'] ?? 0);
                $allOrderList[$value['order_sn']]['refund_price'] += ($value['refund_price'] ?? 0);
                $allOrderList[$value['order_sn']]['goods'][$value['sku_sn']]['order_sn'] = $value['order_sn'];
                $allOrderList[$value['order_sn']]['goods'][$value['sku_sn']]['goods_sn'] = $value['goods_sn'];
                $allOrderList[$value['order_sn']]['goods'][$value['sku_sn']]['sku_sn'] = $value['sku_sn'];
                $allOrderList[$value['order_sn']]['goods'][$value['sku_sn']]['title'] = $value['goods_title'];
                $allOrderList[$value['order_sn']]['goods'][$value['sku_sn']]['specs'] = $value['goods_specs'];
                $allOrderList[$value['order_sn']]['goods'][$value['sku_sn']]['count'] = $value['count'];
                $allOrderList[$value['order_sn']]['goods'][$value['sku_sn']]['price'] = $value['price'];
                $allOrderList[$value['order_sn']]['goods'][$value['sku_sn']]['total_price'] = ($value['total_price'] ?? 0);
                $allOrderList[$value['order_sn']]['goods'][$value['sku_sn']]['real_pay_price'] = $value['real_pay_price'];
                $allOrderList[$value['order_sn']]['goods'][$value['sku_sn']]['coupon_dis'] = $value['coupon_dis'];
                $allOrderList[$value['order_sn']]['goods'][$value['sku_sn']]['direct_price'] = ($value['direct_price'] ?? 0);
                $allOrderList[$value['order_sn']]['goods'][$value['sku_sn']]['indirect_price'] = ($value['indirect_price'] ?? 0);
                $allOrderList[$value['order_sn']]['goods'][$value['sku_sn']]['divide_price'] = ($value['divide_price'] ?? 0);
                $allOrderList[$value['order_sn']]['goods'][$value['sku_sn']]['fare_price'] = ($value['fare_price'] ?? 0);
                $allOrderList[$value['order_sn']]['goods'][$value['sku_sn']]['cost_price'] = ($value['cost_price'] ?? 0);
                $allOrderList[$value['order_sn']]['goods'][$value['sku_sn']]['original_cost_price'] = ($value['cost_price'] ?? 0);
                $allOrderList[$value['order_sn']]['goods'][$value['sku_sn']]['goods_cost_price'] = ($value['goods_cost_price'] ?? 0);
                $allOrderList[$value['order_sn']]['goods'][$value['sku_sn']]['refund_price'] = ($value['refund_price'] ?? 0);
                $allOrderList[$value['order_sn']]['goods'][$value['sku_sn']]['status'] = $value['order_goods_status'];
                $allOrderList[$value['order_sn']]['goods'][$value['sku_sn']]['after_status'] = $value['order_goods_after_status'];
                $allOrderList[$value['order_sn']]['goods'][$value['sku_sn']]['after_sale_ing_price'] = 0;
                $allOrderList[$value['order_sn']]['goods'][$value['sku_sn']]['after_sale_withdraw_time'] = null;
                $allOrderList[$value['order_sn']]['goods'][$value['sku_sn']]['supplier_code'] = $value['supplier_code'] ?? null;
                $allOrderList[$value['order_sn']]['goods'][$value['sku_sn']]['shipping_status'] = $value['shipping_status'] ?? 1;
                $allOrderList[$value['order_sn']]['goods'][$value['sku_sn']]['activity_sign'] = $value['activity_sign'] ?? null;
                $allOrderList[$value['order_sn']]['goods'][$value['sku_sn']]['activity_name'] = null;
                $allOrderList[$value['order_sn']]['goods'][$value['sku_sn']]['total_payable'] = 0;
                //如果处理售后中需要重新查询获得对应的申请金额
                if (in_array($value['order_goods_after_status'], [2, 3, 4, 6])) {
                    $goodsNeedFindAfterSaleApplyPrice[$afterSaleIngCount]['order_sn'] = $value['order_sn'];
                    $goodsNeedFindAfterSaleApplyPrice[$afterSaleIngCount]['sku_sn'] = $value['sku_sn'];
                    $afterSaleIngCount++;
                }


                $allOrderList[$value['order_sn']]['real_pay_price'] += $value['real_pay_price'];
                $allOrderList[$value['order_sn']]['divide_price'] += ($value['divide_price'] ?? 0);
                $allOrderList[$value['order_sn']]['type'] = $this->getTypeText($value['type'] ?? 0);
                $allOrderList[$value['order_sn']]['arrival_status'] = $this->getArrivalStatusText($value['arrival_status'] ?? 0);
            }
        }else{
            foreach ($dataList as $key => $value) {
                if (!isset($allOrderList[$value['order_sn']]) || (!empty($allOrderList[$value['order_sn']]) && !isset($allOrderList[$value['order_sn']][$value['sku_sn']]))) {
                    $allOrderList[$value['order_sn']][$value['sku_sn']] = $value;
                    $allOrderList[$value['order_sn']][$value['sku_sn']]['total_price'] = 0;
                    $allOrderList[$value['order_sn']][$value['sku_sn']]['cost_price'] = 0;
                    $allOrderList[$value['order_sn']][$value['sku_sn']]['fare_price'] = 0;
                    $allOrderList[$value['order_sn']][$value['sku_sn']]['purchase_price'] = 0;
                    $allOrderList[$value['order_sn']][$value['sku_sn']]['coupon_dis'] = 0;
                    $allOrderList[$value['order_sn']][$value['sku_sn']]['direct_price'] = 0;
                    $allOrderList[$value['order_sn']][$value['sku_sn']]['indirect_price'] = 0;
                    $allOrderList[$value['order_sn']][$value['sku_sn']]['refund_price'] = 0;
                    $allOrderList[$value['order_sn']][$value['sku_sn']]['goods'] = [];
                    $allOrderList[$value['order_sn']][$value['sku_sn']]['real_pay_price'] = 0;
                    $allOrderList[$value['order_sn']][$value['sku_sn']]['divide_price'] = 0;
                    $allOrderList[$value['order_sn']][$value['sku_sn']]['profit'] = 0;
                    $allOrderList[$value['order_sn']][$value['sku_sn']]['after_sale_ing_price'] = 0;
                    $allOrderList[$value['order_sn']][$value['sku_sn']]['server_price'] = 0;
                    $allOrderList[$value['order_sn']][$value['sku_sn']]['original_cost_price'] = 0;
                    $allOrderList[$value['order_sn']][$value['sku_sn']]['ks_server_price'] = 0;
                    $allOrderList[$value['order_sn']][$value['sku_sn']]['correction_fare'] = 0;
                    $allOrderList[$value['order_sn']][$value['sku_sn']]['correction_supplier'] = 0;
                    $allOrderList[$value['order_sn']][$value['sku_sn']]['correction_cost'] = 0;
                    $allOrderList[$value['order_sn']][$value['sku_sn']]['VAT'] = 0;
                    $allOrderList[$value['order_sn']][$value['sku_sn']]['financial_income'] = 0;
                    $allOrderList[$value['order_sn']][$value['sku_sn']]['total_payable'] = 0;
                    $allOrderList[$value['order_sn']][$value['sku_sn']]['exist_before_shipping_after_sale'] = false;
                    $allOrderList[$value['order_sn']][$value['sku_sn']]['supplement_fare_price'] = 0;
                }
                $allOrderList[$value['order_sn']][$value['sku_sn']]['total_price'] += $value['total_price'];
//            if((string)$value['refund_price'] < (string)($value['total_price'] - $value['fare_price'])){
//                $allOrderList[$value['order_sn']]['cost_price'] += $value['cost_price'];
//            }
                $allOrderList[$value['order_sn']][$value['sku_sn']]['cost_price'] += $value['cost_price'];
                $allOrderList[$value['order_sn']][$value['sku_sn']]['original_cost_price'] += $value['cost_price'];
                $allOrderList[$value['order_sn']][$value['sku_sn']]['fare_price'] += $value['fare_price'];
                $allOrderList[$value['order_sn']][$value['sku_sn']]['purchase_price'] += ($value['purchase_price'] ?? 0);
                $allOrderList[$value['order_sn']][$value['sku_sn']]['coupon_dis'] += ($value['coupon_dis'] ?? 0);
                $allOrderList[$value['order_sn']][$value['sku_sn']]['direct_price'] += ($value['direct_price'] ?? 0);
                $allOrderList[$value['order_sn']][$value['sku_sn']]['indirect_price'] += ($value['indirect_price'] ?? 0);
                $allOrderList[$value['order_sn']][$value['sku_sn']]['refund_price'] += ($value['refund_price'] ?? 0);
                $allOrderList[$value['order_sn']][$value['sku_sn']]['goods'][$value['sku_sn']]['order_sn'] = $value['order_sn'];
                $allOrderList[$value['order_sn']][$value['sku_sn']]['goods'][$value['sku_sn']]['goods_sn'] = $value['goods_sn'];
                $allOrderList[$value['order_sn']][$value['sku_sn']]['goods'][$value['sku_sn']]['sku_sn'] = $value['sku_sn'];
                $allOrderList[$value['order_sn']][$value['sku_sn']]['goods'][$value['sku_sn']]['title'] = $value['goods_title'];
                $allOrderList[$value['order_sn']][$value['sku_sn']]['goods'][$value['sku_sn']]['specs'] = $value['goods_specs'];
                $allOrderList[$value['order_sn']][$value['sku_sn']]['goods'][$value['sku_sn']]['count'] = $value['count'];
                $allOrderList[$value['order_sn']][$value['sku_sn']]['goods'][$value['sku_sn']]['price'] = $value['price'];
                $allOrderList[$value['order_sn']][$value['sku_sn']]['goods'][$value['sku_sn']]['total_price'] = ($value['total_price'] ?? 0);
                $allOrderList[$value['order_sn']][$value['sku_sn']]['goods'][$value['sku_sn']]['real_pay_price'] = $value['real_pay_price'];
                $allOrderList[$value['order_sn']][$value['sku_sn']]['goods'][$value['sku_sn']]['coupon_dis'] = $value['coupon_dis'];
                $allOrderList[$value['order_sn']][$value['sku_sn']]['goods'][$value['sku_sn']]['direct_price'] = ($value['direct_price'] ?? 0);
                $allOrderList[$value['order_sn']][$value['sku_sn']]['goods'][$value['sku_sn']]['indirect_price'] = ($value['indirect_price'] ?? 0);
                $allOrderList[$value['order_sn']][$value['sku_sn']]['goods'][$value['sku_sn']]['divide_price'] = ($value['divide_price'] ?? 0);
                $allOrderList[$value['order_sn']][$value['sku_sn']]['goods'][$value['sku_sn']]['fare_price'] = ($value['fare_price'] ?? 0);
                $allOrderList[$value['order_sn']][$value['sku_sn']]['goods'][$value['sku_sn']]['cost_price'] = ($value['cost_price'] ?? 0);
                $allOrderList[$value['order_sn']][$value['sku_sn']]['goods'][$value['sku_sn']]['original_cost_price'] = ($value['cost_price'] ?? 0);
                $allOrderList[$value['order_sn']][$value['sku_sn']]['goods'][$value['sku_sn']]['goods_cost_price'] = ($value['goods_cost_price'] ?? 0);
                $allOrderList[$value['order_sn']][$value['sku_sn']]['goods'][$value['sku_sn']]['refund_price'] = ($value['refund_price'] ?? 0);
                $allOrderList[$value['order_sn']][$value['sku_sn']]['goods'][$value['sku_sn']]['status'] = $value['order_goods_status'];
                $allOrderList[$value['order_sn']][$value['sku_sn']]['goods'][$value['sku_sn']]['after_status'] = $value['order_goods_after_status'];
                $allOrderList[$value['order_sn']][$value['sku_sn']]['goods'][$value['sku_sn']]['after_sale_ing_price'] = 0;
                $allOrderList[$value['order_sn']][$value['sku_sn']]['goods'][$value['sku_sn']]['after_sale_withdraw_time'] = null;
                $allOrderList[$value['order_sn']][$value['sku_sn']]['goods'][$value['sku_sn']]['supplier_code'] = $value['supplier_code'] ?? null;
                $allOrderList[$value['order_sn']][$value['sku_sn']]['goods'][$value['sku_sn']]['shipping_status'] = $value['shipping_status'] ?? 1;
                $allOrderList[$value['order_sn']][$value['sku_sn']]['goods'][$value['sku_sn']]['activity_sign'] = $value['activity_sign'] ?? null;
                $allOrderList[$value['order_sn']][$value['sku_sn']]['goods'][$value['sku_sn']]['activity_name'] = null;
                $allOrderList[$value['order_sn']][$value['sku_sn']]['goods'][$value['sku_sn']]['correction_fare'] = ($value['correction_fare'] ?? 0);
                $allOrderList[$value['order_sn']][$value['sku_sn']]['goods'][$value['sku_sn']]['correction_supplier'] = ($value['correction_supplier'] ?? 0);
                $allOrderList[$value['order_sn']][$value['sku_sn']]['goods'][$value['sku_sn']]['correction_cost'] = ($value['correction_cost'] ?? 0);
                $allOrderList[$value['order_sn']][$value['sku_sn']]['goods'][$value['sku_sn']]['total_payable'] = 0;
                //如果处理售后中需要重新查询获得对应的申请金额
                if (in_array($value['order_goods_after_status'], [2, 3, 4, 6])) {
                    $goodsNeedFindAfterSaleApplyPrice[$afterSaleIngCount]['order_sn'] = $value['order_sn'];
                    $goodsNeedFindAfterSaleApplyPrice[$afterSaleIngCount]['sku_sn'] = $value['sku_sn'];
                    $afterSaleIngCount++;
                }


                $allOrderList[$value['order_sn']][$value['sku_sn']]['real_pay_price'] += $value['real_pay_price'];
                $allOrderList[$value['order_sn']][$value['sku_sn']]['divide_price'] += ($value['divide_price'] ?? 0);
                $allOrderList[$value['order_sn']][$value['sku_sn']]['type'] = $this->getTypeText($value['type'] ?? 0);
                $allOrderList[$value['order_sn']][$value['sku_sn']]['arrival_status'] = $this->getArrivalStatusText($value['arrival_status'] ?? 0);
            }
        }

        if (!empty($allOrderList)) {
            if($groupType == 2){
                foreach ($allOrderList as $key => $value) {
                    foreach ($value as $pkey => $pvalue) {
                        $allList[] = $pvalue;
                    }
                }
            }else{
                foreach ($allOrderList as $key => $value) {
                    $allList[] = $value;
                }
            }


            if (!empty($allList)) {
                $allUid = array_unique(array_filter(array_column($allList, 'order_uid')));
                $userInfos = User::where(['uid' => $allUid])->field('uid,name,phone,avatarUrl,vip_level')->select()->toArray();
                if (!empty($userInfos)) {
                    foreach ($userInfos as $key => $value) {
                        $userInfo[$value['uid']] = $value;
                    }
                }
                if (!empty($goodsNeedFindAfterSaleApplyPrice)) {
                    $afterOrderSn = array_unique(array_filter(array_column($goodsNeedFindAfterSaleApplyPrice, 'order_sn')));
                    $afterSkuSn = array_unique(array_filter(array_column($goodsNeedFindAfterSaleApplyPrice, 'sku_sn')));
                    $afterSaleList = AfterSale::where(['order_sn' => $afterOrderSn, 'sku_sn' => $afterSkuSn])->field('order_sn,sku_sn,apply_price,withdraw_time')->select()->toArray();
                    if (!empty($afterSaleList)) {
                        foreach ($allList as $key => $value) {
                            foreach ($afterSaleList as $aKey => $aValue) {
                                if ($value['order_sn'] == $aValue['order_sn']) {
                                    if (!empty($value['goods'])) {
                                        foreach ($value['goods'] as $gKey => $gValue) {
                                            if ($gValue['order_sn'] == $aValue['order_sn'] && $aValue['sku_sn'] == $gValue['sku_sn']) {
                                                $allList[$key]['goods'][$gKey]['after_sale_ing_price'] += $aValue['apply_price'] ?? 0;
                                                if (!empty($aValue['withdraw_time'])) {
                                                    $allList[$key]['goods'][$gKey]['after_sale_withdraw_time'] = ($aValue['withdraw_time']);
                                                }
                                            }
                                        }
                                    }
                                }
                            }
                        }
                    }
                }

                foreach ($allList as $key => $item) {
                    //计算每个商品的利润,在下一个循环汇总得到最后的利润
                    if (!empty($item['goods'])) {
                        foreach ($item['goods'] as $gKey => $gValue) {
                            if (!empty(doubleval($gValue['refund_price'])) && (string)$gValue['refund_price'] >= (string)($gValue['real_pay_price'] - $gValue['fare_price']) && in_array($gValue['shipping_status'], [1, 2])) {
                                $allList[$key]['goods'][$gKey]['server_price'] = 0;
                                $allList[$key]['goods'][$gKey]['profit'] = 0;
                                $gValue['server_price'] = 0;
                                $gValue['ks_server_price'] = 0;
                                $gValue['correction_fare'] = 0;
                                $gValue['correction_supplier'] = 0;
                                $gValue['correction_cost'] = 0;
                                $gValue['VAT'] = 0;
                                $gValue['financial_income'] = 0;
                                $gValue['total_payable'] = 0;

                                $gValue['profit'] = 0;
                                $allList[$key]['goods'][$gKey]['cost_price'] = 0;
//                                $allList[$key]['goods'][$gKey]['coupon_dis'] = 0;
                                $allList[$key]['cost_price'] -= $gValue['cost_price'];
//                                $allList[$key]['coupon_dis'] -= $gValue['coupon_dis'];
                                $allList[$key]['goods'][$gKey]['total_price'] += $gValue['fare_price'];
                                $allList[$key]['exist_before_shipping_after_sale'] = true;
                                $allList[$key]['total_price'] += $gValue['fare_price'];
                                $allList[$key]['supplement_fare_price'] += $gValue['fare_price'];
                            } else {
                                $allList[$key]['goods'][$gKey]['server_price'] = round(($gValue['real_pay_price'] - $gValue['refund_price']) * $this->financialHandlingFee,2);
                                $allList[$key]['goods'][$gKey]['ks_server_price'] = round(($gValue['divide_price']) * $this->kuaiShangFinancialHandlingFee,2);
                                $gValue['server_price'] = $allList[$key]['goods'][$gKey]['server_price'];
                                //快商服务费=（销售佣金+教育资金）*6.8%
                                $gValue['ks_server_price'] = $allList[$key]['goods'][$gKey]['ks_server_price'];

                                //校正的运费如果为空默认为原始运费,这个很重要,会影响后面的公式计算
                                if ((empty($gValue['correction_fare']) || $gValue['correction_fare'] <= 0) && !empty($gValue['fare_price'])) {
                                    $allList[$key]['goods'][$gKey]['correction_fare'] = $gValue['fare_price'];
                                    $gValue['correction_fare'] = $gValue['fare_price'];
                                }

                                //增值税
                                //税费＝（（成交金额+收取运费-优惠券金额-退售金额-商品成本部价-支付运费+售后应扣供应商金额-对账成本调整）/1.06*0.06）-（（销售佣金+教育资金+佣金服务费）/1.06*0.06+金融服务费/1.06*0.06）
                                $allList[$key]['goods'][$gKey]['VAT'] = round((($gValue['real_pay_price'] - $gValue['refund_price'] - $gValue['cost_price'] - ($gValue['correction_fare'] ?? 0) + ($gValue['correction_supplier'] ?? 0) - ($gValue['correction_cost'] ?? 0)) / 1.06 * 0.06) - (($gValue['divide_price'] + ($gValue['ks_server_price'] ?? 0)) / 1.06 * 0.06 + (($gValue['server_price'] ?? 0) / 1.06 * 0.06)),2);
                                $gValue['VAT'] = $allList[$key]['goods'][$gKey]['VAT'];
                                if(!empty($gValue['VAT'] ?? null) && $gValue['VAT'] < 0){
                                    $gValue['VAT'] = 0;
                                }
                                if(!empty($allList[$key]['goods'][$gKey]['VAT'] ?? null) && $allList[$key]['goods'][$gKey]['VAT'] < 0){
                                    $allList[$key]['goods'][$gKey]['VAT'] = 0;
                                }
                                //财务收入
                                //财务收入=(成交金额+收取运费-优惠券金额-退售-商品成本总计-支付运费+售后应扣供应商金额-对账成本调整)/1.06
                                $allList[$key]['goods'][$gKey]['financial_income'] = round((($gValue['real_pay_price'] - $gValue['refund_price'] - $gValue['cost_price'] - ($gValue['correction_fare'] ?? 0) + ($gValue['correction_supplier'] ?? 0) - ($gValue['correction_cost'] ?? 0)) / 1.06),2);
                                $gValue['financial_income'] = $allList[$key]['goods'][$gKey]['financial_income'];
//                                $allList[$key]['goods'][$gKey]['profit'] = round($gValue['total_price'] - ($gValue['server_price'] ?? 0) - ($gValue['cost_price'] ?? 0) - ($gValue['divide_price'] ?? 0) - ($gValue['coupon_dis'] ?? 0) - ($gValue['refund_price'] ?? 0),2);
                                //利润(税后)
                                //利润＝成交金额+收取运费-优惠券金额-退售金额-商品成本金额-支付运费+售后应扣供应商金额-对账成本调整-销售佣金-教育资金-佣金服务费-金融服务费-税费
                                $allList[$key]['goods'][$gKey]['profit'] = round($gValue['real_pay_price'] - $gValue['refund_price'] - $gValue['cost_price'] - ($gValue['correction_fare'] ?? 0) + ($gValue['correction_supplier'] ?? 0) - ($gValue['correction_cost'] ?? 0) - ($gValue['divide_price'] ?? 0) - ($gValue['server_price'] ?? 0) - ($gValue['ks_server_price'] ?? 0) - ($gValue['VAT'] ?? 0),2);
                                $gValue['profit'] = $allList[$key]['goods'][$gKey]['profit'];

                                //应付总计＝成本总计+支付运费-售后应扣金额+对账成本调整（即应该付供应商金额）
                                $allList[$key]['goods'][$gKey]['total_payable'] = round($gValue['cost_price'] + ($gValue['correction_fare'] ?? 0) - ($gValue['correction_supplier'] ?? 0) + ($gValue['correction_cost'] ?? 0),2);
                                $gValue['total_payable'] = $allList[$key]['goods'][$gKey]['total_payable'];
                            }

                            $allList[$key]['after_sale_ing_price'] += ($gValue['after_sale_ing_price'] ?? 0);
                            $allList[$key]['server_price'] += $gValue['server_price'];
                            $allList[$key]['profit'] += $gValue['profit'];


                            $allList[$key]['ks_server_price'] += ($gValue['ks_server_price'] ?? 0);
                            $allList[$key]['correction_fare'] += ($gValue['correction_fare'] ?? 0);
                            $allList[$key]['correction_supplier'] += ($gValue['correction_supplier'] ?? 0);
                            $allList[$key]['correction_cost'] += ($gValue['correction_cost'] ?? 0);
                            $allList[$key]['VAT'] += ($gValue['VAT'] ?? 0);
                            $allList[$key]['financial_income'] += ($gValue['financial_income'] ?? 0);
                            $allList[$key]['total_payable'] += ($gValue['total_payable'] ?? 0);

                        }
                        //获取最晚的退款时间
                        $afterSaleTimeList = array_filter(array_column($item['goods'], 'after_sale_withdraw_time'));
                        if (!empty($afterSaleTimeList)) {
                            array_multisort($afterSaleTimeList, SORT_DESC);
                            $allList[$key]['last_after_sale_time'] = timeToDateFormat(current($afterSaleTimeList));
                        }
                    }
                }

                foreach ($allList as $key => $item) {
                    //服务费为实际支付金额服务费，如有运费，运费的支付服务费,折扣费用也计算入成本
//                    if((string)$item['refund_price'] >= (string)($item['total_price'] - $item['fare_price'])){
//                        $allList[$key]['server_price'] = 0.00;
//                        $allList[$key]['profit'] = 0.00;
//                    }else{
//                        $allList[$key]['server_price'] = priceFormat(($item['real_pay_price'] - $item['refund_price']) * $this->financialHandlingFee);
//                        $item['server_price'] = $allList[$key]['server_price'];
//                        $allList[$key]['profit'] = priceFormat(($item['total_price'] - ($item['refund_price'] ?? 0))- ($item['server_price'] ?? 0) - ($item['cost_price'] ?? 0) - ($item['divide_price'] ?? 0) - ($item['coupon_dis'] ?? 0));
//                    }
                    if (!empty($item['create_time']) && is_numeric($item['create_time'])) {
                        $allList[$key]['create_time'] = timeToDateFormat($item['create_time']);
                    }
                    if (!empty($item['order_create_time']) && is_numeric($item['order_create_time'])) {
                        $allList[$key]['order_create_time'] = timeToDateFormat($item['order_create_time']);
                    }
                    if (!empty($item['goods'])) {
                        $allList[$key]['goods'] = array_values($item['goods']);
                        $allList[$key]['all_goods_sku'] = implode(',',array_column($item['goods'],'sku_sn'));
                        $allList[$key]['all_goods_sn'] = implode(',',array_column($item['goods'],'goods_sn'));
                    }
                    $allList[$key]['userInfo'] = [];
                    if (!empty($item['order_uid']) && !empty($userInfo[$item['order_uid']])) {
                        $allList[$key]['userInfo'] = $userInfo[$item['order_uid']] ?? [];
                    }
                    $allList[$key]['real_total_price'] = priceFormat($item['real_pay_price'] - $item['refund_price'] ?? 0);
                    unset($allList[$key]['goods_cost_price']);
                    unset($allList[$key]['goods_sn']);
                    unset($allList[$key]['sku_sn']);
                    unset($allList[$key]['goods_specs']);
                    unset($allList[$key]['goods_title']);
                    unset($allList[$key]['price']);
                    unset($allList[$key]['purchase_price']);
                    unset($allList[$key]['order_goods_status']);
                    unset($allList[$key]['supplier_code']);
                }

                foreach ($allList as $key => $item) {
                    if (!empty($item['direct_price'])) {
                        $allList[$key]['direct_price'] = priceFormat($item['direct_price']);
                    }
                    if (!empty($item['indirect_price'])) {
                        $allList[$key]['indirect_price'] = priceFormat($item['indirect_price']);
                    }
                    if (!empty($item['divide_price'])) {
                        $allList[$key]['divide_price'] = priceFormat($item['divide_price']);
                    }
                    if (!empty($item['total_price'])) {
                        $allList[$key]['total_price'] = priceFormat($item['total_price']);
                    }
                    if (!empty($item['cost_price'])) {
                        $allList[$key]['cost_price'] = priceFormat($item['cost_price']);
                    }
                    if (!empty($item['coupon_dis'])) {
                        $allList[$key]['coupon_dis'] = priceFormat($item['coupon_dis']);
                    }
                    if (!empty($item['refund_price'])) {
                        $allList[$key]['refund_price'] = priceFormat($item['refund_price']);
                    }
                    if (!empty($item['server_price'])) {
                        $allList[$key]['server_price'] = priceFormat($item['server_price']);
                    }
                    if (!empty($item['server_price'])) {
                        $allList[$key]['profit'] = priceFormat($item['profit']);
                    }
                    if (!empty($item['original_cost_price'])) {
                        $allList[$key]['original_cost_price'] = priceFormat($item['original_cost_price']);
                    }
                    if(!empty($item['VAT'] ?? null) && $item['VAT'] < 0){
                        $allList[$key]['VAT'] = 0;
                    }
                }
            }
        }

        return $allList ?? [];
    }

    /**
     * @title  广宣奖奖励列表
     * @param array $sear
     * @return array
     */
    public function PropagandaRewardList(array $sear)
    {
        $map = [];
        $map[] = ['a.status', '=', 1];
        if (!empty($sear['plan_sn'])) {
            $map[] = ['a.order_sn', 'like', ['%' . $sear['plan_sn'] . '%']];
        }

        if (!empty($sear['start_time']) && !empty($sear['end_time'])) {
            $map[] = ['a.create_time', 'between', [strtotime(substr($sear['start_time'], 0, 10) . ' 00:00:00'), strtotime(substr($sear['end_time'], 0, 10) . ' 23:59:59')]];
        }

        if (!empty($sear['username'])) {
            $map[] = ['a.link_uid', 'in', $this->getIdsByTeamLeaderName($sear['username'])];
        }


//        $map[] = ['status', 'in', $this->getStatusByRequestModule($sear['searType'] ?? 1)];
        $page = intval($sear['page'] ?? 0) ?: null;

        $field = $this->getListFieldByModule('list');
        $this->pageNumber = !empty($sear['pageNumber']) ? intval($sear['pageNumber']) : $this->pageNumber;
        if (!empty($page)) {
            $aTotal = self::alias('a')
                ->join('sp_user b', 'a.link_uid = b.uid', 'left')
                ->where($map)
                ->count();
            $pageTotal = ceil($aTotal / $this->pageNumber);
        }

        $list = self::alias('a')
            ->join('sp_user b', 'a.link_uid = b.uid', 'left')
            ->where($map)
            ->field('a.*,b.phone,b.name,b.vip_level')
            ->when($page, function ($query) use ($page) {
                $query->page($page, $this->pageNumber);
            })
            ->select()
            ->each(function ($item) {
                if (!empty($item['arrive_time'])) {
                    $item['arrive_time'] = timeToDateFormat($item['arrive_time']);
                }
            })->toArray();

        return ['list' => $list, 'pageTotal' => $pageTotal ?? 0];
    }

    /**
     * @title  股东奖奖励列表
     * @param array $sear
     * @return array
     */
    public function shareholderRewardList(array $sear)
    {
        $map = [];
        $map[] = ['a.type','=',6];
        $map[] = ['a.status','=',1];
        if (!empty($sear['plan_sn'])) {
            $map[] = ['a.order_sn', 'like', ['%' . $sear['plan_sn'] . '%']];
        }

        if (!empty($sear['start_time']) && !empty($sear['end_time'])) {
            $map[] = ['a.create_time', 'between', [strtotime(substr($sear['start_time'], 0, 10) . ' 00:00:00'), strtotime(substr($sear['end_time'], 0, 10) . ' 23:59:59')]];
        }

        if (!empty($sear['username'])) {
            $map[] = ['a.link_uid', 'in', $this->getIdsByTeamLeaderName($sear['username'])];
        }


//        $map[] = ['status', 'in', $this->getStatusByRequestModule($sear['searType'] ?? 1)];
        $page = intval($sear['page'] ?? 0) ?: null;

        $field = $this->getListFieldByModule('list');
        $pageNumber = !empty($sear['pageNumber']) ? intval($sear['pageNumber']) : $this->pageNumber;
        if (!empty($page)) {
            $aTotal = self::alias('a')
                ->join('sp_user b', 'a.link_uid = b.uid', 'left')
                ->where($map)
                ->count();
            $pageTotal = ceil($aTotal / $this->pageNumber);
        }

        $list = self::alias('a')
            ->join('sp_user b', 'a.link_uid = b.uid', 'left')
            ->where($map)
            ->field('a.*,b.phone,b.name,b.vip_level')
            ->when($page, function ($query) use ($page) {
                $query->page($page, $this->pageNumber);
            })
            ->select()
            ->each(function ($item) {
                if (!empty($item['arrive_time'])) {
                    $item['arrive_time'] = timeToDateFormat($item['arrive_time']);
                }
            })->toArray();

        return ['list' => $list, 'pageTotal' => $pageTotal ?? 0];
    }

    /**
     * @title  设备订单分润列表
     * @param array $sear
     * @return array
     */
    public function deviceDivideList(array $sear)
    {
        $map = [];
        $map[] = ['a.type', '=', 7];
        $map[] = ['a.status', '=', 1];
        $map[] = ['','exp',Db::raw('a.device_sn is not null')];
        if (!empty($sear['order_sn'])) {
//            $map[] = ['a.order_sn', 'like', ['%' . $sear['order_sn'] . '%']];
            $map[] = ['', 'exp', Db::raw($this->getFuzzySearSql('a.order_sn|a.device_sn', $sear['order_sn']))];
        }

        if (!empty($sear['start_time']) && !empty($sear['end_time'])) {
            $map[] = ['a.create_time', 'between', [strtotime(substr($sear['start_time'], 0, 10) . ' 00:00:00'), strtotime(substr($sear['end_time'], 0, 10) . ' 23:59:59')]];
        }

        if (!empty($sear['username'])) {
            $map[] = ['a.link_uid', 'in', $this->getIdsByTeamLeaderName($sear['username'])];
        }

        $page = intval($sear['page'] ?? 0) ?: null;

        $pageNumber = !empty($sear['pageNumber']) ? intval($sear['pageNumber']) : $this->pageNumber;
        if (!empty($page)) {
            $aTotal = self::alias('a')
                ->join('sp_user b', 'a.link_uid = b.uid COLLATE utf8mb4_unicode_ci', 'left')
                ->where($map)
                ->count();
            $pageTotal = ceil($aTotal / $this->pageNumber);
        }

        $list = self::alias('a')
            ->join('sp_user b', 'a.link_uid = b.uid COLLATE utf8mb4_general_ci', 'left')
            ->join('sp_device c', 'a.device_sn = c.device_sn COLLATE utf8mb4_general_ci', 'left')
            ->where($map)
            ->field('a.*,b.phone as link_user_phone,b.name as link_user_name,b.vip_level as link_user_vip_level,c.device_name')
            ->when($page, function ($query) use ($page) {
                $query->page($page, $this->pageNumber);
            })
            ->order('a.create_time desc')
            ->select()
            ->each(function ($item) {
                if (!empty($item['arrive_time'])) {
                    $item['arrive_time'] = timeToDateFormat($item['arrive_time']);
                }
            })->toArray();

        return ['list' => $list, 'pageTotal' => $pageTotal ?? 0];
    }

    /**
     * @title 批量自增/自减-原生sql
     * @param array $data
     * @return mixed
     */
    public function DBBatchIncOrDecBySql(array $data)
    {
        try {
            $DBRes = Db::transaction(function () use ($data) {
                $res = $this->batchIncOrDecBySql($data);
                return $res;
            });
        } catch (\app\lib\BaseException $baseE) {
            $msg = $baseE->msg;
            $code = $baseE->errorCode;
            $DBRes = false;
        } catch (\Exception $e) {
            $msg = $e->getMessage();
            $code = $e->getCode();
            $DBRes = false;
        }
        return judge($DBRes);
    }

    /**
     * @title 批量新增-原生sql
     * @param array $data
     * @return mixed
     */
    public function DBSaveAll(array $data)
    {
        try {
            $DBRes = Db::transaction(function () use ($data) {
                $data['notValidateValueField'] = ['order_uid', 'uid', 'link_uid'];
                $res = $this->batchCreateBySql($data);
                return $res;
            });
        } catch (\app\lib\BaseException $baseE) {
            $msg = $baseE->msg;
            $code = $baseE->errorCode;
            $DBRes = false;
        } catch (\Exception $e) {
            $msg = $e->getMessage();
            $code = $e->getCode();
            $DBRes = false;
        }
        return judge($DBRes);
    }


    public function link()
    {
        return $this->hasOne('User', 'uid', 'link_uid')->bind(['link_user_name' => 'name', 'link_user_phone' => 'phone', 'link_user_level' => 'vip_level']);
    }

    public function orderGoods()
    {
        return $this->hasOne('OrderGoods', 'sku_sn', 'sku_sn');
    }

    public function orderUser()
    {
        return $this->hasOne('User', 'uid', 'order_uid')->bind(['order_user_name' => 'name', 'order_user_phone' => 'phone', 'order_user_level' => 'vip_level']);
    }

    public function orderAllGoods()
    {
        return $this->hasMany('OrderGoods', 'order_sn', 'order_sn')->field('order_sn,goods_sn,sku_sn,title,specs,total_price,price,count,real_pay_price,cost_price');
    }

}
