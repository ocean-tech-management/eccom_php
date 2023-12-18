<?php
// +----------------------------------------------------------------------
// |[ 文档说明: ]
// +----------------------------------------------------------------------
// | Copyright (c) 2018~2024 http://www.mlhcmk.com All rights reserved.
// +----------------------------------------------------------------------
 

namespace app\lib\services;


use app\lib\BaseException;
use app\lib\exceptions\OrderException;
use app\lib\exceptions\PpylException;
use app\lib\exceptions\ServiceException;
use app\lib\job\TimerForPpyl;
use app\lib\models\AfterSale;
use app\lib\models\BalanceDetail;
use app\lib\models\CouponUserType;
use app\lib\models\GoodsSku;
use app\lib\models\OrderCoupon;
use app\lib\models\OrderGoods;
use app\lib\models\PpylActivity;
use app\lib\models\PpylArea;
use app\lib\models\PpylAuto;
use app\lib\models\PpylBalanceDetail;
use app\lib\models\PpylConfig;
use app\lib\models\PpylGoodsSku;
use app\lib\models\PpylMemberVdc;
use app\lib\models\PpylOrder;
use app\lib\models\PpylOrderGoods;
use app\lib\models\PpylReward;
use app\lib\models\PpylWaitOrder;
use app\lib\models\PtActivity;
use app\lib\models\PtGoodsSku;
use app\lib\models\PtOrder;
use app\lib\models\RefundDetail;
use app\lib\models\UserRepurchase;
use app\lib\services\Order as OrderService;
use app\lib\models\User;
use app\lib\models\UserCoupon;
use app\lib\models\Order;
use think\facade\Cache;
use think\facade\Db;
use think\facade\Queue;

class Ppyl
{
    public $notThrowError = false;

    /**
     * @title  拼拼有礼抽奖和退款操作
     * @param array $data
     * @return bool
     * @throws \Exception
     */
    public function completePpylOrder(array $data)
    {
        $activitySn = $data['activity_sn'] ?? null;
        if (empty($activitySn)) {
            return false;
        }
        $activityOrderInfo = PpylOrder::where(['activity_sn' => $activitySn, 'pay_status' => 2, 'activity_status' => 2])->order('user_role asc,create_time asc')->select()->toArray();
        if (empty($activityOrderInfo)) {
            return false;
        }
        foreach ($activityOrderInfo as $key => $value) {
            if (in_array($value['win_status'], [1, 2])) {
                $log['msg'] = $activitySn . '该团已存在抽奖记录,请勿重复操作';
                $log['info'] = $activityOrderInfo;
                $this->log($log, 'error');
                return false;
            }
        }
        //专区
        $areaList = PpylArea::where(['area_code' => array_unique(array_column($activityOrderInfo, 'area_code')), 'status' => [1, 2]])->select()->toArray();
        if (empty($areaList)) {
            $log['msg'] = '无任何有效的专场,无法执行抽奖';
            $log['info'] = $activityOrderInfo;
            $this->log($log, 'error');
            return false;
        }
        $areaInfos = [];
        foreach ($areaList as $key => $value) {
            $areaInfos[$value['area_code']] = $value;
        }
        $log['areaList'] = $areaList ?? [];
        $log['areaInfos'] = $areaInfos ?? [];

        $loseOrder = [];
        $winOrderInfo = [];
        $rateOrder = [];

        //重新校验每个人的中奖次数的概率
        foreach ($activityOrderInfo as $key => $value) {
            $areaInfo = $areaInfos[$value['area_code']] ?? [];
            if (empty($areaInfo)) {
                $activityOrderInfo[$key]['scale'] = 0;
            }
            $pwMap = [];
            switch ($areaInfo['win_limit_type'] ?? 1) {
                case 1:
                    $timeStart = null;
                    $timeEnd = null;
                    $needLimit = true;
                    break;
                case 2:
                    $timeStart = date('Y-m-d', time()) . ' 00:00:00';
                    $timeEnd = date('Y-m-d', time()) . ' 23:59:59';
                    $needLimit = true;
                    break;
                case 3:
                    //当前日期
                    $sdefaultDate = date("Y-m-d");
                    //$first =1 表示每周星期一为开始日期 0表示每周日为开始日期
                    $first = 1;
                    //获取当前周的第几天 周日是 0 周一到周六是 1 - 6
                    $w = date('w', strtotime($sdefaultDate));
                    $timeStart = date('Y-m-d', strtotime("$sdefaultDate -" . ($w ? $w - $first : 6) . ' days')) . ' 00:00:00';
                    //本周结束日期
                    $timeEnd = date('Y-m-d', strtotime("$timeStart +6 days")) . ' 23:59:59';
                    $needLimit = true;
                    break;
                case 4:
                    //本月的开始和结束
                    $timeStart = date('Y-m-01', strtotime(date("Y-m-d"))) . ' 00:00:00';
                    $timeEnd = date('Y-m-d', strtotime("$timeStart +1 month -1 day")) . ' 23:59:59';
                    $needLimit = true;
                    break;
            }
            if (!empty($timeStart) && !empty($timeEnd)) {
                $pwMap[] = ['group_time', '>=', strtotime($timeStart)];
                $pwMap[] = ['group_time', '<=', strtotime($timeEnd)];
            }
            $pwMap[] = ['uid', '=', $value['uid']];
            $pwMap[] = ['activity_code', '=', $value['activity_code']];
            $pwMap[] = ['area_code', '=', $value['area_code']];
            $pwMap[] = ['activity_status', 'in', [2]];
            $pwMap[] = ['win_status', '=', 1];
//        $pwMap[] = ['goods_sn', '=', $data['goods_sn']];

            //判断该用户参加拼团活动中奖的次数
            $winPtNumber = PpylOrder::where($pwMap)->count();

            if (intval($winPtNumber) >= ($areaInfo['win_number'] ?? 0)) {
                $activityOrderInfo[$key]['scale'] = 0;
            }
        }

        $areaInfo = [];
        //每个人的概率暂时都是一样的,后续如果有特殊规则的用户可以修改此处每个人的概率,以实现特殊用户的中奖概率不同
        foreach ($activityOrderInfo as $key => $value) {
            if ($value['scale'] <= 0) {
                $aRate[$value['uid']] = 0;
            } else {
                $areaInfo = $areaInfos[$value['area_code']] ?? [];
                //如果是无效专场则中奖概率为0
                if (empty($areaInfo)) {
                    $aRate[$value['uid']] = 0;
                } else {
                    //按照默认的概率
//                    $aRate[$value['uid']]  = $value['scale'];
                    //加权概率,根据参与次数
                    $aRate[$value['uid']] = $this->lotteryScaleWeighted(['value' => $value, 'areaInfo' => $areaInfo, 'max_type' => 1, 'max_number' => 10]);
                }
            }
        }

        $winUid = null;
        if (!empty($aRate)) {
            //进入抽奖算法
            $winUid = $this->lotteryAlgorithm($aRate);
        }

        if (empty($winUid)) {
            $log['msg'] = '抽奖概率发生严重错误!';
            $log['activity_sn'] = $activitySn;
            $log['activityOrderInfo'] = $activityOrderInfo;
            $log['aRate'] = $aRate ?? [];
            $log['winUid'] = $winUid ?? null;
            $this->log($log, 'error');
//            return false;
        }
        $notWinUser = false;
        if (!empty($winUid)) {
            foreach ($activityOrderInfo as $key => $value) {
                if ($value['uid'] == $winUid) {
                    $winOrderInfo = $value;
                } else {
                    $loseOrder[] = $value;
                }
                if (isset($aRate[$value['uid']])) {
                    $rateOrder[$value['order_sn']] = $aRate[$value['uid']];
                }
            }
        } else {
            $notWinUser = true;
            foreach ($activityOrderInfo as $key => $value) {
                $loseOrder[] = $value;
                if (isset($aRate[$value['uid']])) {
                    $rateOrder[$value['order_sn']] = $aRate[$value['uid']];
                }
            }
        }

        $res = Db::transaction(function () use ($loseOrder, $winOrderInfo, $data, $rateOrder,$notWinUser,$areaInfos,$winUid) {
            //修改此团全部订单的抽奖时间
            $groupUpdate['lottery_time'] = time();
            PpylOrder::update($groupUpdate, ['activity_sn' => $data['activity_sn']]);

            if(!empty($winOrderInfo ?? [])){
                //中奖的修改拼团订单记录,修改中奖时间记录,未中奖的退款至余额,order表订单状态修改为拼团失败
                $winPpylRecord['win_status'] = 1;
                $winPpylRecord['win_time'] = time();
                $winPpylRes = PpylOrder::update($winPpylRecord, ['order_sn' => $winOrderInfo['order_sn']]);
                $returnData['winPpylRes'] = $winPpylRes->getData();
                //红包奖励
                $rewardRes = $this->ppylOrderReward(['orderInfo' => $winOrderInfo, 'logRecord' => true]);
                //如果有自动拼团计划则停止
                $pMap[] = ['activity_code', '=', $winOrderInfo['activity_code']];
                $pMap[] = ['area_code', '=', $winOrderInfo['area_code']];
                $pMap[] = ['goods_sn', '=', $winOrderInfo['goods_sn']];
                $pMap[] = ['uid', '=', $winOrderInfo['uid']];
                $pMap[] = ['end_time', '>=', time()];
                $pMap[] = ['status', '=', 1];

                $existAuto = PpylAuto::where($pMap)->count();
                if (!empty($existAuto)) {
                    $pMap[] = ['activity_code', '=', $winOrderInfo['activity_code']];
                    $pMap[] = ['area_code', '=', $winOrderInfo['area_code']];
                    $pMap[] = ['goods_sn', '=', $winOrderInfo['goods_sn']];
                    $pMap[] = ['uid', '=', $winOrderInfo['uid']];
                    PpylAuto::update(['status' => 3, 'remark' => '成功中奖停止计划', 'stop_time' => time()], $pMap);
                }

                //如果同专区还有正在进行中的订单,并且因为这次中奖已经达到中奖上限了,修改同专区正在进行中的订单中奖概率为0
                $winAreaInfo = $areaInfos[$winOrderInfo['area_code']] ?? [];
                if (!empty($winAreaInfo)) {
                    $timeStart = null;
                    $timeEnd = null;
                    $pwwMap = [];
                    switch ($winAreaInfo['win_limit_type'] ?? 1) {
                        case 1:
                            $timeStart = null;
                            $timeEnd = null;
                            $needLimit = true;
                            break;
                        case 2:
                            $timeStart = date('Y-m-d', time()) . ' 00:00:00';
                            $timeEnd = date('Y-m-d', time()) . ' 23:59:59';
                            $needLimit = true;
                            break;
                        case 3:
                            //当前日期
                            $sdefaultDate = date("Y-m-d");
                            //$first =1 表示每周星期一为开始日期 0表示每周日为开始日期
                            $first = 1;
                            //获取当前周的第几天 周日是 0 周一到周六是 1 - 6
                            $w = date('w', strtotime($sdefaultDate));
                            $timeStart = date('Y-m-d', strtotime("$sdefaultDate -" . ($w ? $w - $first : 6) . ' days')) . ' 00:00:00';
                            //本周结束日期
                            $timeEnd = date('Y-m-d', strtotime("$timeStart +6 days")) . ' 23:59:59';
                            $needLimit = true;
                            break;
                        case 4:
                            //本月的开始和结束
                            $timeStart = date('Y-m-01', strtotime(date("Y-m-d"))) . ' 00:00:00';
                            $timeEnd = date('Y-m-d', strtotime("$timeStart +1 month -1 day")) . ' 23:59:59';
                            $needLimit = true;
                            break;
                    }
                    if (!empty($timeStart) && !empty($timeEnd)) {
                        $pwwMap[] = ['create_time', '>=', strtotime($timeStart)];
                        $pwwMap[] = ['create_time', '<=', strtotime($timeEnd)];
                    }
                    $pwwMap[] = ['uid', '=', $winOrderInfo['uid']];
                    $pwwMap[] = ['pay_status', '=', 2];
                    $pwwMap[] = ['area_code', '=', $winOrderInfo['area_code']];
                    $winMap = $pwwMap;
                    $winMap[] = ['win_status','=',1];
                    $winOrderCount = PpylOrder::where($winMap)->count();

                    $pwwMap[] = ['activity_status', '=', 1];

                    $activityIngOrderInfo = PpylOrder::where($pwwMap)->order('user_role asc,create_time asc')->select()->toArray();

                    if (!empty($activityIngOrderInfo) && $winOrderCount >= $winAreaInfo['win_number']) {
                        foreach ($activityIngOrderInfo as $key => $value) {
                            PpylOrder::update(['scale' => 0], ['uid' => $value['uid'], 'order_sn' => $value['order_sn'], 'activity_status' => 1, 'area_code' => $value['area_code']]);
                        }
                    }
                }


                $returnData['winRewardRes'] = $rewardRes;
            }


            if (!empty($loseOrder ?? [])) {
                if(empty($winOrderInfo ?? null)){
                    $losePpylRecode['activity_status'] = 3;
                    $losePpylRecode['win_status'] = 3;
                }else{
                    $losePpylRecode['win_status'] = 2;
                    $losePpylRecode['activity_status'] = -3;
                }

                $losePpylRes = PpylOrder::update($losePpylRecode, ['order_sn' => array_unique(array_column($loseOrder, 'order_sn'))]);
                $returnData['losePpylRes'] = $losePpylRes->getData();

                $codeBuilder = (new CodeBuilder());
                //推入退款处理队列中<暂时取消立马退款操作,需要用户手动点击操作退款>
//                foreach ($loseOrder as $key => $value) {
//                    $refundSn = $codeBuilder->buildRefundSn();
//                    Queue::push('app\lib\job\TimerForPpylOrder', ['order_sn' => $value['order_sn'], 'out_refund_no' => $refundSn, 'type' => 2], config('system.queueAbbr') . 'TimeOutPpylOrder');
//                }


                foreach ($loseOrder as $key => $value) {
                    $cMap = [];
                    if(empty($notWinUser)){
                        //红包奖励
                        $rewardRes = $this->ppylOrderReward(['orderInfo' => $value, 'logRecord' => true]);
                    }

                    //检查是否有自动拼团的订单,如果有则尝试重新开团
                    $cMap[] = ['activity_code', '=', $value['activity_code']];
                    $cMap[] = ['area_code', '=', $value['area_code']];
                    $cMap[] = ['goods_sn', '=', $value['goods_sn']];
                    $cMap[] = ['uid', '=', $value['uid']];
                    $cMap[] = ['end_time', '>=', time()];
                    $cMap[] = ['status', '=', 1];

                    $existAuto = PpylAuto::where($cMap)->value('plan_sn');

                    if (!empty($existAuto)) {
                        Queue::push('app\lib\job\TimerForPpyl', ['plan_sn' => $existAuto, 'restartType' => 1, 'type' => 2, 'pay_no' => ($value['pay_no'] ?? null), 'pay_order_sn' => $value['order_sn'],'channel'=>2], config('system.queueAbbr') . 'TimeOutPpyl');
                    }
                }
            }

            //修改订单表中的中奖概率
            if (!empty($rateOrder)) {
                foreach ($rateOrder as $key => $value) {
                    PpylOrder::update(['scale' => $value], ['order_sn' => $key]);
                }
            }

            return $returnData;
        });

        return judge($res);
    }

    /**
     * @title  抽奖算法
     * @param array $proArr
     * @return int|string
     * @remark $proArr是一个预先设置的数组，假设数组为：array(100,200,300,400)，开始是从1,1000这个概率范围内筛选第一个数是否在他的出现概率范围之内， 如果不在，则将概率空减，也就是k的值减去刚刚的那个数字的概率空间，在本例当中就是减去100，也就是说第二个数是在1，900这个范围内筛选的。这样筛选到最终，总会有一个数满足要求。就相当于去一个箱子里摸东西，第一个不是，第二个不是，第三个还不是，那最后一个一定是。这个算法简单，而且效率非常高，尤其是大数据量的项目中效率非常棒。
     */
    public function lotteryAlgorithm(array $proArr)
    {
        $result = '';

        //概率数组的总概率精度
        $proSum = array_sum($proArr);
        if ($proSum <= 0) {
            return false;
        }

        //概率数组循环
        foreach ($proArr as $key => $proCur) {
            $randNum = mt_rand(1, $proSum);
            if ($randNum <= $proCur) {
                $result = $key;
                break;
            } else {
                $proSum -= $proCur;
            }
        }
        unset ($proArr);

        return $result;
    }

    /**
     * @title  抽奖概率加权
     * @param array $data
     * @return float|int|mixed
     */
    public function lotteryScaleWeighted(array $data)
    {
        $value = $data['value'];
        $areaInfo = $data['areaInfo'];
        //max_type 1为固定的满几, 2为根据专区的参数次数上限
        $maxType = $data['max_type'] ?? 1;

        switch ($maxType ?? 1) {
            case 1:
                $maxNumber = $data['max_number'] ?? 10;
                break;
            case 2:
                $maxNumber = $areaInfo['join_number'] ?? 10;
                break;
        }
        $ppMap = [];

        switch ($areaInfo['join_limit_type'] ?? 1) {
            case 1:
                $timeStart = null;
                $timeEnd = null;
                $needLimit = true;
                break;
            case 2:
                $timeStart = date('Y-m-d', time()) . ' 00:00:00';
                $timeEnd = date('Y-m-d', time()) . ' 23:59:59';
                $needLimit = true;
                break;
            case 3:
                //当前日期
                $sdefaultDate = date("Y-m-d");
                //$first =1 表示每周星期一为开始日期 0表示每周日为开始日期
                $first = 1;
                //获取当前周的第几天 周日是 0 周一到周六是 1 - 6
                $w = date('w', strtotime($sdefaultDate));
                $timeStart = date('Y-m-d', strtotime("$sdefaultDate -" . ($w ? $w - $first : 6) . ' days')) . ' 00:00:00';
                //本周结束日期
                $timeEnd = date('Y-m-d', strtotime("$timeStart +6 days")) . ' 23:59:59';
                $needLimit = true;
                break;
            case 4:
                //本月的开始和结束
                $timeStart = date('Y-m-01', strtotime(date("Y-m-d"))) . ' 00:00:00';
                $timeEnd = date('Y-m-d', strtotime("$timeStart +1 month -1 day")) . ' 23:59:59';
                $needLimit = true;
                break;
        }
        if (!empty($timeStart) && !empty($timeEnd)) {
            $ppMap[] = ['create_time', '>=', strtotime($timeStart)];
            $ppMap[] = ['create_time', '<=', strtotime($timeEnd)];
        }
        $ppMap[] = ['uid', '=', $value['uid']];
        $ppMap[] = ['activity_code', '=', $value['activity_code']];
        $ppMap[] = ['area_code', '=', $value['area_code']];
//        $winMap = $ppMap;
//        $winMap[] = ['activity_status', 'in', [2]];

        $ppMap[] = ['activity_status', 'in', [2, -3]];
//        $ppMap[] = ['goods_sn', '=', $value['goods_sn']];


        //判断该用户参加拼团活动的次数
        $joinPtNumber = PpylOrder::where($ppMap)->count();


        //如果用户未达到最后的参与次数,则按照次数*因数(100)(次数需要减少本次的)递增中奖权重,这样做是为了让参与越多次的人越容易中奖,如果刚好是最后一次,则直接给一个超大的权重,有极大的概率抽中他,以满足满几必中的需求
        //基础权重为100,每参加多一次多100,到最后一次权重直接为一亿
        //如果在范围内已经中过了,则按照基础权重,不需要加权
        if (!empty($joinPtNumber)) {
            if (intval($joinPtNumber) > ($areaInfo['join_number']  ?? $maxNumber)) {
                $scale = 0;
            } else {
                //取余如果为零则表示整除,这一次为必中
                if (empty(intval($joinPtNumber) % $maxNumber)) {
                    $scale = 10000000;
                } else {
                    $scale = $value['scale'] + (($joinPtNumber - 1) * 100);
                }
            }
        } else {
            $scale = 100;
        }

        return $scale ?? 0;
    }

    /**
     * @title  拼拼有礼订单提交第三方退款
     * @param array $data
     * @return mixed
     */
    public function submitPpylRefund(array $data)
    {
        $order_sn = $data['out_trade_no'];
        //$type 1为订单退款,超时失败或未成团 2为寄售退款
        $type = $data['type'] ?? 1;

        $orderInfo = PpylOrder::where(['order_sn' => $order_sn,'pay_status'=>2,'status'=>1])->field('uid,activity_sn,activity_code,area_code,order_sn,goods_sn,sku_sn,user_role,activity_type,activity_status,pay_status,win_status,refund_route,refund_price,refund_time,reward_price,real_pay_price,pay_type,refund_status,pay_no,shipping_status')->findOrEmpty()->toArray();
        if(empty($orderInfo)){
            return false;
        }
        //已经退款的不允许继续操作
        if($orderInfo['refund_status'] == 1){
            return false;
        }
        switch ($type){
            case 2:
                if($orderInfo['shipping_status'] != 2){
                    throw new PpylException(['errorCode'=>2700123]);
                }
                break;
        }

//        if ($orderInfo['pay_status'] == 2) {
            switch ($orderInfo['pay_type'] ?? 2){
                case 1:
                    $res = $this->completePpylRefund($data);
                    break;
                case 2:
                case 4:
                    //如果是支付流水号则寻找宿主订单号,才能退款
                    if ($orderInfo['pay_type'] == 4) {
                        $refundOrderSn = PpylOrder::where(['pay_no' => $orderInfo['pay_no'], 'pay_type' => 2])->value('order_sn');
                    } else {
                        $refundOrderSn = $orderInfo['order_sn'];
                    }
                    $refund['out_trade_no'] = $refundOrderSn;
                    $refund['out_refund_no'] = $data['out_refund_no'];
                    $refund['total_fee'] = $orderInfo['real_pay_price'];
                    $refund['refund_fee'] = $orderInfo['real_pay_price'];
//                $refund['total_fee'] = 0.01;
//                $refund['refund_fee'] = 0.01;
                    $refund['refund_desc'] = !empty($data['refund_remark']) ? $data['refund_remark'] : '美好拼拼退款';
                    $refund['notThrowError'] = $data['notThrowError'] ?? false;
                    $refundTime = time();
                    switch ($type){
                        case 1:
//                            $refund['notify_url'] = sprintf(config('system.callback.joinPayPpylRefundCallBackUrl'),$order_sn);
                            $refund['notify_url'] = sprintf(config('system.callback.PpylRefundCallBackUrl'),$order_sn);
                            break;
                        case 2:
//                            $refund['notify_url'] = sprintf(config('system.callback.joinPayPpylWinRefundCallBackUrl'),$order_sn);
                            $refund['notify_url'] = sprintf(config('system.callback.PpylWinRefundCallBackUrl'),$order_sn);
                            break;
                    }
//                    $refundRes = (new WxPayService())->refund($refund);
                    $refundRes = (new JoinPay())->refund($refund);

                    //只有第三方服务申请退款成功了才修改订单状态为退款中
                    if (!empty($refundRes)) {
                        //修改售后订单状态
                        $afterSaleRes = PpylOrder::update(['pay_status' => 3], ['order_sn' => $order_sn, 'pay_status' => 2, 'status' => 1]);
                    }else{
                        if ($type == 2) {
                            //如果当前存在寄售的记录,则返回次数
                            $exist = PpylOrder::where(['order_sn' => $data['out_trade_no'], 'uid' => $orderInfo['uid'], 'shipping_status' => 2])->count();
                            //退款失败打回状态
                            $ppylFefundRes = PpylOrder::update(['shipping_status' => 3, 'shipping_time' => null], ['order_sn' => $data['out_trade_no'], 'uid' => $orderInfo['uid'], 'shipping_status' => 2]);
                            if (!empty($ppylFefundRes->getData()) && !empty($exist)) {
                                //返还次数
                                UserRepurchase::where(['uid' => $orderInfo['uid'], 'area_code' => $orderInfo['area_code'], 'status' => 1])->inc('repurchase_capacity', 1)->update();
                            }
                        }
                    }
                    break;
            }
//        }
        //取消智能计划
        PpylAuto::update(['status' => 3, 'remark' => date('Y-m-d H:i:s') . '用户手动申请拼拼订单 ' . $order_sn . ' 退款停止自动计划'], ['pay_no' => $orderInfo['pay_no'], 'status' => 1]);

        if (!empty($orderInfo['pay_no'])) {
            //若是使用过该流水号的所有订单都不允许继续操作退款了
            PpylOrder::update(['can_operate_refund' => 2], ['pay_no' => $orderInfo['pay_no'], 'can_operate_refund' => 1]);
        }


    }

    /**
     * @title  完成拼拼有礼未中奖订单退款
     * @param array $callbackData
     * @return mixed
     */
    public function completePpylRefund(array $callbackData)
    {
        //退款类型 1为原路退回 2为退回余额
        $refundType = 1;

        $order_sn = $callbackData['out_trade_no'];
        if (!empty($callbackData['now'] ?? null)) {
            $order_sn = $callbackData['now'];
        }

        $overTimePtOrder = PpylOrder::where(['order_sn' => $order_sn])->field('uid,activity_sn,activity_code,area_code,order_sn,goods_sn,sku_sn,user_role,activity_type,activity_status,pay_status,win_status,refund_route,refund_price,refund_time,reward_price,real_pay_price,pay_type,refund_status,pay_no')->findOrEmpty()->toArray();
        if (empty($overTimePtOrder)) {
            return false;
        }
        if ($overTimePtOrder['win_status'] == 2 || $overTimePtOrder['activity_status'] == 3) {
            $res = Db::transaction(function () use ($callbackData, $order_sn, $overTimePtOrder,$refundType) {
                if ($overTimePtOrder['refund_status'] == 1) {
                    $returnRes['errorMsg'] = '已经退款过啦~无法重复操作';
                    return $returnRes;
                }
                //如果是余额支付则退回余额
                if($overTimePtOrder['pay_type'] == 1){
                    $refundType = 2;
                }
//                $orderSn = $callbackData['out_trade_no'];
                $orderSn = $order_sn;
                $afSn = $callbackData['out_refund_no'] ?? null;
//                $aOrder = $overTimePtOrder['orders'];
                $needRefundDetail = false;
                if (in_array($overTimePtOrder['pay_status'], [2, 3])) {
                    $needRefundDetail = true;
                }

                //修改拼团订单为退款
                if (!empty($needRefundDetail)) {
                    $ptOrderSave['pay_status'] = -2;
                    $ptOrderSave['status'] = -2;
                    if ($overTimePtOrder['win_status'] == 2) {
                        $ptOrderSave['activity_status'] = -3;
                    }
                    $ptOrderSave['refund_status'] = 1;
                    $ptOrderSave['refund_route'] = 2;
                    $ptOrderSave['refund_price'] = $overTimePtOrder['real_pay_price'];
                    $ptOrderSave['refund_time'] = time();
                    $ptOrderSave['close_time'] = time();
                } else {
                    $ptOrderSave['status'] = -1;
                }
                //不允许继续操作退款和重开
                $ptOrderSave['can_operate_refund'] = 2;

                $orderMap[] = ['order_sn', '=', $orderSn];
                if ($overTimePtOrder['activity_status'] == 3) {
                    $orderMap[] = ['pay_status', 'in', [1, 3]];
                } else {
                    $orderMap[] = ['pay_status', 'in', [2, 3]];
                }

                $ppylRes = PpylOrder::update($ptOrderSave, $orderMap);

                $returnRes['ppylRes'] = $ppylRes->getData();
                $returnRes['ppylMap'] = $orderMap;

                //取消智能计划
                PpylAuto::update(['status' => 3], ['pay_no' => $overTimePtOrder['pay_no'], 'status' => 1]);

                //只有超时未成团并且团长的订单才恢复一次库存
                if ($overTimePtOrder['activity_status'] == 3 && !empty($overTimePtOrder['user_role']) && $overTimePtOrder['user_role'] == 1) {
                    //先锁行后自减库存
                    $goodsId = GoodsSku::where(['sku_sn' => $overTimePtOrder['sku_sn']])->column('id');

                    $ptGoodsId = PpylGoodsSku::where(['activity_code' => $overTimePtOrder['activity_code'], 'area_code' => $overTimePtOrder['area_code'], 'goods_sn' => $overTimePtOrder['goods_sn'], 'sku_sn' => $overTimePtOrder['sku_sn']])->column('id');
                    if (!empty($goodsId)) {
                        $lockGoods = GoodsSku::where(['id' => $goodsId])->lock(true)->field('id,goods_sn,sku_sn')->findOrEmpty()->toArray();
                        //恢复库存
                        $skuRes = GoodsSku::where(['sku_sn' => $overTimePtOrder['sku_sn']])->inc('stock', 1)->update();
                    }

                    if (!empty($ptGoodsId)) {
                        $lockPtGoods = PpylGoodsSku::where(['id' => $ptGoodsId])->lock(true)->field('id,activity_code,area_code,goods_sn,sku_sn')->findOrEmpty()->toArray();
                        //恢复拼团库存
                        $ptStockRes = PpylGoodsSku::where(['activity_code' => $overTimePtOrder['activity_code'], 'goods_sn' => $overTimePtOrder['goods_sn'], 'sku_sn' => $overTimePtOrder['sku_sn']])->inc('stock', 1)->update();

                        //如果是开团需要恢复开团次数
                        if (!empty($overTimePtOrder['user_role']) && $overTimePtOrder['user_role'] == 1) {
                            PpylGoodsSku::where(['activity_code' => $overTimePtOrder['activity_code'], 'area_code' => $overTimePtOrder['area_code'], 'goods_sn' => $overTimePtOrder['goods_sn'], 'sku_sn' => $overTimePtOrder['sku_sn']])->inc('start_number', 1)->update();
                        }
                    }
                }

                if ($overTimePtOrder['activity_status'] == 3) {
                    //修改对应优惠券状态
                    $aOrderCoupons = OrderCoupon::where(['order_sn' => $orderSn, 'used_status' => 1])->field('coupon_uc_code,coupon_code')->select()->toArray();
                    $aOrderUcCoupon = array_column($aOrderCoupons, 'coupon_uc_code');
                    if (!empty($aOrderUcCoupon)) {
                        //修改订单优惠券状态为取消使用
                        $orderCouponRes = OrderCoupon::update(['used_status' => -1], ['order_sn' => $orderSn, 'used_status' => 1]);
                        //修改用户订单优惠券状态为未使用
                        $uCouponRes = UserCoupon::update(['valid_status' => 1], ['uc_code' => $aOrderUcCoupon]);
                    }
                }

                //若是没有支付则没有后续的退款或发放红包逻辑
                if (empty($needRefundDetail)) {
                    return $returnRes;
                }

                //退款和余额明细
                if (!empty($needRefundDetail)) {
                    $remark = $overTimePtOrder['activity_status'] == -3 ? "拼拼有礼未中奖全额退款" : "拼拼有礼未成团全额退款";
                    //只有退回余额才添加退款明细
                    switch ($refundType){
                        case 2:
                            $userBalance['uid'] = $overTimePtOrder['uid'];
                            $userBalance['order_sn'] = $overTimePtOrder['order_sn'];
                            $userBalance['belong'] = 1;
                            $userBalance['type'] = 1;
                            $userBalance['price'] = $overTimePtOrder['real_pay_price'];
                            $userBalance['change_type'] = 8;
                            $userBalance['remark'] = $remark;
                            $returnRes['userBalance'] = $userBalance;
                            break;
                    }


                    $refundDetail['refund_sn'] = $afSn;
                    $refundDetail['uid'] = $overTimePtOrder['uid'];
                    $refundDetail['order_sn'] = $overTimePtOrder['order_sn'];
                    $refundDetail['after_sale_sn'] = null;
                    $refundDetail['refund_price'] = $overTimePtOrder['real_pay_price'];
                    $refundDetail['all_pay_price'] = $overTimePtOrder['real_pay_price'];
                    $refundDetail['refund_desc'] = $remark;
                    $refundDetail['refund_account'] = $refundType;
                    $refundDetail['pay_status'] = 1;
                    $returnRes['refundDetail'] = $refundDetail ?? [];
                    //退款金额不为0是修改账户明细
                    if (!empty(doubleval($overTimePtOrder['real_pay_price']))) {
                        //添加退款明细
                        $existRefundDetail = RefundDetail::where(['order_sn' => $refundDetail['order_sn'], 'uid' => $refundDetail['uid'], 'status' => 1])->count();
                        if (empty($existRefundDetail)) {
                            $refundRes = RefundDetail::create($refundDetail);
                        }


                        //只有退回余额才添加退款明细
                        if ($refundType == 2 && !empty($userBalance ?? [])) {
                            //累加用户拼拼本金余额
//                            User::where(['uid' => $userBalance['uid']])->inc('payment_balance', $userBalance['price'])->update();

                            //添加账户余额明细
                            $existBalanceDetail = BalanceDetail::where(['order_sn' => $userBalance['order_sn'], 'uid' => $userBalance['uid'], 'change_type' => $userBalance['change_type'], 'status' => 1])->count();
                            if (empty($existBalanceDetail)) {
                                $balanceRes = BalanceDetail::create($userBalance);
                                User::where(['uid' => $userBalance['uid']])->inc('divide_balance', $userBalance['price'])->update();
                                $returnRes['balanceRes'] = $balanceRes->getData();
                            } else {
                                $returnRes['balanceRes'] = '订单' . $userBalance['order_sn'] . '已存在退款记录,请勿重复记录';
                            }

                            $returnRes['refundRes'] = $refundRes->getData();
                        }

                    }
                }

//                //只有拼团未中奖的清单才会进入奖励红包的逻辑
//                if ($overTimePtOrder['activity_status'] == -3 && $overTimePtOrder['win_status'] == 2) {
//                    //红包奖励
//                    $rewardRes = $this->ppylOrderReward(['orderInfo' => $overTimePtOrder, 'logRecord' => false]);
//                    $returnRes['rewardRes'] = $rewardRes;
//                }

                //检查是否有自动拼团的订单,如果有则尝试重新开团
                //2021-10-22 修改为已经退款了则不继续重启自动计划
//                $cMap[] = ['activity_code', '=', $overTimePtOrder['activity_code']];
//                $cMap[] = ['area_code', '=', $overTimePtOrder['area_code']];
//                $cMap[] = ['goods_sn', '=', $overTimePtOrder['goods_sn']];
//                $cMap[] = ['uid', '=', $overTimePtOrder['uid']];
//                $cMap[] = ['end_time', '>=', time()];
//                $cMap[] = ['status', '=', 1];
//
//                $existAuto = PpylAuto::where($cMap)->value('plan_sn');
//                $returnRes['existAuto'] = $existAuto ?? [];
//
//                if (!empty($existAuto)) {
//                    Queue::push('app\lib\job\TimerForPpyl', ['plan_sn' => $existAuto, 'restartType' => 1, 'type' => 2, 'pay_order_sn' => $overTimePtOrder['order_sn'], 'plan_no' => ($overTimePtOrder['plan_no'] ?? null),'channel'=>3], config('system.queueAbbr') . 'TimeOutPpyl');
//                }

                return $returnRes ?? [];
            });

        } else {
            $res = '该拼团订单状态异常,可能已经处理好业务订单取消,故无法继续';
        }

        $log['msg'] = '已接受到拼拼有礼订单未中奖的 ' . $callbackData['out_trade_no'] . ' 的退款操作,退款编号为' . ($callbackData['out_refund_no'] ?? '无退款编码');
        $log['data']['callBackData'] = $callbackData;
        $log['data']['dealRes'] = $res ?? [];
        $this->log($log, 'info');

        return judge($res);
    }

    /**
     * @title  完成拼拼有礼回购订单退款
     * @param array $callbackData
     * @return mixed
     */
    public function completePpylRepurchaseRefund(array $callbackData)
    {
        $order_sn = $callbackData['out_trade_no'];
        if (!empty($callbackData['now'] ?? null)) {
            $order_sn = $callbackData['now'];
        }

        $overTimePtOrder = PpylOrder::where(['order_sn' => $order_sn])->field('uid,activity_sn,activity_code,area_code,order_sn,goods_sn,sku_sn,user_role,activity_type,activity_status,pay_status,win_status,refund_route,refund_price,refund_time,reward_price,real_pay_price,shipping_status,pay_type,pay_no,refund_status')->findOrEmpty()->toArray();
        if ($overTimePtOrder['win_status'] == 1 && $overTimePtOrder['activity_status'] == 2 && $overTimePtOrder['shipping_status'] == 2) {
            $res = Db::transaction(function () use ($callbackData, $order_sn, $overTimePtOrder) {
                if ($overTimePtOrder['refund_status'] == 1) {
                    $returnRes['errorMsg'] = '已经退款过啦~无法重复操作';
                    return $returnRes;
                }
//                $orderSn = $callbackData['out_trade_no'];
                $orderSn = $order_sn;
                $afSn = $callbackData['out_refund_no'] ?? null;
//                $aOrder = $overTimePtOrder['orders'];
                $needRefundDetail = false;
//                if (in_array($overTimePtOrder['pay_status'], [2, 3])) {
//                    return false;
//                }
                if (in_array($overTimePtOrder['pay_status'], [2, 3])) {
                    $needRefundDetail = true;
                }

                //修改拼团订单为退款
                if (!empty($needRefundDetail)) {
                    $ptOrderSave['pay_status'] = -2;
                    $ptOrderSave['status'] = -2;
                    $ptOrderSave['refund_route'] = 1;
                    $ptOrderSave['refund_price'] = $overTimePtOrder['real_pay_price'];
                    $ptOrderSave['refund_time'] = time();
                    $ptOrderSave['close_time'] = time();
                    $ptOrderSave['refund_status'] = 1;
                }
                //不允许继续操作退款和重开
                $ptOrderSave['can_operate_refund'] = 2;

                $orderMap[] = ['order_sn', '=', $orderSn];
                $orderMap[] = ['pay_status', 'in', [2,3]];

                if (!empty($ptOrderSave)) {
                    $ppylRes = PpylOrder::update($ptOrderSave, $orderMap);
                    $returnRes['ppylRes'] = $ppylRes->getData();
                }


                //若是没有支付则没有后续的退款或发放红包逻辑
                if (empty($needRefundDetail)) {
                    return $returnRes;
                }

                //退款和余额明细
                if (!empty($needRefundDetail)) {
                    $remark = "拼拼有礼中奖订单寄售成功平台回款";
                    if($overTimePtOrder['pay_type'] == 1){
                        $userBalance['uid'] = $overTimePtOrder['uid'];
                        $userBalance['order_sn'] = $overTimePtOrder['order_sn'];
                        $userBalance['belong'] = 1;
                        $userBalance['type'] = 1;
                        $userBalance['price'] = $overTimePtOrder['real_pay_price'];
                        $userBalance['change_type'] = 8;
                        $userBalance['remark'] = $remark;
                    }


                    $refundDetail['refund_sn'] = $afSn;
                    $refundDetail['uid'] = $overTimePtOrder['uid'];
                    $refundDetail['order_sn'] = $overTimePtOrder['order_sn'];
                    $refundDetail['after_sale_sn'] = null;
                    $refundDetail['refund_price'] = $overTimePtOrder['real_pay_price'];
                    $refundDetail['all_pay_price'] = $overTimePtOrder['real_pay_price'];
                    $refundDetail['refund_desc'] = $remark;
                    $refundDetail['refund_account'] = 2;
                    $refundDetail['pay_status'] = 1;
                    //退款金额不为0是修改账户明细
                    if (!empty(doubleval($overTimePtOrder['real_pay_price']))) {
                        //添加退款明细
                        $refundRes = RefundDetail::create($refundDetail);

                        if($overTimePtOrder['pay_type'] == 1){
                            //累加用户余额
                            User::where(['uid' => $userBalance['uid']])->inc('total_balance', $userBalance['price'])->update();
                            User::where(['uid' => $userBalance['uid']])->inc('avaliable_balance', $userBalance['price'])->update();

                            //添加账户余额明细
                            $balanceRes = BalanceDetail::create($userBalance);
                            $returnRes['balanceRes'] = $balanceRes->getData();
                            $returnRes['refundRes'] = $refundRes->getData();
                        }

                    }
                }

                return $returnRes ?? [];
            });

        } else {
            $res = '该拼团订单状态异常,可能已经处理好业务订单取消,故无法继续';
        }

        $log['msg'] = '已接受到拼拼有礼订单寄售的 ' . $callbackData['out_trade_no'] . ' 的退款操作,退款编号为' . ($callbackData['out_refund_no'] ?? '无退款编码');
        $log['data']['callBackData'] = $callbackData;
        $log['data']['dealRes'] = $res ?? [];
        $this->log($log, 'info');

        return judge($res);
    }

    /**
     * @title  拼拼有礼排队订单提交第三方退款
     * @param array $data
     * @return mixed
     */
    public function submitPpylWaitRefund(array $data)
    {
        $order_sn = $data['out_trade_no'];
        $orderInfo = PpylWaitOrder::where(['order_sn' => $order_sn,'pay_status'=>2,'status'=>1])->field('uid,activity_code,area_code,order_sn,goods_sn,sku_sn,user_role,activity_type,activity_status,pay_status,refund_route,refund_price,refund_time,reward_price,real_pay_price,wait_status,pay_type,refund_status,pay_no')->findOrEmpty()->toArray();

        if(empty($orderInfo)){
            return false;
        }
        //已经退款的不允许继续操作
        if($orderInfo['refund_status'] == 1){
            return false;
        }
        $canNotRefund = false;
        //查看流水号是否中奖或退款过,如果是则不允许继续操作
        if (!empty($orderInfo['pay_no'])) {
            $winOrderPayNo = PpylOrder::where(['pay_no' => $orderInfo['pay_no'], 'win_status' => 1])->count();
            if (!empty($winOrderPayNo)) {
                $canNotRefund = true;
            }
            $refundOrderPayNo = PpylOrder::where(['pay_no' => $orderInfo['pay_no'], 'pay_status' => [3, -2]])->count();
            if (!empty($refundOrderPayNo)) {
                $canNotRefund = true;
            }
            $refundWaitOrderPayNo = PpylWaitOrder::where(['pay_no' => $orderInfo['pay_no'], 'pay_status' => [3, -2]])->count();
            if (!empty($refundWaitOrderPayNo)) {
                $canNotRefund = true;
            }
            if (!empty($canNotRefund)) {
                $log['msg'] = '接受到排队订单' . $order_sn . '的退款请求,但是该订单的支付流水号' . $orderInfo['pay_no'] . '已存在退款或在退款申请中,或已中奖的订单,无法继续执行申请退款操作,如果是系统派错的排队订单,将会继续执行订单超时行为';
                $log['data'] = $data;
                $log['winOrderPayNo'] = $winOrderPayNo ?? [];
                $log['refundOrderPayNo'] = $refundOrderPayNo ?? [];
                $log['refundWaitOrderPayNo'] = $refundWaitOrderPayNo ?? [];
                (new Log())->setChannel('ppyl')->record($log, 'error');

                //取消智能计划
                PpylAuto::update(['status' => 3, 'remark' => date('Y-m-d H:i:s') . '排队订单超时,支付流水号已被退款,固无退款申请,停止自动计划'], ['pay_no' => $orderInfo['pay_no'], 'status' => 1]);
                return false;
            }
        }

        if ($orderInfo['pay_status'] == 2) {
            switch ($orderInfo['pay_type'] ?? 2) {
                case 1:
                    $res = $this->completePpylWaitRefund($data);
                    break;
                case 2:
                case 4:
                    //如果是支付流水号则寻找宿主订单号,才能退款
                    if ($orderInfo['pay_type'] == 4) {
                        $refundOrderSn = PpylWaitOrder::where(['pay_no' => $orderInfo['pay_no'], 'pay_type' => 2])->order('create_time asc')->value('order_sn');
                        if(empty($refundOrderSn)){
                            $refundOrderSn = PpylOrder::where(['pay_no' => $orderInfo['pay_no'], 'pay_type' => 2])->order('create_time asc')->value('order_sn');
                        }
                    } else {
                        $refundOrderSn = $orderInfo['order_sn'];
                    }
                    if (empty($refundOrderSn)) {
                        return false;
                    }
                    $refund['out_trade_no'] = $refundOrderSn;
                    $refund['out_refund_no'] = $data['out_refund_no'];
                    $refund['total_fee'] = $orderInfo['real_pay_price'];
                    $refund['refund_fee'] = $orderInfo['real_pay_price'];
//                $refund['total_fee'] = 0.01;
//                $refund['refund_fee'] = 0.01;
                    $refund['refund_desc'] = !empty($data['refund_remark']) ? $data['refund_remark'] : '美好拼拼排队退款';
                    $refund['notThrowError'] = $data['notThrowError'] ?? false;
                    $refundTime = time();
//                    $refund['notify_url'] = sprintf(config('system.callback.joinPayPpylWaitRefundCallBackUrl'),$order_sn);
                    $refund['notify_url'] = sprintf(config('system.callback.PpylWaitRefundCallBackUrl'),$order_sn);

//                    $refundRes = (new WxPayService())->refund($refund);
                    $refundRes = (new JoinPay())->refund($refund);
                    //只有第三方服务申请退款成功了才修改订单状态为退款中
                    if (!empty($refundRes)) {
                        //修改订单支付状态为退款中
                        $afterSaleRes = PpylWaitOrder::update(['pay_status' => 3], ['order_sn' => $order_sn, 'pay_status' => 2, 'status' => 1]);
                    }
                    break;
            }
        }

        //取消智能计划
        PpylAuto::update(['status' => 3, 'remark' => date('Y-m-d H:i:s') . '用户手动申请拼拼排队订单 ' . $order_sn . ' 退款或排队订单超时无法执行等原因停止自动计划'], ['pay_no' => $orderInfo['pay_no'], 'status' => 1]);
        if (!empty($orderInfo['pay_no'] ?? null)) {
            //若是使用过该流水号的所有订单都不允许继续操作退款了
            PpylOrder::update(['can_operate_refund' => 2], ['pay_no' => $orderInfo['pay_no'], 'can_operate_refund' => 1]);
        }
    }

    /**
     * @title  完成拼拼有礼排队订单退款
     * @param array $callbackData
     * @return mixed
     */
    public function completePpylWaitRefund(array $callbackData)
    {
        $order_sn = $callbackData['out_trade_no'];
        if (!empty($callbackData['now'] ?? null)) {
            $order_sn = $callbackData['now'];
        }

        $overTimePtOrder = PpylWaitOrder::where(['order_sn' => $order_sn])->field('uid,activity_code,area_code,order_sn,goods_sn,sku_sn,user_role,activity_type,activity_status,pay_status,refund_route,refund_price,refund_time,reward_price,real_pay_price,wait_status,pay_type,pay_no')->findOrEmpty()->toArray();
        if ($overTimePtOrder['wait_status'] == 3) {
            $res = Db::transaction(function () use ($callbackData, $order_sn, $overTimePtOrder) {
//                $orderSn = $callbackData['out_trade_no'];
                $orderSn = $order_sn;
                $afSn = $callbackData['out_refund_no'] ?? null;
//                $aOrder = $overTimePtOrder['orders'];
                $needRefundDetail = false;
                if (in_array($overTimePtOrder['pay_status'], [2, 3])) {
                    $needRefundDetail = true;
                }

                //修改拼团订单为退款
                if (!empty($needRefundDetail)) {
                    $ptOrderSave['pay_status'] = -2;
                    $ptOrderSave['status'] = -2;
                    $ptOrderSave['refund_route'] = 2;
                    $ptOrderSave['refund_price'] = $overTimePtOrder['real_pay_price'];
                    $ptOrderSave['refund_time'] = time();
                    $ptOrderSave['close_time'] = time();
                    $ptOrderSave['refund_status'] = 1;
                } else {
                    $ptOrderSave['status'] = -1;
                    $ptOrderSave['pay_status'] = -1;
                    $ptOrderSave['wait_status'] = -1;
                }

                $orderMap[] = ['order_sn', '=', $orderSn];

                $ppylRes = PpylWaitOrder::update($ptOrderSave, $orderMap);

                $returnRes['ppylRes'] = $ppylRes->getData();

                //取消智能计划
                PpylAuto::update(['status' => 3], ['pay_no' => $overTimePtOrder['pay_no'], 'status' => 1]);

                if ($overTimePtOrder['wait_status'] == 3) {
                    //修改对应优惠券状态
                    $aOrderCoupons = OrderCoupon::where(['order_sn' => $orderSn, 'used_status' => 1])->field('coupon_uc_code,coupon_code')->select()->toArray();
                    $aOrderUcCoupon = array_column($aOrderCoupons, 'coupon_uc_code');
                    if (!empty($aOrderUcCoupon)) {
                        //修改订单优惠券状态为取消使用
                        $orderCouponRes = OrderCoupon::update(['used_status' => -1], ['order_sn' => $orderSn, 'used_status' => 1]);
                        //修改用户订单优惠券状态为未使用
                        $uCouponRes = UserCoupon::update(['valid_status' => 1], ['uc_code' => $aOrderUcCoupon]);
                    }
                }

                //若是没有支付则没有后续的退款或发放红包逻辑
                if (empty($needRefundDetail)) {
                    return $returnRes;
                }

                //退款和余额明细
                if (!empty($needRefundDetail)) {
                    $remark = "拼拼有礼排队超时全额退款";
                    if ($overTimePtOrder['pay_type'] == 1) {
                        $userBalance['uid'] = $overTimePtOrder['uid'];
                        $userBalance['order_sn'] = $overTimePtOrder['order_sn'];
                        $userBalance['belong'] = 1;
                        $userBalance['type'] = 1;
                        $userBalance['price'] = $overTimePtOrder['real_pay_price'];
                        $userBalance['change_type'] = 9;
                        $userBalance['remark'] = $remark;
                    }


                    $refundDetail['refund_sn'] = $afSn;
                    $refundDetail['uid'] = $overTimePtOrder['uid'];
                    $refundDetail['order_sn'] = $overTimePtOrder['order_sn'];
                    $refundDetail['after_sale_sn'] = null;
                    $refundDetail['refund_price'] = $overTimePtOrder['real_pay_price'];
                    $refundDetail['all_pay_price'] = $overTimePtOrder['real_pay_price'];
                    $refundDetail['refund_desc'] = $remark;
                    $refundDetail['refund_account'] = 2;
                    $refundDetail['pay_status'] = 1;
                    //退款金额不为0是修改账户明细
                    if (!empty(doubleval($overTimePtOrder['real_pay_price']))) {
                        //添加退款明细
                        $refundRes = RefundDetail::create($refundDetail);

                        if ($overTimePtOrder['pay_type'] == 1) {
                            //累加用户余额
                            User::where(['uid' => $userBalance['uid']])->inc('total_balance', $userBalance['price'])->update();
                            User::where(['uid' => $userBalance['uid']])->inc('avaliable_balance', $userBalance['price'])->update();
                            //添加账户余额明细
                            $balanceRes = BalanceDetail::create($userBalance);
                            $returnRes['balanceRes'] = $balanceRes->getData();
                            $returnRes['refundRes'] = $refundRes->getData();
                        }
                    }
                }

                return $returnRes ?? [];
            });

        } else {
            $res = '该拼团订单状态异常,可能已经处理好业务订单取消,故无法继续';
        }

        $log['msg'] = '已接受到拼拼有礼排队订单的 ' . $callbackData['out_trade_no'] . ' 的退款操作,退款编号为' . ($callbackData['out_refund_no'] ?? '无退款编码');
        $log['data']['callBackData'] = $callbackData;
        $log['data']['dealRes'] = $res ?? [];
        $this->log($log, 'info');

        return judge($res);
    }

    /**
     * @title  新建拼团订单
     * @param array $data 拼团活动信息
     * @param array $orders 订单信息
     * @return mixed
     * @throws \Exception
     */
    public function orderCreate(array $data, array $orders)
    {
        $joinType = $data['ppyl_join_type'] ?? 1;
        $areaCode = $data['area_code'];
        $orderInfo = $orders['orderRes'];
        $notThrowError = $data['notThrowError'] ?? false;
        $notLock = $data['notLock'] ?? false;
        $pay_no = $data['pay_no'] ?? null;
        $this->notThrowError = $notThrowError;

        if(time() >= 1640847600){
            throw new OrderException(['msg'=>'系统升级中, 暂无法下单, 感谢您的支持']);
        }

        //先记录一下订单的日志,防止后面出错查证或回复
        $log['msg'] = '接受到拼拼订单' . ($orderInfo['order_sn'] ?? null) . '的创建需求';
        $log['orderSn'] = $orderInfo['order_sn'] ?? null;
        $log['data'] = $data;
        $log['orders'] = $orders;
        (new Log())->setChannel('ppyl')->record($log);

        $lockKey = null;
        if (empty($notLock)) {
            //上缓存锁
            $lockKey = 'ppylOrderCreatLock-' . $orderInfo['uid'] . $areaCode . ($data['goods_sn'] ?? '') . $joinType;

            if (!empty(cache($lockKey))) {
                $log['msg'] = '正在处理同类型订单,请稍后操作~';
                $log['orderSn'] = $orderInfo['order_sn'] ?? null;
                $log['data'] = $data;
                $log['orders'] = $orders;
                (new Log())->setChannel('ppyl')->record($log);
//                return $this->throwErrorMsg(['msg' => '正在处理同类型订单,请稍后操作~'], $notThrowError);
                return false;
            }
        }
        //上锁
        if (empty($notLock) && !empty($lockKey) ?? null) {
            cache($lockKey, $data, 10);
        }

        $areaInfo = PpylArea::where(['area_code' => $areaCode, 'status' => 1])->findOrEmpty()->toArray();
        if (empty($areaInfo)) {
            return $this->throwErrorMsg(['msg' => '不存在的美好拼拼专区哦'], $notThrowError);
        }
        $existOrder = PpylOrder::where(['order_sn' => $orderInfo['order_sn']])->count();
        if (!empty($existOrder)) {
            return $this->throwErrorMsg(['msg' => '美好拼拼订单已存在,请勿重复提交'], $notThrowError);
        }
        //若是支付流水号则判断是否存在退款,退款中或已经退款则不允许继续生成订单
        if ($orderInfo['pay_type'] == 4 && !empty($data['pay_no'] ?? null)) {
            //查看是否有用户自己申请退款的缓存锁,有则不允许继续
            if (!empty(cache('userSubmitRefund-' . $data['pay_no']))) {
                return $this->throwErrorMsg(['msg' => '该支付流水号已存在或正在退款,请无频繁操作'], $notThrowError);
            }
            cache('orderCreate-' . $data['pay_no'], $data, 10);

            $existRefund = false;
            $existRefundOrder = PpylOrder::where(['pay_no' => $data['pay_no'], 'pay_status' => [3, -2]])->count();
            if (!empty($existRefundOrder)) {
                $existRefund = true;
            } else {
                $existRefundWaitOrder = PpylWaitOrder::where(['pay_no' => $data['pay_no'], 'pay_status' => [3, -2]])->count();
                if (!empty($existRefundWaitOrder)) {
                    $existRefund = true;
                }
            }
            if (!empty($existRefund)) {
                return $this->throwErrorMsg(['msg' => '该支付流水号已存在或正在退款,无法继续生成订单哦'], $notThrowError);
            }
            $existWinOrder = PpylOrder::where(['pay_no' => $data['pay_no'], 'win_status' => [1]])->count();
            if (!empty($existWinOrder)) {
                return $this->throwErrorMsg(['msg' => '该支付流水号无法继续复用'], $notThrowError);
            }
        }
        $payOrderSnLockKey = null;
        if ($orderInfo['pay_type'] == 4 && !empty($data['pay_order_sn'] ?? null)) {
            //上复用订单的缓存锁
            $payOrderSnLockKey = 'payOrderSnLock-' . $data['pay_order_sn'];
            if (!empty(cache($payOrderSnLockKey))) {
                return false;
            }
            if (!empty($payOrderSnLockKey ?? null)) {
                //加复用订单的缓存锁
                cache($payOrderSnLockKey, $data, 10);
            }
            $existPayOrder = PpylOrder::where(['pay_order_sn' => $data['pay_order_sn']])->count();
            if (!empty($existPayOrder)) {
                return $this->throwErrorMsg(['errorCode' => 2700125], $notThrowError);
            }
        }

        $activityCode = $areaInfo['activity_code'];
        $changeJoinType = false;
//        $orderGoods = OrderGoods::where(['order_sn' => $orderInfo['order_sn'], 'status' => 1])->field('sku_sn,goods_sn,order_sn,count')->select()->toArray();
        $orderGoods = $orders['goodsRes'] ?? [];

        //添加拼拼有礼订单商品,以便后续同步回主订单表使用
        if (!empty($orderGoods)) {
            (new PpylOrderGoods())->saveAll($orderGoods);
        }

        if (empty($joinType)) {
            return $this->throwErrorMsg(['msg' => '请选择拼团参加类型']);
        }
        //如果是支付流水号来重新支付,需要校验一下订单
        if ($orderInfo['pay_type'] == 4) {
            $payNoOrder = (new PpylOrder())->where(['pay_no' => $pay_no])->order('create_time asc')->findOrEmpty()->toArray();
            if (empty($payNoOrder)) {
                return $allRes['errorMsg'] = '查无原支付流水号订单';
            }
            $allRes['payNoOrder'] = $payNoOrder;
            if ((string)$payNoOrder['real_pay_price'] != (string)$orderInfo['real_pay_price']) {
                $allRes['errorMsg'] = '流水号对应的原订单实付金额与当前订单支付金额不符,无法继续支付';
                return $allRes;
            }
            //查看利用该流水号的订单是否有中奖或退款的记录,有则不允许重新复用
            $usePayNoOrder = (new PpylOrder())->where(['pay_no' => $pay_no])->order('create_time asc')->select()->toArray();
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




        if ($joinType == 2) {

            $activitySn = $data['activity_sn'] ?? null;
            //自动找团或进入排队
            if (empty($activitySn)) {
                $data['uid'] = $orderInfo['uid'];
                $data['allOrderRes'] = $orders;
                //如果找不到可以参的团直接修改为开团类型
                $data['notGroupDealType'] = 2;
                $findGroupRes = $this->helpUserCheckWaitGroup($data);
                if (!empty($findGroupRes) && empty($findGroupRes['success'])) {
                    return $this->throwErrorMsg(['msg' => $findGroupRes['msg']]);
                }

                if (!empty($findGroupRes) && !empty($findGroupRes['success'])) {
                    if (!empty($findGroupRes['activity_sn'] ?? false)) {
                        $activitySn = $findGroupRes['activity_sn'];
                    } elseif (!empty($findGroupRes['data'])) {
                        //顺便创建自动排队计划
                        if (!empty($data['autoPpyl'] ?? false)) {
                            $plan['activity_code'] = $activityCode;
                            $plan['area_code'] = $areaCode;
                            $plan['goods_sn'] = $data['goods_sn'];
                            $plan['order_sn'] = ($findGroupRes['data']['order_sn'] ?? null);
                            $plan['sku_sn'] = $data['sku_sn'];
                            $plan['uid'] = $data['uid'];
                            $plan['user_role'] = $data['ppyl_join_type'];
                            $plan['real_pay_price'] = $orderInfo['real_pay_price'] ?? null;
                            $plan['notThrowError'] = $data['notThrowError'] ?? false;
                            //自动参团只能用余额支付或订单支付流水号
                            if($data['pay_type'] == 1){
                                $plan['pay_type'] = 1;
                            }else{
                                $plan['pay_type'] = 4;
                                $plan['pay_no'] = $data['pay_no'] ?? ($findGroupRes['data']['pay_no'] ?? null);
                            }
                            $this->createAutoPpylOrder($plan);
                        }

                        //解锁
                        if(!empty($lockKey ?? null)){
                            cache($lockKey,null);
                        }

                        if (!empty($payOrderSnLockKey ?? null)) {
                            //加复用订单的缓存锁
                            cache($payOrderSnLockKey, null);
                        }

                        return ['waitOrder' => true, 'data' => $findGroupRes['data'] ?? []];
                    }
                }
            }

            $ptOrderParent = PpylOrder::where(['activity_code' => $activityCode, 'activity_sn' => $activitySn, 'status' => 1, 'user_role' => 1, 'pay_status' => 2, 'activity_status' => [1]])->findOrEmpty()->toArray();
            //如果需要修改参团类型 则强制修改为开团
            if(!empty($findGroupRes) && !empty($findGroupRes['success']) && !empty($findGroupRes['changeJoinType'] ?? false)){
                //检查是否可以开团
                $data['number'] = 1;
                $checkStart = $this->startPtActivityCheck($data);
                $ptOrderParent = [];
                $activitySn = (new CodeBuilder())->buildPpylActivityCode();
                $joinType = 1;
                $data['ppyl_join_type'] = 1;
                //标记原始为参团,下次自动计划还是参团
                $changeJoinType = 2;
            }
        } else {
            $ptOrderParent = [];
            $activitySn = (new CodeBuilder())->buildPpylActivityCode();
        }
        //拼团活动信息
        $ptInfo = PpylActivity::where(['activity_code' => $activityCode, 'status' => 1])->findOrEmpty()->toArray();

        $skuInfo = PpylGoodsSku::where(['activity_code' => $activityCode, 'area_code' => $areaCode, 'status' => 1, 'goods_sn' => $data['goods_sn'], 'sku_sn' => $data['sku_sn']])->findOrEmpty()->toArray();

        $pt['activity_sn'] = $activitySn;
        $pt['activity_code'] = $activityCode;
        $pt['area_code'] = $areaCode;
        $pt['area_name'] = $areaInfo['name'] ?? '未知专区';
        $pt['reward_price'] = $skuInfo['reward_price'];
        $pt['uid'] = $orderInfo['uid'];
        $pt['order_sn'] = $orderInfo['order_sn'];
        $pt['goods_sn'] = $data['goods_sn'];
        $pt['sku_sn'] = $data['sku_sn'];
        $pt['real_pay_price'] = $orderInfo['real_pay_price'];
        $pt['user_role'] = $joinType ?? 1;
        $pt['pay_type'] = $data['pay_type'] ?? 1;
        $pt['pay_no'] = $data['pay_no'] ?? null;
        $pt['is_restart_order'] = $data['is_restart_order'] ?? 2;
        $pt['pay_order_sn'] = $data['pay_order_sn'] ?? null;
        $pt['is_auto_order'] = $data['is_auto_order'] ?? 2;
        $pt['auto_plan_sn'] = $data['auto_plan_sn'] ?? null;
        //初始概率为100
        $pt['scale'] = 100;

        //判断每个人的中奖概率,如果已在团中的人中奖概率都为0, 若是参团的最后一个人则不允许继续参团
        $pwMap = [];
        switch ($areaInfo['win_limit_type'] ?? 1) {
            case 1:
                $timeStart = null;
                $timeEnd = null;
                $needLimit = true;
                break;
            case 2:
                $timeStart = date('Y-m-d', time()) . ' 00:00:00';
                $timeEnd = date('Y-m-d', time()) . ' 23:59:59';
                $needLimit = true;
                break;
            case 3:
                //当前日期
                $sdefaultDate = date("Y-m-d");
                //$first =1 表示每周星期一为开始日期 0表示每周日为开始日期
                $first = 1;
                //获取当前周的第几天 周日是 0 周一到周六是 1 - 6
                $w = date('w', strtotime($sdefaultDate));
                $timeStart = date('Y-m-d', strtotime("$sdefaultDate -" . ($w ? $w - $first : 6) . ' days')) . ' 00:00:00';
                //本周结束日期
                $timeEnd = date('Y-m-d', strtotime("$timeStart +6 days")) . ' 23:59:59';
                $needLimit = true;
                break;
            case 4:
                //本月的开始和结束
                $timeStart = date('Y-m-01', strtotime(date("Y-m-d"))) . ' 00:00:00';
                $timeEnd = date('Y-m-d', strtotime("$timeStart +1 month -1 day")) . ' 23:59:59';
                $needLimit = true;
                break;
        }
        if (!empty($timeStart) && !empty($timeEnd)) {
            $pwMap[] = ['group_time', '>=', strtotime($timeStart)];
            $pwMap[] = ['group_time', '<=', strtotime($timeEnd)];
        }
        $pwMap[] = ['uid', '=', $orderInfo['uid']];
        $pwMap[] = ['activity_code', '=', $activityCode];
        $pwMap[] = ['area_code', '=', $areaCode];
        $pwMap[] = ['activity_status', 'in', [2]];
        $pwMap[] = ['win_status', '=', 1];
//        $pwMap[] = ['goods_sn', '=', $data['goods_sn']];

        //判断该用户参加拼团活动中奖的次数
        $winPtNumber = PpylOrder::where($pwMap)->count();

        if (intval($winPtNumber) >= ($areaInfo['win_number'] ?? 0)) {
            $pt['scale'] = 0;
        }

        if ($joinType == 2) {
            //检查参团人数是否超过成团人数要求
            $teamUserId = PpylOrder::where(['activity_code' => $activityCode, 'area_code' => $areaCode, 'activity_sn' => $activitySn, 'status' => 1, 'pay_status' => [1, 2], 'activity_status' => [1]])->column('id');
            if (empty($teamUserId) || empty($ptOrderParent)) {
                return $this->throwErrorMsg(['msg' => '该团已失效,无法参团']);
            }
            $teamUsers = PpylOrder::where(['id' => $teamUserId])->lock(true)->select()->toArray();
            if (count($teamUsers) + 1 > $ptInfo['group_number']) {
                return $this->throwErrorMsg(['errorCode' => 2100110]);
            }


            //如果是参团的最后一个人,概率还是0,则不允许加入团
            if (($pt['scale'] ?? 0) <= 0) {
                $haveUserWin = false;
                foreach ($teamUsers as $key => $value) {
                    if ($value['scale'] > 0) {
                        $haveUserWin = true;
                    }
                }
                if (empty($haveUserWin) && (count($teamUsers) + 1 == $ptInfo['group_number'])) {
                    return $this->throwErrorMsg(['errorCode' => 2100120]);
                }
            }


            $pt['start_time'] = strtotime($ptOrderParent['start_time']);
            $pt['end_time'] = strtotime($ptOrderParent['end_time']);
            $pt['group_number'] = $ptOrderParent['group_number'];
            $pt['join_user_type'] = $ptOrderParent['join_user_type'];
            $pt['activity_type'] = $ptOrderParent['activity_type'];
            $pt['activity_title'] = $ptOrderParent['activity_title'];
        } else {
            //开团需要判断当前可开团次数
            $ptSkuId = PpylGoodsSku::where(['activity_code' => $activityCode, 'goods_sn' => $data['goods_sn'], 'sku_sn' => $data['sku_sn'], 'status' => 1])->column('id');
            if (empty($ptSkuId)) {
                return $this->throwErrorMsg(['msg' => '存在失效的拼团商品哦,无法继续下单']);
            }
            $ptSkuInfo = PpylGoodsSku::where(['id' => $ptSkuId])->lock(true)->findOrEmpty()->toArray();
            if ($ptSkuInfo['start_number'] <= 0) {
                return $this->throwErrorMsg(['errorCode' => 2100112]);
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
        $res = PpylOrder::create($pt);

        //如果是支付流水号的订单,需要取消原订单对应的退款操作
        if ($pt['pay_type'] == 4 && !empty($pt['pay_order_sn'] ?? false)) {
            PpylOrder::update(['can_operate_refund' => 2], ['order_sn' => $pt['pay_order_sn']]);
        }

        //创建自动排队计划
        if (!empty($data['autoPpyl'] ?? false)) {
            $plan['activity_code'] = $activityCode;
            $plan['area_code'] = $areaCode;
            $plan['goods_sn'] = $data['goods_sn'];
            $plan['sku_sn'] = $data['sku_sn'];
            $plan['uid'] = $orderInfo['uid'];
            if(!empty($changeJoinType ?? false)){
                $plan['user_role'] = $changeJoinType;
            }else{
                $plan['user_role'] = $data['ppyl_join_type'];
            }
            $plan['order_sn'] = $orderInfo['order_sn'] ?? null;
//            $plan['user_role'] = $joinType ?? 2;
            $plan['notThrowError'] = $data['notThrowError'] ?? false;
            $plan['real_pay_price'] = $orderInfo['real_pay_price'] ?? null;
            //自动参团只能用余额支付或订单支付流水号
            if($data['pay_type'] == 1){
                $plan['pay_type'] = 1;
            }else{
                $plan['pay_type'] = 4;
                $plan['pay_no'] = $data['pay_no'] ?? null;
            }
            $this->createAutoPpylOrder($plan);
        }

        //修改拼团商品库存,只有开团才减少一次库存
        if ($joinType == 1) {
            if (!empty($orderGoods)) {
                $ptGoodsSkuModel = (new PpylGoodsSku());
                //锁行
                $ptGoodsSkuId = $ptGoodsSkuModel->where(['activity_code' => $activityCode, 'area_code' => $areaCode, 'goods_sn' => $pt['goods_sn'], 'sku_sn' => $pt['sku_sn'], 'status' => 1])->column('id');
                if (empty($ptGoodsSkuId)) {
                    throw new PpylException(['errorCode' => 2100111]);
                }
                $ptGoods = $ptGoodsSkuModel->where(['id' => $ptGoodsSkuId])->field('stock,start_number')->lock(true)->findOrEmpty()->toArray();
                foreach ($orderGoods as $key => $value) {
                    $ptGoodsSkuModel->where(['activity_code' => $activityCode, 'area_code' => $areaCode, 'goods_sn' => $value['goods_sn'], 'sku_sn' => $value['sku_sn']])->dec('stock', intval($value['count']))->update();
                    //如果是开团需要减少开团次数
                    if ($joinType == 1) {
                        $ptGoodsSkuModel->where(['activity_code' => $activityCode, 'area_code' => $areaCode, 'goods_sn' => $value['goods_sn'], 'sku_sn' => $value['sku_sn']])->dec('start_number', 1)->update();
                    }
                }

            }
        }

        if (!empty($lockKey ?? null)) {
            //清除缓存锁
            cache($lockKey, null);
        }

        if (!empty($payOrderSnLockKey ?? null)) {
            //加复用订单的缓存锁
            cache($payOrderSnLockKey, null);
        }


        return $res->getData();
    }

    /**
     * @title  新建自动拼团计划
     * @param array $data
     * @return bool
     */
    public function createAutoPpylOrder(array $data)
    {
        $endTime = strtotime(date('Y-m-d', time()) . ' 23:59:59');
        //今天内如果已经有一单自动计划了则不继续新建
        $cMap[] = ['activity_code', '=', $data['activity_code']];
        $cMap[] = ['area_code', '=', $data['area_code']];
        $cMap[] = ['goods_sn', '=', $data['goods_sn']];
        $cMap[] = ['sku_sn', '=', $data['sku_sn']];
        $cMap[] = ['uid', '=', $data['uid']];
//        $cMap[] = ['user_role', '=', $data['user_role'] ?? 2];
        $cMap[] = ['end_time', '<=', $endTime];
        $cMap[] = ['status', '=', 1];
        $notThrowError = $data['notThrowError'] ?? false;
        $this->notThrowError = $notThrowError;

        $existAuto = PpylAuto::where($cMap)->count();

        if (!empty($existAuto)) {
            return false;
        }
        $userInfo = User::where(['uid' => $data['uid'], 'status' => 1])->field('uid,phone,c_vip_level,c_vip_time_out_time')->findOrEmpty()->toArray();

        //不是CVIP或者不在有效期内的CVIP不允许新建自动拼团计划
        if (empty($userInfo['c_vip_level']) || (!empty($userInfo['c_vip_level'] && $userInfo['c_vip_time_out_time'] <= time()))) {
            return false;
        }

        //一个计划默认为当天内有效
        $newPlan['plan_sn'] = (new CodeBuilder())->buildPlanNo();
        $newPlan['activity_code'] = $data['activity_code'];
        $newPlan['area_code'] = $data['area_code'];
        $newPlan['goods_sn'] = $data['goods_sn'];
        $newPlan['sku_sn'] = $data['sku_sn'];
        $newPlan['user_role'] = $data['user_role'] ?? 2;
        $newPlan['order_sn'] = $data['order_sn'] ?? null;
        $newPlan['uid'] = $data['uid'];
        $newPlan['pay_type'] = $data['pay_type'] ?? 1;
        $newPlan['pay_no'] = $data['pay_no'] ?? null;
        $newPlan['real_pay_price'] = $data['real_pay_price'] ?? 0;
        $newPlan['start_time'] = time();
        $newPlan['end_time'] = $endTime;
        $DBRes = PpylAuto::create($newPlan);

        return judge($DBRes);
    }

    /**
     * @title  重开自动拼团计划
     * @param array $data
     * @return array|bool
     * @throws \Exception
     */
    public function restartAutoPpylOrder(array $data)
    {
        $planSn = $data['plan_sn'] ?? null;
        if (empty($planSn)) {
            return false;
        }

        $log['msg'] = '已接受到拼拼有礼自动拼团计划 ' . $data['plan_sn'] . ' 的重开操作,稍后会有其他日志记录详细信息,此处仅做重开操作记录';
        $log['channel'] = $data['channel'] ?? null;
        $log['data'] = $data;
        $this->log($log, 'info', 'ppylAuto');

        if (!empty(cache($planSn))) {
            $log['msg'] = '已接受到拼拼有礼自动拼团计划 ' . $data['plan_sn'] . ' 的重开操作,但该计划正在重启中,防止并发,此次需要锁住';
            $log['channel'] = $data['channel'] ?? null;
            $log['data'] = $data;
            $this->log($log, 'debug', 'ppylAuto');
            return false;
        }
        cache($planSn, $planSn, 15);

        $log['msg'] = '已接受到拼拼有礼自动拼团计划 ' . $data['plan_sn'] . ' 的重开操作';
        $log['data'] = $data;

        //restart_type 1为系统内部重新开启 2为用户或管理员手动重开,restart_type2的情况会重置计划的结束时间
        $restartType = $data['restart_type'] ?? 1;

        $cMap[] = ['plan_sn', '=', $planSn];
        $cMap[] = ['status', 'in', [1, 2, 3]];
        if ($restartType == 1) {
            $notThrowError = 1;
        } else {
            $notThrowError = $data['notThrowError'] ?? false;
        }

        $this->notThrowError = $notThrowError;

        $existAuto = PpylAuto::where($cMap)->findOrEmpty()->toArray();
        $log['autoInfo'] = $existAuto;

//        return $this->throwErrorMsg(['msg' => '该团已失效,无法参团']);
        if (empty($existAuto)) {
            $log['dealRes'] = '计划不存在哦,无法重新开启计划';
            $this->log($log, 'debug', 'ppylAuto');
            return $this->throwErrorMsg(['msg' => '计划不存在哦,无法重新开启计划']);
        }
        //如果订单支付流水号为空尝试查找
        if ($existAuto['pay_type'] == 4 && empty($existAuto['pay_no'])) {
            if (!empty($existAuto['order_sn'])) {
                $payNoOrder = PpylOrder::where(['order_sn' => $existAuto['order_sn']])->value('pay_no');
                if (empty($payNoOrder)) {
                    $payNoOrder = PpylWaitOrder::where(['order_sn' => $existAuto['order_sn']])->value('pay_no');
                }
            } else {
                $payNoOrder = PpylOrder::where(['order_sn' => current(explode(',', $existAuto['restart_order_sn']))])->value('pay_no');
            }
            if (empty($payNoOrder)) {
                $payNoOrder = $data['pay_no'] ?? null;
            }

            if (empty($payNoOrder)) {
                $log['dealRes'] = '查无原始支付流水号,停止计划';

                $aFailAuto['remark'] = $log['dealRes'];
                $aFailAuto['status'] = 3;
                PpylAuto::update($aFailAuto, ['plan_sn' => $planSn, 'status' => 1]);

                $this->log($log, 'debug', 'ppylAuto');
                return $this->throwErrorMsg(['msg' => $log['dealRes']]);
            }
        }

        $userInfo = User::where(['uid' => $existAuto['uid'], 'status' => 1])->field('uid,phone,c_vip_level,c_vip_time_out_time')->findOrEmpty()->toArray();

        //不是CVIP或者不在有效期内的CVIP不允许重启自动拼团计划
        if (empty($userInfo['c_vip_level']) || (!empty($userInfo['c_vip_level'] && $userInfo['c_vip_time_out_time'] <= time()))) {
            $log['dealRes'] = '您不是尊贵的CVIP,无法执行智能计划';
            $this->log($log, 'debug', 'ppylAuto');

            return $this->throwErrorMsg(['msg' => '您不是尊贵的CVIP,无法执行智能计划']);
        }

        //检查拼参团
        $check['uid'] = $existAuto['uid'];
        $check['area_code'] = $existAuto['area_code'];
        $check['goods_sn'] = $existAuto['goods_sn'];
        $check['sku_sn'] = $existAuto['sku_sn'];
        $check['number'] = 1;
        $check['notThrowError'] = $notThrowError;
        if ($existAuto['user_role'] == 1) {
            $checkRes = $this->startPtActivityCheck($check);
        }
//        else {
//            //自动找团或进入排队
//            $check['allOrderRes']['orderRes']['order_sn'] = $existAuto['order_sn'];
//            $check['allOrderRes']['orderRes']['uid'] = $existAuto['uid'];
//            $check['allOrderRes']['orderRes']['real_pay_price'] = $existAuto['real_pay_price'];
//            $check['allOrderRes']['goodsRes'] = [];
//            $findGroupRes = $this->helpUserCheckWaitGroup($check);
//            if (!empty($findGroupRes) && empty($findGroupRes['success'])) {
//                //失败则中止团
//                $failAuto['remark'] = $findGroupRes['msg'];
//                $failAuto['status'] = 3;
//                PpylAuto::update($failAuto, ['plan_sn' => $planSn,'status'=>1]);
//
//                return $this->throwErrorMsg(['msg' => $findGroupRes['msg']]);
//            }
//
//            if (!empty($findGroupRes) && !empty($findGroupRes['success'])) {
//                if (!empty($findGroupRes['activity_sn'] ?? false)) {
//                    $activitySn = $findGroupRes['activity_sn'];
//                    $checkRes['activity_sn'] = $activitySn;
//                } elseif (!empty($findGroupRes['data'])) {
//                    $log['dealRes'] = $findGroupRes ?? [];
//                    $log['dealMsg'] = '已重新进入排队队伍中';
//                    $this->log($log, 'info','ppylAuto');
//
//                    return $this->throwErrorMsg(['msg' => $log['dealRes']]);
//                }
//            }
//            $checkRes = $this->joinPtActivityCheck($check);
//        }

        if (!empty($checkRes) && empty($checkRes['success'])) {
            $log['dealRes'] = $checkRes['msg'] ?? '无法执行计划';
            $this->log($log, 'error', 'ppylAuto');

            //不可开/参团则中止自动计划
            $failAuto['remark'] = $log['dealRes'];
            $failAuto['status'] = 3;
            PpylAuto::update($failAuto, ['plan_sn' => $planSn, 'status' => 1]);

            return $this->throwErrorMsg(['msg' => $log['dealRes']]);
        }

        //预订单检查商品,如果抛异常了则证明无法执行,则不修改计划
        $ready['uid'] = $existAuto['uid'];
        $ready['order_type'] = 4;
        $ready['city'] = '4401';
        $ready['province'] = '44';
        $ready['area'] = '440113';
        $ready['attach_type'] = -1;
        $ready['uc_code'] = [];
        $ready['integral'] = 0;
        $ready['usedIntegralDis'] = 2;
        $ready['usedCouponDis'] = 2;
        $ready['readyType'] = 1;
        $ready['pay_type'] = $existAuto['pay_type'];
        $ready['pay_no'] = $existAuto['pay_no'] ?? null;
        $ready['pay_order_sn'] = $data['pay_order_sn'] ?? null;
        $ready['belong'] = 4;
        $ready['ppyl_join_type'] = $existAuto['user_role'];
        $goodsInfo['goods_sn'] = $existAuto['goods_sn'];
        $goodsInfo['sku_sn'] = $existAuto['sku_sn'];
        $goodsInfo['activity_id'] = $existAuto['area_code'];
        $goodsInfo['number'] = 1;
        $goodsInfo['attach_type'] = -1;
        $ready['goods'][] = $goodsInfo;
        $log['orderReady'] = $ready;

        //先停止自动拼团计划,等订单创建成功了再重新开启,如果订单创建抛异常了则证明无法执行,则不修改计划
        PpylAuto::update(['status' => 3, 'fail_msg' => '订单创建失败'], ['plan_sn' => $planSn, 'status' => 1]);
        $result = false;
        $orderReady = (new OrderService())->readyOrder($ready);

        $log['orderReadyRes'] = $orderReady ?? [];
        $orderReady['real_pay_price'] = $orderReady['realPayPrice'];
        $orderReady['total_price'] = $orderReady['order_amount'];
        $orderReady['discount_price'] = $orderReady['allDisPrice'];
        $orderReady['fare_price'] = $orderReady['fare'];
        $orderReady['used_integral'] = $orderReady['usedIntegral'];
        $orderReady['is_restart_order'] = 1;
        $orderReady['belong'] = 4;
        $orderReady['is_auto_order'] = 1;
        $orderReady['auto_plan_sn'] = $planSn;

        if (!empty($orderReady)) {
            $orderCreat = (new OrderService())->buildOrder($orderReady);
            $log['orderCreatRes'] = $orderCreat ?? [];

            //创建订单成功且支付成功
            if (!empty($orderCreat) && !empty($orderCreat['complete_pay'])) {
                $result = true;
                if ($restartType == 2) {
                    $update['status'] = 1;
                    $update['end_time'] = strtotime(date('Y-m-d', time()) . ' 23:59:59');
                    $update['fail_msg'] = null;
                } else {
                    $update['status'] = 1;
                    $update['fail_msg'] = null;
                }

                if (empty($existAuto['restart_order_sn'])) {
                    $update['restart_order_sn'] = $orderCreat['order_sn'];
                } else {
                    $update['restart_order_sn'] = $existAuto['restart_order_sn'] . ',' . $orderCreat['order_sn'];
                }

                if (!empty($update)) {
                    $DBRes = PpylAuto::update($update, ['plan_sn' => $planSn]);
                }
            }
        }
        //取消缓存锁
        cache($planSn,null);

        $log['dealRes'] = $result ?? [];
        $this->log($log, 'info', 'ppylAuto');

        return judge($result ?? false);
    }


    /**
     * @title  拼团订单开团前检验
     * @param array $data
     * @return mixed
     * @throws \Exception
     */
    public function startPtActivityCheck(array $data)
    {
        $activityCode = $data['activity_code'] ?? null;
        $areaCode = $data['area_code'];
        $uid = $data['uid'];
        $goodsSn = $data['goods_sn'];
        $sku_sn = $data['sku_sn'];
        $number = $data['number'];
        $notThrowError = $data['notThrowError'] ?? false;
        $this->notThrowError = $notThrowError;

        if (intval($number) != 1) {
            return $this->throwErrorMsg(['errorCode' => 2100108]);
        }
        $ppylArea = PpylArea::where(['area_code' => $areaCode, 'status' => 1])->findOrEmpty()->toArray();
        if (empty($ppylArea)) {
            return $this->throwErrorMsg(['errorCode' => 2100101]);
        }

        //判断是否在开团时间,两个时间分开判断的原因是为了两个不同的提示
        if (strtotime($ppylArea['start_time']) > time()) {
            return $this->throwErrorMsg(['errorCode' => 2100102]);
        }
        //判断是否已经结束
        if (strtotime($ppylArea['end_time']) <= time()) {
            return $this->throwErrorMsg(['errorCode' => 2100103]);
        }
        //判断中奖次数是否为0, 如果为0则等于不允许任何人参加
        if (empty($ppylArea['win_number'])) {
            return $this->throwErrorMsg(['msg' => '您的参与次数已达上限~感谢您的支持']);
        }

        $activityCode = $ppylArea['activity_code'];
        $ptInfo = PpylActivity::where(['activity_code' => $activityCode, 'status' => 1])->findOrEmpty()->toArray();
        if (empty($ptInfo)) {
            return $this->throwErrorMsg(['errorCode' => 2100101]);
        }
        $userInfo = User::with(['member'])->where(['uid' => $uid])->findOrEmpty()->toArray();
        if (empty($userInfo)) {
            return $this->throwErrorMsg(['errorCode' => 2100115]);
        }
        $userTypes = CouponUserType::where(['status' => 1])->select()->toArray();
        foreach ($userTypes as $key => $value) {
            $userType[$value['u_type']] = $value;
        }
        //判断活动类型及开团对象
        switch ($ptInfo['start_user_type']) {
            case 2:
                $userOrder = PpylOrder::where(['uid' => $uid, 'activity_status' => [1, 2, -2, -3]])->count();
                if (!empty($userOrder)) {
                    return $this->throwErrorMsg(['msg' => '本次活动仅限新用户开团~您暂不符合要求']);
                }
                break;
            case 3:
                if (empty($userInfo['vip_level'])) {
                    return $this->throwErrorMsg(['msg' => '本次活动仅限会员用户开团~您暂不符合要求']);
                }
                break;
            case 4:
                break;
            case 5:
            case 6:
            case 7:
                if (empty($userInfo) || ($userInfo['vip_level'] != $userType[$ptInfo['start_user_type']]['vip_level'])) {
                    return $this->throwErrorMsg(['msg' => '本次活动仅限' . $userType[$ptInfo['start_user_type']]['u_name'] . '开团~您暂不符合要求']);
                }
                break;
            case 8:
                $userOrder = PpylOrder::where(['uid' => $uid, 'activity_status' => [1, 2, -2, -3]])->count();
                if (empty($userOrder)) {
                    return $this->throwErrorMsg(['msg' => '本次活动仅限老用户开团~您暂不符合要求']);
                }
                break;
            case 9:
                if (!empty($userInfo['vip_level'])) {
                    return $this->throwErrorMsg(['msg' => '本次活动仅限普通用户开团~您暂不符合要求']);
                }
                break;
            case 10:
                if (empty($userInfo['c_vip_level']) || (($userInfo['c_vip_time_out_time'] ?? 0) <= time())) {
                    return $this->throwErrorMsg(['msg' => '本次活动仅限CVIP开团~您暂不符合要求']);
                }
                break;

        }

        //判断是否在开团时间,两个时间分开判断的原因是为了两个不同的提示
        if (strtotime($ptInfo['start_time']) > time()) {
            return $this->throwErrorMsg(['errorCode' => 2100102]);
        }
        //判断是否已经结束
        if (strtotime($ptInfo['end_time']) <= time()) {
            return $this->throwErrorMsg(['errorCode' => 2100103]);
        }
        $prOrder = (new PpylOrder());
        //判断是否该拼团活动还存在拼团中的订单,如果存在则不允许重新开团
        $oldPtOrder = $prOrder->where(['uid' => $uid, 'activity_code' => $activityCode, 'goods_sn' => $goodsSn, 'area_code' => $areaCode, 'activity_status' => 1])->findOrEmpty()->toArray();
        if (!empty($oldPtOrder)) {
            if ($oldPtOrder['pay_status'] == 1) {
                return $this->throwErrorMsg(['errorCode' => 2100119]);
            }
            return $this->throwErrorMsg(['errorCode' => 2100104]);
        }

        $ppMap = [];
        $ppWaitMap = [];
        switch ($ppylArea['join_limit_type'] ?? 1) {
            case 1:
                $timeStart = null;
                $timeEnd = null;
                $needLimit = true;
                break;
            case 2:
                $timeStart = date('Y-m-d', time()) . ' 00:00:00';
                $timeEnd = date('Y-m-d', time()) . ' 23:59:59';
                $needLimit = true;
                break;
            case 3:
                //当前日期
                $sdefaultDate = date("Y-m-d");
                //$first =1 表示每周星期一为开始日期 0表示每周日为开始日期
                $first = 1;
                //获取当前周的第几天 周日是 0 周一到周六是 1 - 6
                $w = date('w', strtotime($sdefaultDate));
                $timeStart = date('Y-m-d', strtotime("$sdefaultDate -" . ($w ? $w - $first : 6) . ' days')) . ' 00:00:00';
                //本周结束日期
                $timeEnd = date('Y-m-d', strtotime("$timeStart +6 days")) . ' 23:59:59';
                $needLimit = true;
                break;
            case 4:
                //本月的开始和结束
                $timeStart = date('Y-m-01', strtotime(date("Y-m-d"))) . ' 00:00:00';
                $timeEnd = date('Y-m-d', strtotime("$timeStart +1 month -1 day")) . ' 23:59:59';
                $needLimit = true;
                break;
        }
        if (!empty($timeStart) && !empty($timeEnd)) {
            $ppMap[] = ['create_time', '>=', strtotime($timeStart)];
            $ppMap[] = ['create_time', '<=', strtotime($timeEnd)];
        }
        $ppMap[] = ['uid', '=', $uid];
        $ppMap[] = ['activity_code', '=', $activityCode];
        $ppMap[] = ['area_code', '=', $areaCode];
        $ppMap[] = ['activity_status', 'in', [1, 2, -3]];
//        if ($ptInfo['style_type'] != 2) {
//            $ppMap[] = ['goods_sn', '=', $goodsSn];
//        }

        //判断该用户参加拼团活动的次数
        $joinPtNumber = $prOrder->where($ppMap)->count();

        //查询排队中的订单
        if (!empty($timeStart) && !empty($timeEnd)) {
            $ppWaitMap[] = ['create_time', '>=', strtotime($timeStart)];
            $ppWaitMap[] = ['create_time', '<=', strtotime($timeEnd)];
        }
        $ppWaitMap[] = ['uid', '=', $uid];
        $ppWaitMap[] = ['activity_code', '=', $activityCode];
        $ppWaitMap[] = ['area_code', '=', $areaCode];
        $ppWaitMap[] = ['wait_status', 'in', [1]];

        //判断该用户正在排队中的次数
        $waitPPylNumber = PpylWaitOrder::where($ppWaitMap)->count();

        if (intval($joinPtNumber + ($waitPPylNumber - 1)) >= $ppylArea['join_number']) {
            return $this->throwErrorMsg(['errorCode' => 2100105]);
        }

        //用户手动触发的操作需要判断是否有自动拼团计划或者排队订单,如果有则不允许继续下单
        if (!empty($data['userTrigger'] ?? false)) {
            //判断用户是否有自动拼团计划,如果有则不允许继续手动参加
            $aMap[] = ['uid', '=', $uid];
            $aMap[] = ['activity_code', '=', $activityCode];
            $aMap[] = ['area_code', '=', $areaCode];
            $aMap[] = ['goods_sn', '=', $goodsSn];
            $aMap[] = ['status', '=', 1];
            $aMap[] = ['end_time', '>', time()];
            $autoOrder = PpylAuto::where($aMap)->count();
            if (!empty($autoOrder)) {
                return $this->throwErrorMsg(['errorCode' => 2100118]);
            }

            //判断是否该拼团活动还存在排队中的订单,如果存在则不允许重新参团
            $cMap[] = ['uid', '=', $uid];
            $cMap[] = ['activity_code', '=', $activityCode];
            $cMap[] = ['area_code', '=', $areaCode];
            $cMap[] = ['goods_sn', '=', $goodsSn];
            $cMap[] = ['wait_status', '=', 1];

            $cMapOrOne[] = ['timeout_time', '>', time()];
            $cMapOrOne[] = ['', 'exp', Db::raw('timeout_time is not null')];
            $cMapOrTwo[] = ['', 'exp', Db::raw('timeout_time is null')];

            $waitPtOrder = PpylWaitOrder::where($cMap)->where(function ($query) use ($cMapOrOne, $cMapOrTwo) {
                $query->whereOr([$cMapOrOne, $cMapOrTwo]);
            })->findOrEmpty()->toArray();

            if (!empty($waitPtOrder)) {
                return $this->throwErrorMsg(['errorCode' => 2100116]);
            }
        }


        //判断活动商品活动库存
        $checkStock = Db::transaction(function () use ($activityCode, $areaCode, $goodsSn, $sku_sn, $ptInfo, $notThrowError) {
            $ptGoodsSkuId = PpylGoodsSku::where(['activity_code' => $activityCode, 'area_code' => $areaCode, 'goods_sn' => $goodsSn, 'sku_sn' => $sku_sn, 'status' => 1])->column('id');

            $goodsSkuId = GoodsSku::where(['goods_sn' => $goodsSn, 'sku_sn' => $sku_sn])->column('id');
            if (empty($ptGoodsSkuId)) {
                return $this->throwErrorMsg(['errorCode' => 2100111]);
            }

            $ptGoods = PpylGoodsSku::where(['id' => $ptGoodsSkuId])->field('stock,start_number')->lock(true)->findOrEmpty()->toArray();
            $goodsSku = GoodsSku::where(['id' => $goodsSkuId])->field('stock')->lock(true)->findOrEmpty()->toArray();
            //判断开团次数
            if (intval($ptGoods['start_number']) <= 0) {
                return $this->throwErrorMsg(['errorCode' => 2100112]);
            }
            //判断库存,如果总库存大于拼团库存,则认拼团库存,如果总库存比较小,则认总库存
            if ($goodsSku['stock'] >= $ptGoods['stock']) {
//                if (intval($ptGoods['stock']) < intval($ptInfo['group_number'])) {
                if (intval($ptGoods['stock']) < 1) {
                    return $this->throwErrorMsg(['errorCode' => 2100107]);
                }
            } else {
//                if (intval($goodsSku['stock']) < intval($ptInfo['group_number'])) {
                if (intval($goodsSku['stock']) < 1) {
                    return $this->throwErrorMsg(['errorCode' => 2100114]);
                }
            }

            return true;
        });


        return ['success' => true, 'msg' => '成功'];
    }

    /**
     * @title  拼团订单参团前检验
     * @param array $data
     * @return mixed
     * @throws \Exception
     */
    public function joinPtActivityCheck(array $data)
    {
        $activityCode = $data['activity_code'] ?? null;
        $areaCode = $data['area_code'];
        $activitySn = $data['activity_sn'] ?? null;
        if (empty($activitySn)) {
            return $this->throwErrorMsg(['msg' => '缺失主团信息']);
        }
        $uid = $data['uid'];
        $goodsSn = $data['goods_sn'];
        $sku_sn = $data['sku_sn'];
        $number = $data['number'];
        $notThrowError = $data['notThrowError'] ?? false;
        $this->notThrowError = $notThrowError;
        if (intval($number) != 1) {
            return $this->throwErrorMsg(['errorCode' => 2100108]);
        }
        $ppylArea = PpylArea::where(['area_code' => $areaCode, 'status' => 1])->findOrEmpty()->toArray();
        if (empty($ppylArea)) {
            return $this->throwErrorMsg(['errorCode' => 2100101]);
        }
        //判断是否在开团时间,两个时间分开判断的原因是为了两个不同的提示
        if (strtotime($ppylArea['start_time']) > time()) {
            return $this->throwErrorMsg(['errorCode' => 2100102]);
        }
        //判断是否已经结束
        if (strtotime($ppylArea['end_time']) <= time()) {
            return $this->throwErrorMsg(['errorCode' => 2100103]);
        }
        //判断中奖次数是否为0, 如果为0则等于不允许任何人参加
        if (empty($ppylArea['win_number'])) {
            return $this->throwErrorMsg(['msg' => '您的参与次数已达上限~感谢您的支持']);
        }

        $activityCode = $ppylArea['activity_code'];

        $ptInfo = PpylActivity::where(['activity_code' => $activityCode, 'status' => 1])->findOrEmpty()->toArray();
        $ptOrderNumber = PpylOrder::where(['activity_code' => $activityCode, 'area_code' => $areaCode, 'activity_sn' => $activitySn, 'activity_status' => 1, 'status' => 1])->count();
        //拼团活动详情,找团长
        $ptGroupInfo = PpylOrder::where(['activity_code' => $activityCode, 'area_code' => $areaCode, 'activity_sn' => $activitySn, 'activity_status' => 1, 'status' => 1, 'user_role' => 1])->findOrEmpty()->toArray();

        //判断拼团活动是否存在
        if (empty($ptInfo)) {
            return $this->throwErrorMsg(['errorCode' => 2100101]);
        }
        //判断拼团订单是否存在
        if (empty($ptOrderNumber)) {
            return $this->throwErrorMsg(['errorCode' => 2100109]);
        }
        //判断拼团团长订单是否存在
        if (empty($ptGroupInfo)) {
            return $this->throwErrorMsg(['errorCode' => 2100109]);
        }
        //判断已参团人数是否超过全团规定人数
        if (intval($ptOrderNumber) >= $ptInfo['group_number']) {
            return $this->throwErrorMsg(['errorCode' => 2100110]);
        }

        //判断参团的团是否在有效期时间,两个时间分开判断的原因是为了两个不同的提示
        if (strtotime($ptGroupInfo['start_time']) > time()) {
            return $this->throwErrorMsg(['errorCode' => 2100102]);
        }
        //判断参团的团是否已经结束
        if (strtotime($ptGroupInfo['end_time']) <= time()) {
            return $this->throwErrorMsg(['errorCode' => 2100103]);
        }

        $userInfo = User::with(['member'])->where(['uid' => $uid])->findOrEmpty()->toArray();
        $userTypes = CouponUserType::where(['status' => 1])->select()->toArray();
        foreach ($userTypes as $key => $value) {
            $userType[$value['u_type']] = $value;
        }
        //判断活动类型及开团对象
        switch ($ptInfo['join_user_type']) {
            case 2:
//                $userOrder = PpylOrder::where(['uid' => $uid, 'pay_status' => [1, 2, -2]])->count();
                $userOrder = PpylOrder::where(['uid' => $uid, 'activity_status' => [1, 2, -2, -3]])->count();
                if (!empty($userOrder)) {
                    return $this->throwErrorMsg(['msg' => '本次活动仅限新用户参团~您暂不符合要求']);
                }
                break;
            case 3:
                if (empty($userInfo['vip_level'])) {
                    return $this->throwErrorMsg(['msg' => '本次活动仅限会员用户参团~您暂不符合要求']);
                }
                break;
            case 4:
                break;
            case 5:
            case 6:
            case 7:
                if (empty($userInfo) || ($userInfo['vip_level'] != $userType[$ptInfo['start_user_type']]['vip_level'])) {
                    return $this->throwErrorMsg(['msg' => '本次活动仅限' . $userType[$ptInfo['start_user_type']]['u_name'] . '参团~您暂不符合要求']);
                }
                break;
            case 8:
                $userOrder = PpylOrder::where(['uid' => $uid, 'activity_status' => [1, 2, -2, -3]])->count();
                if (empty($userOrder)) {
                    return $this->throwErrorMsg(['msg' => '本次活动仅限老用户参团~您暂不符合要求']);
                }
                break;
            case 9:
                if (!empty($userInfo['vip_level'])) {
                    return $this->throwErrorMsg(['msg' => '本次活动仅限普通用户参团~您暂不符合要求']);
                }
                break;
            case 10:
                if (empty($userInfo['c_vip_level']) || (($userInfo['c_vip_time_out_time'] ?? 0) <= time())) {
                    return $this->throwErrorMsg(['msg' => '本次活动仅限CVIP开团~您暂不符合要求']);
                }
                break;

        }
        //判断是否在开团时间,两个时间分开判断的原因是为了两个不同的提示
        if (strtotime($ptInfo['start_time']) > time()) {
            return $this->throwErrorMsg(['errorCode' => 2100102]);
        }
        //判断是否已经结束
        if (strtotime($ptInfo['end_time']) <= time()) {
            return $this->throwErrorMsg(['errorCode' => 2100103]);
        }
        $prOrder = (new PpylOrder());
        //判断是否该拼团活动还存在拼团中的订单,如果存在则不允许重新参团
        $oldPtOrder = $prOrder->where(['uid' => $uid, 'activity_code' => $activityCode, 'area_code' => $areaCode, 'goods_sn' => $goodsSn, 'activity_status' => 1])->findOrEmpty()->toArray();
        if (!empty($oldPtOrder)) {
            if ($oldPtOrder['pay_status'] == 1) {
                return $this->throwErrorMsg(['errorCode' => 2100119]);
            }
            return $this->throwErrorMsg(['errorCode' => 2100104]);
        }

        $ppMap = [];
        $ppWaitMap = [];
        switch ($ppylArea['join_limit_type'] ?? 1) {
            case 1:
                $timeStart = null;
                $timeEnd = null;
                $needLimit = true;
                break;
            case 2:
                $timeStart = date('Y-m-d', time()) . ' 00:00:00';
                $timeEnd = date('Y-m-d', time()) . ' 23:59:59';
                $needLimit = true;
                break;
            case 3:
                //当前日期
                $sdefaultDate = date("Y-m-d");
                //$first =1 表示每周星期一为开始日期 0表示每周日为开始日期
                $first = 1;
                //获取当前周的第几天 周日是 0 周一到周六是 1 - 6
                $w = date('w', strtotime($sdefaultDate));
                $timeStart = date('Y-m-d', strtotime("$sdefaultDate -" . ($w ? $w - $first : 6) . ' days')) . ' 00:00:00';
                //本周结束日期
                $timeEnd = date('Y-m-d', strtotime("$timeStart +6 days")) . ' 23:59:59';
                $needLimit = true;
                break;
            case 4:
                //本月的开始和结束
                $timeStart = date('Y-m-01', strtotime(date("Y-m-d"))) . ' 00:00:00';
                $timeEnd = date('Y-m-d', strtotime("$timeStart +1 month -1 day")) . ' 23:59:59';
                $needLimit = true;
                break;
        }
        if (!empty($timeStart) && !empty($timeEnd)) {
            $ppMap[] = ['create_time', '>=', strtotime($timeStart)];
            $ppMap[] = ['create_time', '<=', strtotime($timeEnd)];
        }
        $ppMap[] = ['uid', '=', $uid];
        $ppMap[] = ['activity_code', '=', $activityCode];
        $ppMap[] = ['area_code', '=', $areaCode];
        $ppMap[] = ['activity_status', 'in', [1, 2, -3]];
//        if ($ptInfo['style_type'] != 2) {
//            $ppMap[] = ['goods_sn', '=', $goodsSn];
//        }

//        $ppMap[] = ['sku_sn', '=', $sku_sn];

        //判断该用户参加拼团活动的次数
        $joinPtNumber = $prOrder->where($ppMap)->count();

        //查询排队中的订单
        if (!empty($timeStart) && !empty($timeEnd)) {
            $ppWaitMap[] = ['create_time', '>=', strtotime($timeStart)];
            $ppWaitMap[] = ['create_time', '<=', strtotime($timeEnd)];
        }
        $ppWaitMap[] = ['uid', '=', $uid];
        $ppWaitMap[] = ['activity_code', '=', $activityCode];
        $ppWaitMap[] = ['area_code', '=', $areaCode];
        $ppWaitMap[] = ['wait_status', 'in', [1]];

        //判断该用户正在排队中的次数
        $waitPPylNumber = PpylWaitOrder::where($ppWaitMap)->count();

        if (intval($joinPtNumber + ($waitPPylNumber - 1)) >= $ppylArea['join_number']) {
            return $this->throwErrorMsg(['errorCode' => 2100105]);
        }

//        $pwMap = [];
//        switch ($ppylArea['win_limit_type'] ?? 1) {
//            case 1:
//                $wTimeStart = null;
//                $wTimeEnd = null;
//                break;
//            case 2:
//                $wTimeStart = date('Y-m-d', time()) . ' 00:00:00';
//                $wTimeEnd = date('Y-m-d', time()) . ' 23:59:59';
//                break;
//            case 3:
//                //当前日期
//                $wSdefaultDate = date("Y-m-d");
//                //$first =1 表示每周星期一为开始日期 0表示每周日为开始日期
//                $wFirst = 1;
//                //获取当前周的第几天 周日是 0 周一到周六是 1 - 6
//                $ww = date('w', strtotime($wSdefaultDate));
//                $wTimeStart = date('Y-m-d', strtotime("$wSdefaultDate -" . ($ww ? $ww - $wFirst : 6) . ' days')) . ' 00:00:00';
//                //本周结束日期
//                $wTimeEnd = date('Y-m-d', strtotime("$wTimeStart +6 days")) . ' 23:59:59';
//                break;
//            case 4:
//                //本月的开始和结束
//                $wTimeStart = date('Y-m-01', strtotime(date("Y-m-d"))) . ' 00:00:00';
//                $wTimeEnd = date('Y-m-d', strtotime("$wTimeStart +1 month -1 day")) . ' 23:59:59';
//                break;
//        }
//        if (!empty($wTimeStart) && !empty($wTimeEnd)) {
//            $pwMap[] = ['create_time', '>=', strtotime($wTimeStart)];
//            $pwMap[] = ['create_time', '<=', strtotime($wTimeEnd)];
//        }
//        $pwMap[] = ['uid', '=', $uid];
//        $pwMap[] = ['activity_code', '=', $activityCode];
//        $pwMap[] = ['area_code', '=', $areaCode];
//        $pwMap[] = ['activity_status', 'in', [1]];
//        $pwMap[] = ['win_status', '=', 1];
//        $pwMap[] = ['goods_sn', '=', $goodsSn];
//
//        //判断该用户参加拼团活动中奖的次数
//        $winPtNumber = $prOrder->where($pwMap)->count();
//        if (intval($winPtNumber) >= $ppylArea['win_number']) {
//            return $this->throwErrorMsg(['errorCode' => 2100106]);
//        }

        //用户手动触发的操作需要判断是否有自动拼团计划或者排队订单,如果有则不允许继续下单
        if (!empty($data['userTrigger'] ?? false)) {
            //判断用户是否有自动拼团计划,如果有则不允许继续手动参加
            $aMap[] = ['uid', '=', $uid];
            $aMap[] = ['activity_code', '=', $activityCode];
            $aMap[] = ['area_code', '=', $areaCode];
            $aMap[] = ['goods_sn', '=', $goodsSn];
            $aMap[] = ['status', '=', 1];
            $aMap[] = ['end_time', '>', time()];
            $autoOrder = PpylAuto::where($aMap)->count();
            if (!empty($autoOrder)) {
                return $this->throwErrorMsg(['errorCode' => 2100118]);
            }

            //判断是否该拼团活动还存在排队中的订单,如果存在则不允许重新参团
            $cMap[] = ['uid', '=', $uid];
            $cMap[] = ['activity_code', '=', $activityCode];
            $cMap[] = ['area_code', '=', $areaCode];
            $cMap[] = ['goods_sn', '=', $goodsSn];
            $cMap[] = ['wait_status', '=', 1];

            $cMapOrOne[] = ['timeout_time', '>', time()];
            $cMapOrOne[] = ['', 'exp', Db::raw('timeout_time is not null')];
            $cMapOrTwo[] = ['', 'exp', Db::raw('timeout_time is null')];

            PpylWaitOrder::where($cMap)->where(function ($query) use ($cMapOrOne, $cMapOrTwo) {
                $query->whereOr([$cMapOrOne, $cMapOrTwo]);
            })->findOrEmpty()->toArray();
            if (!empty($waitPtOrder)) {
                return $this->throwErrorMsg(['errorCode' => 2100116]);
            }
        }

        //判断活动商品活动库存
        $checkStock = Db::transaction(function () use ($activityCode, $areaCode, $goodsSn, $sku_sn, $ptInfo, $ptOrderNumber, $notThrowError) {
            $ptGoodsSkuId = PpylGoodsSku::where(['activity_code' => $activityCode, 'area_code' => $areaCode, 'goods_sn' => $goodsSn, 'sku_sn' => $sku_sn, 'status' => 1])->column('id');
            $goodsSkuId = GoodsSku::where(['goods_sn' => $goodsSn, 'sku_sn' => $sku_sn])->column('id');
            if (empty($ptGoodsSkuId)) {
                return $this->throwErrorMsg(['errorCode' => 2100111]);
            }
            $ptGoods = PpylGoodsSku::where(['id' => $ptGoodsSkuId])->field('stock')->lock(true)->findOrEmpty()->toArray();
            $GoodsSku = GoodsSku::where(['id' => $goodsSkuId])->field('stock')->lock(true)->findOrEmpty()->toArray();

            if (intval($ptGoods['stock']) <= 0 || intval($GoodsSku['stock']) <= 0) {
                return $this->throwErrorMsg(['errorCode' => 2100107]);
            }
            return true;
        });

        return ['success' => true, 'msg' => '成功'];
    }

    /**
     * @title  拼拼有礼订单前检验,判断是否符合参加要求
     * @param array $data
     * @return mixed
     * @throws \Exception
     */
    public function ActivityCheck(array $data)
    {
        $activityCode = $data['activity_code'] ?? null;
        $areaCode = $data['area_code'];
        $uid = $data['uid'];
        $goodsSn = $data['goods_sn'];
        $sku_sn = $data['sku_sn'];
        $number = $data['number'];
        $notThrowError = $data['notThrowError'] ?? false;
        $this->notThrowError = $notThrowError;
        if (intval($number) != 1) {
            return $this->throwErrorMsg(['errorCode' => 2100108]);
        }
        $ppylArea = PpylArea::where(['area_code' => $areaCode, 'status' => 1])->findOrEmpty()->toArray();
        if (empty($ppylArea)) {
            return $this->throwErrorMsg(['errorCode' => 2100101]);
        }
        //判断是否在开团时间,两个时间分开判断的原因是为了两个不同的提示
        if (strtotime($ppylArea['start_time']) > time()) {
            return $this->throwErrorMsg(['errorCode' => 2100102]);
        }
        //判断是否已经结束
        if (strtotime($ppylArea['end_time']) <= time()) {
            return $this->throwErrorMsg(['errorCode' => 2100103]);
        }
        $activityCode = $ppylArea['activity_code'];

        $ptInfo = PpylActivity::where(['activity_code' => $activityCode, 'status' => 1])->findOrEmpty()->toArray();

        //判断拼团活动是否存在
        if (empty($ptInfo)) {
            return $this->throwErrorMsg(['errorCode' => 2100101]);
        }

        $userInfo = User::with(['member'])->where(['uid' => $uid])->findOrEmpty()->toArray();
        $userTypes = CouponUserType::where(['status' => 1])->select()->toArray();
        foreach ($userTypes as $key => $value) {
            $userType[$value['u_type']] = $value;
        }
        //判断活动类型及开团对象
        switch ($ptInfo['join_user_type']) {
            case 2:
                $userOrder = PpylOrder::where(['uid' => $uid, 'activity_status' => [1, 2, -2, -3]])->count();
                if (!empty($userOrder)) {
                    return $this->throwErrorMsg(['msg' => '本次活动仅限新用户参团~您暂不符合要求']);
                }
                break;
            case 3:
                if (empty($userInfo['vip_level'])) {
                    return $this->throwErrorMsg(['msg' => '本次活动仅限会员用户参团~您暂不符合要求']);
                }
                break;
            case 4:
                break;
            case 5:
            case 6:
            case 7:
                if (empty($userInfo) || ($userInfo['vip_level'] != $userType[$ptInfo['start_user_type']]['vip_level'])) {
                    return $this->throwErrorMsg(['msg' => '本次活动仅限' . $userType[$ptInfo['start_user_type']]['u_name'] . '参团~您暂不符合要求']);
                }
                break;
            case 8:
                $userOrder = PpylOrder::where(['uid' => $uid, 'activity_status' => [1, 2, -2, -3]])->count();
                if (empty($userOrder)) {
                    return $this->throwErrorMsg(['msg' => '本次活动仅限老用户参团~您暂不符合要求']);
                }
                break;
            case 9:
                if (!empty($userInfo['vip_level'])) {
                    return $this->throwErrorMsg(['msg' => '本次活动仅限普通用户参团~您暂不符合要求']);
                }
                break;
            case 10:
                if (empty($userInfo['c_vip_level']) || (($userInfo['c_vip_time_out_time'] ?? 0) <= time())) {
                    return $this->throwErrorMsg(['msg' => '本次活动仅限CVIP开团~您暂不符合要求']);
                }
                break;

        }
        //判断是否在开团时间,两个时间分开判断的原因是为了两个不同的提示
        if (strtotime($ptInfo['start_time']) > time()) {
            return $this->throwErrorMsg(['errorCode' => 2100102]);
        }
        //判断是否已经结束
        if (strtotime($ptInfo['end_time']) <= time()) {
            return $this->throwErrorMsg(['errorCode' => 2100103]);
        }
        $prOrder = (new PpylOrder());
        //判断是否该拼团活动还存在拼团中的订单,如果存在则不允许重新参团
        $oldPtOrder = $prOrder->where(['uid' => $uid, 'activity_code' => $activityCode, 'area_code' => $areaCode, 'goods_sn' => $goodsSn, 'activity_status' => 1])->findOrEmpty()->toArray();
        if (!empty($oldPtOrder)) {
            return $this->throwErrorMsg(['errorCode' => 2100104]);
        }
        if (!empty($data['checkWait'] ?? false)) {
//            //判断用户是否有自动拼团计划,如果有则不允许继续继续参加
//            $aMap[] = ['uid', '=', $uid];
//            $aMap[] = ['activity_code', '=', $activityCode];
//            $aMap[] = ['area_code', '=', $areaCode];
//            $aMap[] = ['goods_sn', '=', $goodsSn];
//            $aMap[] = ['sku_sn', '=', $sku_sn];
//            $aMap[] = ['status', '=', 1];
//            $aMap[] = ['end_time', '>', time()];
//            $autoOrder = PpylAuto::where($aMap)->count();
//            if (!empty($autoOrder)) {
//                return $this->throwErrorMsg(['errorCode' => 2100118]);
//            }

            //判断是否该拼团活动还存在排队中的订单,如果存在则不允许重新参团
            $cMap[] = ['uid', '=', $uid];
            $cMap[] = ['activity_code', '=', $activityCode];
            $cMap[] = ['area_code', '=', $areaCode];
            $cMap[] = ['goods_sn', '=', $goodsSn];
            $cMap[] = ['sku_sn', '=', $sku_sn];
            $cMap[] = ['wait_status', '=', 1];
            $cMap[] = ['', 'exp', Db::raw('timeout_time is not null')];
            $cMap[] = ['timeout_time', '>', time()];
            $waitPtOrder = PpylWaitOrder::where($cMap)->findOrEmpty()->toArray();
            if (!empty($waitPtOrder)) {
                return $this->throwErrorMsg(['errorCode' => 2100116]);
            }
        }

        $ppMap = [];
        $ppWaitMap = [];
        switch ($ppylArea['join_limit_type'] ?? 1) {
            case 1:
                $timeStart = null;
                $timeEnd = null;
                $needLimit = true;
                break;
            case 2:
                $timeStart = date('Y-m-d', time()) . ' 00:00:00';
                $timeEnd = date('Y-m-d', time()) . ' 23:59:59';
                $needLimit = true;
                break;
            case 3:
                //当前日期
                $sdefaultDate = date("Y-m-d");
                //$first =1 表示每周星期一为开始日期 0表示每周日为开始日期
                $first = 1;
                //获取当前周的第几天 周日是 0 周一到周六是 1 - 6
                $w = date('w', strtotime($sdefaultDate));
                $timeStart = date('Y-m-d', strtotime("$sdefaultDate -" . ($w ? $w - $first : 6) . ' days')) . ' 00:00:00';
                //本周结束日期
                $timeEnd = date('Y-m-d', strtotime("$timeStart +6 days")) . ' 23:59:59';
                $needLimit = true;
                break;
            case 4:
                //本月的开始和结束
                $timeStart = date('Y-m-01', strtotime(date("Y-m-d"))) . ' 00:00:00';
                $timeEnd = date('Y-m-d', strtotime("$timeStart +1 month -1 day")) . ' 23:59:59';
                $needLimit = true;
                break;
        }
        if (!empty($timeStart) && !empty($timeEnd)) {
            $ppMap[] = ['create_time', '>=', strtotime($timeStart)];
            $ppMap[] = ['create_time', '<=', strtotime($timeEnd)];
        }
        $ppMap[] = ['uid', '=', $uid];
        $ppMap[] = ['activity_code', '=', $activityCode];
        $ppMap[] = ['area_code', '=', $areaCode];
        $ppMap[] = ['activity_status', 'in', [1, 2, -3]];
//        if ($ptInfo['style_type'] != 2) {
//            $ppMap[] = ['goods_sn', '=', $goodsSn];
////        $ppMap[] = ['sku_sn', '=', $sku_sn];
//        }


        //判断该用户参加拼团活动的次数
        $joinPtNumber = $prOrder->where($ppMap)->count();

        //查询排队中的订单
        if (!empty($timeStart) && !empty($timeEnd)) {
            $ppWaitMap[] = ['create_time', '>=', strtotime($timeStart)];
            $ppWaitMap[] = ['create_time', '<=', strtotime($timeEnd)];
        }
        $ppWaitMap[] = ['uid', '=', $uid];
        $ppWaitMap[] = ['activity_code', '=', $activityCode];
        $ppWaitMap[] = ['area_code', '=', $areaCode];
        $ppWaitMap[] = ['wait_status', 'in', [1]];

        //判断该用户正在排队中的次数
        $waitPPylNumber = PpylWaitOrder::where($ppWaitMap)->count();

        if (intval($joinPtNumber + ($waitPPylNumber - 1)) >= $ppylArea['join_number']) {
            return $this->throwErrorMsg(['errorCode' => 2100105]);
        }

        //判断活动商品活动库存
        $checkStock = Db::transaction(function () use ($activityCode, $areaCode, $goodsSn, $sku_sn, $ptInfo, $notThrowError) {
            $ptGoodsSkuId = PpylGoodsSku::where(['activity_code' => $activityCode, 'area_code' => $areaCode, 'goods_sn' => $goodsSn, 'sku_sn' => $sku_sn, 'status' => 1])->column('id');
            $goodsSkuId = GoodsSku::where(['goods_sn' => $goodsSn, 'sku_sn' => $sku_sn])->column('id');
            if (empty($ptGoodsSkuId)) {
                return $this->throwErrorMsg(['errorCode' => 2100111]);
            }
            $ptGoods = PpylGoodsSku::where(['id' => $ptGoodsSkuId])->field('stock')->lock(true)->findOrEmpty()->toArray();
            $GoodsSku = GoodsSku::where(['id' => $goodsSkuId])->field('stock')->lock(true)->findOrEmpty()->toArray();

            if (intval($ptGoods['stock']) <= 0 || intval($GoodsSku['stock']) <= 0) {
                return $this->throwErrorMsg(['errorCode' => 2100107]);
            }
            return true;
        });

        return ['success' => true, 'msg' => '成功'];
    }

    /**
     * @title  检查是否有待参团的同类型订单,如果有则参团,如果没有则进入排队队列
     * @param array $data
     * @return mixed
     * @throws \Exception
     */
    public function helpUserCheckWaitGroup(array $data)
    {
        //先判断是否符合活动要求,如果符合才自动找团或进入排队
        $checkMap['area_code'] = $data['area_code'];
        $checkMap['goods_sn'] = $data['goods_sn'];
        $checkMap['sku_sn'] = $data['sku_sn'];
        $checkMap['uid'] = $data['uid'];
        $checkMap['number'] = $data['number'] ?? 1;
        $checkMap['notThrowError'] = $data['notThrowError'] ?? false;
        $checkMap['checkWait'] = true;
        $checkRes = $this->ActivityCheck($checkMap);
        if (!empty($checkMap) && empty($checkRes['success'])) {
            return $checkRes;
        }
        //没有团的情况下要怎么处理 1为进入排队 2为强行修改为开团类型
        $notGroupDealType = $data['notGroupDealType'] ?? 1;

        $activitySn = $this->getUnCompletePpylGroup(['area_code' => $data['area_code'], 'onlyOne' => true, 'notThrowError' => $data['notThrowError'] ?? false, 'goods_sn' => $data['goods_sn'], 'sku_sn' => $data['sku_sn'], 'uid' => $data['uid']]);

        //进入排队队列
        if (empty($activitySn)) {
            if ($notGroupDealType == 1) {
                $res = $this->waitOrderCreate($data, $data['allOrderRes']);
            } else {
                $activitySn = (new CodeBuilder())->buildPpylActivityCode();
                return ['success' => true, 'msg' => '暂无团可加,强行修改为开团', 'activity_sn' => $activitySn, 'changeJoinType' => true];
            }

        } else {
            $joinRes = $this->joinPtActivityCheck(['area_code' => $data['area_code'], 'activity_sn' => $activitySn, 'goods_sn' => $data['goods_sn'], 'sku_sn' => $data['sku_sn'], 'uid' => $data['uid'], 'number' => $data['number'] ?? 1, 'notThrowError' => $data['notThrowError'] ?? false]);
            if (!empty($joinRes) && !empty($joinRes['success'])) {
                return ['success' => true, 'msg' => '成功找到团', 'activity_sn' => $activitySn];
            } else {
                //进入排队队列
                if ($notGroupDealType == 1) {
                    $res = $this->waitOrderCreate($data, $data['allOrderRes']);
                } else {
                    $activitySn = (new CodeBuilder())->buildPpylActivityCode();
                    return ['success' => true, 'msg' => '暂无团可加,强行修改为开团', 'activity_sn' => $activitySn, 'changeJoinType' => true];
                }
            }
        }
        return ['success' => true, 'msg' => '成功排队', 'data' => $res ?? []];
    }

    /**
     * @title  查看是否有符合该活动专场的待成团的团
     * @param array $data
     * @return mixed
     * @throws \Exception
     */
    public function getUnCompletePpylGroup(array $data)
    {
        $areaCode = $data['area_code'];
        $ppylArea = PpylArea::where(['area_code' => $areaCode, 'status' => 1])->findOrEmpty()->toArray();
        $notThrowError = $data['notThrowError'] ?? false;
        $this->notThrowError = $notThrowError;
        $goodsSn = $data['goods_sn'];
        $skuSn = $data['sku_sn'];

        if (empty($ppylArea)) {
            return $this->throwErrorMsg(['errorCode' => 2100101]);
        }
        //判断是否在开团时间,两个时间分开判断的原因是为了两个不同的提示
        if (strtotime($ppylArea['start_time']) > time()) {
            return $this->throwErrorMsg(['errorCode' => 2100102]);
        }
        //判断是否已经结束
        if (strtotime($ppylArea['end_time']) <= time()) {
            return $this->throwErrorMsg(['errorCode' => 2100103]);
        }
        $ppylOrder = [];
        $activityCode = $ppylArea['activity_code'];
        $oMap[] = ['activity_code', '=', $activityCode];
        $oMap[] = ['area_code', '=', $areaCode];
        $oMap[] = ['activity_status', '=', 1];
        $oMap[] = ['pay_status', '=', 2];
        $oMap[] = ['user_role', '=', 1];
        $oMap[] = ['goods_sn', '=', $goodsSn];
//        $oMap[] = ['sku_sn', '=', $skuSn];
        $ppylOrder = PpylOrder::with(['joinSuccessNumber'])->where($oMap)->field('activity_sn,group_number,scale')->order('create_time asc')->select()->toArray();

        if (!empty($ppylOrder)) {
            foreach ($ppylOrder as $key => $value) {
                if (!empty($value['joinSuccessNumber']) && (count($value['joinSuccessNumber']) >= $value['group_number'])) {
                    unset($ppylOrder[$key]);
                }
                $ppylOrder[$key]['joinNumber'] = count($value['joinSuccessNumber'] ?? []) - 1;
//                unset($ppylOrder[$key]['joinSuccessNumber']);

                //如果团中没有有人可以中奖的,则剔除该团
                if (intval($value['scale']) <= 0) {
                    $haveScale = false;
                    if(!empty($value['joinSuccessNumber'])){
                        foreach ($value['joinSuccessNumber'] as $cKey => $cValue) {
                            if (intval($cValue['scale']) > 0) {
                                $haveScale = true;
                            }
                        }
                    }
                } else {
                    $haveScale = true;
                }

                //如果团中每个人的概率都为0,但是人数还有剩,如果只是剩一个人,就是当前用户一定要可以中奖才能进这个团,如果当前团不是差最后一个人成团, 当前用户则可以进这个团
                if (empty($haveScale) && ((count($value['joinSuccessNumber']) + 1) < ($value['group_number']))) {
                    $haveScale = true;
                }

                if (empty($haveScale)) {
                    $pwMap = [];
                    switch ($ppylArea['win_limit_type'] ?? 1) {
                        case 1:
                            $wTimeStart = null;
                            $wTimeEnd = null;
                            break;
                        case 2:
                            $wTimeStart = date('Y-m-d', time()) . ' 00:00:00';
                            $wTimeEnd = date('Y-m-d', time()) . ' 23:59:59';
                            break;
                        case 3:
                            //当前日期
                            $wSdefaultDate = date("Y-m-d");
                            //$first =1 表示每周星期一为开始日期 0表示每周日为开始日期
                            $wFirst = 1;
                            //获取当前周的第几天 周日是 0 周一到周六是 1 - 6
                            $ww = date('w', strtotime($wSdefaultDate));
                            $wTimeStart = date('Y-m-d', strtotime("$wSdefaultDate -" . ($ww ? $ww - $wFirst : 6) . ' days')) . ' 00:00:00';
                            //本周结束日期
                            $wTimeEnd = date('Y-m-d', strtotime("$wTimeStart +6 days")) . ' 23:59:59';
                            break;
                        case 4:
                            //本月的开始和结束
                            $wTimeStart = date('Y-m-01', strtotime(date("Y-m-d"))) . ' 00:00:00';
                            $wTimeEnd = date('Y-m-d', strtotime("$wTimeStart +1 month -1 day")) . ' 23:59:59';
                            break;
                    }
                    if (!empty($wTimeStart) && !empty($wTimeEnd)) {
                        $pwMap[] = ['create_time', '>=', strtotime($wTimeStart)];
                        $pwMap[] = ['create_time', '<=', strtotime($wTimeEnd)];
                    }
                    $pwMap[] = ['uid', '=', $data['uid']];
                    $pwMap[] = ['activity_code', '=', $activityCode];
                    $pwMap[] = ['area_code', '=', $areaCode];
                    $pwMap[] = ['activity_status', 'in', [1,2]];
                    $pwMap[] = ['win_status', '=', 1];
//                    $pwMap[] = ['goods_sn', '=', $goodsSn];

                    //判断该用户参加拼团活动中奖的次数
                    $winPtNumber = PpylOrder::where($pwMap)->count();

                    //如果用户本身都没法中奖了,则剔除这个团,因为大家都不能中奖
                    if (intval($winPtNumber) >= $ppylArea['win_number']) {
                        unset($ppylOrder[$key]);
                    }

                }
            }
        }

        $returnData = $ppylOrder ?? [];
        if (!empty($data['onlyOne'])) {
            $returnData = !empty($ppylOrder) ? current(array_column($ppylOrder, 'activity_sn')) : null;
        }
        return $returnData ?? [];
    }

    /**
     * @title  新建拼拼有礼排队订单
     * @param array $data 拼团活动信息
     * @param array $orders 订单信息
     * @return mixed
     * @throws \Exception
     * @remark (仅做排队订单处理,实际开团或参团以orderCreate-创建拼拼有礼订单接口为主)
     */
    public function waitOrderCreate(array $data, array $orders)
    {
        if(time() >= 1640847600){
            throw new OrderException(['msg'=>'系统升级中, 暂无法下单, 感谢您的支持']);
        }

        $queueExpire = PpylConfig::where(['status' => 1])->value('wait_expire_time') ?? 180;
        $joinType = $data['ppyl_join_type'] ?? 2;
        $areaCode = $data['area_code'];
        $orderInfo = $orders['orderRes'];
        $notThrowError = $data['notThrowError'] ?? false;
        $this->notThrowError = $notThrowError;
        $userInfo = User::where(['uid' => $orderInfo['uid'], 'status' => [1, 2]])->field('uid,phone,c_vip_level,c_vip_time_out_time')->findOrEmpty()->toArray();

        $areaInfo = PpylArea::where(['area_code' => $areaCode, 'status' => 1])->findOrEmpty()->toArray();
        if (empty($areaInfo)) {
            return $this->throwErrorMsg(['msg' => '不存在的拼拼有礼专区哦'], $notThrowError);
        }
        $existOrder = PpylOrder::where(['order_sn' => $orderInfo['order_sn']])->count();
        if (!empty($existOrder)) {
            return $this->throwErrorMsg(['msg' => '拼拼有礼订单已存在,请勿重复提交'], $notThrowError);
        }
        $activityCode = $areaInfo['activity_code'];

//        $orderGoods = OrderGoods::where(['order_sn' => $orderInfo['order_sn'], 'status' => 1])->field('sku_sn,goods_sn,order_sn,count')->select()->toArray();
        $orderGoods = $orders['goodsRes'] ?? [];

        if (empty($joinType)) {
            return $this->throwErrorMsg(['msg' => '请选择拼团参加类型']);
        }
        if ($joinType == 2) {
            $activitySn = $data['activity_sn'] ?? null;
            if (!empty($activitySn)) {
                $ptOrderParent = PpylOrder::where(['activity_code' => $activityCode, 'activity_sn' => $activitySn, 'status' => 1, 'user_role' => 1, 'pay_status' => 2, 'activity_status' => [1]])->findOrEmpty()->toArray();
            }
        } else {
            $ptOrderParent = [];
            $activitySn = (new CodeBuilder())->buildPpylActivityCode();
        }
        //拼团活动信息
        $ptInfo = PpylActivity::where(['activity_code' => $activityCode, 'status' => 1])->findOrEmpty()->toArray();

        //查看是否有正在排队或待支付的排队订单,如果有则不允许继续创建
        $existWait = PpylWaitOrder::where(['activity_code' => $activityCode, 'status' => 1, 'area_code' => $areaInfo, 'goods_sn' => $data['goods_sn'], 'wait_status' => 1, 'uid' => $userInfo['uid']])->findOrEmpty()->toArray();
        if (!empty($existWait)) {
//            return $this->throwErrorMsg(['msg' => '已存在未处理完成的排队订单,请勿重复发起~']);
            return $this->throwErrorMsg(['errorCode' => 2700121]);
        }
        //查看重新支付的流水号是否存在于其他排队或拼拼订单中,如果被占用则不允许继续使用
        if ($data['pay_type'] == 4 && !empty($data['pay_order_sn'] ?? null)) {
            $existPayOrderSn = PpylWaitOrder::where(['pay_order_sn' => $data['pay_order_sn']])->count();
            if (empty($existPayOrderSn)) {
                $existPayOrderSn = PpylOrder::where(['pay_order_sn' => $data['pay_order_sn']])->count();
            }
            if (!empty($existPayOrderSn)) {
                $log['msg'] = '美好拼拼流水号' . $data['pay_order_sn'] . '复用订单已存在,请勿重复提交';
                $log['uid'] = $orderInfo['uid'] ?? null;
                $log['data'] = $data;
                (new Log())->setChannel('ppyl')->record($log, 'debug');

                return $this->throwErrorMsg(['errorCode' => 2700124]);
            }
        }

        $skuInfo = PpylGoodsSku::where(['activity_code' => $activityCode, 'area_code' => $areaCode, 'status' => 1, 'goods_sn' => $data['goods_sn'], 'sku_sn' => $data['sku_sn']])->findOrEmpty()->toArray();
//        $pt['activity_sn'] = $activitySn;
        $pt['activity_code'] = $activityCode;
        $pt['area_code'] = $areaCode;
        $pt['area_name'] = $areaInfo['name'] ?? '未知专区';
        $pt['reward_price'] = $skuInfo['reward_price'];
        $pt['uid'] = $orderInfo['uid'];
        $pt['c_vip_level'] = 0;
        if (!empty($userInfo['c_vip_level']) && ($userInfo['c_vip_time_out_time'] >= time())) {
            $pt['c_vip_level'] = $userInfo['c_vip_level'] ?? 0;
        }
        $pt['order_sn'] = $orderInfo['order_sn'];
        $pt['goods_sn'] = $data['goods_sn'];
        $pt['sku_sn'] = $data['sku_sn'];
        $pt['real_pay_price'] = $orderInfo['real_pay_price'];
        $pt['user_role'] = $joinType ?? 1;
        $pt['wait_start_time'] = time();
        $pt['timeout_time'] = $pt['wait_start_time'] + $queueExpire;
        $pt['wait_status'] = 1;
        $pt['activity_title'] = $ptInfo['activity_title'];

        $pt['pay_status'] = 1;
        $pt['pay_type'] = $data['pay_type'] ?? 1;
        if ($pt['pay_type'] == 4) {
            $pt['pay_no'] = $data['pay_no'] ?? null;
            $pt['pay_order_sn'] = $data['pay_order_sn'] ?? null;
        }


        //CVIP不超时
        if (!empty($pt['c_vip_level']) && $userInfo['c_vip_time_out_time'] > time()) {
            $pt['timeout_time'] = null;
        }
        $res = PpylWaitOrder::create($pt);

        //推入预计超时队列
//        if (empty($userInfo['c_vip_level']) || (!empty($userInfo['c_vip_level'] && $userInfo['c_vip_time_out_time'] <= time()))) {
        if (!empty($pt['timeout_time'])) {
            $redis = Cache::store('redis')->handler();
            $redis->lpush("waitOrderTimeoutLists-" . $pt['timeout_time'], $pt['order_sn']);
        }

        //如果有原始支付订单号,则需要修改原始支付订单号为不可退款操作
        if (!empty($pt['pay_order_sn'] ?? null)) {
            PpylOrder::update(['can_operate_refund' => 2], ['order_sn' => $pt['pay_order_sn']]);
        }

        return $res->getData();
    }

    /**
     * @title  处理排队队伍
     * @param array $data
     * @return array|bool
     * @throws \Exception
     */
    public function dealWaitOrder(array $data)
    {
        $areaCode = $data['area_code'];
        $goodsSn = $data['goods_sn'];
        $skuSn = $data['sku_sn'] ?? null;
        //dealType 1为有人开团后查看是否有等待参团的队伍  2为有人进入排队队伍后队伍是否够人凑成一个团
        $dealType = $data['dealType'] ?? 1;
        $activitySn = $data['activity_sn'] ?? null;
        $notThrowError = $data['notThrowError'] ?? false;
        $this->notThrowError = $notThrowError;
        $logService = (new Log());

        //查找最早的未成团的团然后处理队伍中的人
        if ($dealType == 1) {
            $activitySn = PpylOrder::where(['activity_status' => 1, 'user_role' => 1, 'area_code' => $areaCode, 'goods_sn' => $goodsSn, 'status' => 1, 'pay_status' => 2])->order('create_time asc')->value('activity_sn');
            //如果没有未成团的团,则修改为凑人团类型
            if (empty($activitySn)) {
                $dealType = 2;
            }
        }

        $areaInfo = PpylArea::where(['area_code' => $areaCode, 'status' => 1])->findOrEmpty()->toArray();
        if (empty($areaInfo)) {
            $log['msg'] = '不存在的拼拼有礼专区哦';
            $log['data'] = $data;
            $logService->setChannel('ppylWait')->record($log, 'error');
            return $this->throwErrorMsg(['msg' => '不存在的拼拼有礼专区哦'], $notThrowError);
        }
        $log['areaInfo'] = $areaInfo ?? [];

        //判断是否在开团时间,两个时间分开判断的原因是为了两个不同的提示
        if (strtotime($areaInfo['start_time']) > time()) {
            $log['msg'] = '不在有效期内的专区-未开始';
            $log['data'] = $data;
            $logService->setChannel('ppylWait')->record($log, 'error');
            return $this->throwErrorMsg(['errorCode' => 2100102]);
        }
        //判断是否已经结束
        if (strtotime($areaInfo['end_time']) <= time()) {
            $log['msg'] = '不在有效期内的专区-已结束';
            $log['data'] = $data;
            $logService->setChannel('ppylWait')->record($log, 'error');
            return $this->throwErrorMsg(['errorCode' => 2100103]);
        }

        $activityCode = $areaInfo['activity_code'];

        $ptInfo = PpylActivity::where(['activity_code' => $activityCode, 'status' => 1])->findOrEmpty()->toArray();

        if (empty($ptInfo)) {
            $log['msg'] = '不存在的美好拼拼活动哦';
            $log['data'] = $data;
            $logService->setChannel('ppylWait')->record($log, 'error');
            return $this->throwErrorMsg(['msg' => '不存在的美好拼拼活动哦'], $notThrowError);
        }
        $log['activityInfo'] = $ptInfo ?? [];

        $ptOrderNumber = 0;
        if (!empty($activitySn)) {
            $ptOrderNumber = PpylOrder::where(['activity_code' => $activityCode, 'area_code' => $areaCode, 'activity_sn' => $activitySn, 'activity_status' => 1, 'status' => 1])->count();
            $log['ptOrderNumber'] = $ptOrderNumber ?? 0;

            if ($ptOrderNumber >= $ptInfo['group_number']) {
                $log['msg'] = '此团人数已达上限~感谢您的支持';
                $log['data'] = $data;
                $logService->setChannel('ppylWait')->record($log, 'error');
                return $this->throwErrorMsg(['errorCode' => 2100110]);
            }
        }

        //处理排队时锁队伍,不允许取消队伍
        cache('dealWaitOrder', 1, 600);
        $DBRes = Db::transaction(function () use ($data, $areaInfo, $ptInfo, $goodsSn, $skuSn, $areaCode, $activitySn, $dealType, $ptOrderNumber, $activityCode, $logService) {

            //查找排队队伍
            $firstMap[] = ['area_code', '=', $areaCode];
            $firstMap[] = ['goods_sn', '=', $goodsSn];
//        $wMap[] = ['sku_sn', '=', $skuSn];
            $firstMap[] = ['wait_status', '=', 1];
            $firstMap[] = ['pay_status', '=', 2];
            $firstMap[] = ['status', '=', 1];

            $map[] = ['c_vip_level', '=', 0];
            $map[] = ['', 'exp', Db::raw('timeout_time is not null')];
            $map[] = ['timeout_time', '>', time()];

            $mapOr[] = ['c_vip_level', '>', 0];
            $mapOr[] = ['', 'exp', Db::raw('timeout_time is null')];

            $waitListId = PpylWaitOrder::where($firstMap)->where(function ($query) use ($map, $mapOr) {
                $query->whereOr([$map, $mapOr]);
            })->order('create_time asc')->column('id');

            $waitList = [];
            if (!empty($waitListId)) {
                $waitList = PpylWaitOrder::with(['cvip'])->where(['id' => $waitListId])->order('create_time asc')->lock(true)->select()->toArray();
            }

            $waitListIdMd5LockKey = md5(implode(',', $waitListId));

//            if (empty($waitList) || (!empty($waitList) && $dealType == 2 && count($waitList) < $ptInfo['group_number'])) {
            if (empty($waitList)) {
                $log['msg'] = '无队伍可处理';
                $log['data'] = $data;
                $logService->setChannel('ppylWait')->record($log, 'debug');
                return $log['msg'];
            }
            if ($dealType == 2 && count($waitList) < 2) {
                $log['msg'] = '自动招人开团的排队队伍人数必须为两人及以上,现在人数不够';
                $log['data'] = $data;
                $log['waitList'] = $waitList;
                $logService->setChannel('ppylWait')->record($log, 'debug');
                return $log['msg'];
            }
            switch ($dealType) {
                case 1:
                    $returnData['waitList'] = $waitList ?? [];
                    $number = 0;
                    foreach ($waitList as $key => $value) {
                        if ($value['user_role'] == 2 && $number < ($ptInfo['group_number'] - $ptOrderNumber) && empty(cache('dealWaitOrder-' . $value['order_sn']))) {
                            //检查是否符合参团要求
                            $check = $this->joinPtActivityCheck(['area_code' => $areaCode, 'uid' => $value['uid'], 'goods_sn' => $value['goods_sn'], 'sku_sn' => $value['sku_sn'], 'activity_sn' => $activitySn, 'number' => 1, 'notThrowError' => 1]);
                            if (!empty($check) && !empty($check['success'] ?? false)) {
                                $waitJoinList[] = $value;
                                $number++;
                                cache('dealWaitOrder-' . $value['order_sn'], $value, 30, 'dealWaitOrderInfo-' . $activitySn);
                            }
                        }
                    }

                    if (!empty($waitJoinList)) {
                        $log['waitJoinList'] = $waitJoinList ?? [];
                        //参团
                        foreach ($waitJoinList as $key => $value) {
                            $orderM['orderRes'] = $value;
                            $orderM['goodsRes'] = [];
                            $res = false;
                            try {
                                $res = $this->orderCreate(['ppyl_join_type' => 2, 'area_code' => $value['area_code'], 'notThrowError' => $this->notThrowError, 'goods_sn' => $value['goods_sn'], 'sku_sn' => $value['sku_sn'], 'activity_sn' => $activitySn, 'pay_type' => $value['pay_type'], 'pay_no' => $value['pay_no'], 'pay_order_sn' => ($value['pay_order_sn'] ?? null)], $orderM);
                            } catch (BaseException $e) {
                                $errorMsg = $e->msg;
                                $errorCode = $e->errorCode;
                                $errMsg = null;
                                if (!empty($errorMsg)) {
                                    $joinOrderSn = $value['order_sn'];
                                    $errMsg = ' 于' . date('Y-m-d H:i:s') . '执行参团失败,失败原因为' . $errorMsg . ' ';
                                    $updateTime = time();
                                    Db::query("update sp_ppyl_wait_order set coder_remark = IFNULL(concat('$errMsg',coder_remark),'$errMsg') ,update_time = '$updateTime' where order_sn = '$joinOrderSn';");

                                    $returnData['failJoinRes'][$value['order_sn']] = $errorMsg;
                                }
                                //如果存在特殊的异常码,需要停止排队,并退款
                                //如果是达到参与上限了则强制超时并退款
                                if (!empty($errorCode ?? null)) {
                                    if (in_array($errorCode, ['2100105'])) {
                                        (new TimerForPpyl())->overTimeWaitOrder([$value['order_sn']], 2);
                                    }
                                }
                            }

                            $joinRes[] = $res;
                            if (!empty($res) && !isset($res['success'])) {
                                //修改新建的订单为支付成功
                                PpylOrder::update(['pay_status' => 2, 'pay_no' => $value['pay_no'] ?? null, 'pay_time' => strtotime($value['pay_time'])], ['order_sn' => $value['order_sn'], 'pay_status' => 1]);
                                PpylWaitOrder::update(['wait_status' => 2, 'wait_end_time' => time()], ['order_sn' => $value['order_sn'], 'wait_status' => 1]);
                                cache('dealWaitOrder-' . $value['order_sn'], null);
                                //如果是支付流水号的订单,需要取消原订单对应的退款操作
                                if ($value['pay_type'] == 4 && !empty($value['pay_order_sn'] ?? false)) {
                                    PpylOrder::update(['can_operate_refund' => 2], ['order_sn' => $value['pay_order_sn']]);
                                }

                                if (!empty($value['timeout_time'])) {
                                    //删除超时任务
                                    $redis = Cache::store('redis')->handler();
                                    $redis->lrem("waitOrderTimeoutLists-" . strtotime($value['timeout_time']), $value['order_sn']);
                                }

                                //发送消息模版
                                $this->waitOrderDealTemplate($value);

                            }
                            cache('dealWaitOrder-' . $value['order_sn'], null);
                            $returnData['joinRes'] = $joinRes ?? [];
                        }
                    }
                    break;
                case 2:

                    $number = 0;
                    //抓取一个会员身份的开团,暂时先做会员的,后续针对不同人的开团对象此处修改为switch的条件判断
                    //暂时修改为全部人都可以开团.2021/11/8
                    //加上一个开团人的缓存锁,防止出现了同一队伍同一秒出现多个开团人的情况
                    foreach ($waitList as $key => $value) {
//                        if (!empty($value['c_vip_level']) && $value['c_vip_time_out_time'] >= time() && $value['user_role'] == 2 && empty(cache('dealWaitOrder-' . $value['order_sn']) && empty(cache('ppylWaitOrderStartUser-' . $waitListIdMd5LockKey)))
//                        )
                            if ($value['user_role'] == 2 && empty(cache('dealWaitOrder-' . $value['order_sn']) && empty(cache('ppylWaitOrderStartUser-' . $waitListIdMd5LockKey)))
                        ) {
                            $payOrderSnLockKey = null;
                            //如果是使用流水号支付,需要看看是否有流水号锁,如果有则不允许继续
                            if ($value['pay_type'] == 4 && !empty($value['pay_order_sn'] ?? null)) {
                                $payOrderSnLockKey = 'payOrderSnLock-' . $value['pay_order_sn'];
                            }
                            if (empty($payOrderSnLockKey) || (!empty($payOrderSnLockKey) && empty(cache($payOrderSnLockKey)))) {
                                //检查是否符合开团要求
                                $check = $this->startPtActivityCheck(['area_code' => $areaCode, 'uid' => $value['uid'], 'goods_sn' => $value['goods_sn'], 'sku_sn' => $value['sku_sn'], 'activity_sn' => $activitySn, 'number' => 1, 'notThrowError' => 1]);

                                $returnData['startCheckRes'][$value['order_sn']] = $check;
                                if (!empty($check) && !empty($check['success'] ?? false)) {
                                    $startUserOrder = $value;
                                    cache('dealWaitOrder-' . $value['order_sn'], $value, 30);
                                    cache('ppylWaitOrderStartUser-' . $waitListIdMd5LockKey, $value, 10);
                                    unset($waitList[$key]);
                                    break;
                                }else{
                                    $startOrderSn = null;
                                    if (!empty($check) && empty($check['success'] ?? null)) {
                                        if (!empty($check['msg'] ?? null)) {
                                            $startOrderSn = $value['order_sn'];
                                            $errMsg = ' 于' . date('Y-m-d H:i:s') . '执行开团检查失败,失败原因为' . $check['msg'] . ' ';
                                            $updateTime = time();
                                            Db::query("update sp_ppyl_wait_order set coder_remark = IFNULL(concat('$errMsg',coder_remark),'$errMsg') ,update_time = '$updateTime' where order_sn = '$startOrderSn';");
                                        }
                                        //如果存在特殊的异常码,需要停止排队,并退款
                                        //如果是达到参与上限了则强制超时并退款
                                        if (!empty($check['errorCode'] ?? null)) {
                                            if (in_array($check['errorCode'], ['2100105'])) {
                                                (new TimerForPpyl())->overTimeWaitOrder([$value['order_sn']], 2);
                                            }
                                        }
                                    }
                                }
                            }
                        }
                    }

                    //先开团
                    if (!empty($startUserOrder)) {
                        $returnData['startUserOrder'] = $startUserOrder ?? [];
                        $startRes = false;
                        try {
                            $startRes = $this->orderCreate(['ppyl_join_type' => 1, 'area_code' => $data['area_code'], 'notThrowError' => $this->notThrowError, 'goods_sn' => $startUserOrder['goods_sn'], 'sku_sn' => $startUserOrder['sku_sn'], 'pay_type' => $startUserOrder['pay_type'], 'pay_no' => $startUserOrder['pay_no'], 'pay_order_sn' => ($startUserOrder['pay_order_sn'] ?? null)], ['orderRes' => $startUserOrder, 'goodsRes' => []]);
                        } catch (BaseException $e) {
                            $errorMsg = $e->msg;
                            $errorCode = $e->errorCode;
                            $errMsg = null;
                            if (!empty($errorMsg)) {
                                $orderSn = $startUserOrder['order_sn'];
                                $errMsg = ' 于' . date('Y-m-d H:i:s') . '执行开团失败,失败原因为' . $errorMsg . ' ';
                                $updateTime = time();
                                Db::query("update sp_ppyl_wait_order set coder_remark = IFNULL(concat('$errMsg',coder_remark),'$errMsg') ,update_time = '$updateTime' where order_sn = '$orderSn';");
                                $returnData['failStartRes'][$orderSn] = $errorMsg;
                            }
                            //如果存在特殊的异常码,需要停止排队,并退款
                            //如果是达到参与上限了则强制超时并退款
                            if (!empty($errorCode ?? null)) {
                                if (in_array($errorCode, ['2100105'])) {
                                    (new TimerForPpyl())->overTimeWaitOrder([$startUserOrder['order_sn']], 2);
                                }
                            }
                        }

                        if (!empty($startRes) && !isset($startRes['success'])) {
                            cache('dealWaitOrder-' . $startUserOrder['order_sn'], $startUserOrder, 60, 'dealWaitOrderInfo-' . ($startRes['activity_sn'] ?? null));
                            //修改新建的订单为支付成功
                            PpylOrder::update(['pay_status' => 2, 'pay_no' => $startUserOrder['pay_no'] ?? null, 'pay_time' => strtotime($startUserOrder['pay_time'])], ['order_sn' => $startUserOrder['order_sn'], 'pay_status' => 1]);
                            PpylWaitOrder::update(['wait_status' => 2, 'wait_end_time' => time()], ['order_sn' => $startUserOrder['order_sn'], 'wait_status' => 1]);
                            cache('dealWaitOrder-' . $startUserOrder['order_sn'], null);
                            cache('ppylWaitOrderStartUser-' . $waitListIdMd5LockKey, null);
                            //如果是支付流水号的订单,需要取消原订单对应的退款操作
                            if ($startUserOrder['pay_type'] == 4 && !empty($startUserOrder['pay_order_sn'] ?? false)) {
                                PpylOrder::update(['can_operate_refund' => 2], ['order_sn' => $startUserOrder['pay_order_sn']]);
                            }
                            if (!empty($startUserOrder['timeout_time'])) {
                                //删除超时任务
                                $redis = Cache::store('redis')->handler();
                                $redis->lrem("waitOrderTimeoutLists-" . strtotime($startUserOrder['timeout_time']), $startUserOrder['order_sn']);
                            }
                            //发送消息模版
                            $this->waitOrderDealTemplate($startUserOrder);
                        }
                        $returnData['startRes'] = $startRes ?? [];

                        //后参团
                        if (!empty($startRes) && !empty($startRes['activity_sn'] ?? null)) {
                            $activitySn = $startRes['activity_sn'];
                            foreach ($waitList as $key => $value) {
                                if ($value['user_role'] == 2 && $number < ($ptInfo['group_number'] - 1) && $value['uid'] != $startUserOrder['uid'] && empty(cache('dealWaitOrder-' . $value['order_sn']))
                                ) {
                                    //检查是否符合参团要求
                                    $check = $this->joinPtActivityCheck(['area_code' => $areaCode, 'uid' => $value['uid'], 'goods_sn' => $value['goods_sn'], 'sku_sn' => $value['sku_sn'], 'activity_sn' => $activitySn, 'number' => 1, 'notThrowError' => 1]);

                                    $returnData['joinCheckRes'][$value['order_sn']] = $check;
                                    if (!empty($check) && !empty($check['success'] ?? false)) {
                                        $waitJoinList[] = $value;
                                        cache('dealWaitOrder-' . $value['order_sn'], $value, 600, 'dealWaitOrderInfo-' . $activitySn);
                                        $number++;
                                    }else{
                                        $joinOrderSnIn = null;
                                        $errMsg = null;
                                        if (!empty($check) && empty($check['success'] ?? null)) {
                                            if (!empty($check['msg'] ?? null)) {
                                                $joinOrderSnIn = $value['order_sn'];
                                                $errMsg = ' 于' . date('Y-m-d H:i:s') . '执行参团检测失败,失败原因为' . $check['msg'] . ' ';
                                                $updateTime = time();
                                                Db::query("update sp_ppyl_wait_order set coder_remark = IFNULL(concat('$errMsg',coder_remark),'$errMsg') ,update_time = '$updateTime' where order_sn = '$joinOrderSnIn';");
                                            }
                                            //如果存在特殊的异常码,需要停止排队,并退款
                                            //如果是达到参与上限了则强制超时并退款
                                            if (!empty($check['errorCode'] ?? null)) {
                                                if (in_array($check['errorCode'], ['2100105'])) {
                                                    (new TimerForPpyl())->overTimeWaitOrder([$value['order_sn']], 2);
                                                }
                                            }
                                        }
                                    }
                                }
                            }

                            if (!empty($waitJoinList)) {
                                $returnData['waitJoinList'] = $waitJoinList ?? [];
                                //参团
                                foreach ($waitJoinList as $key => $value) {
                                    $orderM['orderRes'] = $value;
                                    $orderM['goodsRes'] = [];
                                    $joinOrderSn = null;
                                    $res = false;
                                    try {
                                        $res = $this->orderCreate(['ppyl_join_type' => 2, 'area_code' => $value['area_code'], 'notThrowError' => $this->notThrowError, 'goods_sn' => $value['goods_sn'], 'sku_sn' => $value['sku_sn'], 'activity_sn' => $activitySn, 'notLock' => true, 'pay_type' => $value['pay_type'], 'pay_no' => $value['pay_no'], 'pay_order_sn' => ($value['pay_order_sn'] ?? null)], $orderM);
                                    } catch (BaseException $e) {
                                        $errorMsg = $e->msg;
                                        $errorCode = $e->errorCode;
                                        $errMsg = null;
                                        if (!empty($errorMsg)) {
                                            $joinOrderSn = $value['order_sn'];
                                            $errMsg = ' 于' . date('Y-m-d H:i:s') . '执行参团失败,失败原因为' . $errorMsg . ' ';
                                            $updateTime = time();
                                            Db::query("update sp_ppyl_wait_order set coder_remark = IFNULL(concat('$errMsg',coder_remark),'$errMsg') ,update_time = '$updateTime' where order_sn = '$joinOrderSn';");
                                            $returnData['failJoinRes'][$value['order_sn']] = $errorMsg;
                                        }
                                        //如果存在特殊的异常码,需要停止排队,并退款
                                        //如果是达到参与上限了则强制超时并退款
                                        if (!empty($errorCode ?? null)) {
                                            if (in_array($errorCode, ['2100105'])) {
                                                (new TimerForPpyl())->overTimeWaitOrder([$value['order_sn']], 2);
                                            }
                                        }
                                    }

                                    $joinRes[] = $res;
                                    if (!empty($res) && !isset($res['success'])) {
                                        //修改新建的订单为支付成功
                                        PpylOrder::update(['pay_status' => 2, 'pay_no' => $value['pay_no'] ?? null, 'pay_time' => strtotime($value['pay_time'])], ['order_sn' => $value['order_sn'], 'pay_status' => 1]);
                                        PpylWaitOrder::update(['wait_status' => 2, 'wait_end_time' => time()], ['order_sn' => $value['order_sn'], 'wait_status' => 1]);
                                        cache('dealWaitOrder-' . $value['order_sn'], null);

                                        //如果是支付流水号的订单,需要取消原订单对应的退款操作
                                        if ($value['pay_type'] == 4 && !empty($value['pay_order_sn'] ?? false)) {
                                            PpylOrder::update(['can_operate_refund' => 2], ['order_sn' => $value['pay_order_sn']]);
                                        }
                                        if (!empty($value['timeout_time'])) {
                                            //删除超时任务
                                            $redis = Cache::store('redis')->handler();
                                            $redis->lrem("waitOrderTimeoutLists-" . strtotime($value['timeout_time']), $value['order_sn']);
                                        }

                                        //发送消息模版
                                        $this->waitOrderDealTemplate($value);
                                    }
                                    //清除缓存锁
                                    cache('dealWaitOrder-' . $value['order_sn'], null);
                                }
                                $returnData['joinRes'] = $joinRes ?? [];
                            }
                        }
                    }
                    break;
            }

            //查看是否满足成团要求
            //全部参团已支付人的数量
            $allPt = PpylOrder::where(['activity_code' => $activityCode, 'area_code' => $areaCode, 'activity_sn' => $activitySn, 'activity_status' => 1, 'pay_status' => 2, 'status' => 1])->count();

            //到达成团人数,完成拼团
            if ($allPt >= intval($ptInfo['group_number'])) {
                $ptMap = ['activity_code' => $activityCode, 'area_code' => $areaCode, 'activity_sn' => $activitySn, 'activity_status' => 1, 'status' => 1];
                $allOrder = PpylOrder::where($ptMap)->field('order_sn,uid,user_role')->order('user_role asc,create_time asc')->select()->toArray();
                PpylOrder::update(['activity_status' => 2, 'group_time' => time(), 'draw_time' => $areaInfo['lottery_delay_time'] + time()], $ptMap);
                $successPt = true;
                //已经完成拼团模版消息通知----尚未完成

                //进入抽奖并发放红包
//                if (!empty($allOrder)) {
                    Queue::later(intval($areaInfo['lottery_delay_time']), 'app\lib\job\PpylLottery', ['activity_sn' => $activitySn], config('system.queueAbbr') . 'PpylLottery');
//                }

                //成团后立马判断是否可以升级
                foreach ($allOrder as $key => $value) {
                    $upgradeQueue = Queue::push('app\lib\job\MemberUpgrade', ['uid' => $value['uid'], 'type' => 2], config('system.queueAbbr') . 'MemberUpgrade');
                }

                //成团后判断上级的推荐奖是否可以激活
                foreach ($allOrder as $key => $value) {
                    Queue::push('app\lib\job\PpylAuto', ['uid' => $value['uid'], 'autoType' => 3], config('system.queueAbbr') . 'PpylAuto');
                }
            }

            $returnData['joinRes'] = $joinRes ?? [];
            return $returnData ?? [];
        });

        $log['msg'] = '处理了一次等待队列';
        $log['DBRes'] = $DBRes ?? [];
        $logService->setChannel('ppylWait')->record($log, 'info');

        return true;
    }

    /**
     * @title  排队订单状态更新后的模版消息通知
     * @param array $aOrderInfo
     * @return mixed
     * @throws \Exception
     */
    public function waitOrderDealTemplate(array $aOrderInfo)
    {
        $orderGoods = PpylOrderGoods::where(['order_sn' => $aOrderInfo['order_sn']])->field('goods_sn,sku_sn,user_level,price,count,total_price,title')->select()->toArray();
        $goodsTitle = implode(',', array_column($orderGoods, 'title'));
        //截取商品名称长度
        $length = mb_strlen($goodsTitle);
        if ($length >= 17) {
            $goodsTitle = mb_substr($goodsTitle, 0, 17) . '...';
        }
        switch ($aOrder['delRes'] ?? 1) {
            case 1:
                $statusRemark = '排队已成功';
                $remark = '排队已成功! 正在为您处理参团~';
                break;
            case 2:
                $statusRemark = '排队已超时';
                $remark = '非常抱歉, 您的排队已超时, 如有需要可重新排队';
                break;
        }

        $template['uid'] = $aOrderInfo['uid'];
        $template['type'] = 'ppylWaitRes';
        $template['page'] = 'pages/index/index';
//        if (empty($aOrderInfo['order_sn'])) {
//            $template['page'] = 'pages/index/index';
//        } else {
//            $template['page'] = 'pages/index/index?redirect=%2Fpages%2Forder-detail%2Forder-detail%3Fsn%3D' . $aOrderInfo['order_sn'];
//        }
        $template['access_key'] = getAccessKey();
        $template['template'] = ['character_string2' => $aOrderInfo['order_sn'], 'thing7' => $goodsTitle, 'phrase4' => $statusRemark ?? null, 'date3' => timeToDateFormat(time()), 'thing5' => $remark];
        $templateQueue = Queue::later(5, 'app\lib\job\Template', $template, config('system.queueAbbr') . 'TemplateList');
        return true;
    }

    /**
     * @title  发放红包
     * @param array $data
     * @return bool
     * @throws \Exception
     */
    public function ppylOrderReward(array $data)
    {
        $order_sn = $data['order_sn'] ?? [];
        $orderInfo = $data['orderInfo'] ?? [];
        if (!empty($orderInfo)) {
            $overTimePtOrder = $orderInfo;
            $order_sn = $orderInfo['order_sn'];
        } else {
            $overTimePtOrder = PpylOrder::where(['order_sn' => $order_sn])->field('uid,activity_sn,activity_code,area_code,order_sn,goods_sn,sku_sn,user_role,activity_type,activity_status,pay_status,win_status,refund_route,refund_price,refund_time,reward_price,real_pay_price')->findOrEmpty()->toArray();
        }

        $refundRes['orderInfo'] = $overTimePtOrder ?? [];

        //查看是否存在奖励记录
        $existReward = PpylReward::where(['order_sn' => $order_sn])->count();
        if (!empty($existReward)) {
            $log['msg'] = '订单' . $order_sn . '已存在奖励,无需重复发放';
            $log['data'] = $data;
            $log['res'] = $existReward;
            $this->log($log, 'error');
            return false;
        }

        if (empty($overTimePtOrder)) {
            return false;
        }

        $goodsInfo = PpylOrderGoods::where(['order_sn' => $overTimePtOrder['order_sn'], 'goods_sn' => $overTimePtOrder['goods_sn'], 'sku_sn' => $overTimePtOrder['sku_sn']])->findOrEmpty()->toArray();
        $refundRes['goodsInfo'] = $goodsInfo ?? [];

        $ppylGoodsInfo = PpylGoodsSku::where(['activity_code' => $overTimePtOrder['activity_code'], 'area_code' => $overTimePtOrder['area_code'], 'goods_sn' => $overTimePtOrder['goods_sn'], 'sku_sn' => $overTimePtOrder['sku_sn'], 'status' => 1])->findOrEmpty()->toArray();
        $refundRes['ppylGoodsInfo'] = $ppylGoodsInfo ?? [];

        $userInfo = User::with(['topLink' => function ($query) {
            $query->field('uid,phone,vip_level,c_vip_level,c_vip_time_out_time,link_superior_user,auto_receive_reward');
        }])->where(['uid' => $overTimePtOrder['uid'], 'status' => 1])->field('uid,phone,vip_level,c_vip_level,c_vip_time_out_time,link_superior_user,auto_receive_reward')->findOrEmpty()->toArray();

        if (empty($userInfo)) {
            return false;
        }
        $refundRes['userInfo'] = $userInfo ?? [];


        //查看红包奖励冻结规则
        $rewardRule = PpylConfig::where(['status' => 1])->field('frozen_reward_time,top_reward_receive_order_number,top_reward_receive_type,freed_expire_time')->findOrEmpty()->toArray();

        $refundRes['rewardRule'] = $rewardRule ?? [];
        $codeBuilder = (new CodeBuilder());
        //添加订单本人红包冻结明细
        $pplyMyself['reward_sn'] = $codeBuilder->buildRewardSn();
        $pplyMyself['order_uid'] = $overTimePtOrder['uid'];
        $pplyMyself['order_sn'] = $overTimePtOrder['order_sn'];
        $pplyMyself['belong'] = 1;
        $pplyMyself['type'] = 1;
        $pplyMyself['goods_sn'] = $overTimePtOrder['goods_sn'];
        $pplyMyself['sku_sn'] = $overTimePtOrder['sku_sn'];
        $pplyMyself['price'] = $goodsInfo['price'];
        $pplyMyself['count'] = $goodsInfo['count'];
        $pplyMyself['total_price'] = $goodsInfo['total_price'];
        $pplyMyself['reward_base_price'] = $goodsInfo['total_price'];
        $pplyMyself['vdc'] = $ppylGoodsInfo['reward_scale'];
        $pplyMyself['vdc_level'] = 0;
        $pplyMyself['vdc_genre'] = 2;
        $pplyMyself['reward_type'] = 2;
        $pplyMyself['purchase_price'] = $goodsInfo['cost_price'];
        $pplyMyself['level'] = $userInfo['vip_level'];
        $pplyMyself['link_uid'] = $overTimePtOrder['uid'];
        $pplyMyself['reward_price'] = $ppylGoodsInfo['reward_price'];
        $pplyMyself['real_reward_price'] = $pplyMyself['reward_price'];
        $pplyMyself['freed_status'] = 3;

        if (!empty($userInfo['c_vip_level']) && $userInfo['c_vip_time_out_time'] > time() && $userInfo['auto_receive_reward'] == 1) {
            $pplyMyself['arrival_status'] = 2;
            $pplyMyself['grant_time'] = time() + $rewardRule['frozen_reward_time'];
            $pplyMyself['receive_time'] = time();
            $pplyMyself['remark'] = '会员尊享: 系统自动领取奖励';
        } else {
            $pplyMyself['arrival_status'] = 3;
        }

        $rewardUser = [];
        if (!empty($userInfo['topLink'])) {
            if (!empty($userInfo['topLink']['vip_level'])) {
                $userInfo['topLink']['vdc_type'] = 'one';
                $rewardUser[] = $userInfo['topLink'];
            }

            $topTopLink = User::where(['uid' => $userInfo['topLink']['link_superior_user'], 'status' => [1]])->findOrEmpty()->toArray();
            $refundRes['topTopLink'] = $topTopLink ?? [];

            if (!empty($topTopLink) && !empty($topTopLink['vip_level'])) {
                $topTopLink['vdc_type'] = 'two';
                $rewardUser[] = $topTopLink;
            }
        }
        $refundRes['rewardUser'] = $rewardUser ?? [];

        //添加订单推荐人红包冻结明细
        if (!empty($rewardUser)) {
            $ppylMemberVdc = PpylMemberVdc::where(['status' => 1])->select()->toArray();
            foreach ($ppylMemberVdc as $key => $value) {
                $ppylMemberVdcInfo[$value['level']] = $value;
            }
            foreach ($rewardUser as $key => $value) {
                $nowVdc = $ppylMemberVdcInfo[$value['vip_level']];
                $vdcLevel = 'vdc_' . $value['vdc_type'];
                if ($nowVdc['close_divide'] == 2 && !empty($nowVdc[$vdcLevel] ?? null)) {
                    $pplyTopUser[$key]['reward_sn'] = $codeBuilder->buildRewardSn();
                    $pplyTopUser[$key]['order_uid'] = $overTimePtOrder['uid'];
                    $pplyTopUser[$key]['order_sn'] = $overTimePtOrder['order_sn'];
                    $pplyTopUser[$key]['belong'] = 1;
                    $pplyTopUser[$key]['type'] = 2;
                    $pplyTopUser[$key]['goods_sn'] = $overTimePtOrder['goods_sn'];
                    $pplyTopUser[$key]['sku_sn'] = $overTimePtOrder['sku_sn'];
                    $pplyTopUser[$key]['price'] = $goodsInfo['price'];
                    $pplyTopUser[$key]['count'] = $goodsInfo['count'];
                    $pplyTopUser[$key]['total_price'] = $goodsInfo['total_price'];
                    $pplyTopUser[$key]['reward_base_price'] = $pplyMyself['reward_price'];
                    $pplyTopUser[$key]['vdc'] = $nowVdc[$vdcLevel];
                    $pplyTopUser[$key]['vdc_level'] = ($vdcLevel == 'vdc_two') ? 2 : 1;
                    $pplyTopUser[$key]['vdc_genre'] = 2;
                    $pplyTopUser[$key]['reward_type'] = 2;
                    $pplyTopUser[$key]['purchase_price'] = $goodsInfo['cost_price'];
                    $pplyTopUser[$key]['level'] = $value['vip_level'];
                    $pplyTopUser[$key]['link_uid'] = $value['uid'];
                    $pplyTopUser[$key]['reward_price'] = priceFormat($pplyTopUser[$key]['reward_base_price'] * $pplyTopUser[$key]['vdc']);
                    $pplyTopUser[$key]['real_reward_price'] = $pplyTopUser[$key]['reward_price'];
                    $pplyTopUser[$key]['freed_type'] = $rewardRule['top_reward_receive_type'] != -1 ? 1 : 2;
                    $pplyTopUser[$key]['freed_limit_start_time'] = strtotime(date('Y-m-d', time()) . ' 00:00:00');
                    $pplyTopUser[$key]['freed_limit_end_time'] = $pplyTopUser[$key]['freed_limit_start_time'] + ($rewardRule['freed_expire_time'] ?? 3600 * 24);

                    //默认待领取,需要激活
                    $pplyTopUser[$key]['arrival_status'] = 3;
                    $pplyTopUser[$key]['freed_status'] = 2;

                    $limitNumber = $rewardRule['top_reward_receive_order_number'] ?? 0;

                    if (!empty($rewardRule) && $rewardRule['top_reward_receive_type'] != -1 && !empty($limitNumber)) {
                        //有条件激活
                        $pplyTopUser[$key]['freed_type'] = 1;
                        $ppMap = [];
//                            switch ($rewardRule['top_reward_receive_type'] ?? -1) {
//                                case 1:
//                                    $timeStart = null;
//                                    $timeEnd = null;
//                                    $needLimit = true;
//                                    break;
//                                case 2:
//                                    $timeStart = date('Y-m-d', time()) . ' 00:00:00';
//                                    $timeEnd = date('Y-m-d', time()) . ' 23:59:59';
//                                    $needLimit = true;
//                                    break;
//                                case 3:
//                                    //当前日期
//                                    $sdefaultDate = date("Y-m-d");
//                                    //$first =1 表示每周星期一为开始日期 0表示每周日为开始日期
//                                    $first = 1;
//                                    //获取当前周的第几天 周日是 0 周一到周六是 1 - 6
//                                    $w = date('w', strtotime($sdefaultDate));
//                                    $timeStart = date('Y-m-d', strtotime("$sdefaultDate -" . ($w ? $w - $first : 6) . ' days')) . ' 00:00:00';
//                                    //本周结束日期
//                                    $timeEnd = date('Y-m-d', strtotime("$timeStart +6 days")) . ' 23:59:59';
//                                    $needLimit = true;
//                                    break;
//                                case 4:
//                                    //本月的开始和结束
//                                    $timeStart = date('Y-m-01', strtotime(date("Y-m-d"))) . ' 00:00:00';
//                                    $timeEnd = date('Y-m-d', strtotime("$timeStart +1 month -1 day")) . ' 23:59:59';
//                                    $needLimit = true;
//                                    break;
//                            }
                        if ($rewardRule['top_reward_receive_type'] != -1) {
                            $timeStart = $pplyTopUser[$key]['freed_limit_start_time'];
                            $timeEnd = $pplyTopUser[$key]['freed_limit_end_time'];
                        }
                        if (!empty($timeStart) && !empty($timeEnd)) {
                            $ppMap[] = ['create_time', '>=', $timeStart];
                            $ppMap[] = ['create_time', '<=', $timeEnd];
                        }
                        if (!empty($needLimit)) {
                            $ppMap[] = ['activity_status', 'in', [2, -3]];
                            $ppMap[] = ['uid', '=', $value['uid']];
                            $orderNumber = PpylOrder::where($ppMap)->count();
                        }
                    }

                    if (empty($limitNumber) || (!empty($limitNumber)) && $limitNumber <= ($orderNumber ?? 0) || (!empty($limitNumber) && $rewardRule['top_reward_receive_type'] == -1)) {
                        //符合领取条件则修改释放状态为已激活,可以领取
                        $pplyTopUser[$key]['freed_status'] = 1;

                        //判断用户是否为会员,是否要自动领取
                        if (!empty($value['c_vip_level']) && $value['c_vip_time_out_time'] > time() && $value['auto_receive_reward'] == 1) {
                            $pplyTopUser[$key]['arrival_status'] = 2;
                            $pplyTopUser[$key]['grant_time'] = time() + $rewardRule['frozen_reward_time'];
                            $pplyTopUser[$key]['receive_time'] = time();
                            $pplyTopUser[$key]['freed_status'] = 1;
                            $pplyTopUser[$key]['remark'] = '会员尊享: 系统自动领取奖励';
                        }

                    }
                }
            }
        }


        //添加奖励数据
        if (!empty($pplyMyself ?? [])) {
            $returnRes['rewardMyself'] = PpylReward::create($pplyMyself)->getData();
        }
        if (!empty($pplyTopUser ?? [])) {
            (new PpylReward())->saveAll($pplyTopUser);
            $returnRes['rewardMyself'] = $pplyTopUser;
        }

        if (!empty($data['logRecord'] ?? false)) {
            $log['msg'] = '发放订单' . $order_sn . '的中奖奖励';
            $log['data'] = $data;
            $log['res'] = $refundRes;
            $this->log($log, 'info');
        }

        return judge($refundRes);
    }

    /**
     * @title  领取红包
     * @param array $data
     * @return bool
     * @throws \Exception
     */
    public function receiveReward(array $data)
    {
        $uid = $data['uid'];
        $orderSn = $data['order_sn'] ?? [];
        $map[] = ['uid', '=', $uid];
        if (!empty($orderSn)) {
            $map[] = ['order_sn', 'in', $orderSn];
        }
        if (!empty($data['type'] ?? null)) {
            $map[] = ['type', '=', $data['type']];
        }
        $map[] = ['arrival_status', '=', 3];
        $rewardOrder = PpylReward::where($map)->select()->toArray();
        if (empty($rewardOrder)) {
            return false;
        }

        foreach ($rewardOrder as $key => $value) {
            if ($value['type'] == 1) {
                $myselfReward[] = $value;
            } elseif ($value['type'] == 2) {
                $recommendReward[] = $value;
            }
        }

        if (!empty($recommendReward)) {
            $earlyStartTime = min(array_unique(array_column($recommendReward, 'freed_limit_start_time')));
            $lastEndTime = max(array_unique(array_column($recommendReward, 'freed_limit_end_time')));
            $ppMap[] = ['create_time', '>=', $earlyStartTime];
            $ppMap[] = ['create_time', '<=', $lastEndTime];
            $ppMap[] = ['activity_status', 'in', [2, -3]];
            $ppMap[] = ['uid', '=', $value['uid']];
            $orderNumber = PpylOrder::where($ppMap)->select()->toArray();
            $canRewardRecommendOrder = [];
            if (!empty($orderNumber)) {
                foreach ($recommendReward as $key => $value) {
                    //如果有条件则判断在规定时间内是否有成团的订单记录,有则可以领取,无条件则可以直接领取
                    if ($value['freed_type'] == 1) {
                        foreach ($orderNumber as $oK => $oV) {
                            if ($oV['groups_time'] >= $value['freed_limit_start_time'] && $oV['groups_time'] < $value['freed_limit_end_time']) {
                                $canRewardRecommendOrder[] = $value;
                            }
                        }
                    } else {
                        $canRewardRecommendOrder[] = $value;
                    }
                }
            }
        }

        //查看红包奖励冻结规则
        $rewardRule = PpylConfig::where(['status' => 1])->field('frozen_reward_time,top_reward_receive_order_number,top_reward_receive_type,freed_expire_time')->findOrEmpty()->toArray();

        //领取个人红包
        if (!empty($myselfReward ?? [])) {
            $myOrder = array_column($myselfReward, 'order_sn');
            $pplyTopUser['arrival_status'] = 2;
            $pplyTopUser['grant_time'] = time() + $rewardRule['frozen_reward_time'];
            $pplyTopUser['receive_time'] = time();
            $myselfRes = PpylOrder::update($pplyTopUser, ['order_sn' => $myOrder, 'arrival_status' => 3]);
        }
        //领取推荐红包
        if (!empty($canRewardRecommendOrder ?? [])) {
            $reOrder = array_column($canRewardRecommendOrder, 'order_sn');
            $pplyTopUser['arrival_status'] = 2;
            $pplyTopUser['grant_time'] = time() + $rewardRule['frozen_reward_time'];
            $pplyTopUser['receive_time'] = time();
            $recommendRes = PpylOrder::update($pplyTopUser, ['order_sn' => $reOrder, 'arrival_status' => 3]);
        }
        return true;
    }

    /**
     * @title  取消排队订单,会执行退款
     * @param array $data
     * @return mixed
     * @throws \Exception
     */
    public function cancelPpylWait(array $data)
    {
        $orderSn = $data['order_sn'] ?? null;
        $thrErrorType = $data['notThrowError'] ?? false;
        $this->notThrowError = $thrErrorType;

        if (empty($orderSn)) {
            return false;
        }
        if (!empty(cache('dealWaitOrder-' . $orderSn))) {
            return $this->throwErrorMsg(['msg' => '排队处理中,暂无法取消'], $this->notThrowError);
        }
        $map[] = ['order_sn', '=', $orderSn];
        $map[] = ['wait_status', '=', 1];
//        $map[] = ['timeout_time', '>', time()];

        $overTimePtOrder = PpylWaitOrder::where($map)->select()->toArray();

        if (empty($overTimePtOrder)) {
            return $this->throwErrorMsg(['msg' => '只有排队中的订单才允许操作哦'], $this->notThrowError);
        }

        if (!empty($overTimePtOrder['timeout_time']) && $overTimePtOrder['timeout_time'] <= time()) {
            return $this->throwErrorMsg(['msg' => '订单已超时~如未正常取消请联系客服'], $this->notThrowError);
        }


        $returnData = [];
        if (!empty($overTimePtOrder)) {
            //已付款的处理方式
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

            //未付款的处理方式
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

            //查询是否有自动拼团计划,如果有则取消自动计划, 因为用户主动干预了
            $autoRes = Db::transaction(function () use ($overTimePtOrder) {

                $autoArray = [];
                foreach ($overTimePtOrder as $key => $value) {
                    $existAuto = PpylAuto::where(['goods_sn' => $value['goods_sn'], 'sku_sn' => $value['sku_sn'], 'uid' => $value['uid'], 'area_code' => $value['area_code'], 'status' => 1])->count();
                    if (!empty($existAuto)) {
                        PpylAuto::update(['status' => 3], ['goods_sn' => $value['goods_sn'], 'sku_sn' => $value['sku_sn'], 'area_code' => $value['area_code'], 'status' => 1, 'uid' => $value['uid']]);
                        $autoArray[] = $existAuto;
                    }
                }
                return ($autoArray ?? []);
            });


            $returnData['res'] = $res ?? false;
            $returnData['noPayRes'] = $noPayRes ?? false;
        }

        return judge($returnData);
    }

    /**
     * @title  取消自动计划
     * @param array $data
     * @return array|bool
     * @throws \Exception
     */
    public function cancelAutoPlan(array $data)
    {
        $uid = $data['uid'];
        $orderSn = $data['order_sn'] ?? null;
        $thrErrorType = $data['notThrowError'] ?? false;
        $this->notThrowError = $thrErrorType;

        if (empty($orderSn)) {
            return false;
        }
        if (!empty(cache('dealWaitOrder-' . $orderSn))) {
            return $this->throwErrorMsg(['msg' => '排队处理中,暂无法取消'], $this->notThrowError);
        }

        $map[] = ['uid','=',$uid];
        $map[] = ['status', '=', 1];

        $planOrder = PpylAuto::where($map)->where(function ($query) use ($orderSn){
            $map1[] = ['order_sn', '=', $orderSn];
            $map2[] = ['', 'exp', Db::raw('FIND_IN_SET("' . $orderSn . '",`restart_order_sn`)')];
            $query->whereOr([$map1,$map2]);
        })->findOrEmpty()->toArray();

        if (empty($planOrder)) {
            return $this->throwErrorMsg(['msg' => '暂无可取消的自动计划,稍后订单状态将会自动更新'], $this->notThrowError);
        }

        if (!empty($planOrder['end_time']) && $planOrder['end_time'] <= time()) {
            return $this->throwErrorMsg(['msg' => '自动计划已超时~如未正常取消请联系客服'], $this->notThrowError);
        }

        $DBRes = Db::transaction(function() use ($data,$planOrder){
            $planSn = $planOrder['plan_sn'];
            $updateTime = time();
            $errMsg = ' 于' . date('Y-m-d H:i:s') . '用户自行取消';
            $res = Db::query("update sp_ppyl_auto set fail_msg = IFNULL(concat('$errMsg',fail_msg),'$errMsg'),status = 3,update_time = '$updateTime' where plan_sn = '$planSn';");
            return true;
        });
        return $DBRes ?? false;
    }

    public function getQueue(array $data)
    {
        $redis = Cache::store('redis')->handler();
        $queueList = $redis->lRange('{queues:mhppMemberChain}', 0, -1);
        dump($redis->lLen('{queues:test}'));
        dump($redis->lRange('{queues:test}', 0, -1));
        dump($redis->lSet('{queues:test}', 18, 'Del'));
        dump($redis->lrem('{queues:test}', 'Del', 0));
//        dump($redis->rpop('{queues:test}'));
        dump($redis->lRange('{queues:test}', 0, -1));
        die;
    }

    /**
     * @title  返回错误
     * @param array $data 抛出异常的数组,如['errorCode'=>170001]
     * @param mixed $thrErrorType 抛出异常类型,为空则直接抛出异常,不为空则返回对应的错误信息
     * @return array
     */
    public function throwErrorMsg(array $data, $thrErrorType = false)
    {
        if (empty($thrErrorType)) {
            $thrErrorType = $this->notThrowError;
        }

        if (empty($thrErrorType)) {
            throw new PpylException($data);
        } else {
            $msg = false;
            if (is_bool($thrErrorType)) {
                $msg = false;
            } else {
                if (!empty($data['errorCode'] ?? null)) {
                    $msg = config('exceptionCode.PpylException')[$data['errorCode']] ?? '拼拼有礼服务有误';
                } elseif (!empty($data['msg'] ?? null)) {
                    $msg = $data['msg'] ?? '拼拼有礼服务有误';
                } else {
                    $msg = '拼拼有礼服务有误';
                }
            }
            if(!empty($data['errorCode'] ?? null)){
                return ['success' => false, 'msg' => $msg, 'errorCode' => $data['errorCode']];
            }else{
                return ['success' => false, 'msg' => $msg];
            }

        }
    }

    /**
     * @title  指定用户CVIP有效期
     * @param array $data
     * @return bool|mixed
     */
    public function assignCVIP(array $data)
    {
        $uid = $data['uid'];
        //$time 必须以秒为单位
        $time = $data['time'] ?? 0;
        if (empty(intval($time))) {
            return false;
        }

        $DBRes = Db::transaction(function () use ($uid, $time, $data) {
            $userInfo = User::where(['uid' => $uid, 'status' => 1])->lock(true)->findOrEmpty()->toArray();

            if (empty($userInfo)) {
                return false;
            }
            $CVIPTimeoutTime = $userInfo['c_vip_time_out_time'] ?? 0;

            if (empty($CVIPTimeoutTime)) {
                $CVIPTimeoutTime = time();
            }
            if ($time > 0) {
                $update['c_vip_time_out_time'] = $CVIPTimeoutTime + $time;
                $update['c_vip_level'] = 1;
            } else {
                $update['c_vip_time_out_time'] = $CVIPTimeoutTime + $time;
                if ((string)$update['c_vip_time_out_time'] <= time()) {
                    $update['c_vip_time_out_time'] = null;
                    $update['c_vip_level'] = 0;
                }
            }

            $res = [];
            if (!empty($update)) {
                $res = User::update($update, ['uid' => $uid]);
            }
            return ['res' => $res ?? false, 'old' => $CVIPTimeoutTime ?? null, 'now' => $update['c_vip_time_out_time'] ?? null];
        });
        return $DBRes;
    }

    /**
     * @title  会员开启自动领取红包功能
     * @param array $data
     * @return bool
     */
    public function autoReceiveSwitch(array $data)
    {
        $uid = $data['uid'];
        $type = $data['type'] ?? 1;
        switch ($type) {
            case 1:
                $changType = 2;
                break;
            case 2:
                $changType = 1;
                break;
        }

        if (empty($changType)) {
            return false;
        }

        $userInfo = User::where(['uid' => $uid, 'status' => 1])->field('uid,phone,c_vip_level,c_vip_time_out_time,auto_receive_reward')->findOrEmpty()->toArray();
        if (empty($userInfo)) {
            return false;
        }
        if (empty($userInfo['c_vip_level']) || (empty($userInfo['c_vip_time_out_time'] ?? null)) || (!empty($userInfo['c_vip_time_out_time'] ?? null) && $userInfo['c_vip_time_out_time'] <= time())) {
            return false;
        }
        if ($changType == 1 && $userInfo['auto_receive_reward'] == 1) {
            return true;
        }
        if ($changType == 2 && $userInfo['auto_receive_reward'] == 2) {
            return true;
        }

        $res = User::update(['auto_receive_reward' => $changType], ['uid' => $uid, 'status' => 1]);

        return judge($res);
    }

    /**
     * @title  记录日志
     * @param array $data 数据信息
     * @param string $level 日志信息
     * @param string $channel 日志通道
     * @return mixed
     */
    public function log(array $data, string $level = 'error', string $channel = 'ppyl')
    {
        return (new Log())->setChannel($channel)->record($data, $level);
    }
}