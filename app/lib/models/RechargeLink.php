<?php
// +----------------------------------------------------------------------
// |[ 文档说明: ]
// +----------------------------------------------------------------------
// | Copyright (c) 2018~2024 http://www.mlhcmk.com All rights reserved.
// +----------------------------------------------------------------------
 

namespace app\lib\models;


use app\BaseModel;
use app\lib\exceptions\ServiceException;
use app\lib\exceptions\UserException;
use think\facade\Db;

class RechargeLink extends BaseModel
{
    /**
     * @title  查询用户的提现额度
     * @param array $data
     * @throws \Exception
     * @return array
     */
    public function userSubordinateRechargeRate(array $data)
    {
        $cacheKey = 'userRechargeSummary-' . $data['uid'] . ($data['time_type'] ?? 1);
        if (empty($data['clearCache'] ?? null)) {
            if (!empty(cache($cacheKey))) {
                return cache($cacheKey);
            }
        }
        $userInfo = User::where(['uid' => $data['uid']])->findOrEmpty()->toArray();
        if (empty($userInfo)) {
            throw new UserException(['msg' => '用户异常']);
        }
        if ($userInfo['team_vip_level'] <= 0 || $userInfo['vip_level'] <= 0) {
            $res['withdrawLimit'] = false;
            $res['rechargeAmount'] = 0;
            $res['aimRechargeAmount'] = 0;
            $res['totalCanWithdrawAmount'] = 0;
            return $res;
        }
        $res = [];
        $teamVdcInfo = TeamMemberVdc::where(['level' => $userInfo['team_vip_level'], 'status' => 1])->findOrEmpty()->toArray();
        if (empty($teamVdcInfo)) {
            throw new ServiceException(['msg' => '会员信息有误']);
        }
        $extraWithdrawAmount = 0;
        //查询是否有额外提现额度(有效的)
        $extraWithdrawAmount = UserExtraWithdraw::where(function ($query) {
            $map1[] = ['valid_type', '=', 1];
            $map2[] = ['valid_type', '=', 2];
            $map2[] = ['valid_start_time', '<=', time()];
            $map2[] = ['valid_end_time', '>', time()];
            $query->whereOr([$map1, $map2]);
        })->where(['status' => 1,'uid'=>$data['uid']])->sum('price');

        //保底提现额度
        $FloorsWithdrawAmount = $teamVdcInfo['floors_withdraw_amount'] ?? 0;
        if (doubleval($teamVdcInfo['withdraw_condition_amount']) <= 0) {
            $res['withdrawLimit'] = false;
            $res['rechargeAmount'] = 0;
            $res['aimRechargeAmount'] = 0;
            $res['totalCanWithdrawAmount'] = 0;
            return $res;
        }

        $historyLastAmount = 0;
        //查询过往累计剩余额度, 从203-1-28开始计算过往累计剩余额度
        $hcMap[] = ['uid', '=', $data['uid']];
        $hcMap[] = ['status', '=', 1];
        $hcMap[] = ['start_time_period', '>=', '1674835200'];
        $historyLastAmount = RechargeLinkCumulative::where($hcMap)->sum('price');
//        $historyLastList = RechargeLinkCumulative::where($hcMap)->field('price,oper_price,total_price,start_time_period')->select()->toArray();
//        if (!empty($historyLastList)) {
//            $historyLastAmount = array_sum(array_column($historyLastList, 'price'));
//            $historyRechargeAmount = array_sum(array_column($historyLastList, 'total_price'));
//            $historyOperAmount = array_sum(array_column($historyLastList, 'oper_price'));
//
//            //过往累计剩余额度需要加上一部分过往累计(已经失效的)额外额度, 这样才可以配平公式,加上的不可以是全部额度而是实际使用的额度
//            $historyExtraWithdrawList = UserExtraWithdraw::where(function ($query) {
//                $map1[] = ['valid_type', '=', 1];
//                $map1[] = ['status', '=', -1];
//                $map2[] = ['status', 'in', [1, -1]];
//                $map2[] = ['valid_type', '=', 2];
//                $map2[] = ['valid_end_time', '<=', time()];
//                $query->whereOr([$map1, $map2]);
//            })->where(['uid' => $data['uid']])->field('type,price,create_time')->select()->toArray();
//
//            if (!empty($historyExtraWithdrawList)) {
//                foreach ($historyExtraWithdrawList as $key => $value) {
//                    $date = 0;
//                    $date = strtotime(date('Y-m-d', strtotime($value['create_time'])) . ' 00:00:00');
//                    if($value['type'] == 1){
//                        $date = 'all';
//                    }
//                    if (!isset($historyExtraWithdrawAmountGroup[$date])) {
//                        $historyExtraWithdrawAmountGroup[$date] = 0;
//                    }
//                    $historyExtraWithdrawAmountGroup[$date] += $value['price'];
//                }
//                $historyExtraWithdrawAmount = 0;
//                foreach ($historyLastList as $key => $value) {
//                    if (!empty($historyExtraWithdrawAmountGroup[$value['start_time_period']] ?? null)) {
//                        //如果操作额度超过了当日充值业绩表示有额外或累计额度,没有超过则不需要加回去
//                        if ((string)$value['oper_price'] > (string)$value['total_price']) {
//                            if ((string)$historyExtraWithdrawAmountGroup[$value['start_time_period']] >= (string)($value['oper_price'] - $value['total_price'])) {
//                                $historyExtraWithdrawAmount += ($value['oper_price'] - $value['total_price']);
//                            } else {
//                                $historyExtraWithdrawAmount += $historyExtraWithdrawAmountGroup[$value['start_time_period']];
//                            }
//                        }
//                    }
//                }
//                $historyLastAmount += priceFormat($historyExtraWithdrawAmount);
//            }
//        }

        //time_type 时间类型 1为查询当日 2为查询当月
        switch ($data['time_type'] ?? 1) {
            case 1:
                $startTime = strtotime(date('Y-m-d', time()) . ' 00:00:00');
                $endTime = strtotime(date('Y-m-d', time()) . ' 23:59:59');
                break;
            case 2:
                $startTime =strtotime(date('Y-m-01 00:00:00'));
                $endTime = strtotime(date('Y-m-t 23:59:59'));
                break;
            default:
                throw new ServiceException(['msg' => '无有效时间类型']);
        }

        $uMap[] = ['uid', '=', $data['uid']];
        $uMap[] = ['status', '=', 1];
        switch ($data['time_type'] ?? 1) {
            case 1:
                $uMap[] = ['start_time_period', '=', $startTime];
                $uMap[] = ['end_time_period', '=', $endTime];
                break;
            case 2:
                $uMap[] = ['start_time_period', '>=', $startTime];
                $uMap[] = ['end_time_period', '<=', $endTime];
                break;
            default:
                throw new ServiceException(['msg' => '无有效时间类型']);
        }
        $userSubordinateRechargeTotal = 0;
        $userSubordinateRechargeTotal = self::where($uMap)->sum('price');
        //查找历史为当前用户上级的直推下级用户充值汇总记录,找出最多的一位, 然后剔除这个人的充值业绩(仅一位, 如果出现同金额不同人也只剔除一份)
        //如果是按天查询则剔除当天充值业绩第一位, 如果是按月则需要统计当个月每天第一位然后累加后剔除
        if (!empty($userSubordinateRechargeTotal)) {
            $gMap[] = ['link_uid', '=', $data['uid']];
            $gMap[] = ['status', '=', 1];
            switch ($data['time_type'] ?? 1) {
                case 1:
                    $gMap[] = ['start_time_period', '=', $startTime];
                    $gMap[] = ['end_time_period', '=', $endTime];
                    break;
                case 2:
                    $gMap[] = ['start_time_period', '>=', $startTime];
                    $gMap[] = ['end_time_period', '<=', $endTime];
                    break;
                default:
                    throw new ServiceException(['msg' => '无有效时间类型']);
            }
            switch ($data['time_type'] ?? 1) {
                case 1:
                    $userSubordinateRechargeGroup = self::where($gMap)->field('uid,sum(price) as total_price')->group('uid')->select()->toArray();
                    if (!empty($userSubordinateRechargeGroup) && count($userSubordinateRechargeGroup) > 1) {
                        foreach ($userSubordinateRechargeGroup as $key => $value) {
                            $userSubordinateRechargeGroupSort[$value['uid']] = $value['total_price'];
                        }
                        arsort($userSubordinateRechargeGroupSort);
                        $maxRechargeTotal = current($userSubordinateRechargeGroupSort);
                    }
                    break;
                case 2:
                    $userSubordinateRechargeGroup = self::where($gMap)->select()->toArray();
                    if (!empty($userSubordinateRechargeGroup)) {
                        foreach ($userSubordinateRechargeGroup as $key => $value) {
                            $timePeriod = $value['start_time_period'] . '-' . $value['end_time_period'];
                            if (empty($userDateGroup[$timePeriod])) {
                                $userDateGroup[$timePeriod] = [];
                            }
                            if (!isset($userDateGroup[$timePeriod][$value['uid']])) {
                                $userDateGroup[$timePeriod][$value['uid']] = 0;
                            }
                            $userDateGroup[$timePeriod][$value['uid']] += $value['price'];

                        }
                        $maxRechargeTotal = 0;
                        foreach ($userDateGroup as $key => $value) {
                            if (count($value) > 1) {
                                arsort($value);
                                $maxRechargeTotal += current($value);
                            }
                        }
                    }
                    break;
                default:
                    throw new ServiceException(['msg' => '无有效时间类型']);
                    break;
            }

            if (!empty($maxRechargeTotal)) {
                $userSubordinateRechargeTotal -= $maxRechargeTotal;
            }
            if ($userSubordinateRechargeTotal < 0) {
                $userSubordinateRechargeTotal = 0;
            }
        }

        //查询当前等级的可提现金额比例
        $userWithdrawScale = priceFormat($teamVdcInfo['withdraw_amount'] / $teamVdcInfo['withdraw_condition_amount']);
        $canWithdrawAmount = priceFormat($userSubordinateRechargeTotal * $userWithdrawScale);
        $finallyWithdrawAmount = priceFormat($FloorsWithdrawAmount + $historyLastAmount + $canWithdrawAmount + $extraWithdrawAmount);

        switch ($data['time_type'] ?? 1) {
            case 1:
                $res['withdrawLimit'] = true;
                $res['rechargeAmount'] = priceFormat($userSubordinateRechargeTotal);
                $res['totalCanWithdrawAmount'] = priceFormat($finallyWithdrawAmount);
                $res['aimRechargeAmount'] = priceFormat($teamVdcInfo['withdraw_condition_amount']);
                cache($cacheKey, $res, 300);
                break;
            case 2:
                $res['withdrawLimit'] = true;
                $res['rechargeAmount'] = priceFormat($userSubordinateRechargeTotal);
                $res['totalCanWithdrawAmount'] = priceFormat($finallyWithdrawAmount);
                $res['aimRechargeAmount'] = priceFormat($teamVdcInfo['withdraw_condition_amount'] * intval(date("t", strtotime(date('Y-m-d')))));
                cache($cacheKey, $res, (3600 * 2));
                break;
            default:
                throw new ServiceException(['msg' => '无有效时间类型']);
                break;
        }

        return $res;

    }

    /**
     * @title  查询用户规定时间内提现冻结成功及转入美丽金的金额
     * @param array $data
     * @return bool|string
     */
    public function checkUserWithdrawAndTransfer(array $data)
    {
        //time_type 时间类型 1为查询当日 2为查询当月
        switch ($data['time_type'] ?? 1) {
            case 1:
                $startTime = strtotime(date('Y-m-d', time()) . ' 00:00:00');
                $endTime = strtotime(date('Y-m-d', time()) . ' 23:59:59');
                break;
            case 2:
                $startTime = strtotime(date('Y-m-01 00:00:00'));
                $endTime = strtotime(date('Y-m-t 23:59:59'));
                break;
            default:
                throw new ServiceException(['msg' => '无有效时间类型']);
        }

        $gMap[] = ['uid', '=', $data['uid']];
        $gMap[] = ['status', '=', 1];
        switch ($data['time_type'] ?? 1) {
            case 1:
                $gMap[] = ['create_time', '>=', $startTime];
                $gMap[] = ['create_time', '<=', $endTime];
                break;
            case 2:
                $gMap[] = ['create_time', '>=', $startTime];
                $gMap[] = ['create_time', '<=', $endTime];
                break;
            default:
                throw new ServiceException(['msg' => '无有效时间类型']);
        }

        //查询用户金额转出多少美丽金
        $cMap = $gMap;
        $cMap[] = ['type', '=', 1];
//        $cMap[] = ['transfer_type', '<>', 1];
        $cMap[] = ['', 'exp', Db::raw('transfer_type is not null and transfer_type <> 1')];
        $userTransferAmount = CrowdfundingBalanceDetail::where($cMap)->sum('price');

        //查询用户提现冻结及成功的金额
        $wMap = $gMap;
        $wMap[] = ['check_status', 'in', [1, 3]];
        $wMap[] = ['withdraw_type', '=', 5];
        $userWithdraw = Withdraw::where($wMap)->sum('total_price');

        $totalAmount = priceFormat($userTransferAmount + $userWithdraw);

        return $totalAmount;
    }

    /**
     * @title  记录当天用户剩余可操作的提现额度, 记入冗余表, <此方法在当天完成23:59分执行>
     * @param array $data
     * @return bool
     * @throws \Exception
     */
    public function summaryAllUserTodayLastRechargeRate(array $data)
    {
        //先查询每个人今天的充值业绩
        $startTime = strtotime(date('Y-m-d', time()) . ' 00:00:00');
        $endTime = strtotime(date('Y-m-d', time()) . ' 23:59:59');
        $uMap[] = ['status', '=', 1];
        switch ($data['time_type'] ?? 1) {
            case 1:
                $uMap[] = ['start_time_period', '=', $startTime];
                $uMap[] = ['end_time_period', '=', $endTime];
                break;
            case 2:
                $uMap[] = ['start_time_period', '>=', $startTime];
                $uMap[] = ['end_time_period', '<=', $endTime];
                break;
            default:
                throw new ServiceException(['msg' => '无有效时间类型']);
        }
        $userSubordinateRechargeTotal = [];
        $userSubordinateRechargeTotal = self::where($uMap)->field('uid,link_uid,sum(price) as price')->group('uid')->select()->toArray();
        if (!empty($userSubordinateRechargeTotal)) {
            //统计每个人下级中最多的一个人后剔除业绩
            $haveLink = [];
            foreach ($userSubordinateRechargeTotal as $key => $value) {
                if (!empty($value['link_uid'] ?? null)) {
                    $haveLink[$value['link_uid']][] = $value;
                }
            }
            $maxRecharge = [];
            if (!empty($haveLink ?? [])) {
                foreach ($haveLink as $key => $value) {
                    if (!empty($value) && count($value) > 1) {
                        arsort($value);
                        $maxRecharge[$key] = current($value);
                    }
                }
            }

            if (!empty($maxRecharge)) {
                foreach ($userSubordinateRechargeTotal as $key => $value) {
                    if (!empty($maxRecharge[$value['uid']] ?? null)) {
                        $userSubordinateRechargeTotal[$key]['price'] -= ($maxRecharge[$value['uid']]['price'] ?? 0);
                    }
                }
            }
        }

        //查询没有充值业绩的其他团队会员, 为了给他们累计保底额度
        $ncMap[] = ['status', '=', 1];
        $ncMap[] = ['level', '>', 0];
        $notRechargeUser = TeamMember::where($ncMap)->column('level', 'uid');
        if (count($notRechargeUser) > count($userSubordinateRechargeTotal)) {
            $notRechargeNumber = 0;
            foreach ($notRechargeUser as $key => $value) {
                if (!empty($userSubordinateRechargeTotal ?? [])) {
                    if (!in_array($key, array_column($userSubordinateRechargeTotal, 'uid'))) {
                        $notRechargeUserList[$notRechargeNumber]['uid'] = $key;
                        $notRechargeUserList[$notRechargeNumber]['price'] = 0;
                        $notRechargeNumber += 1;
                    }
                } else {
                    $notRechargeUserList[$notRechargeNumber]['uid'] = $key;
                    $notRechargeUserList[$notRechargeNumber]['price'] = 0;
                    $notRechargeNumber += 1;
                }
            }
        }

        if (!empty($notRechargeUserList ?? [])) {
            $userSubordinateRechargeTotal = array_merge_recursive($userSubordinateRechargeTotal, $notRechargeUserList);
//            $userSubordinateRechargeTotal = $notRechargeUserList;
        }
        if (empty($userSubordinateRechargeTotal)) {
            return false;
        }
        //根据比例计算每个人的总共可提现金额
        $teamVdc = TeamMemberVdc::where(['status' => 1])->select()->toArray();
        foreach ($teamVdc as $key => $value) {
            $teamVdcInfo[$value['level']] = $value;
        }
//        $allUserUid = array_unique(array_column($userSubordinateRechargeTotal, 'uid'));
//        $allUserTeamLevel = User::where(['uid' => $allUserUid, 'status' => 1])->column('team_vip_level', 'uid');
        $allUserTeamLevel = $notRechargeUser;
        foreach ($userSubordinateRechargeTotal as $key => $value) {
            if (!empty($allUserTeamLevel[$value['uid']] ?? null)) {
                $userSubordinateRechargeTotal[$key]['vdc'] = $teamVdcInfo[$allUserTeamLevel[$value['uid']]];
            }
        }

        //查询是否有额外提现额度
        $extraWithdrawAmount = UserExtraWithdraw::where(function ($query) {
            $map1[] = ['valid_type', '=', 1];
            $map2[] = ['valid_type', '=', 2];
            $map2[] = ['valid_start_time', '<=', time()];
            $map2[] = ['valid_end_time', '>', time()];
            $query->whereOr([$map1, $map2]);
        })->where(['status' => 1])->field('uid,sum(price) as price')->group('uid')->select()->toArray();

        if (!empty($extraWithdrawAmount)) {
            foreach ($extraWithdrawAmount as $key => $value) {
                $extraWithdrawAmountInfo[$value['uid']] = $value['price'];
            }
        }
        foreach ($userSubordinateRechargeTotal as $key => $value) {
            $userWithdrawScale = 0;
            $canWithdrawAmount = 0;
            if (!empty($value['vdc'] ?? null)) {
                $userWithdrawScale = priceFormat($value['vdc']['withdraw_amount'] / $value['vdc']['withdraw_condition_amount']);
                $canWithdrawAmount = priceFormat($value['price'] * $userWithdrawScale);
                //加上保底额度和额外提现额度
                $finallyWithdrawAmount[$value['uid']] = priceFormat(($value['vdc']['floors_withdraw_amount'] ?? 0) + $canWithdrawAmount + ($extraWithdrawAmountInfo[$value['uid']] ?? 0));
                $userAmountDetail[$value['uid']]['totalWithdrawAmount'] = $finallyWithdrawAmount[$value['uid']] ?? 0;
                $userAmountDetail[$value['uid']]['rechargeWithdrawAmount'] = $canWithdrawAmount ?? 0;
                $userAmountDetail[$value['uid']]['floorsWithdrawAmount'] = ($value['vdc']['floors_withdraw_amount'] ?? 0);
                $userAmountDetail[$value['uid']]['extraWithdrawAmount'] = ($extraWithdrawAmountInfo[$value['uid']] ?? 0);
            }
        }
        if (empty($finallyWithdrawAmount ?? [])) {
            return false;
        }
        //查看用户已经提现或者转出的余额, 汇总后需要扣除这部分金额后得出用户当天累计剩余的可操作金额
        $gMap[] = ['status', '=', 1];
        $gMap[] = ['create_time', '>=', $startTime];
        $gMap[] = ['create_time', '<=', $endTime];

        //查询用户金额转出多少美丽金
        $cMap = $gMap;
        $cMap[] = ['type', '=', 1];
//        $cMap[] = ['transfer_type', '<>', 1];
        $cMap[] = ['', 'exp', Db::raw('transfer_type is not null and transfer_type <> 1 and uid in (select uid FROM sp_user where team_vip_level > 0)')];
        $userTransferAmount = CrowdfundingBalanceDetail::where($cMap)->field('uid, sum(price) as price')->group('uid')->select()->toArray();
        if (!empty($userTransferAmount)) {
            foreach ($userTransferAmount as $key => $value) {
                $userTransferAmountInfo[$value['uid']] = $value['price'];
            }
        }

        //查询用户提现冻结及成功的金额
        $wMap = $gMap;
        $wMap[] = ['check_status', 'in', [1, 3]];
        $wMap[] = ['withdraw_type', '=', 5];
        $userWithdraw = Withdraw::where($wMap)->field('uid, sum(price) as price')->group('uid')->select()->toArray();

        if (!empty($userWithdraw)) {
            foreach ($userWithdraw as $key => $value) {
                $userWithdrawInfo[$value['uid']] = $value['price'];
            }
        }
        $needFindHistoryAmountUser = [];
        $deleteExtraAmount = [];
        //查看用户金额操作的额度是否存在已经失效或者已经被删除的额外操作金额, 如果存在需要补回去到额外金额里面去
        foreach ($userAmountDetail as $key => $value) {
            if ($value['rechargeWithdrawAmount'] + $value['floorsWithdrawAmount'] + $value['extraWithdrawAmount'] < (($userTransferAmountInfo[$key] ?? 0) + ($userWithdrawInfo[$key] ?? 0))) {
                //判断用户操额度是否超过了今天可操作的额度, 再查询是否存在历史累计额度, 如果还超过了历史累计额度, 则证明存在部分已经过期或被删除的额外额度, 累加回这部分被删除的额外提现业绩才可以配平公式
                $needFindHistoryAmountUser[] = $key;
            }
        }
        if (!empty($needFindHistoryAmountUser ?? [])) {
            $uhMap[] = ['uid', 'in', $needFindHistoryAmountUser];
            $uhMap[] = ['status', '=', 1];
            $uhMap[] = ['start_time_period', '<', $startTime];
            $userHistoryAmount = RechargeLinkCumulative::where($uhMap)->field('uid,sum(price) as price')->group('uid')->select()->toArray();
            if (!empty($userHistoryAmount)) {
                foreach ($userHistoryAmount as $key => $value) {
                    $userHistoryAmountInfo[$value['uid']] = $value['price'];
                }
                foreach ($userAmountDetail as $key => $value) {
                    if ($value['rechargeWithdrawAmount'] + $value['floorsWithdrawAmount'] + $value['extraWithdrawAmount'] + ($userHistoryAmountInfo[$key] ?? 0) < (($userTransferAmountInfo[$key] ?? 0) + ($userWithdrawInfo[$key] ?? 0))) {
                        $deleteExtraAmount[$key] = priceFormat((($userTransferAmountInfo[$key] ?? 0) + ($userWithdrawInfo[$key] ?? 0)) - ($value['rechargeWithdrawAmount'] + $value['floorsWithdrawAmount'] + $value['extraWithdrawAmount'] + ($userHistoryAmountInfo[$key] ?? 0)));
                    }
                }
                if (!empty($deleteExtraAmount ?? [])) {
                    foreach ($finallyWithdrawAmount as $key => $value) {
                        if (doubleval($deleteExtraAmount[$key] ?? 0) > 0) {
                            $finallyWithdrawAmount[$key] += ($deleteExtraAmount[$key] ?? 0);
//                            $userAmountDetail[$key]['extraWithdrawAmount'] += ($deleteExtraAmount[$key] ?? 0);
                            $userAmountDetail[$key]['totalWithdrawAmount'] += ($deleteExtraAmount[$key] ?? 0);
                            $userAmountDetail[$key]['invalidExtraWithdrawAmount'] = ($deleteExtraAmount[$key] ?? 0);
                        }
                    }
                }
            }
        }

        foreach ($finallyWithdrawAmount as $key => $value) {
            $finallyWithdrawAmount[$key] -= (($userTransferAmountInfo[$key] ?? 0) + ($userWithdrawInfo[$key] ?? 0));
        }

        if (empty($finallyWithdrawAmount)) {
            return false;
        }
        $newRecord = [];
        $number = 0;
        //不管正负数都要记录, 因为有可能会因为累计额度, 导致今天额度是超过的今天的总可提现业绩
        foreach ($finallyWithdrawAmount as $key => $value) {
            $newRecord[$number]['uid'] = $key;
            $newRecord[$number]['price'] = $value;
            $newRecord[$number]['start_time_period'] = $startTime;
            $newRecord[$number]['end_time_period'] = $endTime;
            if (!empty($userAmountDetail[$key] ?? [])) {
                $newRecord[$number]['total_price'] = $userAmountDetail[$key]['totalWithdrawAmount'] ?? 0;
                $newRecord[$number]['recharge_price'] = $userAmountDetail[$key]['rechargeWithdrawAmount'] ?? 0;
                $newRecord[$number]['extra_price'] = $userAmountDetail[$key]['extraWithdrawAmount'] ?? 0;
                $newRecord[$number]['oper_price'] = (($userTransferAmountInfo[$key] ?? 0) + ($userWithdrawInfo[$key] ?? 0));
                $newRecord[$number]['floors_price'] = $userAmountDetail[$key]['floorsWithdrawAmount'] ?? 0;
                $newRecord[$number]['invalid_extra_price'] = $userAmountDetail[$key]['invalidExtraWithdrawAmount'] ?? 0;
            }
            $number++;
        }

        $res = false;
        if (!empty($newRecord)) {
            $res = (new RechargeLinkCumulative())->saveAll($newRecord);
        }
        return judge($res);
    }
}