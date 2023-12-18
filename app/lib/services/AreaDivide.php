<?php
// +----------------------------------------------------------------------
// |[ 文档说明: 区代奖励规则逻辑Service]
// +----------------------------------------------------------------------
// | Copyright (c) 2018~2024 http://www.mlhcmk.com All rights reserved.
// +----------------------------------------------------------------------
 

namespace app\lib\services;


use app\lib\exceptions\OrderException;
use app\lib\models\AfterSale;
use app\lib\models\AreaMemberVdc;
use app\lib\models\BalanceDetail;
use app\lib\models\Member;
use app\lib\models\TeamMember;
use app\lib\models\AreaMember;
use app\lib\services\TeamMember as TeamMemberService;
use app\lib\services\AreaMember as AreaMemberService;
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

class AreaDivide
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
            return $this->recordError($log, ['msg' => '[区域代理分润] 传入参数为空']);
        }

        $dbRes = [];
        $orderSn = $data['order_sn'];
        //查找类型 1为计算分润并存入数据 2为仅计算分润不存入(供仅查询使用)
        $searType = $data['searType'] ?? 1;
        $searTypeName = $searType == 1 ? '计算' : '查询';
        if (empty($orderSn)) {
            return $this->recordError($log, ['msg' => '参数中订单编号为空,非法!']);
        }
        $log['msg'] = '[区域代理分润] 订单' . $orderSn . '的分润,类型为 [ ' . $searTypeName . ' ]';


        $orderInfo = Db::name('order')->alias('a')
            ->join('sp_user b', 'a.uid = b.uid', 'left')
            ->join('sp_area_member c', 'a.uid = c.uid', 'left')
            ->field('a.*,b.name as user_name,b.vip_level,b.member_card,b.avaliable_balance,b.total_balance,b.link_superior_user')
            ->where(['a.order_sn' => $orderSn, 'a.pay_status' => 2])
            ->findOrEmpty();

        if (empty($orderInfo)) {
            $searNumber = $data['searNumber'] ?? 1;
            if (!empty($searNumber)) {
                if (intval($searNumber) == 1) {
                    $divideQueue = Queue::later(10, 'app\lib\job\AreaDividePrice', ['order_sn' => $orderSn, 'searNumber' => $searNumber + 1], config('system.queueAbbr') . 'AreaOrderDivide');
                    return $this->recordError($log, ['msg' => '[区域代理分润] 非法订单,查无此项,将于十秒后重新计算一次分润']);
                } else {
                    return $this->recordError($log, ['msg' => '[区域代理分润] 非法订单,查无此项,已经重复计算了多次,不再继续查询']);
                }
            }

        }

        $log['orderInfo'] = $orderInfo;

        //订单商品, 只能计入指定活动的商品或者是众筹活动的商品--暂时注释,默认全部订单都可以享受区代奖
        $orderGoodsMap[] = ['order_sn', '=', $orderSn];
        $orderGoodsMap[] = ['status', '=', 1];
        $orderGoodsMap[] = ['pay_status', '=', 2];
//        $orderGoodsMap[] = ['activity_sign', 'in', (new TeamMemberService())->activitySign];
        $orderGoods = OrderGoods::with(['vdc'])
            //暂时注释指定区代奖金标准的判断
//            ->where(function($query){
//            $whereOr1[] = ['activity_sign', 'in', (new TeamMemberService())->activitySign];
//            $whereOr2[] = ['', 'exp', Db::raw('crowd_code is not null and crowd_round_number is not null and crowd_period_number is not null')];
//            $query->whereOr([$whereOr1,$whereOr2]);
//        })
            ->where($orderGoodsMap)->withoutField('id,images,specs,desc,create_time,update_time')->select()->toArray();

        $log['orderGoods'] = $orderGoods ?? [];

        //判断是否存在区域代理分润记录
        $exMap[] = ['order_sn', '=', $orderSn];
        $exMap[] = ['status', '=', 1];
        $exMap[] = ['type', '=', 7];
        $exMap[] = ['level', '<>', 0];
        $existDivide = DivideModel::where($exMap)->count();
//        $existDivide = DivideModel::where(['order_sn' => $orderSn, 'status' => 1, 'type' => 1])->count();
        $log['existDivide'] = $existDivide ?? [];

        if ($searType == 1 && !empty($existDivide)) {
            $log['existDivideNumber'] = $existDivide;
            return $this->recordError($log, ['msg' => '[区域代理分润] 该订单已经计算过分润,不可重复计算']);
        }

        if (empty($orderGoods)) {
            //判断是否可以升级
            //$upgradeQueue = Queue::push('app\lib\job\AreaMemberUpgrade', ['uid' => $orderInfo['uid']], config('system.queueAbbr') . 'AreaMemberUpgrade');

            return $this->recordError($log, ['msg' => '[区域代理分润] 不存在可以计算分润的正常状态的商品']);
        }

        $teamMemberVdcRule = AreaMemberVdc::where(['status'=>1])->order('level asc')->select()->toArray();
        $log['teamMemberVdcRule'] = $teamMemberVdcRule ?? [];

        if (empty($teamMemberVdcRule)) {
            //立马判断是否可以升级
            //$upgradeQueue = Queue::push('app\lib\job\AreaMemberUpgrade', ['uid' => $orderInfo['uid']], config('system.queueAbbr') . 'AreaMemberUpgrade');

            return $this->recordError($log, ['msg' => '[区域代理分润] 不存在有效的奖励规则']);
        }
        //区域代理的分润规则直接拿去最新的规则
        foreach ($teamMemberVdcRule as $key => $value) {
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

        //查找订单的实际归属区域
        $shippingAddressDetailInfo = json_decode($orderInfo['shipping_address_detail'], true);
        $provinceId = $shippingAddressDetailInfo['ProvinceId'] ?? null;
        $cityId = $shippingAddressDetailInfo['CityId'] ?? null;
        $areaId = $shippingAddressDetailInfo['AreaId'] ?? null;
        $log['shippingAddressDetail'] = $shippingAddressDetailInfo ?? [];

        if (empty($shippingAddressDetailInfo) || empty($provinceId) || empty($cityId) || empty($areaId)) {
            //立马判断是否可以升级
            //$upgradeQueue = Queue::push('app\lib\job\AreaMemberUpgrade', ['uid' => $orderInfo['uid']], config('system.queueAbbr') . 'AreaMemberUpgrade');
            return $this->recordError($log, ['msg' => '[区域代理分润] 该订单的订单区域有误, 无法完成分润']);
        }


        $whereAndSql = '';
        $vipDivide = false;
        $userMember = [];

        if (!empty($orderInfo['vip_level'])) {
            $vipDivide = true;
        }

        //sql思路,查找不同等级且符合代理区域要求的会员

        $databaseVersion = str_replace('.', '', config('system.databaseVersion'));

        $linkUserParent = Db::query("SELECT member_card, uid, user_phone, level, type, status,link_superior_user,province,city,area,@l := @l + 1 AS divide_level FROM sp_area_member,( SELECT @l := 0 ) b WHERE status = 1 AND (( level = 1 AND province = " . "'" . $provinceId . "'" . " ) OR ( level = 2 AND province = " . "'" . $provinceId . "'" . " AND city = " . "'" . $cityId . "'" . " ) OR ( level = 3 AND province = " . "'" . $provinceId . "'" . " AND city = " . "'" . $cityId . "'" . " AND area = " . "'" . $areaId . "'" . " )) ORDER BY level DESC");

        //数组倒序
        //$linkUserParent = array_reverse($linkUserParent);
        if (empty($linkUserParent) && empty($joinDivideUser)) {
            //立马判断是否可以升级
            //$upgradeQueue = Queue::push('app\lib\job\AreaMemberUpgrade', ['uid' => $orderInfo['uid']], config('system.queueAbbr') . 'AreaMemberUpgrade');
            return $this->recordError($log, ['msg' => '[区域代理分润] 该用户查无可分润的区域上级,请系统查错']);
        }

        $log['linkUserParent'] = $linkUserParent;
        if (!empty($linkUserParent)) {
            $linkUser['level'] = 3;
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
        $memberVdcs = AreaMemberVdc::where(['status' => 1])->withoutField('id,create_time,update_time')->select()->toArray();
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
            //立马判断是否可以升级
            //$upgradeQueue = Queue::push('app\lib\job\AreaMemberUpgrade', ['uid' => $orderInfo['uid']], config('system.queueAbbr') . 'AreaMemberUpgrade');

            return $this->recordError($log, ['msg' => '[区域代理分润] 该用户的上级中没有一个人可以参与分润!']);
        }
        //这里筛选掉空的等级仅是为了得出正确的最高和最低等级,原来的$aDivideUser结构不能改变!
        $adU = $aDivideUser;
        foreach ($adU as $key => $value) {
            if (empty($value)) {
                unset($adU[$key]);
            }
        }
        $topMinLevel = (min(array_keys($adU)));
        $maxLevel = max(array_keys($adU));
        //此处的$minLevel是值第二小的等级, 如果只有一个人, 则默认当前的就是最小的
        $minLevel = min(array_keys($adU)) + 1;
        if (count(array_unique(array_keys($adU))) <= 1) {
            $minLevel = min(array_keys($adU));
        }


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
                        $levelValue['type'] = 7;
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
                            $levelValue['remark'] = '福利活动区代奖励';
                        }
//                        //如果实付价格低于成本价,直接卡断
//                        if ((string)$value['total_price'] < (string)($nowLevelVdc['purchase_price'] * $value['count'])) {
//                            //立马判断是否可以升级
//                            $upgradeQueue = Queue::push('app\lib\job\TeamMemberUpgrade', ['uid' => $orderInfo['uid']], config('system.queueAbbr') . 'TeamMemberUpgrade');
//                            return $this->recordError($log, ['msg' => '[区域代理分润] [严重错误] 分润风控拦截,订单金额低于上级成本价,整链分润取消!']);
//                        }
                        $aDivide[$value['sku_sn']][$count] = $levelValue;
//                        $aDivide[$value['sku_sn']][$count]['purchase_price'] = priceFormat($nowLevelVdc['purchase_price'] * $value['count']);
                        $aDivide[$value['sku_sn']][$count]['purchase_price'] = $levelValue['total_price'];
                        $aDivide[$value['sku_sn']][$count]['vdc_genre'] = 2;
                        $aDivide[$value['sku_sn']][$count]['level'] = $dUKey;
                        $aDivide[$value['sku_sn']][$count]['dis_reduce_price'] = "0.00";
                        $aDivide[$value['sku_sn']][$count]['is_vip_divide'] = !empty($vipDivide) ? 1 : 2;
                        //最大等级的用户享受这笔订单实付金额的百分比, 其他小于他等级的用户按照最大比例递减获取, 加入有1(1%)2(4%)3(2%)三个等级, 1得订单实付金额的1%, 2得(4-2)%,3得2%
                        if($levelValue['level'] == $topMinLevel){
                            $aDivide[$value['sku_sn']][$count]['divide_price'] = priceFormat(($value['real_pay_price'] - $value['total_fare_price'] - $value['refund_price']) * $teamMemberVdcRuleInfo[$levelValue['level']]['vdc_1']);
                        }else{
                            //如果这个商品没有历史分润则直接用(实付价-邮费) * 等级分润比例得出分润价格, 如果有则还需要扣除等级比当前低(level比当前大)的用户所有金额才能得出实际的分润价格
                            if(empty($goodsLevelDividePrice[$value['sku_sn']] ?? [])){
                                $aDivide[$value['sku_sn']][$count]['divide_price'] = priceFormat(($value['real_pay_price'] - $value['total_fare_price'] - $value['refund_price']) * $teamMemberVdcRuleInfo[$levelValue['level']]['vdc_1']);
                                $goodsLevelDividePrice[$value['sku_sn']][$levelValue['level']] = $aDivide[$value['sku_sn']][$count]['divide_price'];
                            }else{
                                //如果当前SKU里面存在了等级比自己还高的分润金额, 代表出现了异常,需要卡断
                                if(min(array_keys($goodsLevelDividePrice[$value['sku_sn']])) < $levelValue['level']){
                                    //立马判断是否可以升级
                                    //$upgradeQueue = Queue::push('app\lib\job\AreaMemberUpgrade', ['uid' => $orderInfo['uid']], config('system.queueAbbr') . 'AreaMemberUpgrade');
                                    return $this->recordError($log, ['msg' => '[区域代理分润] [错误] 分润风控拦截,出现了越级的区域代理奖励金额, 整链分润取消!']);
                                }else{
                                    $aDivide[$value['sku_sn']][$count]['divide_price'] = priceFormat(($value['real_pay_price'] - $value['total_fare_price'] - $value['refund_price']) * $teamMemberVdcRuleInfo[$levelValue['level']]['vdc_1']) - priceFormat(array_sum($goodsLevelDividePrice[$value['sku_sn']]));
                                    $goodsLevelDividePrice[$value['sku_sn']][$levelValue['level']] = $aDivide[$value['sku_sn']][$count]['divide_price'];
                                }
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
                //立马判断是否可以升级
                //$upgradeQueue = Queue::push('app\lib\job\AreaMemberUpgrade', ['uid' => $orderInfo['uid']], config('system.queueAbbr') . 'AreaMemberUpgrade');
                //反错
                $log['errorDivide'] = $errorDivide;
                return $this->recordError($log, ['msg' => '[区域代理分润] [严重错误] 分润风控拦截,分润链条中出现负数分润,整链分润取消!']);
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
                $aStocksDivides = [];
                //判断是否要将区代分出一部分奖励给股票奖
                $areaDivideAllot = false;
                $areaDivideAllotScale = SystemConfig::where(['id' => 1])->value('area_allot_scale') ?? 0;
                $stocksScale = 0;
                if (!empty(doubleval($areaDivideAllotScale))) {
                    $areaDivideAllot = true;
                    $areaDivideAllotScale = priceFormat($areaDivideAllotScale);
                    $stocksScale = (1 - $areaDivideAllotScale);
                }
                if (!empty($areaDivideAllot ?? false) && !empty(doubleval($areaDivideAllotScale) ?? 0)) {
                    $aStocksDivides = $aDivides;
                    foreach ($aDivides as $key => $value) {
                        $aDivides[$key]['divide_price'] = priceFormat($value['divide_price'] * $areaDivideAllotScale);
                        $aDivides[$key]['vdc'] = $value['vdc'] * $areaDivideAllotScale;
                        $aDivides[$key]['real_divide_price'] = $aDivides[$key]['divide_price'];
                        $aDivides[$key]['is_allot'] = 1;
                        $aDivides[$key]['allot_scale'] = $areaDivideAllotScale;
                    }
                    foreach ($aStocksDivides as $key => $value) {
                        $aStocksDivides[$key]['type'] = 9;
                        $aStocksDivides[$key]['divide_price'] = priceFormat($value['divide_price'] * $stocksScale);
                        $aStocksDivides[$key]['vdc'] = $value['vdc'] * $stocksScale;
                        $aStocksDivides[$key]['real_divide_price'] = $aStocksDivides[$key]['divide_price'];
                        $aStocksDivides[$key]['is_allot'] = 1;
                        $aStocksDivides[$key]['allot_scale'] = $stocksScale;
                        $aStocksDivides[$key]['allot_type'] = 2;
                        $aStocksDivides[$key]['remark'] = '股票奖励(来自区代)';
                        if ($orderInfo['order_type'] == 6) {
                            $aStocksDivides[$key]['remark'] = '福利活动股票奖励(来自区代)';
                        }
                    }
                }
                $dbRes = Db::transaction(function () use ($aDivides, $orderInfo, $orderGoods, $linkUserParent, $orderGoodsTotalPrice, $aStocksDivides) {
                    //记录分润明细
                    $res = (new DivideModel())->saveAll($aDivides);
                    if (!empty($aStocksDivides ?? [])) {
                        (new DivideModel())->saveAll($aStocksDivides);
                    }
                    return $res;
                });
            } else {
                $dbRes = $aDivides;
            }
        }
        $log['res'] = $dbRes;
        $log['msg'] = '[区域代理分润] 订单' . $orderSn . '的分润已 [ ' . $searTypeName . '成功 ]';

        $this->log($log, 'info');

        //立马判断是否可以升级
        //$upgradeQueue = Queue::push('app\lib\job\AreaMemberUpgrade', ['uid' => $orderInfo['uid']], config('system.queueAbbr') . 'AreaMemberUpgrade');

        return $dbRes;
    }

    /**
     * @title  分润-设备
     * @param $data
     * @return array|bool|mixed
     * @throws \Exception
     * //Job $job,
     */
    public function divideForDevice($data)
    {
        $log['requestData'] = $data;
        if (empty($data)) {
            return $this->recordError($log, ['msg' => '[区域代理设备分润] 传入参数为空']);
        }

        $dbRes = [];
        $orderSn = $data['order_sn'];
        //查找类型 1为计算分润并存入数据 2为仅计算分润不存入(供仅查询使用)
        $searType = $data['searType'] ?? 1;
        $searTypeName = $searType == 1 ? '计算' : '查询';
        if (empty($orderSn)) {
            return $this->recordError($log, ['msg' => '参数中订单编号为空,非法!']);
        }
        $log['msg'] = '[区域代理设备分润] 订单' . $orderSn . '的设备分润,类型为 [ ' . $searTypeName . ' ]';


        $orderInfo = Db::name('device_order')->alias('a')
            ->join('sp_user b', 'a.uid = b.uid', 'left')
            ->join('sp_area_member c', 'a.uid = c.uid', 'left')
            ->field('a.*,b.name as user_name,b.vip_level,b.member_card,b.avaliable_balance,b.total_balance,b.link_superior_user')
            ->where(['a.order_sn' => $orderSn, 'a.pay_status' => 2])
            ->findOrEmpty();

        if (empty($orderInfo)) {
            $searNumber = $data['searNumber'] ?? 1;
            if (!empty($searNumber)) {
                if (intval($searNumber) == 1) {
                    $divideQueue = Queue::later(10, 'app\lib\job\AreaDividePrice', ['order_sn' => $orderSn, 'searNumber' => $searNumber + 1, 'dealType' => 2, 'grantNow' => ($data['grantNow'] ?? false)], config('system.queueAbbr') . 'AreaOrderDivide');
                    return $this->recordError($log, ['msg' => '[区域代理设备分润] 非法订单,查无此项,将于十秒后重新计算一次设备分润']);
                } else {
                    return $this->recordError($log, ['msg' => '[区域代理设备分润] 非法设备订单,查无此项,已经重复计算了多次,不再继续查询']);
                }
            }

        }

        $log['orderInfo'] = $orderInfo;

        //订单商品, 只能计入指定活动的商品或者是众筹活动的商品--暂时注释,默认全部订单都可以享受区代奖
        $orderInfoGoods = $orderInfo;
        $orderInfoGoods['goods_sn'] = $orderInfoGoods['device_sn'];
        $orderInfoGoods['sku_sn'] = $orderInfoGoods['device_sn'];
        $orderInfoGoods['total_fare_price'] = 0;
        $orderInfoGoods['count'] = 1;
        $orderInfoGoods['real_pay_price'] = $orderInfoGoods['device_cash_price'] ?? 0;
        $orderInfoGoods['total_price'] = $orderInfoGoods['device_cash_price'] ?? 0;
        $orderInfoGoods['refund_price'] = 0;
        $orderGoods[] = $orderInfoGoods;

        $log['orderGoods'] = $orderGoods ?? [];

        //判断是否存在区域代理分润记录
        $exMap[] = ['order_sn', '=', $orderSn];
        $exMap[] = ['status', '=', 1];
        $exMap[] = ['type', '=', 7];
        $exMap[] = ['level', '<>', 0];
        $exMap[] = ['', 'exp', Db::raw('device_sn is not null')];
        $exMap[] = ['is_device', '=', 1];
        $exMap[] = ['device_divide_type', '=', 2];
        $existDivide = DivideModel::where($exMap)->count();
//        $existDivide = DivideModel::where(['order_sn' => $orderSn, 'status' => 1, 'type' => 1])->count();
        $log['existDivide'] = $existDivide ?? [];

        if ($searType == 1 && !empty($existDivide)) {
            $log['existDivideNumber'] = $existDivide;
            return $this->recordError($log, ['msg' => '[区域代理设备分润] 该订单已经计算过分润,不可重复计算']);
        }

        if (empty($orderGoods)) {
            return $this->recordError($log, ['msg' => '[区域代理设备分润] 不存在可以计算分润的正常状态的商品']);
        }

        $teamMemberVdcRule = AreaMemberVdc::where(['status'=>1])->order('level asc')->select()->toArray();
        $log['teamMemberVdcRule'] = $teamMemberVdcRule ?? [];

        if (empty($teamMemberVdcRule)) {
            return $this->recordError($log, ['msg' => '[区域代理设备分润] 不存在有效的奖励规则']);
        }
        //区域代理的分润规则直接拿去最新的规则
        foreach ($teamMemberVdcRule as $key => $value) {
            $teamMemberVdcRuleInfo[$value['level']]['vdc_1'] = $value['vdc_one'];
            $teamMemberVdcRuleInfo[$value['level']]['vdc_2'] = $value['vdc_two'];
        }

        if (!empty($teamMemberVdcRuleInfo ?? [])) {
            foreach ($orderGoods as $key => $value) {
                $orderGoods[$key]['vdc_rule'] = $teamMemberVdcRuleInfo;
                unset($orderGoods[$key]['vdc']);
            }
        }

        $joinDivideUser = [];
        $linkUserCanNotDivide = true;

        //查找订单的实际归属区域
        $shippingAddressDetailInfo = json_decode($orderInfo['device_address_detail'], true);
        $provinceId = $shippingAddressDetailInfo['ProvinceId'] ?? null;
        $cityId = $shippingAddressDetailInfo['CityId'] ?? null;
        $areaId = $shippingAddressDetailInfo['AreaId'] ?? null;
        $log['shippingAddressDetail'] = $shippingAddressDetailInfo ?? [];

        if (empty($shippingAddressDetailInfo) || empty($provinceId) || empty($cityId) || empty($areaId)) {
            return $this->recordError($log, ['msg' => '[区域代理设备分润] 该订单的订单区域有误, 无法完成分润']);
        }


        $whereAndSql = '';
        $vipDivide = false;
        $userMember = [];

        if (!empty($orderInfo['vip_level'])) {
            $vipDivide = true;
        }

        //sql思路,查找不同等级且符合代理区域要求的会员

        $databaseVersion = str_replace('.', '', config('system.databaseVersion'));

        $linkUserParent = Db::query("SELECT member_card, uid, user_phone, level, type, status,link_superior_user,province,city,area,@l := @l + 1 AS divide_level FROM sp_area_member,( SELECT @l := 0 ) b WHERE status = 1 AND (( level = 1 AND province = " . "'" . $provinceId . "'" . " ) OR ( level = 2 AND province = " . "'" . $provinceId . "'" . " AND city = " . "'" . $cityId . "'" . " ) OR ( level = 3 AND province = " . "'" . $provinceId . "'" . " AND city = " . "'" . $cityId . "'" . " AND area = " . "'" . $areaId . "'" . " )) ORDER BY level DESC");

        //数组倒序
        //$linkUserParent = array_reverse($linkUserParent);
        if (empty($linkUserParent) && empty($joinDivideUser)) {
            //立马判断是否可以升级
            //$upgradeQueue = Queue::push('app\lib\job\AreaMemberUpgrade', ['uid' => $orderInfo['uid']], config('system.queueAbbr') . 'AreaMemberUpgrade');
            return $this->recordError($log, ['msg' => '[区域代理设备分润] 该用户查无可分润的区域上级,请系统查错']);
        }

        $log['linkUserParent'] = $linkUserParent;
        if (!empty($linkUserParent)) {
            $linkUser['level'] = 3;
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
        $memberVdcs = AreaMemberVdc::where(['status' => 1])->withoutField('id,create_time,update_time')->select()->toArray();
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
            return $this->recordError($log, ['msg' => '[区域代理设备分润] 该用户的上级中没有一个人可以参与分润!']);
        }
        //这里筛选掉空的等级仅是为了得出正确的最高和最低等级,原来的$aDivideUser结构不能改变!
        $adU = $aDivideUser;
        foreach ($adU as $key => $value) {
            if (empty($value)) {
                unset($adU[$key]);
            }
        }
        $topMinLevel = (min(array_keys($adU)));
        $maxLevel = max(array_keys($adU));
        //此处的$minLevel是值第二小的等级, 如果只有一个人, 则默认当前的就是最小的
        $minLevel = min(array_keys($adU)) + 1;
        if (count(array_unique(array_keys($adU))) <= 1) {
            $minLevel = min(array_keys($adU));
        }


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
            if ($value['real_pay_price'] <= 0 && $value['used_healthy'] <= 0) {
                unset($orderGoods[$key]);
                continue;
            }
            $orderGoodsTotalPrice += $value['real_pay_price'];
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
                        $levelValue['belong'] = 1;
                        $levelValue['order_uid'] = $orderInfo['uid'];
                        $levelValue['type'] = 7;
                        $levelValue['order_sn'] = $value['order_sn'];
                        $levelValue['goods_sn'] = $value['goods_sn'];
                        $levelValue['sku_sn'] = $value['sku_sn'];
                        $levelValue['price'] = $value['real_pay_price'];
                        $levelValue['count'] = 1;
                        $levelValue['total_price'] = $value['real_pay_price'];
                        $levelValue['link_uid'] = $levelValue['uid'];
                        $levelValue['is_device'] = 1;
                        $levelValue['device_sn'] = $value['device_sn'] ?? null;
                        $levelValue['remark'] = '设备区代奖励(' . $value['device_sn'] . ')';
                        $levelValue['device_divide_type'] = 2;
                        $aDivide[$value['sku_sn']][$count] = $levelValue;
                        $aDivide[$value['sku_sn']][$count]['purchase_price'] = $levelValue['total_price'];
                        $aDivide[$value['sku_sn']][$count]['vdc_genre'] = 2;
                        $aDivide[$value['sku_sn']][$count]['level'] = $dUKey;
                        $aDivide[$value['sku_sn']][$count]['dis_reduce_price'] = "0.00";
                        $aDivide[$value['sku_sn']][$count]['is_vip_divide'] = !empty($vipDivide) ? 1 : 2;
                        //最大等级的用户享受这笔订单实付金额的百分比, 其他小于他等级的用户按照最大比例递减获取, 加入有1(1%)2(4%)3(2%)三个等级, 1得订单实付金额的1%, 2得(4-2)%,3得2%
                        if($levelValue['level'] == $topMinLevel){
                            $aDivide[$value['sku_sn']][$count]['divide_price'] = priceFormat(($value['real_pay_price'] - $value['total_fare_price'] - $value['refund_price']) * $teamMemberVdcRuleInfo[$levelValue['level']]['vdc_1']);
                        }else{
                            //如果这个商品没有历史分润则直接用(实付价-邮费) * 等级分润比例得出分润价格, 如果有则还需要扣除等级比当前低(level比当前大)的用户所有金额才能得出实际的分润价格
                            if(empty($goodsLevelDividePrice[$value['sku_sn']] ?? [])){
                                $aDivide[$value['sku_sn']][$count]['divide_price'] = priceFormat(($value['real_pay_price'] - $value['total_fare_price'] - $value['refund_price']) * $teamMemberVdcRuleInfo[$levelValue['level']]['vdc_1']);
                                $goodsLevelDividePrice[$value['sku_sn']][$levelValue['level']] = $aDivide[$value['sku_sn']][$count]['divide_price'];
                            }else{
                                //如果当前SKU里面存在了等级比自己还高的分润金额, 代表出现了异常,需要卡断
                                if(min(array_keys($goodsLevelDividePrice[$value['sku_sn']])) < $levelValue['level']){
                                    //立马判断是否可以升级
                                    //$upgradeQueue = Queue::push('app\lib\job\AreaMemberUpgrade', ['uid' => $orderInfo['uid']], config('system.queueAbbr') . 'AreaMemberUpgrade');
                                    return $this->recordError($log, ['msg' => '[区域代理设备分润] [错误] 设备分润风控拦截,出现了越级的区域代理奖励金额, 整链分润取消!']);
                                }else{
                                    $aDivide[$value['sku_sn']][$count]['divide_price'] = priceFormat(($value['real_pay_price'] - $value['total_fare_price'] - $value['refund_price']) * $teamMemberVdcRuleInfo[$levelValue['level']]['vdc_1']) - priceFormat(array_sum($goodsLevelDividePrice[$value['sku_sn']]));
                                    $goodsLevelDividePrice[$value['sku_sn']][$levelValue['level']] = $aDivide[$value['sku_sn']][$count]['divide_price'];
                                }
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
                //立马判断是否可以升级
                //$upgradeQueue = Queue::push('app\lib\job\AreaMemberUpgrade', ['uid' => $orderInfo['uid']], config('system.queueAbbr') . 'AreaMemberUpgrade');
                //反错
                $log['errorDivide'] = $errorDivide;
                return $this->recordError($log, ['msg' => '[区域代理设备分润] [严重错误] 设备分润风控拦截,分润链条中出现负数分润,整链分润取消!']);
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
                $aStocksDivides = [];
                //判断是否要将区代分出一部分奖励给股票奖,默认不分
                $areaDivideAllot = false;
//                $areaDivideAllotScale = SystemConfig::where(['id' => 1])->value('area_allot_scale') ?? 0;
                $areaDivideAllotScale = 0;
                $stocksScale = 0;
                if (!empty(doubleval($areaDivideAllotScale))) {
                    $areaDivideAllot = true;
                    $areaDivideAllotScale = priceFormat($areaDivideAllotScale);
                    $stocksScale = (1 - $areaDivideAllotScale);
                }
                if (!empty($areaDivideAllot ?? false) && !empty(doubleval($areaDivideAllotScale) ?? 0)) {
                    $aStocksDivides = $aDivides;
                    foreach ($aDivides as $key => $value) {
                        $aDivides[$key]['divide_price'] = priceFormat($value['divide_price'] * $areaDivideAllotScale);
                        $aDivides[$key]['vdc'] = $value['vdc'] * $areaDivideAllotScale;
                        $aDivides[$key]['real_divide_price'] = $aDivides[$key]['divide_price'];
                        $aDivides[$key]['is_allot'] = 1;
                        $aDivides[$key]['allot_scale'] = $areaDivideAllotScale;
                    }
                    foreach ($aStocksDivides as $key => $value) {
                        $aStocksDivides[$key]['type'] = 9;
                        $aStocksDivides[$key]['divide_price'] = priceFormat($value['divide_price'] * $stocksScale);
                        $aStocksDivides[$key]['vdc'] = $value['vdc'] * $stocksScale;
                        $aStocksDivides[$key]['real_divide_price'] = $aStocksDivides[$key]['divide_price'];
                        $aStocksDivides[$key]['is_allot'] = 1;
                        $aStocksDivides[$key]['allot_scale'] = $stocksScale;
                        $aStocksDivides[$key]['allot_type'] = 2;
                        $aStocksDivides[$key]['remark'] = '股票奖励(来自区代设备)';
                        if ($orderInfo['order_type'] == 6) {
                            $aStocksDivides[$key]['remark'] = '福利活动股票奖励(来自区代设备)';
                        }
                    }
                }
                $dbRes = Db::transaction(function () use ($aDivides, $orderInfo, $orderGoods, $linkUserParent, $orderGoodsTotalPrice, $aStocksDivides,$data) {
                    //记录分润明细
                    //判断是否要立马发放
                    if(!empty($data['grantNow'] ?? false)){
                        foreach ($aDivides as $key => $value) {
                            $aDivides[$key]['arrival_status'] = 1;
                            $aDivides[$key]['arrival_time'] = time();
                        }
                    }
                    $res = (new DivideModel())->saveAll($aDivides);
                    if (!empty($aStocksDivides ?? [])) {
                        if (!empty($data['grantNow'] ?? false)) {
                            foreach ($aStocksDivides as $key => $value) {
                                $aStocksDivides[$key]['arrival_status'] = 1;
                                $aStocksDivides[$key]['arrival_time'] = time();
                            }
                        }
                        (new DivideModel())->saveAll($aStocksDivides);
                    }
                    if (!empty($data['grantNow'] ?? false)) {
                        foreach ($aDivides as $key => $value) {
                            //如果是区代奖励记录在原来的钱包里面
                            $uBalance[$key]['order_sn'] = $value['order_sn'];
                            $uBalance[$key]['belong'] = 1;
                            $uBalance[$key]['price'] = $value['real_divide_price'];
                            $uBalance[$key]['type'] = 1;
                            $uBalance[$key]['uid'] = $value['link_uid'];
                            $uBalance[$key]['change_type'] = 20;
                            $uBalance[$key]['remark'] = '区代用户体验设备奖励-' . $value['order_sn'] . '-' . ($value['device_sn'] ?? '');
                            if (doubleval($uBalance[$key]['price']) > 0) {
                                //发奖金到用户团队业绩账户
                                $refundRes[$key] = User::where(['uid' => $value['link_uid']])->inc('area_balance', $uBalance[$key]['price'])->update();
                            }
                        }
                        if (!empty($uBalance ?? [])) {
                            (new BalanceDetail())->saveAll($uBalance);
                        }
                    }
                    return $res;
                });
            } else {
                $dbRes = $aDivides;
            }
            $dbRes = $aDivides;
        }
        $log['res'] = $dbRes;
        $log['msg'] = '[区域代理设备分润] 订单' . $orderSn . '的分润已 [ ' . $searTypeName . '成功 ]';
        if (!empty($data['grantNow'] ?? false)) {
            $log['msg'] .= '-且分润立马发放成功';
        }
        $this->log($log, 'info');
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
        $teamMemberVdc = AreaMemberVdc::where(['status' => 1])->column('level');
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
            $aTotal = (new AreaMember())->where($newMap)->count();
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
                    $dirUser = AreaMember::where(['link_superior_user' => $uid, 'status' => [1, 2]])->column('uid');
                    if (!empty($dirUser)) {
                        $nextUser = AreaMember::where(['link_superior_user' => $dirUser, 'status' => [1, 2]])->column('uid');
                        if (!empty($nextUser)) {
                            $newMap[] = ['uid', 'in', $nextUser];
                        }
                    }
                    break;
            }
        }

        $listSql = (new AreaMember())->field('uid,user_phone,level,type,link_superior_user,status,create_time,upgrade_time,team_chain')->where($newMap)->when(!empty($orderSql), function ($query) use ($orderSql) {
            $query->order($orderSql);
        })->buildSql();
        $list = Db::query($listSql);

        if (!empty($list)) {
            if (!empty($otherMap['searType'] ?? null)) {
                if ($otherMap['searType'] == 1) {
                    $uMap[] = ['link_superior_user', '=', $uid];
                    $uMap[] = ['status', '=', 1];
                    $uMap[] = ['level', '<=', $otherMap['maxLevel']];
                    $dirUid = AreaMember::where($uMap)->column('uid');
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
     * @param int $level 筛选等级 0以上为AreaMember,负数为统一找member
     * @return array
     * @throws \Exception
     */
    public function getNextDirectLinkUserGroupByLevel(string $uid, int $level = 0)
    {
        $map[] = ['status', '=', 1];
        $map[] = ['link_superior_user', '=', $uid];
        $model = (new AreaMember());
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
        $cacheKey = 'AreaDivideOrderCache-' . $orderSn;
        $cacheExpire = 30;
        if (!empty(cache($cacheKey))) {
            if (empty($notThrowError)) {
                throw new OrderException(['msg' => '[区域代理奖励] 前方网络拥堵,请稍后重试']);
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
                throw new OrderException(['msg' => '[区域代理奖励] 只有已发货且无退售后的未完结订单才可以确认收货哟']);
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
                $allUser = User::where(['uid' => $allUid, 'status' => 1])->field('uid,avaliable_balance,total_balance,integral')->lock(true)->select()->toArray();
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

                            $uRes = User::where(['uid' => $value['uid'], 'status' => 1])->inc('area_balance', $dsValue['real_divide_price'])->update();
                            $userRes[] = ['res' => $uRes, 'uid' => $value['uid']];

                            //增加余额明细
                            $detail['order_sn'] = $orderInfo['order_sn'];
                            $detail['belong'] = $orderInfo['order_belong'] ?? 1;
                            $detail['uid'] = $value['uid'];
                            $detail['type'] = 1;
                            $detail['price'] = priceFormat($dsValue['real_divide_price']);
                            //type 16为区域代理奖励
                            $detail['change_type'] = 20;
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

            $allRes['msg'] = '发放订单区域代理奖励完成';
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

                                    $allUser[$key]['avaliable_balance'] = $save['avaliable_balance'];
                                    $allUser[$key]['total_balance'] = $save['total_balance'];

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
        $member = AreaMember::where(['uid' => $topUid, 'status' => 1])->withoutField('id,create_time,update_time')->findOrEmpty()->toArray();
        if (intval($member['level']) > $nowLevel) {
            $user = $this->getTopLevelLinkUser($member['link_superior_user'], $nowLevel);
        } else {
            $user = $member;
        }
        return $user;
    }

    /**
     * @title  记录错误并保存日志,删除该任务后终止
     * @param array $data 所有数据
     * @param array $error 错误内容
     * @return bool
     */
    public function recordError(array $data, array $error)
    {
        $allData['msg'] = '[区域代理奖励] 订单 ' . ($data['requestData']['order_sn'] ?? "<暂无订单编号>") . " [ 服务出错:" . ($error['msg'] ?? '原因未知') . " ] ";
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
        return (new Log())->setChannel('areaDivide')->record($data, $level);
    }
}