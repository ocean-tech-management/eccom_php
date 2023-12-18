<?php
// +----------------------------------------------------------------------
// |[ 文档说明: ]
// +----------------------------------------------------------------------



namespace app\lib\models;


use app\BaseModel;
use app\lib\exceptions\ServiceException;

class CrowdfundingLotteryApply extends BaseModel
{
    /**
     * @title  用户报名抽奖
     * @param array $data
     * @return mixed
     */
    public function userApplyLottery(array $data)
    {
        $planSn = $data['plan_sn'];
        $uid = $data['uid'];
        $info = self::where(['plan_sn' => $planSn, 'uid' => $uid, 'status' => 1])->count();
        if (!empty($info)) {
            throw new ServiceException(['msg' => '您已经申请过啦']);
        }
        $planInfo = CrowdfundingLottery::where(['plan_sn' => $planSn, 'lottery_status' => 3])->findOrEmpty()->toArray();
        if (empty($planInfo)) {
            throw new ServiceException(['msg' => '抽奖暂无法报名']);
        }

        $gWhere[] = ['status', '=', 1];
        $gWhere[] = ['pay_status', '=', 2];
        $gWhere[] = ['crowd_code', '=', $planInfo['activity_code']];
        $gWhere[] = ['crowd_round_number', '=', $planInfo['round_number']];
        $gWhere[] = ['crowd_period_number', '=', $planInfo['period_number']];
        $orderGoodsList = OrderGoods::where($gWhere)->count();
        if (empty($orderGoodsList)) {
            throw new ServiceException(['msg' => '未参与该期无法抽奖哦']);
        }
        $pWhere[] = ['status', 'in', [1,2]];
        $pWhere[] = ['result_status', '=', 1];
        $pWhere[] = ['activity_code', '=', $planInfo['activity_code']];
        $pWhere[] = ['round_number', '=', $planInfo['round_number']];
        $pWhere[] = ['period_number', '=', $planInfo['period_number']];
        $periodInfo = CrowdfundingPeriod::where($pWhere)->count();
        if (empty($periodInfo)) {
            throw new ServiceException(['msg' => '该期未完成, 无法抽奖哦']);
        }
        //抽奖开始前5秒不允许报名
        if($planInfo['lottery_start_time'] - time() < 5){
            throw new ServiceException(['msg' => '抽奖暂无法报名~']);
        }
        $userPhone = User::where(['uid' => $uid, 'status' => 1])->value('phone');
        $new['uid'] = $uid;
        $new['user_phone'] = $userPhone ?? null;
        $new['plan_sn'] = $planSn;
        $new['activity_code'] = $planInfo['activity_code'];
        $new['round_number'] = $planInfo['round_number'];
        $new['period_number'] = $planInfo['period_number'];
        $new['lottery_time'] = $planInfo['lottery_time'];
        $res = self::save($new);
        return judge($res);
    }

    /**
     * @title  中奖详情
     * @param array $data
     * @return array
     * @throws \Exception
     */
    public function winInfo(array $data)
    {
        $planInfo = CrowdfundingLottery::where(['plan_sn' => $data['plan_sn']])->field('plan_sn,title,activity_code,round_number,period_number,lottery_status,lottery_price')->findOrEmpty()->toArray();
        if (empty($planInfo)) {
            throw new ServiceException(['msg' => '查无抽奖信息']);
        }
        if ($planInfo['lottery_status'] != 1) {
            throw new ServiceException(['msg' => '尚未开奖, 请您耐心等待~']);
        }
        $list = [];
        $wMap[] = ['win_status', '=', 1];
        $wMap[] = ['status', '=', 1];
        $wMap[] = ['plan_sn', '=', $data['plan_sn']];
        $winList = self::with(['user' => function ($query) {
            $query->field('uid,name,avatarUrl');
        }])->field('plan_sn,activity_code,round_number,period_number,uid,user_phone,win_status,win_price,win_level')->where($wMap)->order('win_level asc,create_time asc,id asc')->select()->each(function ($item) {
            if ($this->module != 'admin') {
                if (!empty($item['user_phone'] ?? null)) {
                    $item['user_phone'] = encryptPhone($item['user_phone']);
                }
            }
        })->toArray();
        $userWinInfo = [];
        $userWinRes = false;
        if (!empty($winList)) {
            foreach ($winList as $key => $value) {
                $list[$value['win_level']][] = $value;
                if (!empty($data['uid'] ?? null)) {
                    if ($value['uid'] == $data['uid']) {
                        $userWinInfo = $value;
                        $userWinRes = true;
                    }
                }
            }
            $list = array_values($list);
        }
        return ['planInfo' => $planInfo, 'winList' => $list, 'userWinInfo' => $userWinInfo, 'userWinRes' => $userWinRes];
    }

    public function user()
    {
        return $this->hasOne('User','uid','uid');
    }
}