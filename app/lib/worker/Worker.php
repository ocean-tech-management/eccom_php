<?php
// +----------------------------------------------------------------------
// |[ 文档说明: ]
// +----------------------------------------------------------------------
// | Copyright (c) 2018~2024 http://www.mlhcmk.com All rights reserved.
// +----------------------------------------------------------------------


namespace app\lib\worker;


use app\lib\models\GoodsSku;
use app\lib\models\IntegralDetail;
use app\lib\models\Order as OrderModel;
use app\lib\models\OrderCoupon;
use app\lib\models\OrderGoods;
use app\lib\models\RewardFreed;
use app\lib\models\UserCoupon;
use app\lib\services\Log;
use think\facade\Db;
use think\worker\Server;
use Workerman\Lib\Timer;

class Worker extends Server
{
    protected $socket = 'http://0.0.0.0:2346';

    public function __construct()
    {
        parent::__construct();
    }

    public function onWorkerStart()
    {
        //30s 执行一次团队奖励释放定时任务
        Timer::add(30, [$this, 'rewardFreedOperate']);
        //30s 执行一次超时未支付订单恢复库存定时任务
        Timer::add(30, [$this, 'overTimeOrder']);
    }

    /**
     * @title  团队奖励释放-定时任务
     * @return mixed
     * @throws \Exception
     */
    public function rewardFreedOperate()
    {
        $map[] = ['freed_start', '<=', time()];
        $map[] = ['status', 'not in', [2, 5, -1, -2]];
        $map[] = ['fronzen_integral', '<>', 0];
        $rewardList = RewardFreed::where($map)->order('create_time desc')->select()->toArray();
        if (!empty($rewardList)) {
            $integral = new IntegralDetail();
            $rewardInt = [];
            $saveReward = [];
            $aAes = [];
            //查看今日已经释放的订单,防止重复释放
            $allOrderSn = array_column($rewardList, 'order_sn');
            $todayFreedDetail = $integral->where(['order_sn' => $allOrderSn, 'type' => 1, 'change_type' => 4, 'status' => 1])->whereDay('create_time', 'today')->column('order_sn');
            foreach ($rewardList as $key => $value) {
                $aAes[$value['freed_sn']] = [];
                //判断是否还在释放周期内以及余额是否充足
                if ($value['freed_start'] + ($value['freed_cycle'] * 3600 * 24) < time()) {
                    $aAes[$value['freed_sn']]['res_msg'] = '团队奖励超过释放周期';
                    $aAes[$value['freed_sn']]['rawData'] = $value;
                    continue;
                }
                if ($value['freed_integral'] >= $value['reward_integral'] || $value['fronzen_integral'] > $value['reward_integral'] || ($value['freed_each'] > $value['fronzen_integral'])) {
                    $aAes[$value['freed_sn']]['res_msg'] = '团队奖励可释放余额不足';
                    $aAes[$value['freed_sn']]['rawData'] = $value;
                    continue;
                }
                if (!empty($todayFreedDetail) && in_array($value['order_sn'], $todayFreedDetail)) {
                    $aAes[$value['freed_sn']]['res_msg'] = '今日团队奖励已释放';
                    $aAes[$value['freed_sn']]['rawData'] = $value;
                    continue;
                }

                //添加用户积分明细
                $rewardInt['uid'] = $value['uid'];
                $rewardInt['integral'] = $value['freed_each'];
                $rewardInt['order_sn'] = $value['order_sn'];
                $rewardInt['type'] = 1;
                $rewardInt['change_type'] = 4;
                $integralRes = $integral->new($rewardInt);

                //修改本条释放记录
                $saveReward['fronzen_integral'] = $value['fronzen_integral'] - $value['freed_each'];
                $saveReward['freed_integral'] = $value['freed_integral'] + $value['freed_each'];
                $saveReward['status'] = 3;
                //第一个释放周期释放完毕
                if (doubleval($saveReward['fronzen_integral']) <= 0 && $value['is_first'] == 1 && $value['next_freed_support'] == 2) {
                    $saveReward['status'] = 4;
                }
                //已经全部释放完毕
                if ($saveReward['freed_integral'] == $value['reward_integral']) {
                    $saveReward['status'] = 5;
                }
                $rewardFreedRes = RewardFreed::update($saveReward, ['freed_sn' => $value['freed_sn']]);

                $aAes[$value['freed_sn']]['res_msg'] = '团队奖励释放完毕';
                $aAes[$value['freed_sn']]['data']['rawData'] = $value;
                $aAes[$value['freed_sn']]['data']['integral'] = $integralRes->getData();
                $aAes[$value['freed_sn']]['data']['rewardFreed'] = $rewardFreedRes->getData();
            }
            //记录日志
            if (!empty($aAes)) {
                (new Log())->setChannel('divide')->record($aAes, 'info');
            }

        }
        return true;
    }

    /**
     * @title  超时未支付订单恢复库存-定时任务
     * @return bool|mixed
     * @throws \Exception
     */
    public function overTimeOrder()
    {
        $map[] = ['pay_status', '=', 1];
        $map[] = ['order_status', '=', 1];
        $map[] = ['create_time', '<', time() - 7200];
        $overTimeOrder = (new OrderModel())->with(['goods'])->where($map)->field('order_sn,create_time,pay_status,order_status,pay_time')->select()->toArray();
        $res = false;
        if (!empty($overTimeOrder)) {
            $res = Db::transaction(function () use ($overTimeOrder) {
                $orderRes = [];
                foreach ($overTimeOrder as $key => $value) {
                    $orderSn = $value['order_sn'];
                    //修改订单为取消交易
                    $orderSave['order_status'] = -1;
                    $orderSave['pay_status'] = 3;
                    $orderSave['close_time'] = time();
                    $orderRes[] = OrderModel::update($orderSave, ['order_sn' => $orderSn, 'order_status' => 1, 'pay_status' => 1]);

                    //修改订单商品支付状态
                    $goodsSave['pay_status'] = 3;
                    $goodsRes[] = OrderGoods::update($goodsSave, ['order_sn' => $orderSn, 'pay_status' => 1]);

                    //恢复库存
                    $goodsSku = new GoodsSku();
                    foreach ($value['goods'] as $goodsKey => $goodsValue) {
                        $res[] = $goodsSku->where(['goods_sn' => $goodsValue['goods_sn'], 'sku_sn' => $goodsValue['sku_sn']])->inc('stock', intval($goodsValue['count']))->update();
                    }

                    //修改对应优惠券状态
                    $aOrderCoupons = OrderCoupon::where(['order_sn' => $orderSn, 'used_status' => 1])->field('coupon_uc_code,coupon_code')->select()->toArray();
                    $aOrderUcCoupon = array_column($aOrderCoupons, 'coupon_uc_code');
                    //修改订单优惠券状态为取消使用
                    $orderCouponRes = OrderCoupon::update(['used_status' => -1], ['order_sn' => $orderSn, 'used_status' => 1]);
                    //修改用户订单优惠券状态为未使用
                    $uCouponRes = UserCoupon::update(['valid_status' => 1], ['uc_code' => $aOrderUcCoupon]);
                }
                return $orderRes;
            });
        }
        return $res;
    }
}