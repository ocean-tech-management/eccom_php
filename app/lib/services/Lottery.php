<?php
// +----------------------------------------------------------------------
// |[ 文档说明: 抽奖模块Service]
// +----------------------------------------------------------------------



namespace app\lib\services;


use app\lib\exceptions\CouponException;
use app\lib\exceptions\ServiceException;
use app\lib\models\CouponUserType;
use app\lib\models\CrowdfundingBalanceDetail;
use app\lib\models\CrowdfundingLottery;
use app\lib\models\CrowdfundingLotteryApply;
use app\lib\models\CrowdfundingPeriod;
use app\lib\models\Lottery as LotteryModel;
use app\lib\models\LotteryGoods;
use app\lib\models\LotterySpecial;
use app\lib\models\LotteryWin;
use app\lib\models\Member;
use app\lib\models\Order;
use app\lib\models\User;
use think\facade\Db;

class Lottery
{
    /**
     * @title  众筹抽奖
     * @param array $data
     * @return bool|mixed
     * @throws \Exception
     */
    public function crowdLottery(array $data)
    {
        $lotteryInfo = CrowdfundingLottery::where(['plan_sn' => $data['plan_sn'], 'status' => 1, 'lottery_status' => [2, 3]])->findOrEmpty()->toArray();
        $log['msg'] = '已收到众筹抽奖计划处理抽奖的请求';
        $log['requestData'] = $data;
        $log['lotteryInfo'] = $lotteryInfo;

        if (empty($lotteryInfo)) {
            return $this->recordError($log, ['msg' => '抽奖活动已完成~']);
        }
        $existBalance = CrowdfundingBalanceDetail::where(['order_sn' => $data['plan_sn'], 'status' => 1, 'change_type' => 10])->count();
        if (!empty($existBalance)) {
            return $this->recordError($log, ['msg' => '抽奖已存在奖励, 不允许重复抽奖']);
        }
        //判断活动时间
        if ($lotteryInfo['lottery_start_time'] > time()) {
            return $this->recordError($log, ['msg' => '抽奖尚未开始或已结束']);
        }

        $lotteryUser = [];
        $allApplyUser = CrowdfundingLotteryApply::where(['plan_sn'=>$data['plan_sn'],'status'=>1])->column('uid');
        $log['allApplyUser'] = $allApplyUser;
        if(empty($allApplyUser)){
            //修改抽奖状态为已抽奖
            CrowdfundingLottery::update(['lottery_status' => 1, 'lottery_time' => time(), 'remark' => '无有效报名人参与, 此次抽奖计划空置'], ['plan_sn' => $data['plan_sn'], 'status' => 1, 'lottery_status' => [2, 3]]);
            return $this->recordError($log, ['msg' => '无有效报名人']);
        }
        foreach ($allApplyUser as $key => $value) {
            //每个人的概率相同
            $lotteryUser[$value] = 1;
            $lotteryUserInfo[$value] = $value;
        }
        $log['$lotteryUser'] = $lotteryUser;

        //添加缓存锁,防止修改
        cache((new CrowdfundingLottery())->lockCacheKey . $data['plan_sn'], 'isDeal', 600);

        $DBRes = Db::transaction(function () use ($data,$lotteryUser,$lotteryInfo) {
            $winGroup = json_decode($lotteryInfo['lottery_scope'],true);
            array_multisort(array_column($winGroup,'win_level'), SORT_DESC, $winGroup);
            $totalWinPrice = $lotteryInfo['lottery_price'];
            //倒序从最低的(等级最高的)开始抽
            foreach ($winGroup as $key => $value) {
                $winGroupInfo[$value['win_level']] = $value;
                for ($i = 1; $i <= ($value['win_number'] ?? 1); $i++) {
                    $proSum = array_sum($lotteryUser ?? []);
                    if ($proSum > 0) {
                        //进入抽奖算法
                        $winUser = $this->lotteryAlgorithm($lotteryUser);
                        //抽中一个记录一个, 然后把这个人剔除
                        if (!empty($winUser)) {
                            $winArr[$winUser]['win_level'] = $value['win_level'];
                            $winArr[$winUser]['win_price'] = priceFormat(priceFormat($totalWinPrice * $value['win_scale']) / $value['win_number']);
                            unset($lotteryUser[$winUser]);
                        }
                    }
                }
            }

            if(empty($winArr)){
                throw new ServiceException(['msg' => '无有效中奖人']);
            }
            //记录中奖
            foreach ($winArr as $key => $value) {
                $update['win_status'] = 1;
                $update['win_price'] = $value['win_price'];
                $update['win_time'] = time();
                $update['win_level'] = $value['win_level'];
                CrowdfundingLotteryApply::update($update, ['uid' => $key, 'status' => 1, 'win_status' => 3, 'plan_sn' => $data['plan_sn']]);

                //添加余额明细
                $balance[$key]['order_sn'] = $data['plan_sn'];
                $balance[$key]['price'] = $value['win_price'];
                $balance[$key]['type'] = 1;
                $balance[$key]['uid'] = $key;
                $balance[$key]['change_type'] = 10;
                $balance[$key]['crowd_code'] = $lotteryInfo['activity_code'];
                $balance[$key]['crowd_round_number'] = $lotteryInfo['round_number'];
                $balance[$key]['crowd_period_number'] = $lotteryInfo['period_number'];
                $balance[$key]['remark'] = '福利活动中奖奖金';

                //发放到用户账户
                $refundRes[$key] = User::where(['uid' => $key])->inc('crowd_balance', $balance[$key]['price'])->update();
            }

            if (!empty($balance ?? [])) {
                (new CrowdfundingBalanceDetail())->saveAll($balance);
            }

            //记录不中奖
            CrowdfundingLotteryApply::update(['win_status' => 2], ['uid' => array_keys($lotteryUser), 'plan_sn' => $data['plan_sn']]);
            //修改中将计划为已抽奖
            CrowdfundingLottery::update(['lottery_time' => time(), 'lottery_status' => 1], ['plan_sn' => $data['plan_sn']]);
            $winRes['totalWinPrice'] = $totalWinPrice;
            $winRes['winGroup'] = $winGroup;
            $winRes['winArr'] = $winArr;
            return $winRes;
        });

        $log['DBRes'] = $DBRes;
        $log['dealMsg'] = '已成功处理抽奖计划' . $data['plan_sn'] . '的抽奖处理';
        $this->log($log, 'info');
        return $DBRes;
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
     * @title  记录错误并保存日志,删除该任务后终止
     * @param array $data 所有数据
     * @param array $error 错误内容
     * @return bool
     */
    public function recordError(array $data, array $error)
    {
        $allData['msg'] = '抽奖计划 ' . ($data['requestData']['plan_sn'] ?? "<暂无计划编号>") . " [ 服务出错:" . ($error['msg'] ?? '原因未知') . " ] ";
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
        return (new Log())->setChannel('lottery')->record($data, $level);
    }
}