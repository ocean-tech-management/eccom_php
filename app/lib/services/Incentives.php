<?php
// +----------------------------------------------------------------------
// |[ 文档说明: 会员激励制度模块Service]
// +----------------------------------------------------------------------
// | Copyright (c) 2018~2024 http://www.mlhcmk.com All rights reserved.
// +----------------------------------------------------------------------


namespace app\lib\services;

use app\lib\models\BalanceDetail;
use app\lib\models\Divide;
use app\lib\models\Divide as DivideModel;
use app\lib\models\IncentivesRecord;
use app\lib\models\MemberIncentives;
use app\lib\models\User;
use think\facade\Db;

class Incentives
{
    /**
     * @title  结算教育基金
     * @param array $sear
     * @return bool
     * @throws \Exception
     */
    public function rewardIncentives(array $sear = [])
    {
        if (!empty($sear['level'])) {
            $searLevel = intval($sear['level']);
        }
        $dMap[] = ['divide_type', '=', 2];
        $dMap[] = ['arrival_status', '=', 4];
        $dMap[] = ['status', 'in', [1, 2]];
        if (!empty($searLevel)) {
            $dMap[] = ['level', '=', $searLevel];
        }
        $allWaitDivide = Divide::where($dMap)->order('create_time asc')->select()->toArray();

        if (empty($allWaitDivide)) {
            return $this->recordError(['msg' => '暂无已确认且冻结中的教育基金'], [], 'debug');
        }

        $iMap[] = ['status', '=', 1];
        if (!empty($searLevel)) {
            $iMap[] = ['level', '=', $searLevel];
        }
        $allLevelIncentives = MemberIncentives::where($iMap)->select()->toArray();
        if (empty($allLevelIncentives)) {
            return $this->recordError(['msg' => '暂无可实行的激励机制!'], [], 'error');
        }
        $levelDivide = [];
        $levelIncentives = [];
        foreach ($allLevelIncentives as $key => $value) {
            $levelIncentives[$value['level']] = $value;
        }

        foreach ($allWaitDivide as $key => $value) {
            $levelDivide[$value['level']][] = $value;
        }

        foreach ($levelIncentives as $key => $value) {
            $cycle = $value['billing_cycle'];
            //根据不同周期单位获取不同的周期起始时间
            switch ($value['billing_cycle_unit']) {
                case 1:
                    $startTime = date("Y-m-d", strtotime("-$cycle day")) . ' 00:00:00';
                    $endTime = date("Y-m-d", strtotime("-$cycle day")) . ' 23:59:59';
                    break;
                case 2:
                    $week = $this->lastNWeek(time(), $cycle);
                    $startTime = strtotime($week[0] . ' 00:00:00');
                    $endTime = strtotime($week[1] . ' 23:59:59');
                    break;
                case 3:
                    $month = $this->lastMonth(time(), $cycle);
                    $startTime = strtotime($month[0] . ' 00:00:00');
                    $endTime = strtotime($month[1] . ' 23:59:59');
                    break;
                default:
                    $this->recordError(['msg' => '无效的结算周期单位!'], [], 'error');
            }
            $levelIncentives[$key]['cycle_start_time'] = $startTime;
            $levelIncentives[$key]['cycle_end_time'] = $endTime;
        }

        $userAllDivide = [];
        foreach ($levelDivide as $key => $value) {
            foreach ($value as $uKey => $uValue) {
                if ($key == $uValue['level'] && !empty($levelIncentives[$key]) && !empty($uValue['total_price'])) {
                    $startTime = $levelIncentives[$key]['cycle_start_time'];
                    $endTime = $levelIncentives[$key]['cycle_end_time'];
                    //要在该等级开启结算,在结算周期内且今日是结算日的情况下才能累计入总的业绩和分润金额
                    if ($levelIncentives[$key]['billing_cycle_switch'] == 1 && (strtotime($uValue['create_time']) >= $startTime && strtotime($uValue['create_time']) <= $endTime) && intval(date('d', time())) == intval($levelIncentives[$key]['settlement_date'])) {
                        if (!isset($userAllDivide[$key][$uValue['link_uid']]['arrival_price'])) {
                            $userAllDivide[$key][$uValue['link_uid']]['arrival_price'] = 0;
                        }
                        if (!isset($userAllDivide[$key][$uValue['link_uid']]['performance_total'])) {
                            $userAllDivide[$key][$uValue['link_uid']]['performance_total'] = 0;
                        }
                        $userAllDivide[$key][$uValue['link_uid']]['divide_id'][] = $uValue['id'];
                        $userAllDivide[$key][$uValue['link_uid']]['type'] = 1;
                        $userAllDivide[$key][$uValue['link_uid']]['uid'] = $uValue['link_uid'];
                        $userAllDivide[$key][$uValue['link_uid']]['level'] = $uValue['level'];
                        $userAllDivide[$key][$uValue['link_uid']]['summary_date'] = time();
                        $userAllDivide[$key][$uValue['link_uid']]['summary_cycle_unit'] = $levelIncentives[$key]['billing_cycle_unit'];
                        $userAllDivide[$key][$uValue['link_uid']]['summary_cycle'] = $levelIncentives[$key]['billing_cycle'];
                        $userAllDivide[$key][$uValue['link_uid']]['cycle_start'] = $startTime;
                        $userAllDivide[$key][$uValue['link_uid']]['cycle_end'] = $endTime;
                        $userAllDivide[$key][$uValue['link_uid']]['performance_aims'] = $levelIncentives[$key]['performance_aims'];
                        $userAllDivide[$key][$uValue['link_uid']]['performance_total'] += $uValue['total_price'];
                        $userAllDivide[$key][$uValue['link_uid']]['arrival_price'] += $uValue['real_divide_price'];
                    }
                }
            }
        }

        if (empty($userAllDivide)) {
            return $this->recordError(['msg' => '暂无可操作的发放记录']);
        }

        $IncentivesModel = (new IncentivesRecord());
        $CodeBuilder = (new CodeBuilder());
        $allUid = [];
        //判断是否达到业绩标准,不达标则按最低比例发放,达标则全部发放
        foreach ($userAllDivide as $key => $value) {
            foreach ($value as $cKey => $cValue) {
                if (empty($cValue['arrival_price'])) {
                    unset($userAllDivide[$key][$cKey]);
                    continue;
                }
                $userAllDivide[$key][$cKey]['incentives_sn'] = $CodeBuilder->buildIncentivesCode();
                $userAllDivide[$key][$cKey]['divide_id'] = $cValue['divide_id'];
                if ((string)$cValue['performance_total'] < (string)$cValue['performance_aims'] && !empty($cValue['arrival_price'])) {
                    $userAllDivide[$key][$cKey]['arrival_scale'] = $levelIncentives[$cValue['level']]['least_scale'];
                    $userAllDivide[$key][$cKey]['real_arrival_price'] = priceFormat($cValue['arrival_price'] * $userAllDivide[$key][$cKey]['arrival_scale']);
                    if ((string)$userAllDivide[$key][$cKey]['real_arrival_price'] < 0.01) {
                        $userAllDivide[$key][$cKey]['real_arrival_price'] = 0.01;
                    }
                    $userAllDivide[$key][$cKey]['arrival_status'] = 3;
                    $userAllDivide[$key][$cKey]['arrival_time'] = time();
                    $userAllDivide[$key][$cKey]['fronzen_price'] = $cValue['arrival_price'] - $userAllDivide[$key][$cKey]['real_arrival_price'];
                } else {
                    $userAllDivide[$key][$cKey]['real_arrival_price'] = $cValue['arrival_price'];
                    $userAllDivide[$key][$cKey]['arrival_scale'] = 1;
                    $userAllDivide[$key][$cKey]['arrival_status'] = 1;
                    $userAllDivide[$key][$cKey]['arrival_time'] = time();
                    $userAllDivide[$key][$cKey]['fronzen_price'] = 0;
                }

                $allUid[$key][] = $cValue['uid'];
            }
        }

        if (!empty($allUid)) {
            //每个人一个等级在一个结算周期内只能存在一条记录
            foreach ($allUid as $key => $value) {
                $map[] = ['uid', 'in', $value];
                $map[] = ['level', '=', $key];
                $map[] = ['type', '=', $cValue['type']];
                $map[] = ['cycle_start', '=', $cValue['cycle_start']];
                $map[] = ['cycle_end', '=', $cValue['cycle_end']];
                $existUser[$key] = $IncentivesModel->where($map)->column('uid');
            }
            foreach ($userAllDivide as $key => $value) {
                foreach ($value as $cKey => $cValue) {
                    if (!empty($existUser[$key]) && in_array($cValue['uid'], $existUser[$cValue['level']])) {
                        unset($userAllDivide[$key][$cKey]);
                        continue;
                    }
                }
                if (empty($userAllDivide[$key])) {
                    unset($userAllDivide[$key]);
                }
            }
        }

        if (empty($userAllDivide)) {
            return $this->recordError(['msg' => '暂无可操作的发放记录']);
        }

        //数据库操作
        $DBRes = Db::transaction(function () use ($userAllDivide, $IncentivesModel) {

            foreach ($userAllDivide as $key => $value) {
                foreach ($value as $cKey => $cValue) {
                    $allData[] = $cValue;
                }
            }
            $res = false;
            if (!empty($allData)) {
                $res = $IncentivesModel->saveAll($allData);
            }
            $allUid = array_unique(array_column($allData, 'uid'));
            $allUser = User::where(['uid' => $allUid, 'status' => 1])->field('uid,avaliable_balance,total_balance,divide_balance,integral')->select()->toArray();
            $balanceDetail = (new BalanceDetail());
            foreach ($allData as $key => $value) {
                foreach ($allUser as $uKey => $uValue) {
                    if ($value['uid'] == $uValue['uid']) {
                        //修改冻结状态为已支付
                        $divideStatus['arrival_status'] = 1;
                        $divideStatus['arrival_time'] = time();
                        $divideStatus['incentives_sn'] = $value['incentives_sn'];
                        DivideModel::update($divideStatus, ['id' => $value['divide_id'], 'arrival_status' => 4]);

                        //修改余额
//                        $save['avaliable_balance'] = priceFormat($uValue['avaliable_balance'] + $value['real_arrival_price']);
//                        $save['total_balance'] = priceFormat($uValue['total_balance'] + $value['real_arrival_price']);
                        $save['divide_balance'] = priceFormat($uValue['divide_balance'] + $value['real_arrival_price']);
                        $res = User::update($save, ['uid' => $value['uid'], 'status' => 1]);

                        //增加余额明细
                        $detail['belong'] = 1;
                        $detail['uid'] = $value['uid'];
                        $detail['type'] = 1;
                        $detail['price'] = priceFormat($value['real_arrival_price']);
                        $detail['change_type'] = 1;
                        $detail['remark'] = '教育基金发放';
                        $balanceDetail->new($detail);

                        //模板消息----未做
                        //Queue::push('app\lib\job\Template',$template,'tcmTemplateList');
                    }
                }
            }
            return $res->getData() ?? [];
        });

        //记录日志
        $log['allLevelIncentives'] = $allLevelIncentives;
        $log['data'] = $userAllDivide;
        $log['DBRes'] = $DBRes;
        $this->log($log, 'info');

        return judge($DBRes);
    }

    /**
     * 获取前N个月的开始和结束
     * @param int $ts 时间戳
     * @param int $n 前多少个月
     * @return array 第一个元素为开始日期，第二个元素为结束日期
     */
    public function lastMonth(int $ts, int $n = 1): array
    {
        $ts = intval($ts ?? time());

        $oneMonthAgo = mktime(0, 0, 0, date('n', $ts) - $n, 1, date('Y', $ts));
        $year = date('Y', $oneMonthAgo);
        $month = date('n', $oneMonthAgo);

        return [
            date('Y-m-1', strtotime($year . "-{$month}-1")),
            date('Y-m-t', strtotime($year . "-{$month}-1"))
        ];
    }

    /**
     * 获取上n周的开始和结束,每周从周一开始,周日结束日期
     * @param int $ts 时间戳
     * @param int $n 前多少周
     * @param string $format 默认为'%Y-%m-%d',比如"2012-12-18"
     * @return array 第一个元素为开始日期，第二个元素为结束日期
     */
    function lastNWeek(int $ts, int $n = 0, string $format = '%Y-%m-%d'): array
    {
        $ts = intval($ts ?? time());
        $n = abs(intval($n));

        // 周一到周日分别为1-7
        $dayOfWeek = date('w', $ts);
        if (0 == $dayOfWeek) {
            $dayOfWeek = 7;
        }

        $lastNMonday = 7 * $n + $dayOfWeek - 1;
        $lastNSunday = 7 * ($n - 1) + $dayOfWeek;

        return [
            strftime($format, strtotime("-{$lastNMonday} day", $ts)),
            strftime($format, strtotime("-{$lastNSunday} day", $ts))
        ];
    }

    /**
     * @title  记录错误并保存日志,删除该任务后终止
     * @param array $data 所有数据
     * @param array $error 错误内容
     * @param string $level 日志等级
     * @return bool
     */
    public function recordError(array $data, array $error = [], string $level = 'error')
    {
        $allData['msg'] = $data['msg'];
        $allData['data'] = $data;
        $allData['error'] = $error;
        $this->log($allData, $level);
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
        return (new Log())->setChannel('incentives')->record($data, $level);
    }
}