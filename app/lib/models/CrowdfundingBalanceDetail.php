<?php
// +----------------------------------------------------------------------
// |[ 文档说明: 众筹钱包明细模块Model]
// +----------------------------------------------------------------------



namespace app\lib\models;


use app\BaseModel;
use app\lib\exceptions\FinanceException;
use app\lib\exceptions\ServiceException;
use app\lib\exceptions\UserException;
use app\lib\services\Summary;
use mysql_xdevapi\Exception;
use think\facade\Cache;
use think\facade\Db;
use think\facade\Queue;

class CrowdfundingBalanceDetail extends BaseModel
{
    /**
     * @title  余额明细列表
     * @param array $sear
     * @return array
     * @throws \Exception
     */
    public function list(array $sear): array
    {
        if (!empty($sear['keyword'])) {
            $map[] = ['', 'exp', Db::raw($this->getFuzzySearSql('d.phone|d.name|a.order_sn|a.pay_no|a.price', $sear['keyword']))];
        }
        $map[] = ['a.status', 'in', $this->getStatusByRequestModule($sear['searType'] ?? 1)];
        if (!empty($sear['change_type'])) {
            if (is_array($sear['change_type'])) {
                $map[] = ['a.change_type', 'in', $sear['change_type']];
            } else {
                $map[] = ['a.change_type', '=', $sear['change_type']];
            }
        }
        if (!empty($sear['notSearId'])) {
            $map[] = ['a.id', 'not in', $sear['notSearId']];
        }
        if (!empty($sear['type'])) {
            $map[] = ['a.type', '=', $sear['type']];
        }
        if (!empty($sear['transfer_type'] ?? null)) {
            if ($sear['transfer_type'] == 1) {
                $map[] = ['', 'exp', Db::raw('a.transfer_type is not null')];
            } elseif ($sear['transfer_type'] == 2) {
                $map[] = ['a.change_type', '=', 1];
            }
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
        if (!empty($sear['pageNumber'] ?? null)) {
            $this->pageNumber = intval($sear['pageNumber'] ?? 0);
        }
        if (!empty($page)) {
            $aTotal = $this->alias('a')
                ->join('sp_user d','a.uid = d.uid','left')->where($map)->count();
            $pageTotal = ceil($aTotal / $this->pageNumber);
        }
        if ($this->module == 'api') {
            $list = $this->alias('a')
                ->join('sp_user d', 'a.uid = d.uid', 'left')
//            ->join('sp_divide b','a.order_sn = b.order_sn and a.uid = b.link_uid','left')
                ->join('sp_crowdfunding_activity c', 'a.crowd_code = c.activity_code', 'left')
                ->field('a.*,c.title as activity_name,d.name as user_name,d.phone as user_phone,d.avatarUrl')->where($map)->when($page, function ($query) use ($page) {
                    $query->page($page, $this->pageNumber);
                })->order('a.create_time desc,a.id asc')->select()->toArray();
        } else {
            $list = $this->alias('a')
                ->join('sp_user d', 'a.uid = d.uid', 'left')
                ->field('a.id,a.uid,a.order_sn,a.type,a.price,a.change_type,a.status,a.create_time,a.pay_type,a.remark,d.name as user_name,d.phone as user_phone,d.avatarUrl,a.crowd_code,a.crowd_round_number,a.crowd_period_number,a.transfer_for_real_uid,a.transfer_from_real_uid')->where($map)->when($page, function ($query) use ($page) {
                    $query->page($page, $this->pageNumber);
                })->order('a.create_time desc,a.id asc')->select()->toArray();
        }

        if (!empty($list)) {

//            $allOrderUid = array_column($list, 'order_uid');
//            $allOrderUser = User::where(['uid' => $allOrderUid])->column('name', 'uid');
//            if (!empty($allOrderUid)) {
//                foreach ($list as $key => $value) {
//                    foreach ($allOrderUser as $k => $v) {
//                        if (!empty($value['order_uid'])) {
//                            if ($k == $value['order_uid']) {
//                                $list[$key]['order_user_name'] = $v;
//                            }
//                        }
//
//                    }
//                    unset($list[$key]['order_uid']);
//                }
//            }

            if ($this->module == 'api') {
                $allForUid = array_column($list, 'transfer_for_real_uid');
                $allFromUid = array_column($list, 'transfer_from_real_uid');
                if (!empty($allForUid) && !empty($allFromUid)) {
                    $allTUser = array_unique(array_merge_recursive($allForUid, $allFromUid));
                } elseif (!empty($allForUid) && empty($allFromUid)) {
                    $allTUser = $allForUid;
                } else {
                    $allTUser = $allFromUid;
                }

                if (!empty($allTUser)) {
                    $allTransferUserList = User::where(['uid' => $allTUser, 'status' => 1])->field('uid,name,phone')->select()->toArray();
                    if (!empty($allTransferUserList)) {
                        foreach ($allTransferUserList as $key => $value) {
                            $allTransferUser[$value['uid']] = $value;
                        }
                        foreach ($list as $key => $value) {
                            foreach ($allTransferUser as $k => $v) {
                                if (!empty($value['transfer_for_real_uid'])) {
                                    if ($k == $value['transfer_for_real_uid']) {
                                        $list[$key]['transfer_for_user_name'] = $v['name'];
                                        $list[$key]['transfer_for_user_phone'] = $v['phone'];
                                    }
                                }
                                if (!empty($value['transfer_from_real_uid'])) {
                                    if ($k == $value['transfer_from_real_uid']) {
                                        $list[$key]['transfer_from_user_name'] = $v['name'];
                                        $list[$key]['transfer_from_user_phone'] = $v['phone'];
                                    }
                                }
                                unset($list[$key]['transfer_for_uid']);
                                unset($list[$key]['transfer_from_uid']);
                            }
                        }
                    }
                }
            }
            if ($this->module == 'admin') {
                //展示区
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

            //展示众筹订单超前提前购(预售), 奖励延迟发放的信息
            $drWhere[] = ['order_sn', 'in', array_unique(array_column($list, 'order_sn'))];
            $drWhere[] = ['status', '=', 1];
            $delayRewardOrder = CrowdfundingDelayRewardOrder::where($drWhere)->withoutField('id')->select()->toArray();
            $periodInfo = [];
            if (!empty($delayRewardOrder ?? [])) {
                foreach ($delayRewardOrder as $key => $value) {
                    $delayRewardOrderInfo[$value['order_sn']] = $value;
                    $delayRewardOrderSn[] = $value['order_sn'];
                    $crowdKey = $value['crowd_code'] . '-' . $value['crowd_round_number'] . '-' . $value['crowd_period_number'];
                    $delayRewardCrowd[$crowdKey]['activity_code'] = $value['crowd_code'];
                    $delayRewardCrowd[$crowdKey]['round_number'] = $value['crowd_round_number'];
                    $delayRewardCrowd[$crowdKey]['period_number'] = $value['crowd_period_number'];
                }
                //查询对应的期详情, 如果期没有完全成功, 不展示奖励预计发放时间
                if (!empty($delayRewardCrowd ?? [])) {
                    $successPeriod = array_values($delayRewardCrowd);
                    $oWhere[] = ['status', '=', 1];
                    $oWhere[] = ['result_status', '=', 1];
                    $periodList = CrowdfundingPeriod::where(function ($query) use ($successPeriod) {
                        $successPeriod = array_values($successPeriod);
                        foreach ($successPeriod as $key => $value) {
                            ${'where' . ($key + 1)}[] = ['activity_code', '=', $value['activity_code']];
                            ${'where' . ($key + 1)}[] = ['round_number', '=', $value['round_number']];
                            ${'where' . ($key + 1)}[] = ['period_number', '=', $value['period_number']];
                        }
                        for ($i = 0; $i < count($successPeriod); $i++) {
                            $allWhereOr[] = ${'where' . ($i + 1)};
                        }
                        $query->whereOr($allWhereOr);
                    })->where($oWhere)->field('activity_code,round_number,period_number,result_status,buy_status')->select()->toArray();
                    if (!empty($periodList ?? [])) {
                        foreach ($periodList as $key => $value) {
                            $pCrowdKey = $value['activity_code'] . '-' . $value['round_number'] . '-' . $value['period_number'];
                            $periodInfo[$pCrowdKey] = $pCrowdKey;
                        }
                    }
                }

                foreach ($list as $key => $value) {
                    $list[$key]['delay_reward_status'] = -2;
                    $list[$key]['delay_arrival_time'] = null;
                    if ($value['type'] == 2 && in_array($value['order_sn'], $delayRewardOrderSn)) {
                        $list[$key]['delay_reward_status'] = $delayRewardOrderInfo[$value['order_sn']]['arrival_status'];
                        $lCrowdKey = $value['crowd_code'] . '-' . $value['crowd_round_number'] . '-' . $value['crowd_period_number'];
                        if (!empty($periodInfo[$lCrowdKey] ?? null)) {
                            $list[$key]['delay_arrival_time'] = timeToDateFormat($delayRewardOrderInfo[$value['order_sn']]['arrival_time'] ?? null);
                        }
                    }
                }
            }
        }

        return ['list' => $list, 'pageTotal' => $pageTotal ?? 0];
    }

    /**
     * @title  新建余额明细
     * @param array $data
     * @return mixed
     */
    public function new(array $data)
    {
        return $this->baseCreate($data, true);
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
     * @title  获取用户收益面板数据
     * @param array $data
     * @return array
     */
    public function getUserIncomeDetail(array $data)
    {
        $uid = $data['uid'];
        if (empty($uid)) {
            throw new UserException();
        }
        //总余额
        $userBalance = User::where(['uid' => $uid])->value('crowd_balance');

        $map[] = ['uid', '=', $uid];
        $map[] = ['status', '=', 1];
        $map[] = ['change_type', 'in', [3, 4]];

        //今日收益
        $todayStartTime = strtotime(date('Y-m-d', time()) . ' 00:00:00');
        $todayEndTime = strtotime(date('Y-m-d', time()) . ' 23:59:59');
        $todayMap = $map;
        $todayMap[] = ['create_time', '>=', $todayStartTime];
        $todayMap[] = ['create_time', '<=', $todayEndTime];
        $todayIncome = self::where($todayMap)->sum('price');

        //昨日收益
        $yesterdayStartTime = strtotime(date('Y-m-d', strtotime("-1 day")) . ' 00:00:00');
        $yesterdayEndTime = strtotime(date('Y-m-d', strtotime("-1 day")) . ' 23:59:59');
        $yesterdayMap = $map;
        $yesterdayMap[] = ['create_time', '>=', $yesterdayStartTime];
        $yesterdayMap[] = ['create_time', '<=', $yesterdayEndTime];
        $yesterdayIncome = self::where($yesterdayMap)->sum('price');

        //累计收益
        $allMap = $map;
        $allIncome = self::where($allMap)->sum('price');

        //冻结美丽金总数
        $fuseBalance = CrowdfundingFuseRecord::where(['uid' => $data['uid'], 'status' => 1])->sum('last_total_price');

        return [
            'userBalance' => $userBalance ?? 0,
            'userFuseBalance' => priceFormat($fuseBalance ?? 0),
            'todayIncome' => priceFormat($todayIncome ?? 0),
            'yesterdayIncome' => priceFormat($yesterdayIncome ?? 0),
            'allIncome' => priceFormat($allIncome ?? 0)
        ];
    }

    /**
     * @title  后台系统充值美丽金
     * @param array $data
     * @return bool
     */
    public function rechargeCrowdBalance(array $data)
    {
        $allUser = $data['allUser'];
        $type = $data['type'] ?? 1;
        //$performanceType 业绩类型 1为需要计入业绩和后续累计充值 2为不计入业绩也不计入后续统计的累计充值 默认1
        $performanceType = $data['performance_type'] ?? 1;
        //本次充值赠送东西类型 1为美丽豆  2为健康豆 -1为不赠送 默认-1
        $giftType = $data['gift_type'] ?? -1;
        //healthy_channel_type 如果赠送的是健康豆, 需要选择赠送的健康豆渠道 -1默认为不赠送
        $healthyChannelType = $data['healthy_channel_type'] ?? -1;
        if ($giftType == 2 && $healthyChannelType == -1) {
            throw new ServiceException(['msg' => '请选择有效的健康豆渠道']);
        }
//        //本次充值是否赠送美丽豆 1为是 2为否 默认否, 如果选是还需要一并判断系统设置中是否允许赠送, 系统允许才可以赠送美丽豆
//        $giftIntegral = $data['gift_integral'] ?? 2;
        $userPhone = array_unique(array_column($allUser, 'userPhone'));
        $userList = User::where(['phone' => $userPhone, 'status' => 1])->column('uid', 'phone');
        if (empty($userList)) {
            throw new ServiceException(['msg' => '查无有效用户']);
        }
        foreach ($userPhone as $key => $value) {
            if (!in_array(trim($value), array_keys($userList))) {
                throw new ServiceException(['msg' => '手机号码' . $value . '不存在平台, 请仔细检查!']);
            }
        }
        $DBRes = DB::transaction(function () use ($allUser, $userList, $type, $data, $performanceType, $giftType, $healthyChannelType) {
            $res = false;
            $number = 0;
            foreach ($allUser as $key => $value) {
                if (!empty($userList[$value['userPhone']] ?? null)) {
                    $remarkMsg = null;
                    if ($type == 1) {
                        $balanceNew[$number]['order_sn'] = 'SCZ' . date('Ymd') . substr(time(), -2, 2) . substr(microtime(), 2, 4) . sprintf('%04d', rand(1, 9999));
                    } else {
                        if (!empty($data['recharge_sn'] ?? null)) {
                            $balanceNew[$number]['order_sn'] = $data['recharge_sn'];
                        } else {
                            $balanceNew[$number]['order_sn'] = 'OCZ' . date('Ymd') . substr(time(), -2, 2) . substr(microtime(), 2, 4) . sprintf('%04d', rand(1, 9999));
                        }
                    }
                    $balanceNew[$number]['uid'] = $userList[$value['userPhone']];
                    $balanceNew[$number]['type'] = 1;
                    $balanceNew[$number]['price'] = priceFormat($value['price']);
                    $balanceNew[$number]['change_type'] = 1;
                    //如果不计入业绩则变更类型为12, 防止后续统计累计充值出错
                    if ($performanceType == 2) {
                        $balanceNew[$number]['change_type'] = 12;
                    }
                    $remarkMsg = '后台系统充值';
                    if (!empty($value['remark'])) {
                        $remarkMsg .= trim($value['remark']);
                    }
                    if ($type == 1) {
                        $balanceNew[$number]['remark'] = $remarkMsg;
                    } else {
                        $balanceNew[$number]['remark'] = '用户自主提交线下付款申请后审核通过充值';
                    }

                    $number += 1;
                    User::where(['uid' => $userList[$value['userPhone']]])->inc('crowd_balance', $balanceNew[$key]['price'])->update();
                }
            }
            if (!empty($balanceNew)) {
                $res = self::saveAll($balanceNew);

                //将充值的订单推入队列计算团队上级充值业绩冗余明细
                if (config('system.recordRechargeDetail') == 1) {
                    foreach ($balanceNew as $key => $value) {
                        if (!empty($value['order_sn'] ?? null) && $value['change_type'] != 12) {
                            $upgradeQueue = Queue::push('app\lib\job\TeamMemberUpgrade', ['order_sn' => $value['order_sn'], 'type' => 5], config('system.queueAbbr') . 'TeamMemberUpgrade');
                        }
                    }
                }

                //将充值的订单推入队列计算直推上级充值业绩冗余明细
                foreach ($balanceNew as $key => $value) {
                    if (!empty($value['order_sn'] ?? null) && $value['change_type'] != 12) {
                        Queue::push('app\lib\job\TeamMemberUpgrade', ['order_sn' => $value['order_sn'], 'type' => 6], config('system.queueAbbr') . 'TeamMemberUpgrade');
                    }
                }
                //若需要赠送东西则添加明细
                if ($giftType != -1) {
                    switch ($giftType) {
                        case 1:
                            foreach ($balanceNew as $key => $value) {
                                $aGiftIntegral[$key]['order_sn'] = $value['order_sn'];
                                $aGiftIntegral[$key]['integral'] = $value['price'];
                                $aGiftIntegral[$key]['type'] = 1;
                                $aGiftIntegral[$key]['change_type'] = 7;
                                $aGiftIntegral[$key]['remark'] = ($value['remark'] ?? '') . '充值美丽金赠送';
                                $aGiftIntegral[$key]['uid'] = $value['uid'];
                                $aGiftIntegral[$key]['create_time'] = time();
                                $aGiftIntegral[$key]['update_time'] = time();
                            }
                            $res = $this->rechargeGiftSend(['balance_detail' => $aGiftIntegral, 'gift_type' => 1]);
                            break;
                        case 2:
                            foreach ($balanceNew as $key => $value) {
                                $healthyDetail[$key]['order_sn'] = $value['order_sn'];
                                $healthyDetail[$key]['uid'] = $value['uid'];
                                $healthyDetail[$key]['change_type'] = 7;
                                $healthyDetail[$key]['remark'] = ($value['remark'] ?? '') . '充值美丽金赠送';
                                $healthyDetail[$key]['price'] = $value['price'];
                                $healthyDetail[$key]['pay_type'] = 77;
                                $healthyDetail[$key]['create_time'] = strtotime($value['create_time'] ?? time());
                                $healthyDetail[$key]['update_time'] = strtotime($value['update_time'] ?? time());
                            }
                            $res = $this->rechargeGiftSend(['balance_detail' => $healthyDetail, 'gift_type' => 2, 'healthy_channel_type' => $healthyChannelType]);
                            break;
                        default:
                            throw new ServiceException(['msg' => '暂不支持的赠送渠道']);
                    }
                }
            }
            //如果是审核线下提交的表单则自动修改该提交记录为已发放
            if ($type == 2 && !empty($data['recharge_sn'] ?? null)) {
                OfflineRecharge::update(['arrival_status' => 1, 'arrival_time' => time()], ['recharge_sn' => $data['recharge_sn'], 'status' => 1, 'check_status' => 1, 'arrival_status' => 3]);
            }
            return $res;
        });
        return judge($DBRes);
    }

    /**
     * @title  美丽金转让
     * @param array $data
     * @return bool
     */
    public function transfer(array $data)
    {
        //转让类型 1为转给其他用户(美丽金)  2为转给商城余额
        $transferType = $data['transfer_type'] ?? 1;
        if (empty($data['uid'] ?? null) || empty($data['price'] ?? null)) {
            throw new ServiceException(['msg' => '非法请求']);
        }
        if ($transferType == 1) {
            if (empty($data['for_phone'] ?? null)) {
                throw new ServiceException(['msg' => '非法请求']);
            }
        }
        $uid = $data['uid'];
        $data['for_uid'] = User::where(['phone' => $data['for_phone']])->value('uid');
        $forUid = $data['for_uid'] ?? null;
        $price = $data['price'];
        $totalPrice = $price;
        //财务号
        $financeUserInfo = SystemConfig::where(['id' => 1])->field('finance_uid,transfer_finance_uid,transfer_finance_phone')->findOrEmpty();
        if (empty($financeUserInfo['finance_uid'] ?? null) || empty($financeUserInfo['transfer_finance_uid'] ?? null) || empty($financeUserInfo['transfer_finance_phone'] ?? null)) {
            throw new FinanceException(['msg' => '财务模块缺少配置, 暂无法使用转赠功能']);
        }
        $financeUid = $financeUserInfo['finance_uid'] ?? null;
        $withdrawFinanceAccount = !empty($financeUid) ? explode(',', $financeUid) : ['notFinanceUid'];
//        $withdrawFinanceAccount = ['WbzBXu6Bhy', '8NhXjx7TGr', '08bHybNaW1', 'npuh1syedM', '8q3Mn4dWDb', '32Po7PAmOq', 'bnNtLgLO0l', 'SD55DQSPPr'];

        $userInfo = User::where(['uid' => $uid, 'status' => 1])->findOrEmpty()->toArray();
        if (empty($userInfo)) {
            throw new ServiceException(['msg' => '非法用户']);
        }
        if ($userInfo['ban_crowd_transfer'] == 1) {
            throw new ServiceException(['msg' => '暂无法使用转赠功能']);
        }
        $forUserInfo = User::where(['uid' => $forUid, 'status' => 1])->field('uid,phone,name,link_superior_user')->findOrEmpty()->toArray();
        if (empty($forUserInfo)) {
            throw new ServiceException(['msg' => '非法用户']);
        }

        //转赠的时候需要判断用户是否被限制
        if ($transferType == 1) {
            (new UserBalanceLimit())->checkUserBalanceLimit(['uid' => $data['uid'], 'price' => $price, 'oper_type' => 2, 'balance_type' => 6]);
        }

        if (!empty($forUserInfo['link_superior_user']) && $forUserInfo['link_superior_user'] != $uid) {
            //非白名单用户仅允许转给直推用户
            $isWhiteUserCanTransferEveryone = BawList::where(['uid' => $uid, 'channel' => 4, 'type' => 1, 'status' => 1])->value('id');
            if (empty($isWhiteUserCanTransferEveryone)) {
                //允许转给财务号
                if (!in_array($forUid, $withdrawFinanceAccount)) {
                    throw new ServiceException(['msg' => '仅允许转赠给直推用户']);
                }
            }
        }
        if (empty($userInfo['pay_pwd'])) {
            throw new ServiceException(['msg' => '尚未设置支付密码, 请您先设置支付密码!']);
        }
        if(empty($data['pay_pwd'])){
            throw new ServiceException(['msg' => '风控拦截!请勿继续操作!']);
        }
        if ($data['pay_pwd'] != $userInfo['pay_pwd'] && md5($data['pay_pwd']) != $userInfo['pay_pwd']) {
            $cacheKey = 'payPwdError-' . $data['uid'];
            $errorLimitNumber = 5;
            $nowCache = cache($cacheKey);
            if (!empty($nowCache)) {
                if ($nowCache >= $errorLimitNumber) {
                    $lastNumber = 0;
                    throw new ServiceException(['msg' => '支付操作当日已被冻结!']);
                } else {
                    $lastNumber = $errorLimitNumber - $nowCache;
                    Cache::inc($cacheKey);
                }
            } else {
                cache($cacheKey, 1, (24 * 3600));
                $lastNumber = $errorLimitNumber - 1;
            }
            throw new ServiceException(['msg' => '支付密码错误,请重试。今日剩余次数 ' . $lastNumber]);
        }

        $userAllBalance = self::where(['uid' => $uid, 'status' => 1])->sum('price');

        if (doubleval($userAllBalance) <= 0 || $userAllBalance < $price || (($userInfo['crowd_balance'] ?? 0) < $price) || (($userInfo['crowd_balance'] ?? 0) <= 0)) {
            throw new ServiceException(['msg' => '用户余额不足!']);
        }

        $transferTaxationPrice = 0;
        //判断是否需要手续转赠税点
        $transferTaxationScale = CrowdfundingSystemConfig::where(['id' => 1])->value('transfer_taxation_scale');
        if (doubleval($transferTaxationScale) > 0) {
            //非白名单用户需要增加手续费
            $isWhiteUser = BawList::where(['uid' => $uid, 'channel' => 3, 'type' => 1, 'status' => 1])->value('id');
            if (empty($isWhiteUser)) {
                $transferTaxationPrice = priceFormat($price * $transferTaxationScale);
                $totalPrice += $transferTaxationPrice;
                if (doubleval($userAllBalance) <= 0 || $userAllBalance < $totalPrice || (($userInfo['crowd_balance'] ?? 0) < $totalPrice) || (($userInfo['crowd_balance'] ?? 0) <= 0)) {
                    throw new ServiceException(['msg' => '余额转出需要额外支付' . intval($transferTaxationScale * 100) . '%手续费, 您当前余额不足以够扣除手续费, 请减少额度']);
                }
            }
        }
        //判断用户剩余可操作余额
        $checkUserCanWithdrawTotalPrice = (new Summary())->userWithdrawDataPanel(['uid' => $data['uid']]);
        if (!empty($checkUserCanWithdrawTotalPrice) && ((string)($checkUserCanWithdrawTotalPrice['canWithdrawPrice'] ?? 0) <= 0 || (string)($checkUserCanWithdrawTotalPrice['canWithdrawPrice'] ?? 0) < (string)$totalPrice)) {
            throw new FinanceException(['msg' => '过往操作金额已达到上限']);
        }


//        $userTransferTotalPrice = self::where(['uid' => $uid, 'status' => 1, 'change_type' => 7])->sum('price');
//        if (doubleval($userTransferTotalPrice) >= 50000) {
//            throw new ServiceException(['msg' => '转增美丽金已到达最大限额!']);
//        }

        $DBRes = Db::transaction(function () use ($data, $uid, $forUserInfo, $price, $forUid, $transferType,$userInfo,$transferTaxationPrice,$totalPrice,$withdrawFinanceAccount, $financeUserInfo) {
            $forFinance = false;
            if (in_array($forUid, $withdrawFinanceAccount) || in_array($uid, $withdrawFinanceAccount)) {
                $forFinance = true;
            }

            $fOrderSn = null;
            $financeUser = $financeUserInfo['transfer_finance_uid'];
            $financeUserPhone = $financeUserInfo['transfer_finance_phone'];

            $userBalanceDetail[0]['uid'] = $uid;
            $userBalanceDetail[0]['type'] = 2;
            $userBalanceDetail[0]['price'] = '-' . $totalPrice;
            $userBalanceDetail[0]['change_type'] = 7;
            $userBalanceDetail[0]['transfer_type'] = 1;
            if (!empty($forFinance)) {
                $userBalanceDetail[0]['transfer_for_uid'] = $forUid;
                $userBalanceDetail[0]['transfer_for_user_phone'] = $forUserInfo['phone'] ?? null;
            } else {
                $userBalanceDetail[0]['transfer_for_uid'] = $financeUser;
                $userBalanceDetail[0]['transfer_for_user_phone'] = $financeUserPhone;
            }
            $userBalanceDetail[0]['transfer_from_uid'] = $uid;
            $userBalanceDetail[0]['transfer_from_real_uid'] = $uid;
            $userBalanceDetail[0]['transfer_for_real_uid'] = $forUid;
            $userBalanceDetail[0]['remark'] = '给用户' . $forUserInfo['name'] . '-' . $forUserInfo['phone'] . '转赠美丽金';
            if (doubleval($transferTaxationPrice ?? 0) > 0) {
                $userBalanceDetail[0]['taxation_price'] = $transferTaxationPrice;
            }

            //美丽金转赠需要经过财务号转一手,以便后续统计用户的转赠额度
            $fOrderSn = 'UT' . date('Ymd') . substr(time(), -2, 2) . substr(microtime(), 2, 4) . sprintf('%04d', rand(1, 9999));
            if(empty($forFinance)){
                $userBalanceDetail[1]['order_sn'] = $fOrderSn.'F';
                $userBalanceDetail[1]['uid'] = $financeUser;
                $userBalanceDetail[1]['type'] = 1;
                $userBalanceDetail[1]['price'] = $price;
                $userBalanceDetail[1]['change_type'] = 9;
                $userBalanceDetail[1]['transfer_type'] = 1;
                $userBalanceDetail[1]['transfer_for_uid'] = $financeUser;
                $userBalanceDetail[1]['transfer_for_real_uid'] = $forUid;
                $userBalanceDetail[1]['transfer_for_user_phone'] = $financeUserPhone;
                $userBalanceDetail[1]['transfer_from_uid'] = $uid;
                $userBalanceDetail[1]['transfer_from_real_uid'] = $uid;
                $userBalanceDetail[1]['is_transfer'] = 1;
                $userBalanceDetail[1]['remark'] = '用户 ' . $userInfo['name'] . '-' . $userInfo['phone'] . '给用户 ' . $forUserInfo['name'] . '-' . $forUserInfo['phone'] . '转赠美丽金-财务' . $financeUserPhone . '经转入';
                $userBalanceDetail[1]['is_finance'] = 1;

                $userBalanceDetail[2]['uid'] = $financeUser;
                $userBalanceDetail[2]['type'] = 2;
                $userBalanceDetail[2]['price'] = '-' . $price;
                $userBalanceDetail[2]['change_type'] = 7;
                $userBalanceDetail[2]['transfer_type'] = 1;
                $userBalanceDetail[2]['transfer_for_uid'] = $forUid;
                $userBalanceDetail[2]['transfer_for_user_phone'] = $forUserInfo['phone'] ?? null;
                $userBalanceDetail[2]['transfer_from_uid'] = $financeUser;
                $userBalanceDetail[2]['transfer_from_real_uid'] = $uid;
                $userBalanceDetail[2]['transfer_for_real_uid'] = $forUid;
                $userBalanceDetail[2]['remark'] = '用户 ' . $userInfo['name'] . '-' . $userInfo['phone'] . '给用户 ' . $forUserInfo['name'] . '-' . $forUserInfo['phone'] . '转赠美丽金-财务' . $financeUserPhone . '经转出';
                $userBalanceDetail[2]['is_finance'] = 1;
            }

            if ($transferType == 1) {
                if (empty($forFinance)) {
                    $userBalanceDetail[3]['order_sn'] = $fOrderSn;
                    $userBalanceDetail[3]['uid'] = $forUid;
                    $userBalanceDetail[3]['type'] = 1;
                    $userBalanceDetail[3]['price'] = $price;
                    $userBalanceDetail[3]['change_type'] = 9;
                    $userBalanceDetail[3]['transfer_type'] = 1;
                    $userBalanceDetail[3]['transfer_for_uid'] = $forUid;
                    $userBalanceDetail[3]['transfer_for_user_phone'] = $forUserInfo['phone'] ?? null;
                    $userBalanceDetail[3]['transfer_from_uid'] = $financeUser;
                    $userBalanceDetail[3]['transfer_from_real_uid'] = $uid;
                    $userBalanceDetail[3]['transfer_for_real_uid'] = $forUid;
                    $userBalanceDetail[3]['is_transfer'] = 1;
                    $userBalanceDetail[3]['remark'] = '用户' . $userInfo['name'] . '-' . $userInfo['phone'] . '美丽金转入';
                } else {
                    $userBalanceDetail[1]['order_sn'] = $fOrderSn;
                    $userBalanceDetail[1]['uid'] = $forUid;
                    $userBalanceDetail[1]['type'] = 1;
                    $userBalanceDetail[1]['price'] = $price;
                    $userBalanceDetail[1]['change_type'] = 9;
                    $userBalanceDetail[1]['transfer_type'] = 1;
                    $userBalanceDetail[1]['transfer_for_uid'] = $forUid;
                    $userBalanceDetail[1]['transfer_for_user_phone'] = $forUserInfo['phone'] ?? null;
                    $userBalanceDetail[1]['transfer_from_uid'] = $uid;
                    $userBalanceDetail[1]['transfer_from_real_uid'] = $uid;
                    $userBalanceDetail[1]['transfer_for_real_uid'] = $forUid;
                    $userBalanceDetail[1]['is_transfer'] = 1;
                    $userBalanceDetail[1]['remark'] = '用户' . $userInfo['name'] . '-' . $userInfo['phone'] . '美丽金转入';
                }
            } else {
                $userShopBalanceDetail[0]['uid'] = $uid;
                $userShopBalanceDetail[0]['type'] = 1;
                $userShopBalanceDetail[0]['price'] = $price;
                $userShopBalanceDetail[0]['change_type'] = 22;
                $userShopBalanceDetail[0]['remark'] = '美丽金转入';
            }


            User::where(['uid' => $uid, 'status' => 1])->dec('crowd_balance', $totalPrice)->update();
            if ($transferType == 1) {
                User::where(['uid' => $forUid, 'status' => 1])->inc('crowd_balance', $price)->update();
            } else {
                User::where(['uid' => $forUid, 'status' => 1])->inc('divide_balance', $price)->update();
            }

            $res = false;
            if (!empty($userBalanceDetail)) {
                $res = $this->saveAll(array_values($userBalanceDetail));
            }
            if (!empty($userShopBalanceDetail)) {
                (new BalanceDetail())->saveAll($userShopBalanceDetail);
            }

            //将充值的订单推入队列计算团队上级充值业绩冗余明细<只有符合要求的用户转出美丽金才有计入充值业绩>
            if (empty($forFinance)) {
                if (config('system.recordRechargeDetail') == 1 && (!empty($userBalanceDetail[3] ?? []) && !empty($userBalanceDetail[3]['order_sn'] ?? null))) {
                    $upgradeQueue = Queue::push('app\lib\job\TeamMemberUpgrade', ['order_sn' => $userBalanceDetail[3]['order_sn'], 'type' => 5], config('system.queueAbbr') . 'TeamMemberUpgrade');
                }
                if ((!empty($userBalanceDetail[3] ?? []) && !empty($userBalanceDetail[3]['order_sn'] ?? null))) {
                    Queue::push('app\lib\job\TeamMemberUpgrade', ['order_sn' => $userBalanceDetail[3]['order_sn'], 'type' => 6], config('system.queueAbbr') . 'TeamMemberUpgrade');
                }
            }else{
                if (config('system.recordRechargeDetail') == 1 && (!empty($userBalanceDetail[1] ?? []) && !empty($userBalanceDetail[1]['order_sn'] ?? null)) && in_array($uid, $withdrawFinanceAccount)) {
                    $upgradeQueue = Queue::push('app\lib\job\TeamMemberUpgrade', ['order_sn' => $userBalanceDetail[1]['order_sn'], 'type' => 5], config('system.queueAbbr') . 'TeamMemberUpgrade');
                }

                if ((!empty($userBalanceDetail[1] ?? []) && !empty($userBalanceDetail[1]['order_sn'] ?? null)) && in_array($uid, $withdrawFinanceAccount)) {
                    Queue::push('app\lib\job\TeamMemberUpgrade', ['order_sn' => $userBalanceDetail[1]['order_sn'], 'type' => 6], config('system.queueAbbr') . 'TeamMemberUpgrade');
                }
            }

            return $res;
        });

        return judge($DBRes);
    }

    /**
     * @title  充值余额明细列表
     * @param array $sear
     * @return array
     * @throws \Exception
     */
    public function rechargeList(array $sear): array
    {
        if (!empty($sear['keyword'])) {
            $map[] = ['', 'exp', Db::raw($this->getFuzzySearSql('d.phone|d.name|a.order_sn|a.pay_no|a.price', $sear['keyword']))];
        }
        $map[] = ['a.status', 'in', $this->getStatusByRequestModule($sear['searType'] ?? 1)];
        if (!empty($sear['change_type'])) {
            if (is_array($sear['change_type'])) {
                $map[] = ['a.change_type', 'in', $sear['change_type']];
            } else {
                $map[] = ['a.change_type', '=', $sear['change_type']];
            }
        }

        $map[] = ['a.type', '=', 1];
        if (!empty($sear['time_type'] ?? null)) {
            switch ($sear['time_type'] ?? 1) {
                case 1:
                    $map[] = ['a.create_time', '>=', 1677081600];
                    break;
                case 2:
                    $map[] = ['a.create_time', '<', 1677081600];
                    break;
            }
        }
        if (!empty($sear['transfer_type'] ?? null)) {
            if ($sear['transfer_type'] == 1) {
                $map[] = ['', 'exp', Db::raw('a.transfer_type is not null')];
            } elseif ($sear['transfer_type'] == 2) {
                $map[] = ['a.change_type', '=', 1];
            }
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
        if (!empty($sear['pageNumber'] ?? null)) {
            $this->pageNumber = intval($sear['pageNumber'] ?? 0);
        }
        //财务号
        $financeUid = SystemConfig::where(['id' => 1])->value('finance_uid');
        $withdrawFinanceAccount = !empty($financeUid) ? explode(',', $financeUid) : ['notFinanceUid'];

        if (!empty($page)) {
            $aTotal = $this->alias('a')
                ->join('sp_user d','a.uid = d.uid','left')->where($map)->where(function ($query) use ($withdrawFinanceAccount){
                    $map1[] = ['a.change_type', '=', 1];
                    $map2[] = ['a.is_transfer', '=', 1];
                    $map2[] = ['a.transfer_from_uid', 'in', $withdrawFinanceAccount];
                    $query->whereOr([$map1, $map2]);
                })->count();
            $pageTotal = ceil($aTotal / $this->pageNumber);
        }
        if ($this->module == 'api') {
            $list = $this->alias('a')
                ->join('sp_user d', 'a.uid = d.uid', 'left')
                ->field('a.*,d.name as user_name,d.phone as user_phone,d.avatarUrl')->where($map)->where(function ($query) use ($withdrawFinanceAccount){
                    $map1[] = ['a.change_type', '=', 1];
                    $map2[] = ['a.is_transfer', '=', 1];
                    $map2[] = ['a.transfer_from_uid', 'in', $withdrawFinanceAccount];
                    $query->whereOr([$map1, $map2]);
                })->when($page, function ($query) use ($page) {
                    $query->page($page, $this->pageNumber);
                })->order('a.create_time desc,a.id asc')->select()->toArray();
        } else {
            $list = $this->alias('a')
                ->join('sp_user d', 'a.uid = d.uid', 'left')
                ->field('a.id,a.uid,a.order_sn,a.type,a.price,a.change_type,a.status,a.create_time,a.pay_type,a.remark,d.name as user_name,d.phone as user_phone,d.avatarUrl')->where($map)->where(function ($query) use ($withdrawFinanceAccount){
                    $map1[] = ['a.change_type', '=', 1];
                    $map2[] = ['a.is_transfer', '=', 1];
                    $map2[] = ['a.transfer_from_uid', 'in', $withdrawFinanceAccount];
                    $query->whereOr([$map1, $map2]);
                })->when($page, function ($query) use ($page) {
                    $query->page($page, $this->pageNumber);
                })->order('a.create_time desc,a.id asc')->select()->toArray();
        }

        if (!empty($list)) {

//            $allOrderUid = array_column($list, 'order_uid');
//            $allOrderUser = User::where(['uid' => $allOrderUid])->column('name', 'uid');
//            if (!empty($allOrderUid)) {
//                foreach ($list as $key => $value) {
//                    foreach ($allOrderUser as $k => $v) {
//                        if (!empty($value['order_uid'])) {
//                            if ($k == $value['order_uid']) {
//                                $list[$key]['order_user_name'] = $v;
//                            }
//                        }
//
//                    }
//                    unset($list[$key]['order_uid']);
//                }
//            }

            if ($this->module == 'api') {
                $allForUid = array_column($list, 'transfer_for_uid');
                $allFromUid = array_column($list, 'transfer_from_uid');
                if (!empty($allForUid) && !empty($allFromUid)) {
                    $allTUser = array_unique(array_merge_recursive($allForUid, $allFromUid));
                } elseif (!empty($allForUid) && empty($allFromUid)) {
                    $allTUser = $allForUid;
                } else {
                    $allTUser = $allFromUid;
                }

                if (!empty($allTUser)) {
                    $allTransferUser = User::where(['uid' => $allTUser, 'status' => 1])->column('name', 'uid');
                    if (!empty($allTransferUser)) {
                        foreach ($list as $key => $value) {
                            foreach ($allTransferUser as $k => $v) {
                                if (!empty($value['transfer_for_uid'])) {
                                    if ($k == $value['transfer_for_uid']) {
                                        $list[$key]['transfer_for_user_name'] = $v;
                                    }
                                }
                                if (!empty($value['transfer_from_uid'])) {
                                    if ($k == $value['transfer_from_uid']) {
                                        $list[$key]['transfer_from_user_name'] = $v;
                                    }
                                }
                                unset($list[$key]['transfer_for_uid']);
                                unset($list[$key]['transfer_from_uid']);
                            }
                        }
                    }
                }
            }
        }

        return ['list' => $list, 'pageTotal' => $pageTotal ?? 0];
    }

    /**
     * @title  转出余额明细列表
     * @param array $sear
     * @return array
     * @throws \Exception
     */
    public function transferList(array $sear): array
    {
        if (!empty($sear['keyword'])) {
            $map[] = ['', 'exp', Db::raw($this->getFuzzySearSql('d.phone|d.name|a.order_sn|a.pay_no|a.price', $sear['keyword']))];
        }
        $map[] = ['a.status', 'in', $this->getStatusByRequestModule($sear['searType'] ?? 1)];
        if (!empty($sear['change_type'])) {
            if (is_array($sear['change_type'])) {
                $map[] = ['a.change_type', 'in', $sear['change_type']];
            } else {
                $map[] = ['a.change_type', '=', $sear['change_type']];
            }
        }

        $map[] = ['a.type', '=', 2];
        $map[] = ['a.change_type', '=', 7];
        if (!empty($sear['time_type'] ?? null)) {
            switch ($sear['time_type'] ?? 1) {
                case 1:
                    $map[] = ['a.create_time', '>=', 1677081600];
                    break;
                case 2:
                    $map[] = ['a.create_time', '<', 1677081600];
                    break;
            }
        }
        if (!empty($sear['transfer_type'] ?? null)) {
            if ($sear['transfer_type'] == 1) {
                $map[] = ['', 'exp', Db::raw('a.transfer_type is not null')];
            } elseif ($sear['transfer_type'] == 2) {
                $map[] = ['a.change_type', '=', 1];
            }
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
        if (!empty($sear['pageNumber'] ?? null)) {
            $this->pageNumber = intval($sear['pageNumber'] ?? 0);
        }
        if (!empty($page)) {
            $aTotal = $this->alias('a')
                ->join('sp_user d','a.uid = d.uid','left')->where($map)->count();
            $pageTotal = ceil($aTotal / $this->pageNumber);
        }
        if ($this->module == 'api') {
            $list = $this->alias('a')
                ->join('sp_user d', 'a.uid = d.uid', 'left')
                ->field('a.*,d.name as user_name,d.phone as user_phone,d.avatarUrl')->where($map)->when($page, function ($query) use ($page) {
                    $query->page($page, $this->pageNumber);
                })->order('a.create_time desc,a.id asc')->select()->toArray();
        } else {
            $list = $this->alias('a')
                ->join('sp_user d', 'a.uid = d.uid', 'left')
                ->field('a.id,a.uid,a.order_sn,a.type,a.price,a.change_type,a.status,a.create_time,a.pay_type,a.remark,d.name as user_name,d.phone as user_phone,d.avatarUrl')->where($map)->when($page, function ($query) use ($page) {
                    $query->page($page, $this->pageNumber);
                })->order('a.create_time desc,a.id asc')->select()->toArray();
        }

        if (!empty($list)) {
            if ($this->module == 'api') {
                $allForUid = array_column($list, 'transfer_for_uid');
                $allFromUid = array_column($list, 'transfer_from_uid');
                if (!empty($allForUid) && !empty($allFromUid)) {
                    $allTUser = array_unique(array_merge_recursive($allForUid, $allFromUid));
                } elseif (!empty($allForUid) && empty($allFromUid)) {
                    $allTUser = $allForUid;
                } else {
                    $allTUser = $allFromUid;
                }

                if (!empty($allTUser)) {
                    $allTransferUserList = User::where(['uid' => $allTUser, 'status' => 1])->field('uid,name,phone')->select()->toArray();
                    if (!empty($allTransferUserList)) {
                        foreach ($allTransferUserList as $key => $value) {
                            $allTransferUser[$value['uid']] = $value;
                        }
                        foreach ($list as $key => $value) {
                            foreach ($allTransferUser as $k => $v) {
                                if (!empty($value['transfer_for_real_uid'])) {
                                    if ($k == $value['transfer_for_real_uid']) {
                                        $list[$key]['transfer_for_user_name'] = $v['name'];
                                        $list[$key]['transfer_for_user_phone'] = $v['phone'];
                                    }
                                }
                                if (!empty($value['transfer_from_real_uid'])) {
                                    if ($k == $value['transfer_from_real_uid']) {
                                        $list[$key]['transfer_from_user_name'] = $v['name'];
                                        $list[$key]['transfer_from_user_phone'] = $v['phone'];
                                    }
                                }
                                unset($list[$key]['transfer_for_uid']);
                                unset($list[$key]['transfer_from_uid']);
                            }
                        }
                    }
                }
            }
        }

        return ['list' => $list, 'pageTotal' => $pageTotal ?? 0];
    }

    /**
     * @title 查看用户购物送的美丽金是否全部都参与福利专区了
     * @param array $data
     * @return array
     * @throws \think\db\exception\DbException
     */
    public function getUserNormalShoppingSendCrowdBalance(array $data)
    {
        $uid = $data['uid'];
        //按照购物送美丽金汇总作为用户的总美丽金
//        $map[] = ['', 'exp', Db::raw("order_sn in (select order_sn from sp_order where uid = '".$uid."' and pay_status = 2 and order_type = 1)")];
//        $map[] = ['pay_status', '=', 2];
//        $map[] = ['status', '=', 1];
//        $map[] = ['gift_type', '=', 3];
//        $map[] = ['', 'exp', Db::raw("real_pay_price > refund_price and gift_number > 0")];
//        $crowdGiftNumber = OrderGoods::where($map)->sum('gift_number');

        //按照用户总充值的美丽金余额(财务号经传的不算)作为用户的总美丽金
//        $financeUid = SystemConfig::where(['id' => 1])->value('finance_uid');
//        $withdrawFinanceAccount = !empty($financeUid) ? explode(',', $financeUid) : ['notFinanceUid'];
//        $rMap[] = ['uid', '=', $uid];
//        $rMap[] = ['type', '=', 1];
//        $rMap[] = ['status', '=', 1];
//        $crowdGiftNumber = CrowdfundingBalanceDetail::where(function ($query) use ($withdrawFinanceAccount) {
//            $map1[] = ['change_type', '=', 1];
//            $map2[] = ['is_transfer', '=', 1];
//            $map2[] = ['transfer_from_real_uid', 'in', $withdrawFinanceAccount];
//            $query->whereOr([$map1, $map2]);
//        })->where($rMap)->sum('price');
//
//        $crowdOrderPrice = 0;
//        if (doubleval($crowdGiftNumber) > 0) {
//            //查找用户参与的所有众筹订单金额
//            $cMap[] = ['uid', '=', $uid];
//            $cMap[] = ['order_type', '=', 6];
//            $cMap[] = ['pay_status', '=', 2];
//            $crowdOrderPrice = Order::where($cMap)->sum('real_pay_price');
//
//            //查看是否有因为众筹参与不够用户主动提现并扣除特殊手续费的提现, 如果有则加上算是参与额度
//            $wMap[] = ['uid', '=', $uid];
//            $wMap[] = ['is_special_fee', '=', 1];
//            $wMap[] = ['special_fee_type', '=', 1];
//            $wMap[] = ['withdraw_type', '=', 7];
//            $wMap[] = ['check_status', '=', 1];
//            $wMap[] = ['status', '=', 1];
//            $userSpecialFeeWithdraw = Withdraw::where($wMap)->sum('total_price');
//            if (!empty($userSpecialFeeWithdraw) && doubleval($userSpecialFeeWithdraw) > 0) {
//                $crowdOrderPrice = priceFormat($crowdOrderPrice + $userSpecialFeeWithdraw);
//            }
//        }

        //按照用户福利参与总流水 - (福利获得+后台操作部分的健康豆) - (福利获得+后台操作部分美丽豆) - 购物送美丽金专区订单总额 > 0的情况下则可以赠送豆子
        //购物送美丽金订单
        $map[] = ['', 'exp', Db::raw("order_sn in (select order_sn from sp_order where uid = '" . $uid . "' and pay_status = 2 and order_type = 1 and order_status != -3)")];
        $map[] = ['pay_status', '=', 2];
        $map[] = ['status', '=', 1];
        $map[] = ['gift_type', '=', 3];
        $map[] = ['', 'exp', Db::raw("real_pay_price > refund_price and gift_number > 0")];
        $crowdGiftAllPrice = OrderGoods::where($map)->sum('real_pay_price');

        //查找用户参与的所有众筹订单金额
        $cMap[] = ['uid', '=', $uid];
        $cMap[] = ['order_type', '=', 6];
        $cMap[] = ['pay_status', '=', 2];
        $cMap[] = ['order_status', '<>', -3];
        //计算参与福利流水为2023-08-12 00:00:00之后的
        $cMap[] = ['create_time', '>=', "1691769600"];
        $crowdOrderAllPrice = Order::where($cMap)->sum('real_pay_price');

        //查看是否有因为众筹参与不够用户主动提现并扣除特殊手续费的提现, 如果有则加上算是参与额度
        $wMap[] = ['uid', '=', $uid];
        $wMap[] = ['is_special_fee', '=', 1];
        $wMap[] = ['special_fee_type', '=', 1];
        $wMap[] = ['withdraw_type', '=', 7];
        $wMap[] = ['check_status', '=', 1];
        $wMap[] = ['status', '=', 1];
        $userSpecialFeeWithdraw = Withdraw::where($wMap)->sum('total_price');
        if (!empty($userSpecialFeeWithdraw) && doubleval($userSpecialFeeWithdraw) > 0) {
            $crowdOrderAllPrice = priceFormat($crowdOrderAllPrice + $userSpecialFeeWithdraw);
        }

        //福利订单赠送的健康豆
        $hMap[] = ['', 'exp', Db::raw("order_sn in (select order_sn from sp_order where uid = '" . $uid . "' and pay_status = 2 and order_type = 6 and order_status != -3)")];
        $hMap[] = ['pay_status', '=', 2];
        $hMap[] = ['status', '=', 1];
        $hMap[] = ['gift_type', '=', 2];
        $hMap[] = ['', 'exp', Db::raw("real_pay_price > refund_price and gift_number > 0")];
        $crowdGiftHealthyNumber = OrderGoods::where($hMap)->sum('gift_number');

        //第二种算法
//        $crowdGiftHealthyOrder = OrderGoods::where($hMap)->field('order_sn')->buildSql();
//
//        $healthyMap[]  = ['','exp',Db::raw("order_sn in ($crowdGiftHealthyOrder)")];
//        $crowdGiftHealthyNumber = HealthyBalanceDetail::where($healthyMap)->sum('price');

        //后台操作的健康豆额度
        $mHMap[] = ['uid', '=', $uid];
        $mHMap[] = ['', 'exp', Db::raw(" ((LOCATE('后台系统',remark) > 0) or (LOCATE('充值美丽金赠送',remark) > 0))")];
        $mHMap[] = ['status', '=', 1];
        $operHealthyNumber = HealthyBalanceDetail::where($mHMap)->sum('price');

        //福利订单赠送的美丽豆
        $iMap[] = ['', 'exp', Db::raw("order_sn in (select order_sn from sp_order where uid = '" . $uid . "' and pay_status = 2 and order_type = 6 and order_status != -3)")];
        $iMap[] = ['pay_status', '=', 2];
        $iMap[] = ['status', '=', 1];
        $iMap[] = ['gift_type', '=', 1];
        $iMap[] = ['', 'exp', Db::raw("real_pay_price > refund_price and gift_number > 0")];
        $crowdGiftIntegralNumber = OrderGoods::where($iMap)->sum('gift_number');

        //第二种算法
//        $crowdGiftIntegralOrder = OrderGoods::where($hMap)->field('order_sn')->buildSql();

//        $integralMap[] = ['', 'exp', Db::raw("order_sn in ($crowdGiftIntegralOrder)")];
//        $crowdGiftIntegralNumber = IntegralDetail::where($integralMap)->sum('integral');

        //后台操作的美丽豆额度
        $iHMap[] = ['uid', '=', $uid];
        $iHMap[] = ['change_type', 'in', [7, 8]];
        $iHMap[] = ['status', '=', 1];
        $operIntegralNumber = IntegralDetail::where($iHMap)->sum('integral');


        $crowdGiftNumber = priceFormat(($crowdGiftHealthyNumber ?? 0) + ($operHealthyNumber ?? 0) + ($crowdGiftIntegralNumber ?? 0) + ($operIntegralNumber ?? 0) + ($crowdGiftAllPrice ?? 0));
        $crowdOrderPrice = priceFormat(($crowdOrderAllPrice ?? 0));

        $result = ['res' => false, 'gift_price' => $crowdGiftNumber ?? 0, 'crowd_price' => $crowdOrderPrice ?? 0];
        if (doubleval($crowdGiftNumber) <= 0) {
            $result['res'] = true;
        } else {
            if ((string)$crowdGiftNumber >= (string)$crowdOrderPrice) {
                $result['res'] = false;
            } else {
                $result['res'] = true;
            }
        }

        return $result;
    }

    /**
     * @title 后台充值, 财务号直转等赠送健康豆或美丽豆(积分)
     * @param array $data
     * @return array
     */
    public function rechargeGiftSend(array $data)
    {
        //赠送类型 1为美丽豆(积分) 2为健康豆 -1为不赠送
        $giftType = $data['gift_type'] ?? -1;
        //明细数组, 必须为对应类型的二维数组
        $balanceDetail = $data['balance_detail'] ?? [];

        //如果giftType=2为健康豆类型, 需要选择赠送的渠道类型 1为商城 2为福利 3为消费型股东, 更多类型请看PayConstant中的HEALTHY_CHANNEL开头的常量定义, 默认福利类型
        $healthyChannelType = $data['healthy_channel_type'] ?? 2;

        if (empty($balanceDetail) || $giftType == -1) {
            return ['res' => false, 'msg' => '参数有误'];
        }
        switch ($giftType) {
            case 1:
                $integralDec['list'] = $balanceDetail;
                $integralDec['db_name'] = 'sp_user';
                $integralDec['id_field'] = 'uid';
                $integralDec['operate_field'] = 'integral';
                $integralDec['value_field'] = 'integral';
                $integralDec['operate_type'] = 'inc';
                $integralDec['other_map'] = 'status = 1';
                $sql1 = (new CommonModel())->setThrowError(true)->DBBatchIncOrDec($integralDec);

                $integralDetailNew['list'] = $balanceDetail;
                $integralDetailNew['db_name'] = 'sp_integral_detail';
                $integralDetailNew['notValidateValueField'] = ['uid'];
                $sql2 = (new CommonModel())->setThrowError(true)->DBSaveAll($integralDetailNew);
                break;
            case 2:
                $healthyDetail = array_values($balanceDetail);
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
                $batchSqlHealthyData['notValidateValueField'] = ['uid'];
                (new CommonModel())->DBBatchIncOrDec($batchSqlHealthyData);

                //批量新增健康豆明细
                $batchSqlHealthyDetailData['list'] = $healthyDetail;
                $batchSqlHealthyDetailData['db_name'] = 'sp_healthy_balance_detail';
                $batchSqlHealthyDetailData['notValidateValueField'] = ['uid'];
                (new CommonModel())->DBSaveAll($batchSqlHealthyDetailData);

                //添加或新增健康豆渠道冗余表
                //查询每个人在健康豆福利渠道是否存在数据, 如果不存在则新增, 存在则自增
                $existHealthyChannel = HealthyBalance::where(['uid' => array_keys($userGiftHealthy), 'channel_type' => ($healthyChannelType ?? 2), 'status' => 1])->column('uid');
                foreach ($userGiftHealthy as $key => $value) {
                    if (in_array($key, $existHealthyChannel)) {
                        $needUpdateGiftHealthyUser[$key] = $value;
                    } else {
                        $newGiftHealthyUser[$key] = $value;
                    }
                }

                if (!empty($needUpdateGiftHealthyUser ?? [])) {
                    foreach ($needUpdateGiftHealthyUser as $key => $value) {
                        if (doubleval($value) <= 0) {
                            unset($needUpdateGiftHealthyUser[$key]);
                        }
                    }
                }
                if (!empty($needUpdateGiftHealthyUser ?? [])) {
                    //健康豆冗余表批量自增
                    $batchSqlHealthyBalanceData['list'] = $needUpdateGiftHealthyUser;
                    $batchSqlHealthyBalanceData['db_name'] = 'sp_healthy_balance';
                    $batchSqlHealthyBalanceData['id_field'] = 'uid';
                    $batchSqlHealthyBalanceData['operate_field'] = 'balance';
                    $batchSqlHealthyBalanceData['operate_type'] = 'inc';
                    $batchSqlHealthyBalanceData['sear_type'] = 1;
                    $batchSqlHealthyBalanceData['other_map'] = 'status = 1 and channel_type = ' . ($healthyChannelType ?? 2);
                    $batchSqlHealthyBalanceData['notValidateValueField'] = ['uid'];
                    (new CommonModel())->DBBatchIncOrDec($batchSqlHealthyBalanceData);
                }

                //添加健康豆渠道冗余表明细
                if (!empty($newGiftHealthyUser ?? [])) {
                    foreach ($newGiftHealthyUser as $key => $value) {
                        $newGiftHealthyData[$key]['uid'] = $key;
                        $newGiftHealthyData[$key]['balance'] = $value;
                        $newGiftHealthyData[$key]['channel_type'] = ($healthyChannelType ?? 2);
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
                break;
            default:
                return ['res' => false, 'msg' => '未知的赠送类型'];
                break;
        }

        return ['res' => true, 'msg' => '成功'];
    }

    /**
     * @title 汇总统计用户众筹余额明细详情
     * @param array $sear
     * @return array
     */
    public function userCrowdBalanceSummaryList(array $sear = [])
    {
        $otherMap = "u.status = 1";
        $cacheKey = 'userCrowdBalanceSummaryInfo';
        $cacheExpire = 3600 * 1;
        if (!empty($sear['keyword'])) {
            $otherMap .= " AND " . $this->getFuzzySearSql('u.phone|u.name', $sear['keyword']);
        } else {
            if (!empty(cache($cacheKey))) {
                $allList = cache($cacheKey);
            }
        }

//        if (!empty($sear['start_time']) && !empty($sear['end_time'])) {
//            $map[] = ['a.create_time', 'between', [strtotime($sear['start_time']), strtotime($sear['end_time'])]];
//        }

        if (empty($allList)) {
            //sql 汇总统计用户参与中未回本的众筹余额, 及所有可用余额
            $baseSql = "select u.name as user_name,u.uid,u.phone as user_phone,u.avatarUrl as user_avatarUrl,IFNULL(t.price,\"0.00\") as crowd_ing_price,u.crowd_balance,u.crowd_fronzen_balance,IFNULL((t.price + u.crowd_balance + u.crowd_fronzen_balance),\"0.00\") as total_price,from_unixtime(u.create_time) as user_create_time,current_timestamp() as summary_time from (select a.uid,abs(sum(if(a.count_number =0 and a.number < 0,a.number,0))) as price from (select a.uid,a.order_sn,count(b.id) as count_number,a.price as number from (select * from sp_crowdfunding_balance_detail where status = 1 and change_type = 2) a  LEFT JOIN sp_crowdfunding_balance_detail b on a.order_sn = b.order_sn and b.status = 1 and b.change_type = 3 and b.type = 1 and a.crowd_code is not  null GROUP BY a.order_sn) a GROUP BY uid) t right join sp_user u on t.uid = u.uid where $otherMap ORDER BY u.create_time desc";

            $allList = Db::query($baseSql);
            if (!empty($allList) && empty($sear['keyword'] ?? null)) {
                cache($cacheKey, $allList, $cacheExpire);
            }
        }


        $page = intval($sear['page'] ?? 0) ?: null;
        if (!empty($sear['pageNumber'] ?? null)) {
            $this->pageNumber = intval($sear['pageNumber'] ?? 0);
        }
        if (!empty($page)) {
            $aTotal = count($allList ?? []);
            $pageTotal = intval(ceil($aTotal / $this->pageNumber));
        }
        $list = $allList ?? [];
        if (!empty($list)) {
            //虚拟分页
            $pageNumber = $this->pageNumber;
            if (!empty($page)) {
                $start = ($page - 1) * $pageNumber;
                $end = $start + ($pageNumber - 1);
                $list = array_slice($allList, $start, $pageNumber);
            }
        }
        return ['list' => $list, 'pageTotal' => $pageTotal ?? 0];
    }

    public function divide()
    {
        return $this->hasOne('Divide', 'order_sn', 'order_sn')->where(['status' => [1]])->bind(['order_uid', 'total_price', 'divide_type' => 'type']);
    }

    public function activityName()
    {
        return $this->hasOne('CrowdfundingActivity', 'activity_code', 'crowd_code')->bind(['activity_name'=>'title']);
    }
    public function test()
    {
        return $this->hasOne('CrowdfundingBalanceDetail', 'order_sn', 'order_sn')->where(['change_type'=>3]);
    }
}