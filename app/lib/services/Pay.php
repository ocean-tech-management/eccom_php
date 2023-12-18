<?php
// +----------------------------------------------------------------------
// |[ 文档说明: 支付核心业务Service]
// +----------------------------------------------------------------------
// | Copyright (c) 2018~2024 http://www.mlhcmk.com All rights reserved.
// +----------------------------------------------------------------------


namespace app\lib\services;


use app\lib\exceptions\FinanceException;
use app\lib\exceptions\OrderException;
use app\lib\exceptions\ServiceException;
use app\lib\models\AdvanceCardDetail;
use app\lib\models\AfterSale;
use app\lib\models\AfterSaleDetail;
use app\lib\models\BalanceDetail;
use app\lib\models\CrowdfundingBalanceDetail;
use app\lib\models\CrowdfundingTicketDetail;
use app\lib\models\Device;
use app\lib\models\DeviceOrder;
use app\lib\models\Divide as DivideModel;
use app\lib\models\ExchangeGoodsSku;
use app\lib\models\GoodsSku;
use app\lib\models\Handsel;
use app\lib\models\HealthyBalanceDetail;
use app\lib\models\IntegralDetail;
use app\lib\models\Member;
use app\lib\models\MemberOrder;
use app\lib\models\MemberVdc;
use app\lib\models\Order;
use app\lib\models\OrderCoupon;
use app\lib\models\OrderGoods;
use app\lib\models\PpylActivity;
use app\lib\models\PpylArea;
use app\lib\models\PpylAuto;
use app\lib\models\PpylCvipOrder;
use app\lib\models\PpylOrder;
use app\lib\models\PpylOrderGoods;
use app\lib\models\PpylWaitOrder;
use app\lib\models\PtActivity;
use app\lib\models\PtGoodsSku;
use app\lib\models\PtOrder;
use app\lib\models\RechargeLink;
use app\lib\models\RechargeLinkDetail;
use app\lib\models\RefundDetail;
use app\lib\models\ShipOrder;
use app\lib\models\SystemConfig;
use app\lib\models\TeamPerformance;
use app\lib\models\User;
use app\lib\models\UserCoupon;
use app\lib\models\Coupon;
use app\lib\services\Member as MemberService;
use think\facade\Cache;
use think\facade\Db;
use think\facade\Queue;
use app\lib\constant\PayConstant;
use app\lib\models\HealthyBalance;

class Pay
{
    /**
     * @title  完成支付订单
     * @param array $callbackData 包含订单号和流水号
     * @return mixed
     * @throws \Exception
     */
    public function completePay(array $callbackData)
    {
        $res = Db::transaction(function () use ($callbackData) {
            $order_sn = $callbackData['out_trade_no'];
            $pay_no = $callbackData['transaction_id'];
            $aOrderInfo = (new Order())->info(['order_sn' => $order_sn]);
            if (empty($aOrderInfo)) {
                //包含CZ的订单为充值订单, 需要单独处理订单
                if (strstr($order_sn, 'CZ')) {
                    $this->completeRecharge(['order_sn' => $order_sn, 'pay_no' => $pay_no]);
                    return true;
                }
                //包含C的订单为设备订单, 需要单独处理订单
                if (strstr($order_sn, 'D')) {
                    $this->completeDevicePay(['out_trade_no' => $order_sn, 'transaction_id' => $pay_no]);
                    return true;
                }
                //如果支付的时候有传额外参数判断为拼拼有礼订单则直接进入拼拼有礼专属的完成订单,如果没有则尝试查找是否存在拼拼有礼订单
                $ppylComplete = false;
                if (!empty($callbackData['otherMap'] ?? null)) {
                    if ($callbackData['otherMap'] == 'ppyl') {
                        $ppylRes = $this->completePpylPay($callbackData);
                    }
                    if ($callbackData['otherMap'] == 'ppylWait') {
                        $ppylRes = $this->completePpylWaitPay($callbackData);
                    }
                } else {
                    $existPpylOrder = PpylOrder::where(['order_sn' => $order_sn])->count();
                    if (!empty($existPpylOrder)) {
                        $ppylRes = $this->completePpylPay($callbackData);
                    } else {
                        $existPpylWaitOrder = PpylWaitOrder::where(['order_sn' => $order_sn])->count();
                        if (!empty($existPpylWaitOrder)) {
                            $ppylRes = $this->completePpylWaitPay($callbackData);
                        }
                    }

                }

                if (!empty($ppylRes)) {
                    return judge($ppylRes);
                }

                return false;
            }
            $aOrderCoupons = OrderCoupon::where(['order_sn' => $order_sn, 'used_status' => 1])->field('coupon_uc_code,coupon_code')->select()->toArray();
            $orderGoods = OrderGoods::where(['order_sn' => $order_sn])->field('goods_sn,sku_sn,user_level,price,count,total_price,title,crowd_code,crowd_round_number,crowd_period_number,real_pay_price,gift_type,gift_number')->select()->toArray();
            $goodsTitle = implode(',', array_column($orderGoods, 'title'));
            $goodsNumber = array_sum(array_column($orderGoods, 'count'));
            $aOrderUcCoupon = array_column($aOrderCoupons, 'coupon_uc_code');
            $aOrderCoupon = array_column($aOrderCoupons, 'coupon_code');
            $aOrder['pay_no'] = $pay_no;
            $aOrder['pay_status'] = 2;
            $aOrder['order_status'] = 2;
            $aOrder['pay_time'] = time();
            if (!empty($callbackData['bank_trx_no'] ?? null)) {
                $aOrder['bank_pay_no'] = $callbackData['bank_trx_no'];
            }
            if (!empty($callbackData['pay_success_time'] ?? null)) {
                $aOrder['pay_time'] = strtotime($callbackData['pay_success_time']);
            }
            //$aOrder['end_time'] = time();
            //修改订单状态为已支付
            $orderRes = Order::update($aOrder, ['order_sn' => $order_sn, 'pay_status' => 1, 'order_status' => 1]);
            //修改订单商品状态为已支付
            $orderGoodsRes = OrderGoods::update(['pay_status' => 2], ['order_sn' => $order_sn, 'pay_status' => 1]);
            //如果支付方式是协议支付则往协议订单缓存中修改订单状态为已支付, 方便C端嗅探针
            if ($aOrderInfo['pay_type'] == 6) {
                $newCache = $aOrder;
                $newCache['order_sn'] = $order_sn;
                cache((new \app\lib\services\Order())->agreementOrderCacheHeader . $order_sn, $newCache, 120);
            }
            //非众筹订单(目前只有团长大礼包和普通订单和福利专区订单有)如果订单商品有附属赠送的则添加对应的赠送记录
            //团长大礼包由于历史功能已存在赠送成长值, 故在此不做赠送成长值操作
            $aGiftIntegral = [];
            $aGiftHealthy = [];
            $aGiftCrowd = [];
            if (in_array($aOrderInfo['order_type'], [1, 3])) {
                $healthyChannelType = null;
                foreach ($orderGoods as $key => $value) {
//                    if ($aOrderInfo['order_type'] == 6 && (string)$value['gift_number'] > 0) {
//                        //判断购物赠送的美丽金是否参与够福利专区, 如果不够则不允许赠送
//                        $userGiftCrowdBalance = [];
//                        $lastGiftCrowd = 0;
//                        $userGiftCrowdBalance = (new CrowdfundingBalanceDetail())->getUserNormalShoppingSendCrowdBalance(['uid' => $aOrderInfo['uid']]);
//                        if (empty($userGiftCrowdBalance['res'] ?? false)) {
//                            $value['gift_number'] = 0;
//                        } else {
//                            $lastGiftCrowd = ($userGiftCrowdBalance['crowd_price'] ?? 0) - ($userGiftCrowdBalance['gift_price'] ?? 0);
//                            if ((string)$lastGiftCrowd < (string)$value['gift_number']) {
//                                if (doubleval($lastGiftCrowd) > 0) {
//                                    $value['gift_number'] = priceFormat($lastGiftCrowd);
//                                }
//                            }
//                        }
//                    }
                    if ($value['gift_type'] > -1 && (string)$value['gift_number'] > 0) {
                        switch ($value['gift_type']) {
                            case 1:
                                if (!empty(doubleval($value['gift_number'] ?? 0)) > 0) {
                                    $aGiftIntegral[$key]['order_sn'] = $order_sn;
                                    $aGiftIntegral[$key]['integral'] = $value['gift_number'];
                                    $aGiftIntegral[$key]['type'] = 1;
                                    $aGiftIntegral[$key]['change_type'] = 6;
                                    $aGiftIntegral[$key]['remark'] = '商城购物赠送';
                                    $aGiftIntegral[$key]['goods_sn'] = $value['goods_sn'];
                                    $aGiftIntegral[$key]['sku_sn'] = $value['sku_sn'];
                                    $aGiftIntegral[$key]['uid'] = $aOrderInfo['uid'];
                                    $aGiftIntegral[$key]['crowd_code'] = $value['crowd_code'] ?? null;
                                    $aGiftIntegral[$key]['crowd_round_number'] = $value['crowd_round_number'] ?? null;
                                    $aGiftIntegral[$key]['crowd_period_number'] = $value['crowd_period_number'] ?? null;
                                }
                                break;
                            case 2:
                                $aGiftHealthy[$key]['order_sn'] = $order_sn;
                                $aGiftHealthy[$key]['uid'] = $aOrderInfo['uid'];
                                $aGiftHealthy[$key]['pay_no'] = $pay_no;
                                $aGiftHealthy[$key]['price'] = $value['gift_number'];
                                $aGiftHealthy[$key]['type'] = 1;
                                $aGiftHealthy[$key]['change_type'] = 1;
                                $aGiftHealthy[$key]['belong'] = 1;
                                $aGiftHealthy[$key]['remark'] = '商城购物赠送';
                                $aGiftHealthy[$key]['goods_sn'] = $value['goods_sn'];
                                $aGiftHealthy[$key]['sku_sn'] = $value['sku_sn'];
                                $aGiftHealthy[$key]['pay_type'] = 77;
                                $aGiftHealthy[$key]['crowd_code'] = $value['crowd_code'] ?? null;
                                $aGiftHealthy[$key]['crowd_round_number'] = $value['crowd_round_number'] ?? null;
                                $aGiftHealthy[$key]['crowd_period_number'] = $value['crowd_period_number'] ?? null;
                                //判断渠道, 非福利渠道的都是商城渠道, 只有充值的是消费型股东渠道
                                switch ($aOrderInfo['order_type'] ?? 6) {
                                    case '6':
                                        $aGiftHealthy[$key]['healthy_channel_type'] = PayConstant::HEALTHY_CHANNEL_TYPE_CROWD;
                                        break;
                                    default:
                                        $aGiftHealthy[$key]['healthy_channel_type'] = PayConstant::HEALTHY_CHANNEL_TYPE_SHOP;
                                        break;
                                }
                                $healthyChannelType = $aGiftHealthy[$key]['healthy_channel_type'];
                                break;
                            case 3:
                                $aGiftCrowd[$key]['order_sn'] = $order_sn;
                                $aGiftCrowd[$key]['uid'] = $aOrderInfo['uid'];
                                $aGiftCrowd[$key]['pay_no'] = $pay_no;
                                $aGiftCrowd[$key]['price'] = $value['gift_number'];
                                $aGiftCrowd[$key]['type'] = 1;
                                $aGiftCrowd[$key]['change_type'] = 1;
                                $aGiftCrowd[$key]['belong'] = 1;
                                $aGiftCrowd[$key]['remark'] = '充值';
                                $aGiftCrowd[$key]['pay_type'] = 77;
                                $aGiftCrowd[$key]['crowd_code'] = $value['crowd_code'] ?? null;
                                $aGiftCrowd[$key]['crowd_round_number'] = $value['crowd_round_number'] ?? null;
                                $aGiftCrowd[$key]['crowd_period_number'] = $value['crowd_period_number'] ?? null;
                                break;
                        }
                    }
                }
                //添加赠送积分(美丽豆)明细
                if (!empty($aGiftIntegral ?? [])) {
                    foreach ($aGiftIntegral as $key => $value) {
                        User::where(['status' => 1, 'uid' => $value['uid']])->inc('integral', $value['integral'])->update();
                    }
                    (new IntegralDetail())->saveAll(array_values($aGiftIntegral));
                }

                //添加赠送健康豆明细
                if (!empty($aGiftHealthy ?? [])) {
                    $userGiftHealthy = [];
                    $needUpdateGiftHealthyUser = [];
                    foreach ($aGiftHealthy as $key => $value) {
                        User::where(['status' => 1, 'uid' => $value['uid']])->inc('healthy_balance', $value['price'])->update();
                        //统计所有明细每个人用户得到的健康豆, 插入健康豆渠道表
                        if (!isset($userGiftHealthy[$value['uid']])) {
                            $userGiftHealthy[$value['uid']] = 0;
                        }
                        $userGiftHealthy[$value['uid']] += $value['price'];
                    }
                    //新增健康豆明细
                    (new HealthyBalanceDetail())->saveAll(array_values($aGiftHealthy));

                    //添加或新增健康豆渠道冗余表
                    //查询每个人在健康豆福利渠道是否存在数据, 如果不存在则新增, 存在则自增
                    if (!empty($userGiftHealthy)) {
                        $existHealthyChannel = HealthyBalance::where(['uid' => array_keys($userGiftHealthy), 'channel_type' => ($healthyChannelType ?? 2), 'status' => 1])->column('uid');
                        foreach ($userGiftHealthy as $key => $value) {
                            if (in_array($key, $existHealthyChannel)) {
                                $needUpdateGiftHealthyUser[$key] = $value;
                            } else {
                                $newGiftHealthyUser[$key] = $value;
                            }
                        }

                        if (!empty($needUpdateGiftHealthyUser ?? [])) {
                            foreach ($needUpdateGiftHealthyUser as $key => $value) {
                                if (doubleval($value) <= 0) {
                                    unset($needUpdateGiftHealthyUser[$key]);
                                }
                            }
                        }

                        $updateUserHealthySql = 'update sp_healthy_balance set balance = CASE uid ';
                        $updateUserHealthySqlMore = [];
                        $allUidSql = "('" . implode("','", array_unique(array_keys($userGiftHealthy))) . "')";
                        //更新的用户拼接sql
                        if (!empty($needUpdateGiftHealthyUser ?? [])) {
                            //计算，切割健康豆部分的金额sql
                            foreach ($needUpdateGiftHealthyUser as $key => $value) {
                                if (doubleval($value) <= 0) {
                                    unset($needUpdateGiftHealthyUser[$key]);
                                }
                            }
                            $healthyNumber = 0;
                            foreach ($needUpdateGiftHealthyUser as $key => $value) {
                                if ($healthyNumber >= 500) {
                                    if ($healthyNumber % 500 == 0) {
                                        $updateUserHealthyHeaderSql = 'update sp_healthy_balance set balance = CASE uid ';
                                    }
                                    $updateUserHealthySqlMore[intval($healthyNumber / 500)] = $updateUserHealthyHeaderSql .= " WHEN '" . ($key ?? 'notfound') . "' THEN (balance + " . ($value ?? 0) . ")";
                                    $updateUserHealthySqlMoreUid[intval($healthyNumber / 500)][] = ($key ?? 'notfound');
                                    unset($needUpdateGiftHealthyUser[$key]);
                                } else {
                                    $updateUserHealthySql .= " WHEN '" . ($key ?? 'notfound') . "' THEN (balance + " . ($value ?? 0) . ")";
                                    unset($needUpdateGiftHealthyUser[$key]);
                                }
                                $healthyNumber += 1;
                            }

                            $updateUserHealthySql .= ' ELSE (balance + 0) END WHERE uid in ' . $allUidSql . ' AND channel_type = ' . ($healthyChannelType ?? 2) . ' AND status = 1';

                            if (!empty($updateUserHealthySqlMore ?? [])) {
                                foreach ($updateUserHealthySqlMore as $key => $value) {
                                    $updateUserHealthySqlMore[$key] .= ' ELSE (balance + 0) END WHERE uid in ' . "('" . implode("','", $updateUserHealthySqlMoreUid[$key]) . "')" . ' AND channel_type = ' . ($healthyChannelType ?? 2) . ' AND status = 1';
                                }
                            }

                            if (!empty($updateUserHealthySql)) {
                                Db::query($updateUserHealthySql);
                                if (!empty($updateUserHealthySqlMore ?? [])) {
                                    foreach ($updateUserHealthySqlMore as $key => $value) {
                                        Db::query($value);
                                    }
                                }
                            }
                        }

                        if (!empty($newGiftHealthyUser ?? [])) {
                            foreach ($newGiftHealthyUser as $key => $value) {
                                $newGiftHealthyData[$key]['uid'] = $key;
                                $newGiftHealthyData[$key]['balance'] = $value;
                                $newGiftHealthyData[$key]['channel_type'] = ($healthyChannelType ?? 2);
                            }
                            if (!empty($newGiftHealthyData ?? [])) {
                                (new HealthyBalance())->saveAll($newGiftHealthyData);
                            }
                        }
                    }
                }

                //添加赠送美丽金明细
                if (!empty($aGiftCrowd ?? [])) {
                    foreach ($aGiftCrowd as $key => $value) {
                        User::where(['status' => 1, 'uid' => $value['uid']])->inc('crowd_balance', $value['price'])->update();
                    }
                    (new CrowdfundingBalanceDetail())->saveAll(array_values($aGiftCrowd));

                    //只有普通购物赠送美丽金订单才可以计算上级充值业绩
                    if ($aOrderInfo['order_type'] == 1) {
                        //将充值的订单推入队列计算团队上级充值业绩冗余明细
                        if (config('system.recordRechargeDetail') == 1) {
                            foreach ($aGiftCrowd as $key => $value) {
                                if (!empty($value['order_sn'] ?? null)) {
                                    $upgradeQueue = Queue::push('app\lib\job\TeamMemberUpgrade', ['order_sn' => $value['order_sn'], 'type' => 5], config('system.queueAbbr') . 'TeamMemberUpgrade');
                                }
                            }
                        }

                        //将充值的订单推入队列计算直推上级充值业绩冗余明细
                        foreach ($aGiftCrowd as $key => $value) {
                            if (!empty($value['order_sn'] ?? null)) {
                                Queue::push('app\lib\job\TeamMemberUpgrade', ['order_sn' => $value['order_sn'], 'type' => 6], config('system.queueAbbr') . 'TeamMemberUpgrade');
                            }
                        }
                    }
                }
            }

            //如果有使用优惠券则修改对应优惠券状态
            if (!empty($aOrderCoupons)) {
                //修改订单优惠券状态为已使用
                $orderCouponRes = OrderCoupon::update(['used_status' => 2], ['order_sn' => $order_sn, 'used_status' => 1]);
                //修改用户订单优惠券状态为已使用
                $uCouponRes = UserCoupon::update(['valid_status' => 3, 'use_time' => time()], ['uc_code' => $aOrderUcCoupon]);
//                $aOrderCouponNumberList = [];
//                foreach ($aOrderCoupon as $key => $value) {
//                    if(!isset($aOrderCouponNumberList[$key])){
//                        $aOrderCouponNumberList[$key] = 0;
//                    }
//                    $aOrderCouponNumberList[$key] += 1;
//                }
//                //修改优惠券使用人数量
//                if(!empty($aOrderCouponNumberList)){
//                    foreach ($aOrderCouponNumberList as $key => $value) {
//                        $couponRes = Coupon::where(['code' => $key])->inc('used_count',($value ?? 1))->update();
//                    }
//                }else{
//                    $couponRes = Coupon::where(['code' => $aOrderCoupon])->inc('used_count')->update();
//                }
                //修改优惠券使用人数量
                $couponRes = Coupon::where(['code' => $aOrderCoupon])->inc('used_count')->update();

            }
            //如果有使用积分则添加积分明细--这里跟使用纯积分支付的订单可叠加计算
            if (!empty($aOrderInfo['used_integral'])) {
                $aIntegral['type'] = 2;
                $aIntegral['integral'] = '-' . $aOrderInfo['used_integral'];
                $aIntegral['order_sn'] = $order_sn;
                $aIntegral['uid'] = $aOrderInfo['uid'];
                $aIntegral['change_type'] = 3;
                $integralRes = (new IntegralDetail())->new($aIntegral);
                //修改账户积分余额
//                Db::name('user')->where(['uid' => $aOrderInfo['uid'], 'status' => 1])->dec('integral', doubleval($aOrderInfo['used_integral']))->update();
            }
            //如果有使用余额则扣除对应的余额(暂不支持组合支付,仅允许余额或直接支付)
            if (!empty($aOrderInfo['pay_type']) && in_array($aOrderInfo['order_type'], [1, 5, 6, 7, 8]) && !empty((double)$aOrderInfo['real_pay_price']) && in_array($aOrderInfo['pay_type'], [1, 5, 7, 8])) {
                switch ($aOrderInfo['pay_type'] ?? 1) {
                    case 1:
                        //添加消费明细
                        $userBalance['uid'] = $aOrderInfo['uid'];
                        $userBalance['order_sn'] = $aOrderInfo['order_sn'];
                        $userBalance['belong'] = 1;
                        $userBalance['type'] = 2;
                        $userBalance['price'] = '-' . $aOrderInfo['real_pay_price'];
                        $userBalance['change_type'] = 3;
                        $userBalance['remark'] = ' 消费支出';
                        BalanceDetail::create($userBalance);
                        //修改账户余额
                        Db::name('user')->where(['uid' => $aOrderInfo['uid'], 'status' => 1])->dec('divide_balance', doubleval($aOrderInfo['real_pay_price']))->update();
                        break;
                    case 5:
                        //查询账户余额,锁行
                        $userId = Db::name('user')->where(['uid' => $aOrderInfo['uid'], 'status' => 1])->value('id');
                        $info = Db::name('user')->where(['id' => $userId])->lock(true)->value('uid');
                        foreach ($orderGoods as $key => $value) {
                            $crowdBalance = [];
//                            if (!empty($value['crowd_code'] ?? null) && !empty($value['crowd_round_number'] ?? null) && !empty($value['crowd_period_number'] ?? null)) {
                                //添加众筹消费明细
                                $crowdBalance['uid'] = $aOrderInfo['uid'];
                                $crowdBalance['order_sn'] = $aOrderInfo['order_sn'];
                                $crowdBalance['belong'] = 1;
                                $crowdBalance['type'] = 2;
                                $crowdBalance['price'] = '-' . $value['real_pay_price'];
                                $crowdBalance['change_type'] = 2;
                                $crowdBalance['remark'] = '消费支出';
                                if ($aOrderInfo['order_type'] == 1) {
                                    $crowdBalance['remark'] = '商城美丽金专区消费支出';
                                }
                                $crowdBalance['crowd_code'] = $value['crowd_code'] ?? null;
                                $crowdBalance['crowd_round_number'] = $value['crowd_round_number'] ?? null;
                                $crowdBalance['crowd_period_number'] = $value['crowd_period_number'] ?? null;
                                CrowdfundingBalanceDetail::create($crowdBalance);
                                //修改账户余额
                                Db::name('user')->where(['uid' => $aOrderInfo['uid'], 'status' => 1])->dec('crowd_balance', doubleval($value['real_pay_price']))->update();
//                            }
                        }

                        break;
                    case 7:
                        //纯美丽豆(积分)兑换明细
                        $integralDetail['order_sn'] = $aOrderInfo['order_sn'];
                        $integralDetail['integral'] = '-' . $aOrderInfo['real_pay_price'];
                        $integralDetail['type'] = 2;
                        $integralDetail['change_type'] = 3;
                        $integralDetail['remark'] = '积分兑换消费';
                        $integralDetail['uid'] = $aOrderInfo['uid'];
                        $integralRes = (new IntegralDetail())->new($integralDetail);
                        //修改账户积分余额
//                        Db::name('user')->where(['uid' => $aOrderInfo['uid'], 'status' => 1])->dec('integral', $integralDetail['integral'])->update();
                        break;
                    case 8:
                        //美丽券明细
                        $ticketDetail['order_sn'] = $aOrderInfo['order_sn'];
                        $ticketDetail['pay_no'] = $pay_no ?? null;
                        $ticketDetail['price'] = '-' . $aOrderInfo['real_pay_price'];
                        $ticketDetail['type'] = 2;
                        $ticketDetail['change_type'] = 3;
                        $ticketDetail['remark'] = '兑换消费';
                        $ticketDetail['uid'] = $aOrderInfo['uid'];
                        $ticketRes = (new CrowdfundingTicketDetail())->new($ticketDetail);
                        //修改账户积分余额
                        Db::name('user')->where(['uid' => $aOrderInfo['uid'], 'status' => 1])->dec('ticket_balance', doubleval($aOrderInfo['real_pay_price']))->update();
                        break;
                    default:
                        break;
                }
            }
            $successPt = false;

            //根据不同订单类型做不同处理
            switch ($aOrderInfo['order_type']) {
                case 1:
                    if (!empty($aOrderInfo['link_superior_user'])) {
                        //绑定下级用户
                        $this->bindDownUser($aOrderInfo['uid'], $aOrderInfo['link_superior_user']);
                    }

                    $divideQueue = Queue::push('app\lib\job\DividePrice', ['order_sn' => $order_sn, 'searNumber' => 1], config('system.queueAbbr') . 'OrderDivide');
                    //发放订单对应的成长值
//                    (new GrowthValue())->buildOrderGrowthValue($order_sn);\
                    break;
                case 2:
                    //拼团订单 修改拼团订单状态
                    PtOrder::update(['pay_status' => 2], ['order_sn' => $aOrderInfo['order_sn'], 'uid' => $aOrderInfo['uid'], 'pay_status' => 1]);

                    //绑定下级用户,如果原本有上级,则不绑定,如果原本没有,则判断订单推荐人,如果有订单推荐人,则优先订单推荐人,否则默认开团团长
                    $bindUser = null;
                    $userTop = User::where(['uid' => $aOrderInfo['uid']])->value('link_superior_user');
                    if (empty($userTop)) {
                        if (!empty($aOrderInfo['link_superior_user'])) {
                            $bindUser = $aOrderInfo['link_superior_user'];
                        } else {
                            if (!empty($startPtUser)) {
                                $bindUser = $startPtUser;
                            }
                        }
                    }
                    //只有绑定上级不为空且不是本人的时候才进入绑定方法
                    if (!empty($bindUser) && ($bindUser != $aOrderInfo['uid'])) {
                        $this->bindDownUser($aOrderInfo['uid'], $bindUser);
                    }

                    //如果达到了成团人数则完成整团的拼团
                    $ptSn = PtOrder::where(['order_sn' => $aOrderInfo['order_sn'], 'activity_status' => 1, 'status' => 1])->field('activity_code,activity_sn')->findOrEmpty()->toArray();
                    if (!empty($ptSn)) {
                        $activityCode = $ptSn['activity_code'];
                        $activitySn = $ptSn['activity_sn'];
                        //拼团活动信息
                        $ptInfo = PtActivity::where(['activity_code' => $activityCode, 'status' => 1])->findOrEmpty()->toArray();
                        //全部参团已支付人的数量
                        $allPt = PtOrder::where(['activity_code' => $activityCode, 'activity_sn' => $activitySn, 'activity_status' => 1, 'pay_status' => 2, 'status' => 1])->count();

                        //查看该团团长
                        $startPtUser = PtOrder::where(['activity_code' => $activityCode, 'activity_sn' => $activitySn, 'pay_status' => 2, 'status' => 1, 'user_role' => 1])->value('uid');

                        //到达成团人数,完成拼团
                        if ($allPt == intval($ptInfo['group_number'])) {
                            $ptMap = ['activity_code' => $activityCode, 'activity_sn' => $activitySn, 'activity_status' => 1, 'status' => 1];
                            $allOrder = PtOrder::where($ptMap)->field('order_sn,uid,user_role')->order('user_role asc,create_time asc')->select()->toArray();
                            PtOrder::update(['activity_status' => 2], $ptMap);
                            $successPt = true;
                            //已经完成拼团模版消息通知----尚未完成

                            //分润
                            if (!empty($allOrder)) {
                                $growValueService = (new GrowthValue());
                                switch ($ptInfo['type']) {
                                    //普通拼团全部人都参与分润
                                    case 1:
                                        foreach ($allOrder as $key => $value) {
                                            $divideQueue = Queue::push('app\lib\job\DividePrice', ['order_sn' => $value['order_sn'], 'searNumber' => 1], config('system.queueAbbr') . 'OrderDivide');
                                        }
                                        break;
                                    //团长免单和邀新团,开团人发放成长值但不参与分润
                                    case 2:
                                        foreach ($allOrder as $key => $value) {
                                            if ($value['uid'] == $startPtUser || $value['user_role'] == 1) {
                                                unset($allOrder[$key]);
                                                continue;
                                            } else {
                                                $divideQueue = Queue::push('app\lib\job\DividePrice', ['order_sn' => $value['order_sn'], 'searNumber' => 1], config('system.queueAbbr') . 'OrderDivide');
                                            }
                                        }
                                        break;
                                    //团长大礼包拼团因为有等级的限制,按照开团->参团的顺序慢慢参与分润
                                    case 3:
                                        foreach ($allOrder as $key => $value) {
                                            if ($value['user_role'] == 1) {
                                                $divideQueue = Queue::push('app\lib\job\DividePrice', ['order_sn' => $value['order_sn'], 'searNumber' => 1], config('system.queueAbbr') . 'OrderDivide');
                                            } else {
                                                $divideQueue = Queue::later(intval((intval($key) + 1) * 15), 'app\lib\job\DividePrice', ['order_sn' => $value['order_sn'], 'searNumber' => 1], config('system.queueAbbr') . 'OrderDivide');
                                            }
                                        }
                                        break;
                                    default:
                                        $divideQueue = false;
                                }
                            }
                        }
                    }
                    break;
                case 3:
                    if (!empty($aOrderInfo['link_superior_user'])) {
                        //绑定下级用户
                        $this->bindDownUser($aOrderInfo['uid'], $aOrderInfo['link_superior_user']);
                    }
                    //先以原先身份进行分润之后再进行升级判断,升级放在了分润最后,强制先分润后升级
                    //计算分润
                    $divideQueue = Queue::push('app\lib\job\DividePrice', ['order_sn' => $order_sn, 'searNumber' => 1], config('system.queueAbbr') . 'OrderDivide');

//                    //立马成为团长
//                    (new MemberService())->becomeMember($aOrderInfo);
//                    //立马判断是否可以升级
//                    $upgradeQueue = Queue::push('app\lib\job\MemberUpgrade',['uid'=>$aOrderInfo['uid']],config('system.queueAbbr') . 'MemberUpgrade');

                    break;
                case 4:
                    //拼拼有礼订单 修改拼拼有礼订单状态
                    PpylOrder::update(['pay_status' => 2], ['order_sn' => $aOrderInfo['order_sn'], 'uid' => $aOrderInfo['uid'], 'pay_status' => 1]);

                    //绑定下级用户,如果原本有上级,则不绑定,如果原本没有,则判断订单推荐人,如果有订单推荐人,则优先订单推荐人,否则默认开团团长
                    $bindUser = null;
                    $userTop = User::where(['uid' => $aOrderInfo['uid']])->value('link_superior_user');
                    if (empty($userTop)) {
                        if (!empty($aOrderInfo['link_superior_user'])) {
                            $bindUser = $aOrderInfo['link_superior_user'];
                        } else {
                            if (!empty($startPtUser)) {
                                $bindUser = $startPtUser;
                            }
                        }
                    }
                    //只有绑定上级不为空且不是本人的时候才进入绑定方法
                    if (!empty($bindUser) && ($bindUser != $aOrderInfo['uid'])) {
                        $this->bindDownUser($aOrderInfo['uid'], $bindUser);
                    }

                    //如果达到了成团人数则完成整团的拼团
                    $ptSn = PpylOrder::where(['order_sn' => $aOrderInfo['order_sn'], 'activity_status' => 1, 'status' => 1])->field('activity_code,area_code,activity_sn')->findOrEmpty()->toArray();
                    if (!empty($ptSn)) {
                        $activityCode = $ptSn['activity_code'];
                        $areaCode = $ptSn['area_code'];
                        $activitySn = $ptSn['activity_sn'];
                        //拼团活动信息
                        $ptInfo = PpylActivity::where(['activity_code' => $activityCode, 'status' => 1])->findOrEmpty()->toArray();
                        //全部参团已支付人的数量
                        $allPt = PpylOrder::where(['activity_code' => $activityCode, 'area_code' => $areaCode, 'activity_sn' => $activitySn, 'activity_status' => 1, 'pay_status' => 2, 'status' => 1])->count();

                        //查看该团团长
                        $startPtUser = PpylOrder::where(['activity_code' => $activityCode, 'area_code' => $areaCode, 'activity_sn' => $activitySn, 'pay_status' => 2, 'status' => 1, 'user_role' => 1])->value('uid');

                        //到达成团人数,完成拼团
                        if ($allPt == intval($ptInfo['group_number'])) {
                            $ptMap = ['activity_code' => $activityCode, 'area_code' => $areaCode, 'activity_sn' => $activitySn, 'activity_status' => 1, 'status' => 1];
                            $allOrder = PpylOrder::where($ptMap)->field('order_sn,uid,user_role')->order('user_role asc,create_time asc')->select()->toArray();
                            PpylOrder::update(['activity_status' => 2], $ptMap);
                            $successPt = true;
                            //已经完成拼团模版消息通知----尚未完成

                            //进入抽奖并发放红包
                            if (!empty($allOrder)) {
                                Queue::push('app\lib\job\PpylLottery', ['activity_sn' => $activitySn], config('system.queueAbbr') . 'PpylLottery');
                            }
                        }
                    }
                    break;
                case 5:
                    $divideQueue = Queue::push('app\lib\job\DividePrice', ['order_sn' => $order_sn, 'searNumber' => 1], config('system.queueAbbr') . 'OrderDivide');
                    break;
                case 6:
                    //判断订单对应的期是否有不需要判断销售额的缓存锁, 如果有则不推入队列, 因为有些订单初期肯定是不满足认购成功的条件的, 所以判断浪费性能
                    $crowdCacheKey = 'crowdFundPeriodJudgePrice-' . ($aOrderInfo['crowd_key'] ?? '');
                    $crowdFundPeriodJudgePrice = cache($crowdCacheKey);

                    if (!empty($crowdFundPeriodJudgePrice ?? [])) {
                        if ((string)$crowdFundPeriodJudgePrice > 0 && (string)$crowdFundPeriodJudgePrice > (string)($aOrderInfo['real_pay_price'] - ($aOrderInfo['fare_price'] ?? 0))) {
                            $notJudge = true;
                        }
                    } else {
                        $notJudge = false;
                    }

                    if (empty($notJudge ?? false)) {
                        $crowdQueue = Queue::later(5, 'app\lib\job\CrowdFunding', ['order_sn' => $order_sn, 'orderInfo' => $aOrderInfo, 'dealType' => 1, 'operateType' => 1], config('system.queueAbbr') . 'CrowdFunding');
                    }
                    if (!empty($crowdFundPeriodJudgePrice) && $crowdFundPeriodJudgePrice > 0) {
                        Cache::dec($crowdCacheKey, intval(($aOrderInfo['real_pay_price'] - ($aOrderInfo['fare_price'] ?? 0))));
                    }


                    //使用提前购才扣除提前卡
                    if (!empty($aOrderInfo['advance_buy'] ?? false) && $aOrderInfo['advance_buy'] == 1) {
                        (new AdvanceCardDetail())->useAdvanceBuyCard(['order_sn' => $aOrderInfo['order_sn'], 'uid' => $aOrderInfo['uid']]);
                    }
                    //解除部分提前购缓存
                    if (!empty($aOrderInfo['advance_buy'] ?? false) && $aOrderInfo['advance_buy'] == 1) {
                        $cacheKey = (new AdvanceCardDetail())->lockAdvanceBuyKey . $aOrderInfo['uid'];
                        if (!empty(cache($cacheKey))) {
                            $lockList = cache($cacheKey);
                            foreach ($orderGoods as $key => $value) {
                                if (!empty($value['crowd_code']) && !empty($value['crowd_round_number']) && !empty($value['crowd_period_number'])) {
                                    $crowdKey = $value['crowd_code'] . '-' . $value['crowd_round_number'] . '-' . $value['crowd_period_number'];
                                    if (!empty($lockList[$crowdKey] ?? [])) {
                                        unset($lockList[$crowdKey]);
                                    }
                                }
                            }
                            cache($cacheKey, $lockList, 180);
                        }
                    }
                    break;
                case 7:
                case 8:
                    //not operation
                    $payRes = true;
                    break;
                default:
                    throw new ServiceException(['msg' => '暂不支持的订单类型']);
                    break;
            }

//            $divideQueue = Queue::push('app\lib\job\DividePrice',['order_sn'=>$order_sn],config('system.queueAbbr') . 'OrderDivide');

//            //正式大礼包再判断一次是否可以升级
//            if(!empty($aOrderInfo['order_type']) && $aOrderInfo['order_type'] == 3){
//                $upgradeQueue = Queue::push('app\lib\job\MemberUpgrade', ['uid' => $aOrderInfo['uid']], config('system.queueAbbr') . 'MemberUpgrade');
//            }


            //截取商品名称长度
            $length = mb_strlen($goodsTitle);
            if ($length >= 17) {
                $goodsTitle = mb_substr($goodsTitle, 0, 17) . '...';
            }
            $template['uid'] = $aOrderInfo['uid'];
            $template['type'] = 'orderSuccess';
            if (empty($aOrderInfo['order_sn'])) {
                $template['page'] = 'pages/index/index';
            } else {
                $template['page'] = 'pages/index/index?redirect=%2Fpages%2Forder-detail%2Forder-detail%3Fsn%3D' . $aOrderInfo['order_sn'];
                //$template['page'] = null;
            }
//            $template['page'] = null;
            $template['access_key'] = getAccessKey();
            $template['template'] = ['character_string1' => $aOrderInfo['order_sn'], 'amount2' => $aOrderInfo['real_pay_price'], 'thing3' => $goodsTitle, 'time5' => timeToDateFormat($aOrder['pay_time']), 'thing4' => '感谢您对' . config('system.projectName') . '的支持'];
            $templateQueue = Queue::later(15, 'app\lib\job\Template', $template, config('system.queueAbbr') . 'TemplateList');

            $allRes['orderRes'] = $orderRes->getData();
            $allRes['couponRes'] = $uCouponRes ?? false;
            $allRes['integralRes'] = !empty($integralRes) ? $integralRes->getData() : [];
            $allRes['orderInfo'] = $aOrderInfo ?? [];
            $allRes['orderGoods'] = $orderGoods ?? [];
            $allRes['divideQueue'] = $divideQueue ?? '暂无分润情况';
            $allRes['memberUpgrade'] = $memberUpgrade ?? '暂无会员升级情况';
            $allRes['templateQueue'] = $templateQueue ?? '暂无模版消息通知情况';

            return $allRes;
        });
//        //自动发券功能
//        //可进行下面操作的指定商品SKU
//        $SpecifyGoods = ['2191355901'];
//        //指定商品自动同步,自动修改为备货中,自动发券
//        $cData['order_sn'] = trim($callbackData['out_trade_no']);
//        $cData['uid'] = $res['orderInfo']['uid'];
//        if (!empty($res['orderGoods'])) {
//            $goodsSku = array_column($res['orderGoods'], 'sku_sn');
//            if (!empty($goodsSku)) {
//                foreach ($goodsSku as $key => $value) {
//                    if (in_array($value, $SpecifyGoods)) {
//                        $cData['goods'][] = $value;
//                    }
//                }
//            }
//        }
//        if (!empty($cData['goods'])) {
//            $cData['goods'] = array_unique(array_filter($cData['goods']));
//        }
//        $cData['receiveCoupon'] = true;
//        $cData['changeOrderStatus'] = true;
//        if (!empty($cData['goods'])) {
//            $changeOrderStatusAndReceiveCoupon = $this->changeOrderStatus($cData);
//        }
        $log['msg'] = '已接受到订单 ' . $callbackData['out_trade_no'] . ' 的支付回调';
        $log['data']['callBackData'] = $callbackData;
        $log['data']['dealRes'] = $res ?? [];
        $this->log($log, 'info');
        //除了众筹订单, 其他的订单可以尝试判断自动发货
        if (!empty($res['orderInfo'] ?? []) && !empty($res['orderInfo']['order_type'] ?? null) && $res['orderInfo']['order_type'] != 6) {
            //判断是否有兑换商品, 自动发货
            Queue::push('app\lib\job\Auto', ['orderInfo' => $res['orderInfo'], 'orderGoods' => $res['orderGoods'], 'operType' => 1, 'autoType' => 7], config('system.queueAbbr') . 'Auto');
        }


        return judge($res);
    }

    /**
     * @title  完成拼拼有礼支付订单
     * @param array $callbackData 包含订单号和流水号
     * @return mixed
     * @throws \Exception
     */
    public function completePpylPay(array $callbackData)
    {
        $res = Db::transaction(function () use ($callbackData) {
            $order_sn = $callbackData['out_trade_no'];
            $pay_no = $callbackData['transaction_id'];
            $aOrderInfo = (new PpylOrder())->where(['order_sn' => $order_sn])->findOrEmpty()->toArray();


            if (empty($aOrderInfo)) {
                return false;
            }
            //如果是支付流水号来重新支付,需要校验一下订单
            if ($aOrderInfo['pay_type'] == 4) {
                $payNoOrder = PpylOrder::where(['pay_no' => $pay_no, 'pay_type' => 2])->order('create_time asc')->findOrEmpty()->toArray();
                if (empty($payNoOrder)) {
                    $payNoOrder = PpylWaitOrder::where(['pay_no' => $pay_no, 'pay_type' => 2])->order('create_time asc')->findOrEmpty()->toArray();
                }
                if (empty($payNoOrder)) {
                    return $allRes['errorMsg'] = '查无原支付流水号订单';
                }
                $allRes['payNoOrder'] = $payNoOrder;

                if ((string)$payNoOrder['real_pay_price'] != (string)$aOrderInfo['real_pay_price']) {
                    $allRes['errorMsg'] = '流水号对应的原订单实付金额与当前订单支付金额不符,无法继续支付';
                    return $allRes;
                }
                //查看利用该流水号的订单是否有中奖或退款的记录,有则不允许重新复用
                $usePayNoOrder = PpylOrder::where(['pay_no' => $pay_no])->order('create_time asc')->select()->toArray();
                $usePayNoWaitOrder = PpylWaitOrder::where(['pay_no' => $pay_no])->order('create_time asc')->select()->toArray();
                if (!empty($usePayNoOrder)) {
                    $allRes['usePayNoOrder'] = $usePayNoOrder;
                    foreach ($usePayNoOrder as $key => $value) {
                        if ($value['win_status'] == 1 || $value['pay_status'] == -2) {
                            $allRes['errorMsg'] = '使用过原支付流水号的订单' . $value['order_sn'] . '已中奖或已退款,无法继续复用';
                            return $allRes;
                        }
                    }
                }
                if (!empty($usePayNoWaitOrder)) {
                    $allRes['usePayNoWaitOrder'] = $usePayNoWaitOrder;
                    foreach ($usePayNoWaitOrder as $key => $value) {
                        if ($value['wait_status'] == -2 || $value['pay_status'] == -2) {
                            $allRes['errorMsg'] = '使用过原支付流水号的排队订单' . $value['order_sn'] . '已退款,无法继续复用';
                            return $allRes;
                        }
                    }
                }
            }

            $aOrderInfo['order_type'] = 4;
            $aOrderCoupons = OrderCoupon::where(['order_sn' => $order_sn, 'used_status' => 1])->field('coupon_uc_code,coupon_code')->select()->toArray();
            $orderGoods = PpylOrderGoods::where(['order_sn' => $order_sn])->field('goods_sn,sku_sn,user_level,price,count,total_price,title')->select()->toArray();
            $goodsTitle = implode(',', array_column($orderGoods, 'title'));
            $goodsNumber = array_sum(array_column($orderGoods, 'count'));
            $aOrderUcCoupon = array_column($aOrderCoupons, 'coupon_uc_code');
            $aOrderCoupon = array_column($aOrderCoupons, 'coupon_code');

            //修改订单状态为已支付
            $aOrder['pay_no'] = $pay_no;
            $aOrder['pay_status'] = 2;
            $aOrder['pay_time'] = time();
            $orderRes = PpylOrder::update($aOrder, ['order_sn' => $order_sn, 'pay_status' => 1]);

            //修改订单商品状态为已支付
            $orderGoodsRes = PpylOrderGoods::update(['pay_status' => 2], ['order_sn' => $order_sn, 'pay_status' => 1]);

            //修改自动计划的支付流水号
            $aAuto['pay_no'] = $pay_no;
            $autoRes = PpylAuto::update($aAuto, ['order_sn' => $order_sn]);

            //修改利用支付流水号的上一笔订单为不可操作退款
            if (!empty($aOrderInfo['pay_order_sn']) && $aOrderInfo['pay_type'] == 4) {
                $aLastOrder['can_operate_refund'] = 2;
                $lastRes = PpylOrder::update($aLastOrder, ['order_sn' => $aOrderInfo['pay_order_sn']]);
            }


            //如果有使用优惠券则修改对应优惠券状态
            if (!empty($aOrderCoupons)) {
                //修改订单优惠券状态为已使用
                $orderCouponRes = OrderCoupon::update(['used_status' => 2], ['order_sn' => $order_sn, 'used_status' => 1]);
                //修改用户订单优惠券状态为已使用
                $uCouponRes = UserCoupon::update(['valid_status' => 3, 'use_time' => time()], ['uc_code' => $aOrderUcCoupon]);
                //修改优惠券使用人数量
                $couponRes = Coupon::where(['code' => $aOrderCoupon])->inc('used_count')->update();

            }

            //如果有使用积分则添加积分明细
            if (!empty($aOrderInfo['used_integral'])) {
                $aIntegral['type'] = 2;
                $aIntegral['integral'] = '-' . $aOrderInfo['used_integral'];
                $aIntegral['order_sn'] = $order_sn;
                $aIntegral['uid'] = $aOrderInfo['uid'];
                $aIntegral['change_type'] = 3;
                $integralRes = (new IntegralDetail())->new($aIntegral);
                //修改账户积分余额
                Db::name('user')->where(['uid' => $aOrderInfo['uid'], 'status' => 1])->dec('integral', doubleval($aOrderInfo['used_integral']))->update();
            }

            //如果有使用余额则扣除对应的余额(暂不支持组合支付,仅允许余额或直接支付)
            if (!empty($aOrderInfo['pay_type']) && $aOrderInfo['pay_type'] == 1 && !empty((double)$aOrderInfo['real_pay_price'])) {
                //添加消费明细
                $userBalance['uid'] = $aOrderInfo['uid'];
                $userBalance['order_sn'] = $aOrderInfo['order_sn'];
                $userBalance['belong'] = 1;
                $userBalance['type'] = 2;
                $userBalance['price'] = '-' . $aOrderInfo['real_pay_price'];
                $userBalance['change_type'] = 3;
                $userBalance['remark'] = ' 消费支出';
                BalanceDetail::create($userBalance);
                //修改账户余额
                Db::name('user')->where(['uid' => $aOrderInfo['uid'], 'status' => 1])->dec('total_balance', doubleval($aOrderInfo['real_pay_price']))->update();
                Db::name('user')->where(['uid' => $aOrderInfo['uid'], 'status' => 1])->dec('avaliable_balance', doubleval($aOrderInfo['real_pay_price']))->update();
            }

            $successPt = false;

            //根据不同订单类型做不同处理
            switch ($aOrderInfo['order_type']) {
                case 4:
                    //绑定下级用户,如果原本有上级,则不绑定,如果原本没有,则判断订单推荐人,如果有订单推荐人,则优先订单推荐人,否则默认开团团长

                    //如果达到了成团人数则完成整团的拼团
                    $ptSn = PpylOrder::where(['order_sn' => $aOrderInfo['order_sn'], 'activity_status' => 1, 'status' => 1])->field('activity_code,area_code,activity_sn,goods_sn,sku_sn')->findOrEmpty()->toArray();

                    if (!empty($ptSn)) {
                        $activityCode = $ptSn['activity_code'];
                        $areaCode = $ptSn['area_code'];
                        $activitySn = $ptSn['activity_sn'];
                        //拼团活动信息
                        $ptInfo = PpylActivity::where(['activity_code' => $activityCode, 'status' => 1])->findOrEmpty()->toArray();
                        $ppylArea = PpylArea::where(['area_code'=>$areaCode,'status'=>1])->findOrEmpty()->toArray();

                        //全部参团已支付人的数量
                        $allPt = PpylOrder::where(['activity_code' => $activityCode, 'area_code' => $areaCode, 'activity_sn' => $activitySn, 'activity_status' => 1, 'pay_status' => 2, 'status' => 1])->count();

                        //查看该团团长
                        $startPtUser = PpylOrder::where(['activity_code' => $activityCode, 'area_code' => $areaCode, 'activity_sn' => $activitySn, 'pay_status' => 2, 'status' => 1, 'user_role' => 1])->value('uid');

                        $bindUser = null;
                        $userTop = User::where(['uid' => $aOrderInfo['uid']])->value('link_superior_user');
                        if (empty($userTop)) {
                            if (!empty($aOrderInfo['link_superior_user'])) {
                                $bindUser = $aOrderInfo['link_superior_user'];
                            } else {
                                if (!empty($startPtUser)) {
                                    $bindUser = $startPtUser;
                                }
                            }
                        }
                        //只有绑定上级不为空且不是本人的时候才进入绑定方法
                        if (!empty($bindUser) && ($bindUser != $aOrderInfo['uid'])) {
                            $this->bindDownUser($aOrderInfo['uid'], $bindUser);
                        }

                        //到达成团人数,完成拼团
                        if ($allPt >= intval($ptInfo['group_number'])) {
                            $ptMap = ['activity_code' => $activityCode, 'area_code' => $areaCode, 'activity_sn' => $activitySn, 'activity_status' => 1, 'status' => 1];
                            $allOrder = PpylOrder::where($ptMap)->field('order_sn,uid,user_role')->order('user_role asc,create_time asc')->select()->toArray();

                            PpylOrder::update(['activity_status' => 2, 'group_time' => time(), 'draw_time' => $ppylArea['lottery_delay_time'] + time()], $ptMap);

                            $successPt = true;
                            //已经完成拼团模版消息通知----尚未完成

                            //进入抽奖并发放红包
                            if (!empty($allOrder)) {
                                Queue::later(intval($ppylArea['lottery_delay_time']), 'app\lib\job\PpylLottery', ['activity_sn' => $activitySn], config('system.queueAbbr') . 'PpylLottery');

                                //成团后立马判断是否可以升级
                                foreach ($allOrder as $key => $value) {
                                    $upgradeQueue = Queue::push('app\lib\job\MemberUpgrade', ['uid' => $value['uid'], 'type' => 2], config('system.queueAbbr') . 'MemberUpgrade');
                                }

                                //成团后判断上级的推荐奖是否可以激活
                                foreach ($allOrder as $key => $value) {
                                    Queue::push('app\lib\job\PpylAuto', ['uid' => $value['uid'], 'autoType' => 3], config('system.queueAbbr') . 'PpylAuto');
                                }
                            }
                        }else{
                            //人数不够,查看排队队伍中是否有可以符合条件的
                            $wait['area_code'] = $areaCode;
                            $wait['goods_sn'] = $ptSn['goods_sn'];
                            $wait['sku_sn'] = $ptSn['sku_sn'];
                            $wait['dealType'] = 1;
                            $wait['activity_sn'] = $activitySn;
                            $wait['notThrowError'] = 1;
                            $wait['notSelectUser'] = $aOrderInfo['uid'];
                            if (!empty($wait)) {
                                Queue::push('app\lib\job\PpylWait', $wait, config('system.queueAbbr') . 'PpylWait');
                            }
                        }
                    }
                    break;
            }

            //截取商品名称长度
            $length = mb_strlen($goodsTitle);
            if ($length >= 17) {
                $goodsTitle = mb_substr($goodsTitle, 0, 17) . '...';
            }
            $template['uid'] = $aOrderInfo['uid'];
            $template['type'] = 'orderSuccess';
            if (empty($aOrderInfo['order_sn'])) {
                $template['page'] = 'pages/index/index';
            } else {
                $template['page'] = 'pages/index/index?redirect=%2Fpages%2Forder-detail%2Forder-detail%3Fsn%3D' . $aOrderInfo['order_sn'];
            }
            $template['access_key'] = getAccessKey();
            $template['template'] = ['character_string1' => $aOrderInfo['order_sn'], 'amount2' => $aOrderInfo['real_pay_price'], 'thing3' => $goodsTitle, 'time5' => timeToDateFormat($aOrder['pay_time']), 'thing4' => '感谢您对' . config('system.projectName') . '的支持'];
            $templateQueue = Queue::later(15, 'app\lib\job\Template', $template, config('system.queueAbbr') . 'TemplateList');

            $allRes['orderRes'] = $orderRes->getData();
            $allRes['couponRes'] = $uCouponRes ?? false;
            $allRes['integralRes'] = !empty($integralRes) ? $integralRes->getData() : [];
            $allRes['orderInfo'] = $aOrderInfo ?? [];
            $allRes['orderGoods'] = $orderGoods ?? [];
            $allRes['divideQueue'] = $divideQueue ?? '暂无分润情况';
            $allRes['memberUpgrade'] = $memberUpgrade ?? '暂无会员升级情况';
            $allRes['templateQueue'] = $templateQueue ?? '暂无模版消息通知情况';

            return $allRes;
        });

        $log['msg'] = '已接受到拼拼有礼订单 ' . $callbackData['out_trade_no'] . ' 的支付回调';
        $log['data']['callBackData'] = $callbackData;
        $log['data']['dealRes'] = $res ?? [];
        $this->log($log, 'info');
        return judge($res);
    }

    /**
     * @title  完成拼拼有礼排队支付订单
     * @param array $callbackData 包含订单号和流水号
     * @return mixed
     * @throws \Exception
     */
    public function completePpylWaitPay(array $callbackData)
    {
        $res = Db::transaction(function () use ($callbackData) {
            $order_sn = $callbackData['out_trade_no'];
            $pay_no = $callbackData['transaction_id'];
            $aOrderInfo = (new PpylWaitOrder())->where(['order_sn' => $order_sn])->findOrEmpty()->toArray();

            if (empty($aOrderInfo)) {
                return false;
            }
            $aOrderInfo['order_type'] = 4;
            $aOrderCoupons = OrderCoupon::where(['order_sn' => $order_sn, 'used_status' => 1])->field('coupon_uc_code,coupon_code')->select()->toArray();
            $orderGoods = PpylOrderGoods::where(['order_sn' => $order_sn])->field('goods_sn,sku_sn,user_level,price,count,total_price,title')->select()->toArray();
            $goodsTitle = implode(',', array_column($orderGoods, 'title'));
            $goodsNumber = array_sum(array_column($orderGoods, 'count'));
            $aOrderUcCoupon = array_column($aOrderCoupons, 'coupon_uc_code');
            $aOrderCoupon = array_column($aOrderCoupons, 'coupon_code');

            //修改订单状态为已支付
            $aOrder['pay_no'] = $pay_no;
            $aOrder['pay_status'] = 2;
            $aOrder['pay_time'] = time();
            $orderRes = PpylWaitOrder::update($aOrder, ['order_sn' => $order_sn, 'pay_status' => 1]);

            //尝试修改自动拼计划的支付流水号
            $aAutoOrder['pay_no'] = $pay_no;
            $autoMap[] = ['order_sn', '=', $order_sn];
            $autoMap[] = ['', 'exp', Db::raw('pay_no is null')];
            $orderRes = PpylAuto::update($aAutoOrder, $autoMap);

            //修改订单商品状态为已支付
            $orderGoodsRes = PpylOrderGoods::update(['pay_status' => 2], ['order_sn' => $order_sn, 'pay_status' => 1]);

            //如果有使用优惠券则修改对应优惠券状态
            if (!empty($aOrderCoupons)) {
                //修改订单优惠券状态为已使用
                $orderCouponRes = OrderCoupon::update(['used_status' => 2], ['order_sn' => $order_sn, 'used_status' => 1]);
                //修改用户订单优惠券状态为已使用
                $uCouponRes = UserCoupon::update(['valid_status' => 3, 'use_time' => time()], ['uc_code' => $aOrderUcCoupon]);
                //修改优惠券使用人数量
                $couponRes = Coupon::where(['code' => $aOrderCoupon])->inc('used_count')->update();

            }

            //如果有使用积分则添加积分明细
            if (!empty($aOrderInfo['used_integral'])) {
                $aIntegral['type'] = 2;
                $aIntegral['integral'] = '-' . $aOrderInfo['used_integral'];
                $aIntegral['order_sn'] = $order_sn;
                $aIntegral['uid'] = $aOrderInfo['uid'];
                $aIntegral['change_type'] = 3;
                $integralRes = (new IntegralDetail())->new($aIntegral);
                //修改账户积分余额
                Db::name('user')->where(['uid' => $aOrderInfo['uid'], 'status' => 1])->dec('integral', doubleval($aOrderInfo['used_integral']))->update();
            }

            //如果有使用余额则扣除对应的余额(暂不支持组合支付,仅允许余额或直接支付)
            if (!empty($aOrderInfo['pay_type']) && $aOrderInfo['pay_type'] == 1 && !empty((double)$aOrderInfo['real_pay_price'])) {
                //添加消费明细
                $userBalance['uid'] = $aOrderInfo['uid'];
                $userBalance['order_sn'] = $aOrderInfo['order_sn'];
                $userBalance['belong'] = 1;
                $userBalance['type'] = 2;
                $userBalance['price'] = '-' . $aOrderInfo['real_pay_price'];
                $userBalance['change_type'] = 3;
                $userBalance['remark'] = ' 消费支出';
                BalanceDetail::create($userBalance);
                //修改账户余额
                Db::name('user')->where(['uid' => $aOrderInfo['uid'], 'status' => 1])->dec('total_balance', doubleval($aOrderInfo['real_pay_price']))->update();
                Db::name('user')->where(['uid' => $aOrderInfo['uid'], 'status' => 1])->dec('avaliable_balance', doubleval($aOrderInfo['real_pay_price']))->update();
            }

            $successPt = false;

            //截取商品名称长度
            $length = mb_strlen($goodsTitle);
            if ($length >= 17) {
                $goodsTitle = mb_substr($goodsTitle, 0, 17) . '...';
            }
            $remark = '系统正在自动为您处理排队中...';
            if (!empty($aOrderInfo['timeout_time'] ?? null)) {
                $remark .= '若无团可参, 预计超时时间为' . $aOrderInfo['timeout_time'];
            }
            $template['uid'] = $aOrderInfo['uid'];
            $template['type'] = 'ppylWait';
            $template['page'] = 'pages/index/index';
//            if (empty($aOrderInfo['order_sn'])) {
//                $template['page'] = 'pages/index/index';
//            } else {
//                $template['page'] = 'pages/index/index?redirect=%2Fpages%2Forder-detail%2Forder-detail%3Fsn%3D' . $aOrderInfo['order_sn'];
//            }
            $template['access_key'] = getAccessKey();
            $template['template'] = ['character_string1' => $aOrderInfo['order_sn'], 'thing7' => $goodsTitle, 'phrase4' => '排队中', 'thing2' => $remark, 'time6' => timeToDateFormat($aOrder['pay_time'])];
            $templateQueue = Queue::later(10, 'app\lib\job\Template', $template, config('system.queueAbbr') . 'TemplateList');

            $allRes['orderRes'] = $orderRes->getData();
            $allRes['couponRes'] = $uCouponRes ?? false;
            $allRes['integralRes'] = !empty($integralRes) ? $integralRes->getData() : [];
            $allRes['orderInfo'] = $aOrderInfo ?? [];
            $allRes['orderGoods'] = $orderGoods ?? [];
            $allRes['templateQueue'] = $templateQueue ?? '暂无模版消息通知情况';

            return $allRes;
        });

        if (!empty($res) && !empty($res['orderInfo'] ?? [])) {
            $aOrderInfo = $res['orderInfo'];
            //查看排队队伍中是否有可以符合条件的自动成团
            $wait['area_code'] = $aOrderInfo['area_code'];
            $wait['goods_sn'] = $aOrderInfo['goods_sn'];
            $wait['sku_sn'] = $aOrderInfo['sku_sn'];
            $wait['dealType'] = 2;
            $wait['notThrowError'] = 1;
            if (!empty($wait)) {
                Queue::push('app\lib\job\PpylWait', $wait, config('system.queueAbbr') . 'PpylWait');
            }
        }


        $log['msg'] = '已接受到拼拼有礼排队订单 ' . $callbackData['out_trade_no'] . ' 的支付回调';
        $log['data']['callBackData'] = $callbackData;
        $log['data']['dealRes'] = $res ?? [];
        $this->log($log, 'info');
        return judge($res);
    }

    /**
     * @title  完成会员支付订单
     * @param array $callbackData 包含订单号和流水号
     * @return mixed
     */
    public function completeMember(array $callbackData)
    {
        $res = Db::transaction(function () use ($callbackData) {
            $order_sn = $callbackData['out_trade_no'];
            $pay_no = $callbackData['transaction_id'];
            $aOrderInfo = (new PpylCvipOrder())->info($order_sn);
            $returnData['aOrderInfo'] = $aOrderInfo ?? [];

            if (empty($aOrderInfo)) {
                $returnData['errorMsg'] = '查无订单~';
                return $returnData;
            }
            if ($aOrderInfo['pay_status'] == 2) {
                $returnData['errorMsg'] = '订单已完成~无需重复操作';
                return $returnData;
            }
            if ($aOrderInfo['complete_status'] == 1) {
                $returnData['errorMsg'] = '会员续费已完成~无需重复操作';
                return $returnData;
            }

            $aOrder['pay_no'] = $pay_no;
            $aOrder['pay_status'] = 2;
            $aOrder['complete_status'] = 1;
            $aOrder['pay_time'] = time();
            $aOrder['complete_time'] = time();
            //修改订单状态为已支付
            $orderRes = PpylCvipOrder::update($aOrder, ['order_sn' => $order_sn, 'pay_status' => 1]);
            $returnData['orderRes'] = $orderRes ?? [];

            $userInfo = User::where(['uid' => $aOrderInfo['uid'], 'status' => 1])->field('uid,phone,c_vip_level,c_vip_time_out_time,auto_receive_reward')->findOrEmpty()->toArray();
            $returnData['userInfo'] = $userInfo ?? [];

            $userRes = false;
            if (!empty($userInfo)) {
                if (empty($userInfo['c_vip_time_out_time'])) {
                    $update['c_vip_time_out_time'] = time() + $aOrderInfo['buy_expire_time'];
                } else {
                    $update['c_vip_time_out_time'] = $userInfo['c_vip_time_out_time'] + $aOrderInfo['buy_expire_time'];
                }
                $update['c_vip_level'] = $aOrderInfo['buy_c_vip_level'];
                $update['auto_receive_reward'] = 1;
                //修改用户信息
                $userRes = User::update($update, ['uid' => $aOrderInfo['uid'], 'status' => 1]);
            }
            $returnData['userRes'] = $userRes ?? [];
            return $returnData;
        });

        $log['msg'] = '已接受到CVIP订单 ' . $callbackData['out_trade_no'] . ' 的支付回调';
        $log['data']['callBackData'] = $callbackData;
        $log['data']['dealRes'] = $res ?? [];
        $this->log($log, 'info');

        return judge($res);
    }

    /**
     * @title  完成设备支付订单
     * @param array $callbackData 包含订单号和流水号
     * @return mixed
     * @throws \Exception
     */
    public function completeDevicePay(array $callbackData)
    {
        $res = Db::transaction(function () use ($callbackData) {
            $order_sn = $callbackData['out_trade_no'];
            $pay_no = $callbackData['transaction_id'];
            $aOrderInfo = (new DeviceOrder())->where(['order_sn' => $order_sn, 'pay_status' => 1])->findOrEmpty()->toArray();
            if (empty($aOrderInfo)) {
                return false;
            }

            $aOrder['pay_no'] = $pay_no;
            $aOrder['pay_status'] = 2;
            $aOrder['order_status'] = 2;
            $aOrder['pay_time'] = time();
            if (!empty($callbackData['bank_trx_no'] ?? null)) {
                $aOrder['bank_pay_no'] = $callbackData['bank_trx_no'];
            }
            if (!empty($callbackData['pay_success_time'] ?? null)) {
                $aOrder['pay_time'] = strtotime($callbackData['pay_success_time']);
            }
            //修改订单状态为已支付
            $orderRes = DeviceOrder::update($aOrder, ['order_sn' => $order_sn, 'pay_status' => 1, 'order_status' => 1]);
//            $orderRes = Db::name('device_order')->where(['order_sn' => $order_sn, 'pay_status' => 1, 'order_status' => 1])->update($aOrder);

            //如果支付方式是协议支付则往协议订单缓存中修改订单状态为已支付, 方便C端嗅探针
            if ($aOrderInfo['pay_type'] == 6) {
                $newCache = $aOrder;
                $newCache['order_sn'] = $order_sn;
                cache((new \app\lib\services\Order())->agreementOrderCacheHeader . $order_sn, $newCache, 120);
            }
            //如果有使用余额则扣除对应的余额(暂不支持组合支付,仅允许余额或直接支付)
            if (!empty($aOrderInfo['pay_type']) && in_array($aOrderInfo['pay_type'], [1, 5, 7]) && !empty((double)$aOrderInfo['real_pay_price'])) {
                switch ($aOrderInfo['pay_type'] ?? 1) {
                    case 1:
                        //添加消费明细
                        $userBalance['uid'] = $aOrderInfo['uid'];
                        $userBalance['order_sn'] = $aOrderInfo['order_sn'];
                        $userBalance['belong'] = 1;
                        $userBalance['type'] = 2;
                        $userBalance['price'] = '-' . $aOrderInfo['real_pay_price'];
                        $userBalance['change_type'] = 3;
                        $userBalance['remark'] = '设备消费支出';
//                        BalanceDetail::create($userBalance);
                        $userBalance['create_time'] = time();
                        $userBalance['update_time'] = $userBalance['create_time'] ?? time();
                        Db::name('balance_detail')->strict(false)->insert($userBalance);

                        //修改账户余额
                        Db::name('user')->where(['uid' => $aOrderInfo['uid'], 'status' => 1])->dec('divide_balance', doubleval($aOrderInfo['real_pay_price']))->update();
                        break;
                    case 7:
                        //添加健康豆消费明细
                        $userBalance['uid'] = $aOrderInfo['uid'];
                        $userBalance['order_sn'] = $aOrderInfo['order_sn'];
                        $userBalance['belong'] = 1;
                        $userBalance['type'] = 2;
                        $userBalance['price'] = '-' . $aOrderInfo['real_pay_price'];
                        $userBalance['change_type'] = 2;
                        $userBalance['remark'] = '设备消费支出';
                        $userBalance['device_sn'] = $aOrderInfo['device_sn'];
                        HealthyBalanceDetail::create($userBalance);
//                        $userBalance['create_time'] = time();
//                        $userBalance['update_time'] = $userBalance['create_time'] ?? time();
//                        Db::name('healthy_balance_detail')->insertGetId($userBalance);
                        //修改账户余额
                        Db::name('user')->where(['uid' => $aOrderInfo['uid'], 'status' => 1])->dec('healthy_balance', doubleval($aOrderInfo['real_pay_price']))->update();
                        break;
                    default:
                        break;
                }
            }
            $successPt = false;

            //根据不同订单类型做不同处理
//            switch ($aOrderInfo['order_type']) {
//                case 1:
//                    $divideQueue = Queue::push('app\lib\job\Auto', ['order_sn' => $order_sn, 'autoType' => 6], config('system.queueAbbr') . 'Auto');
//                    break;
//                default:
//                    throw new ServiceException(['msg' => '暂不支持的订单类型']);
//                    break;
//            }

            $allRes['orderRes'] = $orderRes;
            $allRes['couponRes'] = $uCouponRes ?? false;
            $allRes['orderInfo'] = $aOrderInfo ?? [];
            $allRes['divideQueue'] = $divideQueue ?? '暂无分润情况';

            //开启机器
            (new \app\lib\services\Device())->startPower(['order_sn'=>$order_sn]);

            return $allRes;
        });

        $log['msg'] = '已接受到设备订单 ' . $callbackData['out_trade_no'] . ' 的支付回调';
        $log['data']['callBackData'] = $callbackData;
        $log['data']['dealRes'] = $res ?? [];
        $this->log($log, 'info');
        return judge($res);
    }

    /**
     * @title  完成退款订单回调
     * @param array $callbackData
     * @return mixed
     */
    public function completeRefund(array $callbackData)
    {
        $order_sn = $callbackData['out_trade_no'];
        $afSn = $callbackData['out_refund_no'];
        $searNumber = $callbackData['searNumber'] ?? 0;
        $aOrder = [];

        //防止并发,设置简易缓存锁
        $cacheKey = $order_sn . '-afterSale';
        $cacheNumKey = $cacheKey . 'Number';
        $cacheExpire = 3;
        $cacheValue = $order_sn . '-' . $afSn;
        $nowCache = cache($cacheKey);
        $nowCacheNum = cache($cacheNumKey) ?? 0;
        if (!empty($nowCache)) {
            if (!empty($nowCacheNum)) {
                $queueNumber = 1;
            } else {
                $queueNumber = $nowCacheNum;
            }
            $waitTimeSecond = 3;
            $waitTime = intval($queueNumber * $waitTimeSecond);
            $rLog['errorMsg'] = '该订单 ' . $order_sn . ' 有正在处理的退售后, 编号为' . $nowCache . ' ,当前的退售后订单先推入等待队列,' . $waitTime . '秒后稍后重新执行';
            $rLog['data'] = $callbackData;
            $rLog['cacheData'] = $nowCache;

            //推入等待队列
            $queueData = $callbackData;
            $queueData['autoType'] = 3;
            $queue = Queue::later($waitTime, 'app\lib\job\Auto', $queueData, config('system.queueAbbr') . 'Auto');
            if (!empty($nowCacheNum)) {
                Cache::inc($nowCacheNum);
            }
            $this->log($rLog, 'error', 'refundCallback');
            return false;
        } else {
            Cache::set($cacheKey, $cacheValue, $cacheExpire);
            Cache::set($cacheNumKey, ($nowCacheNum + 1), $cacheExpire);
        }

//        $aOrder = Order::with(['goods'])->where(['order_sn' => $order_sn, 'pay_status' => 2])->field('id,order_type,order_belong,order_sn,uid,pay_no,real_pay_price,pay_type,split_status,sync_status,order_status,after_status')->findOrEmpty()->toArray();
//        //仅未被关闭的订单才可以进入业务的订单取消
//        if ($aOrder['order_status'] > 0) {
        $res = Db::transaction(function () use ($callbackData, $afSn, $order_sn, $aOrder, $searNumber) {
            $order_sn = $callbackData['out_trade_no'];
            $afSn = $callbackData['out_refund_no'];
            $aOrderId = Order::where(['order_sn' => $order_sn, 'pay_status' => 2])->value('id');
            //锁行
            $aOrder = Order::with(['goods'])->where(['id' => $aOrderId])->lock(true)->field('id,order_type,order_belong,order_sn,uid,pay_no,real_pay_price,pay_type,split_status,sync_status,order_status,after_status,fare_price,shipping_code,shipping_type,handsel_sn')->findOrEmpty()->toArray();
            $rLog['aOrder'] = $aOrder;
            if (empty($aOrderId) || empty($aOrder) || (!empty($aOrder) && $aOrder['order_status'] <= 0)) {
                $rLog['errorMsg'] = '该订单已被关闭,可能已经处理好业务订单取消,故无法继续';
                return $rLog;
            }

            //正常订单为处理售后
            $map[] = ['refund_sn', '=', $afSn];
            $map[] = ['order_sn', '=', $order_sn];
            $map[] = ['status', '=', 1];
            $afInfo = AfterSale::with(['user'])->where($map)->findOrEmpty()->toArray();
            //如果根据退款编码查不到售后订单,有可能会是时间差问题,尝试十秒后重新处理一遍
            if (empty($afInfo)) {
                if (empty($searNumber)) {
                    $againQueueData = $callbackData;
                    $againQueueData['autoType'] = 3;
                    $againQueueData['searNumber'] = 1;
                    $queue = Queue::later(10, 'app\lib\job\Auto', $againQueueData, config('system.queueAbbr') . 'Auto');
                }
                $rLog['errorMsg'] = '未查找到该售后订单,将于10秒后重新尝试处理';
                $this->log($rLog, 'error', 'refundCallback');
                return $rLog;
            }
            $rLog['afInfo'] = $afInfo;

            //当前的订单商品信息
            $thisOrderGoods = OrderGoods::where(['order_sn' => $order_sn, 'goods_sn' => $afInfo['goods_sn'], 'sku_sn' => $afInfo['sku_sn']])->findOrEmpty()->toArray();

            $rLog['thisOrderGoods'] = $thisOrderGoods;

            //判断当前商品申请退售后的金额是否超过了商品总金额(实付金额-实付邮费),如果大于等于则表示需要退整单的分润和修改订单商品状态,否则变成之前的正常状态
            $orderGoodsFullAfter = true;
            $thisGoodsRealTotalPrice = $thisOrderGoods['total_price'] - ($thisOrderGoods['all_dis'] ?? 0);
            if (!empty(doubleval($afInfo['apply_price'])) && (string)$afInfo['apply_price'] < (string)($thisOrderGoods['total_price'] - ($thisOrderGoods['all_dis'] ?? 0))) {
                $orderGoodsFullAfter = false;
                $thisGoodsRealTotalPrice = $afInfo['apply_price'];
            }

            $orderGoodsInfo = [];
            foreach ($aOrder['goods'] as $key => $value) {
                $orderGoodsInfo[$value['sku_sn']] = $value;
            }
//                //查询该订单的全部售后订单并锁行
//                $oaMap[] = ['order_sn', '=', $order_sn];
//                $orderAfHisId = AfterSale::where($oaMap)->column('id');
//                $rLog['orderAfHisId'] = $orderAfHisId ?? [];
//                $orderAfHis = [];
//                if(!empty($orderAfHisId)){
//                    $orderAfHis = AfterSale::where(['id' => $orderAfHisId])->field('order_sn,id')->lock(true)->select()->toArray();
//                }
//                $rLog['orderAllAfHis'] = $orderAfHis;
            $orderAllAfHisId = [];

            //查询该订单的已完成的全部退款售后订单金额
            $oMap[] = ['order_sn', '=', $order_sn];
            $oMap[] = ['status', '=', 1];
            $oMap[] = ['type', 'in', [1, 2]];
            $oMap[] = ['', 'exp', Db::raw('user_arrive_price_time is not null')];
            $oMap[] = ['after_status', '=', 10];
            $orderAllAfHisId = AfterSale::where($oMap)->column('id');
            $rLog['orderAllAfHisId'] = $orderAllAfHisId;

            $orderAllAfHis = 0;
            $afterGoodsRealTotalPrice = 0;
            $afterGoodsDisPrice = 0;
            if (!empty($orderAllAfHisId)) {
                $orderAllAfHisGoods = AfterSale::where(['id' => $orderAllAfHisId])->lock(true)->field('id,goods_sn,order_sn,sku_sn,apply_price,status,after_status,type')->select()->toArray();
                if (!empty($orderAllAfHisGoods)) {
                    foreach ($orderAllAfHisGoods as $key => $value) {
                        if (!empty($orderGoodsInfo[$value['sku_sn']])) {
                            if ((string)$value['apply_price'] >= (string)$orderGoodsInfo[$value['sku_sn']]['total_price'] ?? 0) {
                                $afterGoodsRealTotalPrice += $orderGoodsInfo[$value['sku_sn']]['total_price'] ?? 0;
                                $afterGoodsDisPrice += $orderGoodsInfo[$value['sku_sn']]['all_dis'] ?? 0;
                            } else {
                                $afterGoodsRealTotalPrice += $value['apply_price'];
                            }
//                                $afterGoodsDisPrice += $orderGoodsInfo[$value['sku_sn']]['all_dis'] ?? 0;
                        } else {
                            $afterGoodsRealTotalPrice += $value['apply_price'];
                        }

                        $orderAllAfHis += $value['apply_price'];
                    }
                }
//                    $orderAllAfHis = AfterSale::where(['id' => $orderAllAfHisId])->lock(true)->sum('apply_price');
            }
            $rLog['orderAllAfHis'] = $orderAllAfHis;
            $rLog['afterGoodsRealTotalPrice'] = $afterGoodsRealTotalPrice;

//                $gMap[] = ['order_sn', '=', $order_sn];
//                $gMap[] = ['after_status', 'in', [4]];
//                $gMap[] = ['sku_sn', '<>', $afInfo['sku_sn']];
//                $gMap[] = ['goods_sn', '<>', $afInfo['goods_sn']];
//                $rLog['orderGoodsHisMap'] = $gMap;
//                $orderGoodsHis = OrderGoods::where($gMap)->count();
//                $orderGoodsHisId = OrderGoods::where($gMap)->column('id');
//                $orderGoodsHis = 0;
//                if (!empty($orderGoodsHisId)) {
//                    $orderGoodsHis = OrderGoods::where(['id' => $orderGoodsHisId])->lock(true)->count();
//                }
//                $rLog['orderGoodsHis'] = $orderGoodsHis;

            //拼团订单取消,只有这个商品退全款的时候才取消
            if ($aOrder['order_type'] == 2 && !empty($orderGoodsFullAfter)) {
                $ptOrder = PtOrder::update(['pay_status' => -2, 'status' => -2], ['order_sn' => $order_sn, 'status' => 1, 'pay_status' => 2]);
            }


            $userBalance['uid'] = $aOrder['uid'];
            $userBalance['order_sn'] = $aOrder['order_sn'];
            $userBalance['belong'] = 1;
            $userBalance['type'] = 1;
            $userBalance['price'] = $afInfo['apply_price'] ?? 0;
            $userBalance['change_type'] = 5;
            $userBalance['remark'] = '售后服务退款';

            $refundDetail['refund_sn'] = (new CodeBuilder())->buildRefundSn();
            $refundDetail['uid'] = $aOrder['uid'];
            $refundDetail['order_sn'] = $aOrder['order_sn'];
            $refundDetail['after_sale_sn'] = $afInfo['after_sale_sn'];
            $refundDetail['refund_price'] = $afInfo['apply_price'];
            $refundDetail['all_pay_price'] = $aOrder['real_pay_price'];
            $refundDetail['refund_desc'] = $userBalance['remark'];
            $refundDetail['refund_account'] = 1;
            $refundDetail['pay_status'] = 1;
            //退款金额不为0是修改账户明细
            if (!empty(doubleval($afInfo['apply_price']))) {
                $afDetailModel = new AfterSaleDetail();
                //添加退款明细
                $refundRes = RefundDetail::create($refundDetail);
                switch ($aOrder['pay_type'])
                {
                    case 1:
                        //添加用户账户明细
                        $balanceRes = BalanceDetail::create($userBalance);
                        //修改用户余额
                        $userRes = (new User())->where(['uid' => $userBalance['uid'], 'status' => 1])->inc('divide_balance', $userBalance['price'])->update();
                        break;
                    case 5:
                        $crowdBalance['uid'] = $aOrder['uid'];
                        $crowdBalance['order_sn'] = $aOrder['order_sn'];
                        $crowdBalance['belong'] = 1;
                        $crowdBalance['type'] = 1;
                        $crowdBalance['price'] = $afInfo['apply_price'] ?? 0;
                        $crowdBalance['change_type'] = 13;
                        $crowdBalance['remark'] = '售后服务退款';

                        //添加用户账户明细
                        $balanceRes = CrowdfundingBalanceDetail::create($crowdBalance);
                        //修改用户余额
                        $userRes = (new User())->where(['uid' => $userBalance['uid'], 'status' => 1])->inc('crowd_balance', $crowdBalance['price'])->update();
                        break;
                    case 7:
                        $integralBalance['uid'] = $aOrder['uid'];
                        $integralBalance['order_sn'] = $aOrder['order_sn'];
                        $integralBalance['type'] = 1;
                        $integralBalance['integral'] = $afInfo['apply_price'] ?? 0;
                        $integralBalance['change_type'] = 9;
                        $integralBalance['remark'] = '售后服务退款';

                        //添加用户账户明细
                        $balanceRes = IntegralDetail::create($integralBalance);
                        //修改用户余额
                        $userRes = (new User())->where(['uid' => $integralBalance['uid'], 'status' => 1])->inc('integral', $integralBalance['integral'])->update();
                        break;
                }

            }

            $userArrivePriceTime = time();
            //修改售后订单状态
            $afterSaleRes = AfterSale::update(['real_withdraw_price' => $userBalance['price'], 'after_status' => 10, 'close_time' => time(), 'user_arrive_price_time' => $userArrivePriceTime], ['after_sale_sn' => $afInfo['after_sale_sn']])->toArray();

            //修改订单商品退款金额
            if (!empty(doubleval($afInfo['apply_price']))) {
                $orderGoodsRes = OrderGoods::update(['refund_price' => $userBalance['price'], 'withdraw_time' => $afInfo['withdraw_time'] ?? time(), 'refund_arrive_time' => $userArrivePriceTime], ['order_sn' => $order_sn, 'goods_sn' => $afInfo['goods_sn'], 'sku_sn' => $afInfo['sku_sn']]);
            }

            $rLog['afterSaleRes'] = $afterSaleRes;
            $rLog['orderGoodsRes'] = $orderGoodsRes;

            $afDetailModel = new AfterSaleDetail();
            //添加退款详情
            $detail['after_sale_sn'] = $afInfo['after_sale_sn'];
            $detail['operate_user'] = '商家';
            $detail['after_type'] = 7;
            $afDetailModel->DBNew($detail);
            //添加售后完结流程
            $detail['after_sale_sn'] = $afInfo['after_sale_sn'];
            $detail['operate_user'] = '系统';
            $detail['after_type'] = 10;
            $afDetailModel->DBNew($detail);

            //是否为整单售后
            $allOrderCancel = false;
            //修改订单状态
            //判断是否为整单售后的依据是 以下公式是否成立: 正在申请的商品的申请金额(如果大于商品总价则使用商品总价,不包含运费) + ( 历史退款商品的商品总价(累加每个商品的商品总价,也不包含运费) - 历史退款商品的商品优惠总价(累加每个商品的优惠总价)<加这个是为了兼容使用了优惠券退款的判断> ) >= 订单实付金额 - 运费 (即商品总价)
//                if ((string)($afInfo['apply_price'] + $orderAllAfHis) >= (string)($aOrder['real_pay_price'] - $aOrder['fare_price'])) {
            if ((string)($thisGoodsRealTotalPrice + ($afterGoodsRealTotalPrice - $afterGoodsDisPrice ?? 0)) >= (string)($aOrder['real_pay_price'] - $aOrder['fare_price'])) {
                $allOrderCancel = true;
                $saveOrder['order_status'] = -3;
            } else {
                //如果不是整单全部商品金额售后(算是整单部分售后),且该售后商品不是全款退,又是最后一个售后完成的订单商品,则修改订单状态为之前的状态(已发货)
                if ((count($orderAllAfHisId) == ((count($aOrder['goods'])) - 1))) {
                    if (!empty($aOrder['shipping_code']) || (!empty($aOrder['shipping_type']) && $aOrder['shipping_type'] == 2)) {
                        $saveOrder['order_status'] = 3;
                    }
                }
            }
//                if ((string)(1 + $orderGoodsHis) >= (string)count($aOrder['goods'])) {
//                    $allOrderCancel = true;
//                    $saveOrder['order_status'] = -3;
//                }
            $saveOrder['after_status'] = 4;
            $rLog['saveOrder'] = $saveOrder;

            $orderRes = (new AfterSale())->changeOrderAfterStatus($afInfo, $aOrder, $saveOrder, true);
            $rLog['orderRes'] = $orderRes;

            //取消分润和团队业绩
//                if (!empty(doubleval($afInfo['apply_price']))) {
            $pOrder['order_sn'] = $aOrder['order_sn'];
            $pOrder['uid'] = $aOrder['uid'];
            //如果是整单售后则直接取消全部的分润规则
            if (!empty($allOrderCancel)) {

                //转售订单取消,整单售后恢复原始订单的次数
                if ($aOrder['order_type'] == 5 && !empty($allOrderCancel)) {
                    //修改转售原纪录为未操作, 操作类型写死为转售
                    Handsel::update(['order_sn' => null, 'order_uid' => null, 'operate_status' => 2, 'operate_time' => null, 'select_type' => 3], ['handsel_sn' => $aOrder['handsel_sn'], 'operate_status' => 1, 'status' => 1]);
                }

                $divideService = (new Divide());
                $divideRes = $divideService->deductMoneyForDivideByOrderSn($aOrder['order_sn'], $afInfo['sku_sn'], $aOrder);
                //取消团队所有上级的团队业绩
                $performanceRes = $divideService->getTopUserRecordTeamPerformance($pOrder, 2);
            } else {
                //如果是部分售后(不是退全部金额的情况下),需要按照退款的金额比例然后扣除掉已计算好的实际可分润金额, 如果是退全部金额的情况下,则删除之前的对应商品的分润记录
                //如何界定是否为部分退售后 申请金额是否大于等于(该商品总价(不包含邮费)-该商品总优惠)
                if (empty(doubleval($afInfo['apply_price'])) || (string)$afInfo['apply_price'] >= (string)($thisOrderGoods['total_price'] - ($thisOrderGoods['all_dis'] ?? 0))) {
                    $refundScale = 1;
                } else {
                    //计算的分母为(该商品总价(不包含邮费)-该商品总优惠),避免出现收支金额(主要是涉及分润的计算基础基数问题)不平衡的情况
                    $refundScale = round($afInfo['apply_price'] / ($thisOrderGoods['total_price'] - ($thisOrderGoods['all_dis'] ?? 0)), 2);
                }

                //如果退款金额已经超过了商品总价(不算运费,扣除优惠),则相当于整个商品取消分润
                if ($refundScale >= 1) {
                    $divideRes = (new Divide())->deductMoneyForDivideByOrderSn($aOrder['order_sn'], $afInfo['sku_sn'], $aOrder);
                } else {
                    $thisGoodsDivide = DivideModel::where(['order_sn' => $aOrder['order_sn'], 'arrival_status' => 2, 'goods_sn' => $afInfo['goods_sn'], 'sku_sn' => $afInfo['sku_sn']])->field('id,price,count,total_price,divide_price,dis_reduce_price,refund_reduce_price,real_divide_price,arrival_status')->select()->toArray();
                    if (!empty($thisGoodsDivide)) {
                        foreach ($thisGoodsDivide as $key => $value) {
                            $reduce[$value['id']] = priceFormat($value['divide_price'] * $refundScale);
                        }
                        if (!empty($reduce)) {
                            foreach ($thisGoodsDivide as $key => $value) {
                                if (!empty($reduce[$value['id']]) && $reduce[$value['id']] > 0) {
                                    DivideModel::where(['order_sn' => $aOrder['order_sn'], 'arrival_status' => 2, 'goods_sn' => $afInfo['goods_sn'], 'sku_sn' => $afInfo['sku_sn'], 'id' => $value['id']])->inc('refund_reduce_price', $reduce[$value['id']])->update();
                                    DivideModel::where(['order_sn' => $aOrder['order_sn'], 'arrival_status' => 2, 'goods_sn' => $afInfo['goods_sn'], 'sku_sn' => $afInfo['sku_sn'], 'id' => $value['id']])->dec('real_divide_price', $reduce[$value['id']])->update();
                                }

                            }
                        }
                    }
                }

                //取消团队所有上级的部分团队业绩
                $performanceRes = (new Divide())->getTopUserRecordTeamPerformance($pOrder, 2, $afInfo['sku_sn']);
                //取消团队订单,增加团队订单的退款金额(需要注意,以后别的模块计算团队的订单的总金额向需要扣除对应的退款金额,才是真实的订单有效金额)
                if (!empty(doubleval($afInfo['apply_price']))) {
                    TeamPerformance::where(['order_sn' => $aOrder['order_sn'], 'goods_sn' => $afInfo['goods_sn'], 'sku_sn' => $afInfo['sku_sn'], 'status' => 1])->inc('refund_price', $afInfo['apply_price'])->update();
                }
                if ($refundScale >= 1) {
                    $teamOrderRes = TeamPerformance::update(['status' => -1, 'record_status' => -1], ['order_sn' => $aOrder['order_sn'], 'goods_sn' => $afInfo['goods_sn'], 'sku_sn' => $afInfo['sku_sn']]);
                }
            }
//                }

            //取消成长值
            if (!empty(doubleval($afInfo['apply_price']))) {
                //如果是整单售后则直接取消全部的成长值奖励
                if (!empty($allOrderCancel)) {
                    $growthRes = (new GrowthValue())->cancelGrowthValue(2, ['order_sn' => $order_sn, 'cancel_type' => 1]);
                } else {
                    //如果是部分售后则按照当前商品的实付价格换算成成长值,然后减去,如果是最后一个则减去全部剩下的成长值
                    $allGoods = $aOrder['goods'];
                    $afAccessNumber = 0;
                    $allGoodsNumber = count($allGoods);
                    $allRefundPrice = 0;
                    $allTotalPrice = 0;
                    foreach ($allGoods as $key => $value) {
                        //售后成功被关闭的商品
                        if (!empty($value['status']) && $value['status'] == -2) {
                            $afAccessNumber += 1;
                        }
                        $allRefundPrice += ((string)$value['refund_price'] >= (string)$value['total_price']) ? $value['total_price'] : $value['refund_price'];
                        $allTotalPrice += (($value['total_price'] ?? 0) - ($value['all_dis'] ?? 0));
                        $orderGoods[$value['sku_sn']] = $value;
                    }

                    //修改为按照退货金额来判断是否取消全部成长值
//                        if ($afAccessNumber + 1 >= $allGoodsNumber) {
                    if ((string)$allRefundPrice >= (string)$allTotalPrice) {
                        $growthRes = (new GrowthValue())->cancelGrowthValue(2, ['order_sn' => $order_sn, 'cancel_type' => 1]);
                    } else {

                        //按实际申请金额算(不包含邮费,如果申请金额超过实际商品总金额则按照实际商品总金额计算扣除成长值)
                        if ((string)$afInfo['apply_price'] >= (string)($thisOrderGoods['total_price'] - ($thisOrderGoods['all_dis'] ?? 0))) {
                            $pricePart = $thisOrderGoods['total_price'] - ($thisOrderGoods['all_dis'] ?? 0);
                        } else {
                            $pricePart = $afInfo['apply_price'];
                        }

                        if (!empty(doubleval($pricePart))) {
                            $growthRes = (new GrowthValue())->cancelGrowthValue(2, ['order_sn' => $order_sn, 'cancel_type' => 2, 'price_part' => $pricePart]);
                        }
                        //按商品总价算(不包含邮费)
//                            $growthRes = (new GrowthValue())->cancelGrowthValue(2, ['order_sn' => $order_sn, 'cancel_type' => 2, 'price_part' =>$orderGoods[$afInfo['sku_sn']]]['total_price']);
                    }
                }
            }
            $rLog['msg'] = '订单 ' . $order_sn . ' 退款处理成功';
            return $rLog;
        });
//        } else {
//            $res['errorMsg'] = '该订单已被关闭,可能已经处理好业务订单取消,故无法继续';
//        }

        $log['msg'] = '已接受到订单 ' . $callbackData['out_trade_no'] . ' 的退款回调,退款编号为' . $callbackData['out_refund_no'];
        $log['data']['callBackData'] = $callbackData;
        $log['data']['dealRes'] = $res ?? [];
        $this->log($log, 'info', 'refundCallback');
        //清除缓存锁
        cache($cacheKey, null);
        Cache::dec($cacheNumKey);
        return judge($res['orderRes'] ?? null);
    }

    /**
     * @title  完成退款设备订单回调
     * @param array $callbackData
     * @return mixed
     */
    public function completeDeviceRefund(array $callbackData)
    {
        $order_sn = $callbackData['out_trade_no'];
        $afSn = $callbackData['out_refund_no'];
        $searNumber = $callbackData['searNumber'] ?? 0;
        $aOrder = [];

        $res = Db::transaction(function () use ($callbackData, $afSn, $order_sn, $aOrder, $searNumber) {
            $order_sn = $callbackData['out_trade_no'];
            $afSn = $callbackData['out_refund_no'];
            $rLog['orderRes'] = DeviceOrder::update(['close_time' => time(), 'order_status' => -3, 'after_status' => 4], ['order_sn' => $order_sn, 'order_status' => 2, 'pay_status' => 2]);

            $userBalance['uid'] = $aOrder['uid'];
            $userBalance['order_sn'] = $aOrder['order_sn'];
            $userBalance['belong'] = 1;
            $userBalance['type'] = 1;
            $userBalance['price'] = $aOrder['real_pay_price'] ?? 0;
            $userBalance['change_type'] = 5;
            $userBalance['remark'] = '售后服务退款';

            //退款金额不为0是修改账户明细
            if (!empty(doubleval($aOrder['real_pay_price']))) {
                $afDetailModel = new AfterSaleDetail();

                if ($aOrder['pay_type'] == 1) {
                    //添加用户账户明细
                    $balanceRes = BalanceDetail::create($userBalance);
                    //修改用户余额
                    $userRes = (new User())->where(['uid' => $userBalance['uid'], 'status' => 1])->inc('area_balance', $userBalance['price'])->update();
                }
                if ($aOrder['pay_type'] == 7) {
                    $healthyBalance['uid'] = $aOrder['uid'];
                    $healthyBalance['order_sn'] = $aOrder['order_sn'];
                    $healthyBalance['belong'] = 1;
                    $healthyBalance['type'] = 1;
                    $healthyBalance['price'] = $aOrder['real_pay_price'] ?? 0;
                    $healthyBalance['change_type'] = 3;
                    $healthyBalance['remark'] = '售后服务退款';

                    //添加用户账户明细
                    $balanceRes = HealthyBalanceDetail::create($healthyBalance);
                    //修改用户余额
                    $userRes = (new User())->where(['uid' => $userBalance['uid'], 'status' => 1])->inc('healthy_balance', $healthyBalance['price'])->update();
                }
            }
            //没有分润可以处理, 因为分润是在完成订单后才存在
            $rLog['msg'] = '订单 ' . $order_sn . ' 退款处理成功';
            return $rLog;
        });

        $log['msg'] = '已接受到设备订单 ' . $callbackData['out_trade_no'] . ' 的退款回调,退款编号为' . $callbackData['out_refund_no'];
        $log['data']['callBackData'] = $callbackData;
        $log['data']['dealRes'] = $res ?? [];
        $this->log($log, 'info', 'refundCallback');

        return judge($res['orderRes'] ?? null);
    }

    /**
     * @title  完成退款订单回调<拼团失败专用>
     * @param array $callbackData
     * @return mixed
     */
    public function completePtRefund(array $callbackData)
    {
        $order_sn = $callbackData['out_trade_no'];

        $overTimePtOrder = PtOrder::with(['orders'])->where(['order_sn' => $order_sn])->field('uid,activity_sn,activity_code,order_sn,goods_sn,sku_sn,user_role,activity_type,activity_status,pay_status')->findOrEmpty()->toArray();
        if ($overTimePtOrder['activity_status'] == 3) {
            $res = Db::transaction(function () use ($callbackData, $order_sn, $overTimePtOrder) {
                $orderSn = $callbackData['out_trade_no'];
                $afSn = $callbackData['out_refund_no'] ?? null;
                $aOrder = $overTimePtOrder['orders'];
                $needRefundDetail = false;
                if ($overTimePtOrder['pay_status'] == 2) {
                    $needRefundDetail = true;
                }

                //修改拼团订单为退款
                if (!empty($needRefundDetail)) {
                    $ptOrderSave['pay_status'] = -2;
                    $ptOrderSave['status'] = -2;
                } else {
                    $ptOrderSave['status'] = -1;
                }

                $ptRes = PtOrder::update($ptOrderSave, ['order_sn' => $orderSn, 'pay_status' => 1]);
                //先锁行后自减库存
                $goodsId = GoodsSku::where(['sku_sn' => $overTimePtOrder['sku_sn']])->column('id');
                $ptGoodsId = PtGoodsSku::where(['activity_code' => $overTimePtOrder['activity_code'], 'goods_sn' => $overTimePtOrder['goods_sn'], 'sku_sn' => $overTimePtOrder['sku_sn']])->column('id');
                if (!empty($goodsId)) {
                    $lockGoods = GoodsSku::where(['id' => $goodsId])->lock(true)->field('id,goods_sn,sku_sn')->findOrEmpty()->toArray();
                    //恢复库存
                    $skuRes = GoodsSku::where(['sku_sn' => $overTimePtOrder['sku_sn']])->inc('stock', 1)->update();
                }

                if (!empty($ptGoodsId)) {
                    $lockPtGoods = PtGoodsSku::where(['id' => $ptGoodsId])->lock(true)->field('id,activity_code,goods_sn,sku_sn')->findOrEmpty()->toArray();
                    //恢复拼团库存
                    $ptStockRes = PtGoodsSku::where(['activity_code' => $overTimePtOrder['activity_code'], 'goods_sn' => $overTimePtOrder['goods_sn'], 'sku_sn' => $overTimePtOrder['sku_sn']])->inc('stock', 1)->update();

                    //如果是开团需要恢复开团次数
                    if (!empty($overTimePtOrder['user_role']) && $overTimePtOrder['user_role'] == 1) {
                        PtGoodsSku::where(['activity_code' => $overTimePtOrder['activity_code'], 'goods_sn' => $overTimePtOrder['goods_sn'], 'sku_sn' => $overTimePtOrder['sku_sn']])->inc('start_number', 1)->update();
                    }
                }

                //修改对应优惠券状态
                $aOrderCoupons = OrderCoupon::where(['order_sn' => $orderSn, 'used_status' => 1])->field('coupon_uc_code,coupon_code')->select()->toArray();
                $aOrderUcCoupon = array_column($aOrderCoupons, 'coupon_uc_code');
                if (!empty($aOrderUcCoupon)) {
                    //修改订单优惠券状态为取消使用
                    $orderCouponRes = OrderCoupon::update(['used_status' => -1], ['order_sn' => $orderSn, 'used_status' => 1]);
                    //修改用户订单优惠券状态为未使用
                    $uCouponRes = UserCoupon::update(['valid_status' => 1], ['uc_code' => $aOrderUcCoupon]);
                }

                if (!empty($needRefundDetail)) {
                    $userBalance['uid'] = $overTimePtOrder['uid'];
                    $userBalance['order_sn'] = $overTimePtOrder['order_sn'];
                    $userBalance['belong'] = 1;
                    $userBalance['type'] = 1;
                    $userBalance['price'] = $aOrder['real_pay_price'];
                    $userBalance['change_type'] = 5;
                    $userBalance['remark'] = '拼团未成功全额退款';

                    $refundDetail['refund_sn'] = $afSn;
                    $refundDetail['uid'] = $overTimePtOrder['uid'];
                    $refundDetail['order_sn'] = $overTimePtOrder['order_sn'];
                    $refundDetail['after_sale_sn'] = null;
                    $refundDetail['refund_price'] = $aOrder['real_pay_price'];
                    $refundDetail['all_pay_price'] = $aOrder['real_pay_price'];
                    $refundDetail['refund_desc'] = $userBalance['remark'];
                    $refundDetail['refund_account'] = 1;
                    $refundDetail['pay_status'] = 1;
                    //退款金额不为0是修改账户明细
                    if (!empty(doubleval($aOrder['real_pay_price']))) {
                        //添加退款明细
                        $refundRes = RefundDetail::create($refundDetail);
                    }
                }


                //是否为整单售后
                $allOrderCancel = true;

//                //取消分润--没有拼团成功不会产生分润,故注释
//                if(!empty(doubleval($aOrder['real_pay_price']))){
//                    $pOrder['order_sn'] = $aOrder['order_sn'];
//                    $pOrder['uid'] = $aOrder['uid'];
//                    //如果是整单售后则直接取消全部的分润规则
//                    $divideService = (new Divide());
//                    $divideRes = $divideService->deductMoneyForDivideByOrderSn($aOrder['order_sn'], $overTimePtOrder['sku_sn'], $aOrder);
//
//                    //取消团队所有上级的团队业绩
//                    $performanceRes = $divideService->getTopUserRecordTeamPerformance($pOrder,2,$overTimePtOrder['sku_sn']);
//
//                    //取消成长值
//                    $growthRes = (new GrowthValue())->cancelGrowthValue(2, ['order_sn' => $order_sn, 'cancel_type' => 1]);
//                }

                return true;
            });
        } else {
            $res = '该拼团订单状态异常,可能已经处理好业务订单取消,故无法继续';
        }

        $log['msg'] = '已接受到拼团订单 ' . $callbackData['out_trade_no'] . ' 的退款回调,退款编号为' . ($callbackData['out_refund_no'] ?? '无退款编码');
        $log['data']['callBackData'] = $callbackData;
        $log['data']['dealRes'] = $res ?? [];
        $this->log($log, 'info', 'refundCallback');
        return judge($res);
    }


    /**
     * @title  绑定下级用户
     * @param string $orderUser
     * @param string $orderLinkUser
     * @return mixed
     */
    public function bindDownUser(string $orderUser, string $orderLinkUser)
    {
        $dbRes = false;
        $oUserInfo = User::where(['uid' => $orderUser])->field('uid,phone,vip_level,link_superior_user')->findOrEmpty()->toArray();
        $linkUserInfo = User::with(['member', 'ParentMember'])->where(['uid' => $orderLinkUser, 'status' => 1])->field('uid,vip_level,phone,link_superior_user')->findOrEmpty()->toArray();
        $oldLinkUser = User::with(['member', 'ParentMember'])->where(['uid' => $oUserInfo['link_superior_user'], 'status' => 1])->field('uid,vip_level,phone,link_superior_user')->findOrEmpty()->toArray();

        if (empty($oUserInfo['vip_level'])) {
            if (empty($oUserInfo['link_superior_user'])) {
                //上级是自己时不操作
                if ($orderLinkUser != $orderUser) {

                    //订单用户为普通用户且无上级,可以绑定
                    //如果订单绑定用户已经存在会员表中,则直接修改订单用户的上级为订单绑定用户,如果订单绑定用户不存在会员表中,则判断订单绑定用户是否有上级,如果有上级则给订单绑定用户新建一个level为0的会员记录,以用来绑定订单用户
                    if (!empty($linkUserInfo)) {
                        if (!empty($linkUserInfo['member'])) {
                            $uBind['link_superior_user'] = $orderLinkUser;
                            $uBind['parent_team'] = $linkUserInfo['member']['child_team_code'];
                            $dbRes = User::update($uBind, ['uid' => $orderUser]);
                            (new User())->where(['uid' => $orderLinkUser])->inc('team_number', 1)->update();
                        } else {
                            if (empty($linkUserInfo['vip_level'])) {
                                $uCreate['member_card'] = (new CodeBuilder())->buildMemberNum();
                                $uCreate['uid'] = $orderLinkUser;
                                $uCreate['level'] = 0;
                                $uCreate['user_phone'] = $linkUserInfo['phone'];

                                if (!empty($linkUserInfo['ParentMember'])) {
                                    $uCreate['team_code'] = $linkUserInfo['ParentMember']['team_code'];
                                    $uCreate['parent_team'] = $linkUserInfo['ParentMember']['child_team_code'];
                                    $uCreate['link_superior_user'] = $linkUserInfo['ParentMember']['uid'];
                                    $uCreate['child_team_code'] = (new Member())->buildMemberTeamCode(3, $uCreate['parent_team']);
                                    //记录冗余团队结构
                                    //如果上级用户的团队冗余结构为空,防止出错需要重新跑一边然后为当前用户记录团队结构
                                    if ($linkUserInfo['ParentMember']['level'] != 1 && empty($linkUserInfo['ParentMember']['team_chain'] ?? null)) {
                                        $allTopUser = (new \app\lib\services\Member())->getMemberRealAllTopUser($linkUserInfo['ParentMember']['uid']);
                                        if (!empty($allTopUser)) {
                                            array_multisort(array_column($allTopUser, 'divide_level'), SORT_ASC, $allTopUser);
                                            $allTopUid = implode(',', array_column($allTopUser, 'uid'));
                                            $orderTopUser['team_chain'] = $allTopUid ?? '';
                                            $uCreate['team_chain'] = $orderTopUser['team_chain'];
                                        }
                                    } else {
                                        if (empty($linkUserInfo['ParentMember']['team_chain'] ?? '')) {
                                            $uCreate['team_chain'] = $linkUserInfo['ParentMember']['uid'];
                                        } else {
                                            $uCreate['team_chain'] = $linkUserInfo['ParentMember']['uid'] . ',' . ($linkUserInfo['ParentMember']['team_chain']);
                                        }

                                    }
                                } else {
                                    $uCreate['child_team_code'] = (new Member())->buildMemberTeamCode(3, null);
                                    $uCreate['team_code'] = $uCreate['child_team_code'];
                                    $uCreate['parent_team'] = null;
                                    $uCreate['link_superior_user'] = null;
                                    $uCreate['team_chain'] = '';
                                }

                                $uCreate['type'] = 1;

                                Member::create($uCreate);

                                //修改分润第一人冗余结构
                                Queue::push('app\lib\job\MemberChain', ['uid' => $orderLinkUser, 'handleType' => 1], config('system.queueAbbr') . 'MemberChain');

                                $uBind['link_superior_user'] = $orderLinkUser;
                                $uBind['parent_team'] = $uCreate['child_team_code'];
                                $dbRes = User::update($uBind, ['uid' => $orderUser]);
                                (new User())->where(['uid' => $orderLinkUser])->inc('team_number', 1)->update();
                            }
                        }
                    }
//            if(!empty($dbRes)){
//                //查看上级是否可以升级
//                $divideQueue = Queue::push('app\lib\job\MemberUpgrade',['uid'=>$oUserInfo['uid']],config('system.queueAbbr') . 'MemberUpgrade');
//            }
                }

            } else {
                //同一个上级不操作
                if ($orderLinkUser == $oUserInfo['link_superior_user']) {
                    return true;
                }
//                //如果原先绑定的用户会员等级为空,新的订单关联者是会员,则覆盖原先的绑定关系<-暂时先注释->
//                if(empty($oldLinkUser['vip_level']) && !empty($linkUserInfo['vip_level'])){
//                    $uBind['link_superior_user'] = $orderLinkUser;
//                    $uBind['parent_team'] = $linkUserInfo['member']['child_team_code'];
//                    $dbRes = User::update($uBind,['uid'=>$orderUser]);
//                    (new User())->where(['uid'=>$orderLinkUser])->inc('team_number',1)->update();
//
//                    (new User())->where(['uid'=>$oldLinkUser['uid']])->dec('team_number',1)->update();
//                }
            }
        }
        return $dbRes;

    }

    /**
     * @title  自动修改订单状态和发券
     * @param array $data
     * @return mixed
     * @throws \Exception
     */
    public function changeOrderStatus(array $data)
    {
        $orderSn = $data['order_sn'];
        $uid = $data['uid'];
        $skuList = $data['goods'];
        $receiveCoupon = $data['receiveCoupon'] ?? false;
        $changeOrderStatus = $data['changeOrderStatus'] ?? false;
        //优惠券自动领取数组,后续可以考虑做成动态json记录到表中
        //limit_type是否有领取时限,1是否 2为是,需要填写开始和结束时间戳,时间限制尚未完成
        $receiveCouponList = [
            0 => [
                'sku_sn' => '2191355901',
                'coupon' => ['1001202102251970003' => 2],
                'limit_type' => 1,
                'start_time' => '',
                'end_time' => ''
            ]
        ];

        //同步订单
        $syncRes = (new ShipOrder())->sync(['searOrderSn' => $orderSn]);
        $orderRes = null;
        $receiveRes = [];
        if (!empty($syncRes)) {
            if (!empty($changeOrderStatus)) {
                //修改为备货中
                $orderRes = (new Ship())->changeShippingStatus(['order_sn' => $orderSn, 'type' => 1]);
            }
            if (!empty($receiveCoupon)) {
                //自动领取对应的券
                $coupon = $receiveCouponList;
                if (!empty($coupon) && !empty($skuList)) {
                    $CouponService = (new \app\lib\services\Coupon());
                    foreach ($coupon as $key => $value) {
                        foreach ($value['coupon'] as $cKey => $cValue) {
                            if (in_array($value['sku_sn'], $skuList)) {
                                for ($i = 1; $i <= ($cValue ?? 1); $i++) {
                                    $receiveRes[$value['sku_sn']][$cKey] = $CouponService->userReceive(['uid' => $uid, 'code' => $cKey, 'notThrowError' => true]);
                                }
                            }
                        }
                    }
                }
            }
        }
        //记录日志
        $res = ['msg' => '已针对订单 ' . $orderSn . ' 进行自动同步,可能存在自动备货或发券操作', 'sync' => $syncRes ?? null, 'changeStatus' => $orderRes, 'receiveCoupon' => $receiveRes, 'receiveCouponList' => $receiveCouponList ?? []];
        $this->log($res, 'info', 'callback');

        return true;
    }

    /**
     * @title  完成众筹金的充值
     * @param array $data
     * @return bool
     */
    public function completeRecharge(array $data)
    {
        $orderSn = $data['order_sn'];
        $payNo = $data['pay_no'];
        if (empty($orderSn) || empty($payNo)) {
            return false;
        }
//        $orderInfo = CrowdfundingBalanceDetail::where(['order_sn' => $orderSn, 'status' => 3])->findOrEmpty()->toArray();
//        if (empty($orderInfo)) {
//            return false;
//        }
        $DBRes = Db::transaction(function () use ($orderSn, $payNo) {
            $orderId = CrowdfundingBalanceDetail::where(['order_sn' => $orderSn, 'status' => 3])->value('id');
            if (empty($orderId)) {
                return false;
            }
            $orderInfo = CrowdfundingBalanceDetail::where(['id' => $orderId])->lock(true)->findOrEmpty()->toArray();
            if (empty($orderInfo)) {
                return false;
            }
//            $detailRes = CrowdfundingBalanceDetail::update(['pay_no' => $payNo, 'status' => 1], ['order_sn' => $orderSn, 'status' => 3]);
            $detailRes = CrowdfundingBalanceDetail::where(['order_sn' => $orderSn, 'status' => 3])->save(['pay_no' => $payNo, 'status' => 1]);
            $userRes = false;
            if(intval($detailRes) > 0){
                $userRes = User::where(['uid' => $orderInfo['uid']])->inc('crowd_balance', $orderInfo['price'])->update();
                if (!empty($orderInfo['pay_type'] ?? null) && $orderInfo['pay_type'] == 6) {
                    $newCache['order_sn'] = $orderSn;
                    $newCache['order_status'] = 2;
                    $newCache['pay_status'] = 2;
                    cache((new \app\lib\services\Order())->agreementCrowdOrderCacheHeader . $orderSn, $newCache, 120);
                }

                if (config('system.recordRechargeDetail') == 1) {
                    //将充值的订单推入队列计算团队上级充值业绩冗余明细
                    $upgradeQueue = Queue::push('app\lib\job\TeamMemberUpgrade', ['order_sn' => $orderSn, 'type' => 5], config('system.queueAbbr') . 'TeamMemberUpgrade');
                }

                //将充值的订单推入队列直推上级充值业绩冗余明细
                Queue::push('app\lib\job\TeamMemberUpgrade', ['order_sn' => $orderSn, 'type' => 6], config('system.queueAbbr') . 'TeamMemberUpgrade');

            }

            return ['detailRes' => $detailRes, 'userRes' => $userRes];
        });
        return true;
    }

    /**
     * @title  用户充值后记录对应的每一位上级充值业绩记录
     * @param array $data
     * @return mixed
     */
    public function recordRechargeTopLinkUser(array $data)
    {
        $orderSn = $data['order_sn'];
        if (empty($orderSn)) {
            return false;
        }
        $orderInfo = CrowdfundingBalanceDetail::where(['order_sn' => $orderSn, 'status' => 1, 'type' => 1, 'is_finance' => 2])->findOrEmpty()->toArray();
        if (empty($orderInfo)) {
            return false;
        }
        $DBRes = Db::transaction(function () use ($orderSn, $orderInfo) {
            $orderId = RechargeLinkDetail::where(['order_sn' => $orderSn, 'status' => 1])->value('id');
            if (!empty($orderId)) {
                return false;
            }


            $searUid = $orderInfo['uid'];
            //查找这个用户的上级, 给每个符合条件的上级(团队会员才符合条件)添加本次充值的明细
            $databaseVersion = str_replace('.', '', config('system.databaseVersion'));
            if (!empty($databaseVersion) && is_numeric($databaseVersion) && $databaseVersion > 8016) {
                //mysql 8.0.16以上用此方法
                $linkUserParent = Db::query("SELECT u2.member_card,u2.uid,u2.phone as user_phone,u2.vip_level as level,u2.team_vip_level,u2.status,u2.link_superior_user,u1.LEVEL AS divide_level FROM(SELECT @id c_ids,(SELECT @id := GROUP_CONCAT( link_superior_user ) FROM sp_user ,(SELECT @id := " . "'" . $searUid . "'" . ",@l := 0 ) b WHERE status in (1,2) AND FIND_IN_SET( uid, @id COLLATE utf8mb4_unicode_ci )) p_ids,@l := @l + 1 AS LEVEL FROM sp_user WHERE @id IS NOT NULL ) u1 JOIN sp_user u2 ON u1.c_ids = u2.uid COLLATE utf8mb4_unicode_ci WHERE u2.status = 1 ORDER BY u1.LEVEL ASC;");
            } else {
                //mysql 8.0.16及以下用此方法
                $linkUserParent = Db::query("SELECT u2.member_card,u2.uid,u2.phone as user_phone,u2.vip_level as level,u2.team_vip_level,u2.status,u2.link_superior_user,u1.LEVEL AS divide_level FROM(SELECT @id c_ids,(SELECT @id := GROUP_CONCAT( link_superior_user ) FROM sp_user WHERE status in (1,2) AND FIND_IN_SET( uid, @id COLLATE utf8mb4_unicode_ci )) p_ids,@l := @l + 1 AS LEVEL FROM sp_user,(SELECT @id := " . "'" . $searUid . "'" . ",@l := 0 ) b WHERE @id IS NOT NULL ) u1 JOIN sp_user u2 ON u1.c_ids = u2.uid COLLATE utf8mb4_unicode_ci WHERE u2.status = 1 ORDER BY u1.LEVEL ASC;");
            }
            if (empty($linkUserParent)) {
                return false;
            }
            //剔除订单用户自己
            foreach ($linkUserParent as $key => $value) {
                if ($value['uid'] == $orderInfo['uid']) {
                    unset($linkUserParent[$key]);
                }
            }
            if (empty($linkUserParent)) {
                return false;
            }

            $existParent = [];
            $repeat = [];
            //上级用户去重, 查询出现异常时的补漏措施
            foreach ($linkUserParent as $key => $value) {
                if (!isset($existParent[$value['uid']])) {
                    $existParent[$value['uid']] = $value;
                } else {
                    $repeat[] = $value['uid'];
                    unset($linkUserParent[$key]);
                }
            }
            //去重完之后unset数组防止内存溢出
            unset($existParent);
            //存在重复上级会对后续的判断产生影响, 直接停止本次记录
            if (!empty($repeat ?? [])) {
                return false;
            }

            $topLinkUid = null;
            $secondLinkUid = null;

            foreach ($linkUserParent as $key => $value) {
                if (intval($value['divide_level']) == count($linkUserParent)) {
                    $topLinkUid = $value['uid'];
                }
                if (intval($value['divide_level']) == (count($linkUserParent) - 1)) {
                    $secondLinkUid = $value['uid'];
                }
                $linkTopUserInfo[$value['uid']] = $value['link_superior_user'] ?? null;
            }
            foreach ($linkUserParent as $key => $value) {
                if (intval($value['team_vip_level']) <= 0) {
                    unset($linkUserParent[$key]);
                }
            }

            if (empty($linkUserParent)) {
                return false;
            }
            $linkUserParent = array_values($linkUserParent);
            $recordTimePeriodStart = strtotime(date('Y-m-d', strtotime($orderInfo['create_time'])) . ' 00:00:00');
            $recordTimePeriodEnd = strtotime(date('Y-m-d', strtotime($orderInfo['create_time'])) . ' 23:59:59');
            //财务号
            $financeUid = SystemConfig::where(['id' => 1])->value('finance_uid');
            $withdrawFinanceAccount = !empty($financeUid) ? explode(',', $financeUid) : ['notFinanceUid'];

            foreach ($linkUserParent as $key => $value) {
                $newDetail[$key]['order_sn'] = $orderSn;
                $newDetail[$key]['order_uid'] = $orderInfo['uid'];
                $newDetail[$key]['price'] = $orderInfo['price'];
                $newDetail[$key]['link_uid'] = $value['uid'];
                //不同的充值渠道有不同的记录类型,不符合的直接剔除不计入
                switch ($orderInfo['remark']) {
                    case "充值":
                        $newDetail[$key]['recharge_type'] = 1;
                        break;
                    case strstr($orderInfo['remark'], '后台系统充值'):
                        $newDetail[$key]['recharge_type'] = 2;
                        break;
                    case strstr($orderInfo['remark'], '用户自主提交线下付款申请后审核通过充值'):
                        $newDetail[$key]['recharge_type'] = 3;
                        break;
                    case (strstr($orderInfo['remark'], '13840616567美丽金转入') || strstr($orderInfo['remark'], '14745543825美丽金转入') || strstr($orderInfo['remark'], '18529431349美丽金转入') || in_array($orderInfo['transfer_from_uid'],$withdrawFinanceAccount)):
                        $newDetail[$key]['recharge_type'] = 4;
                        break;
                    default:
                        $newDetail[$key]['recharge_type'] = null;
                        if (in_array($orderInfo['transfer_from_uid'], $withdrawFinanceAccount)) {
                            $newDetail[$key]['recharge_type'] = 4;
                        }
                        break;
                }
                if (empty($newDetail[$key]['recharge_type'] ?? null)) {
                    unset($newDetail[$key]);
                    unset($linkUserParent[$key]);
                    continue;
                }
                $newDetail[$key]['top_link_uid'] = $topLinkUid;
                $newDetail[$key]['second_link_uid'] = $secondLinkUid;

                $newDetail[$key]['start_time_period'] = $recordTimePeriodStart;
                $newDetail[$key]['end_time_period'] = $recordTimePeriodEnd;
            }
            if(!empty($newDetail)){
                (new RechargeLinkDetail())->saveAll($newDetail);
            }
            //汇总明细, 统计到另外一个新的冗余表, 以便后续查询数据可以直接查询
            if (!empty($newDetail ?? [])) {
                $allTopLinkUid = array_unique(array_column($newDetail, 'link_uid'));
                $checkLinkUserId = RechargeLink::where(['uid' => $allTopLinkUid, 'start_time_period' => $recordTimePeriodStart, 'end_time_period' => $recordTimePeriodEnd, 'status' => 1])->column('id');
                $checkLinkUser = RechargeLink::where(['id' => $checkLinkUserId])->lock(true)->order('create_time desc')->group('uid')->column('price', 'uid');

                $summaryDetail = [];
                foreach ($newDetail as $key => $value) {
                    if (!isset($summaryDetail[$value['link_uid']])) {
                        $summaryDetail[$value['link_uid']]['uid'] = $value['link_uid'];
                        if (!empty($checkLinkUser[$value['link_uid']] ?? null)) {
                            $summaryDetail[$value['link_uid']]['price'] = $checkLinkUser[$value['link_uid']];
                            $summaryDetail[$value['link_uid']]['type'] = 2;
                        } else {
                            $summaryDetail[$value['link_uid']]['price'] = 0;
                            $summaryDetail[$value['link_uid']]['type'] = 1;
                        }
                    }
                    $summaryDetail[$value['link_uid']]['price'] += $value['price'];
                }
                if (!empty($summaryDetail)) {
                    foreach ($summaryDetail as $key => $value) {
                        if ($value['type'] == 2) {
                            RechargeLink::update(['price' => $value['price']], ['status' => 1, 'uid' => $value['uid'], 'start_time_period' => $recordTimePeriodStart, 'end_time_period' => $recordTimePeriodEnd]);
                        } else {
                            $value['link_uid'] = $linkTopUserInfo[$value['uid']] ?? null;
                            $value['start_time_period'] = $recordTimePeriodStart;
                            $value['end_time_period'] = $recordTimePeriodEnd;
                            $totalNewSummaryDetail[] = $value;
                        }
                    }
                    if (!empty($totalNewSummaryDetail)) {
                        (new RechargeLink())->saveAll($totalNewSummaryDetail);
                    }
                }
            }
            return ['totalNewSummaryDetail' => $totalNewSummaryDetail ?? [], 'summaryDetail' => $summaryDetail ?? [], 'newDetail' => $newDetail ?? [], 'orderInfo' => $orderInfo];
        });
        return $DBRes;
    }

    /**
     * @title  判断是否存在兑换模块的商品, 是否需要自动发货或自动确认收货
     * @param array $data
     * @return bool
     * @throws \Exception
     */
    public function checkExchangeAutoShip(array $data)
    {
        $orderInfo = $data['orderInfo'] ?? null;
        $orderGoods = $data['orderGoods'] ?? null;
        if (empty($orderInfo) || empty($orderGoods)) {
            return false;
        }
        $orderInfo = Order::where(['order_sn' => $orderInfo['order_sn'], 'order_status' => [2, 3]])->findOrEmpty()->toArray();
        if (empty($orderInfo)) {
            return false;
        }
        $allSku = array_column($orderGoods, 'sku_sn');
        $exchangeGoods = ExchangeGoodsSku::where(['status' => 1, 'sku_sn' => $allSku])->select()->toArray();
        if (empty($exchangeGoods)) {
            return false;
        }

        $operCacheKey = 'orderExchangeIng-' . $orderInfo['order_sn'];

        $shipOrder = (new ShipOrder());
        $shipService = (new Ship());
        switch ($data['operType'] ?? 1){
            case 1:
                set_time_limit(0);
                ini_set('memory_limit', '3072M');
                ini_set('max_execution_time', '0');
                $needAutoShip = false;
                foreach ($exchangeGoods as $key => $value) {
                    if (!empty($value['auto_ship'] ?? null) && $value['auto_ship'] == 1 && $orderInfo['order_status'] == 2) {
                        //$orderInfo['order_status'] == 2
                        //自动同步订单
                        $syncRes[] = $shipOrder->sync(['searOrderSn' => $orderInfo['order_sn']]);
                        $needAutoShip = true;
                    }

                    if (!empty($needAutoShip)) {
                        //加上缓存操作锁, 防止操作冲突
                        cache($operCacheKey, 1, 15);
                        //再自动免物流发货
                        Queue::later(1, 'app\lib\job\Auto', ['orderInfo' => $orderInfo, 'orderGoods' => $orderGoods, 'operType' => 3, 'autoType' => 7], config('system.queueAbbr') . 'Auto');
                    }
                }
                break;
            case 2:
//                if(count($orderGoods) > 1){
//                    unset($shipOrder);
//                    unset($shipService);
//                    $needAutoSplitOrder = false;
//                    foreach ($exchangeGoods as $key => $value) {
//                        if(!empty($value['auto_ship'] ?? null) && $value['auto_ship'] == 1 && $orderInfo['order_status'] == 2) {
//                            $needAutoSplitOrder = true;
//                        }
//                    }
//                    if(!empty($needAutoSplitOrder)){
//                        //自动拆单
//                        (new Ship())->autoSplitOrder(['order_sn'=>$orderInfo['order_sn']]);
//                    }
//                }

                break;
            case 3:
                unset($shipOrder);
                unset($shipService);
                $needAutoShip = false;
                $needAutoComplete = false;
                foreach ($exchangeGoods as $key => $value) {
                    if (!empty($value['auto_ship'] ?? null) && $value['auto_ship'] == 1 && $orderInfo['order_status'] == 2) {
                        $needAutoShip = true;
                    }
                    if (!empty($value['auto_complete'] ?? null) && $value['auto_complete'] == 1){
                        $needAutoComplete = true;
                    }
                }
                //自动免物流发货
                if(!empty($needAutoShip)){
                    $shipOrder[] =  (new Ship())->noShippingCode(['order_sn' => [$orderInfo['order_sn']]]);


                    if(!empty($needAutoComplete)){
                        //再自动确认收货
                        Queue::push('app\lib\job\Auto', ['orderInfo' => $orderInfo, 'orderGoods' => $orderGoods, 'operType' => 4, 'autoType' => 7], config('system.queueAbbr') . 'Auto');
                    }else{
                        //如果没有自动确认收货就释放锁
                        cache($operCacheKey,null);
                    }
                }
                break;
            case 4:
                unset($shipOrder);
                unset($shipService);
                foreach ($exchangeGoods as $key => $value) {
                    //自动确认收货
                    if (!empty($value['auto_complete'] ?? null) && $value['auto_complete'] == 1 && $orderInfo['order_status'] == 3) {
                        $completeRes[] = (new Ship())->userConfirmReceiveGoods(['order_sn' => $orderInfo['order_sn'], 'notThrowError' => true, 'uid' => $orderInfo['uid']]);
                        cache($operCacheKey,null);
                    }
                }
                break;
        }

        return true;
    }

    /**
     * @title  判断订单商品是否有赠送额外的附属值(如健康豆或积分等)
     * @param array $data
     * @return bool|mixed
     * @throws \Exception
     */
    public function checkExchangeOrderGoods(array $data)
    {
        $orderInfo = $data['orderInfo'] ?? null;
        $orderGoods = $data['orderGoods'] ?? null;
        if(empty($orderInfo) || empty($orderGoods)){
            return false;
        }
        $allSku = array_column($orderGoods,'sku_sn');
        $exchangeGoods = ExchangeGoodsSku::where(['status' => 1, 'sku_sn' => $allSku])->select()->toArray();
        if(empty($exchangeGoods)){
            return false;
        }
        foreach ($orderGoods as $key => $value) {
            $orderGoodsInfo[$value['sku_sn']] = $value;
        }
        $healthyBalance = [];
        $healthyTotalBalance = 0;
        //判断渠道
        switch ($orderInfo['order_type']) {
            case '1':case '2':case '3':case '4':case '5':
                $channel_type = PayConstant::HEALTHY_CHANNEL_TYPE_SHOP;
                break;
            case '6':
                $channel_type = PayConstant::HEALTHY_CHANNEL_TYPE_CROWD;
                break;
            default:
                $channel_type = PayConstant::HEALTHY_CHANNEL_TYPE_SHOP;
                break;
        }
        foreach ($exchangeGoods as $key => $value) {
            if(doubleval($value['exchange_value']) > 0){
                switch ($value['type']){
                    case 1:
                        $existHealthy = HealthyBalanceDetail::where(['order_sn'=>$orderInfo['order_sn'],'status'=>1])->findOrEmpty()->toArray();
                        if (empty($existHealthy)) {
                            $healthyBalance[$key]['uid'] = $orderInfo['uid'];
                            $healthyBalance[$key]['order_sn'] = $orderInfo['order_sn'];
                            $healthyBalance[$key]['goods_sn'] = $value['goods_sn'] ?? null;
                            $healthyBalance[$key]['sku_sn'] = $value['sku_sn'] ?? null;
                            $healthyBalance[$key]['pay_no'] = $orderInfo['pay_no'] ?? null;
                            $healthyBalance[$key]['belong'] = $orderInfo['belong'] ?? 1;
                            $healthyBalance[$key]['type'] = 1;
                            $healthyBalance[$key]['healthy_channel_type'] = $channel_type;
                            $healthyBalance[$key]['price'] = priceFormat($value['exchange_value'] * ($orderGoodsInfo[$value['sku_sn']]['count'] ?? 1));
                            $healthyBalance[$key]['change_type'] = 1;
                            $healthyBalance[$key]['remark'] = '订单购物赠送';
                            $healthyTotalBalance += $healthyBalance[$key]['price'];
                        }
                        break;
                    default:
                        break;
                }
            }
        }

        $DBRes = false;
        $DBRes = Db::transaction(function () use ($orderInfo, $orderGoods, $healthyBalance, $healthyTotalBalance, $channel_type) {
            $healthyBalanceDetailRes = false;
            $userHealthyBalanceRes = false;
            $channelHealthyBalanceRes = false;
            if (!empty($healthyBalance)) {
                $healthyBalanceDetailRes = (new HealthyBalanceDetail())->saveAll(array_values($healthyBalance))->toArray();
            }
            if (!empty($healthyTotalBalance)) {
                $userHealthyBalanceRes = User::where(['uid' => $orderInfo['uid'], 'status' => 1])->inc('healthy_balance', priceFormat($healthyTotalBalance))->update();
                $healthyBalanceInfo = HealthyBalance::where(['uid'=>$orderInfo['uid'],'channel_type'=>$channel_type, 'status'=> 1])->find();
                if ($healthyBalanceInfo) {
                    $channelHealthyBalanceRes = HealthyBalance::where(['uid' => $orderInfo['uid'], 'channel_type' => $channel_type, 'status' => 1])
                            ->inc('balance', priceFormat($healthyTotalBalance))
                            ->update();
                } else {
                    $channelHealthyBalanceRes = (new HealthyBalance())->create([
                        'status'=>1,
                        'create_time'=>time(),
                        'update_time'=>time(),
                        'uid'=> $orderInfo['uid'],
                        'channel_type' => $channel_type,
                        'balance' => priceFormat($healthyTotalBalance)
                    ]);
                }
            }
            return [
                'userHealthyBalanceRes' => $userHealthyBalanceRes, 
                'healthyBalanceDetailRes' => $healthyBalanceDetailRes,
                'channelHealthyBalanceRes' => $channelHealthyBalanceRes,
            ];
        });
        return $DBRes;

    }

    /**
     * @title  记录日志
     * @param array $data 数据信息
     * @param string $level 日志信息
     * @param string $channel 日志通道
     * @return mixed
     */
    public function log(array $data, string $level = 'error', string $channel = 'callback')
    {
        return (new Log())->setChannel($channel)->record($data, $level);
    }
}