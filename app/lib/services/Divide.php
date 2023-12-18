<?php
// +----------------------------------------------------------------------
// |[ 文档说明: 分润规则逻辑Service]
// +----------------------------------------------------------------------
// | Copyright (c) 2018~2024 http://www.mlhcmk.com All rights reserved.
// +----------------------------------------------------------------------
 

namespace app\lib\services;


use app\lib\exceptions\OrderException;
use app\lib\models\AfterSale;
use app\lib\models\BalanceDetail;
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
use app\lib\models\TeamPerformance;
use app\lib\models\User;
use app\lib\models\Divide as DivideModel;
use app\lib\models\Order;

use function PHPSTORM_META\map;
use think\facade\Db;
use think\facade\Queue;

class Divide
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
            return $this->recordError($log, ['msg' => '传入参数为空']);
        }

        $dbRes = [];
        $orderSn = $data['order_sn'];
        //查找类型 1为计算分润并存入数据 2为仅计算分润不存入(供仅查询使用)
        $searType = $data['searType'] ?? 1;
        $searTypeName = $searType == 1 ? '计算' : '查询';
        if (empty($orderSn)) {
            return $this->recordError($log, ['msg' => '参数中订单编号为空,非法!']);
        }
        $log['msg'] = '订单' . $orderSn . '的分润,类型为 [ ' . $searTypeName . ' ]';


        $orderInfo = Db::name('order')->alias('a')
            ->join('sp_user b', 'a.uid = b.uid', 'left')
            ->join('sp_member c', 'a.uid = c.uid', 'left')
            ->field('a.*,b.name as user_name,b.vip_level,b.member_card,b.avaliable_balance,b.total_balance,b.divide_balance,b.link_superior_user,c.parent_team,c.child_team_code')
            ->where(['a.order_sn' => $orderSn, 'a.pay_status' => 2])
            ->findOrEmpty();

        if (empty($orderInfo)) {
            $searNumber = $data['searNumber'] ?? 1;
            if (!empty($searNumber)) {
                if (intval($searNumber) == 1) {
                    $divideQueue = Queue::later(10, 'app\lib\job\DividePrice', ['order_sn' => $orderSn, 'searNumber' => $searNumber + 1], config('system.queueAbbr') . 'OrderDivide');
                    return $this->recordError($log, ['msg' => '非法订单,查无此项,将于十秒后重新计算一次分润']);
                } else {
                    return $this->recordError($log, ['msg' => '非法订单,查无此项,已经重复计算了多次,不再继续查询']);
                }
            }

        }

        $log['orderInfo'] = $orderInfo;

        //订单商品
        $orderGoods = OrderGoods::with(['vdc'])->where(['order_sn' => $orderSn, 'status' => 1, 'pay_status' => 2])->withoutField('id,images,specs,desc,create_time,update_time')->select()->toArray();
        $log['orderGoods'] = $orderGoods ?? [];

        //order_type=7美丽豆兑换订单order_type=8美丽券兑换订单,或者美丽金支付的商城订单 不允许任何分润奖励和计入团队业绩
        if (in_array($orderInfo['order_type'], [7, 8]) || ($orderInfo['order_type'] == 1 && $orderInfo['pay_type'] == 5)) {
            return $this->recordError($log, ['msg' => '兑换订单不允许享受分润和团队业绩记录, 不再继续操作']);
        }
        $isGiftOrder = false;
        foreach ($orderGoods as $key => $value) {
            if (!empty($value['gift_type'] ?? null) && intval($value['gift_type']) > -1) {
                $isGiftOrder = true;
            }
        }
        if (!empty($isGiftOrder ?? false)) {
            return $this->recordError($log, ['msg' => '本订单包含赠送商品,不允许享受分润和团队业绩记录, 不再继续操作']);
        }
        //转售的订单(order_type=5)不允许任何分润奖励和计入团队业绩
        if ($orderInfo['order_type'] == 5) {
            //发放转售订单对应的成长值
            (new GrowthValue())->buildResaleOrderGrowthValue($orderSn);
            //立马判断是否可以升级
            $upgradeQueue = Queue::push('app\lib\job\MemberUpgrade', ['uid' => $orderInfo['uid']], config('system.queueAbbr') . 'MemberUpgrade');
            //发放转售订单上级奖励
            $resaleOrderTopUserRewardRes = $this->resaleOrderTopUserDivide(['orderInfo' => $orderInfo, 'linkUid' => $orderInfo['link_superior_user'], 'orderGoods' => $orderGoods, 'searType' => $searType]);
            $log['resaleOrderTopUserRewardRes'] = $resaleOrderTopUserRewardRes;

            return $this->recordError($log, ['msg' => '转售订单不允许享受分润和团队业绩记录, 不再继续操作']);
        }

        //记录团队订单
        $teamOrderRes = (new Team())->recordOrderForTeam(['order_sn' => $orderSn, 'orderInfo' => $orderInfo]);
        $exMap[] = ['order_sn', '=', $orderSn];
        $exMap[] = ['status', '=', 1];
        $exMap[] = ['type', '=', 1];
        $exMap[] = ['level', '<>', 0];
        $exMap[] = ['is_grateful', '=', 2];
        $existDivide = DivideModel::where($exMap)->count();
//        $existDivide = DivideModel::where(['order_sn' => $orderSn, 'status' => 1, 'type' => 1])->count();

        if ($searType == 1 && !empty($existDivide)) {
            $log['existDivideNumber'] = $existDivide;

            //发放团队业绩奖励和销售额累计
            if (in_array($orderInfo['order_type'], [1, 3])) {
                $divideQueue = Queue::push('app\lib\job\TeamDividePrice', ['order_sn' => $orderInfo['order_sn'], 'searNumber' => $data['searNumber'] ?? 1], config('system.queueAbbr') . 'TeamOrderDivide');
            }
            return $this->recordError($log, ['msg' => '该订单已经计算过分润,不可重复计算']);
        }

        if (empty($orderGoods)) {
            //如果此单刚好是拼团最后一单,但是因此中断分润,会导致后续无法发放成长值,故在此发放成长值
            if ($orderInfo['order_type'] == 2) {
                //处理拼团分润后的成长值和分润
                $this->ptOrderUserUpgrade(['order_sn' => $orderSn]);
            } else {
                //发放订单对应的成长值
                (new GrowthValue())->buildOrderGrowthValue($orderSn);
                //立马判断是否可以升级
                $upgradeQueue = Queue::push('app\lib\job\MemberUpgrade', ['uid' => $orderInfo['uid']], config('system.queueAbbr') . 'MemberUpgrade');
                //发放团队业绩奖励和销售额累计
                if (in_array($orderInfo['order_type'], [1, 3])) {
                    $divideQueue = Queue::push('app\lib\job\TeamDividePrice', ['order_sn' => $orderInfo['order_sn'], 'searNumber' => $data['searNumber'] ?? 1], config('system.queueAbbr') . 'TeamOrderDivide');
                }
            }

            return $this->recordError($log, ['msg' => '不存在可以计算分润的正常状态的商品']);
        }

        foreach ($orderGoods as $key => $value) {
            if (!empty($value['vdc'])) {
                foreach ($value['vdc'] as $vdcKey => $vdcValue) {
                    $vdcValue['vdc_1'] = $vdcValue['vdc_one'];
                    $vdcValue['vdc_2'] = $vdcValue['vdc_two'];
                    $orderGoods[$key]['vdc_rule'][$vdcValue['level']] = $vdcValue;
                }
                unset($orderGoods[$key]['vdc']);
            }
        }

        //普通用户上级也有分润
        $linkNormalUser = User::where(['uid' => $orderInfo['link_superior_user'], 'status' => 1])->field('uid,phone,name,vip_level')->findOrEmpty()->toArray();
        if (!empty($linkNormalUser) && $linkNormalUser['vip_level'] <= 0) {
            $normalRewardRes = $this->sendDirectNormalUserDivide(['orderInfo' => $orderInfo, 'linkUid' => $orderInfo['link_superior_user'], 'orderGoods' => $orderGoods, 'searType' => $searType]);
            $log['linkNormalUserRewardRes'] = $normalRewardRes;
        }

        $joinDivideUser = [];
        $linkUserCanNotDivide = true;
        //查找订单用户的上级用户信息
        $linkUser = Member::where(['uid' => $orderInfo['link_superior_user'] ?? []])->withoutField('id,create_time,update_time')->findOrEmpty()->toArray();

        if (empty($orderInfo['link_superior_user']) || empty($linkUser)) {
            //如果此单刚好是拼团最后一单,但是因为没有上级中断分润,会导致后续无法发放成长值,故在此发放成长值
            if ($orderInfo['order_type'] == 2) {
                //处理拼团分润后的成长值和分润
                $this->ptOrderUserUpgrade(['order_sn' => $orderSn]);
            } else {
                //发放订单对应的成长值
                (new GrowthValue())->buildOrderGrowthValue($orderSn);
                //立马判断是否可以升级
                $upgradeQueue = Queue::push('app\lib\job\MemberUpgrade', ['uid' => $orderInfo['uid']], config('system.queueAbbr') . 'MemberUpgrade');
                //发放团队业绩奖励和销售额累计
                if (in_array($orderInfo['order_type'], [1, 3])) {
                    $divideQueue = Queue::push('app\lib\job\TeamDividePrice', ['order_sn' => $orderInfo['order_sn'], 'searNumber' => $data['searNumber'] ?? 1], config('system.queueAbbr') . 'TeamOrderDivide');
                }
            }

            return $this->recordError($log, ['msg' => '该用户无上级,不需要计算分润']);
        }
        //除了团长大礼包和转售订单其他订单允许发放商品感恩奖
        if (!in_array($orderInfo['order_type'], [3, 5])) {
            $directRewardRes = $this->sendDirectUserDivide(['orderInfo' => $orderInfo, 'linkUid' => $orderInfo['link_superior_user'], 'orderGoods' => $orderGoods, 'searType' => $searType]);
            $log['linkDirectRewardRes'] = $directRewardRes;
        }
        $whereAndSql = '';
        $vipDivide = false;
        $userMember = [];

        //团长大礼包发放感恩奖
        if ($orderInfo['order_type'] == 3) {
            $GratefulRes = $this->sendGrateful(['orderInfo' => $orderInfo, 'linkUser' => $linkUser, 'orderGoods' => $orderGoods, 'searType' => $searType]);
            $log['GratefulRes'] = $GratefulRes;
        }

        if (!empty($orderInfo['vip_level'])) {
            //如果购买用户为会员,则筛选比该会员档次高的上级,不找同级的人分润<暂时注释>
//            $whereAndSql = ' and tdd.level <= '.intval($orderInfo['vip_level']);
//            if (empty($orderInfo['child_team_code'])) {
//                return $this->recordError($log, ['msg' => '该用户为会员,但无团队子编码,请查错!']);
//            }
//            $linkUserChildTeamCode = $orderInfo['child_team_code'];

            /*以下为购买用户为会员,同级及以上等级享受分润模块--start*/
            $userMember = Member::where(['uid' => $orderInfo['uid'], 'status' => [1, 2]])->withoutField('id,create_time,update_time')->findOrEmpty()->toArray();

            //会员购买同级也享受价差比例分润
            if ($linkUser['level'] == $userMember['level']) {
                $userMember['divide_type'] = 1;
                $userMember['vdc_level'] = 0;
                $joinDivideUser[$userMember['level']][] = $userMember;
                $linkUserCanNotDivide = false;
                $vipDivide = true;

            } elseif ($linkUser['level'] > $userMember['level']) {

                $topLevelUser = $this->getTopLevelLinkUser($linkUser['uid'], $userMember['level']);
                $linkUser = $topLevelUser;
                $userMember['divide_type'] = 1;
                $userMember['vdc_level'] = 0;
                $joinDivideUser[$userMember['level']][] = $userMember;
                $linkUserCanNotDivide = false;
                $vipDivide = true;
            }


            /*以下为购买用户为会员,同级及以上等级享受分润模块--end*/
        } else {
            if ($linkUser['status'] == 1 && !empty($linkUser['level']) && (intval($linkUser['level']) > $orderInfo['vip_level'] ?? 0)) {
                //divide_type=1则为价差分润 =2则为比例分润
                $linkUser['divide_type'] = 1;
                $linkUser['vdc_level'] = 0;
                $joinDivideUser[$linkUser['level']][] = $linkUser;
                $linkUserCanNotDivide = false;
            }
        }

//        if (empty($linkUserChildTeamCode)) {
//            $linkUserChildTeamCode = $linkUser['child_team_code'];
//        }
        $linkUserTeamCode = $linkUser['team_code'];
        $linkUserUid = $linkUser['uid'];

//        //sql思路,利用变量赋值仿递归的方式先一个个找上级,然后再重新给查出来的数据集加上序号(还是用变量赋值的方式,这么做的原因是left join的时候会将原来数据集的顺序打乱),所以加上了序号然后再子查询按序号排序得到最终想要的结构
//        $linkUserParent = Db::query("SELECT tp.number,tdd.member_card,tdd.uid,tdd.user_phone,tdd.team_code,tdd.child_team_code,tdd.parent_team,tdd.level,tdd.type,tdd.status FROM (SELECT ( @i := @i + 1 ) number,ls.parentTeam FROM(SELECT( SELECT @linkTeam := parent_team FROM sp_member WHERE child_team_code COLLATE utf8mb4_unicode_ci = @linkTeam LIMIT 1) AS parentTeam FROM( SELECT @linkTeam := " . "'" . $linkUserChildTeamCode . "'" . " ) cs,sp_member td where td.status  = 1 and td.team_code = " . "'" . $linkUserTeamCode . "'" . ") ls,(SELECT @i := 0 ) AS t WHERE ls.parentTeam IS NOT NULL) AS tp LEFT JOIN sp_member tdd ON tdd.child_team_code = tp.parentTeam WHERE tp.parentTeam IS NOT NULL and tdd.status = 1 and level <> 0 " . $whereAndSql . " ORDER BY tp.number asc");

        $databaseVersion = str_replace('.', '', config('system.databaseVersion'));
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
            //如果此单刚好是拼团最后一单,但是因为没有上级中断分润,会导致后续无法发放成长值,故在此发放成长值
            if ($orderInfo['order_type'] == 2) {
                //处理拼团分润后的成长值和分润
                $this->ptOrderUserUpgrade(['order_sn' => $orderSn]);
            } else {
                //发放订单对应的成长值
                (new GrowthValue())->buildOrderGrowthValue($orderSn);
                //立马判断是否可以升级
                $upgradeQueue = Queue::push('app\lib\job\MemberUpgrade', ['uid' => $orderInfo['uid']], config('system.queueAbbr') . 'MemberUpgrade');
                //发放团队业绩奖励和销售额累计
                if (in_array($orderInfo['order_type'], [1, 3])) {
                    $divideQueue = Queue::push('app\lib\job\TeamDividePrice', ['order_sn' => $orderInfo['order_sn'], 'searNumber' => $data['searNumber'] ?? 1], config('system.queueAbbr') . 'TeamOrderDivide');
                }
            }
            return $this->recordError($log, ['msg' => '该用户查无可分润上级,请系统查错']);
        }
        $log['linkUserParent'] = $linkUserParent;
        if (!empty($linkUserParent)) {
            /*以下为购买用户为会员,同级及以上等级享受分润模块--start*/
            if (!empty($vipDivide)) {
                //把自己加入分润层级第一层,但是分润出来的金额为0,最后再剔除,这么做是为了保证结构跟普通购买的统一
                $vipMyself['member_card'] = $userMember['member_card'];
                $vipMyself['uid'] = $userMember['uid'];
                $vipMyself['user_phone'] = $userMember['user_phone'];
                $vipMyself['team_code'] = $userMember['team_code'];
                $vipMyself['child_team_code'] = $userMember['child_team_code'];
                $vipMyself['parent_team'] = $userMember['parent_team'];
                $vipMyself['level'] = $userMember['level'];
                $vipMyself['type'] = $userMember['type'];
                //$vipMyself['create_time'] = $userMember['create_time'];
                $vipMyself['status'] = $userMember['status'];
                $vipMyself['link_superior_user'] = $userMember['link_superior_user'] ?? null;
                array_unshift($linkUserParent, $vipMyself);
            }
            /*以下为购买用户为会员,同级及以上等级享受分润模块--end*/

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
        $memberVdcs = MemberVdc::where(['status' => 1])->withoutField('id,create_time,update_time')->select()->toArray();
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
            //如果此单刚好是拼团最后一单,但是因为没有上级中断分润,会导致后续无法发放成长值,故在此发放成长值
            if ($orderInfo['order_type'] == 2) {
                //处理拼团分润后的成长值和分润
                $this->ptOrderUserUpgrade(['order_sn' => $orderSn]);
            } else {
                //发放订单对应的成长值
                (new GrowthValue())->buildOrderGrowthValue($orderSn);
                //立马判断是否可以升级
                $upgradeQueue = Queue::push('app\lib\job\MemberUpgrade', ['uid' => $orderInfo['uid']], config('system.queueAbbr') . 'MemberUpgrade');
                //发放团队业绩奖励和销售额累计
                if (in_array($orderInfo['order_type'], [1, 3])) {
                    $divideQueue = Queue::push('app\lib\job\TeamDividePrice', ['order_sn' => $orderInfo['order_sn'], 'searNumber' => $data['searNumber'] ?? 1], config('system.queueAbbr') . 'TeamOrderDivide');
                }
            }

            return $this->recordError($log, ['msg' => '该用户的上级中没有一个人可以参与分润!']);
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
            $orderGoodsTotalPrice += $value['total_price'];
            //获取商品对应的分润规则
            $vdcRule = $value['vdc_rule'] ?? [];
            $allDividePrice = 0;

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
                        $levelValue['type'] = 1;
                        $levelValue['order_sn'] = $value['order_sn'];
                        $levelValue['goods_sn'] = $value['goods_sn'];
                        $levelValue['sku_sn'] = $value['sku_sn'];
                        $levelValue['price'] = $value['price'];
                        $levelValue['count'] = $value['count'];
                        $levelValue['total_price'] = $value['total_price'];
                        $levelValue['link_uid'] = $levelValue['uid'];
                        //如果实付价格低于成本价,直接卡断
                        if ((string)$value['total_price'] < (string)($nowLevelVdc['purchase_price'] * $value['count'])) {
                            if ($orderInfo['order_type'] == 2) {
                                //处理拼团分润后的成长值
                                $this->ptOrderUserUpgrade(['order_sn' => $orderSn]);
                            } else {
                                //发放订单对应的成长值
                                (new GrowthValue())->buildOrderGrowthValue($orderSn);
                                //立马判断是否可以升级
                                $upgradeQueue = Queue::push('app\lib\job\MemberUpgrade', ['uid' => $orderInfo['uid']], config('system.queueAbbr') . 'MemberUpgrade');
                                //发放团队业绩奖励和销售额累计
                                if (in_array($orderInfo['order_type'], [1, 3])) {
                                    $divideQueue = Queue::push('app\lib\job\TeamDividePrice', ['order_sn' => $orderInfo['order_sn'], 'searNumber' => $data['searNumber'] ?? 1], config('system.queueAbbr') . 'TeamOrderDivide');
                                }
                            }
                            return $this->recordError($log, ['msg' => '[严重错误] 分润风控拦截,订单金额低于上级成本价,整链分润取消!']);
                        }
                        $aDivide[$value['sku_sn']][$count] = $levelValue;
                        $aDivide[$value['sku_sn']][$count]['purchase_price'] = priceFormat($nowLevelVdc['purchase_price'] * $value['count']);
                        $aDivide[$value['sku_sn']][$count]['vdc_genre'] = $nowLevelVdc['vdc_genre'];
                        $aDivide[$value['sku_sn']][$count]['level'] = $dUKey;
                        $aDivide[$value['sku_sn']][$count]['dis_reduce_price'] = "0.00";
                        $aDivide[$value['sku_sn']][$count]['is_vip_divide'] = !empty($vipDivide) ? 1 : 2;
                        //如果分润类型为价差分润,则最高等级的的第一位直接获得销售额和成本价的价差,后面每个等级的则获得<--(上一个等级的成本价 - 当前等级的成本价)-(上个等级指定人员按比例分润的总额)--注意这里的等级越高则代表层级越低!>后续等级拓展到更多此处需要修改!!!!!
                        if ($levelValue['divide_type'] == 1) {
                            //判断是否需要取消该等级的第一人享受的价差分润
                            if (!empty($memberVdc[$levelValue['level']]) && $memberVdc[$levelValue['level']]['close_first_divide'] == 1) {
                                $aDivide[$value['sku_sn']][$count]['divide_price'] = 0;
                            } else {
                                if ($levelValue['level'] == $maxLevel) {
                                    $aDivide[$value['sku_sn']][$count]['divide_price'] = priceFormat($value['total_price'] - priceFormat($nowLevelVdc['purchase_price'] * $value['count']));
                                } else {
                                    if ($levelValue['level'] >= $minLevel) {
                                        //判断下一级(即level更高)的分润是否全部为空,如果是,则判断当前用户等级是否为最高等级,如果为最高,则直接获得销售额和成本价的价差,如果否,则用下一级用户的成本减去当前用户等级的成本价获得两级之间的价差之后再<加上下一级等级中价差抽成的那个人的金额--这个暂时先去掉,有bug,后续等级拓展到更多此处需要修改!!!!!>;如果下一级的分润不是全部为空,则用下一级用户的成本减去当前用户等级的成本价获得两级之间的价差之后再减去下一等级中所有参与比例抽成的人的分润总金额
                                        if (empty($aDivideUser[$dUKey + 1])) {
                                            if ($dUKey + 1 == $maxLevel) {
                                                $aDivide[$value['sku_sn']][$count]['divide_price'] = priceFormat($value['total_price'] - priceFormat($nowLevelVdc['purchase_price'] * $value['count']));
                                            } else {
                                                //$aDivide[$value['sku_sn']][$count]['divide_price'] = priceFormat((($vdcRule[$dUKey+1]['purchase_price'] * $value['count']) - ($nowLevelVdc['purchase_price'] * $value['count'])) + $divideScaleTopUser[$value['sku_sn']][$dUKey+1]);
                                                $aDivide[$value['sku_sn']][$count]['divide_price'] = priceFormat((($vdcRule[$dUKey + 1]['purchase_price'] * $value['count']) - ($nowLevelVdc['purchase_price'] * $value['count'])));

                                                //查看下下级分润是否为空,规则同上,后续等级拓展到更多此处需要修改!!!!!
                                                if (isset($aDivideUser[$dUKey + 2])) {
                                                    if (!empty($aDivideUser[$dUKey + 2])) {
                                                        $aDivide[$value['sku_sn']][$count]['divide_price'] += priceFormat((($vdcRule[$dUKey + 2]['purchase_price'] * $value['count']) - ($vdcRule[$dUKey + 1]['purchase_price'] * $value['count'])) - ($divideScaleUser[$value['sku_sn']][$levelValue['level'] + 2] ?? 0));
                                                    } else {
                                                        $aDivide[$value['sku_sn']][$count]['divide_price'] += priceFormat((($vdcRule[$dUKey + 2]['purchase_price'] * $value['count']) - ($vdcRule[$dUKey + 1]['purchase_price'] * $value['count'])));
                                                    }
                                                }
                                            }

                                        } else {
                                            $aDivide[$value['sku_sn']][$count]['divide_price'] = priceFormat((($vdcRule[$dUKey + 1]['purchase_price'] * $value['count']) - ($nowLevelVdc['purchase_price'] * $value['count'])) - ($divideScaleUser[$value['sku_sn']][$levelValue['level'] + 1] ?? 0));
                                        }

                                    }
                                }
                            }

                            $aDivide[$value['sku_sn']][$count]['vdc'] = 0;
                        } else {
                            //如果分润类型为按比例分润,则按照每个等级对应的分润等级比例来计算分润,计算规则为<--(当前等级的成本价 - 下一个等级的成本价) * (当前等级和分润层级对应的分润比例)--注意这里的等级越高则代表层级越低!>
                            //这里需要区分抽成类型vdc_genre,=1为价差抽成,=2为销售额抽成,如果=2则换一个计算方式,换成计算规则为<--(当前等级的成本价 * 当前等级和分润层级对应的分润比例)-->
                            if ($nowLevelVdc['vdc_genre'] == 1) {
                                $aDivide[$value['sku_sn']][$count]['divide_price'] = priceFormat((($nowLevelVdc['purchase_price'] * $value['count']) - ($vdcRule[$dUKey - 1]['purchase_price'] * $value['count'])) * ($nowLevelVdc['vdc_' . $levelValue['vdc_level']] ?? 0));
                                //计算累加当前等级分润的人一共分到了多少金额
                                $divideScaleUser[$value['sku_sn']][$levelValue['level']] += $aDivide[$value['sku_sn']][$count]['divide_price'];
                                //计算累减上一个等级(即level更低)的人一共失去了多少钱(计算规则为两级的价差然后减去当前这个人分到的金额)
                                if (!isset($divideScaleTopUser[$value['sku_sn']][$levelValue['level'] - 1])) {
                                    $divideScaleTopUser[$value['sku_sn']][$levelValue['level'] - 1] = 0;
                                }
                                $divideScaleTopUser[$value['sku_sn']][$levelValue['level'] - 1] += ($aDivide[$value['sku_sn']][$count]['divide_price']);
                            } elseif ($nowLevelVdc['vdc_genre'] == 2) {

                                $aDivide[$value['sku_sn']][$count]['divide_price'] = priceFormat(($nowLevelVdc['purchase_price'] * $value['count']) * ($nowLevelVdc['vdc_' . $levelValue['vdc_level']] ?? 0));

                                //如果该商品实付金额低于成本价,则按照实际支付价格算
                                if ((string)($value['total_price'] - ($value['all_dis'] ?? 0)) < (string)($nowLevelVdc['purchase_price'] * $value['count'])) {
                                    //添加开发者备注
                                    $coderRemark['type'] = 1;
                                    $coderRemark['msg'] = '实付金额低于该等级成本价,原成本价purchase_price字段值修改为 (实付金额 - 邮费 ),即 ' . $aDivide[$value['sku_sn']][$count]['purchase_price'] ?? 0 . ' 修改为 ' . priceFormat($value['total_price'] - ($value['all_dis'] ?? 0));
                                    $coderRemark['column'] = 'purchase_price';
                                    $coderRemark['before_value'] = $aDivide[$value['sku_sn']][$count]['purchase_price'] ?? 0;
                                    $coderRemark['after_value'] = priceFormat($value['total_price'] - ($value['all_dis'] ?? 0));
                                    $aDivide[$value['sku_sn']][$count]['coder_remark'] = json_encode($coderRemark, 256);

                                    //修改成本价及分润
                                    $aDivide[$value['sku_sn']][$count]['purchase_price'] = priceFormat($value['total_price'] - ($value['all_dis'] ?? 0));
                                    $aDivide[$value['sku_sn']][$count]['divide_price'] = priceFormat(($value['total_price'] - ($value['all_dis'] ?? 0)) * ($nowLevelVdc['vdc_' . $levelValue['vdc_level']] ?? 0));
                                }

                            }
                        }
                        $aDivide[$value['sku_sn']][$count]['vdc'] = ($nowLevelVdc['vdc_' . $levelValue['vdc_level']] ?? 0);
                        //$allDividePrice +=  $aDivide[$value['sku_sn']][$count]['divide_price'];

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
            if (!empty($aDivide) && !empty($vipDivide)) {
                foreach ($aDivide as $skuKey => $skuValue) {
                    foreach ($skuValue as $dKey => $dValue) {
                        if ($dValue['uid'] == $userMember['uid']) {
                            if (empty(doubleval($dValue['divide_price'])) || ((string)$dValue['divide_price'] < 0)) {
                                unset($aDivide[$skuKey][$dKey]);
                                continue;
                            } else {
                                //如果此单刚好是拼团最后一单,但是因为错误中断分润,会导致后续无法发放成长值,故在此发放成长值
                                if ($orderInfo['order_type'] == 2) {
                                    //处理拼团分润后的成长值
                                    $this->ptOrderUserUpgrade(['order_sn' => $orderSn]);
                                } else {
                                    //发放订单对应的成长值
                                    (new GrowthValue())->buildOrderGrowthValue($orderSn);
                                    //立马判断是否可以升级
                                    $upgradeQueue = Queue::push('app\lib\job\MemberUpgrade', ['uid' => $orderInfo['uid']], config('system.queueAbbr') . 'MemberUpgrade');
                                    //发放团队业绩奖励和销售额累计
                                    if (in_array($orderInfo['order_type'], [1, 3])) {
                                        $divideQueue = Queue::push('app\lib\job\TeamDividePrice', ['order_sn' => $orderInfo['order_sn'], 'searNumber' => $data['searNumber'] ?? 1], config('system.queueAbbr') . 'TeamOrderDivide');
                                    }
                                }

                                return $this->recordError($log, ['msg' => '[严重错误] 会员分润首会员(即自己)的分润价格不为0,请纠正!']);
                            }

                        }
                    }
                }
            }
            /*以下为购买用户为会员,同级及以上等级享受分润模块--end*/

            if (!empty($aDivide)) {
                //如果有优惠,则需要扣除优惠,dis_reduce_type来判断计算方式
                //现在默认是优惠券使用不影响分润金额,分润还是按照订单原金额分润
                switch ($value['dis_reduce_type'] ?? 3) {
                    //仅扣除最低级(level最高)的享受价差抽成的那个人的利润
                    case 1:
                        break;
                    //全部参与分润的人按比例平摊
                    case 2:
                        $aDivide = $this->reduceDivideUserPrice($value, $aDivide, $skuDividePrice);
                        break;
                    //其他方式(如公司出这部分利润,则保持原有分润不变)
                    default:
                        break;
                }

            }

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
                if ($orderInfo['order_type'] == 2) {
                    //处理拼团分润后的成长值
                    $this->ptOrderUserUpgrade(['order_sn' => $orderSn]);
                } else {
                    //发放订单对应的成长值
                    (new GrowthValue())->buildOrderGrowthValue($orderSn);
                    //立马判断是否可以升级
                    $upgradeQueue = Queue::push('app\lib\job\MemberUpgrade', ['uid' => $orderInfo['uid']], config('system.queueAbbr') . 'MemberUpgrade');
                    //发放团队业绩奖励和销售额累计
                    if (in_array($orderInfo['order_type'], [1, 3])) {
                        $divideQueue = Queue::push('app\lib\job\TeamDividePrice', ['order_sn' => $orderInfo['order_sn'], 'searNumber' => $data['searNumber'] ?? 1], config('system.queueAbbr') . 'TeamOrderDivide');
                    }
                }
                //反错
                $log['errorDivide'] = $errorDivide;
                return $this->recordError($log, ['msg' => '[严重错误] 分润风控拦截,分润链条中出现负数分润,整链分润取消!']);
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

            if ($searType == 1) {
                $dbRes = Db::transaction(function () use ($aDivides, $orderInfo, $orderGoods, $linkUserParent, $orderGoodsTotalPrice) {
//                    if(!empty($model)){
//                        $res = (new DivideRestore())->saveAll($aDivides);
//                    }else{
                    //记录分润明细
                    $res = (new DivideModel())->saveAll($aDivides);
//                    }

                    /***原先的逻辑为确认收货再计算分润明细和支付余额,现在改为下单就计算分润然后存入分润表中,但是为冻结状态,等确认收货了再重新支付分润金额和修改状态***<下面这部分代码先注释,不可删除!>***/

//                    $goodsTitle = array_column($orderGoods,'title');
//                    //查找用户资料,修改用户余额,添加余额明细,发送模板消息通知等
//                    $allUid = array_unique(array_column($aDivides,'uid'));
//                    $allUser = User::where(['uid'=>$allUid,'status'=>1])->field('uid,avaliable_balance,total_balance,integral')->select()->toArray();
//                    $balanceDetail = (new BalanceDetail());
//                    foreach ($aDivides as $dsKey => $dsValue) {
//                        foreach ($allUser as $key => $value) {
//                            if($value['uid'] == $dsValue['uid']){
//                                //修改余额
//                                $save['avaliable_balance'] = priceFormat($value['avaliable_balance'] + $dsValue['real_divide_price']);
//                                $save['total_balance'] = priceFormat($value['total_balance'] + $dsValue['real_divide_price']);
//                                User::update($save,['uid'=>$value['uid'],'status'=>1]);
//                                $allUser[$key]['avaliable_balance'] = $save['avaliable_balance'];
//                                $allUser[$key]['total_balance'] = $save['total_balance'];
//                                //增加余额明细
//                                $detail['order_sn']= $orderInfo['order_sn'];
//                                $detail['belong']= $orderInfo['order_belong'];
//                                $detail['uid']= $value['uid'];
//                                $detail['type']= 1;
//                                $detail['price']= priceFormat($dsValue['real_divide_price']);
//                                $detail['change_type']= 1;
//                                $balanceDetail->new($detail);
//
//                                //推送模版消息通知
//                                $template['uid'] = $value['uid'];
//                                $template['type'] = 'divide';
//                                $tile = $goodsTitle[0];
//                                if(count($goodsTitle) > 1){
//                                    $tile .= '等';
//                                }
//                                $template['template'] = ['first'=>'有新的订单分润啦~',$orderInfo['user_name'],date('Y-m-d H:i:s',$orderInfo['create_time']),priceFormat($dsValue['real_divide_price']),$tile];
//                                //Queue::push('app\lib\job\Template',$template,'tcmTemplateList');
//                            }
//                        }
//                    }
                    return $res;
                });
            } else {
                $dbRes = $aDivides;
            }
        }
        $log['res'] = $dbRes;
        $log['msg'] = '订单' . $orderSn . '的分润已 [ ' . $searTypeName . '成功 ]';
        $this->log($log, 'info');
        switch ($orderInfo['order_type'] ?? 1) {
            case 1:
                //发放订单对应的成长值
                (new GrowthValue())->buildOrderGrowthValue($orderSn);
                //立马判断是否可以升级
                $upgradeQueue = Queue::push('app\lib\job\MemberUpgrade', ['uid' => $orderInfo['uid']], config('system.queueAbbr') . 'MemberUpgrade');
                //发放团队业绩奖励和销售额累计
                if (in_array($orderInfo['order_type'], [1, 3])) {
                    $divideQueue = Queue::push('app\lib\job\TeamDividePrice', ['order_sn' => $orderInfo['order_sn'], 'searNumber' => $data['searNumber'] ?? 1], config('system.queueAbbr') . 'TeamOrderDivide');
                }
                break;
            case 2:
                //处理拼团分润后的成长值和分润
                $this->ptOrderUserUpgrade(['order_sn' => $orderSn]);
                break;
            case 3:
                //发放订单对应的成长值
                (new GrowthValue())->buildOrderGrowthValue($orderSn);
                //立马判断是否可以升级
                $upgradeQueue = Queue::push('app\lib\job\MemberUpgrade', ['uid' => $orderInfo['uid']], config('system.queueAbbr') . 'MemberUpgrade');
                //发放团队业绩奖励和销售额累计
                if (in_array($orderInfo['order_type'], [1, 3])) {
                    $divideQueue = Queue::push('app\lib\job\TeamDividePrice', ['order_sn' => $orderInfo['order_sn'], 'searNumber' => $data['searNumber'] ?? 1], config('system.queueAbbr') . 'TeamOrderDivide');
                }
//            //立马成为团长
//            (new \app\lib\services\Member())->becomeMember($orderInfo);
                break;
        }

        if ($searType == 1) {
            //发放对应所有上级的销售额
            $recordTeamPerformance = $this->getTopUserRecordTeamPerformance($orderInfo);
        }


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

        //默认获取每个等级允许指定人数的分润,不允许超过3
        $aLevelMaxNumber = [3 => 3, 2 => 3, 1 => 3];
        $nowLevel = !empty($nowLevel) ? $nowLevel : (!empty($linkUser['level']) ? $linkUser['level'] : 3);
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
//                dump($value['level']);
//                dump($nowLevel);
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
            $aTotal = (new Member())->where($newMap)->count();
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
                    $dirUser = Member::where(['link_superior_user' => $uid, 'status' => [1, 2]])->column('uid');
                    if (!empty($dirUser)) {
                        $nextUser = Member::where(['link_superior_user' => $dirUser, 'status' => [1, 2]])->column('uid');
                        if (!empty($nextUser)) {
                            $newMap[] = ['uid', 'in', $nextUser];
                        }
                    }
                    break;
            }
        }

        $listSql = (new Member())->field('uid,user_phone,child_team_code,parent_team,level,type,link_superior_user,status,create_time,upgrade_time,team_chain')->where($newMap)->when(!empty($orderSql), function ($query) use ($orderSql) {
            $query->order($orderSql);
        })->buildSql();
        $list = Db::query($listSql);

        if (!empty($list)) {
            if (!empty($otherMap['searType'] ?? null)) {
                if ($otherMap['searType'] == 1) {
                    $uMap[] = ['link_superior_user', '=', $uid];
                    $uMap[] = ['status', '=', 1];
                    $uMap[] = ['level', '<=', $otherMap['maxLevel']];
                    $dirUid = Member::where($uMap)->column('uid');
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

//        $mySelfSql = '';
//        $otherMapSql = '';
//        if ($mySelfNotExist) {
//            if (strlen($uid) <= 10) {
//                $mySelfSql = "AND u2.uid != " . "'" . $uid . "'";
//            } else {
//                $mySelfSql = "AND u2.uid not in " . "'" . $uid . "'";
//            }
//
//        }
//        if (!empty($otherMap)) {
//            $beginId = $otherMap['beginId'];
//            $endId = $otherMap['endId'];
//            $otherMapSql = ' AND (id < ' . $beginId . ' OR id >' . $endId . ')';
//        }
//
//        //mysql8.0.16版本以前使用这条sql
//        //sql思路 传入父类uid,找到子类后拼接起来形成一个新的父类@ids,然后再用这个新的父类@ids作为父类uids,去找所属下级子类,依次递归一直往下找直到新的父类@ids为null为止
//
//        $databaseVersion = str_replace('.', '', config('system.databaseVersion'));
//        if (!empty($databaseVersion) && is_numeric($databaseVersion) && $databaseVersion > 8016) {
//            //mysql8.0.16版本之后使用这条sql
//            $list = Db::query("SELECT u2.uid,u2.user_phone,u2.child_team_code,u2.parent_team,u2.level,u2.type,u2.link_superior_user,u2.status,u1.team_level,u2.create_time,u2.upgrade_time FROM( SELECT @ids AS p_ids, (SELECT @ids := GROUP_CONCAT(uid) FROM sp_member , (SELECT @ids := " . "'" . $uid . "'" . ", @l := 0 COLLATE utf8mb4_unicode_ci ) b WHERE FIND_IN_SET(link_superior_user, @ids COLLATE utf8mb4_unicode_ci)".$otherMapSql.") AS c_ids, @l := @l+1 AS team_level FROM sp_member WHERE @ids IS NOT NULL".$otherMapSql.") u1 JOIN sp_member u2 ON FIND_IN_SET(u2.uid, u1.p_ids COLLATE utf8mb4_unicode_ci)".$otherMapSql."" . $mySelfSql . " order by u1.team_level ASC " . $newOrderSql);
//        } else {
//            $list = Db::query("SELECT u2.uid,u2.user_phone,u2.child_team_code,u2.parent_team,u2.level,u2.type,u2.link_superior_user,u2.status,u1.team_level,u2.create_time,u2.upgrade_time FROM( SELECT @ids AS p_ids, (SELECT @ids := GROUP_CONCAT(uid) FROM sp_member WHERE FIND_IN_SET(link_superior_user, @ids COLLATE utf8mb4_unicode_ci)".$otherMapSql.") AS c_ids, @l := @l+1 AS team_level FROM sp_member, (SELECT @ids := " . "'" . $uid . "'" . ", @l := 0 ) b WHERE @ids IS NOT NULL ".$otherMapSql.") u1 JOIN sp_member u2 ON FIND_IN_SET(u2.uid, u1.p_ids COLLATE utf8mb4_unicode_ci) ".$otherMapSql."" . $mySelfSql . " order by u1.team_level ASC " . $newOrderSql);
//        }
//
//        if (!empty($list)) {
//            if ($checkStatus) {
//                foreach ($list as $key => $value) {
//                    if (!in_array($value['status'], [1])) {
//                        unset($list[$key]);
//                    }
//                }
//            }
//        }

//        return $list;
    }

    /**
     * @title  获取下级直推用户列表,筛选出正常状态的用户,并根据等级分组归类
     * @param string $uid 用户的uid
     * @return array
     * @throws \Exception
     */
    public function getNextDirectLinkUserGroupByLevel(string $uid, int $level = 0)
    {
        $map[] = ['status', '=', 1];
        $map[] = ['link_superior_user', '=', $uid];
        if (!empty($level)) {
            $map[] = ['level', '=', $level];
        }
        $directUser = Member::with(['user'])->where($map)->withoutField('id,update_time')->order('create_time desc')->select()->toArray();
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
            }
        }
        krsort($userLevel);
        $all['allUser']['count'] = count($teamUser);
        $all['allUser']['list'] = $teamUser;
        $all['allUser']['onlyUidList'] = array_unique(array_column($teamUser, 'uid'));
        $all['userLevel'] = $userLevel;
        $all['totalCount'] = $aTotal ?? 0;
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
        $cacheKey = 'DivideOrderCache-' . $orderSn;
        $cacheExpire = 30;
        if (!empty(cache($cacheKey))) {
            if (empty($notThrowError)) {
                throw new OrderException(['msg' => '前方网络拥堵,请稍后重试']);
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
                throw new OrderException(['msg' => '只有已发货且无退售后的未完结订单才可以确认收货哟']);
            } else {
                return false;
            }
        }

        $aDivides = [];
        $dbRes = Db::transaction(function () use ($orderSn, $aDivides, $orderInfo, $orderGoods) {
            //仅支付冻结中的分润列表
            $map[] = ['order_sn', '=', $orderSn];
            $map[] = ['arrival_status', '=', 2];
            $map[] = ['type', '<>', 9];
            $aDivides = DivideModel::where($map)->lock(true)->select()->toArray();

            //修改订单状态为已成功
            $orderSave['order_status'] = 8;
            $orderSave['end_time'] = time();
            $res = Order::update($orderSave, ['order_sn' => $orderInfo['order_sn'], 'order_status' => 3]);
            $shipRes = ShipOrder::update($orderSave, ['order_sn' => $orderInfo['order_sn'], 'order_status' => 3, 'status' => 1]);
            //同步拆单子订单的状态
            $shipChildRes = ShipOrder::update($orderSave, ['parent_order_sn' => $orderInfo['order_sn'], 'order_status' => 3, 'status' => 1, 'split_status' => [1, 3]]);
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

                            //区分销售利润(直推)和教育基金(间推),销售利润直接发放,教育基金需要判断要直接发放还是冻结后周期性发放
                            //间推的分润情况----已经做好周期结算,暂时先注释
//                            if($dsValue['divide_type'] == 2){
//                                //间推分润需要周期性发放的时候修改状态为已确认但冻结,等待周期发放
//                                if(!empty($memberIncentives[$dsValue['level']]) && $memberIncentives[$dsValue['level']] == 1){
//                                    $divideStatus['arrival_status'] = 4;
//                                    DivideModel::update($divideStatus, ['id' => $dsValue['id']]);
//                                    continue 2;
//                                }
//                            }
                            //修改冻结状态为已支付
                            $divideStatus['arrival_status'] = 1;
                            $divideStatus['arrival_time'] = time();
                            $dRes = DivideModel::update($divideStatus, ['id' => $dsValue['id']]);
                            $divideRes[] = $dRes->getData();

                            //修改余额
//                            $save['avaliable_balance'] = priceFormat($value['avaliable_balance'] + $dsValue['real_divide_price']);
//                            $save['total_balance'] = priceFormat($value['total_balance'] + $dsValue['real_divide_price']);
//                            $uRes = User::update($save, ['uid' => $value['uid'], 'status' => 1]);
                            switch ($dsValue['type'] ?? 1) {
                                case 1:
                                case 3:
                                case 4:
                                    $uRes = User::where(['uid' => $value['uid'], 'status' => 1])->inc('divide_balance', $dsValue['real_divide_price'])->update();
                                    $userRes[] = ['res' => $uRes, 'uid' => $value['uid']];

                                //增加余额明细
                                $detail['order_sn'] = $orderInfo['order_sn'];
                                $detail['belong'] = $orderInfo['order_belong'] ?? 1;
                                $detail['uid'] = $value['uid'];
                                $detail['type'] = 1;
                                $detail['price'] = priceFormat($dsValue['real_divide_price']);
                                //type 3为团长大礼包感恩奖 1为普通分润
                                $detail['change_type'] = $dsValue['type'] == 3 ? 13 : 1;
                                if ($dsValue['type'] == 4) {
                                    $detail['remark'] = '下级转售订单直推奖励';
                                }
                                $bRes = $balanceDetail->new($detail);

                                $balanceRes[] = $bRes;
                                    break;
                                case 5:
                                    $uRes = User::where(['uid' => $value['uid'], 'status' => 1])->inc('team_balance', $dsValue['real_divide_price'])->update();
                                    $userRes[] = ['res' => $uRes, 'uid' => $value['uid']];

                                    //增加余额明细
                                    $detail['order_sn'] = $orderInfo['order_sn'];
                                    $detail['belong'] = $orderInfo['order_belong'] ?? 1;
                                    $detail['uid'] = $value['uid'];
                                    $detail['type'] = 1;
                                    $detail['price'] = priceFormat($dsValue['real_divide_price']);
                                    //type 5为团队业绩奖励
                                    $detail['change_type'] = $dsValue['type'] == 5 ? 16 : 1;
                                    $bRes = $balanceDetail->new($detail);

                                    $balanceRes[] = $bRes;
                                    break;
                                case 7:
                                    $uRes = User::where(['uid' => $value['uid'], 'status' => 1])->inc('area_balance', $dsValue['real_divide_price'])->update();
                                    $userRes[] = ['res' => $uRes, 'uid' => $value['uid']];

                                    //增加余额明细
                                    $detail['order_sn'] = $orderInfo['order_sn'];
                                    $detail['belong'] = $orderInfo['order_belong'] ?? 1;
                                    $detail['uid'] = $value['uid'];
                                    $detail['type'] = 1;
                                    $detail['price'] = priceFormat($dsValue['real_divide_price']);
                                    $detail['change_type'] = 20;
                                    $bRes = $balanceDetail->new($detail);

                                    $balanceRes[] = $bRes;
                                    break;
                                default:
                                    break;
                            }



//                            $allUser[$key]['avaliable_balance'] = $save['avaliable_balance'];
//                            $allUser[$key]['total_balance'] = $save['total_balance'];

                            //推送模版消息通知
                            $template['uid'] = $value['uid'];
                            $template['type'] = 'divide';
                            $tile = $goodsTitle[0];
                            if (count($goodsTitle) > 1) {
                                $tile .= '等';
                            }
//                            $template['template'] = ['first' => '有新的订单分润啦~', $orderInfo['user_name'], date('Y-m-d H:i:s', $orderInfo['create_time']), priceFormat($dsValue['real_divide_price']), $tile];
                            //Queue::push('app\lib\job\Template',$template,'tcmTemplateList');
                        }
                    }
                    /*<----此流程已改,但保留代码>*/
                    //查看会员是否升级
//                    $divideQueue = Queue::push('app\lib\job\MemberUpgrade',['uid'=>$dsValue['link_uid']],config('system.queueAbbr') . 'MemberUpgrade');

                }
            }

            $allRes['msg'] = '发放订单分润完成';
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
                                    if($dsValue['type'] == 5){
                                        //修改余额
                                        $save['team_balance'] = priceFormat($value['team_balance'] - $dsValue['real_divide_price']);
                                        $res = User::update($save, ['uid' => $value['uid'], 'status' => 1]);
                                    }elseif($dsValue['type'] == 7){
                                        $save['area_balance'] = priceFormat($value['area_balance'] - $dsValue['real_divide_price']);
                                        $res = User::update($save, ['uid' => $value['uid'], 'status' => 1]);
                                    }else{
                                        //修改余额
//                                        $save['avaliable_balance'] = priceFormat($value['avaliable_balance'] - $dsValue['real_divide_price']);
//                                        $save['total_balance'] = priceFormat($value['total_balance'] - $dsValue['real_divide_price']);
                                        $save['divide_balance'] = priceFormat($value['divide_balance'] - $dsValue['real_divide_price']);
                                        $res = User::update($save, ['uid' => $value['uid'], 'status' => 1]);
//                                        $allUser[$key]['avaliable_balance'] = $save['avaliable_balance'];
//                                        $allUser[$key]['total_balance'] = $save['total_balance'];
                                        $allUser[$key]['divide_balance'] = $save['divide_balance'];
                                    }

                                    //增加余额明细
                                    $detail['order_sn'] = $orderInfo['order_sn'];
                                    $detail['belong'] = $orderInfo['order_belong'] ?? 1;
                                    $detail['uid'] = $value['uid'];
                                    $detail['type'] = 2;
                                    $detail['price'] = '-' . priceFormat($dsValue['real_divide_price']);
                                    switch ($dsValue['type'] ?? 1) {
                                        case 1:
                                            $detail['change_type'] = 6;
                                            break;
                                        case 5:
                                            $detail['change_type'] = 17;
                                            break;
                                        case 7:
                                            $detail['change_type'] = 21;
                                            break;
                                        default:
                                            break;
                                    }
//                                    $detail['change_type'] = $dsValue['type'] == 5 ? 17 : 6;
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
        $member = Member::where(['uid' => $topUid, 'status' => 1])->withoutField('id,create_time,update_time')->findOrEmpty()->toArray();
        $user = $member;
        if (!empty($member ?? [])) {
            if (intval($member['level']) > $nowLevel && !empty($member['link_superior_user'] ?? null)) {
                $user = $this->getTopLevelLinkUser($member['link_superior_user'], $nowLevel);
            } else {
                $user = $member;
            }
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
        //是否需要包含自己,默认包含
        if (!empty($orderInfo['notFindMyself'])) {
            $needFindMyself = "AND u2.uid != " . "'" . $uid . "'" . "";
        } else {
            $needFindMyself = ' ';
        }

        //查找全部团队上级成员

        $databaseVersion = str_replace('.', '', config('system.databaseVersion'));
        if (!empty($databaseVersion) && is_numeric($databaseVersion) && $databaseVersion > 8016) {
            //mysql 8.0.16以上用这个
            $parent = Db::query("SELECT u2.member_card,u2.uid,u2.user_phone,u2.level,u2.type,u2.status,u2.link_superior_user,u1.LEVEL AS divide_level FROM(SELECT @id c_ids,(SELECT @id := GROUP_CONCAT( link_superior_user ) FROM sp_member ,(SELECT @id := " . "'" . $uid . "'" . ",@l := 0 ) b WHERE FIND_IN_SET( uid, @id COLLATE utf8mb4_unicode_ci )) p_ids,@l := @l + 1 AS LEVEL FROM sp_member WHERE @id IS NOT NULL ) u1 JOIN sp_member u2 ON u1.c_ids = u2.uid COLLATE utf8mb4_unicode_ci WHERE u2.status = 1 " . $needFindMyself . " ORDER BY u1.LEVEL ASC;");
        } else {
            //mysql 8.0.16及以下用这个
            $parent = Db::query("SELECT u2.member_card,u2.uid,u2.user_phone,u2.level,u2.type,u2.status,u2.link_superior_user,u1.LEVEL AS divide_level FROM(SELECT @id c_ids,(SELECT @id := GROUP_CONCAT( link_superior_user ) FROM sp_member WHERE FIND_IN_SET( uid, @id COLLATE utf8mb4_unicode_ci )) p_ids,@l := @l + 1 AS LEVEL FROM sp_member,(SELECT @id := " . "'" . $uid . "'" . ",@l := 0 ) b WHERE @id IS NOT NULL ) u1 JOIN sp_member u2 ON u1.c_ids = u2.uid COLLATE utf8mb4_unicode_ci WHERE u2.status = 1 " . $needFindMyself . " ORDER BY u1.LEVEL ASC;");
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

        $orderGoods = OrderGoods::with(['vdc'])->where($map)->withoutField('id,images,specs,desc,create_time,update_time')->select()->toArray();
        if (empty($orderGoods)) {
            return false;
        }
        $orderGoodsTotalPrice = 0;
        $teamSalesGoodsPrice = 0;

        foreach ($orderGoods as $key => $value) {
            if ($value['vdc_allow'] == 1) {
                if($type == 1){
                    $orderGoodsTotalPrice += (string)($value['total_price'] - ($value['refund_price'] ?? 0)) > 0 ? $value['total_price'] : 0;
                }else{
                    $orderGoodsTotalPrice += ($value['refund_price'] ?? 0);
                }

                //统计团队业绩需要对应累加或累减的销售额
                if (in_array($value['activity_sign'], (new TeamMemberService())->activitySign)) {
                    if($type == 1){
                        $teamSalesGoodsPrice += $orderGoodsTotalPrice;
                    }else{
                        $teamSalesGoodsPrice += ($value['refund_price'] ?? 0);
                    }
                }
            }
        }
        if (empty($orderGoodsTotalPrice) || $orderGoodsTotalPrice < 0) {
            return false;
        }
        //数据库操作
        $allUid = array_column($parent, 'uid');

        $userModel = (new User());
        $teamMemberModel = (new TeamMember());
        $DBRes = Db::transaction(function () use ($allUid, $userModel, $orderGoodsTotalPrice, $type,$teamMemberModel,$teamSalesGoodsPrice,$orderInfo,$skuSn) {
            foreach ($allUid as $key => $value) {
                if ($type == 1) {
                    //团队业绩增加的在另外的团队分润模块单独添加
                    $res = $userModel->where(['uid' => $value])->inc('team_performance', $orderGoodsTotalPrice)->update();
                } elseif ($type == 2) {
                    $res = $userModel->where(['uid' => $value])->dec('team_performance', $orderGoodsTotalPrice)->update();
                }
            }
            if ($type == 2) {
                //如果是业绩删减需要对团队业绩的销售额也做删减
                (new TeamDivide())->getTopUserRecordTeamPerformance($orderInfo, $type, $skuSn);
            }
            return $res;
        });

        return true;
    }

    /**
     * @title  分润结束后对全部拼团订单进行发放成长值和升级
     * @param array $data
     * @return mixed
     * @throws \Exception
     */
    public function ptOrderUserUpgrade(array $data)
    {
        $orderSn = $data['order_sn'] ?? null;
        if (empty($orderSn)) {
            return false;
        }
        $growValueService = (new GrowthValue());
        $ptActivity = PtOrder::where(['order_sn' => $orderSn, 'pay_status' => 2, 'activity_status' => 2])->field('uid,activity_sn,activity_code,activity_type,activity_title')->findOrEmpty()->toArray();
        if (!empty($ptActivity)) {
            $ptActivitySn = $ptActivity['activity_sn'];
            switch ($ptActivity['activity_type']) {
                case 1:
                case 2:
                    //如果是普通拼团或者团长优惠拼团,则判断该团最后一个订单是否已经完成了分润,如果已经完成分润则表示全部都完成了,该团所有订单都进入发放成长值和判断升级
                    $allPtOrder = PtOrder::where(['activity_sn' => $ptActivitySn, 'pay_status' => 2, 'activity_status' => 2])->order('create_time desc')->select()->toArray();
                    if (!empty($allPtOrder)) {
                        $lastOrderSn = current(array_unique(array_filter(array_column($allPtOrder, 'order_sn'))));
                        //如果当前订单是最后一个订单,则表示全部都进行了分润
                        if ($lastOrderSn == $orderSn) {
                            foreach ($allPtOrder as $key => $value) {
                                //发放订单对应的成长值
                                $growValueService->buildOrderGrowthValue($value['order_sn']);
                                //立马判断是否可以升级
                                $ptUpgradeQueue = Queue::push('app\lib\job\MemberUpgrade', ['uid' => $value['uid']], config('system.queueAbbr') . 'MemberUpgrade');
                            }
                        }

                    }
                    break;
                case 3:
                    //团长大礼包拼团需要慢慢参与分润,然后按照顺序依次升级和发放成长值
                    //发放订单对应的成长值
                    $growValueService->buildOrderGrowthValue($orderSn);
                    //立马判断是否可以升级
                    $ptUpgradeQueue = Queue::push('app\lib\job\MemberUpgrade', ['uid' => $ptActivity['uid']], config('system.queueAbbr') . 'MemberUpgrade');
                    break;
                default:
                    $res = true;
            }
        }
        return true;
    }

    /**
     * @title  发放感恩奖
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
        $returnData = ['res' => false, 'errorMsg' => '发放感恩奖出错', 'param' => $data];
        $handselRes = false;
        if (empty($orderInfo) || $orderInfo['order_type'] != 3) {
            return ['res' => false, 'errorMsg' => '不符合的订单类型~', 'param' => $data];
        }
        $topLinkUser = Member::where(['uid' => $linkUser['link_superior_user'], 'status' => 1])->field('uid,link_superior_user,level')->findOrEmpty()->toArray();
        $gratefulRule = MemberVdc::where(['status' => 1])->order('level desc')->field('grateful_vdc_one,grateful_vdc_two,level')->select()->toArray();
        if (empty($gratefulRule)) {
            return ['res' => false, 'errorMsg' => '不存在有效的感恩奖规则', 'param' => $data];
        }
        foreach ($gratefulRule as $key => $value) {
            $gratefulRuleInfo[$value['level']] = $value;
        }
        $existDivide = DivideModel::where(['type' => 3, 'order_sn' => $orderInfo['order_sn'], 'status' => 1])->count();
        if (!empty($existDivide)) {
            return ['res' => false, 'errorMsg' => '该订单已存在感恩奖,不允许重复发放', 'param' => $data, 'existDivide' => $existDivide];
        }
        if (!empty($linkUser)) {
            $userList[1] = $linkUser;
        }
        if (!empty($topLinkUser)) {
            $userList[2] = $topLinkUser;
        }
        if (empty($userList) || (count($userList) == 2 && empty($userList[1] ?? []))) {
            return ['res' => false, 'errorMsg' => '无符合要求的上级或查无有效直推用户', 'userList' => $userList ?? [], 'param' => $data];
        }

        foreach ($userList as $uKey => $uValue) {
            foreach ($orderGoods as $key => $value) {
                $divides[$uKey]['order_sn'] = $orderInfo['order_sn'];
                $divides[$uKey]['order_uid'] = $orderInfo['uid'];
                $divides[$uKey]['belong'] = 1;
                $divides[$uKey]['type'] = 3;
                $divides[$uKey]['is_grateful'] = 1;
                $divides[$uKey]['goods_sn'] = $value['goods_sn'];
                $divides[$uKey]['sku_sn'] = $value['sku_sn'];
                $divides[$uKey]['price'] = $value['price'];
                $divides[$uKey]['count'] = $value['count'];
                $divides[$uKey]['total_price'] = $uKey == 1 ? $value['real_pay_price'] : $divides[$uKey - 1]['real_divide_price'];
                $divides[$uKey]['vdc'] = $uKey == 1 ? $gratefulRuleInfo[$uValue['level']]['grateful_vdc_one'] ?? 0 : $gratefulRuleInfo[$uValue['level']]['grateful_vdc_two'] ?? 0;
                $divides[$uKey]['vdc_genre'] = 1;
                $divides[$uKey]['divide_type'] = $uKey == 1 ? 1 : 2;
                if (!empty($value['vdc_rule'][$orderInfo['user_level']] ?? [])) {
                    $divides[$uKey]['purchase_price'] = $value['vdc_rule'][$orderInfo['user_level']]['purchase_price'] ?? $value['total_price'];
                } else {
                    $divides[$uKey]['purchase_price'] = $value['total_price'];
                }

                $divides[$uKey]['level'] = 1;
                $divides[$uKey]['link_uid'] = $uValue['uid'];
                //一级是按照实付金额百分比奖励 二级按照上一级实际奖励的百分比
                if ($uKey == 1) {
                    $divides[$uKey]['divide_price'] = priceFormat($divides[$uKey]['total_price'] * $divides[$uKey]['vdc']);
                } elseif ($uKey == 2) {
                    $divides[$uKey]['divide_price'] = priceFormat($divides[$uKey - 1]['real_divide_price'] * $divides[$uKey]['vdc']);
                }

                $divides[$uKey]['real_divide_price'] = $divides[$uKey]['divide_price'];
                $divides[$uKey]['arrival_status'] = 2;
                $divides[$uKey]['remark'] = $uKey == 1 ? '套餐销售奖' : '套餐感恩奖';
            }
        }
        if (!empty($divides)) {
            if ($searType == 1) {
                (new DivideModel())->saveAll(array_values($divides));
                $returnData = ['res' => true, 'errorMsg' => '成功生成冻结中的感恩奖', 'data' => $divides, 'param' => $data, 'userList' => $userList, 'handselRes' => $handselRes ?? []];
            } else {
                $returnData = ['res' => true, 'errorMsg' => '仅查询预冻结中的感恩奖', 'data' => $divides, 'param' => $data, 'userList' => $userList, 'handselRes' => $handselRes ?? []];
            }
        }

        return $returnData;
    }

    /**
     * @title  上级用户赠送套餐
     * @param array $data
     * @return array
     * @throws \Exception
     */
    public function topUserHandsel(array $data)
    {
        $topLinkUser = $data['topLinkUser'] ?? null;
        $orderInfo = $data['orderInfo'];
        $orderGoods = $data['orderGoods'];
        $searType = $data['searType'] ?? 1;
        //操作类型 1为系统计算 2为人工指定
        $operateType = $data['operate_type'] ?? 1;
        $abnormalSn = $data['abnormal_sn'] ?? null;
        $adminInfo = $data['adminInfo'] ?? [];

        $log['param'] = $data;

        $returnData = ['res' => false, 'errorMsg' => '增加直属上级赠送条件失败', 'param' => $data];
        if (empty($orderInfo) || $orderInfo['order_type'] != 3) {
            return ['res' => false, 'errorMsg' => '不符合的订单类型~', 'param' => $data];
        }
        if ($operateType == 2 && empty($abnormalSn)) {
            return ['res' => false, 'errorMsg' => '请选择指定的异常记录', 'param' => $data];
        }
        if (empty($topLinkUser) || (!empty($topLinkUser) && empty($topLinkUser['vip_level']))) {
            return ['res' => false, 'errorMsg' => '直属上级非会员~', 'param' => $data];
        }
        $existRecord = HandselStandard::where(['order_sn' => $orderInfo['order_sn'], 'status' => 1])->count();
        if (!empty($existRecord)) {
            return ['res' => false, 'errorMsg' => '已存在赠送条件记录~', 'param' => $data, 'existRecord' => $existRecord ?? 0];
        }
        if($operateType == 1){
            foreach ($orderGoods as $key => $value) {
                if (empty(intval($value['refund_price'] ?? 0))) {
                    for ($i = 0; $i < $value['count']; $i++) {
                        $newRecord[$i]['order_sn'] = $value['order_sn'];
                        $newRecord[$i]['order_uid'] = $orderInfo['uid'];
                        $newRecord[$i]['order_goods_sn'] = $value['goods_sn'];
                        $newRecord[$i]['order_sku_sn'] = $value['sku_sn'];
                        $newRecord[$i]['order_goods_number'] = $value['count'];
                        $newRecord[$i]['record_number'] = $value['count'];
                        $newRecord[$i]['uid'] = $topLinkUser['uid'];
                        $newRecord[$i]['type'] = 1;
                        $newRecord[$i]['handsel_status'] = 2;
                    }
                } elseif ($value['refund_price'] < $value['real_pay_price']) {
                    //如果只是退部分款的情况要记录到新表, 让运营人员自行分配记录的条件
                    $abnormalRecord['abnormal_sn'] = (new CodeBuilder())->buildHandselSnCode();
                    $abnormalRecord['order_sn'] = $value['order_sn'];
                    $abnormalRecord['order_uid'] = $orderInfo['uid'];
                    $abnormalRecord['order_goods_sn'] = $value['goods_sn'];
                    $abnormalRecord['order_sku_sn'] = $value['sku_sn'];
                    $abnormalRecord['order_goods_number'] = $value['count'];
                    $abnormalRecord['record_number'] = 0;
                    $abnormalRecord['operate_status'] = 2;
                    HandselStandardAbnormal::create($abnormalRecord);
                    unset($orderGoods[$key]);
                    continue;
                }
            }
        }else{
            $operateNumber = HandselStandardAbnormal::where(['abnormal_sn'=>$abnormalSn,'operate_status'=>1])->value('record_number');
            if (empty($operateNumber) || intval($operateNumber) < 0) {
                return ['res' => false, 'errorMsg' => '不存在有效记录条数~', 'param' => $data, 'existRecord' => $existRecord ?? 0, 'operateNumber' => $operateNumber];
            }
            foreach ($orderGoods as $key => $value) {
                for ($i = 0; $i < $operateNumber; $i++) {
                    $newRecord[$i]['order_sn'] = $value['order_sn'];
                    $newRecord[$i]['order_uid'] = $orderInfo['uid'];
                    $newRecord[$i]['order_goods_sn'] = $value['goods_sn'];
                    $newRecord[$i]['order_sku_sn'] = $value['sku_sn'];
                    $newRecord[$i]['order_goods_number'] = $value['count'];
                    $newRecord[$i]['record_number'] = $operateNumber;
                    $newRecord[$i]['uid'] = $topLinkUser['uid'];
                    $newRecord[$i]['type'] = 2;
                    $newRecord[$i]['handsel_status'] = 2;
                }
            }
        }
        if (!empty($newRecord)) {
            $res = Db::transaction(function () use ($newRecord, $topLinkUser, $searType) {
                $newSend = [];
                if ($searType == 1) {
                    $recordRes = (new HandselStandard())->saveAll($newRecord);
                } else {
                    $recordRes = $newRecord;
                }
                //重新获取全部待奖励的条件记录, 查看是否能赠送
                $allRecord = HandselStandard::where(['handsel_status' => 2, 'status' => 1, 'uid' => $topLinkUser['uid']])->order('create_time asc')->select()->toArray();
                $lastOrderGoods = $allRecord[count($allRecord) - 1];
                $handselRuleRecordNumber = 4;
                $handselRuleSendNumber = 2;
                if (!empty($allRecord)) {
                    $allowNumber = floor(count($allRecord) / $handselRuleRecordNumber);
                    $codeBuilder = (new CodeBuilder());
                    $thisAllowNumber = ($allowNumber * $handselRuleSendNumber);
                    $changeRecordNumber = ($allowNumber * $handselRuleRecordNumber);
                    if (!empty($allowNumber) && $allowNumber > 0) {
                        for ($n = 0; $n < $thisAllowNumber; $n++) {
                            $newSend[$n]['handsel_sn'] = $codeBuilder->buildHandselSnCode();
                            $newSend[$n]['uid'] = $topLinkUser['uid'];
                            $newSend[$n]['goods_sn'] = $lastOrderGoods['order_goods_sn'];
                            $newSend[$n]['sku_sn'] = $lastOrderGoods['order_sku_sn'];
                        }
                    }
                    if (!empty($newSend)) {
                        foreach ($allRecord as $key => $value) {
                            if ($key < $changeRecordNumber) {
                                $changeRecord[] = $value['id'];
                            }
                        }
                        foreach ($changeRecord as $key => $value) {
                            $changeRecordUpdate[$key]['id'] = $value;
                            $changeRecordUpdate[$key]['handsel_status'] = 1;
                            if (empty($key)) {
                                $changeRecordUpdate[$key]['handsel_sn'] = $newSend[$key]['handsel_sn'];
                            } else {
                                if (!empty($newSend[floor($key / $handselRuleSendNumber)] ?? [])) {
                                    $changeRecordUpdate[$key]['handsel_sn'] = $newSend[floor($key / $handselRuleSendNumber)]['handsel_sn'];
                                }
                            }
                            $changeRecordUpdate[$key]['handsel_time'] = time();
                        }
                        if (!empty($newSend)) {
                            if ($searType == 1) {
                                $sendRes = (new Handsel())->saveAll($newSend);
                            } else {
                                $sendRes = $newSend;
                            }

                        }
                        if (!empty($changeRecordUpdate)) {
                            if ($searType == 1) {
                                foreach ($changeRecordUpdate as $key => $value) {
                                    $changeRecordRes[] = HandselStandard::update($value, ['id' => $value['id'], 'handsel_status' => 2]);
                                }
                            } else {
                                $changeRecordRes = $changeRecordUpdate;
                            }
                        }
                    }
                }
                return ['msg' => $searType == 1 ? '新增数据' : '仅查询数据', 'recordRes' => $recordRes ?? [], 'sendRes' => $sendRes ?? [], 'changeRecordRes' => $changeRecordRes ?? []];
            });

        }
        $returnData = ['res' => judge($res ?? []), 'errorMsg' => '成功处理上级套餐赠送逻辑', 'param' => $data, 'dealData' => $res ?? []];

        //记录日志
        $log['returnData'] = $returnData;
        (new Log())->setChannel('handsel')->record($log);

        return $returnData;
    }

    /**
     * @title  普通用户直推发放奖励
     * @param array $data
     * @return array
     * @throws \Exception
     */
    public function sendDirectNormalUserDivide(array $data)
    {
        $orderInfo = $data['orderInfo'] ?? [];
        $linkUid = $data['linkUid'] ?? null;
        $orderGoods = $data['orderGoods'] ?? [];
        $searType = $data['searType'] ?? 1;
        $returnData = ['res' => false, 'errorMsg' => '发放普通用户直推奖励出错', 'param' => $data];

        if (empty($orderInfo) || empty($linkUid)) {
            return ['res' => false, 'errorMsg' => '不符合的订单类型~', 'param' => $data];
        }
        $linkUser = User::where(['uid' => $linkUid, 'status' => 1])->field('uid,link_superior_user,vip_level')->findOrEmpty()->toArray();
        if (empty($linkUser ?? []) || (!empty($linkUser ?? []) && (!empty($linkUser['vip_level']) || intval($linkUser['vip_level']) > 0))) {
            return ['res' => false, 'errorMsg' => '仅限普通用户获取该奖', 'param' => $data, 'linkUserInfo' => $linkUser ?? []];
        }
        $rewardRule = SystemConfig::where(['status' => 1, 'id' => 1])->value('normal_user_reward_scale');
        if (empty($rewardRule)) {
            return ['res' => false, 'errorMsg' => '无有效奖励规则或奖励比例为0', 'param' => $data];
        }
        $existDivide = DivideModel::where(['type' => 1, 'order_sn' => $orderInfo['order_sn'], 'status' => 1,'link_uid'=>$linkUid])->count();
        if (!empty($existDivide)) {
            return ['res' => false, 'errorMsg' => '该订单已存在普通用户奖励,不允许重复发放', 'param' => $data, 'existDivide' => $existDivide];
        }
        if (!empty($linkUser)) {
            $userList[] = $linkUser;
        }
        if (empty($userList)) {
            return ['res' => false, 'errorMsg' => '无符合要求的上级或查无有效直推用户', 'userList' => $userList ?? [], 'param' => $data];
        }

        foreach ($userList as $uKey => $uValue) {
            foreach ($orderGoods as $key => $value) {
                $divides[$uKey]['order_sn'] = $orderInfo['order_sn'];
                $divides[$uKey]['order_uid'] = $orderInfo['uid'];
                $divides[$uKey]['belong'] = 1;
                $divides[$uKey]['type'] = 1;
                $divides[$uKey]['goods_sn'] = $value['goods_sn'];
                $divides[$uKey]['sku_sn'] = $value['sku_sn'];
                $divides[$uKey]['price'] = $value['price'];
                $divides[$uKey]['count'] = $value['count'];
                $divides[$uKey]['total_price'] = $value['total_price'];
                $divides[$uKey]['vdc'] = $rewardRule ?? 0.01;
                $divides[$uKey]['vdc_genre'] = 2;
                $divides[$uKey]['divide_type'] = 2;
                $divides[$uKey]['purchase_price'] = $value['total_price'];
                $divides[$uKey]['level'] = 0;
                $divides[$uKey]['link_uid'] = $uValue['uid'];
                $divides[$uKey]['divide_price'] = priceFormat($value['real_pay_price'] * $divides[$uKey]['vdc']);

                $divides[$uKey]['real_divide_price'] = $divides[$uKey]['divide_price'];
                $divides[$uKey]['arrival_status'] = 2;
                $divides[$uKey]['remark'] = '普通用户商品直推感恩奖';
            }
        }
        if (!empty($divides)) {
            if ($searType == 1) {
                (new DivideModel())->saveAll(array_values($divides));
                $returnData = ['res' => true, 'errorMsg' => '成功生成冻结中的普通用户商品直推奖励', 'data' => $divides, 'param' => $data, 'userList' => $userList];
            } else {
                $returnData = ['res' => true, 'errorMsg' => '仅查询预冻结中的普通用户商品直推奖励', 'data' => $divides, 'param' => $data, 'userList' => $userList];
            }
        }

        return $returnData;
    }

    /**
     * @title  直推上级发放奖励
     * @param array $data
     * @return array
     * @throws \Exception
     */
    public function sendDirectUserDivide(array $data)
    {
        $orderInfo = $data['orderInfo'] ?? [];
        $linkUid = $data['linkUid'] ?? null;
        $orderGoods = $data['orderGoods'] ?? [];
        $searType = $data['searType'] ?? 1;
        $returnData = ['res' => false, 'errorMsg' => '发放直推上级奖励出错', 'param' => $data];

        if (empty($orderInfo) || empty($linkUid) || (!empty($orderInfo) && in_array($orderInfo['order_type'], [3, 5]))) {
            return ['res' => false, 'errorMsg' => '不符合的订单类型~', 'param' => $data];
        }
        $linkUser = User::where(['uid' => $linkUid, 'status' => 1])->field('uid,link_superior_user,vip_level')->findOrEmpty()->toArray();
        if (empty($linkUser ?? []) || (!empty($linkUser ?? []) && (empty($linkUser['vip_level']) || intval($linkUser['vip_level']) <= 0))) {
            return ['res' => false, 'errorMsg' => '无有效上级用户信息或普通用户上级无法享受商品感恩奖', 'param' => $data, 'linkUserInfo' => $linkUser ?? []];
        }
        $rewardRule = SystemConfig::where(['status' => 1, 'id' => 1])->value('direct_user_reward_scale');
        if (empty($rewardRule)) {
            return ['res' => false, 'errorMsg' => '无有效奖励规则或奖励比例为0', 'param' => $data];
        }
        $existDivide = DivideModel::where(['type' => 1, 'order_sn' => $orderInfo['order_sn'], 'status' => 1, 'link_uid' => $linkUid, 'is_grateful' => 1])->count();
        if (!empty($existDivide)) {
            return ['res' => false, 'errorMsg' => '该订单已存在直推用户商品感恩奖励,不允许重复发放', 'param' => $data, 'existDivide' => $existDivide];
        }
        if (!empty($linkUser)) {
            $userList[] = $linkUser;
        }
        if (empty($userList)) {
            return ['res' => false, 'errorMsg' => '无符合要求的上级或查无有效直推用户', 'userList' => $userList ?? [], 'param' => $data];
        }

        foreach ($userList as $uKey => $uValue) {
            foreach ($orderGoods as $key => $value) {
                $divides[$uKey]['order_sn'] = $orderInfo['order_sn'];
                $divides[$uKey]['order_uid'] = $orderInfo['uid'];
                $divides[$uKey]['belong'] = 1;
                $divides[$uKey]['type'] = 1;
                $divides[$uKey]['is_grateful'] = 1;
                $divides[$uKey]['goods_sn'] = $value['goods_sn'];
                $divides[$uKey]['sku_sn'] = $value['sku_sn'];
                $divides[$uKey]['price'] = $value['price'];
                $divides[$uKey]['count'] = $value['count'];
                $divides[$uKey]['total_price'] = $value['total_price'];
                $divides[$uKey]['vdc'] = $rewardRule ?? 0.05;
                $divides[$uKey]['vdc_genre'] = 2;
                $divides[$uKey]['divide_type'] = 2;
                $divides[$uKey]['purchase_price'] = $value['total_price'];
                $divides[$uKey]['level'] = $uValue['vip_level'] ?? 0;
                $divides[$uKey]['link_uid'] = $uValue['uid'];
                $divides[$uKey]['divide_price'] = priceFormat($value['real_pay_price'] * $divides[$uKey]['vdc']);

                $divides[$uKey]['real_divide_price'] = $divides[$uKey]['divide_price'];
                $divides[$uKey]['arrival_status'] = 2;
                $divides[$uKey]['remark'] = '直推用户商品感恩奖';
            }
        }
        if (!empty($divides)) {
            if ($searType == 1) {
                (new DivideModel())->saveAll(array_values($divides));
                $returnData = ['res' => true, 'errorMsg' => '成功生成冻结中的直推用户商品感恩奖', 'data' => $divides, 'param' => $data, 'userList' => $userList];
            } else {
                $returnData = ['res' => true, 'errorMsg' => '仅查询预冻结中的直推用户商品感恩奖', 'data' => $divides, 'param' => $data, 'userList' => $userList];
            }
        }

        return $returnData;
    }

    /**
     * @title  转售订单上级获得下级订单金额奖励
     * @param array $data
     * @return array
     * @throws \Exception
     */
    public function resaleOrderTopUserDivide(array $data)
    {
        $orderInfo = $data['orderInfo'] ?? [];
        $linkUid = $data['linkUid'] ?? null;
        $orderGoods = $data['orderGoods'] ?? [];
        $searType = $data['searType'] ?? 1;
        $returnData = ['res' => false, 'errorMsg' => '发放转售订单上级奖励出错', 'param' => $data];

        if (empty($orderInfo) || empty($linkUid) || (!empty($orderInfo) && $orderInfo['order_type'] != 5)) {
            return ['res' => false, 'errorMsg' => '不符合的订单类型~', 'param' => $data];
        }
        $linkUser = User::where(['uid' => $linkUid, 'status' => 1])->field('uid,link_superior_user,vip_level')->findOrEmpty()->toArray();
        if (empty($linkUser ?? []) || (!empty($linkUser ?? []) && empty($linkUser['vip_level'] ?? 0))) {
            return ['res' => false, 'errorMsg' => '仅限会员用户获取该奖', 'param' => $data, 'linkUserInfo' => $linkUser ?? []];
        }

        $existDivide = DivideModel::where(['type' => 1, 'order_sn' => $orderInfo['order_sn'], 'status' => 1,'link_uid'=>$linkUid])->count();
        if (!empty($existDivide)) {
            return ['res' => false, 'errorMsg' => '该订单已存在奖励,不允许重复发放', 'param' => $data, 'existDivide' => $existDivide];
        }
        if (!empty($linkUser)) {
            $userList[] = $linkUser;
        }
        if (empty($userList)) {
            return ['res' => false, 'errorMsg' => '无符合要求的上级或查无有效直推用户', 'userList' => $userList ?? [], 'param' => $data];
        }

        foreach ($userList as $uKey => $uValue) {
            foreach ($orderGoods as $key => $value) {
                if($value['real_pay_price'] - $value['total_fare_price'] > 0){
                    $divides[$uKey]['order_sn'] = $orderInfo['order_sn'];
                    $divides[$uKey]['order_uid'] = $orderInfo['uid'];
                    $divides[$uKey]['belong'] = 1;
                    $divides[$uKey]['type'] = 4;
                    $divides[$uKey]['goods_sn'] = $value['goods_sn'];
                    $divides[$uKey]['sku_sn'] = $value['sku_sn'];
                    $divides[$uKey]['price'] = $value['price'];
                    $divides[$uKey]['count'] = $value['count'];
                    $divides[$uKey]['total_price'] = $value['total_price'];
                    $divides[$uKey]['vdc'] = $rewardRule ?? 0.01;
                    $divides[$uKey]['vdc_genre'] = 1;
                    $divides[$uKey]['divide_type'] = 1;
                    $divides[$uKey]['purchase_price'] = $value['total_price'];
                    $divides[$uKey]['level'] = 1;
                    $divides[$uKey]['link_uid'] = $uValue['uid'];
                    $divides[$uKey]['divide_price'] = $value['real_pay_price'] - $value['total_fare_price'];

                    $divides[$uKey]['real_divide_price'] = $divides[$uKey]['divide_price'];
                    $divides[$uKey]['arrival_status'] = 2;
                    $divides[$uKey]['remark'] = '下级转售订单直推奖励';
                }
            }
        }
        if (!empty($divides)) {
            if ($searType == 1) {
                (new DivideModel())->saveAll(array_values($divides));
                $returnData = ['res' => true, 'errorMsg' => '成功生成冻结中的转售订单上级奖励', 'data' => $divides, 'param' => $data, 'userList' => $userList];
            } else {
                $returnData = ['res' => true, 'errorMsg' => '仅查询预冻结中的转售订单上级奖励', 'data' => $divides, 'param' => $data, 'userList' => $userList];
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
        $allData['msg'] = '订单 ' . ($data['requestData']['order_sn'] ?? "<暂无订单编号>") . " [ 服务出错:" . ($error['msg'] ?? '原因未知') . " ] ";
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
        return (new Log())->setChannel('divide')->record($data, $level);
    }
}