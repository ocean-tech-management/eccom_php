<?php
// +----------------------------------------------------------------------
// |[ 文档说明: 拼拼有礼订单模块定时任务]
// +----------------------------------------------------------------------
// | Copyright (c) 2018~2024 http://www.mlhcmk.com All rights reserved.
// +----------------------------------------------------------------------
 

namespace app\lib\job;


use app\lib\exceptions\OrderException;
use app\lib\models\GoodsSku;
use app\lib\models\Order as OrderModel;
use app\lib\models\OrderGoods;
use app\lib\models\PpylAuto;
use app\lib\models\PpylGoodsSku;
use app\lib\models\PpylOrder;
use app\lib\models\PpylOrderGoods;
use app\lib\models\PpylWaitOrder;
use app\lib\services\CodeBuilder;
use app\lib\services\JoinPay;
use app\lib\services\Ppyl;
use app\lib\subscribe\Timer;
use think\facade\Db;
use think\facade\Log;
use think\facade\Queue;
use think\queue\Job;

class TimerForPpylOrder
{
    public $timeOutSecond = 15;            //订单未支付超时时间

    /**
     * @title  fire
     * @param Job $job
     * @param  $data
     * @return void
     * @throws \Exception
     */
    public function fire(Job $job, $data)
    {
        //type 1为超时未成团 2为未中奖退款 3为超时未支付
        if (!empty($data)) {
            switch ($data['type'] ?? 1) {
                case 1:
                    Log::close('file');
                    $map[] = ['activity_status', '=', 1];
                    $map[] = ['status', '=', 1];
                    $map[] = ['end_time', '<=', time()];
                    $overTimeWaitOrder = PpylOrder::where($map)->column('activity_sn');
                    if (!empty($overTimeWaitOrder)) {
                        $overTimePtOrder = array_unique($overTimeWaitOrder);
                        $log['msg'] = '查找到美好拼拼超时订单,推入处理拼拼有礼超时订单队列';
                        $log['data'] = $overTimePtOrder;
                        $log['time'] = date('Y-m-d H:i:s');
                        $res = $this->overTimePtOrder($overTimePtOrder ?? []);
                        $log['map'] = $map ?? [];
                        $log['delRes'] = $res;
                        (new Timer())->log($log);
                    }
                    break;
                case 2:
//                    (new Ppyl())->completePpylRefund(['out_trade_no' => $data['order_sn'], 'out_refund_no' => $data['out_refund_no']]);
                      //退款需要先提交申请
                    (new Ppyl())->submitPpylRefund(['out_trade_no' => $data['order_sn'], 'out_refund_no' => $data['out_refund_no'], 'refund_remark' => ($data['refund_remark'] ?? null)]);
                    break;
                case 3:
                    Log::close('file');
                    $map[] = ['activity_status', 'in', [1,3]];
                    $map[] = ['status', '=', 1];
                    $map[] = ['pay_status', '=', 1];
                    $map[] = ['create_time', '<=', (time()  - $this->timeOutSecond)];
                    $overTimeNoPayOrder = PpylOrder::where($map)->column('order_sn');
                    if (!empty($overTimeNoPayOrder)) {
                        $overTimeNoPayPpylOrder = array_unique($overTimeNoPayOrder);
                        $log['msg'] = '查找到拼拼有礼超时未支付订单,推入处理拼拼有礼超时未支付订单队列';
                        $log['data'] = $overTimeNoPayPpylOrder;
                        $log['time'] = date('Y-m-d H:i:s');
                        $res = $this->overTimeNoPayPpylOrder($overTimeNoPayPpylOrder ?? []);
                        $log['map'] = $map ?? [];
                        $log['delRes'] = $res;
                        (new Timer())->log($log);
                    }
                    break;
            }

        }
        $job->delete();
    }

    /**
     * @title  超时未成团订单恢复库存-定时任务
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
        $overTimePtOrder = (new PpylOrder())->where($map)->field('activity_sn,activity_code,uid,area_code,order_sn,goods_sn,sku_sn,user_role,activity_type,activity_status,pay_status,pay_no')->order('user_role asc')->select()->toArray();

        $res = false;
        if (!empty($overTimePtOrder)) {
            $res = Db::transaction(function () use ($overTimePtOrder) {
                $orderRes = [];
                $codeBuilder = (new CodeBuilder());
                $PayService = (new JoinPay());
                $second = 8;
                foreach ($overTimePtOrder as $key => $value) {
                    $cMap = [];
                    if ($value['pay_status'] == 2) {
                        $orderSn = $value['order_sn'];
                        //修改拼团订单为失败
                        $ptSave['activity_status'] = 3;
                        $ptSave['close_time'] = time();
                        $ptRes = PpylOrder::where(['activity_sn' => $value['activity_sn'], 'activity_status' => 1, 'order_sn' => $orderSn])->update($ptSave);


                        //修改订单商品为状态为超时失败
                        $orderGoodsSave['pay_status'] = 3;
                        $orderGoodsSave['status'] = -3;
                        $orderGoodsRes = PpylOrderGoods::update($orderGoodsSave, ['order_sn' => $orderSn, 'goods_sn' => $value['goods_sn'], 'sku_sn' => $value['sku_sn']]);

                        //只有付款的了才进入退款流程,没有付款的走一下事务区块
                        //退款,暂时取消立马退款操作
//                        $refundSn = $codeBuilder->buildRefundSn();
//                        $refund['out_trade_no'] = $orderSn;
//                        $refund['out_refund_no'] = $refundSn;
//                        $refundRes = (new Ppyl())->completePpylRefund($refund);

                        //检查是否有自动拼团的订单,如果有则尝试重新开团
                        $cMap[] = ['activity_code', '=', $value['activity_code']];
                        $cMap[] = ['area_code', '=', $value['area_code']];
                        $cMap[] = ['goods_sn', '=', $value['goods_sn']];
                        $cMap[] = ['uid', '=', $value['uid']];
                        $cMap[] = ['end_time', '>=', time()];
                        $cMap[] = ['status', '=', 1];

                        $existAuto = PpylAuto::where($cMap)->value('plan_sn');

                        if (!empty($existAuto)) {
                            if ($value['user_role'] == 1) {
                                Queue::push('app\lib\job\TimerForPpyl', ['plan_sn' => $existAuto, 'restartType' => 1, 'type' => 2, 'pay_no' => ($value['pay_no'] ?? null), 'pay_order_sn' => $value['order_sn'], 'channel' => 1], config('system.queueAbbr') . 'TimeOutPpyl');
                            } else {
                                Queue::later($second, 'app\lib\job\TimerForPpyl', ['plan_sn' => $existAuto, 'restartType' => 1, 'type' => 2, 'pay_no' => ($value['pay_no'] ?? null), 'pay_order_sn' => $value['order_sn'], 'channel' => 1], config('system.queueAbbr') . 'TimeOutPpyl');
                            }
                            $second += 5;
                        }

                    }
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
                        $ptSave['pay_status'] = -1;
                        $ptRes = PpylOrder::update($ptSave, ['activity_sn' => $value['activity_sn'], 'activity_status' => 1, 'order_sn' => $orderSn]);


                        //修改订单商品为状态为支付失败
                        $orderGoodsSave['pay_status'] = 3;
                        $orderGoodsSave['status'] = -3;
                        $orderGoodsRes = PpylOrderGoods::update($orderGoodsSave, ['order_sn' => $orderSn, 'goods_sn' => $value['goods_sn'], 'sku_sn' => $value['sku_sn']]);

                        //没付款的直接进入恢复库存流程
                        (new Ppyl())->completePpylRefund(['out_trade_no' => $orderSn]);
                    }
                }
                return $orderRes;
            });
        }
        return $res;
    }

    /**
     * @title  超时未支付订单恢复库存-定时任务
     * @param array $orderSn 超时拼团订单编号
     * @return bool|mixed
     * @throws \Exception
     */
    public function overTimeNoPayPpylOrder(array $orderSn)
    {
        if (empty($orderSn)) {
            return false;
        }
        $map[] = ['activity_status', 'in', [1,3]];
        $map[] = ['order_sn', 'in', $orderSn];
        $map[] = ['pay_status', 'in', [1]];
        $overTimePtOrder = (new PpylOrder())->where($map)->field('activity_sn,activity_code,area_code,order_sn,goods_sn,sku_sn,user_role,activity_type,activity_status,pay_status,pay_type')->select()->toArray();

        if (!empty($overTimePtOrder)) {
            $noPayRes = Db::transaction(function () use ($overTimePtOrder) {
                $orderRes = [];
                $codeBuilder = (new CodeBuilder());
                $PayService = (new JoinPay());
                foreach ($overTimePtOrder as $key => $value) {
                    //关闭订单,只做了汇聚支付的,微信支付可以直接通过统一下单传入失效时间,无需手动关单
                    if (!empty($value['pay_type'])) {
                        if (in_array($value['pay_type'], [2, 3])) {
                            if ($value['pay_type'] == 2) {
                                $FrpCode = 'WEIXIN_XCX';
                            }
                            if (!empty($FrpCode)) {
                                $closeOrder = (new JoinPay())->closeOrder(['order_sn' => $value['order_sn'], 'pay_type' => $FrpCode]);
                                $returnData['closeOrder'] = $closeOrder ?? [];
//                                if (empty($closeOrder) || (!empty($closeOrder) && $closeOrder['status'] != 100) || (!empty($closeOrder) && !empty($closeOrder['result']) && !empty($closeOrder['result']['rb_Code'] ?? null))) {
                                if (empty($closeOrder) || (!empty($closeOrder) && $closeOrder['status'] != 100)) {
                                    $returnData['msg'] = '汇聚关单失败,无法继续业务逻辑';
                                    return $returnData;
                                }
                            }
                        }
                    }

                    if ($value['pay_status'] == 1) {
                        $orderSn = $value['order_sn'];
                        //修改拼团订单为取消支付
                        $ptSave['activity_status'] = -1;
                        $ptSave['pay_status'] = -1;
                        $ptSave['status'] = -1;
                        $ptSave['close_time'] = time();
                        $returnData['orderRes'] = PpylOrder::update($ptSave, ['activity_sn' => $value['activity_sn'], 'activity_status' => [1,3], 'order_sn' => $orderSn]);


                        //修改订单商品为状态为支付失败
                        $orderGoodsSave['pay_status'] = 3;
                        $orderGoodsSave['status'] = -3;
                        $returnData['orderGoodsRes'] = PpylOrderGoods::update($orderGoodsSave, ['order_sn' => $orderSn, 'goods_sn' => $value['goods_sn'], 'sku_sn' => $value['sku_sn']]);

                        //只有团长的订单才恢复一次库存
                        if (!empty($value['user_role']) && $value['user_role'] == 1) {
                            //先锁行后自减库存
                            $goodsId = GoodsSku::where(['sku_sn' => $value['sku_sn']])->column('id');

                            $ptGoodsId = PpylGoodsSku::where(['activity_code' => $value['activity_code'], 'area_code' => $value['area_code'], 'goods_sn' => $value['goods_sn'], 'sku_sn' => $value['sku_sn']])->column('id');
                            if (!empty($goodsId)) {
                                $lockGoods = GoodsSku::where(['id' => $goodsId])->lock(true)->field('id,goods_sn,sku_sn')->findOrEmpty()->toArray();
                                //恢复库存
                                $skuRes = GoodsSku::where(['sku_sn' => $value['sku_sn']])->inc('stock', 1)->update();
                            }

                            if (!empty($ptGoodsId)) {
                                $lockPtGoods = PpylGoodsSku::where(['id' => $ptGoodsId])->lock(true)->field('id,activity_code,area_code,goods_sn,sku_sn')->findOrEmpty()->toArray();
                                //恢复拼团库存
                                $ptStockRes = PpylGoodsSku::where(['activity_code' => $value['activity_code'], 'goods_sn' => $value['goods_sn'], 'sku_sn' => $value['sku_sn']])->inc('stock', 1)->update();

                                //如果是开团需要恢复开团次数
                                if (!empty($value['user_role']) && $value['user_role'] == 1) {
                                    PpylGoodsSku::where(['activity_code' => $value['activity_code'], 'area_code' => $value['area_code'], 'goods_sn' => $value['goods_sn'], 'sku_sn' => $value['sku_sn']])->inc('start_number', 1)->update();
                                }
                            }
                        }
                    }
                }
                return $returnData ?? [];
            });
        }
        return $noPayRes ?? false;
    }
}