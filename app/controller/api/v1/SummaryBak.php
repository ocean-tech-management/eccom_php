<?php
// +----------------------------------------------------------------------
// |[ 文档说明: ]
// +----------------------------------------------------------------------
// | Copyright (c) 2018~2024 http://www.mlhcmk.com All rights reserved.
// +----------------------------------------------------------------------
 

namespace app\controller\api\v1;


use app\BaseController;
use app\lib\exceptions\ServiceException;
use app\lib\models\Member as MemberModel;
use app\lib\models\Member;
use app\lib\models\MemberVdc;
use app\lib\models\Order;
use app\lib\services\Divide;
use app\lib\models\User;
use app\lib\models\Divide as DivideModel;
use think\facade\Db;

class SummaryBak extends BaseController
{
    protected $middleware = [
        'checkApiToken',
    ];

    /**
     * @title  我的业绩
     * @return mixed
     * @throws \Exception
     */
    public function myPerformance()
    {
        $uid = $this->request->param('uid');
        $orderTeamType = [1 => '直属团队', 2 => '整个团队'];
        $divide = (new Divide());
        $member = (new MemberModel());
        $aMemberInfo = $member->with(['user'])->where(['uid' => $uid, 'status' => 1])->field('uid,user_phone,member_card,level,type,link_superior_user,child_team_code,team_code,parent_team,growth_value')->findOrEmpty()->toArray();
        $aUserLevel = $aMemberInfo['level'] ?? 0;
        if (empty($aUserLevel)) {
            throw new ServiceException(['msg' => '非会员无法查看哦~~']);
        }
        //直推人数
        $directTeam = $divide->getNextDirectLinkUserGroupByLevel($aMemberInfo['uid']);
        //团队全部人数
        $allTeam = $divide->getTeamAllUserGroupByLevel($aMemberInfo['uid']);

        $nextLevel = ($aUserLevel - 1);
        if ($nextLevel > 0) {
            $aNextLevel = MemberVdc::where(['status' => 1, 'level' => ($aUserLevel - 1)])->order('level desc')->findOrEmpty()->toArray();
        } else {
            $aNextLevel = MemberVdc::where(['status' => 1, 'level' => $aUserLevel])->order('level desc')->findOrEmpty()->toArray();
        }
        $allLevel = MemberVdc::order('level desc')->column('name', 'level');

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
                        $aims[$allCount]['nowNumber'] = $directTeam['userLevel'][$value]['count'] ?? 0;
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
                        $aims[$allCount]['nowNumber'] = $allTeam['userLevel'][$value]['count'] ?? 0;
                        $allCount++;
                    }

                }
            }

        }

        //成长值
        $aims[$allCount]['title'] = '成长值';
        $aims[$allCount]['aimsNumber'] = $aNextLevel['growth_value'];
        $aims[$allCount]['nowNumber'] = $aMemberInfo['growth_value'] ?? 0;

        return returnData($aims ?? []);
    }

    /**
     * @title  我的直推团队
     * @return mixed
     * @throws \Exception
     */
    public function myDirectTeam()
    {
        $uid = $this->request->param('uid');
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
        return returnData($finally ?? []);
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
        $divide = (new Divide());
        $member = (new MemberModel());
        $aMemberInfo = $member->with(['pUser', 'userInfo'])->where(['uid' => $uid, 'status' => 1])->field('uid,user_phone,member_card,level,type,link_superior_user,child_team_code,team_code,parent_team,create_time')->findOrEmpty()->toArray();
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
            case 3:
                //查找团队全部人
                $allTeam = !empty($cacheList) ? $cacheList : $divide->getTeamAllUserGroupByLevel($aMemberInfo['uid']);
                break;
            case 2:
                $allTeam = !empty($cacheList) ? $cacheList : $divide->getNextDirectLinkUserGroupByLevel($aMemberInfo['uid']);
                break;
        }
        $finally['TeamAllUserLevelNumber'] = [];
        $finally['TeamAllUserNumber'] = 0;
        $todayNumber = [];
        $MonthNumber = [];
        $AllNumber = [];

        if (!empty($allTeam)) {
            //加入缓存
            cache($cacheKey, $allTeam, 600);

            $allUserUid = $allTeam['allUser']['onlyUidList'];
            $allUser = $allTeam['allUser']['list'];

            switch ($searType) {
                case 1:
                    $removeTeam = [];
                    foreach ($allUser as $key => $value) {
                        if ($value['level'] <= $aMemberInfo['level'] || in_array($value['link_superior_user'], $removeTeam)) {
                            $removeTeam[] = $value['uid'];
                            unset($allUser[$key]);
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
            }
            $memberTitle = MemberVdc::where(['status' => 1])->order('level asc')->column('name', 'level');
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
                foreach ($AllUserLevelNumber as $key => $value) {
                    $allType = array_unique(array_column($value, 'type'));
                    $count = count($cValue);
                    foreach ($value as $cKey => $cValue) {
                        foreach ($type as $dKey => $dValue) {
                            if (!in_array($dValue, $allType)) {
                                $AllUserLevelNumber[$key][$count]['number'] = 0;
                                $AllUserLevelNumber[$key][$count]['level'] = $cValue['level'];
                                $AllUserLevelNumber[$key][$count]['type'] = $dValue;
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
                    }
                    if (!isset($todayNumber[$level])) {
                        $todayNumber[$level]['title'] = $memberTitle[$level];
                        $todayNumber[$level]['number'] = 0;
                    }
                    if (!isset($MonthNumber[$level])) {
                        $MonthNumber[$level]['title'] = $memberTitle[$level];
                        $MonthNumber[$level]['number'] = 0;
                    }
                    //统计全部
                    $AllNumber[$level]['number'] += 1;
                    //统计当天
                    $value['create_time'] = timeToDateFormat($value['create_time']);
                    if (substr($value['create_time'], 0, 10) == date('Y-m-d')) {
                        $todayNumber[$level]['number'] += 1;
                    }
                    //统计当月
                    if ((strtotime($value['create_time']) >= strtotime($thisMonthStart)) && (strtotime($value['create_time']) <= strtotime($thisMonthEnd))) {
                        $MonthNumber[$level]['number'] += 1;
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

            $finally['todayNewNumber'] = array_values($todayNumber);
            $finally['MonthNewNumber'] = array_values($MonthNumber);
            $finally['AllNumber'] = array_values($AllNumber);
            $finally['TeamAllUserLevelNumber'] = array_values($finallyAllUserLevelNumbersSort ?? []);

            $finally['TeamAllUserNumber'] = count($allUserUid);
//            $finally['TeamAllUserListNumber'] = count($allUser);

        }
        return returnData($finally ?? []);
    }

    /**
     * @title  我的全部团队
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

        if (empty($userList)) {
            $uid = $data['uid'] ?? '';
        } else {
            $uid = $userList['allUser']['uid'];
        }
        $divide = (new Divide());
        $member = (new MemberModel());
        if (!empty($uid)) {
            $aMemberInfo = $member->with(['pUser'])->where(['uid' => $uid, 'status' => 1])->field('uid,user_phone,member_card,level,type,link_superior_user,child_team_code,team_code,parent_team,create_time')->findOrEmpty()->toArray();
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
                case 3:
                    //查找团队全部人
                    $allTeam = !empty($cacheList) ? $cacheList : $divide->getTeamAllUserGroupByLevel($aMemberInfo['uid'], ',u2.create_time desc');
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
                //加入缓存
                cache($cacheKey, $allTeam, 600);
            }

            $allUserUid = $allTeam['allUser']['onlyUidList'];
            $allUser = $allTeam['allUser']['list'];

            switch ($searType) {
                case 1:
                    $removeTeam = [];
                    foreach ($allUser as $key => $value) {
                        $allUser[$key]['create_time'] = timeToDateFormat($value['create_time']);
                        if ($value['level'] <= $aMemberInfo['level'] || in_array($value['link_superior_user'], $removeTeam)) {
                            $removeTeam[] = $value['uid'];
                            unset($allUser[$key]);
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
            if (!empty($page)) {
                $start = ($page - 1) * $pageNumber;
                $end = $start + ($pageNumber - 1);
                $allUser = array_slice($allUser, $start, $pageNumber);
                $allUserUid = array_column($allUser, 'uid');
            }
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

            $myselfOrderTotalPrice = [];
            $myselfOrderMonthPrice = [];
            foreach ($myselfOrder as $key => $value) {
                foreach ($value['goods'] as $gKey => $gValue) {
                    if (!isset($myselfOrderTotalPrice[$value['uid']])) {
                        $myselfOrderTotalPrice[$value['uid']] = 0;
                    }
                    if (!isset($myselfOrderMonthPrice[$value['uid']])) {
                        $myselfOrderMonthPrice[$value['uid']] = 0;
                    }
                    $myselfOrderTotalPrice[$value['uid']] += $gValue['total_price'];
                    //统计本月
                    if ($value['create_time'] >= $thisMonthStart && $value['create_time'] <= $thisMonthEnd) {
                        $myselfOrderMonthPrice[$value['uid']] += $gValue['total_price'];
                    }
                }
            }

            $finally['myInfo']['user_order_price'] = 0;
            $finally['myInfo']['user_month_order_price'] = 0;


            $indOrderPrice = [];
            $indWillInComePrice = [];
            $indAllOrder = [];
            $indOrderPriceMonth = [];
            $indWillInComePriceMonth = [];
            $indAllOrderMonth = [];
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
                        $indOrderPriceMonth[$key] += ($myselfOrderMonthPrice[$key] ?? 0);
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
                }
            }


            $memberTitle = MemberVdc::where(['status' => 1])->order('level asc')->column('name', 'level');
            $memberTitle[0] = '普通用户';
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
                            $allUser[$key]['vip_name'] = $memberTitle[$uValue['vip_level']];
                            if (!empty($uValue['link_user_phone'])) {
                                $allUser[$key]['link_user_phone'] = encryptPhone($uValue['link_user_phone']);
                            } else {
                                $allUser[$key]['link_user_phone'] = '用户暂未绑定手机号码';
                            }
                            $allUser[$key]['link_user_name'] = $uValue['link_user_name'];
                            $allUser[$key]['link_user_level'] = $uValue['link_user_level'];
                            $allUser[$key]['link_user_level_name'] = $memberTitle[$uValue['link_user_level']];
                        }
                    }
                }
            }


            $finally['TeamAllUserList'] = $allUser ?? [];
            $finally['TeamAllUserListNumber'] = $maxCount;
            $finally['TeamAllUserListPageNumber'] = $pageNumber ?? 10;
            $finally['TeamAllUserPageNumber'] = ceil($maxCount / $pageNumber);

        }
        return returnData($finally ?? []);
    }

    /**
     * @title  查找我的团队
     * @return string
     * @throws \Exception
     */
    public function searMyTeam()
    {
        $data = $this->requestData;
        $first = (new MemberModel())->searTeamUser($data);
        $list = $this->myAllTeam(['userList' => $first, 'page' => $data['page'] ?? 1, 'searType' => $data['searType'] ?? 1])->getData('data');
        if (!empty($list) && !empty($list['data'])) {
            $list = $list['data'] ?? [];
        }
        return returnData($list);
    }

    /**
     * @title  我的同级直推团队
     * @return string
     * @throws \Exception
     */
    public function sameLevelDirectTeam()
    {
        $uid = $this->request->param('uid');
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

        return returnData($finally ?? []);
    }

    /**
     * @title  我的同级间推团队
     * @return string
     * @throws \Exception
     */
    public function nextDirectTeam()
    {
        $uid = $this->request->param('uid');
        $lastFinally = $this->sameLevelDirectTeam()->getData();
        $lastFinally = $lastFinally['data'];
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
        return returnData($finally ?? []);
    }

    /**
     * @title  个人余额报表
     * @return mixed
     * @throws \Exception
     */
    public function balanceAll()
    {
        $uid = $this->request->param('uid');
        $userInfo = User::where(['uid' => $uid])->field('uid,divide_balance,divide_withdraw_total')->findOrEmpty();
        $finally['total_balance'] = $userInfo['divide_balance'];
        $finally['withdraw_total'] = $userInfo['divide_withdraw_total'];
        $divideInfo = DivideModel::where(['link_uid' => $userInfo['uid'], 'arrival_status' => [1, 2, 4], 'status' => [1, 2]])->field('real_divide_price,arrival_status')->select()->toArray();
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
        return returnData($finally ?? []);
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
        }

        $map[] = ['link_uid', '=', $uid];
        $map[] = ['arrival_status', 'in', [1, 2, 4]];
        $map[] = ['status', 'in', [1, 2]];
        $aDivide = DivideModel::where($map)->field('real_divide_price,arrival_status,total_price,purchase_price,divide_type')->select()->toArray();

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

        foreach ($aDivide as $key => $value) {
            if (!empty(doubleval($value['real_divide_price']))) {
                $orderPrice += $value['total_price'];
                $purchasePrice += $value['purchase_price'];
                $willInComePrice += $value['real_divide_price'];
                $allOrder += 1;
                if ($value['divide_type'] == 1) {
                    $dirOrderPrice += $value['total_price'];
                    $dirPurchasePrice += $value['purchase_price'];
                    $dirWillInComePrice += $value['real_divide_price'];
                    $dirAllOrder += 1;
                }
                if ($value['divide_type'] == 2) {
                    $indOrderPrice += $value['total_price'];
                    $indPurchasePrice += $value['purchase_price'];
                    $indWillInComePrice += $value['real_divide_price'];
                    $indAllOrder += 1;
                }
            }
        }

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
//        $finally['indirect']['order_price'] = priceFormat((string)((priceFormat($indOrderPrice) ?? 0) + $myselfOrderTotalPrice));
//        $finally['indirect']['purchase_price'] = priceFormat((string)((priceFormat($indPurchasePrice) ?? 0) + $myselfOrderTotalPrice));
        $finally['indirect']['order_price'] = (priceFormat($indOrderPrice) ?? 0);
        $finally['indirect']['purchase_price'] = (priceFormat($indPurchasePrice) ?? 0);
        $finally['indirect']['will_income_total'] = priceFormat($indWillInComePrice) ?? 0;
        $finally['indirect']['pay_order_number'] = $indAllOrder ?? 0;

        return returnData($finally ?? []);

    }

    /**
     * @title  用户收益情况
     * @return string
     */
    public function userAllInCome()
    {
        $uid = $this->request->param('uid');
        //今日收益
        $data['start_time'] = date('Y-m-d', time()) . ' 00:00:00';
        $data['end_time'] = date('Y-m-d', time()) . ' 23:59:59';

        $map[] = ['create_time', 'between', [strtotime($data['start_time']), strtotime($data['end_time'])]];
        $map[] = ['link_uid', '=', $uid];
        $map[] = ['arrival_status', 'in', [1, 2, 4]];
        $map[] = ['status', 'in', [1]];

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
        $monthDivide = DivideModel::where($map2)->sum('real_divide_price');

        //上月收益
        $thisMonthStart = date('Y-m-01', strtotime('-1 month')) . ' 00:00:00';
        $thisMonthEnd = date('Y-m-t', strtotime('-1 month')) . ' 23:59:59';
        $data['start_time'] = $thisMonthStart;
        $data['end_time'] = $thisMonthEnd;
        $map3[] = ['create_time', 'between', [strtotime($data['start_time']), strtotime($data['end_time'])]];
        $map3[] = ['link_uid', '=', $uid];
        $map3[] = ['arrival_status', 'in', [1, 2, 4]];
        $map3[] = ['status', 'in', [1]];
        $lastMonthDivide = DivideModel::where($map3)->sum('real_divide_price');

        //累计收益
        $map4[] = ['link_uid', '=', $uid];
        $map4[] = ['arrival_status', 'in', [1, 2, 4]];
        $map4[] = ['status', 'in', [1]];
        $allDivide = DivideModel::where($map4)->sum('real_divide_price');

        $finally['todayInCome'] = priceFormat($todayDivide) ?? 0;
        $finally['MonthInCome'] = priceFormat($monthDivide) ?? 0;
        $finally['lastMonthInCome'] = priceFormat($lastMonthDivide) ?? 0;
        $finally['allInCome'] = priceFormat($allDivide) ?? 0;

        return returnData($finally);

    }

    /**
     * @title  用户团队全部成员数量
     * @return string
     */
    public function teamAllUserNumber()
    {
        $uid = $this->request->param('uid');
        $divide = (new Divide());
        $member = (new MemberModel());
        $aMemberInfo = $member->where(['uid' => $uid, 'status' => 1])->field('uid,user_phone,member_card,level,type,link_superior_user,child_team_code,team_code,parent_team')->findOrEmpty()->toArray();
        $finally['memberInfo'] = $aMemberInfo ?? [];
        $finally['TeamAllUserNumber'] = 0;
        if (!empty($aMemberInfo)) {
            //团队全部人数
            $allTeam = $divide->getTeamAllUserGroupByLevel($aMemberInfo['uid']);
            $finally['TeamAllUserNumber'] = $allTeam['allUser']['count'] ?? 0;
        }

        return returnData($finally);
    }


}