<?php
// +----------------------------------------------------------------------
// |[ 文档说明: ]
// +----------------------------------------------------------------------
// | Copyright (c) 2018~2024 http://www.mlhcmk.com All rights reserved.
// +----------------------------------------------------------------------


namespace app\lib\models;


use app\BaseModel;
use app\lib\exceptions\FinanceException;
use app\lib\exceptions\ParamException;
use app\lib\exceptions\ServiceException;
use think\facade\Cache;
use think\facade\Db;

class BalanceDetail extends BaseModel
{
    protected $field = ['uid', 'order_sn', 'type', 'price', 'status', 'change_type', 'belong', 'remark'];
    protected $validateFields = ['uid', 'type', 'price', 'status' => 'number'];

    /**
     * @title  余额明细列表
     * @param array $sear
     * @return array
     * @throws \Exception
     */
    public function list(array $sear): array
    {
        $map[] = ['status', 'in', $this->getStatusByRequestModule($sear['searType'] ?? 1)];
        if (!empty($sear['change_type'])) {
            $map[] = ['change_type', '=', $sear['change_type']];
        }
        if (!empty($sear['type'])) {
            $map[] = ['type', '=', $sear['type']];
        }

        if (!empty($sear['uid'])) {
            $map[] = ['uid', '=', $sear['uid']];
            $map[] = ['price', '<>', 0];
        }

        if ($this->module == 'api') {
            $map[] = ['uid', '=', $sear['uid']];
            $map[] = ['price', '<>', 0];
        }

        if (!empty($sear['start_time']) && !empty($sear['end_time'])) {
            $map[] = ['create_time', 'between', [strtotime($sear['start_time']), strtotime($sear['end_time'])]];
        }

        $page = intval($sear['page'] ?? 0) ?: null;
        if (!empty($sear['pageNumber'] ?? 0)) {
            $this->pageNumber = intval($sear['pageNumber']);
        }
        if (!empty($page)) {
            $aTotal = $this->where($map)->count();
            $pageTotal = ceil($aTotal / $this->pageNumber);
        }

        $list = $this->with(['divide'])->where($map)->withoutField('id,update_time,status,belong')->when($page, function ($query) use ($page) {
            $query->page($page, $this->pageNumber);
        })->order('create_time desc')->select()->toArray();

        if (!empty($list)) {
            $allOrderUid = array_column($list, 'order_uid');
            $allOrderUser = User::where(['uid' => $allOrderUid])->column('name', 'uid');
            foreach ($list as $key => $value) {
                foreach ($allOrderUser as $k => $v) {
                    if (!empty($value['order_uid'])) {
                        if ($k == $value['order_uid']) {
                            $list[$key]['order_user_name'] = $v;
                        }
                    }

                }
                unset($list[$key]['order_uid']);
            }
        }

//        if($this->module == 'api'){
//            $aUserInfo = (new User())->getUserInfo($sear['uid']);
//            $info['withdraw_total'] = $aUserInfo['withdraw_total'];
//            $info['now_total'] = $aUserInfo['avaliable_balance'];
//            $aDivide = Divide::where(['link_uid'=>$sear['uid'],'status'=>1])->sum('divide_price');
//            $aDivideYesterday = Divide::where(['link_uid'=>$sear['uid'],'status'=>1])->whereDay('create_time','yesterday')->sum('divide_price');
//            $info['divide_total'] = priceFormat($aDivide);
//            $info['yesterday_divide_total'] = priceFormat($aDivideYesterday);
//        }
//        if(!empty($info)){
//            $msg = '成功';
//            if(empty($list)){
//                $msg = '暂无数据';
//            }
//            return ['error_code'=>0,'msg'=>$msg,'data'=>['balance'=>$info,'pageTotal'=>$pageTotal ?? 0,'list'=>$list]];
//        }

        return ['list' => $list, 'pageTotal' => $pageTotal ?? 0];
    }

    /**
     * @title  新建余额明细
     * @param array $data
     * @return mixed
     */
    public function new(array $data)
    {
        return $this->validate()->baseCreate($data, true);
    }

    /**
     * @title  获取用户的总余额(一般用于校验用户可用余额)
     * @param string $uid
     * @param array $type 类型
     * @return float
     */
    public function getAllBalanceByUid(string $uid, array $type = [])
    {
        $map[] = ['uid', '=', $uid];
        $map[] = ['status', '=', 1];
        if (!empty($type)) {
            $map[] = ['change_type', 'in', $type];
        }
        return $this->where($map)->sum('price');
    }

    /**
     * @title  获取余额汇总
     * @param array $data
     * @return mixed
     */
    public function getSummaryBalanceByUid(array $data)
    {
        $userInfo = User::where(['uid' => $data['uid']])->findOrEmpty()->toArray();
        $returnData['ad_balance'] = $userInfo['ad_balance'] ?? 0;
        $returnData['ad_fronzen_balance'] = $userInfo['ad_fronzen_balance'] ?? 0;
        $returnData['ad_withdraw_total'] = $userInfo['ad_withdraw_total'] ?? 0;
        $returnData['divide_balance'] = $userInfo['divide_balance'] ?? 0;
        $returnData['divide_fronzen_balance'] = $userInfo['divide_fronzen_balance'] ?? 0;
        $returnData['divide_withdraw_total'] = $userInfo['divide_withdraw_total'] ?? 0;
        $returnData['ppyl_balance'] = $userInfo['ppyl_balance'] ?? 0;
        $returnData['ppyl_fronzen_balance'] = $userInfo['ppyl_fronzen_balance'] ?? 0;
        $returnData['ppyl_withdraw_total'] = $userInfo['ppyl_withdraw_total'] ?? 0;
        $returnData['total_price'] = priceFormat($this->getAllBalanceByUid($data['uid']) ?? 0);
        return $returnData;
    }

    /**
     * @title  钱包余额互转
     * @param array $data
     * @return bool
     */
    public function transfer(array $data)
    {
        //转让类型 1为除美丽金外的钱包互转 2为转入美丽金
        $transferType = $data['transfer_type'] ?? 1;
        if (empty($data['uid'] ?? null) || empty($data['price'] ?? null)) {
            throw new ServiceException(['msg' => '非法请求']);
        }
        $uid = $data['uid'];
        if (!empty(cache('withdrawBalanceIng-' . $data['uid']))) {
            throw new FinanceException(['msg' => '请勿重复提交, 感谢您的支持和理解']);
        }
        cache('withdrawBalanceIng-' . $data['uid'], $data, 60);
        $price = $data['price'];
        //转换来源类型 1为商城余额  2为拼拼 3为广宣奖 4为团队业绩 5为股东奖 6为区代奖 7为混合余额(包含1~6) 8为美丽金余额
        $fromType = $data['from_type'] ?? 1;
        //转换去处类型 (值同转换来源类型)
        $forType = $data['for_type'] ?? 1;

        $userInfo = User::where(['uid' => $uid, 'status' => 1])->findOrEmpty()->toArray();
        if (empty($userInfo)) {
            throw new ServiceException(['msg' => '非法用户']);
        }
        if (empty($userInfo['pay_pwd'])) {
            throw new ServiceException(['msg' => '尚未设置支付密码, 请您先设置支付密码!']);
        }
        if(empty($data['pay_pwd'])){
            throw new ServiceException(['msg' => '风控拦截!请勿继续操作!']);
        }
        $cacheKey = 'payPwdError-' . $data['uid'];
        if ($data['pay_pwd'] != $userInfo['pay_pwd'] && md5($data['pay_pwd']) != $userInfo['pay_pwd']) {
            $errorLimitNumber = 5;
            $nowCache = cache($cacheKey);
            if (!empty($nowCache)) {
                if ($nowCache >= $errorLimitNumber) {
                    $lastNumber = 0;
                    throw new ServiceException(['msg' => '支付操作当日已被冻结!']);
                } else {
                    $lastNumber = $errorLimitNumber - $nowCache - 1;
                    Cache::inc($cacheKey);
                }
            } else {
                cache($cacheKey, 1, (24 * 3600));
                $lastNumber = $errorLimitNumber - 1;
            }
            throw new ServiceException(['msg' => '支付密码错误,请重试。今日剩余次数 ' . $lastNumber]);
        }
        //密码正确重置次数
        cache($cacheKey, null);
        $fieldName = null;
        //用户余额限制-钱包类型
        $userLimitBalanceType = null;
        //用户余额限制-提现类型, 默认团长端提现
        $userLimitWithdrawType = 5;

        switch ($fromType ?? 1) {
            case 1:
                $changeType = [1, 2, 3, 4, 5, 6, 7, 10, 11, 13, 22, 23, 24];
                $fieldName = 'divide_balance';
                $userNowBalance = $userInfo[$fieldName];
                $userLimitBalanceType = 1;
                $userLimitWithdrawType = [1, 3];
                break;
            case 2:
                $changeType = [8, 9, 25, 26];
                $fieldName = 'ppyl_balance';
                $userNowBalance = $userInfo[$fieldName];
                $userLimitWithdrawType = 4;
                break;
            case 3:
                $changeType = [12, 15, 27, 28];
                $fieldName = 'ad_balance';
                $userNowBalance = $userInfo[$fieldName];
                $userLimitBalanceType = 2;
                break;
            case 4:
                $changeType = [16, 17, 29, 30];
                $fieldName = 'team_balance';
                $userNowBalance = $userInfo[$fieldName];
                $userLimitBalanceType = 3;
                break;
            case 5:
                $changeType = [18, 19, 31, 32];
                $fieldName = 'shareholder_balance';
                $userNowBalance = $userInfo[$fieldName];
                $userLimitBalanceType = 5;
                break;
            case 6:
                $changeType = [20, 21, 33, 34];
                $fieldName = 'area_balance';
                $userNowBalance = $userInfo[$fieldName];
                $userLimitBalanceType = 4;
                break;
            case 7:
                $changeType = [];
                $userNowBalance = ($userInfo['divide_balance'] ?? 0) + ($userInfo['ppyl_balance'] ?? 0) + ($userInfo['ad_balance'] ?? 0) + ($userInfo['team_balance'] ?? 0) + ($userInfo['shareholder_balance'] ?? 0) + ($userInfo['area_balance'] ?? 0);
                //请注意, 这里转化操作包含了商城余额, 但实际判断余额限制的时候没有包含
                $userLimitBalanceType = 10;
                break;
            case 8:
                //美丽金支付
                $changeType = [];
                $userNowBalance = $userInfo['crowd_balance'] ?? 0;
                $userLimitBalanceType = 6;
                $userLimitWithdrawType = 7;
                break;
            default:
                throw new ServiceException(['msg' => '不允许的类型']);
        }
        $map[] = ['uid','=',$uid];
        $map[] = ['status','=',1];
        if(!empty($changeType)){
            $map[] = ['change_type','in',$changeType];
        }
        if ($fromType == 8) {
            $userAllBalance = CrowdfundingBalanceDetail::where($map)->sum('price');
        } else {
            $userAllBalance = self::where($map)->sum('price');
        }


        if (doubleval($userAllBalance) <= 0 || $userAllBalance < $price  || (($userNowBalance ?? 0) < $price) || (($userNowBalance ?? 0) <= 0)) {
            throw new ServiceException(['msg' => '用户余额不足!']);
        }
        if (!empty($fieldName ?? null)) {
            if (doubleval($userInfo[$fieldName] ?? 0) <= 0 || ($userInfo[$fieldName] ?? 0) < $price) {
                throw new ServiceException(['msg' => '用户当前余额不足!']);
            }
        }

        //判断用户是否有余额限制操作
        (new UserBalanceLimit())->checkUserBalanceLimit(['oper_type' => 3, 'uid' => $data['uid'], 'balance_type' => ($userLimitBalanceType ?? 10), 'limit_change_type' => ($changeType ?? []), 'withdraw_type' => ($userLimitWithdrawType ?? 5), 'price' => ($price ?? 0)]);

        if ($fromType == 8) {
            throw new ServiceException(['msg' => '暂不支持通过此途径转出美丽金']);
        }
        //处理美丽金转换, 其他途径的余额都需要检验当天的提现额度,额度不够不允许转出
        if ($fromType != 8 && (config('system.withdrawLimitByRecharge') == 1)) {
            //获取用户当天可以提现的额度
            $userTodayWithdrawLimit = (new RechargeLink())->userSubordinateRechargeRate(['uid' => $data['uid'], 'clearCache' => true, 'time_type' => 1]);
            if (empty($userTodayWithdrawLimit)) {
                throw new FinanceException(['msg' => '额度计算异常']);
            }
            if (!empty(doubleval($userTodayWithdrawLimit['withdrawLimit'] ?? false))) {
                $userHistoryAmount = (new RechargeLink())->checkUserWithdrawAndTransfer(['uid' => $data['uid'], 'time_type' => 1]);
                if ((string)($data['price'] + $userHistoryAmount) > (string)($userTodayWithdrawLimit['totalCanWithdrawAmount'] ?? 0)) {
                    throw new FinanceException(['msg' => '今日累计可以提现额度为' . ($userTodayWithdrawLimit['totalCanWithdrawAmount'] ?? 0) . ' 当前已操作的金额为' . (priceFormat($userHistoryAmount ?? 0)) . ' 你填写的额度已超过, 请减少您的提现额度']);
                }
            }
        }

        $DBRes = Db::transaction(function () use ($data, $uid, $price, $transferType, $fromType, $forType,$userInfo) {
            $balanceDetail[0]['uid'] = $uid;
            $balanceDetail[0]['type'] = 2;
            $balanceDetail[0]['transfer_type'] = $transferType;
            switch ($fromType ?? 1) {
                case 1:
                    $fromChangeType = 24;
                    $typeText = '商城余额转入';
                    $crowdForChangeType = 3;
                    break;
                case 2:
                    $fromChangeType = 26;
                    $typeText = '拼拼余额转入';
                    $crowdForChangeType = 4;
                    break;
                case 3:
                    $fromChangeType = 28;
                    $typeText = '广宣奖余额转入';
                    $crowdForChangeType = 5;
                    break;
                case 4:
                    $fromChangeType = 30;
                    $typeText = '团队业绩余额转入';
                    $crowdForChangeType = 6;
                    break;
                case 5:
                    $fromChangeType = 32;
                    $typeText = '股东将余额转入';
                    $crowdForChangeType = 7;
                    break;
                case 6:
                    $fromChangeType = 34;
                    $typeText = '区代奖余额转入';
                    $crowdForChangeType = 8;
                    break;
                case 7:
                    $typeText = '混合余额转入';
                    $crowdForChangeType = 7;
                    $allBalance = [
                        'divide_balance' => ['change_type' => 24, 'balance' => $userInfo['divide_balance'], 'type_text' => '商城余额转入', 'for_change_type' => 23, 'crowdForChangeType' => 3],
                        'ppyl_balance' => ['change_type' => 26, 'balance' => $userInfo['ppyl_balance'], 'type_text' => '拼拼余额转入', 'for_change_type' => 23, 'crowdForChangeType' => 4],
                        'ad_balance' => ['change_type' => 28, 'balance' => $userInfo['ad_balance'], 'type_text' => '广宣奖余额转入', 'for_change_type' => 23, 'crowdForChangeType' => 5],
                        'team_balance' => ['change_type' => 30, 'balance' => $userInfo['team_balance'], 'type_text' => '团队业绩余额转入', 'for_change_type' => 23, 'crowdForChangeType' => 6],
                        'shareholder_balance' => ['change_type' => 32, 'balance' => $userInfo['shareholder_balance'], 'type_text' => '股东奖余额转入', 'for_change_type' => 23, 'crowdForChangeType' => 7],
                        'area_balance' => ['change_type' => 34, 'balance' => $userInfo['area_balance'], 'type_text' => '区代余额转入', 'for_change_type' => 23, 'crowdForChangeType' => 8]];
                    $totalBalance = 0;
                    $mixBalanceType = [];
                    $mixBalance = [];
                    foreach ($allBalance as $key => $value) {
                        if(doubleval($value['balance']) > 0){
                            if(empty($totalBalance)){
                                if($value['balance'] >= $data['price']){
                                    $mixBalanceType[$key] = $data['price'];
                                    $mixBalance[$key] = $value;
                                    $mixBalance[$key]['balance'] = $mixBalanceType[$key];
                                    $totalBalance += ($mixBalanceType[$key] ?? 0);
                                    break;
                                }else{
                                    $mixBalanceType[$key] = $value['balance'];
                                    $mixBalance[$key] = $value;
                                    $mixBalance[$key]['balance'] = $mixBalanceType[$key];
                                    $totalBalance += ($mixBalanceType[$key] ?? 0);
                                }
                            }else{
                                if ($totalBalance + $value['balance'] <= $data['price']) {
                                    $mixBalanceType[$key] = $value['balance'];
                                    $mixBalance[$key] = $value;
                                    $mixBalance[$key]['balance'] = $mixBalanceType[$key];
                                } else {
                                    if ($data['price'] - $totalBalance <= $value['balance']) {
                                        $mixBalanceType[$key] = $data['price'] - $totalBalance;
                                        $mixBalance[$key] = $value;
                                        $mixBalance[$key]['balance'] = $mixBalanceType[$key];
                                    }
                                }
                                $totalBalance += ($mixBalanceType[$key] ?? 0);
                            }

                        }
                    }

                    if (doubleval(array_sum($mixBalanceType)) != doubleval($data['price'])) {
                        throw new ServiceException(['msg' => '计算出错']);
                    }
                    break;
                case 8:
                    $fromChangeType = 22;
                    $typeText = '美丽金余额转入';
                    break;
                default:
                    throw new ServiceException(['msg' => '不允许的类型']);
            }
            if ($fromType != 7) {
                $balanceDetail[0]['change_type'] = $fromChangeType;
                $balanceDetail[0]['price'] = '-' . $price;
            } else {
                if (empty($mixBalance)) {
                    throw new ServiceException(['msg' => '计算出错']);
                }
                foreach (array_values($mixBalance) as $key => $value) {
                    $balanceDetail[$key + 1] = $balanceDetail[0];
                    $balanceDetail[$key + 1]['change_type'] = $value['change_type'];
                    $balanceDetail[$key + 1]['price'] = '-' . $value['balance'];
                }
                unset($balanceDetail[0]);
            }

            if ($transferType == 1) {
                switch ($forType ?? 1) {
                    case 1:
                        $forChangeType = 23;
                        break;
                    case 2:
                        $forChangeType = 25;
                        break;
                    case 3:
                        $forChangeType = 27;
                        break;
                    case 4:
                        $forChangeType = 29;
                        break;
                    case 5:
                        $forChangeType = 31;
                        break;
                    case 6:
                        $forChangeType = 34;
                        break;
                    default:
                        throw new ServiceException(['msg' => '不允许的类型']);
                }
                $number = count($balanceDetail);
                $balanceDetail[$number]['uid'] = $uid;
                $balanceDetail[$number]['type'] = 1;
                $balanceDetail[$number]['price'] = $price;
                if ($fromType == 8) {
                    $balanceDetail[$number]['change_type'] = 22;
                } else {
                    $balanceDetail[$number]['change_type'] = $forChangeType;
                }
                $balanceDetail[$number]['transfer_type'] = 1;
                $balanceDetail[$number]['remark'] = $typeText;
            } else {
                $userCrowdBalanceDetail[0]['uid'] = $uid;
                $userCrowdBalanceDetail[0]['type'] = 1;
                $userCrowdBalanceDetail[0]['price'] = $price;
                $userCrowdBalanceDetail[0]['change_type'] = 9;
                $userCrowdBalanceDetail[0]['transfer_type'] = $crowdForChangeType;
                $userCrowdBalanceDetail[0]['remark'] = $typeText;
            }


            switch ($fromType ?? 1) {
                case 1:
                    User::where(['uid' => $uid, 'status' => 1])->dec('divide_balance', $price)->update();
                    break;
                case 2:
                    User::where(['uid' => $uid, 'status' => 1])->dec('ppyl_balance', $price)->update();
                    break;
                case 3:
                    User::where(['uid' => $uid, 'status' => 1])->dec('ad_balance', $price)->update();
                    break;
                case 4:
                    User::where(['uid' => $uid, 'status' => 1])->dec('team_balance', $price)->update();
                    break;
                case 5:
                    User::where(['uid' => $uid, 'status' => 1])->dec('shareholder_balance', $price)->update();
                    break;
                case 6:
                    User::where(['uid' => $uid, 'status' => 1])->dec('area_balance', $price)->update();
                    break;
                case 7:
                    if (!empty($mixBalance)) {
                        foreach ($mixBalance as $key => $value) {
                            if (doubleval($value['balance']) > 0) {
                                $res = User::where(['uid' => $uid, 'status' => 1])->dec("$key", $value['balance'])->update();
                            }
                        }
                    }
                    break;
                case 8:
                    User::where(['uid' => $uid, 'status' => 1])->dec('crowd_balance', $price)->update();
                    break;
            }

            if ($transferType == 1) {
                switch ($forType ?? 1) {
                    case 1:
                        User::where(['uid' => $uid, 'status' => 1])->inc('divide_balance', $price)->update();
                        break;
                    case 2:
                        User::where(['uid' => $uid, 'status' => 1])->inc('ppyl_balance', $price)->update();
                        break;
                    case 3:
                        User::where(['uid' => $uid, 'status' => 1])->inc('ad_balance', $price)->update();
                        break;
                    case 4:
                        User::where(['uid' => $uid, 'status' => 1])->inc('team_balance', $price)->update();
                        break;
                    case 5:
                        User::where(['uid' => $uid, 'status' => 1])->inc('shareholder', $price)->update();
                        break;
                    case 6:
                        User::where(['uid' => $uid, 'status' => 1])->inc('area_balance', $price)->update();
                        break;
                }
            } else {
                User::where(['uid' => $uid, 'status' => 1])->inc('crowd_balance', $price)->update();
            }

            $res = false;
            if (!empty($balanceDetail)) {
                if ($fromType == 8) {
                    $crowdDetail[0]['uid'] = $balanceDetail[0]['uid'];
                    $crowdDetail[0]['change_type'] = 5;
                    $crowdDetail[0]['price'] = $balanceDetail[0]['price'];
                    $crowdDetail[0]['type'] = 2;
                    $crowdDetail[0]['transfer_type'] = 2;
                    $crowdDetail[0]['remark'] = '转入商城余额';
                    (new CrowdfundingBalanceDetail())->saveAll($crowdDetail);
                    unset($balanceDetail[0]);
                }
                $res = $this->saveAll(array_values($balanceDetail));
            }
            if (!empty($userCrowdBalanceDetail)) {
                (new CrowdfundingBalanceDetail())->saveAll($userCrowdBalanceDetail);
            }

            return $res;
        });

        return judge($DBRes);
    }

    public function user()
    {
        return $this->hasOne('User', 'uid', 'uid')->bind(['user_name' => 'name']);
    }

    public function divide()
    {
        return $this->hasOne('Divide', 'order_sn', 'order_sn')->where(['status' => [1]])->bind(['order_uid', 'total_price', 'divide_type' => 'type']);
    }

}