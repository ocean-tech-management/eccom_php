<?php
// +----------------------------------------------------------------------
// |[ 文档说明: 团队业绩奖励规则逻辑Service]
// +----------------------------------------------------------------------
// | Copyright (c) 2018~2024 http://www.mlhcmk.com All rights reserved.
// +----------------------------------------------------------------------


namespace app\lib\services;


use app\lib\BaseException;
use app\lib\exceptions\OrderException;
use app\lib\models\AfterSale;
use app\lib\models\BalanceDetail;
use app\lib\models\CrowdfundingSystemConfig;
use app\lib\models\DivideRestore;
use app\lib\models\Handsel;
use app\lib\models\HandselStandard;
use app\lib\models\HandselStandardAbnormal;
use app\lib\models\Member;
use app\lib\models\TeamMember;
use app\lib\services\TeamMember as TeamMemberService;
use app\lib\models\MemberIncentives;
use app\lib\models\MemberVdc;
use app\lib\models\OrderGoods;
use app\lib\models\PtActivity;
use app\lib\models\PtOrder;
use app\lib\models\ShipOrder;
use app\lib\models\SystemConfig;
use app\lib\models\TeamMemberVdc;
use app\lib\models\TeamPerformance;
use app\lib\models\User;
use app\lib\models\Divide as DivideModel;
use app\lib\models\Order;

use function PHPSTORM_META\map;
use think\facade\Db;
use think\facade\Queue;

class TeamDivide
{
    /**
     * @title  分润
     * @param $data
     * @return array|bool|mixed
     * @throws \Exception
     * //Job $job,
     */
    public function divideForTopUser($data)
    {
        $log['requestData'] = $data;
        if (empty($data)) {
            return $this->recordError($log, ['msg' => '[团队业绩分润] 传入参数为空']);
        }

        $dbRes = [];
        $orderSn = $data['order_sn'];
        //查找类型 1为计算分润并存入数据 2为仅计算分润不存入(供仅查询使用)
        $searType = $data['searType'] ?? 1;
        $searTypeName = $searType == 1 ? '计算' : '查询';
        if (empty($orderSn)) {
            return $this->recordError($log, ['msg' => '参数中订单编号为空,非法!']);
        }
        $log['msg'] = '[团队业绩分润] 订单' . $orderSn . '的分润,类型为 [ ' . $searTypeName . ' ]';


        $orderInfo = Db::name('order')->alias('a')
            ->join('sp_user b', 'a.uid = b.uid', 'left')
            ->join('sp_team_member c', 'a.uid = c.uid', 'left')
            ->field('a.*,b.name as user_name,b.vip_level,b.member_card,b.avaliable_balance,b.total_balance,b.link_superior_user,b.team_vip_level')
            ->where(['a.order_sn' => $orderSn, 'a.pay_status' => 2, 'a.order_type' => [1, 3, 6]])
            ->findOrEmpty();

        if (empty($orderInfo)) {
            $searNumber = $data['searNumber'] ?? 1;
            if (!empty($searNumber)) {
                if (intval($searNumber) == 1) {
                    $divideQueue = Queue::later(10, 'app\lib\job\TeamDividePrice', ['order_sn' => $orderSn, 'searNumber' => $searNumber + 1], config('system.queueAbbr') . 'TeamOrderDivide');
                    return $this->recordError($log, ['msg' => '[团队业绩分润] 非法订单,查无此项,将于十秒后重新计算一次分润']);
                } else {
                    return $this->recordError($log, ['msg' => '[团队业绩分润] 非法订单,查无此项,已经重复计算了多次,不再继续查询']);
                }
            }

        }

        $log['orderInfo'] = $orderInfo;

        //订单商品, 只能计入指定活动的商品或者是众筹活动的商品
        $orderGoodsMap[] = ['order_sn', '=', $orderSn];
        $orderGoodsMap[] = ['status', '=', 1];
        $orderGoodsMap[] = ['pay_status', '=', 2];
//        $orderGoodsMap[] = ['activity_sign', 'in', (new TeamMemberService())->activitySign];
        $orderGoods = OrderGoods::with(['vdc'])->where(function($query){
            $whereOr1[] = ['activity_sign', 'in', (new TeamMemberService())->activitySign];
            $whereOr2[] = ['', 'exp', Db::raw('crowd_code is not null and crowd_round_number is not null and crowd_period_number is not null')];
            $query->whereOr([$whereOr1,$whereOr2]);
        })->where($orderGoodsMap)->withoutField('id,images,specs,desc,create_time,update_time')->select()->toArray();
        $log['orderGoods'] = $orderGoods ?? [];

        //判断是否存在团队业绩分润记录
        $exMap[] = ['order_sn', '=', $orderSn];
        $exMap[] = ['status', '=', 1];
        $exMap[] = ['type', '=', 5];
        $exMap[] = ['level', '<>', 0];
        $existDivide = DivideModel::where($exMap)->count();
//        $existDivide = DivideModel::where(['order_sn' => $orderSn, 'status' => 1, 'type' => 1])->count();
        $log['existDivide'] = $existDivide ?? [];

        if ($searType == 1 && !empty($existDivide)) {
            $log['existDivideNumber'] = $existDivide;

            //尝试发放体验中心奖励
            try {
                $divideExpRes = (new Team())->divideTeamOrderForExp(['order_sn' => $orderInfo['order_sn'], 'orderInfo' => $orderInfo]);
            } catch (BaseException $e) {
                $log['divideExpErrorMsg'] = $e->msg ?? '发放体验中心奖励错误';
            }

            return $this->recordError($log, ['msg' => '[团队业绩分润] 该订单已经计算过分润,不可重复计算']);
        }

        if (empty($orderGoods)) {
            //判断是否可以升级
            $upgradeQueue = Queue::push('app\lib\job\TeamMemberUpgrade', ['uid' => $orderInfo['uid']], config('system.queueAbbr') . 'TeamMemberUpgrade');

            return $this->recordError($log, ['msg' => '[团队业绩分润] 不存在可以计算分润的正常状态的商品']);
        }

        if ($searType == 1) {
            //记录团队订单
            try {
                $recordTeamRes = (new Team())->recordOrderForTeamFromUserTable(['order_sn' => $orderInfo['order_sn'], 'orderInfo' => $orderInfo]);
            } catch (BaseException $e) {
                $log['recordTeamResErrorMsg'] = $e->msg ?? '记录团队订单错误';
            }

            //发放体验中心奖励
            try {
                $divideExpRes = (new Team())->divideTeamOrderForExp(['order_sn' => $orderInfo['order_sn'], 'orderInfo' => $orderInfo]);
            } catch (BaseException $e) {
                $log['divideExpErrorMsg'] = $e->msg ?? '发放体验中心奖励错误';
            }
        }


        $teamMemberVdcRule = TeamMemberVdc::where(['status'=>1])->order('level asc')->select()->toArray();
        $log['teamMemberVdcRule'] = $teamMemberVdcRule ?? [];

        if (empty($teamMemberVdcRule)) {
            if ($searType == 1) {
                //发放对应所有上级的销售额
                $recordTeamPerformance = $this->getTopUserRecordTeamPerformance($orderInfo);
            }
            //立马判断是否可以升级
            $upgradeQueue = Queue::push('app\lib\job\TeamMemberUpgrade', ['uid' => $orderInfo['uid']], config('system.queueAbbr') . 'TeamMemberUpgrade');

            return $this->recordError($log, ['msg' => '[团队业绩分润] 不存在有效的奖励规则']);
        }
        $teamDivideAllot = false;
        $teamDivideAllotScale = SystemConfig::where(['id' => 1])->value('team_allot_scale') ?? 0;
        $stocksScale = 0;
        if (!empty(doubleval($teamDivideAllotScale))) {
            $teamDivideAllot = true;
            $teamDivideAllotScale = priceFormat($teamDivideAllotScale);
            $stocksScale = (1 - $teamDivideAllotScale);
        }

        //团队业绩的分润规则直接拿去最新的规则
        foreach ($teamMemberVdcRule as $key => $value) {
//            if (!empty($teamDivideAllotScale ?? 0)) {
//                $value['vdc_one'] = $value['vdc_one'] * $teamDivideAllotScale;
//                $value['vdc_two'] = $value['vdc_two'] * $teamDivideAllotScale;
//            }
            $teamMemberVdcRuleInfo[$value['level']]['vdc_1'] = $value['vdc_one'];
            $teamMemberVdcRuleInfo[$value['level']]['vdc_2'] = $value['vdc_two'];
        }

//        foreach ($orderGoods as $key => $value) {
//            if (!empty($value['vdc'])) {
//                foreach ($value['vdc'] as $vdcKey => $vdcValue) {
//                    $vdcValue['vdc_1'] = $vdcValue['vdc_one'];
//                    $vdcValue['vdc_2'] = $vdcValue['vdc_two'];
//                    $orderGoods[$key]['vdc_rule'][$vdcValue['level']] = $vdcValue;
//                }
//                unset($orderGoods[$key]['vdc']);
//            }
//        }

        if (!empty($teamMemberVdcRuleInfo ?? [])) {
            foreach ($orderGoods as $key => $value) {
                $orderGoods[$key]['vdc_rule'] = $teamMemberVdcRuleInfo;
                unset($orderGoods[$key]['vdc']);
            }
        }

        $joinDivideUser = [];
        $linkUserCanNotDivide = true;
        //查找订单用户的上级用户信息
        $linkUser = Member::where(['uid' => $orderInfo['link_superior_user'] ?? []])->withoutField('id,create_time,update_time')->findOrEmpty()->toArray();

        if (empty($orderInfo['link_superior_user']) || empty($linkUser)) {
            if ($searType == 1) {
                //发放对应所有上级的销售额
                $recordTeamPerformance = $this->getTopUserRecordTeamPerformance($orderInfo);
            }
            //立马判断是否可以升级
            $upgradeQueue = Queue::push('app\lib\job\TeamMemberUpgrade', ['uid' => $orderInfo['uid']], config('system.queueAbbr') . 'TeamMemberUpgrade');
            return $this->recordError($log, ['msg' => '[团队业绩分润] 该用户无上级,不需要计算分润']);
        }

        $whereAndSql = '';
        $vipDivide = false;
        $userMember = [];

        if (!empty($orderInfo['vip_level'])) {
            $vipDivide = true;
        }
        $linkUserUid = $linkUser['uid'];

//        //sql思路,利用变量赋值仿递归的方式先一个个找上级,然后再重新给查出来的数据集加上序号(还是用变量赋值的方式,这么做的原因是left join的时候会将原来数据集的顺序打乱),所以加上了序号然后再子查询按序号排序得到最终想要的结构

        $databaseVersion = str_replace('.', '', config('system.databaseVersion'));

//        if (!empty($databaseVersion) && $databaseVersion > 8016) {
//            //mysql8.0.16版本之后使用这条sql
//            $linkUserParent = Db::query("SELECT u2.member_card,u2.uid,u2.user_phone,u2.level,u2.type,u2.status,u2.link_superior_user,u1.LEVEL AS divide_level FROM(SELECT @id c_ids,(SELECT @id := GROUP_CONCAT( link_superior_user ) FROM sp_team_member,(SELECT @id := " . "'" . $linkUserUid . "'" . ",@l := 0 ) b WHERE status in (1,2) AND FIND_IN_SET( uid, @id COLLATE utf8mb4_unicode_ci )) p_ids,@l := @l + 1 AS LEVEL FROM sp_team_member WHERE @id IS NOT NULL ) u1 JOIN sp_team_member u2 ON u1.c_ids = u2.uid COLLATE utf8mb4_unicode_ci WHERE u2.status = 1 and u2.level != 0 ORDER BY u1.LEVEL ASC;");
//        } else {
//            //mysql8.0.16版本以前使用这条sql
//            $linkUserParent = Db::query("SELECT u2.member_card,u2.uid,u2.user_phone,u2.level,u2.type,u2.status,u2.link_superior_user,u1.LEVEL AS divide_level FROM(SELECT @id c_ids,(SELECT @id := GROUP_CONCAT( link_superior_user ) FROM sp_team_member WHERE status in (1,2) AND FIND_IN_SET( uid, @id COLLATE utf8mb4_unicode_ci )) p_ids,@l := @l + 1 AS LEVEL FROM sp_team_member,(SELECT @id := " . "'" . $linkUserUid . "'" . ",@l := 0 ) b WHERE @id IS NOT NULL ) u1 JOIN sp_team_member u2 ON u1.c_ids = u2.uid COLLATE utf8mb4_unicode_ci WHERE u2.status = 1 and u2.level != 0 ORDER BY u1.LEVEL ASC;");
//        }
        //先查member原始会员结构然后把真实的团队等级组装回去, 最后用原始会员结构+团队会员等级去筛选上级
        if (!empty($databaseVersion) && $databaseVersion > 8016) {
            //mysql8.0.16版本之后使用这条sql
            $linkUserParent = Db::query("SELECT u2.member_card,u2.uid,u2.user_phone,u2.team_code,u2.child_team_code,u2.parent_team,u2.level,u2.type,u2.status,u2.link_superior_user,u1.LEVEL AS divide_level FROM(SELECT @id c_ids,(SELECT @id := GROUP_CONCAT( link_superior_user ) FROM sp_member,(SELECT @id := " . "'" . $linkUserUid . "'" . ",@l := 0 ) b WHERE status in (1,2) AND FIND_IN_SET( uid, @id COLLATE utf8mb4_unicode_ci )) p_ids,@l := @l + 1 AS LEVEL FROM sp_member WHERE @id IS NOT NULL ) u1 JOIN sp_member u2 ON u1.c_ids = u2.uid COLLATE utf8mb4_unicode_ci WHERE u2.status = 1 ORDER BY u1.LEVEL ASC;");
        } else {
            //mysql8.0.16版本以前使用这条sql
            $linkUserParent = Db::query("SELECT u2.member_card,u2.uid,u2.user_phone,u2.team_code,u2.child_team_code,u2.parent_team,u2.level,u2.type,u2.status,u2.link_superior_user,u1.LEVEL AS divide_level FROM(SELECT @id c_ids,(SELECT @id := GROUP_CONCAT( link_superior_user ) FROM sp_member WHERE status in (1,2) AND FIND_IN_SET( uid, @id COLLATE utf8mb4_unicode_ci )) p_ids,@l := @l + 1 AS LEVEL FROM sp_member,(SELECT @id := " . "'" . $linkUserUid . "'" . ",@l := 0 ) b WHERE @id IS NOT NULL ) u1 JOIN sp_member u2 ON u1.c_ids = u2.uid COLLATE utf8mb4_unicode_ci WHERE u2.status = 1 ORDER BY u1.LEVEL ASC;");
        }

        //数组倒序
        //$linkUserParent = array_reverse($linkUserParent);
        if (empty($linkUserParent) && empty($joinDivideUser)) {
            if ($searType == 1) {
                //发放对应所有上级的销售额
                $recordTeamPerformance = $this->getTopUserRecordTeamPerformance($orderInfo);
            }
            //立马判断是否可以升级
            $upgradeQueue = Queue::push('app\lib\job\TeamMemberUpgrade', ['uid' => $orderInfo['uid']], config('system.queueAbbr') . 'TeamMemberUpgrade');
            return $this->recordError($log, ['msg' => '[团队业绩分润] 该用户查无可分润上级,请系统查错']);
        }

        $log['linkUserParent'] = $linkUserParent;
        if (!empty($linkUserParent)) {
            //查找所有上级中对应的团队会员等级
            $allTopUid = array_column($linkUserParent, 'uid');
            $log['allTopUid'] = $allTopUid ?? [];
            $teamMemberLevel = TeamMember::where(['uid' => $allTopUid, 'status' => 1])->column('level', 'uid');
//            $teamMemberToggle = TeamMember::where(['uid' => $allTopUid, 'status' => 1])->column('toggle_level', 'uid');
            $log['teamMemberLevel'] = $teamMemberLevel ?? [];
//            $log['teamMemberToggle'] = $teamMemberToggle ?? [];

            if (empty($teamMemberLevel)) {
                if ($searType == 1) {
                    //发放对应所有上级的销售额
                    $recordTeamPerformance = $this->getTopUserRecordTeamPerformance($orderInfo);
                }
                //立马判断是否可以升级
                $upgradeQueue = Queue::push('app\lib\job\TeamMemberUpgrade', ['uid' => $orderInfo['uid']], config('system.queueAbbr') . 'TeamMemberUpgrade');
                return $this->recordError($log, ['msg' => '[团队业绩分润] 该用户查无可分润且拥有团队会员等级的上级,请系统查错']);
            }
            //用团队会员等级替换原来的商城会员等级
            foreach ($linkUserParent as $key => $value) {
                if (isset($teamMemberLevel[$value['uid']])) {
                    $linkUserParent[$key]['level'] = $teamMemberLevel[$value['uid']];
                }else{
                    $linkUserParent[$key]['level'] = 0;
                }
//                if (isset($teamMemberToggle[$value['uid']])) {
//                    $linkUserParent[$key]['toggle_level'] = $teamMemberToggle[$value['uid']];
//                } else {
//                    $linkUserParent[$key]['toggle_level'] = 0;
//                }
            }
            foreach ($linkUserParent as $key => $value) {
                if ($value['level'] == 0 || (!empty($orderInfo['team_vip_level'] ?? 0) && $value['level'] > $orderInfo['team_vip_level']) || (!empty($orderInfo['team_vip_level'] ?? 0) && $value['level'] == $orderInfo['team_vip_level'] && $value['uid'] != $orderInfo['link_superior_user'])) {
                    unset($linkUserParent[$key]);
                }
            }

            if (empty($linkUserParent ?? [])) {
                if ($searType == 1) {
                    //发放对应所有上级的销售额
                    $recordTeamPerformance = $this->getTopUserRecordTeamPerformance($orderInfo);
                }
                //立马判断是否可以升级
                $upgradeQueue = Queue::push('app\lib\job\TeamMemberUpgrade', ['uid' => $orderInfo['uid']], config('system.queueAbbr') . 'TeamMemberUpgrade');
                return $this->recordError($log, ['msg' => '[团队业绩分润] 该用户所有上级经过判断后无符合条件上级,无法分润']);
            }
            //查询每个人团队长的自购业绩, 如果不够则剔除这个上级,不允许分润--暂时注释
//            if (!empty($linkUserParent) ?? []) {
//                $notCheckUserPrice = true;
//                foreach ($teamMemberVdcRule as $key => $value) {
//                    $teamMemberVdcRuleAInfo[$value['level']] = $value;
//                    if (doubleval($value['get_reward_condition_price'] ?? 0) > 0) {
//                        $notCheckUserPrice = false;
//                    }
//                }
//                $parentOrderPrice = [];
//                if (empty($notCheckUserPrice)) {
//                    $checkPriceStartTime = date("Y-m-d", strtotime("-1 day")) . " 00:00:00";
//                    $checkPriceEndTime = date("Y-m-d", strtotime("-1 day")) . " 23:59:59";
//
//                    $coMap[] = ['order_type', 'in', [1, 3, 6]];
//                    $coMap[] = ['order_status', 'in', [2, 3, 8]];
//                    $coMap[] = ['pay_status', '=', 2];
//                    $coMap[] = ['create_time', '>=', strtotime($checkPriceStartTime)];
//                    $coMap[] = ['create_time', '<=', strtotime($checkPriceEndTime)];
//                    $coMap[] = ['uid', 'in', array_column($linkUserParent, 'uid')];
//                    $parentOrderPrice = Order::where($coMap)->field('uid,sum(real_pay_price) as total_price')->group('uid')->select()->toArray();
//                    $log['parentOrderPrice'] = $parentOrderPrice ?? [];
//                    $log['teamMemberVdcRuleAInfo'] = $teamMemberVdcRuleAInfo ?? [];
//
//                    $parentOrderPriceInfo = [];
//                    if (!empty($parentOrderPrice ?? [])) {
//                        foreach ($parentOrderPrice as $key => $value) {
//                            $parentOrderPriceInfo[$value['uid']] = $value['total_price'];
//                        }
//                    }
//                    foreach ($linkUserParent as $key => $value) {
//                        if (!empty($teamMemberVdcRuleAInfo[$value['level']] ?? []) && doubleval($teamMemberVdcRuleAInfo[$value['level']]['get_reward_condition_price'] ?? 0) > 0) {
//                            if ((string)doubleval($parentOrderPriceInfo[$value['uid']] ?? 0) < (string)doubleval($teamMemberVdcRuleAInfo[$value['level']]['get_reward_condition_price'] ?? 0)) {
//                                unset($linkUserParent[$key]);
//                            }
//                        }
//                    }
//                }
//
//                if (empty($linkUserParent)) {
//                    if ($searType == 1) {
//                        //发放对应所有上级的销售额
//                        $recordTeamPerformance = $this->getTopUserRecordTeamPerformance($orderInfo);
//                    }
//                    //立马判断是否可以升级
//                    $upgradeQueue = Queue::push('app\lib\job\TeamMemberUpgrade', ['uid' => $orderInfo['uid']], config('system.queueAbbr') . 'TeamMemberUpgrade');
//                    return $this->recordError($log, ['msg' => '[团队业绩分润] 该用户团队下所有上级不符合奖金自购金额条件,无法分润']);
//                }
//            }

            $linkUserTeamInfo = TeamMember::where(['uid'=>$linkUser['uid'],'status'=>1])->value('level');
            $linkUser['level'] = !empty($linkUserTeamInfo) ? $linkUserTeamInfo : 0;

//            /*以下为购买用户为会员,同级及以上等级享受分润模块--start*/
//            if (!empty($vipDivide)) {
//                //把自己加入分润层级第一层,但是分润出来的金额为0,最后再剔除,这么做是为了保证结构跟普通购买的统一
//                $vipMyself['member_card'] = $userMember['member_card'];
//                $vipMyself['uid'] = $userMember['uid'];
//                $vipMyself['user_phone'] = $userMember['user_phone'];
////                $vipMyself['team_code'] = $userMember['team_code'];
////                $vipMyself['child_team_code'] = $userMember['child_team_code'];
////                $vipMyself['parent_team'] = $userMember['parent_team'];
//                $vipMyself['level'] = $userMember['level'];
//                $vipMyself['type'] = $userMember['type'];
//                //$vipMyself['create_time'] = $userMember['create_time'];
//                $vipMyself['status'] = $userMember['status'];
//                $vipMyself['link_superior_user'] = $userMember['link_superior_user'] ?? null;
//                array_unshift($linkUserParent, $vipMyself);
//            }
//            /*以下为购买用户为会员,同级及以上等级享受分润模块--end*/

            //筛选可参加分润的人群(仅筛选到该团队的一号人员(即直推合伙人级别))
            $aDivideUsers = $this->getJoinDivideTopUserList($linkUserParent, $linkUser, $joinDivideUser);
            $aDivideUser = $aDivideUsers['joinDivideUser'];
            $log['aDivideUsers'] = $aDivideUsers;

            $divideUserNumber = $aDivideUsers['joinDivideUserNumber'];
        } else {
            $aDivideUser = $joinDivideUser;
            $divideUserNumber = count($joinDivideUser);
        }

        //会员等级明细
        $memberVdcs = TeamMemberVdc::where(['status' => 1])->withoutField('id,create_time,update_time')->select()->toArray();
        foreach ($memberVdcs as $key => $value) {
            $memberVdc[$value['level']] = $value;
        }
        //如果该等级取消了分润,则将该等级人群都置空
        foreach ($aDivideUser as $key => $value) {
            if (!empty($memberVdc[$key] ?? null) && $memberVdc[$key]['close_divide'] == 1) {
                $aDivideUser[$key] = [];
                if (isset($divideUserNumber)) {
                    $divideUserNumber -= (count($value) ?? 2);
                }
            }
        }

        if (empty($divideUserNumber) || empty($aDivideUser ?? [])) {
            if ($searType == 1) {
                //发放对应所有上级的销售额
                $recordTeamPerformance = $this->getTopUserRecordTeamPerformance($orderInfo);
            }
            //立马判断是否可以升级
            $upgradeQueue = Queue::push('app\lib\job\TeamMemberUpgrade', ['uid' => $orderInfo['uid']], config('system.queueAbbr') . 'TeamMemberUpgrade');

            return $this->recordError($log, ['msg' => '[团队业绩分润] 该用户的上级中没有一个人可以参与分润!']);
        }
        //这里筛选掉空的等级仅是为了得出正确的最高和最低等级,原来的$aDivideUser结构不能改变!
        $adU = $aDivideUser;
        foreach ($adU as $key => $value) {
            if (empty($value)) {
                unset($adU[$key]);
            }
        }
        $maxLevel = max(array_keys($adU));
        $minLevel = min(array_keys($adU));

        $skuDividePrice = [];
        $divideScaleTopUser = [];
        $goodsMaxDividePrice = [];
        $goodsLevelDividePrice = [];

        //分润数组 存储分润数组明细,后续统一存入
        $aDivide = [];
        $log['aDivide'] = [];
        $orderGoodsTotalPrice = 0;

        foreach ($orderGoods as $key => $value) {
            //改商品SKU不允许分润则直接跳过,到下一个商品
            if ($value['vdc_allow'] == 2) {
                unset($orderGoods[$key]);
                continue;
            }
            //实付减邮费已经为0的情况下不允许继续分润
            if($value['real_pay_price'] - $value['total_fare_price'] <= $value['refund_price']){
                unset($orderGoods[$key]);
                continue;
            }
            $orderGoodsTotalPrice += $value['total_price'];
            //获取商品对应的分润规则
            $vdcRule = $value['vdc_rule'] ?? [];
            $allDividePrice = 0;
            //计算出这个商品最大的总分润金额, 这个商品全部分润总金额不允许超过
            $goodsMaxDividePrice[$value['sku_sn']] = $minLevel * $teamMemberVdcRuleInfo[$minLevel]['vdc_1'];
//            $goodsLevelDividePrice[$value['sku_sn']] = [];

            if (!isset($skuDividePrice[$value['sku_sn']])) {
                $skuDividePrice[$value['sku_sn']] = 0;
            }
            //每个层级按比例分润的总金额数组,键名是等级,值对应的是金额,仅记录每个等级的分润金额(目前规则为有且不超过两个人的数量,可以为0)
            if (!isset($divideScaleUser[$value['sku_sn']])) {
                $divideScaleUser[$value['sku_sn']] = [];
            }
            //每个层级第一个人按差价分润的应该得到总金额数组,键名是等级,值对应的是金额
            if (!isset($divideScaleTopUser[$value['sku_sn']])) {
                $divideScaleTopUser[$value['sku_sn']] = [];
            }

            foreach ($aDivideUser as $dUKey => $dUValue) {
                $nowLevelVdc = $vdcRule[$dUKey];
                //初始化统计每个层级按比例分润的总额数组
                if (!isset($divideScaleUser[$value['sku_sn']][$dUKey])) {
                    $divideScaleUser[$value['sku_sn']][$dUKey] = 0;
                }
                //初始化统计每个层级第一个人按价差分润的总额数组
                if (!isset($divideScaleTopUser[$value['sku_sn']][$dUKey])) {
                    $divideScaleTopUser[$value['sku_sn']][$dUKey] = 0;
                }
                //初始化每个商品SKU的分润总数组
                if (!isset($aDivide[$value['sku_sn']])) {
                    $aDivide[$value['sku_sn']] = [];
                }
                $count = count($aDivide[$value['sku_sn']]);

                if (!empty($nowLevelVdc) && !empty($dUValue)) {
                    //初始化分润数组
                    if (!isset($aDivide[$value['sku_sn']][$count])) {
                        $aDivide[$value['sku_sn']][$count] = [];
                    }
                    foreach ($dUValue as $levelKey => $levelValue) {
                        //将分润人员信息记录到分润数组
                        $levelValue['belong'] = $orderInfo['order_belong'];
                        $levelValue['order_type'] = $orderInfo['order_type'];
                        $levelValue['order_uid'] = $orderInfo['uid'];
                        $levelValue['type'] = 5;
                        $levelValue['order_sn'] = $value['order_sn'];
                        $levelValue['goods_sn'] = $value['goods_sn'];
                        $levelValue['sku_sn'] = $value['sku_sn'];
                        $levelValue['price'] = $value['price'];
                        $levelValue['count'] = $value['count'];
                        $levelValue['total_price'] = $value['total_price'];
                        $levelValue['link_uid'] = $levelValue['uid'];
                        if ($orderInfo['order_type'] == 6) {
                            $levelValue['crowd_code'] = $value['crowd_code'] ?? null;
                            $levelValue['crowd_round_number'] = $value['crowd_round_number'] ?? null;
                            $levelValue['crowd_period_number'] = $value['crowd_period_number'] ?? null;
                            $levelValue['remark'] = '福利活动团队奖励';
                        }

//                        //如果实付价格低于成本价,直接卡断
//                        if ((string)$value['total_price'] < (string)($nowLevelVdc['purchase_price'] * $value['count'])) {
//                            if ($searType == 1) {
//                                //发放对应所有上级的销售额
//                                $recordTeamPerformance = $this->getTopUserRecordTeamPerformance($orderInfo);
//                            }
//                            //立马判断是否可以升级
//                            $upgradeQueue = Queue::push('app\lib\job\TeamMemberUpgrade', ['uid' => $orderInfo['uid']], config('system.queueAbbr') . 'TeamMemberUpgrade');
//                            return $this->recordError($log, ['msg' => '[团队业绩分润] [严重错误] 分润风控拦截,订单金额低于上级成本价,整链分润取消!']);
//                        }
                        $aDivide[$value['sku_sn']][$count] = $levelValue;
//                        $aDivide[$value['sku_sn']][$count]['purchase_price'] = priceFormat($nowLevelVdc['purchase_price'] * $value['count']);
                        $aDivide[$value['sku_sn']][$count]['purchase_price'] = $levelValue['total_price'];
                        $aDivide[$value['sku_sn']][$count]['vdc_genre'] = 2;
                        $aDivide[$value['sku_sn']][$count]['level'] = $dUKey;
                        $aDivide[$value['sku_sn']][$count]['dis_reduce_price'] = "0.00";
                        $aDivide[$value['sku_sn']][$count]['is_vip_divide'] = !empty($vipDivide) ? 1 : 2;

                        //如果这个商品没有历史分润则直接用(实付价-邮费) * 等级分润比例得出分润价格, 如果有则还需要扣除等级比当前低(level比当前大)的用户所有金额才能得出实际的分润价格
                        if(empty($goodsLevelDividePrice[$value['sku_sn']] ?? [])){
                            $aDivide[$value['sku_sn']][$count]['divide_price'] = priceFormat(($value['real_pay_price'] - $value['total_fare_price'] - $value['refund_price']) * $teamMemberVdcRuleInfo[$levelValue['level']]['vdc_1']);
                            $goodsLevelDividePrice[$value['sku_sn']][$levelValue['level']] = $aDivide[$value['sku_sn']][$count]['divide_price'];
                        }else{
                            //如果当前SKU里面存在了等级比自己还高的分润金额, 代表出现了异常,需要卡断
                            if(min(array_keys($goodsLevelDividePrice[$value['sku_sn']])) < $levelValue['level']){
                                //立马判断是否可以升级
                                $upgradeQueue = Queue::push('app\lib\job\TeamMemberUpgrade', ['uid' => $orderInfo['uid']], config('system.queueAbbr') . 'TeamMemberUpgrade');
                                return $this->recordError($log, ['msg' => '[团队业绩分润] [错误] 分润风控拦截,出现了越级的团队业绩金额, 整链分润取消!']);
                            }else{
                                $aDivide[$value['sku_sn']][$count]['divide_price'] = priceFormat(($value['real_pay_price'] - $value['total_fare_price'] - $value['refund_price']) * $teamMemberVdcRuleInfo[$levelValue['level']]['vdc_1']) - priceFormat(array_sum($goodsLevelDividePrice[$value['sku_sn']]));
                                $goodsLevelDividePrice[$value['sku_sn']][$levelValue['level']] = $aDivide[$value['sku_sn']][$count]['divide_price'];
                            }
                        }

                        $aDivide[$value['sku_sn']][$count]['vdc'] = ($teamMemberVdcRuleInfo[$levelValue['level']]['vdc_1'] ?? 0);
                        $skuDividePrice[$value['sku_sn']] += $aDivide[$value['sku_sn']][$count]['divide_price'];
                        $aDivide[$value['sku_sn']][$count]['real_divide_price'] = priceFormat($aDivide[$value['sku_sn']][$count]['divide_price']);
                        $count++;

                    }

                }
            }

            if (!isset($log['aDivide'][$value['sku_sn']])) {
                $log['aDivide'][$value['sku_sn']] = [];
            }

            $log['aDivide'][$value['sku_sn']] = $aDivide[$value['sku_sn']];

            /*以下为购买用户为会员,同级及以上等级享受分润模块--删除之前添加的自己的分润占位数组(价格一定是为0的,不然就是错误了)--start*/
//            if (!empty($aDivide) && !empty($vipDivide)) {
//                foreach ($aDivide as $skuKey => $skuValue) {
//                    foreach ($skuValue as $dKey => $dValue) {
//                        if ($dValue['uid'] == $userMember['uid']) {
//                            if (empty(doubleval($dValue['divide_price'])) || ((string)$dValue['divide_price'] < 0)) {
//                                unset($aDivide[$skuKey][$dKey]);
//                                continue;
//                            } else {
//                                //如果此单刚好是拼团最后一单,但是因为错误中断分润,会导致后续可能出现升级错误, 所以在这里升级
//                                if ($searType == 1) {
//                                    //发放对应所有上级的销售额
//                                    $recordTeamPerformance = $this->getTopUserRecordTeamPerformance($orderInfo);
//                                }
//                                //立马判断是否可以升级
//                                $upgradeQueue = Queue::push('app\lib\job\TeamMemberUpgrade', ['uid' => $orderInfo['uid']], config('system.queueAbbr') . 'TeamMemberUpgrade');
//
//                                return $this->recordError($log, ['msg' => '[团队业绩分润] [严重错误] 会员分润首会员(即自己)的分润价格不为0,请纠正!']);
//                            }
//
//                        }
//                    }
//                }
//            }
            /*以下为购买用户为会员,同级及以上等级享受分润模块--end*/
        }
        $log['skuDividePrice'] = $skuDividePrice;
        $log['afterDisDivide'] = $aDivide;
        $errorDivide = [];

        //获取最后的分润数组,以便一次性加入数据库
        if (!empty($aDivide)) {
            $finalCount = 0;
            $aDivides = [];
            foreach ($aDivide as $skuKey => $skuValue) {
                foreach ($skuValue as $dKey => $dValue) {
                    //不允许出现负数的分润
                    if ((string)$dValue['real_divide_price'] < 0) {
                        //记录异常分润数据
                        $errorDivide[] = $dValue;
                        unset($aDivide[$skuKey][$dKey]);
                        continue;
                    }
                    $aDivides[$finalCount] = $dValue;
                    //状态为冻结中
                    $aDivides[$finalCount]['arrival_status'] = 2;
                    $finalCount++;
                }
            }

            //如果出现任意一个负数的分润,整条分润都将失败
            if (!empty($errorDivide)) {
                //如果此单刚好是拼团最后一单,但是因为错误中断分润,会导致后续无法发放成长值,故在此发放成长值
                if ($searType == 1) {
                    //发放对应所有上级的销售额
                    $recordTeamPerformance = $this->getTopUserRecordTeamPerformance($orderInfo);
                }
                //立马判断是否可以升级
                $upgradeQueue = Queue::push('app\lib\job\TeamMemberUpgrade', ['uid' => $orderInfo['uid']], config('system.queueAbbr') . 'TeamMemberUpgrade');
                //反错
                $log['errorDivide'] = $errorDivide;
                return $this->recordError($log, ['msg' => '[团队业绩分润] [严重错误] 分润风控拦截,分润链条中出现负数分润,整链分润取消!']);
            }

            //根据排序给每个分润等级加上属于分润第几人
            if (!empty($aDivides)) {
                foreach ($aDivides as $key => $value) {
                    if (!isset($levelSort[$value['sku_sn']][$value['level']])) {
                        $levelSort[$value['sku_sn']][$value['level']] = 1;
                    }
                    if (!isset($sort[$value['sku_sn']])) {
                        $sort[$value['sku_sn']] = 1;
                    }
                    $aDivides[$key]['level_sort'] = $levelSort[$value['sku_sn']][$value['level']];
                    $aDivides[$key]['divide_sort'] = $sort[$value['sku_sn']] ?? 1;
                    $levelSort[$value['sku_sn']][$value['level']] += 1;
                    $sort[$value['sku_sn']] += 1;
                }
            }

            //众筹订单有特殊的体验中心的奖励
//            $aToggleDivide = [];
//            if (!empty($aDivides) && $orderInfo['order_type'] == 6) {
//                $toggleScale = CrowdfundingSystemConfig::where(['id' => 1])->value('toggle_scale');
//                if (doubleval($toggleScale) > 0) {
//                    foreach ($aDivides as $key => $value) {
//                        if (!empty($teamMemberToggle[$value['link_uid']] ?? null) && intval($teamMemberToggle[$value['link_uid']]) > 0) {
//                            $value['divide_type'] = 2;
//                            $value['remark'] = '福利中心体验中心奖励';
//                            $value['is_exp'] = 1;
//                            $value['level'] = 1;
//                            $value['vdc'] = $toggleScale;
//                            $value['divide_price'] = priceFormat($value['total_price'] * $value['vdc']);
//                            $value['real_divide_price'] = $value['divide_price'];
//                            $aToggleDivide[] = $value;
//                        }
//                    }
//                }
//            }
            $aStocksDivides = [];
            if ($searType == 1) {
                if (!empty($teamDivideAllot ?? false) && !empty(doubleval($teamDivideAllotScale) ?? 0)) {
                    $aStocksDivides = $aDivides;
                    foreach ($aDivides as $key => $value) {
                        $aDivides[$key]['divide_price'] = priceFormat($value['divide_price'] * $teamDivideAllotScale);
                        $aDivides[$key]['vdc'] = $value['vdc'] * $teamDivideAllotScale;
                        $aDivides[$key]['real_divide_price'] = $aDivides[$key]['divide_price'];
                        $aDivides[$key]['is_allot'] = 1;
                        $aDivides[$key]['allot_scale'] = $teamDivideAllotScale;
                    }
                    foreach ($aStocksDivides as $key => $value) {
                        $aStocksDivides[$key]['type'] = 9;
                        $aStocksDivides[$key]['divide_price'] = priceFormat($value['divide_price'] * $stocksScale);
                        $aStocksDivides[$key]['vdc'] = $value['vdc'] * $stocksScale;
                        $aStocksDivides[$key]['real_divide_price'] = $aStocksDivides[$key]['divide_price'];
                        $aStocksDivides[$key]['is_allot'] = 1;
                        $aStocksDivides[$key]['allot_scale'] = $stocksScale;
                        $aStocksDivides[$key]['allot_type'] = 1;
                        $aStocksDivides[$key]['remark'] = '股票奖励(来自团队业绩)';
                        if ($orderInfo['order_type'] == 6) {
                            $aStocksDivides[$key]['remark'] = '福利活动股票奖励(来自团队业绩)';
                        }
                    }
                }

                $dbRes = Db::transaction(function () use ($aDivides, $orderInfo, $orderGoods, $linkUserParent, $orderGoodsTotalPrice,$aStocksDivides) {
                    //记录分润明细
                    $res = (new DivideModel())->saveAll($aDivides);
//                    if (!empty($aToggleDivide)) {
//                        (new DivideModel())->saveAll(array_values($aToggleDivide));
//                    }
                    if(!empty($aStocksDivides ?? [])){
                        (new DivideModel())->saveAll($aStocksDivides);
                    }
                    return $res;
                });
            } else {
                $dbRes = $aDivides;
            }
        }
        $log['res'] = $dbRes;
        $log['msg'] = '[团队业绩分润] 订单' . $orderSn . '的分润已 [ ' . $searTypeName . '成功 ]';
        $allotDivideScale = 0;
        if (!empty($teamDivideAllot ?? false) && !empty(doubleval($teamDivideAllotScale) ?? 0)) {
            $allotDivideScale = $teamDivideAllotScale;
        }
        //发放此次所有分润用户的上级感恩奖
        $GratefulRes = $this->sendGrateful(['orderInfo' => $orderInfo, 'linkUser' => $linkUser, 'orderGoods' => $orderGoods, 'searType' => $searType, 'aDivides' => $aDivides, 'allotDivideScale' => $allotDivideScale ?? 0]);
        $log['GratefulRes'] = $GratefulRes;

        if(!empty($aStocksDivides ?? [])){
            //发放此次所有分润用户的股票上级感恩奖
            $stocksGratefulRes = $this->sendStocksGrateful(['orderInfo' => $orderInfo, 'linkUser' => $linkUser, 'orderGoods' => $orderGoods, 'searType' => $searType, 'aDivides' => $aStocksDivides, 'allotDivideScale' => $stocksScale ?? 0]);
            $log['stocksGratefulRes'] = $stocksGratefulRes;
        }

        $this->log($log, 'info');



        if ($searType == 1) {
            //发放对应所有上级的销售额
            $recordTeamPerformance = $this->getTopUserRecordTeamPerformance($orderInfo);
        }

        //立马判断是否可以升级
        $upgradeQueue = Queue::push('app\lib\job\TeamMemberUpgrade', ['uid' => $orderInfo['uid']], config('system.queueAbbr') . 'TeamMemberUpgrade');

        unset($aStockDivides);
        unset($aDivides);
        unset($aDivide);


        return $dbRes;
    }

    /**
     * @title  获取可参加分润的上级层级结构和人员列表
     * @param array $linkUserParent 关联的全部上级用户(从高到低的一条线上的直推会员)
     * @param array $linkUser 实际购买用户的关联上级用户
     * @param array $joinDivideUser 参与分润的人员列表(传入的时候只有一个关联上级或者为空)
     * @return mixed 参与分润的人员数组列表
     * @remark 代码中留有了跨级取消价差分润操作,如果需要则要再传入$linkUserCanNotDivide参数作为判断
     */
    public function getJoinDivideTopUserList(array $linkUserParent, array $linkUser, array $joinDivideUser)
    {
        $joinDivideUserNumber = 0;
        if (!empty($joinDivideUser)) {
            $joinDivideUserNumber = count($joinDivideUser);
        }
        $teamMemberVdc = TeamMemberVdc::where(['status' => 1])->column('level');
        $minLevel = max($teamMemberVdc);
        if (!empty($teamMemberVdc)) {
            //默认获取每个等级允许指定人数的分润,不允许超过1
            foreach ($teamMemberVdc as $key => $value) {
                $aLevelMaxNumber[$value] = 1;
            }
        }

//        $aLevelMaxNumber = [3 => 3, 2 => 3, 1 => 3];
        $nowLevel = !empty($nowLevel) ? $nowLevel : (!empty($linkUser['level']) ? $linkUser['level'] : $minLevel);
        $joinDivideUserLevel[$nowLevel] = 0;
        foreach ($linkUserParent as $key => $value) {
            //如果等级小于等于0,则触底结束循环
            if ($nowLevel <= 0) {
                break;
            }
            if (!isset($joinDivideUserLevel[$nowLevel])) {
                $joinDivideUserLevel[$nowLevel] = 0;
            }
            if (!isset($joinDivideUser[$nowLevel])) {
                $joinDivideUser[$nowLevel] = [];
            }

            //如果状态非正常,或不是会员,或会员等级比当前的还高则剔除(这是为了跨级不分润)
            if ($value['status'] != 1 || empty($value['level']) || intval($value['level']) > $nowLevel || in_array($value['uid'], array_column($joinDivideUser[$nowLevel], 'uid'))) {
                unset($linkUserParent[$key]);
                continue;
            }
            if ($value['status'] == 1 && $value['level'] <= $nowLevel) {
                //统计每一个等级的人数
                $nowLevelNumber = count($joinDivideUser[$nowLevel]);
                //获取每个等级允许指定人数的分润,不允许超过
                $levelMaxNumber = $aLevelMaxNumber[$nowLevel];
                //判断当前这个人的等级档次是否比当前在循环的等级高(即level更小),如果是则提高等级档次,并且保留当前这个人的信息到更高等级档次的第一位,然后跳出本次循环,继续下一个
                if ($value['level'] < $nowLevel) {
                    //如果中间出现断层,需要计算空了哪一等级层然后补回去,举例:比如只有31,没有2的情况,需要补回2的空数组
                    if (($nowLevel - $value['level']) == 1) {
                        $nowLevel = $nowLevel - 1;
                    } else {
                        for ($i = $value['level']; $i <= ($nowLevel - $value['level']); $i++) {
                            if (!isset($joinDivideUser[$i])) {
                                $joinDivideUser[$i] = [];
                            }
                        }
                        $nowLevel = $value['level'];

                    }
                    //$nowLevel = $nowLevel - 1;
                    //$nowLevel = $value['level'];
                    $value['divide_type'] = 1;
                    $value['vdc_level'] = 0;
                    $joinDivideUser[$nowLevel][] = $value;
                    $joinDivideUserNumber++;
                    unset($linkUserParent[$key]);
                    continue;
                } else {
                    //如果在当前循环的等级中,还有符合的人则判断是否人数已经满了,满了则开始寻找上一档等级
                    if (count($joinDivideUser[$nowLevel]) == $levelMaxNumber) {
                        $nowLevel = $nowLevel - 1;
                        continue;
                    }
                }

//                        //如果用户关联的会员不能参与分润,则第三等级(团长级)不允许价差分润,直接走比例分润(此情况仅在团长级分润出现)---跨级取消价差分润操作,暂时先注释
//                        if($linkUserCanNotDivide && $nowLevel == 3){
//                            $levelMaxNumber -= 1;
//                        }
                //divide_type=1则为价差分润 =2则为比例分润
                if ($nowLevelNumber < $levelMaxNumber) {
                    if ($value['level'] == $nowLevel) {
                        //默认分润形式为比例分润,如果刚好当前用户处于该等级的第一位,则修改为价差分润
                        $value['divide_type'] = 2;
                        if (empty($nowLevelNumber)) {
                            $value['divide_type'] = 1;
                            $value['vdc_level'] = 0;
                            //跨级取消价差分润操作---暂时先注释
//                                    if($nowLevel == 3 && $linkUserCanNotDivide){
//                                        $value['divide_type'] = 2;
//                                    }

                        } else {
                            $joinDivideUserLevel[$nowLevel]++;
                            $value['vdc_level'] = $joinDivideUserLevel[$nowLevel];
                        }
                        $joinDivideUser[$nowLevel][] = $value;
                        $joinDivideUserNumber++;


                    }
                    unset($linkUserParent[$key]);
                }
            }
        }
        if (!empty($joinDivideUser)) {
            krsort($joinDivideUser);
        }
        return ['joinDivideUser' => $joinDivideUser, 'joinDivideUserNumber' => $joinDivideUserNumber];
    }

    /**
     * @title  获取可参加分润的上级层级结构和人员列表(不烧伤结构)
     * @param array $linkUserParent 关联的全部上级用户(从高到低的一条线上的直推会员)
     * @param array $linkUser 实际购买用户的关联上级用户
     * @param array $joinDivideUser 参与分润的人员列表(传入的时候只有一个关联上级或者为空)
     * @return mixed 参与分润的人员数组列表
     * @remark 代码中留有了跨级取消价差分润操作,如果需要则要再传入$linkUserCanNotDivide参数作为判断
     */
    public function getJoinDivideTopUserListNoBurns(array $linkUserParent, array $linkUser, array $joinDivideUser)
    {
        $joinDivideUserNumber = 0;
        if (!empty($joinDivideUser)) {
            $joinDivideUserNumber = count($joinDivideUser);
        }

        //默认获取每个等级允许指定人数的分润,不允许超过3
        $aLevelMaxNumber = [3 => 3, 2 => 3, 1 => 3];
//        $nowLevel = !empty($nowLevel) ? $nowLevel : (!empty($linkUser['level']) ? $linkUser['level'] : 3);
//        $joinDivideUserLevel[$nowLevel] = 0;
        //为了实现跨级可分润加上的代码---start
        $thisArrayMaxLevel = 0;
        $levelUser = [];
        $existLevel = [];
        foreach ($linkUserParent as $key => $value) {
            if(!isset($existLevel[$value['level']])){
                $existLevel[$value['level']] = 0;
            }
            if($value['status'] == 1){
                $levelUser[$value['level']] = $value;
                $existLevel[$value['level']] += 1;
            }
        }
        //补齐没有的等级
        foreach ($aLevelMaxNumber as $key => $value) {
            if (!in_array($key, array_keys($existLevel))) {
                $existLevel[$key] = 0;
            }
        }

        if(!empty($levelUser)){
            $thisArrayMaxLevel = max(array_keys($levelUser));
        }
        $nowLevel = !empty($nowLevel) ? $nowLevel : (!empty($thisArrayMaxLevel) ? $thisArrayMaxLevel : (!empty($linkUser['level']) ? $linkUser['level'] : 3));
        $joinDivideUserLevel[$nowLevel] = 0;

        //为了实现跨级可分润加上的代码---end
        foreach ($linkUserParent as $key => $value) {
            $joinRes = false;
            //如果等级小于等于0,则触底结束循环
            if ($nowLevel <= 0) {
                break;
            }
            if (!isset($joinDivideUserLevel[$nowLevel])) {
                $joinDivideUserLevel[$nowLevel] = 0;
            }
            if (!isset($joinDivideUser[$nowLevel])) {
                $joinDivideUser[$nowLevel] = [];
            }

            //如果状态非正常,或不是会员,或会员等级比当前的还高则剔除(这是为了跨级不分润)
//            if ($value['status'] != 1 || empty($value['level']) || intval($value['level']) > $nowLevel || in_array($value['uid'], array_column($joinDivideUser[$nowLevel], 'uid'))) {
            //如果状态非正常,或不是会员(跨级可以分润)
            //为了实现跨级可分润加上的代码---start
            if ($value['status'] != 1 || empty($value['level']) || in_array($value['uid'], array_column($joinDivideUser[$nowLevel], 'uid')) || (!empty($joinDivideUser[$value['level'] ?? []]) && in_array($value['uid'], array_column($joinDivideUser[$value['level'] ?? []], 'uid')))) {
                unset($linkUserParent[$key]);
                continue;
            }
            //为了实现跨级可分润加上的代码---end

            //跨级可以分润
            //为了实现跨级可分润加上的代码---start
//            if ($value['status'] == 1 && $value['level'] <= $nowLevel) {
            //为了实现跨级可分润加上的代码---end
            if ($value['status'] == 1) {
                //统计每一个等级的人数
                $nowLevelNumber = count($joinDivideUser[$nowLevel]);
                //获取每个等级允许指定人数的分润,不允许超过
                $levelMaxNumber = $aLevelMaxNumber[$nowLevel];
                //判断当前这个人的等级档次是否比当前在循环的等级高(即level更小),如果是则提高等级档次,并且保留当前这个人的信息到更高等级档次的第一位,然后跳出本次循环,继续下一个
                if ($value['level'] < $nowLevel) {
//                    unset($existLevel[$nowLevel]);
                    //如果中间出现断层,需要计算空了哪一等级层然后补回去,举例:比如只有31,没有2的情况,需要补回2的空数组
                    if (($nowLevel - $value['level']) == 1) {
                        //为了实现跨级可分润加上的代码---start
                        if(count($joinDivideUser[$value['level'] + 1] ?? []) >= $levelMaxNumber || $existLevel[$value['level'] + 1] - 1 < 0){
                            $nowLevel = $value['level'];
                        }
                        //为了实现跨级可分润加上的代码---end
//                        $nowLevel = $nowLevel - 1;
                    } else {
                        for ($i = $value['level']; $i <= ($nowLevel - $value['level']); $i++) {
                            if (!isset($joinDivideUser[$i]) && empty($levelUser[$i] ?? [])) {
                                $joinDivideUser[$i] = [];
                            }
                        }
                        //为了实现跨级可分润加上的代码---start
                        if(count($joinDivideUser[$value['level'] + 1] ?? []) >= $levelMaxNumber || $existLevel[$value['level'] + 1] - 1 < 0){
                            $nowLevel = $value['level'];
                        }
                        //为了实现跨级可分润加上的代码---end
//                        $nowLevel = $value['level'];

                    }

                    //$nowLevel = $nowLevel - 1;
                    //$nowLevel = $value['level'];
                    $value['divide_type'] = 1;
                    $value['vdc_level'] = 0;
                    //为了实现跨级可分润加上的代码---start
                    if ((count($joinDivideUser[$value['level'] + 1] ?? []) < $levelMaxNumber || $existLevel[$value['level'] + 1] - 1 > 0)) {
                        if((count($joinDivideUser[$value['level']] ?? []) < $aLevelMaxNumber[$value['level']])){
                            if(!empty(count($joinDivideUser[$value['level']] ?? [])) && !in_array($value['uid'], array_column($joinDivideUser[$value['level']], 'uid'))){
                                $value['divide_type'] = 2;
                                $value['vdc_level'] = count($joinDivideUser[$value['level']] ?? []);
                            }

                            $joinDivideUser[$value['level']][] = $value;
                            $existLevel[$value['level']] -= 1;
                            $joinRes = true;
                        }
                        //为了实现跨级可分润加上的代码---end
                    } else {
                        //为了实现跨级可分润加上的代码---start

                        if(count($joinDivideUser[$nowLevel] ?? []) < $levelMaxNumber) {
                            if(!empty(count($joinDivideUser[$value['level']] ?? [])) && !in_array($value['uid'], array_column($joinDivideUser[$nowLevel], 'uid'))){
                                $value['divide_type'] = 2;
                                $value['vdc_level'] = count($joinDivideUser[$value['level']] ?? []);
                            }

                            //为了实现跨级可分润加上的代码---end
                            $joinDivideUser[$nowLevel][] = $value;
                            $existLevel[$nowLevel] -= 1;
                            $joinRes = true;
                        }
                    }

                    //为了实现跨级可分润加上的代码---start
                    if(!empty($joinRes)){
                        $joinDivideUserNumber++;
                        //为了实现跨级可分润加上的代码---end
                        unset($linkUserParent[$key]);
                    }

                    continue;
                } else {

                    //如果在当前循环的等级中,还有符合的人则判断是否人数已经满了,满了则开始寻找上一档等级
//                    if (count($joinDivideUser[$nowLevel]) == $levelMaxNumber) {
                    //为了实现跨级可分润加上的代码---start
                    if (count($joinDivideUser[$nowLevel]) >= $levelMaxNumber && !in_array($value['uid'], array_column($joinDivideUser[$nowLevel], 'uid'))) {
                        //为了实现跨级可分润加上的代码---end
                        $nowLevel = $nowLevel - 1;
                        continue;
                    }
                }

//                        //如果用户关联的会员不能参与分润,则第三等级(团长级)不允许价差分润,直接走比例分润(此情况仅在团长级分润出现)---跨级取消价差分润操作,暂时先注释
//                        if($linkUserCanNotDivide && $nowLevel == 3){
//                            $levelMaxNumber -= 1;
//                        }

                //divide_type=1则为价差分润 =2则为比例分润
                if ($nowLevelNumber < $levelMaxNumber) {
                    if ($value['level'] == $nowLevel) {
                        //默认分润形式为比例分润,如果刚好当前用户处于该等级的第一位,则修改为价差分润
                        $value['divide_type'] = 2;
//                        if (empty($nowLevelNumber)) {
                        //为了实现跨级可分润加上的代码---start
                        if (empty(count($joinDivideUser[$value['level']]))) {
                            //为了实现跨级可分润加上的代码---end
                            $value['divide_type'] = 1;
                            $value['vdc_level'] = 0;
                            //跨级取消价差分润操作---暂时先注释
//                                    if($nowLevel == 3 && $linkUserCanNotDivide){
//                                        $value['divide_type'] = 2;
//                                    }

                        } else {
                            $joinDivideUserLevel[$nowLevel]++;
//                            $value['vdc_level'] = $joinDivideUserLevel[$nowLevel];
                            //为了实现跨级可分润加上的代码---start
                            $value['vdc_level'] = count($joinDivideUser[$value['level']] ?? []);
                            //为了实现跨级可分润加上的代码---end
                        }
                        $joinDivideUser[$nowLevel][] = $value;
                        $joinDivideUserNumber++;


                    }
                    unset($linkUserParent[$key]);
                }
            }
        }

        if (!empty($joinDivideUser)) {
            if(!empty($thisArrayMaxLevel) && count($joinDivideUser) != $thisArrayMaxLevel){
                for ($i = 1; $i <= $thisArrayMaxLevel; $i++) {
                    if (!isset($joinDivideUser[$i])) {
                        $joinDivideUser[$i] = [];
                    }
                }
            }
            krsort($joinDivideUser);
        }
        return ['joinDivideUser' => $joinDivideUser, 'joinDivideUserNumber' => $joinDivideUserNumber];
    }

    /**
     * @title  获取<参与分润的人按分润占比分摊优惠折扣的金额>后的数组
     * @param array $goodsInfo 当前循环的商品详情
     * @param array $aDivide 全部参与分润的人的数组
     * @param array $skuDividePrice 每个商品对应的(原价)分润总额
     * @return mixed
     */
    public function reduceDivideUserPrice(array $goodsInfo, array $aDivide, array $skuDividePrice)
    {
        if (!empty($aDivide) && !empty($goodsInfo) && !empty($skuDividePrice)) {
            $aDis = [];
            $aDisScale = [];
            if (!isset($aDis[$goodsInfo['sku_sn']])) {
                $aDis[$goodsInfo['sku_sn']] = 0;
            }
            if (!isset($aDisScale[$goodsInfo['sku_sn']])) {
                $aDisScale[$goodsInfo['sku_sn']] = 0;
            }
            $nDivide = $aDivide;
            //先按照分润金额升序排序,然后计算每个人分润金额占总分润金额的比例,然后慢慢累加判断是否超过100%,如果加上某个人的比例之后刚好超过100%,则剩下的所有该扣的金额都将给这个人
            array_multisort(array_column($nDivide[$goodsInfo['sku_sn']], 'divide_price'), SORT_ASC, $nDivide[$goodsInfo['sku_sn']]);
            foreach ($nDivide as $oKey => $oValue) {
                if (!empty(doubleval($goodsInfo['all_dis'])) && $oKey == $goodsInfo['sku_sn']) {
                    foreach ($oValue as $cKey => $cValue) {
                        $nowDisScale = round($cValue['divide_price'] / $skuDividePrice[$goodsInfo['sku_sn']], 2);
                        if ($aDisScale[$goodsInfo['sku_sn']] + $nowDisScale <= 1) {
                            $nDivide[$oKey][$cKey]['dis_reduce_price'] = priceFormat($goodsInfo['all_dis'] * $nowDisScale);
                            $nDivide[$oKey][$cKey]['real_divide_price'] -= priceFormat($goodsInfo['all_dis'] * $nowDisScale);
                            $aDisScale[$goodsInfo['sku_sn']] += $nowDisScale;
                            $aDis[$goodsInfo['sku_sn']] += $nDivide[$oKey][$cKey]['dis_reduce_price'];
                        } elseif ($aDisScale[$goodsInfo['sku_sn']] + $nowDisScale > 1) {
                            $nDivide[$oKey][$cKey]['dis_reduce_price'] = priceFormat($goodsInfo['all_dis'] - $aDis[$goodsInfo['sku_sn']]);
                            $nDivide[$oKey][$cKey]['real_divide_price'] -= priceFormat($goodsInfo['all_dis'] - $aDis[$goodsInfo['sku_sn']]);
                            $aDisScale[$goodsInfo['sku_sn']] = 1;
                            $aDis[$goodsInfo['sku_sn']] += $nDivide[$oKey][$cKey]['dis_reduce_price'];
                            break;
                        }
                    }

                }
            }
            //如果计算完了后总占比还是不够100,应扣金额还未扣完;则继续再来一次占比的扣除
            if ($aDis[$goodsInfo['sku_sn']] < $goodsInfo['all_dis']) {
                foreach ($nDivide as $oKey => $oValue) {
                    if (!empty(doubleval($goodsInfo['all_dis'])) && $oKey == $goodsInfo['sku_sn']) {
                        foreach ($oValue as $cKey => $cValue) {
                            $nowDisScale = round($cValue['divide_price'] / $skuDividePrice[$goodsInfo['sku_sn']], 2);
                            if ($aDisScale[$goodsInfo['sku_sn']] + $nowDisScale <= 1) {
                                $nDivide[$oKey][$cKey]['dis_reduce_price'] += priceFormat(($goodsInfo['all_dis'] - $aDis[$goodsInfo['sku_sn']]) * $nowDisScale);
                                $nDivide[$oKey][$cKey]['real_divide_price'] -= priceFormat(($goodsInfo['all_dis'] - $aDis[$goodsInfo['sku_sn']]) * $nowDisScale);
                                $aDisScale[$goodsInfo['sku_sn']] += $nowDisScale;
                                $aDis[$goodsInfo['sku_sn']] += $nDivide[$oKey][$cKey]['dis_reduce_price'];
                            } elseif ($aDisScale[$goodsInfo['sku_sn']] + $nowDisScale > 1) {
                                $nDivide[$oKey][$cKey]['dis_reduce_price'] += priceFormat(($goodsInfo['all_dis'] - $aDis[$goodsInfo['sku_sn']]));
                                $nDivide[$oKey][$cKey]['real_divide_price'] -= priceFormat(($goodsInfo['all_dis'] - $aDis[$goodsInfo['sku_sn']]));
                                $aDisScale[$goodsInfo['sku_sn']] = 1;
                                $aDis[$goodsInfo['sku_sn']] += $nDivide[$oKey][$cKey]['dis_reduce_price'];
                                break;
                            }
                        }

                    }
                }
            }
            //计算好重组数组回去$aDivide,这么做是为了跟原数组顺序和结构保持相同
            foreach ($aDivide as $aKey => $aValue) {
                foreach ($aValue as $oKey => $oValue) {
                    foreach ($nDivide as $nKey => $nValue) {
                        foreach ($nValue as $noKey => $noValue) {
                            if ($noValue['uid'] == $oValue['uid'] && $noValue['sku_sn'] == $oValue['sku_sn']) {
                                $aDivide[$aKey][$oKey]['dis_reduce_price'] = $noValue['dis_reduce_price'];
                                $aDivide[$aKey][$oKey]['real_divide_price'] = $noValue['real_divide_price'];
                            }
                        }

                    }
                }

            }
        }
        return $aDivide;
    }

    /**
     * @title  查看某个人下的全部团队成员
     * @param string $uid 需要查找的人的uid
     * @param bool $mySelfNotExist 是否需要剔除自己,默认否
     * @param bool $checkStatus 是否需要剔除状态不为正常的人员,默认否
     * @param string $newOrderSql 其他拼接排序的sql
     * @return array
     * @throws \Exception
     */
    public function getTeamAllUser(string $uid, bool $mySelfNotExist = false, bool $checkStatus = false, string $newOrderSql = '', array $otherMap = [])
    {
        $aTotal = 0;
        $pageTotal = 0;
        $pageNumber = 10;
        $newMap[] = ['', 'exp', Db::raw("find_in_set('$uid',team_chain)")];
        if (!empty($checkStatus)) {
            $newMap[] = ['status', '=', 1];
        }
        if (!empty($mySelfNotExist)) {
            $newMap[] = ['uid', '<>', $uid];
        }
        $orderSql = '';
        if (!empty($newOrderSql)) {
            switch ($newOrderSql) {
                case 1:
                    $orderSql = 'create_time desc';
                    break;
                default:
                    $orderSql = '';
            }
        }
        $dirUid = [];

        if (!empty($otherMap['returnFull'] ?? null)) {
            //统计团队总数
            $aTotal = (new TeamMember())->where($newMap)->count();
            $pageTotal = ceil($aTotal / $pageNumber);
        }

        if (!empty($otherMap['searType'] ?? null)) {
            switch ($otherMap['searType']) {
                case 1:
                    $newMap[] = ['level', '>', $otherMap['maxLevel']];
                    break;
                case 2:
//                    $newMap[] = ['', 'exp', Db::raw('length(team_chain) = 21')];
                    break;
                case 3:
                    $dirUser = TeamMember::where(['link_superior_user' => $uid, 'status' => [1, 2]])->column('uid');
                    if (!empty($dirUser)) {
                        $nextUser = TeamMember::where(['link_superior_user' => $dirUser, 'status' => [1, 2]])->column('uid');
                        if (!empty($nextUser)) {
                            $newMap[] = ['uid', 'in', $nextUser];
                        }
                    }
                    break;
            }
        }

        $listSql = (new TeamMember())->field('uid,user_phone,level,type,link_superior_user,status,create_time,upgrade_time,team_chain')->where($newMap)->when(!empty($orderSql), function ($query) use ($orderSql) {
            $query->order($orderSql);
        })->buildSql();
        $list = Db::query($listSql);

        if (!empty($list)) {
            if (!empty($otherMap['searType'] ?? null)) {
                if ($otherMap['searType'] == 1) {
                    $uMap[] = ['link_superior_user', '=', $uid];
                    $uMap[] = ['status', '=', 1];
                    $uMap[] = ['level', '<=', $otherMap['maxLevel']];
                    $dirUid = TeamMember::where($uMap)->column('uid');
                    if (!empty($dirUid)) {
                        foreach ($list as $key => $value) {
                            if (!empty($value['team_chain'])) {
                                foreach (explode(',', $value['team_chain']) as $tK => $tV) {
                                    if (in_array($tV, $dirUid)) {
                                        unset($list[$key]);
                                        continue;
                                    }

                                }
                            }
                        }
                    }
                }
            }

            if (!empty($list)) {
                //通过团队冗余结构计算属于团队第几层级
                foreach ($list as $key => $value) {
                    if (empty($value['team_chain'])) {
                        $list[$key]['team_level'] = 1;
                    } else {
                        //如果这个用户是属于其他团队链中的人,判断等级的时候应该以这个人为首,然后来统计层级
                        if (strlen($value['team_chain']) > 10) {
                            $value['team_chain'] = substr($value['team_chain'], 0, (strpos($value['team_chain'], $uid) + 10));
                        }
                        $list[$key]['team_level'] = count(explode(',', $value['team_chain'])) + 1;
                    }
                }
                if (!empty($list)) {
                    //按照团队等级来排序
                    array_multisort(array_column($list, 'team_level'), SORT_ASC, $list);
                }
            }

        }

        if (!empty($otherMap['returnFull'] ?? null)) {
            return ['list' => $list, 'pageTotal' => $pageTotal ?? 0, 'total' => $aTotal ?? 0];
        }

        return $list;
    }

    /**
     * @title  获取下级直推用户列表,筛选出正常状态的用户,并根据等级分组归类
     * @param string $uid 用户的uid
     * @param int $level 筛选等级 0以上为TeamMember,负数为统一找member
     * @return array
     * @throws \Exception
     */
    public function getNextDirectLinkUserGroupByLevel(string $uid, int $level = 0)
    {
        $map[] = ['status', '=', 1];
        $map[] = ['link_superior_user', '=', $uid];
        $model = (new TeamMember());
        //如果是-1则标识需要找会员表下的直推
        if (!empty($level) && $level < 0) {
            $model = (new Member());
        } else {
            if (!empty($level)) {
                $map[] = ['level', '=', $level];
            }
        }

        $directUser = $model->with(['user'])->where($map)->withoutField('id,update_time')->order('create_time desc')->select()->toArray();
        $all = $this->rebuildTeamUserGroupByLevel($directUser);
        return $all;
    }

    /**
     * @title  获取下级直推用户列表,筛选出正常状态的用户,并根据等级分组归类
     * @param string $uid 用户的uid
     * @return array
     * @throws \Exception
     */
    public function getNextDirectLinkUserGroupByLevelForUserTable(string $uid, int $level = 0)
    {
        $map[] = ['status', '=', 1];
        $map[] = ['link_superior_user', '=', $uid];
        if (!empty($level)) {
            $map[] = ['level', '=', $level];
        }
        $directUser = User::where($map)->field('uid,phone,vip_level as level,name,openid,avatarUrl,c_vip_level,c_vip_time_out_time,auto_receive_reward,link_superior_user,growth_value,status,create_time')->order('create_time desc')->select()->toArray();
        $all = $this->rebuildTeamUserGroupByLevel($directUser);
        return $all;
    }

    /**
     * @title  获取团队全部成员,筛选出正常状态的用户,并根据等级分组归类
     * @param string $uid 用户uid
     * @return mixed
     * @throws \Exception
     */
    public function getTeamAllUserGroupByLevel(string $uid, string $otherSql = '', array $otherMap = [])
    {
        if (!empty($otherSql)) {
            $allUser = $this->getTeamAllUser($uid, true, true, $otherSql, $otherMap);
        } else {
            $allUser = $this->getTeamAllUser($uid, true, true, '', $otherMap);
        }

        $all = $this->rebuildTeamUserGroupByLevel($allUser);
        return $all;
    }

    /**
     * @title  根据成员列表的等级分组归类
     * @param array $teamUser 成员列表
     * @return mixed
     */
    public function rebuildTeamUserGroupByLevel(array $teamUser)
    {
        $userLevel = [];
        $aTotal = 0;
        if (isset($teamUser['list'])) {
            $aTotal = $teamUser['total'] ?? 0;
            $teamUser = $teamUser['list'] ?? [];
        }

        if (!empty($teamUser)) {
            //顺便统计全部人
            $userLevel[0]['count'] = 0;
            $userLevel[0]['list'] = [];
            $userLevel[0]['onlyUidList'] = [];

            foreach ($teamUser as $key => $value) {
                if (!isset($userLevel[$value['level']])) {
                    $userLevel[$value['level']]['count'] = 0;
                    $userLevel[$value['level']]['list'] = [];
                    $userLevel[$value['level']]['onlyUidList'] = [];
                }
                $userLevel[$value['level']]['count'] ++;
                $userLevel[$value['level']]['list'][] = $value;
                $userLevel[$value['level']]['onlyUidList'][] = $value['uid'];

                $userLevel[0]['count'] ++;
                $userLevel[0]['list'][] = $value;
                $userLevel[0]['onlyUidList'][] = $value['uid'];

                //统计直推线下存在各会员等级的情况
                if (!empty($value['team_level'] ?? null)) {
                    if ($value['team_level'] >= 2) {
                        if ($value['team_level'] == 2) {
                            $linkUid = $value['uid'];
                        } else {
                            $linkUid = explode(',', $value['team_chain'])[$value['team_level'] - 3];
                        }
                        if (!isset($dirUser[$linkUid])) {
                            $dirUser[$linkUid] = [];
                        }
                        if (!isset($dirUser[$linkUid][$value['level']])) {
                            $dirUser[$linkUid][$value['level']] = true;
                        }
                    }
                }
            }
        }
        krsort($userLevel);
        $all['allUser']['count'] = count($teamUser);
        $all['allUser']['list'] = $teamUser;
        $all['allUser']['onlyUidList'] = array_unique(array_column($teamUser, 'uid'));
        $all['userLevel'] = $userLevel;
        $all['totalCount'] = $aTotal ?? 0;
        $all['dirUser'] = $dirUser ?? [];
        return $all;
    }

    /**
     * @title  确认收货后支付冻结中的分润订单
     * @param string $orderSn 订单编码
     * @param array $orderInfo 订单详情
     * @param bool $notThrowError 是否要报错
     * @return bool|mixed
     * @throws \Exception
     */
    public function payMoneyForDivideByOrderSn(string $orderSn, array $orderInfo, bool $notThrowError = false)
    {
        $cacheKey = 'TeamDivideOrderCache-' . $orderSn;
        $cacheExpire = 30;
        if (!empty(cache($cacheKey))) {
            if (empty($notThrowError)) {
                throw new OrderException(['msg' => '[团队业绩奖励] 前方网络拥堵,请稍后重试']);
            } else {
                return false;
            }

        }
        //加缓存锁
        cache($cacheKey, $orderSn, $cacheExpire);

        $dbRes = true;
        //订单商品详情
        $orderGoods = OrderGoods::with(['vdc'])->where(['order_sn' => $orderSn, 'status' => 1, 'pay_status' => 2])->withoutField('id,images,specs,desc,create_time,update_time')->select()->toArray();

        //判断商品状态
        $nowOrderStatus = Order::where(['order_sn' => $orderSn])->value('order_status');
        if ($nowOrderStatus != 3) {
            if (empty($notThrowError)) {
                throw new OrderException(['msg' => '[团队业绩奖励] 只有已发货且无退售后的未完结订单才可以确认收货哟']);
            } else {
                return false;
            }
        }

        $aDivides = [];
        $dbRes = Db::transaction(function () use ($orderSn, $aDivides, $orderInfo, $orderGoods) {
            //仅支付冻结中的分润列表
            $map[] = ['order_sn', '=', $orderSn];
            $map[] = ['arrival_status', '=', 2];
            $map[] = ['type', '=', 5];
            $aDivides = DivideModel::where($map)->lock(true)->select()->toArray();

            //如果存在分润则释放分润的冻结金额
            if (!empty($aDivides)) {
                $goodsTitle = array_column($orderGoods, 'title');
                //查找用户资料,修改用户余额,添加余额明细,发送模板消息通知等
                $allUid = array_unique(array_column($aDivides, 'link_uid'));
                $allUser = User::where(['uid' => $allUid, 'status' => 1])->field('uid,avaliable_balance,divide_balance,total_balance,integral')->lock(true)->select()->toArray();
                $balanceDetail = (new BalanceDetail());
                $allLevel = array_column($aDivides, 'level');
                $memberIncentives = MemberIncentives::where(['level' => $allLevel, 'status' => 1])->column('billing_cycle_switch', 'level');
                $res = false;
                foreach ($aDivides as $dsKey => $dsValue) {
                    foreach ($allUser as $key => $value) {
                        if ($value['uid'] == $dsValue['link_uid']) {
                            //修改冻结状态为已支付
                            $divideStatus['arrival_status'] = 1;
                            $divideStatus['arrival_time'] = time();
                            $dRes = DivideModel::update($divideStatus, ['id' => $dsValue['id']]);
                            $divideRes[] = $dRes->getData();

                            $uRes = User::where(['uid' => $value['uid'], 'status' => 1])->inc('team_balance', $dsValue['real_divide_price'])->update();
                            $userRes[] = ['res' => $uRes, 'uid' => $value['uid']];

                            //增加余额明细
                            $detail['order_sn'] = $orderInfo['order_sn'];
                            $detail['belong'] = $orderInfo['order_belong'] ?? 1;
                            $detail['uid'] = $value['uid'];
                            $detail['type'] = 1;
                            $detail['price'] = priceFormat($dsValue['real_divide_price']);
                            //type 16为团队业绩奖励
                            $detail['change_type'] = 16;
                            $bRes = $balanceDetail->new($detail);

                            $balanceRes[] = $bRes;

                            //推送模版消息通知
                            $template['uid'] = $value['uid'];
                            $template['type'] = 'divide';
                            $tile = $goodsTitle[0];
                            if (count($goodsTitle) > 1) {
                                $tile .= '等';
                            }
                        }
                    }

                }
            }

            $allRes['msg'] = '发放订单团队业绩奖励完成';
            $allRes['orderRes'] = $res;
            $allRes['divideUser'] = $userRes ?? [];
            $allRes['divideDivide'] = $divideRes ?? [];
            $allRes['divideBalances'] = $balanceRes ?? [];
            return $allRes;
        });

        //记录日志
        $log['orderSn'] = $orderSn;
        $log['orderInfo'] = $orderInfo;
        $log['res'] = $dbRes;
        (new Log())->setChannel('divide')->record($log);

        //清除缓存锁
        cache($cacheKey, null);

        return $dbRes;

    }

    /**
     * @title  售后成功后取消冻结中或其他已分润的分润订单
     * @param string $orderSn 订单编码
     * @param string $skuSn 商品SKU
     * @param array $orderInfo 订单详情
     * @return bool|mixed
     * @throws \Exception
     */
    public function deductMoneyForDivideByOrderSn(string $orderSn, string $skuSn, array $orderInfo)
    {
        //订单商品详情
        $orderGoods = OrderGoods::with(['vdc'])->where(['order_sn' => $orderSn, 'status' => 1, 'pay_status' => 2])->withoutField('id,images,specs,desc,create_time,update_time')->select()->toArray();
        //该订单的分润列表
        $map[] = ['order_sn', '=', $orderSn];
        $map[] = ['sku_sn', '=', $skuSn];
        $aDivides = DivideModel::where($map)->select()->toArray();

        $dbRes = Db::transaction(function () use ($aDivides, $orderInfo, $orderGoods, $orderSn, $skuSn) {
            //取消团队订单
            if (!empty($orderSn) && !empty($skuSn)) {
                $map[] = ['sku_sn', '=', $skuSn];
                $map[] = ['order_sn', '=', $orderSn];
                $map[] = ['status', '=', 1];
                $map[] = ['after_status', '=', 10];
                $afInfo = AfterSale::where($map)->findOrEmpty()->toArray();
                $teamOrderStatus['record_status'] = -1;
                $teamOrderStatus['status'] = -1;
                $teamOrderStatus['refund_price'] = $afInfo['apply_price'] ?? 0;
                $teamRes = TeamPerformance::update($teamOrderStatus, ['order_sn' => $orderSn, 'sku_sn' => $skuSn]);
            }

            $res = true;
            //如果存在分润则取消分润的冻结金额
            if (!empty($aDivides)) {
                $goodsTitle = array_column($orderGoods, 'title');
                //查找用户资料,修改用户余额,添加余额明细,发送模板消息通知等
                $allUid = array_unique(array_column($aDivides, 'link_uid'));
                $allUser = User::where(['uid' => $allUid])->field('uid,avaliable_balance,divide_balance,total_balance,integral')->select()->toArray();
                $balanceDetail = (new BalanceDetail());
                $res = false;
                if (!empty($allUser)) {
                    foreach ($aDivides as $dsKey => $dsValue) {
                        foreach ($allUser as $key => $value) {
                            if ($value['uid'] == $dsValue['link_uid']) {
                                //修改冻结状态为退款取消分润
                                $divideStatus['refund_reduce_price'] = $dsValue['real_divide_price'];
                                $divideStatus['real_divide_price'] = 0;
                                $divideStatus['arrival_status'] = 3;
                                $divideStatus['status'] = -1;
                                $divideStatus['arrival_time'] = time();
                                $res = DivideModel::update($divideStatus, ['id' => $dsValue['id']]);

                                //如果之前是已支付的状态需要扣除
                                if ($dsValue['arrival_status'] == 1) {
                                    //修改余额
//                                    $save['avaliable_balance'] = priceFormat($value['avaliable_balance'] - $dsValue['real_divide_price']);
//                                    $save['total_balance'] = priceFormat($value['total_balance'] - $dsValue['real_divide_price']);
                                    $save['divide_balance'] = priceFormat($value['divide_balance'] - $dsValue['real_divide_price']);
                                    $res = User::update($save, ['uid' => $value['uid'], 'status' => 1]);

//                                    $allUser[$key]['avaliable_balance'] = $save['avaliable_balance'];
//                                    $allUser[$key]['total_balance'] = $save['total_balance'];
                                    $allUser[$key]['total_balance'] = $save['divide_balance'];

                                    //增加余额明细
                                    $detail['order_sn'] = $orderInfo['order_sn'];
                                    $detail['belong'] = $orderInfo['order_belong'] ?? 1;
                                    $detail['uid'] = $value['uid'];
                                    $detail['type'] = 2;
                                    $detail['price'] = '-' . priceFormat($dsValue['real_divide_price']);
                                    $detail['change_type'] = $dsValue['type'] == 3 ? 14 : 6;
                                    $balanceDetail->new($detail);

                                    //                            //推送模版消息通知
//                            $template['uid'] = $value['uid'];
//                            $template['type'] = 'divide';
//                            $tile = $goodsTitle[0];
//                            if(count($goodsTitle) > 1){
//                                $tile .= '等';
//                            }
//                            $template['template'] = ['first'=>'订单分润已被扣除~',$orderInfo['user_name'],date('Y-m-d H:i:s',$orderInfo['create_time']),priceFormat($dsValue['real_divide_price']),$tile];
//                            //Queue::push('app\lib\job\Template',$template,'tcmTemplateList');


                                }
                            }
                        }
                    }
                }

            }

            return $res;
        });

        return $dbRes;

    }

    /**
     * @title  查找更高等级(level更低)的关联上级用户,如果没有则一直往上找,递归
     * @param string $topUid
     * @param int $nowLevel
     * @return array
     */
    public function getTopLevelLinkUser(string $topUid, int $nowLevel)
    {
        $member = TeamMember::where(['uid' => $topUid, 'status' => 1])->withoutField('id,create_time,update_time')->findOrEmpty()->toArray();
        if (intval($member['level']) > $nowLevel) {
            $user = $this->getTopLevelLinkUser($member['link_superior_user'], $nowLevel);
        } else {
            $user = $member;
        }
        return $user;
    }

    /**
     * @title  给用户的全部上级都添加上/扣除团队业绩
     * @param array $orderInfo 订单详情
     * @param int $type 添加类型 1为添加 2为减少
     * @param mixed $skuSn SKU数组
     * @return bool
     * @throws \Exception
     */
    public function getTopUserRecordTeamPerformance(array $orderInfo, int $type = 1, $skuSn = [])
    {
        $orderSn = $orderInfo['order_sn'];
        $uid = $orderInfo['uid'];

        //是否需要包含自己,默认不包含
        if (empty($orderInfo['notFindMyself'] ?? false)) {
            $needFindMyself = "AND u2.uid != " . "'" . $uid . "'" . "";
        } else {
            $needFindMyself = ' ';
        }

        //查找全部团队上级成员

        $databaseVersion = str_replace('.', '', config('system.databaseVersion'));
        if (!empty($databaseVersion) && is_numeric($databaseVersion) && $databaseVersion > 8016) {
            //mysql 8.0.16以上用这个
            $parent = Db::query("SELECT u2.member_card,u2.uid,u2.phone as user_phone,u2.vip_level as level,u2.team_vip_level,u2.status,u2.link_superior_user,u1.LEVEL AS divide_level FROM(SELECT @id c_ids,(SELECT @id := GROUP_CONCAT( link_superior_user ) FROM sp_user ,(SELECT @id := " . "'" . $uid . "'" . ",@l := 0 ) b WHERE FIND_IN_SET( uid, @id COLLATE utf8mb4_unicode_ci )) p_ids,@l := @l + 1 AS LEVEL FROM sp_user WHERE @id IS NOT NULL ) u1 JOIN sp_user u2 ON u1.c_ids = u2.uid COLLATE utf8mb4_unicode_ci WHERE u2.status = 1 and u2.vip_level != 0 " . $needFindMyself . " ORDER BY u1.LEVEL DESC;");
        } else {
            //mysql 8.0.16及以下用这个
            $parent = Db::query("SELECT u2.member_card,u2.uid,u2.phone as user_phone,u2.vip_level as level,u2.team_vip_level,u2.status,u2.link_superior_user,u1.LEVEL AS divide_level FROM(SELECT @id c_ids,(SELECT @id := GROUP_CONCAT( link_superior_user ) FROM sp_user WHERE FIND_IN_SET( uid, @id COLLATE utf8mb4_unicode_ci )) p_ids,@l := @l + 1 AS LEVEL FROM sp_user,(SELECT @id := " . "'" . $uid . "'" . ",@l := 0 ) b WHERE @id IS NOT NULL ) u1 JOIN sp_user u2 ON u1.c_ids = u2.uid COLLATE utf8mb4_unicode_ci WHERE u2.status = 1 and u2.vip_level != 0 " . $needFindMyself . " ORDER BY u1.LEVEL DESC;");
        }

        if (empty($parent)) {
            return false;
        }

        //统计可分润的所有商品总价
        if (!empty($skuSn)) {
            if (is_array($skuSn)) {
                $map[] = ['sku_sn', 'in', $skuSn];
            } else {
                $map[] = ['sku_sn', '=', $skuSn];
            }
        }
        $map[] = ['order_sn', '=', $orderSn];
        $map[] = ['pay_status', '=', 2];
        if($type == 1){
            $map[] = ['status', '=', 1];
        }
//        $map[] = ['activity_sign', 'in', (new TeamMemberService())->activitySign];

        $orderGoods = OrderGoods::with(['vdc'])->where(function($query){
            $whereOr1[] = ['activity_sign', 'in', (new TeamMemberService())->activitySign];
            $whereOr2[] = ['', 'exp', Db::raw('crowd_code is not null and crowd_round_number is not null and crowd_period_number is not null')];
            $query->whereOr([$whereOr1,$whereOr2]);
        })->where($map)->withoutField('id,images,specs,desc,create_time,update_time')->select()->toArray();

        if (empty($orderGoods)) {
            return false;
        }

        //查看是否记录过团队业绩, 如果记录过不允许重复记录
        if (!empty($skuSn)) {
            if (is_array($skuSn)) {
                $rMap[] = ['sku_sn', 'in', $skuSn];
            } else {
                $rMap[] = ['sku_sn', '=', $skuSn];
            }
        }
        $rMap[] = ['order_sn', '=', $orderSn];
        $rMap[] = ['record_team', '=', 1];
        if($type == 1){
            $map[] = ['status', '=', 1];
        }
        $recordExist = TeamPerformance::where($rMap)->column('sku_sn');
        if(!empty($recordExist)){
            foreach ($orderGoods as $key => $value) {
                if (!empty($value['sku_sn'] ?? null) && in_array($value['sku_sn'], array_unique($recordExist))) {
                    unset($orderGoods[$key]);
                }
            }
        }

        if (empty($orderGoods ?? [])) {
            return false;
        }
        $orderGoodsTotalPrice = 0;
        foreach ($orderGoods as $key => $value) {
            //如果是众筹订单没有众筹标识则剔除
            if (!empty($value['order_type'] ?? null) && $value['order_type'] == 6) {
                if ((empty($value['crowd_code'] ?? null) && empty($value['crowd_round_number'] ?? null) && empty($value['crowd_period_number'] ?? null))) {
                    unset($orderGoods[$key]);
                    continue;
                }
            } else {
                //必须为3,4两个活动专区的商品才可以计入团队业绩或者
                if (!in_array($value['activity_sign'], (new TeamMemberService())->activitySign)) {
                    unset($orderGoods[$key]);
                    continue;
                }
            }

            if ($value['vdc_allow'] == 1) {
                if($type == 1){
                    $orderGoodsTotalPrice += (string)($value['total_price'] - ($value['refund_price'] ?? 0)) > 0 ? $value['total_price'] : 0;
                }else{
                    $orderGoodsTotalPrice += ($value['refund_price'] ?? 0);
                }

            }
        }
        if (empty($orderGoodsTotalPrice) || $orderGoodsTotalPrice < 0 || empty($orderGoods ?? [])) {
            return false;
        }

        //数据库操作
        $allUid = array_column($parent, 'uid');
        $allSku = array_column($orderGoods,'sku_sn');
        foreach ($parent as $key => $value) {
            $userInfo[$value['uid']] = $value;
        }
        if (!empty($allUid)) {
            foreach ($allUid as $key => $value) {
                if ($value == $uid) {
                    unset($allUid[$key]);
                }
            }
        }

        if (empty($allUid)) {
            return false;
        }

        $userModel = (new User());
        $memberModel = (new TeamMember());
        $DBRes = Db::transaction(function () use ($allUid, $userModel,$memberModel, $orderGoodsTotalPrice, $type,$userInfo,$orderSn,$allSku,$uid) {
            $orderUid = Order::where(['order_sn'=>$orderSn])->value('uid');
            foreach ($allUid as $key => $value) {
                //订单用户不允许增加业绩
                if($value == $orderUid){
                    continue;
                }
                if ($type == 1) {
                    $res = $userModel->where(['uid' => $value])->inc('team_sales_price', $orderGoodsTotalPrice)->update();
                    $res = $memberModel->where(['uid' => $value])->inc('team_sales_price', $orderGoodsTotalPrice)->update();
                    //尝试成为团队业绩会员或升级,分开推送,分批执行
                    if (empty($userInfo[$value]['team_vip_level'] ?? 0) && !empty($userInfo[$value]['level'])) {
                        Queue::later(20 * ($key + 1), 'app\lib\job\TeamMemberUpgrade', ['uid' => $value, 'type' => 2], config('system.queueAbbr') . 'TeamMemberUpgrade');
                    } elseif (!empty($userInfo[$value]['team_vip_level'] ?? 0)) {
                        Queue::later(10 * ($key + 1), 'app\lib\job\TeamMemberUpgrade', ['uid' => $value, 'type' => 1], config('system.queueAbbr') . 'TeamMemberUpgrade');
                    }
                } elseif ($type == 2) {
                    //如果是业绩删减需要对团队业绩的销售额也做删减
                    $res = $userModel->where(['uid' => $value])->dec('team_sales_price', $orderGoodsTotalPrice)->update();
                    $res = $memberModel->where(['uid' => $value])->dec('team_sales_price', $orderGoodsTotalPrice)->update();

                    //判断是否销售额不达标, 需要做降级处理
                    Queue::later(10 * ($key + 1), 'app\lib\job\TeamMemberUpgrade', ['uid' => $value, 'type' => 3], config('system.queueAbbr') . 'TeamMemberUpgrade');
                }

            }
            if($type == 1){
                //修改团队业绩为记录过
                TeamPerformance::update(['record_team' => 1], ['order_sn' => $orderSn, 'sku_sn' => $allSku, 'status' => 1,'link_uid'=>$allUid]);
            }

            return $res;
        });

        return true;
    }

    /**
     * @title  发放团队业绩感恩奖
     * @param array $data
     * @return array
     * @throws \Exception
     */
    public function sendGrateful(array $data)
    {
        $orderInfo = $data['orderInfo'];
        $linkUser = $data['linkUser'];
        $orderGoods = $data['orderGoods'];
        $searType = $data['searType'] ?? 1;
        $aDivides = $data['aDivides'] ?? [];
        //被股票感恩奖切分的比例
        $allotDivideScale = $data['allotDivideScale'] ?? 0;
        $returnData = ['res' => false, 'errorMsg' => '发放感恩奖出错', 'param' => $data];
        $handselRes = false;
        if (empty($orderInfo) || !in_array($orderInfo['order_type'], [1, 3, 6])) {
            return ['res' => false, 'errorMsg' => '不符合的订单类型~', 'param' => $data];
        }
        $allDivideUser = array_column($aDivides,'uid');
        foreach ($aDivides as $key => $value) {
            $aDividesInfo[$value['uid']] = $value;
        }
        $allTopUser = User::where(['uid'=>$allDivideUser,'status'=>1])->field('link_superior_user,uid,vip_level,team_vip_level')->select()->toArray();

        $gratefulRule = TeamMemberVdc::where(['status' => 1])->order('level desc')->field('grateful_vdc_one,grateful_vdc_two,level')->select()->toArray();
        if (empty($gratefulRule)) {
            return ['res' => false, 'errorMsg' => '不存在有效的感恩奖规则', 'param' => $data];
        }
        $gratefulRuleInfo[0] = 0.05;
        foreach ($gratefulRule as $key => $value) {
            $gratefulRuleInfo[$value['level']] = $value;
        }
        $existDivide = DivideModel::where(['type' => 5, 'order_sn' => $orderInfo['order_sn'], 'status' => 1, 'is_grateful' => 1])->count();
        if (!empty($existDivide)) {
            return ['res' => false, 'errorMsg' => '该订单已存在团队业绩奖励感恩奖,不允许重复发放', 'param' => $data, 'existDivide' => $existDivide];
        }
        $userList = $allTopUser;
        if (empty($userList)) {
            return ['res' => false, 'errorMsg' => '无符合要求的上级或查无有效直推用户', 'userList' => $userList ?? [], 'param' => $data];
        }

        foreach ($userList as $uKey => $uValue) {
            if(empty($uValue['link_superior_user'] ?? null)){
                continue;
            }
            foreach ($orderGoods as $key => $value) {
                $divides[$uKey]['order_sn'] = $orderInfo['order_sn'];
                $divides[$uKey]['order_uid'] = $orderInfo['uid'];
                $divides[$uKey]['belong'] = 1;
                $divides[$uKey]['type'] = 5;
                $divides[$uKey]['is_grateful'] = 1;
                $divides[$uKey]['goods_sn'] = $value['goods_sn'];
                $divides[$uKey]['sku_sn'] = $value['sku_sn'];
                $divides[$uKey]['price'] = $value['price'];
                $divides[$uKey]['count'] = $value['count'];
                $divides[$uKey]['total_price'] = $aDividesInfo[$uValue['uid']]['real_divide_price'] ?? 0;
                $divides[$uKey]['vdc'] = $gratefulRuleInfo[$uValue['team_vip_level']]['team_grateful_vdc_one'] ?? 0.05;
                $divides[$uKey]['vdc_genre'] = 2;
                $divides[$uKey]['divide_type'] = 2;
                $divides[$uKey]['purchase_price'] = $value['total_price'];
                $divides[$uKey]['level'] = $uValue['team_vip_level'] ?? 0;
                $divides[$uKey]['link_uid'] = $uValue['link_superior_user'];
                $divides[$uKey]['divide_price'] = priceFormat($aDividesInfo[$uValue['uid']]['real_divide_price'] * $divides[$uKey]['vdc']);

                $divides[$uKey]['real_divide_price'] = $divides[$uKey]['divide_price'];
                $divides[$uKey]['arrival_status'] = 2;
                $divides[$uKey]['remark'] = '团队业绩感恩奖';

                if ($orderInfo['order_type'] == 6) {
                    $divides[$uKey]['remark'] = '福利活动团队业绩感恩奖';
                    $divides[$uKey]['crowd_code'] = $value['crowd_code'];
                    $divides[$uKey]['crowd_round_number'] = $value['crowd_round_number'];
                    $divides[$uKey]['crowd_period_number'] = $value['crowd_period_number'];
                }
                if (!empty((string)$allotDivideScale ?? 0)) {
                    $divides[$uKey]['is_allot'] = 1;
                    $divides[$uKey]['allot_scale'] = $allotDivideScale;
                }
            }
        }
        if (!empty($divides)) {
            if ($searType == 1) {
                (new DivideModel())->saveAll(array_values($divides));
                $returnData = ['res' => true, 'errorMsg' => '成功生成冻结中的团队业绩感恩奖', 'data' => $divides, 'param' => $data, 'userList' => $userList, 'handselRes' => $handselRes ?? []];
            } else {
                $returnData = ['res' => true, 'errorMsg' => '仅查询预冻结中的团队业绩感恩', 'data' => $divides, 'param' => $data, 'userList' => $userList, 'handselRes' => $handselRes ?? []];
            }
        }

        return $returnData;
    }

    /**
     * @title  发放股票感恩奖
     * @param array $data
     * @return array
     * @throws \Exception
     */
    public function sendStocksGrateful(array $data)
    {
        $orderInfo = $data['orderInfo'];
        $linkUser = $data['linkUser'];
        $orderGoods = $data['orderGoods'];
        $searType = $data['searType'] ?? 1;
        $aDivides = $data['aDivides'] ?? [];
        $allotDivideScale = $data['allotDivideScale'] ?? 0;
        $returnData = ['res' => false, 'errorMsg' => '发放股票感恩奖出错', 'param' => $data];
        $handselRes = false;
        if (empty($orderInfo) || !in_array($orderInfo['order_type'], [1, 3, 6])) {
            return ['res' => false, 'errorMsg' => '不符合的订单类型~', 'param' => $data];
        }
        $allDivideUser = array_column($aDivides, 'uid');
        foreach ($aDivides as $key => $value) {
            if ($value['type'] != 9) {
                unset($aDivides[$key]);
            }
        }
        if (empty($aDivides)) {
            return ['res' => false, 'errorMsg' => '无符合的分润记录~', 'param' => $data];
        }
        foreach ($aDivides as $key => $value) {
            $aDividesInfo[$value['uid']] = $value;
        }
        $allTopUser = User::where(['uid'=>$allDivideUser,'status'=>1])->field('link_superior_user,uid,vip_level,team_vip_level')->select()->toArray();

        $gratefulRule = TeamMemberVdc::where(['status' => 1])->order('level desc')->field('grateful_vdc_one,grateful_vdc_two,level')->select()->toArray();
        if (empty($gratefulRule)) {
            return ['res' => false, 'errorMsg' => '不存在有效的感恩奖规则', 'param' => $data];
        }
        $gratefulRuleInfo[0] = 0.05;
        foreach ($gratefulRule as $key => $value) {
            $gratefulRuleInfo[$value['level']] = $value;
        }
        $existDivide = DivideModel::where(['type' => 9, 'order_sn' => $orderInfo['order_sn'], 'status' => 1, 'is_grateful' => 1])->count();
        if (!empty($existDivide)) {
            return ['res' => false, 'errorMsg' => '该订单已存在股票奖励感恩奖,不允许重复发放', 'param' => $data, 'existDivide' => $existDivide];
        }
        $userList = $allTopUser;
        if (empty($userList)) {
            return ['res' => false, 'errorMsg' => '无符合要求的上级或查无有效直推用户', 'userList' => $userList ?? [], 'param' => $data];
        }

        foreach ($userList as $uKey => $uValue) {
            if(empty($uValue['link_superior_user'] ?? null)){
                continue;
            }
            foreach ($orderGoods as $key => $value) {
                $divides[$uKey]['order_sn'] = $orderInfo['order_sn'];
                $divides[$uKey]['order_uid'] = $orderInfo['uid'];
                $divides[$uKey]['belong'] = 1;
                $divides[$uKey]['type'] = 9;
                $divides[$uKey]['is_grateful'] = 1;
                $divides[$uKey]['goods_sn'] = $value['goods_sn'];
                $divides[$uKey]['sku_sn'] = $value['sku_sn'];
                $divides[$uKey]['price'] = $value['price'];
                $divides[$uKey]['count'] = $value['count'];
                $divides[$uKey]['total_price'] = $aDividesInfo[$uValue['uid']]['real_divide_price'] ?? 0;
                $divides[$uKey]['vdc'] = $gratefulRuleInfo[$uValue['team_vip_level']]['team_grateful_vdc_one'] ?? 0.05;
                $divides[$uKey]['vdc_genre'] = 2;
                $divides[$uKey]['divide_type'] = 2;
                $divides[$uKey]['purchase_price'] = $value['total_price'];
                $divides[$uKey]['level'] = $uValue['team_vip_level'] ?? 0;
                $divides[$uKey]['link_uid'] = $uValue['link_superior_user'];
                $divides[$uKey]['divide_price'] = priceFormat($aDividesInfo[$uValue['uid']]['real_divide_price'] * $divides[$uKey]['vdc']);

                $divides[$uKey]['real_divide_price'] = $divides[$uKey]['divide_price'];
                $divides[$uKey]['arrival_status'] = 2;
                $divides[$uKey]['remark'] = '股票奖励感恩奖';

                if ($orderInfo['order_type'] == 6) {
                    $divides[$uKey]['remark'] = '福利活动股票奖励感恩奖';
                    $divides[$uKey]['crowd_code'] = $value['crowd_code'];
                    $divides[$uKey]['crowd_round_number'] = $value['crowd_round_number'];
                    $divides[$uKey]['crowd_period_number'] = $value['crowd_period_number'];
                }

                if (!empty((string)$allotDivideScale ?? 0)) {
                    $divides[$uKey]['is_allot'] = 1;
                    $divides[$uKey]['allot_scale'] = $allotDivideScale;
                    $divides[$uKey]['allot_type'] = 1;
                }
            }
        }
        if (!empty($divides)) {
            if ($searType == 1) {
                (new DivideModel())->saveAll(array_values($divides));
                $returnData = ['res' => true, 'errorMsg' => '成功生成冻结中的股票奖励感恩奖', 'data' => $divides, 'param' => $data, 'userList' => $userList, 'handselRes' => $handselRes ?? []];
            } else {
                $returnData = ['res' => true, 'errorMsg' => '仅查询预冻结中的股票奖励感恩', 'data' => $divides, 'param' => $data, 'userList' => $userList, 'handselRes' => $handselRes ?? []];
            }
        }

        return $returnData;
    }


    /**
     * @title  记录错误并保存日志,删除该任务后终止
     * @param array $data 所有数据
     * @param array $error 错误内容
     * @return bool
     */
    public function recordError(array $data, array $error)
    {
        $allData['msg'] = '[团队业绩奖励] 订单 ' . ($data['requestData']['order_sn'] ?? "<暂无订单编号>") . " [ 服务出错:" . ($error['msg'] ?? '原因未知') . " ] ";
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
        return (new Log())->setChannel('teamDivide')->record($data, $level);
    }
}