<?php
// +----------------------------------------------------------------------
// |[ 文档说明: ]
// +----------------------------------------------------------------------
// | Copyright (c) 2018~2024 http://www.mlhcmk.com All rights reserved.
// +----------------------------------------------------------------------
 

namespace app\lib\services;


use app\lib\exceptions\PtException;
use app\lib\models\CouponUserType;
use app\lib\models\GoodsSku;
use app\lib\models\OrderGoods;
use app\lib\models\PtActivity;
use app\lib\models\Order;
use app\lib\models\PtGoodsSku;
use app\lib\models\PtOrder;
use app\lib\models\User;
use think\facade\Db;

class Pt
{
    /**
     * @title  新建拼团订单
     * @param array $data 拼团活动信息
     * @param array $orderInfo 订单信息
     * @return mixed
     * @throws \Exception
     */
    public function orderCreate(array $data, array $orderInfo)
    {
        $activityCode = $data['activity_code'];
        $joinType = $data['pt_join_type'] ?? 1;
        $orderGoods = OrderGoods::where(['order_sn' => $orderInfo['order_sn'], 'status' => 1])->field('sku_sn,goods_sn,order_sn,count')->select()->toArray();
        if (empty($joinType)) {
            throw new PtException(['msg' => '请选择拼团参加类型!']);
        }
        if ($joinType == 2) {
            $activitySn = $data['activity_sn'];
            $ptOrderParent = PtOrder::where(['activity_code' => $activityCode, 'activity_sn' => $activitySn, 'status' => 1, 'user_role' => 1, 'pay_status' => 2])->findOrEmpty()->toArray();
        } else {
            $ptOrderParent = [];
            $activitySn = (new CodeBuilder())->buildPtActivityCode();
        }
        //拼团活动信息
        $ptInfo = PtActivity::where(['activity_code' => $activityCode, 'status' => 1])->findOrEmpty()->toArray();

        $pt['activity_sn'] = $activitySn;
        $pt['activity_code'] = $activityCode;
        $pt['uid'] = $orderInfo['uid'];
        $pt['order_sn'] = $orderInfo['order_sn'];
        $pt['goods_sn'] = $data['goods_sn'];
        $pt['sku_sn'] = $data['sku_sn'];
        $pt['real_pay_price'] = $orderInfo['real_pay_price'];
        $pt['user_role'] = $joinType ?? 1;
        if ($joinType == 2) {
            //检查参团人数是否超过成团人数要求
            $teamUserId = PtOrder::where(['activity_code' => $activityCode, 'activity_sn' => $activitySn, 'status' => 1, 'pay_status' => [1, 2]])->column('id');
            if (empty($teamUserId) || empty($ptOrderParent)) {
                throw new PtException(['msg' => '该团已失效,无法参团']);
            }
            $teamUsers = PtOrder::where(['id' => $teamUserId])->lock(true)->select()->toArray();
            if (count($teamUsers) + 1 > $ptInfo['group_number']) {
                throw new PtException(['errorCode' => 2100110]);
            }
            $pt['start_time'] = strtotime($ptOrderParent['start_time']);
            $pt['end_time'] = strtotime($ptOrderParent['end_time']);
            $pt['group_number'] = $ptOrderParent['group_number'];
            $pt['join_user_type'] = $ptOrderParent['join_user_type'];
            $pt['activity_type'] = $ptOrderParent['activity_type'];
            $pt['activity_title'] = $ptOrderParent['activity_title'];
        } else {
            //开团需要判断当前可开团次数
            $ptSkuId = PtGoodsSku::where(['activity_code' => $activityCode, 'goods_sn' => $data['goods_sn'], 'sku_sn' => $data['sku_sn'], 'status' => 1])->column('id');
            if (empty($ptSkuId)) {
                throw new PtException(['msg' => '存在失效的拼团商品哦,无法继续下单']);
            }
            $ptSkuInfo = PtGoodsSku::where(['id' => $ptSkuId])->lock(true)->findOrEmpty()->toArray();
            if ($ptSkuInfo['start_number'] <= 0) {
                throw new PtException(['errorCode' => 2100112]);
            }
            $pt['activity_title'] = $ptInfo['activity_title'];
            $pt['start_time'] = time();
            $pt['end_time'] = time() + $ptInfo['expire_time'];
            $pt['group_number'] = $ptInfo['group_number'];
            $pt['join_user_type'] = $ptInfo['join_user_type'];
            $pt['activity_type'] = $ptInfo['type'];
        }

        $pt['activity_status'] = 1;
        $pt['pay_status'] = 1;
        $res = PtOrder::create($pt);

        //修改拼团商品库存
        if (!empty($orderGoods)) {
            $ptGoodsSkuModel = (new PtGoodsSku());
            //锁行
            $ptGoodsSkuId = $ptGoodsSkuModel->where(['activity_code' => $activityCode, 'goods_sn' => $pt['goods_sn'], 'sku_sn' => $pt['sku_sn'], 'status' => 1])->column('id');
            if (empty($ptGoodsSkuId)) {
                throw new PtException(['errorCode' => 2100111]);
            }
            $ptGoods = $ptGoodsSkuModel->where(['id' => $ptGoodsSkuId])->field('stock,start_number')->lock(true)->findOrEmpty()->toArray();
            foreach ($orderGoods as $key => $value) {
                $ptGoodsSkuModel->where(['activity_code' => $activityCode, 'goods_sn' => $value['goods_sn'], 'sku_sn' => $value['sku_sn']])->dec('stock', intval($value['count']))->update();
                //如果是开团需要减少开团次数
                if ($joinType == 1) {
                    $ptGoodsSkuModel->where(['activity_code' => $activityCode, 'goods_sn' => $value['goods_sn'], 'sku_sn' => $value['sku_sn']])->dec('start_number', 1)->update();
                }
            }

        }
        return $res->getData();
    }

    /**
     * @title  拼团订单开团前检验
     * @param array $data
     * @return bool
     * @throws \Exception
     */
    public function startPtActivityCheck(array $data)
    {
        $activityCode = $data['activity_code'];
        $uid = $data['uid'];
        $goodsSn = $data['goods_sn'];
        $sku_sn = $data['sku_sn'];
        $number = $data['number'];
        if (intval($number) != 1) {
            throw new PtException(['errorCode' => 2100108]);
        }
        $ptInfo = PtActivity::where(['activity_code' => $activityCode, 'status' => 1])->findOrEmpty()->toArray();
        if (empty($ptInfo)) {
            throw new PtException(['errorCode' => 2100101]);
        }
        $userInfo = User::with(['member'])->where(['uid' => $uid])->findOrEmpty()->toArray();
        if (empty($userInfo)) {
            throw new PtException(['msg' => 2100115]);
        }
        $userTypes = CouponUserType::where(['status' => 1])->select()->toArray();
        foreach ($userTypes as $key => $value) {
            $userType[$value['u_type']] = $value;
        }
        //判断活动类型及开团对象
        switch ($ptInfo['start_user_type']) {
            case 2:
                $userOrder = Order::where(['uid' => $uid])->count();
                if (!empty($userOrder)) {
                    throw new PtException(['msg' => '本次活动仅限新用户开团~您暂不符合要求']);
                }
                break;
            case 3:
                if (empty($userInfo['vip_level'])) {
                    throw new PtException(['msg' => '本次活动仅限会员用户开团~您暂不符合要求']);
                }
                break;
            case 4:
                break;
            case 5:
            case 6:
            case 7:
                if (empty($userInfo) || ($userInfo['vip_level'] != $userType[$ptInfo['start_user_type']]['vip_level'])) {
                    throw new PtException(['msg' => '本次活动仅限' . $userType[$ptInfo['start_user_type']]['u_name'] . '开团~您暂不符合要求']);
                }
                break;
            case 8:
                $userOrder = Order::where(['uid' => $uid, 'pay_status' => 2])->count();
                if (empty($userOrder)) {
                    throw new PtException(['msg' => '本次活动仅限老用户开团~您暂不符合要求']);
                }
                break;
            case 9:
                if (!empty($userInfo['vip_level'])) {
                    throw new PtException(['msg' => '本次活动仅限普通用户开团~您暂不符合要求']);
                }

        }
        //判断是否在开团时间,两个时间分开判断的原因是为了两个不同的提示
        if (strtotime($ptInfo['start_time']) > time()) {
            throw new PtException(['errorCode' => 2100102]);
        }
        //判断是否已经结束
        if (strtotime($ptInfo['end_time']) <= time()) {
            throw new PtException(['errorCode' => 2100103]);
        }
        $prOrder = (new PtOrder());
        //判断是否该拼团活动还存在拼团中的订单,如果存在则不允许重新开团
        $oldPtOrder = $prOrder->where(['uid' => $uid, 'activity_code' => $activityCode, 'goods_sn' => $goodsSn, 'sku_sn' => $sku_sn, 'activity_status' => 1])->findOrEmpty()->toArray();
        if (!empty($oldPtOrder)) {
            throw new PtException(['errorCode' => 2100104]);
        }
        //判断该用户参加拼团活动的次数
        $joinPtNumber = $prOrder->where(['uid' => $uid, 'activity_code' => $activityCode, 'activity_status' => [1, 2], 'goods_sn' => $goodsSn])->count();
        if (intval($joinPtNumber) >= $ptInfo['join_number']) {
            throw new PtException(['errorCode' => 2100105]);
        }

        //判断活动商品活动库存
        $checkStock = Db::transaction(function () use ($activityCode, $goodsSn, $sku_sn, $ptInfo) {
            $ptGoodsSkuId = PtGoodsSku::where(['activity_code' => $activityCode, 'goods_sn' => $goodsSn, 'sku_sn' => $sku_sn, 'status' => 1])->column('id');
            $goodsSkuId = GoodsSku::where(['goods_sn' => $goodsSn, 'sku_sn' => $sku_sn])->column('id');
            if (empty($ptGoodsSkuId)) {
                throw new PtException(['errorCode' => 2100111]);
            }

            $ptGoods = PtGoodsSku::where(['id' => $ptGoodsSkuId])->field('stock,start_number')->lock(true)->findOrEmpty()->toArray();
            $goodsSku = GoodsSku::where(['id' => $goodsSkuId])->field('stock')->lock(true)->findOrEmpty()->toArray();
            //判断开团次数
            if (intval($ptGoods['start_number']) <= 0) {
                throw new PtException(['errorCode' => 2100112]);
            }
            //判断库存,如果总库存大于拼团库存,则认拼团库存,如果总库存比较小,则认总库存
            if ($goodsSku['stock'] >= $ptGoods['stock']) {
                if (intval($ptGoods['stock']) < intval($ptInfo['group_number'])) {
                    throw new PtException(['errorCode' => 2100107]);
                }
            } else {
                if (intval($goodsSku['stock']) < intval($ptInfo['group_number'])) {
                    throw new PtException(['errorCode' => 2100114]);
                }
            }

            return true;
        });


        return true;
    }

    /**
     * @title  拼团订单参团前检验
     * @param array $data
     * @return bool
     * @throws \Exception
     */
    public function joinPtActivityCheck(array $data)
    {
        $activityCode = $data['activity_code'];
        $activitySn = $data['activity_sn'];
        $uid = $data['uid'];
        $goodsSn = $data['goods_sn'];
        $sku_sn = $data['sku_sn'];
        $number = $data['number'];
        if (intval($number) != 1) {
            throw new PtException(['errorCode' => 2100108]);
        }
        $ptInfo = PtActivity::where(['activity_code' => $activityCode, 'status' => 1])->findOrEmpty()->toArray();
        $ptOrderNumber = PtOrder::where(['activity_code' => $activityCode, 'activity_sn' => $activitySn, 'activity_status' => 1, 'status' => 1])->count();
        //拼团活动详情,找团长
        $ptGroupInfo = PtOrder::where(['activity_code' => $activityCode, 'activity_sn' => $activitySn, 'activity_status' => 1, 'status' => 1, 'user_role' => 1])->findOrEmpty()->toArray();

        //判断拼团活动是否存在
        if (empty($ptInfo)) {
            throw new PtException(['errorCode' => 2100101]);
        }
        //判断拼团订单是否存在
        if (empty($ptOrderNumber)) {
            throw new PtException(['errorCode' => 2100109]);
        }
        //判断拼团团长订单是否存在
        if (empty($ptGroupInfo)) {
            throw new PtException(['errorCode' => 2100109]);
        }
        //判断已参团人数是否超过全团规定人数
        if (intval($ptOrderNumber) >= $ptInfo['group_number']) {
            throw new PtException(['errorCode' => 2100110]);
        }

        //判断参团的团是否在有效期时间,两个时间分开判断的原因是为了两个不同的提示
        if (strtotime($ptGroupInfo['start_time']) > time()) {
            throw new PtException(['errorCode' => 2100102]);
        }
        //判断参团的团是否已经结束
        if (strtotime($ptGroupInfo['end_time']) <= time()) {
            throw new PtException(['errorCode' => 2100103]);
        }

        $userInfo = User::with(['member'])->where(['uid' => $uid])->findOrEmpty()->toArray();
        $userTypes = CouponUserType::where(['status' => 1])->select()->toArray();
        foreach ($userTypes as $key => $value) {
            $userType[$value['u_type']] = $value;
        }
        //判断活动类型及开团对象
        switch ($ptInfo['join_user_type']) {
            case 2:
                $userOrder = Order::where(['uid' => $uid, 'pay_status' => 2])->count();
                if (!empty($userOrder)) {
                    throw new PtException(['msg' => '本次活动仅限新用户参团~您暂不符合要求']);
                }
                break;
            case 3:
                if (empty($userInfo['vip_level'])) {
                    throw new PtException(['msg' => '本次活动仅限会员用户参团~您暂不符合要求']);
                }
                break;
            case 4:
                break;
            case 5:
            case 6:
            case 7:
                if (empty($userInfo) || ($userInfo['vip_level'] != $userType[$ptInfo['start_user_type']]['vip_level'])) {
                    throw new PtException(['msg' => '本次活动仅限' . $userType[$ptInfo['start_user_type']]['u_name'] . '参团~您暂不符合要求']);
                }
                break;
            case 8:
                $userOrder = Order::where(['uid' => $uid, 'pay_status' => 2])->count();
                if (empty($userOrder)) {
                    throw new PtException(['msg' => '本次活动仅限老用户参团~您暂不符合要求']);
                }
                break;
            case 9:
                if (!empty($userInfo['vip_level'])) {
                    throw new PtException(['msg' => '本次活动仅限普通用户参团~您暂不符合要求']);
                }

        }
        //判断是否在开团时间,两个时间分开判断的原因是为了两个不同的提示
        if (strtotime($ptInfo['start_time']) > time()) {
            throw new PtException(['errorCode' => 2100102]);
        }
        //判断是否已经结束
        if (strtotime($ptInfo['end_time']) <= time()) {
            throw new PtException(['errorCode' => 2100103]);
        }
        $prOrder = (new PtOrder());
        //判断是否该拼团活动还存在拼团中的订单,如果存在则不允许重新开团
        $oldPtOrder = $prOrder->where(['uid' => $uid, 'activity_code' => $activityCode, 'goods_sn' => $goodsSn, 'sku_sn' => $sku_sn, 'activity_status' => 1])->findOrEmpty()->toArray();
        if (!empty($oldPtOrder)) {
            throw new PtException(['errorCode' => 2100104]);
        }
        //判断该用户参加拼团活动的次数
        $joinPtNumber = $prOrder->where(['uid' => $uid, 'activity_code' => $activityCode, 'activity_status' => [1, 2], 'goods_sn' => $goodsSn])->count();
        if (intval($joinPtNumber) >= $ptInfo['join_number']) {
            throw new PtException(['errorCode' => 2100105]);
        }

        //判断活动商品活动库存
        $checkStock = Db::transaction(function () use ($activityCode, $goodsSn, $sku_sn, $ptInfo, $ptOrderNumber) {
            $ptGoodsSkuId = PtGoodsSku::where(['activity_code' => $activityCode, 'goods_sn' => $goodsSn, 'sku_sn' => $sku_sn, 'status' => 1])->column('id');
            $goodsSkuId = GoodsSku::where(['goods_sn' => $goodsSn, 'sku_sn' => $sku_sn])->column('id');
            if (empty($ptGoodsSkuId)) {
                throw new PtException(['errorCode' => 2100111]);
            }
            $ptGoods = PtGoodsSku::where(['id' => $ptGoodsSkuId])->field('stock')->lock(true)->findOrEmpty()->toArray();
            $GoodsSku = GoodsSku::where(['id' => $goodsSkuId])->field('stock')->lock(true)->findOrEmpty()->toArray();

            if (intval($ptGoods['stock']) <= 0 || intval($GoodsSku['stock']) <= 0) {
                throw new PtException(['errorCode' => 2100107]);
            }
            return true;
        });

        return true;
    }
}