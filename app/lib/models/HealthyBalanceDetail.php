<?php
// +----------------------------------------------------------------------
// |[ 文档说明: 健康豆模块Model]
// +----------------------------------------------------------------------
// | Copyright (c) 2018~2024 http://www.mlhcmk.com All rights reserved.
// +----------------------------------------------------------------------


namespace app\lib\models;


use app\BaseModel;
use app\lib\constant\PayConstant;
use app\lib\exceptions\ParamException;
use app\lib\exceptions\ServiceException;
use app\lib\services\CodeBuilder;
use think\facade\Cache;
use think\facade\Db;

class HealthyBalanceDetail extends BaseModel
{
    protected $validateFields = ['uid', 'type', 'price', 'status' => 'number'];

    /**
     * @title  余额明细列表
     * @param array $sear
     * @return array
     * @throws \Exception
     */
    public function list(array $sear): array
    {
        if (!empty($sear['keyword'])) {
            $map[] = ['', 'exp', Db::raw($this->getFuzzySearSql('order_sn', $sear['keyword']))];
        }

        if (!empty($sear['userKeyword'])) {
            $uMap[] = ['', 'exp', Db::raw($this->getFuzzySearSql('name|phone', $sear['userKeyword']))];
            $uids = User::where($uMap)->column('uid');
            if (!empty($uids)) {
                $map[] = ['uid', 'in', $uids];
            }
        }

        $map[] = ['status', 'in', $this->getStatusByRequestModule($sear['searType'] ?? 1)];
        if (!empty($sear['change_type'])) {
            if (is_array($sear['change_type'])) {
                $map[] = ['change_type', 'in', $sear['change_type']];
            } else {
                $map[] = ['change_type', '=', $sear['change_type']];
            }
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
        if (!empty($sear['pageNumber'] ?? null)) {
            $this->pageNumber = intval($sear['pageNumber']);
        }
        if (!empty($page)) {
            $aTotal = $this->where($map)->count();
            $pageTotal = ceil($aTotal / $this->pageNumber);
        }

        $list = $this->with(['userList'])->where($map)->withoutField('id,update_time,belong')->when($page, function ($query) use ($page) {
            $query->page($page, $this->pageNumber);
        })->order('create_time desc,id asc')->select()->toArray();

        if (!empty($list)) {
            $allCrowdActivity = array_unique(array_column($list, 'crowd_code'));
            if (!empty($allCrowdActivity)) {
                $allCrowdActivityName = CrowdfundingActivity::where(['activity_code' => $allCrowdActivity])->column('title', 'activity_code');
                if (!empty($allCrowdActivityName)) {
                    foreach ($list as $key => $value) {
                        if (!empty($value['crowd_code'] ?? null)) {
                            $list[$key]['activity_name'] = $allCrowdActivityName[$value['crowd_code']] ?? '未知福利区';
                        }
                    }
                }
            }
        }

//        if (!empty($list)) {
//            $allOrderUid = array_column($list, 'order_uid');
//            $allOrderUser = User::where(['uid' => $allOrderUid])->column('name', 'uid');
//            foreach ($list as $key => $value) {
//                foreach ($allOrderUser as $k => $v) {
//                    if (!empty($value['order_uid'])) {
//                        if ($k == $value['order_uid']) {
//                            $list[$key]['order_user_name'] = $v;
//                        }
//                    }
//
//                }
//                unset($list[$key]['order_uid']);
//            }
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
        switch ($fromType ?? 1) {
            case 1:
                $changeType = [1, 2, 3, 4, 5, 6, 7, 10, 11, 13, 22, 23, 24];
                $fieldName = 'divide_balance';
                $userNowBalance = $userInfo[$fieldName];
                break;
            case 2:
                $changeType = [8, 9, 25, 26];
                $fieldName = 'ppyl_balance';
                $userNowBalance = $userInfo[$fieldName];
                break;
            case 3:
                $changeType = [12, 15, 27, 28];
                $fieldName = 'ad_balance';
                $userNowBalance = $userInfo[$fieldName];
                break;
            case 4:
                $changeType = [16, 17, 29, 30];
                $fieldName = 'team_balance';
                $userNowBalance = $userInfo[$fieldName];
                break;
            case 5:
                $changeType = [18, 19, 31, 32];
                $fieldName = 'shareholder_balance';
                $userNowBalance = $userInfo[$fieldName];
                break;
            case 6:
                $changeType = [20, 21, 33, 34];
                $fieldName = 'area_balance';
                $userNowBalance = $userInfo[$fieldName];
                break;
            case 7:
                $changeType = [];
                $userNowBalance = ($userInfo['divide_balance'] ?? 0) + ($userInfo['ppyl_balance'] ?? 0) + ($userInfo['ad_balance'] ?? 0) + ($userInfo['team_balance'] ?? 0) + ($userInfo['shareholder_balance'] ?? 0) + ($userInfo['area_balance'] ?? 0);
                break;
            case 8:
                //美丽金支付
                $changeType = [];
                $userNowBalance = $userInfo['crowd_balance'] ?? 0;
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
                                if ($totalBalance + $value['balance'] < $data['price']) {
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

    /**
     * @title  后台系统充值健康豆
     * @param array $data
     * @return bool
     */
    public function rechargeHealthyBalance(array $data)
    {
        $allUser = $data['all_user'];
        $type = $data['type'] ?? 1;
        $userPhone = array_unique(array_column($allUser, 'user_phone'));
        $userList = User::where(['phone' => $userPhone, 'status' => 1])->column('uid', 'phone');
        if (empty($userList)) {
            throw new ServiceException(['msg' => '查无有效用户']);
        }
        if (isset($data['channel_type']) && in_array($data['channel_type'], PayConstant::HEALTHY_CHANNEL_TYPE)) {
            $channelType = $data['channel_type'];
        } else {
            throw new ServiceException(['msg' => '非法健康豆渠道']);
        }

        foreach ($userPhone as $key => $value) {
            if (!in_array(trim($value), array_keys($userList))) {
                throw new ServiceException(['msg' => '手机号码' . $value . '不存在平台, 请仔细检查!']);
            }
        }

        $DBRes = DB::transaction(function () use ($allUser, $userList, $type, $data, $channelType) {
            $res = false;
            $number = 0;
            $healthyDetail = [];
            $CodeBuilder = (new CodeBuilder());
            foreach ($allUser as $key => $value) {
                if (priceFormat($value['price']) > 1000000) {
                    throw new ServiceException(['msg' => '单次充值不能超过1000000']);
                }

                if (!empty($userList[$value['user_phone']] ?? null)) {
                    $remarkMsg = null;
                    $healthyDetail[$number]['order_sn'] = $CodeBuilder->buildSystemRechargeHealthySn();
                    $healthyDetail[$number]['uid'] = $userList[$value['user_phone']];
                    $healthyDetail[$number]['type'] = 1;
                    if (priceFormat($value['price']) < 0) {
                        $healthyDetail[$number]['type'] = 2;
                    }
                    $healthyDetail[$number]['price'] = priceFormat($value['price']);
                    $healthyDetail[$number]['change_type'] = 1;
                    $healthyDetail[$number]['healthy_channel_type'] = $channelType;
                    $remarkMsg = '后台系统充值';
                    if ($healthyDetail[$number]['type'] == 2) {
                        $remarkMsg = '后台系统扣除';
                    }
                    if (!empty($value['remark'])) {
                        $remarkMsg .= trim($value['remark']);
                    }
                    $healthyDetail[$number]['remark'] = $remarkMsg;
                    $number += 1;
                }
            }

            if (!empty($healthyDetail ?? [])) {
                $healthyDetail = array_values($healthyDetail);
                $userGiftHealthy = [];
                $needUpdateGiftHealthyUser = [];

                foreach ($healthyDetail as $key => $value) {
                    //统计所有明细每个人用户得到的健康豆, 插入健康豆渠道表
                    if (!isset($userGiftHealthy[$value['uid']])) {
                        $userGiftHealthy[$value['uid']] = 0;
                    }
                    $userGiftHealthy[$value['uid']] += $value['price'];
                }

                //批量用户表总健康豆余额自增
                $batchSqlHealthyData['list'] = $healthyDetail;
                $batchSqlHealthyData['db_name'] = 'sp_user';
                $batchSqlHealthyData['id_field'] = 'uid';
                $batchSqlHealthyData['operate_field'] = 'healthy_balance';
                $batchSqlHealthyData['value_field'] = 'price';
                $batchSqlHealthyData['operate_type'] = 'inc';
                $batchSqlHealthyData['sear_type'] = 1;
                $batchSqlHealthyData['other_map'] = 'status = 1';
                $res = (new CommonModel())->DBBatchIncOrDec($batchSqlHealthyData);

                //批量新增健康豆明细
                $batchSqlHealthyDetailData['list'] = $healthyDetail;
                $batchSqlHealthyDetailData['db_name'] = 'sp_healthy_balance_detail';
                $batchSqlHealthyDetailData['sear_type'] = 1;
                $batchSqlHealthyDetailData['notValidateValueField'] = ['uid'];
                (new CommonModel())->DBSaveAll($batchSqlHealthyDetailData);

                //添加或新增健康豆渠道冗余表
                //查询每个人在健康豆福利渠道是否存在数据, 如果不存在则新增, 存在则自增
                $existHealthyChannel = HealthyBalance::where(['uid' => array_keys($userGiftHealthy), 'channel_type' => ($channelType ?? 2), 'status' => 1])->column('uid');
                foreach ($userGiftHealthy as $key => $value) {
                    if (in_array($key, $existHealthyChannel)) {
                        $needUpdateGiftHealthyUser[$key] = $value;
                    } else {
                        $newGiftHealthyUser[$key] = $value;
                    }
                }

//                if (!empty($needUpdateGiftHealthyUser ?? [])) {
//                    foreach ($needUpdateGiftHealthyUser as $key => $value) {
//                        if (doubleval($value) <= 0) {
//                            unset($needUpdateGiftHealthyUser[$key]);
//                        }
//                    }
//                }
                if (!empty($needUpdateGiftHealthyUser ?? [])) {
                    //健康豆冗余表批量自增
                    $batchSqlHealthyBalanceData['list'] = $needUpdateGiftHealthyUser;
                    $batchSqlHealthyBalanceData['db_name'] = 'sp_healthy_balance';
                    $batchSqlHealthyBalanceData['id_field'] = 'uid';
                    $batchSqlHealthyBalanceData['operate_field'] = 'balance';
                    $batchSqlHealthyBalanceData['operate_type'] = 'inc';
                    $batchSqlHealthyBalanceData['sear_type'] = 1;
                    $batchSqlHealthyBalanceData['other_map'] = 'status = 1 and channel_type = ' . ($channelType ?? 2);
                    (new CommonModel())->DBBatchIncOrDec($batchSqlHealthyBalanceData);
                }

                //添加健康豆渠道冗余表明细
                if (!empty($newGiftHealthyUser ?? [])) {
                    foreach ($newGiftHealthyUser as $key => $value) {
                        $newGiftHealthyData[$key]['uid'] = $key;
                        $newGiftHealthyData[$key]['balance'] = $value;
                        $newGiftHealthyData[$key]['channel_type'] = ($channelType ?? 2);
                        $newGiftHealthyData[$key]['status'] = 1;
                    }
                    if (!empty($newGiftHealthyData ?? [])) {
                        $batchSqlHealthyBalanceNewData['list'] = array_values($newGiftHealthyData);
                        $batchSqlHealthyBalanceNewData['db_name'] = 'sp_healthy_balance';
                        $batchSqlHealthyBalanceNewData['sear_type'] = 1;
                        $batchSqlHealthyBalanceNewData['auto_fill_status'] = true;
                        $batchSqlHealthyBalanceNewData['notValidateValueField'] = ['uid'];
                        (new CommonModel())->DBSaveAll($batchSqlHealthyBalanceNewData);
                    }
                }
            }
            return $res;
        });
        return judge($DBRes);
    }

    /**
     * @title 批量自增/自减-原生sql
     * @param array $data
     * @return mixed
     */
    public function DBBatchIncOrDecBySql(array $data)
    {
        try {
            $DBRes = Db::transaction(function () use ($data) {
                $res = $this->batchIncOrDecBySql($data);
                return $res;
            });
        } catch (\app\lib\BaseException $baseE) {
            $msg = $baseE->msg;
            $code = $baseE->errorCode;
            $DBRes = false;
        } catch (\Exception $e) {
            $msg = $e->getMessage();
            $code = $e->getCode();
            $DBRes = false;
        }
        return judge($DBRes);
    }

    /**
     * @title 批量新增-原生sql
     * @param array $data
     * @return mixed
     */
    public function DBSaveAll(array $data)
    {
        try {
            $DBRes = Db::transaction(function () use ($data) {
                $data['notValidateValueField'] = ['uid'];
                $res = $this->batchCreateBySql($data);
                return $res;
            });
        } catch (\app\lib\BaseException $baseE) {
            $msg = $baseE->msg;
            $code = $baseE->errorCode;
            $DBRes = false;
        } catch (\Exception $e) {
            $msg = $e->getMessage();
            $code = $e->getCode();
            $DBRes = false;
        }
        return judge($DBRes);
    }

    public function user()
    {
        return $this->hasOne('User', 'uid', 'uid')->bind(['user_name' => 'name']);
    }

    public function userList()
    {
        return $this->hasOne('User', 'uid', 'uid')->bind(['user_name' => 'name','user_phone' => 'phone']);
    }

    public function divide()
    {
        return $this->hasOne('Divide', 'order_sn', 'order_sn')->where(['status' => [1]])->bind(['order_uid', 'total_price', 'divide_type' => 'type']);
    }

}