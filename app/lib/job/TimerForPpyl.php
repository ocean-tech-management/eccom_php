<?php
// +----------------------------------------------------------------------
// |[ 文档说明: 拼拼有礼模块定时任务]
// +----------------------------------------------------------------------
// | Copyright (c) 2018~2024 http://www.mlhcmk.com All rights reserved.
// +----------------------------------------------------------------------
 

namespace app\lib\job;


use app\lib\BaseException;
use app\lib\models\Order as OrderModel;
use app\lib\models\OrderGoods;
use app\lib\models\PpylOrder;
use app\lib\models\PpylOrderGoods;
use app\lib\models\PpylWaitOrder;
use app\lib\services\CodeBuilder;
use app\lib\services\JoinPay;
use app\lib\services\Ppyl;
use app\lib\subscribe\Timer;
use think\facade\Cache;
use think\facade\Db;
use think\facade\Log;
use think\queue\Job;
use app\lib\models\PpylAuto;

class TimerForPpyl
{
    //超时支付时间
    private $notPayExpire = 60;

    /**
     * @title  fire
     * @param Job $job
     * @param  $data
     * @return void
     * @throws \Exception
     */
    public function fire(Job $job, $data)
//    public function fire($data)
    {
        //type 1为排队订单超时(redis) 2为重开自动拼团 3为平台回购退款 4为未支付订单超时 5为排队订单超时(数据库)
        if (!empty($data)) {
            switch ($data['type'] ?? 1) {
                case 1:
                    Log::close('file');
                    //数据库查询
//                    $map[] = ['wait_status', '=', 1];
//                    $map[] = ['status', '=', 1];
//                    $map[] = ['end_time', '<=', time()];
//                    $overTimePtOrder = PpylWaitOrder::where($map)->column('activity_sn');
                    //redis
                    $redis = Cache::store('redis')->handler();
                    $overTimeList = $redis->keys('waitOrderTimeoutLists*');
                    if(!empty($overTimeList)){
                        foreach ($overTimeList as $key => $value) {
                            $explode = explode('-',$value);
                            if(empty($explode[1]) || (!empty($explode[1] && $explode[1] >= time()))){
                                unset($overTimeList[$key]);
                            }
                        }
                    }

                    if (!empty($overTimeList)) {
                        foreach ($overTimeList as $k => $v) {
                            $overTimePtOrder = $redis->lrange($v, 0, -1);
                            if (!empty($overTimePtOrder)) {
                                //处理中加锁的订单不处理
                                foreach ($overTimePtOrder as $key => $value) {
                                    if (!empty(cache('dealWaitOrder-' . $value))) {
                                        unset($overTimePtOrder[$key]);
                                    }
                                }
                                $overTimePtOrder = array_unique($overTimePtOrder);
                                $log['msg'] = '查找到拼拼有礼排队超时订单,推入处理拼拼有礼排队超时订单队列';
                                $log['data'] = $overTimePtOrder;
                                $log['time'] = date('Y-m-d H:i:s');
                                $res = $this->overTimeWaitOrder($overTimePtOrder ?? []);
                                $log['map'] = $map ?? [];
                                $log['delRes'] = $res;
                                (new Timer())->log($log);
                            }
                        }
                    }
                    break;
                case 2:
                    //尝试捕获异常
                    $errorMsg = null;
                    $errorCode = null;
                    try {
                        $res = (new Ppyl())->restartAutoPpylOrder($data);
                    } catch (BaseException $e) {
                        $errorMsg = $e->msg;
                        $errorCode = $e->errorCode;
                    }

                    //只要出现了某种错误,则记录到该计划里面
                    if (!empty($errorMsg)) {
                        $planSn = $data['plan_sn'];
                        $errMsg = ' 于' . date('Y-m-d H:i:s') . '执行失败,失败原因为' . $errorMsg . ' ';
                        $updateTime = time();
                        if (!empty($errorCode) && in_array($errorCode, ['2700121','2700125','2700124'])) {
                            Db::query("update sp_ppyl_auto set fail_msg = IFNULL(concat('$errMsg',fail_msg),'$errMsg'),status = 1,update_time = '$updateTime' where plan_sn = '$planSn';");
                        } else {
                            Db::query("update sp_ppyl_auto set fail_msg = IFNULL(concat('$errMsg',fail_msg),'$errMsg') where plan_sn = '$planSn';");
                        }

                    }

                    break;

                case 3:
//                    $res = (new Ppyl())->completePpylRepurchaseRefund($data);
                    //退款需要先提交申请
                    (new Ppyl())->submitPpylRefund(['out_trade_no' => $data['out_trade_no'], 'out_refund_no' => $data['out_refund_no'], 'refund_remark' => ($data['refund_remark'] ?? null), 'type' => 2, 'notThrowError' => 1]);
                    break;

                case 4:
                    Log::close('file');
                    //数据库查询
                    $map[] = ['wait_status', '=', 1];
                    $map[] = ['status', '=', 1];
                    $map[] = ['pay_status', '=', 1];
                    $map[] = ['create_time', '<=', time() - $this->notPayExpire];
                    $overTimePtOrder = PpylWaitOrder::where($map)->column('order_sn');
                    if (!empty($overTimePtOrder)) {
                        foreach ($overTimePtOrder as $key => $value) {
                            //处理中加锁的订单不处理
                            if (!empty(cache('dealWaitOrder-' . $value))) {
                                unset($overTimePtOrder[$key]);
                            }
                        }
                        $overTimePtOrder = array_unique($overTimePtOrder);
                        $log['msg'] = '查找到拼拼有礼排队未支付订单,推入处理拼拼有礼排队未支付订单队列';
                        $log['data'] = $overTimePtOrder;
                        $log['time'] = date('Y-m-d H:i:s');
                        $res = $this->overTimeWaitOrder($overTimePtOrder ?? [],1);
                        $log['map'] = $map ?? [];
                        $log['delRes'] = $res;
                        (new Timer())->log($log);
                    }
                    break;
                case 5:
                    //排队订单超时(数据库),此项是为了防止redis错漏补查询
                    Log::close('file');
                    //数据库查询
                    $map[] = ['wait_status', '=', 1];
                    $map[] = ['status', '=', 1];
                    $map[] = ['timeout_time', '<=', time()];
                    $overTimePtOrder = PpylWaitOrder::where($map)->column('order_sn');
                    if (!empty($overTimePtOrder)) {
                        foreach ($overTimePtOrder as $key => $value) {
                            //处理中加锁的订单不处理
                            if (!empty(cache('dealWaitOrder-' . $value))) {
                                unset($overTimePtOrder[$key]);
                            }
                        }
                        $overTimePtOrder = array_unique($overTimePtOrder);
                        $log['msg'] = '查找到拼拼有礼排队超时订单,推入处理拼拼有礼排队超时订单(数据库防漏)队列';
                        $log['data'] = $overTimePtOrder;
                        $log['time'] = date('Y-m-d H:i:s');
                        $res = $this->overTimeWaitOrder($overTimePtOrder ?? [],1);
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
     * @title  超时排队或未支付订单退款-定时任务
     * @param array $orderSn 超时排队订单编号
     * @param int $type 超时类型 1为自动找超时 2为指定特殊订单超时
     * @return bool|mixed
     * @throws \Exception
     */
    public function overTimeWaitOrder(array $orderSn, int $type = 1)
    {
        if (empty($orderSn)) {
            return false;
        }
        $firstMap[] = ['wait_status', '=', 1];
        $firstMap[] = ['status', '=', 1];
        $firstMap[] = ['order_sn', 'in', $orderSn];

        if($type == 1){
            $map[] = ['c_vip_level', '=', 0];
            $map[] = ['', 'exp', Db::raw('timeout_time is not null')];
            $map[] = ['timeout_time', '<=', time()];

            $mapOr[] = ['pay_status', '=', 1];
            $mapOr[] = ['create_time', '<=', time() - $this->notPayExpire];

            $overTimePtOrder = (new PpylWaitOrder())->where($firstMap)->where(function ($query) use ($map, $mapOr) {
                $query->whereOr([$map, $mapOr]);
            })->field('activity_code,area_code,order_sn,goods_sn,sku_sn,user_role,activity_type,activity_status,pay_status,wait_status,timeout_time')->select()->toArray();
        }else{
            $overTimePtOrder = (new PpylWaitOrder())->where($firstMap)->field('activity_code,area_code,order_sn,goods_sn,sku_sn,user_role,activity_type,activity_status,pay_status,wait_status,timeout_time')->select()->toArray();
        }

        $returnData['overTimeWaitOrder'] = $overTimePtOrder ?? [];
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
                        $ptSave['wait_status'] = 3;
                        $ptSave['close_time'] = time();
                        $ptRes = PpylWaitOrder::where(['wait_status' => 1, 'order_sn' => $orderSn])->update($ptSave);


                        //修改订单商品为状态为超时失败
                        $orderGoodsSave['pay_status'] = 3;
                        $orderGoodsSave['status'] = -3;
                        $orderGoodsRes = PpylOrderGoods::update($orderGoodsSave, ['order_sn' => $orderSn, 'goods_sn' => $value['goods_sn'], 'sku_sn' => $value['sku_sn']]);

                        //只有付款的了才进入退款流程,没有付款的走一下事务区块
                        //退款
                        $refundSn = $codeBuilder->buildRefundSn();
                        $refund['out_trade_no'] = $orderSn;
                        $refund['out_refund_no'] = $refundSn;
                        $refundRes = (new Ppyl())->submitPpylWaitRefund($refund);

                        if (!empty($value['timeout_time'])) {
                            //删除超时任务
                            $redis = Cache::store('redis')->handler();
                            $redis->lrem("waitOrderTimeoutLists-" . strtotime($value['timeout_time']), $value['order_sn']);
                        }

                    }
                }
                return $ptRes ?? [];
            });

            $noPayRes = Db::transaction(function () use ($overTimePtOrder) {
                $orderRes = [];
                $codeBuilder = (new CodeBuilder());
                $PayService = (new JoinPay());
                foreach ($overTimePtOrder as $key => $value) {
                    if ($value['pay_status'] == 1) {
                        $orderSn = $value['order_sn'];
                        //修改排队订单为失败
                        $ptSave['activity_status'] = 3;
                        $ptSave['wait_status'] = 3;
                        $ptSave['close_time'] = time();
                        $ptRes = PpylWaitOrder::update($ptSave, ['activity_status' => 1, 'order_sn' => $orderSn]);

                        //修改订单商品为状态为支付失败
                        $orderGoodsSave['pay_status'] = 3;
                        $orderGoodsSave['status'] = -3;
                        $orderGoodsRes = PpylOrderGoods::update($orderGoodsSave, ['order_sn' => $orderSn, 'goods_sn' => $value['goods_sn'], 'sku_sn' => $value['sku_sn']]);

                        //没付款的直接进入恢复库存流程
                        (new Ppyl())->completePpylWaitRefund(['out_trade_no' => $orderSn]);

                        if (!empty($value['timeout_time'])) {
                            //删除超时任务
                            $redis = Cache::store('redis')->handler();
                            $redis->lrem("waitOrderTimeoutLists-" . strtotime($value['timeout_time']), $value['order_sn']);
                        }
                    }
                }
                return $ptRes ?? [];
            });
        }
        $returnData['res'] = $res;
        return $returnData;
    }


}