<?php
// +----------------------------------------------------------------------
// |[ 文档说明: 物流模块Service]
// +----------------------------------------------------------------------
// | Copyright (c) 2018~2024 http://www.mlhcmk.com All rights reserved.
// +----------------------------------------------------------------------
 

namespace app\lib\services;

use app\lib\exceptions\OrderException;
use app\lib\exceptions\ServiceException;
use app\lib\exceptions\ShipException;
use app\lib\models\AdvanceCardDetail;
use app\lib\models\GoodsSku;
use app\lib\models\GoodsSpu;
use app\lib\models\OperationLog;
use app\lib\models\Order;
use app\lib\models\OrderAttach;
use app\lib\models\OrderGoods;
use app\lib\models\ShipOrder;
use app\lib\models\ShippingDetail;
use app\lib\models\SystemConfig;
use app\lib\models\User;
use think\facade\Db;
use think\facade\Queue;

class Ship
{
    /**
     * @title  拆分订单操作
     * @param array $data
     * @return mixed
     * @throws \Exception
     */
    public function splitOrder(array $data)
    {
        $orderSn = trim($data['order_sn']);
        $skuSn = $data['sku_sn'];
        //split_type=1则为拆正常订单 =2则为拆子订单 =3为拆合并订单
        $splitType = $data['split_type'] ?? 1;
        $splitIsParent = false;
        $skuSnNumber = $data['sku_sn_number'] ?? []; //拆数量时需要传每个商品对应的数量
        $shipOrderModel = new ShipOrder();
        $notThrowError = $data['notThrowError'] ?? false;

        if ($splitType == 1) {
            $map[] = ['split_status', '=', 3];
        } elseif ($splitType == 3) {
            $map[] = ['split_status', '=', 2];
        }
        //仅允许待发货和没填物流编号的订单才可以操作
        $orderStatus = $shipOrderModel->where(['order_sn' => $orderSn])->field('order_status,shipping_code,shipping_status')->findOrEmpty()->toArray();

        if ($orderStatus['order_status'] != 2 || !empty($orderStatus['shipping_code']) || $orderStatus['shipping_status'] != 1) {
            if (empty($notThrowError)) {
                throw new ShipException(['errorCode' => 1900111]);
            } else {
                return false;
            }
        }
        if ($splitType == 3) {

            $orderInfo = $shipOrderModel->with(['parent'])->where($map)->where(function ($query) use ($orderSn) {
                $mapOr[] = ['parent_order_sn', '=', $orderSn];
                $mapAnd[] = ['order_sn', '=', $orderSn];
                $query->where($mapAnd)->whereOr([$mapOr]);
            })->withoutField('id')->order('order_sort desc,order_child_sort asc')->select()->toArray();
        } else {
            $map[] = ['order_sn', '=', $orderSn];
            $orderInfo = $shipOrderModel->with(['parent'])->where($map)->withoutField('id')->findOrEmpty()->toArray();
        }

        if ($splitType == 1) {
            $aSku = (new OrderGoods())->where(['order_sn' => $orderSn, 'status' => 1])->select()->toArray();
            $childShipOrder = (new ShipOrder())->where(['parent_order_sn' => $orderSn, 'status' => 1])->count();
        } elseif ($splitType == 3) {
            $orderSns = array_column($orderInfo, 'order_sn');
            $aSku = (new OrderGoods())->where(['order_sn' => $orderSns, 'status' => 1])->select()->toArray();
            $childShipOrder = (new ShipOrder())->where(['parent_order_sn' => $orderSns, 'status' => 1])->count();
        } else {
            if (empty($orderInfo['parent_order_sn'])) {
                $splitIsParent = true;
                $skuMap[] = ['order_sn', '=', $orderInfo['order_sn']];
            } else {
                $skuMap[] = ['order_sn', '=', $orderInfo['parent_order_sn']];
            }
            $skuMap[] = ['status', '=', 1];
            $skuMap[] = ['sku_sn', 'in', explode(',', $orderInfo['goods_sku'])];
            $aSku = (new OrderGoods())->where($skuMap)->select()->toArray();
            $childShipOrder = (new ShipOrder())->where(['parent_order_sn' => $orderInfo['parent_order_sn'], 'status' => 1])->count();
        }

        if (empty($orderInfo) || empty($aSku)) {
            if (empty($notThrowError)) {
                throw new ShipException(['errorCode' => 1900101]);
            } else {
                return false;
            }
        }

        $mergeChildOrder = [];
        if ($splitType == 3) {
            $skus = [];
            foreach ($aSku as $key => $value) {
                $skus[$value['order_sn']][] = $value;
            }

            foreach ($orderInfo as $key => $value) {
                if (empty($value['parent_order_sn'])) {
                    if (count(explode(',', $value['goods_sku'])) == 1 && $skuSn[0] == $value['goods_sku']) {
                        $oneSkuHaveMoreItem = true;
                    }
                    $parentOrderGoodsSku = $skus[$value['order_sn']];
                    $asOrder = $value;
                    //continue;
                } else {
                    $mergeChildOrder[$value['order_sn']] = $value;
                    $mergeChildOrder[$value['order_sn']]['goods'] = $skus[$value['order_sn']];
                }
                $aIntersect = array_intersect(explode(',', $asOrder['goods_sku']), $skuSn);
                if (!empty($aIntersect)) {
                    if (implode(',', $aIntersect) == $value['goods_sku']) {
                        $acOrder = $value;
                    }
                }

            }
            if (empty($acOrder)) {
                if (!empty($aIntersect)) {
                    if (implode(',', $aIntersect) == implode(',', array_column($parentOrderGoodsSku, 'sku_sn'))) {
                        $acOrder = $asOrder;
                    }
                }
            }

            if (empty($acOrder)) {
                if (empty($notThrowError)) {
                    throw new ShipException(['msg' => '选择的商品不合规,不可拆单']);
                } else {
                    return false;
                }
            }

            $needQuChu = [];
            $afterSplitBecomeNormal = false;
            $notFindParent = false;
            $pGoodsSku = explode(',', $asOrder['goods_sku']);
            if ($asOrder['order_sn'] != $acOrder['order_sn']) {
                $notFindParent = true;
                $cGoodsSku = [];
                if ($childShipOrder > 1) {
                    foreach ($mergeChildOrder as $key => $value) {
                        unset($mergeChildOrder[$acOrder['order_sn']]);
                        $cGoodsSku = array_unique(array_merge_recursive($cGoodsSku, explode(',', $value['goods_sku'])));
                    }
                    $allGoodsSku = array_unique(array_merge_recursive($pGoodsSku, $cGoodsSku));
                } else {
                    $allGoodsSku = $pGoodsSku;
                }
                $needQuChu = array_intersect($allGoodsSku, explode(',', $acOrder['goods_sku']));
                if ($childShipOrder == 1) {
                    $afterSplitBecomeNormal = true;
                }
                //父订单
                $asOrder['item_count'] -= $acOrder['item_count'];
                $asOrder['total_price'] -= $acOrder['total_price'];
                $asOrder['fare_price'] -= $acOrder['fare_price'];
                $asOrder['discount_price'] -= $acOrder['discount_price'];
                $asOrder['real_pay_price'] -= $acOrder['real_pay_price'];
            } else {
                //$acOrder = [];
                $afterSplitBecomeNormal = true;
                foreach ($mergeChildOrder as $key => $value) {
                    $asOrder['item_count'] -= $value['item_count'];
                    $asOrder['total_price'] -= $value['total_price'];
                    $asOrder['fare_price'] -= $value['fare_price'];
                    $asOrder['discount_price'] -= $value['discount_price'];
                    $asOrder['real_pay_price'] -= $value['real_pay_price'];
                    $needQuChu = array_unique(array_merge_recursive($needQuChu, explode(',', $value['goods_sku'])));
                }
            }
            if (!empty($notFindParent)) {
                $parentHaveSku = $allGoodsSku;
            } else {
                $parentHaveSku = array_column($parentOrderGoodsSku, 'sku_sn');
            }
            //判断哪些SKU本来就存在,如果存在就不需要剔除
            foreach ($needQuChu as $key => $value) {
                if (in_array($value, $parentHaveSku)) {
                    unset($needQuChu[$key]);
                }
            }
            //判断哪些SKU需要剔除
            if (!empty($needQuChu)) {
                foreach ($pGoodsSku as $key => $value) {
                    if (in_array($value, $needQuChu)) {
                        unset($pGoodsSku[$key]);
                    }
                }

                $asOrder['goods_sku'] = implode(',', $pGoodsSku);
            }

            if (!empty($afterSplitBecomeNormal)) {
                $asOrder['split_status'] = 3;
            } else {
                $asOrder['split_status'] = 2;
            }
            $asOrder['order_is_exist'] = 1;
//            if(empty($oneSkuHaveMoreItem)){
//                $asOrder['goods_sku'] = implode(',',$asSku);
//            }
            $asOrder['create_time'] = strtotime($asOrder['create_time']);
            unset($asOrder['update_time']);

            //新增或编辑操作
            $allOrder = Db::transaction(function () use ($asOrder, $acOrder, $shipOrderModel, $orderSn, $afterSplitBecomeNormal, $mergeChildOrder) {
                unset($asOrder['update_time']);
                $all[] = $asOrder;
                $res = ShipOrder::update($asOrder, ['order_sn' => $asOrder['order_sn']]);
                if ($asOrder['split_status'] == 3) {
                    Order::update(['split_status' => 3], ['order_sn' => $acOrder['order_sn']]);
                }
                if ($acOrder['order_sn'] != $asOrder['order_sn']) {
                    //修改子订单
                    $aChildSave['split_status'] = 3;
                    $aChildSave['parent_order_sn'] = null;
                    ShipOrder::update($aChildSave, ['order_sn' => $acOrder['order_sn']]);
                    Order::update($aChildSave, ['order_sn' => $acOrder['order_sn']]);
                    $acOrder['split_status'] = 3;
                    $acOrder['parent_order_sn'] = null;
                    $all[] = $acOrder;
                } else {

                    if (!empty($mergeChildOrder)) {
                        foreach ($mergeChildOrder as $key => $value) {
                            $childSave['split_status'] = 3;
                            $childSave['parent_order_sn'] = null;
                            ShipOrder::update($childSave, ['order_sn' => $value['order_sn']]);
                            Order::update($childSave, ['order_sn' => $value['order_sn']]);
                            $value['split_status'] = 3;
                            $value['parent_order_sn'] = null;
                            $all[] = $value;
                        }
                    }

                }

                return $all;
            });
            //为了方便直接查询已经拆分好的订单返回
            $mergeOrder = (new ShipOrder())->list(['searOrderSn' => array_column($allOrder, 'order_sn'), 'needNormalKey' => true]);
            return $mergeOrder['list'];
        }


        $oneSkuHaveMoreItem = false;
        //父订单
        $asOrder = $orderInfo;
        //子订单
        $acOrder = $orderInfo;
        if ($splitType == 1) {
            $patentOrderSn = $orderSn;
            $parentOrderSort = $orderInfo['order_sort'];
        } else {
            if (!empty($splitIsParent)) {
                $patentOrderSn = trim($orderSn);
                $parentOrderSort = $orderInfo['order_sort'];
            } else {
                $patentOrderSn = trim($orderInfo['parent_order_sn']);
                $parentOrderSort = $orderInfo['parent_order_sort'];
            }
        }
        $acOrder['order_sn'] = (new CodeBuilder())->buildOrderChileNo($patentOrderSn);
        $acOrder['parent_order_sn'] = $patentOrderSn;

        $acOrder['item_count'] = 0;
        $acOrder['total_price'] = 0;
        $acOrder['fare_price'] = 0;
        $acOrder['discount_price'] = 0;
        $acOrder['real_pay_price'] = 0;

        $allItemCount = array_column($aSku, 'count');

        if (count($aSku) <= 1) {
            $oneSkuHaveMoreItem = true;
            if ($allItemCount[0] <= 1) {
                throw new ShipException(['msg' => '仅剩最后一个商品不可继续拆单']);
            }
            if (empty($skuSnNumber)) {
                throw new ShipException(['msg' => '仅剩最后一个商品只能拆数量了哦,不可以拆单啦']);
            }
        } else {
            if (!empty($skuSnNumber)) {
                throw new ShipException(['msg' => '暂不允许存在多个SKU的情况下直接拆单个SKU的数量']);
            }
        }
        //<选中商品归给父订单--part 1--start>//
//        if(empty($oneSkuHaveMoreItem)){
//            $asOrder['item_count'] = 0;
//            $asOrder['total_price'] = 0;
//            $asOrder['fare_price'] = 0;
//            $asOrder['discount_price'] = 0;
//            $asOrder['real_pay_price'] = 0;
//        }
        //<选中商品归给父订单--part 1--end>//
        //如果不止一个产品则可以继续拆订单,如果只有一个产品但是数量不止一个,则可以拆数量,单总价格不变
        foreach ($aSku as $key => $value) {
            if (empty($oneSkuHaveMoreItem)) {
                //<选中商品归给父订单--part 2--start>//
//                if(in_array($value['sku_sn'],$skuSn)){
//                    //父订单
//                    $asOrder['item_count'] += 1;
//                    $asOrder['total_price'] += $value['total_price'];
//                    $asOrder['fare_price'] += $value['total_fare_price'];
//                    $asOrder['discount_price'] += $value['all_dis'];
//                    $asOrder['real_pay_price'] += $value['real_pay_price'];
//                    //添加父类SKU
//                    $asSku[] = $value['sku_sn'];
//                    $asOrder['goods'][$value['sku_sn']] = $value;
//                }else{
//                    //子订单
//                    $acOrder['item_count'] += 1;
//                    $acOrder['total_price'] += $value['total_price'];
//                    $acOrder['fare_price'] += $value['total_fare_price'];
//                    $acOrder['discount_price'] += $value['all_dis'];
//                    $acOrder['real_pay_price'] += $value['real_pay_price'];
//
//                    //添加子类SKU
//                    $acSku[] = $value['sku_sn'];
//                    $acOrder['goods'][$value['sku_sn']] = $value;
//                }
                //<选中商品归给父订单--part 2--end>//

                /*-------------分割线------------*/

                //<选中商品归给子订单--part 1--start>//
                if (in_array($value['sku_sn'], $skuSn)) {
                    //父订单
                    $asOrder['item_count'] -= 1;
                    $asOrder['total_price'] -= $value['total_price'];
                    $asOrder['fare_price'] -= $value['total_fare_price'];
                    $asOrder['discount_price'] -= $value['all_dis'];
                    $asOrder['real_pay_price'] -= $value['real_pay_price'];

                    //子订单
                    $acOrder['item_count'] += 1;
                    $acOrder['total_price'] += $value['total_price'];
                    $acOrder['fare_price'] += $value['total_fare_price'];
                    $acOrder['discount_price'] += $value['all_dis'];
                    $acOrder['real_pay_price'] += $value['real_pay_price'];
                    //添加子类SKU
                    $acSku[] = $value['sku_sn'];
                    $acOrder['goods'][$value['sku_sn']] = $value;

                } else {
                    //添加父类SKU
                    $asSku[] = $value['sku_sn'];
                    $asOrder['goods'][$value['sku_sn']] = $value;
                }
            } else {
                //父订单
                $number = $skuSnNumber[$value['sku_sn']];

                //拆子订单的数量时候用 如果查出来子订单的拆单数量为0,则证明没有拆数量过,直接使用该SKU的数量,如果不为空,则证明拆数量过,需要引用该子订单的split_number
                if ($splitType == 2) {
                    $split[] = ['order_sn', '=', $orderSn];
                    $split[] = ['status', '=', 1];
                    $allChildNumber = $shipOrderModel->where($split)->sum('split_number');
                    if (empty($allChildNumber)) {
                        $allNumber = $allItemCount[0];
                    } else {
                        $allNumber = $allChildNumber ?? 0;
                    }
                } else {
                    $allNumber = $allItemCount[0];
                }

                if ($allNumber - intval($number) <= 0) {
                    throw new ShipException(['msg' => '拆单数量不能超过商品总数量哟']);
                }

                $asOrder['split_number'] = $allNumber - intval($number);
                $asOrder['item_count'] = 1;
                $asOrder['goods'][$value['sku_sn']] = $value;
                $asSku[] = $value['sku_sn'];

                //子订单
                $acOrder['split_number'] = intval($number);
                $acOrder['item_count'] = 1;
                $acOrder['total_price'] = $asOrder['total_price'];
                $acOrder['fare_price'] = $asOrder['fare_price'];
                $acOrder['discount_price'] = $asOrder['discount_price'];
                $acOrder['real_pay_price'] = $asOrder['real_pay_price'];
                $acOrder['goods'][$value['sku_sn']] = $value;
                $acSku[] = $value['sku_sn'];
            }

        }

        $asOrder['order_update_time'] = $orderInfo['update_time'];
        unset($asOrder['update_time']);
        $asOrder['split_status'] = 1;
        $asOrder['order_is_exist'] = empty($asOrder['parent_order_sn']) ? 1 : $splitType;
        $asOrder['goods_sku'] = implode(',', $asSku);
        $asOrder['create_time'] = strtotime($asOrder['create_time']);
        $asOrder['order_update_time'] = strtotime($asOrder['order_update_time']);

        $acOrder['order_update_time'] = $orderInfo['update_time'];
        unset($acOrder['update_time']);
        $acOrder['split_status'] = 1;
        $acOrder['order_is_exist'] = 2;
        $acOrder['goods_sku'] = implode(',', $acSku);
        $acOrder['order_sort'] = $parentOrderSort;
        $acOrder['order_child_sort'] = $parentOrderSort . sprintf("%03d", ($childShipOrder + 1));
        $acOrder['create_time'] = strtotime($acOrder['create_time']);
        $acOrder['order_update_time'] = strtotime($acOrder['order_update_time']);
        $all = [$asOrder, $acOrder];

        //新增或编辑操作
        $dbRes = Db::transaction(function () use ($all, $shipOrderModel, $orderSn, $splitType) {
            foreach ($all as $key => $value) {
                $res = $shipOrderModel->newOrEdit(['order_sn' => $value['order_sn']], $value);
            }
            if ($splitType == 1) {
                //修改原订单标识此单包含拆单
                Order::update(['split_status' => 1], ['order_sn' => $orderSn]);
            }
            return $res;
        });

        return $all;
    }

    /**
     * @title  订单合并操作
     * @param array $data
     * @return mixed
     * @remark 不允许嵌套合并,不允许正常订单和拆单后的订单合并,不允许不同父订单的子订单合并
     * @throws \Exception
     */
    public function mergeOrder(array $data)
    {
        $orderSn = $data['order_sn'];
        //不允许嵌套合并,或者正常订单和拆单后的订单合并
        if (count($orderSn) < 2) {
            throw new ShipException(['errorCode' => 1900105]);
        }
        //split_type=1则合并正常订单 =2则合并子订单
        $splitType = $data['merge_type'] ?? 1;
        $shipOrderModel = new ShipOrder();
        $map[] = ['status', '=', 1];
        if ($splitType == 1) {
            $map[] = ['split_status', '=', 3];
        } else {
            $map[] = ['split_status', '=', 1];
        }
        //仅允许操作待发货(未备货)且为填物流单号的订单
        $map[] = ['order_status', '=', 2];
        $map[] = ['shipping_status', '=', 1];
        $map[] = ['', 'exp', Db::raw('shipping_code is null')];

        $aOrder = $shipOrderModel->with(['parent'])->where($map)->where(function ($query) use ($orderSn) {
            $mapOr[] = ['parent_order_sn', 'in', $orderSn];
            $mapAnd[] = ['order_sn', 'in', $orderSn];
            $query->where($mapAnd)->whereOr([$mapOr]);
        })->withoutField('id')->order('order_sort desc,order_child_sort asc')->select()->toArray();

        if (empty($aOrder)) {
            throw new ShipException(['errorCode' => 1900101]);
        }

        if (count($aOrder) < count($orderSn)) {
            throw new ShipException(['errorCode' => 1900111]);
        }
        if ($splitType == 2) {
            $notParent = false;
            foreach ($aOrder as $key => $value) {
                //如果是拆数量的子订单,舍弃找到的父类订单,因为需要合并回去的是原来的拆数量的子订单
                if (!empty($value['order_sn']) && $value['order_sn'] == current($orderSn) && !empty($value['split_number']) && $value['split_status'] == 1) {
                    $notParent = true;
                }
            }
        }

        if (!empty($notParent)) {
            foreach ($aOrder as $key => $value) {
                if (empty($value['parent_order_sn'])) {
                    unset($aOrder[$key]);
                }
            }
        }

        //判断是否为同一个父订单的子订单合并
        $parentOrderSns = array_unique(array_filter(array_column($aOrder, 'parent_order_sn')));

        if (count($parentOrderSns) > 1) {
            throw new ShipException(['errorCode' => 1900107]);
        }

        foreach ($aOrder as $key => $value) {
            if (!in_array($value['order_sn'], $orderSn)) {
                $otherChildOrder[] = $value['order_sn'];
                unset($aOrder[$key]);
            }
        }

        $needAddParent = false;
        $parentExist = false;
        if ($splitType == 2) {
            //如果父类不存在于需要合单的订单号中,最后的总订单数需要加1
            if (!in_array(current($parentOrderSns), array_column($aOrder, 'order_sn'))) {
                $needAddParent = true;
            } else {
                $parentExist = true;
            }
        }

        $orderAllNumber = count($aOrder);
        $orderNumber = $orderAllNumber;

        if ($splitType == 2) {
            if (!empty($needAddParent)) {
                $orderAllNumber += 1;
            }
            //如果有存在的其他同父类的但是没有选中的子订单号,也要累加这一部分进入总订单数
            if (!empty($otherChildOrder)) {
                $orderAllNumber += count($otherChildOrder);
            }
        }

        //合并订单至少要两个有效订单
        if ($orderNumber < 2) {
            throw new ShipException(['errorCode' => 1900106]);
        }

        //如果合并的两个订单只有一个商品且为子订单合并,可以判断此合并是同一个商品的数量拆分后的合并
        $oneSkuHaveMoreItem = false;
        $orderGoods = array_unique(array_filter(array_column($aOrder, 'goods_sku')));
        if (count($orderGoods) == 1 && $splitType == 2) {
            $oneSkuHaveMoreItem = true;
        }

        $Option = [];
        $allSku = [];
        $childOrder = [];
        $allOrder = [];
        $splitNumber = 0;
        $LastSplitOrder = false;
        if ($orderAllNumber <= $orderNumber) {
            $LastSplitOrder = true;
        }

        foreach ($aOrder as $key => $value) {
            if ($value['split_status'] == 2) {
                throw new ShipException(['errorCode' => 1900104]);
            }
            if (empty($Option)) {
                $allOrder = $value;
                $allOrder['item_count'] = 0;
                $allOrder['total_price'] = 0;
                $allOrder['fare_price'] = 0;
                $allOrder['discount_price'] = 0;
                $allOrder['real_pay_price'] = 0;
                $allOrder['split_status'] = 2;
                $allOrder['order_is_exist'] = 1;
                $Option['uid'] = $value['uid'];
                $Option['shipping_name'] = $value['shipping_name'];
                $Option['shipping_phone'] = $value['shipping_phone'];
                $Option['shipping_address'] = $value['shipping_address'];
                if ($splitType == 2) {
//                    if(!empty($value['parent_order_sn'])){
                    $allOrder['split_status'] = 1;
                    if (!empty($parentExist)) {
                        $allOrder['order_is_exist'] = 1;
                    } else {
                        $allOrder['order_is_exist'] = 2;
                    }
                    if (!empty($LastSplitOrder)) {
                        $allOrder['split_status'] = 3;
                        $allOrder['order_is_exist'] = 1;
                    }
//                    }
                }
            } else {
                if ($splitType == 1) {
                    $childOrder[$value['order_sn']] = $allOrder['order_sn'];
                } else {
                    $childOrder[$value['order_sn']] = 'will delete';
                }
            }
            if ($value['uid'] != $Option['uid'] || $value['shipping_name'] != $Option['shipping_name'] || $value['shipping_phone'] != $Option['shipping_phone'] || $value['shipping_address'] != $Option['shipping_address']) {
                throw new ShipException(['errorCode' => 1900103]);
            }
            if (empty($oneSkuHaveMoreItem)) {
                $allOrder['item_count'] += intval($value['item_count']);
                $allOrder['total_price'] += $value['total_price'];
                $allOrder['fare_price'] += $value['fare_price'];
                $allOrder['discount_price'] += $value['discount_price'];
                $allOrder['real_pay_price'] += $value['real_pay_price'];
                $allSku = array_unique(array_merge_recursive($allSku, explode(',', $value['goods_sku'])));

            } else {
                //$allOrder['item_count'] += intval($value['item_count']);
                $allOrder['item_count'] = 1;
                $allOrder['total_price'] = $value['total_price'];
                $allOrder['fare_price'] = $value['fare_price'];
                $allOrder['discount_price'] = $value['discount_price'];
                $allOrder['real_pay_price'] = $value['real_pay_price'];
                $splitNumber += $value['split_number'];
                $allOrder['split_number'] = $splitNumber;
                $allSku = explode(',', $value['goods_sku']);
            }

        }

        $allOrder['goods_sku'] = implode(',', $allSku);
        $allOrder['create_time'] = strtotime($allOrder['create_time']);
        unset($allOrder['update_time']);

        //新增或编辑操作
        $dbRes = Db::transaction(function () use ($allOrder, $shipOrderModel, $orderSn, $childOrder, $splitType, $LastSplitOrder) {
            $res = $shipOrderModel->newOrEdit(['order_sn' => $allOrder['order_sn']], $allOrder);
            if ($splitType == 1) {
                $orderRes = Order::update(['split_status' => 2], ['order_sn' => $allOrder['order_sn']]);
            }
            if (!empty($childOrder)) {
                foreach ($childOrder as $key => $value) {
                    if ($splitType == 1) {
                        ShipOrder::update(['parent_order_sn' => $value, 'split_status' => 2], ['order_sn' => $key]);
                        Order::update(['split_status' => 2], ['order_sn' => $key]);
                    } else {
                        ShipOrder::update(['status' => -1], ['order_sn' => $key]);
                    }
                }
            }
            if ($splitType == 2) {
                if (!empty($LastSplitOrder)) {
                    //修改原订单标识此单已经全部合单完了,取消拆单标识
                    Order::update(['split_status' => 3], ['order_sn' => $orderSn]);
                }
            }
            return $res;
        });

        //为了方便直接查询已经合并好的订单返回
        $mergeOrder = (new ShipOrder())->list(['searOrderSn' => $allOrder['order_sn'], 'needNormalKey' => true]);

        return $mergeOrder['list'];
    }

    /**
     * @title  取消子订单的拆分
     * @param array $data
     * @return mixed
     * @remark 仅支持正常订单拆分后的子订单取消拆分
     * @throws \Exception
     */
    public function cancelSplit(array $data)
    {
        //仅支持正常订单拆分后的子订单取消拆分
        $orderSn = $data['order_sn'];
        $aOrder = ShipOrder::where(['order_sn' => $orderSn, 'split_status' => 1, 'status' => 1, 'order_is_exist' => 2])->field('order_sn,parent_order_sn,order_status,shipping_code,split_status,split_number,goods_sku')->findOrEmpty()->toArray();

        if (empty($aOrder)) {
            throw new ShipException(['errorCode' => 1900101]);
        }
        if ($aOrder['order_status'] != 2 || !empty($aOrder['shipping_code'])) {
            throw new ShipException(['errorCode' => 1900111]);
        }
        if (empty($aOrder['parent_order_sn'])) {
            throw new ShipException(['errorCode' => 1900108]);
        }

        //仅允许待发货的订单才可以操作
        $orderStatus = ShipOrder::where(['order_sn' => $aOrder['parent_order_sn']])->value('order_status');
        if ($orderStatus != 2) {
            throw new ShipException(['msg' => '父订单状态不允许操作!']);
        }

        //如果取消的订单是拆数量的订单(split_status=1就是拆单订单,goods_sku只有一个,split_number不为空则表示拆数量了),查找同个父类订单下同个SKU的最早的那个拆数量的订单,然后合并两个拆数量的子订单
        $splitOrder = [];
        if (count(explode(',', $aOrder['goods_sku'])) == 1 && $aOrder['split_status'] == 1 && !empty($aOrder['split_number'])) {
            //查找同个父类订单下同个SKU的最早的那个拆数量的订单
            $splitMap[] = ['parent_order_sn', '=', $aOrder['parent_order_sn']];
            $splitMap[] = ['split_status', '=', 1];
            $splitMap[] = ['split_number', '<>', 0];
            $splitMap[] = ['order_sn', '<>', $aOrder['order_sn']];
            $splitMap[] = ['goods_sku', '=', $aOrder['goods_sku']];
            $splitMap[] = ['status', '=', 1];
            $splitOrder = ShipOrder::where($splitMap)->field('order_sn,parent_order_sn,order_status,shipping_code,split_status,split_number,goods_sku')->order('id')->findOrEmpty()->toArray();
            if (empty($splitOrder)) {
                throw new ShipException(['msg' => '寻找拆数量父类订单有误']);
            }
        }

        if (!empty($splitOrder)) {
            $merge['order_sn'] = [$splitOrder['order_sn'], $aOrder['order_sn']];
        } else {
            $merge['order_sn'] = [$aOrder['parent_order_sn'], $aOrder['order_sn']];
        }
        $merge['merge_type'] = 2;
        $res = $this->mergeOrder($merge);
        return $res;
    }

    /**
     * @title  取消订单的合并
     * @param array $data
     * @return mixed
     * @remark 仅支持正常订单合并后的订单取消合并
     * @throws \Exception
     */
    public function cancelMerge(array $data)
    {
        //仅支持正常订单合并后的订单取消合并
        $orderSn = $data['order_sn'];
        $aOrder = ShipOrder::with(['childOrderSn'])->where(['order_sn' => $orderSn, 'split_status' => 2, 'status' => 1, 'order_is_exist' => 1])->field('order_sn,parent_order_sn,order_status,shipping_code')->findOrEmpty()->toArray();
        if (empty($aOrder)) {
            throw new ShipException(['errorCode' => 1900101]);
        }
        if ($aOrder['order_status'] != 2 || !empty($aOrder['shipping_code'])) {
            throw new ShipException(['errorCode' => 1900111]);
        }
        if (!empty($aOrder['parent_order_sn']) || empty($aOrder['childOrderSn'])) {
            throw new ShipException(['errorCode' => 1900109]);
        }
        $aOrderGoodsSku = OrderGoods::where(['order_sn' => $orderSn, 'status' => 1])->column('sku_sn');
        //选中父类原有的商品然后拆单
        $merge['order_sn'] = $orderSn;
        $merge['sku_sn'] = $aOrderGoodsSku;
        $merge['split_type'] = 3;
        $res = $this->splitOrder($merge);

        //有可能选中的商品是跟父类是同样的,导致只是拆了子类的,所以需要递归拆到不能拆为止
        $needReSplit = false;
        if (!empty($res)) {
            foreach ($res as $key => $value) {
                if ($value['order_sn'] == $orderSn && $value['split_status'] != 3) {
                    $needReSplit = true;
                    break;
                }
            }
        }
        foreach ($res as $key => $value) {
            $all[$value['order_sn']] = $value;
        }

        if (!empty($needReSplit)) {
            $res = $this->cancelMerge(['order_sn' => $orderSn]);
        }

        foreach ($res as $key => $value) {
            $all[$value['order_sn']] = $value;
        }

        $alls = array_values($all);

        return $alls;
    }

    /**
     * @title  发货
     * @param array $data
     * @return mixed
     * @throws \Exception
     */
    public function ship(array $data)
    {
        $orderShip = $data['order_ship'];
        $orderSn = array_unique(array_filter(array_column($orderShip, 'order_sn')));
        $orderShipCode = [];
        foreach ($orderShip as $key => $value) {
            //物流编号只能是数字和字母的组合,不允许单号组合在一起
            if (empty(ctype_alnum(trim($value['shipping_code'])))) {
                throw new ServiceException(['msg' => '<' . $value['shipping_code'] . '> 物流单号仅允许英文和数字,请重新填写']);
            }
            $orderShipCode[$value['order_sn']]['order_sn'] = $value['order_sn'];
            $orderShipCode[$value['order_sn']]['company'] = $value['company'];
            $orderShipCode[$value['order_sn']]['company_code'] = $value['company_code'];
            $orderShipCode[$value['order_sn']]['shipping_code'] = $value['shipping_code'];
        }

        $shipOrderModel = (new ShipOrder());
        $orderModel = (new Order());
        $map[] = ['order_sn', 'in', $orderSn];
        $map[] = ['pay_status', '=', 2];
        $map[] = ['order_status', '=', 2];
        $map[] = ['status', '=', 1];
        $orderList = $shipOrderModel->with(['childOrder', 'goods' => function ($query) {
            $query->where(['status' => 1]);
        }])->where($map)->field('uid,order_sn,order_type,pay_type,parent_order_sn,delivery_time,shipping_name,shipping_phone,shipping_address,shipping_code,split_status,goods_sku')->select()->toArray();

        //如果需要发货的订单号和实际查询到的待发货的订单号数量不一致,需要判断是否有订单存在其他状态,存在则不继续支持发货操作,返回异常订单数组
        if (count($orderList) != count($orderSn)) {
            if (!empty($orderList)) {
                $findOrders = array_column($orderList, 'order_sn');
                foreach ($orderSn as $key => $value) {
                    if (!in_array($value, $findOrders)) {
                        $notFoundOrder[] = $value;
                    }
                }
            } else {
                $notFoundOrder = $orderSn;
            }

            if (!empty($notFoundOrder)) {
                $notFoundOrder = array_unique($notFoundOrder);
                //过滤部分已经发货的订单
                $shipOrder = $shipOrderModel->where(['order_sn' => $notFoundOrder, 'order_status' => 3])->column('order_sn');
                if (!empty($shipOrder)) {
                    $notFoundOrder = array_diff($notFoundOrder, $shipOrder);
                }
            }
            if (!empty($notFoundOrder)) {
                return [
                    'success' => ['count' => 0, 'order' => []],
                    'fail' => ['count' => 0, 'order' => []],
                    'error' => ['count' => count($notFoundOrder), 'order' => array_values($notFoundOrder)]
                ];
            }
        }

        if (empty($orderList)) {
            throw new ShipException(['errorCode' => 1900116]);
        }

        foreach ($orderList as $key => $value) {
            $orderList[$value['order_sn']] = $value;
            unset($orderList[$key]);
        }
        $goodsErrorOrder = [];

        foreach ($orderList as $key => $value) {
            $value['shipping_code'] = $orderShipCode[$value['order_sn']]['shipping_code'];
            $value['company_code'] = $orderShipCode[$value['order_sn']]['company_code'];
            $value['company'] = $orderShipCode[$value['order_sn']]['company'];
            $orderList[$key]['shipping_code'] = $value['shipping_code'];
            $orderList[$key]['company_code'] = $value['company_code'];
            $orderList[$key]['company'] = $value['company'];
            //给合并的订单也修改信息
            if ($value['split_status'] == 2 && empty($value['parent_order_sn'])) {
                if (!empty($value['childOrder'])) {
                    foreach ($value['childOrder'] as $cKey => $cValue) {
                        if ($cValue['order_status'] == 2) {
                            $cValue['shipping_code'] = $value['shipping_code'];
                            $cValue['company_code'] = $value['company_code'];
                            $cValue['company'] = $value['company'];
                            $orderList[$cValue['order_sn']] = $cValue;
                        }
                    }
                }
            }
            //给拆单的子订单填充回商品信息
            if ($value['split_status'] == 1 && !empty($value['parent_order_sn'])) {
                if (empty($value['goods'])) {
                    $orderList[$key]['goods'] = OrderGoods::where(['order_sn' => $value['parent_order_sn'], 'pay_status' => 2, 'status' => 1, 'sku_sn' => explode(',', $value['goods_sku'])])->select()->toArray();
                }
            }
        }

        //剔除不属于该发货订单的商品,防止误发货,一般这种情况的都是父订单的商品
        foreach ($orderList as $key => $value) {
            if (!empty($value['goods']) && !empty($value['goods_sku'])) {
                foreach ($value['goods'] as $gKey => $gValue) {
                    if (!in_array($gValue['sku_sn'], explode(',', $value['goods_sku']))) {
                        unset($orderList[$key]['goods'][$gKey]);
                    }
                    //判断商品是否属于供应商可发的范畴,不等于当前后台用户供应商编码的剔除
                    if (!empty($data['adminInfo'])) {
                        $supplierCode = $data['adminInfo']['supplier_code'] ?? null;
                        if (!empty($supplierCode)) {
                            if (!empty($gValue['supplier_code']) && ($supplierCode != $gValue['supplier_code'])) {
                                unset($orderList[$key]['goods'][$gKey]);
                            }
                        }
                    }
                }
            }
        }

        foreach ($orderList as $key => $value) {
            //如果查出来的发货订单正常状态的商品数量为空,证明程序出现了异常,(可能是:1. 商品都退售后了但是发货订单正常 2. 不属于该供应商可操作范畴),需要报错防止错发
            if (!empty($data['adminInfo']) && !empty($data['adminInfo']['supplier_code'])) {
                if (empty($orderList[$key]['goods'])) {
                    $goodsErrorOrder[] = $value['order_sn'];
                }
            } else {
                if ($value['split_status'] == 1) {
                    if (empty($orderList[$key]['goods'])) {
                        $goodsErrorOrder[] = $value['order_sn'];
                    }
                }
            }
        }

        //如果查出来的发货订单正常状态的商品数量为空,证明程序出现了异常,(可能是:1. 商品都退售后了但是发货订单正常 2. 不属于该供应商可操作范畴),需要报错防止错发
        if (!empty($goodsErrorOrder)) {
            return [
                'success' => ['count' => 0, 'order' => []],
                'fail' => ['count' => 0, 'order' => []],
                'error' => ['count' => count($goodsErrorOrder), 'order' => array_values($goodsErrorOrder)]
            ];
        }

        $shippingService = new Shipping();
        $template = [];
        $newShip = [];
        $aFailOrder = [];
        $count = 0;
        //向第三方订阅物流信息推送
        foreach ($orderList as $key => $value) {
            $ship['company_name'] = $value['company'];
            $ship['company'] = $value['company_code'];
            $ship['shipping_code'] = $value['shipping_code'];
            $ship['user_phone'] = $value['shipping_phone'] ?? '';
            $subRes = $shippingService->subscribe($ship);

            if (intval($subRes['returnCode']) == 200) {
                //组装消息模板通知数组
                $template[$count]['uid'] = $value['uid'];
                $template[$count]['type'] = 'ship';
                if (empty($value['order_sn'])) {
                    $template[$count]['page'] = 'pages/index/index';
                } else {
                    $template[$count]['page'] = 'pages/index/index?redirect=%2Fpages%2Forder-detail%2Forder-detail%3Fsn%3D' . $value['order_sn'];
                }
//                $template[$count]['page'] = null;
                if (!empty($value['goods'])) {
                    $goodsTitle = implode(',', array_column($value['goods'], 'title'));
                    $length = mb_strlen($goodsTitle);
                    if ($length >= 17) {
                        $goodsTitle = mb_substr($goodsTitle, 0, 17) . '...';
                    }
                } else {
                    $goodsTitle = '您购买的商品';
                }

                $template[$count]['template'] = ['character_string1' => $value['order_sn'] ?? null, 'thing19' => $goodsTitle, 'thing7' => trim($value['company'] ?? null), 'character_string4' => $value['shipping_code'] ?? null, 'thing6' => '您的商品已发货,感谢您对' . config('system.projectName') . '的支持'];

                //组装往物流详情里面添加发货记录的数组
                $newShip[$count]['shipping_code'] = $value['shipping_code'];
                $newShip[$count]['company'] = $value['company_code'];
                $newShip[$count]['company_name'] = $value['company'];
                $newShip[$count]['is_check'] = 0;
                $newShip[$count]['node_status'] = '发货';
                $newShip[$count]['node_time'] = date('Y-m-d H:i:s', time());
                $newShip[$count]['content'] = '平台已发货(物流更新可能存在延迟,请耐心等待)';
                $count++;
            } elseif (intval($subRes['returnCode']) == 501) {
                continue;
            } else {
                $aFailOrder[] = $value['order_sn'];
                unset($orderList[$key]);
                continue;
            }

        }

        //数据库操作
        $aSuccessOrder = Db::transaction(function () use ($orderList, $shipOrderModel, $orderModel, $template, $newShip) {
            $successOrder = [];
            //修改订单表数据
            if (!empty($orderList)) {
                foreach ($orderList as $key => $value) {
                    $save['shipping_code'] = $value['shipping_code'];
                    $save['shipping_company'] = $value['company'];
                    $save['shipping_company_code'] = $value['company_code'];
                    $save['delivery_time'] = time();
                    $save['order_status'] = 3;
                    $save['shipping_status'] = 3;
                    $shipOrderRes = $shipOrderModel->where(['order_sn' => $value['order_sn']])->save($save);

                    unset($save['shipping_company']);
                    unset($save['shipping_company_code']);
                    $map = [];
                    if ($value['split_status'] == 1) {
                        if (empty($value['parent_order_sn'])) {
                            $map[] = ['order_sn', '=', $value['order_sn']];
                        } else {
                            $map[] = ['order_sn', '=', $value['parent_order_sn']];
                        }
                        $orderShipCode = $orderModel->where($map)->value('shipping_code');
                        if (!empty($orderShipCode)) {
                            $aShipCode = [];
                            $aShipCode = explode(',', $orderShipCode);
                            foreach ($aShipCode as $cKey => $cValue) {
                                if ($value['shipping_code'] == $cValue) {
                                    unset($aShipCode[$key]);
                                }
                            }
                            if (!empty($aShipCode)) {
                                $orderShipCode = implode(',', $aShipCode);
                                $save['shipping_code'] = $orderShipCode . "," . $value['shipping_code'];
                            }
                        }
                    } else {
                        $map[] = ['order_sn', '=', $value['order_sn']];
                    }

                    $orderRes = $orderModel->where($map)->save($save);
                    $successOrder[] = $value['order_sn'];

                    //修改订单相关商品的备货状态
                    if (!empty($value['goods'])) {
                        $gMap = [];
                        $skuSn = array_unique(array_filter(array_column($value['goods'], 'sku_sn')));
                        if (!empty($skuSn)) {
                            if ($value['split_status'] == 1 && !empty($value['parent_order_sn'])) {
                                $gMap[] = ['order_sn', '=', $value['parent_order_sn']];
                            } else {
                                $gMap[] = ['order_sn', '=', $value['order_sn']];
                            }
                            $gMap[] = ['shipping_status', 'in', [1, 2]];
                            $gMap[] = ['sku_sn', 'in', $skuSn];

                            $goodsSave = [];
                            $goodsSave['shipping_status'] = 3;
                            $goodsSave['shipping_code'] = $value['shipping_code'];
                            $goodsSave['shipping_company'] = $value['company'];
                            $goodsSave['shipping_company_code'] = $value['company_code'];
                            $goodsSave['delivery_time'] = time();
                            OrderGoods::update($goodsSave, $gMap);
                        }
                    }

                    //order_type=7美丽豆兑换订单order_type=8美丽券兑换订单,或者美丽金支付的商城订单 不允许任何分润奖励
                    if (!in_array($value['order_type'], [7, 8]) && ($value['order_type'] == 1 && $value['pay_type'] != 5)) {
                        //发放区域代理奖励
                        $areaDivideQueue = Queue::push('app\lib\job\AreaDividePrice', ['order_sn' => $value['order_sn'], 'searNumber' => $data['searNumber'] ?? 1, 'dealType' => 1], config('system.queueAbbr') . 'AreaOrderDivide');
                    }
                }
            }

            if (!empty($template)) {
                //推送消息模板队列
                $access_key = getAccessKey();
                foreach ($template as $key => $value) {
                    $value['access_key'] = $access_key;
                    $templateQueue = Queue::push('app\lib\job\Template', $value, config('system.queueAbbr') . 'TemplateList');
                }
            }
            if (!empty($newShip)) {
                //往物流详情里面添加发货记录
                (new ShippingDetail())->saveAll($newShip);
            }
            return $successOrder;
        });

        $allRes = [
            'success' => ['count' => count($aSuccessOrder), 'order' => $aSuccessOrder],
            'fail' => ['count' => count($aFailOrder), 'order' => $aFailOrder],
            'order' => ['count' => 0, 'order' => []]
        ];
        return $allRes;
    }

    /**
     * @title  修改物流单号或地址
     * @param array $data
     * @return bool
     */
    public function updateShipOrder(array $data)
    {
        //1为填物流单号 2为修改物流单号
        $type = $data['type'] ?? 1;
        $shipping = $data['order_ship'] ?? [];

        switch ($type) {
            case 1:
                foreach ($shipping as $key => $value) {
                    $updateShip['shipping_code'] = $value['shipping_code'];
                    $updateShip['shipping_company_code'] = $value['company_code'];
                    $updateShip['shipping_company'] = $value['company'];
                    $res = ShipOrder::update($updateShip, ['order_sn' => $value['order_sn'], 'order_status' => 2]);
                }
                break;
            case 2:
                $number = count($shipping);
                if (intval($number) != 1) {

                    throw new ShipException(['errorCode' => 1900110]);
                }
                $ship = $shipping[0];

                $shipOrderInfo = ShipOrder::with(['childOrder'])->where(['order_sn' => $ship['order_sn'], 'order_status' => 3])->field('uid,order_sn,parent_order_sn,delivery_time,shipping_name,shipping_phone,shipping_address,shipping_code,split_status')->findOrEmpty()->toArray();

                if (empty($shipOrderInfo)) {
                    throw new ShipException(['errorCode' => 1900101]);
                }
                $res = Db::transaction(function () use ($ship, $shipOrderInfo) {
                    $uShipOrder['shipping_company_code'] = $ship['company_code'];
                    $uShipOrder['shipping_company'] = $ship['company'];
                    $uShipOrder['shipping_code'] = $ship['shipping_code'];
                    $dbRes = ShipOrder::update($uShipOrder, ['order_sn' => $ship['order_sn']]);
                    if ($shipOrderInfo['split_status'] == 1) {
                        if (empty($shipOrderInfo['parent_order_sn'])) {
                            $map[] = ['order_sn', '=', $shipOrderInfo['order_sn']];
                        } else {
                            $map[] = ['order_sn', '=', $shipOrderInfo['parent_order_sn']];
                        }

                        $orderShipCode = Order::where($map)->value('shipping_code');

                        //剔除原来的订单号
                        if (!empty($orderShipCode)) {
                            $aShipCode = explode(',', $orderShipCode);
                            foreach ($aShipCode as $key => $value) {
                                if ($shipOrderInfo['shipping_code'] == $value) {
                                    unset($aShipCode[$key]);
                                }
                            }
                            if (!empty($aShipCode)) {
                                $orderShipCode = implode(',', $aShipCode);
                                $order['shipping_code'] = $orderShipCode . "," . $ship['shipping_code'];
                            } else {
                                $order['shipping_code'] = $ship['shipping_code'];
                            }
                        } else {
                            $order['shipping_code'] = $ship['shipping_code'];
                        }
                        $dbRes = Order::update($order, $map);
                    } elseif ($shipOrderInfo['split_status'] == 2) {
                        //一并修改被合并掉的子订单的物流信息
                        if (!empty($shipOrderInfo['childOrder'])) {
                            $allOrder = array_merge_recursive([$shipOrderInfo], $shipOrderInfo['childOrder']);
                            foreach ($allOrder as $key => $value) {
                                $umShipOrder['shipping_company_code'] = $ship['company_code'];
                                $umShipOrder['shipping_company'] = $ship['company'];
                                $umShipOrder['shipping_code'] = $ship['shipping_code'];
                                ShipOrder::update($umShipOrder, ['order_sn' => $value['order_sn']]);
                                $dbRes = Order::update(['shipping_code' => $umShipOrder['shipping_code']], ['order_sn' => $value['order_sn']]);
                            }
                        } else {
                            throw new ShipException(['errorCode' => 1900101]);
                        }
                    } elseif ($shipOrderInfo['split_status'] == 3) {
                        $dbRes = Order::update($uShipOrder, ['order_sn' => $ship['order_sn']]);
                    }
                    return $dbRes;
                });
                if (!empty($res)) {
                    $ship['company'] = $ship['company_code'];
                    $subRes = (new Shipping())->subscribe($ship);
                }
                break;
            default:
                $res = false;
        }
        return judge($res);

    }

    /**
     * @title  更新收货信息
     * @param array $shipInfo
     * @return mixed
     */
    public function updateShipInfo(array $shipInfo)
    {
        $orderSn = trim($shipInfo['order_sn'] ?? []);
        $shipOrderInfo = ShipOrder::where(['order_sn' => $orderSn, 'order_status' => 2])->count();
        if (empty($shipOrderInfo)) {
            throw new ShipException(['errorCode' => 1900101]);
        }
        $res = false;
        if (!empty($shipInfo)) {
            $updateShip['shipping_name'] = $shipInfo['shipping_name'];
            $updateShip['shipping_phone'] = $shipInfo['shipping_phone'];
            $updateShip['shipping_address'] = $shipInfo['shipping_address'];
            if (!empty($shipInfo['shipping_address_detail'] ?? null)) {
                if (empty($shipInfo['shipping_address_detail']['AreaId'] ?? null) || empty($shipInfo['shipping_address_detail']['CityId'] ?? null) || empty($shipInfo['shipping_address_detail']['ProvinceId'] ?? null)) {
                    throw new OrderException(['msg' => '收货地址信息有误, 可能存在过期的行政区域, 请重新编辑']);
                }
                $updateShip['shipping_address_detail'] = json_encode($shipInfo['shipping_address_detail'], 256);
            }
            $res = ShipOrder::update($updateShip, ['order_sn' => $orderSn]);
            Order::update($updateShip, ['order_sn' => $orderSn]);
        }
        return judge($res);
    }

    /**
     * @title  更新订单附加信息
     * @param array $shipInfo
     * @return mixed
     */
    public function updateAttachInfo(array $shipInfo)
    {
        $orderSn = trim($shipInfo['order_sn'] ?? []);
        $orderInfo = Order::with(['attach'])->where(['order_sn' => $orderSn, 'order_status' => 2])->field('order_sn,uid')->findOrEmpty()->toArray();
        if (empty($orderInfo)) {
            throw new ShipException(['errorCode' => 1900101]);
        }
        if (empty($orderInfo['attach'])) {
            throw new ShipException(['errorCode' => 1900115]);
        }
        $res = false;
        if (!empty($shipInfo)) {
            $updateShip['real_name'] = $shipInfo['real_name'];
            $updateShip['id_card'] = $shipInfo['id_card'];
            if (!empty($shipInfo['id_card_front'])) {
                $updateShip['id_card_front'] = $shipInfo['id_card_front'];
            }
            if (!empty($shipInfo['id_card_back'])) {
                $updateShip['id_card_back'] = $shipInfo['id_card_back'];
            }
            $res = OrderAttach::update($updateShip, ['order_sn' => $orderSn]);
        }
        return judge($res);
    }

    /**
     * @title  用户确认收货
     * @param array $data
     * @return mixed
     * @throws \Exception
     */
    public function userConfirmReceiveGoods(array $data)
    {
        $notThrowError = $data['notThrowError'] ?? false;
        $orderSn = trim($data['order_sn'] ?? null);
        $aOrder = Db::name('order')->alias('a')
            ->join('sp_user b', 'a.uid = b.uid', 'left')
            ->join('sp_member c', 'a.uid = c.uid', 'left')
            ->field('a.*,b.name as user_name,b.vip_level,b.member_card,b.avaliable_balance,b.total_balance,b.link_superior_user,c.child_team_code,c.team_code,c.parent_team,c.level,c.type')
            ->where(['a.order_sn' => $orderSn, 'a.pay_status' => 2, 'a.order_status' => 3])
            ->findOrEmpty();

        if (empty($aOrder) || $data['uid'] != $aOrder['uid']) {
            if (empty($notThrowError)) {
                throw new OrderException(['errorCode' => 1500109]);
            } else {
                return false;
            }
        }

        //如果订单商品任意一个存在售后,则整单不允许确认收货
        $orderGoods = OrderGoods::where(['order_sn' => $orderSn])->select()->toArray();
        $afterGoods = [];
        if (!empty($orderGoods)) {
            foreach ($orderGoods as $key => $value) {
                if (in_array($value['after_status'], [2, 3])) {
                    if (empty($notThrowError)) {
                        throw new OrderException(['msg' => '该订单存在正在售后的商品,还不能确认收货哦']);
                    } else {
                        return false;
                    }
                }
                if ($value['after_status'] == 4) {
                    $afterGoods[] = $value['sku_sn'];
                }
            }
        }

        //如果发货订单中有一个订单没有发货物流,即没有发货,不允许确认收货
        $shipMap[] = ['status', '=', 1];
        $shipMap[] = ['order_status', 'in', [2]];
        $shipOrder = ShipOrder::where($shipMap)->where(function ($query) use ($orderSn) {
            $mapOr[] = ['parent_order_sn', 'in', $orderSn];
            $mapAnd[] = ['order_sn', 'in', $orderSn];
            $query->where($mapAnd)->whereOr([$mapOr]);
        })->field('order_sn,parent_order_sn,shipping_status,shipping_code,order_status,shipping_type')->order('order_sort desc,order_child_sort asc')->select()->toArray();

        if (!empty($shipOrder)) {
            //统计已发货的发货订单
            $shipNumber = 0;
            foreach ($shipOrder as $key => $value) {
                if (!empty($value['shipping_code']) || (!empty($value['shipping_type']) && $value['shipping_type'] == 2)) {
                    $shipNumber += 1;
                }
            }
            if ($shipNumber < count($shipOrder)) {
                if (empty($notThrowError)) {
                    throw new OrderException(['errorCode' => 1500128]);
                } else {
                    return false;
                }
            }
        }

//        //如果是团长大礼包则升级用户等级<----此流程已改,但保留代码>
//        if($aOrder['order_type'] == 3){
//            $upgrade = (new Member())->becomeMember($aOrder);
//        }
        $divideService = (new Divide());


        //除了众筹订单其他类型订单可以进入确认分润的环节
        //兑换订单(美丽豆, 美丽券订单, 普通用美丽金的订单都不允许分润)
//        if (!in_array($aOrder['order_type'], [6, 7, 8]) || ($aOrder['order_type'] == 1 && $aOrder['pay_type'] != 5)) {
        if ($aOrder['order_type'] != 6) {
            //确认分润
            $divideRes = $divideService->payMoneyForDivideByOrderSn($orderSn, $aOrder, $notThrowError);
            //给全部上级都加上团队业绩<流程已改,修改为下单即送>
//        $recordTeamPerformance = $divideService->getTopUserRecordTeamPerformance($aOrder);
        } else {
            $divideRes = true;
        }

        //给直属上级添加赠送条件或赠送套餐
        if (!empty($aOrder) && in_array($aOrder['order_type'], [3]) && !empty($aOrder['link_superior_user'])) {
            $topLinkUser = User::where(['uid' => $aOrder['link_superior_user'], 'status' => 1])->findOrEmpty()->toArray();
            if (!empty($topLinkUser) && !empty($topLinkUser['vip_level'] ?? 0)) {
                $handselRes = (new Divide())->topUserHandsel(['orderInfo' => $aOrder, 'topLinkUser' => $topLinkUser, 'orderGoods' => $orderGoods, 'searType' => 1]);
            }
        }

        //如果订单金额超过了提前购卡的门槛则赠送提前购卡
        if (in_array($aOrder['order_type'], [1, 3, 5])) {
            $systemConfig = SystemConfig::where(['status' => 1])->field('order_advance_buy_condition')->findOrEmpty()->toArray();
            if (priceFormat(($aOrder['real_pay_price'] - $aOrder['fare_price'])) >= (string)$systemConfig['order_advance_buy_condition']) {
                (new AdvanceCardDetail())->sendAdvanceBuyCard(['send_type' => 1, 'order_sn' => $aOrder['order_sn'], 'uid' => $aOrder['uid']]);
            }
        }
        //判断是否有兑换商品的附属赠送
        (new Pay())->checkExchangeOrderGoods(['orderInfo' => $aOrder, 'orderGoods' => $orderGoods]);
        return $divideRes;
    }

    /**
     * @title  无物流发货
     * @param array $data
     * @return array
     * @throws \Exception
     */
    public function noShippingCode(array $data)
    {
        $orderSn = array_unique(array_filter($data['order_sn']));
        if (count($orderSn) >= 200) {
            throw new ShipException(['msg' => '为保证系统稳定, 批量免物流发货单次仅支持200条订单']);
        }
        $shipOrderModel = (new ShipOrder());
        $orderModel = (new Order());
        $map[] = ['order_sn', 'in', $orderSn];
        $map[] = ['pay_status', '=', 2];
        $map[] = ['order_status', '=', 2];
        $map[] = ['status', '=', 1];
        $orderList = $shipOrderModel->with(['childOrder', 'goods' => function ($query) {
            $query->where(['status' => 1]);
        }])->where($map)->field('uid,order_sn,parent_order_sn,delivery_time,shipping_name,shipping_phone,shipping_address,shipping_code,split_status,goods_sku')->select()->toArray();

        //如果需要发货的订单号和实际查询到的待发货的订单号数量不一致,需要判断是否有订单存在其他状态,存在则不继续支持发货操作,返回异常订单数组
        if (count($orderList) != count($orderSn)) {
            if (!empty($orderList)) {
                $findOrders = array_column($orderList, 'order_sn');
                foreach ($orderSn as $key => $value) {
                    if (!in_array($value, $findOrders)) {
                        $notFoundOrder[] = $value;
                    }
                }
            } else {
                $notFoundOrder = $orderSn;
            }
            if (!empty($notFoundOrder)) {
                $notFoundOrder = array_unique($notFoundOrder);
                //过滤部分已经发货的订单
                $shipOrder = $shipOrderModel->where(['order_sn' => $notFoundOrder, 'order_status' => 3])->column('order_sn');
                if (!empty($shipOrder)) {
                    $notFoundOrder = array_diff($notFoundOrder, $shipOrder);
                }
            }
            if (!empty($notFoundOrder)) {
                return [
                    'success' => ['count' => 0, 'order' => []],
                    'fail' => ['count' => 0, 'order' => []],
                    'error' => ['count' => count($notFoundOrder), 'order' => $notFoundOrder]
                ];
            }
        }

        if (empty($orderList)) {
            throw new OrderException(['errorCode' => 1500109]);
        }

        //如果查出来的发货订单正常状态的商品数量为空,证明程序出现了异常,商品都退售后了但是发货订单正常,需要报错防止错发
        $goodsErrorOrder = [];
        $checkOrderList = $orderList;
        foreach ($checkOrderList as $key => $value) {
            //给拆单的子订单填充回商品信息
            if ($value['split_status'] == 1 && !empty($value['parent_order_sn'])) {
                if (empty($value['goods'])) {
                    $checkOrderList[$key]['goods'] = OrderGoods::where(['order_sn' => $value['parent_order_sn'], 'pay_status' => 2, 'status' => 1, 'sku_sn' => explode(',', $value['goods_sku'])])->select()->toArray();
                }
            }
        }

        //剔除不属于该发货订单的商品,防止误发货,一般这种情况的都是父订单的商品
        foreach ($checkOrderList as $key => $value) {
            if (!empty($value['goods']) && !empty($value['goods_sku'])) {
                foreach ($value['goods'] as $gKey => $gValue) {
                    if (!in_array($gValue['sku_sn'], explode(',', $value['goods_sku']))) {
                        unset($checkOrderList[$key]['goods'][$gKey]);
                    }
                }
            }
        }
        foreach ($checkOrderList as $key => $value) {
            if ($value['split_status'] == 1) {
                if (empty($checkOrderList[$key]['goods'])) {
                    $goodsErrorOrder[] = $value['order_sn'];
                }
            }
        }

        //如果查出来的发货订单正常状态的商品数量为空,证明程序出现了异常,商品都退售后了但是发货订单正常,需要报错防止错发
        if (!empty($goodsErrorOrder)) {
            return [
                'success' => ['count' => 0, 'order' => []],
                'fail' => ['count' => 0, 'order' => []],
                'error' => ['count' => count($goodsErrorOrder), 'order' => array_values($goodsErrorOrder)]
            ];
        }


        $allOrderSn = [];
        foreach ($orderList as $key => $value) {
            $allOrderSn[] = $value['order_sn'];
            if (!empty($value['parent_order_sn'])) {
                $allOrderSn[] = $value['parent_order_sn'];
            }
            $orderList[$value['order_sn']] = $value;
            unset($orderList[$key]);
        }

        $realOrders = [];
        $exchangeOrders = [];
        $exchangeOrderSn = [];
        //查找原始订单,主要用来判断原始订单是否发货了,如果发货了不需要修改原始订单的发货物流类型
        if (!empty($allOrderSn)) {
            $oMap[] = ['order_sn', 'in', array_unique(array_filter($allOrderSn))];
            $oMap[] = ['pay_status', '=', 2];
            $orders = $orderModel->where($oMap)->field('id,order_sn,uid,user_phone,order_status,shipping_type,shipping_code,is_exchange,order_type')->select()->toArray();
            if (!empty($orders)) {
                foreach ($orders as $key => $value) {
                    $realOrders[$value['order_sn']] = $value;
                    //统计存在兑换商品的订单, 以便后续发放兑换的积分或健康豆, 仅限众筹订单, 其他类型订单在其余模块完成发放兑换的积分或健康豆
                    if (!empty($value['is_exchange'] ?? null) && $value['is_exchange'] == 1 && $value['order_type'] == 6) {
                        $exchangeOrders[] = $value;
                        $exchangeOrderSn[] = $value['order_sn'];
                    }
                }
            }
        }

        foreach ($orderList as $key => $value) {
            $value['shipping_type'] = 2;
            if ($value['split_status'] == 1) {
                $judgeOrderSn = !empty($value['parent_order_sn']) ? $value['parent_order_sn'] : $value['order_sn'];
            } else {
                $judgeOrderSn = $value['order_sn'];
            }

            //如果原本有物流,则还是保持有物流发货(需要注意区分拆单订单,拆单订单还是要无物流发货)
            if (!empty($value['shipping_code']) || (!empty($realOrders[$judgeOrderSn]) && !empty($realOrders[$judgeOrderSn]['shipping_code']))) {
                $value['shipping_type'] = 1;
            }
            $orderList[$key]['shipping_type'] = $value['shipping_type'];
            //给合并的订单也修改信息
            if ($value['split_status'] == 2 && empty($value['parent_order_sn'])) {
                if (!empty($value['childOrder'])) {
                    foreach ($value['childOrder'] as $cKey => $cValue) {
                        if ($cValue['order_status'] == 2) {
                            $cValue['shipping_type'] = $value['shipping_type'];
                            if ((!empty($realOrders[$cValue['order_sn']]) && !empty($realOrders[$cValue['order_sn']]['shipping_code']))) {
                                $cValue['shipping_type'] = 1;
                            }
                            $orderList[$cValue['order_sn']] = $cValue;
                        }
                    }
                }
            }
        }

        $aFailOrder = [];

        //数据库操作
        $aSuccessOrder = Db::transaction(function () use ($orderList, $shipOrderModel, $orderModel, $orderSn, $exchangeOrders,$exchangeOrderSn) {
            $successOrder = [];
            $needFindExchangeOrders = [];
            //修改订单表数据
            foreach ($orderList as $key => $value) {
                $map = [];
                $save['shipping_type'] = $value['shipping_type'];
                $save['delivery_time'] = time();
                $save['order_status'] = 3;
                $save['shipping_status'] = 3;

                $shipSave = $save;
                //选择了无物流发货的拆单订单的发货状态必须为无物流发货
                if ($value['split_status'] == 1 && (!empty($orderSn) && in_array($value['order_sn'], $orderSn))) {
                    $shipSave['shipping_type'] = 2;
                }
//                $shipOrderRes = ShipOrder::where(['order_sn' => $value['order_sn']])->save($shipSave);
                $shipOrderRes = ShipOrder::update($shipSave,['order_sn' => $value['order_sn']]);
                if ($value['split_status'] == 1) {
                    if (empty($value['parent_order_sn'])) {
                        $map[] = ['order_sn', '=', $value['order_sn']];
                        if (!empty($exchangeOrderSn ?? [])) {
                            if (in_array($value['order_sn'], $exchangeOrderSn)) {
                                $needFindExchangeOrders[] = $value;
                            }
                        }
                    } else {
                        $map[] = ['order_sn', '=', $value['parent_order_sn']];
                        if (!empty($exchangeOrderSn ?? [])) {
                            if (in_array($value['parent_order_sn'], $exchangeOrderSn)) {
                                $needFindExchangeOrders[] = $value;
                            }
                        }
                    }

                } else {
                    $map[] = ['order_sn', '=', $value['order_sn']];
                    if (!empty($exchangeOrderSn ?? [])) {
                        if (in_array($value['order_sn'], $exchangeOrderSn)) {
                            $needFindExchangeOrders[] = $value;
                        }
                    }
                }
//                $orderRes = Order::where($map)->save($save);
                $orderRes = Order::update($save,$map);
                $successOrder[] = $value['order_sn'];

                //修改订单相关商品的备货状态
                if (!empty($value['goods_sku'])) {
                    $gMap = [];
                    $skuSn = array_unique(array_filter(explode(',', $value['goods_sku'])));
                    if (!empty($skuSn)) {
                        if ($value['split_status'] == 1 && !empty($value['parent_order_sn'])) {
                            $gMap[] = ['order_sn', '=', $value['parent_order_sn']];
                        } else {
                            $gMap[] = ['order_sn', '=', $value['order_sn']];
                        }
                        $gMap[] = ['shipping_status', 'in', [1, 2]];
                        $gMap[] = ['sku_sn', 'in', $skuSn];
                        OrderGoods::update(['shipping_status' => 3], $gMap);
                    }
                }
            }

            if(!empty($needFindExchangeOrders)){
                $allExchangeGoodsSku = [];
                foreach ($needFindExchangeOrders as $key => $value) {
                    $needFindExchangeOrdersInfo[$value['order_sn']] = $value;
                    if (!empty($value['goods_sku'])) {
                        if (!empty($allExchangeGoodsSku)) {
                            $allExchangeGoodsSku = array_merge_recursive($allExchangeGoodsSku, array_unique(array_filter(explode(',', $value['goods_sku']))));
                        } else {
                            $allExchangeGoodsSku = array_unique(array_filter(explode(',', $value['goods_sku'])));
                        }
                    }
                }

                if (!empty($allExchangeGoodsSku)) {
                    $egMap = [];
                    $egMap[] = ['order_sn', 'in', array_keys($needFindExchangeOrdersInfo)];
                    $egMap[] = ['sku_sn', 'in', array_unique($allExchangeGoodsSku)];
                    $egMap[] = ['status', '=', 1];
                    $exchangeOrderGoods = OrderGoods::where($egMap)->select()->toArray();
                    if (!empty($exchangeOrderGoods)) {
                        foreach ($exchangeOrderGoods as $key => $value) {
                            //判断是否有兑换商品的附属赠送
                            $test = (new Pay())->checkExchangeOrderGoods(['orderInfo' => $needFindExchangeOrdersInfo[$value['order_sn']], 'orderGoods' => [$value]]);
                        }
                    }
                }
            }
            return $successOrder;
        });

        $allRes = [
            'success' => ['count' => count($aSuccessOrder), 'order' => $aSuccessOrder],
            'fail' => ['count' => count($aFailOrder), 'order' => $aFailOrder],
            'error' => ['count' => 0, 'order' => []],
        ];
        return $allRes;

    }

    /**
     * @title  自动拆单多个商品的订单
     * @param array $data
     * @return bool
     * @throws \Exception
     */
    public function autoSplitOrder(array $data)
    {
        //查询两次同步周期过程中新增的正常订单中多商品的订单,允许筛选时间,否则默认同步时间为两次同步周期中的开始时间的间隔
        $newShipOrderList = (new ShipOrder())->checkSyncMoreGoodsOrder($data);

        $DBRes = false;
        if (!empty($newShipOrderList)) {
            $DBRes = Db::transaction(function () use ($newShipOrderList) {
                foreach ($newShipOrderList as $key => $value) {
                    $aGoods = explode(',', $value['goods_sku']);
                    if (count($aGoods) > 1) {
                        //将商品倒序,最底下的商品优先拆出来
                        $aGoods = array_reverse($aGoods);
                        foreach ($aGoods as $gKey => $gValue) {
                            if ($gKey + 1 < count($aGoods)) {
                                //如果是第一个则为拆主订单,第二个到倒数二个为拆子订单,最后一个不允许拆
                                $res[] = $this->splitOrder(['order_sn' => $value['order_sn'], 'sku_sn' => [$gValue], 'split_type' => ($gKey == 0) ? 1 : 2, 'notThrowError' => true]);
                            }
                        }
                    }
                }
                return $res ?? [];
            });
        } else {
            $DBRes = true;
        }

        return judge($DBRes);
    }

    /**
     * @title  修改商品备货状态
     * @param array $data
     * @return bool
     * @throws \Exception
     */
    public function changeShippingStatus(array $data)
    {
        //类型type=1为修改为备货中 2为修改为待备货(打回原来的状态)
        $type = $data['type'] ?? 1;
        switch ($type) {
            case 1:
                $map[] = ['order_sn', 'in', $data['order_sn']];
                $map[] = ['pay_status', '=', 2];
                $map[] = ['order_status', '=', 2];
                $map[] = ['shipping_status', '=', 1];

                $errorMsg = '暂无待发货且待备货中的订单,请查验!';
                $changeType = 2;
                break;
            case 2:
                $map[] = ['order_sn', 'in', $data['order_sn']];
                $map[] = ['pay_status', '=', 2];
                $map[] = ['order_status', '=', 2];
                $map[] = ['shipping_status', '=', 2];

                $errorMsg = '暂无待发货且备货中的订单,请查验!';
                $changeType = 1;
                break;
            default:
                $map = [];
                $errorMsg = '系统错误!';
                throw new ServiceException(['msg' => '系统错误,有误的备货类型']);
        }

        $list = ShipOrder::where($map)->select()->toArray();
        if (empty($list)) {
            throw new ShipException(['msg' => $errorMsg]);
        }

        $DBRes = Db::transaction(function () use ($list, $type, $changeType) {
            $shipOrderModel = (new ShipOrder());
            $orderModel = (new Order());
            $orderGoodsModel = (new OrderGoods());
            $res = false;

            foreach ($list as $key => $value) {
                $shipOrderSplitNumber = [];
                $allShippingGoodsNumber = [];
                $sSave['shipping_status'] = $changeType;
                $shipOrderModel->where(['order_sn' => $value['order_sn']])->save($sSave);

                $allGoods = explode(',', $value['goods_sku']);
                //如果是修改回待备货状态则需要判断是否全部商品都恢复了,如果是才修改整个订单的状态为待备货,如果是拆数量的商品还需要判断商品数量是否全部都恢复了
                if ($type == 2) {
                    if ($value['split_status'] == 1 && !empty($value['split_number'])) {
                        if (!isset($shipOrderSplitNumber[$value['goods_sku']])) {
                            $shipOrderSplitNumber[$value['goods_sku']] = 0;
                        }
                        $shipOrderSplitNumber[$value['goods_sku']] = $value['split_number'];
                    }
                    //拆单情况下的订单需要判断子订单的商品状态
                    if ($value['split_status'] == 1) {

                        unset($orderSn);
                        if (!empty($value['parent_order_sn'])) {
                            $searOrderSn = $value['parent_order_sn'];
                        } else {
                            $searOrderSn = $value['order_sn'];
                        }
                        $allGoodsList = $orderGoodsModel->where(['order_sn' => $searOrderSn, 'sku_sn' => $allGoods])->field('order_sn,goods_sn,sku_sn,count,shipping_status')->select()->toArray();

                        if (!empty($allGoodsList)) {
                            $allGoodsCount = count($allGoodsList);
                            foreach ($allGoodsList as $sKey => $sValue) {
                                if ($value['shipping_status'] == 2) {
                                    if (!isset($allShippingGoodsNumber[$sValue['sku_sn']])) {
                                        $allShippingGoodsNumber[$sValue['sku_sn']] = 0;
                                    }
                                    $allShippingGoodsNumber[$sValue['sku_sn']] = $sValue['count'];
                                }
                            }

                            if (!empty($value['split_number'])) {
                                //查询子父订单拆数量的订单
                                $childOrderGoodsList = $shipOrderModel->where(['goods_sku' => $allGoods, 'split_status' => 1])->where(function ($query) use ($value) {
                                    $query->where(['order_sn' => $value['parent_order_sn']])->whereOr(['parent_order_sn' => $value['parent_order_sn']]);
                                })->select()->toArray();

                                $childOrderGoods = 0;
                                if (!empty($childOrderGoodsList)) {
                                    foreach ($childOrderGoodsList as $cgKey => $cgValue) {
                                        if ($cgValue['order_sn'] != $value['order_sn'] && in_array($cgValue['split_status'], [1, 3])) {
                                            $childOrderGoods += $cgValue['split_number'];
                                        } elseif ($cgValue['split_status'] == 2) {
                                            if ($cgValue['order_sn'] == $value['order_sn']) {
                                                $childOrderGoods += $cgValue['split_number'];
                                            }
                                        }

                                    }
                                }

                                unset($allGoods);
                                if (!empty($childOrderGoods) && !empty($allShippingGoodsNumber[$value['goods_sku']])) {
                                    if ($childOrderGoods == $allShippingGoodsNumber[$value['goods_sku']]) {
                                        $allGoods[] = $value['goods_sku'];
                                        $orderSn[] = $value['parent_order_sn'];
                                    }
                                }
                            } else {
                                if (count($allShippingGoodsNumber) == $allGoodsCount) {
                                    if (!empty($value['parent_order_sn'])) {
                                        $orderSn[] = $value['parent_order_sn'];
                                    } else {
                                        $orderSn[] = $value['order_sn'];
                                    }
                                }
                            }
                        }
                    } else {
                        //如果是拆单或者合单的,有父类订单号也要一并修改父类的状态
                        if (!empty($value['parent_order_sn'])) {
                            $orderSn = [$value['order_sn'], $value['parent_order_sn']];
                        } else {
                            //如果没有父类订单编号,如果是合单的则需要找到合单的其他订单一并修改状态
                            if ($value['split_status'] == 2) {
                                $childOrder = ShipOrder::where(['parent_order_sn' => $value['order_sn'], 'split_status' => 2, 'pay_status' => 2, 'order_status' => 2])->column('order_sn');
                            }
                            $orderSn = [$value['order_sn']];
                            if (!empty($childOrder)) {
                                $orderSn = array_merge_recursive($orderSn, $childOrder);
                            }
                        }
                    }
                } else {
                    //如果是拆单或者合单的,有父类订单号也要一并修改父类的状态
                    if (!empty($value['parent_order_sn'])) {
                        $orderSn = [$value['order_sn'], $value['parent_order_sn']];
                    } else {
                        //如果没有父类订单编号,如果是合单的则需要找到合单的其他订单一并修改状态
                        if ($value['split_status'] == 2) {
                            $childOrder = ShipOrder::where(['parent_order_sn' => $value['order_sn'], 'split_status' => 2, 'pay_status' => 2, 'order_status' => 2])->column('order_sn');
                        }
                        $orderSn = [$value['order_sn']];
                        if (!empty($childOrder)) {
                            $orderSn = array_merge_recursive($orderSn, $childOrder);
                        }
                    }
                }

                if (!empty($orderSn)) {
                    $orderSn = array_unique(array_filter($orderSn));
                    $oSave['shipping_status'] = $changeType;
                    $res = $orderModel->isAutoWriteTimestamp(false)->where(['order_sn' => $orderSn])->save($oSave);
                }

                if (!empty($allGoods)) {
                    $gSave['shipping_status'] = $changeType;
                    $orderGoodsModel->where(['order_sn' => $orderSn, 'sku_sn' => $allGoods])->save($gSave);
                }
            }
            return true;
        });
        return judge($DBRes);

    }

    /**
     * @title  发货订单商品数据汇总面板
     * @param array $data
     * @return mixed
     * @throws \Exception
     */
    public function shippingOrderSummary(array $data)
    {
        $orderSn = $data['order_sn'];
        $sMap[] = ['order_sn', 'in', $orderSn];
        $sMap[] = ['pay_status', '=', 2];
        $shipList = ShipOrder::with(['childOrderSn'])->where(['order_sn' => $orderSn])->select()->toArray();
        $log['requestData'] = $data ?? [];
        $log['orderSn'] = $orderSn;
        $log['shipList'] = $shipList;

        //全部订单总数
        $allNumber = count($shipList);
        //全部商品总份数
        $allGoodsNumber = 0;
        //拆数量的订单总数分组汇总数组
        $splitOrderNumber = [];
        //拆数量的订单数组
        $splitOrder = [];
        //拆数量的订单商品数量分类数组
        $splitOrderGoods = [];
        //拆数量主订单需要正确累加的订单数组
        $noAddCountOrder = [];
        //订单商品数量和信息分类数组
        $orderGoodsNumber = [];
        //需要查询订单信息的order_sn数组
        $orderSns = [];
        //发货订单商品数量超过1的订单
        $overOneGoodsShipOrder = [];

        foreach ($shipList as $key => $value) {
            $goods = explode(',', $value['goods_sku']);
            if (count($goods) > 1) {
                if ($value['split_status'] == 1 && !empty($value['parent_order_sn'])) {
                    $overOneGoodsShipOrder[] = $value['parent_order_sn'];
                } else {
                    $overOneGoodsShipOrder[] = $value['order_sn'];
                }
            }

            if (empty($goods)) {
                unset($shipList[$key]);
                continue;
            }

            //拆数量的情况下这个商品数量要加拆出来的数量,否则就是正常订单的数量. 最终舍弃差数量的订单,将查询到的正常订单的订单号去查询获取订单信息 (但是拆数量的订单为了查询主订单的信息,所以这里会将没有父类订单编号 即原来的主订单号也加入所有订单列表里面,但是加多一个数组标识这个订单不要直接加原来的订单的商品数量,而是要加拆分后的数量)
            if ($value['split_status'] == 1 && !empty($value['split_number']) && (count($goods) == 1)) {
                $splitOrderGoods[$value['goods_sku']] = $value['split_number'];
                if (!empty($value['parent_order_sn']) && !isset($splitOrderNumber[$value['parent_order_sn']])) {
                    $splitOrderNumber[$value['goods_sku']] = 0;
                }
                if (!empty($value['parent_order_sn'])) {
                    $splitOrderNumber[$value['goods_sku']] += 1;
                    $splitOrder[$value['parent_order_sn']][] = $value['order_sn'];
                } else {
                    $orderSns[] = $value['order_sn'];
                    $noAddCountOrder[$value['order_sn']] = $value['split_number'];
                }
                unset($shipList[$key]);
                continue;
            } else {
                foreach ($goods as $cKey => $cValue) {
                    $allGoods[] = $cValue;
                }
                if ($value['split_status'] == 1 && !empty($value['parent_order_sn'])) {
                    $orderSns[] = $value['parent_order_sn'];
                } elseif ($value['split_status'] == 2 && !empty($value['childOrderSn'])) {
                    $orderSns[] = $value['order_sn'];
                    $childOrderSn = array_column($value['childOrderSn'], 'order_sn');
                    if (!empty($childOrderSn)) {
                        foreach ($childOrderSn as $cKey => $cValue) {
                            $orderSns[] = $cValue;
                        }
                    }
                } else {
                    $orderSns[] = $value['order_sn'];
                }
            }
        }
        $log['shipListTwo'] = $shipList ?? [];
        $log['overOneGoodsShipOrder'] = $overOneGoodsShipOrder ?? [];
        $log['noAddCountOrder'] = $noAddCountOrder ?? [];

        if (!empty($allGoods)) {
            $allGoods = array_unique(array_filter($allGoods));
            $allOrderSn = array_unique(array_filter($orderSns));
            $log['allGoods'] = $allGoods ?? [];
            $log['allOrderSn'] = $allOrderSn ?? [];

            $orderGoods = OrderGoods::with(['goodsSku' => function ($query) {
                $query->field('goods_sn,sku_sn,title,image,sub_title,specs,sale_price');
            }])->where(['order_sn' => $allOrderSn, 'sku_sn' => $allGoods, 'status' => 1])->field('order_sn,goods_sn,sku_sn,count,status')->select()->toArray();
            $log['orderGoods'] = $orderGoods ?? [];

            $allOrderGoodsTotal = [];
            $allOrderGoodsNumber = [];
            $spuDisOrderNumber = [];
            $mergerChildOrder = [];
            if (!empty($orderGoods)) {
                if (count($orderGoods) != count($shipList)) {
                    foreach ($orderGoods as $key => $value) {
                        $allOrderGoods[$value['order_sn']][] = $value;
                    }
                    //获取每笔订单中同一个SPU不同SKU的数量,因为下面的订单统计会将一个订单包含相同SPU的不同商品的订单数算重复,比如一笔订单有两个相同SPU的不同SKU的商品,下面订单数统计会是2,其实应该要是1,此处就是获取需要扣去的每个SPU的订单数
                    //之所以分三次重组数据是为了后续这三个数组有各自独立的作用,做保留拓展的能力
                    if (!empty($allOrderGoods)) {
                        foreach ($allOrderGoods as $key => $value) {
                            foreach ($value as $oKey => $oValue) {
                                $allOrderGoodsTotal[$oValue['order_sn']][$oValue['goods_sn']][] = $oValue;
                            }
                        }
                    }
                    if (!empty($allOrderGoodsTotal)) {
                        foreach ($allOrderGoodsTotal as $key => $value) {
                            foreach ($value as $gKey => $gValue) {
                                $allOrderGoodsNumber[$key][$gKey] = count($gValue);
                            }
                        }
                    }

                    if (!empty($allOrderGoodsNumber)) {
                        //剔除本身没有多商品的发货订单
                        if (!empty($overOneGoodsShipOrder)) {
                            foreach ($allOrderGoodsNumber as $key => $value) {
                                if (!in_array($key, $overOneGoodsShipOrder)) {
                                    unset($allOrderGoodsNumber[$key]);
                                }
                            }
                        }

                        foreach ($allOrderGoodsNumber as $key => $value) {
                            foreach ($value as $gKey => $gValue) {
                                if ($gValue > 1) {
                                    if (!isset($spuDisOrderNumber[$gKey])) {
                                        $spuDisOrderNumber[$gKey] = 0;
                                    }
                                    $spuDisOrderNumber[$gKey] += ($gValue - 1);
                                }
                            }
                        }
                    }
                    //处理发货订单对应的商品列表,如果是拆单的订单需要剔除部分重复的订单商品列表,如果是合单的子订单需要补回部分缺失的子订单商品列表
                    $mergerParentGoodsSn = [];
                    $dealShipList = $shipList;
                    foreach ($dealShipList as $key => $value) {
                        $goodsSku = explode(',', $value['goods_sku']);
                        if (!empty($goodsSku)) {
                            foreach ($goodsSku as $gKey => $gValue) {
                                //正常订单或拆单订单
                                if ($value['split_status'] == 1 && !empty($value['parent_order_sn'])) {
                                    $realOrderSn = $value['parent_order_sn'];
                                } else {
                                    $realOrderSn = $value['order_sn'];
                                }
                                if (!empty($allOrderGoods[$realOrderSn])) {
                                    foreach ($allOrderGoods[$realOrderSn] as $ogKey => $ogValue) {
                                        if ($gValue == $ogValue['sku_sn']) {
                                            $allOrder[] = $ogValue;
                                            //剔除当前订单的商品数组,防止一个发货订单中有两个商品,同个SPU不同SKU的情况,防止重复计算订单数
                                            if ($value['split_status'] == 2 && !empty($value['childOrderSn'])) {
                                                $mergerParentGoodsSn[$value['order_sn']][] = $ogValue['goods_sn'];
                                            }
                                            unset($allOrderGoods[$realOrderSn][$ogKey]);
                                        }
                                    }
                                }

                                //针对合单被合掉的订单(即合单的子订单)商品也要展示出来,但是如果遇到了一个合并订单里面包含了同个SPU不同SKU的情况下,如果剔除多出来的SKU订单数,因为是被合并了,即使不同SKU导出也只能算一个订单
                                if ($value['split_status'] == 2 && !empty($value['childOrderSn'])) {
                                    $childOrderSn = array_column($value['childOrderSn'], 'order_sn');
                                    if (!empty($childOrderSn)) {
                                        foreach ($childOrderSn as $cKey => $cValue) {
                                            if (!empty($allOrderGoods[$cValue])) {
                                                foreach ($allOrderGoods[$cValue] as $ogKey => $ogValue) {
                                                    if ($gValue == $ogValue['sku_sn']) {
                                                        $allOrder[] = $ogValue;
                                                        if (!isset($mergerChildOrder[$cValue][$ogValue['goods_sn']])) {
                                                            if (!empty($mergerParentGoodsSn[$value['order_sn']]) && in_array($ogValue['goods_sn'], $mergerParentGoodsSn[$value['order_sn']])) {
                                                                $mergerChildOrder[$cValue][$ogValue['goods_sn']] = 1;
                                                            } else {
                                                                $mergerChildOrder[$cValue][$ogValue['goods_sn']] = 0;
                                                            }
                                                        }
                                                        $mergerChildOrder[$cValue][$ogValue['goods_sn']] += 1;
                                                        if ((!empty($mergerChildOrder[$cValue][$ogValue['goods_sn']]) && $mergerChildOrder[$cValue][$ogValue['goods_sn']]) > 1) {
                                                            if (!isset($spuDisOrderNumber[$ogValue['goods_sn']])) {
                                                                $spuDisOrderNumber[$ogValue['goods_sn']] = 0;
                                                            }
                                                            $spuDisOrderNumber[$ogValue['goods_sn']] += 1;
                                                        }
                                                        //剔除当前订单的商品数组,防止一个发货订单中有两个商品,同个SPU不同SKU的情况,防止重复计算订单数
                                                        unset($allOrderGoods[$cValue][$ogKey]);
                                                    }
                                                }
                                            }
                                        }
                                    }
                                }
                            }
                        }
                    }
                    $log['mergerChildOrder'] = $mergerChildOrder ?? [];
                    $log['spuDisOrderNumber'] = $spuDisOrderNumber ?? [];
                    $log['allOrderGoodsNumber'] = $allOrderGoodsNumber ?? [];
                    $log['allOrderGoodsTotal'] = $allOrderGoodsTotal ?? [];
                    $log['allOrder'] = $allOrder ?? [];

                    $orderGoods = $allOrder ?? [];
//                    $orderGoods = [];
                    if (empty($orderGoods)) {
                        (new Log())->record($log, 'debug');
                        throw new ShipException(['msg' => '非常抱歉, 汇总面板数据出现了某些错误, 请尝试刷新页面同步后重新导出订单~如还有错误请联系工程师']);
                    }
                }
            }

            if (!empty($orderGoods)) {
                //剔除一些本来不存在导出订单里面的商品
//                foreach ($shipList as $key => $value) {
//                    foreach ($orderGoods as $gKey => $gValue) {
//                        if (empty($value['parent_order_sn']) && $value['order_sn'] == $gValue['order_sn']) {
//                            if (!empty(explode(',', $value['goods_sku']))) {
//                                if (!in_array($gValue['sku_sn'], explode(',', $value['goods_sku']))) {
//                                    unset($orderGoods[$gKey]);
//                                    continue;
//                                }
//                            }
//                        }
//                        if (!empty($value['parent_order_sn']) && $value['parent_order_sn'] == $gValue['order_sn']) {
//                            if (!empty(explode(',', $value['goods_sku']))) {
//                                if (!in_array($gValue['sku_sn'], explode(',', $value['goods_sku']))) {
//                                    unset($orderGoods[$gKey]);
//                                    continue;
//                                }
//                            }
//                        }
//                    }
//                }

                foreach ($orderGoods as $key => $value) {
                    if (!isset($orderGoodsNumber[$value['sku_sn']])) {
                        $orderGoodsNumber[$value['sku_sn']] = [];
                        $orderGoodsNumber[$value['sku_sn']]['number'] = 0;
                    }
                    $orderGoodsNumber[$value['sku_sn']]['goodsInfo'] = $value['goodsSku'];
                    $orderGoodsNumber[$value['sku_sn']]['sku_sn'] = $value['sku_sn'];
                    $orderGoodsNumber[$value['sku_sn']]['goods_sn'] = $value['goods_sn'];
                    if (!empty($noAddCountOrder[$value['order_sn']])) {
                        $orderGoodsNumber[$value['sku_sn']]['number'] += $noAddCountOrder[$value['order_sn']] ?? 0;
                    } else {
                        $orderGoodsNumber[$value['sku_sn']]['number'] += $value['count'];
                    }
                    $orderGoodsNumber[$value['sku_sn']]['order_sn'][] = $value['order_sn'];
                }
            }
            //如果拆分数量的订单数组存在,则累加回订单列表,订单数量和商品数量做展示用
            if (!empty($orderGoodsNumber)) {
                foreach ($orderGoodsNumber as $key => $value) {
                    $orderGoodsNumber[$key]['order_sn'] = array_unique(array_filter($value['order_sn']));
                    $orderGoodsNumber[$key]['order_number'] = count($orderGoodsNumber[$key]['order_sn']);
                }
                if (!empty($splitOrderGoods)) {
                    foreach ($orderGoodsNumber as $key => $value) {
                        foreach ($splitOrderGoods as $cKey => $cValue) {
                            if ($value['sku_sn'] == $cKey) {
                                $orderGoodsNumber[$key]['number'] += $cValue ?? 0;
                                foreach ($value['order_sn'] as $oKey => $oValue) {
                                    if (!empty(($splitOrder[$oValue]))) {
                                        $orderGoodsNumber[$key]['order_sn'] = array_merge_recursive($value['order_sn'], $splitOrder[$oValue]);
                                    }
                                }
                                $orderGoodsNumber[$key]['order_number'] = count($orderGoodsNumber[$key]['order_sn']);
                            }
                        }
                    }
                }
                //统计全部商品总份数
                if (!empty($orderGoodsNumber)) {
                    $allGoodsNumber = array_sum(array_column($orderGoodsNumber, 'number'));
                }
            }
            if (!empty($orderGoodsNumber)) {
                foreach ($orderGoodsNumber as $key => $value) {
                    $orderGoodsSpu[$value['goods_sn']]['sku'][] = $value;
                }
                //获取商品列表,已用来展示累计销售情况
                $allGoodsSpuSn = array_unique(array_filter(array_column($orderGoodsNumber, 'goods_sn')));
                $goodsList = (new GoodsSpu())->list(['goods_sn' => $allGoodsSpuSn, 'searType' => 1, 'needAllSkuOrderNumber' => true])['list'] ?? [];

                if (!empty($orderGoodsSpu)) {
                    $allSpu = GoodsSpu::where(['goods_sn' => array_column($orderGoodsNumber, 'goods_sn')])->field('goods_sn,goods_code,main_image,title,sub_title,status')->select()->toArray();
                    if (!empty($allSpu)) {
                        foreach ($allSpu as $key => $value) {
                            $spuInfo[$value['goods_sn']] = $value;
                        }
                        foreach ($orderGoodsSpu as $key => $value) {
                            $orderGoodsSpu[$key]['info'] = $spuInfo[$key] ?? [];
                            if (!isset($orderGoodsSpu[$key]['info']['number'])) {
                                $orderGoodsSpu[$key]['info']['number'] = 0;
                            }
                            if (!isset($orderGoodsSpu[$key]['info']['order_number'])) {
                                $orderGoodsSpu[$key]['info']['order_number'] = 0;
                            }
                            foreach ($value['sku'] as $sKey => $sValue) {
                                $orderGoodsSpu[$key]['info']['number'] += $sValue['number'];
                                $orderGoodsSpu[$key]['info']['order_number'] += $sValue['order_number'];
                            }
                        }
                    }

                    //扣去同一个订单相同SPU不同SKU造成的订单数重复计算的数量
                    if (!empty($spuDisOrderNumber)) {
                        foreach ($orderGoodsSpu as $key => $value) {
                            if (!empty($value['info']) && !empty($value['info']['goods_sn'])) {
                                if (!empty($spuDisOrderNumber[$value['info']['goods_sn']])) {
                                    $orderGoodsSpu[$key]['info']['order_number'] -= $spuDisOrderNumber[$value['info']['goods_sn']] ?? 0;
                                }
                            }
                        }
                    }

                    if (!empty($goodsList)) {
                        foreach ($orderGoodsSpu as $key => $value) {
                            foreach ($goodsList as $gKey => $gValue) {
                                if ($value['info']['goods_sn'] == $gValue['goods_sn']) {
                                    $orderGoodsSpu[$key]['original'] = $gValue;
                                }
                            }
                        }
                    }


                }
            }
        }

        $finally['orderTotal'] = $allNumber ?? 0;
        $finally['goodsTotal'] = $allGoodsNumber ?? 0;
//        $finally['goods'] = !empty($orderGoodsNumber) ? array_values($orderGoodsNumber) : [];
        $finally['goods'] = !empty($orderGoodsSpu) ? array_values($orderGoodsSpu) : [];
        return $finally;
    }
}