<?php

namespace app\lib\models;

use app\BaseModel;
use app\lib\exceptions\CrowdFundingActivityException;
use app\lib\exceptions\ParamException;
use app\lib\exceptions\ServiceException;

class CrowdfundingDelayRewardOrder extends BaseModel
{
    /**
     * @title 批量新增
     * @param array $data
     * @throws \Exception
     */
    public function DBNew(array $data)
    {
        //必须为二维数组
        $list = $data['list'];
        $existOrder = self::where(['order_sn' => array_unique(array_column($list, 'order_sn')), 'status' => 1])->select()->toArray();
        foreach ($list as $key => $value) {
            foreach ($existOrder as $eKey => $eValue) {
                if ($value['order_sn'] == $eValue['order_sn'] && $value['goods_sn'] == $eValue['goods_sn'] && $value['sku_sn'] == $eValue['sku_sn']) {
                    unset($list[$key]);
                    continue;
                }
            }
        }
        if (empty($list)) {
            return false;
        }
        $res = self::saveAll($list);
        return $res;
    }

    /**
     * @title 统计个人或区的超前提前购情况
     * @param array $data
     * @return mixed
     */
    public function advanceSummary(array $data)
    {
        //汇总类型 1为查询个人 2为查询某个区
        $type = $data['type'] ?? 1;
        $uid = $data['uid'] ?? null;
        $activityCode = $data['activity_code'] ?? null;
        if ($type == 1 && empty($uid)) {
            throw new ParamException();
        }
        if ($type == 2 && empty($activityCode)) {
            throw new ParamException();
        }
        $finally = [];
        switch ($type) {
            case 1:
                //汇总查询用户超前提前购详情
                $userAdvanceDelayOrderGroupCount = self::where(['uid' => $uid, 'arrival_status' => 3, 'status' => 1])->field('uid,crowd_code,crowd_period_number,count(id) as number')->group('crowd_code,crowd_period_number')->select()->toArray();
                if (empty($userAdvanceDelayOrderGroupCount)) {
                    return ['thisActivitySummary' => [], 'thisActivityOrderSummary' => [], 'totalActivitySummaryNumber' => 0];
                }

                if (!empty($userAdvanceDelayOrderGroupCount)) {
                    foreach ($userAdvanceDelayOrderGroupCount as $key => $value) {
                        if (!isset($userAdvanceCount[$value['crowd_code']])) {
                            $userAdvanceCount[$value['crowd_code']] = 0;
                        }
                        if (!isset($userAdvanceOrderCount[$value['crowd_code']])) {
                            $userAdvanceOrderCount[$value['crowd_code']] = 0;
                        }
                        //每个区总超前提前购次数, 同一期多次参与算一次
                        $userAdvanceCount[$value['crowd_code']] += 1;
                        $userAdvanceOrderCount[$value['crowd_code']] += $value['number'];
                    }
                }
                //该用户全部区总超前提前购次数
                $userAdvanceCountTotal = array_sum($userAdvanceCount ?? []);

                $finally = ['thisActivitySummary' => $userAdvanceCount ?? [], 'thisActivityOrderSummary' => $userAdvanceOrderCount ?? [], 'totalActivitySummaryNumber' => $userAdvanceCountTotal ?? 0];
                break;
            case 2:
                //汇总查询所有期的超前提前购期数
                $advanceDelayOrderGroupCount = self::where(['activity_code' => $activityCode, 'arrival_status' => 3, 'status' => 1])->field('crowd_code,crowd_round_number,crowd_period_number,count(id) as number')->group('crowd_code,crowd_round_number,crowd_period_number')->select()->toArray();

                if (empty($advanceDelayOrderGroupCount)) {
                    return  ['thisActivitySummary' => [], 'thisActivityDetail' => []];
                }
                foreach ($advanceDelayOrderGroupCount as $key => $value) {
                    $crowdKey = $value['crowd_code'] . '-' .$value['crowd_round_number'] . '-' . $value['crowd_period_number'];
                    if (!isset($advanceCount[$value['crowd_code']])) {
                        $advanceCount[$value['crowd_code']] = 0;
                    }
                    //每个区总超前提前购次数, 同一轮一期多次参与算一次
                    $advanceCount[$value['crowd_code']] += 1;
                    $advancePeriod[$crowdKey]['round_number'] = $value['crowd_round_number'];
                    $advancePeriod[$crowdKey]['period_number'] = $value['crowd_period_number'];

                }
                $finally = ['thisActivitySummary' => $advanceCount ?? [], 'thisActivityDetail' => array_values($advancePeriod ?? [])];
                break;
            default:
                throw new ServiceException(['msg' => '暂不支持的类型']);
                break;
        }
        return $finally;
    }
}