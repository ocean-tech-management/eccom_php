<?php
// +----------------------------------------------------------------------
// |[ 文档说明: 拼拼有礼模块自动化处理业务逻辑队列]
// +----------------------------------------------------------------------
// | Copyright (c) 2018~2024 http://www.mlhcmk.com All rights reserved.
// +----------------------------------------------------------------------


namespace app\lib\job;


use app\lib\models\GoodsSpu;
use app\lib\models\MemberTest;
use app\lib\models\PpylBalanceDetail;
use app\lib\models\PpylReward;
use app\lib\models\ShippingDetail;
use app\lib\models\User;
use app\lib\services\AfterSale;
use app\lib\services\Pay;
use app\lib\subscribe\Timer;
use think\facade\Db;
use think\facade\Log;
use app\lib\services\Log as LogService;
use app\lib\models\PpylAuto as PpylAutoModel;
use think\queue\Job;

class PpylAuto
{
    /**
     * @title  fire
     * @param Job $job
     * @param $data
     * @return void
     * @throws \Exception
     */
    public function fire(Job $job, $data)
    {
        Log::close('file');
        //自动处理类型 1为冻结奖励发放 2为推荐奖励释放 3为自动激活(领取)推荐奖励 4为检测会员到期 5为自动计划停止
        $type = $data['autoType'] ?? 1;
        switch ($type) {
            case 1:
                $res = Db::transaction(function () use ($data) {
                    //检查需要发放的冻结奖励
                    $map[] = ['arrival_status', '=', 2];
                    $map[] = ['status', '=', 1];
                    $map[] = ['grant_time', '<=', time()];
                    $rewardId = PpylReward::where($map)->column('id');
                    if (!empty($rewardId)) {
                        $rewardList = PpylReward::where(['id' => $rewardId])->lock(true)->select()->toArray();
                        if (!empty($rewardList)) {
                            $log['msg'] = '查找到需要发放的冻结奖励,推入发放冻结奖励队列';
                            $log['data'] = $rewardList;
                            $log['time'] = date('Y-m-d H:i:s');
                            $res = $this->arriveReward($rewardList);
                            $log['map'] = $map ?? [];
                            $log['delRes'] = $res;
                            $this->log($log);
                        }
                    }
                });
                break;
            case 2:
                //检查需要释放的推荐奖励
                $map[] = ['type', '=', 2];
                $map[] = ['arrival_status', '=', 3];
                $map[] = ['status', '=', 1];
                $map[] = ['freed_status', '=', 2];
                $map[] = ['freed_limit_end_time', '<=', time()];
                $topRewardList = PpylReward::where($map)->select()->toArray();
                if (!empty($topRewardList)) {
                    $log['msg'] = '查找到需要释放的推荐奖励,推入释放推荐奖励队列';
                    $log['data'] = array_unique(array_column($topRewardList, 'reward_sn'));
                    $log['time'] = date('Y-m-d H:i:s');
                    $res = $this->freedTopReward($topRewardList);
                    $log['map'] = $map ?? [];
                    $log['delRes'] = $res;
                    $this->log($log);
                }
                break;
            case 3:
                $res = (new PpylReward())->autoReceiveTopReward(['uid' => $data['uid']]);
                break;
            case 4:
                //检查需要超时的CVIP
                $map[] = ['status', '=', 1];
                $map[] = ['c_vip_level', '<>', 0];
                $map[] = ['c_vip_time_out_time', '<=', time()];
                $timeOutCVIP = User::where($map)->field('uid')->select()->toArray();
                if (!empty($timeOutCVIP)) {
                    $log['msg'] = '查找到需要超时的CVIP,推入超时CVIP队列';
                    $log['data'] = $timeOutCVIP;
                    $log['time'] = date('Y-m-d H:i:s');
                    $res = $this->CVIPTimeout($timeOutCVIP);
                    $log['map'] = $map ?? [];
                    $log['delRes'] = $res;
                    $this->log($log);
                }
                break;
            case 5:
                //检查需要释放的自动计划
                $map[] = ['end_time', '<=', time()];
                $map[] = ['status', '=', 1];
                $autoList = PpylAutoModel::where($map)->select()->toArray();
                if (!empty($autoList)) {
                    $log['msg'] = '查找到需要停止的自动计划,推入释放自动计划队列';
                    $log['data'] = array_unique(array_column($autoList, 'plan_sn'));
                    $log['time'] = date('Y-m-d H:i:s');
                    $res = $this->autoTimeOut($autoList);
                    $log['map'] = $map ?? [];
                    $log['delRes'] = $res;
                    $this->log($log);
                }
                break;
            default:
                $res = false;
        }
        $job->delete();
    }

    /**
     * @title  冻结奖励到账
     * @param array $rewardList
     * @return bool|mixed
     * @throws \Exception
     */
    public function arriveReward(array $rewardList)
    {
        if (empty($rewardList)) {
            return false;
        }
        //查询订单是否已经存在余额明细,如果存在则剔除
//        $balanceList = PpylBalanceDetail::where(['order_sn' => array_unique(array_column($rewardList, 'order_sn')), 'type' => 1, 'status' => 1])->column('order_sn');
//        if(!empty($balanceList)){
//            foreach ($rewardList as $key => $value) {
//                if(in_array($value['order_sn'],$balanceList)){
//                    unset($rewardList[$key]);
//                }
//            }
//        }

        foreach ($rewardList as $key => $value) {
            $check = [];
            //循环检查订单是否存在余额收益里面,存在则不允许继续添加
            $check['order_sn'] = $value['order_sn'];
            $check['uid'] = $value['link_uid'];
            $check['type'] = 1;
            $check['status'] = 1;
            if ($value['type'] == 2) {
                $check['change_type'] = 4;
            } else {
                $check['change_type'] = 1;
            }

            //加缓存锁
            $cacheKey = md5(http_build_query($check));
            if (!empty(cache($cacheKey))) {
                unset($rewardList[$key]);
                continue;
            }
            cache($cacheKey, $check, 5);

            $rewardList[$key]['cacheKey'] = $cacheKey;
            $existBalance = PpylBalanceDetail::where($check)->column('order_sn');
            if (!empty($existBalance)) {
                unset($rewardList[$key]);
                continue;
            }
        }

        if (empty($rewardList)) {
            return false;
        }

        $DBRes = Db::transaction(function () use ($rewardList) {

            foreach ($rewardList as $key => $value) {
                $rewardRes = false;
                $userRes = false;
                $balanceRes = false;
                $balanceDetail = [];

                $check = [];
                //循环检查订单是否存在余额收益里面,存在则不允许继续添加
                $check['order_sn'] = $value['order_sn'];
                $check['uid'] = $value['link_uid'];
                $check['type'] = 1;
                $check['status'] = 1;
                if ($value['type'] == 2) {
                    $check['change_type'] = 4;
                } else {
                    $check['change_type'] = 1;
                }

                $existId = PpylBalanceDetail::where($check)->value('id');
                if (!empty($existId)) {
                    $existBalance = PpylBalanceDetail::where(['id' => $existId])->lock(true)->column('order_sn');
                    continue;
                }
                if (!empty($existBalance ?? false)) {
                    continue;
                }

                //修改奖励时间
                $rewardRes = PpylReward::update(['arrival_time' => time(), 'arrival_status' => 1], ['reward_sn' => $value['reward_sn']]);

                //累加拼拼有礼余额
                $userRes = User::where(['uid' => $value['link_uid'], 'status' => 1])->inc('ppyl_balance', $value['real_reward_price'])->update();

                //拼拼有礼余额明细
                $balanceDetail['uid'] = $value['link_uid'];
                $balanceDetail['order_sn'] = $value['order_sn'];
                $balanceDetail['type'] = 1;
                $balanceDetail['price'] = $value['real_reward_price'];
                if($value['type'] == 2){
                    $balanceDetail['change_type'] = 4;
                }else{
                    $balanceDetail['change_type'] = 1;
                }

                if (!empty($balanceDetail)) {
                    $balanceRes = PpylBalanceDetail::create($balanceDetail);
                }

                $returnData[$key]['rewardRes'] = $rewardRes;
                $returnData[$key]['userRes'] = $userRes;
                $returnData[$key]['balanceRes'] = $balanceRes;

                if(!empty($value['cacheKey'] ?? null)){
                    cache($value['cacheKey'],null);
                }

            }

            return $returnData ?? [];
        });

        return $DBRes;

    }

    /**
     * @title  待激活(领取的)推荐奖励释放
     * @param array $rewardList
     * @return bool|mixed
     * @throws \Exception
     */
    public function freedTopReward(array $rewardList)
    {
        if (empty($rewardList)) {
            return false;
        }
        $DBRes = Db::transaction(function () use ($rewardList) {
            foreach ($rewardList as $key => $value) {
                //修改奖励时间
                $returnData['rewardRes'][$key] = PpylReward::update(['freed_time' => time(), 'arrival_status' => -2, 'freed_status' => -1, 'remark' => '到期未领取系统自动释放'], ['reward_sn' => $value['reward_sn']]);
            }
            return $returnData ?? [];
        });

        return $DBRes;

    }

    /**
     * @title  CVIP超时
     * @param array $data
     * @return bool|mixed
     */
    public function CVIPTimeout(array $data)
    {
        if (empty($data)) {
            return false;
        }
        $DBRes = Db::transaction(function () use ($data) {
            $allUid = array_column($data, 'uid');
            if (!empty($allUid)) {
                $returnData = User::update(['c_vip_level' => 0, 'auto_receive_reward' => 2], ['uid' => $allUid, 'status' => 1])->getData();
                return $returnData ?? [];
            }
        });

        return $DBRes;
    }

    /**
     * @title  自动计划超时
     * @param array $data
     * @return mixed
     */
    public function autoTimeOut(array $data)
    {
        if (empty($data)) {
            return false;
        }
        $DBRes = Db::transaction(function () use ($data) {
            $allPlan = array_column($data, 'plan_sn');
            $returnData = false;
            if (!empty($allPlan)) {
//                $returnData = PpylAutoModel::update(['status' => 3, 'fail_msg' => '超时自动停止', 'remark' => '超时自动停止'], ['plan_sn' => $allPlan, 'status' => 1])->getData();
                $errMsg = '超时自动停止';
                $updateTime = time();
//                $planSn = '('.implode(',',$allPlan).')';
                $planSn = '';
                foreach ($allPlan as $key => $value) {
                    if($key == 0){
                        $planSn =  "('".$value."',";
                    }elseif($key != 0 && ($key != count($allPlan) - 1)){
                        $planSn .= "'".$value."',";
                    }elseif($key == count($allPlan) - 1){
                        $planSn .= "'".$value."')";
                    }
                }
                if(!empty($planSn)){
                    $returnData =  Db::query("update sp_ppyl_auto set fail_msg = IFNULL(concat('$errMsg',fail_msg),'$errMsg'),status = 3,update_time = '$updateTime' where plan_sn in $planSn and status = 1;");
                }

                return $returnData ?? [];
            }
        });
        return judge($DBRes);
    }

    /**
     * @title  记录日志
     * @param array $data 数据信息
     * @param string $level 日志等级
     * @return mixed
     */
    public function log(array $data, string $level = 'info')
    {
        return (new LogService())->setChannel('ppylTimer')->record($data, $level);
    }
}