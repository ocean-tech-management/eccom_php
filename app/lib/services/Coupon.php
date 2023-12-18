<?php
// +----------------------------------------------------------------------
// |[ 文档说明: 优惠券模块Service]
// +----------------------------------------------------------------------
// | Copyright (c) 2018~2024 http://www.mlhcmk.com All rights reserved.
// +----------------------------------------------------------------------


namespace app\lib\services;


use app\lib\exceptions\CouponException;
use app\lib\exceptions\UserException;
use app\lib\models\CouponDeliver;
use app\lib\models\CouponUserType;
use app\lib\models\GoodsSku;
use app\lib\models\GoodsSkuVdc;
use app\lib\models\GoodsSpu;
use app\lib\models\Member;
use app\lib\models\Order;
use app\lib\models\User;
use app\lib\models\UserCoupon;
use app\lib\validates\Coupon as CouponValidate;
use app\lib\models\Coupon as CouponModel;
use think\facade\Db;

class Coupon
{
    private $belong = 1;

    /**
     * @title  创建优惠券
     * @param array $data
     * @return mixed
     */
    public function create(array $data)
    {
        $data['belong_type'] = $data['belong_type'] ?? $this->belong;
        $res = (new CouponModel())->new($data);
        //新增成功后向商城系统同步此条数据--尚未完成
        return $res;
    }

    /**
     * @title  编辑优惠券
     * @param array $data
     * @return CouponModel
     */
    public function edit(array $data)
    {
        $data['belong_type'] = $data['belong_type'] ?? $this->belong;
        $res = (new CouponModel())->edit($data);
        //编辑成功后向商城系统同步此条数据--尚未完成
        return $res;
    }

    /**
     * @title  修改优惠券发券数量
     * @param array $data
     * @return mixed
     */
    public function updateCouponNumber(array $data)
    {
        $code = $data['code'] ?? null;
        $number = $data['number'] ?? 0;
        if (empty($code)) {
            throw new CouponException(['msg' => '缺少优惠券唯一编码']);
        }
        if (empty($number) || !is_numeric($number)) {
            throw new CouponException(['msg' => '数量一定要为数字哦,不能为0哟']);
        }
        $res = (new CouponModel())->updateNumber($data);
        return $res;
    }

    /**
     * @title  用户领取优惠券
     * @param array $data
     * @return mixed
     * @throws \Exception
     */
    public function userReceive(array $data)
    {
        $coupon = (new CouponModel())->infoForInternal($data['code']);
        $aMemberInfo = (new Member())->getUserLevel($data['uid']);
        $aMemberType = (new CouponUserType())->memberTypeList();
        $isNewUser = (new Order())->checkUserOrder($data['uid']);
        $nUserCoupon = (new UserCoupon())->couponNumberByCouponCode($data['uid'], $data['code']);

        //判断优惠券是否存在
        if (empty($coupon)) {
            if (empty($data['notThrowError'])) {
                throw new CouponException(['errorCode' => 1200110]);
            } else {
                if (empty($data['needMsg'])) {
                    return false;
                } else {
                    return config('exceptionCode.CouponException')[1200110];
                }
            }
        }
        //判断用户类型(会员)
        if (in_array($coupon['take_user_type'], $aMemberType) && empty($aMemberInfo)) {
            if (empty($data['notThrowError'])) {
                throw new CouponException(['errorCode' => 1200106]);
            } else {
                if (empty($data['needMsg'])) {
                    return false;
                } else {
                    return config('exceptionCode.CouponException')[1200106];
                }
            }
        }
        //判断用户类型(新人)
        if (($coupon['take_user_type'] == 2) && !empty($isNewUser)) {
            if (empty($data['notThrowError'])) {
                throw new CouponException(['errorCode' => 1200107]);
            } else {
                if (empty($data['needMsg'])) {
                    return false;
                } else {
                    return config('exceptionCode.CouponException')[1200107];
                }
            }
        }
        //判断用户领取数量是否超过上限
        if ($nUserCoupon >= $coupon['take_limit']) {
            if (empty($data['notThrowError'])) {
                throw new CouponException(['errorCode' => 1200108]);
            } else {
                if (empty($data['needMsg'])) {
                    return false;
                } else {
                    return config('exceptionCode.CouponException')[1200108];
                }
            }
        }
        //判断领取时间是在合法
        if (strtotime($coupon['start_time']) > time() || strtotime($coupon['end_time']) < time()) {
            if (empty($data['notThrowError'])) {
                throw new CouponException(['errorCode' => 1200109]);
            } else {
                if (empty($data['needMsg'])) {
                    return false;
                } else {
                    return config('exceptionCode.CouponException')[1200109];
                }
            }
        }
        //判断是否可以领取
        if ($coupon['valid_status'] != 1) {
            if (empty($data['notThrowError'])) {
                throw new CouponException(['errorCode' => 1200111]);
            } else {
                if (empty($data['needMsg'])) {
                    return false;
                } else {
                    return config('exceptionCode.CouponException')[1200111];
                }
            }
        }
        //判断领取数量是否超过上限
//        if($coupon['take_count'] >= $coupon['number']){
//            throw new CouponException(['errorCode'=>1200110]);
//        }
        if (($coupon['number'] - $coupon['take_count'] ?? 0) <= 0) {
            if (empty($data['notThrowError'])) {
                throw new CouponException(['errorCode' => 1200110]);
            } else {
                if (empty($data['needMsg'])) {
                    return false;
                } else {
                    return config('exceptionCode.CouponException')[1200110];
                }
            }
        }

        $reRes = (new UserCoupon())->new(['code' => $data['code'], 'uid' => $data['uid'], 'receive_type' => $data['receive_type'] ?? 1]);
        return $reRes;
    }


    /**
     * @title  订单模块->获取用户优惠券列表
     * @param array $data 关键信息
     * @return array
     * @throws \Exception
     */
    public function userCouponList(array $data): array
    {
        //筛选不可使用的优惠券
        $aCoupons = $this->filterNotBeUsedCoupon($data);
        $aCoupon = $aCoupons['aCoupon'];
        $aUnavailable = $aCoupons['aUnavailable'];

        //按最高优惠排序优惠券
        $aCoupon = $this->calculateDisPrice(['aCoupon' => $aCoupon, 'order_amount' => $data['order_amount'], 'goods' => $data['goods'] ?? [], 'uid' => $data['uid'] ?? []]);

        if (!empty($aUnavailable)) {
            $aMerge = array_merge_recursive($aCoupon, $aUnavailable);
        } else {
            $aMerge = $aCoupon;
        }

        return $aMerge;
    }

    /**
     * @title  系统自动选择最优优惠券
     * @param array $data
     * @return array|mixed
     * @throws \Exception
     */
    public function systemChooseCoupon(array $data)
    {
        //用户可用优惠券列表
        $aCouponList = $this->userCouponList($data);
        if (empty($aCouponList)) {
            return [];
        }

        $aChoose = [];
        $aNoChoose = [];
        $aOverlay = [];
        $aAllDis = [];
        $aOnlyCoupon = [];
        $aMoreCoupon = [];
        $aOnlyCoupons = [];
        $allDis = 0.00;
        $onlyDisPrice = 0.00;
        $allMoreDisPrice = 0.00;
        $onlyDisType = null;
        $chooseType = 1;

        //将不可叠加券和可叠加券分开计算
        foreach ($aCouponList as $key => &$value) {
            if ($value['use_status'] == 1) {
                if ($value['select_type'] == 1) {
                    $aOnlyCoupons[$key]['dis_price'] = $value['dis_price'];
                    $aOnlyCoupons[$key]['coupon_code'] = $value['coupon_code'];
                    $aOnlyCoupons[$key]['coupon_uc_code'] = $value['uc_code'];
                    $aOnlyCoupons[$key]['coupon_name'] = $value['coupon']['name'];
                    $aOnlyCoupons[$key]['coupon_type'] = $value['coupon']['type'];
                    $aOnlyCoupons[$key]['coupon_used'] = $value['coupon']['used'];
                    $aOnlyCoupons[$key]['coupon_condition'] = $value['coupon']['with_condition'];
                    $aOnlyCoupons[$key]['coupon_goods_sn'] = $value['coupon']['with_goods_sn'];
                    $aOnlyCoupons[$key]['coupon_category_code'] = $value['coupon']['with_category'];
                    $aOnlyCoupons[$key]['select_type'] = $value['select_type'];
                } elseif ($value['select_type'] == 2) {
                    $aMoreCoupon[$key]['dis_price'] = $value['dis_price'];
                    $aMoreCoupon[$key]['coupon_code'] = $value['coupon_code'];
                    $aMoreCoupon[$key]['coupon_uc_code'] = $value['uc_code'];
                    $aMoreCoupon[$key]['coupon_name'] = $value['coupon']['name'];
                    $aMoreCoupon[$key]['coupon_type'] = $value['coupon']['type'];
                    $aMoreCoupon[$key]['coupon_used'] = $value['coupon']['used'];
                    $aMoreCoupon[$key]['coupon_condition'] = $value['coupon']['with_condition'];
                    $aMoreCoupon[$key]['coupon_goods_sn'] = $value['coupon']['with_goods_sn'];
                    $aMoreCoupon[$key]['coupon_category_code'] = $value['coupon']['with_category'];
                    $aMoreCoupon[$key]['select_type'] = $value['select_type'];;
                }
            }
        }

        /*------计算单张券的最高优惠---start-----------*/
        //选择单选的最高优惠
        if (!empty($aOnlyCoupons)) {
            array_multisort(array_column($aOnlyCoupons, 'dis_price'), SORT_DESC, $aOnlyCoupons);
            $onlyDisPrice = $aOnlyCoupons[0]['dis_price'];
            $onlyDisType = $aOnlyCoupons[0]['coupon_type'];
            //仅选择第一个最高优惠的优惠券
            $aOnlyCoupon[] = $aOnlyCoupons[0];
        }
        /*------计算单张券的最高优惠---end-----------*/

        /*------计算叠加券的最高优惠---start-----------*/
        //计算可多选的券(叠加满减券&无门槛券)的累加优惠金额(数组按照优惠价格从大到小排序,一直往下校验累加)
        //累加可多选的券优惠价格,直到不超过订单价格的最大价格
        if (!empty($aMoreCoupon)) {
            array_multisort(array_column($aMoreCoupon, 'dis_price'), SORT_DESC, $aMoreCoupon);
//            $allMoreDisPrice = $onlyDisPrice;
            $allMoreDisPrice = 0;
            foreach ($aMoreCoupon as $key => $value) {
                //排除折扣券和满减券互斥的可能,优先折扣券(折扣券不可以用叠加满减券)
                if ($onlyDisType == 4 && $value['coupon_type'] == 2) {
                    $aNoChoose[$key] = $value;
                    continue;
                } else {
                    if ($allMoreDisPrice + $value['dis_price'] <= $data['order_amount']) {
                        $allMoreDisPrice += $value['dis_price'];
                        $aChoose[$key] = $value;
                    } else {
                        $aNoChoose[$key] = $value;
                    }

                }
            }

            if (!empty($aChoose)) {
                $aOverlay = array_values($aChoose);
            }
        }

        //判断可叠加的券中那些不能用的单张券优惠金额是否会超过累加的优惠价格,如果超过则替换成了单张券
        if (!empty($aNoChoose)) {
            array_multisort(array_column($aNoChoose, 'dis_price'), SORT_DESC, $aNoChoose);
            if ($aNoChoose[0]['dis_price'] >= $allMoreDisPrice) {
                $allMoreDisPrice = $aNoChoose[0]['dis_price'];
                unset($aOverlay);
                $aOverlay[] = $aNoChoose[0];
                $chooseType = 2;
            }
        }
        /*------计算叠加券的最高优惠---end-----------*/

        //合并两种类型数组结果
        if (!empty($aOnlyCoupon) && empty($aMoreCoupon)) {
            $aAllDis = $aOnlyCoupon;
            $allDis = $onlyDisPrice;
        } elseif (empty($aOnlyCoupon) && !empty($aMoreCoupon)) {
            $aAllDis = $aOverlay;
            $allDis = $allMoreDisPrice;
        } elseif (!empty($aOnlyCoupon) && !empty($aMoreCoupon)) {
//            $aAllDis = $chooseType == 1 ? array_merge_recursive($aOnlyCoupon,$aOverlay) : $aOverlay;
//            $allDis  = $allMoreDisPrice;
            //哪个优惠高选哪个
            if ((string)$onlyDisPrice >= (string)$allMoreDisPrice) {
                $aAllDis = $aOnlyCoupon;
                $allDis = $onlyDisPrice;
            } else {
                $aAllDis = $aOverlay;
                $allDis = $allMoreDisPrice;
            }
        }

        if (empty($aAllDis)) {
            return [];
        } else {
            return ['dis_price' => priceFormat($allDis), 'disArr' => $aAllDis];
        }

    }

    /**
     * @title  用户自行选择优惠券
     * @param array $data
     * @return
     * @throws \Exception
     */
    public function userChooseCoupon(array $data)
    {
        $allDis = 0.00;
        $aAllDis = [];
        $cache = config('cache.systemCacheKey.userCouponList');
        $belong = 1;
        $type = 1;
        $searType = 2;
        $ucCode = implode(',', $data['uc_code'] ?? []);
        $cacheKey = $cache['key'] . trim($data['uid']) . $belong . $type . $searType . 'orderChoose-' . $ucCode;
        //获取用户选择的优惠券详情
        $aCoupons = (new UserCoupon())->getUserListByInternal(['uid' => $data['uid'], 'belong' => $belong, 'type' => $type, 'searType' => $searType, 'uc_code' => $data['uc_code']]);
        $aCoupon = $aCoupons['list'] ?? [];

        //目前需要实付的总金额(扣除积分和会员折扣)
        $orderAllAmount = $data['order_amount'] - ($data['memberDis'] ?? 0);

        //计算累加优惠价格
        if (!empty($aCoupon)) {
            $aCoupon = $this->calculateDisPrice(['aCoupon' => $aCoupon, 'order_amount' => $data['order_amount'], 'goods' => $data['goods'] ?? [], 'uid' => $data['uid'] ?? []]);

            $nowCanUsedNumber = count($aCoupon);
            $nowDis = 0;
            //先判断是否是一张优惠券,如果是,则判断可优惠金额是否超过当前的订单总额,如果是,则优惠金额为订单总额;
            //再判断多张优惠券的情况,将累加累计优惠金额+当前优惠金额的的和跟订单总额对比,如果没超过则继续累加,如果超过则判断是否判断两者之和减去订单总额是否小于当前优惠金额了,如果小于则代表还可以使用部分,修改优惠金额为剩余可优惠部分,如果大于等于则表明这张券没有使用的必要,直接舍去并跳过当前循环进入下一轮直至结束
            foreach ($aCoupon as $key => $value) {
                if ($nowCanUsedNumber == 1) {
                    if ($value['dis_price'] >= $orderAllAmount) {
                        $value['dis_price'] = $orderAllAmount;
                        $allDis += $orderAllAmount;
                        $aAllDis[$key]['dis_price'] = $orderAllAmount;
                    } else {
                        $allDis += $value['dis_price'];
                        $aAllDis[$key]['dis_price'] = $value['dis_price'];
                    }
                } else {
                    $nowAllDis = $nowDis + $value['dis_price'];
                    if (((string)$nowAllDis < (string)$orderAllAmount)) {
                        $allDis += $value['dis_price'];
                        $aAllDis[$key]['dis_price'] = $value['dis_price'];
                        $nowDis += $value['dis_price'];
                    } else {
                        if ((string)($nowAllDis - $orderAllAmount) < (string)($value['dis_price'])) {
                            $partDis = $value['dis_price'] - ($nowAllDis - $orderAllAmount);
                            $allDis += $partDis;
                            $aAllDis[$key]['dis_price'] = $partDis;
                            $nowDis += $partDis;
                        } else {
                            unset($aCoupon[$key]);
                            continue;

                        }
                    }

                }
//                $allDis += $value['dis_price'];
//                $aAllDis[$key]['dis_price'] = $value['dis_price'];
                $aAllDis[$key]['coupon_code'] = $value['coupon_code'];
                $aAllDis[$key]['coupon_uc_code'] = $value['uc_code'];
                $aAllDis[$key]['coupon_name'] = $value['coupon']['name'];
                $aAllDis[$key]['coupon_type'] = $value['coupon']['type'];
                $aAllDis[$key]['coupon_used'] = $value['coupon']['used'];
                $aAllDis[$key]['coupon_condition'] = $value['coupon']['with_condition'];
                $aAllDis[$key]['coupon_goods_sn'] = $value['coupon']['with_goods_sn'];
                $aAllDis[$key]['coupon_category_code'] = $value['coupon']['with_category'];
            }
        }

        if (empty($aAllDis)) {
            return [];
        } else {
            return ['dis_price' => priceFormat($allDis), 'disArr' => $aAllDis];
        }
    }

    /**
     * @title  筛选不可使用的优惠券
     * @param array $data
     * @return array|mixed
     * @throws \Exception
     */
    public function filterNotBeUsedCoupon(array $data)
    {
        $orderAmount = $data['order_amount'];
        $aGoods = $data['goods'];
        $cache = config('cache.systemCacheKey.userCouponList');
        $belong = $this->belong;
        $type = 1;
        $searType = 2;
        $cacheKey = $cache['key'] . trim($data['uid']) . $belong . $type . $searType;
        $map = ['uid' => $data['uid'], 'belong' => $belong, 'type' => $type, 'searType' => $searType];
        if (!empty($data['uc_code'])) {
            $map['uc_code'] = $data['uc_code'];
        }

        //查看用户拥有的有效期内的优惠券
        $aCoupons = (new UserCoupon())->getUserListByInternal($map);
        $aCoupon = $aCoupons['list'] ?? [];
        if (empty($aCoupon)) {
            return ['aCoupon' => [], 'aUnavailable' => []];
        }
        $aUnavailable = [];

        $isNewUser = (new Order())->checkUserOrder($data['uid']);
        $useMember = (new Member())->getUserLevel($data['uid']);
        $aMemberType = (new CouponUserType())->memberTypeList();
        $aOrderGoods = (new GoodsSku())->getInfoByOrderGoods($aGoods);

        foreach ($aGoods as $key => $value) {
            $aGoodsInfo[$value['sku_sn']] = $value;
        }
        $amount = [];
        //筛选掉不可使用的优惠券
        foreach ($aCoupon as $key => $value) {
//            if(!isset($amount[$value['coupon_code']])){
//                $amount[$value['coupon_code']] = 0;
//            }
            $amount[$value['coupon_code']] = 0;
            $coupon = $value['coupon'];
            $aCoupon[$key]['use_status'] = 1;
            $aCoupon[$key]['select_type'] = 1;

            //判断可使用场景和商品是否和订单商品存在符合的交集,存在则可以使用优惠券
            if ($coupon['used'] == 20 || $coupon['used'] == 30) {
                $inCateGory = false;
                $inGoods = false;
                foreach ($aOrderGoods as $goodsKey => $goodsValue) {
                    //判断可使用分类与商品分类是否存在交集
                    if ($coupon['used'] == 20 && !empty(array_intersect(explode(',', $coupon['with_category']), $goodsValue['category_code']))) {
                        $inCateGory = true;
                        break;
                    }
                    //判断可使用商品是否与订单商品存在交集
                    if ($coupon['used'] == 30 && !empty(array_intersect(explode(',', $coupon['with_goods_sn']), [$goodsValue['sku_sn']]))) {
                        $inGoods = true;
                        break;
                    }
                }
                if (!$inCateGory && !$inGoods) {
//                    if(!$inCateGory){
                    $aCoupon[$key]['use_status'] = 2;
                    $aCoupon[$key]['invalid_reason'] = '不符合的类目或商品';
                    $aUnavailable[] = $aCoupon[$key];
                    unset($aCoupon[$key]);
                    continue;
//                    }
                }

                //重新判断一个专属券包含了哪些商品,需要累加总价计算使用门槛
                $amountGoods = [];
                foreach ($aOrderGoods as $gKeys => $gValues) {
                    //判断可使用分类与商品分类是否存在交集
                    if ($coupon['used'] == 20 && !empty(array_intersect(explode(',', $coupon['with_category']), $gValues['category_code']))) {
                        $amountGoods[$gValues['sku_sn']] = $gValues['sku_sn'];
                    }
                    //判断可使用商品是否与订单商品存在交集
                    if ($coupon['used'] == 30 && !empty(array_intersect(explode(',', $coupon['with_goods_sn']), [$gValues['sku_sn']]))) {
                        $amountGoods[$gValues['sku_sn']] = $gValues['sku_sn'];
                    }
                }

                //获取专属类目或商品的总价,判断使用门槛
                if (!empty($amountGoods)) {
                    $amountGoods = array_unique(array_filter($amountGoods));
                    if (!empty($amountGoods)) {
                        foreach ($amountGoods as $aKey => $aValue) {
                            if (!empty($aGoodsInfo[$aValue])) {
                                $amount[$value['coupon_code']] += ($aGoodsInfo[$aValue]['price'] ?? 0) * ($aGoodsInfo[$aValue]['number'] ?? 1);
                            }
                        }
                    }
                }
            }

            //判断专属商品或类目使用金额门槛
            if (($coupon['used'] == 30 || $coupon['used'] == 20)) {
                if (!empty($coupon['with_amount']) && $coupon['with_amount'] > ($amount[$value['coupon_code']] ?? 0)) {
                    $aCoupon[$key]['use_status'] = 2;
                    $aCoupon[$key]['invalid_reason'] = '未达到指定商品或类目使用金额';
                    $aUnavailable[] = $aCoupon[$key];
                    unset($aCoupon[$key]);
                    continue;
                }
            } else {
                //判断使用金额门槛
                if (!empty($coupon['with_amount']) && $coupon['with_amount'] > $orderAmount) {
                    $aCoupon[$key]['use_status'] = 2;
                    $aCoupon[$key]['invalid_reason'] = '未达到使用金额';
                    $aUnavailable[] = $aCoupon[$key];
                    unset($aCoupon[$key]);
                    continue;
                }
            }

            //判断使用用户类型(新人)
            if ($coupon['take_user_type'] == 2 && !empty($isNewUser)) {
                $aCoupon[$key]['use_status'] = 2;
                $aCoupon[$key]['invalid_reason'] = '非新人用户,不可使用';
                $aUnavailable[] = $aCoupon[$key];
                unset($aCoupon[$key]);
                continue;
            }
            //判断使用用户类型(会员)
            if (in_array($coupon['take_user_type'], $aMemberType) && empty($useMember)) {
                $aCoupon[$key]['use_status'] = 2;
                $aCoupon[$key]['invalid_reason'] = '非指定会员用户,不可使用';
                $aUnavailable[] = $aCoupon[$key];
                unset($aCoupon[$key]);
                continue;
            }
        }

        return ['aCoupon' => $aCoupon, 'aUnavailable' => $aUnavailable];
    }

    /**
     * @title  计算每张券对应的优惠价格
     * @param array $data
     * @return array|mixed
     */
    public function calculateDisPrice(array $data)
    {
        $aCoupon = $data['aCoupon'] ?? [];
        $orderAmount = $data['order_amount'];
        $goods = $data['goods'] ?? [];

        //按最高优惠排序优惠券
        if (!empty($aCoupon)) {
            foreach ($aCoupon as $key => $value) {
                $useCoupon = $value['coupon'];
                //获取不同场景的券对应的商品总价,然后对应不同类型的券需要用不同的商品总价作为该券可使用的金额
                switch ($value['coupon_used']) {
                    case 20:
                        if (!isset($couponCategoryAmount[$value['coupon_code']])) {
                            $couponCategoryAmount[$value['coupon_code']] = 0;
                        }
                        if (!empty($goods) && !empty($value['coupon']) && !empty($value['coupon']['with_category'])) {
                            foreach ($goods as $gKey => $gValue) {
                                if (in_array($gValue['category_code'], explode(',', $value['coupon']['with_category']))) {
                                    $couponCategoryAmount[$value['coupon_code']] += $gValue['total_price'] ?? 0;
                                }
                            }
                        }
                        break;
                    case 30:
                        if (!isset($couponGoodsAmount[$value['coupon_code']])) {
                            $couponGoodsAmount[$value['coupon_code']] = 0;
                        }

                        if (!empty($goods) && !empty($value['coupon']) && !empty($value['coupon']['with_goods_sn'])) {

                            foreach ($goods as $gKey => $gValue) {
                                if (in_array($gValue['sku_sn'], explode(',', $value['coupon']['with_goods_sn']))) {

                                    $couponGoodsAmount[$value['coupon_code']] += $gValue['total_price'] ?? 0;
                                }
                            }
                        }
                        break;
                }

                //单独计算折扣券,优惠券三个场景下都是直接抵扣,最后再计算是否超过优惠上限
                if ($useCoupon['type'] == 4 && !empty($useCoupon['with_discount'])) {
                    $disPrice = 0;
                    $nowPrice = $orderAmount;

                    switch ($value['coupon_used']) {
                        case 10:
                        case 20:
                        case 30:
                            $disPrice = priceFormat($orderAmount * (1 - $useCoupon['with_discount']));
                            $nowPrice = priceFormat($orderAmount * $useCoupon['with_discount']);
                            break;
//                            注释的代码为错误代码
//                        case 20:
//                            //计算优惠金额
//                            if (!empty($couponCategoryAmount ?? [])) {
//                                if ($couponCategoryAmount[$value['coupon_code']] <= $useCoupon['used_amount']) {
//                                    $disPrice = priceFormat($couponCategoryAmount[$value['coupon_code']] * (1 - $useCoupon['with_discount']));;
//                                    $nowPrice = priceFormat($couponCategoryAmount[$value['coupon_code']] * $useCoupon['with_discount']);
//                                } else {
//                                    $disPrice = $couponCategoryAmount[$value['coupon_code']];
//                                    $nowPrice = number_format(0, 2);
//                                }
//                            }
//                            break;
//                        case 30:
//
//                            //计算优惠金额
//                            if (!empty($couponGoodsAmount ?? [])) {
//                                if ($couponGoodsAmount[$value['coupon_code']] <= $useCoupon['used_amount']) {
//                                    $disPrice = priceFormat($couponGoodsAmount[$value['coupon_code']] * (1 - $useCoupon['with_discount']));;
//                                    $nowPrice = priceFormat($couponGoodsAmount[$value['coupon_code']] * $useCoupon['with_discount']);
//                                } else {
//                                    $disPrice = $couponGoodsAmount[$value['coupon_code']];
//                                    $nowPrice = number_format(0, 2);
//                                }
//                            }
//                            break;
                    }

                    if ($disPrice >= $useCoupon['used_amount']) {
                        $disPrice = $useCoupon['used_amount'];
                    }
                    $aCoupon[$key]['dis_price'] = $disPrice;
                    $aCoupon[$key]['now_price'] = $nowPrice;
                } else {
                    //分不同使用场景计算不同不同的可优惠金额,如果是指定类目和指定商品,优惠的金额最高只能为符合的指定类目或指定商品的总价
                    switch ($value['coupon_used']) {
                        case 10:
                            //计算优惠金额
                            if ($orderAmount <= $useCoupon['used_amount']) {
                                $aCoupon[$key]['dis_price'] = $orderAmount;
                                $aCoupon[$key]['now_price'] = number_format(0, 2);
                            } else {
                                $aCoupon[$key]['dis_price'] = $useCoupon['used_amount'];
                                $aCoupon[$key]['now_price'] = priceFormat($orderAmount - $useCoupon['used_amount']);
                            }
                            break;
                        case 20:
                            //计算优惠金额
                            if ($couponCategoryAmount[$value['coupon_code']] <= $useCoupon['used_amount']) {
                                $aCoupon[$key]['dis_price'] = $couponCategoryAmount[$value['coupon_code']];
                                $aCoupon[$key]['now_price'] = number_format(0, 2);
                            } else {
                                $aCoupon[$key]['dis_price'] = $useCoupon['used_amount'];
                                $aCoupon[$key]['now_price'] = priceFormat($orderAmount - $useCoupon['used_amount']);
                            }
                            break;
                        case 30:
                            //计算优惠金额
                            if ($couponGoodsAmount[$value['coupon_code']] <= $useCoupon['used_amount']) {
                                $aCoupon[$key]['dis_price'] = $couponGoodsAmount[$value['coupon_code']];
                                $aCoupon[$key]['now_price'] = number_format(0, 2);
                            } else {
                                $aCoupon[$key]['dis_price'] = $useCoupon['used_amount'];
                                $aCoupon[$key]['now_price'] = priceFormat($orderAmount - $useCoupon['used_amount']);
                            }
                            break;
                    }
                }

                //如果是无条件优惠券或叠加满减券,则可以多选
                if ($useCoupon['type'] == 2 || $useCoupon['type'] == 3) {
                    $aCoupon[$key]['select_type'] = 2;
                } else {
                    $aCoupon[$key]['select_type'] = 1;
                }

            }
            array_multisort(array_column($aCoupon, 'dis_price'), SORT_DESC, $aCoupon);
        }

        return $aCoupon;
    }

    /**
     * @title  检查优惠券合法性
     * @param array $data
     * @return bool
     * @throws \Exception
     */
    public function checkCouponLicit(array $data)
    {
        //筛选不可使用的优惠券
        $aCoupons = $this->filterNotBeUsedCoupon($data);
        $aCoupon = $aCoupons['aCoupon'];

        if (count($aCoupon) != count($data['uc_code'])) {
            throw new CouponException(['errorCode' => 1200103]);
        }

        //满减叠加券和无门槛券可叠加
        $canMoreCouponsType = [2, 3];
        //排除是否存在互斥券或不可叠加的券
        if (!empty($aCoupon)) {
            //获取每种券类型的数量
            $aCouponTypeNumber = [];
            foreach ($aCoupon as $key => $value) {
                if (!isset($aCouponTypeNumber[$value['coupon_type']])) {
                    $aCouponTypeNumber[$value['coupon_type']] = 0;
                }
                $aCouponTypeNumber[$value['coupon_type']] += 1;
            }

            if (!empty($aCouponTypeNumber)) {
                //判断不可叠加类型的券(除了满减叠加券和无门槛券以外的类型)使用数量是否超出了
                foreach ($aCouponTypeNumber as $key => $value) {
                    if (!in_array($key, $canMoreCouponsType) && intval($value) > 1) {
                        throw new CouponException(['errorCode' => 1200111]);
                    }
                }
            }

            //排除优惠券类型是否存在互斥券
            $aCouponType = array_unique(array_column($aCoupon, 'coupon_type'));
            $couponTypeNumber = count($aCouponType);
            if ($couponTypeNumber != 1) {
                if ($couponTypeNumber == 2 && in_array(2, $aCouponType) && in_array(3, $aCouponType)) {
                    $canPass = true;
                } else {
                    throw new CouponException(['errorCode' => 1200104]);
//                    //折扣券和满减券不可共用
//                    if(in_array(4,$aCouponType) && in_array(2,$aCouponType)){
//                        throw new CouponException(['msg'=>'满减叠加券和折扣券不可公用哟~']);
//                    }
                }
            }

        }
        if (empty($aCoupon)) {
            throw new CouponException(['errorCode' => 12001]);
        }

        return true;
    }

    /**
     * @title  用户使用优惠券后的系统操作
     * @param array $ucCode
     * @return void
     */
    public function userUseCoupon(array $ucCode)
    {
        $res = Db::transaction(function () use ($ucCode) {
            $save['valid_status'] = 3;
            $save['use_time'] = time();
            $aCoupon = UserCoupon::where(['uc_code' => $ucCode, 'status' => 1])->column('coupon_code');
            //改变用户优惠券状态
            $res = UserCoupon::update($save, ['uc_code' => $ucCode, 'status' => 1]);
            //改变优惠券使用人数
            Db::name('coupon')->where(['code' => $aCoupon, 'status' => 1])->inc('used_count', 1)->update();
            return $res;
        });

        return $res;

    }

    /**
     * @title  系统派券
     * @param array $data
     * @return array|mixed
     * @throws \Exception
     * @remark 原理是用帮用户领券,规则需遵守优惠券规则
     */
    public function systemDeliverCoupon(array $data)
    {
        $couponUser = $data['user'];
        $coupon = $data['coupon'];
        $adminInfo = $data['adminInfo'] ?? [];
        $type = $data['type'] ?? 1;
        $failCouponMsg = '';
        $userInfo = [];
        $receiveRes = [];

        $userList = array_unique(array_filter(array_keys($data['user'])));
        $userInfos = User::where(['uid' => $userList, 'status' => [1]])->field('uid,name,phone')->select()->toArray();
        if (count($userInfos) != count($userList)) {
            throw new UserException(['msg' => '指定用户存在非法用户,仅支持派券给正常状态的用户']);
        }

        foreach ($userInfos as $key => $value) {
            $userInfo[$value['uid']] = $value;
        }

        $couponList = array_unique(array_filter(array_keys($data['coupon'])));
        $couponInfo = CouponModel::where(['code' => $couponList, 'status' => [1], 'valid_status' => 1])->select()->toArray();
        if (count($couponInfo) != count($couponList)) {
            $existCoupon = array_column($couponInfo, 'coupon_code');
            foreach ($couponList as $key => $value) {
                if (!in_array($value, $existCoupon)) {
                    $failCoupon[] = $value;
                }
            }

            if (!empty($failCoupon)) {
                $failCouponMsg = implode(', ', $failCoupon);
            }
            throw new CouponException(['msg' => '存在不可领取的优惠券,请核对一下优惠券编码: ' . $failCouponMsg ?? '']);
        }
        $receiveRes = Db::transaction(function () use ($couponUser, $coupon, $userInfo) {
//            $couponUser = ['2l5y0RsHvw' => 4, 'sGIpMUKIv8' => 1, 'DcDROGefYK' => 1, 'FodAlI4KyP' => 1, 'siXN5ZT5YV' => 3, 'VmeYJ8Rcij' => 2];
//            $coupon = ['1003202104081560001' => 4, '1001202104087210001' => 1, '1001202104089770002' => 3];
            foreach ($coupon as $cKey => $cValue) {
                $number = 0;
                foreach ($couponUser as $key => $value) {
                    for ($i = 1; $i <= (($cValue ?? 1) * ($value ?? 1)); $i++) {
                        //系统领券
                        $res = $this->userReceive(['uid' => $key, 'code' => $cKey, 'notThrowError' => true, 'needMsg' => true, 'receive_type' => 2]);

                        if (!empty($res) && is_numeric($res)) {
                            $thisReceiveSuccess = true;
                        }
                        if (!isset($receiveRes[$cKey])) {
                            $receiveRes[$cKey] = [];
                        }
                        if (!isset($receiveRes[$cKey][$number])) {
                            $receiveRes[$cKey][$number] = [];
                        }
                        $receiveRes[$cKey][$number]['userInfo'] = $userInfo[$key] ?? [];
                        $receiveRes[$cKey][$number]['res'][$i] = $res;
                        if (empty($thisReceiveSuccess)) {
                            $failRes[$cKey][$number]['userInfo'] = $userInfo[$key] ?? [];
                            $failRes[$cKey][$number]['res'][$i] = $res;
                        } else {
                            $successRes[$cKey][$number]['userInfo'] = $userInfo[$key] ?? [];
                            $successRes[$cKey][$number]['res'][$i] = $res;
                        }
                    }
                    $number++;
                }
            }
            $finally['all'] = $receiveRes ?? [];
            $finally['success'] = $successRes ?? [];
            $finally['fail'] = $failRes ?? [];
            return $finally;
        });
        $log['result'] = json_encode($receiveRes ?? [], 256);
        $receiveRes['couponInfo'] = $couponInfo ?? [];

        //记录日志
        $log['admin_id'] = $adminInfo['id'] ?? 0;
        $log['admin_name'] = $adminInfo['name'] ?? '未知管理员';
        $log['type'] = $type ?? 1;
        $log['user'] = json_encode($data['user'], 256);
        $log['coupon'] = json_encode($data['coupon'], 256);
        $log['coupon_info'] = json_encode($receiveRes['couponInfo'], 256);
        $log['create_time'] = time();
        $log['update_time'] = time();
        CouponDeliver::create($log);

        return $receiveRes ?? [];
    }

}