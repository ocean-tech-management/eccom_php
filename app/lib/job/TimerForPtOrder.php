<?php
// +----------------------------------------------------------------------
// |[ 文档说明: 拼团订单模块定时任务]
// +----------------------------------------------------------------------
// | Copyright (c) 2018~2024 http://www.mlhcmk.com All rights reserved.
// +----------------------------------------------------------------------
 

namespace app\lib\job;


use app\lib\models\AfterSale;
use app\lib\models\GoodsSku;
use app\lib\models\Order as OrderModel;
use app\lib\models\OrderCoupon;
use app\lib\models\OrderGoods;
use app\lib\models\PtGoodsSku;
use app\lib\models\PtOrder;
use app\lib\models\User;
use app\lib\models\UserCoupon;
use app\lib\services\CodeBuilder;
use app\lib\services\JoinPay;
use app\lib\services\Member;
use app\lib\services\Pay;
use app\lib\services\WxPayService;
use app\lib\subscribe\Timer;
use think\facade\Db;
use think\facade\Log;
use think\queue\Job;

class TimerForPtOrder
{
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
            $map[] = ['activity_status', '=', 1];
            $map[] = ['status', '=', 1];
            $map[] = ['end_time', '<=', time()];
            $overTimePtOrder = PtOrder::where($map)->column('activity_sn');
            if (!empty($overTimePtOrder)) {
                $overTimePtOrder = array_unique($overTimePtOrder);
                $log['msg'] = '查找到拼团超时订单,推入处理拼团超时订单队列';
                $log['data'] = $overTimePtOrder;
                $log['time'] = date('Y-m-d H:i:s');
                $res = $this->overTimePtOrder($overTimePtOrder ?? []);
                $log['map'] = $map ?? [];
                $log['delRes'] = $res;
                (new Timer())->log($log);
            }
        }
        $job->delete();
    }

    /**
     * @title  超时未支付订单恢复库存-定时任务
     * @param array $activitySn 超时拼团订单编号
     * @return bool|mixed
     * @throws \Exception
     */
    public function overTimePtOrder(array $activitySn)
    {
        if (empty($activitySn)) {
            return false;
        }
        $map[] = ['activity_status', '=', 1];
        $map[] = ['activity_sn', 'in', $activitySn];
        $overTimePtOrder = (new PtOrder())->with(['orders'])->where($map)->field('activity_sn,activity_code,order_sn,goods_sn,sku_sn,user_role,activity_type,activity_status,pay_status')->select()->toArray();
        $res = false;
        if (!empty($overTimePtOrder)) {
            $res = Db::transaction(function () use ($overTimePtOrder) {
                $orderRes = [];
                $codeBuilder = (new CodeBuilder());
                $PayService = (new JoinPay());
                foreach ($overTimePtOrder as $key => $value) {
                    if ($value['pay_status'] == 2) {
                        $orderSn = $value['order_sn'];
                        //修改拼团订单为失败
                        $ptSave['activity_status'] = 3;
                        $ptSave['close_time'] = time();
                        $ptRes = PtOrder::where(['activity_sn' => $value['activity_sn'], 'activity_status' => 1, 'order_sn' => $orderSn])->update($ptSave);

                        //修改订单为拼团失败自动取消
                        $orderSave['order_status'] = -4;
                        $orderSave['close_time'] = time();
                        $orderRes = OrderModel::update($orderSave, ['order_sn' => $orderSn]);


                        //修改订单商品为状态为超时失败
                        $orderGoodsSave['pay_status'] = 3;
                        $orderGoodsSave['status'] = -3;
                        $orderGoodsRes = OrderGoods::update($orderGoodsSave, ['order_sn' => $orderSn, 'goods_sn' => $value['goods_sn'], 'sku_sn' => $value['sku_sn']]);

                        //只有付款的了才进入退款流程,没有付款的走一下事务区块
                        //退款
                        $refundSn = $codeBuilder->buildRefundSn();
                        $refund['out_trade_no'] = $orderSn;
                        $refund['out_refund_no'] = $refundSn;
                        $refund['total_fee'] = $value['orders']['real_pay_price'];
                        $refund['refund_fee'] = $value['orders']['real_pay_price'];
//                    $refund['total_fee'] = 0.01;
//                    $refund['refund_fee'] = 0.01;
                        $refund['refund_desc'] = '拼团未成功全额退款';
                        $refund['notify_url'] = config('system.callback.joinPayPtRefundCallBackUrl');
//                    $refund['notify_url'] = config('system.callback.ptRefundCallBackUrl');
                        $refundRes = $PayService->refund($refund);
                    }

//                    //恢复库存
//                    GoodsSku::where(['sku_sn' => $value['sku_sn'], 'status' => 1])->inc('stock', 1)->update();
//                    PtGoodsSku::where(['sku_sn' => $value['sku_sn'], 'activity_code' => $value['activity_code'], 'status' => 1])->inc('stock', 1)->update();
                }
                return $orderRes;
            });

            $noPayRes = Db::transaction(function () use ($overTimePtOrder) {
                $orderRes = [];
                $codeBuilder = (new CodeBuilder());
                $PayService = (new JoinPay());
                foreach ($overTimePtOrder as $key => $value) {
                    if ($value['pay_status'] == 1) {
                        $orderSn = $value['order_sn'];
                        //修改拼团订单为失败
                        $ptSave['activity_status'] = 3;
                        $ptSave['close_time'] = time();
                        $ptRes = PtOrder::update($ptSave, ['activity_sn' => $value['activity_sn'], 'activity_status' => 1, 'order_sn' => $orderSn]);

                        //修改订单为拼团失败自动取消
                        $orderSave['order_status'] = -4;
                        $orderSave['close_time'] = time();
                        $orderRes = OrderModel::update($orderSave, ['order_sn' => $orderSn]);

                        //修改订单商品为状态为支付失败
                        $orderGoodsSave['pay_status'] = 3;
                        $orderGoodsSave['status'] = -3;
                        $orderGoodsRes = OrderGoods::update($orderGoodsSave, ['order_sn' => $orderSn, 'goods_sn' => $value['goods_sn'], 'sku_sn' => $value['sku_sn']]);

                        //没付款的直接进入恢复库存流程
                        (new Pay())->completePtRefund(['out_trade_no' => $orderSn]);
                    }
                }
                return $orderRes;
            });
        }
        return $res;
    }
}