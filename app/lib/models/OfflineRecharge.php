<?php
// +----------------------------------------------------------------------
// |[ 文档说明: 线下充值提交记录表]
// +----------------------------------------------------------------------
// | Copyright (c) 2018~2024 http://www.mlhcmk.com All rights reserved.
// +----------------------------------------------------------------------
 

namespace app\lib\models;


use app\BaseModel;
use app\lib\exceptions\ServiceException;
use app\lib\services\CodeBuilder;
use think\facade\Db;

class OfflineRecharge extends BaseModel
{

    /**
     * @title  线下充值提交记录列表
     * @param array $sear
     * @return array
     * @throws \Exception
     */
    public function list(array $sear): array
    {
        if (!empty($sear['keyword'])) {
            $map[] = ['', 'exp', Db::raw($this->getFuzzySearSql('d.phone', $sear['keyword']))];
        }
        $map[] = ['a.status', 'in', $this->getStatusByRequestModule($sear['searType'] ?? 1)];

        if (!empty($sear['type'])) {
            $map[] = ['a.type', '=', $sear['type']];
        }
        if (!empty($sear['check_status'])) {
            $map[] = ['a.check_status', '=', $sear['check_status']];
        }

        if (!empty($sear['uid'])) {
            $map[] = ['a.uid', '=', $sear['uid']];
            $map[] = ['a.price', '<>', 0];
        }


        if ($this->module == 'api') {
            $map[] = ['a.uid', '=', $sear['uid']];
            $map[] = ['a.price', '<>', 0];
        }

        if (!empty($sear['start_time']) && !empty($sear['end_time'])) {
            $map[] = ['a.create_time', 'between', [strtotime($sear['start_time']), strtotime($sear['end_time'])]];
        }

        $page = intval($sear['page'] ?? 0) ?: null;
        if (!empty($page)) {
            $aTotal = $this->alias('a')
                ->join('sp_user d', 'a.uid = d.uid', 'left')->where($map)->count();
            $pageTotal = ceil($aTotal / $this->pageNumber);
        }
        $list = $this->alias('a')
            ->join('sp_user d', 'a.uid = d.uid', 'left')
            ->field('a.*,d.name as user_name,d.phone as user_phone,d.avatarUrl')->where($map)->when($page, function ($query) use ($page) {
                $query->page($page, $this->pageNumber);
            })->order('a.create_time desc')->select()->each(function($item){
                if (!empty($item['check_time'] ?? null)) {
                    $item['check_time'] = timeToDateFormat($item['check_time']);
                }
                if (!empty($item['arrival_time'] ?? null)) {
                    $item['arrival_time'] = timeToDateFormat($item['arrival_time']);
                }
            })->toArray();

        return ['list' => $list, 'pageTotal' => $pageTotal ?? 0];
    }

    /**
     * @title  用户提交线下充值申请
     * @param array $data
     * @return bool
     */
    public function userSubmit(array $data)
    {
        if(empty($data['phone'] ?? null) || empty($data['pay_no'] ?? null) || empty($data['pay_image'] ?? null)){
            throw new ServiceException(['msg'=>'非法请求']);
        }
        $phone = trim($data['phone']);
        if (!preg_match("/^(([a-za-z])|([0-9]))*$/i", trim($data['pay_no']))) {
            throw new ServiceException(['msg' => '流水号必须由数字和字母的组合而成']);
        }
        $payNo = trim($data['pay_no']);
        $payImages = $data['pay_image'];
        $remark = $data['remark'] ?? null;
        $existRecord = self::where(function ($query) use ($data) {
            $where1[] = ['pay_no', '=', trim($data['pay_no'])];
            $where2[] = ['user_phone', '=', trim($data['phone'])];
            $query->whereOr([$where1, $where2]);
        })->where(['check_status' => [1, 3], 'user_phone' => $phone])->order('create_time desc')->findOrEmpty()->toArray();
        //检查流水号是否重复存在
        $existPayNo = self::where(['check_status' => [1, 3], 'pay_no' => trim($data['pay_no'])])->count();
        if (!empty($existPayNo)) {
            throw new ServiceException(['msg' => '[风控拦截] 请勿提交重复的流水号!']);
        }
        if (!empty($existRecord)) {
            if ($existRecord['check_status'] == 3) {
                throw new ServiceException(['msg' => '提交过于频繁, 请稍后重试']);
            } else {
                if ($existRecord['pay_no'] == $payNo) {
                    throw new ServiceException(['msg' => '请勿提交重复的流水号!']);
                }
            }
        }
        $userUid = User::where(['phone' => $data['phone'], 'status' => 1])->value('uid');
        if (empty($userUid)) {
            throw new ServiceException(['msg' => '查无该手机号码!']);
        }
        $newRecord['uid'] = $userUid;
        $newRecord['user_phone'] = $phone;
        $newRecord['recharge_sn'] = (new CodeBuilder())->buildOfflineRechargeSn();
        $newRecord['type'] = 4;
        $newRecord['pay_no'] = $payNo;
        $newRecord['price'] = doubleval($data['price']);
        $newRecord['remark'] = $remark;
        $newRecord['image'] = $payImages;
        $res = $this->save($newRecord);
        return judge($res);
    }

    /**
     * @title  审核
     * @param array $data
     * @return mixed
     */
    public function check(array $data)
    {
        $rechargeSn = $data['recharge_sn'];
        $save['check_status'] = $data['check_status'];
        $save['check_time'] = time();
        if ($save['check_status'] == 2) {
            $save['check_remark'] = $data['check_remark'] ?? '管理员审核不通过';
        }
        //本次充值赠送东西类型 1为美丽豆  2为健康豆 -1为不赠送 默认-1
        $giftType = $data['gift_type'] ?? -1;
        //healthy_channel_type 如果赠送的是健康豆, 需要选择赠送的健康豆渠道 -1默认为不赠送
        $healthyChannelType = $data['healthy_channel_type'] ?? -1;
        if ($giftType != -1) {
            $save['gift_type'] = intval($giftType);
            if ($save['gift_type'] == 2) {
                $save['healthy_channel_type'] = intval($healthyChannelType);
            }
        }
        $res = self::update($save, ['recharge_sn' => $rechargeSn, 'status' => 1, 'check_status' => 3]);
        return judge($res);
    }

    /**
     * @title  发放美丽金
     * @param array $data
     * @return bool
     */
    public function grantPrice(array $data)
    {
        $rechargeSn = $data['recharge_sn'];
        //发放类型 1为通过系统发放 2为已通过其他渠道发放
        $grantType = $data['type'] ?? 1;
        $info = self::where(['recharge_sn' => $rechargeSn, 'status' => 1, 'check_status' => 1, 'arrival_status' => 3])->findOrEmpty()->toArray();
        if (empty($info)) {
            throw new ServiceException(['msg' => '查无有效可发放记录']);
        }
        if (empty($info['user_phone'])) {
            throw new ServiceException(['msg' => '用户尚未授权手机号码, 无法发放美丽金']);
        }
        //本次充值赠送东西类型 1为美丽豆  2为健康豆 -1为不赠送 默认-1
        $giftType = $info['gift_type'] ?? -1;
        //healthy_channel_type 如果赠送的是健康豆, 需要选择赠送的健康豆渠道 -1默认为不赠送
        $healthyChannelType = $info['healthy_channel_type'] ?? -1;
        if ($giftType == 2 && $healthyChannelType == -1) {
            throw new ServiceException(['msg' => '请选择有效的健康豆渠道']);
        }
        $requestData[0]['userPhone'] = $info['user_phone'];
        $requestData[0]['price'] = $info['price'];
        if ($grantType == 1) {
            $res = (new CrowdfundingBalanceDetail())->rechargeCrowdBalance(['allUser' => $requestData, 'type' => 2, 'recharge_sn' => $rechargeSn, 'gift_type' => $giftType, 'performance_type' => 1, 'healthy_channel_type' => $healthyChannelType]);
        } else {
            $res = self::update(['arrival_status' => 1, 'arrival_time' => time(), 'arrival_type' => 2], ['recharge_sn' => $data['recharge_sn'], 'status' => 1, 'check_status' => 1, 'arrival_status' => 3]);
        }

        return judge($res);
    }

    public function userPhone()
    {
        return $this->hasOne('User', 'uid', 'uid')->bind(['user_phone' => 'phone']);
    }
}