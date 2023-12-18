<?php
// +----------------------------------------------------------------------
// |[ 文档说明: 订单模块定时任务]
// +----------------------------------------------------------------------
// | Copyright (c) 2018~2024 http://www.mlhcmk.com All rights reserved.
// +----------------------------------------------------------------------
 

namespace app\lib\job;


use app\lib\models\CrowdfundingPeriod;
use app\lib\models\GoodsSku;
use app\lib\models\Order;
use app\lib\models\Order as OrderModel;
use app\lib\models\OrderCoupon;
use app\lib\models\OrderGoods;
use app\lib\models\PtOrder;
use app\lib\models\UserCoupon;
use app\lib\services\JoinPay;
use app\lib\services\Member;
use app\lib\services\Ship;
use app\lib\subscribe\Timer;
use think\facade\Db;
use think\facade\Log;
use think\queue\Job;

class TimerForOrder
{
    public $timeOutSecond = 900;                       //订单未支付超时时间
    public $crowdTimeOutSecond = 180;                  //众筹订单未支付超时时间
    public $receiveTimeOutSecond = 3600 * 24 * 7;     //订单未签收超时时间

    /**
     * @title  fire
     * @param Job $job
     * @param  $data
     * @return void
     * @throws \Exception
     */
    public function fire(Job $job, $data)
    {
        Log::close('file');
        if (!empty($data)) {
            //type 1为超时未支付订单 2为超时签收订单
            switch ($data['type'] ?? 1) {
                case 1:
                    $map[] = ['pay_status', '=', 1];
                    $map[] = ['order_status', '=', 1];
                    //提前十秒关闭订单,为了防止误支付
                //众筹订单的关闭时间不同于普通订单,需要区分判断
//                    $map[] = ['create_time', '<=', (time() - ($this->timeOutSecond - 10))];
                    $overTimeOrder = Order::where(function ($query) {
                        $where1[] = ['order_type', '<>', 6];
                        $where1[] = ['create_time', '<=', (time() - ($this->timeOutSecond - 10))];
                        $where2[] = ['order_type', '=', 6];
                        $where2[] = ['create_time', '<=', (time() - ($this->crowdTimeOutSecond - 10))];
                        $query->whereOr([$where1, $where2]);
                    })->where($map)->column('order_sn');
                    if (!empty($overTimeOrder)) {
                        $log['msg'] = '查找到超时订单,推入处理超时订单队列';
                        $log['data'] = $overTimeOrder;
                        $log['time'] = date('Y-m-d H:i:s');
                        $res = $this->overTimeOrder($overTimeOrder ?? []);
                        $log['map'] = $map ?? [];
                        $log['delRes'] = $res;
                        (new Timer())->log($log);
                    }
                    //$this->overTimeOrder($data['order_sn'] ?? []);
                    break;
                case 2:
                    //福利订单不主动签收, 因为分润周期不确定
                    $map[] = ['order_type', '<>', 6];
                    $map[] = ['pay_status', '=', 2];
                    $map[] = ['order_status', '=', 3];
                    $map[] = ['delivery_time', '<=', (time() - $this->receiveTimeOutSecond)];
                    $overReceiveTimeOrder = Order::where($map)->column('order_sn');
                    if (!empty($overReceiveTimeOrder)) {
                        $log['msg'] = '查找到超时签收订单,推入处理超时签收订单队列';
                        $log['data'] = $overReceiveTimeOrder;
                        $log['time'] = date('Y-m-d H:i:s');
                        $res = $this->receiveOrder($overReceiveTimeOrder ?? []);
                        $log['map'] = $map ?? [];
                        $log['delRes'] = $res;
                        (new Timer())->log($log);
                    }
                    //释放变量, 防止内存泄露
                    unset($overReceiveTimeOrder);
                    gc_collect_cycles();
                    // $res = $this->receiveOrder($data['order_sn'] ?? []);
                    break;
            }
        }
        $job->delete();
    }

    /**
     * @title  超时未支付订单恢复库存-定时任务
     * @param array $orderSn
     * @return bool|mixed
     * @throws \Exception
     */
    public function overTimeOrder(array $orderSn)
    {
        $map[] = ['pay_status', '=', 1];
        $map[] = ['order_status', '=', 1];
//        $map[] = ['create_time','<',time() - 7200];
        $map[] = ['order_sn', 'in', $orderSn];
        $overTimeOrder = (new OrderModel())->with(['goods'])->where($map)->field('order_sn,order_type,uid,create_time,pay_status,pay_type,order_status,pay_time,handsel_sn')->select()->toArray();
        $res = false;
        if (!empty($overTimeOrder)) {
            $joinPayService = (new JoinPay());
            foreach ($overTimeOrder as $key => $value) {
                //关闭订单,只做了汇聚支付的,微信支付可以直接通过统一下单传入失效时间,无需手动关单
                if (!empty($value['pay_type'])) {
                    if (in_array($value['pay_type'], [2, 3])) {
                        if ($value['pay_type'] == 2) {
                            $FrpCode = 'WEIXIN_XCX';
                        }
                        if (!empty($FrpCode)) {
                            $closeOrder[] = $joinPayService->closeOrder(['order_sn' => $value['order_sn'], 'pay_type' => $FrpCode]);
                        }
                    }
                }
            }
            $res = Db::transaction(function () use ($overTimeOrder) {
                $goodsSku = new GoodsSku();
                //批量锁行后恢复库存
                foreach ($overTimeOrder as $key => $value) {
                    if (!empty($value['goods'])) {
                        foreach ($value['goods'] as $gKey => $gValue) {
                            $allGoodsSn[] = $gValue['goods_sn'];
                            $allSkuSn[] = $gValue['sku_sn'];
                        }
                    }
                }
                if (!empty($allGoodsSn) && !empty($allSkuSn)) {
                    $allGoodsSn = array_unique(array_filter($allGoodsSn));
                    $allSkuSn = array_unique(array_filter($allSkuSn));
                    $goodsId = $goodsSku->where(['goods_sn' => $allGoodsSn, 'sku_sn' => $allSkuSn])->column('id');
                    if (!empty($goodsId)) {
                        $goodsInfo = $goodsSku->where(['id' => $goodsId])->lock(true)->field('id,goods_sn,sku_sn')->select()->toArray();
                    }
                }

                $orderRes = [];
                $memberService = (new Member());
                foreach ($overTimeOrder as $key => $value) {
                    $orderSn = $value['order_sn'];
                    //修改订单为取消交易
                    $orderSave['order_status'] = -2;
                    $orderSave['pay_status'] = 3;
                    $orderSave['close_time'] = time();
                    $orderRes[] = OrderModel::update($orderSave, ['order_sn' => $orderSn, 'order_status' => 1, 'pay_status' => 1]);

                    //修改订单商品支付状态
                    $goodsSave['pay_status'] = 3;
                    $goodsRes[] = OrderGoods::update($goodsSave, ['order_sn' => $orderSn, 'pay_status' => 1]);

                    //恢复库存
                    if (!empty($value['goods'])) {
                        foreach ($value['goods'] as $goodsKey => $goodsValue) {
                            $res[] = $goodsSku->where(['goods_sn' => $goodsValue['goods_sn'], 'sku_sn' => $goodsValue['sku_sn']])->inc('stock', intval($goodsValue['count']))->update();
                            //如果是众筹需要恢复众筹的目标销售额
                            if ($value['order_type'] == 6 && !empty($goodsValue['crowd_code'] ?? null)) {
                                CrowdfundingPeriod::where(['activity_code' => $goodsValue['crowd_code'], 'round_number' => $goodsValue['crowd_round_number'], 'period_number' => $goodsValue['crowd_period_number']])->inc('last_sales_price', ($goodsValue['real_pay_price'] - $goodsValue['total_fare_price']))->update();
                            }
                        }
                    }


                    //修改对应优惠券状态
                    $aOrderCoupons = OrderCoupon::where(['order_sn' => $orderSn, 'used_status' => 1])->field('coupon_uc_code,coupon_code')->select()->toArray();
                    $aOrderUcCoupon = array_column($aOrderCoupons, 'coupon_uc_code');
                    //修改订单优惠券状态为取消使用
                    $orderCouponRes = OrderCoupon::update(['used_status' => -1], ['order_sn' => $orderSn, 'used_status' => 1]);
                    //修改用户订单优惠券状态为未使用
                    $uCouponRes = UserCoupon::update(['valid_status' => 1], ['uc_code' => $aOrderUcCoupon]);

                    //如果是团长大礼包订单判断取消缓存锁
                    if (!empty($value['order_type']) && $value['order_type'] == 3) {
                        cache($value['uid'] . $memberService->memberUpgradeOrderKey, null);
                    }

                    //如果是转售订单判断取消缓存锁
                    if (!empty($value['order_type']) && $value['order_type'] == 5) {
                        $handselCacheKey = 'handSelOrderLock-' . $value['handsel_sn'];
                        $handselCacheInfo = cache($handselCacheKey, null);
                    }


                }
                return $orderRes;
            });
        }
        return $res;
    }

    /**
     * @title  用户签收快递
     * @param array $orderSn
     * @return bool|mixed
     * @throws \Exception
     */
    public function receiveOrder(array $orderSn)
    {
        $orderInfo = Order::where(['order_sn' => $orderSn])->select()->toArray();
        $res = false;
        if (!empty($orderInfo)) {
            $shipService = (new Ship());
            foreach ($orderInfo as $key => $value) {
                $data['order_sn'] = $value['order_sn'];
                $data['uid'] = $value['uid'];
                $data['notThrowError'] = true;
                $res = $shipService->userConfirmReceiveGoods($data);
            }
        }
        return $res;
    }
}