<?php
// +----------------------------------------------------------------------
// |[ 文档说明: 众筹模式业务Service]
// +----------------------------------------------------------------------



namespace app\lib\services;


use app\lib\BaseException;
use app\lib\constant\PayConstant;
use app\lib\exceptions\CrowdFundingActivityException;
use app\lib\exceptions\OrderException;
use app\lib\models\AdvanceCardDetail;
use app\lib\models\BalanceDetail;
use app\lib\models\CommonModel;
use app\lib\models\CrowdfundingActivity;
use app\lib\models\CrowdfundingActivityGoodsSku;
use app\lib\models\CrowdfundingBalanceDetail;
use app\lib\models\CrowdfundingDelayRewardOrder;
use app\lib\models\CrowdfundingFuseRecord;
use app\lib\models\CrowdfundingFuseRecordDetail;
use app\lib\models\CrowdfundingPeriod;
use app\lib\models\CrowdfundingSystemConfig;
use app\lib\models\HealthyBalance;
use app\lib\models\HealthyBalanceDetail;
use app\lib\models\IntegralDetail;
use app\lib\models\OrderGoods;
use app\lib\models\Divide;
use app\lib\models\ShipOrder;
use app\lib\models\User;
use app\lib\models\Order;
use think\facade\Cache;
use think\facade\Db;
use think\facade\Queue;

class CrowdFunding
{
    //延时发放奖励的缓存key名
    public $delayRewardOrderCacheKey = 'crowdfundingDelayRewardOrderList';

    /**
     * @title  完成众筹模式业务
     * @param array $data
     * @return mixed
     * @throws \Exception
     */
    public function completeCrowFunding(array $data)
    {
        set_time_limit(0);
        ini_set('memory_limit', '3072M');
        ini_set('max_execution_time', '0');

        $orderSn = $data['order_sn'] ?? null;
        $log['msg'] = '已收到众筹订单处理的请求';
        if (!empty($orderSn)) {
            $log['msg'] = '已收到众筹订单 <' . $orderSn . '> 的请求';
        }
        $log['requestData'] = $data;
        //处理类型 默认1 1为根据订单支付回调来判断订单对应的期是否有成功的 2为根据指定期来判断是否有成功的 3为直接指定某一期成功
        $dealType = $data['operateType'] ?? 1;
        $crowdGoods = [];
        if ($dealType == 1) {
            $orderInfo = (new Order())->info(['order_sn' => $orderSn]);
            $log['orderInfo'] = $orderInfo ?? [];
            if (empty($orderSn) || empty($orderInfo) || (!empty($orderInfo) && empty($orderInfo['goods'] ?? []))) {
                return $this->recordError($log, ['msg' => '参数有误']);
            }
            if ($orderInfo['pay_status'] != 2 || in_array($orderInfo['order_status'], [6, 7, -3, -4]) || $orderInfo['order_type'] != 6) {
                return $this->recordError($log, ['msg' => '订单状态不符合要求, 跳过该订单']);
            }
            $orderGoods = $orderInfo['goods'] ?? [];
            $crowdGoods = [];
            foreach ($orderGoods as $key => $value) {
                $crowdKey = null;
                if ($value['pay_status'] == 2 && in_array($value['after_status'], [1, -1]) && $value['status'] == 1 && !empty($value['crowd_code']) && !empty($value['crowd_round_number']) && !empty($value['crowd_period_number'])) {
                    $crowdKey = $value['crowd_code'] . '-' . $value['crowd_round_number'] . '-' . $value['crowd_period_number'];
                    if (!isset($crowdGoods[$crowdKey])) {
                        $crowdGoods[$crowdKey] = 0;
                    }
                    $crowdGoods[$crowdKey] += priceFormat($value['real_pay_price'] - $value['total_fare_price']);
                }
            }
            $log['crowdGoods'] = $crowdGoods;
            if (empty($crowdGoods)) {
                return $this->recordError($log, ['msg' => '暂无符合的商品']);
            }
        } elseif (in_array($dealType, [2, 3])) {
            if (empty($data['activity_code'] ?? null) || empty($data['round_number'] ?? null) || (empty($data['period_number'] ?? null))) {
                return $this->recordError($log, ['msg' => '参数有误']);
            }
            $crowdGoods[$data['activity_code'] . '-' . $data['round_number'] . '-' . $data['period_number']] = 0;
        }

        $periodOrderPriceInfo = [];
        $periodOrderTotalPrice = [];

        //只有处理方式为1和2的才需要真的判断期成功是否达到销售额
        if (in_array($dealType, [1, 2])) {
            //统计对应的期实际销售额, 需要实际支付的销售额和剩余销售额为0才可以认定为成功
            $gWhere[] = ['status', '=', 1];
            $gWhere[] = ['pay_status', '=', 2];
            $periodOrderPrice = OrderGoods::where(function ($query) use ($crowdGoods) {
                $number = 0;
                foreach ($crowdGoods as $key => $value) {
                    $crowdQ = explode('-', $key);
                    ${'where' . ($number + 1)}[] = ['crowd_code', '=', $crowdQ[0]];
                    ${'where' . ($number + 1)}[] = ['crowd_round_number', '=', $crowdQ[1]];
                    ${'where' . ($number + 1)}[] = ['crowd_period_number', '=', $crowdQ[2]];
                    $number++;
                }

                for ($i = 0; $i < count($crowdGoods); $i++) {
                    $allWhereOr[] = ${'where' . ($i + 1)};
                }
                $query->whereOr($allWhereOr);
            })->where($gWhere)->field('crowd_code,crowd_round_number,crowd_period_number,sum(real_pay_price - total_fare_price) as crowd_total_price')->group('crowd_code,crowd_round_number,crowd_period_number')->select()->toArray();
            $log['periodOrderPrice'] = $periodOrderPrice ?? [];

            if (empty($periodOrderPrice)) {
                return $this->recordError($log, ['msg' => '暂无符合的期订单']);
            }
            foreach ($periodOrderPrice as $key => $value) {
                $crowdKey = $value['crowd_code'] . '-' . $value['crowd_round_number'] . '-' . $value['crowd_period_number'];
                $periodOrderPriceInfo[$crowdKey] = $value;
                $periodOrderTotalPrice[$crowdKey] = $value['crowd_total_price'] ?? 0;
            }
        }

        $DBRes = Db::transaction(function () use ($crowdGoods, $orderSn, $periodOrderPriceInfo, $periodOrderTotalPrice, $dealType, $data) {

            $where[] = ['status', '=', 1];
            $where[] = ['buy_status', '=', 2];
            $where[] = ['result_status', '=', 4];
            $periodIds = CrowdfundingPeriod::where(function ($query) use ($crowdGoods) {
                $number = 0;
                foreach ($crowdGoods as $key => $value) {
                    $crowdQ = explode('-', $key);
                    ${'where' . ($number + 1)}[] = ['activity_code', '=', $crowdQ[0]];
                    ${'where' . ($number + 1)}[] = ['round_number', '=', $crowdQ[1]];
                    ${'where' . ($number + 1)}[] = ['period_number', '=', $crowdQ[2]];
                    $number++;
                }

                for ($i = 0; $i < count($crowdGoods); $i++) {
                    $allWhereOr[] = ${'where' . ($i + 1)};
                }
                $query->whereOr($allWhereOr);
            })->where($where)->column('id');
            $periodInfo = CrowdfundingPeriod::where(['id' => $periodIds])->lock(true)->select()->toArray();

            if (empty($periodInfo)) {
                return ['errorMsg' => '查无符合状态的有效期'];
            }
            //只有处理方式为1和2的才需要真的判断期成功是否达到销售额
            if (in_array($dealType, [1, 2])) {
                $successCount = 0;
                foreach ($periodInfo as $key => $value) {
                    $crowdKey = $value['activity_code'] . '-' . $value['round_number'] . '-' . $value['period_number'];
                    $periodInfos[$crowdKey] = $value;
                    //判断是否达到销售额,到达则走成功逻辑
                    if (doubleval($value['last_sales_price'] ?? 0) <= 0 && doubleval($periodOrderTotalPrice[$crowdKey] ?? 0) >= $value['sales_price']) {
                        $successPeriod[$successCount]['activity_code'] = $value['activity_code'];
                        $successPeriod[$successCount]['round_number'] = $value['round_number'];
                        $successPeriod[$successCount]['period_number'] = $value['period_number'];
                    } else {
                        continue;
                    }
                }
            } elseif ($dealType == 3) {
                $successPeriod[0]['activity_code'] = $data['activity_code'];
                $successPeriod[0]['round_number'] = $data['round_number'];
                $successPeriod[0]['period_number'] = $data['period_number'];
            }


            //成功则生成冻结奖励
            if (!empty($successPeriod)) {
                $successPeriod = array_values($successPeriod);
                //加缓存锁防止后台修改
                $cacheKey = 'CrowdFundingPeriodSuccess';
                foreach ($successPeriod as $key => $value) {
                    $cacheKey .= '-' . $value['activity_code'] . '-' . $value['round_number'] . '-' . $value['period_number'];
                    cache($cacheKey, 'inSuccessDealProcessing', 600);
                }
                $oWhere[] = ['pay_status', '=', 2];
                $oWhere[] = ['status', '=', 1];
                $oWhere[] = ['after_status', 'in', [1, -1]];
                $orderGoods = OrderGoods::with(['orderInfo'])->where(function ($query) use ($successPeriod) {
                    foreach ($successPeriod as $key => $value) {
                        ${'where' . ($key + 1)}[] = ['crowd_code', '=', $value['activity_code']];
                        ${'where' . ($key + 1)}[] = ['crowd_round_number', '=', $value['round_number']];
                        ${'where' . ($key + 1)}[] = ['crowd_period_number', '=', $value['period_number']];
                    }
                    for ($i = 0; $i < count($successPeriod); $i++) {
                        $allWhereOr[] = ${'where' . ($i + 1)};
                    }
                    $query->whereOr($allWhereOr);
                })->where($oWhere)->select()->toArray();

                //剔除不合法的订单数据
                if (!empty($orderGoods)) {
                    foreach ($orderGoods as $key => $value) {
                        if (empty($value['orderInfo'] ?? []) || (!empty($value['orderInfo']) && $value['orderInfo']['pay_status'] != 2)) {
                            unset($orderGoods[$key]);
                            continue;
                        }
                    }
                }

                //根据订单生成冻结分润
                if (!empty($orderGoods)) {
                    $orderGoods = array_values($orderGoods);
                    foreach ($orderGoods as $key => $value) {
                        if (empty($value['orderInfo'] ?? []) || (!empty($value['orderInfo']) && $value['orderInfo']['pay_status'] != 2)) {
                            unset($orderGoods[$key]);
                            continue;
                        }
                        $allOrderUid[] = $value['orderInfo']['uid'];
                    }
                    $linkUser = [];
                    $linkUserList = User::where(['uid' => array_values($allOrderUid)])->field('link_superior_user,uid,vip_level,phone')->select()->toArray();
                    if (!empty($linkUserList)) {
                        foreach ($linkUserList as $key => $value) {
                            $linkUser[$value['uid']] = $value['link_superior_user'];
                            $linkUserInfo[$value['uid']] = $value;
                        }
                    }
                    $gratefulVdcOne = CrowdfundingSystemConfig::where(['id' => 1])->value('grateful_vdc_one');
                    $divideData = [];
                    $topDivideData = [];
                    $orderModel = (new Order());
                    foreach ($orderGoods as $key => $value) {
                        $crowdKey = $value['crowd_code'] . '-' . $value['crowd_round_number'] . '-' . $value['crowd_period_number'];
                        $divideData[$key]['order_sn'] = $value['order_sn'];
                        $divideData[$key]['order_uid'] = $value['orderInfo']['uid'];
                        $divideData[$key]['goods_sn'] = $value['goods_sn'];
                        $divideData[$key]['sku_sn'] = $value['sku_sn'];
                        $divideData[$key]['crowd_code'] = $value['crowd_code'];
                        $divideData[$key]['crowd_round_number'] = $value['crowd_round_number'];
                        $divideData[$key]['crowd_period_number'] = $value['crowd_period_number'];
                        $divideData[$key]['type'] = 8;
                        $divideData[$key]['vdc'] = $periodInfos[$crowdKey]['reward_scale'] ?? 0;
                        $divideData[$key]['level'] = $value['user_level'] ?? 0;
                        $divideData[$key]['link_uid'] = $value['orderInfo']['uid'];
                        $divideData[$key]['divide_price'] = priceFormat(($value['real_pay_price'] - $value['total_fare_price']) * $divideData[$key]['vdc']);
                        $divideData[$key]['real_divide_price'] = $divideData[$key]['divide_price'];
                        $divideData[$key]['arrival_status'] = 2;
                        $divideData[$key]['total_price'] = priceFormat(($value['real_pay_price'] - $value['total_fare_price']));
                        $divideData[$key]['purchase_price'] = priceFormat(($value['real_pay_price'] - $value['total_fare_price']));
                        $divideData[$key]['price'] = $value['price'];
                        $divideData[$key]['count'] = $value['count'];
                        $divideData[$key]['vdc_genre'] = 2;
                        $divideData[$key]['divide_type'] = 2;
                        $divideData[$key]['remark'] = '福利活动奖励(' . $crowdKey . ')';
                        //上级感恩奖奖励
                        if (!empty($linkUser[$value['orderInfo']['uid']] ?? [])) {
                            $topDivideData[$key]['order_sn'] = $value['order_sn'];
                            $topDivideData[$key]['order_uid'] = $value['orderInfo']['uid'];
                            $topDivideData[$key]['goods_sn'] = $value['goods_sn'];
                            $topDivideData[$key]['sku_sn'] = $value['sku_sn'];
                            $topDivideData[$key]['crowd_code'] = $value['crowd_code'];
                            $topDivideData[$key]['crowd_round_number'] = $value['crowd_round_number'];
                            $topDivideData[$key]['crowd_period_number'] = $value['crowd_period_number'];
                            $topDivideData[$key]['type'] = 8;
                            $topDivideData[$key]['is_grateful'] = 1;
                            $topDivideData[$key]['vdc'] = $gratefulVdcOne ?? 0;
                            $topDivideData[$key]['level'] = $linkUserInfo[$value['orderInfo']['uid']]['vip_level'] ?? 0;
                            $topDivideData[$key]['link_uid'] = $linkUser[$value['orderInfo']['uid']];
                            $topDivideData[$key]['divide_price'] = priceFormat(($divideData[$key]['real_divide_price']) * $topDivideData[$key]['vdc']);
                            $topDivideData[$key]['real_divide_price'] = $topDivideData[$key]['divide_price'];
                            $topDivideData[$key]['arrival_status'] = 2;
                            $topDivideData[$key]['total_price'] = priceFormat(($divideData[$key]['real_divide_price']));
                            $topDivideData[$key]['purchase_price'] = priceFormat(($divideData[$key]['real_divide_price']));
                            $topDivideData[$key]['price'] = $value['price'];
                            $topDivideData[$key]['count'] = $value['count'];
                            $topDivideData[$key]['vdc_genre'] = 2;
                            $topDivideData[$key]['divide_type'] = 2;
                            $topDivideData[$key]['remark'] = '福利活动感恩奖奖励(' . $crowdKey . ')';
                        }
//                        //修改对应的所有订单为可同步----新逻辑无需发货
//                        $orderModel->isAutoWriteTimestamp(false)->update(['can_sync' => 1], ['order_sn' => $value['order_sn']]);
                    }

                    if (!empty($divideData)) {
                        (new Divide())->saveAll($divideData);
                    }

                    if (!empty($topDivideData)) {
                        (new Divide())->saveAll($topDivideData);
                    }

                    //生成团队业绩分润,分批慢慢推送
                    $teamOrderGoods = array_values($orderGoods);
                    foreach ($teamOrderGoods as $key => $value) {
                        if ($key == 0) {
                            $teamDivideQueue[$key] = Queue::push('app\lib\job\TeamDividePrice', ['order_sn' => $value['order_sn'], 'searNumber' => 1], config('system.queueAbbr') . 'TeamOrderDivide');
                        } else {
                            $teamDivideQueue[$key] = Queue::later((intval($key) * 1), 'app\lib\job\TeamDividePrice', ['order_sn' => $value['order_sn'], 'searNumber' => 1], config('system.queueAbbr') . 'TeamOrderDivide');
                        }

                    }
                    if (!empty($allOrderUid)) {
                        //判断用户除了此单是否还购买过其他众筹订单, 如果没有则为众筹新用户, 不是会员则可以指定成为初级会员
                        $uMap[] = ['uid', 'in', $allOrderUid];
                        $uMap[] = ['status', '=', 1];
                        $uMap[] = ['vip_level', '=', 0];
                        $notLevelUser = User::where($uMap)->field('uid,phone,vip_level,link_superior_user')->select()->toArray();
                        if (!empty($notLevelUser)) {
                            foreach ($notLevelUser as $key => $value) {
                                $notLevelUserInfo[$value['uid']] = $value;
                            }
                            $oMap[] = ['order_type', '=', 6];
                            $oMap[] = ['pay_status', 'in', [2]];
                            $oMap[] = ['order_status', 'in', [2, 3, 6, 8]];
                            $oMap[] = ['uid', 'in', array_column($notLevelUser, 'uid')];
                            $existOtherOrder = Order::where($oMap)->field('order_sn,uid,count(id) as all_order_number,sum(real_pay_price) as all_order_price')->group('uid')->select()->toArray();
                            if (!empty($existOtherOrder)) {
                                //只有一次购买记录的人才可以成为会员,<预留总购物金额的统计, 尚未加上判断>--修改为只要总购买金额大于200元且身份为普通人的就可以升级

                                foreach ($existOtherOrder as $key => $value) {
//                                    if(intval($value['all_order_number']) == 1){
//                                        $allExistOrderUid[] = $value['uid'];
//                                    }
                                    //只要总购买金额大于200元且身份为普通人的就可以升级
                                    if (doubleval($value['all_order_price']) >= 200) {
                                        $allExistOrderUid[] = $value['uid'];
                                    }
                                }

                                if (!empty($allExistOrderUid)) {
                                    foreach ($notLevelUser as $key => $value) {
                                        if (!in_array($value['uid'], $allExistOrderUid)) {
                                            unset($notLevelUser[$key]);
                                            continue;
                                        }
                                    }
                                    if (!empty($notLevelUser)) {
                                        $notLevelUser = array_values($notLevelUser);
                                        foreach ($notLevelUser as $key => $value) {
                                            if ($value['vip_level'] <= 0) {
                                                if ($key == 0) {
                                                    $becomeMemberQueue[$key] = Queue::push('app\lib\job\MemberUpgrade', ['uid' => $value['uid'], 'link_superior_user' => $value['link_superior_user'], 'user_phone' => $value['phone'], 'type' => 3, 'coder_remark' => '福利专区赠送会员', 'is_send' => true], config('system.queueAbbr') . 'MemberUpgrade');
                                                } else {
                                                    $teamDivideQueue[$key] = Queue::later((intval($key) * 1), 'app\lib\job\MemberUpgrade', ['uid' => $value['uid'], 'link_superior_user' => $value['link_superior_user'], 'user_phone' => $value['phone'], 'type' => 3, 'coder_remark' => '福利专区赠送会员', 'is_send' => true], config('system.queueAbbr') . 'MemberUpgrade');
                                                }
                                            }
                                        }
                                    }
                                }
                            }
                        }
                    }
                }

                foreach ($successPeriod as $key => $value) {
                    //修改本期为成功认购满状态
                    CrowdfundingPeriod::update(['buy_status' => 1, 'result_status' => 2, 'success_time' => time()], ['activity_code' => $value['activity_code'], 'round_number' => $value['round_number'], 'period_number' => $value['period_number'], 'buy_status' => 2, 'result_status' => [4]]);
                    //清除判断缓存
                    Cache::delete('crowdFundPeriodJudgePrice-' . $value['activity_code'] . '-' . $value['round_number'] . '-' . $value['period_number']);

                    //尝试捕获异常
                    try {
                        //自动开新的一期
                        $regenerateRes = $this->regeneratePeriod(['activity_code' => $value['activity_code'], 'round_number' => $value['round_number'], 'period_number' => $value['period_number'], 'type' => 1]);
                    } catch (BaseException $e) {
                        $errorMsg = $e->msg;
                        $errorCode = $e->errorCode;
                        $regenerateRes['regeneratePeriodMsg'] = '自动生成新一期出现了错误';
                        $regenerateRes['regeneratePeriodErrorMsg'] = $errorMsg;
                        $regenerateRes['regeneratePeriodErrorCode'] = $errorCode;
                        $regenerateRes['regeneratePeriodData'] = $value;
                    }
                    //判断N-3轮是否能够成功释放奖金
                    Queue::push('app\lib\job\CrowdFunding', ['dealType' => 3, 'activity_code' => $value['activity_code'], 'round_number' => $value['round_number'], 'period_number' => $value['period_number'], 'searType' => 2], config('system.queueAbbr') . 'CrowdFunding');

                    //生成冻结中的历史熔断分期返回美丽金
                    Queue::later(15, 'app\lib\job\CrowdFunding', ['dealType' => 7, 'periodList' => [0 => ['crowd_code' => $value['activity_code'], 'crowd_round_number' => $value['round_number'], 'crowd_period_number' => $value['period_number']]]], config('system.queueAbbr') . 'CrowdFunding');

                }
            }

            $returnData =  ['periodInfo' => $periodInfo ?? [], 'orderGoods' => $orderGoods ?? [], 'divideData' => $divideData ?? [], 'topDivideData' => $topDivideData ?? [], 'successPeriod' => $successPeriod ?? [], 'regenerateRes' => $regenerateRes ?? []];

            //释放变量, 释放内存, 防止内存泄露
            unset($divideData);
            unset($topDivideData);
            unset($orderGoods);
            unset($successPeriod);
            unset($teamDivideQueue);

            return $returnData;
        });
        //新逻辑无需发货
//        if (!empty($DBRes['successPeriod'] ?? [])) {
//            foreach ($DBRes['successPeriod'] as $key => $value) {
//                //同步熔本期的众筹活动所有订单到发货订单
//                $syncOrder['searCrowdFunding'] = true;
//                $syncOrder['order_type'] = 6;
//                if (!empty($DBRes['orderGoods'] ?? [])) {
//                    $syncOrder['start_time'] = date('Y-m-d H:i:s', (strtotime(min(array_column($DBRes['orderGoods'], 'create_time'))) - 3600));
//                } else {
//                    $syncOrder['start_time'] = date('Y-m-d', strtotime('-2 day')) . " 00:00:00";
//                }
//                $syncOrder['end_time'] = date('Y-m-d', time()) . " 23:59:59";
//                $syncOrder['searCrowdKey'] = $value['activity_code'] . '-' . $value['round_number'] . '-' . $value['period_number'];
//                (new ShipOrder())->sync($syncOrder);
//            }
//
//        }

        $log['DBRes'] = $DBRes;
        $this->log($log, 'info');
        gc_collect_cycles();
        return true;


    }

    /**
     * @title  完成众筹模式业务(新方法, 不发货送美丽豆)
     * @param array $data
     * @return mixed
     * @throws \Exception
     */
    public function completeCrowFundingNew(array $data)
    {
        set_time_limit(0);
        ini_set('memory_limit', '3072M');
        ini_set('max_execution_time', '0');

        $orderSn = $data['order_sn'] ?? null;
        $log['msg'] = '已收到众筹订单处理的请求';
        if (!empty($orderSn)) {
            $log['msg'] = '已收到众筹订单 <' . $orderSn . '> 的请求';
        }
        $log['requestData'] = $data;
        //处理类型 默认1 1为根据订单支付回调来判断订单对应的期是否有成功的 2为根据指定期来判断是否有成功的 3为直接指定某一期成功
        $dealType = $data['operateType'] ?? 1;
        $crowdGoods = [];
        if ($dealType == 1) {
            $orderInfo = (new Order())->info(['order_sn' => $orderSn]);
            $log['orderInfo'] = $orderInfo ?? [];
            if (empty($orderSn) || empty($orderInfo) || (!empty($orderInfo) && empty($orderInfo['goods'] ?? []))) {
                return $this->recordError($log, ['msg' => '参数有误']);
            }
            if ($orderInfo['pay_status'] != 2 || in_array($orderInfo['order_status'], [6, 7, -3, -4]) || $orderInfo['order_type'] != 6) {
                return $this->recordError($log, ['msg' => '订单状态不符合要求, 跳过该订单']);
            }
            $orderGoods = $orderInfo['goods'] ?? [];
            $crowdGoods = [];
            foreach ($orderGoods as $key => $value) {
                $crowdKey = null;
                if ($value['pay_status'] == 2 && in_array($value['after_status'], [1, -1]) && $value['status'] == 1 && !empty($value['crowd_code']) && !empty($value['crowd_round_number']) && !empty($value['crowd_period_number'])) {
                    $crowdKey = $value['crowd_code'] . '-' . $value['crowd_round_number'] . '-' . $value['crowd_period_number'];
                    if (!isset($crowdGoods[$crowdKey])) {
                        $crowdGoods[$crowdKey] = 0;
                    }
                    $crowdGoods[$crowdKey] += priceFormat($value['real_pay_price'] - $value['total_fare_price']);
                }
            }
            $log['crowdGoods'] = $crowdGoods;
            if (empty($crowdGoods)) {
                return $this->recordError($log, ['msg' => '暂无符合的商品']);
            }
        } elseif (in_array($dealType, [2, 3])) {
            if (empty($data['activity_code'] ?? null) || empty($data['round_number'] ?? null) || (empty($data['period_number'] ?? null))) {
                return $this->recordError($log, ['msg' => '参数有误']);
            }
            $crowdGoods[$data['activity_code'] . '-' . $data['round_number'] . '-' . $data['period_number']] = 0;
        }

        $periodOrderPriceInfo = [];
        $periodOrderTotalPrice = [];

        //只有处理方式为1和2的才需要真的判断期成功是否达到销售额
        if (in_array($dealType, [1, 2])) {
            //统计对应的期实际销售额, 需要实际支付的销售额和剩余销售额为0才可以认定为成功
            $gWhere[] = ['status', '=', 1];
            $gWhere[] = ['pay_status', '=', 2];
            $periodOrderPrice = OrderGoods::where(function ($query) use ($crowdGoods) {
                $number = 0;
                foreach ($crowdGoods as $key => $value) {
                    $crowdQ = explode('-', $key);
                    ${'where' . ($number + 1)}[] = ['crowd_code', '=', $crowdQ[0]];
                    ${'where' . ($number + 1)}[] = ['crowd_round_number', '=', $crowdQ[1]];
                    ${'where' . ($number + 1)}[] = ['crowd_period_number', '=', $crowdQ[2]];
                    $number++;
                }

                for ($i = 0; $i < count($crowdGoods); $i++) {
                    $allWhereOr[] = ${'where' . ($i + 1)};
                }
                $query->whereOr($allWhereOr);
            })->where($gWhere)->field('crowd_code,crowd_round_number,crowd_period_number,sum(real_pay_price - total_fare_price) as crowd_total_price')->group('crowd_code,crowd_round_number,crowd_period_number')->select()->toArray();
            $log['periodOrderPrice'] = $periodOrderPrice ?? [];

            if (empty($periodOrderPrice)) {
                return $this->recordError($log, ['msg' => '暂无符合的期订单']);
            }
            foreach ($periodOrderPrice as $key => $value) {
                $crowdKey = $value['crowd_code'] . '-' . $value['crowd_round_number'] . '-' . $value['crowd_period_number'];
                $periodOrderPriceInfo[$crowdKey] = $value;
                $periodOrderTotalPrice[$crowdKey] = $value['crowd_total_price'] ?? 0;
            }
        }

        $DBRes = Db::transaction(function () use ($crowdGoods, $orderSn, $periodOrderPriceInfo, $periodOrderTotalPrice, $dealType, $data) {

            $where[] = ['status', '=', 1];
            $where[] = ['buy_status', '=', 2];
            $where[] = ['result_status', '=', 4];
            $periodIds = CrowdfundingPeriod::where(function ($query) use ($crowdGoods) {
                $number = 0;
                foreach ($crowdGoods as $key => $value) {
                    $crowdQ = explode('-', $key);
                    ${'where' . ($number + 1)}[] = ['activity_code', '=', $crowdQ[0]];
                    ${'where' . ($number + 1)}[] = ['round_number', '=', $crowdQ[1]];
                    ${'where' . ($number + 1)}[] = ['period_number', '=', $crowdQ[2]];
                    $number++;
                }

                for ($i = 0; $i < count($crowdGoods); $i++) {
                    $allWhereOr[] = ${'where' . ($i + 1)};
                }
                $query->whereOr($allWhereOr);
            })->where($where)->column('id');
            $periodInfo = CrowdfundingPeriod::where(['id' => $periodIds])->lock(true)->select()->toArray();

            if (empty($periodInfo)) {
                return ['errorMsg' => '查无符合状态的有效期'];
            }
            //只有处理方式为1和2的才需要真的判断期成功是否达到销售额
            if (in_array($dealType, [1, 2])) {
                $successCount = 0;
                foreach ($periodInfo as $key => $value) {
                    $crowdKey = $value['activity_code'] . '-' . $value['round_number'] . '-' . $value['period_number'];
                    $periodInfos[$crowdKey] = $value;
                    //判断是否达到销售额,到达则走成功逻辑
                    if (doubleval($value['last_sales_price'] ?? 0) <= 0 && doubleval($periodOrderTotalPrice[$crowdKey] ?? 0) >= $value['sales_price']) {
                        $successPeriod[$successCount]['activity_code'] = $value['activity_code'];
                        $successPeriod[$successCount]['round_number'] = $value['round_number'];
                        $successPeriod[$successCount]['period_number'] = $value['period_number'];
                    } else {
                        continue;
                    }
                }
            } elseif ($dealType == 3) {
                $successPeriod[0]['activity_code'] = $data['activity_code'];
                $successPeriod[0]['round_number'] = $data['round_number'];
                $successPeriod[0]['period_number'] = $data['period_number'];
            }


            //成功则生成冻结奖励
            if (!empty($successPeriod)) {
                $successPeriod = array_values($successPeriod);
                //加缓存锁防止后台修改
                $cacheKey = 'CrowdFundingPeriodSuccess';
                foreach ($successPeriod as $key => $value) {
                    $cacheKey .= '-' . $value['activity_code'] . '-' . $value['round_number'] . '-' . $value['period_number'];
                    cache($cacheKey, 'inSuccessDealProcessing', 600);
                }
                $oWhere[] = ['pay_status', '=', 2];
                $oWhere[] = ['status', '=', 1];
                $oWhere[] = ['after_status', 'in', [1, -1]];
                $orderGoods = OrderGoods::with(['orderInfo'])->where(function ($query) use ($successPeriod) {
                    foreach ($successPeriod as $key => $value) {
                        ${'where' . ($key + 1)}[] = ['crowd_code', '=', $value['activity_code']];
                        ${'where' . ($key + 1)}[] = ['crowd_round_number', '=', $value['round_number']];
                        ${'where' . ($key + 1)}[] = ['crowd_period_number', '=', $value['period_number']];
                    }
                    for ($i = 0; $i < count($successPeriod); $i++) {
                        $allWhereOr[] = ${'where' . ($i + 1)};
                    }
                    $query->whereOr($allWhereOr);
                })->where($oWhere)->select()->toArray();

                //剔除不合法的订单数据
                if (!empty($orderGoods)) {
                    foreach ($orderGoods as $key => $value) {
                        if (empty($value['orderInfo'] ?? []) || (!empty($value['orderInfo']) && $value['orderInfo']['pay_status'] != 2)) {
                            unset($orderGoods[$key]);
                            continue;
                        }
                    }
                }

                //根据订单生成冻结分润
                if (!empty($orderGoods)) {
                    $orderGoods = array_values($orderGoods);
                    foreach ($orderGoods as $key => $value) {
                        if (empty($value['orderInfo'] ?? []) || (!empty($value['orderInfo']) && $value['orderInfo']['pay_status'] != 2)) {
                            unset($orderGoods[$key]);
                            continue;
                        }
                        $allOrderUid[] = $value['orderInfo']['uid'];
                    }
                    $linkUser = [];
                    $linkUserList = User::where(['uid' => array_values($allOrderUid)])->field('link_superior_user,uid,vip_level,phone')->select()->toArray();
                    if (!empty($linkUserList)) {
                        foreach ($linkUserList as $key => $value) {
                            $linkUser[$value['uid']] = $value['link_superior_user'];
                            $linkUserInfo[$value['uid']] = $value;
                        }
                    }
                    $gratefulVdcOne = CrowdfundingSystemConfig::where(['id' => 1])->value('grateful_vdc_one');
                    $divideData = [];
                    $topDivideData = [];
                    $orderModel = (new Order());
                    foreach ($orderGoods as $key => $value) {
                        $crowdKey = $value['crowd_code'] . '-' . $value['crowd_round_number'] . '-' . $value['crowd_period_number'];
                        $divideData[$key]['order_sn'] = $value['order_sn'];
                        $divideData[$key]['order_uid'] = $value['orderInfo']['uid'];
                        $divideData[$key]['goods_sn'] = $value['goods_sn'];
                        $divideData[$key]['sku_sn'] = $value['sku_sn'];
                        $divideData[$key]['crowd_code'] = $value['crowd_code'];
                        $divideData[$key]['crowd_round_number'] = $value['crowd_round_number'];
                        $divideData[$key]['crowd_period_number'] = $value['crowd_period_number'];
                        $divideData[$key]['type'] = 8;
                        $divideData[$key]['vdc'] = $periodInfos[$crowdKey]['reward_scale'] ?? 0;
                        $divideData[$key]['level'] = $value['user_level'] ?? 0;
                        $divideData[$key]['link_uid'] = $value['orderInfo']['uid'];
                        $divideData[$key]['divide_price'] = priceFormat(($value['real_pay_price'] - $value['total_fare_price']) * $divideData[$key]['vdc']);
                        $divideData[$key]['real_divide_price'] = $divideData[$key]['divide_price'];
                        $divideData[$key]['arrival_status'] = 2;
                        $divideData[$key]['total_price'] = priceFormat(($value['real_pay_price'] - $value['total_fare_price']));
                        $divideData[$key]['purchase_price'] = priceFormat(($value['real_pay_price'] - $value['total_fare_price']));
                        $divideData[$key]['price'] = $value['price'];
                        $divideData[$key]['count'] = $value['count'];
                        $divideData[$key]['vdc_genre'] = 2;
                        $divideData[$key]['divide_type'] = 2;
                        $divideData[$key]['remark'] = '福利活动奖励(' . $crowdKey . ')';
                        //上级感恩奖奖励
                        if (!empty($linkUser[$value['orderInfo']['uid']] ?? [])) {
                            $topDivideData[$key]['order_sn'] = $value['order_sn'];
                            $topDivideData[$key]['order_uid'] = $value['orderInfo']['uid'];
                            $topDivideData[$key]['goods_sn'] = $value['goods_sn'];
                            $topDivideData[$key]['sku_sn'] = $value['sku_sn'];
                            $topDivideData[$key]['crowd_code'] = $value['crowd_code'];
                            $topDivideData[$key]['crowd_round_number'] = $value['crowd_round_number'];
                            $topDivideData[$key]['crowd_period_number'] = $value['crowd_period_number'];
                            $topDivideData[$key]['type'] = 8;
                            $topDivideData[$key]['is_grateful'] = 1;
                            $topDivideData[$key]['vdc'] = $gratefulVdcOne ?? 0;
                            $topDivideData[$key]['level'] = $linkUserInfo[$value['orderInfo']['uid']]['vip_level'] ?? 0;
                            $topDivideData[$key]['link_uid'] = $linkUser[$value['orderInfo']['uid']];
                            $topDivideData[$key]['divide_price'] = priceFormat(($divideData[$key]['real_divide_price']) * $topDivideData[$key]['vdc']);
                            $topDivideData[$key]['real_divide_price'] = $topDivideData[$key]['divide_price'];
                            $topDivideData[$key]['arrival_status'] = 2;
                            $topDivideData[$key]['total_price'] = priceFormat(($divideData[$key]['real_divide_price']));
                            $topDivideData[$key]['purchase_price'] = priceFormat(($divideData[$key]['real_divide_price']));
                            $topDivideData[$key]['price'] = $value['price'];
                            $topDivideData[$key]['count'] = $value['count'];
                            $topDivideData[$key]['vdc_genre'] = 2;
                            $topDivideData[$key]['divide_type'] = 2;
                            $topDivideData[$key]['remark'] = '福利活动感恩奖奖励(' . $crowdKey . ')';
                        }
                        //赠送美丽豆明细
                        if ($value['gift_type'] > -1 && doubleval($value['gift_number']) > 0) {
                            switch ($value['gift_type']) {
                                case 1:
                                    $integralDetail[$key]['order_sn'] = $value['order_sn'];
                                    $integralDetail[$key]['uid'] = $value['orderInfo']['uid'];
                                    $integralDetail[$key]['goods_sn'] = $value['goods_sn'] ?? null;
                                    $integralDetail[$key]['sku_sn'] = $value['sku_sn'] ?? null;
                                    $integralDetail[$key]['crowd_code'] = $value['crowd_code'] ?? null;
                                    $integralDetail[$key]['crowd_round_number'] = $value['crowd_round_number'] ?? null;
                                    $integralDetail[$key]['crowd_period_number'] = $value['crowd_period_number'] ?? null;
                                    $integralDetail[$key]['change_type'] = 6;
                                    $integralDetail[$key]['remark'] = '福利活动参与赠送(' . $crowdKey . ')';
                                    $integralDetail[$key]['integral'] = $value['gift_number'];
                                    $integralDetail[$key]['create_time'] = strtotime($value['create_time'] ?? time());
                                    $integralDetail[$key]['update_time'] = strtotime($value['update_time'] ?? time());
                                    break;
                                case 2:
                                    $healthyDetail[$key]['order_sn'] = $value['order_sn'];
                                    $healthyDetail[$key]['uid'] = $value['orderInfo']['uid'];
                                    $healthyDetail[$key]['goods_sn'] = $value['goods_sn'] ?? null;
                                    $healthyDetail[$key]['sku_sn'] = $value['sku_sn'] ?? null;
                                    $healthyDetail[$key]['crowd_code'] = $value['crowd_code'] ?? null;
                                    $healthyDetail[$key]['crowd_round_number'] = $value['crowd_round_number'] ?? null;
                                    $healthyDetail[$key]['crowd_period_number'] = $value['crowd_period_number'] ?? null;
                                    $healthyDetail[$key]['change_type'] = 1;
                                    $healthyDetail[$key]['remark'] = '健康活动参与赠送(' . $crowdKey . ')';
                                    $healthyDetail[$key]['price'] = $value['gift_number'];
                                    $healthyDetail[$key]['pay_type'] = 77;
                                    $healthyDetail[$key]['create_time'] = strtotime($value['create_time'] ?? time());
                                    $healthyDetail[$key]['update_time'] = strtotime($value['update_time'] ?? time());
                                    //判断渠道, 非福利渠道的都是商城渠道, 只有充值的是消费型股东渠道
                                    switch ($value['orderInfo']['order_type'] ?? 6) {
                                        case '6':
                                            $healthyDetail[$key]['healthy_channel_type'] = PayConstant::HEALTHY_CHANNEL_TYPE_CROWD;
                                            break;
                                        default:
                                            $healthyDetail[$key]['healthy_channel_type'] = PayConstant::HEALTHY_CHANNEL_TYPE_SHOP;
                                            break;
                                    }
                                    //福利活动健康豆渠道默认为2
                                    $healthyChannelType = PayConstant::HEALTHY_CHANNEL_TYPE_CROWD;
                                    break;
                                default:
                                    $notOper = [];
                                    break;
                            }
                        }
                    }

                    if (!empty($divideData)) {
                        $batchSqlDivideData['list'] = $divideData;
                        $batchSqlDivideData['db_name'] = 'sp_divide';
                        (new Divide())->DBSaveAll($batchSqlDivideData);
//                        (new Divide())->saveAll($divideData);

                    }

                    if (!empty($topDivideData)) {
                        $batchSqlTopDivideData['list'] = $topDivideData;
                        $batchSqlTopDivideData['db_name'] = 'sp_divide';
                        (new Divide())->DBSaveAll($batchSqlTopDivideData);
//                        (new Divide())->saveAll($topDivideData);
                    }

                    //插入积分明细
                    $sqls = null;
                    if (!empty($integralDetail ?? [])) {
                        //检查是否有已经赠送的, 如有则直接剔除
                        $eiWhere[] = ['status', '=', 1];
                        $existIntegralRecord = IntegralDetail::where(function ($query) use ($successPeriod) {
                            foreach ($successPeriod as $key => $value) {
                                ${'where' . ($key + 1)}[] = ['crowd_code', '=', $value['activity_code']];
                                ${'where' . ($key + 1)}[] = ['crowd_round_number', '=', $value['round_number']];
                                ${'where' . ($key + 1)}[] = ['crowd_period_number', '=', $value['period_number']];
                            }
                            for ($i = 0; $i < count($successPeriod); $i++) {
                                $allWhereOr[] = ${'where' . ($i + 1)};
                            }
                            $query->whereOr($allWhereOr);
                        })->where($eiWhere)->column('order_sn');
                        if (!empty($existIntegralRecord)) {
                            foreach ($integralDetail as $key => $value) {
                                if (in_array($value['order_sn'], $existIntegralRecord)) {
                                    unset($integralDetail[$key]);
                                }
                            }
                        }
                    }
                    if (!empty($integralDetail ?? [])) {
                        $integralDetail = array_values($integralDetail);

                        //批量自增
                        $batchSqlIntegralData['list'] = $integralDetail;
                        $batchSqlIntegralData['db_name'] = 'sp_user';
                        $batchSqlIntegralData['id_field'] = 'uid';
                        $batchSqlIntegralData['operate_field'] = 'integral';
                        $batchSqlIntegralData['value_field'] = 'integral';
                        $batchSqlIntegralData['operate_type'] = 'inc';
                        $batchSqlIntegralData['sear_type'] = 1;
                        $batchSqlIntegralData['other_map'] = 'status = 1';
                        (new IntegralDetail())->DBBatchIncOrDecBySql($batchSqlIntegralData);

                        //批量新增明细
                        $batchSqlIntegralDetailData['list'] = $integralDetail;
                        $batchSqlIntegralDetailData['db_name'] = 'sp_integral_detail';
                        $batchSqlIntegralDetailData['sear_type'] = 1;
                        (new IntegralDetail())->DBSaveAll($batchSqlIntegralDetailData);
//                (new IntegralDetail())->saveAll(array_values($integralDetail));
                    }

                    //插入健康豆明细
                    $sqls = null;
                    if (!empty($healthyDetail ?? [])) {
                        //检查是否有已经赠送的, 如有则直接剔除
                        $ehWhere[] = ['status', '=', 1];
                        $existHealthyRecord = HealthyBalanceDetail::where(function ($query) use ($successPeriod) {
                            foreach ($successPeriod as $key => $value) {
                                ${'where' . ($key + 1)}[] = ['crowd_code', '=', $value['activity_code']];
                                ${'where' . ($key + 1)}[] = ['crowd_round_number', '=', $value['round_number']];
                                ${'where' . ($key + 1)}[] = ['crowd_period_number', '=', $value['period_number']];
                            }
                            for ($i = 0; $i < count($successPeriod); $i++) {
                                $allWhereOr[] = ${'where' . ($i + 1)};
                            }
                            $query->whereOr($allWhereOr);
                        })->where($ehWhere)->column('order_sn');
                        if (!empty($existHealthyRecord ?? [])) {
                            foreach ($healthyDetail as $key => $value) {
                                if (in_array($value['order_sn'], $existHealthyRecord)) {
                                    unset($healthyDetail[$key]);
                                }
                            }
                        }
                    }
                    if (!empty($healthyDetail ?? [])) {
                        $healthyDetail = array_values($healthyDetail);
                        $userGiftHealthy = [];
                        $needUpdateGiftHealthyUser = [];

                        foreach ($healthyDetail as $key => $value) {
                            //统计所有明细每个人用户得到的健康豆, 插入健康豆渠道表
                            if (!isset($userGiftHealthy[$value['uid']])) {
                                $userGiftHealthy[$value['uid']] = 0;
                            }
                            $userGiftHealthy[$value['uid']] += $value['price'];
                        }

                        //批量用户表总健康豆余额自增
                        $batchSqlHealthyData['list'] = $healthyDetail;
                        $batchSqlHealthyData['db_name'] = 'sp_user';
                        $batchSqlHealthyData['id_field'] = 'uid';
                        $batchSqlHealthyData['operate_field'] = 'healthy_balance';
                        $batchSqlHealthyData['value_field'] = 'price';
                        $batchSqlHealthyData['operate_type'] = 'inc';
                        $batchSqlHealthyData['sear_type'] = 1;
                        $batchSqlHealthyData['other_map'] = 'status = 1';
                        (new HealthyBalanceDetail())->DBBatchIncOrDecBySql($batchSqlHealthyData);

                        //批量新增健康豆明细
                        $batchSqlHealthyDetailData['list'] = $healthyDetail;
                        $batchSqlHealthyDetailData['db_name'] = 'sp_healthy_balance_detail';
                        $batchSqlHealthyDetailData['sear_type'] = 1;
                        (new HealthyBalanceDetail())->DBSaveAll($batchSqlHealthyDetailData);

                        //添加或新增健康豆渠道冗余表
                        //查询每个人在健康豆福利渠道是否存在数据, 如果不存在则新增, 存在则自增
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
                        if(!empty($needUpdateGiftHealthyUser ?? [])){
                            //健康豆冗余表批量自增
                            $batchSqlHealthyBalanceData['list'] = $needUpdateGiftHealthyUser;
                            $batchSqlHealthyBalanceData['db_name'] = 'sp_healthy_balance';
                            $batchSqlHealthyBalanceData['id_field'] = 'uid';
                            $batchSqlHealthyBalanceData['operate_field'] = 'balance';
                            $batchSqlHealthyBalanceData['operate_type'] = 'inc';
                            $batchSqlHealthyBalanceData['sear_type'] = 1;
                            $batchSqlHealthyBalanceData['other_map'] = 'status = 1 and channel_type = '.($healthyChannelType ?? 2);
                            (new HealthyBalance())->DBBatchIncOrDecBySql($batchSqlHealthyBalanceData);
                        }

                        //添加健康豆渠道冗余表明细
                        if (!empty($newGiftHealthyUser ?? [])) {
                            foreach ($newGiftHealthyUser as $key => $value) {
                                $newGiftHealthyData[$key]['uid'] = $key;
                                $newGiftHealthyData[$key]['balance'] = $value;
                                $newGiftHealthyData[$key]['channel_type'] = ($healthyChannelType ?? 2);
                                $newGiftHealthyData[$key]['status'] = 1;
                            }
                            if (!empty($newGiftHealthyData ?? [])) {
                                $batchSqlHealthyBalanceNewData['list'] = array_values($newGiftHealthyData);
                                $batchSqlHealthyBalanceNewData['db_name'] = 'sp_healthy_balance';
                                $batchSqlHealthyBalanceNewData['sear_type'] = 1;
                                $batchSqlHealthyBalanceNewData['auto_fill_status'] = true;
                                (new HealthyBalance())->DBSaveAll($batchSqlHealthyBalanceNewData);
                            }
                        }

//                (new HealthyBalanceDetail())->saveAll(array_values($healthyDetail));
                    }

                    //生成团队业绩分润,分批慢慢推送
                    $teamOrderGoods = array_values($orderGoods);
                    foreach ($teamOrderGoods as $key => $value) {
                        if ($key == 0) {
                            $teamDivideQueue[$key] = Queue::push('app\lib\job\TeamDividePrice', ['order_sn' => $value['order_sn'], 'searNumber' => 1], config('system.queueAbbr') . 'TeamOrderDivide');
                        } else {
                            $teamDivideQueue[$key] = Queue::later((intval($key) * 1), 'app\lib\job\TeamDividePrice', ['order_sn' => $value['order_sn'], 'searNumber' => 1], config('system.queueAbbr') . 'TeamOrderDivide');
                        }

                    }
                    if (!empty($allOrderUid)) {
                        //判断用户除了此单是否还购买过其他众筹订单, 如果没有则为众筹新用户, 不是会员则可以指定成为初级会员
                        $uMap[] = ['uid', 'in', $allOrderUid];
                        $uMap[] = ['status', '=', 1];
                        $uMap[] = ['vip_level', '=', 0];
                        $notLevelUser = User::where($uMap)->field('uid,phone,vip_level,link_superior_user')->select()->toArray();
                        if (!empty($notLevelUser)) {
                            foreach ($notLevelUser as $key => $value) {
                                $notLevelUserInfo[$value['uid']] = $value;
                            }
                            $oMap[] = ['order_type', '=', 6];
                            $oMap[] = ['pay_status', 'in', [2]];
                            $oMap[] = ['order_status', 'in', [2, 3, 6, 8]];
                            $oMap[] = ['uid', 'in', array_column($notLevelUser, 'uid')];
                            $existOtherOrder = Order::where($oMap)->field('order_sn,uid,count(id) as all_order_number,sum(real_pay_price) as all_order_price')->group('uid')->select()->toArray();
                            if (!empty($existOtherOrder)) {
                                //只有一次购买记录的人才可以成为会员,<预留总购物金额的统计, 尚未加上判断>--修改为只要总购买金额大于200元且身份为普通人的就可以升级

                                foreach ($existOtherOrder as $key => $value) {
//                                    if(intval($value['all_order_number']) == 1){
//                                        $allExistOrderUid[] = $value['uid'];
//                                    }
                                    //只要总购买金额大于200元且身份为普通人的就可以升级
                                    if (doubleval($value['all_order_price']) >= 200) {
                                        $allExistOrderUid[] = $value['uid'];
                                    }
                                }

                                if (!empty($allExistOrderUid)) {
                                    foreach ($notLevelUser as $key => $value) {
                                        if (!in_array($value['uid'], $allExistOrderUid)) {
                                            unset($notLevelUser[$key]);
                                            continue;
                                        }
                                    }
                                    if (!empty($notLevelUser)) {
                                        $notLevelUser = array_values($notLevelUser);
                                        foreach ($notLevelUser as $key => $value) {
                                            if ($value['vip_level'] <= 0) {
                                                if ($key == 0) {
                                                    $becomeMemberQueue[$key] = Queue::push('app\lib\job\MemberUpgrade', ['uid' => $value['uid'], 'link_superior_user' => $value['link_superior_user'], 'user_phone' => $value['phone'], 'type' => 3, 'coder_remark' => '福利专区赠送会员', 'is_send' => true], config('system.queueAbbr') . 'MemberUpgrade');
                                                } else {
                                                    $teamDivideQueue[$key] = Queue::later((intval($key) * 1), 'app\lib\job\MemberUpgrade', ['uid' => $value['uid'], 'link_superior_user' => $value['link_superior_user'], 'user_phone' => $value['phone'], 'type' => 3, 'coder_remark' => '福利专区赠送会员', 'is_send' => true], config('system.queueAbbr') . 'MemberUpgrade');
                                                }
                                            }
                                        }
                                    }
                                }
                            }
                        }
                    }
                }

                foreach ($successPeriod as $key => $value) {
                    //修改本期为成功认购满状态
                    CrowdfundingPeriod::update(['buy_status' => 1, 'result_status' => 2, 'success_time' => time()], ['activity_code' => $value['activity_code'], 'round_number' => $value['round_number'], 'period_number' => $value['period_number'], 'buy_status' => 2, 'result_status' => [4]]);
                    //清除判断缓存
                    Cache::delete('crowdFundPeriodJudgePrice-' . $value['activity_code'] . '-' . $value['round_number'] . '-' . $value['period_number']);

                    //尝试捕获异常
                    try {
                        //自动开新的一期
                        $regenerateRes = $this->regeneratePeriod(['activity_code' => $value['activity_code'], 'round_number' => $value['round_number'], 'period_number' => $value['period_number'], 'type' => 1]);
                    } catch (BaseException $e) {
                        $errorMsg = $e->msg;
                        $errorCode = $e->errorCode;
                        $regenerateRes['regeneratePeriodMsg'] = '自动生成新一期出现了错误';
                        $regenerateRes['regeneratePeriodErrorMsg'] = $errorMsg;
                        $regenerateRes['regeneratePeriodErrorCode'] = $errorCode;
                        $regenerateRes['regeneratePeriodData'] = $value;
                    }
                    //判断N-3轮是否能够成功释放奖金
                    Queue::push('app\lib\job\CrowdFunding', ['dealType' => 3, 'activity_code' => $value['activity_code'], 'round_number' => $value['round_number'], 'period_number' => $value['period_number'], 'searType' => 2], config('system.queueAbbr') . 'CrowdFunding');

                    //生成冻结中的历史熔断分期返回美丽金
                    Queue::later(15, 'app\lib\job\CrowdFunding', ['dealType' => 7, 'periodList' => [0 => ['crowd_code' => $value['activity_code'], 'crowd_round_number' => $value['round_number'], 'crowd_period_number' => $value['period_number']]]], config('system.queueAbbr') . 'CrowdFunding');

                }
            }

            $returnData = ['periodInfo' => $periodInfo ?? [], 'orderGoods' => $orderGoods ?? [], 'divideData' => $divideData ?? [], 'topDivideData' => $topDivideData ?? [], 'successPeriod' => $successPeriod ?? [], 'regenerateRes' => $regenerateRes ?? []];

            //释放变量, 释放内存, 防止内存泄露
            unset($divideData);
            unset($topDivideData);
            unset($orderGoods);
            unset($successPeriod);
            unset($teamDivideQueue);
            unset($existIntegralRecord);
            unset($healthyDetail);
            unset($integralDetail);
            unset($existHealthyRecord);
            unset($userGiftHealthy);

            return $returnData;
        });

        //新方案无需发货
//        if (!empty($DBRes['successPeriod'] ?? [])) {
//            foreach ($DBRes['successPeriod'] as $key => $value) {
//                //同步熔本期的众筹活动所有订单到发货订单
//                $syncOrder['searCrowdFunding'] = true;
//                $syncOrder['order_type'] = 6;
//                if (!empty($DBRes['orderGoods'] ?? [])) {
//                    $syncOrder['start_time'] = date('Y-m-d H:i:s', (strtotime(min(array_column($DBRes['orderGoods'], 'create_time'))) - 3600));
//                } else {
//                    $syncOrder['start_time'] = date('Y-m-d', strtotime('-2 day')) . " 00:00:00";
//                }
//                $syncOrder['end_time'] = date('Y-m-d', time()) . " 23:59:59";
//                $syncOrder['searCrowdKey'] = $value['activity_code'] . '-' . $value['round_number'] . '-' . $value['period_number'];
//                (new ShipOrder())->sync($syncOrder);
//            }
//
//        }

        $log['DBRes'] = $DBRes;
        $this->log($log, 'info');
        gc_collect_cycles();
        return true;


    }

    /**
     * @title  查找是否有未达到销售额的期,有则做相应队列推送
     * @param array $data
     * @return bool
     * @throws \Exception
     */
    public function checkExpireUndonePeriod(array $data = [])
    {
        $map[] = ['status', 'in', [1, 2]];
        $map[] = ['end_time', '<=', time()];
        $map[] = ['limit_type', '=', 1];
        $map[] = ['buy_status', '=', 2];
        $map[] = ['result_status', '=', 4];
        $map[] = ['last_sales_price', '>', 0];
        $expirePeriod = CrowdfundingPeriod::where($map)->select()->toArray();
        if (empty($expirePeriod)) {
            return false;
        }
        foreach ($expirePeriod as $key => $value) {
            Queue::push('app\lib\job\CrowdFunding', ['dealType' => 4, 'activity_code' => $value['activity_code'], 'round_number' => $value['round_number'], 'period_number' => $value['period_number']], config('system.queueAbbr') . 'CrowdFunding');
        }
        return true;
    }

    /**
     * @title  查找是否有未达到销售额的期,有则做相应处理(老方案, 直接熔断部分金额)
     * @param array $data
     * @return bool
     * @throws \Exception
     */
    public function checkExpireUndonePeriodDealOld(array $data)
    {
        set_time_limit(0);
        ini_set('memory_limit', '3072M');
        ini_set('max_execution_time', '0');

        if (!empty($data['activity_code'])) {
            $map[] = ['activity_code', '=', $data['activity_code']];
            $map[] = ['round_number', '=', $data['round_number']];
            $map[] = ['period_number', '=', $data['period_number']];
        }

        $map[] = ['status', 'in', [1, 2]];
        $map[] = ['end_time', '<=', time()];
        $map[] = ['limit_type', '=', 1];
        $map[] = ['buy_status', '=', 2];
        $map[] = ['result_status', '=', 4];
        $map[] = ['last_sales_price', '>', 0];
        $expirePeriod = CrowdfundingPeriod::where($map)->select()->toArray();

        if (empty($expirePeriod)) {
            return false;
        }
        $log['msg'] = '查询是否有超时未成功的众筹期';
        $log['expirePeriod'] = $expirePeriod ?? [];

        //加缓存锁防止后台修改
        $cacheKey = 'CrowdFundingPeriodFail';
        $expirePeriodCacheKey = 'expirePeriodDeal';
        foreach ($expirePeriod as $key => $value) {
            $cacheKey .= '-' . $value['activity_code'] . '-' . $value['round_number'] . '-' . $value['period_number'];
            $expirePeriodCacheKey .= '-' . $value['activity_code'] . '-' . $value['round_number'] . '-' . $value['period_number'];
            $expirePeriod[$key]['fullKey'] = $value['activity_code'] . '-' . $value['round_number'] . '-' . $value['period_number'];
            $expirePeriodInfo[$value['activity_code'] . '-' . $value['round_number'] . '-' . $value['period_number']] = $value;
            if (!empty(cache($expirePeriodCacheKey))) {
                unset($expirePeriod[$key]);
                continue;
            }
            cache($cacheKey, 'inFailDealProcessing', 600);
            cache($expirePeriodCacheKey, 'inFailDealProcessing', 120);
        }
        if (empty($expirePeriod)) {
            return false;
        }

        $rollbackPeriodNumber = CrowdfundingSystemConfig::where(['id' => 1])->value('rollback_period_number');
        $rollbackPeriod = [];
        $rollbackCount = 0;
        foreach ($expirePeriod as $key => $value) {
            for ($i = 1; $i <= $rollbackPeriodNumber; $i++) {
                if ($value['period_number'] - $i > 0) {
                    $rollbackPeriod[$rollbackCount]['activity_code'] = $value['activity_code'];
                    $rollbackPeriod[$rollbackCount]['round_number'] = $value['round_number'];
                    $rollbackPeriod[$rollbackCount]['period_number'] = $value['period_number'] - $i;
                    $rollbackPeriod[$rollbackCount]['fail_originally_round_number'] = $value['round_number'];
                    $rollbackPeriod[$rollbackCount]['fail_originally_period_number'] = $value['period_number'];
                    $rollbackCount++;
                }
            }
        }

        $log['rollbackPeriod'] = $rollbackPeriod ?? [];
        $log['rollbackPeriodNumber'] = $rollbackPeriodNumber ?? 0;


        $divideList = [];
        $periodInfo = [];
        if (!empty($rollbackPeriod)) {
            $rollbackPeriod = array_values($rollbackPeriod);

            $oWhere[] = ['arrival_status', '=', 2];
            $oWhere[] = ['status', '=', 1];
            $oWhere[] = ['type', 'in', [5, 8, 7]];
            $divideList = Divide::where(function ($query) use ($rollbackPeriod) {
                foreach ($rollbackPeriod as $key => $value) {
                    ${'where' . ($key + 1)}[] = ['crowd_code', '=', $value['activity_code']];
                    ${'where' . ($key + 1)}[] = ['crowd_round_number', '=', $value['round_number']];
                    ${'where' . ($key + 1)}[] = ['crowd_period_number', '=', $value['period_number']];
                }
                for ($i = 0; $i < count($rollbackPeriod); $i++) {
                    $allWhereOr[] = ${'where' . ($i + 1)};
                }
                $query->whereOr($allWhereOr);
            })->where($oWhere)->select()->toArray();

            $pWhere[] = ['status', '=', 1];
            $pWhere[] = ['buy_status', '=', 1];
            $pWhere[] = ['result_status', '=', 2];
            $periodList = CrowdfundingPeriod::where(function ($query) use ($rollbackPeriod) {
                foreach ($rollbackPeriod as $key => $value) {
                    ${'where' . ($key + 1)}[] = ['activity_code', '=', $value['activity_code']];
                    ${'where' . ($key + 1)}[] = ['round_number', '=', $value['round_number']];
                    ${'where' . ($key + 1)}[] = ['period_number', '=', $value['period_number']];
                }
                for ($i = 0; $i < count($rollbackPeriod); $i++) {
                    $allWhereOr[] = ${'where' . ($i + 1)};
                }
                $query->whereOr($allWhereOr);
            })->where($pWhere)->select()->toArray();
            foreach ($periodList as $key => $value) {
                $crowdKey = $value['activity_code'] . '-' . $value['round_number'] . '-' . $value['period_number'];
                $periodInfo[$crowdKey] = $value;
            }
        }


        $DBRes = Db::transaction(function () use ($rollbackPeriod, $expirePeriod, $divideList, $periodInfo, $log, $expirePeriodInfo) {
            $orderModel = (new Order());
            if (!empty($rollbackPeriod)) {
                if (!empty($divideList ?? [])) {
                    foreach ($divideList as $key => $value) {
                        $crowdKey = $value['crowd_code'] . '-' . $value['crowd_round_number'] . '-' . $value['crowd_period_number'];

                        //修改分润记录为取消
                        $divideRes[$key] = Divide::update(['arrival_status' => 3, 'status' => -1], ['id' => $value['id']]);
                        //只有订单本人才可以获得积分和返本记录
                        if ($value['order_uid'] == $value['link_uid']) {
                            //积分明细
                            $integral[$key]['order_sn'] = $value['order_sn'];
                            $integral[$key]['integral'] = priceFormat($value['total_price'] * $periodInfo[$crowdKey]['fail_reward_scale'] ?? 1);;
                            $integral[$key]['type'] = 1;
                            $integral[$key]['uid'] = $value['link_uid'];
                            $integral[$key]['change_type'] = 5;
                            $integral[$key]['remark'] = '福利活动奖励(' . $crowdKey . ')';
                            //修改每个人的积分余额
                            $userIntegralDetail[$key] = User::where(['uid' => $value['link_uid']])->inc('integral', $integral[$key]['integral'])->update();


                            //余额明细
                            $balance[$key]['order_sn'] = $value['order_sn'];
                            $balance[$key]['price'] = priceFormat($value['total_price'] * ($periodInfo[$crowdKey]['fuse_return_scale'] ?? 0.5));
                            $balance[$key]['type'] = 1;
                            $balance[$key]['uid'] = $value['link_uid'];
                            $balance[$key]['change_type'] = 3;
                            $balance[$key]['crowd_code'] = $value['crowd_code'];
                            $balance[$key]['crowd_round_number'] = $value['crowd_round_number'];
                            $balance[$key]['crowd_period_number'] = $value['crowd_period_number'];
                            $balance[$key]['remark'] = '福利活动熔断退本金(' . $crowdKey . ')';

                            //退款到用户账户
                            $refundRes[$key] = User::where(['uid' => $value['link_uid']])->inc('crowd_balance', $balance[$key]['price'])->update();

                            //修改对应的所有订单为可同步
//                            $orderModel->isAutoWriteTimestamp(false)->update(['can_sync' => 1], ['order_sn' => $value['order_sn']]);
//                    OrderGoods::update(['status' => -2, 'after_status' => 4, 'refund_price' => $balance[$key]['price'], 'update_time' => time()], ['order_sn' => $value['order_sn'], 'goods_sn' => $value['goods_sn'], 'sku_sn' => $value['sku_sn']]);
                        }
                    }
                }


                //添加积分明细
                if (!empty($integral ?? [])) {
                    (new IntegralDetail())->saveAll($integral);
                }

                //添加熔断期用户的余额明细
                if (!empty($balance ?? [])) {
                    (new CrowdfundingBalanceDetail())->saveAll($balance);
//                //修改对应的订单状态
//                $allOrderSn = array_unique(array_column(array_values($balance), 'order_sn'));
//                Order::update(['order_status' => -3, 'after_status' => 4, 'remark' => '众筹订单熔断退部分款', 'update_time' => time(), 'close_time' => time()], ['order_sn' => $allOrderSn, 'order_type' => 6]);
                }


                //修改回滚的期状态为熔断
                foreach ($rollbackPeriod as $key => $value) {
                    CrowdfundingPeriod::update(['result_status' => 5, 'fuse_time' => time(), 'fail_round_number' => $value['fail_originally_round_number'], 'fail_period_number' => $value['fail_originally_period_number']], ['activity_code' => $value['activity_code'], 'round_number' => $value['round_number'], 'period_number' => $value['period_number'], 'buy_status' => 1]);
                    //发放熔断赠送的提前购卡
                    (new AdvanceCardDetail())->sendAdvanceBuyCard(['send_type' => 3, 'activity_code' => $value['activity_code'], 'round_number' => $value['round_number'], 'period_number' => $value['period_number']]);
                }
            }

            //修改本期状态为失败
            foreach ($expirePeriod as $key => $value) {
                CrowdfundingPeriod::update(['result_status' => 3, 'fail_time' => time()], ['activity_code' => $value['activity_code'], 'round_number' => $value['round_number'], 'period_number' => $value['period_number'], 'buy_status' => 2]);
                //发放失败赠送的提前购卡
                (new AdvanceCardDetail())->sendAdvanceBuyCard(['send_type' => 2, 'activity_code' => $value['activity_code'], 'round_number' => $value['round_number'], 'period_number' => $value['period_number']]);

                //本期失败后重开新一轮第一期
                $this->regeneratePeriod(['activity_code' => $value['activity_code'], 'round_number' => $value['round_number'], 'period_number' => $value['period_number'], 'type' => 2]);
            }

            //针对失败本期的购买用户进行退款
            $gWhere[] = ['pay_status', '=', 2];
            $gWhere[] = ['status', '=', 1];
            $gWhere[] = ['after_status', 'in', [1, -1]];

            $orderGoods = OrderGoods::with(['orderInfo'])->where(function ($query) use ($expirePeriod) {
                foreach ($expirePeriod as $key => $value) {
                    ${'where' . ($key + 1)}[] = ['crowd_code', '=', $value['activity_code']];
                    ${'where' . ($key + 1)}[] = ['crowd_round_number', '=', $value['round_number']];
                    ${'where' . ($key + 1)}[] = ['crowd_period_number', '=', $value['period_number']];
                }
                for ($i = 0; $i < count($expirePeriod); $i++) {
                    $allWhereOr[] = ${'where' . ($i + 1)};
                }
                $query->whereOr($allWhereOr);
            })->where($gWhere)->select()->toArray();
            if (!empty($orderGoods)) {
                foreach ($orderGoods as $key => $value) {
                    $crowdKey = $value['crowd_code'] . '-' . $value['crowd_round_number'] . '-' . $value['crowd_period_number'];
                    //余额明细
                    $exBalance[$key]['order_sn'] = $value['order_sn'];
                    $exBalance[$key]['price'] = priceFormat($value['real_pay_price'] * $expirePeriodInfo[$crowdKey]['fail_return_scale'] ?? 1);
                    $exBalance[$key]['type'] = 1;
                    $exBalance[$key]['uid'] = $value['orderInfo']['uid'];
                    $exBalance[$key]['change_type'] = 3;
                    $exBalance[$key]['crowd_code'] = $value['crowd_code'];
                    $exBalance[$key]['crowd_round_number'] = $value['crowd_round_number'];
                    $exBalance[$key]['crowd_period_number'] = $value['crowd_period_number'];
                    $exBalance[$key]['remark'] = '福利活动退本金(' . $crowdKey . ')';

                    //退款到用户账户
                    $exRefundRes[$key] = User::where(['uid' => $value['orderInfo']['uid']])->inc('crowd_balance', $exBalance[$key]['price'])->update();
                    //修改商品状态
                    OrderGoods::update(['status' => -2, 'after_status' => 4, 'refund_price' => $exBalance[$key]['price'], 'update_time' => time()], ['order_sn' => $value['order_sn'], 'goods_sn' => $value['goods_sn'], 'sku_sn' => $value['sku_sn']]);
                    //修改订单状态为不允许同步
                    $orderModel->isAutoWriteTimestamp(false)->update(['can_sync' => 2], ['order_sn' => $value['order_sn']]);
                }
            }


            if (!empty($exBalance ?? [])) {
                //添加本期失败人的余额明细
                (new CrowdfundingBalanceDetail())->saveAll($exBalance);
                //修改对应的订单状态
                $allExOrderSn = array_unique(array_column(array_values($exBalance), 'order_sn'));
                Order::update(['order_status' => -3, 'after_status' => 4, 'coder_remark' => '福利订单失败退全款', 'update_time' => time(), 'close_time' => time()], ['order_sn' => $allExOrderSn, 'order_type' => 6]);
            }


            $res = ['rollbackBalanceDetail' => $balance ?? [], 'rollbackIntegralDetail' => $integral ?? [], 'expireBalanceDetail' => $exBalance ?? []];
            //记录日志
            $log['DBRes'] = $res;
            $this->log($log, 'info');
            return $res;
        });
        return true;
    }

    /**
     * @title  查找是否有未达到销售额的期,有则做相应处理
     * @param array $data
     * @return bool
     * @throws \Exception
     */
    public function checkExpireUndonePeriodDeal(array $data)
    {
        set_time_limit(0);
        ini_set('memory_limit', '3072M');
        ini_set('max_execution_time', '0');

        if (!empty($data['activity_code'])) {
            $map[] = ['activity_code', '=', $data['activity_code']];
            $map[] = ['round_number', '=', $data['round_number']];
            $map[] = ['period_number', '=', $data['period_number']];
        }

        $map[] = ['status', 'in', [1, 2]];
        $map[] = ['end_time', '<=', time()];
        $map[] = ['limit_type', '=', 1];
        $map[] = ['buy_status', '=', 2];
        $map[] = ['result_status', '=', 4];
        $map[] = ['last_sales_price', '>', 0];
        $expirePeriod = CrowdfundingPeriod::where($map)->select()->toArray();

        if (empty($expirePeriod)) {
            return false;
        }
        $log['msg'] = '查询是否有超时未成功的众筹期';
        $log['expirePeriod'] = $expirePeriod ?? [];

        //加缓存锁防止后台修改
        $cacheKey = 'CrowdFundingPeriodFail';
        $expirePeriodCacheKey = 'expirePeriodDeal';
        foreach ($expirePeriod as $key => $value) {
            $cacheKey .= '-' . $value['activity_code'] . '-' . $value['round_number'] . '-' . $value['period_number'];
            $expirePeriodCacheKey .= '-' . $value['activity_code'] . '-' . $value['round_number'] . '-' . $value['period_number'];
            $expirePeriod[$key]['fullKey'] = $value['activity_code'] . '-' . $value['round_number'] . '-' . $value['period_number'];
            $expirePeriodInfo[$value['activity_code'] . '-' . $value['round_number'] . '-' . $value['period_number']] = $value;
            if (!empty(cache($expirePeriodCacheKey))) {
                unset($expirePeriod[$key]);
                continue;
            }
            cache($cacheKey, 'inFailDealProcessing', 600);
            cache($expirePeriodCacheKey, 'inFailDealProcessing', 120);
        }
        if (empty($expirePeriod)) {
            return false;
        }

        $rollbackPeriodNumber = CrowdfundingSystemConfig::where(['id' => 1])->value('rollback_period_number');
        $rollbackPeriod = [];
        $rollbackCount = 0;
        foreach ($expirePeriod as $key => $value) {
            for ($i = 1; $i <= $rollbackPeriodNumber; $i++) {
                if ($value['period_number'] - $i > 0) {
                    $rollbackPeriod[$rollbackCount]['activity_code'] = $value['activity_code'];
                    $rollbackPeriod[$rollbackCount]['round_number'] = $value['round_number'];
                    $rollbackPeriod[$rollbackCount]['period_number'] = $value['period_number'] - $i;
                    $rollbackPeriod[$rollbackCount]['fail_originally_round_number'] = $value['round_number'];
                    $rollbackPeriod[$rollbackCount]['fail_originally_period_number'] = $value['period_number'];
                    $rollbackCount++;
                }
            }
        }

        $log['rollbackPeriod'] = $rollbackPeriod ?? [];
        $log['rollbackPeriodNumber'] = $rollbackPeriodNumber ?? 0;


        $divideList = [];
        $periodInfo = [];
        if (!empty($rollbackPeriod)) {
            $rollbackPeriod = array_values($rollbackPeriod);

            $oWhere[] = ['arrival_status', '=', 2];
            $oWhere[] = ['status', '=', 1];
            $oWhere[] = ['type', 'in', [5, 8, 7, 9]];
            $divideList = Divide::where(function ($query) use ($rollbackPeriod) {
                foreach ($rollbackPeriod as $key => $value) {
                    ${'where' . ($key + 1)}[] = ['crowd_code', '=', $value['activity_code']];
                    ${'where' . ($key + 1)}[] = ['crowd_round_number', '=', $value['round_number']];
                    ${'where' . ($key + 1)}[] = ['crowd_period_number', '=', $value['period_number']];
                }
                for ($i = 0; $i < count($rollbackPeriod); $i++) {
                    $allWhereOr[] = ${'where' . ($i + 1)};
                }
                $query->whereOr($allWhereOr);
            })->where($oWhere)->select()->toArray();

            $pWhere[] = ['status', '=', 1];
            $pWhere[] = ['buy_status', '=', 1];
            $pWhere[] = ['result_status', '=', 2];
            $periodList = CrowdfundingPeriod::where(function ($query) use ($rollbackPeriod) {
                foreach ($rollbackPeriod as $key => $value) {
                    ${'where' . ($key + 1)}[] = ['activity_code', '=', $value['activity_code']];
                    ${'where' . ($key + 1)}[] = ['round_number', '=', $value['round_number']];
                    ${'where' . ($key + 1)}[] = ['period_number', '=', $value['period_number']];
                }
                for ($i = 0; $i < count($rollbackPeriod); $i++) {
                    $allWhereOr[] = ${'where' . ($i + 1)};
                }
                $query->whereOr($allWhereOr);
            })->where($pWhere)->select()->toArray();
            foreach ($periodList as $key => $value) {
                $crowdKey = $value['activity_code'] . '-' . $value['round_number'] . '-' . $value['period_number'];
                $periodInfo[$crowdKey] = $value;
            }
        }


        $DBRes = Db::transaction(function () use ($rollbackPeriod, $expirePeriod, $divideList, $periodInfo, $log, $expirePeriodInfo) {
            $orderModel = (new Order());
            if (!empty($rollbackPeriod)) {
                //修改分润记录为取消
                if (!empty($divideList ?? [])) {
                    Divide::update(['arrival_status' => 3, 'status' => -1], ['id' => array_unique(array_column($divideList, 'id')), 'arrival_status' => 2, 'status' => 1]);
                }

                //针对熔断期的购买用户的订单修改为熔断订单
                $gWhere[] = ['pay_status', '=', 2];
                $gWhere[] = ['status', '=', 1];
                $gWhere[] = ['after_status', 'in', [1, -1]];

                $rollbackOrderGoods = OrderGoods::where(function ($query) use ($rollbackPeriod) {
                    foreach ($rollbackPeriod as $key => $value) {
                        ${'where' . ($key + 1)}[] = ['crowd_code', '=', $value['activity_code']];
                        ${'where' . ($key + 1)}[] = ['crowd_round_number', '=', $value['round_number']];
                        ${'where' . ($key + 1)}[] = ['crowd_period_number', '=', $value['period_number']];
                    }
                    for ($i = 0; $i < count($rollbackPeriod); $i++) {
                        $allWhereOr[] = ${'where' . ($i + 1)};
                    }
                    $query->whereOr($allWhereOr);
                })->where($gWhere)->column('order_sn');

                if (!empty($rollbackOrderGoods ?? [])) {
                    Order::update(['crowd_fuse_status' => 1], ['order_sn' => array_unique($rollbackOrderGoods), 'order_type' => 6]);

                    //修改超前提前购的发放状态为发放失败
                    CrowdfundingDelayRewardOrder::update(['arrival_status' => 2, 'status' => -1, 'remark' => '福利订单熔断取消冻结发放',], ['order_sn' => array_unique($rollbackOrderGoods), 'arrival_status' => 3, 'status' => 1]);
                }

                //修改回滚的期状态为熔断
                foreach ($rollbackPeriod as $key => $value) {
                    CrowdfundingPeriod::update(['result_status' => 5, 'fuse_time' => time(), 'fail_round_number' => $value['fail_originally_round_number'], 'fail_period_number' => $value['fail_originally_period_number']], ['activity_code' => $value['activity_code'], 'round_number' => $value['round_number'], 'period_number' => $value['period_number'], 'buy_status' => 1]);
                }

                //查看是否有关于本熔断期产生的冻结中的待释放的熔断分期美丽金, 如果有则需要根据订单返回到原来的记录里面去
                $fWhere[] = ['status', '=', 1];
                $fWhere[] = ['arrival_status', '=', 2];
                $existFrozenFuseRecord = CrowdfundingFuseRecordDetail::where(function ($query) use ($rollbackPeriod) {
                    foreach ($rollbackPeriod as $key => $value) {
                        ${'where' . ($key + 1)}[] = ['from_crowd_code', '=', $value['activity_code']];
                        ${'where' . ($key + 1)}[] = ['from_crowd_round_number', '=', $value['round_number']];
                        ${'where' . ($key + 1)}[] = ['from_crowd_period_number', '=', $value['period_number']];
                    }
                    for ($i = 0; $i < count($rollbackPeriod); $i++) {
                        $allWhereOr[] = ${'where' . ($i + 1)};
                    }
                    $query->whereOr($allWhereOr);
                })->where($fWhere)->select()->toArray();
                if (!empty($existFrozenFuseRecord)) {
                    foreach ($existFrozenFuseRecord as $key => $value) {
                        if (doubleval($value['price']) > 0) {
                            CrowdfundingFuseRecord::where(['order_sn' => $value['order_sn'], 'uid' => $value['uid'], 'crowd_code' => $value['crowd_code'], 'crowd_round_number' => $value['crowd_round_number'], 'crowd_period_number' => $value['crowd_period_number'], 'status' => 1])->inc('last_total_price', $value['price'])->update();
                            //如果记录因为分完了显示记录完成, 这次加回去之后需要把记录重新修改为发放中以便后续可以继续发放
                            CrowdfundingFuseRecord::where(['order_sn' => $value['order_sn'], 'uid' => $value['uid'], 'crowd_code' => $value['crowd_code'], 'crowd_round_number' => $value['crowd_round_number'], 'crowd_period_number' => $value['crowd_period_number'], 'status' => 1, 'grant_status' => 1])->save(['grant_status' => 2]);
                        }
                    }
                    CrowdfundingFuseRecordDetail::update(['status' => -1], ['id' => array_column($existFrozenFuseRecord, 'id'), 'arrival_status' => 2, 'status' => 1]);
                }
            }

            //修改本期状态为失败
            foreach ($expirePeriod as $key => $value) {
                CrowdfundingPeriod::update(['result_status' => 3, 'fail_time' => time()], ['activity_code' => $value['activity_code'], 'round_number' => $value['round_number'], 'period_number' => $value['period_number'], 'buy_status' => 2]);
                //本期失败后重开新一轮第一期
                $this->regeneratePeriod(['activity_code' => $value['activity_code'], 'round_number' => $value['round_number'], 'period_number' => $value['period_number'], 'type' => 2]);
            }

            //针对失败本期的购买用户进行退款
            $gWhere[] = ['pay_status', '=', 2];
            $gWhere[] = ['status', '=', 1];
            $gWhere[] = ['after_status', 'in', [1, -1]];

            $orderGoods = OrderGoods::with(['orderInfo'])->where(function ($query) use ($expirePeriod) {
                foreach ($expirePeriod as $key => $value) {
                    ${'where' . ($key + 1)}[] = ['crowd_code', '=', $value['activity_code']];
                    ${'where' . ($key + 1)}[] = ['crowd_round_number', '=', $value['round_number']];
                    ${'where' . ($key + 1)}[] = ['crowd_period_number', '=', $value['period_number']];
                }
                for ($i = 0; $i < count($expirePeriod); $i++) {
                    $allWhereOr[] = ${'where' . ($i + 1)};
                }
                $query->whereOr($allWhereOr);
            })->where($gWhere)->select()->toArray();
            if (!empty($orderGoods)) {
                foreach ($orderGoods as $key => $value) {
                    $crowdKey = $value['crowd_code'] . '-' . $value['crowd_round_number'] . '-' . $value['crowd_period_number'];
                    //余额明细
                    $exBalance[$key]['order_sn'] = $value['order_sn'];
                    $exBalance[$key]['price'] = priceFormat($value['real_pay_price'] * $expirePeriodInfo[$crowdKey]['fail_return_scale'] ?? 1);
                    $exBalance[$key]['type'] = 1;
                    $exBalance[$key]['uid'] = $value['orderInfo']['uid'];
                    $exBalance[$key]['change_type'] = 3;
                    $exBalance[$key]['crowd_code'] = $value['crowd_code'];
                    $exBalance[$key]['crowd_round_number'] = $value['crowd_round_number'];
                    $exBalance[$key]['crowd_period_number'] = $value['crowd_period_number'];
                    $exBalance[$key]['remark'] = '福利活动退本金(' . $crowdKey . ')';

                    //退款到用户账户
                    $exRefundRes[$key] = User::where(['uid' => $value['orderInfo']['uid']])->inc('crowd_balance', $exBalance[$key]['price'])->update();
                    //修改商品状态
                    OrderGoods::update(['status' => -2, 'after_status' => 4, 'refund_price' => $exBalance[$key]['price'], 'update_time' => time()], ['order_sn' => $value['order_sn'], 'goods_sn' => $value['goods_sn'], 'sku_sn' => $value['sku_sn']]);
                    //修改订单状态为不允许同步
                    $orderModel->isAutoWriteTimestamp(false)->update(['can_sync' => 2], ['order_sn' => $value['order_sn']]);
                }
            }


            if (!empty($exBalance ?? [])) {
                //添加本期失败人的余额明细
                (new CrowdfundingBalanceDetail())->saveAll($exBalance);
                //修改对应的订单状态
                $allExOrderSn = array_unique(array_column(array_values($exBalance), 'order_sn'));
                Order::update(['order_status' => -3, 'after_status' => 4, 'coder_remark' => '福利订单失败退全款', 'update_time' => time(), 'close_time' => time()], ['order_sn' => $allExOrderSn, 'order_type' => 6]);

                //修改超前提前购的发放状态为发放失败
                CrowdfundingDelayRewardOrder::update(['arrival_status' => 2, 'status' => -1, 'remark' => '福利订单失败取消冻结发放',], ['order_sn' => $allExOrderSn, 'arrival_status' => 3, 'status' => 1]);
            }


            $res = ['rollbackBalanceDetail' => json_encode($balance ?? [], 256), 'rollbackIntegralDetail' => json_encode($integral ?? [], 256), 'expireBalanceDetail' => json_encode($exBalance ?? [], 256)];
            //记录日志
            $log['DBRes'] = $res;
            $this->log($log, 'info');
            return $res;
        });
        return true;
    }



    /**
     * @title  判断是否有过了冻结期的众筹奖金发放(新方法)
     * @param array $data
     * @return bool
     * @throws \Exception
     */
    public function checkSuccessPeriod(array $data)
    {
        set_time_limit(0);
        ini_set('memory_limit', '3072M');
        ini_set('max_execution_time', '0');
        $successExpire = CrowdfundingSystemConfig::where(['id' => 1])->value('period_success_time');
        $successPeriodNumber = CrowdfundingSystemConfig::where(['id' => 1])->value('success_period_number');
        $log['msg'] = '查找是否有可以释放成功的众筹活动';
        $log['requestData'] = $data;

        //searType 1为查询所有待成功的期, 然后一个个N轮后判断是否成功 2为查询指定期是否成功
        $searType = $data['searType'] ?? 1;
        //查询包含众筹标识的冻结中的分润记录, 有团队业绩和众筹类型和区代
        if ($searType == 2) {
            $startPeriodNumber = intval($data['period_number']) - $successPeriodNumber;
            if ($startPeriodNumber <= 0) {
                return $this->recordError($log, ['msg' => '根据指定的成功轮数,需要经过' . $successPeriodNumber . '轮才可以成功,目前最大期数还不足,故此期不能判定为成功']);
            }
            $dWhere[] = ['crowd_code', '=', $data['activity_code']];
            $dWhere[] = ['crowd_round_number', '=', $data['round_number']];
            $dWhere[] = ['crowd_period_number', '=', $startPeriodNumber];
//            $dWhere[] = ['crowd_period_number', '<>', $data['period_number']];
        }else{
            $dWhere[] = ['', 'exp', Db::raw('crowd_code is not null and crowd_round_number is not null and crowd_period_number is not null')];
        }
        $dWhere[] = ['type', 'in', [8, 5, 7]];
        $dWhere[] = ['arrival_status', '=', 2];
        $dWhere[] = ['status', '=', 1];
//        $dWhere[] = ['create_time', '<=', (time() - $successExpire)];
        $divideList = Divide::field('id,order_sn,order_uid,link_uid,type,goods_sn,sku_sn,price,count,total_price,divide_type,purchase_price,divide_price,dis_reduce_price,refund_reduce_price,real_divide_price,arrival_status,status,create_time,is_grateful,crowd_code,crowd_round_number,crowd_period_number,is_exp,is_device,device_sn,is_allot,allot_scale,device_divide_type,team_shareholder_level,level')->where($dWhere)->select()->toArray();


        $log['divideList'] = $divideList;
        if (empty($divideList)) {
            return $this->recordError($log, ['msg' => '无有效的冻结收益']);
        }

        $pWhere[] = ['buy_status', '=', 1];
        $pWhere[] = ['result_status', 'in', [2]];
        if ($searType == 2) {
            $startPeriodNumber = intval($data['period_number']) - $successPeriodNumber;
            if ($startPeriodNumber <= 0) {
                return $this->recordError($log, ['msg' => '根据指定的成功轮数,需要经过' . $successPeriodNumber . '轮才可以成功,目前最大期数还不足,故此期不能判定为成功']);
            }
            $pWhere[] = ['activity_code', '=', $data['activity_code']];
            $pWhere[] = ['round_number', '=', $data['round_number']];
            $pWhere[] = ['period_number', '>=', $startPeriodNumber];
        }
        //查询所有待成功的期, 来匹配是否后N轮是否有成功然后判断是否真的成功
        $allPeriodList = CrowdfundingPeriod::where($pWhere)->select()->toArray();

        foreach ($allPeriodList as $key => $value) {
            $crowdKey = $value['activity_code'] . '-' . $value['round_number'] . '-' . $value['period_number'];
            $allPeriodInfo[$crowdKey] = $value;
        }
        $log['allPeriodList'] = $allPeriodList;

        if ($searType == 1) {
            foreach ($divideList as $key => $value) {
                $crowdKey = $value['crowd_code'] . '-' . $value['crowd_round_number'] . '-' . $value['crowd_period_number'];
                $successPeriod[$crowdKey]['activity_code'] = $value['crowd_code'];
                $successPeriod[$crowdKey]['round_number'] = $value['crowd_round_number'];
                $successPeriod[$crowdKey]['period_number'] = $value['crowd_period_number'];
            }
        } elseif ($searType == 2) {
            foreach ($allPeriodList as $key => $value) {
                $crowdKey = $value['activity_code'] . '-' . $value['round_number'] . '-' . $value['period_number'];
                $successPeriod[$crowdKey]['activity_code'] = $value['activity_code'];
                $successPeriod[$crowdKey]['round_number'] = $value['round_number'];
                $successPeriod[$crowdKey]['period_number'] = $value['period_number'];
            }
        }

        $log['successPeriod'] = $successPeriod ?? [];
        $successPeriodCount = [];
        foreach ($successPeriod as $key => $value) {
            if (!isset($successPeriodCount[$key])) {
                $successPeriodCount[$key] = 0;
            }
            for ($i = 1; $i <= $successPeriodNumber; $i++) {
                if (!empty($allPeriodInfo[$value['activity_code'] . '-' . $value['round_number'] . '-' . (intval($value['period_number']) + $i)] ?? [])) {
                    $successPeriodCount[$key] += 1;
                }
            }
        }
        $log['successPeriodCount'] = $successPeriodCount ?? [];


        if (empty($successPeriodCount)) {
            return $this->recordError($log, ['msg' => '剔除后-查无有效的期(N+3)冻结收益']);
        }

        $notSuccessPeriod = [];
        //如果成功的本期前N次没有都成功, 则不允许释放本期的奖励
        foreach ($successPeriodCount as $key => $value) {
            $crowdKey = null;
            if ($value < $successPeriodNumber) {
                unset($successPeriod[$key]);
                $crowdKey = explode($key, '-');
                foreach ($divideList as $dKey => $dValue) {
                    if ($value['crowd_code'] == $crowdKey[0] && $value['crowd_round_number'] == $crowdKey[1] && $value['crowd_period_number'] == $crowdKey[2]) {
                        unset($divideList[$dKey]);
                        $notSuccessPeriod[$key]['crowd_code'] = $crowdKey[0];
                        $notSuccessPeriod[$key]['crowd_round_number'] = $crowdKey[1];
                        $notSuccessPeriod[$key]['crowd_period_number'] = $crowdKey[2];
                    }
                }
            }
        }

        //判断是否超前提前购的订单情况
        $checkDelayOrder = false;
        //存在延迟发放奖励的期
        $delayPeriodInfo = [];
        //延迟发放的分润订单列表
        $delayDivideList = [];
        //被舍弃的分润id
        $unsetDivideId = [];
        if(!empty($divideList ?? [])){
            //判断分润订单是否为超级提前购(预售), 奖励需要指定时间发放的
            $drWhere[] = ['arrival_time', '>', time()];
            $drWhere[] = ['arrival_status', '=', 3];
            $drWhere[] = ['status', '=', 1];
            $delayOrderList = CrowdfundingDelayRewardOrder::where(function ($query) use ($successPeriod) {
                $successPeriod = array_values($successPeriod);
                foreach ($successPeriod as $key => $value) {
                    ${'where' . ($key + 1)}[] = ['crowd_code', '=', $value['activity_code']];
                    ${'where' . ($key + 1)}[] = ['crowd_round_number', '=', $value['round_number']];
                    ${'where' . ($key + 1)}[] = ['crowd_period_number', '=', $value['period_number']];
                }
                for ($i = 0; $i < count($successPeriod); $i++) {
                    $allWhereOr[] = ${'where' . ($i + 1)};
                }
                $query->whereOr($allWhereOr);
            })->where($drWhere)->column('arrival_time','order_sn');

            //如果有存在当前时间不允许发放奖励的订单, 则此次不处理发放, 后续通过延时队列或定时任务触发发放奖励
            if (!empty($delayOrderList ?? [])) {
                foreach ($divideList as $key => $value) {
                    if (in_array($value['order_sn'], array_keys($delayOrderList))) {
                        unset($divideList[$key]);
                        $delayOrderCrowdKey = $value['crowd_code'] . '-' . $value['crowd_round_number'] . '-' . $value['crowd_period_number'];
                        //往延时执行的队列中添加待发放订单
                        $redisDelayOrder[] = ($delayOrderList[$value['order_sn']] ?? (time() + (3600 * 24 * 7))) . '-' . $value['order_sn'];
                        $delayPeriodInfo[$delayOrderCrowdKey] = $delayOrderCrowdKey;
                        $checkDelayOrder = true;
                        $delayDivideList[$key] = $value;
                        $unsetDivideId[] = $value['id'];
                    }
                }
                if (!empty($redisDelayOrder ?? [])) {
                    $redisDelayOrder = array_unique($redisDelayOrder);
                    //利用语法糖批量追加redis list数据
                    //在用户自定义函数中支持可变数量的参数列表
                    Cache::store('redis')->lpush($this->delayRewardOrderCacheKey, ...$redisDelayOrder);
                }
            }
        }

        $log['successPeriodNew'] = $successPeriod ?? [];
        $log['successDivideNew'] = $divideList ?? [];
        if (empty($successPeriod)) {
            return $this->recordError($log, ['msg' => '剔除后-无有效的期冻结收益']);
        }
        if (empty($divideList) && empty($checkDelayOrder ?? false)) {
            return $this->recordError($log, ['msg' => '剔除后-无有效的冻结收益']);
        }

        $oWhere[] = ['buy_status', '=', 1];
        $oWhere[] = ['status', '=', 1];
        $oWhere[] = ['result_status', 'in', [2]];
        $periodList = CrowdfundingPeriod::where(function ($query) use ($successPeriod) {
            $successPeriod = array_values($successPeriod);
            foreach ($successPeriod as $key => $value) {
                ${'where' . ($key + 1)}[] = ['activity_code', '=', $value['activity_code']];
                ${'where' . ($key + 1)}[] = ['round_number', '=', $value['round_number']];
                ${'where' . ($key + 1)}[] = ['period_number', '=', $value['period_number']];
            }
            for ($i = 0; $i < count($successPeriod); $i++) {
                $allWhereOr[] = ${'where' . ($i + 1)};
            }
            $query->whereOr($allWhereOr);
        })->where($oWhere)->select()->toArray();

        $log['periodList'] = $periodList;
        if (empty($periodList)) {
            return $this->recordError($log, ['msg' => '冻结的收益无有效的众筹期']);
        }
        foreach ($periodList as $key => $value) {
            $crowdKey = $value['activity_code'] . '-' . $value['round_number'] . '-' . $value['period_number'];
            $periodInfo[$crowdKey] = $value;
        }

        $DBRes = Db::transaction(function () use ($periodList, $divideList, $periodInfo, $log, $notSuccessPeriod, $dWhere, $delayPeriodInfo, $checkDelayOrder, $delayDivideList, $unsetDivideId) {
            if(!empty($divideList ?? [])){
                $allUid = array_unique(array_column($divideList,'link_uid'));
                //加锁防止其他操作打断
//            $userId = (new User())->where(['uid' => $allUid])->value('id');
//            (new User())->where(['id' => $userId])->lock(true)->value('uid');
                foreach ($allUid as $key => $value) {
                    cache('canNotOperBalance-' . $value, 1, 300);
                }

                $allUidSql = "('".implode("','",$allUid)."')";
                $updateUserCrowdBalanceSql = 'update sp_user set crowd_balance = CASE uid ';
//            $updateUserIntegralSql = 'update sp_user set integral = CASE uid ';
                $updateUserTeamBalanceSql = 'update sp_user set team_balance = CASE uid ';
                $updateUserAreaBalanceSql = 'update sp_user set area_balance = CASE uid ';
                $updateUserCrowdBalanceHeaderSql = 'update sp_user set crowd_balance = CASE uid ';
                $updateUserTeamBalanceHeaderSql = 'update sp_user set team_balance = CASE uid ';
                $updateUserAreaBalanceHeaderSql = 'update sp_user set area_balance = CASE uid ';

                $haveCrowdBalanceSql = false;
//            $haveIntegralSql = false;
                $haveTeamBalanceSql = false;
                $haveAreaBalanceSql = false;
                $crowdBalanceNumber  = [];
//            $integralNumber  = [];
                $teamBalanceNumber  = [];
                $areaBalanceNumber  = [];
                $updateUserCrowdBalanceSqlMore = [];
                $updateUserIntegralSqlMore = [];
                $updateUserTeamBalanceSqlMore = [];
                $updateUserAreaBalanceSqlMore = [];
                $allSuccessOrderSn = [];

//            $userCrowdBalance = [];
//            $userIntegral = [];
                foreach ($divideList as $key => $value) {
                    if(!isset($userCrowdBalance[$value['link_uid']])){
                        $userCrowdBalance[$value['link_uid']] = 0;
                    }
//                if(!isset($userIntegral[$value['link_uid']])){
//                    $userIntegral[$value['link_uid']] = 0;
//                }
                    if(!isset($userTeamBalance[$value['link_uid']])){
                        $userTeamBalance[$value['link_uid']] = 0;
                    }
                    if(!isset($userAreaBalance[$value['link_uid']])){
                        $userAreaBalance[$value['link_uid']] = 0;
                    }
                    $crowdKey = $value['crowd_code'] . '-' . $value['crowd_round_number'] . '-' . $value['crowd_period_number'];
                    //订单本人才可以反本金, 其他人得对应的奖金
                    if ($value['order_uid'] == $value['link_uid'] && $value['type'] == 8 && $value['is_grateful'] == 2) {
                        //余额明细,本金
                        $aBalance[$key]['order_sn'] = $value['order_sn'];
                        //100%返本金
                        $aBalance[$key]['price'] = priceFormat($value['total_price'] * 1);
                        $aBalance[$key]['type'] = 1;
                        $aBalance[$key]['uid'] = $value['link_uid'];
                        $aBalance[$key]['change_type'] = 3;
                        $aBalance[$key]['crowd_code'] = $value['crowd_code'];
                        $aBalance[$key]['crowd_round_number'] = $value['crowd_round_number'];
                        $aBalance[$key]['crowd_period_number'] = $value['crowd_period_number'];
                        $aBalance[$key]['remark'] = '福利活动成功返本金(' . $crowdKey . ')';
                        if (doubleval($aBalance[$key]['price']) > 0) {
                            //退款到用户账户
//                        $refundRes[$key] = (new User())->where(['uid' => $value['link_uid']])->inc('crowd_balance', $aBalance[$key]['price'])->update();
                            $userCrowdBalance[$value['link_uid']] += $aBalance[$key]['price'];
                            $haveCrowdBalanceSql = true;
                            $crowdBalanceNumber[$value['link_uid']] = 1;
                        }
                        $allSuccessOrderSn[$value['order_sn']] = $value['order_sn'];
//
//                    //赠送同等数量的积分
//                    $aIntegral[$key]['order_sn'] = $value['order_sn'];
//                    //100%返本金
//                    $aIntegral[$key]['price'] = priceFormat($value['total_price'] * 1);
//                    $aIntegral[$key]['type'] = 1;
//                    $aIntegral[$key]['uid'] = $value['link_uid'];
//                    $aIntegral[$key]['change_type'] = 6;
//                    $aIntegral[$key]['crowd_code'] = $value['crowd_code'];
//                    $aIntegral[$key]['crowd_round_number'] = $value['crowd_round_number'];
//                    $aIntegral[$key]['crowd_period_number'] = $value['crowd_period_number'];
//                    $aIntegral[$key]['remark'] = '福利活动成功获得美丽豆(' . $crowdKey . ')';
//                    $aIntegral[$key]['goods_sn'] = $value['goods_sn'] ?? '';
//                    $aIntegral[$key]['sku_sn'] = $value['sku_sn'] ?? '';
//                    if (doubleval($aIntegral[$key]['price']) > 0) {
////                        $refundRes[$key] = (new User())->where(['uid' => $value['link_uid']])->inc('integral', $aIntegral[$key]['price'])->update();
//                        $userIntegral[$value['link_uid']] += $aIntegral[$key]['price'];
//                        $haveIntegralSql = true;
//                        $integralNumber[$value['link_uid']] = 1;
//                        $allSuccessOrderSn[$value['order_sn']] = $value['order_sn'];
//                    }

                        //修改对应的所有订单为可同步
//                    (new Order())->isAutoWriteTimestamp(false)->update(['can_sync' => 1], ['order_sn' => $value['order_sn']]);
                        $orderSync[] = $value['order_sn'];
                    }

                    //余额明细, 奖金
                    if ($value['type'] == 5) {
                        //如果是团队业绩记录在原来的钱包里面
                        $uBalance[$key]['order_sn'] = $value['order_sn'];
                        $uBalance[$key]['belong'] = 1;
                        $uBalance[$key]['price'] = $value['real_divide_price'];
                        $uBalance[$key]['type'] = 1;
                        $uBalance[$key]['uid'] = $value['link_uid'];
                        $uBalance[$key]['change_type'] = 16;
                        $uBalance[$key]['remark'] = '福利活动成功团队奖奖金(' . $crowdKey . ')';
                        $uBalance[$key]['crowd_code'] = $value['crowd_code'];
                        $uBalance[$key]['crowd_round_number'] = $value['crowd_round_number'];
                        $uBalance[$key]['crowd_period_number'] = $value['crowd_period_number'];
                        if (!empty($value['is_exp'] ?? null) && $value['is_exp'] == 1) {
//                        if($value['level'] == 3){
                            if (!empty($value['team_shareholder_level'] ?? null) && intval($value['team_shareholder_level'] > 0)) {
                                $uBalance[$key]['remark'] = '福利活动成功团队股东奖奖金(' . $crowdKey . ')';
                            }else{
                                $uBalance[$key]['remark'] = '福利活动成功体验中心奖奖金(' . $crowdKey . ')';
                            }
                        }

                        if (doubleval($uBalance[$key]['price']) > 0) {
                            //发奖金到用户团队业绩账户
//                        $refundRes[$key] = (new User())->where(['uid' => $value['link_uid']])->inc('team_balance', $uBalance[$key]['price'])->update();
                            $haveTeamBalanceSql = true;
                            $userTeamBalance[$value['link_uid']] += $uBalance[$key]['price'];
                            $teamBalanceNumber[$value['link_uid']] = 1;

                        }
                    } elseif ($value['type'] == 7) {
                        //如果是区代奖励记录在原来的钱包里面
                        $uBalance[$key]['order_sn'] = $value['order_sn'];
                        $uBalance[$key]['belong'] = 1;
                        $uBalance[$key]['price'] = $value['real_divide_price'];
                        $uBalance[$key]['type'] = 1;
                        $uBalance[$key]['uid'] = $value['link_uid'];
                        $uBalance[$key]['change_type'] = 20;
                        $uBalance[$key]['remark'] = '福利活动成功区代奖奖金(' . $crowdKey . ')';
                        $uBalance[$key]['crowd_code'] = $value['crowd_code'];
                        $uBalance[$key]['crowd_round_number'] = $value['crowd_round_number'];
                        $uBalance[$key]['crowd_period_number'] = $value['crowd_period_number'];
                        if (doubleval($uBalance[$key]['price']) > 0) {
                            //发奖金到用户团队业绩账户
//                        $refundRes[$key] = User::where(['uid' => $value['link_uid']])->inc('area_balance', $uBalance[$key]['price'])->update();
                            $haveAreaBalanceSql = true;
                            $userAreaBalance[$value['link_uid']] += $uBalance[$key]['price'];
                            $areaBalanceNumber[$value['link_uid']] = 1;

                        }
                    } elseif ($value['type'] == 8) {
                        //如果是众筹记录在众筹的钱包里面
                        $balance[$key]['order_sn'] = $value['order_sn'];
                        $balance[$key]['price'] = $value['real_divide_price'];
                        $balance[$key]['type'] = 1;
                        $balance[$key]['uid'] = $value['link_uid'];
                        $balance[$key]['change_type'] = 4;
                        $balance[$key]['crowd_code'] = $value['crowd_code'];
                        $balance[$key]['crowd_round_number'] = $value['crowd_round_number'];
                        $balance[$key]['crowd_period_number'] = $value['crowd_period_number'];
                        $balance[$key]['remark'] = '福利活动成功奖金(' . $crowdKey . ')';
                        if ($value['is_grateful'] == 1) {
                            $balance[$key]['remark'] = '福利活动成功感恩奖奖金(' . $crowdKey . ')';
                            $balance[$key]['is_grateful'] = 1;
                        }
                        if (doubleval($balance[$key]['price']) > 0) {
                            //发奖金到用户账户
//                        $refundRes[$key] = (new User())->where(['uid' => $value['link_uid']])->inc('crowd_balance', $balance[$key]['price'])->update();
                            $userCrowdBalance[$value['link_uid']] += $balance[$key]['price'];
                            $haveCrowdBalanceSql = true;
                        }
                    }

                    //修改分润状态为到账
//                $divideRes[$key] = Divide::update(['arrival_status' => 1, 'arrival_time' => time()], ['order_sn' => $value['order_sn'], 'type' => $value['type'], 'link_uid' => $value['link_uid'], 'is_grateful' => $value['is_grateful'], 'arrival_status' => 2, 'status' => 1, 'crowd_code' => $value['crowd_code'], 'crowd_round_number' => $value['crowd_round_number'], 'crowd_period_number' => $value['crowd_period_number']]);
                }
                //计算，切割众筹部分的金额sql
                if (!empty($userCrowdBalance)) {
                    foreach ($userCrowdBalance as $key => $value) {
                        if(doubleval($value) <= 0){
                            unset($userCrowdBalance[$key]);
                        }
                    }
                    $crowdNumber = 0;
                    foreach ($userCrowdBalance as $key => $value) {
                        if ($crowdNumber >= 500) {
                            if($crowdNumber % 500 == 0){
                                $updateUserCrowdBalanceHeaderSql = 'update sp_user set crowd_balance = CASE uid ';
                            }
                            $updateUserCrowdBalanceSqlMore[intval($crowdNumber / 500)] = $updateUserCrowdBalanceHeaderSql .= " WHEN '" . ($key ?? 'notfound') . "' THEN (crowd_balance + " . ($value ?? 0) . ")";
                            $updateUserCrowdBalanceSqlMoreUid[intval($crowdNumber / 500)][] = ($key ?? 'notfound');
                            unset($userCrowdBalance[$key]);
                        } else{
                            $updateUserCrowdBalanceSql .= " WHEN '" . ($key ?? 'notfound') . "' THEN (crowd_balance + " . ($value ?? 0) . ")";
                            unset($userCrowdBalance[$key]);
                        }
                        $crowdNumber += 1;
                    }

                    $updateUserCrowdBalanceSql .= ' ELSE (crowd_balance + 0) END WHERE uid in ' . $allUidSql;

                    if(!empty($updateUserCrowdBalanceSqlMore ?? [])){
                        foreach ($updateUserCrowdBalanceSqlMore as $key => $value) {
                            $updateUserCrowdBalanceSqlMore[$key] .= ' ELSE (crowd_balance + 0) END WHERE uid in ' . "('".implode("','",$updateUserCrowdBalanceSqlMoreUid[$key])."')";;
                        }
                    }
                }

//            //计算，切割众筹部分的积分sql
//            if (!empty($userIntegral)) {
//                foreach ($userIntegral as $key => $value) {
//                    if(doubleval($value) <= 0){
//                        unset($userIntegral[$key]);
//                    }
//                }
//                $integNumber = 0;
//                foreach ($userIntegral as $key => $value) {
//                    if ($integNumber >= 500) {
//                        if($integNumber % 500 == 0){
//                            $updateUserIntegralHeaderSql = 'update sp_user set integral = CASE uid ';
//                        }
//                        $updateUserIntegralSqlMore[intval($integNumber / 500)] = $updateUserIntegralHeaderSql .= " WHEN '" . ($key ?? 'notfound') . "' THEN (integral + " . ($value ?? 0) . ")";
//                        $updateUserIntegralSqlMoreUid[intval($integNumber / 500)][] = ($key ?? 'notfound');
//                        unset($userIntegral[$key]);
//                    } else{
//                        $updateUserIntegralSql .= " WHEN '" . ($key ?? 'notfound') . "' THEN (integral + " . ($value ?? 0) . ")";
//                        unset($userIntegral[$key]);
//                    }
//                    $integNumber += 1;
//                }
//
//                $updateUserIntegralSql .= ' ELSE (integral + 0) END WHERE uid in ' . $allUidSql;
//
//                if(!empty($updateUserIntegralSqlMore ?? [])){
//                    foreach ($updateUserIntegralSqlMore as $key => $value) {
//                        $updateUserIntegralSqlMore[$key] .= ' ELSE (integral + 0) END WHERE uid in ' . "('".implode("','",$updateUserIntegralSqlMoreUid[$key])."')";;
//                    }
//                }
//            }

                //计算，切割团队部分的sql
                $crowdNumber = 0;

                if (!empty($userTeamBalance)) {
                    foreach ($userTeamBalance as $key => $value) {
                        if(doubleval($value) <= 0){
                            unset($userTeamBalance[$key]);
                        }
                    }
                    $teamNumber = 0;

                    foreach ($userTeamBalance as $key => $value) {
                        if ($teamNumber >= 500) {
                            if($teamNumber % 500 == 0){
                                $updateUserTeamBalanceHeaderSql = 'update sp_user set team_balance = CASE uid ';
                            }
                            $updateUserTeamBalanceSqlMore[intval($teamNumber / 500)] = $updateUserTeamBalanceHeaderSql .= " WHEN '" . ($key ?? 'notfound') . "' THEN (team_balance + " . ($value ?? 0) . ")";
                            $updateUserTeamBalanceSqlMoreUid[intval($teamNumber / 500)][] = $key;
                            unset($userTeamBalance[$key]);
                        } else{
                            $updateUserTeamBalanceSql .= " WHEN '" . ($key ?? 'notfound') . "' THEN (team_balance + " . ($value ?? 0) . ")";
                            unset($userTeamBalance[$key]);
                        }
                        $teamNumber += 1;
                    }

                    $updateUserTeamBalanceSql .= ' ELSE (team_balance + 0) END WHERE uid in ' . $allUidSql;
                    if(!empty($updateUserTeamBalanceSqlMore ?? [])){
                        foreach ($updateUserTeamBalanceSqlMore as $key => $value) {
                            $updateUserTeamBalanceSqlMore[$key] .= ' ELSE (team_balance + 0) END WHERE uid in ' . "('".implode("','",$updateUserTeamBalanceSqlMoreUid[$key])."')";;
                        }
                    }

                }
                //计算。切割区代的sql
                $crowdNumber = 0;
                $teamNumber = 0;

                if (!empty($userAreaBalance)) {
                    foreach ($userAreaBalance as $key => $value) {
                        if(doubleval($value) <= 0){
                            unset($userAreaBalance[$key]);
                        }
                    }
                    $areaNumber = 0;

                    foreach ($userAreaBalance as $key => $value) {
                        if ($areaNumber >= 500) {
                            if($areaNumber % 500 == 0){
                                $updateUserAreaBalanceHeaderSql = 'update sp_user set area_balance = CASE uid ';
                            }
                            $updateUserAreaBalanceSqlMore[intval($areaNumber / 500)] = $updateUserAreaBalanceHeaderSql .= " WHEN '" . ($key ?? 'notfound') . "' THEN (area_balance + " . ($value ?? 0) . ")";
                            $updateUserAreaBalanceSqlMoreUid[intval($areaNumber / 500)][] = $key;
                            unset($userAreaBalance[$key]);
                        } else{
                            $updateUserAreaBalanceSql .= " WHEN '" . ($key ?? 'notfound') . "' THEN (area_balance + " . ($value ?? 0) . ")";
                            unset($userAreaBalance[$key]);
                        }
                        $areaNumber += 1;
                    }

                    $updateUserAreaBalanceSql .= ' ELSE (area_balance + 0) END WHERE uid in ' . $allUidSql;
                    if(!empty($updateUserAreaBalanceSqlMore ?? [])){
                        foreach ($updateUserAreaBalanceSqlMore as $key => $value) {
                            $updateUserAreaBalanceSqlMore[$key] .= ' ELSE (area_balance + 0) END WHERE uid in ' . "('".implode("','",$updateUserAreaBalanceSqlMoreUid[$key])."')";;
                        }
                    }

                }

                $log['updateUserCrowdBalanceSql'] = $updateUserCrowdBalanceSql;
                $log['updateUserCrowdBalanceSqlMore'] = $updateUserCrowdBalanceSqlMore ?? [];
//            $log['updateUserIntegralSql'] = $updateUserIntegralSql;
//            $log['updateUserIntegralSqlMore'] = $updateUserIntegralSqlMore ?? [];
                $log['updateUserTeamBalanceSql'] = $updateUserTeamBalanceSql;
                $log['updateUserTeamBalanceSqlMore'] = $updateUserTeamBalanceSqlMore ?? [];
                $log['updateUserAreaBalanceSql'] = $updateUserAreaBalanceSql;
                $log['updateUserAreaBalanceSqlMore'] = $updateUserAreaBalanceSqlMore ?? [];
//            dump($updateUserCrowdBalanceSql);
//            dump($updateUserCrowdBalanceSqlMore ?? []);
//            dump($updateUserTeamBalanceSql);
//            dump($updateUserTeamBalanceSqlMore ?? []);
//            dump($updateUserAreaBalanceSql);
//            dump($updateUserAreaBalanceSqlMore ?? []);die;

                //对应的sql执行
                if (!empty($haveCrowdBalanceSql)) {
                    Db::query($updateUserCrowdBalanceSql);
                    if(!empty($updateUserCrowdBalanceSqlMore ?? [])){
                        foreach ($updateUserCrowdBalanceSqlMore as $key => $value) {
                            Db::query($value);
                        }
                    }
                }
//            if (!empty($haveIntegralSql)) {
//                Db::query($updateUserIntegralSql);
//                if(!empty($updateUserIntegralSqlMore ?? [])){
//                    foreach ($updateUserIntegralSqlMore as $key => $value) {
//                        Db::query($value);
//                    }
//                }
//            }
                if (!empty($haveTeamBalanceSql)) {
                    Db::query($updateUserTeamBalanceSql);
                    if(!empty($updateUserTeamBalanceSqlMore ?? [])){
                        foreach ($updateUserTeamBalanceSqlMore as $key => $value) {
                            Db::query($value);
                        }
                    }
                }
                if (!empty($haveAreaBalanceSql)) {
                    Db::query($updateUserAreaBalanceSql);
                    if(!empty($updateUserAreaBalanceSqlMore ?? [])){
                        foreach ($updateUserAreaBalanceSqlMore as $key => $value) {
                            Db::query($value);
                        }
                    }
                }

                //修改分润状态为到账
                $allDivideListId = array_unique(array_column($divideList, 'id'));
//            $log['allDivideListId'] = $haveAreaBalanceSql;

                if (!empty($allDivideListId)) {
                    if (!empty($unsetDivideId ?? [])) {
                        $dWhere[] = ['id', 'not in', $unsetDivideId];
                    }
                    $divideListSql = Divide::field('id')->where($dWhere)->when(!empty($notSuccessPeriod ?? null), function ($query) use ($notSuccessPeriod) {
                        $notSuccessPeriod = array_values($notSuccessPeriod);
                        foreach ($notSuccessPeriod as $key => $value) {
                            ${'where' . ($key + 1)}[] = ['crowd_code', '<>', $value['crowd_code']];
                            ${'where' . ($key + 1)}[] = ['crowd_round_number', '<>', $value['crowd_round_number']];
                            ${'where' . ($key + 1)}[] = ['crowd_period_number', '<>', $value['crowd_period_number']];
                        }
                        for ($i = 0; $i < count($notSuccessPeriod); $i++) {
                            $allWhereOr[] = ${'where' . ($i + 1)};
                        }
                        $query->whereOr($allWhereOr);
                    })->buildSql();
                    $acWhere[] = ['', 'exp', Db::raw("id in (select id from $divideListSql a)")];
                    $acWhere[] = ['arrival_status', '=', 2];
                    $acWhere[] = ['status', '=', 1];
//                Divide::update(['arrival_status' => 1, 'arrival_time' => time()], ['id' => $allDivideListId, 'arrival_status' => 2, 'status' => 1]);
                    Divide::update(['arrival_status' => 1, 'arrival_time' => time()], $acWhere);
                }

                //修改对应的所有订单为可同步
//            if (!empty($syncOrder)) {
//                (new Order())->isAutoWriteTimestamp(false)->update(['can_sync' => 1], ['order_sn' => array_unique($syncOrder)]);
//            }

                if (!empty($aBalance ?? [])) {
                    //拼接sql批量插入
                    $chunkaBalance = array_chunk($aBalance, 500);
                    foreach ($chunkaBalance as $key => $value) {
                        $sqls = '';
                        $itemStrs = '';
                        $sqls = sprintf("INSERT INTO sp_crowdfunding_balance_detail (uid, order_sn,pay_no,belong,type,price,change_type,status,remark,create_time,update_time,crowd_code,crowd_round_number,crowd_period_number,is_grateful) VALUES ");
                        $createTime = time();
                        foreach ($value as $items) {
                            $itemStrs = '( ';
                            $itemStrs .= ("'" . $items['uid'] . "'," . "'" . $items['order_sn'] . "'," . "''" . "," . ($items['belong'] ?? 1) . "," . $items['type'] . "," . $items['price'] . "," . $items['change_type'] . "," . "1" . "," . "'" . $items['remark'] . "'" . "," . $createTime . "," . $createTime . "," . "'" . $items['crowd_code'] . "'" . "," . "'" . $items['crowd_round_number'] . "'" . "," . "'" . $items['crowd_period_number'] . "'" . "," . ($items['is_grateful'] ?? 2));
                            $itemStrs .= '),';
                            $sqls .= $itemStrs;
                        }

                        // 去除最后一个逗号，并且加上结束分号
                        $sqls = rtrim($sqls, ',');
                        $sqls .= ';';
                        if (!empty($itemStrs ?? null) && !empty($sqls)) {
                            Db::query($sqls);
                        }
                    }
//                (new CrowdfundingBalanceDetail())->saveAll(array_values($aBalance));
                }

//            //插入积分明细
//            $sqls = null;
//            if (!empty($aIntegral ?? [])) {
//                //拼接sql批量插入
//                $chunkaIntegral = array_chunk($aIntegral, 500);
//                foreach ($chunkaIntegral as $key => $value) {
//                    $sqls = '';
//                    $itemiStrs = '';
//                    $sqls = sprintf("INSERT INTO sp_integral_detail (order_sn,integral,type,uid,change_type,remark,status,create_time,update_time,crowd_code,crowd_round_number,crowd_period_number,goods_sn,sku_sn) VALUES ");
//                    $createTime = time();
//                    foreach ($value as $items) {
//                        $itemiStrs = '( ';
//                        $itemiStrs .= ("'" . $items['order_sn'] . "'," . "''" . "," . ($items['price'] ?? 0) . "," . $items['type'] . "," . $items['uid'] . "," . $items['change_type'] . "," . "'" . $items['remark'] . "'," . "1" . "," . $createTime . "," . $createTime . "," . "'" . $items['crowd_code'] . "'" . "," . "'" . $items['crowd_round_number'] . "'" . "," . "'" . $items['crowd_period_number'] . "'" . "," . "'" . ($items['goods_sn'] ?? '') . "'" . "," . "'" . ($items['sku_sn'] ?? '') . "'");
//                        $itemiStrs .= '),';
//                        $sqls .= $itemiStrs;
//                    }
//
//                    // 去除最后一个逗号，并且加上结束分号
//                    $sqls = rtrim($sqls, ',');
//                    $sqls .= ';';
//                    if (!empty($itemiStrs ?? null) && !empty($sqls)) {
//                        Db::query($sqls);
//                    }
//                }
////                (new IntegralDetail())->saveAll(array_values($aIntegral));
//            }

                $sqls = null;
                if (!empty($balance ?? [])) {
                    //拼接sql批量插入
                    $chunkBalance = array_chunk($balance, 500);
                    foreach ($chunkBalance as $key => $value) {
                        $bsqls = '';
                        $bitemStrs = '';
                        $bsqls = sprintf("INSERT INTO sp_crowdfunding_balance_detail (uid, order_sn,pay_no,belong,type,price,change_type,status,remark,create_time,update_time,crowd_code,crowd_round_number,crowd_period_number,is_grateful) VALUES ");
                        $createTime = time();
                        foreach ($value as $items) {
                            $bitemStrs = '( ';
                            $bitemStrs .= ("'" . $items['uid'] . "'," . "'" . $items['order_sn'] . "'," . "''" . "," . ($items['belong'] ?? 1) . "," . $items['type'] . "," . $items['price'] . "," . $items['change_type'] . "," . "1" . "," . "'" . $items['remark'] . "'" . "," . $createTime . "," . $createTime . "," . "'" . $items['crowd_code'] . "'" . "," . "'" . $items['crowd_round_number'] . "'" . "," . "'" . $items['crowd_period_number'] . "'" . "," . ($items['is_grateful'] ?? 2));
                            $bitemStrs .= '),';
                            $bsqls .= $bitemStrs;
                        }

                        // 去除最后一个逗号，并且加上结束分号
                        $bsqls = rtrim($bsqls, ',');
                        $bsqls .= ';';
                        if (!empty($bitemStrs ?? null) && !empty($bsqls)) {
                            Db::query($bsqls);
                        }
                    }
//                (new CrowdfundingBalanceDetail())->saveAll(array_values($balance));
                }
                if (!empty($uBalance ?? [])) {
                    //拼接sql批量插入
                    $chunkuBalance = array_chunk($uBalance, 500);
                    foreach ($chunkuBalance as $key => $value) {
                        $usqls = '';
                        $uitemStrs = '';
                        $usqls = sprintf("INSERT INTO sp_balance_detail (uid, order_sn,belong,type,price,change_type,status,remark,create_time,update_time) VALUES ");
                        $createTime = time();
                        foreach ($value as $items) {
                            $uitemStrs = '( ';
                            $uitemStrs .= ("'" . $items['uid'] . "'," . "'" . $items['order_sn'] . "'," . ($items['belong'] ?? 1) . "," . $items['type'] . "," . $items['price'] . "," . $items['change_type'] . "," . "1" . "," . "'" . $items['remark'] . "'" . "," . $createTime . "," . $createTime);
                            $uitemStrs .= '),';
                            $usqls .= $uitemStrs;
                        }

                        // 去除最后一个逗号，并且加上结束分号
                        $usqls = rtrim($usqls, ',');
                        $usqls .= ';';
                        if (!empty($uitemStrs ?? null) && !empty($usqls)) {
                            Db::query($usqls);
                        }
                    }
//                (new BalanceDetail())->saveAll(array_values($uBalance));
                }
            } else {
                $delayAllSuccessOrderSn = [];
                if (!empty($delayDivideList ?? [])) {
                    foreach ($delayDivideList as $key => $value) {
//订单本人才可以反本金, 其他人得对应的奖金
                        if ($value['order_uid'] == $value['link_uid'] && $value['type'] == 8 && $value['is_grateful'] == 2) {
                            $delayAllSuccessOrderSn[$value['order_sn']] = $value['order_sn'];
                        }
                    }
                }

            }

            //批量修改所有订单为已完成
            if (!empty($allSuccessOrderSn ?? [])) {
                (new Order())->update(['order_status' => 8, 'end_time' => time()], ['order_sn' => array_unique($allSuccessOrderSn), 'order_type' => 6]);

                CrowdfundingDelayRewardOrder::update(['arrival_status' => 1, 'real_arrival_time' => time(), 'remark' => '跟随期自动发放'], ['order_sn' => array_unique($allSuccessOrderSn), 'arrival_status' => 3]);
            }

            //批量修改所有延迟发放奖励订单为已完成--延时发放奖励的订单也直接将订单修改为已完成,只是奖励晚发
            if (!empty($delayAllSuccessOrderSn ?? [])) {
                (new Order())->update(['order_status' => 8, 'end_time' => time()], ['order_sn' => array_unique($delayAllSuccessOrderSn), 'order_type' => 6]);
            }

            //修改众筹期状态为成功
            foreach ($periodList as $key => $value) {
                $successCrowdKey = $value['activity_code'] . '-' . $value['round_number'] . '-' . $value['period_number'];
                CrowdfundingPeriod::update(['result_status' => 1, 'success_time' => time()], ['activity_code' => $value['activity_code'], 'round_number' => $value['round_number'], 'period_number' => $value['period_number'], 'result_status' => 2]);
                //如果存在该期延迟发放奖励的订单, 冻结中的熔断分期返回金额统一按照最早一笔超前提前购订单发放奖励的时候一起发放
                if (empty($delayPeriodInfo ?? []) || (!empty($delayPeriodInfo ?? []) && empty($delayPeriodInfo[$successCrowdKey] ?? null))) {
                    //释放冻结中的历史熔断分期返回美丽金
                    Queue::later(15, 'app\lib\job\CrowdFunding', ['dealType' => 6, 'periodList' => [0 => ['crowd_code' => $value['activity_code'], 'crowd_round_number' => $value['round_number'], 'crowd_period_number' => $value['period_number']]]], config('system.queueAbbr') . 'CrowdFunding');
                }
            }

            $res = ['priceBalance' => ($aBalance ?? []), 'rewardBalance' => ($balance ?? [])];
            //记录日志
            $log['DBRes'] = $res;
            $this->log($log, 'info');

            //释放对象, 防止内存泄露
            unset($aBalance);
            unset($balance);
            unset($uBalance);
            unset($allDivideListId);
            unset($allSuccessOrderSn);
            unset($delayAllSuccessOrderSn);

            return $res;
        });

//        if (!empty($periodList) && !empty($divideList)) {
//            //同步熔断和本期的众筹活动所有订单到发货订单
//            $syncOrder['searCrowdFunding'] = true;
//            $syncOrder['order_type'] = 6;
//            $syncOrder['start_time'] = date('Y-m-d H:i:s', (strtotime(min(array_column($divideList, 'create_time'))) - 3600));
//            $syncOrder['end_time'] = date('Y-m-d H:i:s', (strtotime(max(array_column($divideList, 'create_time')))));
//            (new ShipOrder())->sync($syncOrder);
//        }

        $log['DBRes'] = $DBRes;
        $this->log($log, 'info');
        return true;
    }


    /**
     * @title  判断是否有过了冻结期的众筹奖金发放(老方法)
     * @param array $data
     * @return bool
     * @throws \Exception
     */
    public function checkSuccessPeriodOld(array $data)
    {

        set_time_limit(0);
        ini_set('memory_limit', '3072M');
        ini_set('max_execution_time', '0');
        $successExpire = CrowdfundingSystemConfig::where(['id' => 1])->value('period_success_time');
        $successPeriodNumber = CrowdfundingSystemConfig::where(['id' => 1])->value('success_period_number');
        $log['msg'] = '查找是否有可以释放成功的众筹活动';
        $log['requestData'] = $data;

        //searType 1为查询所有待成功的期, 然后一个个N轮后判断是否成功 2为查询指定期是否成功
        $searType = $data['searType'] ?? 1;
        //查询包含众筹标识的冻结中的分润记录, 有团队业绩和众筹类型和区代
        $dWhere[] = ['arrival_status', '=', 2];
        $dWhere[] = ['status', '=', 1];
        $dWhere[] = ['type', 'in', [8, 5, 7]];
        $dWhere[] = ['', 'exp', Db::raw('crowd_code is not null and crowd_round_number is not null and crowd_period_number is not null')];
        if ($searType == 2) {
            $startPeriodNumber = intval($data['period_number']) - $successPeriodNumber;
            if ($startPeriodNumber <= 0) {
                return $this->recordError($log, ['msg' => '根据指定的成功轮数,需要经过' . $successPeriodNumber . '轮才可以成功,目前最大期数还不足,故此期不能判定为成功']);
            }
            $dWhere[] = ['crowd_code', '=', $data['activity_code']];
            $dWhere[] = ['crowd_round_number', '=', $data['round_number']];
            $dWhere[] = ['crowd_period_number', '=', $startPeriodNumber];
            $dWhere[] = ['crowd_period_number', '<>', $data['period_number']];
        }
//        $dWhere[] = ['create_time', '<=', (time() - $successExpire)];
        $divideList = Divide::where($dWhere)->select()->toArray();


        $log['divideList'] = $divideList;
        if (empty($divideList)) {
            return $this->recordError($log, ['msg' => '无有效的冻结收益']);
        }

        $pWhere[] = ['buy_status', '=', 1];
        $pWhere[] = ['result_status', 'in', [2]];
        if ($searType == 2) {
            $startPeriodNumber = intval($data['period_number']) - $successPeriodNumber;
            if ($startPeriodNumber <= 0) {
                return $this->recordError($log, ['msg' => '根据指定的成功轮数,需要经过' . $successPeriodNumber . '轮才可以成功,目前最大期数还不足,故此期不能判定为成功']);
            }
            $pWhere[] = ['activity_code', '=', $data['activity_code']];
            $pWhere[] = ['round_number', '=', $data['round_number']];
            $pWhere[] = ['period_number', '>=', $startPeriodNumber];
        }
        //查询所有待成功的期, 来匹配是否后N轮是否有成功然后判断是否真的成功
        $allPeriodList = CrowdfundingPeriod::where($pWhere)->select()->toArray();

        foreach ($allPeriodList as $key => $value) {
            $crowdKey = $value['activity_code'] . '-' . $value['round_number'] . '-' . $value['period_number'];
            $allPeriodInfo[$crowdKey] = $value;
        }
        $log['allPeriodList'] = $allPeriodList;

        if ($searType == 1) {
            foreach ($divideList as $key => $value) {
                $crowdKey = $value['crowd_code'] . '-' . $value['crowd_round_number'] . '-' . $value['crowd_period_number'];
                $successPeriod[$crowdKey]['activity_code'] = $value['crowd_code'];
                $successPeriod[$crowdKey]['round_number'] = $value['crowd_round_number'];
                $successPeriod[$crowdKey]['period_number'] = $value['crowd_period_number'];
            }
        } elseif ($searType == 2) {
            foreach ($allPeriodList as $key => $value) {
                $crowdKey = $value['activity_code'] . '-' . $value['round_number'] . '-' . $value['period_number'];
                $successPeriod[$crowdKey]['activity_code'] = $value['activity_code'];
                $successPeriod[$crowdKey]['round_number'] = $value['round_number'];
                $successPeriod[$crowdKey]['period_number'] = $value['period_number'];
            }
        }

        $log['successPeriod'] = $successPeriod ?? [];
        $successPeriodCount = [];
        foreach ($successPeriod as $key => $value) {
            if (!isset($successPeriodCount[$key])) {
                $successPeriodCount[$key] = 0;
            }
            for ($i = 1; $i <= $successPeriodNumber; $i++) {
                if (!empty($allPeriodInfo[$value['activity_code'] . '-' . $value['round_number'] . '-' . (intval($value['period_number']) + $i)] ?? [])) {
                    $successPeriodCount[$key] += 1;
                }
            }
        }
        $log['successPeriodCount'] = $successPeriodCount ?? [];


        if (empty($successPeriodCount)) {
            return $this->recordError($log, ['msg' => '剔除后-查无有效的期(N+3)冻结收益']);
        }

        //如果成功的本期前N次没有都成功, 则不允许释放本期的奖励
        foreach ($successPeriodCount as $key => $value) {
            $crowdKey = null;
            if ($value < $successPeriodNumber) {
                unset($successPeriod[$key]);
                $crowdKey = explode($key, '-');
                foreach ($divideList as $dKey => $dValue) {
                    if ($value['crowd_code'] == $crowdKey[0] && $value['crowd_round_number'] == $crowdKey[1] && $value['crowd_period_number'] == $crowdKey[2]) {
                        unset($divideList[$dKey]);
                    }
                }
            }
        }

        $log['successPeriodNew'] = $successPeriod ?? [];
        $log['successDivideNew'] = $divideList ?? [];
        if (empty($successPeriod)) {
            return $this->recordError($log, ['msg' => '剔除后-无有效的期冻结收益']);
        }
        if (empty($divideList)) {
            return $this->recordError($log, ['msg' => '剔除后-无有效的冻结收益']);
        }

        $oWhere[] = ['buy_status', '=', 1];
        $oWhere[] = ['status', '=', 1];
        $oWhere[] = ['result_status', 'in', [2]];
        $periodList = CrowdfundingPeriod::where(function ($query) use ($successPeriod) {
            $successPeriod = array_values($successPeriod);
            foreach ($successPeriod as $key => $value) {
                ${'where' . ($key + 1)}[] = ['activity_code', '=', $value['activity_code']];
                ${'where' . ($key + 1)}[] = ['round_number', '=', $value['round_number']];
                ${'where' . ($key + 1)}[] = ['period_number', '=', $value['period_number']];
            }
            for ($i = 0; $i < count($successPeriod); $i++) {
                $allWhereOr[] = ${'where' . ($i + 1)};
            }
            $query->whereOr($allWhereOr);
        })->where($oWhere)->select()->toArray();

        $log['periodList'] = $periodList;
        if (empty($periodList)) {
            return $this->recordError($log, ['msg' => '冻结的收益无有效的众筹期']);
        }
        foreach ($periodList as $key => $value) {
            $crowdKey = $value['activity_code'] . '-' . $value['round_number'] . '-' . $value['period_number'];
            $periodInfo[$crowdKey] = $value;
        }

        $DBRes = Db::transaction(function () use ($periodList, $divideList, $periodInfo, $log) {
            foreach ($divideList as $key => $value) {
                //加锁防止其他操作打断
//                $userId = (new User())->where(['uid' => $value['link_uid']])->value('id');
//                (new User())->where(['id' => $userId])->lock(true)->value('uid');
//                cache('canNotOperBalance-' . $value['link_uid'], 1,300);
                $crowdKey = $value['crowd_code'] . '-' . $value['crowd_round_number'] . '-' . $value['crowd_period_number'];
                //订单本人才可以反本金, 其他人得对应的奖金
                if ($value['order_uid'] == $value['link_uid'] && $value['type'] == 8 && $value['is_grateful'] == 2) {
                    //余额明细,本金
                    $aBalance[$key]['order_sn'] = $value['order_sn'];
                    //100%返本金
                    $aBalance[$key]['price'] = priceFormat($value['total_price'] * 1);
                    $aBalance[$key]['type'] = 1;
                    $aBalance[$key]['uid'] = $value['link_uid'];
                    $aBalance[$key]['change_type'] = 3;
                    $aBalance[$key]['crowd_code'] = $value['crowd_code'];
                    $aBalance[$key]['crowd_round_number'] = $value['crowd_round_number'];
                    $aBalance[$key]['crowd_period_number'] = $value['crowd_period_number'];
                    $aBalance[$key]['remark'] = '福利活动成功返本金(' . $crowdKey . ')';
                    if (doubleval($aBalance[$key]['price']) > 0) {
                        //退款到用户账户
                        $refundRes[$key] = (new User())->where(['uid' => $value['link_uid']])->inc('crowd_balance', $aBalance[$key]['price'])->update();
                    }
                    //修改对应的所有订单为可同步
                    (new Order())->isAutoWriteTimestamp(false)->update(['can_sync' => 1], ['order_sn' => $value['order_sn']]);
                }

                //余额明细, 奖金
                if ($value['type'] == 5) {
                    //如果是团队业绩记录在原来的钱包里面
                    $uBalance[$key]['order_sn'] = $value['order_sn'];
                    $uBalance[$key]['belong'] = 1;
                    $uBalance[$key]['price'] = $value['real_divide_price'];
                    $uBalance[$key]['type'] = 1;
                    $uBalance[$key]['uid'] = $value['link_uid'];
                    $uBalance[$key]['change_type'] = 16;
                    $uBalance[$key]['remark'] = '福利活动成功团队奖奖金(' . $crowdKey . ')';
                    if (!empty($value['is_exp'] ?? null) && $value['is_exp'] == 1) {
                        if($value['level'] == 3){
                            $uBalance[$key]['remark'] = '福利活动成功团队股东奖奖金(' . $crowdKey . ')';
                        }else{
                            $uBalance[$key]['remark'] = '福利活动成功体验中心奖奖金(' . $crowdKey . ')';
                        }
                    }

                    if (doubleval($uBalance[$key]['price']) > 0) {
                        //发奖金到用户团队业绩账户
                        $refundRes[$key] = (new User())->where(['uid' => $value['link_uid']])->inc('team_balance', $uBalance[$key]['price'])->update();
                    }
                } elseif ($value['type'] == 7) {
                    //如果是区代奖励记录在原来的钱包里面
                    $uBalance[$key]['order_sn'] = $value['order_sn'];
                    $uBalance[$key]['belong'] = 1;
                    $uBalance[$key]['price'] = $value['real_divide_price'];
                    $uBalance[$key]['type'] = 1;
                    $uBalance[$key]['uid'] = $value['link_uid'];
                    $uBalance[$key]['change_type'] = 20;
                    $uBalance[$key]['remark'] = '福利活动成功区代奖奖金(' . $crowdKey . ')';
                    if (doubleval($uBalance[$key]['price']) > 0) {
                        //发奖金到用户团队业绩账户
                        $refundRes[$key] = User::where(['uid' => $value['link_uid']])->inc('area_balance', $uBalance[$key]['price'])->update();
                    }
                } elseif ($value['type'] == 8) {
                    //如果是众筹记录在众筹的钱包里面
                    $balance[$key]['order_sn'] = $value['order_sn'];
                    $balance[$key]['price'] = $value['real_divide_price'];
                    $balance[$key]['type'] = 1;
                    $balance[$key]['uid'] = $value['link_uid'];
                    $balance[$key]['change_type'] = 4;
                    $balance[$key]['crowd_code'] = $value['crowd_code'];
                    $balance[$key]['crowd_round_number'] = $value['crowd_round_number'];
                    $balance[$key]['crowd_period_number'] = $value['crowd_period_number'];
                    $balance[$key]['remark'] = '福利活动成功奖金(' . $crowdKey . ')';
                    if ($value['is_grateful'] == 1) {
                        $balance[$key]['remark'] = '福利活动成功感恩奖奖金(' . $crowdKey . ')';
                        $balance[$key]['is_grateful'] = 1;
                    }
                    if (doubleval($balance[$key]['price']) > 0) {
                        //发奖金到用户账户
                        $refundRes[$key] = (new User())->where(['uid' => $value['link_uid']])->inc('crowd_balance', $balance[$key]['price'])->update();
                    }
                }

                //修改分润状态为到账
                $divideRes[$key] = Divide::update(['arrival_status' => 1, 'arrival_time' => time()], ['order_sn' => $value['order_sn'], 'type' => $value['type'], 'link_uid' => $value['link_uid'], 'is_grateful' => $value['is_grateful'], 'arrival_status' => 2, 'status' => 1, 'crowd_code' => $value['crowd_code'], 'crowd_round_number' => $value['crowd_round_number'], 'crowd_period_number' => $value['crowd_period_number']]);
            }

            if (!empty($aBalance ?? [])) {
                (new CrowdfundingBalanceDetail())->saveAll(array_values($aBalance));
            }
            if (!empty($balance ?? [])) {
                (new CrowdfundingBalanceDetail())->saveAll(array_values($balance));
            }
            if (!empty($uBalance ?? [])) {
                (new BalanceDetail())->saveAll(array_values($uBalance));
            }
            //修改众筹期状态为成功
            foreach ($periodList as $key => $value) {
                CrowdfundingPeriod::update(['result_status' => 1, 'success_time' => time()], ['activity_code' => $value['activity_code'], 'round_number' => $value['round_number'], 'period_number' => $value['period_number'], 'result_status' => 2]);
                //释放冻结中的历史熔断分期返回美丽金
                Queue::later(15, 'app\lib\job\CrowdFunding', ['dealType' => 6, 'periodList' => [0 => ['crowd_code' => $value['activity_code'], 'crowd_round_number' => $value['round_number'], 'crowd_period_number' => $value['period_number']]]], config('system.queueAbbr') . 'CrowdFunding');
            }

            $res = ['priceBalance' => $aBalance ?? [], 'rewardBalance' => $balance ?? []];
            //记录日志
            $log['DBRes'] = $res;
            $this->log($log, 'info');

            //释放对象, 防止内存泄露
            unset($aBalance);
            unset($balance);
            unset($uBalance);

            return $res;
        });

        if (!empty($periodList) && !empty($divideList)) {
            //同步熔断和本期的众筹活动所有订单到发货订单
            $syncOrder['searCrowdFunding'] = true;
            $syncOrder['order_type'] = 6;
            $syncOrder['start_time'] = date('Y-m-d H:i:s', (strtotime(min(array_column($divideList, 'create_time'))) - 3600));
            $syncOrder['end_time'] = date('Y-m-d H:i:s', (strtotime(max(array_column($divideList, 'create_time')))));
            (new ShipOrder())->sync($syncOrder);
        }

//        $log['DBRes'] = $DBRes;
//        $this->log($log, 'info');
        return true;
    }

    /**
     * @title  自动新增一期
     * @param array $data
     * @return mixed
     * @throws \Exception
     */
    public function regeneratePeriod(array $data)
    {
        $activityCode = $data['activity_code'];
        $roundNumber = $data['round_number'];
        $periodNumber = $data['period_number'];
        //type 1为成功的新增, 则沿用本区本轮上期的;2为失败的新增, 则用本区上一轮第一期的, 时间则用本轮本期的然后增加指定间隔时间
        $type = $data['type'] ?? 1;
        $newPeriod['activity_code'] = $activityCode;
        $newPeriod['add_type'] = 2;
        $newPeriod['regenerate_type'] = $type;
        if ($type == 1) {
            $newPeriod['round_number'] = $roundNumber;
            $newPeriod['period_number'] = $periodNumber + 1;
            $newPeriod['top_round_number'] = $roundNumber;
            $newPeriod['top_period_number'] = $periodNumber;
        } else {
            $newPeriod['round_number'] = $roundNumber + 1;
            $newPeriod['period_number'] = 1;
            $newPeriod['top_round_number'] = $roundNumber - 1 > 0 ? $roundNumber - 1 : 1;
            $newPeriod['top_period_number'] = 1;
            $newPeriod['time_top_round_number'] = $roundNumber;
            $newPeriod['time_top_period_number'] = $periodNumber;
        }

        $oldPeriod = (new CrowdfundingPeriod())->DBNew($newPeriod);
        return $oldPeriod;
    }

    /**
     * @title  用户选择熔断方案<预处理>
     * @param array $data
     * @return mixed
     * @throws \Exception
     */
    public function userChoosePeriodFusePlanGroupByPeriod(array $data)
    {
        //加缓存锁防止并发
        if (!empty(cache('chooseFusePlanReady-' . $data['uid']))) {
            throw new OrderException(['msg' => '请勿重复提交操作']);
        }
        cache('chooseFusePlanReady-' . $data['uid'], $data, 60);
        $orderSn = $data['order_sn'];
        //choose_type 选择类型 1为根据期找出对应订单统一选择方案 2为根据订单单独选择方案
        switch ($data['choose_type'] ?? 1) {
            case 1:
                $orderGoodsPeriod = OrderGoods::where(['order_sn' => $orderSn, 'pay_status' => 2])->findOrEmpty()->toArray();
                $map[] = ['a.uid', '=', $data['uid']];
                $map[] = ['b.crowd_code', '=', $orderGoodsPeriod['crowd_code']];
                $map[] = ['b.crowd_round_number', '=', $orderGoodsPeriod['crowd_round_number']];
                $map[] = ['b.crowd_period_number', '=', $orderGoodsPeriod['crowd_period_number']];
                $map[] = ['b.pay_status', '=', 2];
                $map[] = ['b.status', '=', 1];
                $map[] = ['b.after_status', 'in', [1, -1]];
                $map[] = ['a.crowd_fuse_status', '=', 1];
                $map[] = ['a.crowd_fuse_type', '=', 88];

                $orderSnList = Db::name('order')->alias('a')
                    ->join('sp_order_goods b', 'a.order_sn = b.order_sn', 'left')
                    ->where($map)
                    ->column('a.order_sn');
                if (empty($orderSnList)) {
                    throw new CrowdFundingActivityException(['msg' => '查无有效订单']);
                }
                foreach (array_unique($orderSnList) as $key => $value) {
                    $cData = [];
                    $cData['order_sn'] = $value;
                    $cData['uid'] = $data['uid'];
                    $cData['type'] = $data['type'];
                    $this->userChoosePeriodFusePlan($cData);
                }
                break;
            case 2:
                $cData['order_sn'] = $orderSn;
                $cData['uid'] = $data['uid'];
                $cData['type'] = $data['type'];
                $this->userChoosePeriodFusePlan($cData);
                break;
            default:
                throw new CrowdFundingActivityException([['msg' => '无效的方案']]);
                break;
        }
        //清除缓存锁
        cache('chooseFusePlanReady-' . $data['uid'],null);
        return true;
    }

    /**
     * @title  用户选择熔断方案
     * @param array $data
     * @throws \Exception
     * @return mixed
     */
    public function userChoosePeriodFusePlan(array $data)
    {
        $orderSn = $data['order_sn'];
//        加缓存锁防止并发
        if (!empty(cache('chooseFusePlan-' . $orderSn))) {
            throw new OrderException(['msg' => '请勿重复提交操作']);
        }
        cache('chooseFusePlan-' . $orderSn, $data, 60);
        $orderInfo = Order::where(['order_sn' => $orderSn, 'order_type' => 6, 'uid' => $data['uid']])->findOrEmpty()->toArray();

        if (empty($orderInfo)) {
            throw new OrderException(['msg' => '查无有效订单']);
        }
        if ($orderInfo['uid'] != $data['uid']) {
            throw new OrderException(['msg' => '操作非本人订单涉嫌违法!已记录风控, 严禁继续操作']);
        }

        if ($orderInfo['crowd_fuse_status'] == 2 || $orderInfo['crowd_fuse_type'] != 88) {
            throw new OrderException(['msg' => '不符合的订单或该订单已选择方案']);
        }

        $existRecord = CrowdfundingFuseRecord::where(['order_sn' => $orderSn, 'status' => 1])->count();
        if (!empty($existRecord)) {
            throw new OrderException(['msg' => '订单 ' . $orderSn . ' 已存在方案记录']);
        }

        $orderGoodsPeriod = OrderGoods::where(['order_sn' => $orderSn, 'pay_status' => 2])->field('*')->select()->toArray();

//        $pWhere[] = ['status', 'in',[1,2]];
        $pWhere[] = ['result_status', '=', 5];
        $periodList = CrowdfundingPeriod::where(function ($query) use ($orderGoodsPeriod) {
            foreach ($orderGoodsPeriod as $key => $value) {
                ${'where' . ($key + 1)}[] = ['activity_code', '=', $value['crowd_code']];
                ${'where' . ($key + 1)}[] = ['round_number', '=', $value['crowd_round_number']];
                ${'where' . ($key + 1)}[] = ['period_number', '=', $value['crowd_period_number']];
            }
            for ($i = 0; $i < count($orderGoodsPeriod); $i++) {
                $allWhereOr[] = ${'where' . ($i + 1)};
            }
            $query->whereOr($allWhereOr);
        })->where($pWhere)->select()->toArray();
        if (empty($periodList)) {
            throw new OrderException(['msg' => '不符合的期']);
        }
        foreach ($periodList as $key => $value) {
            $crowdKey = $value['activity_code'] . '-' . $value['round_number'] . '-' . $value['period_number'];
            $periodInfo[$crowdKey] = $value;
        }
        switch ($data['type'] ?? 1){
            case 1:
                //方案一, 直接按照比例熔断扣下部分金额, 返回剩下的部分到账上
                $DBRes = Db::transaction(function() use ($orderGoodsPeriod,$periodInfo,$orderInfo){
                    $existReturnBalance = CrowdfundingBalanceDetail::where(['order_sn' => $orderInfo['order_sn'], 'status' => 1, 'change_type' => 3])->count();
                    if (!empty($existReturnBalance)) {
                        throw new OrderException(['msg' => '订单 ' . $orderInfo['order_sn'] . ' 已存在退本金记录']);
                    }
                    foreach ($orderGoodsPeriod as $key => $value) {
                        $crowdKey = $value['crowd_code'] . '-' . $value['crowd_round_number'] . '-' . $value['crowd_period_number'];

                        //积分明细
                        $integral[$key]['order_sn'] = $value['order_sn'];
                        $integral[$key]['integral'] = priceFormat(($value['real_pay_price'] - $value['total_fare_price']) * ($periodInfo[$crowdKey]['fail_reward_scale'] ?? 1));
                        $integral[$key]['type'] = 1;
                        $integral[$key]['uid'] = $orderInfo['uid'];
                        $integral[$key]['change_type'] = 5;
                        $integral[$key]['remark'] = '福利活动鼓励奖(' . $crowdKey . ')';
                        //修改每个人的积分余额
                        $userIntegralDetail[$key] = User::where(['uid' => $orderInfo['uid']])->inc('integral', $integral[$key]['integral'])->update();

                        //余额明细
                        $balance[$key]['order_sn'] = $value['order_sn'];
                        $balance[$key]['price'] = priceFormat(($value['real_pay_price'] - $value['total_fare_price']) * ($periodInfo[$crowdKey]['fuse_return_scale'] ?? 0.5));
                        $balance[$key]['type'] = 1;
                        $balance[$key]['uid'] = $orderInfo['uid'];
                        $balance[$key]['change_type'] = 3;
                        $balance[$key]['crowd_code'] = $value['crowd_code'];
                        $balance[$key]['crowd_round_number'] = $value['crowd_round_number'];
                        $balance[$key]['crowd_period_number'] = $value['crowd_period_number'];
                        $balance[$key]['remark'] = '福利活动熔断退本金(' . $crowdKey . ')';

                        //退款到用户账户
                        $refundRes[$key] = User::where(['uid' => $orderInfo['uid']])->inc('crowd_balance', $balance[$key]['price'])->update();

                        //发放熔断赠送的提前购卡
                        (new AdvanceCardDetail())->sendAdvanceBuyCard(['send_type' => 7, 'order_sn' => $orderInfo['order_sn'], 'activity_code' => $value['crowd_code'], 'round_number' => $value['crowd_round_number'], 'period_number' => $value['crowd_period_number']]);
                    }

                    //添加积分明细
                    if (!empty($integral ?? [])) {
                        (new IntegralDetail())->saveAll($integral);
                    }

                    //添加熔断期用户的余额明细
                    if (!empty($balance ?? [])) {
                        (new CrowdfundingBalanceDetail())->saveAll($balance);
                    }
                    //修改本订单为已选择熔断方案
                    $orderUpdate['crowd_fuse_type'] = 1;
                    $orderUpdate['crowd_fuse_time'] = time();
                    Order::update($orderUpdate, ['order_sn' => $orderInfo['order_sn'], 'crowd_fuse_type' => 88, 'crowd_fuse_status' => 1]);
                    return $balance ?? [];
                });
                break;
            case 2:
                //方案二, 熔断的金额根据用户下次购买本区的订单总额比例分期返回
                $DBRes = Db::transaction(function() use ($orderGoodsPeriod,$periodInfo,$orderInfo){
                    foreach ($orderGoodsPeriod as $key => $value) {
                        $crowdKey = $value['crowd_code'] . '-' . $value['crowd_round_number'] . '-' . $value['crowd_period_number'];

                        //记录明细
                        $balance[$key]['order_sn'] = $value['order_sn'];
                        $balance[$key]['uid'] = $orderInfo['uid'];
                        $balance[$key]['original_price'] = priceFormat(($value['real_pay_price'] - $value['total_fare_price']));
                        $balance[$key]['original_price'] = priceFormat(($value['real_pay_price'] - $value['total_fare_price']) * (1 - ($periodInfo[$crowdKey]['fuse_second_return_scale'] ?? 0.5)));
                        $balance[$key]['scale'] = ($periodInfo[$crowdKey]['fuse_second_rising_scale'] ?? 1);
                        $balance[$key]['total_price'] = priceFormat($balance[$key]['original_price'] * $balance[$key]['scale']);
                        $balance[$key]['last_total_price']  = $balance[$key]['total_price'];
                        $balance[$key]['crowd_code'] = $value['crowd_code'];
                        $balance[$key]['crowd_round_number'] = $value['crowd_round_number'];
                        $balance[$key]['crowd_period_number'] = $value['crowd_period_number'];
                        $balance[$key]['grant_status'] = 3;

                        //发放熔断赠送的提前购卡
                        (new AdvanceCardDetail())->sendAdvanceBuyCard(['send_type' => 7, 'order_sn' => $orderInfo['order_sn'], 'activity_code' => $value['crowd_code'], 'round_number' => $value['crowd_round_number'], 'period_number' => $value['crowd_period_number']]);


                        //余额明细
                        $uBalance[$key]['order_sn'] = $value['order_sn'];
                        $uBalance[$key]['price'] = priceFormat(($value['real_pay_price'] - $value['total_fare_price']) * ($periodInfo[$crowdKey]['fuse_second_return_scale'] ?? 0.5));
                        $uBalance[$key]['type'] = 1;
                        $uBalance[$key]['uid'] = $orderInfo['uid'];
                        $uBalance[$key]['change_type'] = 3;
                        $uBalance[$key]['crowd_code'] = $value['crowd_code'];
                        $uBalance[$key]['crowd_round_number'] = $value['crowd_round_number'];
                        $uBalance[$key]['crowd_period_number'] = $value['crowd_period_number'];
                        $uBalance[$key]['remark'] = '福利活动熔断退本金(' . $crowdKey . ')';

                        //退款到用户账户
                        $refundRes[$key] = User::where(['uid' => $orderInfo['uid']])->inc('crowd_balance', $uBalance[$key]['price'])->update();

                    }

                    //添加熔断期用户的明细
                    if (!empty($balance ?? [])) {
                        (new CrowdfundingFuseRecord())->saveAll($balance);
                    }

                    //添加熔断期用户的余额明细
                    if (!empty($uBalance ?? [])) {
                        (new CrowdfundingBalanceDetail())->saveAll($uBalance);
                    }

                    //修改本订单为已选择熔断方案
                    $orderUpdate['crowd_fuse_type'] = 2;
                    $orderUpdate['crowd_fuse_time'] = time();
                    Order::update($orderUpdate, ['order_sn' => $orderInfo['order_sn'], 'crowd_fuse_type' => 88, 'crowd_fuse_status' => 1]);
                    return $balance ?? [];
                });
                break;
            default:
                throw new CrowdFundingActivityException(['msg' => '未知的方案']);
                break;

        }

        return true;
    }

    /**
     * @title  生成用户分期需要返还的熔断金额<冻结状态><具体到熔断的区>
     * @param array $data
     * @return bool
     * @throws \Exception
     */
    public function crowdFusePlanDivideInFuseActivity(array $data)
//    public function crowdFusePlanDivide(array $data)
    {
        $dealPeriodList = $data['periodList'];
        if (empty($dealPeriodList)) {
            return $this->recordError($data, ['msg' => '无有效期']);
        }
        if (count($dealPeriodList) != 1) {
            return $this->recordError($data, ['msg' => '单次仅允许执行一个期']);
        }
        $log['data'] = $data;
        //查看是否有该期有效的还有剩余金额可分期的记录
        $pWhere[] = ['status', '=', 1];
        $pWhere[] = ['grant_status', 'in', [2,3]];
        $pWhere[] = ['last_total_price', '>', 0];
        $fuseRecordList = CrowdfundingFuseRecord::where(function ($query) use ($dealPeriodList) {
            foreach ($dealPeriodList as $key => $value) {
                ${'where' . ($key + 1)}[] = ['crowd_code', '=', $value['crowd_code']];
            }
            for ($i = 0; $i < count($dealPeriodList); $i++) {
                $allWhereOr[] = ${'where' . ($i + 1)};
            }
            $query->whereOr($allWhereOr);
        })->withSum('detail','price')->where($pWhere)->select()->toArray();

        $log['fuseRecordList'] = $fuseRecordList;
        if (empty($fuseRecordList)) {
            return $this->recordError($log, ['msg' => '无有效可发放记录']);
        }
        //剔除已经发放完毕的但是主记录存在异常的数据或者是剩余发放金额大于总金额的异常数据
        foreach ($fuseRecordList as $key => $value) {
            if (!empty(doubleval($value['detail_sum'] ?? 0)) && (string)$value['detail_sum'] >= (string)$value['total_price']) {
                unset($fuseRecordList[$key]);
            }
            if ((string)$value['last_total_price'] > (string)$value['total_price']) {
                unset($fuseRecordList[$key]);
            }
        }

        if (empty($fuseRecordList)) {
            return $this->recordError($log, ['msg' => '剔除数据后无有效可发放记录']);
        }

        //必须为认购满待成功状态的才可以进入计算
        $cWhere[] = ['status', '=', 1];
        $cWhere[] = ['buy_status', '=', 1];
        $cWhere[] = ['result_status', 'in', [2]];
        $periodList = CrowdfundingPeriod::where(function ($query) use ($dealPeriodList) {
            foreach ($dealPeriodList as $key => $value) {
                ${'where' . ($key + 1)}[] = ['activity_code', '=', $value['crowd_code']];
                ${'where' . ($key + 1)}[] = ['round_number', '=', $value['crowd_round_number']];
                ${'where' . ($key + 1)}[] = ['period_number', '=', $value['crowd_period_number']];
            }
            for ($i = 0; $i < count($dealPeriodList); $i++) {
                $allWhereOr[] = ${'where' . ($i + 1)};
            }
            $query->whereOr($allWhereOr);
        })->where($cWhere)->select()->toArray();
        $log['periodList'] = $periodList;

        if (empty($periodList)) {
            return $this->recordError($log, ['msg' => '无有效可执行期']);
        }

        foreach ($periodList as $key => $value) {
            $crowdKey = $value['activity_code'] . '-' . $value['round_number'] . '-' . $value['period_number'];
            $periodInfo[$value['activity_code']] = $value;
            $periodReturnScale[$value['activity_code']] = $value['fuse_second_once_return_scale'] ?? '0.02';
        }

        //查看本期的购买用户
        $gWhere[] = ['pay_status', '=', 2];
        $gWhere[] = ['status', '=', 1];
        $gWhere[] = ['after_status', 'in', [1, -1]];

        $orderGoods = OrderGoods::with(['orderInfo'])->where(function ($query) use ($dealPeriodList) {
            foreach ($dealPeriodList as $key => $value) {
                ${'where' . ($key + 1)}[] = ['crowd_code', '=', $value['crowd_code']];
                ${'where' . ($key + 1)}[] = ['crowd_round_number', '=', $value['crowd_round_number']];
                ${'where' . ($key + 1)}[] = ['crowd_period_number', '=', $value['crowd_period_number']];
            }
            for ($i = 0; $i < count($dealPeriodList); $i++) {
                $allWhereOr[] = ${'where' . ($i + 1)};
            }
            $query->whereOr($allWhereOr);
        })->where($gWhere)->select()->toArray();

        $log['orderGoods'] = $orderGoods;

        if (empty($orderGoods)) {
            return $this->recordError($log, ['msg' => '无有效可执行订单']);
        }

        //计算每个人每区剩下多少可返还金额
        foreach ($fuseRecordList as $key => $value) {
            if(doubleval($value['last_total_price']) > 0){
                if(!isset($userRecord[$value['uid']][$value['crowd_code']])){
                    $userRecord[$value['uid']][$value['crowd_code']] = 0;
                }
                $userRecord[$value['uid']][$value['crowd_code']] += $value['last_total_price'];
                $userRecordList[$value['uid']][] = $value;
            }
        }

        //计算每个人本次购买的订单总金额, 并计算出根据当前期允许分期返回比例实际能返多少钱给用户
        $userOrderPrice = [];
        foreach ($orderGoods as $key => $value) {
            if(doubleval($value['real_pay_price'] - $value['total_fare_price']) > 0){
                if(!isset($userOrderPrice[$value['orderInfo']['uid']][$value['crowd_code']])){
                    $userOrderPrice[$value['orderInfo']['uid']] = [];
                    $userOrderPrice[$value['orderInfo']['uid']][$value['crowd_code']] = 0;
                }

                $userOrderPrice[$value['orderInfo']['uid']][$value['crowd_code']] += ($value['real_pay_price'] - $value['total_fare_price']);
            }
        }

        if (!empty($userOrderPrice ?? [])) {
            foreach ($userOrderPrice as $key => $value) {
                foreach ($value as $cKey => $cValue) {
                    if (!isset($userPrice[$key])) {
                        $userPrice[$key][$cKey] = 0;
                    }
                    if (!empty($userRecordList[$key] ?? 0)) {
                        $userPrice[$key][$cKey] += priceFormat($cValue * $periodReturnScale[$cKey]);
                    }
                }
            }
        }

        $userRealPrice = $userPrice;
//        if(!empty($userRecord)){
//            foreach ($userRecord as $key => $value) {
//                foreach ($value as $cKey => $cValue) {
//                    if (doubleval($cValue) > 0) {
//                        if ((string)$userPrice[$key][$cKey] <= (string)$cValue) {
//                            $userRealPrice[$key][$cKey] = priceFormat($userPrice[$key][$cKey]);
//                        } else {
//                            $userRealPrice[$key][$cKey] = priceFormat($userPrice[$key][$cKey] - $cValue);
//                        }
//                    }
//                }
//            }
//        }
//        dump($userRecordList);
//        dump($userRealPrice);die;

        $DBRes = Db::transaction(function() use ($userRecordList,$userRealPrice,$dealPeriodList){
            $number = 0;
            foreach ($userRecordList as $key => $value) {
                $isGrantSuccess = false;
                foreach ($value as $cKey => $cValue) {
                    $isGrantSuccess = false;
                    if (!empty($userRealPrice[$cValue['uid']][$cValue['crowd_code']] ?? null)) {
                        if (!isset($userAllDetail[$cValue['uid']])) {
                            $userAllDetail[$cValue['uid']] = 0;
                        }

                        $userRealPrice[$cValue['uid']][$cValue['crowd_code']] -= $userAllDetail[$cValue['uid']];

                        $finallyDetail[$number]['uid'] = $cValue['uid'];
                        if ((string)$userRealPrice[$cValue['uid']][$cValue['crowd_code']] >= $cValue['last_total_price']) {
                            $isGrantSuccess = true;
                            $finallyDetail[$number]['price'] = priceFormat($cValue['last_total_price']);
                        } else {
                            if ((string)$userRealPrice[$cValue['uid']][$cValue['crowd_code']] < 0) {
                                $isGrantSuccess = true;
                                $finallyDetail[$number]['price'] = priceFormat($cValue['last_total_price']);
                            } else {
                                $finallyDetail[$number]['price'] = priceFormat($userRealPrice[$cValue['uid']][$cValue['crowd_code']]);
                            }
                        }

                        if((string)$finallyDetail[$number]['price'] <= 0){
                            unset($finallyDetail[$number]);
                            continue;
                        }
                        $finallyDetail[$number]['order_sn'] = $cValue['order_sn'];
                        $finallyDetail[$number]['crowd_code'] = $cValue['crowd_code'];
                        $finallyDetail[$number]['crowd_round_number'] = $cValue['crowd_round_number'];
                        $finallyDetail[$number]['crowd_period_number'] = $cValue['crowd_period_number'];
                        $finallyDetail[$number]['from_crowd_code'] = $dealPeriodList[0]['crowd_code'];
                        $finallyDetail[$number]['from_crowd_round_number'] = $dealPeriodList[0]['crowd_round_number'];
                        $finallyDetail[$number]['from_crowd_period_number'] = $dealPeriodList[0]['crowd_period_number'];
                        $finallyDetail[$number]['arrival_status'] = 2;

                        CrowdfundingFuseRecord::where(['id' => $cValue['id'], 'crowd_code' => $cValue['crowd_code'], 'uid' => $cValue['uid']])->dec('last_total_price', $finallyDetail[$number]['price'])->update();

                        $userAllDetail[$cValue['uid']] += $finallyDetail[$number]['price'];
                        if (!empty($isGrantSuccess ?? false)) {
                            $successId[] = $cValue['id'];
                        } else {
                            $ingId[] = $cValue['id'];
                        }
                        $number += 1;
                    }
                }
            }
            if(!empty($userAllDetail)){
                (new CrowdfundingFuseRecordDetail())->saveAll(array_values($finallyDetail));
            }
            if (!empty($successId ?? [])) {
                CrowdfundingFuseRecord::update(['grant_status' => 1], ['id' => array_unique($successId), 'grant_status' => [2, 3]]);
            }
            if (!empty($ingId ?? [])) {
                CrowdfundingFuseRecord::update(['grant_status' => 2], ['id' => array_unique($ingId), 'grant_status' => [3]]);
            }
            return $userAllDetail ?? [];
        });
        return judge($DBRes ?? []);
    }

    /**
     * @title  生成用户分期需要返还的熔断金额<冻结状态><所有区都可以>
     * @param array $data
     * @return bool
     * @throws \Exception
     */
    public function crowdFusePlanDivide(array $data)
    {
        $dealPeriodList = $data['periodList'];
        if (empty($dealPeriodList)) {
            return $this->recordError($data, ['msg' => '无有效期']);
        }
        if (count($dealPeriodList) != 1) {
            return $this->recordError($data, ['msg' => '单次仅允许执行一个期']);
        }
        if (!empty(cache('crowdFusePlanDealIng'))) {
            //生成冻结中的历史熔断分期返回美丽金
            Queue::later(180, 'app\lib\job\CrowdFunding', ['dealType' => 7, 'periodList' => [0 => ['crowd_code' => $dealPeriodList[0]['crowd_code'], 'crowd_round_number' => $dealPeriodList[0]['crowd_round_number'], 'crowd_period_number' => $dealPeriodList[0]['crowd_period_number']]]], config('system.queueAbbr') . 'CrowdFunding');

            return $this->recordError($data, ['msg' => '正在处理其他期的冻结中熔断金额反还，将延期至稍后重试']);
        }
        cache('crowdFusePlanDealIng',$data,180);

        $cMap[] = ['from_crowd_code', '=', $dealPeriodList[0]['crowd_code']];
        $cMap[] = ['from_crowd_round_number', '=', $dealPeriodList[0]['crowd_round_number']];
        $cMap[] = ['from_crowd_period_number', '=', $dealPeriodList[0]['crowd_period_number']];
        $cMap[] = ['status', '=', 1];
        $checkExist = CrowdfundingFuseRecordDetail::where($cMap)->count();

        $log['requestData'] = $data;
        $log['checkExist'] = $checkExist;
        if (!empty($checkExist)) {
            return $this->recordError($log, ['msg' => '该期已存在有效的分期反还记录，本次不处理']);
        }

        //查看是否有该期有效的还有剩余金额可分期的记录
        $pWhere[] = ['status', '=', 1];
        $pWhere[] = ['grant_status', 'in', [2,3]];
        $pWhere[] = ['last_total_price', '>', 0];
//        $fuseRecordList = CrowdfundingFuseRecord::withSum('detail','price')->where($pWhere)->select()->toArray();
        $orderCrowdKey = $dealPeriodList[0]['crowd_code'].'-'.$dealPeriodList[0]['crowd_round_number'].'-'.$dealPeriodList[0]['crowd_period_number'];
        $fuseRecordList = Db::query("( SELECT *,(SELECT SUM(`price`) AS think_sum FROM `sp_crowdfunding_fuse_record_detail` `sum_table` WHERE  `status` = 1  AND ( `sum_table`.`order_sn` =sp_crowdfunding_fuse_record.order_sn )) AS `detail_sum` FROM `sp_crowdfunding_fuse_record` WHERE  `status` = 1  AND `grant_status` IN (2,3)  AND `last_total_price` > '0' and uid in ( SELECT DISTINCT `uid` FROM `sp_order` WHERE  `crowd_key` = '".$orderCrowdKey."' ) ) ");
        $log['fuseRecordList'] = $fuseRecordList;

        if (empty($fuseRecordList)) {
            return $this->recordError($log, ['msg' => '无有效可发放记录']);
        }
        //剔除已经发放完毕的但是主记录存在异常的数据或者是剩余发放金额大于总金额的异常数据
        foreach ($fuseRecordList as $key => $value) {
            if (!empty(doubleval($value['detail_sum'] ?? 0)) && (string)$value['detail_sum'] >= (string)$value['total_price']) {
                unset($fuseRecordList[$key]);
            }
            if ((string)$value['last_total_price'] > (string)$value['total_price']) {
                unset($fuseRecordList[$key]);
            }
        }

        if (empty($fuseRecordList)) {
            return $this->recordError($log, ['msg' => '剔除数据后无有效可发放记录']);
        }

        //必须为认购满待成功状态的才可以进入计算
        $cWhere[] = ['status', '=', 1];
        $cWhere[] = ['buy_status', '=', 1];
        $cWhere[] = ['result_status', 'in', [2]];
        $periodList = CrowdfundingPeriod::where(function ($query) use ($dealPeriodList) {
            foreach ($dealPeriodList as $key => $value) {
                ${'where' . ($key + 1)}[] = ['activity_code', '=', $value['crowd_code']];
                ${'where' . ($key + 1)}[] = ['round_number', '=', $value['crowd_round_number']];
                ${'where' . ($key + 1)}[] = ['period_number', '=', $value['crowd_period_number']];
            }
            for ($i = 0; $i < count($dealPeriodList); $i++) {
                $allWhereOr[] = ${'where' . ($i + 1)};
            }
            $query->whereOr($allWhereOr);
        })->where($cWhere)->select()->toArray();
        $log['periodList'] = $periodList;

        if (empty($periodList)) {
            return $this->recordError($log, ['msg' => '无有效可执行期']);
        }

        foreach ($periodList as $key => $value) {
            $crowdKey = $value['activity_code'] . '-' . $value['round_number'] . '-' . $value['period_number'];
            $periodInfo[$value['activity_code']] = $value;
            $periodReturnScale[$value['activity_code']] = $value['fuse_second_once_return_scale'] ?? '0.02';
        }

        //查看本期的购买用户
        $gWhere[] = ['pay_status', '=', 2];
        $gWhere[] = ['status', '=', 1];
        $gWhere[] = ['after_status', 'in', [1, -1]];

        $orderGoods = OrderGoods::with(['orderInfo'=>(function($query){
            $query->field('order_sn,uid,order_type,order_belong,crowd_key');
        })])->field('order_sn,count,price,total_price,real_pay_price,total_fare_price,crowd_code,crowd_round_number,crowd_period_number')->withCount(['fuseDetail'=>'fuse_count'])->where(function ($query) use ($dealPeriodList) {
            foreach ($dealPeriodList as $key => $value) {
                ${'where' . ($key + 1)}[] = ['crowd_code', '=', $value['crowd_code']];
                ${'where' . ($key + 1)}[] = ['crowd_round_number', '=', $value['crowd_round_number']];
                ${'where' . ($key + 1)}[] = ['crowd_period_number', '=', $value['crowd_period_number']];
            }
            for ($i = 0; $i < count($dealPeriodList); $i++) {
                $allWhereOr[] = ${'where' . ($i + 1)};
            }
            $query->whereOr($allWhereOr);
        })->where($gWhere)->select()->toArray();

        $log['orderGoods'] = $orderGoods;

        if (empty($orderGoods)) {
            return $this->recordError($log, ['msg' => '无有效可执行订单']);
        }

        //计算每个人每区剩下多少可返还金额
        foreach ($fuseRecordList as $key => $value) {
            if(doubleval($value['last_total_price']) > 0){
                if(!isset($userRecord[$value['uid']][$value['crowd_code']])){
                    $userRecord[$value['uid']][$value['crowd_code']] = 0;
                }
                $userRecord[$value['uid']][$value['crowd_code']] += $value['last_total_price'];
                $userRecordList[$value['uid']][] = $value;
            }
        }

        //计算每个人本次购买的订单总金额, 并计算出根据当前期允许分期返回比例实际能返多少钱给用户
        $userOrderPrice = [];
        $existOtherCrowd = [];
        foreach ($orderGoods as $key => $value) {
            if(doubleval($value['real_pay_price'] - $value['total_fare_price']) > 0){
                if(!isset($userOrderPrice[$value['orderInfo']['uid']])){
                    $userOrderPrice[$value['orderInfo']['uid']] = [];
//                    $userOrderPrice[$value['orderInfo']['uid']][$value['crowd_code']] = 0;
                    $userOrderPrice[$value['orderInfo']['uid']] = 0;
                }

//                $userOrderPrice[$value['orderInfo']['uid']][$value['crowd_code']] += ($value['real_pay_price'] - $value['total_fare_price']);
                $userOrderPrice[$value['orderInfo']['uid']] += ($value['real_pay_price'] - $value['total_fare_price']);
            }
        }


        if (!empty($userOrderPrice ?? [])) {
            foreach ($userOrderPrice as $key => $value) {
                if (!isset($userPrice[$key])) {
                    $userPrice[$key] = 0;
                }
                if (!empty($userRecordList[$key] ?? 0)) {
                    $userPrice[$key] += priceFormat($value * '0.02');
                }
            }
        }

//        if (!empty($userOrderPrice ?? [])) {
//            foreach ($userOrderPrice as $key => $value) {
//                foreach ($value as $cKey => $cValue) {
//                    if (!isset($userPrice[$key])) {
//                        $userPrice[$key][$cKey] = 0;
//                    }
//                    if (!empty($userRecordList[$key] ?? 0)) {
//                        $userPrice[$key][$cKey] += priceFormat($cValue * $periodReturnScale[$cKey]);
//                    }
//                }
//            }
//        }

        $userRealPrice = $userPrice;
//        if(!empty($userRecord)){
//            foreach ($userRecord as $key => $value) {
//                foreach ($value as $cKey => $cValue) {
//                    if (doubleval($cValue) > 0) {
//                        if ((string)$userPrice[$key][$cKey] <= (string)$cValue) {
//                            $userRealPrice[$key][$cKey] = priceFormat($userPrice[$key][$cKey]);
//                        } else {
//                            $userRealPrice[$key][$cKey] = priceFormat($userPrice[$key][$cKey] - $cValue);
//                        }
//                    }
//                }
//            }
//        }
//        dump($userRecordList);
//        dump($userRealPrice);die;

        $DBRes = Db::transaction(function() use ($userRecordList,$userRealPrice,$dealPeriodList){
            $number = 0;

            foreach ($userRecordList as $key => $value) {
                $isGrantSuccess = false;
                foreach ($value as $cKey => $cValue) {
                    $isGrantSuccess = false;
//                    if (!empty($userRealPrice[$cValue['uid']][$cValue['crowd_code']] ?? null)) {
                    if (!empty($userRealPrice[$cValue['uid']] ?? null)) {
                        if (!isset($userAllDetail[$cValue['uid']])) {
                            $userAllDetail[$cValue['uid']] = 0;
                        }

//                        $userRealPrice[$cValue['uid']][$cValue['crowd_code']] -= $userAllDetail[$cValue['uid']];
                        $userRealPrice[$cValue['uid']] -= $userAllDetail[$cValue['uid']];

                        $finallyDetail[$number]['uid'] = $cValue['uid'];
//                        if ((string)$userRealPrice[$cValue['uid']][$cValue['crowd_code']] >= $cValue['last_total_price']) {
                        if ((string)$userRealPrice[$cValue['uid']] >= $cValue['last_total_price']) {
                            $isGrantSuccess = true;
                            $finallyDetail[$number]['price'] = priceFormat($cValue['last_total_price']);
                        } else {
//                            if ((string)$userRealPrice[$cValue['uid']][$cValue['crowd_code']] < 0) {
//                            if ((string)$userRealPrice[$cValue['uid']] < 0) {
//                                $isGrantSuccess = true;
//                                $finallyDetail[$number]['price'] = priceFormat($cValue['last_total_price']);
//                            }
                            //如果已经是负数了则结束了本次计算
                            if ((string)$userRealPrice[$cValue['uid']] < 0) {
//                                $isGrantSuccess = true;
                                $finallyDetail[$number]['price'] = priceFormat($cValue['last_total_price']);
                                unset($finallyDetail[$number]);
                                break;
                            } else {
//                                $finallyDetail[$number]['price'] = priceFormat($userRealPrice[$cValue['uid']][$cValue['crowd_code']]);
                                $finallyDetail[$number]['price'] = priceFormat($userRealPrice[$cValue['uid']]);
                            }
                        }

                        if((string)$finallyDetail[$number]['price'] <= 0){
                            unset($finallyDetail[$number]);
                            continue;
                        }
                        $finallyDetail[$number]['order_sn'] = $cValue['order_sn'];
                        $finallyDetail[$number]['crowd_code'] = $cValue['crowd_code'];
                        $finallyDetail[$number]['crowd_round_number'] = $cValue['crowd_round_number'];
                        $finallyDetail[$number]['crowd_period_number'] = $cValue['crowd_period_number'];
                        $finallyDetail[$number]['from_crowd_code'] = $dealPeriodList[0]['crowd_code'];
                        $finallyDetail[$number]['from_crowd_round_number'] = $dealPeriodList[0]['crowd_round_number'];
                        $finallyDetail[$number]['from_crowd_period_number'] = $dealPeriodList[0]['crowd_period_number'];
                        $finallyDetail[$number]['arrival_status'] = 2;

                        CrowdfundingFuseRecord::where(['id' => $cValue['id'], 'crowd_code' => $cValue['crowd_code'], 'uid' => $cValue['uid']])->dec('last_total_price', $finallyDetail[$number]['price'])->update();

                        $userAllDetail[$cValue['uid']] += $finallyDetail[$number]['price'];
                        if (!empty($isGrantSuccess ?? false)) {
                            $successId[] = $cValue['id'];
                        } else {
                            $ingId[] = $cValue['id'];
                        }
                        $number += 1;
                    }
                }
            }
//            dump($userAllDetail ?? []);
            if(!empty($userAllDetail)){
                (new CrowdfundingFuseRecordDetail())->saveAll(array_values($finallyDetail));
            }
            if (!empty($successId ?? [])) {
                CrowdfundingFuseRecord::update(['grant_status' => 1], ['id' => array_unique($successId), 'grant_status' => [2, 3]]);
            }
            if (!empty($ingId ?? [])) {
                CrowdfundingFuseRecord::update(['grant_status' => 2], ['id' => array_unique($ingId), 'grant_status' => [3]]);
            }
            return $userAllDetail ?? [];
        });
        cache('crowdFusePlanDealIng',null);
        return judge($DBRes ?? []);
    }

    /**
     * @title  释放冻结的熔断分期返回记录
     * @param array $data
     * @throws \Exception
     * @return mixed
     */
    public function releaseFuseRecordDetail(array $data)
    {
        $dealPeriodList = $data['periodList'];
        if (empty($dealPeriodList)) {
            return $this->recordError($data, ['msg' => '无有效期']);
        }
        if (count($dealPeriodList) != 1) {
            return $this->recordError($data, ['msg' => '单次仅允许执行一个期']);
        }
        $log['data'] = $data;
        //查看是否有该期有效的还有剩余金额可分期的记录
        $pWhere[] = ['status', '=', 1];
        $pWhere[] = ['arrival_status', 'in', [2]];
        $fuseRecordList = CrowdfundingFuseRecordDetail::where(function ($query) use ($dealPeriodList) {
            foreach ($dealPeriodList as $key => $value) {
                ${'where' . ($key + 1)}[] = ['from_crowd_code', '=', $value['crowd_code']];
                ${'where' . ($key + 1)}[] = ['from_crowd_round_number', '=', $value['crowd_round_number']];
                ${'where' . ($key + 1)}[] = ['from_crowd_period_number', '=', $value['crowd_period_number']];
            }
            for ($i = 0; $i < count($dealPeriodList); $i++) {
                $allWhereOr[] = ${'where' . ($i + 1)};
            }
            $query->whereOr($allWhereOr);
        })->where($pWhere)->select()->toArray();

        $log['fuseRecordList'] = $fuseRecordList;
        if (empty($fuseRecordList)) {
            return $this->recordError($log, ['msg' => '无有效可发放记录']);
        }

        $DBRes = Db::transaction(function () use ($fuseRecordList) {
            foreach ($fuseRecordList as $key => $value) {
                $crowdKey = $value['crowd_code'] . '-' . $value['crowd_round_number'] . '-' . $value['crowd_period_number'];
                if ($value['price'] > 0 && $value['arrival_status'] == 2) {
                    //余额明细
                    $balance[$key]['order_sn'] = $value['order_sn'];
                    $balance[$key]['price'] = $value['price'];
                    $balance[$key]['type'] = 1;
                    $balance[$key]['uid'] = $value['uid'];
                    $balance[$key]['change_type'] = 11;
                    $balance[$key]['crowd_code'] = $value['crowd_code'];
                    $balance[$key]['crowd_round_number'] = $value['crowd_round_number'];
                    $balance[$key]['crowd_period_number'] = $value['crowd_period_number'];
                    $balance[$key]['remark'] = '福利活动熔断分期返美丽金(' . $crowdKey . ')';

                    //放款到用户账户
                    $refundRes[$key] = User::where(['uid' => $value['uid']])->inc('crowd_balance', $balance[$key]['price'])->update();
                    $updateStatus[] = $value['id'];
                }
            }

            //添加用户的余额明细
            if (!empty($balance ?? [])) {
                (new CrowdfundingBalanceDetail())->saveAll($balance);
            }

            //修改记录为已发放
            if (!empty($updateStatus ?? [])) {
                CrowdfundingFuseRecordDetail::update(['arrival_status' => 1, 'arrival_time' => time()], ['id' => $updateStatus, 'status' => 1, 'arrival_status' => 2]);
            }
            return $balance ?? [];
        });

        return judge($DBRes);
    }

    /**
     * @title 执行发放众筹活动已完成但延迟释放本/奖金的订单
     * @param array $data
     * @return array|bool
     * @throws \Exception
     */
    public function delayRewardOrderCanRelease(array $data= [])
    {
        //执行类型 1为执行 2为查询
        $searType = $data['searType'] ?? 1;
        $dealCacheKey = 'dealDelayRewardOrder';
        //加上处理缓存锁,防止并发
        if ($searType == 1) {
            if (!empty(cache($dealCacheKey))) {
                return ['res' => true, 'msg' => '正在执行中, 请勿重复处理'];
            }
            cache($dealCacheKey, 1, 300);
        }

        $redisService = Cache::store('redis')->handler();
        $orderList = $redisService->lrange($this->delayRewardOrderCacheKey, 0, -1);
        if (empty($orderList)) {
            cache($dealCacheKey, null);
            return ['res' => true, 'msg' => '无延迟发放奖励订单'];
        }

        $dealOrderInfo = [];
        foreach ($orderList as $key => $value) {
            $orderInfo = explode('-', $value);
            if ($orderInfo[0] <= time()) {
                $dealOrderInfo[$orderInfo[1]] = $orderInfo[0];
            }
        }
        unset($orderList);
        if (empty($dealOrderInfo ?? [])) {
            cache($dealCacheKey, null);

            //释放变量内存
            unset($orderList);
            return ['res' => true, 'msg' => '无到期可执行订单'];
        }

        $dealOrderSn = array_unique(array_keys($dealOrderInfo));

        if (empty($dealOrderSn ?? [])) {
            cache($dealCacheKey, null);
            //释放变量内存
            unset($orderList);
            return ['res' => true, 'msg' => '无到期可执行订单'];
        }

        //重新校验此批订单是否为可发放奖励订单
        $cdrWhere[] = ['order_sn', 'in', $dealOrderSn];
        $cdrWhere[] = ['arrival_status', '=', 3];
        $cdrWhere[] = ['arrival_time', '<=', time()];
        $cdrWhere[] = ['status', '=', 1];
        $canDealOrderSn = CrowdfundingDelayRewardOrder::where($cdrWhere)->column('order_sn');
        if (empty($canDealOrderSn)) {
            cache($dealCacheKey, null);

            //释放变量内存
            unset($orderList);
            unset($dealOrderSn);
            return ['res' => true, 'msg' => '查无有效可执行订单'];
        }

        $canDealOrderSn = array_unique($canDealOrderSn);

        //查询实际分润订单
        $dWhere[] = ['order_sn', 'in', $canDealOrderSn];
        $dWhere[] = ['type', 'in', [8, 5, 7]];
        $dWhere[] = ['arrival_status', '=', 2];
        $dWhere[] = ['status', '=', 1];
        $dWhere[] = ['', 'exp', Db::raw('crowd_code is not null and crowd_round_number is not null and crowd_period_number is not null')];
        $divideList = Divide::where($dWhere)->field('id,order_sn,order_uid,link_uid,type,goods_sn,sku_sn,price,count,total_price,divide_type,purchase_price,divide_price,dis_reduce_price,refund_reduce_price,real_divide_price,arrival_status,status,create_time,is_grateful,crowd_code,crowd_round_number,crowd_period_number,is_exp,is_device,device_sn,is_allot,allot_scale,device_divide_type,team_shareholder_level,level')->select()->toArray();

        if (empty($divideList ?? [])) {
            cache($dealCacheKey, null);
            //释放变量内存
            unset($orderList);
            return ['res' => true, 'msg' => '实际无可执行订单'];
        }

        $log['msg'] = '发放众筹订单延时奖励订单';
        $successPeriod = [];
        $notSuccessPeriod = [];
        $periodList = [];
        $periodInfo = [];
        foreach ($divideList as $key => $value) {
            $crowdKey = $value['crowd_code'] . '-' . $value['crowd_round_number'] . '-' . $value['crowd_period_number'];
            $successPeriod[$crowdKey]['activity_code'] = $value['crowd_code'];
            $successPeriod[$crowdKey]['round_number'] = $value['crowd_round_number'];
            $successPeriod[$crowdKey]['period_number'] = $value['crowd_period_number'];
        }
        if (!empty($successPeriod ?? [])) {
            $successPeriod = array_values($successPeriod);
            $oWhere[] = ['status', '=', 1];
            $oWhere[] = ['result_status', '=', 1];
            $periodList = CrowdfundingPeriod::where(function ($query) use ($successPeriod) {
                $successPeriod = array_values($successPeriod);
                foreach ($successPeriod as $key => $value) {
                    ${'where' . ($key + 1)}[] = ['activity_code', '=', $value['activity_code']];
                    ${'where' . ($key + 1)}[] = ['round_number', '=', $value['round_number']];
                    ${'where' . ($key + 1)}[] = ['period_number', '=', $value['period_number']];
                }
                for ($i = 0; $i < count($successPeriod); $i++) {
                    $allWhereOr[] = ${'where' . ($i + 1)};
                }
                $query->whereOr($allWhereOr);
            })->where($oWhere)->select()->toArray();
            if (!empty($periodList ?? [])) {
                foreach ($periodList as $key => $value) {
                    $crowdKey = $value['activity_code'] . '-' . $value['round_number'] . '-' . $value['period_number'];
                    $periodInfo[$crowdKey] = $value;
                }
                //剔除没有完全成功的期的分润记录
                foreach ($divideList as $key => $value) {
                    $crowdKey = $value['crowd_code'] . '-' . $value['crowd_round_number'] . '-' . $value['crowd_period_number'];
                    if (empty($periodInfo[$crowdKey] ?? null)) {
                        unset($divideList[$key]);
                    }
                }
            } else {
                cache($dealCacheKey, null);
                //释放变量内存
                unset($orderList);
                unset($divideList);
                return ['res' => false, 'msg' => '期没有真的完成,不允许发放奖励'];
            }
        }

        if (empty($divideList ?? [])) {
            cache($dealCacheKey, null);
            //释放变量内存
            unset($orderList);
            return ['res' => true, 'msg' => '实际无可执行订单'];
        }


        $divideOrderSn = array_unique(array_column($divideList ?? [], 'order_sn'));
        //判断是否有漏修改状态的冻结释放记录
        if (!empty($divideOrderSn ?? []) && count($divideOrderSn) != count($canDealOrderSn)) {
            foreach ($canDealOrderSn as $key => $value) {
                if(!in_array($value,$divideOrderSn)){
                    $notFoundOrder[] = $value;
                }
            }
            if (!empty($notFoundOrder ?? [])) {
                $rdWhere[] = ['order_sn', 'in', $notFoundOrder];
                $rdWhere[] = ['change_type', '=', 3];
                $rdWhere[] = ['status', '=', 1];
//                $rdWhere[] = ['', 'exp', Db::raw('order_uid = link_uid and type = 8 and is_grateful = 2 and arrival_status = 1 and status = 1')];
//                $realDivideList = Divide::where($rdWhere)->column('order_sn');
                $realDivideList = CrowdfundingBalanceDetail::where($rdWhere)->column('create_time', 'order_sn');
//                if ($value['order_uid'] == $value['link_uid'] && $value['type'] == 8 && $value['is_grateful'] == 2) {
                //如果查询出来的待发放本/奖金的订单总数不等于分润查询出来的总订单, 有可能是因为直接发放了奖励, 所以需要将已经发放奖励分润并且延迟发放标准红状态为待发放的订单修改为已发放
                if (!empty($realDivideList ?? [])) {
                    $drWhere[] = ['order_sn', 'in', array_keys($realDivideList)];
                    $drWhere[] = ['arrival_time', '<=', time()];
                    $drWhere[] = ['arrival_status', '=', 3];
                    $drWhere[] = ['status', '=', 1];
                    $delayOrderList = CrowdfundingDelayRewardOrder::where($drWhere)->column('order_sn');
                    if (!empty($delayOrderList ?? [])) {
                        foreach ($delayOrderList as $key => $value) {
                            $updateDataList[$key]['order_sn'] = $value;
                            $updateDataList[$key]['arrival_status'] = 1;
                            $updateDataList[$key]['real_arrival_time'] = $realDivideList[$value] ?? time();
                        }
                        $updateData['list'] = array_values($updateDataList);
                        $updateData['id_field'] = 'order_sn';
                        $updateData['db_name'] = 'sp_crowdfunding_delay_reward_order';
                        $updateData['auto_fill_time'] = true;
                        $updateData['other_map'] = 'arrival_status = 3 and status = 1';
                        (new CommonModel())->DBUpdateAllAboutUniqueId($updateData);
                    }
                }
            }
        }



        if ($searType == 2) {
            cache($dealCacheKey, null);
            //释放变量内存
            unset($orderList);
            unset($divideList);
            return ['res' => false, 'msg' => '仅查询不执行发放逻辑', 'dealOrderSn' => $dealOrderSn ?? [], 'dealOrderInfo' => $dealOrderInfo ?? [], 'divideList' => $divideList ?? []];
        }

        $DBRes = Db::transaction(function () use ($periodList, $divideList, $periodInfo, $log, $notSuccessPeriod, $dWhere, $redisService, $dealOrderInfo) {
            $allUid = array_unique(array_column($divideList, 'link_uid'));
            //加锁防止其他操作打断
//            $userId = (new User())->where(['uid' => $allUid])->value('id');
//            (new User())->where(['id' => $userId])->lock(true)->value('uid');
            foreach ($allUid as $key => $value) {
                cache('canNotOperBalance-' . $value, 1, 300);
            }

            $allUidSql = "('" . implode("','", $allUid) . "')";
            $updateUserCrowdBalanceSql = 'update sp_user set crowd_balance = CASE uid ';
//            $updateUserIntegralSql = 'update sp_user set integral = CASE uid ';
            $updateUserTeamBalanceSql = 'update sp_user set team_balance = CASE uid ';
            $updateUserAreaBalanceSql = 'update sp_user set area_balance = CASE uid ';
            $updateUserCrowdBalanceHeaderSql = 'update sp_user set crowd_balance = CASE uid ';
            $updateUserTeamBalanceHeaderSql = 'update sp_user set team_balance = CASE uid ';
            $updateUserAreaBalanceHeaderSql = 'update sp_user set area_balance = CASE uid ';

            $haveCrowdBalanceSql = false;
//            $haveIntegralSql = false;
            $haveTeamBalanceSql = false;
            $haveAreaBalanceSql = false;
            $crowdBalanceNumber = [];
//            $integralNumber  = [];
            $teamBalanceNumber = [];
            $areaBalanceNumber = [];
            $updateUserCrowdBalanceSqlMore = [];
            $updateUserIntegralSqlMore = [];
            $updateUserTeamBalanceSqlMore = [];
            $updateUserAreaBalanceSqlMore = [];
            $allSuccessOrderSn = [];

//            $userCrowdBalance = [];
//            $userIntegral = [];
            foreach ($divideList as $key => $value) {
                if (!isset($userCrowdBalance[$value['link_uid']])) {
                    $userCrowdBalance[$value['link_uid']] = 0;
                }
//                if(!isset($userIntegral[$value['link_uid']])){
//                    $userIntegral[$value['link_uid']] = 0;
//                }
                if (!isset($userTeamBalance[$value['link_uid']])) {
                    $userTeamBalance[$value['link_uid']] = 0;
                }
                if (!isset($userAreaBalance[$value['link_uid']])) {
                    $userAreaBalance[$value['link_uid']] = 0;
                }
                $crowdKey = $value['crowd_code'] . '-' . $value['crowd_round_number'] . '-' . $value['crowd_period_number'];
                //订单本人才可以反本金, 其他人得对应的奖金
                if ($value['order_uid'] == $value['link_uid'] && $value['type'] == 8 && $value['is_grateful'] == 2) {
                    //余额明细,本金
                    $aBalance[$key]['order_sn'] = $value['order_sn'];
                    //100%返本金
                    $aBalance[$key]['price'] = priceFormat($value['total_price'] * 1);
                    $aBalance[$key]['type'] = 1;
                    $aBalance[$key]['uid'] = $value['link_uid'];
                    $aBalance[$key]['change_type'] = 3;
                    $aBalance[$key]['crowd_code'] = $value['crowd_code'];
                    $aBalance[$key]['crowd_round_number'] = $value['crowd_round_number'];
                    $aBalance[$key]['crowd_period_number'] = $value['crowd_period_number'];
                    $aBalance[$key]['remark'] = '福利活动成功返本金(' . $crowdKey . ')';
                    if (doubleval($aBalance[$key]['price']) > 0) {
                        //退款到用户账户
//                        $refundRes[$key] = (new User())->where(['uid' => $value['link_uid']])->inc('crowd_balance', $aBalance[$key]['price'])->update();
                        $userCrowdBalance[$value['link_uid']] += $aBalance[$key]['price'];
                        $haveCrowdBalanceSql = true;
                        $crowdBalanceNumber[$value['link_uid']] = 1;
                    }
                    $allSuccessOrderSn[$value['order_sn']] = $value['order_sn'];
//
//                    //赠送同等数量的积分
//                    $aIntegral[$key]['order_sn'] = $value['order_sn'];
//                    //100%返本金
//                    $aIntegral[$key]['price'] = priceFormat($value['total_price'] * 1);
//                    $aIntegral[$key]['type'] = 1;
//                    $aIntegral[$key]['uid'] = $value['link_uid'];
//                    $aIntegral[$key]['change_type'] = 6;
//                    $aIntegral[$key]['crowd_code'] = $value['crowd_code'];
//                    $aIntegral[$key]['crowd_round_number'] = $value['crowd_round_number'];
//                    $aIntegral[$key]['crowd_period_number'] = $value['crowd_period_number'];
//                    $aIntegral[$key]['remark'] = '福利活动成功获得美丽豆(' . $crowdKey . ')';
//                    $aIntegral[$key]['goods_sn'] = $value['goods_sn'] ?? '';
//                    $aIntegral[$key]['sku_sn'] = $value['sku_sn'] ?? '';
//                    if (doubleval($aIntegral[$key]['price']) > 0) {
////                        $refundRes[$key] = (new User())->where(['uid' => $value['link_uid']])->inc('integral', $aIntegral[$key]['price'])->update();
//                        $userIntegral[$value['link_uid']] += $aIntegral[$key]['price'];
//                        $haveIntegralSql = true;
//                        $integralNumber[$value['link_uid']] = 1;
//                        $allSuccessOrderSn[$value['order_sn']] = $value['order_sn'];
//                    }

                    //修改对应的所有订单为可同步
//                    (new Order())->isAutoWriteTimestamp(false)->update(['can_sync' => 1], ['order_sn' => $value['order_sn']]);
                    $orderSync[] = $value['order_sn'];
                }

                //余额明细, 奖金
                if ($value['type'] == 5) {
                    //如果是团队业绩记录在原来的钱包里面
                    $uBalance[$key]['order_sn'] = $value['order_sn'];
                    $uBalance[$key]['belong'] = 1;
                    $uBalance[$key]['price'] = $value['real_divide_price'];
                    $uBalance[$key]['type'] = 1;
                    $uBalance[$key]['uid'] = $value['link_uid'];
                    $uBalance[$key]['change_type'] = 16;
                    $uBalance[$key]['remark'] = '福利活动成功团队奖奖金(' . $crowdKey . ')';
                    $uBalance[$key]['crowd_code'] = $value['crowd_code'];
                    $uBalance[$key]['crowd_round_number'] = $value['crowd_round_number'];
                    $uBalance[$key]['crowd_period_number'] = $value['crowd_period_number'];
                    if (!empty($value['is_exp'] ?? null) && $value['is_exp'] == 1) {
//                        if($value['level'] == 3){
                        if (!empty($value['team_shareholder_level'] ?? null) && intval($value['team_shareholder_level'] > 0)) {
                            $uBalance[$key]['remark'] = '福利活动成功团队股东奖奖金(' . $crowdKey . ')';
                        } else {
                            $uBalance[$key]['remark'] = '福利活动成功体验中心奖奖金(' . $crowdKey . ')';
                        }
                    }

                    if (doubleval($uBalance[$key]['price']) > 0) {
                        //发奖金到用户团队业绩账户
//                        $refundRes[$key] = (new User())->where(['uid' => $value['link_uid']])->inc('team_balance', $uBalance[$key]['price'])->update();
                        $haveTeamBalanceSql = true;
                        $userTeamBalance[$value['link_uid']] += $uBalance[$key]['price'];
                        $teamBalanceNumber[$value['link_uid']] = 1;

                    }
                } elseif ($value['type'] == 7) {
                    //如果是区代奖励记录在原来的钱包里面
                    $uBalance[$key]['order_sn'] = $value['order_sn'];
                    $uBalance[$key]['belong'] = 1;
                    $uBalance[$key]['price'] = $value['real_divide_price'];
                    $uBalance[$key]['type'] = 1;
                    $uBalance[$key]['uid'] = $value['link_uid'];
                    $uBalance[$key]['change_type'] = 20;
                    $uBalance[$key]['remark'] = '福利活动成功区代奖奖金(' . $crowdKey . ')';
                    $uBalance[$key]['crowd_code'] = $value['crowd_code'];
                    $uBalance[$key]['crowd_round_number'] = $value['crowd_round_number'];
                    $uBalance[$key]['crowd_period_number'] = $value['crowd_period_number'];
                    if (doubleval($uBalance[$key]['price']) > 0) {
                        //发奖金到用户团队业绩账户
//                        $refundRes[$key] = User::where(['uid' => $value['link_uid']])->inc('area_balance', $uBalance[$key]['price'])->update();
                        $haveAreaBalanceSql = true;
                        $userAreaBalance[$value['link_uid']] += $uBalance[$key]['price'];
                        $areaBalanceNumber[$value['link_uid']] = 1;

                    }
                } elseif ($value['type'] == 8) {
                    //如果是众筹记录在众筹的钱包里面
                    $balance[$key]['order_sn'] = $value['order_sn'];
                    $balance[$key]['price'] = $value['real_divide_price'];
                    $balance[$key]['type'] = 1;
                    $balance[$key]['uid'] = $value['link_uid'];
                    $balance[$key]['change_type'] = 4;
                    $balance[$key]['crowd_code'] = $value['crowd_code'];
                    $balance[$key]['crowd_round_number'] = $value['crowd_round_number'];
                    $balance[$key]['crowd_period_number'] = $value['crowd_period_number'];
                    $balance[$key]['remark'] = '福利活动成功奖金(' . $crowdKey . ')';
                    if ($value['is_grateful'] == 1) {
                        $balance[$key]['remark'] = '福利活动成功感恩奖奖金(' . $crowdKey . ')';
                        $balance[$key]['is_grateful'] = 1;
                    }
                    if (doubleval($balance[$key]['price']) > 0) {
                        //发奖金到用户账户
//                        $refundRes[$key] = (new User())->where(['uid' => $value['link_uid']])->inc('crowd_balance', $balance[$key]['price'])->update();
                        $userCrowdBalance[$value['link_uid']] += $balance[$key]['price'];
                        $haveCrowdBalanceSql = true;
                    }
                }
            }
            //计算，切割众筹部分的金额sql
            if (!empty($userCrowdBalance)) {
                foreach ($userCrowdBalance as $key => $value) {
                    if (doubleval($value) <= 0) {
                        unset($userCrowdBalance[$key]);
                    }
                }
                $crowdNumber = 0;
                foreach ($userCrowdBalance as $key => $value) {
                    if ($crowdNumber >= 500) {
                        if ($crowdNumber % 500 == 0) {
                            $updateUserCrowdBalanceHeaderSql = 'update sp_user set crowd_balance = CASE uid ';
                        }
                        $updateUserCrowdBalanceSqlMore[intval($crowdNumber / 500)] = $updateUserCrowdBalanceHeaderSql .= " WHEN '" . ($key ?? 'notfound') . "' THEN (crowd_balance + " . ($value ?? 0) . ")";
                        $updateUserCrowdBalanceSqlMoreUid[intval($crowdNumber / 500)][] = ($key ?? 'notfound');
                        unset($userCrowdBalance[$key]);
                    } else {
                        $updateUserCrowdBalanceSql .= " WHEN '" . ($key ?? 'notfound') . "' THEN (crowd_balance + " . ($value ?? 0) . ")";
                        unset($userCrowdBalance[$key]);
                    }
                    $crowdNumber += 1;
                }

                $updateUserCrowdBalanceSql .= ' ELSE (crowd_balance + 0) END WHERE uid in ' . $allUidSql;

                if (!empty($updateUserCrowdBalanceSqlMore ?? [])) {
                    foreach ($updateUserCrowdBalanceSqlMore as $key => $value) {
                        $updateUserCrowdBalanceSqlMore[$key] .= ' ELSE (crowd_balance + 0) END WHERE uid in ' . "('" . implode("','", $updateUserCrowdBalanceSqlMoreUid[$key]) . "')";;
                    }
                }
            }

            //计算，切割团队部分的sql
            $crowdNumber = 0;

            if (!empty($userTeamBalance)) {
                foreach ($userTeamBalance as $key => $value) {
                    if (doubleval($value) <= 0) {
                        unset($userTeamBalance[$key]);
                    }
                }
                $teamNumber = 0;

                foreach ($userTeamBalance as $key => $value) {
                    if ($teamNumber >= 500) {
                        if ($teamNumber % 500 == 0) {
                            $updateUserTeamBalanceHeaderSql = 'update sp_user set team_balance = CASE uid ';
                        }
                        $updateUserTeamBalanceSqlMore[intval($teamNumber / 500)] = $updateUserTeamBalanceHeaderSql .= " WHEN '" . ($key ?? 'notfound') . "' THEN (team_balance + " . ($value ?? 0) . ")";
                        $updateUserTeamBalanceSqlMoreUid[intval($teamNumber / 500)][] = $key;
                        unset($userTeamBalance[$key]);
                    } else {
                        $updateUserTeamBalanceSql .= " WHEN '" . ($key ?? 'notfound') . "' THEN (team_balance + " . ($value ?? 0) . ")";
                        unset($userTeamBalance[$key]);
                    }
                    $teamNumber += 1;
                }

                $updateUserTeamBalanceSql .= ' ELSE (team_balance + 0) END WHERE uid in ' . $allUidSql;
                if (!empty($updateUserTeamBalanceSqlMore ?? [])) {
                    foreach ($updateUserTeamBalanceSqlMore as $key => $value) {
                        $updateUserTeamBalanceSqlMore[$key] .= ' ELSE (team_balance + 0) END WHERE uid in ' . "('" . implode("','", $updateUserTeamBalanceSqlMoreUid[$key]) . "')";;
                    }
                }

            }
            //计算。切割区代的sql
            $crowdNumber = 0;
            $teamNumber = 0;

            if (!empty($userAreaBalance)) {
                foreach ($userAreaBalance as $key => $value) {
                    if (doubleval($value) <= 0) {
                        unset($userAreaBalance[$key]);
                    }
                }
                $areaNumber = 0;

                foreach ($userAreaBalance as $key => $value) {
                    if ($areaNumber >= 500) {
                        if ($areaNumber % 500 == 0) {
                            $updateUserAreaBalanceHeaderSql = 'update sp_user set area_balance = CASE uid ';
                        }
                        $updateUserAreaBalanceSqlMore[intval($areaNumber / 500)] = $updateUserAreaBalanceHeaderSql .= " WHEN '" . ($key ?? 'notfound') . "' THEN (area_balance + " . ($value ?? 0) . ")";
                        $updateUserAreaBalanceSqlMoreUid[intval($areaNumber / 500)][] = $key;
                        unset($userAreaBalance[$key]);
                    } else {
                        $updateUserAreaBalanceSql .= " WHEN '" . ($key ?? 'notfound') . "' THEN (area_balance + " . ($value ?? 0) . ")";
                        unset($userAreaBalance[$key]);
                    }
                    $areaNumber += 1;
                }

                $updateUserAreaBalanceSql .= ' ELSE (area_balance + 0) END WHERE uid in ' . $allUidSql;
                if (!empty($updateUserAreaBalanceSqlMore ?? [])) {
                    foreach ($updateUserAreaBalanceSqlMore as $key => $value) {
                        $updateUserAreaBalanceSqlMore[$key] .= ' ELSE (area_balance + 0) END WHERE uid in ' . "('" . implode("','", $updateUserAreaBalanceSqlMoreUid[$key]) . "')";;
                    }
                }

            }

            $log['updateUserCrowdBalanceSql'] = $updateUserCrowdBalanceSql;
            $log['updateUserCrowdBalanceSqlMore'] = $updateUserCrowdBalanceSqlMore ?? [];
//            $log['updateUserIntegralSql'] = $updateUserIntegralSql;
//            $log['updateUserIntegralSqlMore'] = $updateUserIntegralSqlMore ?? [];
            $log['updateUserTeamBalanceSql'] = $updateUserTeamBalanceSql;
            $log['updateUserTeamBalanceSqlMore'] = $updateUserTeamBalanceSqlMore ?? [];
            $log['updateUserAreaBalanceSql'] = $updateUserAreaBalanceSql;
            $log['updateUserAreaBalanceSqlMore'] = $updateUserAreaBalanceSqlMore ?? [];

            //对应的sql执行
            if (!empty($haveCrowdBalanceSql)) {
                Db::query($updateUserCrowdBalanceSql);
                if (!empty($updateUserCrowdBalanceSqlMore ?? [])) {
                    foreach ($updateUserCrowdBalanceSqlMore as $key => $value) {
                        Db::query($value);
                    }
                }
            }
//            if (!empty($haveIntegralSql)) {
//                Db::query($updateUserIntegralSql);
//                if(!empty($updateUserIntegralSqlMore ?? [])){
//                    foreach ($updateUserIntegralSqlMore as $key => $value) {
//                        Db::query($value);
//                    }
//                }
//            }
            if (!empty($haveTeamBalanceSql)) {
                Db::query($updateUserTeamBalanceSql);
                if (!empty($updateUserTeamBalanceSqlMore ?? [])) {
                    foreach ($updateUserTeamBalanceSqlMore as $key => $value) {
                        Db::query($value);
                    }
                }
            }
            if (!empty($haveAreaBalanceSql)) {
                Db::query($updateUserAreaBalanceSql);
                if (!empty($updateUserAreaBalanceSqlMore ?? [])) {
                    foreach ($updateUserAreaBalanceSqlMore as $key => $value) {
                        Db::query($value);
                    }
                }
            }

            //修改分润状态为到账
            $allDivideListId = array_unique(array_column($divideList, 'id'));
//            $log['allDivideListId'] = $haveAreaBalanceSql;

            if (!empty($allDivideListId)) {
                $divideListSql = Divide::field('id')->where($dWhere)->when(!empty($notSuccessPeriod ?? null), function ($query) use ($notSuccessPeriod) {
                    $notSuccessPeriod = array_values($notSuccessPeriod);
                    foreach ($notSuccessPeriod as $key => $value) {
                        ${'where' . ($key + 1)}[] = ['crowd_code', '<>', $value['crowd_code']];
                        ${'where' . ($key + 1)}[] = ['crowd_round_number', '<>', $value['crowd_round_number']];
                        ${'where' . ($key + 1)}[] = ['crowd_period_number', '<>', $value['crowd_period_number']];
                    }
                    for ($i = 0; $i < count($notSuccessPeriod); $i++) {
                        $allWhereOr[] = ${'where' . ($i + 1)};
                    }
                    $query->whereOr($allWhereOr);
                })->buildSql();
                $acWhere[] = ['', 'exp', Db::raw("id in (select id from $divideListSql a)")];
                $acWhere[] = ['arrival_status', '=', 2];
                $acWhere[] = ['status', '=', 1];
//                Divide::update(['arrival_status' => 1, 'arrival_time' => time()], ['id' => $allDivideListId, 'arrival_status' => 2, 'status' => 1]);
                Divide::update(['arrival_status' => 1, 'arrival_time' => time()], $acWhere);
            }

            if (!empty($aBalance ?? [])) {
                //拼接sql批量插入
                $chunkaBalance = array_chunk($aBalance, 500);
                foreach ($chunkaBalance as $key => $value) {
                    $sqls = '';
                    $itemStrs = '';
                    $sqls = sprintf("INSERT INTO sp_crowdfunding_balance_detail (uid, order_sn,pay_no,belong,type,price,change_type,status,remark,create_time,update_time,crowd_code,crowd_round_number,crowd_period_number,is_grateful) VALUES ");
                    $createTime = time();
                    foreach ($value as $items) {
                        $itemStrs = '( ';
                        $itemStrs .= ("'" . $items['uid'] . "'," . "'" . $items['order_sn'] . "'," . "''" . "," . ($items['belong'] ?? 1) . "," . $items['type'] . "," . $items['price'] . "," . $items['change_type'] . "," . "1" . "," . "'" . $items['remark'] . "'" . "," . $createTime . "," . $createTime . "," . "'" . $items['crowd_code'] . "'" . "," . "'" . $items['crowd_round_number'] . "'" . "," . "'" . $items['crowd_period_number'] . "'" . "," . ($items['is_grateful'] ?? 2));
                        $itemStrs .= '),';
                        $sqls .= $itemStrs;
                    }

                    // 去除最后一个逗号，并且加上结束分号
                    $sqls = rtrim($sqls, ',');
                    $sqls .= ';';
                    if (!empty($itemStrs ?? null) && !empty($sqls)) {
                        Db::query($sqls);
                    }
                }
//                (new CrowdfundingBalanceDetail())->saveAll(array_values($aBalance));
            }

            $sqls = null;
            if (!empty($balance ?? [])) {
                //拼接sql批量插入
                $chunkBalance = array_chunk($balance, 500);
                foreach ($chunkBalance as $key => $value) {
                    $bsqls = '';
                    $bitemStrs = '';
                    $bsqls = sprintf("INSERT INTO sp_crowdfunding_balance_detail (uid, order_sn,pay_no,belong,type,price,change_type,status,remark,create_time,update_time,crowd_code,crowd_round_number,crowd_period_number,is_grateful) VALUES ");
                    $createTime = time();
                    foreach ($value as $items) {
                        $bitemStrs = '( ';
                        $bitemStrs .= ("'" . $items['uid'] . "'," . "'" . $items['order_sn'] . "'," . "''" . "," . ($items['belong'] ?? 1) . "," . $items['type'] . "," . $items['price'] . "," . $items['change_type'] . "," . "1" . "," . "'" . $items['remark'] . "'" . "," . $createTime . "," . $createTime . "," . "'" . $items['crowd_code'] . "'" . "," . "'" . $items['crowd_round_number'] . "'" . "," . "'" . $items['crowd_period_number'] . "'" . "," . ($items['is_grateful'] ?? 2));
                        $bitemStrs .= '),';
                        $bsqls .= $bitemStrs;
                    }

                    // 去除最后一个逗号，并且加上结束分号
                    $bsqls = rtrim($bsqls, ',');
                    $bsqls .= ';';
                    if (!empty($bitemStrs ?? null) && !empty($bsqls)) {
                        Db::query($bsqls);
                    }
                }
//                (new CrowdfundingBalanceDetail())->saveAll(array_values($balance));
            }

            if (!empty($uBalance ?? [])) {
                //拼接sql批量插入
                $chunkuBalance = array_chunk($uBalance, 500);
                foreach ($chunkuBalance as $key => $value) {
                    $usqls = '';
                    $uitemStrs = '';
                    $usqls = sprintf("INSERT INTO sp_balance_detail (uid, order_sn,belong,type,price,change_type,status,remark,create_time,update_time) VALUES ");
                    $createTime = time();
                    foreach ($value as $items) {
                        $uitemStrs = '( ';
                        $uitemStrs .= ("'" . $items['uid'] . "'," . "'" . $items['order_sn'] . "'," . ($items['belong'] ?? 1) . "," . $items['type'] . "," . $items['price'] . "," . $items['change_type'] . "," . "1" . "," . "'" . $items['remark'] . "'" . "," . $createTime . "," . $createTime);
                        $uitemStrs .= '),';
                        $usqls .= $uitemStrs;
                    }

                    // 去除最后一个逗号，并且加上结束分号
                    $usqls = rtrim($usqls, ',');
                    $usqls .= ';';
                    if (!empty($uitemStrs ?? null) && !empty($usqls)) {
                        Db::query($usqls);
                    }
                }
//                (new BalanceDetail())->saveAll(array_values($uBalance));
            }

            //批量修改所有订单为已完成
            if (!empty($allSuccessOrderSn ?? [])) {
//                //补订单完成, 防止有些订单没有正确完成, <暂时注释,避免不要的性能损耗>
//                (new Order())->update(['order_status' => 8, 'end_time' => time()], ['order_sn' => array_unique($allSuccessOrderSn), 'order_type' => 6, 'order_status' => 2]);

                //修改延迟发放奖励记录列表数据为已发放
                CrowdfundingDelayRewardOrder::update(['real_arrival_time' => time(), 'arrival_status' => 1], ['order_sn' => array_unique($allSuccessOrderSn), 'arrival_status' => 3, 'status' => 1]);
                //删除redis数据
                foreach ($allSuccessOrderSn as $key => $value) {
                    if(in_array($value,array_keys($dealOrderInfo))){
                        $redisService->lrem($this->delayRewardOrderCacheKey, (($dealOrderInfo[$value] ?? '') . '-' . $value));
                    }
                }
            }

            //发放冻结订单对应期的熔断分期返回美丽金
            if (!empty($periodList ?? [])) {
                foreach ($periodList as $key => $value) {
                    $successCrowdKey = $value['activity_code'] . '-' . $value['round_number'] . '-' . $value['period_number'];
                    //释放冻结中的历史熔断分期返回美丽金
                    Queue::later(15, 'app\lib\job\CrowdFunding', ['dealType' => 6, 'periodList' => [0 => ['crowd_code' => $value['activity_code'], 'crowd_round_number' => $value['round_number'], 'crowd_period_number' => $value['period_number']]]], config('system.queueAbbr') . 'CrowdFunding');
                }
            }


            $res = ['priceBalance' => $aBalance ?? [], 'rewardBalance' => $balance ?? []];
            //记录日志
            $log['DBRes'] = $res;
            $this->log($log, 'info');

            //释放对象, 防止内存泄露
            unset($aBalance);
            unset($balance);
            unset($uBalance);
            unset($allDivideListId);

            return $res;
        });
        //清除缓存锁
        cache($dealCacheKey, null);

        unset($dealOrderSn);
        unset($divideList);
        unset($orderList);
        unset($dealOrderInfo);
        return true;

    }

    /**
     * @title  记录错误并保存日志,删除该任务后终止
     * @param array $data 所有数据
     * @param array $error 错误内容
     * @return bool
     */
    public function recordError(array $data, array $error)
    {
        $allData['msg'] = '订单 ' . ($data['requestData']['order_sn'] ?? "<暂无订单编号>") . " [ 服务出错:" . ($error['msg'] ?? '原因未知') . " ] ";
        $allData['data'] = $data;
        $allData['error'] = $error;
        $this->log($allData, 'error');
        return false;
    }


    /**
     * @title  记录日志
     * @param array $data 数据信息
     * @param string $level 日志等级
     * @return mixed
     */
    public function log(array $data, string $level = 'error')
    {
        return (new Log())->setChannel('crowd')->record($data, $level);
    }
}