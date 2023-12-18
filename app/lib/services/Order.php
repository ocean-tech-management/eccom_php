<?php
// +----------------------------------------------------------------------
// |[ 文档说明: 订单模块Service]
// +----------------------------------------------------------------------
// | Copyright (c) 2018~2024 http://www.mlhcmk.com All rights reserved.
// +----------------------------------------------------------------------


namespace app\lib\services;

use app\controller\callback\v1\PayCallback;
use app\lib\exceptions\ActivityException;
use app\lib\exceptions\FinanceException;
use app\lib\exceptions\JoinPayException;
use app\lib\exceptions\OrderException;
use app\lib\exceptions\PpylException;
use app\lib\exceptions\SandPayException;
use app\lib\exceptions\ServiceException;
use app\lib\exceptions\ShipException;
use app\lib\exceptions\UserException;
use app\lib\exceptions\YsePayException;
use app\lib\job\TimerForOrder;
use app\lib\models\BawList;
use app\lib\models\CrowdfundingBalanceDetail;
use app\lib\models\CrowdfundingPeriod;
use app\lib\models\Divide as DivideModel;
use app\lib\models\ExchangeGoods;
use app\lib\models\GoodsSku;
use app\lib\models\GoodsSkuVdc;
use app\lib\models\Handsel;
use app\lib\models\Member;
use app\lib\models\OrderPayArguments;
use app\lib\models\PpylGoodsSku;
use app\lib\models\PpylOrder;
use app\lib\models\PpylOrderGoods;
use app\lib\models\UserBankCard;
use app\lib\models\Withdraw;
use app\lib\services\Member as MemberService;
use app\lib\models\Order as OrderModel;
use app\lib\models\OrderCoupon;
use app\lib\models\OrderGoods;
use app\lib\models\PostageTemplate;
use app\lib\models\PtGoodsSku;
use app\lib\models\PtOrder;
use app\lib\models\ShipOrder;
use app\lib\models\SystemConfig;
use app\lib\models\User;
use app\lib\models\UserCoupon;
use app\lib\models\Activity;
use think\facade\Cache;
use think\facade\Db;


class Order
{
    public $orderCacheExpire = 900;  //订单失效时间
    public $userMaxNotPayOrderNumber = 5; //用户未支付最大订单数,超过拦截不允许继续下单
    public  $agreementOrderCacheHeader = 'ago-';
    public  $agreementCrowdOrderCacheHeader = 'agoc-';
    public  $defaultPayType = 6;  //默认支付方式, 1为余额支付 2为微信支付 6为协议支付

    /**
     * @title  下单后调起微信统一下单
     * @param array $data
     * @return array
     * @throws \Exception
     */
    public function buildOrder(array $data): array
    {
        //临时关于微信支付的报错(根据accss_key判断)
        $key = getAccessKey();
        $type = substr($key, 0, 1);
        if ($data['pay_type'] == 2 && !in_array($type, config('system.wxPayType'))) {
            throw new ServiceException(['msg' => '请使用余额或银行卡协议支付, 谢谢您的支持']);
        }
        //如果是美丽金专区订单需要检测用户是否可以参与
        if ($data['order_type'] == 1 && $data['pay_type'] == 5) {
            $this->checkUserCanBuyCrowdActivityGoods(['uid' => $data['uid']]);
        }
        $memberUpgradeOrderCacheKey = (new MemberService())->memberUpgradeOrderKey;
        $wxRes = [];
        //检查用户待支付订单是否超过可允许数量,超过则拦截不允许继续下单
        if ($this->userNotPayOrder(['uid' => $data['uid'] ?? null])) {
            throw new OrderException(['errorCode' => 1500126]);
        }
        //如果是团长大礼包订单判断是否有正在进行中的订单(下单,分润,升级一整个流程中的订单,只有处理了升级才会解锁),防止系统处理间隙时用户刷成长值
        if (!empty($data['order_type']) && $data['order_type'] == 3) {
            if (!empty(cache($data['uid'] . $memberUpgradeOrderCacheKey))) {
                throw new OrderException(['errorCode' => 1500127]);
            }
        }

        //如果是转售订单判断是否有正在进行中的订单(下单,分润,升级一整个流程中的订单,只有处理了完才会解锁),防止系统处理间隙时其他用户重复下单
        if (!empty($data['order_type']) && $data['order_type'] == 5) {
            $handselSn = $data['handsel_sn'] ?? null;
            if (empty($handselSn)) {
                throw new OrderException(['errorCode' => 1500130]);
            }
            //加入缓存锁防止并发
            $handselCacheKey = 'handSelOrderLock-' . $handselSn;
            $handselCacheInfo = cache($handselCacheKey);
            if (!empty($handselCacheInfo) && $data['uid'] != $handselCacheInfo) {
                throw new OrderException(['errorCode' => 1500131]);
            }
        }
        //如果支付方式为银行协议支付, 需要判断支付密码和银行卡是否签约
        if ($data['pay_type'] == 6) {
            $this->agreementPayCheck($data);
        }
        //默认允许的支付类型
        $defaultAllowPayType = $this->getDefaultAllowPayType();
        $joinShopCard = 1;
        $allAllowPayType = null;
//        //如果是商城活动的商品检查支付方式是否跟活动约束相符,防止伪造数据
        if (!empty(array_unique(array_column($data['goods'], 'activity_id'))) && $data['order_type'] != 6) {
            $allActivityInfo = Activity::where(['id' => array_unique(array_column($data['goods'], 'activity_id')), 'status' => 1])->field('id,allow_pay_type,allow_shop_card')->select()->toArray();
            if (!empty($allActivityInfo)) {
                $allAllowPayType = array_column($allActivityInfo, 'allow_pay_type');
                //如果存在冲突的加入购物车权限则默认都不可以加入购物车
                $allAllowShopCard = array_column($allActivityInfo, 'allow_shop_card');
                if (in_array(2, $allAllowShopCard)) {
                    $joinShopCard = 2;
                }
                if (empty($allAllowPayType)) {
                    throw new ActivityException(['msg' => '活动存在异常, 请稍后重试']);
                }
                //筛选出所有活动都允许的支付类型, 如果没有则标识支付类型互斥, 不允许继续支付
                $aMixed = [];
                $sAllAllowPayType = [];
                foreach ($allAllowPayType as $key => $value) {
                    foreach (explode(',', $value) as $cKey => $cValue) {
                        if (!isset($aMixed[$cValue])) {
                            $aMixed[$cValue] = 0;
                        }
                        $aMixed[$cValue] += 1;
                    }
                }
                foreach ($aMixed as $key => $value) {
                    if ($value == count($allAllowPayType)) {
                        $sAllAllowPayType[] = $key;
                    }
                }
                if (empty($sAllAllowPayType)) {
                    throw new ActivityException(['msg' => '订单商品中存在互斥的支付方式, 请检查后重试哦']);
                }
                if ($joinShopCard == 2 && count($allAllowShopCard) > 1) {
                    throw new ActivityException(['msg' => '提交订单中存在不允许加入购物车一并支付的商品, 请检查后重试哦']);
                }
                $allAllowPayType = $sAllAllowPayType;
                foreach ($allAllowPayType as $key => $value) {
                    $allAllowPayType[$key] = intval($value);
                }
                if (!empty($allAllowPayType)) {
                    if (!in_array($data['pay_type'], $allAllowPayType)) {
                        throw new ActivityException(['msg' => '不允许的支付方式!']);
                    }
                }
            }

            //若本次同时存在特殊支付方式和普通商城商品的情况下, 不允许一并购买
            if (count($data['goods'] ?? []) > 1) {
                $existNotActivityGoods = false;
                $existSpecialPayType = false;
                foreach ($data['goods'] as $key => $value) {
                    if (empty($value['activity_id'] ?? null)) {
                        $existNotActivityGoods = true;
                    }
                }
                foreach ($allAllowPayType as $key => $value) {
                    if (in_array($value, [5, 7, 8, 9])) {
                        $existSpecialPayType = true;
                    }
                }
                if (!empty($existNotActivityGoods ?? false) && !empty($existSpecialPayType ?? false)) {
                    throw new ActivityException(['msg' => '提交订单中存在不允许加入购物车一并支付的商品, 请检查后重试哦']);
                }
            }

        } else {
            //众筹专区仅允许美丽金支付,其他专区需要判断各自对应的支付方式
            if (($data['order_type'] == 6 && $data['pay_type'] != 5) || ($data['order_type'] != 6 && !in_array($data['pay_type'], $defaultAllowPayType))) {
                throw new ActivityException(['msg' => '不允许的支付方式!']);
            }
        }

        $buildOrder = Db::transaction(function () use ($data, $memberUpgradeOrderCacheKey) {
            $data['order_amount'] = $data['total_price'];
            $userModel = (new User());
            $aUserInfo = $userModel->getUserProtectionInfo($data['uid']);
            if (!empty($data['order_link_user'])) {
                $data['order_link_user'] = trim($data['order_link_user']);
            }
            //判断是否开启自由用户的购买条件
            $newUserCondition = SystemConfig::where(['id' => 1, 'status' => 1])->value('new_user_condition');
            switch ($newUserCondition) {
                case 1:
                    //如果没有传订单关联人则判断改用户原先上级是否存在,不存在则不允许下单,存在则订单关联人为原上级用户,合伙人级别允许没有上级
                    if (intval($aUserInfo['vip_level'] ?? 0) != 1) {
                        if (!empty($aUserInfo['vip_level'])) {
                            $data['order_link_user'] = $aUserInfo['link_superior_user'] ?? null;
                        } else {
                            if (empty($data['order_link_user'])) {
                                if (empty($aUserInfo['link_superior_user'])) {
                                    throw new OrderException(['msg' => '没有推荐人暂无法购买哟']);
                                } else {
                                    $data['order_link_user'] = $aUserInfo['link_superior_user'];
                                }
                            } else {
                                //如果有传订单关联人,但是用户本来有上级用户,则采用原来的上级用户
                                if (!empty($aUserInfo['link_superior_user']) && $aUserInfo['link_superior_user'] != $data['order_link_user']) {
                                    $data['order_link_user'] = $aUserInfo['link_superior_user'];
                                }

                                //没有等级的会员不允许自己通过自己的分享链接购买
                                if (empty($aUserInfo['link_superior_user']) && ($data['order_link_user'] == $data['uid'])) {
                                    throw new OrderException(['msg' => '您为普通用户,请先通过其他代理人分享的链接重新进入小程序哦']);
                                }
                                //没有等级的会员不允许自己通过自己的分享链接购买,如果有上级则修改为上级
                                if (!empty($aUserInfo['link_superior_user']) && ($data['order_link_user'] == $data['uid'])) {
                                    $data['order_link_user'] = $aUserInfo['link_superior_user'];
                                }
                                //查看订单关联上级的信息
                                $linkUserInfo = $userModel->getUserProtectionInfo($data['order_link_user']);

                                //拼团不限制订单关联上级一定要是会员
                                if (!empty($data['order_type']) && in_array($data['order_type'], [2, 4])) {
                                    if (empty($linkUserInfo)) {
                                        throw new OrderException(['msg' => '仅限会员使用，没有推荐人暂无法购买哦']);
                                    }
                                } else {
                                    //普通用户没有上级不允许购买
                                    if (empty($linkUserInfo) && empty(($aUserInfo['vip_level']))) {
                                        throw new OrderException(['msg' => '无上级推荐用户暂无法购买哦']);
                                    }
//                                    if (empty($linkUserInfo) && empty($aUserInfo['vip_level']) || (!empty($linkUserInfo) && empty($linkUserInfo['vip_level'] ?? 0) && empty($aUserInfo['vip_level']))) {
//                                        throw new OrderException(['msg' => '仅限会员使用，没有推荐人或推荐人非代理人身份暂无法购买~如果您是普通用户, 请通过其他代理人分享的链接重新进入小程序哦']);
//                                    } else {
                                        //普通用户如果通过的上级为普通用户等级,且该上级没有绑定上级则不允许购买,自然用户不允许成为顶级
//                                        if(empty($linkUserInfo['vip_level']) && $aUserInfo['vip_level'] == 0 && empty($linkUserInfo['link_superior_user'])){
//                                            throw new OrderException(['msg' => '仅限会员使用，没有推荐人或推荐人非代理人身份暂无法购买~如果您是普通用户, 请通过其他代理人分享的链接重新进入小程序哦']);
//
////                                            $prevMemberUid = $this->findNormalUserPrevMemberByTeam(['link_superior_user'=>$aUserInfo['link_superior_user']]);
////
////                                            //团队上级中没有一个会员用户则不允许购买
////                                            if(empty($prevMemberUid)){
////                                                throw new OrderException(['msg' => '仅限会员使用，没有推荐人或推荐人非代理人身份暂无法购买~如果您是普通用户, 请通过其他代理人分享的链接重新进入小程序哦']);
////                                            }
//                                        }
//                                    }
                                }
//                                if (empty($linkUserInfo)) {
//                                    //拼团不限制订单关联上级一定要是会员
//                                    if(!empty($data['order_type']) && $data['order_type'] == 2){
//                                        throw new OrderException(['msg' => '仅限会员使用，没有推荐人或推荐人非代理人身份暂无法购买哦']);
//                                    }elseif(empty($linkUserInfo['vip_level'])){
//                                        throw new OrderException(['msg' => '仅限会员使用，没有推荐人或推荐人非代理人身份暂无法购买哦']);
//                                    }
//                                }

                                $data['order_link_user'] = $aUserInfo['link_superior_user'] ?? $data['order_link_user'];
                            }
                        }
                    } else {
                        $data['order_link_user'] = !empty($aUserInfo['link_superior_user']) ? $aUserInfo['link_superior_user'] : ($data['order_link_user'] ?? null);
                    }
                    break;
                case -1:
                    //获取关联人信息
                    $data['order_link_user'] = !empty($aUserInfo['link_superior_user']) ? $aUserInfo['link_superior_user'] : ($data['order_link_user'] ?? null);
                    break;
                default:
                    //获取关联人信息
                    $data['order_link_user'] = !empty($aUserInfo['link_superior_user']) ? $aUserInfo['link_superior_user'] : ($data['order_link_user'] ?? null);
            }

            //校验订单金额
            $checkData = $this->checkOrderAmount($data);

            //获取校验后的用户优惠券编码列表
            if (!empty($checkData['coupon'])) {
                $data['uc_code'] = $checkData['coupon'] ?? [];
            }

            $newOrder = (new OrderModel())->new($data);

            //如果是直接返回数组则是正常订单,拼拼有礼单返回的数据格式不同
            if(empty($newOrder['orderRes'] ?? [])){
                if ($newOrder['order_type'] == 2) {
                    //一次拼团仅允许一个商品
                    $pt['activity_code'] = current(array_unique(array_filter(array_column($data['goods'], 'activity_id'))));
                    $pt['pt_join_type'] = $data['pt_join_type'];
                    $pt['activity_sn'] = current(array_unique(array_filter(array_column($data['goods'], 'activity_sn')))) ?? null;
                    $firstGoodsSku = current($data['goods']);
                    $pt['goods_sn'] = $firstGoodsSku['goods_sn'] ?? null;
                    $pt['sku_sn'] = $firstGoodsSku['sku_sn'] ?? null;
                    $pt['pay_type'] = $data['pay_type'] ?? 2;
                    $ptOrder = (new Pt())->orderCreate($pt, $newOrder);
                }

                //团长大礼包下订单需要加一个缓存锁,只有在处理完升级后才解锁,不允许在升级过程中继续下单
                if ($newOrder['order_type'] == 3) {
                    cache($data['uid'] . $memberUpgradeOrderCacheKey, $newOrder['order_sn'], 1800);
                }
            }else{
                if ($newOrder['orderRes']['order_type'] == 4) {
                    //一次拼团仅允许一个商品
                    $ppyl['area_code'] = current(array_unique(array_filter(array_column($data['goods'], 'activity_id'))));
                    $ppyl['ppyl_join_type'] = $data['ppyl_join_type'];
                    $ppyl['activity_sn'] = current(array_unique(array_filter(array_column($data['goods'], 'activity_sn')))) ?? null;
                    $firstGoodsSku = current($data['goods']);
                    $ppyl['goods_sn'] = $firstGoodsSku['goods_sn'] ?? null;
                    $ppyl['sku_sn'] = $firstGoodsSku['sku_sn'] ?? null;
                    $ppyl['autoPpyl'] = $data['autoPpyl'] ?? false;
                    $ppyl['pay_type'] = $data['pay_type'] ?? 1;
                    $ppyl['pay_no'] = $data['pay_no'] ?? null;
                    $ppyl['is_restart_order'] = $data['is_restart_order'] ?? null;
                    $ppyl['pay_order_sn'] = $data['pay_order_sn'] ?? null;
                    $ppyl['is_auto_order'] = $data['is_auto_order'] ?? 2;
                    $ppyl['auto_plan_sn'] = $data['auto_plan_sn'] ?? null;
                    $ppylOrder = (new Ppyl())->orderCreate($ppyl, $newOrder);
                    $newOrder = $newOrder['orderRes'];
                }
            }

            return ['newOrder' => $newOrder, 'userInfo' => $aUserInfo, 'ptOrder' => $ptOrder ?? [], 'ppylOrder' => $ppylOrder ?? []];
//            return $wxRes;
        });

        //支付服务,直接扣款或调起支付
        if (!empty($buildOrder)) {
            $aUserInfo = $buildOrder['userInfo'] ?? [];
            $newOrder = $buildOrder['newOrder'] ?? [];
            $ptOrder = $buildOrder['ptOrder'] ?? [];
            $ppylOrder = $buildOrder['ppylOrder'] ?? [];
            if (!empty($newOrder) && !empty($aUserInfo)) {
                if (!empty(doubleval($newOrder['real_pay_price']))) {
                    switch ($newOrder['pay_type'] ?? 2) {
                        case 1:
                            //余额支付
                            if ((string)$aUserInfo['divide_balance'] < (string)$newOrder['real_pay_price']) {
                                throw new OrderException(['errorCode' => 1500113]);
                            }

                            $accountPay['out_trade_no'] = $newOrder['order_sn'];
                            $accountPay['transaction_id'] = null;
                            if ($newOrder['order_type'] == 4) {
                                if (empty($ppylOrder)) {
                                    throw new PpylException(['msg' => '拼拼订单生成有误,操作过于频繁,请稍后重试']);
                                }
                                $accountPay['otherMap'] = 'ppyl';
                                if(!empty($ppylOrder) && !empty($ppylOrder['waitOrder'] ?? false)){
                                    $accountPay['otherMap'] = 'ppylWait';
                                }
                            }
                            $payRes = (new Pay())->completePay($accountPay);
                            $wxRes['need_pay'] = false;
                            $wxRes['complete_pay'] = !empty($payRes ?? false) ? true : false;
                            $wxRes['msg'] = '余额支付中,订单稍后完成';
                            break;
                        case 2:
                            //微信支付
                            //获取真实的微信用户openid
                            $aUserInfo['openid'] = (new User())->getOpenIdByUid($data['uid']);
                            if (empty($aUserInfo['openid'] ?? null)) {
                                throw new OrderException(['msg' => '微信用户信息有误, 暂无法完成微信支付']);
                            }
                            $wxOrder['openid'] = $aUserInfo['openid'];
                            $wxOrder['out_trade_no'] = $newOrder['order_sn'];
                            $wxOrder['body'] = '商城购物订单';
                            $wxOrder['attach'] = '商城订单';
                            $wxOrder['total_fee'] = $newOrder['real_pay_price'];
                            //$wxOrder['total_fee'] = 0.01;
                            //根据当前系统不同支付商渠道对应调起不同的支付下单
                            switch (config('system.thirdPayTypeForWxPay') ?? 2) {
                                case 1:
                                    //微信商户号支付
                                    $wxOrder['notify_url'] = config('system.callback.wxPayCallBackUrl');
                                    if (substr(getAccessKey(), 0, 1) == 'd') {
                                        $wxOrder['trade_type'] = 'APP';
                                    }
                                    $wxRes = (new WxPayService())->order($wxOrder);
                                    break;
                                case 2:
                                    //汇聚支付
                                    $wxOrder['notify_url'] = config('system.callback.joinPayCallBackUrl');
                                    if ($newOrder['order_type'] == 4) {
                                        if (empty($ppylOrder)) {
                                            throw new PpylException(['msg' => '拼拼订单生成有误,操作过于频繁,请稍后重试']);
                                        }
                                        $wxOrder['map'] = 'ppyl';
                                        $wxOrder['body'] = '拼拼订单';
                                        if (!empty($ppylOrder) && !empty($ppylOrder['waitOrder'] ?? false)) {
                                            $wxOrder['map'] = 'ppylWait';
                                            $wxOrder['body'] = '拼拼排队订单';
                                        }
                                    }
                                    $wxRes = (new JoinPay())->order($wxOrder);
                                    break;
                                case 3:
                                    //衫德支付暂未支持
                                    throw new OrderException(['msg' => '暂不支持的支付通道']);
                                    break;
                                case 4:
                                    //银盛支付
                                    $wxOrder['notify_url'] = config('system.callback.ysePayCallBackUrl');
                                    if ($newOrder['order_type'] == 4) {
                                        if (empty($ppylOrder)) {
                                            throw new PpylException(['msg' => '拼拼订单生成有误,操作过于频繁,请稍后重试']);
                                        }
                                        $wxOrder['map'] = 'ppyl';
                                        $wxOrder['body'] = '拼拼订单';
                                        if (!empty($ppylOrder) && !empty($ppylOrder['waitOrder'] ?? false)) {
                                            $wxOrder['map'] = 'ppylWait';
                                            $wxOrder['body'] = '拼拼排队订单';
                                        }
                                    }
                                    $wxRes = (new YsePay())->order($wxOrder);
                                    break;
                                default:
                                    throw new OrderException(['msg' => '暂不支持的支付通道']);
                                    break;
                            }

                            $wxRes['need_pay'] = true;
                            $wxRes['complete_pay'] = false;
                            $cacheOrder = $wxRes;
                            $cacheOrder['order_sn'] = $newOrder['order_sn'];
                            cache($newOrder['order_sn'], $cacheOrder, $this->orderCacheExpire);
                            break;
                        case 4:
                            //流水号支付,复用之前的支付订单
                            if (empty($data['pay_no'] ?? null)) {
                                throw new OrderException(['msg' => '缺少支付流水号']);
                            }
                            $accountPay['out_trade_no'] = $newOrder['order_sn'];
                            $accountPay['transaction_id'] = $data['pay_no'];
                            if ($newOrder['order_type'] == 4) {
                                $accountPay['otherMap'] = 'ppyl';
                                if(!empty($ppylOrder) && !empty($ppylOrder['waitOrder'] ?? false)){
                                    $accountPay['otherMap'] = 'ppylWait';
                                }
                            }
                            $payRes = (new Pay())->completePay($accountPay);
                            $wxRes['need_pay'] = false;
                            $wxRes['complete_pay'] = !empty($payRes ?? false) ? true : false;
                            $wxRes['msg'] = '流水号支付中,订单稍后完成';
                            $wxRes['ppylOrder'] = $ppylOrder ?? [];
                            $wxRes['newOrder'] = $newOrder ?? [];
                            break;
                        case 5:
                            //众筹余额支付
                            if ((string)$aUserInfo['crowd_balance'] < (string)$newOrder['real_pay_price']) {
                                throw new OrderException(['errorCode' => 1500113]);
                            }

                            $accountPay['out_trade_no'] = $newOrder['order_sn'];
                            $accountPay['transaction_id'] = null;
//                            if ($newOrder['order_type'] != 6) {
                            if (!in_array($newOrder['order_type'], [1, 6])) {
                                throw new PpylException(['msg' => '当前订单类型不允许使用此支付方式']);
                            }
                            $payRes = (new Pay())->completePay($accountPay);
                            $wxRes['need_pay'] = false;
                            $wxRes['complete_pay'] = !empty($payRes ?? false) ? true : false;
                            $wxRes['msg'] = '美丽金余额支付中,订单稍后完成';
                            break;
                        case 6:
                            //协议(银行卡)支付
                            if (empty($data['sign_no'] ?? null)) {
                                throw new ServiceException(['msg' => '请选择支付的银行卡']);
                            }
                            $cardInfo = UserBankCard::where(['sign_no' => $data['sign_no'], 'status' => 1, 'contract_status' => 1])->count();
                            if (empty($cardInfo)) {
                                throw new ServiceException(['msg' => '请选择已成功签约的银行卡']);
                            }

                            $wxOrder['out_trade_no'] = $newOrder['order_sn'];
                            $wxOrder['body'] = '商城购物订单';
                            $wxOrder['attach'] = '商城订单';
                            $wxOrder['total_fee'] = $newOrder['real_pay_price'];
                            $wxOrder['order_create_time'] = $newOrder['create_time'];
                            $wxOrder['sign_no'] = $data['sign_no'];
                            $wxOrder['uid'] = $newOrder['uid'];
                            $wxOrder['pay_pwd'] = $data['pay_pwd'];
//                            $wxOrder['total_fee'] = 0.01;
                            //协议支付
                            switch (config('system.thirdPayType') ?? 2) {
                                case 2:
                                    $wxOrder['notify_url'] = config('system.callback.joinPayAgreementCallBackUrl');
                                    break;
                                case 3:
                                    $wxOrder['notify_url'] = config('system.callback.sandPayAgreementCallBackUrl');
                                    break;
                                case 4:
                                    $wxOrder['notify_url'] = config('system.callback.ysePayAgreementCallBackUrl');
                                    break;
                                default:
                                    throw new FinanceException(['msg' => '暂不支持的支付商通道']);
                                    break;
                            }

                            if ($newOrder['order_type'] == 4) {
                                if (empty($ppylOrder)) {
                                    throw new PpylException(['msg' => '拼拼订单生成有误,操作过于频繁,请稍后重试']);
                                }
                                $wxOrder['map'] = 'ppyl';
                                $wxOrder['body'] = '拼拼订单';
                                if(!empty($ppylOrder) && !empty($ppylOrder['waitOrder'] ?? false)){
                                    $wxOrder['map'] = 'ppylWait';
                                    $wxOrder['body'] = '拼拼排队订单';
                                }
                            }
                            //下发支付短信验证码
                            $wxRes = $this->paySms($wxOrder);
                            $wxRes['need_pay'] = true;
                            $wxRes['complete_pay'] = false;
                            $cacheOrder = $wxOrder;
                            $cacheOrder['order_sn'] = $newOrder['order_sn'];
                            $cacheOrder['order_status'] = 1;
                            $cacheOrder['pay_status'] = 1;
                            cache($this->agreementOrderCacheHeader . $newOrder['order_sn'], $cacheOrder, 120);
                            break;
                        case 7:
                            //纯积分(美丽豆)支付
                            if ((string)$aUserInfo['integral'] < (string)$newOrder['real_pay_price']) {
                                throw new OrderException(['errorCode' => 1500113]);
                            }

                            $accountPay['out_trade_no'] = $newOrder['order_sn'];
                            $accountPay['transaction_id'] = 'spi-' . $newOrder['order_sn'];
                            if ($newOrder['order_type'] == 4) {
                                if (empty($ppylOrder)) {
                                    throw new PpylException(['msg' => '拼拼订单生成有误,操作过于频繁,请稍后重试']);
                                }
                                $accountPay['otherMap'] = 'ppyl';
                                if(!empty($ppylOrder) && !empty($ppylOrder['waitOrder'] ?? false)){
                                    $accountPay['otherMap'] = 'ppylWait';
                                }
                            }
                            $payRes = (new Pay())->completePay($accountPay);
                            $wxRes['need_pay'] = false;
                            $wxRes['complete_pay'] = !empty($payRes ?? false) ? true : false;
                            $wxRes['msg'] = '纯积分支付中,订单稍后完成';
                            break;
                        case 8:
                            //美丽券支付
                            if ((string)$aUserInfo['ticket_balance'] < (string)$newOrder['real_pay_price']) {
                                throw new OrderException(['errorCode' => 1500113]);
                            }

                            $accountPay['out_trade_no'] = $newOrder['order_sn'];
                            $accountPay['transaction_id'] = 'spt-' . $newOrder['order_sn'];
                            if ($newOrder['order_type'] == 4) {
                                if (empty($ppylOrder)) {
                                    throw new PpylException(['msg' => '拼拼订单生成有误,操作过于频繁,请稍后重试']);
                                }
                                $accountPay['otherMap'] = 'ppyl';
                                if(!empty($ppylOrder) && !empty($ppylOrder['waitOrder'] ?? false)){
                                    $accountPay['otherMap'] = 'ppylWait';
                                }
                            }
                            $payRes = (new Pay())->completePay($accountPay);
                            $wxRes['need_pay'] = false;
                            $wxRes['complete_pay'] = !empty($payRes ?? false) ? true : false;
                            $wxRes['msg'] = '美丽券支付中,订单稍后完成';
                            break;
                        default :
                            throw new OrderException(['errorCode' => 1500114]);
                    }
                } else {
                    $freeOrder['out_trade_no'] = $newOrder['order_sn'];
                    $freeOrder['transaction_id'] = null;
                    (new Pay())->completePay($freeOrder);
                    $wxRes['need_pay'] = false;
                    $wxRes['complete_pay'] = true;
                    $wxRes['msg'] = '无需支付,订单稍后完成';
                }
                $templateId = config('system.templateId');
                $wxRes['expireTimestamp'] = (new TimerForOrder())->timeOutSecond ?? 300;
                $wxRes['templateId'] = [$templateId['orderSuccess'] ?? null, $templateId['ship'] ?? null];
                $wxRes['order_sn'] = $newOrder['order_sn'];
                if ($newOrder['order_type'] == 2 && !empty($ptOrder)) {
                    $wxRes['activity_code'] = $ptOrder['activity_code'] ?? null;
                    $wxRes['activity_sn'] = $ptOrder['activity_sn'];
                    $wxRes['ptTemplateId'] = [$templateId['ptSchedule'] ?? null, $templateId['ptSuccess'] ?? null, $templateId['ptFail'] ?? null];
                }
                if ($newOrder['order_type'] == 4 && !empty($ppylOrder)) {
                    $wxRes['area_code'] = $ppylOrder['area_code'] ?? null;
                    $wxRes['activity_sn'] = $ppylOrder['activity_sn'] ?? null;
                    $wxRes['ppylTemplateId'] = [$templateId['ptSchedule'] ?? null, $templateId['ptSuccess'] ?? null, $templateId['ptFail'] ?? null];
                    //如果是排队则换排队的模版消息
                    if(!empty($ppylOrder['waitOrder'] ?? false)){
                        $wxRes['templateId'] = [$templateId['ppylWait'] ?? null,$templateId['ppylWaitRes'] ?? null];
                    }

                }
            }
        }

        return $wxRes;
    }

    /**
     * @title  预订单
     * @param array $data
     * @return array
     * @throws \Exception
     */
    public function readyOrder(array $data): array
    {
        //添加缓存锁
        $joinReadyOrderCacheKey = 'joinReadyOrderNumber';
        $maxJoinReadyOrderNumber = 20;
        $cacheExpire = 1;
        $nowCacheNumber = cache($joinReadyOrderCacheKey);
        if (!empty($nowCacheNumber) && intval($nowCacheNumber) >= 1) {
            if (intval($nowCacheNumber) >= $maxJoinReadyOrderNumber) {
                throw new OrderException(['msg' => '活动火爆,前方拥挤~请稍后再试~']);
            } else {
                Cache::inc($joinReadyOrderCacheKey, 1);
            }
        } else {
            cache($joinReadyOrderCacheKey, 1, $cacheExpire);
        }

        $disArr = [];
        $maxFare = 0.00;
        $disPrice = 0.00;
        $totalPrice = 0.00;
        $memberPrice = 0.00;
        $integralDis = 0.00;
        $usedIntegral = 0;
        $usedIntegralDis = $data['usedIntegralDis'] ?? 1; //是否使用积分 1为是 2为否
        $usedCouponDis = $data['usedCouponDis'] ?? 1; //是否使用优惠券 1为是 2为否
        if (empty($data['goods'])) {
            throw new OrderException(['msg' => '缺少有效商品']);
        }
        $aSku = array_column($data['goods'], 'sku_sn');
        if (!empty($data['order_type'])) {
            $orderType = $data['order_type'];
        } else {
            $orderType = 1;
        }
        //$orderType = $data['order_type'] ?? 1;
        $activityId = $data['activity_id'] ?? [];
        $activitySn = $data['activity_sn'] ?? null;
        $ptJoinType = $data['pt_join_type'] ?? null;
        $userAddressProvince = !empty($data['province']) ? intval($data['province']) : null;
        $userAddressCity = !empty($data['city']) ? intval($data['city']) : null;
        $userAddressArea = !empty($data['area']) ? intval($data['area']) : null;
        $readyType = $data['readyType'] ?? 2; //预订单类型 1为从商品详情进入 2为在预订单页面重新调预订单
        $aUserInfo = (new User())->getUserProtectionInfo($data['uid']);
        if (!empty($aUserInfo) && empty($aUserInfo['phone'])) {
            throw new OrderException(['msg' => '请前往个人中心填写有效的联系方式']);
        }
        //购物黑名单,can_buy不等于1则不允许下单
        if (!empty($aUserInfo['can_buy'] ?? null) && $aUserInfo['can_buy'] != 1) {
            throw new OrderException(['msg' => '前方拥挤, 请稍后重试~']);
        }
        //如果是转售订单判断是否为直属下级,不是则不允许下单
        if($orderType == 5){
            $handselSn = $data['handsel_sn'] ?? null;
            if (empty($handselSn)) {
                throw new OrderException(['msg' => '暂无法下单']);
            }
            //加入缓存锁防止并发
            $handselCacheKey = 'handSelOrderLock-' . $handselSn;
            $handselCacheInfo = cache($handselCacheKey);

            if (!empty($handselCacheInfo) && $data['uid'] != $handselCacheInfo) {
                throw new OrderException(['msg' => '订单交易中,您暂无法操作']);
            }
            cache($handselCacheKey, $data['uid'], 30);
            $res = Db::transaction(function() use ($data,$handselSn,$aUserInfo){
                $handselId= Handsel::where(['handsel_sn'=>$handselSn,'operate_status'=>2])->lock(true)->value('id');
                $handselInfo = Handsel::where(['handsel_sn'=>$handselSn,'operate_status'=>2])->findOrEmpty()->toArray();
                if(empty($handselId) || empty($handselInfo)){
                    throw new OrderException(['msg' => '暂不支持下单']);
                }
                if(empty($aUserInfo['link_superior_user']) || (!empty($aUserInfo['link_superior_user']) && $aUserInfo['link_superior_user'] != $handselInfo['uid'])){
                    throw new OrderException(['msg' => '您不是此订单的下单对象,暂不支持下单']);
                }
            });

        }
        //获取关联人信息
        $data['order_link_user'] = !empty($aUserInfo['link_superior_user']) ? $aUserInfo['link_superior_user'] : ($data['order_link_user'] ?? null);

        //判断会员价
        $aUserMember = (new Member())->getUserLevel($data['uid']);

        $aSkuList = (new GoodsSku())->getInfoBySkuSnForReadyOrder($data['goods'], $orderType, $activityId, $aUserMember ?? 0, $data['order_link_user'],$data['uid']);
        if (empty($aSkuList) || (count($aSkuList) != count($data['goods']))) {
            throw new OrderException(['msg' => '订单中包含未上架的产品!']);
        }

        //判断是否存在兑换专区商品, 如果存在则不允许多件商品一起下单
        $existExchange = ExchangeGoods::where(['goods_sn' => array_unique(array_column($data['goods'], 'goods_sn')), 'status' => 1])->count();
        if (!empty($existExchange) && count($data['goods']) > 1) {
            throw new OrderException(['msg' => '订单中存在特殊商品, 暂不允许多件商品一起下单哦']);
        }
        //众筹订单是否为提前购
        $advanceBuy = false;

        if (!empty($aSkuList)) {

            //邮费模版设置
            $allPostageTemplateCode = array_column($aSkuList, 'postage_code');

            //如果无地址或查无运费模版的时候默认包邮
            if (empty($userAddressCity) || empty($allPostageTemplateCode)) {
                if ($readyType == 2 && $orderType != 6) {
                    throw new OrderException(['errorCode' => 1500115]);
                }
//                foreach ($aSkuList as $key => $value) {
//                    $aSkuList[$key]['fare'] = 0;
//                    $aSkuList[$key]['free_shipping'] = 1;
//                }
            } else {
                //福利专区订单不需要判断邮费
                if ($data['order_type'] != 6) {
                    foreach ($data['goods'] as $key => $value) {
                        $goodsNumber[$value['sku_sn']] = $value['number'];
                        if (!isset($goodsSpuNumber[$value['goods_sn']])) {
                            $goodsSpuNumber[$value['goods_sn']] = 0;
                        }
                        $goodsSpuNumber[$value['goods_sn']] += $value['number'];
                    }

                    if (!empty($allPostageTemplateCode)) {
                        $templateInfo = (new PostageTemplate())->list(['code' => $allPostageTemplateCode, 'notFormat' => true])['list'] ?? [];
                        if (!empty($templateInfo)) {
                            foreach ($aSkuList as $key => $value) {
//                            $number = $goodsNumber[$value['sku_sn']];
                                $number = $goodsSpuNumber[$value['goods_sn']];
                                $aSkuList[$key]['fare'] = 0;
                                $aSkuList[$key]['free_shipping'] = 1;
                                $defaultFare = [];
                                foreach ($templateInfo as $tKey => $tValue) {
                                    $notSaleArea = !empty($tValue['not_sale_area']) ? explode(',', $tValue['not_sale_area']) : [];
                                    if ($value['postage_code'] == $tValue['code']) {
                                        if (!empty($notSaleArea)) {
                                            if (in_array($userAddressProvince, $notSaleArea) || in_array($userAddressArea, $notSaleArea) || in_array($userAddressCity, $notSaleArea)) {
                                                throw new ShipException(['msg' => '非常抱歉,商品<' . $value['title'] . '>在您的收货地区暂不支持销售或配送,感谢您的支持']);
                                            }
                                        }
                                        if ($tValue['free_shipping'] == 2 && !empty($tValue['detail'])) {
                                            foreach ($tValue['detail'] as $cKey => $cValue) {
                                                if ($cValue['type'] == 1) {
                                                    $defaultFare = $cValue;
                                                }
                                                if (in_array($userAddressCity, $cValue['city_code'])) {
                                                    if ($number <= $cValue['default_number']) {
                                                        $aSkuList[$key]['fare'] = $cValue['default_price'];
                                                    } else {
                                                        $aSkuList[$key]['fare'] = $cValue['default_price'] + ceil((($number - $cValue['default_number']) / $cValue['create_number'])) * $cValue['create_price'];
                                                    }
                                                    $aSkuList[$key]['exist_fare'] = true;
                                                }
                                            }

                                            //如果一直都没有找到匹配城市则使用默认运费模版
                                            if (empty(doubleval($aSkuList[$key]['fare'])) && (empty($aSkuList[$key]['exist_fare'])) && !empty($defaultFare)) {
                                                if ($number <= $defaultFare['default_number']) {
                                                    $aSkuList[$key]['fare'] = $defaultFare['default_price'];
                                                } else {
                                                    $aSkuList[$key]['fare'] = $defaultFare['default_price'] + ceil((($number - $defaultFare['default_number']) / $defaultFare['create_number'])) * $defaultFare['create_price'];
                                                }
                                            }

                                        }
                                    }

                                }
                                $aSkuList[$key]['free_shipping'] = (empty($aSkuList[$key]['fare'])) ? 1 : 2;
                            }
                        }
                    }

                    //分配邮费
                    $existFareGoods = [];
                    $existFareGoodsNumber = [];
                    $goodsSkuNumber = [];
                    foreach ($aSkuList as $key => $value) {
                        if (!empty(doubleval($value['fare']))) {
                            $existFareGoods[$value['goods_sn']] = $value['goods_sn'];
                            if (!isset($existFareGoodsNumber[$value['goods_sn']])) {
                                $existFareGoodsNumber[$value['goods_sn']] = 0;
                            }
                            $existFareGoodsNumber[$value['goods_sn']] += $goodsNumber[$value['sku_sn']] ?? 1;
                        }
                        if (!isset($goodsSkuNumber[$value['goods_sn']][$value['sku_sn']])) {
                            $goodsSkuNumber[$value['goods_sn']][$value['sku_sn']] = 0;
                        }
                        $goodsSkuNumber[$value['goods_sn']][$value['sku_sn']] += $goodsNumber[$value['sku_sn']] ?? 1;
                    }
                    if (!empty($existFareGoodsNumber)) {
                        foreach ($existFareGoodsNumber as $key => $value) {
                            if (doubleval($value) > 1) {
                                $lastFare[$key] = 0;
                                $addFareSkuNumber[$key] = 0;
                                foreach ($aSkuList as $skuKey => $skuValue) {
                                    if ($key == $skuValue['goods_sn'] && !empty(doubleval($skuValue['fare']))) {
                                        $nowSkuFareScale = round($goodsNumber[$skuValue['sku_sn']] / array_sum($goodsSkuNumber[$skuValue['goods_sn']]), 2);
                                        $nowSkuFare = priceFormat($skuValue['fare'] * $nowSkuFareScale);
                                        if ($goodsNumber[$skuValue['sku_sn']] + $addFareSkuNumber[$key] >= array_sum($goodsSkuNumber[$skuValue['goods_sn']])) {
                                            $aSkuList[$skuKey]['fare'] = priceFormat($skuValue['fare'] - $lastFare[$key]);
                                        } else {
                                            if ($lastFare[$key] + $nowSkuFare <= (string)$skuValue['fare']) {
                                                $aSkuList[$skuKey]['fare'] = $nowSkuFare;
                                                $lastFare[$key] += $nowSkuFare;
                                            } else {
                                                $aSkuList[$skuKey]['fare'] = priceFormat($skuValue['fare'] - $lastFare[$key]);
                                            }
                                            $addFareSkuNumber[$key] += $goodsNumber[$skuValue['sku_sn']] ?? 0;
                                        }
                                    }
                                }
                            }
                        }
                    }
                }
            }


            foreach ($data['goods'] as $key => $value) {
                foreach ($aSkuList as $k => $v) {
                    if ($value['sku_sn'] == $v['sku_sn']) {
                        //$maxFare += priceFormat($v['fare'] * $value['number']);
                        //福利专区订单不需要判断邮费
                        if ($data['order_type'] == 6) {
                            $aSkuList[$key]['fare'] = 0;
                            $v['fare'] = 0;
                        }
                        $maxFare += priceFormat($v['fare']);
                    }
                }
            }

            //最大邮费设置,先注释
            // $maxFare = max(array_column($aSkuList,'fare'));

            //判断库存是否充足
            //$allStock = array_column($aSkuList,'stock');
            foreach ($data['goods'] as $key => $value) {
                $data['goods'][$key]['gift_type'] = -1;
                $data['goods'][$key]['gift_number'] = 0;
                foreach ($aSkuList as $goodsKey => $goodsValue) {
                    if ($goodsValue['sku_sn'] == $value['sku_sn']) {
                        if (empty($goodsValue) || (intval($value['number']) > $goodsValue['stock'])) {
                            Cache::tag([config('cache.systemCacheKey.apiGoodsInfo.key') . ($value['goods_sn'] ?? '')])->clear();
                            throw new OrderException(['errorCode' => 1500107]);
                        }
                    }
                    if ($data['order_type'] == 6) {
                        $data['goods'][$key]['advance_buy'] = $goodsValue['advance_buy'] ?? false;
                        if (!empty($goodsValue['advance_buy'] ?? false)) {
                            $advanceBuy = true;
                            $data['goods'][$key]['presale'] = $goodsValue['presale'] ?? false;
                            $data['goods'][$key]['advance_buy_reward_send_time'] = $goodsValue['advance_buy_reward_send_time'] ?? 0;
                        }
                    }

                    if ($goodsValue['sku_sn'] == $value['sku_sn']) {
                        //查询活动是否有其他赠送的东西
                        $data['goods'][$key]['gift_type'] = $goodsValue['gift_type'] ?? -1;
                        $data['goods'][$key]['gift_number'] = $goodsValue['gift_number'] ?? 0;
                    }
                }
            }
        }

        //如果从购物车进来goods数组里面是没有商品总价的,这里需要补上,下面的优惠券的使用金额判断需要用到
        if (!empty($data['goods']) && !empty($data['uid'])) {
            if (count(array_column($data['goods'], 'total_price')) != count($data['goods'])) {
                $userLevel = $aUserMember ?? 0;
                $sku_sn = array_filter(array_unique(array_column($data['goods'], 'sku_sn')));
                if (!empty($userLevel)) {
                    $goodsVdcInfo = GoodsSkuVdc::where(['sku_sn' => $sku_sn, 'status' => 1, 'level' => $userLevel])->column('purchase_price', 'sku_sn');
                } else {
                    $goodsVdcInfo = GoodsSku::where(['sku_sn' => $sku_sn, 'status' => 1])->column('sale_price', 'sku_sn');
                }
                if (!empty($goodsVdcInfo)) {
                    foreach ($data['goods'] as $key => $value) {
                        if (!empty($goodsVdcInfo[$value['sku_sn']]) && empty(doubleval($value['total_price'] ?? null))) {
                            $data['goods'][$key]['total_price'] = $value['number'] * $goodsVdcInfo[$value['sku_sn']] ?? 0;
                        }
                        foreach ($aSkuList as $skuKey => $skuValue) {
                            if ($skuValue['sku_sn'] == $value['sku_sn']) {
                                $data['goods'][$key]['category_code'] = $skuValue['category_code'];
                                $data['goods'][$key]['price'] = $skuValue['sale_price'];
                            }
                        }
                    }
                }
            }
        }

        $aSkuNum = [];
        foreach ($data['goods'] as $key => $value) {
            if (!isset($aSkuNum[$value['sku_sn']])) {
                $aSkuNum[$value['sku_sn']] = 0;
            }
            $aSkuNum[$value['sku_sn']] += $value['number'] ?? 1;
        }
        //累加获取全部商品的总价和会员价
        foreach ($aSkuList as $key => $value) {
            $totalPrice += $value['sale_price'] * (int)$aSkuNum[$value['sku_sn']];
            $memberPrice += $value['member_price'] * (int)$aSkuNum[$value['sku_sn']];
        }

        $aFilter['order_amount'] = priceFormat($totalPrice);
        $aFilter['fare'] = !empty($maxFare) ? priceFormat($maxFare) : "0.00";
        if (!empty($aUserMember)) {
            $aFilter['member_price'] = $memberPrice;
        } else {
            $aFilter['member_price'] = $totalPrice;
        }
        $aFilter['uid'] = $data['uid'];
        $aFilter['goods'] = $data['goods'];

        //重新添加回每个商品的单价以便前端展示使用
        foreach ($data['goods'] as $key => $value) {
            foreach ($aSkuList as $k => $v) {
                if ($value['sku_sn'] == $v['sku_sn']) {
                    $data['goods'][$key]['price'] = $v['sale_price'];
                    $data['goods'][$key]['member_price'] = $v['member_price'];
                    $data['goods'][$key]['title'] = $v['title'];
                    $data['goods'][$key]['specs'] = $v['specs'];
                    $data['goods'][$key]['image'] = $v['image'];
                    $data['goods'][$key]['category_code'] = $v['category_code'];
//                    $data['goods'][$key]['total_fare_price'] = priceFormat($v['fare']* $value['number']);
                    $data['goods'][$key]['total_fare_price'] = $v['fare'];
                }
            }
        }

        //会员折扣
        $aFilter['memberDis'] = priceFormat($aFilter['order_amount'] - $aFilter['member_price']);

        if ($usedCouponDis == 1) {
            //如果没有选优惠券则使用系统默认选择优惠券
            if (empty($data['uc_code'] ?? [])) {
                $aCoupon = (new Coupon())->systemChooseCoupon($aFilter);
            } else {
                $aFilter['uc_code'] = $data['uc_code'];
                //筛选优惠券列表
                (new Coupon())->checkCouponLicit($aFilter);
                $aCoupon = (new Coupon())->userChooseCoupon($aFilter);
                //如果筛选出来的
                if (count($aCoupon['disArr'] ?? []) > count($data['uc_code'])) {
                    throw new OrderException(['errorCode' => 1500111]);
                }
            }

        }

        if (!empty($aCoupon)) {
            $disPrice = $aCoupon['dis_price'];
            $disArr = $aCoupon['disArr'];
        }

        //如果选择使用积分抵扣则判断积分能抵扣的金额
        //积分抵扣金额(没有传使用积分时默认根据商品需要的积分返回可使用的积分,有传使用积分时检查积分是否合法)
        $userInfo = (new User())->where(['uid' => $data['uid'], 'status' => 1])->field('uid,integral,vip_level')->findOrEmpty()->toArray();
        $userIntegral = $userInfo['integral'];

        if ($usedIntegralDis == 1) {
            $totalAmountNeedIntegral = (new Integral())->moneyToIntegral($aFilter['order_amount'] - ($aFilter['order_amount'] - $aFilter['member_price']));
            if (empty(intval($data['integral'] ?? 0))) {
                if ($userIntegral >= $totalAmountNeedIntegral) {
                    $usedIntegral = $totalAmountNeedIntegral;
                } else {
                    $usedIntegral = $userIntegral;
                }
                $integralDis = (new Integral())->integralToMoney($usedIntegral);
            } else {
                $usedIntegral = intval($data['integral']);
                if ($usedIntegral > $totalAmountNeedIntegral) {
                    $usedIntegral = $totalAmountNeedIntegral;
                }
                $integralDis = (new Integral())->integralToMoney($usedIntegral);
            }
        }

        //如果积分能抵扣全部总价则不使用优惠券
        $aRes = $aFilter;
        $aRes['userAllIntegral'] = intval($userIntegral);
        $aRes['usedIntegral'] = intval($usedIntegral);
        $aRes['integralDis'] = priceFormat($integralDis);


        //$aRes['memberDis']    = priceFormat($aRes['order_amount'] - $aRes['member_price']);
        $aRes['memberDis'] = 0;
        if ($aRes['integralDis'] >= $aRes['order_amount']) {
            $aRes['couponDis'] = "0.00";
            $aRes['CouponDisArr'] = [];
        } else {
//            //如果已经有积分优惠,则判断是否有使用优惠券后超过订单总价格的,有则舍去该优惠券
//            array_multisort(array_column($disArr,'dis_price'), SORT_DESC, $disArr);
//            $nowDis = $aRes['integralDis'];
//            foreach ($disArr as $key => $value) {
//                if($nowDis + $value['dis_price'] > $aRes['order_amount']){
//                    $disPrice -= $value['dis_price'];
//                    unset($disArr[$key]);
//                }else{
//                    $nowDis += $value['dis_price'];
//                }
//            }
            $aRes['couponDis'] = priceFormat($disPrice);
            $aRes['CouponDisArr'] = $disArr;
        }

        $aRes['allDisPrice'] = priceFormat($aRes['memberDis'] + $aRes['integralDis'] + $aRes['couponDis']);
        $aRes['realPayPrice'] = priceFormat(($totalPrice + $maxFare) - ($aRes['allDisPrice']));
        //判断实付金额不允许为负数及折扣金额不允许超过实付金额
        if ((double)$aRes['realPayPrice'] <= 0) {
            $aRes['realPayPrice'] = "0.00";
        }
        if ((double)$aRes['allDisPrice'] >= (double)$aRes['order_amount']) {
            $aRes['allDisPrice'] = (double)$aRes['order_amount'];
        }
        $aRes['goods'] = $data['goods'];
//
        //根据商品活动详情指定支付方式---part1---start
        $allAllowPayType = $this->getDefaultAllowPayType();
        $allowPayTypeDefaultPayType = $this->getDefaultPayType();
        $joinShopCard = 1;
        //查看商品中活动商品(仅限商城活动)是否存在特殊指定支付方式
        if (!empty(array_unique(array_column($aRes['goods'], 'activity_id'))) && $data['order_type'] != 6) {
            $allActivityInfo = Activity::where(['id' => array_unique(array_column($aRes['goods'], 'activity_id')), 'status' => 1])->field('id,allow_pay_type,allow_shop_card')->select()->toArray();
            if(!empty($allActivityInfo)){
                $allAllowPayType = array_column($allActivityInfo, 'allow_pay_type');
                //如果存在冲突的加入购物车权限则默认都不可以加入购物车
                $allAllowShopCard = array_column($allActivityInfo, 'allow_shop_card');
                if (in_array(2, $allAllowShopCard)) {
                    $joinShopCard = 2;
                }
                //筛选出所有活动都允许的支付类型, 如果没有则标识支付类型互斥, 不允许继续支付
                $aMixed = [];
                $sAllAllowPayType = [];
                foreach ($allAllowPayType as $key => $value) {
                    foreach (explode(',', $value) as $cKey => $cValue) {
                        if (!isset($aMixed[$cValue])) {
                            $aMixed[$cValue] = 0;
                        }
                        $aMixed[$cValue] += 1;
                    }
                }
                foreach ($aMixed as $key => $value) {
                    if ($value == count($allAllowPayType)) {
                        $sAllAllowPayType[] = $key;
                    }
                }
                if (empty($sAllAllowPayType)) {
                    throw new ActivityException(['msg' => '订单商品中存在互斥的支付方式, 请检查后重试哦']);
                }
                if ($joinShopCard == 2 && count($allAllowShopCard) > 1) {
                    throw new ActivityException(['msg' => '提交订单中存在不允许加入购物车一并支付的商品, 请检查后重试哦']);
                }
                $allAllowPayType = $sAllAllowPayType;
                foreach ($allAllowPayType as $key => $value) {
                    $allAllowPayType[$key] = intval($value);
                }
                $allowPayTypeDefaultPayType = current($allAllowPayType);
            }
            //若本次同时存在特殊支付方式和普通商城商品的情况下, 不允许一并购买
            if (count($aRes['goods'] ?? []) > 1) {
                $existNotActivityGoods = false;
                $existSpecialPayType = false;
                foreach ($aRes['goods'] as $key => $value) {
                    if (empty($value['activity_id'] ?? null)) {
                        $existNotActivityGoods = true;
                    }
                }
                foreach ($allAllowPayType as $key => $value) {
                    if (in_array($value, [5, 7, 8, 9])) {
                        $existSpecialPayType = true;
                    }
                }
                if (!empty($existNotActivityGoods ?? false) && !empty($existSpecialPayType ?? false)) {
                    throw new ActivityException(['msg' => '提交订单中存在不允许加入购物车一并支付的商品, 请检查后重试哦']);
                }
            }

        }
        //根据商品活动详情指定支付方式---part1---end
        $aRes['user'] = ['vip_level' => $userInfo['vip_level']];
        $aRes['activity_id'] = intval($data['activity_id'] ?? 0);
        $aRes['activity_sn'] = $data['activity_sn'] ?? null;
        $aRes['order_type'] = $data['order_type'] ?? null;
        $aRes['pt_join_type'] = $data['pt_join_type'] ?? null;
        $aRes['ppyl_join_type'] = $data['ppyl_join_type'] ?? null;
        $aRes['pay_type'] = $data['pay_type'] ?? $this->defaultPayType;
        //根据商品活动详情指定支付方式---part2---start
        if (empty($data['pay_type'] ?? null)) {
            if (!empty($allowPayTypeDefaultPayType)) {
                $aRes['pay_type'] = intval($allowPayTypeDefaultPayType);
            } else {
                $aRes['pay_type'] = $this->defaultPayType;
            }
        } else {
            if (!empty($allAllowPayType) && !in_array($data['pay_type'], $allAllowPayType) && $data['order_type'] != 6) {
                throw new ServiceException(['msg' => '不允许的支付方式']);
            }
            $aRes['pay_type'] = $data['pay_type'] ?? $this->defaultPayType;
        }
        //根据商品活动详情指定支付方式---part2---end
        //众筹订单强行只能美丽金支付
        if ($data['order_type'] == 6) {
            $aRes['pay_type'] = 5;
        }
        //如果是美丽金专区订单需要检测用户是否可以参与
        if ($data['order_type'] == 1 && $aRes['pay_type'] == 5) {
            $this->checkUserCanBuyCrowdActivityGoods(['uid' => $data['uid']]);
        }
        $aRes['city'] = $data['city'] ?? null;
        $aRes['province'] = $data['province'] ?? null;
        $aRes['area'] = $data['area'] ?? null;
        $aRes['autoPpyl'] = $data['autoPpyl'] ?? null;
//        $aRes['pay_no'] = $data['pay_no'] ?? null;
        $aRes['pay_no'] = $data['pay_no'] ?? null;
        $aRes['is_restart_order'] = $data['is_restart_order'] ?? null;
        $aRes['pay_order_sn'] = $data['pay_order_sn'] ?? null;
        $aRes['attach_type'] = intval($data['attach_type'] ?? -1);
        $aRes['handsel_sn'] = $data['handsel_sn'] ?? null;
        $aRes['shipping_address_detail'] = $data['shipping_address_detail'] ?? null;
        //允许的支付类型,C端配合
        $aRes['allow_pay_type'] = $this->getDefaultAllowPayType();
        //根据商品活动详情指定支付方式---part3---start
        if (!empty($allAllowPayType)) {
            $aRes['allow_pay_type'] = array_values($allAllowPayType);
        }
        //根据商品活动详情指定支付方式---part3---end
        if ($data['order_type'] == 6) {
            $aRes['allow_pay_type'] = [5];
            $aRes['advance_buy'] = $advanceBuy ?? false;
        }
        $aRes['pay_pwd_unset'] = true;
        //是否允许加入购物车
        $aRes['joinShopCard'] = $joinShopCard ?? 1;
        //用户是否有支付密码
        if (!empty($aUserInfo['pay_pwd'] ?? null)) {
            $aRes['pay_pwd_unset'] = false;
        }
        //积分支付和美丽券支付的订单为积分订单或美丽券订单
        if (in_array(($aRes['pay_type'] ?? 1), [7, 8])) {
            $aRes['order_type'] = $aRes['pay_type'];
        }

        //计算每个商品详细的折扣明细
        $aRes = $this->orderAllGoodsMoneyDetail($aRes);

        //根据应用身份处理支付方式（针对微信支付）
        $key = getAccessKey();
        $type = substr($key, 0, 1);
        if (!in_array($type, config('system.wxPayType'))) {
            //过滤微信支付
            if ($aRes['pay_type'] == 2) {
                throw new ServiceException(['msg' => '支付方式有误，请刷新后重新下单！']);
            }
            //去除微信支付
            $pay_key = array_flip($aRes['allow_pay_type']);
            if (isset($pay_key['2'])) {
                unset($pay_key['2']);
                if (empty($pay_key)) {
                    throw new ServiceException(['msg' => '支付方式有误，请刷新后重新下单！']);
                }
                $aRes['allow_pay_type'] = array_flip($pay_key);
                $aRes['allow_pay_type'] = array_values($aRes['allow_pay_type']);
            }
        }

        //去除缓存锁
        if (!empty(cache($joinReadyOrderCacheKey)) && cache($joinReadyOrderCacheKey) >= 1) {
            Cache::dec($joinReadyOrderCacheKey, 1);
        }
        return $aRes;

    }

    /**
     * @title  校验订单金额是否合法
     * @param array $data
     * @return mixed
     * @throws \Exception
     */
    public function checkOrderAmount(array $data)
    {
        if (empty($data['goods'])) {
            throw new OrderException(['msg' => '缺少有效商品']);
        }
        //获取商品SKU的价格信息等
        $aSku = array_column($data['goods'], 'sku_sn');
        if (!empty($data['order_type'])) {
            $orderType = $data['order_type'];
        } else {
            $orderType = 1;
        }
        //纯积分支付或美丽券支付必须为积分订单或美丽券订单
        if (in_array($data['pay_type'], [7, 8])) {
            $orderType = $data['pay_type'];
            $data['order_type'] = $data['pay_type'];
        }
        //$orderType = !empty($data['order_type']) ? $data['order_type'] : 1;
        $activityId = $data['activity_id'] ?? null;
        $userAddressProvince = !empty($data['province']) ? intval($data['province']) : null;
        $userAddressCity = !empty($data['city']) ? intval($data['city']) : null;
        $userAddressArea = !empty($data['area']) ? intval($data['area']) : null;
//        $userAddressProvince = intval($data['province']) ?? null;
//        $userAddressCity = intval($data['city']) ?? null;
//        $userAddressArea = intval($data['area']) ?? null;
        $aUserMember = (new Member())->getUserLevel($data['uid'], false);
        $aSkuList = (new GoodsSku())->getInfoBySkuSn($data['goods'], false, $orderType, $activityId, $aUserMember ?? 0, $data['order_link_user'],$data['uid'],$data);
        $maxFare = 0.00;
        $totalPrice = 0.00;
        $memberPrice = 0.00;
        $couponDis = 0.00;
        //获取最大邮费
        if (!empty($aSkuList)) {

            //邮费模版设置
            $allPostageTemplateCode = array_column($aSkuList, 'postage_code');

            //如果无地址或查无运费模版的时候默认包邮
            if (empty($userAddressCity) || empty($allPostageTemplateCode)) {
                //福利订单无需检测地址
                if ($orderType != 6) {
                    throw new OrderException(['errorCode' => 1500115]);
                }
//                foreach ($aSkuList as $key => $value) {
//                    $aSkuList[$key]['fare'] = 0;
//                    $aSkuList[$key]['free_shipping'] = 1;
//                }
            } else {
                //福利专区不需要判断邮费
                if ($data['order_type'] != 6) {
                    foreach ($data['goods'] as $key => $value) {
                        $goodsNumber[$value['sku_sn']] = $value['number'];
                        if (!isset($goodsSpuNumber[$value['goods_sn']])) {
                            $goodsSpuNumber[$value['goods_sn']] = 0;
                        }
                        $goodsSpuNumber[$value['goods_sn']] += $value['number'];
                    }
                    if (!empty($allPostageTemplateCode)) {
                        $templateInfo = (new PostageTemplate())->list(['code' => $allPostageTemplateCode, 'notFormat' => true])['list'] ?? [];
                        if (!empty($templateInfo)) {
                            foreach ($aSkuList as $key => $value) {
//                            $number = $goodsNumber[$value['sku_sn']];
                                $number = $goodsSpuNumber[$value['goods_sn']];
                                $aSkuList[$key]['fare'] = 0;
                                $aSkuList[$key]['free_shipping'] = 1;
                                $defaultFare = [];
                                foreach ($templateInfo as $tKey => $tValue) {
                                    $notSaleArea = !empty($tValue['not_sale_area']) ? explode(',', $tValue['not_sale_area']) : [];
                                    //判断限售地区
                                    if ($value['postage_code'] == $tValue['code']) {
                                        if (!empty($notSaleArea)) {
                                            if (in_array($userAddressProvince, $notSaleArea) || in_array($userAddressArea, $notSaleArea) || in_array($userAddressCity, $notSaleArea)) {
                                                throw new ShipException(['msg' => '非常抱歉,商品<' . $value['title'] . '>在您的收货地区暂不支持销售或配送,感谢您的支持']);
                                            }
                                        }
                                        if ($tValue['free_shipping'] == 2 && !empty($tValue['detail'])) {
                                            foreach ($tValue['detail'] as $cKey => $cValue) {
                                                if ($cValue['type'] == 1) {
                                                    $defaultFare = $cValue;
                                                }
                                                if (in_array($userAddressCity, $cValue['city_code'])) {
                                                    if ($number <= $cValue['default_number']) {
                                                        $aSkuList[$key]['fare'] = $cValue['default_price'];
                                                    } else {
                                                        $aSkuList[$key]['fare'] = $cValue['default_price'] + ceil((($number - $cValue['default_number']) / $cValue['create_number'])) * $cValue['create_price'];
                                                    }
                                                    $aSkuList[$key]['exist_fare'] = true;
                                                }
                                            }

                                            //如果一直都没有找到匹配城市则使用默认运费模版
                                            if (empty(doubleval($aSkuList[$key]['fare'])) && (empty($aSkuList[$key]['exist_fare'])) && !empty($defaultFare)) {
                                                if ($number <= $defaultFare['default_number']) {
                                                    $aSkuList[$key]['fare'] = $defaultFare['default_price'];
                                                } else {
                                                    $aSkuList[$key]['fare'] = $defaultFare['default_price'] + ceil((($number - $defaultFare['default_number']) / $defaultFare['create_number'])) * $defaultFare['create_price'];
                                                }
                                            }
                                        }
                                    }

                                }
                                $aSkuList[$key]['free_shipping'] = (empty($aSkuList[$key]['fare'])) ? 1 : 2;
                            }
                        }
                    }

                    //分配邮费的比例
                    $existFareGoods = [];
                    $existFareGoodsNumber = [];
                    $goodsSkuNumber = [];
                    foreach ($aSkuList as $key => $value) {
                        if (!empty(doubleval($value['fare']))) {
                            $existFareGoods[$value['goods_sn']] = $value['goods_sn'];
                            if (!isset($existFareGoodsNumber[$value['goods_sn']])) {
                                $existFareGoodsNumber[$value['goods_sn']] = 0;
                            }
                            $existFareGoodsNumber[$value['goods_sn']] += $goodsNumber[$value['sku_sn']] ?? 1;
                        }
                        if (!isset($goodsSkuNumber[$value['goods_sn']][$value['sku_sn']])) {
                            $goodsSkuNumber[$value['goods_sn']][$value['sku_sn']] = 0;
                        }
                        $goodsSkuNumber[$value['goods_sn']][$value['sku_sn']] += $goodsNumber[$value['sku_sn']] ?? 1;
                    }
                    if (!empty($existFareGoodsNumber)) {
                        foreach ($existFareGoodsNumber as $key => $value) {
                            if (doubleval($value) > 1) {
                                $lastFare[$key] = 0;
                                $addFareSkuNumber[$key] = 0;
                                foreach ($aSkuList as $skuKey => $skuValue) {
                                    if ($key == $skuValue['goods_sn'] && !empty(doubleval($skuValue['fare']))) {
                                        $nowSkuFareScale = round($goodsNumber[$skuValue['sku_sn']] / array_sum($goodsSkuNumber[$skuValue['goods_sn']]), 2);
                                        $nowSkuFare = priceFormat($skuValue['fare'] * $nowSkuFareScale);
                                        if ($goodsNumber[$skuValue['sku_sn']] + $addFareSkuNumber[$key] >= array_sum($goodsSkuNumber[$skuValue['goods_sn']])) {
                                            $aSkuList[$skuKey]['fare'] = priceFormat($skuValue['fare'] - $lastFare[$key]);
                                        } else {
                                            if ($lastFare[$key] + $nowSkuFare <= (string)$skuValue['fare']) {
                                                $aSkuList[$skuKey]['fare'] = $nowSkuFare;
                                                $lastFare[$key] += $nowSkuFare;
                                            } else {
                                                $aSkuList[$skuKey]['fare'] = priceFormat($skuValue['fare'] - $lastFare[$key]);
                                            }
                                            $addFareSkuNumber[$key] += $goodsNumber[$skuValue['sku_sn']] ?? 0;
                                        }
                                    }
                                }
                            }
                        }
                    }
                }
            }

            foreach ($data['goods'] as $key => $value) {
                foreach ($aSkuList as $k => $v) {
                    if ($value['sku_sn'] == $v['sku_sn']) {
                        //$maxFare += priceFormat($v['fare'] * $value['number']);
                        if ($data['order_type'] == 6) {
                            $aSkuList[$key]['fare'] = 0;
                            $v['fare'] = 0;
                        }
                        $maxFare += priceFormat($v['fare']);
                    }
                }
            }

            //$maxFare = priceFormat(max(array_column($aSkuList,'fare')));
            //判断库存是否充足
            //$allStock = array_column($aSkuList,'stock');
            foreach ($data['goods'] as $key => $value) {
                foreach ($aSkuList as $goodsKey => $goodsValue) {
                    if ($goodsValue['sku_sn'] == $value['sku_sn']) {
                        if (empty($goodsValue) || (intval($value['number']) > $goodsValue['stock'])) {
                            Cache::tag([config('cache.systemCacheKey.apiGoodsInfo.key') . ($value['goods_sn'] ?? '')])->clear();
                            throw new OrderException(['errorCode' => 1500107]);
                        }
                    }
                }
            }
        }
        //获取每个商品对应的数量
        $aSkuNum = [];
        foreach ($data['goods'] as $key => $value) {
            if (!isset($aSkuNum[$value['sku_sn']])) {
                $aSkuNum[$value['sku_sn']] = 0;
            }
            $aSkuNum[$value['sku_sn']] += $value['number'];
        }

        //累加获取全部商品的总价和会员价
        foreach ($aSkuList as $key => $value) {
            $totalPrice += $value['sale_price'] * (int)$aSkuNum[$value['sku_sn']];
            $memberPrice += $value['member_price'] * (int)$aSkuNum[$value['sku_sn']];
        }

        if (empty($aUserMember)) {
            $memberPrice = $totalPrice;
        }

        /*---检查总价是否合法---*/
        if ((string)$totalPrice != (string)$data['total_price']) {
            throw new OrderException(['errorCode' => 1500102]);
        }

        //会员折扣金额
        //$memberDis = $totalPrice - $memberPrice;
        $memberDis = 0;

        /*---检查折扣金额是否合法---*/
        //检查优惠券是否合法
        $couponService = new Coupon();
        if (!empty($data['uc_code'])) {
            $data['order_amount'] = $data['total_price'];
            $couponService->checkCouponLicit($data); //检查优惠券合法性
            $data['memberDis'] = $memberDis;
            $aCoupon = $couponService->userChooseCoupon($data);//优惠券折扣金额
            $couponDis = $aCoupon['dis_price'];
            $aCouponArr = $aCoupon['disArr'];
        }


        //积分折扣金额
        $integralDis = (new Integral())->integralToMoney($data['used_integral'] ?? 0);

//        if(!empty($aCouponArr)){
//            //如果已经有积分优惠,则判断是否有使用优惠券后超过订单总价格的,有则舍去该优惠券
//            array_multisort(array_column($aCouponArr, 'dis_price'), SORT_DESC, $aCouponArr);
//            $nowDis = $integralDis + $memberDis;
//
//            foreach ($aCouponArr as $key => $value) {
//
//                if ($nowDis + $value['dis_price'] > $data['order_amount']) {
//                    $couponDis -= $value['dis_price'];
//                    unset($aCouponArr[$key]);
//                } else {
//                    $nowDis += $value['dis_price'];
//                }
//            }
//        }

        //总折扣金额
        $allDis = priceFormat(($couponDis + $memberDis + $integralDis));

        //如果折扣金额大于总的订单金额,需要慢慢递减判断是否未合法的折扣金额,如果见了会员金额还不超过则证明为正常的折扣金额,将总折扣金额改变为总金额,如果还超过则继续减去积分抵扣金额,不超过操作同上,超过则判断为非法折扣金额
        if ($allDis > $data['total_price']) {
            $allDis -= $memberDis;
            if ($allDis > $data['total_price']) {
                $allDis -= $integralDis;
                if ($allDis > $data['total_price']) {
                    throw new OrderException(['errorCode' => 1500104]);
                } else {
                    $allDis = $totalPrice;
                }
            } else {
                $allDis = $totalPrice;
            }
        }

        if ($allDis < priceFormat($data['discount_price'])) {
            throw new OrderException(['errorCode' => 1500101]);
        }

        /*---检查实付金额是否合法---*/
        $realPay = priceFormat((($totalPrice + $maxFare) - $allDis));
        if ($realPay != $data['real_pay_price']) {
            throw new OrderException(['errorCode' => 1500103]);
        }

        //如果是余额支付,判断余额是否足够,不足拦阻不允许下单
        //锁行
        $userId = Db::name('user')->where(['uid' =>  $data['uid']])->value('id');
//        $info = Db::name('user')->where(['id' => $userId])->lock(true)->value('uid');
//        $userBalanceInfo = User::where(['uid' => $data['uid']])->field('total_balance,divide_balance,ppyl_balance,ad_balance,crowd_balance')->findOrEmpty()->toArray();
        $userBalanceInfo = User::where(['id' => $userId])->lock(true)->field('total_balance,divide_balance,ppyl_balance,ad_balance,crowd_balance,integral,ticket_balance')->findOrEmpty()->toArray();

        if ($data['pay_type'] == 1) {
            if ((string)($userBalanceInfo['divide_balance'] ?? 0) < (string)$data['real_pay_price']) {
                throw new OrderException(['errorCode' => 1500113]);
            }
        } elseif ($data['pay_type'] == 5) {
            if ((string)($userBalanceInfo['crowd_balance'] ?? 0) < (string)$data['real_pay_price']) {
                throw new OrderException(['errorCode' => 1500113]);
            }
        } elseif ($data['pay_type'] == 7) {
            if ((string)($userBalanceInfo['integral'] ?? 0) < (string)$data['real_pay_price']) {
                throw new OrderException(['errorCode' => 1500113]);
            }
        } elseif ($data['pay_type'] == 8) {
            if ((string)($userBalanceInfo['ticket_balance'] ?? 0) < (string)$data['real_pay_price']) {
                throw new OrderException(['errorCode' => 1500113]);
            }
        }

        $all = [];
        if (!empty($aCouponArr)) {
            $all['coupon'] = array_column($aCouponArr, 'coupon_uc_code');
        }

        return $all;

    }

    /**
 * @title  重新支付订单
 * @param array $data 包含订单编号和token用户
 * @return mixed
 */
    public function orderPayAgain(array $data)
    {
        $orderSn = $data['order_sn'];
        $tokenUid = $data['token_uid'];
        $orderInfo = OrderModel::where(['order_sn' => $orderSn, 'order_status' => 1, 'pay_status' => 1])->field('order_sn,uid,create_time,pay_status,order_status,pay_time')->findOrEmpty()->toArray();
        if (empty($orderInfo)) {
            throw new OrderException(['msg' => '待支付订单已失效~']);
        }
        //判断订单中的商品是否全部都是有效的,如果不是正常状态则整单不允许继续支付
        $orderGoods = OrderGoods::where(['order_sn' => $orderSn])->column('sku_sn');
        $goodsStatus = GoodsSku::where(['sku_sn' => $orderGoods, 'status' => 1])->count();
        if (count($orderGoods) != $goodsStatus) {
            throw new OrderException(['errorCode' => 1500121]);
        }

        //超时订单不允许重新支付
        $timeOutTime = (new TimerForOrder())->timeOutSecond;
        if (time() >= strtotime($orderInfo['create_time']) + $timeOutTime) {
            throw new OrderException(['errorCode' => 1500123]);
        }

        $cacheWxPay = cache($orderSn);
        if (empty($cacheWxPay) || empty($orderInfo) || (time() - strtotime($orderInfo['create_time']) >= $timeOutTime)) {
            //没有订单缓存标识证明没有正确生成订单或者真的过期了,可以直接取消订单
            if (empty($cacheWxPay)) {
                $this->cancelPay(['order_sn' => $orderSn, 'token_uid' => $tokenUid]);
            }
            throw new OrderException(['errorCode' => 1500108]);
        }
        if ($tokenUid != $orderInfo['uid']) {
            throw new ServiceException(['errorCode' => 400101]);
        }
        //返回模版消息id
        $templateId = config('system.templateId');
        $cacheWxPay['templateId'] = [$templateId['orderSuccess'] ?? null, $templateId['ship'] ?? null];

        return $cacheWxPay;
    }


    /**
     * @title  取消支付
     * @param array $data 包含订单编号和token用户
     * @return mixed
     */
    public function cancelPay(array $data)
    {
        $orderSn = $data['order_sn'];
        $tokenUid = $data['token_uid'];
        $orderInfo = (new OrderModel())->with(['goods'])->where(['order_sn' => $orderSn, 'order_status' => 1, 'pay_status' => 1])->field('order_sn,order_type,uid,create_time,pay_type,pay_status,order_status,pay_time,handsel_sn')->findOrEmpty()->toArray();
        if (empty($orderInfo) || (time() - strtotime($orderInfo['create_time']) > $this->orderCacheExpire)) {
            throw new OrderException(['errorCode' => 1500108]);
        }
//        if($tokenUid != $orderInfo['uid']){
//            throw new ServiceException(['errorCode'=>400101]);
//        }
        //如果是团长大礼包订单判断取消缓存锁
        if (!empty($orderInfo['order_type']) && $orderInfo['order_type'] == 3) {
            cache($orderInfo['uid'] . (new \app\lib\services\Member())->memberUpgradeOrderKey, null);
        }
        //先关闭第三方支付订单,关闭成功后再做业务逻辑处理

        //关闭订单,只做了汇聚支付和银盛支付的,微信支付可以直接通过统一下单传入失效时间,无需手动关单
        if (!empty($orderInfo['pay_type'])) {
            if (in_array($orderInfo['pay_type'], [2, 3])) {
                if ($orderInfo['pay_type'] == 2) {
                    //只有汇聚支付才取消订单
                    switch (config('system.thirdPayType') ?? 2) {
                        case 2:
                            $FrpCode = 'WEIXIN_XCX';
                            break;
                        case 4:
                            $FrpCode = 'YSE_WEIXIN_XCX';
                            break;
                        default:
                            $FrpCode = null;
                    }
//                    $FrpCode = 'WEIXIN_XCX';
                }
                if (!empty($FrpCode)) {
                    switch (config('system.thirdPayType') ?? 2) {
                        case 2:
                            $closeOrder = (new JoinPay())->closeOrder(['order_sn' => $orderSn, 'pay_type' => $FrpCode]);
                            if (empty($closeOrder) || (!empty($closeOrder) && $closeOrder['status'] != 100)) {
                                throw new OrderException(['errorCode' => 1500129]);
                            }
                            break;
                        case 4:
                            $closeOrder = (new YsePay())->closeOrder(['order_sn' => $orderSn]);
                            if (empty($closeOrder) || (!empty($closeOrder) && $closeOrder['status'] != 100)) {
                                throw new OrderException(['errorCode' => 1500129]);
                            }
                            break;
                        default:
                            //notOper
                            break;
                    }
                }
            }
        }

        $res = Db::transaction(function () use ($orderInfo, $orderSn, $data) {
            //修改订单为取消交易
            $orderSave['order_status'] = -1;
            $orderSave['pay_status'] = 3;
            $orderSave['close_time'] = time();
            if (!empty($data['coder_remark'] ?? null)) {
                $orderSave['coder_remark'] = $data['coder_remark'];
            }
            if (!empty($data['order_remark'] ?? null)) {
                $orderSave['order_remark'] = $data['order_remark'];
            }
            $orderRes = OrderModel::update($orderSave, ['order_sn' => $orderSn, 'order_status' => 1, 'pay_status' => 1]);

            //修改订单商品支付状态
            $goodsSave['pay_status'] = 3;
            $goodsRes = OrderGoods::update($goodsSave, ['order_sn' => $orderSn, 'pay_status' => 1]);
            //如果是支付方式是协议支付修改订单缓存为失败
            if ($orderInfo['pay_type'] == 6) {
                $newCache = $orderSave;
                $newCache['order_sn'] = $orderSn;
                cache($this->agreementOrderCacheHeader . $orderSn, $newCache, 120);
            }
            //恢复库存
            $goodsSku = new GoodsSku();
            $ptGoodsSkuModel = new PtGoodsSku();
            //锁行后修改库存
            if (!empty($orderInfo['goods'])) {
                $allGoodsSn = array_unique(array_filter(array_column($orderInfo['goods'], 'goods_sn')));
                $allSkuSn = array_unique(array_filter(array_column($orderInfo['goods'], 'sku_sn')));
                if (!empty($allGoodsSn) && !empty($allSkuSn)) {
                    $goodsId = $goodsSku->where(['goods_sn' => $allGoodsSn, 'sku_sn' => $allSkuSn])->column('id');
                    if (!empty($goodsId)) {
                        $goodsInfo = $goodsSku->where(['id' => $goodsId])->lock(true)->field('id,goods_sn,sku_sn')->select()->toArray();
                    }
                    //拼团需要锁拼团商品的行
                    if ($orderInfo['order_type'] == 2) {
                        $ptOrderInfo = PtOrder::where(['order_sn' => $orderSn, 'goods_sn' => $allGoodsSn, 'sku_sn' => $allSkuSn])->findOrEmpty()->toArray();
                        $ptGoodsId = $ptGoodsSkuModel->where(['goods_sn' => $allGoodsSn, 'sku_sn' => $allSkuSn, 'activity_code' => $ptOrderInfo['activity_code']])->column('id');
                        if (!empty($ptGoodsId)) {
                            $ptGoodsInfo = $ptGoodsSkuModel->where(['id' => $ptGoodsId])->lock(true)->field('id,activity_code,goods_sn,sku_sn')->select()->toArray();
                        }
                    }
                }

                foreach ($orderInfo['goods'] as $key => $value) {
                    $res[] = $goodsSku->where(['goods_sn' => $value['goods_sn'], 'sku_sn' => $value['sku_sn']])->inc('stock', intval($value['count']))->update();
                    //如果是拼团需要恢复对应拼团的库存和开团数量
                    if ($orderInfo['order_type'] == 2 && !empty($ptOrderInfo)) {
                        $ptGoodsSkuModel->where(['goods_sn' => $value['goods_sn'], 'sku_sn' => $value['sku_sn'], 'activity_code' => $ptOrderInfo['activity_code']])->inc('stock', intval($value['count']))->update();
                        //如果是开团的人取消订单则回复可开团数量
                        if (!empty($ptOrderInfo) && $ptOrderInfo['user_role'] == 1) {
                            $ptGoodsSkuModel->where(['goods_sn' => $value['goods_sn'], 'sku_sn' => $value['sku_sn'], 'activity_code' => $ptOrderInfo['activity_code']])->inc('start_number', 1)->update();
                        }
                    }

                    //如果是众筹需要恢复众筹的目标销售额
                    if ($orderInfo['order_type'] == 6 && !empty($value['crowd_code'] ?? null)) {
                        CrowdfundingPeriod::where(['activity_code' => $value['crowd_code'], 'round_number' => $value['crowd_round_number'], 'period_number' => $value['crowd_period_number']])->inc('last_sales_price', ($value['real_pay_price'] - $value['total_fare_price']))->update();
                    }
                }
            }


            //如果是拼团订单则取消拼团里的占位
            if ($orderInfo['order_type'] == 2) {
                PtOrder::update(['pay_status' => -1, 'status' => -1, 'activity_status' => -1], ['order_sn' => $orderInfo['order_sn'], 'uid' => $orderInfo['uid'], 'pay_status' => 1]);
            }

            //如果是转售订单取消原订单的占位
            if ($orderInfo['order_type'] == 5) {
                Handsel::update(['order_sn' => null, 'order_uid' => null, 'operate_status' => 2, 'operate_time' => null], ['handsel_sn' => $orderInfo['handsel_sn'], 'operate_status' => 1]);
                //取消锁
                $handselCacheKey = 'handSelOrderLock-' . $orderInfo['handsel_sn'];
                $handselCacheInfo = cache($handselCacheKey, null);
            }

            //修改对应优惠券状态
            $aOrderCoupons = OrderCoupon::where(['order_sn' => $orderSn, 'used_status' => 1])->field('coupon_uc_code,coupon_code')->select()->toArray();
            $aOrderUcCoupon = array_column($aOrderCoupons, 'coupon_uc_code');
            //修改订单优惠券状态为取消使用
            $orderCouponRes = OrderCoupon::update(['used_status' => -1], ['order_sn' => $orderSn, 'used_status' => 1]);
            //修改用户订单优惠券状态为未使用
            $uCouponRes = UserCoupon::update(['valid_status' => 1], ['uc_code' => $aOrderUcCoupon]);

            return $orderRes;
        });
        //清除订单支付缓存信息
        cache($orderSn, null);
        return $res;
    }

    /**
     * @title  拼拼有礼-重新支付订单
     * @param array $data 包含订单编号和token用户
     * @return mixed
     */
    public function ppylOrderPayAgain(array $data)
    {
        $orderSn = $data['order_sn'];
        $tokenUid = $data['token_uid'];
        $orderInfo = PpylOrder::where(['order_sn' => $orderSn, 'order_status' => 1, 'pay_status' => 1])->field('order_sn,uid,create_time,pay_status,activity_status,pay_time')->findOrEmpty()->toArray();
        if (empty($orderInfo)) {
            throw new OrderException(['msg' => '待支付订单已失效~']);
        }
        //判断订单中的商品是否全部都是有效的,如果不是正常状态则整单不允许继续支付
        $orderGoods = OrderGoods::where(['order_sn' => $orderSn])->column('sku_sn');
        $goodsStatus = GoodsSku::where(['sku_sn' => $orderGoods, 'status' => 1])->count();
        if (count($orderGoods) != $goodsStatus) {
            throw new OrderException(['errorCode' => 1500121]);
        }

        //超时订单不允许重新支付
        $timeOutTime = (new TimerForOrder())->timeOutSecond;
        if (time() >= strtotime($orderInfo['create_time']) + $timeOutTime) {
            throw new OrderException(['errorCode' => 1500123]);
        }

        $cacheWxPay = cache($orderSn);
        if (empty($cacheWxPay) || empty($orderInfo) || (time() - strtotime($orderInfo['create_time']) >= $timeOutTime)) {
            //没有订单缓存标识证明没有正确生成订单或者真的过期了,可以直接取消订单
            if (empty($cacheWxPay)) {
                $this->ppylCancelPay(['order_sn' => $orderSn, 'token_uid' => $tokenUid]);
            }
            throw new OrderException(['errorCode' => 1500108]);
        }
        if ($tokenUid != $orderInfo['uid']) {
            throw new ServiceException(['errorCode' => 400101]);
        }
        //返回模版消息id
        $templateId = config('system.templateId');
        $cacheWxPay['templateId'] = [$templateId['orderSuccess'] ?? null, $templateId['ship'] ?? null];

        return $cacheWxPay;
    }

    /**
     * @title  拼拼有礼-取消支付
     * @param array $data 包含订单编号和token用户
     * @return mixed
     */
    public function ppylCancelPay(array $data)
    {
        $orderSn = $data['order_sn'];
        $tokenUid = $data['token_uid'];
        $orderInfo = (new PpylOrder())->with(['orderGoods'])->where(['order_sn' => $orderSn, 'order_status' => 1, 'pay_status' => 1])->field('order_sn,order_type,uid,create_time,pay_type,pay_status,activity_status,pay_time,user_role')->findOrEmpty()->toArray();
        if (empty($orderInfo) || (time() - strtotime($orderInfo['create_time']) > $this->orderCacheExpire)) {
            throw new OrderException(['errorCode' => 1500108]);
        }

        //先关闭第三方支付订单,关闭成功后再做业务逻辑处理

        //关闭订单,只做了汇聚支付的,微信支付可以直接通过统一下单传入失效时间,无需手动关单
        if (!empty($orderInfo['pay_type'])) {
            if (in_array($orderInfo['pay_type'], [2, 3])) {
                if ($orderInfo['pay_type'] == 2) {
                    $FrpCode = 'WEIXIN_XCX';
                }
                if (!empty($FrpCode)) {
                    $closeOrder = (new JoinPay())->closeOrder(['order_sn' => $orderSn, 'pay_type' => $FrpCode]);
                    if (empty($closeOrder) || (!empty($closeOrder) && $closeOrder['status'] != 100) || (!empty($closeOrder) && !empty($closeOrder['result']) && !empty($closeOrder['result']['rb_Code'] ?? null))) {
                        throw new OrderException(['errorCode' => 1500129]);
                    }
                }
            }
        }

        $res = Db::transaction(function () use ($orderInfo, $orderSn) {
            //修改订单为取消交易
            $orderSave['activity_status'] = -1;
            $orderSave['pay_status'] = 3;
            $orderSave['close_time'] = time();
            $orderRes = PpylOrder::update($orderSave, ['order_sn' => $orderSn, 'order_status' => 1, 'pay_status' => 1]);

            //修改订单商品支付状态
            $goodsSave['pay_status'] = 3;
            $goodsRes = PpylOrderGoods::update($goodsSave, ['order_sn' => $orderSn, 'pay_status' => 1]);

            //恢复库存
            $goodsSku = new GoodsSku();
            $ptGoodsSkuModel = new PpylGoodsSku();
            //锁行后修改库存
            if (!empty($orderInfo['orderGoods'])) {
                $allGoodsSn = array_unique(array_filter(array_column($orderInfo['goods'], 'goods_sn')));
                $allSkuSn = array_unique(array_filter(array_column($orderInfo['goods'], 'sku_sn')));
                if (!empty($allGoodsSn) && !empty($allSkuSn)) {
                    $goodsId = $goodsSku->where(['goods_sn' => $allGoodsSn, 'sku_sn' => $allSkuSn])->column('id');
                    if (!empty($goodsId)) {
                        $goodsInfo = $goodsSku->where(['id' => $goodsId])->lock(true)->field('id,goods_sn,sku_sn')->select()->toArray();
                    }
                    //拼拼有礼需要锁拼拼有礼商品的行
                    if ($orderInfo['order_type'] == 4) {
                        $ptOrderInfo = PpylOrder::where(['order_sn' => $orderSn, 'goods_sn' => $allGoodsSn, 'sku_sn' => $allSkuSn])->findOrEmpty()->toArray();
                        $ptGoodsId = $ptGoodsSkuModel->where(['goods_sn' => $allGoodsSn, 'sku_sn' => $allSkuSn, 'activity_code' => $ptOrderInfo['activity_code']])->column('id');
                        if (!empty($ptGoodsId)) {
                            $ptGoodsInfo = $ptGoodsSkuModel->where(['id' => $ptGoodsId])->lock(true)->field('id,activity_code,goods_sn,sku_sn')->select()->toArray();
                        }
                    }
                }
                //只有团长才恢复库存
                if(!empty($ptOrderInfo ?? []) && $ptOrderInfo['user_role'] == 1){
                    foreach ($orderInfo['goods'] as $key => $value) {
                        $res[] = $goodsSku->where(['goods_sn' => $value['goods_sn'], 'sku_sn' => $value['sku_sn']])->inc('stock', intval($value['count']))->update();
                        //如果是拼拼有礼需要恢复对应拼团的库存和开团数量
                        if ($orderInfo['order_type'] == 4 && !empty($ptOrderInfo)) {
                            $ptGoodsSkuModel->where(['goods_sn' => $value['goods_sn'], 'sku_sn' => $value['sku_sn'], 'activity_code' => $ptOrderInfo['activity_code']])->inc('stock', intval($value['count']))->update();
                            //如果是开团的人取消订单则恢复可开团数量
                            if (!empty($ptOrderInfo) && $ptOrderInfo['user_role'] == 1) {
                                $ptGoodsSkuModel->where(['goods_sn' => $value['goods_sn'], 'sku_sn' => $value['sku_sn'], 'activity_code' => $ptOrderInfo['activity_code']])->inc('start_number', 1)->update();
                            }
                        }
                    }
                }

            }

            //如果是拼拼有礼订单则取消拼团里的占位
            if ($orderInfo['order_type'] == 4) {
                PpylOrder::update(['pay_status' => -1, 'status' => -1, 'activity_status' => -1], ['order_sn' => $orderInfo['order_sn'], 'uid' => $orderInfo['uid'], 'pay_status' => 1]);
            }

            //修改对应优惠券状态
            $aOrderCoupons = OrderCoupon::where(['order_sn' => $orderSn, 'used_status' => 1])->field('coupon_uc_code,coupon_code')->select()->toArray();
            $aOrderUcCoupon = array_column($aOrderCoupons, 'coupon_uc_code');
            //修改订单优惠券状态为取消使用
            $orderCouponRes = OrderCoupon::update(['used_status' => -1], ['order_sn' => $orderSn, 'used_status' => 1]);
            //修改用户订单优惠券状态为未使用
            $uCouponRes = UserCoupon::update(['valid_status' => 1], ['uc_code' => $aOrderUcCoupon]);

            return $orderRes;
        });
        //清除订单支付缓存信息
        cache($orderSn, null);
        return $res;
    }

    /**
     * @title  检查商品是否存在未付款或已购买的
     * @param array $data
     * @return bool|string
     * @throws \Exception
     */
    public function checkBuyHistory(array $data)
    {
        $list = (new OrderModel())->checkBuyHistory($data['uid'], $data['goods'], [2]);
        $aBuyedGoods = false;
        if (!empty($list)) {
            $aBuyedGoods = implode(',', array_column($list, 'title'));
        }
        if (!empty($aBuyedGoods)) {
            throw new OrderException(['errorCode' => 1500105, 'msg' => '包含已经购买或未付款的商品,包括以下:' . $aBuyedGoods]);
        }
        return $aBuyedGoods;

    }

    /**
     * @title  计算每个商品对应的折扣明细
     * @param array $data
     * @return array
     * @remark 先按照商品价格占比(单个商品销售价格/订单总价格)排序,从小到大,然后慢慢累加计算折扣的余量,到达最后一个最大比例的商品,那么剩下的折扣金额都是最后一个的,这样做详细折扣和总折扣能保持一致,但是商品价格占比的比例和折扣占比的比例不同,考虑到商品价格占比比例为后台程序计算并无具体业务逻辑,所以暂且无妨
     */
    public function orderAllGoodsMoneyDetail(array $data)
    {
//        dump($data);die;
        //先按照商品价格占比排序,从小到大
//        if($data['order_amount'] != $data['realPayPrice'] && !empty($data['allDisPrice'])){
        if (!empty($data['allDisPrice'])) {
            $coupon = [];
            $aCouponDisPrice = [];
            $canUsedCouponTotal = [];
            //取出可以使用的优惠券对应的分类和商品SKU编码
            if (!empty($data['CouponDisArr'])) {
                foreach ($data['CouponDisArr'] as $key => $value) {
                    $coupon[$value['coupon_code']]['category_code'] = $value['coupon_category_code'];
                    $coupon[$value['coupon_code']]['goods_sn'] = $value['coupon_goods_sn'];
                    if (!isset($aCouponDisPrice[$value['coupon_code']])) {
                        $aCouponDisPrice[$value['coupon_code']] = 0;
                    }
                    $aCouponDisPrice[$value['coupon_code']] += $value['dis_price'] ?? 0;
                }
            }
            //计算不同优惠券符合的商品各自的总金额,以便后续算出每个商品在各个券中的占比
            foreach ($data['goods'] as $key => $value) {
                foreach ($data['CouponDisArr'] as $cdKey => $cdValue) {
                    if (!isset($conditionAmount[$cdValue['coupon_code']])) {
                        $conditionAmount[$cdValue['coupon_code']] = 0;
                    }
                    switch ($cdValue['coupon_used']) {
                        case 10:
                            $conditionAmount[$cdValue['coupon_code']] = $data['order_amount'];
                            break;
                        case 20:
                            if (in_array($value['category_code'], explode(',', $cdValue['coupon_category_code']))) {
                                $conditionAmount[$cdValue['coupon_code']] += $value['total_price'];
                            }
                            break;
                        case 30:
                            if (in_array($value['sku_sn'], explode(',', $cdValue['coupon_goods_sn']))) {
                                $conditionAmount[$cdValue['coupon_code']] += $value['total_price'];
                            }
                            break;
                    }
                }
            }

            foreach ($data['goods'] as $key => $value) {
                $oneGoodsTotalPrice = priceFormat($value['price'] * intval($value['number']));
                $data['goods'][$key]['total_price'] = $oneGoodsTotalPrice;
                $data['goods'][$key]['scale'] = [];
                if (!empty($data['CouponDisArr'])) {
                    foreach ($data['CouponDisArr'] as $cdKey => $cdValue) {
                        if (!isset($data['goods'][$key]['scale'][$cdValue['coupon_code']])) {
                            $data['goods'][$key]['scale'][$cdValue['coupon_code']] = 0.001;
                        }
                        //不同券的不同使用场景要分开计算比例,因为分母是不同的
                        switch ($cdValue['coupon_used']) {
                            case 10:
                                $data['goods'][$key]['scale'][$cdValue['coupon_code']] = round($oneGoodsTotalPrice / $data['order_amount'], 2);
                                break;
                            case 20:
                                if (in_array($value['category_code'], explode(',', $cdValue['coupon_category_code']))) {
                                    $data['goods'][$key]['scale'][$cdValue['coupon_code']] = round($oneGoodsTotalPrice / $conditionAmount[$cdValue['coupon_code']], 2);
                                }
                                break;
                            case 30:
                                if (in_array($value['sku_sn'], explode(',', $cdValue['coupon_goods_sn']))) {

                                    $data['goods'][$key]['scale'][$cdValue['coupon_code']] = round($oneGoodsTotalPrice / $conditionAmount[$cdValue['coupon_code']], 2);
                                }
                                break;
                        }
                    }
                }

                //积分抵扣专用的比例
                $data['goods'][$key]['scaleForInt'] = round($oneGoodsTotalPrice / $data['order_amount'], 2);
                if (empty(doubleval($data['goods'][$key]['scaleForInt']))) {
                    $data['goods'][$key]['scaleForInt'] = 0.001;
                }

                //判断当前商品的分类或SKU编码是否符合优惠券的使用场景,如果适合则标识为true,否则为false,且用$canUsedCouponTotal数组来统计每个优惠券对应的可用商品数量为多少,以便后续用来做比例的计算
                if (!empty($coupon)) {
                    $data['goods'][$key]['couponDisDetail'] = [];
                    foreach ($coupon as $cKey => $cValue) {
                        if (!isset($data['goods'][$key]['couponDisDetail'][$cKey])) {
                            $data['goods'][$key]['couponDisDetail'][$cKey] = false;
                        }
                        if (!isset($canUsedCouponTotal[$cKey])) {
                            $canUsedCouponTotal[$cKey] = 0;
                        }
                        if (!empty($cValue['category_code'] || !empty($cValue['goods_sn']))) {
                            if (!empty(array_intersect(explode(',', $value['category_code']), explode(',', $cValue['category_code']))) || !empty(array_intersect([$value['sku_sn']], explode(',', $cValue['goods_sn'])))) {
                                $data['goods'][$key]['couponDisDetail'][$cKey] = true;
                                $canUsedCouponTotal[$cKey] += 1;
                            }
                        } else {
                            $data['goods'][$key]['couponDisDetail'][$cKey] = true;
                            $canUsedCouponTotal[$cKey] += 1;
                        }
                    }

                }
            }

            //根据$canUsedCouponTotal的数据来计算每个商品在每张可使用的优惠券上对应的比例,以便接下来的计算金额
            if (!empty($coupon)) {
                $aDisStayCouponScale = [];
                foreach ($data['goods'] as $key => $value) {
                    foreach ($value['couponDisDetail'] as $couponK => $couponV) {
                        if (!isset($aDisStayCouponScale[$couponK])) {
                            $aDisStayCouponScale[$couponK] = 0;
                        }
                        if (!empty($couponV) && !empty($canUsedCouponTotal[$couponK])) {
                            //均分比例
//                            $data['goods'][$key]['couponDisDetail'][$couponK] = round(1 / $canUsedCouponTotal[$couponK], 2);
                            //按照金额计算比例
                            if ($aDisStayCouponScale[$couponK] + $value['scale'][$couponK] < 1) {
                                $data['goods'][$key]['couponDisDetail'][$couponK] = $value['scale'][$couponK];
                                $aDisStayCouponScale[$couponK] += $value['scale'][$couponK];
                            } else {
                                $data['goods'][$key]['couponDisDetail'][$couponK] = $value['scale'][$couponK] - (($aDisStayCouponScale[$couponK] + $value['scale'][$couponK]) - 1);
                            }
                        } else {
                            $data['goods'][$key]['couponDisDetail'][$couponK] = 0;
                        }
                    }
                }
            }

            //根据金额占比正序排列
            array_multisort(array_column($data['goods'], 'scaleForInt'), SORT_ASC, $data['goods']);
            //积分和优惠券的计算分开来,积分可以根据比例无条件计算,但是分类要根据条件来计算扣除
            //慢慢累加计算折扣的余量,到达最后一个最大比例的商品,那么剩下的折扣金额都是最后一个的
            $stayIntScale = 0;
            $stayIntegralDis = 0;
            foreach ($data['goods'] as $key => $value) {
                $data['goods'][$key]['memberDis'] = priceFormat($value['total_price'] - ($value['member_price'] * intval($value['number'])));
                $amountScale = $value['scaleForInt'];
                $data['goods'][$key]['couponDis'] = 0;
                $data['goods'][$key]['integralDis'] = 0;
                //积分折扣计算扣除规则----如果不超过1则表示还没到最后一个比例的商品
                if ($stayIntScale + $value['scaleForInt'] < 1) {
                    $data['goods'][$key]['integralDis'] = sprintf("%.2f", $data['integralDis'] * $amountScale);
                    $stayIntScale += $value['scaleForInt'];
                    $stayIntegralDis += $data['goods'][$key]['integralDis'];
                } else {
                    $data['goods'][$key]['integralDis'] = priceFormat($data['integralDis'] - $stayIntegralDis);
                }

                //优惠券折扣计算扣除规则----根据上面数组的比例来计算出每个商品对应的可使用优惠券的详细的金额,如果要超出商品总价了只累加剩余可扣的金额,优惠金额不能超过商品总价
                if (!empty($aCouponDisPrice)) {
                    foreach ($value['couponDisDetail'] as $couponKey => $couponValue) {
                        if (!empty($couponValue)) {
                            $thisCouponDis = priceFormat($couponValue * $aCouponDisPrice[$couponKey]);
                            //如果当前优惠+总优惠大于商品总价则判断是否当前优惠是否还有可用的剩余部分,如果有则累加剩余部分的优惠金额,如果全超了直接停止本次循环
                            if ((string)($thisCouponDis + ($data['goods'][$key]['couponDis'] ?? 0)) > (string)$value['total_price']) {
                                if ($value['total_price'] - ($thisCouponDis + ($data['goods'][$key]['couponDis'] ?? 0)) > 0) {
                                    $data['goods'][$key]['couponDis'] += priceFormat($value['total_price'] - $thisCouponDis);
                                }

                                break;
                            } else {
                                $data['goods'][$key]['couponDis'] += priceFormat($couponValue * $aCouponDisPrice[$couponKey]);
                            }
                        }
                    }
                    //去除为了计算数据的数组
                    unset($data['goods'][$key]['couponDisDetail']);
                }
                //计算总折扣金额和实际支付总额
                $data['goods'][$key]['allDisPrice'] = priceFormat($data['goods'][$key]['memberDis'] + $data['goods'][$key]['couponDis'] + $data['goods'][$key]['integralDis']);
                $data['goods'][$key]['realPayPrice'] = priceFormat($value['total_price'] + ($value['total_fare_price'] ?? 0) - $data['goods'][$key]['allDisPrice']);
            }

        } else {
            foreach ($data['goods'] as $key => $value) {
                $data['goods'][$key]['memberDis'] = '0.00';
                $data['goods'][$key]['couponDis'] = '0.00';
                $data['goods'][$key]['integralDis'] = '0.00';
                $data['goods'][$key]['allDisPrice'] = '0.00';
                $data['goods'][$key]['realPayPrice'] = priceFormat($value['price'] * intval($value['number']) + ($value['total_fare_price'] ?? 0));
            }
        }

        //修补优惠券按比例释放的金额精度不准问题,如果超过则扣去多余的部分,如果不够则补足,都是针对价格占比最多的商品
        $allCouponDis = 0;
        $maxKey = count($data['goods']) - 1;
        foreach ($data['goods'] as $key => $value) {
            if (!empty(doubleval($value['couponDis']))) {
                if ($allCouponDis + $value['couponDis'] < $data['allDisPrice']) {
                    if ($key != $maxKey) {
                        $allCouponDis += $value['couponDis'];
                    } else {
                        $data['goods'][$key]['couponDis'] = $data['allDisPrice'] - $allCouponDis;
                        //总优惠金额加上补足的部分,实付金额减去补足的部分
                        $data['goods'][$key]['allDisPrice'] += ($data['goods'][$key]['couponDis'] - $value['couponDis']);
                        $data['goods'][$key]['realPayPrice'] -= ($data['goods'][$key]['couponDis'] - $value['couponDis']);
                    }
                } else {
                    $data['goods'][$key]['couponDis'] = $value['couponDis'] - ($data['allDisPrice'] - ($allCouponDis + $value['couponDis']));
                    //总优惠金额减去超出的部分,实付金额加上超出的部分
                    $data['goods'][$key]['allDisPrice'] -= $data['allDisPrice'] - ($allCouponDis + $value['couponDis']);
                    $data['goods'][$key]['allDisPrice'] += $data['allDisPrice'] - ($allCouponDis + $value['couponDis']);
                }
            }

        }
        if (!empty(doubleval($data['allDisPrice']))) {
            $data['allDisPrice'] = priceFormat($data['allDisPrice']);
        }
        if (!empty(doubleval($data['couponDis']))) {
            $data['couponDis'] = priceFormat($data['couponDis']);
        }

        foreach ($data['goods'] as $key => $value) {
            $value['total_price'] = $value['number'] * $value['price'];
            if (!empty($value['allDisPrice'])) {
                $data['goods'][$key]['allDisPrice'] = priceFormat($value['allDisPrice']);
            }
            if (!empty($value['couponDis'])) {
                $data['goods'][$key]['couponDis'] = priceFormat($value['couponDis']);
            }

            if (!empty($value['total_price'])) {
                if ((string)$value['couponDis'] >= (string)$value['total_price']) {
                    $data['goods'][$key]['couponDis'] = priceFormat($value['total_price']);
                }
                if ((string)$value['allDisPrice'] >= (string)$value['total_price']) {
                    $data['goods'][$key]['allDisPrice'] = priceFormat($value['total_price']);
                }
            }
        }
        return $data;
    }

    /**
     * @title  判断活动需要添加的附属条件
     * @param array $data
     * @param string $activityId
     * @param int $orderType
     * @return bool
     */
    public function activityAttach(array $data, string $activityId, int $orderType)
    {
        $msg = false;
        if ($orderType == 1) {
            $activityInfo = Activity::where(['id' => $activityId, 'status' => 1])->field('id,attach_type')->findOrEmpty()->toArray();
            if (!empty($activityInfo)) {
                switch ($activityInfo['attach_type'] ?? null) {
                    case 1:
                        if (empty($data['attach'])) {
                            throw new OrderException(['errorCode' => 1500116]);
                        }
                        $attach = $data['attach'];
                        if (empty($attach['id_card']) || empty($attach['real_name'])) {
                            throw new OrderException(['errorCode' => 1500117]);
                        }
                        $msg = true;
                        break;
                    case 2:
                        if (empty($data['attach'])) {
                            throw new OrderException(['errorCode' => 1500116]);
                        }
                        $attach = $data['attach'];
                        if (empty($attach['id_card']) || empty($attach['id_card_front']) || empty($attach['id_card_back']) || empty($attach['real_name'])) {
                            throw new OrderException(['errorCode' => 1500117]);
                        }
                        $msg = true;
                        break;
                    case -1:
                        $msg = false;
                        break;
                    default:
                        break;
                }
            }
        }

        return $msg;
    }

    /**
     * @title  判断商品SKU是否需要添加的附属条件
     * @param array $data sku数组
     * @return bool
     */
    public function goodsSkuAttach(array $data)
    {
        $msg = false;
        $attachTypeList = array_column($data['sku'], 'attach_type');
        if (empty($attachTypeList)) {
            return false;
        }
        foreach ($attachTypeList as $key => $value) {
            if ($value == -1) {
                unset($attachTypeList[$key]);
            }
        }
        if (empty($attachTypeList)) {
            return false;
        }
        //获取最大要求的附属条件
        $attachType = max($attachTypeList);

        if (!empty($attachType)) {
            switch ($attachType ?? null) {
                case 1:
                    if (empty($data['attach'])) {
                        throw new OrderException(['errorCode' => 1500116]);
                    }
                    $attach = $data['attach'];
                    if (empty($attach['id_card']) || empty($attach['real_name'])) {
                        throw new OrderException(['errorCode' => 1500117]);
                    }
                    $msg = true;
                    break;
                case 2:
                    if (empty($data['attach'])) {
                        throw new OrderException(['errorCode' => 1500116]);
                    }
                    $attach = $data['attach'];
                    if (empty($attach['id_card']) || empty($attach['id_card_front']) || empty($attach['id_card_back']) || empty($attach['real_name'])) {
                        throw new OrderException(['errorCode' => 1500117]);
                    }
                    $msg = true;
                    break;
                case -1:
                    $msg = false;
                    break;
                default:
                    break;
            }

        }

        return $msg;
    }

    /***
     * @title  提起退单
     * @param array $data
     * @return mixed
     */
    public function systemCancelOrder(array $data)
    {
        $orderSn = $data['order_sn'] ?? null;
        if (empty($orderSn)) {
            throw new OrderException(['msg' => '非法操作']);
        }
        $cancelReason = $data['reason'];
        if (empty($cancelReason)) {
            throw new OrderException(['msg' => '请填写退单原因!']);
        }
        $orderInfo = OrderModel::with(['goods'])->where(['order_sn' => $orderSn, 'order_status' => [1, 2]])->findOrEmpty()->toArray();
        if (empty($orderSn)) {
            throw new OrderException(['errorCode' => 1500124]);
        }
        //如果订单中包含售后申请或售后中的商品,则整单都不允许直接退单
        foreach ($orderInfo as $key => $value) {
            foreach ($value['goods'] as $gKey => $gValue) {
                if (in_array($gValue['after_status'], [2, 3])) {
                    throw new OrderException(['errorCode' => 1500125]);
                }
                if (in_array($gValue['after_status'], [1, -1]) && $gValue['status'] == 1) {
                    $canCancelGoods[] = $gValue;
                }
            }
        }
        if (empty($canCancelGoods)) {
            throw new OrderException(['msg' => '暂无可退单的商品']);
        }
        $allOrderCancel = false;
        if (count($canCancelGoods) == count($orderInfo['goods'])) {
            $allOrderCancel = true;
        }
        foreach ($canCancelGoods as $key => $value) {
            $saveGoods[$value['sku_sn']]['status'] = -4;
        }
//
//        //取消分润和团队业绩
//
//        $pOrder['order_sn'] = $orderSn;
//        $pOrder['uid'] = $orderInfo['uid'];
//        //如果是整单售后则直接取消全部的分润规则
//        if (!empty($allOrderCancel)) {
//            $divideService = (new Divide());
//            $divideRes = $divideService->deductMoneyForDivideByOrderSn($orderInfo['order_sn'], $afInfo['sku_sn'], $orderInfo);
//            //取消团队所有上级的团队业绩
//            $performanceRes = $divideService->getTopUserRecordTeamPerformance($pOrder,2);
//        } else {
//            //如果是部分售后则删除之前的对应商品的分润记录
//            $divideRes = DivideModel::update(['status' => -1, 'arrival_status' => 3, 'arrival_time' => time()], ['order_sn' => $orderInfo['order_sn'], 'arrival_status' => 2, 'goods_sn' => $afInfo['goods_sn'], 'sku_sn' => $afInfo['sku_sn']]);
//            //取消团队所有上级的部分团队业绩
//            $performanceRes = (new Divide())->getTopUserRecordTeamPerformance($pOrder,2,$afInfo['sku_sn']);
//        }
//
//        //取消成长值
//
//        //如果是整单售后则直接取消全部的成长值奖励
//        if (!empty($allOrderCancel)) {
//            $growthRes = (new GrowthValue())->cancelGrowthValue(2, ['order_sn' => $order_sn, 'cancel_type' => 1]);
//        } else {
//            //如果是部分售后则按照当前商品的实付价格换算成成长值,然后减去,如果是最后一个则减去全部剩下的成长值
//            $allGoods = $aOrder['goods'];
//            $afAccessNumber = 0;
//            $allGoodsNumber = count($allGoods);
//            foreach ($allGoods as $key => $value) {
//                //售后成功被关闭的商品
//                if (!empty($value['status']) && $value['status'] == -2) {
//                    $afAccessNumber += 1;
//                }
//            }
//            if ($afAccessNumber + 1 >= $allGoodsNumber) {
//                $growthRes = (new GrowthValue())->cancelGrowthValue(2, ['order_sn' => $order_sn, 'cancel_type' => 1]);
//            } else {
//                $growthRes = (new GrowthValue())->cancelGrowthValue(2, ['order_sn' => $order_sn, 'cancel_type' => 2, 'price_part' => $afInfo['apply_price']]);
//            }
//        }


//        $orderSave['order_status'] = -1;
//        OrderModel::update($orderSave,['order_sn'=>$orderSn]);
//        ShipOrder::update($orderSave,['order_sn'=>$orderSn]);
        return true;
    }

    /**
     * @title  筛选用户未支付订单是否超过可允许的未支付订单总数
     * @param array $data uid 用户uid
     * @return bool
     */
    public function userNotPayOrder(array $data)
    {
        $res = false;
        $uid = $data['uid'] ?? null;
        if (empty($uid)) {
            throw new UserException();
        }
        if (empty($this->userMaxNotPayOrderNumber)) {
            return false;
        }
        $orderCount = OrderModel::where(['pay_status' => 1, 'order_status' => 1, 'uid' => $uid])->count();
        if ($orderCount >= $this->userMaxNotPayOrderNumber) {
            $res = true;
        }

        return $res;
    }

    /**
     * @title  查找普通用户的团队链上最接近的那个会员用户
     * @param array $data
     * @return mixed
     */
    public function findNormalUserPrevMemberByTeam(array $data)
    {
        //返回数据类型 1为只要用户uid 2为要用户全部信息
        $findInfoType = $data['find_type'] ?? 1;
        $uid = $data['link_superior_user'] ?? null;

        if (empty($uid)) {
            return null;
        }
        $linkUserInfo = User::where(['uid' => $uid, 'status' => [1, 2]])->field('uid,vip_level,link_superior_user')->findOrEmpty()->toArray();

        if (empty($linkUserInfo)) {
            return null;
        }
        if (empty($linkUserInfo['vip_level']) && empty($linkUserInfo['link_superior_user'])) {
            return null;
        }

        if (empty($linkUserInfo['vip_level']) && !empty($linkUserInfo['link_superior_user'])) {
            $linkUserInfo = $this->findNormalUserPrevMemberByTeam(['link_superior_user' => $linkUserInfo['link_superior_user'], 'find_type' => 2]);
        }

        if ($findInfoType == 1) {
            $returnInfo = $linkUserInfo['uid'] ?? null;
        } else {
            $returnInfo = $linkUserInfo ?? [];
        }
        return $returnInfo;
    }

    /**
     * @title  生成充值众筹钱包微信支付单
     * @param array $data
     * @return mixed
     */
    public function rechargeForPay(array $data)
    {
        if (!is_numeric($data['price']) || doubleval($data['price'] ?? 0) <= 0) {
            throw new ServiceException(['msg' => '请填写正确的金额']);
        }
        $aUserInfo = User::where(['uid' => $data['uid']])->findOrEmpty()->toArray();

        $newOrder['order_sn'] = (new CodeBuilder())->buildRechargeOrderNo();
        $newOrder['uid'] = $data['uid'];
        $newOrder['belong'] = 1;
        $newOrder['type'] = 1;
        $newOrder['price'] = doubleval($data['price']);
        $newOrder['change_type'] = 1;
        $newOrder['remark'] = '充值';
        $newOrder['status'] = 3;

        //生成订单
        CrowdfundingBalanceDetail::create($newOrder);

        //记录微信支付额外信息
        $newOrder['pay_type'] = 2;
        $newOrder['pay_channel'] = config('system.thirdPayTypeForWxPay') ?? 2;
        $oap_res = (new OrderPayArguments())->new($newOrder);

        //微信支付
        //获取真实的微信用户openid
        $aUserInfo['openid'] = (new User())->getOpenIdByUid($data['uid']);
        if (empty($aUserInfo['openid'] ?? null)) {
            throw new OrderException(['msg' => '微信用户信息有误, 暂无法完成微信支付']);
        }
        $wxOrder['openid'] = $aUserInfo['openid'];
        $wxOrder['out_trade_no'] = $newOrder['order_sn'];
        $wxOrder['body'] = '商城美丽购物订单';
        $wxOrder['attach'] = '美丽购物订单';
        $wxOrder['total_fee'] = doubleval($data['price']);
        //$wxOrder['total_fee'] = 0.01;

        switch (config('system.thirdPayTypeForWxPay') ?? 2) {
            case 1:
                //微信商户号支付
                $wxOrder['notify_url'] = config('system.callback.wxPayCallBackUrl');
                if (substr(getAccessKey(), 0, 1) == 'd') {
                    $wxOrder['trade_type'] = 'APP';
                }
                $wxRes = (new WxPayService())->order($wxOrder);
                break;
            case 2:
                //汇聚支付
                $wxOrder['notify_url'] = config('system.callback.joinPayCallBackUrl');
                $wxRes = (new JoinPay())->order($wxOrder);
                break;
            case 3:
                //衫德支付
                throw new FinanceException(['msg' => '暂不支持的支付商通道']);
                break;
            case 4:
                //银盛支付
                $wxOrder['notify_url'] = config('system.callback.ysePayCallBackUrl');
                $wxRes = (new YsePay())->order($wxOrder);
                break;
            default:
                throw new FinanceException(['msg' => '未知支付商通道']);
        }

        //微信商户号支付
//        $wxOrder['notify_url'] = config('system.callback.wxPayCallBackUrl');
//        $wxRes = (new WxPayService())->order($wxOrder);
        //汇聚支付
//        $wxOrder['notify_url'] = config('system.callback.joinPayCallBackUrl');
//        $wxRes = (new JoinPay())->order($wxOrder);
        $wxRes['need_pay'] = true;
        $wxRes['complete_pay'] = false;
        $cacheOrder = $wxRes;
        $cacheOrder['order_sn'] = $newOrder['order_sn'];
        cache($wxOrder['out_trade_no'], $cacheOrder, $this->orderCacheExpire);


        return $wxRes;
    }

    /**
     * @title  生成充值众筹钱包银行卡协议支付单
     * @param array $data
     * @return mixed
     */
    public function rechargeForAgreementPay(array $data)
    {
        if (!is_numeric($data['price']) || doubleval($data['price'] ?? 0) <= 0) {
            throw new ServiceException(['msg' => '请填写正确的金额']);
        }
        //协议支付校验
        $this->agreementPayCheck($data);

        $newOrder['order_sn'] = (new CodeBuilder())->buildRechargeOrderNo();
        $newOrder['uid'] = $data['uid'];
        $newOrder['belong'] = 1;
        $newOrder['type'] = 1;
        $newOrder['price'] = doubleval($data['price']);
        $newOrder['change_type'] = 1;
        $newOrder['remark'] = '充值';
        $newOrder['status'] = 3;
        $newOrder['create_time'] = time();
        $newOrder['pay_type'] = 6;
        $newOrder['pay_channel'] = $data['pay_channel'] ?? (config('system.thirdPayType') ?? 2);

        //生成订单
        CrowdfundingBalanceDetail::create($newOrder);

        //协议支付
        $wxOrder['out_trade_no'] = $newOrder['order_sn'];
        $wxOrder['body'] = '商城美丽购物订单';
        $wxOrder['attach'] = '商城美丽订单';
        $wxOrder['total_fee'] = doubleval($data['price']);
        $wxOrder['order_create_time'] = $newOrder['create_time'];
        $wxOrder['sign_no'] = $data['sign_no'];
        $wxOrder['uid'] = $newOrder['uid'];
        //$wxOrder['total_fee'] = 0.01;

//        $wxOrder['notify_url'] = config('system.callback.joinPayAgreementCallBackUrl');

        //协议支付
        switch (config('system.thirdPayType') ?? 2) {
            case 2:
                $wxOrder['notify_url'] = config('system.callback.joinPayAgreementCallBackUrl');
                break;
            case 3:
                if ($wxOrder['total_fee'] < 0.1) {
                    throw new FinanceException(['msg' => '最低支付金额必须大于0.1元']);
                }
                $wxOrder['notify_url'] = config('system.callback.sandPayAgreementCallBackUrl');
                break;
            case 4:
                $wxOrder['notify_url'] = config('system.callback.ysePayAgreementCallBackUrl');
                break;
            default:
                throw new FinanceException(['msg' => '暂不支持的支付商通道']);
                break;
        }
        //下发支付短信验证码
        $wxRes = $this->paySms($wxOrder);
        $wxRes['need_pay'] = true;
        $wxRes['complete_pay'] = false;
        $cacheOrder = $wxOrder;
        $cacheOrder['order_sn'] = $newOrder['order_sn'];
        $cacheOrder['order_status'] = 1;
        $cacheOrder['pay_status'] = 1;
        cache($this->agreementCrowdOrderCacheHeader . $newOrder['order_sn'], $cacheOrder, 120);

        return $cacheOrder;
    }

    /**
     * @title  判断用户支付密码和银行卡是否签约
     * @param array $data
     * @return bool
     */
    public function agreementPayCheck(array $data)
    {
        //需要校验支付密码
        $userInfo = $this->getUserInfo($data['uid']);
        if (empty(trim($data['sign_no'] ?? null))) {
            throw new ServiceException(['msg' => '请选择支付的银行卡']);
        }

        //先验证该银行卡是否签约
        $cardContractInfo = UserBankCard::where(['sign_no' => $data['sign_no'], 'uid' => $data['uid'], 'status' => 1, 'contract_status' => 1])->order('id desc')->findOrEmpty()->toArray();
        if (empty($cardContractInfo)) {
            throw new ServiceException(['msg' => '银行卡选择错误!']);
        }
        if (empty($userInfo['pay_pwd'])) {
            throw new UserException(['msg' => '请您先设置支付密码']);
        }
        $cacheKey = 'payPwdError-' . $data['uid'];
        if (md5($data['pay_pwd']) != $userInfo['pay_pwd'] && $data['pay_pwd'] != $userInfo['pay_pwd']) {
            $errorLimitNumber = 5;
            $nowCache = cache($cacheKey);
            if (!empty($nowCache)) {
                if ($nowCache >= $errorLimitNumber) {
                    $lastNumber = 0;
                    throw new ServiceException(['msg' => '支付操作当日已被冻结!']);
                } else {
                    $lastNumber = $errorLimitNumber - $nowCache - 1;
                    Cache::inc($cacheKey);
                }
            } else {
                cache($cacheKey, 1, (24 * 3600));
                $lastNumber = $errorLimitNumber - 1;
            }
            throw new ServiceException(['msg' => '支付密码错误,请重试。今日剩余次数 ' . $lastNumber]);
        }
        //密码正确重置次数
        cache($cacheKey, null);
        return true;
    }

    /**
     * @title  重新下发支付短信验证码
     * @param array $data
     * @return bool
     */
    public function paySendSmsAgain(array $data)
    {
        if (in_array(config('system.thirdPayType'), [1, 3])) {
            throw new FinanceException(['msg' => '暂不支持的支付商通道']);
        }
        $orderSn = $data['order_sn'];
        //订单来源 1为普通订单 2为充值订单
        $orderChannel = $data['order_channel'] ?? 1;
        if ($orderChannel) {
            $orderInfo = OrderModel::where(['order_sn' => $orderSn, 'pay_status' => 1])->field('order_sn,real_pay_price,create_time')->findOrEmpty()->toArray();
        } else {
            $orderInfo = CrowdfundingBalanceDetail::where(['order_sn' => $orderSn, 'status' => 3, 'pay_type' => 6, 'change_type' => 1])->field('order_sn,pride as real_pay_price,create_time')->findOrEmpty()->toArray();
        }

        if (empty($orderInfo)) {
            throw new ServiceException(['msg' => '查无订单']);
        }
        //协议(银行卡)支付
        if (empty($data['sign_no'] ?? null)) {
            throw new ServiceException(['msg' => '请选择支付的银行卡']);
        }
        $cardInfo = UserBankCard::where(['sign_no' => $data['sign_no'], 'status' => 1, 'contract_status' => 1])->count();
        if (empty($cardInfo)) {
            throw new ServiceException(['msg' => '请选择已成功签约的银行卡']);
        }

        $wxOrder['out_trade_no'] = $orderInfo['order_sn'];
        $wxOrder['body'] = '商城购物订单';
        $wxOrder['attach'] = '商城订单';
        $wxOrder['total_fee'] = $orderInfo['real_pay_price'];
        $wxOrder['order_create_time'] = strtotime($orderInfo['create_time']);
        $wxOrder['sign_no'] = $data['sign_no'];
        $wxOrder['uid'] = $orderInfo['uid'];
        //$wxOrder['total_fee'] = 0.01;
        //协议支付
        $wxOrder['notify_url'] = config('system.callback.joinPayAgreementCallBackUrl');

        //下发支付短信验证码
        $wxRes = $this->paySms($wxOrder);
        $wxRes['need_pay'] = true;
        $wxRes['complete_pay'] = false;
        $cacheOrder = $wxOrder;
        $cacheOrder['order_sn'] = $orderInfo['order_sn'];
        $cacheOrder['order_status'] = 1;
        $cacheOrder['pay_status'] = 1;
        cache($this->agreementOrderCacheHeader . $orderInfo['order_sn'], $cacheOrder, 120);
        return true;
    }


    /**
     * @title  支付验证码
     * @param array $data
     * @return mixed
     */
    public function paySms(array $data)
    {
        $orderInfo['out_trade_no'] = $data['out_trade_no'];
        $orderInfo['total_fee'] = $data['total_fee'];
        $orderInfo['notify_url'] = $data['notify_url'];
        $orderInfo['sign_no'] = $data['sign_no'];
        $orderInfo['order_create_time'] = $data['order_create_time'];
        $orderInfo['body'] = $data['body'] ?? null;
        $orderInfo['attach'] = $data['attach'] ?? null;
        if (!empty($data['map'] ?? null)) {
            $orderInfo['map'] = $data['map'];
        }
        $res = false;

        switch (config('system.thirdPayType') ?? 2) {
            case 1:
                throw new FinanceException(['msg' => '暂不支持的支付商通道']);
                break;
            case 2:
                $orderInfo['notify_url'] = config('system.callback.joinPayAgreementCallBackUrl');
                $sendSms = (new JoinPay())->agreementPaySms($orderInfo);
                break;
            case 3:
                $orderInfo['notify_url'] = config('system.callback.sandPayAgreementCallBackUrl');
                $cardInfo = UserBankCard::where(['sign_no' => $data['sign_no'], 'status' => 1])->field('sign_no,uid,bank_phone')->findOrEmpty()->toArray();
                if (empty($cardInfo)) {
                    throw new SandPayException(['msg' => '查无有效签约银行卡']);
                }
                $orderInfo['bank_phone'] = $cardInfo['bank_phone'];
                //杉德支付订单创建不在下发短信的接口, 在后续的验证短信的接口, 所以此处缓存相关订单信息, 方便后续调用
                cache('sandPayAgreementPaySmsOrderInfo-' . $data['out_trade_no'], $orderInfo, 900);
                $orderInfo['uid'] = $data['uid'];
                $sendSms = (new SandPay())->agreementPaySms($orderInfo);
                break;
            case 4:
                //银盛支付
                $cardInfo = UserBankCard::where(['sign_no' => $data['sign_no'], 'status' => 1])->field('sign_no,uid,bank_phone,cvv,expire_date')->findOrEmpty()->toArray();
                if (empty($cardInfo)) {
                    throw new YsePayException(['msg' => '查无有效签约银行卡']);
                }
                if (!empty(trim($cardInfo['cvv']))) {
                    $orderInfo['cvv'] = $cardInfo['cvv'];
                    $orderInfo['expire_date'] = $cardInfo['expire_date'];
                }
                $orderInfo['order_expire'] = ceil($this->orderCacheExpire / 60);
                $orderInfo['uid'] = $data['uid'];
                $orderInfo['notify_url'] = config('system.callback.ysePayAgreementCallBackUrl');
                $sendSms = (new YsePay())->agreementPaySms($orderInfo);
                break;
            default:
                throw new FinanceException(['msg' => '未知支付商通道']);
        }
        if (!empty($sendSms) && !empty($sendSms['res'])) {
            $res = true;
        } else {
            //失败了直接取消平台订单
            $cancelOrder['order_sn'] = $data['out_trade_no'];
            $cancelOrder['token_uid'] = $data['uid'];
            $cancelOrder['coder_remark'] = '银行卡支付失败, 原因: ' . $sendSms['errorMsg'] ?? '支付出错啦~,具体错误请查询日志';
            $cancelOrder['order_remark'] = '银行卡支付失败, 原因: ' . $sendSms['errorMsg'] ?? '支付出错啦~';
            (new Order())->cancelPay($cancelOrder);

            throw new ServiceException(['msg' => $sendSms['errorMsg'] ?? '支付验证码下发出错啦~']);
        }
        return ['smsRes' => judge($res), 'data' => $data];
    }


    /**
     * @title  协议支付验证支付短信验证码
     * @param array $data
     * @return bool
     */
    public function agreementVerifyPaySmsCode(array $data)
    {
        if (empty($data['order_sn'] ?? null) || empty($data['sms_code'] ?? null)) {
            throw new ServiceException(['msg' => '请输入验证码哦~']);
        }
        if (!is_numeric($data['sms_code']) || strlen($data['sms_code']) != 6) {
            throw new ServiceException(['msg' => '验证码必须为六位数字']);
        }
        $orderSn = $data['order_sn'];
        $cacheKey = 'agPaySms' . $orderSn;
        if (!empty(cache($cacheKey))) {
            throw new ServiceException(['msg' => '订单正在执行中, 请勿重复请求']);
        }
        cache($cacheKey, $data, 120);

        $orderChannel = $data['order_channel'] ?? 1;
        if($orderChannel == 1){
            $orderCreateTime = OrderModel::where(['order_sn' => $orderSn])->value('create_time');
        }else{
            $orderCreateTime = CrowdfundingBalanceDetail::where(['order_sn' => $orderSn])->value('create_time');
        }
        if (empty($orderCreateTime)) {
            cache($cacheKey,null);
            throw new ServiceException(['msg' => '订单信息有误']);
        }

        $verifyData['out_trade_no'] = $orderSn;
        $verifyData['sms_code'] = trim($data['sms_code']);
        $verifyData['order_create_time'] = $orderCreateTime;

        switch (config('system.thirdPayType') ?? 2) {
            case 1:
                throw new FinanceException(['msg' => '暂不支持的支付商通道']);
                break;
            case 2:
                $verifyRes = (new JoinPay())->agreementSmsPay($verifyData);
                break;
            case 3:
                $cacheOrderInfo = cache('sandPayAgreementPaySmsOrderInfo-' . $data['order_sn']);

                if (empty($cacheOrderInfo)) {
                    throw new SandPayException(['msg' => '订单信息存在异常, 请退出重新下单']);
                }
                $verifyData = array_merge($verifyData, $cacheOrderInfo);

                $verifyData['uid'] = $data['uid'];
                $verifyRes = (new SandPay())->agreementSmsPay($verifyData);
                break;
            case 4:
                $cacheOrderInfo = cache('ysePayAgreementPaySmsOrderInfo-' . $data['order_sn']);

                if (empty($cacheOrderInfo)) {
                    throw new SandPayException(['msg' => '订单信息存在异常, 请退出重新下单']);
                }
                $verifyData = array_merge($verifyData, $cacheOrderInfo);

                $verifyData['uid'] = $data['uid'];
                $verifyRes = (new YsePay())->agreementSmsPay($verifyData);
                break;
            default:
                throw new FinanceException(['msg' => '未知支付商通道']);
        }

        if (!empty($verifyRes) && !empty($verifyRes['res'])) {
            cache($cacheKey,null);
            return true;
        } else {
            $needCancelOrder = true;
            //根据不通过的错误失败码做对应的业务操作
            if (!empty($verifyRes['orderResData'] ?? [])) {
                if (!empty($verifyRes['err_code'] ?? null)) {
                    switch ($verifyRes['err_code']) {
                        case 'JS120003':
                            //卡已被解约或未签约, 需要解约这张卡
                            UserBankCard::update(['contract_status' => 3, 'coder_remark' => '该卡在尝试支付订单 ' . $orderSn . ' 时发现卡已被解约或未签约, 强行处理为此卡解约状态'], ['sign_no' => $data['sign_no']]);
                            break;
                        case 'JS120001':
                            cache($cacheKey,null);
                            //验证码错误
                            throw new ServiceException(['errorCode' => 2300110]);
                            break;
                        default:
                            break;
                    }
                }
            }

            if (!empty($needCancelOrder)) {
                //失败了订单不允许成功, 需要取消订单
                $errorMsg = '银行卡支付失败, 原因: ' . $verifyRes['errorMsg'] ?? '支付出错啦~,具体错误请查询日志';
                if ($orderChannel == 1) {
                    $cancelOrder['order_sn'] = $data['order_sn'];
                    $cancelOrder['token_uid'] = $data['uid'];
                    $cancelOrder['coder_remark'] = $errorMsg;
                    $cancelOrder['order_remark'] = $errorMsg;
                    (new Order())->cancelPay($cancelOrder);
                } else {
                    CrowdfundingBalanceDetail::update(['remark' => $errorMsg], ['order_sn' => $orderSn, 'status' => 3]);
                }
            }

            cache($cacheKey,null);
            throw new ServiceException(['msg' => $verifyRes['errorMsg'] ?? '验证出错啦, 请稍后重试']);
        }
    }

    /**
     * @title  获取用户信息
     * @param string $uid
     * @return array
     */
    public function getUserInfo(string $uid)
    {
        $userInfo = User::where(['uid' => $uid, 'status' => 1])->field('uid,phone,name,pay_pwd')->findOrEmpty()->toArray();
        if (empty($userInfo)) {
            throw new UserException();
        }
        return $userInfo;
    }

    /**
     * @title  判断用户是否有可以参与美丽金专区的权限
     * @param string $uid
     * @return mixed
     */
    public function checkUserCanBuyCrowdActivityGoods(array $data)
    {
        //财务号
        $financeUid = SystemConfig::where(['id' => 1])->value('finance_uid');
        $withdrawFinanceAccount = !empty($financeUid) ? explode(',', $financeUid) : ['notFinanceUid'];
//        $withdrawFinanceAccount = ['WbzBXu6Bhy', '8NhXjx7TGr', '08bHybNaW1', 'npuh1syedM', '8q3Mn4dWDb', '32Po7PAmOq', 'bnNtLgLO0l', 'SD55DQSPPr', 'OQ3dxIHWn9'];
        //白名单用户可以直接用美丽金购买
//        $bawUser = BawList::where(['uid' => $data['uid'], 'channel' => 5, 'status' => 1, 'type' => 1])->value('id');
//        if (!empty($bawUser)) {
//            return true;
//        }
        //黑名单用户
        $blackUser = BawList::where(['uid' => $data['uid'], 'channel' => 5, 'status' => 1, 'type' => 2])->value('id');
        if (!empty($blackUser)) {
            throw new ServiceException(['msg' => '非常抱歉, 你非本次活动对象,感谢您的支持']);
        }
        return true;
//        //查看用户总提现金额
//        $hwMap[] = ['uid', '=', $data['uid']];
//        $hwMap[] = ['withdraw_type', '=', 7];
//        $hwMap[] = ['payment_status', '=', 1];
//        $hwMap[] = ['check_status', '=', 1];
//        $hwMap[] = ['status', '=', 1];
//        $userTotalWithdraw = Withdraw::where($hwMap)->sum('total_price');
//
//        //查看用户累计充值金额
//        $rMap[] = ['uid', '=', $data['uid']];
//        $rMap[] = ['type', '=', 1];
//        $rMap[] = ['status', '=', 1];
//        $userTotalRecharge = CrowdfundingBalanceDetail::where($rMap)->where(function ($query) use ($withdrawFinanceAccount) {
//            $map1[] = ['change_type', '=', 1];
//            $map2[] = ['is_transfer', '=', 1];
//            $map2[] = ['transfer_from_uid', 'in', $withdrawFinanceAccount];
//            $query->whereOr([$map1, $map2]);
//        })->sum('price');
//        if ((string)$userTotalRecharge <= (string)$userTotalWithdraw) {
//            throw new ServiceException(['msg' => '你非本次活动对象,感谢您的支持']);
//        }
//
//        //团队会员经理级别以上不允许购买
//        $useTeamVipLevel = User::where(['uid' => $data['uid'], 'status' => 1])->value('team_vip_level');
//        if (intval($useTeamVipLevel) > 0 && intval($useTeamVipLevel) <= 3) {
//            throw new ServiceException(['msg' => '抱歉你非本次活动对象,感谢您的支持~']);
//        }
//        return true;
    }

    /**
     * @title  通过配置文件获取默认允许的支付方式
     * @return mixed
     */
    public function getDefaultAllowPayType()
    {
        return config('system.defaultAllowPayType') ?? [1, 6];
    }

    /**
     * @title  通过配置文件获取默认的支付方式
     * @return mixed
     */
    public function getDefaultPayType()
    {
        return config('system.defaultPayType') ?? ($this->defaultPayType ?? 6);
    }


}