<?php
// +----------------------------------------------------------------------
// |[ 文档说明: ]
// +----------------------------------------------------------------------



namespace app\lib\services;

use app\lib\exceptions\ServiceException;
use app\lib\models\Order;
use app\lib\models\PropagandaReward as PropagandaRewardModel;
use app\lib\models\PropagandaRewardPlan;
use app\lib\models\Divide;
use app\lib\models\BalanceDetail;
use app\lib\models\ShareholderMember;
use app\lib\models\ShareholderReward;
use app\lib\models\ShareholderRewardPlan;
use app\lib\models\User;
use think\facade\Db;

class PropagandaReward
{
    /**
     * @title  发放奖励
     * @param array $data
     * @return mixed
     */
    public function reward(array $data = [])
    {
        $res = Db::transaction(function () use ($data) {
            $planSn = $data['plan_sn'] ?? null;
            $showNumber = $data['show_number'] ?? false;
            $userList = [];
            $rewardUser = [];
            //是否需要自行选择人群
            $selectUser = $data['selectUser'] ?? false;
            $selectUserList = $data['selectUserList'] ?? [];
            //自选人群需要遵守正常规则,如果否则无任何限制,随意发放
            $selectUserFollowRule = $data['selectUserFollowRule'] ?? true;
            //自选人群不遵守规则的情况下的订单总额,目的是限制用户的奖励上限
            $customizeOrderRealPrice = 99999;
            //查询计划详情做相应约束
            $planInfo = PropagandaRewardPlan::where(['plan_sn'=>$planSn,'status'=>1, 'grant_res' => [3, 4]])->findOrEmpty()->toArray();
            if (empty($planInfo)) {
                return $this->propagandaRewardErrorReturn(['plan_sn'=>$planSn,'msg'=>'无效的计划']);
//                throw new ServiceException(['msg' => '无效的计划']);
            }
            if ((!empty($selectUser) || !empty($selectUserList)) && $planInfo['plan_type'] != 2) {
                return $this->propagandaRewardErrorReturn(['plan_sn'=>$planSn,'msg'=>'计划类型不允许自定义人群']);
//                throw new ServiceException(['msg' => '计划类型不允许自定义人群']);
            }
            if(!empty($selectUser)){
                foreach ($selectUserList as $key => $value) {
                    $customizeRewardRule[$value['level']] = $value['total_price'] ?? 0;
                    foreach ($value['uid'] as $cKey => $cValue) {
                        $selectUid[] = $cValue;
                    }
                }
                if (empty($selectUid)) {
                    return $this->propagandaRewardErrorReturn(['plan_sn'=>$planSn,'msg'=>'指定人群参数有误']);
                }
            }

            if(!empty($selectUser) && empty($selectUserFollowRule)){
                $userList = User::where(['status' => 1, 'uid' => $selectUid])->field('uid,name,phone,(ad_balance+ ad_withdraw_total + ad_fronzen_balance) as user_all_reward')->select()->toArray();
            }else{
                //如果有指定人群查指定人群
                if (!empty($selectUser) && !empty($selectUserFollowRule)) {
                    $map[] = ['a.uid', 'in', array_unique($selectUid)];
                }
                $map[] = ['a.order_type', 'in', [3,5]];
                $map[] = ['a.order_status', 'in', [8]];
                $map[] = ['b.pay_status', '=', 2];
                $map[] = ['b.status', 'in', [1]];
                $map[] = ['a.create_time', '<=', 1650960000];

                $orderGoodsId = Order::alias('a')
                    ->join('sp_order_goods b', 'a.order_sn = b.order_sn', 'left')
                    ->join('sp_user u', 'a.uid = u.uid', 'left')
                    ->where($map)
                    ->lock(true)
                    ->column('b.id', 'u.id');

                if (!empty($orderGoodsId)) {
                    $numberList = Order::alias('a')
                        ->join('sp_order_goods b', 'a.order_sn = b.order_sn', 'left')
                        ->where($map)->field('a.uid,sum(b.count) as number,sum(b.real_pay_price) as all_real_pay_price')
                        ->group('a.uid')
                        ->buildSql();
                    $userList = Db::table($numberList . ' d')->join('sp_user c', 'd.uid = c.uid', 'left')->field('d.*,(c.ad_balance+ c.ad_withdraw_total+ c.ad_fronzen_balance) as user_all_reward,c.phone')->select()->toArray();
                }
            }

            //如果是查询特定用户需要补齐数据,然后补充后续需要的数据格式
            if (!empty($selectUser)) {
                //查询部分没有任何符合订单信息的用户,补齐数据
                foreach (array_unique($selectUid) as $key => $value) {
                    if (!in_array($value, array_column($userList, 'uid'))) {
                        $notOrderUser[] = $value;
                    }
                }
                if (!empty($notOrderUser)) {
                    $notOrderUserList = User::where(['status' => 1, 'uid' => $notOrderUser])->field('uid,name,phone,(ad_balance+ ad_withdraw_total + ad_fronzen_balance) as user_all_reward')->select()->toArray();
                    if (!empty($notOrderUserList)) {
                        foreach ($notOrderUserList as $key => $value) {
                            $notOrderUserList[$key]['all_real_pay_price'] = 0;
                        }
                        $userList = array_merge_recursive($userList, $notOrderUserList);
                    }
                }
                if (!empty($userList)) {
                    foreach ($userList as $key => $value) {
                        foreach ($selectUserList as $sKey => $sValue) {
                            if (in_array($value['uid'], $sValue['uid'])) {
                                $userList[$key]['reward_level'] = $sValue['level'];
                            }
                        }
                    }
                }
            }

            if (empty($userList)) {
                return $this->propagandaRewardErrorReturn(['plan_sn'=>$planSn,'msg'=>'暂无复合的用户列表']);
            }
            foreach ($userList as $key => $value) {
                if (empty($value['user_all_reward'])) {
                    $userList[$key]['user_all_reward'] = 0;
                }
            }

            $rewardRule = PropagandaRewardModel::where(['status' => 1])->order('combo_number desc')->select()->toArray();

            $rewardPlanInfo = PropagandaRewardPlan::where(['plan_sn' => $planSn, 'status' => 1, 'grant_res' => [3, 4]])->where(function ($query) {
                $map1[] = ['start_type', '=', 1];
                $map2[] = ['start_type', '=', 2];
                $map2[] = ['start_time', '>=', time()];
                $query->whereOr([$map1, $map2]);
            })->value('total_reward_price');

            if (empty($rewardRule) || (empty($rewardPlanInfo) && !empty($planSn))) {
                return null;
            }

            //查看是否存在已发放的记录,存在则不允许重复发放
            $planExistReward = Divide::where(['order_sn' => $planSn, 'type' => 2, 'status' => 1])->count();
            if (!empty($planExistReward)) {
                return $this->propagandaRewardErrorReturn(['plan_sn'=>$planSn,'msg'=>'该计划已存在至少一条发放记录, 无法重复发放']);
            }
            $thisRewardPrice = [];
            $onceRewardPrice = [];
            $allRewardUser = [];
            $rewardUserCount = [];
            foreach ($rewardRule as $key => $value) {
                $thisRewardPrice[$value['level']] = priceFormat($rewardPlanInfo * $value['scale']);
                //如果有自定义奖池金额则用自定义的
                if(!empty($selectUser)){
                    if(!empty($customizeRewardRule[$value['level']] ?? 0) && $customizeRewardRule[$value['level']] > 0){
                        $thisRewardPrice[$value['level']] = $customizeRewardRule[$value['level']];
                    }
                }
                $rewardRuleInfo[$value['level']] = $value;
            }

            //查询已经发放过奖励的用户, 发放过将不再继续发放
            $planRewardHis = Divide::where(['type' => 2, 'order_sn' => $planSn, 'arrival_status' => 1])->column('link_uid');
            $allUserPhoneIsSelect = array_column($userList,'phone');

            foreach ($userList as $key => $value) {
                foreach ($rewardRule as $rKey => $rValue) {
                    if (in_array($value, $planRewardHis)) {
                        $existRewardUser[] = $value['uid'];
                        $existRewardUserPhone[] = $value['phone'] ?? null;
                        unset($userList[$key]);
                        continue 2;
                    }
                    if (empty($selectUser)) {
                        if ($value['number'] >= $rValue['combo_number'] && (string)$value['user_all_reward'] <= (string)priceFormat($value['all_real_pay_price'] * $rValue['upper_limit_scale']) && !in_array($value['uid'], $planRewardHis ?? [])) {
                            $rewardUser[$rValue['level']][] = $value['uid'];
                            $allRewardUser[] = $value['uid'];
                            $allRewardUserPhone[] = $value['phone'];
                            $value['max_allow_reward'] = priceFormat($value['all_real_pay_price'] * $rValue['upper_limit_scale']);
                            $rewardUserInfo[$value['uid']] = $value;
                            unset($userList[$key]);
                            continue 2;
                        }
                    } else {
                        //遵守规则与不遵守的判断奖励上限的判断金额不同,遵守的是统计出来的实付金额,不遵守的是写死的固定金额
                        if (!empty($selectUserFollowRule)) {
                            if ($value['reward_level'] == $rValue['level'] && (string)$value['user_all_reward'] <= (string)priceFormat($value['all_real_pay_price'] * $rValue['upper_limit_scale']) && !in_array($value['uid'], $planRewardHis ?? [])) {
                                $rewardUser[$rValue['level']][] = $value['uid'];
                                $allRewardUser[] = $value['uid'];
                                $allRewardUserPhone[] = $value['phone'];
                                $value['max_allow_reward'] = priceFormat($value['all_real_pay_price'] * $rValue['upper_limit_scale']);
                                $rewardUserInfo[$value['uid']] = $value;
                                unset($userList[$key]);
                                continue 2;
                            }
                        } else {
                            if ($value['reward_level'] == $rValue['level'] && (string)$value['user_all_reward'] <= (string)priceFormat($customizeOrderRealPrice * $rValue['upper_limit_scale']) && !in_array($value['uid'], $planRewardHis ?? [])) {
                                $rewardUser[$rValue['level']][] = $value['uid'];
                                $allRewardUser[] = $value['uid'];
                                $allRewardUserPhone[] = $value['phone'];
                                $value['max_allow_reward'] = priceFormat($customizeOrderRealPrice * $rValue['upper_limit_scale']);
                                $rewardUserInfo[$value['uid']] = $value;
                                unset($userList[$key]);
                                continue 2;
                            }
                        }

                    }

                }
            }

            if (empty($rewardUser)) {
                return $this->propagandaRewardErrorReturn(['plan_sn'=>$planSn,'msg'=>'根据规则约束后查询无效奖励人群, 可能原因为所有用户均以达到奖励上限']);
            }

            if (!empty(count($userList ?? []))) {
                return $this->propagandaRewardErrorReturn(['plan_sn' => $planSn, 'msg' => '导入的用户中存在不符合条件或已满额发放的, 请审核后剔除用户名单重新导入, 包含的用户手机号码有:' . implode(', ', array_column($userList, 'phone'))]);
            }

            foreach ($rewardRule as $key => $value) {
                if (empty($rewardUser[$value['level']] ?? [])) {
                    $onceRewardPrice[$value['level']] = 0;
                } else {
                    $onceRewardPrice[$value['level']] = priceFormat($thisRewardPrice[$value['level']] / count($rewardUser[$value['level']] ?? []));
                }
            }

            foreach ($rewardUser as $key => $value) {
                $rewardUserCount[$key] = count($value);
            }
            if (!empty($showNumber)) {
                $rewardUserCounts = [];
                $thisRewardPrices = [];
                $onceRewardPrices = [];
                $rewardUsers = [];
                if (!empty($rewardUser)) {
                    foreach ($rewardUser as $key => $value) {
                        $rewardUsers[$key]['level'] = $key;
                        $rewardUsers[$key]['level_name'] = $rewardRuleInfo[$key]['name'];
                        $rewardUsers[$key]['data'] = $value;
                    }
                }
                if (!empty($rewardUserCount)) {
                    foreach ($rewardUserCount as $key => $value) {
                        $rewardUserCounts[$key]['level'] = $key;
                        $rewardUserCounts[$key]['level_name'] = $rewardRuleInfo[$key]['name'];
                        $rewardUserCounts[$key]['data'] = $value;
                    }
                }
                if (!empty($thisRewardPrice)) {
                    foreach ($thisRewardPrice as $key => $value) {
                        $thisRewardPrices[$key]['level'] = $key;
                        $thisRewardPrices[$key]['level_name'] = $rewardRuleInfo[$key]['name'];
                        $thisRewardPrices[$key]['data'] = $value;
                    }
                }
                if (!empty($onceRewardPrice)) {
                    foreach ($onceRewardPrice as $key => $value) {
                        $onceRewardPrices[$key]['level'] = $key;
                        $onceRewardPrices[$key]['level_name'] = $rewardRuleInfo[$key]['name'];
                        $onceRewardPrices[$key]['data'] = $value;
                    }
                }

                return ['msg' => '仅查询可领取奖励人群', 'allRewardUserCount' => array_values($rewardUserCounts), 'rewardScalePrice' => array_values($thisRewardPrices), 'onceRewardPrice' => array_values($onceRewardPrices)];
            }

            $divideData = [];
            $balanceDetail = [];

            foreach ($rewardUser as $key => $value) {
                $sql[$key] = 'UPDATE sp_user SET ad_balance = CASE uid';
                $userPrice = 0;
                foreach ($value as $cKey => $cValue) {
                    //判断是否会超过允许奖励的上限,如果超过了只能拿余下的部分,不能拿全额
                    if ((string)$rewardUserInfo[$cValue]['max_allow_reward'] - $rewardUserInfo[$cValue]['user_all_reward'] >= $onceRewardPrice[$key]) {
                        $userPrice = $onceRewardPrice[$key];
                    } else {
//                        $userPrice = $onceRewardPrice[$key] - ($rewardUserInfo[$cValue]['max_allow_reward'] - $rewardUserInfo[$cValue]['user_all_reward']);
                        $userPrice = $rewardUserInfo[$cValue]['max_allow_reward'] - $rewardUserInfo[$cValue]['user_all_reward'];
                    }
                    if ($userPrice <= 0) {
                        continue;
                    }

                    if (($cKey + 1) >= count($value)) {
                        $sql[$key] .= " WHEN '" . $cValue . "' THEN " . 'ad_balance + ' . $userPrice . ' ELSE ad_balance END';
                    } else {
                        $sql[$key] .= " WHEN '" . $cValue . "' THEN " . 'ad_balance + ' . $userPrice;
                    }

                    $divideData[$key][$cKey]['order_sn'] = $planSn;
                    $divideData[$key][$cKey]['order_uid'] = $cValue;
                    $divideData[$key][$cKey]['type'] = 2;
                    $divideData[$key][$cKey]['vdc'] = $rewardRuleInfo[$key]['scale'] ?? 0;
                    $divideData[$key][$cKey]['level'] = $rewardRuleInfo[$key]['level'] ?? 0;
                    $divideData[$key][$cKey]['link_uid'] = $cValue;
                    $divideData[$key][$cKey]['divide_price'] = $userPrice;
                    $divideData[$key][$cKey]['real_divide_price'] = $userPrice;
                    $divideData[$key][$cKey]['arrival_status'] = 1;
                    $divideData[$key][$cKey]['arrival_time'] = time();
                    $divideData[$key][$cKey]['total_price'] = $rewardPlanInfo;
                    $divideData[$key][$cKey]['price'] = $thisRewardPrice[$key];

                    $balanceDetail[$key][$cKey]['uid'] = $cValue;
                    $balanceDetail[$key][$cKey]['order_sn'] = $planSn;
                    $balanceDetail[$key][$cKey]['type'] = 1;
                    $balanceDetail[$key][$cKey]['price'] = $userPrice;
                    $balanceDetail[$key][$cKey]['change_type'] = 12;
                }

            }
            //插入数据库
            foreach ($sql as $key => $value) {
                if ($value != 'UPDATE sp_user SET ad_balance = CASE uid') {
                    $sqlRes[$key] = Db::query($value);
                    (new Divide())->saveAll($divideData[$key]);
                    (new BalanceDetail())->saveAll($balanceDetail[$key]);
                }else{
                    return $this->propagandaRewardErrorReturn(['plan_sn' => $planSn, 'msg' => '无有效可奖励人群, 所有用户奖励均已达到上限']);
                }
            }

            PropagandaRewardPlan::update(['grant_res' => 1, 'grant_time' => time()], ['plan_sn' => $planSn, 'status' => 1, 'grant_res' => [3, 4]]);


            return ['allRewardUser' => $rewardUser ?? [], 'allRewardUserCount' => $rewardUserCount ?? [], 'rewardScalePrice' => $thisRewardPrice ?? [], 'onceRewardPrice' => $onceRewardPrice ?? [], 'sql' => $sql, 'divideData' => $divideData ?? [], 'balanceDetail' => $balanceDetail ?? []];
        });
        //记录日志
        $log['param'] = $data ?? [];
        $log['returnData'] = $res;
        $this->log($log);

        return $res;

    }

    /**
     * @title  广宣奖发放失败后的操作
     * @param array $data
     * @return mixed
     */
    public function propagandaRewardErrorReturn(array $data)
    {
        $planSn = $data['plan_sn'];
        if (empty($planSn)) {
            return false;
        }
        $msg = $data['msg'] ?? null;
        $update['grant_res'] = 2;
        $update['error_time'] = time();
        if (!empty($msg)) {
            $update['error_remark'] = $msg;
        }
        PropagandaRewardPlan::update($update, ['plan_sn' => $planSn, 'status' => 1, 'grant_res' => [3, 4]]);
        return ['res' => false, 'msg' => $msg, 'plan_sn' => $planSn];
    }


    /**
     * @title  发放股东奖励
     * @param array $data
     * @return mixed
     */
    public function shareholderReward(array $data = [])
    {
        $res = Db::transaction(function () use ($data) {
            $planSn = $data['plan_sn'] ?? null;
            $showNumber = $data['show_number'] ?? false;
            $userList = [];
            $rewardUser = [];
            //是否需要自行选择人群
            $selectUser = $data['selectUser'] ?? false;
            $selectUserList = $data['selectUserList'] ?? [];
            //自选人群需要遵守正常规则,如果否则无任何限制,随意发放
            $selectUserFollowRule = $data['selectUserFollowRule'] ?? true;
            //自选人群不遵守规则的情况下的订单总额,目的是限制用户的奖励上限
            $customizeOrderRealPrice = 399;
            //查询计划详情做相应约束
            $planInfo = ShareholderRewardPlan::where(['plan_sn'=>$planSn,'status'=>1, 'grant_res' => [3, 4]])->findOrEmpty()->toArray();
            if (empty($planInfo)) {
                throw new ServiceException(['msg' => '无效的计划']);
            }
            if ((!empty($selectUser) || !empty($selectUserList)) && $planInfo['plan_type'] != 2) {
                throw new ServiceException(['msg' => '计划类型不允许自定义人群']);
            }
            if(!empty($selectUser)){
                foreach ($selectUserList as $key => $value) {
                    $customizeRewardRule[$value['level']] = $value['total_price'] ?? 0;
                    foreach ($value['uid'] as $cKey => $cValue) {
                        $selectUid[] = $cValue;
                    }
                }
                if (empty($selectUid)) {
                    return null;
                }
            }

            if(!empty($selectUser) && empty($selectUserFollowRule)){
                $userList = User::where(['status' => 1, 'uid' => $selectUid])->field('uid,name,phone')->select()->toArray();
            }else{
                //如果有指定人群查指定人群
                if (!empty($selectUser) && !empty($selectUserFollowRule)) {
                    $sMap[] = ['uid','in',$selectUid];
                }

                $sMap[] = ['status','=',1];
                $sMap[] = ['level','<>',0];
                $userList = ShareholderMember::with(['userName'])->where($sMap)->field('uid,user_phone as phone')->select()->toArray();
            }

            //如果是查询特定用户需要补齐数据,然后补充后续需要的数据格式
            if (!empty($selectUser)) {
                //查询部分没有任何符合订单信息的用户,补齐数据
                foreach (array_unique($selectUid) as $key => $value) {
                    if (!in_array($value, array_column($userList, 'uid'))) {
                        $notOrderUser[] = $value;
                    }
                }
                if (!empty($notOrderUser)) {
                    $notOrderUserList = User::where(['status' => 1, 'uid' => $notOrderUser])->field('uid,name,phone')->select()->toArray();
                    if (!empty($notOrderUserList)) {
                        foreach ($notOrderUserList as $key => $value) {
                            $notOrderUserList[$key]['all_real_pay_price'] = 0;
                        }
                        $userList = array_merge_recursive($userList, $notOrderUserList);
                    }
                }
                if (!empty($userList)) {
                    foreach ($userList as $key => $value) {
                        foreach ($selectUserList as $sKey => $sValue) {
                            if (in_array($value['uid'], $sValue['uid'])) {
                                $userList[$key]['reward_level'] = $sValue['level'];
                            }
                        }
                    }
                }
            }

            if (empty($userList)) {
                return null;
            }

            $rewardRule = ShareholderReward::where(['status' => 1])->order('level asc')->select()->toArray();

            $rewardPlanInfo = ShareholderRewardPlan::where(['plan_sn' => $planSn, 'status' => 1, 'grant_res' => [3, 4]])->where(function ($query) {
                $map1[] = ['start_type', '=', 1];
                $map2[] = ['start_type', '=', 2];
                $map2[] = ['start_time', '>=', time()];
                $query->whereOr([$map1, $map2]);
            })->value('total_reward_price');

            if (empty($rewardRule) || (empty($rewardPlanInfo) && !empty($planSn))) {
                return null;
            }

            //查看是否存在已发放的记录,存在则不允许重复发放
            $planExistReward = Divide::where(['order_sn' => $planSn, 'type' => 5])->count();

            if (!empty($planExistReward)) {
                return null;
            }
            $thisRewardPrice = [];
            $onceRewardPrice = [];
            $allRewardUser = [];
            $rewardUserCount = [];
            foreach ($rewardRule as $key => $value) {
                $thisRewardPrice[$value['level']] = priceFormat($rewardPlanInfo * $value['scale']);
                //如果有自定义奖池金额则用自定义的
                if(!empty($selectUser)){
                    if(!empty($customizeRewardRule[$value['level']] ?? 0) && $customizeRewardRule[$value['level']] > 0){
                        $thisRewardPrice[$value['level']] = $customizeRewardRule[$value['level']];
                    }
                }
                $rewardRuleInfo[$value['level']] = $value;
            }

            //查询已经发放过奖励的用户, 发放过将不再继续发放
            $planRewardHis = Divide::where(['type' => 5, 'order_sn' => $planSn, 'arrival_status' => 1])->column('link_uid');

            foreach ($userList as $key => $value) {
                foreach ($rewardRule as $rKey => $rValue) {
                    if (in_array($value, $planRewardHis)) {
                        $existRewardUser[] = $value['uid'];
                    }
                    $rewardUser[$rValue['level']][] = $value['uid'];
                    $rewardUserList[$rValue['level']][] = $value;
                    $allRewardUser[] = $value['uid'];
                    $rewardUserInfo[$value['uid']] = $value;
                    unset($userList[$key]);
                    continue 2;
//                    if (empty($selectUser)) {
//                        if ((string)$value['user_all_reward'] <= (string)priceFormat($value['all_real_pay_price'] * $rValue['upper_limit_scale']) && !in_array($value['uid'], $planRewardHis ?? [])) {
//                            $rewardUser[$rValue['level']][] = $value['uid'];
//                            $allRewardUser[] = $value['uid'];
//                            $value['max_allow_reward'] = priceFormat($value['all_real_pay_price'] * $rValue['upper_limit_scale']);
//                            $rewardUserInfo[$value['uid']] = $value;
//                            unset($userList[$key]);
//                            continue 2;
//                        }
//                    } else {
//                        //遵守规则与不遵守的判断奖励上限的判断金额不同,遵守的是统计出来的实付金额,不遵守的是写死的固定金额
//                        if (!empty($selectUserFollowRule)) {
//                            if ($value['reward_level'] == $rValue['level'] && (string)$value['user_all_reward'] <= (string)priceFormat($value['all_real_pay_price'] * $rValue['upper_limit_scale']) && !in_array($value['uid'], $planRewardHis ?? [])) {
//                                $rewardUser[$rValue['level']][] = $value['uid'];
//                                $allRewardUser[] = $value['uid'];
//                                $value['max_allow_reward'] = priceFormat($value['all_real_pay_price'] * $rValue['upper_limit_scale']);
//                                $rewardUserInfo[$value['uid']] = $value;
//                                unset($userList[$key]);
//                                continue 2;
//                            }
//                        } else {
//                            if ($value['reward_level'] == $rValue['level'] && (string)$value['user_all_reward'] <= (string)priceFormat($customizeOrderRealPrice * $rValue['upper_limit_scale']) && !in_array($value['uid'], $planRewardHis ?? [])) {
//                                $rewardUser[$rValue['level']][] = $value['uid'];
//                                $allRewardUser[] = $value['uid'];
//                                $value['max_allow_reward'] = priceFormat($customizeOrderRealPrice * $rValue['upper_limit_scale']);
//                                $rewardUserInfo[$value['uid']] = $value;
//                                unset($userList[$key]);
//                                continue 2;
//                            }
//                        }
//
//                    }

                }
            }

            if (empty($rewardUser)) {
                return null;
            }

            foreach ($rewardRule as $key => $value) {
                if (empty($rewardUser[$value['level']] ?? [])) {
                    $onceRewardPrice[$value['level']] = 0;
                } else {
                    $onceRewardPrice[$value['level']] = priceFormat($thisRewardPrice[$value['level']] / count($rewardUser[$value['level']] ?? []));
                }
            }

            foreach ($rewardUser as $key => $value) {
                $rewardUserCount[$key] = count($value);
            }
            if (!empty($showNumber)) {
                $rewardUserCounts = [];
                $thisRewardPrices = [];
                $onceRewardPrices = [];
                $rewardUsers = [];
                if (!empty($rewardUser)) {
                    foreach ($rewardUser as $key => $value) {
                        $rewardUsers[$key]['level'] = $key;
                        $rewardUsers[$key]['level_name'] = $rewardRuleInfo[$key]['name'];
                        $rewardUsers[$key]['data'] = $value;
                    }
                }
                if (!empty($rewardUserCount)) {
                    foreach ($rewardUserCount as $key => $value) {
                        $rewardUserCounts[$key]['level'] = $key;
                        $rewardUserCounts[$key]['level_name'] = $rewardRuleInfo[$key]['name'];
                        $rewardUserCounts[$key]['data'] = $value;
                        $rewardUserCounts[$key]['list'] = $rewardUserList[$key] ?? [];

                    }
                }
                if (!empty($thisRewardPrice)) {
                    foreach ($thisRewardPrice as $key => $value) {
                        $thisRewardPrices[$key]['level'] = $key;
                        $thisRewardPrices[$key]['level_name'] = $rewardRuleInfo[$key]['name'];
                        $thisRewardPrices[$key]['data'] = $value;
                    }
                }
                if (!empty($onceRewardPrice)) {
                    foreach ($onceRewardPrice as $key => $value) {
                        $onceRewardPrices[$key]['level'] = $key;
                        $onceRewardPrices[$key]['level_name'] = $rewardRuleInfo[$key]['name'];
                        $onceRewardPrices[$key]['data'] = $value;
                    }
                }
                $rewardUserLists = [];
                if(!empty($rewardUserList ?? [])){
                    foreach ($rewardUserList as $key => $value) {
                        $rewardUserLists[$key]['level'] = $key;
                        $rewardUserLists[$key]['list'] = $value;
                    }
                    $rewardUserLists = array_values($rewardUserLists);
                }
                return ['msg' => '仅查询可领取奖励人群', 'allRewardUser' => $rewardUserLists ?? [],'allRewardUserCount' => array_values($rewardUserCounts), 'rewardScalePrice' => array_values($thisRewardPrices), 'onceRewardPrice' => array_values($onceRewardPrices)];
            }

            $divideData = [];
            $balanceDetail = [];

            foreach ($rewardUser as $key => $value) {
                $sql[$key] = 'UPDATE sp_user SET shareholder_balance = CASE uid';
                $userPrice = 0;
                foreach ($value as $cKey => $cValue) {
//                    //判断是否会超过允许奖励的上限,如果超过了只能拿余下的部分,不能拿全额
//                    if ((string)$rewardUserInfo[$cValue]['max_allow_reward'] - $rewardUserInfo[$cValue]['user_all_reward'] >= $onceRewardPrice[$key]) {
//                        $userPrice = $onceRewardPrice[$key];
//                    } else {
////                        $userPrice = $onceRewardPrice[$key] - ($rewardUserInfo[$cValue]['max_allow_reward'] - $rewardUserInfo[$cValue]['user_all_reward']);
//                        $userPrice = $rewardUserInfo[$cValue]['max_allow_reward'] - $rewardUserInfo[$cValue]['user_all_reward'];
//                    }
                    $userPrice = $onceRewardPrice[$key];
                    if ($userPrice <= 0) {
                        continue;
                    }

                    if (($cKey + 1) >= count($value)) {
                        $sql[$key] .= " WHEN '" . $cValue . "' THEN " . 'shareholder_balance + ' . $userPrice . ' ELSE shareholder_balance END';
                    } else {
                        $sql[$key] .= " WHEN '" . $cValue . "' THEN " . 'shareholder_balance + ' . $userPrice;
                    }

                    $divideData[$key][$cKey]['order_sn'] = $planSn;
                    $divideData[$key][$cKey]['order_uid'] = $cValue;
                    $divideData[$key][$cKey]['type'] = 6;
                    $divideData[$key][$cKey]['vdc'] = $rewardRuleInfo[$key]['scale'] ?? 0;
                    $divideData[$key][$cKey]['level'] = $rewardRuleInfo[$key]['level'] ?? 0;
                    $divideData[$key][$cKey]['link_uid'] = $cValue;
                    $divideData[$key][$cKey]['divide_price'] = $userPrice;
                    $divideData[$key][$cKey]['real_divide_price'] = $userPrice;
                    $divideData[$key][$cKey]['arrival_status'] = 1;
                    $divideData[$key][$cKey]['arrival_time'] = time();
                    $divideData[$key][$cKey]['total_price'] = $rewardPlanInfo;
                    $divideData[$key][$cKey]['price'] = $thisRewardPrice[$key];

                    $balanceDetail[$key][$cKey]['uid'] = $cValue;
                    $balanceDetail[$key][$cKey]['order_sn'] = $planSn;
                    $balanceDetail[$key][$cKey]['type'] = 1;
                    $balanceDetail[$key][$cKey]['price'] = $userPrice;
                    $balanceDetail[$key][$cKey]['change_type'] = 18;
                }

            }

            //插入数据库
            foreach ($sql as $key => $value) {
                if ($value != 'UPDATE sp_user SET shareholder_balance = CASE uid') {
                    $sqlRes[$key] = Db::query($value);
                    (new Divide())->saveAll($divideData[$key]);
                    (new BalanceDetail())->saveAll($balanceDetail[$key]);
                }
            }

            ShareholderRewardPlan::update(['grant_res' => 1, 'grant_time' => time()], ['plan_sn' => $planSn, 'status' => 1, 'grant_res' => [3, 4]]);


            return ['allRewardUser' => $rewardUser ?? [], 'allRewardUserCount' => $rewardUserCount ?? [], 'rewardScalePrice' => $thisRewardPrice ?? [], 'onceRewardPrice' => $onceRewardPrice ?? [], 'sql' => $sql, 'divideData' => $divideData ?? [], 'balanceDetail' => $balanceDetail ?? []];
        });
        //记录日志
        $log['param'] = $data ?? [];
        $log['returnData'] = $res;
        $this->log($log);

        return $res;

    }

    /**
     * @title  记录日志
     * @param array $data 数据信息
     * @param string $level 日志等级
     * @return mixed
     */
    public function log(array $data, string $level = 'error')
    {
        return (new Log())->setChannel('propaganda')->record($data, $level);
    }
}