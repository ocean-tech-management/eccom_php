<?php
// +----------------------------------------------------------------------
// |[ 文档说明: 财务模块Service]
// +----------------------------------------------------------------------
// | Copyright (c) 2018~2024 http://www.mlhcmk.com All rights reserved.
// +----------------------------------------------------------------------


namespace app\lib\services;


use app\lib\BaseException;
use app\lib\constant\WithdrawConstant;
use app\lib\exceptions\FinanceException;
use app\lib\exceptions\ServiceException;
use app\lib\exceptions\WithdrawException;
use app\lib\models\BalanceDetail;
use app\lib\models\BawList;
use app\lib\models\CrowdfundingBalanceDetail;
use app\lib\models\CrowdfundingTicketDetail;
use app\lib\models\MemberVdc;
use app\lib\models\PpylBalanceDetail;
use app\lib\models\RechargeLink;
use app\lib\models\RechargeLinkCumulative;
use app\lib\models\Reward;
use app\lib\models\SystemConfig;
use app\lib\models\User;
use app\lib\models\UserBalanceLimit;
use app\lib\models\UserPrivacy;
use app\lib\models\Withdraw;
use app\lib\models\Member;
use think\Exception;
use think\facade\Cache;
use think\facade\Db;
use think\facade\Queue;

class Finance
{
    private $belong = 1;
    protected $userOneDayWithdrawNumber = 1;
    private $handingScale = 0.07;
    //较低提现手续费的门槛金额,高于此值则使用正常提现手续费
    private $minHandingPrice = 500;
    private $minHandingScale = 0.01;
    //最低提现门槛
    private $leastPrice = 100;
    //最低手续费
    private $leastHandingFree = 0.5;

    /**
     * @title  检查提现金额是否合法
     * @param array $data
     * @return bool
     */
    public function checkWithdrawPrice(array $data)
    {
        $userInfo = (new User())->getUserInfo($data['uid']);
        $withdrawType = $data['withdraw_type'] ?? 1;
        $price = priceFormat($data['price']);
        if (empty($price) || $price < 1) {
            throw new FinanceException(['errorCode' => 1700102]);
        }
//        $userBalance = (new BalanceDetail())->getAllBalanceByUid($data['uid']);
        //withdraw_type 1本金提现 2为商城佣金和拼拼有利奖励一起提现 3为单独提现商城佣金 4为单独提现拼拼有礼奖励 5为H5提现 6为单独提现广宣奖 7为小程序众筹钱包提现
        switch ($withdrawType) {
            case 1:
                $userBalance = (new BalanceDetail())->getAllBalanceByUid($data['uid']);
                $checkPrice = $userInfo['avaliable_balance'];
                break;
            case 2:
                $divideBalance = (new BalanceDetail())->getAllBalanceByUid($data['uid'], [1, 2, 7, 11, 13, 22, 23, 24]);
                $ppylBalance = (new PpylBalanceDetail())->getAllBalanceByUid($data['uid']);
                $userBalance = $divideBalance + $ppylBalance;
                $checkPrice = $userInfo['divide_balance'] + $userInfo['ppyl_balance'];
                break;
            case 3:
                $userBalance = (new BalanceDetail())->getAllBalanceByUid($data['uid'], [1, 2, 7, 11, 13, 23, 24]);
                $checkPrice = $userInfo['divide_balance'];
                break;
            case 4:
                $userBalance = (new PpylBalanceDetail())->getAllBalanceByUid($data['uid']);
                $checkPrice = $userInfo['ppyl_balance'];
                break;
            case 5:
                $userBalance = (new BalanceDetail())->getAllBalanceByUid($data['uid'], [12, 15, 16, 17, 18, 19, 20, 21, 27, 28, 29, 30, 31, 32, 33, 34]);
                $checkPrice = $userInfo['ad_balance'] + $userInfo['team_balance'] + $userInfo['shareholder_balance'] + ($userInfo['area_balance'] ?? 0);
                break;
            case 7:
                $userBalance = (new CrowdfundingBalanceDetail())->getAllBalanceByUid($data['uid']);
                $checkPrice = $userInfo['crowd_balance'];
                break;
            default:
                throw new FinanceException(['msg' => '未知的提现类型']);
        }
        if (empty($userBalance)) {
            throw new FinanceException(['errorCode' => 1700101]);
        }

        if ((string)$checkPrice < (string)($price) || (string)($userBalance) < (string)($price)) {
            throw new FinanceException(['errorCode' => 1700101]);
        }
        return true;
    }

    /**
     * @title  提现手续费规则
     * @param array $data
     * @return array
     */
    private function withdrawRule(array $data)
    {
        //withdraw_type 1本金提现 2为商城佣金和拼拼有利奖励一起提现 3为单独提现商城佣金 4为单独提现拼拼有礼奖励
        $type = $data['withdraw_type'] ?? 1;
        $userMemberInfo = $data['memberInfo'];
        switch ($type) {
            case 1:
                $handingScale = 0.07;
                $this->leastPrice = 100;
                $this->handingScale = $handingScale;
                $this->leastHandingFree = 0.5;
                break;
            case 2:
                //商城混合提现
                $handingScale = 0.07;
                $this->handingScale = $handingScale;
                $this->leastPrice = 100;
                $this->leastHandingFree = 0.5;
                break;
            case 3:
                //单独提现商城佣金手续费
                if (!empty($userMemberInfo)) {
                    $userLevel = $userMemberInfo['level'] ?? null;
                    $leaderLevel = $userMemberInfo['leader_level'] ?? null;
                    if (empty($userLevel)) {
                        throw new FinanceException(['msg' => '非会员暂无法提现']);
                    }
                    if (empty($leaderLevel)) {
                        $levelInfo = (new MemberVdc())->getMemberRule($userLevel);
                    } else {
                        $levelInfo = (new Reward())->getTeamRewardRule($leaderLevel);
                    }
                    $handingScale = $levelInfo['handing_scale'] ?? 0;
                } else {
                    $handingScale = 0.07;
                }
                $this->handingScale = $handingScale;
                $this->leastPrice = 100;
                $this->leastHandingFree = 0.5;
                break;
            case 4:
                //单独提现拼拼有礼奖励
                $handingScale = 0.07;
                $this->handingScale = $handingScale;
                $this->leastPrice = 100;
                $this->leastHandingFree = 0.5;
                break;
            case 5:
                //H5端,提现广宣和团队业绩等混合奖励
                $handingScale = 0.07;
                $this->handingScale = $handingScale;
                $this->leastPrice = 100;
                $this->leastHandingFree = 0.5;
                break;
            case 6:
                //单独提现广宣奖奖励
                $handingScale = 0.07;
                $this->handingScale = $handingScale;
                $this->leastPrice = 100;
                $this->leastHandingFree = 0.5;
                break;
            case 7:
                //众筹余额提现
                $handingScale = 0.07;
                $this->handingScale = $handingScale;
                $this->leastPrice = 100;
                $this->leastHandingFree = 0.5;
                break;
            default:
                $handingScale = 0.07;
                $this->handingScale = $handingScale;
                $this->leastPrice = 100;
                $this->leastHandingFree = 0.5;
                break;
        }
        //低于较低手续费门槛的提现总额收取较低提现手续费
        if (!empty($data['total_price'] ?? null) && doubleval($data['total_price']) <= $this->minHandingPrice) {
            $this->handingScale = $this->minHandingScale;
        }
        return
            [
                'handingScale' => $handingScale ?? 0,
                'leastPrice' => $this->leastPrice,
                'leastHandingFree' => $this->leastHandingFree
            ];
    }

    /**
     * @title  用户发起提现时申请
     * @param array $data
     * @return mixed
     */
    public function userWithdraw(array $data)
    {
        $data['withdraw_type'] = $data['withdraw_type'] ?? 2;
        if (!empty(cache('canNotOperBalance-' . $data['uid']))) {
            throw new FinanceException(['msg' => '前方拥挤, 请五分钟后重试, 感谢您的支持和理解']);
        }
        if (!empty(cache('withdrawBalanceIng-' . $data['uid']))) {
            throw new FinanceException(['msg' => '请勿重复提交, 感谢您的支持和理解']);
        }
        cache('withdrawBalanceIng-' . $data['uid'], $data, 60);
        //判断提现总金额必须能够被100整除
        if (((string)$data['total_price'] % 100) !== 0) {
            throw new FinanceException(['msg' => '提现金额必须为100的整数倍~']);
        }
        $userInfo = User::where(['uid' => $data['uid'], 'status' => 1])->value('id');
        if (empty($userInfo)) {
            throw new FinanceException(['msg' => '用户信息异常, 请联系客服']);
        }
//        throw new ServiceException(['msg'=>'提现功能升级中,请耐心等待~']);
        $res = Db::transaction(function () use ($data) {
            $withdrawPayType = config('system.withdrawPayType');
            if ($withdrawPayType == 5) {
                //验证和获取签约信息
                $userZsk = (new UserPrivacy())->where(['uid' => $data['uid'], 'status' => 1])->find();
                if (!$userZsk) {
                    throw new WithdrawException(['errorCode' => 30002]);
                }
            }
            //$data['total_price'] = 1;
            //限制只有被审核过后的才可以进行下一次审核,且一天只能申请一次提现审核(包括审核失败和成功的次数)
            $withdrawCount = Withdraw::where(['uid' => $data['uid'], 'check_status' => 3])->count();
            if ($withdrawCount >= 1) {
                throw new FinanceException(['errorCode' => 1700104]);
            }
            if (doubleval($data['total_price'] ?? 0) >= 18000) {
                throw new FinanceException(['msg' => '单次提现金额上限不可大于18000元']);
            }
//            if ($data['withdraw_type'] == 5 && doubleval($data['total_price'] ?? 0) > 1000) {
//                throw new FinanceException(['msg' => '单次提现金额上限不可大于1000元']);
//            }
//            if ($data['withdraw_type'] == 7 && doubleval($data['total_price'] ?? 0) > 500) {
//                throw new FinanceException(['msg' => '单次提现金额上限不可大于500元']);
//            }
            $withdrawTodayCount = Withdraw::where(['uid' => $data['uid'], 'check_status' => [1, 2], 'payment_status' => 1])->whereDay('create_time', 'today')->count();
            if ($withdrawTodayCount >= $this->userOneDayWithdrawNumber) {
                throw new FinanceException(['msg' => '每个人每天只能提交' . intval($this->userOneDayWithdrawNumber) . '次审核哦~']);
            }
            //判断用户等级并取对应手续费
            $userMemberInfo = (new Member())->where(['uid' => $data['uid'], 'status' => 1])->field('level,type')->findOrEmpty()->toArray();
            //获取手续费
            $rule = $this->withdrawRule(['withdraw_type' => $data['withdraw_type'], 'memberInfo' => $userMemberInfo, 'total_price' => $data['total_price']]);

            //判断最低申请提现金额
            if ((string)$data['total_price'] < $this->leastPrice) {
                throw new FinanceException(['msg' => '最低申请金额不得低于' . $this->leastPrice . '元']);
            }

            //判断用户是否被限制
            (new UserBalanceLimit())->checkUserBalanceLimit(['uid' => $data['uid'], 'price' => $data['total_price'], 'withdraw_type' => $data['withdraw_type'], 'oper_type' => 1]);
//            $userLevel = $userMemberInfo['level'] ?? null;
//            $leaderLevel = $userMemberInfo['leader_level'] ?? null;
//            if (empty($userLevel)) {
//                throw new FinanceException(['msg' => '非会员暂无法提现']);
//            }
//            if (empty($leaderLevel)) {
//                $levelInfo = (new MemberVdc())->getMemberRule($userLevel);
//            } else {
//                $levelInfo = (new Reward())->getTeamRewardRule($leaderLevel);
//            }
////            if(!empty($levelInfo)){
////                $handingScale = $levelInfo['handing_scale'] ?? 0;
////            }else{
////                $handingScale = $this->handingScale;
////            }
//            $handingScale = $levelInfo['handing_scale'] ?? 0;
//            $this->handingScale = $handingScale;

            if ($data['withdraw_type'] == 7) {
                //暂时停止关于福利专区提现的提现限制
//                $checkUserCanWithdrawTotalPrice = (new Summary())->userWithdrawDataPanel(['uid' => $data['uid']]);
//                if (!empty($checkUserCanWithdrawTotalPrice) && ((string)($checkUserCanWithdrawTotalPrice['canWithdrawPrice'] ?? 0) <= 0 || (string)($checkUserCanWithdrawTotalPrice['canWithdrawPrice'] ?? 0) < (string)$data['total_price'])) {
//                    throw new FinanceException(['msg' => '过往提现金额已达到上限']);
//                }
            }

            //获取用户当天可以提现的额度
            if (config('system.withdrawLimitByRecharge') == 1 && $data['withdraw_type'] == 5) {
                $userTodayWithdrawLimit = (new RechargeLink())->userSubordinateRechargeRate(['uid' => $data['uid'], 'clearCache' => true, 'time_type' => 1]);
                if (empty($userTodayWithdrawLimit)) {
                    throw new FinanceException(['msg' => '额度计算异常']);
                }
                if (!empty($userTodayWithdrawLimit['withdrawLimit'] ?? false)) {
                    $userHistoryAmount = (new RechargeLink())->checkUserWithdrawAndTransfer(['uid' => $data['uid'], 'time_type' => 1]);
                    if ((string)($data['total_price'] + $userHistoryAmount) > (string)($userTodayWithdrawLimit['totalCanWithdrawAmount'] ?? 0)) {
                        throw new FinanceException(['msg' => '今日累计可以提现额度为' . ($userTodayWithdrawLimit['totalCanWithdrawAmount'] ?? 0) . ' 当前已操作的金额为' . (priceFormat($userHistoryAmount ?? 0)) . ' 你填写的额度已超过, 请减少您的提现额度']);
                    }
                }
            }
            //福利专区提现如果用户购物赠送的美丽金没有完全参与福利专区则手续费强制为30%, 如果存在混合的情况则强制进允许提剩余的赠送的美丽金
            $isCrowdJoinNotEnoughWithdraw = false;
            if ($data['withdraw_type'] == 7) {
                //查看用户是否为免提现手续费的白名单
                $notFreeUser = BawList::where(['uid' => $data['uid'], 'channel' => 6, 'type' => 1, 'status' => 1])->value('uid');
                if (empty($notFreeUser)) {
                    $userGiftCrowdBalance = (new CrowdfundingBalanceDetail())->getUserNormalShoppingSendCrowdBalance(['uid' => $data['uid']]);
                    if (empty($userGiftCrowdBalance['res'] ?? false)) {
                        $lastGiftCrowd = ($userGiftCrowdBalance['gift_price'] ?? 0) - ($userGiftCrowdBalance['crowd_price'] ?? 0);
//                        if ((string)$lastGiftCrowd < (string)$data['total_price']) {
//                            throw new FinanceException(['msg' => '仅允许操作购物赠送的美丽金, 共计剩余' . priceFormat($lastGiftCrowd ?? 0)]);
//                        }
                        if ((!empty(doubleval($userGiftCrowdBalance['crowd_price'] ?? 0)) && doubleval($lastGiftCrowd) < 0) || (empty(doubleval($userGiftCrowdBalance['crowd_price'] ?? 0)) && doubleval($lastGiftCrowd) > 0)) {
                            throw new FinanceException(['msg' => '福利参与额度不足,请继续参与']);
                            $this->handingScale = 0.3;
                            $isCrowdJoinNotEnoughWithdraw = true;
                        }
                    }
                }
            }

            //判断是否需要部分金额转化为美丽券, 目前只有在团队提现才需要部分比例转化为美丽券, 先扣美丽券然后剩下的金额在算手续费
            if ($data['withdraw_type'] == 5) {
                $withdrawTicketScale = SystemConfig::where(['id' => 1])->value('withdraw_ticket_scale');
                if (doubleval($withdrawCount) > 0) {
                    $withdrawTicketNumber = priceFormat($data['total_price'] * $withdrawTicketScale);
                    $data['total_price'] = $data['total_price'] - $withdrawTicketNumber;
                    $data['ticket_price'] = $withdrawTicketNumber;
                }
            }

            $handingScale = $this->handingScale;
            //扣除提现手续费
            $data['handing_scale'] = $handingScale;

            $data['handing_fee'] = priceFormat($data['total_price'] * $this->handingScale);

            if (!empty($this->handingScale)) {
                if (!empty($this->leastHandingFree)) {
                    //提现手续费最低价
                    $data['handing_fee'] = $data['handing_fee'] < $this->leastHandingFree ? $this->leastHandingFree : $data['handing_fee'];
                }
                $data['price'] = priceFormat($data['total_price'] - $data['handing_fee']);
            } else {
                $data['price'] = $data['total_price'];
            }

            $this->checkWithdrawPrice($data);

            $userInfo = (new User())->getUserProtectionInfo($data['uid']);
            $add['order_sn'] = (new CodeBuilder())->buildWithdrawOrderNo();
            $add['uid'] = $data['uid'];
            $add['user_phone'] = $userInfo['phone'];
            $add['user_no'] = isset($userZsk['user_no']) ? privateDecrypt($userZsk['user_no']) : "";
            $add['user_real_name'] = $data['real_name'];
            $add['withdraw_type'] = $data['withdraw_type'];
            $add['total_price'] = priceFormat($data['total_price']);
            $add['handing_scale'] = priceFormat($data['handing_scale'] ?? 0);
            $add['handing_fee'] = priceFormat($data['handing_fee'] ?? 0);
            $add['price'] = priceFormat($data['price']);
            $add['ticket_price'] = priceFormat($data['ticket_price'] ?? 0);
            $add['type'] = $data['type'] ?? 2;
            if ($add['type'] == 2) {
                if (!$this->checkBankAccount(trim($data['bank_account']))) {
                    throw new FinanceException(['errorCode' => 1700105]);
                }
                $add['bank_account'] = $data['bank_account'];
            }
            //如果为特殊高额手续费则记录类型, 现在默认special_fee_type为1 1为众筹参与不够
            if (!empty($isCrowdJoinNotEnoughWithdraw ?? false)) {
                $add['is_special_fee'] = 1;
                $add['special_fee_type'] = 1;
            }
            //是否用了关联帐号的身份信息
            $add['related_user'] = $data['related_user'] ?? null;


            //修改用户金额资料(冻结待提现的余额)
            switch ($data['withdraw_type']) {
                case 1:
                    throw new FinanceException(['msg' => '不支持的提现方式']);
//                    $user['avaliable_balance'] = priceFormat($userInfo['avaliable_balance'] - $add['total_price']);
//                    $user['total_balance'] = priceFormat($userInfo['total_balance'] - $add['total_price']);
//                    $user['fronzen_balance'] = priceFormat($userInfo['fronzen_balance'] + $add['total_price']);
//                    $userInfo['nowCanUseBalance'] = $user['avaliable_balance'] + $userInfo['ad_balance'];
                    break;
                case 2:
                    //优先扣除商城分润奖金(商城钱包),不够则继续扣除拼拼有礼奖金
                    $divideBalance = (new BalanceDetail())->getAllBalanceByUid($data['uid'], [1, 2, 3, 4, 5, 7, 11, 13, 22, 23, 24]);
                    $ppylBalance = (new PpylBalanceDetail())->getAllBalanceByUid($data['uid']);

                    if ((string)$add['total_price'] <= (string)$divideBalance) {
                        $user['divide_balance'] = priceFormat($userInfo['divide_balance'] - $add['total_price']);
                        $user['divide_fronzen_balance'] = priceFormat($userInfo['divide_fronzen_balance'] + $add['total_price']);
                        $add['divide_price'] = $add['total_price'];
                        $add['ppyl_price'] = 0;
                        $add['shareholder_price'] = 0;
                        $add['area_price'] = 0;
                        $userInfo['nowCanUseBalance'] = 0;
                    } else {
                        $dividePrice = $userInfo['divide_balance'];
                        $ppylPrice = $add['total_price'] - $dividePrice;
                        $user['divide_balance'] = priceFormat($userInfo['divide_balance'] - $dividePrice);
                        $user['divide_fronzen_balance'] = priceFormat($userInfo['divide_fronzen_balance'] + $dividePrice);

                        $user['ppyl_balance'] = priceFormat($userInfo['ppyl_balance'] - $ppylPrice);
                        $user['ppyl_fronzen_balance'] = priceFormat($userInfo['ppyl_fronzen_balance'] + $ppylPrice);

                        $add['divide_price'] = $dividePrice;
                        $add['ppyl_price'] = $ppylPrice ?? 0;
                        $add['shareholder_price'] = 0;
                        $add['area_price'] = 0;
                        $userInfo['nowCanUseBalance'] = $user['ppyl_balance'] + $userInfo['ad_balance'];
                    }
                    break;
                case 3:
                    $user['divide_balance'] = priceFormat($userInfo['divide_balance'] - $add['total_price']);
                    $user['divide_fronzen_balance'] = priceFormat($userInfo['divide_fronzen_balance'] + $add['total_price']);
                    $userInfo['nowCanUseBalance'] = $user['divide_balance'] + $userInfo['ppyl_balance'] + $userInfo['ad_balance'];
                    break;
                case 4:
                    $user['ppyl_balance'] = priceFormat($userInfo['ppyl_balance'] - $add['total_price']);
                    $user['ppyl_fronzen_balance'] = priceFormat($userInfo['ppyl_fronzen_balance'] + $add['total_price']);
                    $userInfo['nowCanUseBalance'] = $userInfo['divide_balance'] + $user['ppyl_balance'] + $userInfo['ad_balance'];
                    break;
                case 5:
                    //优先扣除广宣奖金,不够则继续扣除团队业绩奖金,再不够继续扣除股东奖业绩,还不够在扣除区代奖
                    $adBalance = (new BalanceDetail())->getAllBalanceByUid($data['uid'], [12, 15, 27, 28]);
                    $teamBalance = (new BalanceDetail())->getAllBalanceByUid($data['uid'], [16, 17, 29, 30]);
                    $shareholderBalance = (new BalanceDetail())->getAllBalanceByUid($data['uid'], [18, 19, 31, 32]);
                    $areaBalance = (new BalanceDetail())->getAllBalanceByUid($data['uid'], [20, 21, 33, 34]);
                    if ((string)$add['total_price'] <= (string)$adBalance) {
                        $user['ad_balance'] = priceFormat($userInfo['ad_balance'] - $add['total_price']);
                        $user['ad_fronzen_balance'] = priceFormat($userInfo['ad_fronzen_balance'] + $add['total_price']);
                        $add['ad_price'] = $add['total_price'];
                        $add['team_price'] = 0;
                        $add['area_price'] = 0;
                        $userInfo['nowCanUseBalance'] = 0;
                    } elseif ((string)$add['total_price'] <= (string)($adBalance + $teamBalance)) {
                        $adPrice = $userInfo['ad_balance'];
                        $teamPrice = $add['total_price'] - $adPrice;
                        $user['ad_balance'] = priceFormat($userInfo['ad_balance'] - $adPrice);
                        $user['ad_fronzen_balance'] = priceFormat($userInfo['ad_fronzen_balance'] + $adPrice);

                        $user['team_balance'] = priceFormat($userInfo['team_balance'] - $teamPrice);
                        $user['team_fronzen_balance'] = priceFormat($userInfo['team_fronzen_balance'] + $teamPrice);

                        $add['ad_price'] = $adPrice;
                        $add['team_price'] = $teamPrice ?? 0;
                        $add['area_price'] = 0;
                        $userInfo['nowCanUseBalance'] = $user['ad_balance'] + $userInfo['team_balance'];
                    } elseif ((string)$add['total_price'] <= (string)($adBalance + $teamBalance + $shareholderBalance)) {
                        $adPrice = $userInfo['ad_balance'];
                        $teamPrice = $userInfo['team_balance'];
                        $shareholderPrice = $add['total_price'] - $adPrice - $teamPrice;
                        $user['ad_balance'] = priceFormat($userInfo['ad_balance'] - $adPrice);
                        $user['ad_fronzen_balance'] = priceFormat($userInfo['ad_fronzen_balance'] + $adPrice);

                        $user['team_balance'] = priceFormat($userInfo['team_balance'] - $teamPrice);
                        $user['team_fronzen_balance'] = priceFormat($userInfo['team_fronzen_balance'] + $teamPrice);

                        $user['shareholder_balance'] = priceFormat($userInfo['shareholder_balance'] - $shareholderPrice);
                        $user['shareholder_fronzen_balance'] = priceFormat($userInfo['shareholder_fronzen_balance'] + $shareholderPrice);

                        $add['ad_price'] = $adPrice;
                        $add['team_price'] = $teamPrice ?? 0;
                        $add['shareholder_price'] = $shareholderPrice ?? 0;
                        $add['area_price'] = 0;
                        $userInfo['nowCanUseBalance'] = $user['ad_balance'] + $user['team_balance'] + $user['shareholder_balance'];
                    } else {
                        $adPrice = $userInfo['ad_balance'];
                        $teamPrice = $userInfo['team_balance'];
                        $shareholderPrice = $userInfo['shareholder_balance'];
                        $areaPrice = $add['total_price'] - $adPrice - $teamPrice - $shareholderPrice;
                        $user['ad_balance'] = priceFormat($userInfo['ad_balance'] - $adPrice);
                        $user['ad_fronzen_balance'] = priceFormat($userInfo['ad_fronzen_balance'] + $adPrice);

                        $user['team_balance'] = priceFormat($userInfo['team_balance'] - $teamPrice);
                        $user['team_fronzen_balance'] = priceFormat($userInfo['team_fronzen_balance'] + $teamPrice);

                        $user['shareholder_balance'] = priceFormat($userInfo['shareholder_balance'] - $shareholderPrice);
                        $user['shareholder_fronzen_balance'] = priceFormat($userInfo['shareholder_fronzen_balance'] + $shareholderPrice);

                        $user['area_balance'] = priceFormat($userInfo['area_balance'] - $areaPrice);
                        $user['area_fronzen_balance'] = priceFormat($userInfo['area_fronzen_balance'] + $areaPrice);

                        $add['ad_price'] = $adPrice;
                        $add['team_price'] = $teamPrice ?? 0;
                        $add['shareholder_price'] = $shareholderPrice ?? 0;
                        $add['area_price'] = $areaPrice ?? 0;
                        $userInfo['nowCanUseBalance'] = $user['ad_balance'] + $user['team_balance'] + $user['shareholder_balance'] + $user['area_balance'];
                    }
                    break;
                case 6:
                    //单独广宣奖
                    $user['ad_balance'] = priceFormat($userInfo['ad_balance'] - $add['total_price']);
                    $user['ad_fronzen_balance'] = priceFormat($userInfo['ad_fronzen_balance'] + $add['total_price']);
                    $userInfo['nowCanUseBalance'] = $userInfo['divide_balance'] + $userInfo['ppyl_balance'] + $user['ad_balance'];
                    $add['ad_price'] = $add['total_price'];
                    $add['divide_price'] = 0;
                    $add['ppyl_price'] = 0;
                    $add['shareholder_price'] = 0;
                    $add['area_price'] = 0;
                    break;
                case 7:
                    //众筹钱包提现
                    $add['crowd_price'] = $add['total_price'];;
                    $user['crowd_balance'] = priceFormat($userInfo['crowd_balance'] - $add['total_price']);
                    $user['crowd_fronzen_balance'] = priceFormat($userInfo['crowd_fronzen_balance'] + $add['total_price']);
                    $userInfo['nowCanUseBalance'] = $userInfo['crowd_balance'];
                    break;
                default:
                    throw new ServiceException(['msg' => '非法类型']);
            }
            if ((!empty($user['ad_balance']) && $user['ad_balance'] < 0) || (!empty($user['divide_balance']) && $user['divide_balance'] < 0) || (!empty($user['ppyl_balance']) && $user['ppyl_balance'] < 0) || (!empty($user['shareholder_balance']) && $user['shareholder_balance'] < 0) || (!empty($user['team_balance']) && $user['team_balance'] < 0) || (!empty($user['area_balance']) && $user['area_balance'] < 0) || (!empty($user['crowd_balance']) && $user['crowd_balance'] < 0)) {
                throw new ServiceException(['msg' => '系统风控拦截~请联系客服']);
            }
//            $res = (new Withdraw())->new($add);
            $add['create_time'] = time();
            $add['update_time'] = $add['create_time'] ?? time();
            $res = Db::name('withdraw')->insertGetId($add);

            //如果此次银行卡帐号不同, 则修改保存, 下次提现复用此次的
            if (!empty($data['bank_account'] ?? null) && $userInfo['withdraw_bank_card'] != trim($data['bank_account'])) {
                $user['withdraw_bank_card'] = str_replace(' ', '', trim($data['bank_account']));
            }

//            User::update($user, ['uid' => $data['uid'], 'status' => 1]);
            Db::name('user')->where(['uid' => $data['uid'], 'status' => 1])->update($user);
            return ['withdrawInfo' => $add, 'userInfo' => $userInfo];
        });
        //推送模版消息通知
        $template['uid'] = $res['userInfo']['uid'];
        $template['type'] = 'withdrawApply';
        $price = priceFormat($res['withdrawInfo']['price']);
        $handingScale = priceFormat($res['withdrawInfo']['handing_scale']);
        switch ($data['type'] ?? 2) {
            case 1:
                $bankAccount = '微信零钱';
                break;
            case 2:
                $bankAccount = '银行卡';
                break;
            default:
                $bankAccount = '未知';
        }
        $nowPrice = priceFormat($res['userInfo']['nowCanUseBalance']);


        $template['template'] = ['amount1' => $data['total_price'], 'character_string3' => $handingScale, 'amount2' => $price, 'thing5' => '管理员将进行审核,审核结果将另行通知'];
        $templateId = config('system.templateId');
        $res['templateId'] = [$templateId['withdrawApply'] ?? null, $templateId['withdrawStatus'] ?? null];

//        Queue::later(10,'app\lib\job\Template',$template,config('system.queueAbbr') . 'TemplateList');
        return $res;
    }

    /**
     * @title  管理员审核提现
     * @param array $data
     * @return mixed
     */
    public function checkUserWithdraw(array $data)
    {
        $info = (new Withdraw())->info($data['id']);
        if (empty($info)) {
            throw new FinanceException(['errorCode' => 1700103]);
        }
        if ($info['check_status'] != 3) {
            throw new FinanceException(['msg' => '提现记录非待审核,信息可能存在延迟, 请稍后刷新']);
        }
//        if (doubleval($info['area_price']) > 0) {
//            throw new ServiceException(['msg'=>'数据异常待确认中,暂无法操作']);
//        }
        $success = false;
        switch ($data['check_status']) {
            case 1:
                throw new FinanceException(['msg' => '已停用本通道, 请选择其他平台打款']);
                if (doubleval($info['total_price']) > 500) {
                    throw new FinanceException(['msg' => '高于500元金额请使用快商提现通道']);
                }
                //$aUserInfo = (new User())->getUserProtectionInfo($info['uid']);

                //修改审核状态
                $info['remark'] = '管理员已通过您的提现审核,请查看账户到账';
                $save['remark'] = '管理员已通过您的提现审核,请查看账户到账';
                $save['check_status'] = 1;
                $save['check_time'] = time();
                $withdrawRes = Withdraw::update($save, ['id' => $info['id']]);

                //企业付款到零钱
                $data['partner_trade_no'] = $info['order_sn'];
                $data['amount'] = $info['price'];
                //$data['openid'] = $aUserInfo['openid'];
//                $data['desc'] = config('system.projectName') . '激励计划奖金到账啦~';
                $data['desc'] = config('system.projectName');
                $data['re_user_name'] = $info['user_real_name'];
                if ($info['type'] == 2) {
                    $data['bank_account'] = $info['bank_account'];
                }

                switch (config('system.thirdPayType') ?? 2) {
                    case 1:
                        throw new FinanceException(['msg' => '暂不支持的支付商通道']);
                        break;
                    case 2:
                        $paymentRes = (new JoinPay())->enterprisePayment($data);
                        break;
                    case 3:
                        $paymentRes = (new SandPay())->enterprisePayment($data);
                        break;
                    default:
                        throw new FinanceException(['msg' => '未知支付商通道']);
                }
                if ($info['type'] == 1) {
                    $res = $this->completeWithdraw($info, $paymentRes);
                } elseif ($info['type'] == 2) {
                    $res = $paymentRes;
                }
                $success = !empty($res) ? judge($res) : $res;
                break;
            case 2:
                $res = Db::transaction(function () use ($data, $info) {
                    //修改审核状态
                    $info['remark'] = $data['remark'] ?? '您的申请未通过管理员审核';
                    $save['remark'] = $data['remark'] ?? '您的申请未通过管理员审核';
                    $save['check_status'] = $data['check_status'];
                    $save['check_time'] = time();
                    $res = Withdraw::update($save, ['id' => $data['id']]);

                    //$allPrice = $info['price'] + $info['handing_fee'];
                    //修改用户金额资料(解除冻结余额)
                    $aUserInfo = (new User())->getUserProtectionInfo($info['uid']);
                    if (empty($aUserInfo)) {
                        throw new ServiceException(['msg' => '不存在的用户信息 ' . ($info['user_real_name'] ?? '未知用户姓名')]);
                    }
                    switch ($info['withdraw_type']) {
                        case 1:
                            //原始大余额钱包提现---暂时已废除
                            throw new ServiceException(['msg' => '暂不支持的类型, 请联系运维人员']);
                            if (($aUserInfo['fronzen_balance'] - $info['total_price']) < 0) {
                                throw new FinanceException(['msg' => '用户冻结余额有误']);
                            }
                            $user['avaliable_balance'] = priceFormat
                            ($aUserInfo['avaliable_balance'] + $info['total_price']);
                            $user['total_balance'] = priceFormat($aUserInfo['total_balance'] + $info['total_price']);
                            $user['fronzen_balance'] = priceFormat($aUserInfo['fronzen_balance'] - $info['total_price']);
                            break;
                        case 2:
                            //商城和拼拼混合提现
                            if (!empty($info['divide_price'])) {
                                if (($aUserInfo['divide_fronzen_balance'] - $info['divide_price']) < 0) {
                                    throw new FinanceException(['msg' => '用户分佣冻结余额有误']);
                                }
                                $user['divide_balance'] = priceFormat
                                ($aUserInfo['divide_balance'] + $info['divide_price']);
                                $user['divide_fronzen_balance'] = priceFormat($aUserInfo['divide_fronzen_balance'] - $info['divide_price']);
                            }
                            if (!empty($info['ppyl_price'])) {
                                if (($aUserInfo['ppyl_fronzen_balance'] - $info['ppyl_price']) < 0) {
                                    throw new FinanceException(['msg' => '用户拼拼冻结余额有误']);
                                }
                                $user['ppyl_balance'] = priceFormat
                                ($aUserInfo['ppyl_balance'] + $info['ppyl_price']);
                                $user['ppyl_fronzen_balance'] = priceFormat($aUserInfo['ppyl_fronzen_balance'] - $info['ppyl_price']);
                            }
                            break;
                        case 3:
                            //单独分佣
                            if (($aUserInfo['divide_fronzen_balance'] - $info['divide_price']) < 0) {
                                throw new FinanceException(['msg' => '用户分佣冻结余额有误']);
                            }
                            $user['divide_balance'] = priceFormat
                            ($aUserInfo['divide_balance'] + $info['divide_price']);
                            $user['divide_fronzen_balance'] = priceFormat($aUserInfo['divide_fronzen_balance'] - $info['divide_price']);
                            break;
                        case 4:
                            //单独拼拼
                            if (($aUserInfo['ppyl_fronzen_balance'] - $info['ppyl_price']) < 0) {
                                throw new FinanceException(['msg' => '用户拼拼冻结余额有误']);
                            }
                            $user['ppyl_balance'] = priceFormat
                            ($aUserInfo['ppyl_balance'] + $info['ppyl_price']);
                            $user['ppyl_fronzen_balance'] = priceFormat($aUserInfo['ppyl_fronzen_balance'] - $info['ppyl_price']);
                            break;
                        case 5:
                            //H5端 广宣和团队业绩和股东奖混合提现
                            if (!empty($info['ad_price'])) {
                                if (($aUserInfo['ad_fronzen_balance'] - $info['ad_price']) < 0) {
                                    throw new FinanceException(['msg' => '用户广宣奖模块分佣冻结余额有误']);
                                }
                                $user['ad_balance'] = priceFormat
                                ($aUserInfo['ad_balance'] + $info['ad_price']);
                                $user['ad_fronzen_balance'] = priceFormat($aUserInfo['ad_fronzen_balance'] - $info['ad_price']);
                            }
                            if (!empty($info['team_price'])) {
                                if (($aUserInfo['team_fronzen_balance'] - $info['team_price']) < 0) {
                                    throw new FinanceException(['msg' => '用户团队业绩模块冻结余额有误']);
                                }
                                $user['team_balance'] = priceFormat
                                ($aUserInfo['team_balance'] + $info['team_price']);
                                $user['team_fronzen_balance'] = priceFormat($aUserInfo['team_fronzen_balance'] - $info['team_price']);
                            }

                            if (!empty($info['shareholder_price'] ?? 0)) {
                                if (($aUserInfo['shareholder_fronzen_balance'] - $info['shareholder_price']) < 0) {
                                    throw new FinanceException(['msg' => '用户股东奖模块分佣冻结余额有误']);
                                }
                                $user['shareholder_balance'] = priceFormat
                                ($aUserInfo['shareholder_balance'] + $info['shareholder_price']);
                                $user['shareholder_fronzen_balance'] = priceFormat($aUserInfo['shareholder_fronzen_balance'] - $info['shareholder_price']);
                            }

                            if (!empty($info['area_price'] ?? 0)) {
                                if (($aUserInfo['area_fronzen_balance'] - $info['area_price']) < 0) {
                                    throw new FinanceException(['msg' => '用户区代模块分佣冻结余额有误']);
                                }
                                $user['area_balance'] = priceFormat
                                ($aUserInfo['area_balance'] + $info['area_price']);
                                $user['area_fronzen_balance'] = priceFormat($aUserInfo['area_fronzen_balance'] - $info['area_price']);
                            }
                            //如果存在美丽券的额度默认归给团队, 因为子公司项目没有其他的奖项
                            if (!empty($info['ticket_price'] ?? 0)) {
                                if (!isset($user['team_balance'])) {
                                    $user['team_balance'] = priceFormat
                                    ($aUserInfo['team_balance'] + $info['ticket_price']);
                                } else {
                                    $user['team_balance'] = priceFormat
                                    (($user['team_balance'] ?? 0) + $info['ticket_price']);
                                }
                            }

                            //返回操作额度
                            $rMap[] = ['start_time_period', '=', strtotime(date('Y-m-d', strtotime($info['create_time'])) . ' 00:00:00')];
                            $rMap[] = ['status', '=', 1];
                            $rMap[] = ['uid', '=', $info['uid']];
                            RechargeLinkCumulative::where($rMap)->inc('price', $info['total_price'])->update();
                            RechargeLinkCumulative::where($rMap)->dec('oper_price', $info['total_price'])->update();
                            break;
                        case 6:
                            //广宣奖
                            if (($aUserInfo['ad_fronzen_balance'] - $info['ad_price']) < 0) {
                                throw new FinanceException(['msg' => '用户广宣冻结余额有误']);
                            }
                            $user['ad_balance'] = priceFormat
                            ($aUserInfo['ad_balance'] + $info['ad_price']);
                            $user['ad_fronzen_balance'] = priceFormat($aUserInfo['ad_fronzen_balance'] - $info['ad_price']);
                            break;
                        case 7:
                            //众筹本金和奖金
                            //单独分佣
                            if (($aUserInfo['crowd_fronzen_balance'] - $info['total_price']) < 0) {
                                throw new FinanceException(['msg' => '用户福利钱包冻结余额有误']);
                            }
                            $user['crowd_balance'] = priceFormat
                            ($aUserInfo['crowd_balance'] + $info['total_price']);
                            $user['crowd_fronzen_balance'] = priceFormat($aUserInfo['crowd_fronzen_balance'] - $info['total_price']);

                            //如果存在美丽券的额度归还给众筹余额
                            if (!empty(doubleval($info['ticket_price'] ?? 0))) {
                                if (!isset($user['crowd_balance'])) {
                                    $user['crowd_balance'] = priceFormat
                                    ($aUserInfo['crowd_balance'] + $info['ticket_price']);
                                } else {
                                    $user['crowd_balance'] = priceFormat
                                    (($user['crowd_balance'] ?? 0) + $info['ticket_price']);
                                }
                            }
                            break;
                        default:
                            throw new ServiceException(['msg' => '非法的类型']);
                    }
                    if (!empty($user)) {
                        User::update($user, ['uid' => $info['uid'], 'status' => 1]);

                    }
                    return ['withdrawInfo' => $info, 'wxRes' => [], 'userInfo' => $aUserInfo];
                });
                break;
            case 3:
                //$aUserInfo = (new User())->getUserProtectionInfo($info['uid']);
                //快商付钱
//                $data['partner_trade_no'] = $info['order_sn'];
//                $data['amount'] = $info['price'];
//                //$data['openid'] = $aUserInfo['openid'];
//                $data['desc'] = config('system.projectName') . '激励计划奖金到账啦~';
//                $data['re_user_name'] = $info['user_real_name'];
//                if ($info['type'] == 2) {
//                    $data['bank_account'] = $info['bank_account'];
//                }
//                $paymentRes = (new JoinPay())->enterprisePayment($data);
//                if (doubleval($info['total_price']) <= 500) {
//                    throw new FinanceException(['msg' => '低于500元金额请使用汇聚提现通道']);
//                }
                $res = Db::transaction(function () use ($info, $data) {
                    //修改审核状态
                    $info['remark'] = '管理员已通过您的提现审核,请查看账户到账';
                    $save['remark'] = '管理员已通过您的提现审核,请查看账户到账';
                    $save['check_status'] = 1;
                    $save['check_time'] = time();
                    $save['pay_channel'] = $data['pay_channel'] ?? 88;
                    $withdrawRes = Withdraw::update($save, ['id' => $info['id']]);

                    //直接完成提现
//                if (empty($data['payment_no'] ?? null)) {
//                    throw new FinanceException(['msg' => '请填写打款支付流水号']);
//                }
//                $paymentRes['payment_no'] = $data['payment_no'];
                    $paymentRes['payment_no'] = $data['payment_no'] ?? '暂无流水号';
                    $res = $this->completeWithdraw($info, $paymentRes);

//                if ($info['type'] == 1) {
//                    $res = $this->completeWithdraw($info, $paymentRes);
//                } elseif ($info['type'] == 2) {
//                    $res = $paymentRes;
//                }
                    return $res;
                });

                $success = !empty($res) ? judge($res) : $res;
                break;
            default:
                $res = false;
        }
        //推送模版消息通知
        $template['uid'] = $info['uid'];
        $price = priceFormat($info['price'] ?? 0);
        switch ($info['type'] ?? 2) {
            case 1:
                $accountType = '微信零钱';
                break;
            case 2:
                $accountType = '银行卡';
                break;
            default:
                $accountType = '未知';
        }

        //推送模版消息通知
        if ($success == true) {
            $template['type'] = 'withdrawStatus';
            $template['template'] = ['amount1' => $price, 'phrase2' => $accountType, 'phrase5' => '已通过', 'date3' => $res['withdrawInfo']['create_time'], 'remark' => '实际到账可能存在延迟，请稍后查看账户余额'];
        } else {
            $template['type'] = 'withdrawStatus';
            $template['template'] = ['amount1' => $price, 'phrase2' => $accountType, 'phrase5' => '未通过', 'date3' => $res['withdrawInfo']['create_time'], 'remark' => '申请已被驳回,请查看平台提现规则后重试'];
        }

//        if (!empty($template['type'])) {
//             Queue::push('app\lib\job\Template',$template,config('system.queueAbbr') . 'TemplateList');
//        }


        return $res;
    }

    /**
     * @title  管理员批量审核提现
     * @param array $data
     * @return mixed
     */
    public function batchCheckUserWithdraw(array $data)
    {
        /**  限制接口调用频率 START */
        functionLimit(WithdrawConstant::WITHDRAW_BATCH_CHECK_LOCK,WithdrawConstant::WITHDRAW_BATCH_CHECK_TIME);
//        $key = WithdrawConstant::WITHDRAW_BATCH_CHECK_LOCK;
//        if (Cache::store('redis')->get($key)) {
//            throw new ServiceException(['errorCode' => 400103]);
//        } else {
//            Cache::store('redis')->set($key, 1, WithdrawConstant::WITHDRAW_BATCH_CHECK_TIME);
//        }

        /**  限制接口调用频率 END*/
        try {
            $list = $data['list'];
            //check_status 1为汇聚直接打款 2为拒绝申请 3为其他途径打款(或为导表形式)
            //pay_channel  1为微信支付 2为汇聚支付 3为杉德支付通道 88为线下打款 4为快商 5为中数科
            foreach ($list as $key => $value) {
                if (empty($value['withdraw_id'] ?? null)) {
                    throw new ServiceException(['msg' => '存在异常数据哦']);
                }
            }
            $allWithdrawInfo = Withdraw::where(['id' => array_unique(array_column($list, 'withdraw_id')), 'status' => 1, 'check_status' => 3])->withoutField('update_time')->select()->toArray();
            if (count($allWithdrawInfo) != count($list)) {
                throw new FinanceException(['msg' => '表中存在非待审核状态的订单, 请核对后重新提交审核']);
            }
            foreach ($allWithdrawInfo as $key => $value) {
                $allWithdrawInfos[$value['id']] = $value;
            }

            $res = Db::transaction(function () use ($list, $data, $allWithdrawInfos) {
                foreach ($list as $key => $value) {
                    $info = $allWithdrawInfos[$value['withdraw_id']] ?? [];
                    if (empty($info)) {
                        continue;
                    }
                    $success = false;
                    switch ($data['check_status']) {
                        case 1:
                            throw new FinanceException(['msg' => '不支持的审核类型']);
                            break;
                        case 2:
//                        throw new FinanceException(['msg'=>'不支持的审核类型']);
                            //修改审核状态
                            $info['remark'] = $value['remark'] ?? '您的申请未通过管理员审核';
                            $save['remark'] = $value['remark'] ?? '您的申请未通过管理员审核';
                            $save['check_status'] = $data['check_status'];
                            $save['check_time'] = time();
                            $res = Withdraw::update($save, ['id' => $value['withdraw_id']]);

                            //$allPrice = $info['price'] + $info['handing_fee'];
                            //修改用户金额资料(解除冻结余额)
                            $aUserInfo = (new User())->getUserProtectionInfo($info['uid']);
                            if (empty($aUserInfo)) {
                                throw new ServiceException(['msg' => '不存在的用户信息 ' . ($info['user_real_name'] ?? '未知用户姓名')]);
                            }
                            switch ($info['withdraw_type']) {
                                case 1:
                                    //原始大余额钱包提现---暂时已废除
                                    throw new ServiceException(['msg' => '暂不支持的类型, 请联系运维人员']);
                                    if (($aUserInfo['fronzen_balance'] - $info['total_price']) < 0) {
                                        throw new FinanceException(['msg' => '用户冻结余额有误']);
                                    }
                                    $user['avaliable_balance'] = priceFormat
                                    ($aUserInfo['avaliable_balance'] + $info['total_price']);
                                    $user['total_balance'] = priceFormat($aUserInfo['total_balance'] + $info['total_price']);
                                    $user['fronzen_balance'] = priceFormat($aUserInfo['fronzen_balance'] - $info['total_price']);
                                    break;
                                case 2:
                                    //商城和拼拼混合提现
                                    if (!empty($info['divide_price'])) {
                                        if (($aUserInfo['divide_fronzen_balance'] - $info['divide_price']) < 0) {
                                            throw new FinanceException(['msg' => '用户分佣冻结余额有误']);
                                        }
                                        $user['divide_balance'] = priceFormat
                                        ($aUserInfo['divide_balance'] + $info['divide_price']);
                                        $user['divide_fronzen_balance'] = priceFormat($aUserInfo['divide_fronzen_balance'] - $info['divide_price']);
                                    }
                                    if (!empty($info['ppyl_price'])) {
                                        if (($aUserInfo['ppyl_fronzen_balance'] - $info['ppyl_price']) < 0) {
                                            throw new FinanceException(['msg' => '用户拼拼冻结余额有误']);
                                        }
                                        $user['ppyl_balance'] = priceFormat
                                        ($aUserInfo['ppyl_balance'] + $info['ppyl_price']);
                                        $user['ppyl_fronzen_balance'] = priceFormat($aUserInfo['ppyl_fronzen_balance'] - $info['ppyl_price']);
                                    }
                                    break;
                                case 3:
                                    //单独分佣
                                    if (($aUserInfo['divide_fronzen_balance'] - $info['divide_price']) < 0) {
                                        throw new FinanceException(['msg' => '用户分佣冻结余额有误']);
                                    }
                                    $user['divide_balance'] = priceFormat
                                    ($aUserInfo['divide_balance'] + $info['divide_price']);
                                    $user['divide_fronzen_balance'] = priceFormat($aUserInfo['divide_fronzen_balance'] - $info['divide_price']);
                                    break;
                                case 4:
                                    //单独拼拼
                                    if (($aUserInfo['ppyl_fronzen_balance'] - $info['ppyl_price']) < 0) {
                                        throw new FinanceException(['msg' => '用户拼拼冻结余额有误']);
                                    }
                                    $user['ppyl_balance'] = priceFormat
                                    ($aUserInfo['ppyl_balance'] + $info['ppyl_price']);
                                    $user['ppyl_fronzen_balance'] = priceFormat($aUserInfo['ppyl_fronzen_balance'] - $info['ppyl_price']);
                                    break;
                                case 5:
                                    //H5端 广宣和团队业绩和股东奖混合提现
                                    if (!empty($info['ad_price'])) {
                                        if (($aUserInfo['ad_fronzen_balance'] - $info['ad_price']) < 0) {
                                            throw new FinanceException(['msg' => '用户广宣奖模块分佣冻结余额有误']);
                                        }
                                        $user['ad_balance'] = priceFormat
                                        ($aUserInfo['ad_balance'] + $info['ad_price']);
                                        $user['ad_fronzen_balance'] = priceFormat($aUserInfo['ad_fronzen_balance'] - $info['ad_price']);
                                    }
                                    if (!empty($info['team_price'])) {
                                        if (($aUserInfo['team_fronzen_balance'] - $info['team_price']) < 0) {
                                            throw new FinanceException(['msg' => '用户团队业绩模块冻结余额有误']);
                                        }
                                        $user['team_balance'] = priceFormat
                                        ($aUserInfo['team_balance'] + $info['team_price']);
                                        $user['team_fronzen_balance'] = priceFormat($aUserInfo['team_fronzen_balance'] - $info['team_price']);
                                    }

                                    if (!empty($info['shareholder_price'] ?? 0)) {
                                        if (($aUserInfo['shareholder_fronzen_balance'] - $info['shareholder_price']) < 0) {
                                            throw new FinanceException(['msg' => '用户股东奖模块分佣冻结余额有误']);
                                        }
                                        $user['shareholder_balance'] = priceFormat
                                        ($aUserInfo['shareholder_balance'] + $info['shareholder_price']);
                                        $user['shareholder_fronzen_balance'] = priceFormat($aUserInfo['shareholder_fronzen_balance'] - $info['shareholder_price']);
                                    }

                                    if (!empty($info['area_price'] ?? 0)) {
                                        if (($aUserInfo['area_fronzen_balance'] - $info['area_price']) < 0) {
                                            throw new FinanceException(['msg' => '用户区代模块分佣冻结余额有误']);
                                        }
                                        $user['area_balance'] = priceFormat
                                        ($aUserInfo['area_balance'] + $info['area_price']);
                                        $user['area_fronzen_balance'] = priceFormat($aUserInfo['area_fronzen_balance'] - $info['area_price']);
                                    }
                                    //如果存在美丽券的额度默认归给团队, 因为子公司项目没有其他的奖项
                                    if (!empty($info['ticket_price'] ?? 0)) {
                                        if (!isset($user['team_balance'])) {
                                            $user['team_balance'] = priceFormat
                                            ($aUserInfo['team_balance'] + $info['ticket_price']);
                                        } else {
                                            $user['team_balance'] = priceFormat
                                            (($user['team_balance'] ?? 0) + $info['ticket_price']);
                                        }
                                    }
                                    //返回操作额度
                                    $rMap[] = ['start_time_period', '=', strtotime(date('Y-m-d', strtotime($info['create_time'])) . ' 00:00:00')];
                                    $rMap[] = ['status', '=', 1];
                                    $rMap[] = ['uid', '=', $info['uid']];
                                    RechargeLinkCumulative::where($rMap)->inc('price', $info['total_price'])->update();
                                    RechargeLinkCumulative::where($rMap)->dec('oper_price', $info['total_price'])->update();
                                    break;
                                case 6:
                                    //广宣奖
                                    if (($aUserInfo['ad_fronzen_balance'] - $info['ad_price']) < 0) {
                                        throw new FinanceException(['msg' => '用户广宣冻结余额有误']);
                                    }
                                    $user['ad_balance'] = priceFormat
                                    ($aUserInfo['ad_balance'] + $info['ad_price']);
                                    $user['ad_fronzen_balance'] = priceFormat($aUserInfo['ad_fronzen_balance'] - $info['ad_price']);
                                    break;
                                case 7:
                                    //众筹本金和奖金
                                    //单独分佣
                                    if (($aUserInfo['crowd_fronzen_balance'] - $info['total_price']) < 0) {
                                        throw new FinanceException(['msg' => '用户福利钱包冻结余额有误']);
                                    }
                                    $user['crowd_balance'] = priceFormat
                                    ($aUserInfo['crowd_balance'] + $info['total_price']);
                                    $user['crowd_fronzen_balance'] = priceFormat($aUserInfo['crowd_fronzen_balance'] - $info['total_price']);
                                    //如果存在美丽券的额度归还给众筹余额
                                    if (!empty(doubleval($info['ticket_price'] ?? 0))) {
                                        if (!isset($user['crowd_balance'])) {
                                            $user['crowd_balance'] = priceFormat
                                            ($aUserInfo['crowd_balance'] + $info['ticket_price']);
                                        } else {
                                            $user['crowd_balance'] = priceFormat
                                            (($user['crowd_balance'] ?? 0) + $info['ticket_price']);
                                        }
                                    }
                                    break;
                                default:
                                    throw new ServiceException(['msg' => '非法的类型']);
                            }
                            if (!empty($user)) {
                                User::update($user, ['uid' => $info['uid'], 'status' => 1]);

                            }
                            break;
                        case 3:
//                if (doubleval($info['total_price']) <= 500) {
//                    throw new FinanceException(['msg' => '低于500元金额请使用汇聚提现通道']);
//                }
                            //修改审核状态
                            $info['remark'] = '管理员已通过您的提现审核,请查看账户到账';
                            $save['remark'] = '管理员已通过您的提现审核,请查看账户到账';
                            $save['check_status'] = 1;
                            $save['check_time'] = time();
                            $save['pay_channel'] = $data['pay_channel'] ?? 88;
                            $withdrawRes = Withdraw::update($save, ['id' => $value['withdraw_id']]);
                            $paymentRes['payment_no'] = $value['payment_no'] ?? '暂无流水号';
                            $res[] = $this->completeWithdraw($info, $paymentRes);
                            $success = !empty($res) ? judge($res) : $res;
                            break;
                        default:
                            $res = false;
                    }
                }
                return $res ?? [];
            });
        }catch (BaseException $base_e){
            functionLimitClear(WithdrawConstant::WITHDRAW_BATCH_CHECK_LOCK);
            throw new ServiceException(['msg'=>$base_e->msg]);
        }catch (Exception $e){
            functionLimitClear(WithdrawConstant::WITHDRAW_BATCH_CHECK_LOCK);
            throw new ServiceException(['msg'=>$e->getMessage()]);
        }
        functionLimitClear(WithdrawConstant::WITHDRAW_BATCH_CHECK_LOCK);

        return true;
    }

    /**
     * @title  完成提现状态和明细修改
     * @param array $withdrawInfo
     * @param array $paymentRes 支付结果
     * @return bool|mixed
     */
    public function completeWithdraw(array $withdrawInfo, array $paymentRes = [])
    {
        $info = $withdrawInfo;
        if (empty($info)) {
            return false;
        }

        $res = Db::transaction(function () use ($info, $paymentRes) {
            $aUserInfo = (new User())->getUserProtectionInfo($info['uid']);
            if (empty($aUserInfo)) {
                throw new ServiceException(['msg' => '不存在的用户信息 ' . ($info['user_real_name'] ?? '未知用户姓名')]);
            }
            $balanceDetail = [];
            $ppylBalanceDetail = [];
            $crowdBalanceDetail = [];
            $ticketDetail = [];
            switch ($info['withdraw_type']) {
                case 1:
                    //添加余额明细
                    $balanceDetail['uid'] = $info['uid'];
                    $balanceDetail['order_sn'] = $info['order_sn'];
                    $balanceDetail['belong'] = $this->belong;
                    $balanceDetail['type'] = 2;
                    $balanceDetail['price'] = '-' . $info['total_price'];
                    $balanceDetail['change_type'] = 4;
                    break;
                case 2:
                    if (!empty(intval($info['divide_price']))) {
                        //添加余额明细
                        $balanceDetail['uid'] = $info['uid'];
                        $balanceDetail['order_sn'] = $info['order_sn'];
                        $balanceDetail['belong'] = $this->belong;
                        $balanceDetail['type'] = 2;
                        $balanceDetail['price'] = '-' . $info['divide_price'];
                        $balanceDetail['change_type'] = 11;
                        $balanceDetail['remark'] = '佣金提现';
                    }
                    if (!empty(intval($info['ppyl_price']))) {
                        //添加拼拼余额明细
                        $ppylBalanceDetail['uid'] = $info['uid'];
                        $ppylBalanceDetail['order_sn'] = $info['order_sn'];
                        $ppylBalanceDetail['belong'] = $this->belong;
                        $ppylBalanceDetail['type'] = 2;
                        $ppylBalanceDetail['price'] = '-' . $info['ppyl_price'];
                        $ppylBalanceDetail['change_type'] = 4;
                        $ppylBalanceDetail['remark'] = '提现';
                    }
                    break;
                case 3:
                    //添加余额明细-分佣
                    $balanceDetail['uid'] = $info['uid'];
                    $balanceDetail['order_sn'] = $info['order_sn'];
                    $balanceDetail['belong'] = $this->belong;
                    $balanceDetail['type'] = 2;
                    $balanceDetail['price'] = '-' . $info['total_price'];
                    $balanceDetail['change_type'] = 11;
                    break;
                case 4:
                    //添加拼拼余额明细
                    $ppylBalanceDetail['uid'] = $info['uid'];
                    $ppylBalanceDetail['order_sn'] = $info['order_sn'];
                    $ppylBalanceDetail['belong'] = $this->belong;
                    $ppylBalanceDetail['type'] = 2;
                    $ppylBalanceDetail['price'] = '-' . $info['total_price'];
                    $ppylBalanceDetail['change_type'] = 4;
                    break;
                case 5:
                    //H5端 混合提现广宣奖和团队业绩奖和股东将
                    if (!empty($info['ad_price'])) {
                        //添加余额明细
                        $balanceDetail[0]['uid'] = $info['uid'];
                        $balanceDetail[0]['order_sn'] = $info['order_sn'];
                        $balanceDetail[0]['belong'] = $this->belong;
                        $balanceDetail[0]['type'] = 2;
                        $balanceDetail[0]['price'] = '-' . $info['ad_price'];
                        $balanceDetail[0]['change_type'] = 15;
                        $balanceDetail[0]['remark'] = '广宣奖提现';
                    }
                    if (!empty($info['team_price'])) {
                        //添加拼拼余额明细
                        $balanceDetail[1]['uid'] = $info['uid'];
                        $balanceDetail[1]['order_sn'] = $info['order_sn'];
                        $balanceDetail[1]['belong'] = $this->belong;
                        $balanceDetail[1]['type'] = 2;
                        $balanceDetail[1]['price'] = '-' . $info['team_price'];
                        $balanceDetail[1]['change_type'] = 17;
                        $balanceDetail[1]['remark'] = '团队业绩奖提现';
                    }
                    if (!empty($info['shareholder_price'])) {
                        //添加余额明细
                        $balanceDetail[2]['uid'] = $info['uid'];
                        $balanceDetail[2]['order_sn'] = $info['order_sn'];
                        $balanceDetail[2]['belong'] = $this->belong;
                        $balanceDetail[2]['type'] = 2;
                        $balanceDetail[2]['price'] = '-' . $info['shareholder_price'];
                        $balanceDetail[2]['change_type'] = 19;
                        $balanceDetail[2]['remark'] = '股东奖提现';
                    }
                    //区代奖
                    if (!empty($info['area_price'])) {
                        //添加余额明细
                        $balanceDetail[3]['uid'] = $info['uid'];
                        $balanceDetail[3]['order_sn'] = $info['order_sn'];
                        $balanceDetail[3]['belong'] = $this->belong;
                        $balanceDetail[3]['type'] = 2;
                        $balanceDetail[3]['price'] = '-' . $info['area_price'];
                        $balanceDetail[3]['change_type'] = 21;
                        $balanceDetail[3]['remark'] = '区代奖提现';
                    }
                    //美丽券
                    if (!empty($info['ticket_price'])) {
                        //添加余额明细
                        $ticketDetail[0]['uid'] = $info['uid'];
                        $ticketDetail[0]['order_sn'] = $info['order_sn'];
                        $ticketDetail[0]['pay_no'] = $info['pay_no'] ?? null;
                        $ticketDetail[0]['belong'] = $this->belong;
                        $ticketDetail[0]['type'] = 1;
                        $ticketDetail[0]['price'] = $info['ticket_price'];
                        $ticketDetail[0]['change_type'] = 1;
                        $ticketDetail[0]['remark'] = '美丽金回收分账获得';
                        $ticketDetail[0]['pay_type'] = 77;
                    }
                    break;
                case 6:
                    //添加余额明细-广宣奖
                    $balanceDetail['uid'] = $info['uid'];
                    $balanceDetail['order_sn'] = $info['order_sn'];
                    $balanceDetail['belong'] = $this->belong;
                    $balanceDetail['type'] = 2;
                    $balanceDetail['price'] = '-' . $info['total_price'];
                    $balanceDetail['change_type'] = 15;
                    $balanceDetail['remark'] = '广宣奖提现';
                    break;
                case 7:
                    //添加众筹余额明细-众筹钱包
                    $crowdBalanceDetail['uid'] = $info['uid'];
                    $crowdBalanceDetail['order_sn'] = $info['order_sn'];
                    $crowdBalanceDetail['belong'] = $this->belong;
                    $crowdBalanceDetail['type'] = 2;
                    $crowdBalanceDetail['price'] = '-' . $info['total_price'];
                    $crowdBalanceDetail['change_type'] = 6;
                    $crowdBalanceDetail['remark'] = '提现';
                    break;
                default:
                    throw new ServiceException(['msg' => '非法的类型']);
            }

            if (!empty($balanceDetail ?? [])) {
                if (count($balanceDetail) == count($balanceDetail, 1)) {
                    $balanceRes = (new BalanceDetail())->new($balanceDetail);
                } else {
                    $balanceRes = (new BalanceDetail())->saveAll(array_values($balanceDetail));
                }
            }

            if (!empty($ppylBalanceDetail)) {
                $ppylBalanceRes = (new PpylBalanceDetail())->new($ppylBalanceDetail);
            }
            if (!empty($ticketDetail ?? [])) {
                $ticketRes = (new CrowdfundingTicketDetail())->saveAll(array_values($ticketDetail));
            }

            if (!empty($crowdBalanceDetail ?? [])) {

//                $crowdBalanceRes = (new CrowdfundingBalanceDetail())->new($crowdBalanceDetail);
                //检查是否存在记录
                $existCrowdBalance = CrowdfundingBalanceDetail::where(['order_sn' => $crowdBalanceDetail['order_sn'], 'change_type' => $crowdBalanceDetail['change_type'], 'status' => 1])->count();
                if (empty($existCrowdBalance)) {
                    $crowdBalanceRes = (new CrowdfundingBalanceDetail())->save($crowdBalanceDetail);
                }

            }


            //修改用户冻结金额和提现金额
            switch ($info['withdraw_type']) {
                case 1:
                    $userBalance['fronzen_balance'] = priceFormat($aUserInfo['fronzen_balance'] - $info['total_price']);
                    $userBalance['withdraw_total'] = priceFormat($aUserInfo['withdraw_total'] + $info['total_price']);
                    break;
                case 2:
                    if (!empty($info['divide_price'])) {
                        $userBalance['divide_fronzen_balance'] = priceFormat($aUserInfo['divide_fronzen_balance'] - $info['divide_price']);
                        $userBalance['divide_withdraw_total'] = priceFormat($aUserInfo['divide_withdraw_total'] + $info['divide_price']);
                    }
                    if (!empty($info['ppyl_price'])) {
                        $userBalance['ppyl_fronzen_balance'] = priceFormat($aUserInfo['ppyl_fronzen_balance'] - $info['ppyl_price']);
                        $userBalance['ppyl_withdraw_total'] = priceFormat($aUserInfo['ppyl_withdraw_total'] + $info['ppyl_price']);
                    }
                    break;
                case 3:
                    $userBalance['divide_fronzen_balance'] = priceFormat($aUserInfo['divide_fronzen_balance'] - $info['total_price']);
                    $userBalance['divide_withdraw_total'] = priceFormat($aUserInfo['divide_withdraw_total'] + $info['total_price']);
                    break;
                case 4:
                    $userBalance['ppyl_fronzen_balance'] = priceFormat($aUserInfo['ppyl_fronzen_balance'] - $info['total_price']);
                    $userBalance['ppyl_withdraw_total'] = priceFormat($aUserInfo['ppyl_withdraw_total'] + $info['total_price']);
                    break;
                case 5:
                    //H5端 混合提现广宣奖和团队业绩奖和股东奖和区代奖
                    if (!empty($info['ad_price'])) {
                        $userBalance['ad_fronzen_balance'] = priceFormat($aUserInfo['ad_fronzen_balance'] - $info['ad_price']);
                        $userBalance['ad_withdraw_total'] = priceFormat($aUserInfo['ad_withdraw_total'] + $info['ad_price']);
                    }
                    if (!empty($info['team_price'])) {
                        $userBalance['team_fronzen_balance'] = priceFormat($aUserInfo['team_fronzen_balance'] - $info['team_price']);
                        $userBalance['team_withdraw_total'] = priceFormat($aUserInfo['team_withdraw_total'] + $info['team_price']);
                    }
                    if (!empty($info['shareholder_price'])) {
                        $userBalance['shareholder_fronzen_balance'] = priceFormat($aUserInfo['shareholder_fronzen_balance'] - $info['shareholder_price']);
                        $userBalance['shareholder_withdraw_total'] = priceFormat($aUserInfo['shareholder_withdraw_total'] + $info['shareholder_price']);
                    }
                    if (!empty($info['area_price'])) {
                        $userBalance['area_fronzen_balance'] = priceFormat($aUserInfo['area_fronzen_balance'] - $info['area_price']);
                        $userBalance['area_withdraw_total'] = priceFormat($aUserInfo['area_withdraw_total'] + $info['area_price']);
                    }
                    //美丽券此处是新增
                    if (!empty($info['ticket_price'] ?? 0)) {
                        $userBalance['ticket_balance'] = priceFormat($aUserInfo['ticket_balance'] + $info['ticket_price']);
                    }
                    break;
                case 6:
                    //单独广宣奖提现
                    $userBalance['ad_fronzen_balance'] = priceFormat($aUserInfo['ad_fronzen_balance'] - $info['total_price']);
                    $userBalance['ad_withdraw_total'] = priceFormat($aUserInfo['ad_withdraw_total'] + $info['total_price']);
                    break;
                case 7:
                    //众筹钱包提现
                    $userBalance['crowd_fronzen_balance'] = priceFormat($aUserInfo['crowd_fronzen_balance'] - $info['total_price']);
                    if ($userBalance['crowd_fronzen_balance'] < 0) {
                        $userBalance['crowd_fronzen_balance'] = 0;
                    }
                    $userBalance['crowd_withdraw_total'] = priceFormat($aUserInfo['crowd_withdraw_total'] + $info['total_price']);
                    //美丽券此处是新增
                    if (!empty($info['ticket_price'] ?? 0)) {
                        $userBalance['ticket_balance'] = priceFormat($aUserInfo['ticket_balance'] + $info['ticket_price']);
                    }
                    break;
                default:
                    throw new ServiceException(['msg' => '非法的类型']);
            }

            if (!empty($userBalance)) {
                User::update($userBalance, ['uid' => $info['uid']]);
            }


            $res = $paymentRes ?? [];

            if (!empty($paymentRes)) {
                //修改提现到账时间和流水号
                $savePayment['payment_status'] = 1;
                $savePayment['payment_remark'] = '已到账';
                $savePayment['payment_no'] = $paymentRes['payment_no'] ?? null;
                $savePayment['payment_time'] = !empty($paymentRes['payment_time']) ? strtotime($paymentRes['payment_time']) : time();
                Withdraw::update($savePayment, ['id' => $info['id']]);
            }

            return ['withdrawInfo' => $info, 'paymentRes' => $res, 'userInfo' => $aUserInfo];
        });
        return !empty($res) ? judge($res) : $res;
    }

    /**
     * @title  提现失败取消冻结金额
     * @param string $withdrawOrderSn 提现订单号
     * @return mixed
     */
    public function withdrawFailure(string $withdrawOrderSn)
    {
        //仅到账失败切未回款的才允许取消冻结金额
        $withdrawInfo = Withdraw::where(['order_sn' => $withdrawOrderSn, 'payment_status' => [-1], 'fail_status' => 1])->findOrEmpty()->toArray();
        if (empty($withdrawInfo)) {
            return false;
        }
        //返回总金额,包含手续费
        $realPrice = $withdrawInfo['total_price'] ?? 0;
        $DBRes = false;

        if (!empty($realPrice) && (string)$realPrice > 0) {
            $DBRes = Db::transaction(function () use ($withdrawInfo, $realPrice, $withdrawOrderSn) {
                //退回申请款
                switch ($withdrawInfo['withdraw_type'] ?? 2) {
                    case 2:
                        $fronzenRes = User::where(['uid' => $withdrawInfo['uid']])->dec('divide_fronzen_balance', $realPrice)->update();
                        $totalRes = User::where(['uid' => $withdrawInfo['uid']])->inc('divide_balance', $realPrice)->update();
                        break;
                    case 5:
                        //手续费默认退回广宣奖
                        if (doubleval($withdrawInfo['handing_fee'] ?? 0) > 0) {
                            User::where(['uid' => $withdrawInfo['uid']])->dec('ad_fronzen_balance', $withdrawInfo['handing_fee'])->update();
                            User::where(['uid' => $withdrawInfo['uid']])->inc('ad_balance', $withdrawInfo['handing_fee'])->update();
                        }
                        if (doubleval($withdrawInfo['ad_price'] ?? 0) > 0) {
                            User::where(['uid' => $withdrawInfo['uid']])->dec('ad_fronzen_balance', $withdrawInfo['ad_price'])->update();
                            User::where(['uid' => $withdrawInfo['uid']])->inc('ad_balance', $withdrawInfo['ad_price'])->update();
                        }
                        if (doubleval($withdrawInfo['team_price'] ?? 0) > 0) {
                            User::where(['uid' => $withdrawInfo['uid']])->dec('team_fronzen_balance', $withdrawInfo['team_price'])->update();
                            User::where(['uid' => $withdrawInfo['uid']])->inc('team_balance', $withdrawInfo['team_price'])->update();
                        }
                        if (doubleval($withdrawInfo['area_price'] ?? 0) > 0) {
                            User::where(['uid' => $withdrawInfo['uid']])->dec('area_fronzen_balance', $withdrawInfo['area_price'])->update();
                            User::where(['uid' => $withdrawInfo['uid']])->inc('area_balance', $withdrawInfo['area_price'])->update();
                        }
                        if (doubleval($withdrawInfo['shareholder_price'] ?? 0) > 0) {
                            User::where(['uid' => $withdrawInfo['uid']])->dec('shareholder_fronzen_balance', $withdrawInfo['shareholder_price'])->update();
                            User::where(['uid' => $withdrawInfo['uid']])->inc('shareholder_balance', $withdrawInfo['shareholder_price'])->update();
                        }
                        break;
                    case 7:
                        User::where(['uid' => $withdrawInfo['uid']])->dec('crowd_fronzen_balance', $realPrice)->update();
                        $totalRes = User::where(['uid' => $withdrawInfo['uid']])->inc('crowd_balance', $realPrice)->update();
                        break;
                    default:
                        break;
                }


//                $avaliableRes = User::where(['uid' => $withdrawInfo['uid']])->inc('avaliable_balance', $realPrice)->update();
                //修改提现记录为失败,回款状态
                Withdraw::update(['fail_status' => 2], ['order_sn' => $withdrawOrderSn]);
                return $totalRes;
            });
        }

        return judge($DBRes);
    }

    /**
     * @title  检验银行卡号
     * @param string $bankAccount 银行卡号
     * @return string
     * @remark 16-19 位卡号校验位采用 Luhm 校验方法计算：
     *  1，将未带校验位的 15 位卡号从右依次编号 1 到 15，位于奇数位号上的数字乘以 2
     *  2，将奇位乘积的个十位全部相加，再加上所有偶数位上的数字
     *  3，将加法和加上校验位能被 10 整除。
     */
    public function checkBankAccount(string $bankAccount)
    {
        $bankAccount = str_replace(' ', '', $bankAccount);
        $length = strlen(trim($bankAccount));
        if ($length == 16 || $length == 19 || $length == 18) {
            return true;
        } else {
            return false;
        }

        //去除空格
        $bankAccount = str_replace(' ', '', $bankAccount);
        $arr_no = str_split($bankAccount);
        $last_n = $arr_no[count($arr_no) - 1];
        krsort($arr_no);
        $i = 1;
        $total = 0;
        foreach ($arr_no as $n) {
            if ($i % 2 == 0) {
                $ix = $n * 2;
                if ($ix >= 10) {
                    $nx = 1 + ($ix % 10);
                    $total += $nx;
                } else {
                    $total += $ix;
                }
            } else {
                $total += $n;
            }
            $i++;
        }
        $total -= $last_n;
        $x = 10 - ($total % 10);

        if ($x == $last_n) {
            return true;
        } else {
            return false;
        }
    }
}