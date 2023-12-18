<?php
// +----------------------------------------------------------------------
// |[ 文档说明: ]
// +----------------------------------------------------------------------
// | Copyright (c) 2018~2024 http://www.mlhcmk.com All rights reserved.
// +----------------------------------------------------------------------
 

namespace app\lib\services;


use app\BaseController;
use app\lib\exceptions\ServiceException;
use app\lib\models\BalanceDetail;
use app\lib\models\CrowdfundingFuseRecord;
use app\lib\models\Member as MemberModel;
use app\lib\models\Member;
use app\lib\models\MemberVdc;
use app\lib\models\Order;
use app\lib\models\PpylOrder;
use app\lib\models\PpylReward;
use app\lib\models\TeamMemberVdc;
use app\lib\models\TeamPerformance;
use app\lib\services\Divide;
use app\lib\models\User;
use app\lib\models\Divide as DivideModel;
use app\lib\models\TeamMember;
use think\facade\Db;
use think\facade\Request;

class UserSummary
{
    protected $requestData;

    public function __construct()
    {
        $this->requestData = Request::param();
    }

    /**
     * @title  我的业绩
     * @return mixed
     * @throws \Exception
     */
    public function myPerformance()
    {
        $uid = Request::param('uid');
        $orderTeamType = [1 => '直属团队', 2 => '整个团队'];
        $divide = (new Divide());
        $member = (new MemberModel());
        $aMemberInfo = $member->with(['user'])->where(['uid' => $uid, 'status' => 1])->field('uid,user_phone,member_card,level,type,link_superior_user,child_team_code,team_code,parent_team,growth_value')->findOrEmpty()->toArray();
        $aUserLevel = $aMemberInfo['level'] ?? 0;
        if (empty($aUserLevel)) {
            throw new ServiceException(['msg' => '非会员无法查看哦~~']);
        }
        $allLevel = MemberVdc::order('level desc')->column('name', 'level');
        $maxLevel = min(array_keys($allLevel));

        //如果已经是最高等级了证明都达标了,前端不显示具体团队人数,没必要去查询了,直接全部符合
        $isMaxLevel = false;
        if ($aUserLevel == $maxLevel) {
            $isMaxLevel = true;
        }
        $directTeam = [];
        $allTeam = [];
        if (empty($isMaxLevel)) {
            //直推人数
            $directTeam = $divide->getNextDirectLinkUserGroupByLevel($aMemberInfo['uid']);
            //团队全部人数
            $allTeam = $divide->getTeamAllUserGroupByLevel($aMemberInfo['uid']);
        }

        $nextLevel = ($aUserLevel - 1);
        if ($nextLevel > 0) {
            $aNextLevel = MemberVdc::where(['status' => 1, 'level' => ($aUserLevel - 1)])->order('level desc')->findOrEmpty()->toArray();
        } else {
            $aNextLevel = MemberVdc::where(['status' => 1, 'level' => $aUserLevel])->order('level desc')->findOrEmpty()->toArray();
        }


        if ($aNextLevel['sales_team_level'] == 1) {
            $checkOrderTeam = $directTeam;
        } elseif ($aNextLevel['sales_team_level'] == 2) {
            //追加自己
            $allTeam['allUser']['onlyUidList'][] = $uid;
            $checkOrderTeam = $allTeam;
        }

        $oldPerformance = 0;
        $now['allTeamOrderPrice'] = 0;
//        if(!empty($checkOrderTeam['allUser']['onlyUidList'])){
//            $orderTotalPrice = (new Member())->getUserTeamOrderPrice($checkOrderTeam['allUser']['onlyUidList']);
//
//            //累加之前的旧业绩
//            if($aNextLevel['sales_team_level'] == 1){
//                $oldPerformance = $aMemberInfo['old_update_team_performance'] ?? 0;
//            }elseif($aNextLevel['sales_team_level'] == 2){
//                $oldPerformance = $aMemberInfo['old_all_team_performance'] ?? 0;
//            }
//
//            $now['allTeamOrderPrice'] = $orderTotalPrice + $oldPerformance;
//
//        }

        $allCount = 0;

        //如果需要检验团队人数才显示对应的条件
        if ($aNextLevel['need_team_condition'] == 1) {
            //直推要求
            $recommend_level = json_decode($aNextLevel['recommend_level'], true);
            $recommend_number = json_decode($aNextLevel['recommend_number'], true);

            foreach ($recommend_level as $key => $value) {
                foreach ($recommend_number as $nKey => $nValue) {
                    if ($nKey == $key) {
                        $aims[$allCount]['title'] = '直属' . $allLevel[$value];
                        $aims[$allCount]['aimsNumber'] = $nValue;
                        $aims[$allCount]['nowNumber'] = !empty($isMaxLevel) ? ($nValue + 1) : ($directTeam['userLevel'][$value]['count'] ?? 0);
                        $allCount++;
                    }

                }
            }

            //团队要求
            $train_level = json_decode($aNextLevel['train_level'], true);
            $train_number = json_decode($aNextLevel['train_number'], true);
            foreach ($train_level as $key => $value) {
                foreach ($train_number as $nKey => $nValue) {
                    if ($nKey == $key) {
                        $aims[$allCount]['title'] = '团队' . $allLevel[$value];
                        $aims[$allCount]['aimsNumber'] = $nValue;
                        $aims[$allCount]['nowNumber'] = !empty($isMaxLevel) ? ($nValue + 1) : ($allTeam['userLevel'][$value]['count'] ?? 0);
                        $allCount++;
                    }

                }
            }

        }

        //成长值
        $aims[$allCount]['title'] = '成长值';
        $aims[$allCount]['aimsNumber'] = $aNextLevel['growth_value'];
        $aims[$allCount]['nowNumber'] = $aMemberInfo['growth_value'] ?? 0;

        return $aims ?? [];
    }

    /**
     * @title  我的团队代理人业绩(晋级进度)
     * @return mixed
     * @throws \Exception
     */
    public function myTeamPerformance()
    {
        $uid = Request::param('uid');
        $orderTeamType = [1 => '直属团队', 2 => '整个团队'];
        $teamDivide = (new TeamDivide());
        $member = (new TeamMember());
        $aMemberInfo = $member->with(['user'])->where(['uid' => $uid, 'status' => 1])->field('uid,user_phone,member_card,level,type,link_superior_user,team_sales_price')->findOrEmpty()->toArray();
        if(empty($aMemberInfo)){
            $userInfo = User::where(['uid'=>$uid,'status'=>1])->findOrEmpty()->toArray();
        }
        $aUserLevel = $aMemberInfo['level'] ?? 0;
//        if (empty($aUserLevel)) {
//            throw new ServiceException(['msg' => '非会员无法查看哦~~']);
//        }
        $allLevel = TeamMemberVdc::order('level desc')->column('name', 'level');
        $maxLevel = min(array_keys($allLevel));

        //如果已经是最高等级了证明都达标了,前端不显示具体团队人数,没必要去查询了,直接全部符合
        $isMaxLevel = false;
        if ($aUserLevel == $maxLevel) {
            $isMaxLevel = true;
        }else{
            $teamAims = [];
            $aTeamLevel = $aMemberInfo['level'] ?? 0;
            $tOrderTeamType = [1 => '直属团队业绩', 2 => '整个团队业绩'];
            $teamNextLevel = ($aTeamLevel - 1);
            if ($teamNextLevel > 0) {
                $aTeamNextLevel = TeamMemberVdc::with(['recommend', 'train'])->where(['status' => 1, 'level' => ($aTeamLevel - 1)])->order('level desc')->findOrEmpty()->toArray();
            } else {
                $aTeamNextLevel = TeamMemberVdc::with(['recommend', 'train'])->where(['status' => 1, 'level' => 4])->order('level desc')->findOrEmpty()->toArray();
            }
        }

        $directTeam = [];
        $allTeam = [];
        //获取团队晋级目标
        $tdirectTeam = [];
        $tallTeam = [];
        $teamAims = [];
        if (empty($isMaxLevel)) {
            if (!empty($aTeamNextLevel['recommend'] ?? null) || !empty(json_decode(($aTeamNextLevel['recommend_level'] ?? null), true))) {
                //直推人数
                $tdirectTeam = $teamDivide->getNextDirectLinkUserGroupByLevel($aMemberInfo['uid'] ?? $uid);
            }

            if (!empty($aTeamNextLevel['train'] ?? null) || !empty(json_decode(($aTeamNextLevel['train_level'] ?? null), true))) {
                //团队全部人数
                $tallTeam = $teamDivide->getTeamAllUserGroupByLevel($aMemberInfo['uid'] ?? $uid);
            }


            $allLevelName = TeamMemberVdc::where(['status' => 1])->column('name', 'level');

            if (!empty($aTeamNextLevel['recommend_member_number'])) {
                $allShopMemberNumber = $teamDivide->getNextDirectLinkUserGroupByLevel($aMemberInfo['uid'] ?? $uid, -1);
            }
            $aMemberInfo['allTeamNumber'] = $allTeam['allUser']['count'] ?? 0;

            $aMemberInfo['allTeamSalesPrice'] = $aMemberInfo['team_sales_price'] ?? 0;

            //直推要求
            $teamAimsCount = 0;

            if (!empty(json_decode($aTeamNextLevel['recommend_level'] ?? [], true))) {
                $teamRecommendLevel = json_decode($aTeamNextLevel['recommend_level'], true);
                $teamRecommendNumber = json_decode($aTeamNextLevel['recommend_number'], true);
                foreach ($teamRecommendLevel as $key => $value) {
                    $teamAims[$teamAimsCount]['title'] = '直接招募' . $allLevelName[$value];
                    $teamAims[$teamAimsCount]['aimsNumber'] = $teamRecommendNumber[$key];
                    $teamAims[$teamAimsCount]['nowNumber'] = $tdirectTeam['userLevel'][$value]['count'] ?? 0;
                    $teamAimsCount++;

                }
            } else {
                if (!empty($aTeamNextLevel['recommend'] ?? [])) {
                    $teamAims[0]['title'] = '直接招募' . $aTeamNextLevel['recommend']['name'];
                    $teamAims[0]['aimsNumber'] = $aTeamNextLevel['recommend_number'];
                    $teamAims[0]['nowNumber'] = $tdirectTeam['userLevel'][$aTeamNextLevel['recommend_level']]['count'] ?? 0;
                }
            }

            if (!empty(json_decode($aTeamNextLevel['train_level'] ?? [], true))) {
                $teamTrainLevel = json_decode($aTeamNextLevel['train_level'], true);
                $teamTrainNumber = json_decode($aTeamNextLevel['train_number'], true);
                foreach ($teamTrainLevel as $key => $value) {
                    $teamAims[$teamAimsCount]['title'] = '团队招募' . $allLevelName[$value];
                    $teamAims[$teamAimsCount]['aimsNumber'] = $teamTrainNumber[$key];
                    $teamAims[$teamAimsCount]['nowNumber'] = $tallTeam['userLevel'][$value]['count'] ?? 0;
                    $teamAimsCount++;
                }
            }

            if (!empty($aTeamNextLevel['train'] ?? [])) {
                $teamAims[count($teamAims)]['title'] = '团队招募' . $aTeamNextLevel['train']['name'];
                $teamAims[count($teamAims)]['aimsNumber'] = $aTeamNextLevel['train_number'];
                $teamAims[count($teamAims)]['nowNumber'] = $tallTeam['userLevel'][$aTeamNextLevel['recommend_level']]['count'] ?? 0;
            }

            if (!empty($aTeamNextLevel['recommend_member_number'] ?? [])) {
                $rNumber = count($teamAims);
                $teamAims[$rNumber]['title'] = '直接招募商城会员';
                $teamAims[$rNumber]['aimsNumber'] = $aTeamNextLevel['recommend_member_number'];
                $teamAims[$rNumber]['nowNumber'] = $allShopMemberNumber['allUser']['count'] ?? 0;
            }
            $tNumber = count($teamAims);

            $teamAims[$tNumber]['title'] = $tOrderTeamType[2];
            $teamAims[$tNumber]['aimsNumber'] = $aTeamNextLevel['sales_price'];
            $teamAims[$tNumber]['nowNumber'] = !empty($aMemberInfo['allTeamSalesPrice'] ?? 0) ? priceFormat($aMemberInfo['allTeamSalesPrice']) : ((!empty($userInfo['team_sales_price'] ?? 0)) ? priceFormat($userInfo['team_sales_price'] ?? 0) : 0);
        } else {
            $teamAims[0]['title'] = '您已经是最高等级';
            $teamAims[0]['aimsNumber'] = 100;
            $teamAims[0]['nowNumber'] = 100;
        }

        return $teamAims ?? [];
    }

    /**
     * @title  我的直推团队
     * @return mixed
     * @throws \Exception
     */
    public function myDirectTeam()
    {
        $uid = Request::param('uid');
        $divide = (new Divide());
        $member = (new MemberModel());
        $aMemberInfo = $member->where(['uid' => $uid, 'status' => 1])->field('uid,user_phone,member_card,level,type,link_superior_user,child_team_code,team_code,parent_team')->findOrEmpty()->toArray();
        $aUserLevel = $aMemberInfo['level'] ?? 0;
        if (empty($aUserLevel)) {
            throw new ServiceException(['msg' => '非会员无法查看哦~~']);
        }
        $finally['myInfo'] = $aMemberInfo;

        //直推人数
        $directTeam = $divide->getNextDirectLinkUserGroupByLevel($aMemberInfo['uid']);
        $finally['directAllUserList'] = [];
        $finally['directAllUserLevelNumber'] = [];
        $finally['todayNewNumber'] = [];
        $finally['MonthNewNumber'] = [];

        if (!empty($directTeam)) {
            $directUser = $directTeam['allUser']['list'];
            $dUid = $directTeam['allUser']['onlyUidList'];

            //查找每个人的订单总额
//            $map[] = ['link_uid','in',$dUid];
//            $map[] = ['arrival_status','in',[1,2,4]];
//            $orderTotalPriceSql = DivideModel::where($map)->field('total_price,link_uid as uid')->group('order_sn')->buildSql();
//            $orderTotalPrice = Db::table($orderTotalPriceSql ." a")->field('sum(total_price) as total_price,uid')->select()->toArray();
//
//            //查找每个人之前的信息然后补齐旧系统的业绩(本人+本人的直推业绩)
//            $userOldInfo = User::where(['uid'=>$dUid,'status'=>1])->column('old_update_team_performance','uid');
//            if(!empty($userOldInfo) && !empty($directUser)){
//                foreach ($directUser as $key => $value) {
//                    if(!isset($directUser[$key]['old_update_team_performance'])){
//                        $directUser[$key]['old_update_team_performance'] = 0;
//                    }
//                    foreach ($userOldInfo as $oldKey => $oldValue) {
//                        if($value['uid'] == $oldKey){
//                            $directUser[$key]['old_update_team_performance'] = $oldValue;
//                        }
//                    }
//                }
//            }
            $orderTotalPrice = User::where(['uid' => $dUid, 'status' => 1])->field('uid,team_performance as total_price')->select()->toArray();

            foreach ($directUser as $key => $value) {
                //隐藏手机号码中间四位
                if (!empty($value['user_phone'])) {
                    $directUser[$key]['user_phone'] = encryptPhone($value['user_phone']);
                }
                $directUser[$key]['user_join_time'] = $value['create_time'];
                $directUser[$key]['user_order_price'] = null;
                foreach ($orderTotalPrice as $oKey => $oValue) {
                    if ($oValue['uid'] == $value['uid']) {
                        $directUser[$key]['user_order_price'] = (string)$oValue['total_price'] >= 0 ? $oValue['total_price'] : 0;
//                        $directUser[$key]['user_order_price'] = $oValue['total_price'] + ($value['old_update_team_performance'] ?? 0);
                    }
                }
                if (!isset($directAllUserLevelNumber[$value['level']])) {
                    $directAllUserLevelNumber[$value['level']] = [];
                    $directAllUserLevelNumber[$value['level']]['number'] = 0;
                }
                $directAllUserLevelNumber[$value['level']]['level'] = $value['level'];
                $directAllUserLevelNumber[$value['level']]['number'] += 1;

            }
            //$memberTitle = [1=>'DT','2'=>'SV',3=>'VIP'];
            $memberTitle = MemberVdc::where(['status' => 1])->order('level asc')->column('name', 'level');
            //补齐没有数据的等级
            if (!empty($directAllUserLevelNumber)) {
                $directAllLevel = array_keys($directAllUserLevelNumber);
                foreach ($memberTitle as $key => $value) {
                    if (!in_array($key, $directAllLevel) && $key > intval($aMemberInfo['level'])) {
                        $directAllUserLevelNumber[$key]['level'] = $key;
                        $directAllUserLevelNumber[$key]['number'] = 0;
                    }
                }
//                //剔除自己等级的
//                unset($directAllUserLevelNumber[$aMemberInfo['level']]);
                krsort($directAllUserLevelNumber);
            }
            $finally['directAllUserLevelNumber'] = array_values($directAllUserLevelNumber ?? []);

            //汇总每周每月的新会员
            $thisMonthStart = date('Y-m-01', strtotime(date("Y-m-d"))) . ' 00:00:00';
            $thisMonthEnd = date('Y-m-d', strtotime("$thisMonthStart +1 month -1 day")) . ' 23:59:59';
            $todayNumber = [];
            $MonthNumber = [];

            foreach ($directTeam['userLevel'] as $key => $value) {
                if (isset($memberTitle[$key])) {
                    if (!isset($todayNumber[$key])) {
                        $todayNumber[$key]['title'] = $memberTitle[$key];
                        $todayNumber[$key]['number'] = 0;
                    }
                    if (!isset($MonthNumber[$key])) {
                        $MonthNumber[$key]['title'] = $memberTitle[$key];
                        $MonthNumber[$key]['number'] = 0;
                    }

                    foreach ($value['list'] as $lKey => $lValue) {
                        if (substr($lValue['create_time'], 0, 10) == date('Y-m-d')) {
                            $todayNumber[$key]['number'] += 1;
                        }
                        if ((strtotime($lValue['create_time']) >= strtotime($thisMonthStart)) && (strtotime($lValue['create_time']) <= strtotime($thisMonthEnd))) {
                            $MonthNumber[$key]['number'] += 1;
                        }
                    }
                }


            }
            //补齐没有数据的等级
            if (!empty($todayNumber)) {
                if (!empty($todayNumber)) {
                    $todayLevel = array_keys($todayNumber);
                    foreach ($memberTitle as $key => $value) {
                        if (!in_array($key, $todayLevel) && $key > intval($aMemberInfo['level'])) {
                            $todayNumber[$key]['title'] = $value;
                            $todayNumber[$key]['number'] = 0;
                        }
                    }
//                //剔除自己等级的
                    unset($todayNumber[$aMemberInfo['level']]);
                    krsort($todayNumber);
                }
            }
            if (!empty($MonthNumber)) {
                if (!empty($MonthNumber)) {
                    $monthLevel = array_keys($MonthNumber);
                    foreach ($memberTitle as $key => $value) {
                        if (!in_array($key, $monthLevel) && $key > intval($aMemberInfo['level'])) {
                            $MonthNumber[$key]['title'] = $value;
                            $MonthNumber[$key]['number'] = 0;
                        }
                    }
//                //剔除自己等级的
                    unset($MonthNumber[$aMemberInfo['level']]);
                    krsort($MonthNumber);
                }
            }

            $finally['directAllUserList'] = $directUser;
            $finally['directAllUserListNumber'] = count($directUser);
            $finally['todayNewNumber'] = array_values($todayNumber);
            $finally['MonthNewNumber'] = array_values($MonthNumber);
        }

        //团队全部人数
        $allTeam = $divide->getTeamAllUserGroupByLevel($aMemberInfo['uid']);
        $finally['TeamAllUserNumber'] = $allTeam['allUser']['count'] ?? 0;
        return $finally ?? [];
    }

    /**
     * @title  我的全部团队(数量及人数等级汇总)
     * @return mixed
     * @throws \Exception
     */
    public function myAllTeamSummary()
    {
        $data = $this->requestData;
        $uid = $data['uid'];
        $searType = $data['searType'] ?? 1;
        $page = $data['page'] ?? 0;
        $needList = $data['needList'] ?? false;
        $bigDataIdAgain = 14763;
        $bigDataIdEnd = 162037;
        $divide = (new Divide());
        $member = (new MemberModel());
        $aMemberInfo = $member->with(['pUser', 'userInfo'])->where(['uid' => $uid, 'status' => 1])->field('id,uid,user_phone,member_card,level,type,link_superior_user,child_team_code,team_code,parent_team,create_time')->findOrEmpty()->toArray();
        if (!empty($aMemberInfo['user_phone'])) {
            $aMemberInfo['user_phone'] = encryptPhone($aMemberInfo['user_phone']);
        }
        $aUserLevel = $aMemberInfo['level'] ?? 0;
        if (empty($aUserLevel)) {
            throw new ServiceException(['msg' => '非会员无法查看哦~~']);
        }
        $aMemberInfo['vip_level'] = $aUserLevel;
        $finally['myInfo'] = $aMemberInfo;

        //总基数加入缓存
        $cacheKey = $uid . $searType;
        $cacheList = cache($cacheKey);
        if (!empty($data['clearCache'])) {
            $cacheList = [];
        }

//        //团队全部人数
//        $allTeam = $divide->getTeamAllUserGroupByLevel($aMemberInfo['uid']);
        switch ($searType) {
            case 1:
                //查找团队全部人
//                if($aMemberInfo['id'] >= $bigDataIdAgain && $aMemberInfo['id'] <= $bigDataIdEnd){
//                    $requestData['uid'] = $aMemberInfo['uid'];
//                    $requestData['topUid'] = $aMemberInfo['uid'];
//                    $requestData['page'] = $page ?? 1;
//                    $requestData['mapType'] =  1;
//                    $requestData['allowLevel'] = 1;
//                    $allTeam = $this->getSortTeam($requestData);
//                }else{
//                    $allTeam = !empty($cacheList) ? $cacheList : $divide->getTeamAllUserGroupByLevel($aMemberInfo['uid'], '', ['beginId' => $bigDataIdAgain, 'endId' => $bigDataIdEnd]);
//                }

//                $allTeam = !empty($cacheList) ? $cacheList : $divide->getTeamAllUserGroupByLevel($aMemberInfo['uid'], '', ['searType' => $searType, 'maxLevel' => $aMemberInfo['level'], 'returnFull' => true]);
                if ($aUserLevel == 3) {
                    if (!empty($cacheList)) {
                        $allTeam = $cacheList;
                    } else {
                        $minLevelLinkUserList = User::where(['link_superior_user' => $uid, 'status' => 1])->field('uid,phone as user_phone,vip_level as level,link_superior_user,status,create_time,create_time as upgrade_time')->select()->each(function($item){
                            $item['team_level'] = 2;
                            $item['upgrade_time'] = timeToDateFormat($item['upgrade_time']);
                        })->toArray();
                        if (!empty($minLevelLinkUserList)) {
                            foreach ($minLevelLinkUserList as $key => $value) {
                                $minLevelLinkUserList[$key]['create_time'] = strtotime($value['create_time']);
                            }
                        }
                        $allTeam['allUser']['list'] = $minLevelLinkUserList ?? [];
                        $allTeam['allUser']['onlyUidList'] = [];
                        $allTeam['allUser']['count'] = 0;
                        if (!empty($minLevelLinkUserList)) {
                            $allTeam['allUser']['onlyUidList'] = array_column($minLevelLinkUserList, 'uid');
                            $allTeam['allUser']['count'] = count($minLevelLinkUserList) ?? 0;
                        }

                    }
                }else{
                    $allTeam = !empty($cacheList) ? $cacheList : $divide->getTeamAllUserGroupByLevel($aMemberInfo['uid'], 1, ['searType' => $searType, 'maxLevel' => $aMemberInfo['level']]);
                }

                break;
            case 3:
                //查找TDT
//                if($aMemberInfo['id'] >= $bigDataIdAgain && $aMemberInfo['id'] <= $bigDataIdEnd){
//                    $requestData['uid'] = $aMemberInfo['uid'];
//                    $allTeam = $this->getTDT($requestData);
//                }else{
//                    $allTeam = !empty($cacheList) ? $cacheList : $divide->getTeamAllUserGroupByLevel($aMemberInfo['uid'], '', ['beginId' => $bigDataIdAgain, 'endId' => $bigDataIdEnd]);
//                }
                $allTeam = !empty($cacheList) ? $cacheList : $divide->getTeamAllUserGroupByLevel($aMemberInfo['uid'], '', ['searType' => $searType, 'maxLevel' => $aMemberInfo['level']]);
                break;
            case 2:
                $allTeam = !empty($cacheList) ? $cacheList : $divide->getNextDirectLinkUserGroupByLevel($aMemberInfo['uid'], $aMemberInfo['level']);
                break;
        }

        $finally['TeamAllUserLevelNumber'] = [];
        $finally['TeamAllUserNumber'] = 0;
        $todayNumber = [];
        $MonthNumber = [];
        $AllNumber = [];
        $allDetailUserList = [];

        if (!empty($allTeam)) {
            //只有不在限定区间的数据才加入缓存,因为是全查的
//            if($aMemberInfo['id'] < $bigDataIdAgain && $aMemberInfo['id'] > $bigDataIdEnd){
            //加入缓存
            cache($cacheKey, $allTeam, 1800);
//            }


            $allUserUid = $allTeam['allUser']['onlyUidList'];
            $allUser = $allTeam['allUser']['list'];

            switch ($searType) {
                case 1:
                    $removeTeam = [];
                    foreach ($allUser as $key => $value) {
//                        if ($value['level'] <= $aMemberInfo['level'] || in_array($value['link_superior_user'], $removeTeam)) {
//                            $removeTeam[] = $value['uid'];
//                            unset($allUser[$key]);
//                        }
                        if(!empty($value['level'])){
                            if ($value['level'] <= $aMemberInfo['level'] || in_array($value['link_superior_user'], $removeTeam)) {
                                $removeTeam[] = $value['uid'];
                                unset($allUser[$key]);
                            }
                        }else{
                            if ($aMemberInfo['level'] != 3 || in_array($value['link_superior_user'], $removeTeam)) {
                                $removeTeam[] = $value['uid'];
                                unset($allUser[$key]);
                            }
                        }
                    }
                    break;
                case 2:
                    foreach ($allUser as $key => $value) {
                        $allUser[$key]['team_level'] = 2;
                        $allUser[$key]['create_time'] = strtotime($value['create_time']);
                        if ($allUser[$key]['team_level'] != 2 || $allUser[$key]['level'] != $aMemberInfo['level']) {
                            unset($allUser[$key]);
                        }
                    }
                    break;
                case 3:
                    foreach ($allUser as $key => $value) {
                        if ($aMemberInfo['level'] >= 2) {
                            if ($value['team_level'] != 3 || $value['level'] != $aMemberInfo['level']) {
                                unset($allUser[$key]);
                            }
                        } else {
                            if ($value['team_level'] <= 2 || $value['level'] != $aMemberInfo['level']) {
                                unset($allUser[$key]);
                            }
                        }

                    }
                    break;
            }

            //本月的开始和结束
            $thisMonthStart = date('Y-m-01', strtotime(date("Y-m-d"))) . ' 00:00:00';
            $thisMonthEnd = date('Y-m-d', strtotime("$thisMonthStart +1 month -1 day")) . ' 23:59:59';
            //先获取每个层级现有的人员情况,按照层级分组,然后在补齐每个分组中缺失的等级
            foreach ($allUser as $key => $value) {
                if (!isset($AllUserLevelNumber[$value['team_level']][$value['level']])) {
                    $AllUserLevelNumber[$value['team_level']][$value['level']] = [];
                    $AllUserLevelNumber[$value['team_level']][$value['level']]['number'] = 0;
                }
                $AllUserLevelNumber[$value['team_level']][$value['level']]['level'] = $value['level'];
                $AllUserLevelNumber[$value['team_level']][$value['level']]['type'] = ($value['team_level'] <= 2 ? 1 : 2);
                $AllUserLevelNumber[$value['team_level']][$value['level']]['number'] += 1;
                $AllUserLevelNumber[$value['team_level']][$value['level']]['list'][] = $value;
            }
            $memberTitle = MemberVdc::where(['status' => 1])->order('level asc')->column('name', 'level');
            $memberTitle[0] = '普通用户';
            //补齐每个层级分组中缺失的等级
            if (!empty($AllUserLevelNumber)) {
                foreach ($AllUserLevelNumber as $key => $value) {
                    foreach ($value as $cKey => $cValue) {
                        $allUserLevel = array_keys($value);
                        foreach ($memberTitle as $dKey => $dValue) {
                            if (!in_array($dKey, $allUserLevel) && $dKey > intval($aMemberInfo['level'])) {
                                $AllUserLevelNumber[$key][$dKey]['number'] = 0;
                                $AllUserLevelNumber[$key][$dKey]['level'] = $dKey;
                                $AllUserLevelNumber[$key][$dKey]['type'] = ($key <= 2 ? 1 : 2);
                                $AllUserLevelNumber[$key][$dKey]['list'] = [];
                            }
                        }
                    }
                }

                //键名归零
                foreach ($AllUserLevelNumber as $key => $value) {
                    $AllUserLevelNumber[$key] = array_values($value);
                }

                //计算每个等级的归属类型 1为直推 2为团队
                $type = [1, 2];
                if($aMemberInfo['level'] == 3) {
                    $type = [1];
                }
                foreach ($AllUserLevelNumber as $key => $value) {
                    $allType = array_unique(array_column($value, 'type'));
                    $count = count($cValue);
                    foreach ($value as $cKey => $cValue) {
                        foreach ($type as $dKey => $dValue) {
                            if (!in_array($dValue, $allType)) {
                                $AllUserLevelNumber[$key][$count]['number'] = 0;
                                $AllUserLevelNumber[$key][$count]['level'] = $cValue['level'];
                                $AllUserLevelNumber[$key][$count]['type'] = $dValue;
                                $AllUserLevelNumber[$key][$count]['list'] = [];
                                $count++;
                            }
                        }
                    }
                }

                //按照归属类型排序
                foreach ($AllUserLevelNumber as $key => $value) {
                    array_multisort(array_column($value, 'type'), SORT_ASC, $AllUserLevelNumber[$key]);
                }

                //按照特定键(等级+归属类型拼起来),累加每个等级对应归属类型的人数
                foreach ($AllUserLevelNumber as $key => $value) {
                    foreach ($value as $cKey => $cValue) {
                        if (!empty($finallyAllUserLevelNumbers[$cValue['level'] . '-' . $cValue['type']])) {
                            $finallyAllUserLevelNumbers[$cValue['level'] . '-' . $cValue['type']]['number'] += $cValue['number'];
                            $finallyAllUserLevelNumbers[$cValue['level'] . '-' . $cValue['type']]['list'] = array_merge_recursive($finallyAllUserLevelNumbers[$cValue['level'] . '-' . $cValue['type']]['list'], $cValue['list']);
                        } else {
                            $finallyAllUserLevelNumbers[$cValue['level'] . '-' . $cValue['type']] = $cValue;
                        }
                    }
                }

                //本来的数组键名是等级-团队类型,为了排序重新组装一下数组的键名,如果团队类型是直属则键名为等级拼5,团队全部键名为等级拼0,然后变成数字类型,按照键名降序排序
                $finallyAllUserLevelNumbersSort = [];
                if (!empty($finallyAllUserLevelNumbers)) {
                    foreach ($finallyAllUserLevelNumbers as $key => $value) {
                        $levelAndTeamType = explode('-', $key);
                        $level = current($levelAndTeamType);
                        if (end($levelAndTeamType) == 1) {
                            $newKey = intval($level . '5');
                        } else {
                            $newKey = intval($level . '0');
                        }
                        $value['specificType'] = 't' . $value['level'] . $value['type'];
                        $finallyAllUserLevelNumbersSort[$newKey] = $value;
                    }
                    krsort($finallyAllUserLevelNumbersSort);
                }
//                array_multisort(array_column($finallyAllUserLevelNumbers,'level'), SORT_DESC, $finallyAllUserLevelNumbers);
            }

            //统计当日和当月的人员数据
            foreach ($allUser as $key => $value) {
                $level = $value['level'];
                if (isset($memberTitle[$level])) {
                    if (!isset($AllNumber[$level])) {
                        $AllNumber[$level]['title'] = $memberTitle[$level];
                        $AllNumber[$level]['number'] = 0;
                        $AllNumber[$level]['list'] = [];
                        $AllNumber[$level]['specificType'] = 'a' . $level;
                    }
                    if (!isset($todayNumber[$level])) {
                        $todayNumber[$level]['title'] = $memberTitle[$level];
                        $todayNumber[$level]['number'] = 0;
                        $todayNumber[$level]['list'] = [];
                        $todayNumber[$level]['specificType'] = 'd' . $level;
                    }
                    if (!isset($MonthNumber[$level])) {
                        $MonthNumber[$level]['title'] = $memberTitle[$level];
                        $MonthNumber[$level]['number'] = 0;
                        $MonthNumber[$level]['list'] = [];
                        $MonthNumber[$level]['specificType'] = 'm' . $level;
                    }
                    //统计全部
                    $AllNumber[$level]['number'] += 1;
                    $AllNumber[$level]['list'][] = $value;
                    $AllNumber[$level]['specificType'] = 'a' . $level;
                    //统计当天
                    if (is_numeric($value['create_time'])) {
                        $value['create_time'] = timeToDateFormat($value['create_time']);
                    }
                    if (is_numeric($value['upgrade_time'])) {
                        $value['upgrade_time'] = timeToDateFormat($value['upgrade_time']);
                    }
                    if (substr($value['upgrade_time'], 0, 10) == date('Y-m-d')) {
                        $todayNumber[$level]['number'] += 1;
                        $todayNumber[$level]['list'][] = $value;
                        $todayNumber[$level]['specificType'] = 'd' . $level;
                    }
                    //统计当月
                    if ((strtotime($value['upgrade_time']) >= strtotime($thisMonthStart)) && (strtotime($value['upgrade_time']) <= strtotime($thisMonthEnd))) {
                        $MonthNumber[$level]['number'] += 1;
                        $MonthNumber[$level]['list'][] = $value;
                        $MonthNumber[$level]['specificType'] = 'm' . $level;
                    }
                }
            }
            //补齐没有数据的等级
            if (!empty($AllNumber)) {
                if (!empty($AllNumber) && $searType == 1) {
                    $allLevel = array_keys($AllNumber);
                    foreach ($memberTitle as $key => $value) {
                        if (!in_array($key, $allLevel) && $key > intval($aMemberInfo['level'])) {
                            $AllNumber[$key]['title'] = $value;
                            $AllNumber[$key]['number'] = 0;
                            $AllNumber[$key]['list'] = [];
                            $AllNumber[$key]['specificType'] = 'a' . $value;
                        }
                    }

//                //剔除自己等级的
                    unset($AllNumber[$aMemberInfo['level']]);
                    krsort($AllNumber);

                }
            }

            //补齐没有数据的等级
            if (!empty($todayNumber)) {
                if (!empty($todayNumber) && $searType == 1) {
                    $todayLevel = array_keys($todayNumber);
                    foreach ($memberTitle as $key => $value) {
                        if (!in_array($key, $todayLevel) && $key > intval($aMemberInfo['level'])) {
                            $todayNumber[$key]['title'] = $value;
                            $todayNumber[$key]['number'] = 0;
                            $todayNumber[$key]['list'] = [];
                            $todayNumber[$key]['specificType'] = 'd' . $value;
                        }
                    }

//                //剔除自己等级的
                    unset($todayNumber[$aMemberInfo['level']]);
                    krsort($todayNumber);

                }
            }

            if (!empty($MonthNumber)) {
                if (!empty($MonthNumber) && $searType == 1) {
                    $monthLevel = array_keys($MonthNumber);
                    foreach ($memberTitle as $key => $value) {
                        if (!in_array($key, $monthLevel) && $key > intval($aMemberInfo['level'])) {
                            $MonthNumber[$key]['title'] = $value;
                            $MonthNumber[$key]['number'] = 0;
                            $MonthNumber[$key]['list'] = [];
                            $MonthNumber[$key]['specificType'] = 'm' . $value;
                        }
                    }
//                //剔除自己等级的
                    unset($MonthNumber[$aMemberInfo['level']]);
                    krsort($MonthNumber);
                }
            }
            if (!empty($finally['myInfo'])) {
                $finally['myInfo']['vip_name'] = $memberTitle[$finally['myInfo']['level']];
                if (!empty($finally['myInfo']['pUser'])) {
                    $finally['myInfo']['pUser']['vip_name'] = $memberTitle[$finally['myInfo']['pUser']['vip_level']];
                }
            }

            //如果不需要具体用户列表不返回该字段,需要还需要组装一下返回一个全部的明细列表
            if (empty($needList)) {
                if (!empty($AllNumber)) {
                    foreach ($AllNumber as $key => $value) {
                        unset($AllNumber[$key]['list']);
                    }
                }
                if (!empty($MonthNumber)) {
                    foreach ($MonthNumber as $key => $value) {
                        unset($MonthNumber[$key]['list']);
                    }
                }
                if (!empty($todayNumber)) {
                    foreach ($todayNumber as $key => $value) {
                        unset($todayNumber[$key]['list']);
                    }
                }

                if (!empty($finallyAllUserLevelNumbersSort)) {
                    foreach ($finallyAllUserLevelNumbersSort as $key => $value) {
                        unset($finallyAllUserLevelNumbersSort[$key]['list']);
                    }
                }
            } else {
                $memberTitle[0] = '普通用户';
                //全部用户列表明细
                if (!empty($AllNumber)) {
                    foreach ($AllNumber as $key => $value) {
                        if (!empty(array_search($value['title'], $memberTitle)) || intval(array_search($value['title'], $memberTitle) == 0)) {
                            $allDetailUserList['a' . array_search($value['title'], $memberTitle)] = $value['list'];
                        }
                    }
                }

                //本月新增用户列表明细
                if (!empty($MonthNumber)) {
                    foreach ($MonthNumber as $key => $value) {
                        if (!empty(array_search($value['title'], $memberTitle)) || intval(array_search($value['title'], $memberTitle) == 0)) {
                            $allDetailUserList['m' . array_search($value['title'], $memberTitle)] = $value['list'];
                        }
                    }
                }
                //今日新增用户列表明细
                if (!empty($todayNumber)) {
                    foreach ($todayNumber as $key => $value) {
                        if (!empty(array_search($value['title'], $memberTitle)) || intval(array_search($value['title'], $memberTitle) == 0)) {
                            $allDetailUserList['d' . array_search($value['title'], $memberTitle)] = $value['list'];
                        }
                    }
                }

                //全部用户分等级和直/间推分组后的列表明细
                if (!empty($finallyAllUserLevelNumbersSort)) {
                    foreach ($finallyAllUserLevelNumbersSort as $key => $value) {
                        $allDetailUserList['t' . $value['level'] . $value['type']] = $value['list'];
                    }
                }
            }

//            if ($aMemberInfo['id'] >= $bigDataIdAgain && $aMemberInfo['id'] <= $bigDataIdEnd) {
//
//                if (!empty($finallyAllUserLevelNumbersSort)) {
//                    $DirectLinkUserNumber = $this->getDirectLinkUser(['uid' => $uid, 'level' => $aMemberInfo['level'],'needList'=>$needList ?? false]);
//                    if (!empty($DirectLinkUserNumber)) {
//                        foreach ($finallyAllUserLevelNumbersSort as $key => $value) {
//                            if ($value['type'] == 1) {
//                                if(!empty($needList)){
//                                    $allDetailUserList['t' . $value['level'] . $value['type']] = $DirectLinkUserNumber[$value['level']] ?? [];
//                                }else{
//                                    $finallyAllUserLevelNumbersSort[$key]['number'] = $DirectLinkUserNumber[$value['level']] ?? ($value['number'] ?? 0);
//                                }
//
//                            }else{
//                                $finallyAllUserLevelNumbersSort[$key]['number'] = '维护中';
//                            }
//                        }
//                    }
//                }
//            }


            $finally['todayNewNumber'] = array_values($todayNumber);
            $finally['MonthNewNumber'] = array_values($MonthNumber);
            $finally['AllNumber'] = array_values($AllNumber);
            $finally['TeamAllUserLevelNumber'] = array_values($finallyAllUserLevelNumbersSort ?? []);
//            if ($aMemberInfo['id'] >= $bigDataIdAgain && $aMemberInfo['id'] <= $bigDataIdEnd) {
//                if ($aMemberInfo['id'] == $bigDataIdAgain) {
//                    $finally['TeamAllUserNumber'] = 147525;
//                } else {
//                    $finally['TeamAllUserNumber'] = '维护中';
//                }
//
//            }else{
//                $finally['TeamAllUserNumber'] = count($allUserUid);
//            }

//            $finally['TeamAllUserNumber'] = !empty($allTeam['totalCount'] ?? 0) ? intval($allTeam['totalCount']) : count($allUserUid);
            $totalTeamNumber = 0;
            $teamAllNumberCacheKey = 'teamAllNumber-' . $uid;
            if (empty(cache($teamAllNumberCacheKey))) {
                $allMap[] = ['', 'exp', Db::raw("find_in_set('$uid',team_chain)")];
                $allMap[] = ['uid', '<>', $uid];
                $allMap[] = ['status', '=', 1];
                $totalTeamNumber = MemberModel::where($allMap)->count();

                if (!empty($totalTeamNumber)) {
                    cache($teamAllNumberCacheKey, $totalTeamNumber, 1800);
                }
            } else {
                $totalTeamNumber = cache($teamAllNumberCacheKey);
            }

            $finally['TeamAllUserNumber'] = $totalTeamNumber;
            if (!empty($needList)) {
                $finally['detailUserList'] = $allDetailUserList ?? [];
            }
//            $finally['TeamAllUserListNumber'] = count($allUser);

        }
        return $finally ?? [];
    }

    /**
     * @title  我的全部团队
     * @param array $aData
     * @return mixed
     * @throws \Exception
     */
    public function myAllTeam(array $aData = [])
    {
        if (!empty($aData)) {
            $data = $aData;
        } else {
            $data = $this->requestData;
        }
        $page = $data['page'] ?? 0;
        $searType = $data['searType'] ?? 1;
        $userList = $data['userList'] ?? [];
        $bigDataIdAgain = 14763;
        $bigDataIdEnd = 162037;
        if (empty($userList)) {
            $uid = $data['uid'] ?? '';
        } else {
            $uid = $userList['allUser']['uid'];
        }
        $divide = (new Divide());
        $member = (new MemberModel());
        if (!empty($uid)) {
            $aMemberInfo = $member->with(['pUser'])->where(['uid' => $uid, 'status' => 1])->field('id,uid,user_phone,member_card,level,type,link_superior_user,child_team_code,team_code,parent_team,create_time')->findOrEmpty()->toArray();
            $aUserLevel = $aMemberInfo['level'] ?? 0;
            if (!empty($aMemberInfo['user_phone'])) {
                $aMemberInfo['user_phone'] = encryptPhone($aMemberInfo['user_phone']);
            }
            if (empty($aUserLevel)) {
                throw new ServiceException(['msg' => '非会员无法查看哦~~']);
            }
            $finally['myInfo'] = $aMemberInfo;
        }

        //总基数加入缓存
        $cacheKey = $uid . $searType;
        $cacheList = cache($cacheKey);
        //清除缓存操作
        if (!empty($data['clearCache'])) {
            $cacheList = [];
        }
        if (!empty($userList)) {
            $allTeam = $userList;
        } else {
            switch ($searType) {
                case 1:
                    //查找团队全部人
//                    if ($aMemberInfo['id'] >= $bigDataIdAgain && $aMemberInfo['id'] <= $bigDataIdEnd) {
//                        $requestData['uid'] = $aMemberInfo['uid'];
//                        $requestData['topUid'] = $aMemberInfo['uid'];
//                        $requestData['page'] = $page ?? 1;
//                        $requestData['mapType'] =  1;
//                        $requestData['allowLevel'] = 1;
//                        $allTeam = $this->getSortTeam($requestData);
//                    }else{
//                        $allTeam = !empty($cacheList) ? $cacheList : $divide->getTeamAllUserGroupByLevel($aMemberInfo['uid'], ',u2.create_time desc', ['beginId' => $bigDataIdAgain, 'endId' => $bigDataIdEnd]);
//                    }
                    if ($aUserLevel == 3) {
                        if (!empty($cacheList)) {
                            $allTeam = $cacheList;
                        } else {
                            $minLevelLinkUserList = User::where(['link_superior_user' => $uid, 'status' => 1])->field('uid,phone as user_phone,vip_level as level,link_superior_user,status,create_time,create_time as upgrade_time')->select()->each(function($item){
                                $item['team_level'] = 2;
                                $item['upgrade_time'] = timeToDateFormat($item['upgrade_time']);
                            })->toArray();
                            if (!empty($minLevelLinkUserList)) {
                                foreach ($minLevelLinkUserList as $key => $value) {
                                    $minLevelLinkUserList[$key]['create_time'] = strtotime($value['create_time']);
                                }
                            }

                            $allTeam['allUser']['list'] = $minLevelLinkUserList ?? [];
                            $allTeam['allUser']['onlyUidList'] = [];
                            $allTeam['allUser']['count'] = 0;
                            if (!empty($minLevelLinkUserList)) {
                                $allTeam['allUser']['onlyUidList'] = array_column($minLevelLinkUserList, 'uid');
                                $allTeam['allUser']['count'] = count($minLevelLinkUserList) ?? 0;
                            }
                        }
                    }else{
                        $allTeam = !empty($cacheList) ? $cacheList : $divide->getTeamAllUserGroupByLevel($aMemberInfo['uid'], 1, ['searType' => $searType, 'maxLevel' => $aMemberInfo['level']]);
                    }
                    break;
                case 3:
                    //查找团队全部人
//                    if ($aMemberInfo['id'] >= $bigDataIdAgain && $aMemberInfo['id'] <= $bigDataIdEnd) {
//                        $requestData['uid'] = $aMemberInfo['uid'];
//                        $requestData['page'] = $page ?? 1;
//                        $allTeam = $this->getTDT($requestData);
//                    }else{
//                        $allTeam = !empty($cacheList) ? $cacheList : $divide->getTeamAllUserGroupByLevel($aMemberInfo['uid'], ',u2.create_time desc', ['beginId' => $bigDataIdAgain, 'endId' => $bigDataIdEnd]);
//                    }
                    $allTeam = !empty($cacheList) ? $cacheList : $divide->getTeamAllUserGroupByLevel($aMemberInfo['uid'], 1, ['searType' => $searType, 'maxLevel' => $aMemberInfo['level']]);
                    break;
                case 2:
                    //查找团队直推
                    $allTeam = !empty($cacheList) ? $cacheList : $divide->getNextDirectLinkUserGroupByLevel($aMemberInfo['uid']);
                    break;
            }
        }

        $todayNumber = [];
        $MonthNumber = [];

        if (!empty($allTeam)) {

            if (empty($userList)) {
                //只有不在限定区间的数据才加入缓存,因为是全查的
//                if($aMemberInfo['id'] < $bigDataIdAgain && $aMemberInfo['id'] > $bigDataIdEnd){
                //加入缓存
                cache($cacheKey, $allTeam, 1800);
//                }

            }

            $allUserUid = $allTeam['allUser']['onlyUidList'];
            $allUser = $allTeam['allUser']['list'];

            switch ($searType) {
                case 1:
                    $removeTeam = [];
                    foreach ($allUser as $key => $value) {
                        if (!empty($value['create_time'] ?? null) && is_numeric($value['create_time'])) {
                            $allUser[$key]['create_time'] = timeToDateFormat($value['create_time']);
                        }
                        if(!empty($value['level'])){
                            if ($value['level'] <= $aMemberInfo['level'] || in_array($value['link_superior_user'], $removeTeam)) {
                                $removeTeam[] = $value['uid'];
                                unset($allUser[$key]);
                            }
                        }else{
                            if ($aMemberInfo['level'] != 3 || in_array($value['link_superior_user'], $removeTeam)) {
                                $removeTeam[] = $value['uid'];
                                unset($allUser[$key]);
                            }
                        }

                    }
                    break;
                case 2:
                    foreach ($allUser as $key => $value) {
                        $allUser[$key]['team_level'] = 2;
//                        $allUser[$key]['create_time'] = strtotime($value['create_time']);
                        if ($allUser[$key]['team_level'] != 2 || $allUser[$key]['level'] != $aMemberInfo['level']) {
                            unset($allUser[$key]);
                        }
                    }
                    break;
                case 3:
                    foreach ($allUser as $key => $value) {
                        if ($value['team_level'] <= 2 || $value['level'] != $aMemberInfo['level']) {
                            unset($allUser[$key]);
                        }
                    }
                    break;
            }
            //强行按照新建时间排序,本来是按照团队每个层级排序
            if (!empty($allUser)) {
                array_multisort(array_column($allUser, 'create_time'), SORT_DESC, $allUser);
            }

            $finally['TeamAllUserList'] = [];

            //虚拟分页-------start
            //一页的页数
            $pageNumber = 10;
            $maxCount = count($allUser);
//            if (($aMemberInfo['id'] < $bigDataIdAgain || $aMemberInfo['id'] > $bigDataIdEnd) || $searType == 2) {
            if (!empty($page)) {
                $start = ($page - 1) * $pageNumber;
                $end = $start + ($pageNumber - 1);
                $allUser = array_slice($allUser, $start, $pageNumber);
                $allUserUid = array_column($allUser, 'uid');
            }
//            }

            //虚拟分页-------end

            array_unshift($allUserUid, $uid);
            //获取订单和分润缓存
            $childCacheKey = md5(implode($allUserUid, ','));
            $divideCacheKey = $childCacheKey . 'divide';
            $myselfCacheKey = $childCacheKey . 'myself';
            $divideCache = cache($divideCacheKey);
            $myselfCache = cache($myselfCacheKey);


            if (!empty($divideCache)) {
                $aDivide = $divideCache;
            } else {
                //查找每个人的订单总额
                $map[] = ['link_uid', 'in', $allUserUid];
                $map[] = ['arrival_status', 'in', [1, 2, 4]];
                $map[] = ['status', 'in', [1, 2]];
                $map[] = ['type', 'in', [1]];
                $aDivide = DivideModel::where($map)->field('real_divide_price,arrival_status,total_price,purchase_price,divide_type,link_uid,order_uid,create_time')->select()->toArray();

                //加入缓存
                cache($divideCacheKey, $aDivide, 600);
            }

            if (!empty($myselfCache)) {
                $myselfOrder = $myselfCache;
            } else {
                $mMap[] = ['uid', 'in', $allUserUid];
                $mMap[] = ['pay_status', '=', 2];
                $mMap[] = ['order_status', 'in', [2, 3, 4, 8]];
                $myselfOrder = Order::with(['goods' => function ($query) {
                    $query->where(['status' => 1]);
                }])->where($mMap)->field('order_sn,uid,real_pay_price,create_time')->select()->toArray();

                //加入缓存
                cache($myselfCacheKey, $myselfOrder, 600);
            }
//            //去除重复的订单
//            foreach ($aDivide as $key => $value) {
//                if(in_array($value['order_uid'],array_column($myselfOrder,'uid'))){
//                    unset($aDivide[$key]);
//                }
//            }
            //本月的开始和结束
            $thisMonthStart = date('Y-m-01', strtotime(date("Y-m-d"))) . ' 00:00:00';
            $thisMonthEnd = date('Y-m-d', strtotime("$thisMonthStart +1 month -1 day")) . ' 23:59:59';
            //上月的开始和结束
            $lastMonthStart = date('Y-m-01 00:00:00', strtotime('-1 month'));
            $lastMonthEnd = date("Y-m-d 23:59:59", strtotime(-date('d') . 'day'));

            $myselfOrderTotalPrice = [];
            $myselfOrderMonthPrice = [];
            $myselfOrderLastMonthPrice = [];
            foreach ($myselfOrder as $key => $value) {
                foreach ($value['goods'] as $gKey => $gValue) {
                    if (!isset($myselfOrderTotalPrice[$value['uid']])) {
                        $myselfOrderTotalPrice[$value['uid']] = 0;
                    }
                    if (!isset($myselfOrderMonthPrice[$value['uid']])) {
                        $myselfOrderMonthPrice[$value['uid']] = 0;
                    }
                    if (!isset($myselfOrderLastMonthPrice[$value['uid']])) {
                        $myselfOrderLastMonthPrice[$value['uid']] = 0;
                    }
                    $myselfOrderTotalPrice[$value['uid']] += $gValue['total_price'];
                    //统计本月
                    if ($value['create_time'] >= $thisMonthStart && $value['create_time'] <= $thisMonthEnd) {
                        $myselfOrderMonthPrice[$value['uid']] += $gValue['total_price'];
                    }
                    //统计上月
                    if ($value['create_time'] >= $lastMonthStart && $value['create_time'] <= $lastMonthEnd) {
                        $myselfOrderLastMonthPrice[$value['uid']] += $gValue['total_price'];
                    }

                }
            }

            $finally['myInfo']['user_order_price'] = 0;
            $finally['myInfo']['user_month_order_price'] = 0;
            $finally['myInfo']['user_last_month_order_price'] = 0;


            $indOrderPrice = [];
            $indWillInComePrice = [];
            $indAllOrder = [];
            $indOrderPriceMonth = [];
            $indOrderPriceLastMonth = [];
            $indWillInComePriceMonth = [];
            $indAllOrderMonth = [];
            $indWillInComePriceLastMonth = [];
            $indAllOrderLastMonth = [];
            //统计进货额(即享受价差的人)
            if (!empty($aDivide)) {
                foreach ($aDivide as $key => $value) {
                    if ($value['divide_type'] == 1) {
                        if (!isset($indOrderPrice[$value['link_uid']])) {
                            $indOrderPrice[$value['link_uid']] = 0;
                        }
                        if (!isset($indWillInComePrice[$value['link_uid']])) {
                            $indWillInComePrice[$value['link_uid']] = 0;
                        }
                        if (!isset($indAllOrder[$value['link_uid']])) {
                            $indAllOrder[$value['link_uid']] = 0;
                        }
                        if (!isset($indOrderPriceMonth[$value['link_uid']])) {
                            $indOrderPriceMonth[$value['link_uid']] = 0;
                        }
                        if (!isset($indOrderPriceLastMonth[$value['link_uid']])) {
                            $indOrderPriceLastMonth[$value['link_uid']] = 0;
                        }
                        if (!isset($indWillInComePriceMonth[$value['link_uid']])) {
                            $indWillInComePriceMonth[$value['link_uid']] = 0;
                        }
                        if (!isset($indAllOrderMonth[$value['link_uid']])) {
                            $indAllOrderMonth[$value['link_uid']] = 0;
                        }
                        if (!isset($indWillInComePriceLastMonth[$value['link_uid']])) {
                            $indWillInComePriceLastMonth[$value['link_uid']] = 0;
                        }
                        if (!isset($indAllOrderLastMonth[$value['link_uid']])) {
                            $indAllOrderLastMonth[$value['link_uid']] = 0;
                        }

                        $indOrderPrice[$value['link_uid']] += $value['purchase_price'];
                        $indWillInComePrice[$value['link_uid']] += $value['real_divide_price'];
                        $indAllOrder[$value['link_uid']] += 1;
                        //统计本月
                        if ($value['create_time'] >= $thisMonthStart && $value['create_time'] <= $thisMonthEnd) {
                            $indOrderPriceMonth[$value['link_uid']] += $value['purchase_price'];
                            $indWillInComePriceMonth[$value['link_uid']] += $value['real_divide_price'];
                            $indAllOrderMonth[$value['link_uid']] += 1;

                        }
                        //统计上月
                        if ($value['create_time'] >= $lastMonthStart && $value['create_time'] <= $lastMonthEnd) {
                            $indOrderPriceLastMonth[$value['link_uid']] += $value['purchase_price'];
                            $indWillInComePriceLastMonth[$value['link_uid']] += $value['real_divide_price'];
                            $indAllOrderLastMonth[$value['link_uid']] += 1;

                        }

                    }
                }
            }

            if (!empty($myselfOrderTotalPrice)) {
                //个人进货总额为个人购买+分润中的成本价,先补齐个人购买的订单金额
                foreach ($myselfOrderTotalPrice as $key => $value) {
                    if (empty($indOrderPrice[$key])) {
                        $indOrderPrice[$key] = 0;
                    }
                    if (empty($indOrderPriceMonth[$key])) {
                        $indOrderPriceMonth[$key] = 0;
                    }
                    if (empty($indOrderPriceLastMonth[$key])) {
                        $indOrderPriceLastMonth[$key] = 0;
                    }
                }

                if (!empty($indOrderPrice)) {
                    //再用分润中的累计成本价加上个人购买的订单金额
                    foreach ($indOrderPrice as $key => $value) {
                        $indOrderPrice[$key] += ($myselfOrderTotalPrice[$key] ?? 0);
                    }
                }

                if (!empty($indOrderPriceMonth)) {
                    foreach ($indOrderPriceMonth as $key => $value) {
                        $indOrderPriceMonth[$key] += ($myselfOrderMonthPrice[$key] ?? 0);
                    }
                }

                if (!empty($indOrderPriceLastMonth)) {
                    foreach ($indOrderPriceLastMonth as $key => $value) {
                        $indOrderPriceLastMonth[$key] += ($myselfOrderLastMonthPrice[$key] ?? 0);
                    }
                }

                //剔除本人,仅为显示当前这个人的营业总额
                foreach ($indOrderPrice as $key => $value) {
                    $indOrderPrice[$key] = (string)$value;
                    if ($key == $uid) {
                        $finally['myInfo']['user_order_price'] = priceFormat((string)$value);
                        unset($indOrderPrice[$key]);
                    }
                }
                foreach ($indOrderPriceMonth as $key => $value) {
                    $indOrderPriceMonth[$key] = (string)$value;
                    if ($key == $uid) {
                        $finally['myInfo']['user_month_order_price'] = priceFormat((string)$value);
                        unset($indOrderPriceMonth[$key]);
                    }
                }

                foreach ($indOrderPriceLastMonth as $key => $value) {
                    $indOrderPriceLastMonth[$key] = (string)$value;
                    if ($key == $uid) {
                        $finally['myInfo']['user_last_month_order_price'] = priceFormat((string)$value);
                        unset($indOrderPriceLastMonth[$key]);
                    }
                }

            }


            $userInfo = User::with(['member', 'link'])->where(['uid' => $allUserUid, 'status' => 1])->select()->toArray();
            if (!empty($allUser)) {
                foreach ($allUser as $key => $value) {
                    if (is_numeric($value['create_time'])) {
                        $allUser[$key]['create_time'] = timeToDateFormat($value['create_time']);
                    }
                    //隐藏手机号码中间四位
                    if (!empty($value['user_phone'])) {
                        $allUser[$key]['user_phone'] = encryptPhone($value['user_phone']);
                    }
                    $allUser[$key]['user_order_price'] = priceFormat($indOrderPrice[$value['uid']] ?? 0);
                    $allUser[$key]['user_month_order_price'] = priceFormat($indOrderPriceMonth[$value['uid']] ?? 0);
                    $allUser[$key]['user_last_month_order_price'] = priceFormat($indOrderPriceLastMonth[$value['uid']] ?? 0);
                }
            }


            $memberTitle = MemberVdc::where(['status' => 1])->order('level asc')->column('name', 'level');
            $memberTitle[0] = 'VIP';
            if (!empty($finally['myInfo'])) {
                $finally['myInfo']['vip_name'] = $memberTitle[$finally['myInfo']['level']];
            }


            if (!empty($userInfo) && !empty($allUser)) {
                foreach ($allUser as $key => $value) {
                    foreach ($userInfo as $uKey => $uValue) {
                        if ($value['uid'] == $uValue['uid']) {
                            $allUser[$key]['user_name'] = $uValue['name'];
                            if (!empty($uValue['phone'])) {
                                $allUser[$key]['user_phone'] = encryptPhone($uValue['phone']);
                            } else {
                                $allUser[$key]['user_phone'] = '暂无绑定手机';
                            }
                            $allUser[$key]['user_avatarUrl'] = $uValue['avatarUrl'];
                            $allUser[$key]['user_join_time'] = $uValue['member']['create_time'];
                            if (empty($value['level'])) {
                                $allUser[$key]['user_join_time'] = $value['create_time'];
                            }
                            $allUser[$key]['vip_name'] = $memberTitle[$uValue['vip_level']];
                            if (!empty($uValue['link_user_phone'])) {
                                $allUser[$key]['link_user_phone'] = encryptPhone($uValue['link_user_phone']);
                            } else {
                                $allUser[$key]['link_user_phone'] = '用户暂未绑定手机号码';
                            }
                            $allUser[$key]['link_user_name'] = $uValue['link_user_name'];
                            $allUser[$key]['link_user_level'] = $uValue['link_user_level'];
                            $allUser[$key]['link_user_level_name'] = $memberTitle[($uValue['link_user_level'] ?? 0)];
                        }
                    }
                }
            }


            $finally['TeamAllUserList'] = $allUser ?? [];
            $finally['TeamAllUserListNumber'] = $maxCount;
            $finally['TeamAllUserListPageNumber'] = $pageNumber ?? 10;
            $finally['TeamAllUserPageNumber'] = ceil($maxCount / $pageNumber);
//            if ($aMemberInfo['id'] >= $bigDataIdAgain && $aMemberInfo['id'] <= $bigDataIdEnd) {
//                $finally['TeamAllUserPageNumber'] = 5000;
//            }else{
//                $finally['TeamAllUserPageNumber'] = ceil($maxCount / $pageNumber);
//            }


        }
        return $finally ?? [];
    }

    /**
     * @title  我的团队数据面板用户详细列表
     * @param array $data
     * @return array
     * @throws \Exception
     */
    public function allTeamSpecific(array $data = [])
    {
        $requestData = $this->requestData;
        $requestData['needList'] = true;
        $this->requestData = $requestData;
        $specificType = $data['specificType'];
        $MemberInfo = Member::where(['uid' => $requestData['uid'], 'status' => 1])->findOrEmpty()->toArray();
        $MemberId = null;
        if(!empty($MemberInfo)){
            $MemberId = $MemberInfo['id'];
            if($MemberInfo['level'] == 3 && in_array($requestData['specificType'],['m3','d3'])){
                $requestData['searType'] = 2;
                $data['searType'] = 2;
                $this->requestData = $requestData;
            }
        }

//        if ($MemberId >= 14763 & $MemberId <= 162037) {
//            if (in_array($specificType, ['t32', 't22'])) {
//                throw new ServiceException(['msg' => '后续将开放该数据的查询,感谢您的支持~']);
//            }
//        }
        $allDetailList = $this->myAllTeamSummary();
        if (empty($allDetailList) || empty($allDetailList['detailUserList'])) {
            return [];
        }
        $detailList = $allDetailList['detailUserList'];
        if (empty($specificType) || empty($detailList[$specificType])) {
            return [];
        }

        $uList = $detailList[$specificType];
        foreach ($uList as $key => $value) {
            if (!is_numeric($value['create_time'])) {
                $uList[$key]['create_time'] = strtotime($value['create_time']);
            }
        }
        $uidList = array_unique(array_filter(array_column($uList, 'uid')));
        $userList['allUser']['onlyUidList'] = $uidList;
        $userList['allUser']['list'] = $uList;
        $userList['allUser']['uid'] = $requestData['uid'];
        $list = $this->myAllTeam(['userList' => $userList, 'page' => $data['page'] ?? 1, 'searType' => $data['searType'] ?? 1]);
        return $list ?? [];
    }

    /**
     * @title  查找我的团队
     * @return mixed
     * @throws \Exception
     */
    public function searMyTeam()
    {
        $data = $this->requestData;
        $first = (new MemberModel())->searTeamUser($data);
        $list = $this->myAllTeam(['userList' => $first, 'page' => $data['page'] ?? 1, 'searType' => $data['searType'] ?? 1]);
        return $list ?? [];
    }

    /**
     * @title  获取TDT
     * @param array $data
     * @return mixed
     * @throws \Exception
     */
    public function getTDT(array $data)
    {
        $uid = $data['uid'];
        $page = $data['page'] ?? null;
        $pageNumber = $data['pageNumber'] ?? 10;
        $levelTwoList = Member::where(['link_superior_user' => $uid, 'level' => 1, 'status' => 1])->column('uid');

        $list = [];
        if (!empty($levelTwoList)) {
            $list = Member::where(['link_superior_user' => $levelTwoList, 'level' => 1, 'status' => 1])->field('uid,user_phone,child_team_code,parent_team,level,link_superior_user,type,status,create_time,upgrade_time')->when(!empty($page), function ($query) use ($pageNumber, $page) {
                $query->page($page, $pageNumber);
            })->select()->toArray();
        }
        if (!empty($list)) {
            foreach ($list as $key => $value) {
                $list[$key]['team_level'] = 3;
                if (!empty($value['create_time'])) {
                    $list[$key]['create_time'] = strtotime($value['create_time']);
                }
            }
        }
        $return['allUser']['list'] = $list ?? [];
        $return['allUser']['onlyUidList'] = !empty($list) ? array_unique(array_column($list, 'uid')) : [];
        $return['allUser']['count'] = !empty($list) ? count($return['allUser']['onlyUidList']) : 0;
        return $return;
    }

    /**
     * @title  特定人群查找全团人数时是从上往下找
     * @param array $data
     * @return mixed
     * @throws \Exception
     */
    public function getSortTeam(array $data)
    {
        $uid = $data['uid'];
        $page = $data['page'] ?? 1;
        $pageNumber = 20;
        $teamLevel = $data['teamLevel'] ?? 2;
        $topUid = $data['topUid'];
        $mapType = $data['mapType'] ?? null;
        $allowLevel = $data['allowLevel'] ?? null;

        $maxLevelCacheKey = 'maxLevel' . $topUid;
        $cacheKey = $topUid . '-' . $teamLevel;
        $cacheExpire = 1800;
        $maxLevelCacheExpire = 1800;
        $list = [];
        if (is_array($uid)) {
            $map[] = ['link_superior_user', 'in', $uid];
        } else {
            $map[] = ['link_superior_user', '=', $uid];
        }
        $map[] = ['status', '=', 1];
        if (!empty($mapType)) {
            switch ($mapType) {
                case 1:
                    $map[] = ['level', '>', $allowLevel];
                    break;
                case 2:
                    $map[] = ['level', '=', $allowLevel];
                    break;
                case 3:
                    $map[] = ['level', '=', $allowLevel];
                    break;
            }
        }

        $list = MemberModel::where($map)->field('uid,user_phone,child_team_code,parent_team,level,link_superior_user,type,status,create_time,upgrade_time')->page($page, $pageNumber)->select()->each(function ($item) use ($teamLevel) {
            $item['team_level'] = $teamLevel ?? 2;

        })->toArray();

        if (empty(cache($maxLevelCacheKey) ?? null)) {
            cache($maxLevelCacheKey, 2, $maxLevelCacheExpire);
        }

        if (empty($list)) {
            $nextData['uid'] = cache($cacheKey);
            if (!empty($nextData['uid'])) {
                $page = $page - intval(ceil(count($nextData['uid']) / $pageNumber));
                $nextData['page'] = $page;
                $nextData['topUid'] = $topUid;
//                $teamLevel = cache($maxLevelCacheKey) ?? 2;
                $teamLevel = explode('-', $cacheKey)[1] ?? 2;

                $teamLevel += 1;
                if (!empty($teamLevel)) {
                    $nextData['teamLevel'] = $teamLevel;
                }
                $nextData['mapType'] = $mapType;
                $nextData['allowLevel'] = $data['allowLevel'] ?? null;
                $returnData = $this->getSortTeamByPage($nextData);
                $list = $returnData['list'] ?? [];
                $teamLevel = $returnData['teamLevel'] ?? 2;
            }
        }

        $cacheKey = $topUid . '-' . $teamLevel;
        if (!empty($list)) {
            foreach ($list as $key => $value) {
                if (!empty($value['create_time'])) {
                    $list[$key]['create_time'] = strtotime($value['create_time']);
                }
            }
            $allUid = array_unique(array_column($list, 'uid'));
            if (!empty(cache($cacheKey))) {
                $newCache = array_unique(array_merge_recursive(cache($cacheKey), $allUid));
            } else {
                $newCache = $allUid;
            }
            if (count($list) < $pageNumber) {
                $maxLevel = cache($maxLevelCacheKey) ?? null;
                if (!empty($maxLevel)) {
                    cache($maxLevelCacheKey, $maxLevel + 1, $maxLevelCacheExpire);
                }

            }

            cache($cacheKey, $newCache, $cacheExpire);
        }

        $return['allUser']['list'] = $list ?? [];
        $return['allUser']['onlyUidList'] = !empty($list) ? array_unique(array_column($list, 'uid')) : [];
        $return['allUser']['count'] = !empty($list) ? count($return['allUser']['onlyUidList']) : 0;
        return $return;
    }

    /**
     * @title  递归查询
     * @param array $data
     * @return array
     * @throws \Exception
     */
    public function getSortTeamByPage(array $data)
    {
        $uid = $data['uid'];
        $page = $data['page'] ?? 1;
        $pageNumber = 20;
        $teamLevel = $data['teamLevel'] ?? 2;
        $topUid = $data['topUid'];
        $mapType = $data['mapType'] ?? null;
        $allowLevel = $data['allowLevel'] ?? null;

        $cacheKey = $topUid . '-' . $teamLevel;
        $maxLevelCacheKey = 'maxLevel' . $topUid;
        $cacheExpire = 1800;
        $maxLevelCacheExpire = 1800;

        if (is_array($uid)) {
            $map[] = ['link_superior_user', 'in', $uid];
        } else {
            $map[] = ['link_superior_user', '=', $uid];
        }
        $map[] = ['status', '=', 1];
        if (!empty($mapType)) {
            switch ($mapType) {
                case 1:
                    $map[] = ['level', '>', $allowLevel];
                    break;
                case 2:
                    $map[] = ['level', '=', $allowLevel];
                    break;
                case 3:
                    $map[] = ['level', '=', $allowLevel];
                    break;
            }
        }

        $list = [];
        $list = MemberModel::where($map)->field('uid,user_phone,child_team_code,parent_team,level,link_superior_user,type,status,create_time,upgrade_time')->page($page, $pageNumber)->select()->each(function ($item) use ($teamLevel) {
            $item['team_level'] = $teamLevel ?? 2;
        })->toArray();

        if (empty($list)) {
            $nextData['uid'] = cache($cacheKey);
            if (!empty($nextData['uid'])) {
                $page = $page - intval(ceil(count($nextData['uid']) / $pageNumber));

                $nextData['page'] = $page;
                $nextData['topUid'] = $topUid;
//                $teamLevel = cache($maxLevelCacheKey) ?? 2;
                $teamLevel = explode('-', $cacheKey)[1] ?? 2;
                $teamLevel += 1;
                if (!empty($teamLevel)) {
                    $nextData['teamLevel'] = $teamLevel;
                }
                $nextData['mapType'] = $mapType;
                $nextData['allowLevel'] = $data['allowLevel'] ?? null;
                $returnData = $this->getSortTeamByPage($nextData);
                $list = $returnData['list'] ?? [];
                $teamLevel = $returnData['teamLevel'] ?? 2;
            }
        }
        return ['list' => $list, 'teamLevel' => $teamLevel];
    }

    /**
     * @title  获取团队直推各等级人数
     * @param array $data
     * @return array
     * @throws \Exception
     */
    public function getDirectLinkUser(array $data)
    {
        $uid = $data['uid'];
        $maxLevel = $data['level'] ?? null;
        $needList = $data['needList'] ?? false;
        $map[] = ['link_superior_user', '=', $uid];
        $map[] = ['status', '=', 1];
        if (!empty($maxLevel)) {
            $map[] = ['level', '>', $maxLevel];
        }
        if (!empty($needList)) {
            $list = Member::where($map)->field('uid,user_phone as phone,child_team_code,parent_team,level,type,link_superior_user,status,create_time,upgrade_time')->select()->toArray();

            $info = [];
            if (!empty($list)) {
                foreach ($list as $key => $value) {
                    if (!empty($value['create_time'])) {
                        $value['create_time'] = strtotime($value['create_time']);
                    }
                    $value['team_level'] = 3;
                    $info[$value['level']][] = $value;
                }
            }

        } else {
            $list = Member::where($map)->field('level,count(*) as number')->group('level')->select()->toArray();
            $info = [];
            if (!empty($list)) {
                foreach ($list as $key => $value) {
                    $info[$value['level']] = $value['number'];
                }
            }
        }

        return $info ?? [];

    }

    /**
     * @title  我的同级直推团队
     * @return mixed
     * @throws \Exception
     */
    public function sameLevelDirectTeam()
    {
        $uid = Request::param('uid');
        $member = (new MemberModel());
        $aMemberInfo = $member->where(['uid' => $uid, 'status' => 1])->field('uid,user_phone,member_card,level,type,link_superior_user,child_team_code,team_code,parent_team')->findOrEmpty()->toArray();
        $aUserLevel = $aMemberInfo['level'] ?? 0;
        if (empty($aUserLevel)) {
            throw new ServiceException(['msg' => '非会员无法查看哦~~']);
        }
        $memberTitle = MemberVdc::where(['status' => 1])->order('level asc')->column('name', 'level');
        $aMemberInfo['vip_level'] = $aMemberInfo['level'];
        $aMemberInfo['vip_name'] = $memberTitle[$aMemberInfo['level']];
        $finally['myInfo'] = $aMemberInfo;

        $map[] = ['status', '=', 1];
        $map[] = ['link_superior_user', '=', $aMemberInfo['uid']];
        $map[] = ['level', '=', $aUserLevel];
        $directUser = MemberModel::with(['user'])->where($map)->withoutField('id,update_time')->withSum('linkOrder', 'total_price')->select()->each(function ($item) {
            //$item['user_order_price'] = $item['link_order_sum'];
            unset($item['link_order_sum']);
            //隐藏手机号码中间四位
            if (!empty($item['user_phone'])) {
                $item['user_phone'] = encryptPhone($item['user_phone']);
            }
            return $item;
        })->toArray();

        $allUserUid = array_column($directUser, 'uid');
        //获取订单和分润缓存
        $childCacheKey = md5(implode($allUserUid, ','));
        $divideCacheKey = $childCacheKey . 'divide-3dir';
        $myselfCacheKey = $childCacheKey . 'myself-3dir';
        $divideCache = cache($divideCacheKey);
        $myselfCache = cache($myselfCacheKey);


        if (!empty($divideCache)) {
            $aDivide = $divideCache;
        } else {
            //查找每个人的订单总额
            $oMap[] = ['link_uid', 'in', $allUserUid];
            $oMap[] = ['arrival_status', 'in', [1, 2, 4]];
            $oMap[] = ['status', 'in', [1, 2]];
            $oMap[] = ['type', 'in', [1]];
            $aDivide = DivideModel::where($oMap)->field('real_divide_price,arrival_status,total_price,purchase_price,divide_type,link_uid,order_uid,create_time')->select()->toArray();

            //加入缓存
            cache($divideCacheKey, $aDivide, 600);
        }

        if (!empty($myselfCache)) {
            $myselfOrder = $myselfCache;
        } else {
            $mMap[] = ['uid', 'in', $allUserUid];
            $mMap[] = ['pay_status', '=', 2];
            $mMap[] = ['order_status', 'in', [2, 3, 4, 8]];
            $myselfOrder = Order::with(['goods' => function ($query) {
                $query->where(['status' => 1]);
            }])->where($mMap)->field('order_sn,uid,real_pay_price')->select()->toArray();

            //加入缓存
            cache($myselfCacheKey, $myselfOrder, 600);
        }


        foreach ($myselfOrder as $key => $value) {
            foreach ($value['goods'] as $gKey => $gValue) {
                if (!isset($myselfOrderTotalPrice[$value['uid']])) {
                    $myselfOrderTotalPrice[$value['uid']] = 0;
                }
                $myselfOrderTotalPrice[$value['uid']] += $gValue['total_price'];
            }
        }

        //本月的开始和结束
        $thisMonthStart = date('Y-m-01', strtotime(date("Y-m-d"))) . ' 00:00:00';
        $thisMonthEnd = date('Y-m-d', strtotime("$thisMonthStart +1 month -1 day")) . ' 23:59:59';
        //统计进货额(即享受价差的人)
        foreach ($aDivide as $key => $value) {
            if ($value['divide_type'] == 1) {
                if (!isset($indOrderPrice[$value['link_uid']])) {
                    $indOrderPrice[$value['link_uid']] = 0;
                }
                if (!isset($indWillInComePrice[$value['link_uid']])) {
                    $indWillInComePrice[$value['link_uid']] = 0;
                }
                if (!isset($indAllOrder[$value['link_uid']])) {
                    $indAllOrder[$value['link_uid']] = 0;
                }
                if (!isset($indOrderPriceMonth[$value['link_uid']])) {
                    $indOrderPriceMonth[$value['link_uid']] = 0;
                }
                if (!isset($indWillInComePriceMonth[$value['link_uid']])) {
                    $indWillInComePriceMonth[$value['link_uid']] = 0;
                }
                if (!isset($indAllOrderMonth[$value['link_uid']])) {
                    $indAllOrderMonth[$value['link_uid']] = 0;
                }
                $indOrderPrice[$value['link_uid']] += $value['purchase_price'];
                $indWillInComePrice[$value['link_uid']] += $value['real_divide_price'];
                $indAllOrder[$value['link_uid']] += 1;
                //统计本月
                if ($value['create_time'] >= $thisMonthStart && $value['create_time'] <= $thisMonthEnd) {
                    $indOrderPriceMonth[$value['link_uid']] += $value['purchase_price'];
                    $indWillInComePriceMonth[$value['link_uid']] += $value['real_divide_price'];
                    $indAllOrderMonth[$value['link_uid']] += 1;
                }

            }
        }

        if (!empty($myselfOrderTotalPrice)) {
            //个人进货总额为个人购买+分润中的成本价,先补齐个人购买的订单金额
            foreach ($myselfOrderTotalPrice as $key => $value) {
                if (empty($indOrderPrice[$key])) {
                    $indOrderPrice[$key] = 0;
                }
                if (empty($indOrderPriceMonth[$key])) {
                    $indOrderPriceMonth[$key] = 0;
                }
            }
            if (!empty($indOrderPrice)) {
                //再用分润中的累计成本价加上个人购买的订单金额
                foreach ($indOrderPrice as $key => $value) {
                    $indOrderPrice[$key] += ($myselfOrderTotalPrice[$key] ?? 0);
                }
            }

            if (!empty($indOrderPriceMonth)) {
                foreach ($indOrderPriceMonth as $key => $value) {
                    $indOrderPriceMonth[$key] += ($myselfOrderTotalPrice[$key] ?? 0);
                }
            }

            //剔除本人
            foreach ($indOrderPrice as $key => $value) {
                $indOrderPrice[$key] = (string)$value;
                if ($key == $uid) {
                    $finally['myInfo']['user_order_price'] = (string)$value;
                    unset($indOrderPrice[$key]);
                }
            }
            foreach ($indOrderPriceMonth as $key => $value) {
                $indOrderPriceMonth[$key] = (string)$value;
                if ($key == $uid) {
                    $finally['myInfo']['user_month_order_price'] = (string)$value;
                    unset($indOrderPriceMonth[$key]);
                }
            }

        }

        foreach ($directUser as $key => $value) {
            $directUser[$key]['user_order_price'] = priceFormat($indOrderPrice[$value['uid']] ?? 0);
            $directUser[$key]['user_month_order_price'] = priceFormat($indOrderPriceMonth[$value['uid']] ?? 0);
            $directUser[$key]['vip_name'] = $memberTitle[$value['level']];
        }

        $finally['directAllUserList'] = $directUser;

        return $finally ?? [];
    }

    /**
     * @title  我的同级间推团队
     * @return mixed
     * @throws \Exception
     */
    public function nextDirectTeam()
    {
        $uid = Request::param('uid');
        $lastFinally = $this->sameLevelDirectTeam();
//        $lastFinally = $lastFinally['data'];
        $memberTitle = MemberVdc::where(['status' => 1])->order('level asc')->column('name', 'level');
        if (!empty($lastFinally['myInfo'])) {
            $lastFinally['myInfo']['vip_level'] = $lastFinally['myInfo']['level'];
            $lastFinally['myInfo']['vip_name'] = $memberTitle[$lastFinally['myInfo']['level']];
        }
        $finally['myInfo'] = $lastFinally['myInfo'] ?? [];
        $allUser = $lastFinally['directAllUserList'] ?? [];
        $finally['directAllUserList'] = [];
        if (!empty($allUser)) {
            $allChildTeamCode = array_unique(array_column($allUser, 'uid'));
            if (!empty($allChildTeamCode)) {
                $map[] = ['status', '=', 1];
                $map[] = ['link_superior_user', 'in', $allChildTeamCode];
                $map[] = ['level', '=', $finally['myInfo']['level']];
                $nextDirectUser = MemberModel::with(['user'])->where($map)->withoutField('id,update_time')->withSum('linkOrder', 'total_price')->select()->each(function ($item) {
                    //$item['user_order_price'] = $item['link_order_sum'];
                    $item['user_order_price'] = (string)$item['team_performance'] >= 0 ? $item['team_performance'] : 0;
                    unset($item['link_order_sum']);
                    //隐藏手机号码中间四位
                    if (!empty($item['user_phone'])) {
                        $item['user_phone'] = encryptPhone($item['user_phone']);
                    }
                    return $item;
                })->toArray();
                if (!empty($nextDirectUser)) {
                    $allUserUid = array_column($nextDirectUser, 'uid');
                    //获取订单和分润缓存
                    $childCacheKey = md5(implode($allUserUid, ','));
                    $divideCacheKey = $childCacheKey . 'divide-3next';
                    $myselfCacheKey = $childCacheKey . 'myself-3next';
                    $divideCache = cache($divideCacheKey);
                    $myselfCache = cache($myselfCacheKey);


                    if (!empty($divideCache)) {
                        $aDivide = $divideCache;
                    } else {
                        //查找每个人的订单总额
                        $oMap[] = ['link_uid', 'in', $allUserUid];
                        $oMap[] = ['arrival_status', 'in', [1, 2, 4]];
                        $oMap[] = ['status', 'in', [1, 2]];
                        $oMap[] = ['type', 'in', [1]];
                        $aDivide = DivideModel::where($oMap)->field('real_divide_price,arrival_status,total_price,purchase_price,divide_type,link_uid,order_uid,create_time')->select()->toArray();

                        //加入缓存
                        cache($divideCacheKey, $aDivide, 600);
                    }

                    if (!empty($myselfCache)) {
                        $myselfOrder = $myselfCache;
                    } else {
                        $mMap[] = ['uid', 'in', $allUserUid];
                        $mMap[] = ['pay_status', '=', 2];
                        $mMap[] = ['order_status', 'in', [2, 3, 4, 8]];
                        $myselfOrder = Order::with(['goods' => function ($query) {
                            $query->where(['status' => 1]);
                        }])->where($mMap)->field('order_sn,uid,real_pay_price')->select()->toArray();

                        //加入缓存
                        cache($myselfCacheKey, $myselfOrder, 600);
                    }


                    foreach ($myselfOrder as $key => $value) {
                        foreach ($value['goods'] as $gKey => $gValue) {
                            if (!isset($myselfOrderTotalPrice[$value['uid']])) {
                                $myselfOrderTotalPrice[$value['uid']] = 0;
                            }
                            $myselfOrderTotalPrice[$value['uid']] += $gValue['total_price'];
                        }
                    }

                    //本月的开始和结束
                    $thisMonthStart = date('Y-m-01', strtotime(date("Y-m-d"))) . ' 00:00:00';
                    $thisMonthEnd = date('Y-m-d', strtotime("$thisMonthStart +1 month -1 day")) . ' 23:59:59';
                    //统计进货额(即享受价差的人)
                    foreach ($aDivide as $key => $value) {
                        if ($value['divide_type'] == 1) {
                            if (!isset($indOrderPrice[$value['link_uid']])) {
                                $indOrderPrice[$value['link_uid']] = 0;
                            }
                            if (!isset($indWillInComePrice[$value['link_uid']])) {
                                $indWillInComePrice[$value['link_uid']] = 0;
                            }
                            if (!isset($indAllOrder[$value['link_uid']])) {
                                $indAllOrder[$value['link_uid']] = 0;
                            }
                            if (!isset($indOrderPriceMonth[$value['link_uid']])) {
                                $indOrderPriceMonth[$value['link_uid']] = 0;
                            }
                            if (!isset($indWillInComePriceMonth[$value['link_uid']])) {
                                $indWillInComePriceMonth[$value['link_uid']] = 0;
                            }
                            if (!isset($indAllOrderMonth[$value['link_uid']])) {
                                $indAllOrderMonth[$value['link_uid']] = 0;
                            }
                            $indOrderPrice[$value['link_uid']] += $value['purchase_price'];
                            $indWillInComePrice[$value['link_uid']] += $value['real_divide_price'];
                            $indAllOrder[$value['link_uid']] += 1;
                            //统计本月
                            if ($value['create_time'] >= $thisMonthStart && $value['create_time'] <= $thisMonthEnd) {
                                $indOrderPriceMonth[$value['link_uid']] += $value['purchase_price'];
                                $indWillInComePriceMonth[$value['link_uid']] += $value['real_divide_price'];
                                $indAllOrderMonth[$value['link_uid']] += 1;
                            }

                        }
                    }

                    if (!empty($myselfOrderTotalPrice)) {
                        //个人进货总额为个人购买+分润中的成本价,先补齐个人购买的订单金额
                        foreach ($myselfOrderTotalPrice as $key => $value) {
                            if (empty($indOrderPrice[$key])) {
                                $indOrderPrice[$key] = 0;
                            }
                            if (empty($indOrderPriceMonth[$key])) {
                                $indOrderPriceMonth[$key] = 0;
                            }
                        }
                        if (!empty($indOrderPrice)) {
                            //再用分润中的累计成本价加上个人购买的订单金额
                            foreach ($indOrderPrice as $key => $value) {
                                $indOrderPrice[$key] += ($myselfOrderTotalPrice[$key] ?? 0);
                            }
                        }

                        if (!empty($indOrderPriceMonth)) {
                            foreach ($indOrderPriceMonth as $key => $value) {
                                $indOrderPriceMonth[$key] += ($myselfOrderTotalPrice[$key] ?? 0);
                            }
                        }

                        //剔除本人
                        foreach ($indOrderPrice as $key => $value) {
                            $indOrderPrice[$key] = (string)$value;
                            if ($key == $uid) {
                                $finally['myInfo']['user_order_price'] = (string)$value;
                                unset($indOrderPrice[$key]);
                            }
                        }
                        foreach ($indOrderPriceMonth as $key => $value) {
                            $indOrderPriceMonth[$key] = (string)$value;
                            if ($key == $uid) {
                                $finally['myInfo']['user_month_order_price'] = (string)$value;
                                unset($indOrderPriceMonth[$key]);
                            }
                        }

                    }

                    foreach ($nextDirectUser as $key => $value) {
                        $nextDirectUser[$key]['user_order_price'] = priceFormat($indOrderPrice[$value['uid']] ?? 0);
                        $nextDirectUser[$key]['user_month_order_price'] = priceFormat($indOrderPriceMonth[$value['uid']] ?? 0);
                        $nextDirectUser[$key]['vip_name'] = $memberTitle[$value['level']];
                    }

                    $finally['directAllUserList'] = $nextDirectUser;
                }
            }
        }
        return $finally ?? [];
    }

    /**
     * @title  个人余额报表
     * @return mixed
     * @throws \Exception
     */
    public function balanceAll()
    {
        $uid = Request::param('uid');
        $userInfo = User::where(['uid' => $uid])->field('uid,total_balance,withdraw_total,ppyl_balance,divide_balance,divide_withdraw_total,ppyl_withdraw_total')->findOrEmpty();
//        $finally['total_balance'] = $userInfo['total_balance'];
//        $finally['withdraw_total'] = $userInfo['withdraw_total'];
        $finally['total_balance'] = priceFormat($userInfo['ppyl_balance'] + $userInfo['divide_balance']);
//        $finally['total_balance'] = priceFormat($userInfo['divide_balance']);
//        $finally['total_balance'] = priceFormat($userInfo['total_balance']);
        $finally['withdraw_total'] = priceFormat($userInfo['divide_withdraw_total'] + $userInfo['ppyl_withdraw_total']);
        $divideInfo = DivideModel::where(['link_uid' => $userInfo['uid'], 'arrival_status' => [1, 2, 4], 'status' => [1, 2], 'type' => [1, 3]])->field('real_divide_price,arrival_status')->select()->toArray();
        $fronzenPrice = 0;
        $inComePrice = 0;
        foreach ($divideInfo as $key => $value) {
            if (in_array($value['arrival_status'], [2, 4])) {
                $fronzenPrice += $value['real_divide_price'];
            }
//            if($value['arrival_status'] == 1){
//                $inComePrice += $value['real_divide_price'];
//            }
            $inComePrice += $value['real_divide_price'];
        }
        $finally['divide_fronzen_total'] = priceFormat($fronzenPrice) ?? 0;
        $finally['divide_inCome_total'] = priceFormat($inComePrice) ?? 0;
        return $finally ?? [];
    }

    /**
     * @title  筛选用户分润收益
     * @return mixed
     * @throws \Exception
     */
    public function userTeamIncome()
    {
        $data = $this->requestData;
        $uid = $data['uid'];
        if (empty($data['start_time'])) {
            $data['start_time'] = date('Y-m-d', time()) . ' 00:00:00';
            $data['end_time'] = date('Y-m-d', time()) . ' 23:59:59';
        }
        if (!empty($data['start_time']) && !empty($data['end_time'])) {
            $map[] = ['create_time', 'between', [strtotime($data['start_time']), strtotime($data['end_time'])]];
            $mMap[] = ['create_time', 'between', [strtotime($data['start_time']), strtotime($data['end_time'])]];
            $pMap[] = ['create_time', 'between', [strtotime($data['start_time']), strtotime($data['end_time'])]];
        }

        $map[] = ['link_uid', '=', $uid];
        $map[] = ['arrival_status', 'in', [1, 2, 4]];
        $map[] = ['status', 'in', [1, 2]];
        $map[] = ['type', 'in', [1, 3]];
        $aDivide = DivideModel::where($map)->field('real_divide_price,arrival_status,total_price,purchase_price,count,divide_type,type')->select()->toArray();

        $mMap[] = ['uid', '=', $uid];
        $mMap[] = ['pay_status', '=', 2];
        $mMap[] = ['order_status', 'in', [2, 3, 4, 8]];
        $myselfOrder = Order::with(['goods' => function ($query) {
            $query->where(['status' => 1]);
        }])->where($mMap)->field('order_sn,uid,real_pay_price')->select()->toArray();
        $myselfOrderTotalPrice = 0;
        foreach ($myselfOrder as $key => $value) {
            foreach ($value['goods'] as $gKey => $gValue) {
                $myselfOrderTotalPrice += $gValue['total_price'];
            }
        }

        $orderPrice = 0;
        $purchasePrice = 0;
        $willInComePrice = 0;
        $allOrder = 0;
        $dirOrderPrice = 0;
        $dirPurchasePrice = 0;
        $dirWillInComePrice = 0;
        $dirAllOrder = 0;
        $indOrderPrice = 0;
        $indPurchasePrice = 0;
        $indWillInComePrice = 0;
        $indAllOrder = 0;
        $graOrderPrice = 0;
        $graPurchasePrice = 0;
        $graWillInComePrice = 0;
        $graAllOrder = 0;

        foreach ($aDivide as $key => $value) {
            if (!empty(doubleval($value['real_divide_price']))) {
                $orderPrice += $value['total_price'];
                $purchasePrice += $value['purchase_price'];
                $willInComePrice += $value['real_divide_price'];
                $allOrder += 1;
                if ($value['divide_type'] == 1 && $value['type'] == 1) {
                    $dirOrderPrice += $value['total_price'];
                    $dirPurchasePrice += ($value['purchase_price'] * $value['count'] ?? 1);
                    $dirWillInComePrice += $value['real_divide_price'];
                    $dirAllOrder += 1;
                }
                if ($value['divide_type'] == 2 && $value['type'] == 1) {
                    $indOrderPrice += $value['total_price'];
                    $indPurchasePrice += ($value['purchase_price'] * $value['count'] ?? 1);
                    $indWillInComePrice += $value['real_divide_price'];
                    $indAllOrder += 1;
                }
                if ($value['type'] == 3) {
                    $graOrderPrice += $value['total_price'];
                    $graPurchasePrice += ($value['purchase_price'] * $value['count'] ?? 1);
                    $graWillInComePrice += $value['real_divide_price'];
                    $graAllOrder += 1;
                }
            }
        }

        $pMap[] = ['change_type', '=', 10];
        $pMap[] = ['type', '=', 1];
        $pMap[] = ['status', '=', 1];
        $pMap[] = ['uid', '=', $uid];
        //拼拼有礼转入余额
        $ppylBalance = BalanceDetail::where($pMap)->sum('price');

        //总利润
        $finally['all']['order_price'] = priceFormat((string)((priceFormat($orderPrice) ?? 0) + $myselfOrderTotalPrice));
        $finally['all']['purchase_price'] = priceFormat((string)((priceFormat($purchasePrice) ?? 0) + $myselfOrderTotalPrice));
        $finally['all']['will_income_total'] = priceFormat($willInComePrice) ?? 0;
        $finally['all']['pay_order_number'] = $allOrder ?? 0;

        //销售利润(直推)
        $finally['direct']['order_price'] = priceFormat((string)((priceFormat($dirOrderPrice) ?? 0) + $myselfOrderTotalPrice));
        $finally['direct']['purchase_price'] = priceFormat((string)((priceFormat($dirPurchasePrice) ?? 0) + $myselfOrderTotalPrice));
        $finally['direct']['will_income_total'] = priceFormat($dirWillInComePrice) ?? 0;
        $finally['direct']['pay_order_number'] = $dirAllOrder ?? 0;

        //教育基金(间推)
////        $finally['indirect']['order_price'] = priceFormat((string)((priceFormat($indOrderPrice) ?? 0) + $myselfOrderTotalPrice));
////        $finally['indirect']['purchase_price'] = priceFormat((string)((priceFormat($indPurchasePrice) ?? 0) + $myselfOrderTotalPrice));
        //以下为暂时隐藏部分
//        $finally['indirect']['order_price'] = (priceFormat($indOrderPrice) ?? 0);
//        $finally['indirect']['purchase_price'] = (priceFormat($indPurchasePrice) ?? 0);
//        $finally['indirect']['will_income_total'] = priceFormat($indWillInComePrice) ?? 0;
//        $finally['indirect']['pay_order_number'] = $indAllOrder ?? 0;

        //拼拼有礼转入总余额
        $finally['ppyl']['price'] = $ppylBalance;

        //套餐感恩奖
        $finally['grateful']['order_price'] = priceFormat((string)((priceFormat($graOrderPrice) ?? 0)));
        $finally['grateful']['purchase_price'] = (string)((priceFormat($graPurchasePrice) ?? 0));
        $finally['grateful']['will_income_total'] = priceFormat($graWillInComePrice) ?? 0;
        $finally['grateful']['pay_order_number'] = $graAllOrder ?? 0;

        return $finally ?? [];

    }

    /**
     * @title  用户收益情况
     * @return mixed
     */
    public function userAllInCome()
    {
        $uid = Request::param('uid');
        //今日收益
        $data['start_time'] = date('Y-m-d', time()) . ' 00:00:00';
        $data['end_time'] = date('Y-m-d', time()) . ' 23:59:59';

        $map[] = ['create_time', 'between', [strtotime($data['start_time']), strtotime($data['end_time'])]];
        $map[] = ['link_uid', '=', $uid];
        $map[] = ['arrival_status', 'in', [1, 2, 4]];
        $map[] = ['status', 'in', [1]];
        $map[] = ['type', 'in', [1, 3, 4]];

        $todayDivide = DivideModel::where($map)->sum('real_divide_price');

        //本月收益
        $thisMonthStart = date('Y-m-01', strtotime(date("Y-m-d"))) . ' 00:00:00';
        $thisMonthEnd = date('Y-m-d', strtotime("$thisMonthStart +1 month -1 day")) . ' 23:59:59';
        $data['start_time'] = $thisMonthStart;
        $data['end_time'] = $thisMonthEnd;
        $map2[] = ['create_time', 'between', [strtotime($data['start_time']), strtotime($data['end_time'])]];
        $map2[] = ['link_uid', '=', $uid];
        $map2[] = ['arrival_status', 'in', [1, 2, 4]];
        $map2[] = ['status', 'in', [1]];
        $map2[] = ['type', 'in', [1, 3, 4]];
        $monthDivide = DivideModel::where($map2)->sum('real_divide_price');

//        //上月收益
//        $thisMonthStart = date('Y-m-01', strtotime('-1 month')) . ' 00:00:00';
//        $thisMonthEnd = date('Y-m-t', strtotime('-1 month')) . ' 23:59:59';
//        $data['start_time'] = $thisMonthStart;
//        $data['end_time'] = $thisMonthEnd;
//        $map3[] = ['create_time', 'between', [strtotime($data['start_time']), strtotime($data['end_time'])]];
//        $map3[] = ['link_uid', '=', $uid];
//        $map3[] = ['arrival_status', 'in', [1, 2, 4]];
//        $map3[] = ['status', 'in', [1]];
//        $lastMonthDivide = DivideModel::where($map3)->sum('real_divide_price');

        //累计收益
        $map4[] = ['link_uid', '=', $uid];
        $map4[] = ['arrival_status', 'in', [1, 2, 4]];
        $map4[] = ['status', 'in', [1]];
        $map4[] = ['type', 'in', [1, 3, 4]];
        $allDivide = DivideModel::where($map4)->sum('real_divide_price');

        //拼拼部分收益
        $data = [];
        $map = [];
        $map2 = [];
        $map3 = [];
        $map4 = [];

        //今日收益
        $data['start_time'] = date('Y-m-d', time()) . ' 00:00:00';
        $data['end_time'] = date('Y-m-d', time()) . ' 23:59:59';


        $map[] = ['link_uid', '=', $uid];
        $map[] = ['arrival_status', 'in', [1, 2]];
        $map[] = ['status', 'in', [1]];

        $mapOrOne1[] = ['receive_time', 'between', [strtotime($data['start_time']), strtotime($data['end_time'])]];
        $mapOrTwo1[] = ['arrival_time', 'between', [strtotime($data['start_time']), strtotime($data['end_time'])]];

        $todayPpyl = PpylReward::where($map)->where(function ($query) use ($mapOrOne1, $mapOrTwo1) {
            $query->whereOr([$mapOrOne1, $mapOrTwo1]);
        })->sum('real_reward_price');

        //本月收益
        $thisMonthStart = date('Y-m-01', strtotime(date("Y-m-d"))) . ' 00:00:00';
        $thisMonthEnd = date('Y-m-d', strtotime("$thisMonthStart +1 month -1 day")) . ' 23:59:59';
        $data['start_time'] = $thisMonthStart;
        $data['end_time'] = $thisMonthEnd;

        $map2[] = ['link_uid', '=', $uid];
        $map2[] = ['arrival_status', 'in', [1, 2]];
        $map2[] = ['status', 'in', [1]];

        $mapOrOne2[] = ['receive_time', 'between', [strtotime($data['start_time']), strtotime($data['end_time'])]];
        $mapOrTwo2[] = ['arrival_time', 'between', [strtotime($data['start_time']), strtotime($data['end_time'])]];

        $monthPpyl = PpylReward::where($map2)->where(function ($query) use ($mapOrOne2, $mapOrTwo2) {
            $query->whereOr([$mapOrOne2, $mapOrTwo2]);
        })->sum('real_reward_price');

//        //上月收益
//        $thisMonthStart = date('Y-m-01', strtotime('-1 month')) . ' 00:00:00';
//        $thisMonthEnd = date('Y-m-t', strtotime('-1 month')) . ' 23:59:59';
//        $data['start_time'] = $thisMonthStart;
//        $data['end_time'] = $thisMonthEnd;
//
//        $map3[] = ['link_uid', '=', $uid];
//        $map3[] = ['arrival_status', 'in', [1, 2, 3]];
//        $map3[] = ['status', 'in', [1]];
//
//        $mapOrOne3[] = ['create_time', 'between', [strtotime($data['start_time']), strtotime($data['end_time'])]];
//        $mapOrTwo3[] = ['arrival_time', 'between', [strtotime($data['start_time']), strtotime($data['end_time'])]];
//
//        $lastMonthPpyl = PpylReward::where($map3)->where(function ($query) use ($mapOrOne3, $mapOrTwo3) {
//            $query->whereOr([$mapOrOne3, $mapOrTwo3]);
//        })->sum('real_reward_price');

        //累计收益
        $map4[] = ['link_uid', '=', $uid];
        $map4[] = ['arrival_status', 'in', [1, 2]];
        $map4[] = ['status', 'in', [1]];
        $allPpyl = PpylReward::where($map4)->sum('real_reward_price');

        //商城分润收益
        $finally['divideTodayInCome'] = priceFormat($todayDivide ?? 0);
        $finally['divideMonthInCome'] = priceFormat($monthDivide ?? 0);
        $finally['divideLastMonthInCome'] = priceFormat($lastMonthDivide ?? 0);
        $finally['divideAllInCome'] = priceFormat($allDivide ?? 0);

        //美好拼拼推荐收益
        $finally['mhppTodayInCome'] = priceFormat($todayPpyl ?? 0);
        $finally['mhppMonthInCome'] = priceFormat($monthPpyl ?? 0);
        $finally['mhppLastMonthInCome'] = priceFormat($lastMonthPpyl ?? 0);
        $finally['mhppAllInCome'] = priceFormat($allPpyl ?? 0);

        //总收益
        $finally['todayInCome'] = priceFormat(priceFormat($todayDivide ?? 0) + priceFormat($todayPpyl ?? 0));
        $finally['MonthInCome'] = priceFormat(priceFormat($monthDivide ?? 0) + priceFormat($monthPpyl ?? 0));
        $finally['lastMonthInCome'] = priceFormat(priceFormat($lastMonthDivide ?? 0) + priceFormat($lastMonthPpyl ?? 0));
        $finally['allInCome'] = priceFormat(priceFormat($allDivide ?? 0) + priceFormat($allPpyl ?? 0));

        //用户余额
        $userInfo = User::where(['uid'=>$uid])->field('total_balance,ppyl_balance,divide_balance')->findOrEmpty()->toArray();

        $finally['balance'] = 0;
        $platform = Request::param('platform') ?? 1;
        if (!empty($userInfo)) {
            //$platform 平台类型 1为小程序看到的余额 2为H5看到的余额
            switch ($platform){
                case 1:
                    $finally['balance'] = priceFormat($userInfo['ppyl_balance'] + $userInfo['divide_balance']);
                    break;
                case 2:
                    $finally['balance'] = priceFormat($userInfo['ad_balance']);
                    break;
                default:
                    $finally['balance'] = 0;
            }

//            $finally['balance'] = priceFormat($userInfo['total_balance']);
        }


        return $finally ?? [];
    }

    /**
     * @title  用户收益情况(拼拼)
     * @return mixed
     */
    public function userPpylAllInCome()
    {
        $uid = Request::param('uid');
        //今日收益
        $data['start_time'] = date('Y-m-d', time()) . ' 00:00:00';
        $data['end_time'] = date('Y-m-d', time()) . ' 23:59:59';

        $map[] = ['receive_time', 'between', [strtotime($data['start_time']), strtotime($data['end_time'])]];
        $map[] = ['link_uid', '=', $uid];
        $map[] = ['arrival_status', 'in', [1, 2]];
        $map[] = ['status', 'in', [1]];

        $todayDivide = PpylReward::where($map)->sum('real_reward_price');

        //昨日收益
        $data['start_time'] = date("Y-m-d",strtotime("-1 day")) . ' 00:00:00';
        $data['end_time'] = date("Y-m-d",strtotime("-1 day")) . ' 23:59:59';

        $map[] = ['receive_time', 'between', [strtotime($data['start_time']), strtotime($data['end_time'])]];
        $map[] = ['link_uid', '=', $uid];
        $map[] = ['arrival_status', 'in', [1, 2]];
        $map[] = ['status', 'in', [1]];

        $yesterdayDivide = PpylReward::where($map)->sum('real_reward_price');

        //本月收益
        $thisMonthStart = date('Y-m-01', strtotime(date("Y-m-d"))) . ' 00:00:00';
        $thisMonthEnd = date('Y-m-d', strtotime("$thisMonthStart +1 month -1 day")) . ' 23:59:59';
        $data['start_time'] = $thisMonthStart;
        $data['end_time'] = $thisMonthEnd;
        $map2[] = ['receive_time', 'between', [strtotime($data['start_time']), strtotime($data['end_time'])]];
        $map2[] = ['link_uid', '=', $uid];
        $map2[] = ['arrival_status', 'in', [1, 2]];
        $map2[] = ['status', 'in', [1]];
        $monthDivide = PpylReward::where($map2)->sum('real_reward_price');

        //上月收益
        $thisMonthStart = date('Y-m-01', strtotime('-1 month')) . ' 00:00:00';
        $thisMonthEnd = date('Y-m-t', strtotime('-1 month')) . ' 23:59:59';
        $data['start_time'] = $thisMonthStart;
        $data['end_time'] = $thisMonthEnd;
        $map3[] = ['receive_time', 'between', [strtotime($data['start_time']), strtotime($data['end_time'])]];
        $map3[] = ['link_uid', '=', $uid];
        $map3[] = ['arrival_status', 'in', [1, 2]];
        $map3[] = ['status', 'in', [1]];
        $lastMonthDivide = PpylReward::where($map3)->sum('real_reward_price');

        //累计收益
        $map4[] = ['link_uid', '=', $uid];
        $map4[] = ['arrival_status', 'in', [1, 2]];
        $map4[] = ['status', 'in', [1]];
        $allDivide = PpylReward::where($map4)->field('sum(real_reward_price) as all_real_reward_price,sum(if(arrival_status = 1,real_reward_price,0)) as arrival_price,sum(if(arrival_status = 2,real_reward_price,0)) as frozen_price')->findOrEmpty()->toArray();

        //剩余余额
        $ppylBalace = User::where(['uid'=>$uid,'status'=>1])->value('ppyl_balance');


        //本金,即未退款的订单的
        $notRefundOrderPrice = 0;

        $orderMap[] = ['uid', '=', $uid];
        $orderMap[] = ['activity_status', 'in', [3, -3]];
        $orderMap[] = ['pay_status', 'in', [2]];
        $orderMap[] = ['refund_status', 'in', [2]];
        $orderMap[] = ['can_operate_refund', '=', 1];
        $notRefundOrder = PpylOrder::where($orderMap)->order('create_time desc')->select()->toArray();

        if (!empty($notRefundOrder)) {
            $allPayNo = array_unique(array_column($notRefundOrder, 'pay_no'));
            $allOrderSn = array_unique(array_column($notRefundOrder, 'order_sn'));
            $payMap[] = ['pay_no', 'in', $allPayNo];
            $payMap[] = ['refund_status', '=', 1];
            $RefundOrder = PpylOrder::where($payMap)->group('pay_no')->column('pay_no');

            if (!empty($RefundOrder)) {
                foreach ($notRefundOrder as $key => $value) {
                    if (in_array($value, $RefundOrder)) {
                        unset($notRefundOrder[$key]);
                    }
                }
            }

            if (!empty($notRefundOrder)) {
                foreach ($notRefundOrder as $key => $value) {
                    $notRefundOrderPrice += $value['real_pay_price'];
                }
            }
        }


        $finally['todayInCome'] = priceFormat($todayDivide ?? 0);
        $finally['yesterdayInCome'] = priceFormat($yesterdayDivide ?? 0);
        $finally['MonthInCome'] = priceFormat($monthDivide ?? 0);
        $finally['lastMonthInCome'] = priceFormat($lastMonthDivide ?? 0);
        $finally['allInCome'] = priceFormat($allDivide['all_real_reward_price'] ?? 0);
        $finally['arrivalInCome'] = priceFormat($allDivide['arrival_price'] ?? 0);
        $finally['frozenInCome'] = priceFormat($allDivide['frozen_price'] ?? 0);
        $finally['balance'] = priceFormat($ppylBalace ?? 0);
        $finally['purchase_price'] = priceFormat($notRefundOrderPrice ?? 0);

        return $finally ?? [];
    }

    /**
     * @title  用户收益情况(h5端)
     * @throws \Exception
     * @return mixed
     */
    public function h5UserAllInCome()
    {
        $uid = Request::param('uid');

        //累计收益
        $map4[] = ['link_uid', '=', $uid];
        $map4[] = ['arrival_status', 'in', [1, 2, 4]];
        $map4[] = ['status', '=', 1];
        $map4[] = ['type', 'in', [2, 5, 6, 7, 9]];
        $allDivideList = DivideModel::where($map4)->field('type,real_divide_price,is_grateful,is_exp,is_device,allot_type,device_divide_type,create_time,level,team_shareholder_level')->select()->toArray();

        //今日收益
        $data['start_time'] = date('Y-m-d', time()) . ' 00:00:00';
        $data['end_time'] = date('Y-m-d', time()) . ' 23:59:59';
        $todayStartTime = date('Y-m-d', time()) . ' 00:00:00';
        $todayEndTime = date('Y-m-d', time()) . ' 23:59:59';

        $map[] = ['create_time', 'between', [strtotime($data['start_time']), strtotime($data['end_time'])]];
        $map[] = ['link_uid', '=', $uid];
        $map[] = ['arrival_status', 'in', [1, 2, 4]];
        $map[] = ['status', 'in', [1]];
        $map[] = ['type', 'in', [2, 5, 6, 7, 9]];

//        $todayDivideList = DivideModel::where($map)->field('type,real_divide_price,is_grateful,is_exp,is_device,allot_type,device_divide_type,create_time')->select()->toArray();

        $adTodayDivide = 0;
        $todayDivide = 0;
        $teamTodayDivide = 0;
        $teamGratefulTodayDivide = 0;
        $teamExpTodayDivide = 0;
        $teamShareholderTodayDivide = 0;
        $shareholderTodayDivide = 0;
        $areaTodayDivide = 0;
        $deviceTodayDivide = 0;
        $stocksTeamTodayDivide = 0;
        $stocksAreaTodayDivide = 0;
        if (!empty($allDivideList ?? [])) {
            foreach ($allDivideList as $key => $value) {
                if ($value['create_time'] >= $todayStartTime && $value['create_time'] <= $todayEndTime) {
                    $todayDivide += $value['real_divide_price'];
                    if ($value['type'] == 2) {
                        $adTodayDivide += $value['real_divide_price'];
                    }
                    //把体验中心单独和感恩奖切分出来统计
                    if ($value['type'] == 5) {
                        if ($value['is_exp'] == 2) {
                            if ($value['type'] == 5 && $value['is_grateful'] == 2) {
                                $teamTodayDivide += $value['real_divide_price'];
                            }
                            if ($value['type'] == 5 && $value['is_grateful'] == 1) {
                                $teamGratefulTodayDivide += $value['real_divide_price'];
                            }
                        } else {
                            if($value['team_shareholder_level'] > 0){
                                $teamShareholderTodayDivide += $value['real_divide_price'];
                            }else{
                                $teamExpTodayDivide += $value['real_divide_price'];
                            }
                        }
                    }

                    if ($value['type'] == 6) {
                        $shareholderTodayDivide += $value['real_divide_price'];
                    }
                    if ($value['type'] == 7) {
                        //区代奖励把设备订单切分开来
                        if ($value['is_device'] == 1 && $value['device_divide_type'] == 1) {
                            $deviceTodayDivide += $value['real_divide_price'];
                        } else {
                            $areaTodayDivide += $value['real_divide_price'];
                        }
                    }

                    if ($value['type'] == 9) {
                        //把区代和团队业绩区分出来
                        if ($value['allot_type'] == 2) {
                            $stocksAreaTodayDivide += $value['real_divide_price'];
                        } else {
                            $stocksTeamTodayDivide += $value['real_divide_price'];
                        }
                    }
                }
            }
        }

        //本月收益
        $thisMonthStart = date('Y-m-01', strtotime(date("Y-m-d"))) . ' 00:00:00';
        $thisMonthEnd = date('Y-m-d', strtotime("$thisMonthStart +1 month -1 day")) . ' 23:59:59';
        $data['start_time'] = $thisMonthStart;
        $data['end_time'] = $thisMonthEnd;
        $map2[] = ['create_time', 'between', [strtotime($data['start_time']), strtotime($data['end_time'])]];
        $map2[] = ['link_uid', '=', $uid];
        $map2[] = ['arrival_status', 'in', [1, 2, 4]];
        $map2[] = ['status', 'in', [1]];
        $map2[] = ['type', 'in', [2, 5, 6, 7, 9]];
//        $monthDivideList = DivideModel::where($map2)->field('type,real_divide_price,is_grateful,is_exp,is_device,allot_type,device_divide_type,create_time')->select()->toArray();


        $adMonthDivide = 0;
        $monthDivide = 0;
        $teamMonthDivide = 0;
        $teamGratefulMonthDivide = 0;
        $teamExpMonthDivide = 0;
        $teamShareholderMonthDivide = 0;
        $shareholderMonthDivide = 0;
        $areaMonthDivide = 0;
        $deviceMonthDivide = 0;
        $stocksTeamMonthDivide = 0;
        $stocksAreaMonthDivide = 0;
        if(!empty($allDivideList ?? [])){
            foreach ($allDivideList as $key => $value) {
                if ($value['create_time'] >= $thisMonthStart && $value['create_time'] <= $thisMonthEnd) {
                    $monthDivide += $value['real_divide_price'];
                    if($value['type'] == 2){
                        $adMonthDivide += $value['real_divide_price'];
                    }
                    //把体验中心单独和感恩奖切分出来统计
                    if ($value['type'] == 5) {
                        if ($value['is_exp'] == 2) {
                            if ($value['type'] == 5 && $value['is_grateful'] == 2) {
                                $teamMonthDivide += $value['real_divide_price'];
                            }
                            if ($value['type'] == 5 && $value['is_grateful'] == 1) {
                                $teamGratefulMonthDivide += $value['real_divide_price'];
                            }
                        } else {
                            if ($value['team_shareholder_level'] > 0) {
                                $teamShareholderMonthDivide += $value['real_divide_price'];
                            } else {
                                $teamExpMonthDivide += $value['real_divide_price'];
                            }

                        }
                    }
                    if($value['type'] == 6){
                        $shareholderMonthDivide += $value['real_divide_price'];
                    }
                    if($value['type'] == 7){
                        //区代奖励把设备订单切分开来
                        if ($value['is_device'] == 1 && $value['device_divide_type'] == 1) {
                            $deviceMonthDivide += $value['real_divide_price'];
                        } else {
                            $areaMonthDivide += $value['real_divide_price'];
                        }
                    }
                    if($value['type'] == 9){
                        //把区代和团队业绩区分出来
                        if ($value['allot_type'] == 2) {
                            $stocksAreaMonthDivide += $value['real_divide_price'];
                        } else {
                            $stocksTeamMonthDivide += $value['real_divide_price'];
                        }
                    }
                }
            }
        }


//        //上月收益
//        $thisMonthStart = date('Y-m-01', strtotime('-1 month')) . ' 00:00:00';
//        $thisMonthEnd = date('Y-m-t', strtotime('-1 month')) . ' 23:59:59';
//        $data['start_time'] = $thisMonthStart;
//        $data['end_time'] = $thisMonthEnd;
//        $map3[] = ['create_time', 'between', [strtotime($data['start_time']), strtotime($data['end_time'])]];
//        $map3[] = ['link_uid', '=', $uid];
//        $map3[] = ['arrival_status', 'in', [1, 2, 4]];
//        $map3[] = ['status', 'in', [1]];
//        $lastMonthDivide = DivideModel::where($map3)->sum('real_divide_price');

//        //累计收益
//        $map4[] = ['link_uid', '=', $uid];
//        $map4[] = ['arrival_status', 'in', [1, 2, 4]];
//        $map4[] = ['status', 'in', [1]];
//        $map4[] = ['type', 'in', [2, 5, 6, 7, 9]];
//        $allDivideList = DivideModel::where($map4)->field('type,real_divide_price,is_grateful,is_exp,is_device,allot_type,device_divide_type')->select()->toArray();

        $adAllDivide = 0;
        $allDivide = 0;
        $teamAllDivide = 0;
        $teamGratefulAllDivide = 0;
        $teamExpAllDivide = 0;
        $teamShareholderAllDivide = 0;
        $shareholderAllDivide = 0;
        $areaAllDivide = 0;
        $deviceAllDivide = 0;
        $stocksTeamAllDivide = 0;
        $stocksAreaAllDivide = 0;
        foreach ($allDivideList as $key => $value) {
            $allDivide += $value['real_divide_price'];
            if($value['type'] == 2){
                $adAllDivide += $value['real_divide_price'];
            }
            //把体验中心单独和感恩奖切分出来统计
            if ($value['type'] == 5) {
                if ($value['is_exp'] == 2) {
                    if ($value['type'] == 5 && $value['is_grateful'] == 2) {
                        $teamAllDivide += $value['real_divide_price'];
                    }
                    if ($value['type'] == 5 && $value['is_grateful'] == 1) {
                        $teamGratefulAllDivide += $value['real_divide_price'];
                    }
                } else {
                    if($value['team_shareholder_level'] > 0){
                        $teamShareholderAllDivide += $value['real_divide_price'];
                    }else{
                        $teamExpAllDivide += $value['real_divide_price'];
                    }
                }
            }

            if($value['type'] == 6){
                $shareholderAllDivide += $value['real_divide_price'];
            }
            if($value['type'] == 7){
                if ($value['is_device'] == 1 && $value['device_divide_type'] == 1) {
                    $deviceAllDivide += $value['real_divide_price'];
                } else {
                    $areaAllDivide += $value['real_divide_price'];
                }
            }

            if ($value['type'] == 9) {
                //把区代和团队业绩区分出来
                if ($value['allot_type'] == 2) {
                    $stocksAreaAllDivide += $value['real_divide_price'];
                } else {
                    $stocksTeamAllDivide += $value['real_divide_price'];
                }
            }

        }


        //广宣奖分润收益
        $finally['adTodayInCome'] = priceFormat($adTodayDivide ?? 0);
        $finally['adMonthInCome'] = priceFormat($adMonthDivide ?? 0);
//        $finally['adLastMonthInCome'] = priceFormat($lastMonthDivide ?? 0);
        $finally['adAllInCome'] = priceFormat($adAllDivide ?? 0);

        //团队业绩奖分润收益
        $finally['teamTodayInCome'] = priceFormat($teamTodayDivide ?? 0);
        $finally['teamMonthInCome'] = priceFormat($teamMonthDivide ?? 0);
        $finally['teamAllInCome'] = priceFormat($teamAllDivide ?? 0);

        //团队业绩感恩奖分润收益
        $finally['teamGratefulTodayInCome'] = priceFormat($teamGratefulTodayDivide ?? 0);
        $finally['teamGratefulMonthInCome'] = priceFormat($teamGratefulMonthDivide ?? 0);
        $finally['teamGratefulAllInCome'] = priceFormat($teamGratefulAllDivide ?? 0);

        //团队业绩体验中心奖分润收益
        $finally['teamExpTodayInCome'] = priceFormat($teamExpTodayDivide ?? 0);
        $finally['teamExpMonthInCome'] = priceFormat($teamExpMonthDivide ?? 0);
        $finally['teamExpAllInCome'] = priceFormat($teamExpAllDivide ?? 0);

        //团队业绩股东奖分润收益
        $finally['teamShareholderTodayInCome'] = priceFormat($teamShareholderTodayDivide ?? 0);
        $finally['teamShareholderMonthInCome'] = priceFormat($teamShareholderMonthDivide ?? 0);
        $finally['teamShareholderAllInCome'] = priceFormat($teamShareholderAllDivide ?? 0);

//        //股东奖分润收益
//        $finally['shareholderTodayInCome'] = priceFormat($shareholderTodayDivide ?? 0);
//        $finally['shareholderMonthInCome'] = priceFormat($shareholderMonthDivide ?? 0);
//        $finally['shareholderAllInCome'] = priceFormat($shareholderAllDivide ?? 0);

        //股票奖团队业绩分润收益
        $finally['stocksTeamTodayInCome'] = priceFormat($stocksTeamTodayDivide ?? 0);
        $finally['stocksTeamMonthInCome'] = priceFormat($stocksTeamMonthDivide ?? 0);
        $finally['stocksTeamAllInCome'] = priceFormat($stocksTeamAllDivide ?? 0);

        //股票奖区代分润收益
        $finally['stocksAreaTodayInCome'] = priceFormat($stocksAreaTodayDivide ?? 0);
        $finally['stocksAreaMonthInCome'] = priceFormat($stocksAreaMonthDivide ?? 0);
        $finally['stocksAreaAllInCome'] = priceFormat($stocksAreaAllDivide ?? 0);

        //区代奖分润收益
        $finally['areaTodayInCome'] = priceFormat($areaTodayDivide ?? 0);
        $finally['areaMonthInCome'] = priceFormat($areaMonthDivide ?? 0);
        $finally['areaAllInCome'] = priceFormat($areaAllDivide ?? 0);

        //区代奖设备奖励分润收益
        $finally['deviceTodayInCome'] = priceFormat($deviceTodayDivide ?? 0);
        $finally['deviceMonthInCome'] = priceFormat($deviceMonthDivide ?? 0);
        $finally['deviceAllInCome'] = priceFormat($deviceAllDivide ?? 0);

        //总收益
        $finally['todayInCome'] = priceFormat(priceFormat($todayDivide ?? 0));
        $finally['MonthInCome'] = priceFormat(priceFormat($monthDivide ?? 0));
//        $finally['lastMonthInCome'] = priceFormat(priceFormat($lastMonthDivide ?? 0));
        $finally['allInCome'] = priceFormat(priceFormat($allDivide ?? 0));

        //用户余额
        $userInfo = User::where(['uid' => $uid])->field('ad_balance,divide_balance,ppyl_balance,team_balance,shareholder_balance,area_balance')->findOrEmpty()->toArray();

        $finally['balance'] = 0;
        if (!empty($userInfo)) {
//            $finally['balance'] = priceFormat($userInfo['divide_balance'] + $userInfo['ppyl_balance'] + $userInfo['ad_balance']);
            $finally['balance'] = priceFormat(($userInfo['ad_balance'] ?? 0) + ($userInfo['team_balance'] ?? 0) + ($userInfo['shareholder_balance'] ?? 0)) + ($userInfo['area_balance'] ?? 0);
            $finally['balance'] = priceFormat($finally['balance']);
        }

        return $finally ?? [];
    }



    /**
     * @title  用户团队全部成员数量
     * @return mixed
     */
    public function teamAllUserNumber()
    {
        $uid = Request::param('uid');
        $divide = (new Divide());
        $member = (new MemberModel());
        $aMemberInfo = $member->where(['uid' => $uid, 'status' => 1])->field('id,uid,user_phone,member_card,level,type,link_superior_user,child_team_code,team_code,parent_team')->findOrEmpty()->toArray();
        if ($aMemberInfo['id'] >= 14763 & $aMemberInfo['id'] <= 162037) {
            throw new ServiceException(['msg' => '后续将开放该数据的查询,感谢您的支持~']);
        }
        $finally['memberInfo'] = $aMemberInfo ?? [];
        $finally['TeamAllUserNumber'] = 0;
        if (!empty($aMemberInfo)) {
            //团队全部人数
            $allTeam = $divide->getTeamAllUserGroupByLevel($aMemberInfo['uid']);
            $finally['TeamAllUserNumber'] = $allTeam['allUser']['count'] ?? 0;
        }

        return $finally ?? [];
    }

    /**
     * @title  补齐数据
     * @param array $data
     * @return mixed
     */
    public function makeUpUserBalanceData(array $data)
    {
        $allDivide = $data['allDivide'] ?? [];
        $uid = $data['uid'];
        if (empty($allDivide)) {
            return false;
        }
        foreach ($allDivide as $key => $value) {
            if (!isset($allDivides[$value['order_sn']])) {
                $allDivides[$value['order_sn']] = 0;
            }
            $allDivides[$value['order_sn']] += $value['real_divide_price'];
            $allOrderSn = $value['order_sn'];
        }
        if (empty($allOrderSn)) {
            return false;
        }

        $balance = BalanceDetail::where(['uid' => $uid, 'type' => 1, 'order_sn' => $allOrderSn])->field('order_sn,sum(price) as price')->group('order_sn')->select()->toArray();
        $allNeedAdd = 0;
        foreach ($allDivides as $key => $value) {
            foreach ($balance as $k => $v) {
                if ($value['order_sn'] == $v['order_sn'] && $value['real_divide_price'] != $v['price']) {
                    if ($value['real_divide_price'] > $value['price']) {
                        $allNeedAdd += ($value['real_divide_price'] - $value['price']);
                    } elseif ($value['real_divide_price'] < $value['price']) {
                        $allNeedAdd += ($value['price'] - $value['real_divide_price']);
                    }
                }
            }
        }
        return $allNeedAdd;
    }

    /**
     * @title  用户团队结构(信的查询结构和方式)
     * @param array $data
     * @return array
     * @throws \Exception
     */
    public function userTeamSummaryNew(array $data)
    {
        $uid = $data['uid'];
        $level = $data['level'] ?? null;
        $tokenUid = $data['tokenUid'] ?? null;
        $userInfo = User::where(['uid' => $uid, 'status' => 1])->field('uid,phone,name,vip_level,team_vip_level,c_vip_level,avatarUrl,team_sales_price')->findOrEmpty()->toArray();
        unset($data['tokenUid']);
        $cacheKey = md5(implode(',', $data));
        if (!empty(cache($cacheKey))) {
            return cache($cacheKey);
        }
        if (empty($uid) || empty($userInfo)) {
            throw new ServiceException(['msg' => '非法用户']);
        }
        $notMyself = false;
        if ($tokenUid != $userInfo['uid']) {
            $notMyself = true;
            if (!empty($userInfo['phone'])) {
                $userInfo['phone'] = encryptPhone($userInfo['phone']);
            }
        }
        //查找类型 1为查询用户团队总人数 2为查询用户团队日期人数面板 3为用户团队列表 4为根据日期筛选团队列表
        $searType = $data['searType'] ?? 1;
        //如果是查询的是数据面板, 统计总人数的时候不筛选会员等级, 默认查全部
        if ($searType != 2) {
            if (!empty($level)) {
                $map[] = ['level', '=', $level];
            }
        }
        $map[] = ['', 'exp', Db::raw("find_in_set('$uid',team_chain)")];
        $map[] = ['level', '<>', 0];
        $map[] = ['status', '=', 1];
        if (!empty($data['start_time'] ?? null) && !empty($data['end_time'] ?? null)) {
            $map[] = ['upgrade_time', '>=', strtotime($data['start_time'])];
            $map[] = ['upgrade_time', '<=', strtotime($data['end_time'])];
        }
        if (!empty($data['link_uid'])) {
            $map[] = ['link_superior_user', '=', $data['link_uid']];
        }
        if (!empty($data['keyword'] ?? null)) {
            $keyword = str_replace(' ', '', trim($data['keyword']));
            if (!is_numeric($keyword)) {
                throw new ServiceException(['msg' => '仅支持搜索手机号码, 请检查输入的内容是否为为纯数字的手机号码']);
            }
            $map[] = ['', 'exp', Db::raw("LOCATE(\"" . $keyword . "\", `user_phone`) > 0")];
        }

        $page = intval($data['page'] ?? 0) ?: null;
        $pageNumber = 10;
        $memberCount = MemberModel::where($map)->count();
        $teamMemberVdc = TeamMemberVdc::where(['status' => 1])->column('name', 'level');
        $memberVdc = MemberVdc::where(['status' => 1])->column('name', 'level');

        $userInfo['vip_name'] = $memberVdc[$userInfo['vip_level']] ?? '普通用户';
        $userInfo['team_vip_name'] = $teamMemberVdc[$userInfo['team_vip_level']] ?? null;
        switch ($searType) {
            case 1:
                $userInfo['total_team_number'] = intval($memberCount);
                $returnFinally = ['myInfo' => $userInfo, 'list' => [], 'pageTotal' => 0];
                cache($cacheKey, $returnFinally, 600);
                return $returnFinally;
                break;
            case 2:
                if (!empty($level)) {
                    $map[] = ['level', '=', $level];
                }
                $tMap = $map;
                $todayStart = date('Y-m-d', time()) . ' 00:00:00';
                $toadyEnd = date('Y-m-d', time()) . ' 23:59:59';
                //先获取每个层级现有的人员情
                $tMap[] = ['upgrade_time', '>=', strtotime($todayStart)];
                $tMap[] = ['upgrade_time', '<=', strtotime($toadyEnd)];
                $memberTodayCount = MemberModel::where($tMap)->count();

                $mMap = $map;
                $thisMonthStart = date('Y-m-01', strtotime(date("Y-m-d"))) . ' 00:00:00';
                $thisMonthEnd = date('Y-m-d', strtotime("$thisMonthStart +1 month -1 day")) . ' 23:59:59';

                $mMap[] = ['upgrade_time', '>=', strtotime($thisMonthStart)];
                $mMap[] = ['upgrade_time', '<=', strtotime($thisMonthEnd)];
                $memberMonthCount = MemberModel::where($mMap)->count();

                $dMap = $map;
                //获取直推总数
                $dMap[] = ['link_superior_user', '=', $uid];
                $memberDirAllCount = MemberModel::where($dMap)->count();

                $summaryInfo['total_team_number'] = intval($memberCount);
                $summaryInfo['toady_team_number'] = intval($memberTodayCount);
                $summaryInfo['direct_team_number'] = intval($memberDirAllCount);
                $summaryInfo['month_team_number'] = intval($memberMonthCount);

                $returnFinally = ['myInfo' => $userInfo, 'summaryInfo' => $summaryInfo, 'list' => [], 'pageTotal' => 0];
                cache($cacheKey, $returnFinally, 600);
                return $returnFinally;
                break;
            case 3:
            case 4:
                $memberList = MemberModel::with(['tUser', 'pUser'])->where($map)->field('uid,level,link_superior_user,growth_value,upgrade_time,create_time')->when(in_array($searType, [3, 4]), function ($query) use ($page, $pageNumber) {
                    $query->page($page, $pageNumber);
                })->order('upgrade_time desc,id asc')->select()->each(function ($item) use ($uid, $teamMemberVdc, $memberVdc, $notMyself) {
                    $item['is_direct'] = 2;
                    if ($item['link_superior_user'] == $uid) {
                        $item['is_direct'] = 1;
                    }
                    $item['team_vip_name'] = null;
                    if (!empty($item['team_vip_level'])) {
                        $item['team_vip_name'] = $teamMemberVdc[$item['team_vip_level']] ?? null;
                    }
                    $item['vip_name'] = null;
                    if (!empty($item['vip_level'])) {
                        $item['vip_name'] = $memberVdc[$item['vip_level']] ?? null;
                    }
                    $item['all_team_count'] = 0;
                    $item['month_team_sales_price'] = 0;
                    $item['today_buy_sales_price'] = 0;
                    $item['month_buy_sales_price'] = 0;
                    if (!empty($item['user_phone'] ?? null)) {
                        $item['user_phone'] = encryptPhone($item['user_phone']);
                    }
                    if (!empty($item['pUser'] ?? []) && !empty($item['pUser']['phone'] ?? null)) {
                        $item['pUser']['phone'] = encryptPhone($item['pUser']['phone']);
                    }
                    return $item;
                })->toArray();
                break;
            default:
                throw new ServiceException(['msg' => '未知的类型']);
        }

        if (empty($memberList)) {
            $finally['pageTotal'] = ceil(($memberCount ?? 0) / $pageNumber);
            $finally['list'] = $memberList ?? [];
            $finally['totalTeamPerformance'] = $UserTeamPerformanceCount ?? 0;
            $finally['monthTeamPerformance'] = $UserMonthTeamPerformanceCount ?? 0;
            $finally['totalUser'] = $memberCount ?? 0;
            return ['error_code' => 0, 'msg' => '成功', 'data' => $finally];
        }

//        //所有销售业绩
//        $UserTeamPerformanceCount = 0;
//        foreach ($memberList as $key => $value) {
//            $UserTeamPerformanceCount += $value['team_sales_price'];
//        }
        $allUid = array_unique(array_column($memberList, 'uid'));

        //查询全部人的团队总人数, 用union的形式来联合查询
        $selectSql = '';
        foreach ($allUid as $key => $value) {
            if ($key != (count($allUid) - 1)) {
                $selectSql .= " (select '" . $value . "' as select_uid, count(*) as number from sp_member where find_in_set('" . $value . "',team_chain) and status = 1) union";
            } else {
                $selectSql .= " (select '" . $value . "' as select_uid, count(*) as number from sp_member where find_in_set('" . $value . "',team_chain) and status = 1)";
            }
        }

        $allUserTeamCount = Db::query(trim($selectSql));
        if (!empty($allUserTeamCount)) {
            foreach ($allUserTeamCount as $key => $value) {
                $allUserTeamCountInfo[$value['select_uid']] = $value['number'] ?? 0;
            }
            foreach ($memberList as $key => $value) {
                if (!empty($allUserTeamCountInfo[$value['uid']] ?? null)) {
                    $memberList[$key]['all_team_count'] = $allUserTeamCountInfo[$value['uid']] ?? 0;
                }
            }
        }


        //查询用户的当月的业绩
        $thisMonthStart = date('Y-m-01', strtotime(date("Y-m-d"))) . ' 00:00:00';
        $thisMonthEnd = date('Y-m-d', strtotime("$thisMonthStart +1 month -1 day")) . ' 23:59:59';

        //查询今日用户自购业绩
        $otdMap[] = ['link_uid', 'in', $allUid];
        $otdMap[] = ['type', '=', 2];
        $otdMap[] = ['status', '=', 1];
        $otdMap[] = ['record_team', '=', 2];
        $otdMap[] = ['record_status', '=', 1];
        $otdMap[] = ['order_create_time', '>=', strtotime(date('Y-m-d').' 00:00:00')];
        $otdMap[] = ['order_create_time', '<=', strtotime(date('Y-m-d').' 23:59:59')];
        $listUserTodayBuySalesPrice = TeamPerformance::where($otdMap)->field('link_uid, sum(real_pay_price - refund_price) as total_price')->group('link_uid')->select()->toArray();
        if (!empty($listUserTodayBuySalesPrice)) {
            foreach ($listUserTodayBuySalesPrice as $key => $value) {
                $listUserTodayBuySalesPriceInfo[$value['link_uid']] = $value['total_price'];
            }
            foreach ($memberList as $key => $value) {
                if (!empty($listUserTodayBuySalesPriceInfo[$value['uid']] ?? null)) {
                    $memberList[$key]['today_buy_sales_price'] = priceFormat($listUserTodayBuySalesPriceInfo[$value['uid']]);
                }
            }
        }

        //查询本月用户自购业绩
        $otmMap[] = ['link_uid', 'in', $allUid];
        $otmMap[] = ['type', '=', 2];
        $otmMap[] = ['status', '=', 1];
        $otmMap[] = ['record_status', '=', 1];
        $otmMap[] = ['order_create_time', '>=', strtotime($thisMonthStart)];
        $otmMap[] = ['order_create_time', '<=', strtotime($thisMonthEnd)];
        $listUserMonthBuySalesPrice = TeamPerformance::where($otmMap)->field('link_uid, sum(real_pay_price - refund_price) as total_price,order_sn')->group('link_uid')->select()->toArray();

        if (!empty($listUserMonthBuySalesPrice)) {
            foreach ($listUserMonthBuySalesPrice as $key => $value) {
                $listUserMonthBuySalesPriceInfo[$value['link_uid']] = $value['total_price'];
            }
            foreach ($memberList as $key => $value) {
                if (!empty($listUserMonthBuySalesPriceInfo[$value['uid']] ?? null)) {
                    $memberList[$key]['month_buy_sales_price'] = priceFormat($listUserMonthBuySalesPriceInfo[$value['uid']]);
                }
            }
        }

        //团队月业绩(每个人的记录)
        $allUidSql = '';
        foreach ($allUid as $key => $value) {
            $allUidSql .= "'".$value."'";
        }
//        $utMap[] = ['', 'exp', Db::raw("(link_uid in (".$allUidSql.")) or (link_uid = '". $uid . "')")];
        $utMap[] = ['', 'exp', Db::raw("(link_uid in (".$allUidSql."))")];
//        $utMap[] = ['', 'exp', Db::raw("(link_uid in (select uid from sp_member where find_in_set('" . $uid . "',team_chain) and status = 1)) or (link_uid = '". $uid . "')")];
        $utMap[] = ['type', '=', 1];
        $utMap[] = ['status', '=', 1];
        $utMap[] = ['record_team', '=', 1];
        $utMap[] = ['record_status', '=', 1];
        $utMap[] = ['order_create_time', '>=', strtotime($thisMonthStart)];
        $utMap[] = ['order_create_time', '<=', strtotime($thisMonthEnd)];

        $allUserTeamPerformance = TeamPerformance::where($utMap)->field('link_uid, sum(real_pay_price - refund_price) as total_price,order_sn')->group('link_uid')->select()->toArray();

        //统计当前用户当月自己的业绩
        $utnMap[] = ['link_uid', '=', $uid];
        $utnMap[] = ['type', '=', 1];
        $utnMap[] = ['status', '=', 1];
        $utnMap[] = ['record_team', '=', 1];
        $utnMap[] = ['record_status', '=', 1];
        $utnMap[] = ['order_create_time', '>=', strtotime($thisMonthStart)];
        $utnMap[] = ['order_create_time', '<=', strtotime($thisMonthEnd)];

        $nowUserTeamPerformance = TeamPerformance::where($utnMap)->field('link_uid, sum(real_pay_price - refund_price) as total_price,order_sn')->group('link_uid')->select()->toArray();
        $UserMonthTeamPerformanceCount = 0;
        if(!empty($nowUserTeamPerformance)){
            $UserMonthTeamPerformanceCount = $nowUserTeamPerformance[0]['total_price'] ?? 0;
        }
        //团队所有业绩, 两种方式, 一种是统计所有记录 一种直接拿冗余字段
//        $taMap[] = ['', 'exp', Db::raw("(link_uid in (select uid from sp_member where find_in_set('" . $uid . "',team_chain) and status = 1)) or (link_uid = '". $uid . "')")];
//        $taMap[] = ['type', '=', 1];
//        $taMap[] = ['status', '=', 1];
//        $taMap[] = ['record_team', '=', 1];
//        $taMap[] = ['record_status', '=', 1];
////        $UserTeamPerformanceCount = TeamPerformance::where($taMap)->field('link_uid, sum(real_pay_price - refund_price) as total_price')->sum('total_price');
//        $UserTeamPerformanceCountSql = TeamPerformance::where($taMap)->field('order_sn, real_pay_price, refund_price')->group('order_sn')->buildSql();
//        $UserTeamPerformanceCount = Db::table($UserTeamPerformanceCountSql.' a')->field('sum(a.real_pay_price - a.refund_price) as total_price')->select()->toArray();
//        if(!empty($UserTeamPerformanceCount)){
//            $UserTeamPerformanceCount = array_shift($UserTeamPerformanceCount)['total_price'] ?? 0;
//        }else{
//            $UserTeamPerformanceCount = 0;
//        }
        $UserTeamPerformanceCount = $userInfo['team_sales_price'] ?? 0;

        if (!empty($allUserTeamPerformance)) {
//            $UserMonthTeamPerformanceCount = [];
//            $UserMonthTeamPerformanceCount = 0;
            $monthTeamPerformanceOrder = [];
            foreach ($allUserTeamPerformance as $key => $value) {
                $allUserTeamPerformanceInfo[$value['link_uid']] = $value['total_price'] ?? 0;
//                if (!isset($UserMonthTeamPerformanceCount[$value['link_uid']])) {
//                    $UserMonthTeamPerformanceCount[$value['link_uid']] = 0;
//                }
                //避免统计重复的订单业绩
//                if (!isset($monthTeamPerformanceOrder[$value['order_sn']])) {
//                    if ($value['link_uid'] == $uid) {
//                        $UserMonthTeamPerformanceCount += $value['total_price'];
//                        $monthTeamPerformanceOrder[$value['order_sn']] = 1;
//                    }
//                }

            }
            foreach ($memberList as $key => $value) {
                if (!empty($allUserTeamPerformanceInfo[$value['uid']] ?? null)) {
                    $memberList[$key]['month_team_sales_price'] = $allUserTeamPerformanceInfo[$value['uid']] ?? 0;
                }
            }
        }

        $finally['pageTotal'] = ceil(($memberCount ?? 0) / $pageNumber);
        $finally['list'] = $memberList ?? [];
        $finally['totalTeamPerformance'] = priceFormat($UserTeamPerformanceCount ?? 0);
        $finally['monthTeamPerformance'] = priceFormat($UserMonthTeamPerformanceCount ?? 0);
        $finally['totalUser'] = intval($memberCount ?? 0);
        $aReturn = ['error_code' => 0, 'msg' => '成功', 'data' => $finally];
        cache($cacheKey, $aReturn, 600);

        return $aReturn;
    }


}