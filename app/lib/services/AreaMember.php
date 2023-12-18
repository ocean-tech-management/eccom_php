<?php
// +----------------------------------------------------------------------
// |[ 文档说明: 区代会员模块Service]
// +----------------------------------------------------------------------
// | Copyright (c) 2018~2024 http://www.mlhcmk.com All rights reserved.
// +----------------------------------------------------------------------


namespace app\lib\services;

use app\lib\exceptions\FileException;
use app\lib\exceptions\MemberException;
use app\lib\exceptions\ParamException;
use app\lib\exceptions\ServiceException;
use app\lib\exceptions\UserException;
use app\lib\models\AreaMemberVdc;
use app\lib\models\GrowthValueDetail;
use app\lib\models\Member;
use app\lib\models\AreaMember as AreaMemberModel;
use app\lib\models\Member as MemberModel;
use app\lib\models\MemberOrder;
use app\lib\models\MemberTest;
use app\lib\models\MemberVdc;
use app\lib\models\PpylMemberVdc;
use app\lib\models\PpylOrder;
use app\lib\models\Reward;
use app\lib\models\Order;
use app\lib\models\Divide as DivideModel;
use app\lib\models\ShareholderMember;
use app\lib\models\ShareholderMemberVdc;
use app\lib\models\TeamMember as TeamMemberModel;
use app\lib\models\TeamMemberVdc;
use app\lib\models\TeamPerformance;
use app\lib\models\User;
use app\lib\models\UserTest;
use app\lib\services\Member as MemberService;
use think\facade\Db;
use think\facade\Queue;

class AreaMember
{
    private $belong = 1;
    protected $becomeLevelTwoPullNewMember = 1;
    public $memberUpgradeOrderKey = 'areaMemberUpgradeOrder';
    protected $canNotOPERUidList = [];

    //导入会员专用变量
    protected $topUserTeamStartNumber = [];
    protected $lastMemberCount = 0;
    protected $notTopUserStartUser = 0;
    protected $TeamHierarchy = [];
    //导入会员团队冗余结构专用变量
    public $teamChainArray = [];

    //统计用户业绩指定活动
    public $activitySign = [2, 3, 4, 9, 15, 16];

    /**
     * @title  会员等级升级
     * @param string $uid 用户uid
     * @param bool $topUserUpgrade 是否需要递归查找上级是否可升级
     * @return mixed
     * @throws \Exception
     */
    public function memberUpgrade(string $uid, bool $topUserUpgrade = true)
    {
        $log['uid'] = $uid;
        $log['type'] = '会员等级升级';
        $upgradeRes = false;
        $member = (new AreaMemberModel());
        $divide = (new AreaDivide());
        $aMemberInfo = $member->where(['uid' => $uid, 'status' => 1])->field('uid,user_phone,member_card,level,type,type,link_superior_user,team_sales_price,team_sales_price_offset')->findOrEmpty()->toArray();
        $aUserLevel = $aMemberInfo['level'] ?? 0;
        $aUserInfo = User::where(['uid'=>$uid,'status'=>1])->findOrEmpty()->toArray();

        //非会员无法升级
        if (empty($aMemberInfo) || empty($aUserLevel) || empty($aUserInfo) || (!empty($aUserInfo) && (empty($aUserInfo['area_vip_level'] || empty($aUserInfo['vip_level']))))) {
            $log['res'] = $upgradeRes;
            $log['userInfo'] = $aUserInfo;
            $log['teamMemberInfo'] = $aMemberInfo;
            $log['upgradeTopUser'][] = $this->memberTopUserUpgrade($aMemberInfo['link_superior_user'] ?? '');
            return $this->recordError($log, ['msg' => '非会员无法升级']);
        }
        //判断该等级是否允许继续自主升级(如果没有上级的自由用户不允许继续往上升级),VIP不允许自主升级
        $allowUpgrade = AreaMemberVdc::where(['status' => 1, 'level' => $aUserLevel])->value('close_upgrade');
        if (($allowUpgrade == 1 && (empty($aMemberInfo['link_superior_user']) && $aMemberInfo['type'] == 1))) {
            $log['upgradeTopUser'][] = $this->memberTopUserUpgrade($aMemberInfo['link_superior_user'] ?? '');
            return $this->recordError($log, ['msg' => '已关闭自主升级通道,当前等级已为顶级,如有疑惑可联客服专员']);
        }

        $aNextLevel = AreaMemberVdc::where(['status' => 1, 'level' => ($aUserLevel - 1)])->order('level desc')->findOrEmpty()->toArray();

        //没有找到对应的等级规则 则不升级
        if (empty($aNextLevel)) {
            $log['upgradeTopUser'][] = $this->memberTopUserUpgrade($aMemberInfo['link_superior_user'] ?? '');
            return $this->recordError($log, ['msg' => '没有找到对应的等级规则 不升级']);
        }

        //判断销售额
        if ((string)($aMemberInfo['team_sales_price']) < (string)$aNextLevel['sales_price']) {
            $log['upgradeTopUser'][] = $this->memberTopUserUpgrade($aMemberInfo['link_superior_user'] ?? '');
            return $this->recordError($log, ['msg' => '团队销售额不足' . $aNextLevel['sales_price'] . ' ,无法升级!']);
        }

        $directMemberRes = false;

        //判断队伍成员人数
        if ($aNextLevel['need_team_condition'] == 1) {
            //查看需要直推的商城会员数量
            $directMemberRes = false;
            if (!empty($aNextLevel['recommend_member_number'] ?? 0)) {
                $directMemberTeam = $divide->getNextDirectLinkUserGroupByLevel($aMemberInfo['uid'], -1);
                if ($aNextLevel['recommend_member_number'] <= ($directMemberTeam['userLevel'][0]['count'] ?? 0)) {
                    $log['directMemberRes'] = '直推商城会员团队升级人数符合要求';
                    $directMemberRes = true;
                } else {
                    $log['directMemberRes'] = '直推商城会员团队升级人数不符合要求,当前为' . ($directMemberTeam['userLevel'][0]['count'] ?? 0) . '人, 需要' . $aNextLevel['recommend_member_number'] . '人';
                }
            }else{
                $log['directMemberRes'] = '直推商城会员团队升级人数符合要求[无此要求]';
                $directMemberRes = true;
            }

            $directTeam = $divide->getNextDirectLinkUserGroupByLevel($aMemberInfo['uid']);

            $allTeam = $divide->getTeamAllUserGroupByLevel($aMemberInfo['uid']);
            $log['directTeam'] = $directTeam;
            $log['allTeam'] = $allTeam;

            //检查直推成员中升级指定的等级和人数是否符合条件
            $directRes = false;
            $log['directMsg'] = '直推团队升级指定的等级和人数不符合要求';
            if (!empty($directTeam)) {
                if (!empty($directTeam['userLevel'])) {
                    $recommendLevel = json_decode($aNextLevel['recommend_level'], true);
                    $recommendNumber = json_decode($aNextLevel['recommend_number'], true);
                    $directAccessNumber = 0;
                    $directConditionNumber = count($recommendLevel);
                    if (!empty($recommendLevel)) {
                        foreach ($recommendLevel as $key => $value) {
                            if (isset($directTeam['userLevel'][$value])) {
                                if ($recommendNumber[$key] <= $directTeam['userLevel'][$value]['count']) {
                                    $directAccessNumber += 1;
                                }
                            }
                        }
                        if ($directAccessNumber == $directConditionNumber) {
                            $log['directMsg'] = '直推团队升级指定的等级和人数符合要求';
                            $directRes = true;
                        }
                    } else {
                        if (isset($directTeam['userLevel'][$aNextLevel['recommend_level']])) {
                            if ($aNextLevel['recommend_number'] <= $directTeam['userLevel'][$aNextLevel['recommend_level']]['count']) {
                                $log['directMsg'] = '直推团队升级指定的等级和人数符合要求';
                                $directRes = true;
                            }
                        }
                    }

                }
            }

            if (empty($directRes) && empty(json_decode($aNextLevel['recommend_level'], true))) {
                //如果空数组则标识不需要判断直推人数
                $log['directMsg'] = '直推团队升级指定的等级和人数符合要求';
                $directRes = true;
            }

            $log['directRes'] = $directRes;

            //检查全部成员中升级指定的等级和人数是否符合条件
            $allRes = false;
            $log['allMsg'] = '全部团队升级指定的等级和人数不符合要求';
            if (!empty($allTeam)) {
                if (!empty($allTeam['userLevel'])) {
                    $allTeamLevel = json_decode($aNextLevel['train_level'], true);
                    $allTeamNumber = json_decode($aNextLevel['train_number'], true);
                    if (!empty($allTeamLevel)) {
                        $allAccessNumber = 0;
                        $allConditionNumber = count($allTeamLevel);
                        foreach ($allTeamLevel as $key => $value) {
                            if (isset($allTeam['userLevel'][$value])) {
                                if ($allTeamNumber[$key] <= $allTeam['userLevel'][$value]['count']) {
                                    $allAccessNumber += 1;
                                }
                            }
                        }
                        if ($allAccessNumber == $allConditionNumber) {
                            $log['allMsg'] = '全部团队升级指定的等级和人数符合要求';
                            $allRes = true;
                        }
                    } else {
                        if (isset($allTeam['userLevel'][$aNextLevel['train_level']])) {
                            if ($aNextLevel['train_number'] <= $allTeam['userLevel'][$aNextLevel['train_level']]['count']) {
                                $log['allMsg'] = '全部团队升级指定的等级和人数符合要求';
                                $allRes = true;
                            }

                        }
                    }
                }
            }
            //如果空数组则标识不需要判断团队人数
            if (empty($allRes) && empty(json_decode($aNextLevel['train_level'], true))) {
                $log['allMsg'] = '全部团队升级指定的等级和人数符合要求';
                $allRes = true;
            }

            $log['allRes'] = $allRes;

        } else {
            $directRes = true;
            $allRes = true;
            $directMemberRes = true;
        }

        $log['nextLevelRule'] = $aNextLevel;
        $log['upgradeRes'] = false;

        $upgradeRes = false;
        //同时满足全部条件才能升级
        if (!empty($directRes) && !empty($allRes) && !empty($directMemberRes)) {
            $log['upgradeRes'] = true;
            $log['upgradeMsg'] = '该用户 [' . $aMemberInfo['uid'] . ' - ' . $aMemberInfo['user_phone'] . '] 当前等级为' . $aMemberInfo['level'] . ' 经系统检测符合升级,等级即将升级为' . $aNextLevel['level'];
            $upgradeRes = Db::transaction(function () use ($aNextLevel, $uid) {
                $userSave['area_vip_level'] = $aNextLevel['level'];
                $memberSave['level'] = $aNextLevel['level'];
                $memberSave['upgrade_time'] = time();
                $memberSave['demotion_team_sales_price'] = $aNextLevel['demotion_sales_price'] ?? $aNextLevel['sales_price'];
                $user = User::update($userSave, ['uid' => $uid]);
                $member = AreaMemberModel::update($memberSave, ['uid' => $uid]);

                //修改分润第一人冗余结构
                //先修改自己的,然后修改自己整个团队下级的
                $dRes = $this->refreshDivideChain(['uid' => $uid]);
                //团队的用队列形式,避免等待时间太长
                $dtRes = Queue::later(10, 'app\lib\job\AreaMemberChain', ['searUser' => $uid, 'handleType' => 1], config('system.queueAbbr') . 'AreaMemberChain');

                return $user->getData();
            });
        } else {
            $log['upgradeRes'] = false;
            $log['upgradeMsg'] = '该用户 [' . $aMemberInfo['uid'] . ' - ' . $aMemberInfo['user_phone'] . '] 当前等级为' . $aMemberInfo['level'] . ' 经系统检测不符合升级成为等级' . $aNextLevel['level'] . '的条件';
        }
        //发送消息模版---暂未完成----start

        //发送消息模版---暂未完成----end

        $log['res'] = $upgradeRes;
        $this->log($log, 'info');

        //如果有成功升级且存在上级则判断上级是否也会因此而升级
        if (!empty($topUserUpgrade)) {
            if (!empty($aMemberInfo['link_superior_user'])) {
                $topRes = $this->memberUpgrade($aMemberInfo['link_superior_user']);
            }
        }

        //如果当前有升级成功, 尝试重新推送本人查看是否能够继续升一级
        if (!empty($log['upgradeRes'] ?? false)) {
            $upgradeQueue = Queue::push('app\lib\job\AreaMemberUpgrade', ['uid' => $uid], config('system.queueAbbr') . 'AreaMemberUpgrade');
        }

        //return !empty($upgradeRes) ? $upgradeRes->getData() : [];
        return judge($upgradeRes);

    }

    /**
     * @title  升级会员的上级
     * @param string $uid
     * @param bool $topUserUpgrade
     * @return bool|mixed
     * @throws \Exception
     */
    public function memberTopUserUpgrade(string $uid, bool $topUserUpgrade = true)
    {
        if (!empty($topUserUpgrade)) {
            if (!empty($uid)) {
                $topRes = $this->memberUpgrade($uid, $topUserUpgrade);
                $log['upgradeTopUser'][] = $topRes;
                return $topRes;
            }
        }
        return false;
    }

    /**
     * @title  升级成为区代
     * @param array $orderInfo
     * @return mixed
     * @throws \Exception
     */
    public function becomeMember(array $orderInfo)
    {
        $res['res'] = false;
        $res['msg'] = '等待处理中';
        $log['orderInfo'] = $orderInfo;
        $log['uid'] = $orderInfo['uid'];
        $log['type'] = '升级成为会员';
        //默认升级的等级为3,最低一级
        $memberFirstLevel = 3;
        $memberFirstLevelGrowthValue = 0;
        $notTeamMemberInfo = false;

        if (!empty($orderInfo)) {

            $mUser = User::where(['uid' => $orderInfo['uid']])->findOrEmpty()->toArray();
            $memberVdc = AreaMemberVdc::where(['status' => 1])->order('level asc')->select()->toArray();
            $memberFirstLevel = max(array_column($memberVdc,'level'));
            if(empty($mUser['vip_level'] ?? 0)){
                $res['res'] = false;
                $res['msg'] = '非商城会员无法成为区代会员';
                $this->recordError($log, ['msg' => $res['msg']]);
                return $res;
            }
            if (empty($memberVdc)) {
                $res['res'] = false;
                $res['msg'] = '暂无有效的会员等级制度';
                $this->recordError($log, ['msg' => $res['msg']]);
                return $res;
            }
            foreach ($memberVdc as $key => $value) {
                $levelName[$value['level']] = $value['name'];
                if ($value['level'] == $memberFirstLevel) {
                    $memberFirstLevelGrowthValue = $value['sales_price'] ?? 0;
                    $memberFirstLevelDemotionGrowthValue = $value['demotion_sales_price'] ?? ($value['sales_price'] ?? 0);
                    $memberFirstLevelInfo = $value;
                }
                $levelInfo[$value['level']] = $value;
            }
            $levelName[0] = '普通用户';

            $log['userInfo'] = $mUser ?? [];
            $orderTopUser = [];
            $topUser = !empty($mUser['link_superior_user']) ? $mUser['link_superior_user'] : ($orderInfo['link_superior_user'] ?? null);
            if (!empty($orderInfo['link_superior_user'])) {
                $orderTopUser = AreaMemberModel::where(['uid' => $topUser])->findOrEmpty()->toArray();
            }
//            //如果没有团队会员上级则尝试找原来的普通用户上级或会员上级
//            if(empty($orderTopUser)){
//                $orderTopUser = MemberModel::where(['uid' => $topUser])->findOrEmpty()->toArray();
//                $notTeamMemberInfo = true;
//            }

            $log['topUserInfo'] = $orderTopUser ?? [];
            if (!empty($mUser)) {
                if (intval($mUser['area_vip_level']) > 0) {
                    $res['res'] = false;
                    $res['msg'] = $orderInfo['uid'] . '该用户已经为' . ($levelName[$mUser['area_vip_level']] ?? " <未知等级> ") . ',无法继续绑定升级';
                    $this->recordError($log, ['msg' => $res['msg']]);
                    return $res;
                }


                //判断销售额
                $userTotalSalePrice = 0;
                //统计该用户的销售额
                $sMap[] = ['a.link_uid','=',$orderInfo['uid']];
                $sMap[] = ['a.status','=',1];
                $sMap[] = ['', 'exp', Db::raw('b.real_pay_price - b.refund_price > 0 and b.activity_sign in (' . implode(',', $this->activitySign). ')')];

                $userTotalSalePriceList = TeamPerformance::alias('a')
                    ->join('sp_order_goods b','a.order_sn = b.order_sn','left')
                    ->where($sMap)->field('a.order_sn,a.link_uid,b.refund_price,b.real_pay_price,b.total_fare_price')->select()->toArray();
                if(!empty($userTotalSalePriceList)){
                    foreach ($userTotalSalePriceList as $key => $value) {
                        if($value['real_pay_price'] - $value['refund_price'] > 0){
                            $userTotalSalePrice += ($value['real_pay_price'] - $value['refund_price']);
                        }
                    }
                }

                if ((string)$userTotalSalePrice < (string)$memberFirstLevelGrowthValue) {
                    $log['upgradeTopUser'][] = $this->memberTopUserUpgrade($aMemberInfo['link_superior_user'] ?? '');
                    return $this->recordError($log, ['msg' => '团队销售额不足' . $memberFirstLevelGrowthValue . ' ,无法升级!']);
                }

                //判断队伍成员人数
                $divide = (new TeamDivide());
                $aNextLevel = $memberFirstLevelInfo;
                if ($aNextLevel['need_team_condition'] == 1) {
                    //查看需要直推的商城会员数量
                    $directMemberRes = false;
                    if (!empty($aNextLevel['recommend_member_number'] ?? 0)) {
                        $directMemberTeam = $divide->getNextDirectLinkUserGroupByLevel($orderInfo['uid'], -1);
                        if ($aNextLevel['recommend_member_number'] <= ($directMemberTeam['userLevel'][0]['count'] ?? 0)) {
                            $log['directMemberRes'] = '直推商城会员团队升级人数符合要求';
                            $directMemberRes = true;
                        } else {
                            $log['directMemberRes'] = '直推商城会员团队升级人数不符合要求,当前为' . ($directMemberTeam['userLevel'][0]['count'] ?? 0) . '人, 需要' . $aNextLevel['recommend_member_number'] . '人';
                        }
                    }else{
                        $log['directMemberRes'] = '直推商城会员团队升级人数符合要求[无此要求]';
                        $directMemberRes = true;
                    }

                    $directTeam = $divide->getNextDirectLinkUserGroupByLevel($orderInfo['uid']);

                    $allTeam = $divide->getTeamAllUserGroupByLevel($orderInfo['uid']);
                    $log['directTeam'] = $directTeam;
                    $log['allTeam'] = $allTeam;

                    //检查直推成员中升级指定的等级和人数是否符合条件
                    $directRes = false;
                    $log['directMsg'] = '直推团队升级指定的等级和人数不符合要求';
                    if (!empty($directTeam)) {
                        //成为最初级的会员仅判断下级会员全部人数
                        if (!empty($directTeam['userLevel'])) {
                            $recommendLevel = json_decode($aNextLevel['recommend_level'], true);
                            $recommendNumber = json_decode($aNextLevel['recommend_number'], true);
                            $directAccessNumber = 0;
                            $directConditionNumber = count($recommendLevel);
                            if (!empty($recommendLevel)) {
                                foreach ($recommendLevel as $key => $value) {
                                    if (isset($directTeam['userLevel'][$value])) {
                                        if ($recommendNumber[$key] <= $directTeam['userLevel'][$value]['count']) {
                                            $directAccessNumber += 1;
                                        }
                                    }
                                }
                                if ($directAccessNumber == $directConditionNumber) {
                                    $log['directMsg'] = '直推团队升级指定的等级和人数符合要求';
                                    $directRes = true;
                                }
                            } else {
                                if (isset($directTeam['userLevel'][$aNextLevel['recommend_level']])) {
                                    if ($aNextLevel['recommend_number'] <= $directTeam['userLevel'][$aNextLevel['recommend_level']]['count']) {
                                        $log['directMsg'] = '直推团队升级指定的等级和人数符合要求';
                                        $directRes = true;
                                    }
                                }
                            }

                        }
                    }

                    if (empty(json_decode($aNextLevel['recommend_level'] ?? [],true)) && empty($directRes)) {
                        $log['directMsg'] = '直推团队升级指定的等级和人数符合要求,无需要求';
                        $directRes = true;
                    }

                    $log['directRes'] = $directRes;

                    //检查全部成员中升级指定的等级和人数是否符合条件
                    $allRes = false;
                    $log['allMsg'] = '全部团队升级指定的等级和人数不符合要求';
                    if (!empty($allTeam)) {
                        if (empty($allTeam['userLevel'])) {
                            $allTeamLevel = json_decode($aNextLevel['train_level'], true);
                            $allTeamNumber = json_decode($aNextLevel['train_number'], true);
                            if (!empty($allTeamLevel)) {
                                $allAccessNumber = 0;
                                $allConditionNumber = count($allTeamLevel);
                                foreach ($allTeamLevel as $key => $value) {
                                    if (isset($allTeam['userLevel'][$value])) {
                                        if ($allTeamNumber[$key] <= $allTeam['userLevel'][$value]['count']) {
                                            $allAccessNumber += 1;
                                        }
                                    }
                                }
                                if ($allAccessNumber == $allConditionNumber) {
                                    $log['allMsg'] = '全部团队升级指定的等级和人数符合要求';
                                    $allRes = true;
                                }
                            } else {
                                if (isset($allTeam['userLevel'][$aNextLevel['train_level']])) {
                                    if ($aNextLevel['train_number'] <= $allTeam['userLevel'][$aNextLevel['train_level']]['count']) {
                                        $log['allMsg'] = '全部团队升级指定的等级和人数符合要求';
                                        $allRes = true;
                                    }

                                }
                            }
                        }
                    }

                    if (empty(json_decode(($aNextLevel['train_level'] ?? []), true)) && empty($allRes)) {
                        $log['directMsg'] = '全部团队升级指定的等级和人数符合要求,无需要求';
                        $allRes = true;
                    }

                    $log['allRes'] = $allRes;

                } else {
                    $directRes = true;
                    $allRes = true;
                    $directMemberRes = true;
                }

                if(empty($directRes) || empty($allRes) || empty($directMemberRes ?? false)){
                    $res['res'] = false;
                    $res['msg'] = $orderInfo['uid'] . '该用户团队要求不符合要求, 无法继续绑定升级';
                    return $this->recordError($log, ['msg' => $res['msg']]);
                }

                //如果原先作为普通会员但是有推荐过人,存在会员体系中的话,允许重新跟上级要求购买团长大礼包然后重新修改会员体系中的登记信息
                $userMember = AreaMemberModel::where(['uid' => $orderInfo['uid'], 'status' => [1, 2]])->findOrEmpty()->toArray();
                $update['level'] = $memberFirstLevel;
                $update['type'] = 1;
                $update['team_sales_price'] = ($userTotalSalePrice ?? 0);
                $update['demotion_team_sales_price'] = $memberFirstLevelDemotionGrowthValue ?? $memberFirstLevelGrowthValue;
                $update['link_superior_user'] = null;
                if (!empty($orderTopUser)) {
//                    $update['team_code'] = $orderTopUser['team_code'];
//                    $update['parent_team'] = $orderTopUser['child_team_code'];
                    $update['link_superior_user'] = $orderTopUser['uid'];
                }

                if (!empty($userMember)) {
                    if (empty($userMember['level'])) {
                        //如果跟原来的上级不一致,则冗余团队结构采用用新上级的
                        if (!empty($orderTopUser ?? []) && $userMember['link_superior_user'] != $orderTopUser['uid']) {
                            if (empty($orderTopUser['link_superior_user'])) {
                                $update['team_chain'] = $orderTopUser['uid'];
                            } else {
                                //如果上级用户的团队冗余结构为空,防止出错需要重新跑一边然后为当前用户记录团队结构
                                if ($orderTopUser['level'] != 1 && empty($orderTopUser['team_chain'] ?? null)) {
                                    $allTopUser = $this->getMemberRealAllTopUser($orderTopUser['uid']);
                                    if (!empty($allTopUser)) {
                                        array_multisort(array_column($allTopUser, 'divide_level'), SORT_ASC, $allTopUser);
                                        $allTopUid = implode(',', array_column($allTopUser, 'uid'));
                                        $orderTopUser['team_chain'] = $allTopUid ?? '';
                                        $update['team_chain'] = $orderTopUser['team_chain'];
                                    }
                                } else {
                                    if (empty($orderTopUser['team_chain'] ?? '')) {
                                        $update['team_chain'] = $orderTopUser['uid'];
                                    } else {
                                        $update['team_chain'] = $orderTopUser['uid'] . ',' . ($orderTopUser['team_chain']);
                                    }

                                }
                            }
                        }

                        $update['upgrade_time'] = time();
                        $dbRes = AreaMemberModel::update($update, ['uid' => $orderInfo['uid']]);
                        //修改分润第一人冗余结构
                        Queue::push('app\lib\job\AreaMemberChain', ['uid' => $orderInfo['uid'], 'handleType' => 1], config('system.queueAbbr') . 'AreaMemberChain');

                    } else {
                        $res['res'] = false;
                        $res['msg'] = $orderInfo['uid'] . '该用户无法继续绑定升级';
                        $this->recordError($log, ['msg' => $res['msg']]);
                        return $res;
                    }

                } else {
                    $update['member_card'] = (new CodeBuilder())->buildAreaMemberNum();
                    $update['uid'] = $orderInfo['uid'];
                    $update['user_phone'] = $orderInfo['user_phone'] ?? ($mUser['phone'] ?? null);
                    if (!empty($orderTopUser)) {
//                        $update['child_team_code'] = (new TeamMemberModel())->buildMemberTeamCode($update['level'], $update['parent_team']);
                    } else {
//                        $teamCode = (new TeamMemberModel())->buildMemberTeamCode($update['level'], null);
//                        $update['team_code'] = $teamCode;
//                        $update['child_team_code'] = $teamCode;
//                        $update['parent_team'] = null;
                        $update['link_superior_user'] = null;
                    }
                    $update['team_sales_price'] = $mUser['team_sales_price'] ?? 0;
                    $update['demotion_team_sales_price'] = $memberFirstLevelInfo['demotion_sales_price'] ?? $memberFirstLevelInfo['sales_price'];
                    $update['upgrade_time'] = time();
//                    //记录冗余团队结构
                    if (!empty($orderTopUser)) {
                        if (!empty($notTeamMemberInfo)) {
                            //尝试重新找一次上级是否有团队会员
                            $topUser = AreaMemberModel::where(['uid' => $topUser])->findOrEmpty()->toArray();
                            if (!empty($topUser ?? [])) {
                                $orderTopUser = $topUser;
                            }
                        }

                        if (empty($orderTopUser['link_superior_user'])) {
                            $update['team_chain'] = $orderTopUser['uid'];
                        } else {
                            //如果上级用户的团队冗余结构为空,防止出错需要重新跑一边然后为当前用户记录团队结构
                            if (!empty($orderTopUser) && $orderTopUser['level'] != 1 && empty(trim($orderTopUser['team_chain']) ?? null)) {
                                $allTopUser = $this->getMemberRealAllTopUser($orderTopUser['uid']);
                                if (!empty($allTopUser)) {
                                    array_multisort(array_column($allTopUser, 'divide_level'), SORT_ASC, $allTopUser);
                                    $allTopUid = implode(',', array_column($allTopUser, 'uid'));
                                    $orderTopUser['team_chain'] = $allTopUid ?? '';
                                    $update['team_chain'] = $orderTopUser['team_chain'];
                                }
                            } else {
                                if (empty($orderTopUser['team_chain'] ?? '')) {
                                    $update['team_chain'] = $orderTopUser['uid'];
                                } else {
                                    $update['team_chain'] = $orderTopUser['uid'] . ',' . ($orderTopUser['team_chain']);
                                }

                            }
                        }
                    }
                    $dbRes = AreaMemberModel::create($update);

                    //修改分润第一人冗余结构
                    Queue::push('app\lib\job\AreaMemberChain', ['uid' => $update['uid'], 'handleType' => 1], config('system.queueAbbr') . 'AreaMemberChain');
                }

                $userRes = User::update(['area_vip_level' => $update['level']], ['uid' => $mUser['uid']]);

                //(new User())->where(['uid'=>$orderTopUser['uid']])->inc('team_number',1)->update();

                $res['res'] = true;
                $res['msg'] = '订单用户' . $orderInfo['uid'] . '成功升级为LV' . $memberFirstLevel . '(已修改上级)';
                $log['msg'] = $res['msg'];
                $this->log($log, 'info');
            } else {
                $res['res'] = false;
                $res['msg'] = $orderInfo['uid'] . ' 查无改用户,无法继续绑定升级';
                $this->recordError($log, ['msg' => $res['msg']]);
            }
        } else {
            $res['res'] = false;
            $res['msg'] = $orderInfo['uid'] . '的订单不符合推荐要求,无法继续绑定升级';
            $this->recordError($log, ['msg' => $res['msg']]);
            return $res;
        }
        return $res;
    }

    /**
     * @title  指定会员等级
     * @param array $data
     * @throws \Exception
     * @return mixed
     */
    public function assignUserLevel(array $data)
    {
        $uid = $data['uid'];
        $topUser = $data['link_user'] ?? null;
        $level = $data['level'];
        $log['requestData'] = $data;
        $log['uid'] = $uid;
        $log['type'] = '指定会员等级';
        $topUserInfo = [];
        if (!in_array($level, [1, 2, 3, 4])) {
            $this->recordError($log, ['msg' => '所选会员等级非法']);
            throw new MemberException(['msg' => '所选会员等级非法']);
        }
        if (!empty($topUser) && ($uid == $topUser)) {
            $this->recordError($log, ['msg' => '不允许选自己为上级']);
            throw new MemberException(['msg' => '不允许选自己为上级']);
        }

        $userInfo = User::with(['areaMember'])->where(['uid' => $uid, 'status' => 1])->findOrEmpty()->toArray();
        if (!empty($topUser)) {
            $topUserInfo = User::with(['areaMember'])->where(['uid' => $topUser, 'status' => 1])->findOrEmpty()->toArray();
        }
        $log['userInfo'] = $userInfo ?? [];
        $log['topUserInfo'] = $topUserInfo ?? [];

        if (empty($userInfo) && empty($topUserInfo)) {
            $this->recordError($log, ['msg' => '会员信息异常']);
            throw new UserException();
        }

        if(empty($level)){
            $this->recordError($log, ['msg' => '不允许的操作']);
            throw new UserException(['msg' => '不允许的操作']);
        }

        //判断是否在不可操作人的uid名单内
        if (!empty($this->canNotOPERUidList)) {
            if (in_array($data['uid'], $this->canNotOPERUidList)) {
                throw new MemberException(['errorCode' => 1600105]);
            }
        }

        //判断是否存在头尾相接的情况,如果需要指定的上级的团队结构包含当前用户,则不允许指定
        if (!empty($userInfo['areaMember']['link_superior_user'] ?? null) && $topUser != ($userInfo['areaMember']['link_superior_user'])) {
            if (!empty($topUserInfo['areaMember']['team_chain'] ?? null) && (strpos($topUserInfo['areaMember']['team_chain'], $uid) !== false)) {
                throw new MemberException(['errorCode' => 1600106]);
            }
        }

        $topUserInc = null;
        $topUserDes = null;
        $needChangeTeam = false;
        $needChangeDivideChain = false;
        $changeType = 1;
        $newMember = false;

        if (empty($userInfo['areaMember']) && empty($userInfo['area_vip_level'])) {
            $uAssign['area_vip_level'] = $level;

            $memberCardType = 1;
            if ($level == 1) {
                $memberCardType = 2;
            }
            $mAssign['member_card'] = (new CodeBuilder())->buildAreaMemberNum($memberCardType);
            $mAssign['uid'] = $uid;
            $mAssign['user_phone'] = $userInfo['phone'];
            $mAssign['link_superior_user'] = !empty($userInfo['link_superior_user']) ? $userInfo['link_superior_user'] : null;
            $mAssign['level'] = $level;
            $mAssign['type'] = 2;
            $newMember = true;
        } else {
            $uAssign['area_vip_level'] = $level;

            $mAssign['type'] = 2;
            $mAssign['level'] = $level;
            $mAssign['user_phone'] = $userInfo['phone'];
            $mAssign['link_superior_user'] = !empty($userInfo['link_superior_user']) ? $userInfo['link_superior_user'] : null;
            if ($level == 1) {
                $mAssign['member_card'] = (new CodeBuilder())->buildAreaMemberNum(2);
            }
            //$topUserDes = $userInfo['link_superior_user'];
            $topUserDes = null;
        }

        if (!empty($mAssign['link_superior_user'] ?? null)) {
            $topUserInfo = User::with(['areaMember'])->where(['uid' => $mAssign['link_superior_user'], 'status' => 1])->findOrEmpty()->toArray();
        }
        $log['topUserInfo'] = $topUserInfo ?? [];

        $log['uAssign'] = $uAssign ?? [];
        $log['mAssign'] = $mAssign ?? [];
        if (empty($uAssign) || empty($mAssign)) {
            $this->recordError($log, ['msg' => '指定会员出现了错误,请查验']);
            throw new MemberException();
        }
        $memberInfo = AreaMemberModel::where(['uid'=>$uid,'status'=>1])->findOrEmpty()->toArray();
        //计算销售额偏移量
        $memberGrowthValue = AreaMemberVdc::where(['status' => 1])->column('sales_price', 'level');
        //如果用户实际销售额大于指定等级则不允许降级操作
        if (!empty($memberInfo['level']) && $memberInfo['level'] < $level && $memberInfo['team_sales_price'] >= $memberGrowthValue[$memberInfo['level']]) {
            $this->recordError($log, ['msg' => '不允许操作会员降级,平级无需操作']);
            throw new UserException(['msg' => '不允许操作会员降级,平级无需操作']);
        }
        $mAssign['team_sales_price_offset'] = 0;
        if ($memberGrowthValue[$mAssign['level']] - $userInfo['team_sales_price'] > 0) {
            $mAssign['team_sales_price_offset'] = $memberGrowthValue[$mAssign['level']] - $userInfo['team_sales_price'];
            $uAssign['team_sales_price_offset'] = $mAssign['team_sales_price_offset'];
        }

        $dbRes = Db::transaction(function () use ($uAssign, $mAssign, $uid, $topUser, $topUserInc, $topUserDes, $needChangeTeam, $userInfo, $topUserInfo, $changeType, $needChangeDivideChain,$memberGrowthValue,$newMember) {
            if (!empty($uAssign)) {
                $uRes = User::update($uAssign, ['uid' => $uid]);
            }

            if (!empty($mAssign)) {
                //只要操作了等级就修改等级最后升级时间和修改对应的降级成长值
                if (!empty($mAssign['level']) && ($mAssign['level'] != $userInfo['area_vip_level'])) {
                    $mAssign['upgrade_time'] = time();
                    $demotionGrowthValue = AreaMemberVdc::where(['status' => 1, 'level' => $mAssign['level']])->value('demotion_sales_price');
                    $mAssign['demotion_team_sales_price'] = $demotionGrowthValue ?? $memberGrowthValue[$userInfo['area_vip_level']];
                    $needChangeDivideChain = true;
                }

                //查看是否需要修改团队结构冗余字段
                if ($mAssign['link_superior_user'] != ($userInfo['member']['link_superior_user'] ?? null)) {
                    if (empty($mAssign['link_superior_user'])) {
                        $mAssign['team_chain'] = null;
                    } else {
                        if (!empty($topUserInfo['areaMember']['team_chain'] ?? null)) {
                            $mAssign['team_chain'] = $mAssign['link_superior_user'] . ',' . $topUserInfo['areaMember']['team_chain'];
                        } else {
                            if ($topUserInfo['areaMember']['level'] != 1) {
                                throw new MemberException(['msg' => '上级团队结构出现错误,请联系运维管理员']);
                            }
                            $mAssign['team_chain'] = $mAssign['link_superior_user'];
                        }
                    }
                    $needChangeDivideChain = true;
                }


                if (!empty($newMember)) {
//                    $mAssign['growth_value'] = $addGrowthValue ?? 0;
//                    //累加普通用户的原有成长值
//                    if (empty($userInfo['area_vip_level']) && !empty(doubleval($userInfo['growth_value']))) {
//                        $mAssign['growth_value'] += $userInfo['growth_value'] ?? 0;
//                    }
                    $mRes = AreaMemberModel::create($mAssign);
                    //修改分润第一人冗余结构
                    $dRes = $this->refreshDivideChain(['uid' => $mAssign['uid']]);
                } else {
                    $mRes = AreaMemberModel::update($mAssign, ['uid' => $uid]);

                    //查看是否需要修改以他为首下面的团队的冗余结构
                    if ($mAssign['link_superior_user'] != ($userInfo['areaMember']['link_superior_user'] ?? null)) {
                        $dirTeamNumber = AreaMemberModel::where(['link_superior_user' => $uid, 'status' => 1])->count();
                        if (!empty($dirTeamNumber)) {
                            if (!empty($userInfo['areaMember']['team_chain'] ?? null)) {
                                $oldTeamChain = $uid . ',' . $userInfo['areaMember']['team_chain'];
                            } else {
                                $oldTeamChain = $uid;
                            }

                            if (empty($mAssign['link_superior_user'])) {
                                $nowTeamChain = $uid;
                            } else {
                                $nowTeamChain = $mAssign['team_chain'] ?? null;
                            }

                            if (!empty($nowTeamChain)) {
                                $nowTeamChain = $uid . ',' . $nowTeamChain;
                                $changeTeamRes = Db::query("UPDATE sp_area_member set team_chain=REPLACE(team_chain,'" . $oldTeamChain . "','" . $nowTeamChain . "') where uid in (select a.* from (SELECT uid from sp_area_member where FIND_IN_SET('" . $uid . "',team_chain) and status in (1,2)) a)");
                            }
                        }
                    }

                    //修改分润第一人冗余结构
                    if (!empty($needChangeDivideChain)) {
                        //先修改自己的,然后修改自己整个团队下级的
                        $dRes = $this->refreshDivideChain(['uid' => $uid]);
                        //团队的用队列形式,避免等待时间太长
                        $dtRes = Queue::later(10, 'app\lib\job\AreaMemberChain', ['searUser' => $uid, 'handleType' => 1], config('system.queueAbbr') . 'AreaMemberChain');
                    }

                }
            }
            if (!empty($uAssign)) {
                //记录操作日志
                $mLog['uid'] = $uid;
                $mLog['before_level'] = $userInfo['area_vip_level'] ?? 0;
                $mLog['after_level'] = $uAssign['area_vip_level'];
                $mLog['before_link_user'] = $userInfo['link_superior_user'] ?? null;
                $mLog['after_link_user'] = $uAssign['link_superior_user'] ?? null;
                $mLog['type'] = 1;
                $mLog['remark'] = '操作了团队代理的等级或上级关系';
                (new AdminLog())->areaMemberOPER($mLog);
            }

            return $uRes ?? null;
        });

        if (!empty($dbRes)) {
            $log['res'] = '指定会员成功处理';
        }

        $this->log($log, 'info');
        return judge($dbRes);

    }

    /**
     * @title  取消会员等级
     * @param string $uid 用户uid
     * @param string|null $parentUid 是否指定上级
     * @return bool
     * @throws \Exception
     */
    public function revokeUserMember(string $uid, string $parentUid = null)
    {
        throw new MemberException(['msg'=>'不允许的操作!']);
        $memberInfo = TeamMemberModel::with(['parent', 'user'])->where(['uid' => $uid, 'status' => [1, 2]])->findOrEmpty()->toArray();
        if (empty($memberInfo)) {
            throw new MemberException(['errorCode' => 1600104]);
        }

        //判断是否在不可操作人的uid名单内
        if (!empty($this->canNotOPERUidList)) {
            if (in_array($uid, $this->canNotOPERUidList)) {
                throw new MemberException(['errorCode' => 1600105]);
            }
        }

        if (!empty($memberInfo['parent']) && !empty($parentUid) && ($memberInfo['parent']['uid'] != $parentUid)) {
            throw new MemberException(['msg' => '当前用户已存在上级,不允许指定给其他用户!']);
        }

        if (!empty($parentUid)) {
            $parentInfo = TeamMemberModel::with(['user'])->where(['uid' => $parentUid, 'status' => [1, 2]])->findOrEmpty()->toArray();
        }
        $divideService = (new TeamDivide());
        //查找所有直推
        $directTeam = $divideService->getNextDirectLinkUserGroupByLevel($memberInfo['uid']);
        $parentMember = [];
        //如果该用户存在直推,则必须要有一个父类以用来接管上级
        if (!empty($directTeam['allUser']['onlyUidList'])) {
            if (!empty($parentInfo)) {
                $parentMember = $parentInfo ?? [];
            } else {
                $parentMember = $memberInfo['parent'] ?? [];
            }
            if (empty($parentMember)) {
                throw new MemberException(['msg' => '该用户无上级,无法解除会员权益']);
            }
        } else {
            $parentMember = $memberInfo['parent'] ?? [];
        }


        $dbRes = Db::transaction(function () use ($uid, $memberInfo, $parentMember, $directTeam, $divideService) {
            if (!empty($directTeam['allUser']['onlyUidList'])) {
                //查找所有用户
//                $userAllTeam = $divideService->getTeamAllUserGroupByLevel($memberInfo['child_team_code']);
                //如果存在直推则修改直推的上级为当前用户的上级
                $directUser = $directTeam['allUser']['onlyUidList'];
                $dirUserCount = count($directUser);
                if (!empty($dirUserCount)) {
                    $dUpdate['team_code'] = $parentMember['team_code'];
                    $dUpdate['parent_team'] = $parentMember['child_team_code'];
                    $dUpdate['link_superior_user'] = $parentMember['uid'];
                    $dUpdate['create_time'] = time();
                    //修改会员信息
                    TeamMemberModel::update($dUpdate, ['uid' => $directUser, 'status' => [1, 2]]);

                    $dUUpdate['parent_team'] = $dUpdate['parent_team'];
                    $dUUpdate['link_superior_user'] = $dUpdate['link_superior_user'];
                    //修改用户信息
                    User::update($dUUpdate, ['uid' => $directUser, 'status' => [1, 2]]);

                    //增加上级用户团队数和减少当前用户团队数
                    (new User())->where(['uid' => $parentMember['uid']])->inc('team_number', $dirUserCount)->update();
                    (new User())->where(['uid' => $uid])->dec('team_number', $dirUserCount)->update();

                    //修改团队冗余结构
                    if (!empty($memberInfo['team_chain'] ?? null)) {
                        $oldTeamChain = $uid . ',' . $memberInfo['team_chain'];
                    } else {
                        $oldTeamChain = $uid;
                    }

                    $nowTeamChain = $parentMember['team_chain'] ?? null;
                    if (!empty($nowTeamChain)) {
                        $nowTeamChain = $parentMember['uid'] . ',' . $nowTeamChain;
                    } else {
                        $nowTeamChain = $parentMember['uid'];
                    }
                    $updateTime = time();
                    if (!empty($nowTeamChain)) {
                        $changeTeamRes = Db::query("UPDATE sp_area_member set team_chain=REPLACE(team_chain,'" . $oldTeamChain . "','" . $nowTeamChain . "') , update_time = '" . $updateTime . "' where uid in (select a.* from (SELECT uid from sp_area_member where FIND_IN_SET('" . $uid . "',team_chain) and status in (1,2)) a)");
                    }

                    //修改分润第一人冗余结构
                    //团队的用队列形式,避免等待时间太长
                    $dtRes = Queue::push('app\lib\job\AreaMemberChain', ['searUser' => $parentMember['uid'], 'update_time' => $updateTime, 'handleType' => 1], config('system.queueAbbr') . 'AreaMemberChain');
                }

                //如果有下级则当前用户的上级接管所有下级
//                if(!empty($userAllTeam)){
//                    $allDownUser = $userAllTeam['allUser']['onlyUidList'];
//                    $allUserCount = count($allDownUser);
//                    if(!empty($allUserCount)){
//                        $daUpdate['team_code'] = $parentMember['team_code'];
//                        //修改会员信息
//                        MemberModel::update($daUpdate,['uid'=>$allDownUser,'status'=>[1,2]]);
//                    }
//                }
            }
            if (!empty($parentMember)) {
                //查看当前会员是否有冻结中的分润利益,如果有则由上级用户接管
                $notCompleteDivideOrder = DivideModel::where(['link_uid' => $uid, 'arrival_status' => 2])->column('order_sn');
                if (!empty($notCompleteDivideOrder)) {
                    $changeDivide = DivideModel::update(['link_uid' => $parentMember['uid'], 'remark' => '由于原下级' . $memberInfo['user_name'] . '被取消代理权益,故由其上级接管冻结分润'], ['order_sn' => $notCompleteDivideOrder, 'link_uid' => $uid, 'arrival_status' => 2, 'type' => 5]);
                }
            }

            //修改当前用户信息<删除会员信息和等级>
            $res = AreaMemberModel::update(['status' => -1, 'growth_value' => 0, 'team_chain' => null, 'divide_chain' => null], ['uid' => $uid, 'status' => [1, 2]]);
            User::update(['area_vip_level' => 0], ['uid' => $uid, 'status' => [1, 2]]);

            //记录操作日志
            $mLog['uid'] = $uid;
            $mLog['before_level'] = $memberInfo['level'];
            $mLog['after_level'] = 0;
            $mLog['type'] = 2;
            $mLog['remark'] = '取消了团队代理权益';
            $mLog['growth_value'] = $detail ?? [];
            (new AdminLog())->areaMemberOPER($mLog);
            return $res;
        });

        return judge($dbRes);

    }

    /**
     * @title  导入会员数据
     * @return array
     * @throws \PhpOffice\PhpSpreadsheet\Reader\Exception
     * @throws \Exception
     * @throws \PhpOffice\PhpSpreadsheet\Exception
     */
    public function importMember(array $data = [])
    {
        set_time_limit(0);
        ini_set('memory_limit', '3072M');
        ini_set('max_execution_time', '0');
        $dataType = $data['type'] ?? 1;
        if ($dataType == 1) {
            $fileUpload = 'uploads/5-20.xlsx';
            $list = (new Office())->ReadExcel($fileUpload, 3);
//        $list = (new Office())->importExcel(['type'=>3]);
        } else {
            $list = [$data['list']];
        }

        if (empty($list)) {
            throw new FileException(['msg' => '暂无可读数据']);
        }

        $existUser = [];
        $notExistTopUser = [];
        $topUserMemberInfo = [];
        $userModel = (new User());
        $memberModel = (new AreaMemberModel());
        $codeBuild = (new CodeBuilder());
        $userInfos = [];
        $newUser = [];
        $member = [];
        $newAssignGrowthDetail = [];

        $topUserPhone = array_unique(array_filter(array_column($list, 'topUserPhone')));
        $userPhone = array_unique(array_filter(array_column($list, 'userPhone')));
        foreach ($list as $key => $value) {
            if (!empty(trim($value['topUserName'])) && empty(trim($value['topUserPhone']))) {
                throw new ParamException(['msg' => '导入数据仅已手机号为准,数据中存在部分上级用户的手机号为空,请检查补齐后重新导入!']);
            }
            if (empty(trim($value['userPhone']))) {
                throw new ParamException(['msg' => '导入数据仅已手机号为准,数据中存在部分导入用户的手机号为空,请检查补齐后重新导入!']);
            }
        }

        $topUserInfos = User::where(['phone' => $topUserPhone])->field('uid,phone,name')->select()->toArray();
        $topUserMemberInfos = MemberModel::where(['user_phone' => $topUserPhone])->field('uid,user_phone,child_team_code,team_code,team_chain')->select()->toArray();
        if (empty($topUserMemberInfos)) {
            throw new ParamException(['msg' => '暂无有效的上级用户,请确认上级用户已经成为平台会员了喔']);
        }

        $userInfos = User::where(['phone' => $userPhone])->field('uid,phone,name,vip_level')->select()->toArray();
        if (!empty($userInfos)) {
            $existUser = array_unique(array_filter(array_column($userInfos, 'phone')));
        }

        if (!empty($topUserInfos)) {
            if (count($topUserInfos) != count($topUserPhone)) {
                foreach ($topUserInfos as $key => $value) {
                    if (!in_array($value['phone'], $topUserPhone)) {
                        $notExistTopUser[] = $value['phone'];
                    }
                }
            }
        }
        if (!empty($topUserMemberInfos)) {
            foreach ($topUserMemberInfos as $key => $value) {
                $topUserMemberInfo[$value['user_phone']] = $value;
            }
        }

//        $level = ['DT' => 1, 'SV' => 2, 'VIP' => 3];
        $level = [];
        $growthValue = [];
        $demotionGrowthValue = [];
        $memberVdc = MemberVdc::where(['status' => 1])->field('level,growth_value,demotion_growth_value,name')->order('level asc')->select()->toArray();
        if (empty($memberVdc)) {
            throw new MemberException(['msg' => '缺少正确的会员规则']);
        }

        foreach ($memberVdc as $key => $value) {
            $growthValue[$value['level']] = $value['growth_value'];
            $demotionGrowthValue[$value['level']] = $value['demotion_growth_value'];
            $level[str_replace(' ', '', $value['name'])] = $value['level'];
        }

//        $growthValue = MemberVdc::where(['status' => 1])->order('level asc')->column('growth_value', 'level');
//        $demotionGrowthValue = MemberVdc::where(['status' => 1])->order('level asc')->column('growth_value', 'level');

        foreach ($list as $key => $value) {
            if (!empty($value['userPhone']) && !in_array($value['topUserPhone'], $notExistTopUser) && !in_array($value['userPhone'], $existUser)) {
                $newUser[$key]['uid'] = getUid(10);
                $newUser[$key]['name'] = !empty(trim($value['userName'])) ? trim($value['userName']) : (!empty($value['topUserName']) ? $value['topUserName'] . '的下级用户' : null);
                $newUser[$key]['phone'] = $value['userPhone'];
                $newUser[$key]['vip_level'] = $level[strtoupper(trim($value['shenfen']))];
                $newUser[$key]['growth_value'] = $growthValue[$newUser[$key]['vip_level']];
                $newUser[$key]['old_sync'] = 5;
                $newUser[$key]['parent_team'] = $topUserMemberInfo[$value['topUserPhone']]['child_team_code'];
                $newUser[$key]['link_superior_user'] = $topUserMemberInfo[$value['topUserPhone']]['uid'];

                $member[$key]['uid'] = $newUser[$key]['uid'];
                $member[$key]['type'] = 2;
                $member[$key]['user_phone'] = $newUser[$key]['phone'];
//                $member[$key]['member_card'] = $codeBuild->buildAreaMemberNum();
                $member[$key]['level'] = $level[strtoupper(trim($value['shenfen']))];
                $member[$key]['parent_team'] = $topUserMemberInfo[$value['topUserPhone']]['child_team_code'];
//                $member[$key]['child_team_code'] = $memberModel->buildMemberTeamCode($member[$key]['level'], $member[$key]['parent_team'] ?? null);
                $member[$key]['team_code'] = $topUserMemberInfo[$value['topUserPhone']]['team_code'];
                $member[$key]['link_superior_user'] = $topUserMemberInfo[$value['topUserPhone']]['uid'];
                $member[$key]['growth_value'] = $growthValue[$member[$key]['level']];
                $member[$key]['demotion_growth_value'] = $demotionGrowthValue[$member[$key]['level']] ?? $growthValue[$member[$key]['level']];
                $member[$key]['upgrade_time'] = time();
                $member[$key]['create_time'] = time();
                $member[$key]['update_time'] = time();

                $newAssignGrowthDetail[$key]['type'] = 4;
                $newAssignGrowthDetail[$key]['uid'] = $newUser[$key]['uid'];
                $newAssignGrowthDetail[$key]['user_phone'] = $value['userPhone'];
                $newAssignGrowthDetail[$key]['user_level'] = 0;
                $newAssignGrowthDetail[$key]['growth_scale'] = '0.01';
                $newAssignGrowthDetail[$key]['growth_value'] = $member[$key]['growth_value'];
                $newAssignGrowthDetail[$key]['surplus_growth_value'] = $member[$key]['growth_value'];
                $newAssignGrowthDetail[$key]['arrival_status'] = 1;
                $newAssignGrowthDetail[$key]['assign_level'] = $member[$key]['level'];
                $newAssignGrowthDetail[$key]['remark'] = '系统代理升级赠送';
            }
        }

        $res = Db::transaction(function () use ($newUser, $member, $newAssignGrowthDetail) {
            $userRes = [];
            $memberRes = [];
            $growthRes = [];
            if (!empty($newUser)) {
                $userRes = (new User())->saveAll($newUser)->toArray();

                //依次生成会员卡号和团队子编码
                $memberModel = (new MemberModel());
                $codeBuild = (new CodeBuilder());
                foreach ($member as $key => $value) {
                    $value['member_card'] = $codeBuild->buildAreaMemberNum();
                    $value['child_team_code'] = $memberModel->buildMemberTeamCode($member[$key]['level'], $member[$key]['parent_team'] ?? null);

                    if (!empty($memberModel->insert($value))) {
                        $memberRes[] = $value;
                    }
                }
                $growthRes = (new GrowthValueDetail())->saveAll($newAssignGrowthDetail)->toArray();

                foreach ($member as $key => $value) {
                    User::where(['uid' => $value['link_superior_user']])->inc('team_number', 1)->update();
                }
            }
            return ['user' => $userRes, 'member' => $memberRes, 'growth' => $growthRes];
        });

        $allRes = ['newRes' => $res, 'notExistTopUser' => $notExistTopUser, 'existUser' => $existUser, 'existUserInfo' => $userInfos];
        return $allRes;
    }

    /**
     * @title  导入混乱表格数据的的团队结构
     * @return mixed
     * @throws \Exception
     * @ramark 整体思路为先导入user表,然后整理会员会员层级结构,按照层级结构一层一层排序梳理后再导入              会员表,只要存在的用户信息或会员信息均不更新
     */
    public function importConfusionTeam()
    {
        set_time_limit(0);
        ini_set('memory_limit', '80072M');
        ini_set('max_execution_time', '0');
        $type = 2;
//        $fileUpload = 'uploads/ttt.xlsx';
//        $list = (new Office())->ReadExcel($fileUpload,$type);
//        //读取数据
        $list = (new Office())->importExcel(['type' => 2, 'validateType' => false]);
        $userModel = (new User());

        if (empty($list)) {
            throw new ServiceException(['msg' => '读无有效数据,请检查数据表内容是否符合模版格式']);
        }

        if (count($list) >= 2000) {
            throw new ParamException(['msg' => '单次数据导入仅支持2000行数据~']);
        }

        foreach ($list as $key => $value) {
            $userInfo[$value['userPhone']] = $value;
        }

        $existDBUser = User::where(['phone' => array_unique(array_column($list, 'userPhone')), 'status' => [1, 2]])->field('uid,phone,name,vip_level,openid')->select()->toArray();
        if (!empty($existDBUser)) {
            foreach ($existDBUser as $key => $value) {
                $existDBUserInfo[$value['phone']] = $value;
            }
        }
        $memberVdcInfo = TeamMemberVdc::where(['status' => 1])->field('level,name,growth_value,demotion_growth_value')->select()->toArray();
        foreach ($memberVdcInfo as $key => $value) {
            $memberVdc[$value['level']] = $value['growth_value'];
            $memberDemotionVdc[$value['level']] = $value['demotion_growth_value'];
            $memberLevel[str_replace(' ', '', $value['name'])] = $value['level'];
            $memberTitle[$value['level']] = str_replace(' ', '', $value['name']);
        }
        $memberVdc[0] = 0;
        $memberDemotionVdc[0] = 0;
        $memberLevel['普通用户'] = 0;
        $memberTitle[0] = '普通用户';
        //$memberLevel = ['合伙人'=>1,'高级分享官'=>2,'中级分享官'=>3,'VIP顾客'=>3];
//        $memberLevel = ['合伙人' => 1, '高级团长' => 2, '团长' => 3];
        $userPhone = array_column($list, 'userPhone');

        //导入用户信息
        foreach ($list as $key => $value) {
            if (!empty($existDBUserInfo[$value['userPhone']])) {
                $newUser[$key]['uid'] = $existDBUserInfo[$value['userPhone']]['uid'];
                $newUser[$key]['openid'] = $existDBUserInfo[$value['userPhone']]['openid'] ?? null;
                $newUser[$key]['name'] = $existDBUserInfo[$value['userPhone']]['name'];
                $newUser[$key]['phone'] = $existDBUserInfo[$value['userPhone']]['phone'];
                $newUser[$key]['team_vip_level'] = $existDBUserInfo[$value['userPhone']]['vip_level'];
                $newUser[$key]['notNewUser'] = true;
            } else {
                $newUser[$key]['uid'] = getUid(10);
                $newUser[$key]['openid'] = $value['openid'] ?? null;
                $newUser[$key]['name'] = $value['userName'];
                $newUser[$key]['phone'] = $value['userPhone'];
                if (empty($memberLevel[$value['shenfen']] ?? null)) {
                    throw new ServiceException(['msg' => '会员身份名称有误']);
                }
                $newUser[$key]['team_vip_level'] = $memberLevel[$value['shenfen']];
                $newUser[$key]['notNewUser'] = false;
            }
            $topUserInfo[$value['userPhone']]['uid'] = $newUser[$key]['uid'];
            $topUserInfo[$value['userPhone']]['name'] = $newUser[$key]['name'];
            $topUserInfo[$value['userPhone']]['phone'] = $newUser[$key]['phone'];
            if (!empty($value['topUserPhone'])) {
                if (!empty($topUserInfo[$value['topUserPhone']])) {
                    if (!isset($topUserTeamNumber[$value['topUserPhone']])) {
                        $topUserTeamNumber[$value['topUserPhone']] = 0;
                    }
                    $newUser[$key]['link_superior_user'] = $topUserInfo[$value['topUserPhone']]['uid'];
                    $topUserTeamNumber[$value['topUserPhone']] += 1;
                } else {
                    //如果找不到上级资料暂存之后继续添加
                    $notFoundTopUser[$key] = $value;
                }
            }
            $newUser[$key]['old_sync'] = 5;

            if (!empty(intval($newUser[$key]['vip_level']))) {
                $newUser[$key]['growth_value'] = $memberVdc[$newUser[$key]['vip_level']] ?? 0;
            } else {
                $newUser[$key]['growth_value'] = 0;
            }

        }
        //重新再本表中找上级
        if (!empty($notFoundTopUser)) {
            foreach ($notFoundTopUser as $key => $value) {
                if (!empty($topUserInfo[$value['topUserPhone']])) {
                    if (!isset($topUserTeamNumber[$value['topUserPhone']])) {
                        $topUserTeamNumber[$value['topUserPhone']] = 0;
                    }
                    $newUser[$key]['link_superior_user'] = $topUserInfo[$value['topUserPhone']]['uid'];
                    $topUserTeamNumber[$value['topUserPhone']] += 1;
                } else {
                    $tableNotTopUser[$key] = $value;
                }
            }
        }
        $failTeam = [];
        //如果在本表中还是找不到上级,则从表中再查找一次上级是否存在
        $existDbTopTeamNumber = [];
        if (!empty($tableNotTopUser ?? [])) {
            $existTopUser = $userModel->where(['phone' => array_unique(array_column($tableNotTopUser, 'topUserPhone')), 'status' => [1, 2]])->field('uid,phone')->select()->toArray();
            if (!empty($existTopUser)) {
                foreach ($existTopUser as $key => $value) {
                    $existTopUserInfo[$value['phone']] = $value;
                }
                foreach ($tableNotTopUser as $key => $value) {
                    if (!empty($existTopUserInfo[$value['topUserPhone']])) {
                        if (!isset($existDbTopTeamNumber[$value['topUserPhone']])) {
                            $existDbTopTeamNumber[$value['topUserPhone']] = 0;
                        }
                        $newUser[$key]['link_superior_user'] = $existTopUserInfo[$value['topUserPhone']]['uid'];
                        $existDbTopTeamNumber[$value['topUserPhone']]++;
                    } else {
                        $failTeam[] = $value;
//                        throw new ServiceException(['msg' => ($value['userName'] ?? '') . ' 此用户 (手机号码: ' . ($value['userPhone'] ?? '') . ') 上级异常,查询无果,无法继续执行导入']);
                    }
                }
                if (!empty($failTeam)) {
                    $finally['accessUser'] = [];
                    $finally['accessMember'] = [];
                    $finally['existUser'] = [];
                    $finally['existMember'] = [];
                    $finally['notFoundTopUser'] = $failTeam ?? [];
                    return $finally;
                }
            } else {
                $failTeam = $tableNotTopUser;
//                throw new ServiceException(['msg' => '以下用户 (手机号码: ' . (implode('、',array_unique(array_column($tableNotTopUser, 'userPhone')))) . ') 上级异常,异常的上级电话号码有'.(implode('、',array_unique(array_column($tableNotTopUser, 'topUserPhone')))).'查询无果,无法继续执行导入']);
                $finally['accessUser'] = [];
                $finally['accessMember'] = [];
                $finally['existUser'] = [];
                $finally['existMember'] = [];
                $finally['notFoundTopUser'] = $failTeam ?? [];
                return $finally;
            }
        }

        if (!empty($topUserTeamNumber)) {
            foreach ($newUser as $key => $value) {
                if (!empty($topUserTeamNumber[$value['phone']]) && empty($existDBUserInfo[$value['phone']])) {
                    $newUser[$key]['team_number'] = $topUserTeamNumber[$value['phone']] ?? 0;
                }
            }
        }
        $oldUser = [];
        foreach ($newUser as $key => $value) {
            if (!empty($value['notNewUser'])) {
                $oldUser[] = $value;
                unset($newUser[$key]);
            }
        }

        //查找全部数据的会员信息
        $existDBMember = TeamMemberModel::where(['user_phone' => array_unique(array_column($list, 'userPhone')), 'status' => [1, 2]])->field('uid,user_phone,level')->select()->toArray();

        //查找全部上级的会员信息
        $existDBTopMember = TeamMemberModel::where(['user_phone' => array_unique(array_column($list, 'topUserPhone')), 'status' => [1, 2]])
            ->withCount(['cUserCount' => 'cCount'])
            ->field('uid,user_phone,level')->select()->toArray();
        $allMember = TeamMemberModel::count();
        $notMap[] = ['', 'exp', Db::raw('link_superior_user is null')];
        $notMap[] = ['status', 'in', [1, 2]];
        $notTopUserStartUser = TeamMemberModel::where($notMap)->count();
//        $notTopUserStartUser += 1000;
        $memberVdc = $memberVdc ?? [];
        $oldUser = $oldUser ?? [];

        //在事务内操作新增
        $DBRes = Db::transaction(function () use ($existDBMember, $allMember, $notTopUserStartUser, $userModel, $memberVdc, $newUser, $oldUser, $existDBTopMember, $existDbTopTeamNumber, $memberDemotionVdc) {

            //保存用户信息
            if (!empty($newUser)) {
                $res = $userModel->saveAll($newUser);
            }

            //新增已经存在表中的上级用户的团队数量
            if (!empty($existDbTopTeamNumber)) {
                foreach ($existDbTopTeamNumber as $key => $value) {
                    $userModel->where(['phone' => $key, 'status' => 1])->inc('team_number', intval($value))->update();
                }
            }
            /*------------------------------导入会员和结构---------------------------------------------*/
            $mModel = (new TeamMemberModel());

            $allUser = !empty($newUser) ? $newUser : ($oldUser ?? []);

            if (empty($allUser)) {
                throw new ServiceException(['msg' => '查无实际可导入的用户或会员,请检验数据是否已存在']);
            }
            foreach ($newUser as $key => $value) {
                if (empty($value['vip_level'] ?? null)) {
                    $newUser[$key]['vip_level'] = $value['team_vip_level'] ?? 0;
                }
            }
            if (!empty($existDBTopMember)) {
                foreach ($existDBTopMember as $key => $value) {
                    $existDBTopMemberInfos[$value['uid']] = $value;
                }
            }

            //筛选出最顶级的用户
            foreach ($allUser as $key => $value) {
                if (empty($value['link_superior_user'])) {
                    $topUser[] = $value['uid'];
                    $sortAllUser[] = $value;
                } else {
                    //如果有上级,但是上级存在于数据库中,也可以将其作为此次数据中最顶级的用户
                    if (!empty($existDBTopMemberInfos[$value['link_superior_user']] ?? null)) {
                        $topUser[] = $value['uid'];
                        $sortAllUser[] = $value;
                    }
                }
            }

            $all = [];
            //递归查询,查出团队中每一级的人,后续按照层级一层一层导入信息
            if (!empty($topUser)) {
                $all = $this->getTeamHierarchy($allUser, $topUser, 2);
            }

            if (!empty($all)) {
                foreach ($all as $key => $value) {
                    foreach ($value as $k => $v) {
                        $sortAllUser[] = $v;
                    }
                }
            }
            //如果有排序则按照排序来,如果没有只能按照原始数据来
            $allUser = !empty($sortAllUser) ? $sortAllUser : $allUser;
//        //按照等级排序,vip_level越小等级越高
//        array_multisort(array_column($allUser,'vip_level'), SORT_ASC, $allUser);

            $topUser = [];
            if (!empty($existDBMember)) {
                foreach ($existDBMember as $key => $value) {
                    $topUser[$value['uid']] = $value;
                    $existMember[] = $value;
                    $existMemberPhone[] = $value['user_phone'];
                }
            }
            if (!empty($existDBTopMember)) {
                foreach ($existDBTopMember as $key => $value) {
                    $DBTopUser[$value['uid']] = $value;
                }
            }

            if (!empty($allUser)) {

                $codeBuildService = (new CodeBuilder());
                $memberModel = (new TeamMemberModel());
//            $allMember = MemberModel::count();
                $this->lastMemberCount = $allMember ?? 15000;
                $memberCard = sprintf("%010d", ($allMember + 1));

                foreach ($allUser as $key => $value) {
                    if (!empty($value['link_superior_user']) && !empty($DBTopUser[$value['link_superior_user']])) {
                        $topUserTeamStartNumber[$value['link_superior_user']] = !empty($DBTopUser[$value['link_superior_user']]['cCount'] ?? null) ? $DBTopUser[$value['link_superior_user']]['cCount'] + 1 : 1000;
                        if ($value['vip_level'] == $DBTopUser[$value['link_superior_user']]['level']) {
                            $topUserTeamStartNumber[$value['link_superior_user']] += 500;
                        }
                    } else {
                        $topUserTeamStartNumber[$value['uid']] = 1000;
                    }
                    $this->topUserTeamStartNumber = $topUserTeamStartNumber;
                }
//            $notMap[] = ['','exp',Db::raw('link_superior_user is null')];
//            $notMap[] = ['status','in',[1,2]];
//            $notTopUserStartUser = MemberModel::where($notMap)->count();
                $notTopUserStartUser += 1000;
                $this->notTopUserStartUser = $notTopUserStartUser;
                foreach ($allUser as $key => $value) {
                    //如果已存在不允许覆盖,不进行插入或更新操作
                    if (in_array($value['phone'], $existMemberPhone ?? [])) {
                        $existMemberUser[] = $value;
                        continue;
                    }

                    $newMember[$key]['member_card'] = sprintf("%010d", ($allMember + 1));
                    $this->lastMemberCount = $allMember + 1;
                    $newMember[$key]['uid'] = $value['uid'];
                    $newMember[$key]['user_phone'] = $value['phone'];
                    $newMember[$key]['create_time'] = time();
                    $newMember[$key]['update_time'] = time();
                    $newMember[$key]['upgrade_time'] = time();

                    if (empty($value['link_superior_user'])) {
//                    $newMember[$key]['team_code'] = $memberModel->buildMemberTeamCode($value['vip_level']);
//                        $newMember[$key]['team_code'] = $this->getTeamCode('', $value['vip_level'], $notTopUserStartUser);
//                        $newMember[$key]['child_team_code'] = $newMember[$key]['team_code'];
                        $notTopUserStartUser++;
                        $this->notTopUserStartUser = $notTopUserStartUser;
                        $this->topUserTeamStartNumber[$value['uid']] = $notTopUserStartUser;

                    } else {
                        $topUserInfo = $topUser[$value['link_superior_user']] ?? ($DBTopUser[$value['link_superior_user']] ?? []);
                        if (!empty($topUserInfo)) {
//                            $newMember[$key]['team_code'] = $topUserInfo['team_code'];
//                            $newMember[$key]['child_team_code'] = $this->getTeamCode($topUserInfo['child_team_code'], $value['vip_level'], $this->topUserTeamStartNumber[$value['link_superior_user']] ?? 1000);

//                            $newMember[$key]['parent_team'] = $topUserInfo['child_team_code'];
                            if (!empty($this->topUserTeamStartNumber[$value['link_superior_user']] ?? null)) {
                                $topUserTeamStartNumber[$value['link_superior_user']]++;
                                $this->topUserTeamStartNumber[$value['link_superior_user']]++;
                            }
                            if (empty($topUser[$value['link_superior_user']]['link_superior_user'] ?? null)) {
                                if (!empty($this->topUserTeamStartNumber[$value['uid']] ?? null)) {
                                    $this->topUserTeamStartNumber[$value['uid']] += 2;
                                }

                            }
                        } else {
                            //上级不在创建上级,但是只要前面的层级结构排好了一般不会到这一步
                            $topUserMember = $this->buildTopMember($value, ['allMember' => $topUser ?? [], 'allUser' => $userInfo ?? [], 'type' => 1]);
                            $newMember[$key]['team_code'] = $topUserMember['team_code'];
//                        $newMember[$key]['child_team_code'] = $memberModel->buildMemberTeamCode($value['vip_level'],$topUserMember['child_team_code']);
                            $newMember[$key]['child_team_code'] = $this->getTeamCode($topUserMember['child_team_code'], $topUserMember['level'], $this->topUserTeamStartNumber[$value['link_superior_user']] ?? 1000);
                            if (!empty($this->topUserTeamStartNumber[$value['link_superior_user']] ?? null)) {
                                $this->topUserTeamStartNumber[$value['link_superior_user']]++;
                            }
                            $newMember[$key]['parent_team'] = $topUserMember['child_team_code'];
                        }

                    }

                    $topMember[$value['uid']]['team_code'] = $newMember[$key]['team_code'];
                    $topMember[$value['uid']]['child_team_code'] = $newMember[$key]['child_team_code'];

                    $newMember[$key]['level'] = $value['vip_level'];
                    $newMember[$key]['growth_value'] = $memberVdc[$newMember[$key]['level']];
                    $newMember[$key]['demotion_growth_value'] = $memberDemotionVdc[$newMember[$key]['level']] ?? $newMember[$key]['growth_value'];
                    $newMember[$key]['type'] = 1;
                    $newMember[$key]['link_superior_user'] = $value['link_superior_user'] ?? null;

                    $allMember++;
                    $topUser[$value['uid']] = $newMember[$key];

                }

                //汇总每个人的团队冗余结构
                if (!empty($newMember)) {
                    //先查找表中最顶级的人的团队结构和uid
                    $topTeam = $this->recursionGetTopUserTeamChain($allUser, $newMember, $existDBTopMember);
                    //根据表中最顶级的人的uid进入下面的方法递归按照一层一层往下找团队结构
                    if (!empty($topTeam)) {
                        $next = $this->recursionGetTopTopUserTeamChain($newMember, $topTeam, 2);
                    }

                    foreach ($newMember as $key => $value) {
                        if (!empty($value['link_superior_user']) && empty($this->teamChainArray[$value['uid']])) {
                            throw new MemberException(['msg' => '用户' . $value['user_phone'] . '团队结构计算有误,请联系运费管理员']);
                        }
                        $newMember[$key]['team_chain'] = $this->teamChainArray[$value['uid']];
                    }
                }

                if (!empty($newMember)) {
                    (new TeamMemberModel())->saveAll($newMember);
                }
            }

            return ['newMember' => $newMember ?? [], 'existMemberUser' => $existMemberUser ?? []];
        });


        $finally['accessUser'] = !empty($newUser) ? array_values($newUser) : [];
        $finally['accessMember'] = !empty($DBRes['newMember'] ?? []) ? array_values($DBRes['newMember']) : [];
        if (!empty($oldUser)) {
            foreach ($oldUser as $key => $value) {
                $oldUser[$key]['vip_name'] = $memberTitle[$value['team_vip_level']];
                $oldUser[$key]['rawData'] = $userInfo[$value['phone']] ?? [];
            }
        }
        $finally['existUser'] = $oldUser ?? [];
        if (!empty($existMemberUser)) {
            foreach ($existMemberUser as $key => $value) {
                $existMemberUser[$key]['vip_name'] = $memberTitle[$value['team_vip_level']];
                $existMemberUser[$key]['rawData'] = $userInfo[$value['phone']] ?? [];
            }
        }
        $finally['existMember'] = $DBRes['existMemberUser'] ?? [];
        $finally['notFountTopUser'] = $failTeam ?? [];

        return $finally;
    }

    /**
     * @title  先查找表中最顶级的人的团队结构和uid
     * @param array $oldAllUser
     * @param array $nowAllUser
     * @param array $existDbTopMember
     * @return array
     */
    public function recursionGetTopUserTeamChain(array $oldAllUser, array $nowAllUser, array $existDbTopMember)
    {
        $notTopPhone = [];
        foreach ($oldAllUser as $key => $value) {
            $oldUserInfo[$value['phone']] = $value;
        }
        foreach ($existDbTopMember as $key => $value) {
            $existDbTopMemberInfo[$value['user_phone']] = $value;
            $existDbTopMemberInfoUid[$value['uid']] = $value;
        }

        $topPhone = array_unique(array_column($oldAllUser, 'topUserPone'));
        //如果当前要添加的新会员信息中没有上级或者上级信息存在于数据库中的,则可以表示为表中最顶级的用户
        foreach ($nowAllUser as $key => $value) {
            if (empty($value['link_superior_user'])) {
                $notTopPhone[] = $value['uid'];
                $this->teamChainArray[$value['uid']] = '';
            } else {
                if (!empty($existDbTopMemberInfoUid[$value['link_superior_user']])) {
                    $notTopPhone[] = $value['uid'];
                    if (!empty($existDbTopMemberInfoUid[$value['link_superior_user']]['team_chain'] ?? null)) {
                        $this->teamChainArray[$value['uid']] = $value['link_superior_user'] . ',' . $existDbTopMemberInfoUid[$value['link_superior_user']]['team_chain'];
                    } else {
                        $this->teamChainArray[$value['uid']] = $value['link_superior_user'];
                    }
                }
            }
        }

        foreach ($topPhone as $key => $value) {
            if (!empty($existDbTopMemberInfo[$value])) {
                $topUserInfo[$existDbTopMemberInfo[$value]]['team_chain'] = $existDbTopMemberInfo[$value]['team_chain'];
                $this->teamChainArray[$existDbTopMemberInfo[$value]['uid']] = $existDbTopMemberInfo[$existDbTopMemberInfo[$value]['uid']]['team_chain'];
            } else {
                if (empty($oldUserInfo[$value])) {
                    throw new MemberException(['msg' => '用户 ' . $value . ' 记录团队信息结构有误']);
                }
                if (!empty($oldUserInfo[$value]) && !empty($oldUserInfo[$value]['link_superior_user'])) {
                    unset($topPhone[$key]);
                    continue;
                }
                if (!empty($oldUserInfo[$value]) && empty($oldUserInfo[$value]['link_superior_user'])) {
                    $topUserInfo[$existDbTopMemberInfo[$value['uid']]]['team_chain'] = '';
                    $this->teamChainArray[$existDbTopMemberInfo[$value]['uid']] = '';
                }
            }
        }
        if (!empty($notTopPhone ?? [])) {
            $returnList = array_merge_recursive($notTopPhone, array_unique(array_keys($topUserInfo ?? [])));
        } else {
            $returnList = array_unique(array_keys($topUserInfo ?? []));;
        }

        return $returnList;

    }

    /**
     * @title  按照层级递归按照每个人的团队冗余结构
     * @param array $allUser
     * @param array $topUser
     * @param int $level
     * @return array
     */
    public function recursionGetTopTopUserTeamChain(array $allUser, array $topUser, int $level)
    {
        foreach ($allUser as $k1 => $v1) {
            if (!empty($v1['link_superior_user']) && in_array($v1['link_superior_user'], $topUser)) {
                $finally[] = $v1;
                $finallyUid[] = $v1['uid'];
                if (!empty($this->teamChainArray[$v1['link_superior_user'] ?? null])) {
                    $this->teamChainArray[$v1['uid']] = $v1['link_superior_user'] . ',' . $this->teamChainArray[$v1['link_superior_user']];
                } else {
                    $this->teamChainArray[$v1['uid']] = $v1['link_superior_user'];
                }
            }
        }

        if (!empty($finallyUid)) {
            $all = $this->recursionGetTopTopUserTeamChain($allUser, $finallyUid, $level + 1);
        }
        return $this->teamChainArray;

    }

    /**
     * @title  递归获取团队各个层级结构的人群数组
     * @param array $allUser 总人群数组
     * @param array $topUser 上级用户uid数组
     * @param int $level 层级数(团队中第几层)
     * @return array
     */
    public function getTeamHierarchy(array $allUser, array $topUser, int $level)
    {
        foreach ($allUser as $k1 => $v1) {
            if (!empty($v1['link_superior_user']) && in_array($v1['link_superior_user'], $topUser)) {
                $finally[] = $v1;
                $finallyUid[] = $v1['uid'];
            }
        }
        $this->TeamHierarchy[$level] = $finally ?? [];
        if (!empty($finallyUid)) {
            $all = $this->getTeamHierarchy($allUser, $finallyUid, $level + 1);
        }
        return $this->TeamHierarchy;

    }

    /**
     * @title  导入会员时生成团队编码
     * @param string $ParentTeam 上级编码
     * @param int $level 现在的等级
     * @param int $number 现在的数量
     * @return string
     */
    public function getTeamCode(string $ParentTeam, int $level, int $number)
    {
        $pLength = strlen($ParentTeam);

        switch ($level) {
            case 1:
                $ParentTeam = null;
                break;
            case 2:
                if ($pLength >= 5) {
                    $ParentTeam = substr($ParentTeam, 0, 5);
                    $sqlMaxLength = 10;
                }
                break;
            case 3:
                if ($pLength >= 10) {
                    $ParentTeam = substr($ParentTeam, 0, 10);
                    $sqlMaxLength = 16;
                }
                break;
        }

        if ($level == 3 && !empty($ParentTeam)) {
            if (strlen($ParentTeam) >= 10) {
                $SPTFLen = "%06d";
            } else {
                $SPTFLen = "%05d";
            }

        } else {
            $SPTFLen = "%05d";
        }

        return trim($ParentTeam . sprintf($SPTFLen, ($number + 1)));
    }

    /**
     * @title  新建上级用户会员信息
     * @param array $value 当前的上级用户信息
     * @param array $data 其他数据
     * @return array
     */
    public function buildTopMember(array $value, array $data)
    {
        //当前总数据中存在的会员列表
        $allMember = $data['allMember'] ?? [];
        //当前总数据中存在的普通用户列表
        $allUser = $data['allUser'] ?? [];
        $type = $data['type'] ?? 1;
        $codeBuildService = (new CodeBuilder());
        $memberModel = (new TeamMemberModel());
        $map[] = ['uid', '=', $value['uid']];
        $topUser = $value;
        $newMember['member_card'] = sprintf("%010d", ($this->lastMemberCount + 1));
        $newMember['uid'] = $topUser['uid'];
        $newMember['user_phone'] = $topUser['phone'];
        $newMember['level'] = $topUser['vip_level'];
        $newMember['link_superior_user'] = $topUser['link_superior_user'];
        if (empty($topUser['link_superior_user'])) {
            $newMember['team_code'] = $this->getTeamCode('', $topUser['vip_level'], $this->notTopUserStartUser ?? 1000);
            $newMember['child_team_code'] = $newMember['team_code'];
            $newMember['parent_team'] = null;
            $newMember['team_chain'] = '';
            $this->notTopUserStartUser += 1;
        } else {
            $topUserInfo = $allMember[$topUser['link_superior_user']] ?? [];
            if (!empty($topUserInfo)) {
                $newMember['team_code'] = $topUserInfo['team_code'];
                $newMember['child_team_code'] = $this->getTeamCode($topUserInfo['child_team_code'], $topUser['vip_level'], $this->topUserTeamStartNumber[$topUser['link_superior_user']] ?? 1000);
                $newMember['parent_team'] = $topUserInfo['child_team_code'];
                if (!empty($topUserInfo['team_chain'] ?? null)) {
                    $newMember['team_chain'] = $topUser['link_superior_user'] . ',' . $topUserInfo['team_chain'];
                } else {
                    $newMember['team_chain'] = $topUser['link_superior_user'];
                }


                if (!empty($this->topUserTeamStartNumber[$topUser['link_superior_user']] ?? null)) {
                    $this->topUserTeamStartNumber[$topUser['link_superior_user']] += 1;
                }
            } else {
                //上上级用户
                $topTopUserInfo = User::where(['uid' => $topUser['link_superior_user']])->findOrEmpty()->toArray();

                if (!empty($topTopUserInfo)) {
                    $toptopUser = $this->buildTopMember($topTopUserInfo, ['allMember' => $allMember ?? [], 'allUser' => $allUser ?? [], 'type' => 2]);
//                    $newMember['team_code'] = $toptopUser['team_code'];
//                    $newMember['child_team_code'] = $this->getTeamCode($toptopUser['child_team_code'], $value['vip_level'], $this->topUserTeamStartNumber[$topUser['link_superior_user']] ?? 1000);
                    if (!empty($this->topUserTeamStartNumber[$topUser['link_superior_user']] ?? null)) {
                        $this->topUserTeamStartNumber[$topUser['link_superior_user']] += 1;
                    }
//                    $newMember['parent_team'] = $toptopUser['child_team_code'];
                } else {
                    throw new ServiceException(['msg' => '此人的上级有误,请查证' . $topUser['link_superior_user'] . ' 手机号码为 ' . ($topUser['phone'] ?? '')]);
                }
            }

        }

//        $memberInfo = (new MemberTest())->where(['uid'=>$newMember['uid'],'status'=>[1,2]])->findOrEmpty()->toArray();
        $memberInfo = [];
        if (empty($memberInfo)) {
            $res = (new MemberModel())->insert($newMember);
        } else {
            return $memberInfo;
        }
        return $newMember;
    }

    /**
     * @title  查找该用户真实的(从下往上的)所有上级用户信息
     * @param string $uid
     * @return mixed
     */
    public function getMemberRealAllTopUser(string $uid)
    {
        if (empty($uid)) {
            return [];
        }
        $databaseVersion = str_replace('.', '', config('system.databaseVersion'));
        if (!empty($databaseVersion) && $databaseVersion > 8016) {
            //mysql8.0.16版本之后使用这条sql
            $linkUserParent = Db::query("SELECT u2.member_card,u2.uid,u2.user_phone,u2.level,u2.type,u2.status,u2.link_superior_user,u1.LEVEL AS divide_level FROM(SELECT @id c_ids,(SELECT @id := GROUP_CONCAT( link_superior_user ) FROM sp_area_member,(SELECT @id := " . "'" . $uid . "'" . ",@l := 0 ) b WHERE status in (1,2) AND FIND_IN_SET( uid, @id COLLATE utf8mb4_unicode_ci )) p_ids,@l := @l + 1 AS LEVEL FROM sp_area_member WHERE @id IS NOT NULL ) u1 JOIN sp_area_member u2 ON u1.c_ids = u2.uid COLLATE utf8mb4_unicode_ci WHERE u2.status = 1 ORDER BY u1.LEVEL ASC;");
        } else {
            //mysql8.0.16版本以前使用这条sql
            $linkUserParent = Db::query("SELECT u2.member_card,u2.uid,u2.user_phone,u2.level,u2.type,u2.status,u2.link_superior_user,u1.LEVEL AS divide_level FROM(SELECT @id c_ids,(SELECT @id := GROUP_CONCAT( link_superior_user ) FROM sp_area_member WHERE status in (1,2) AND FIND_IN_SET( uid, @id COLLATE utf8mb4_unicode_ci )) p_ids,@l := @l + 1 AS LEVEL FROM sp_area_member,(SELECT @id := " . "'" . $uid . "'" . ",@l := 0 ) b WHERE @id IS NOT NULL ) u1 JOIN sp_area_member u2 ON u1.c_ids = u2.uid COLLATE utf8mb4_unicode_ci WHERE u2.status = 1 ORDER BY u1.LEVEL ASC;");
        }
        return $linkUserParent ?? [];
    }

    /**
     * @title  获取或刷新用户相关的分润第一人冗余结构
     * @param array $data
     * @return mixed
     * @throws \Exception
     */
    public function refreshDivideChain(array $data = [])
    {
        set_time_limit(0);
        ini_set('memory_limit', '3072M');
        ini_set('max_execution_time', '0');
        $log['msg'] = '[团队代理人] 获取或刷新用户相关的分润第一人冗余结构';
        $map = [];

        if (!empty($data['searUser'])) {
            $user = $data['searUser'];
            $map[] = ['', 'exp', Db::raw("find_in_set('$user',team_chain)")];
        }
        if (!empty($data['uid'])) {
            if (is_array($data['uid'])) {
                $map[] = ['uid', 'in', $data['uid']];
            } else {
                $map[] = ['uid', '=', $data['uid']];
            }
        }
        if (!empty($data['update_time'])) {
            $map[] = ['update_time', '>=', $data['update_time']];
        }
//        if (empty($map)) {
//            //记录日志
//            (new Log())->setChannel('memberChain')->record($log);
//            return false;
//        }
        $map[] = ['status', '=', 1];
        $log['searData'] = $data ?? [];

        $list = AreaMemberModel::where($map)->field('uid,level,team_chain')->select()->toArray();
        if (empty($list)) {
            //记录日志
            (new Log())->setChannel('areaMemberChain')->record($log);
            return false;
        }

        foreach ($list as $key => $value) {
            if (!empty($value['team_chain'])) {
                foreach (explode(',', $value['team_chain']) as $k => $v) {
                    if (empty($TopUser[$v])) {
                        $TopUser[$v] = 0;
                    }
                }
            }
            $userLevel[$value['uid']] = $value['level'] ?? 0;
            if (!empty($value['team_chain'])) {
                $list[$key]['allTeamChain'] = explode(',', $value['team_chain']);
            } else {
                $list[$key]['allTeamChain'] = null;
            }
        }
        $log['list'] = $list ?? [];
        $log['userLevel'] = $userLevel ?? [];

        if (!empty($TopUser)) {
            $existUserInfo = array_keys($userLevel);
            foreach ($TopUser as $key => $value) {
                if (!in_array($key, $existUserInfo)) {
                    $findTopUser[] = $key;
                }
            }
            if (!empty($findTopUser)) {
                $topUserList = AreaMemberModel::where(['uid' => $findTopUser, 'status' => 1])->column('level', 'uid');
                if (!empty($topUserList)) {
                    $userLevel = array_merge_recursive($userLevel, $topUserList);
                    $log['userLevel'] = $userLevel ?? [];
                }
            }

            //根据团队结构和当前用户等级划分要批量更新的分润价差第一人,主要根据上级的等级判断
            foreach ($list as $key => $value) {
                if (empty($levelUserList[$value['level']])) {
                    $levelUserList[$value['level']] = "'" . $value['uid'] . "'";
                } else {
                    $levelUserList[$value['level']] .= ",'" . $value['uid'] . "'";
                }
                if (!empty($value['allTeamChain'])) {
                    foreach ($value['allTeamChain'] as $k => $v) {
                        $list[$key]['allTeamChainLevel'][$v] = $userLevel[$v];
                    }
                    $firstNumber[$value['uid']] = false;
                    $firstTeamNumber[$value['level']][$value['team_chain']] = false;
                    foreach ($list[$key]['allTeamChainLevel'] as $cK => $cV) {
//                            if($firstNumber[$value['uid']] === false){
//                                $firstNumber[$value['uid']][$cK] = $cV;
//                                continue;
//                            }else{
//                                if($cV >= end($firstNumber[$value['uid']])){
//                                    continue;
//                                }else{
//                                    $firstNumber[$value['uid']][$cK] = $cV;
//                                }
//                            }

                        if ($firstTeamNumber[$value['level']][$value['team_chain']] === false) {
                            if (!empty($cV) && intval($cV) > 0 && $cV < $value['level']) {
                                $firstTeamNumber[$value['level']][$value['team_chain']][$cK] = $cV;
                            }
                            continue;
                        } else {
                            if ($cV >= end($firstTeamNumber[$value['level']][$value['team_chain']]) || empty($cV)) {
                                continue;
                            } else {
                                if (!empty($cV) && intval($cV) > 0) {
                                    $firstTeamNumber[$value['level']][$value['team_chain']][$cK] = $cV;
                                }
                            }
                        }
                    }
                }
            }

            $log['Team'] = $firstTeamNumber ?? [];
            //按照一个一个人更新
//            if(!empty($firstNumber)){
//                $sql =  "UPDATE sp_area_member set divide_chain = CASE uid";
//                foreach ($firstNumber as $key => $value) {
////                    $sql = "UPDATE sp_area_member set divide_chain = '". json_encode($value,256)."'"." where uid = '".$key."';";
////                    file_put_contents(app()->getRootPath() . 'public/storage/test.sql',$sql,FILE_APPEND);
////                    $sql .= sprintf("WHEN %d THEN %d ", $key, json_encode($value,256));
//                    $sql .= " WHEN '$key' THEN '".json_encode($value,256)."'";
//                }
//                $sql .= "END WHERE status = 1";
//            }
            //按照相同团队结构更新
            if (!empty($firstTeamNumber)) {
                //剔除空数据
                foreach ($firstTeamNumber as $key => $value) {
                    foreach ($value as $k => $v) {
                        if (empty($v)) {
                            unset($firstTeamNumber[$key][$k]);
                        }
                    }
                }
                foreach ($firstTeamNumber as $key => $value) {
                    if (empty($value)) {
                        unset($firstTeamNumber[$key]);
                    }
                }
                if (!empty($firstTeamNumber)) {
                    foreach ($firstTeamNumber as $key => $value) {

                        $sql[$key] = "UPDATE sp_area_member set divide_chain = ( CASE team_chain";
                        foreach ($value as $k => $v) {
                            if (empty($teamChainList[$key] ?? null)) {
                                $teamChainList[$key] = "'" . $k . "'";
                            } else {
                                $teamChainList[$key] .= ",'" . $k . "'";
                            }
//                    $sql = "UPDATE sp_area_member set divide_chain = '". json_encode($value,256)."'"." where uid = '".$key."';";
//                    file_put_contents(app()->getRootPath() . 'public/storage/test.sql',$sql,FILE_APPEND);
//                    $sql .= sprintf("WHEN %d THEN %d ", $key, json_encode($value,256));
                            if (!empty($v)) {
                                $sql[$key] .= " WHEN '$k' THEN '" . json_encode($v, 256) . "'";
                            }
                        }
                        $sql[$key] .= "END ) , update_time = '" . time() . "' WHERE status = 1 AND level = " . $key . ' AND team_chain in (' . $teamChainList[$key] . ')';
                    }
                }
            }

            $log['sql'] = $sql ?? [];
            if (!empty($sql)) {
                $DBRes = Db::transaction(function () use ($sql) {
                    foreach ($sql as $key => $value) {
                        $res[$key] = Db::query($value);
                    }
                    return $res;
                });
                $log['DBRes'] = $DBRes ?? false;
            }
        }

        //记录日志
        (new Log())->setChannel('areaMemberChain')->record($log);
        return $DBRes ?? false;
    }

    /**
     * @title  User表中查找用户的上级
     * @param array $data
     * @return array
     */
    public function getUserTopUserInUserDB(array $data)
    {
        $uid = $data['uid'];
        $notMyself = $data['notMySelf'] ?? false;
        $otherMapSql = '';
        if (!empty($notMyself)) {
            $otherMapSql = "AND u2.uid != '" . $uid . "'";
        }
        $databaseVersion = str_replace('.', '', config('system.databaseVersion'));
        if (!empty($databaseVersion) && $databaseVersion > 8016) {
            //mysql8.0.16版本之后使用这条sql
            $linkUserParent = Db::query("SELECT u2.uid,u2.phone as user_phone,u2.area_vip_level as level,u2.status,u2.link_superior_user,u1.LEVEL AS divide_level FROM(SELECT @id c_ids,(SELECT @id := GROUP_CONCAT( link_superior_user ) FROM sp_user,(SELECT @id := " . "'" . $uid . "'" . ",@l := 0 ) b WHERE status in (1,2) AND FIND_IN_SET( uid, @id COLLATE utf8mb4_unicode_ci )) p_ids,@l := @l + 1 AS LEVEL FROM sp_user WHERE @id IS NOT NULL ) u1 JOIN sp_user u2 ON u1.c_ids = u2.uid COLLATE utf8mb4_unicode_ci WHERE u2.status = 1 " . $otherMapSql . "ORDER BY u1.LEVEL ASC;");
        } else {
            //mysql8.0.16版本以前使用这条sql
            $linkUserParent = Db::query("SELECT u2.uid,u2.phone as user_phone,u2.area_vip_level as level,u2.status,u2.link_superior_user,u1.LEVEL AS divide_level FROM(SELECT @id c_ids,(SELECT @id := GROUP_CONCAT( link_superior_user ) FROM sp_user WHERE status in (1,2) AND FIND_IN_SET( uid, @id COLLATE utf8mb4_unicode_ci )) p_ids,@l := @l + 1 AS LEVEL FROM sp_user,(SELECT @id := " . "'" . $uid . "'" . ",@l := 0 ) b WHERE @id IS NOT NULL ) u1 JOIN sp_user u2 ON u1.c_ids = u2.uid COLLATE utf8mb4_unicode_ci WHERE u2.status = 1 " . $otherMapSql . " ORDER BY u1.LEVEL ASC;");
        }
        return $linkUserParent ?? [];
    }

    /**
     * @title  获取用户上级的团队链条
     * @param array $data
     * @return array
     */
    public function getUserTopChain(array $data)
    {
        $uid = $data['uid'];
        $memberDivideChain = TeamMemberModel::where(['uid' => $uid, 'status' => 1])->value('team_chain');

        if (empty($memberDivideChain)) {
            $userTopTeam = $this->getUserTopUserInUserDB(['uid' => $uid, 'notMySelf' => true]);
            if (!empty($userTopTeam)) {
                $aMemberDivideChain = $userTopTeam;
            }
        } else {
            $aMemberDivideChainList = explode(',', $memberDivideChain);
            $topUserList = User::where(['uid' => $aMemberDivideChainList, 'status' => 1])->column('area_vip_level', 'uid');

            $number = 0;
            foreach ($aMemberDivideChainList as $key => $value) {
                if (!empty($topUserList[$value] ?? null)) {
                    if (!isset($aMemberDivideChain[$number])) {
                        $aMemberDivideChain[$number] = [];
                    }
                    $aMemberDivideChain[$number]['uid'] = $value;
                    $aMemberDivideChain[$number]['level'] = $topUserList[$value];
                    $number += 1;
                }
            }
        }
        return $aMemberDivideChain ?? [];
    }

    /**
     * @title  获取用户分润第一人链条
     * @param array $data
     * @return array
     */
    public function getUserDivideChain(array $data)
    {
        $aMemberDivideChain = $this->getUserTopChain(['uid' => $data['uid']]);
        $userLevel = $data['user_level'] ?? 0;
        //一直都找不到上级或者没有分润,则不继续往下走,无法记录
        if (empty($aMemberDivideChain)) {
            return [];
        }

        $firstTeamNumber = [];
        $orderUserLevel = $userLevel;

        foreach ($aMemberDivideChain as $key => $value) {
            if (empty($firstTeamNumber)) {
                //只有上级等级不为0时记录, 用户等级为0时或者等级比用户高(即level比较小)的时候记录
                if (!empty($value['level']) && intval($value['level']) > 0) {
                    if (($orderUserLevel > 0 && $value['level'] < $orderUserLevel) || $orderUserLevel <= 0)
                        $firstTeamNumber[$value['uid']] = $value['level'];
                }
                continue;
            } else {
                if ($value['level'] >= end($firstTeamNumber) || empty($value['level'])) {
                    continue;
                } else {
                    if (!empty($value['level']) && intval($value['level']) > 0) {
                        $firstTeamNumber[$value['uid']] = $value['level'];
                    }
                }
            }
        }

        return $firstTeamNumber ?? [];
    }

    /**
     * @title  根据订单获取用户的分润第一人链条
     * @param array $data
     * @return array
     * @throws \Exception
     */
    public function getOrderChain(array $data)
    {
        $orderSn = $data['order_sn'];
        $orderInfo = Order::where(['order_sn' => $orderSn])->field('order_sn,uid,uid,pay_status,user_level')->findOrEmpty()->toArray();
        if (empty($orderInfo)) {
            return [];
        }
        //如果已经存在分润记录,从分润里面拿,如果没有则重新获取上级链条后计算出第一人
        $existDivide = DivideModel::where(['order_sn' => $orderInfo['order_sn'], 'status' => 1])->select()->toArray();
        if (!empty($existDivide)) {
            foreach ($existDivide as $key => $value) {
                if ($value['divide_type'] == 1) {
                    $divideChain[$value['link_uid']] = $value['level'];
                }
            }
        } else {
            $firstTeamNumber = $this->getUserDivideChain(['uid' => $orderInfo['uid'], 'user_level' => $orderInfo['user_level']]);
            if (!empty($firstTeamNumber)) {
                $divideChain = $firstTeamNumber;
            }
        }

        return $divideChain ?? [];
    }

    /**
     * @title  根据订单获取用户的团队第一人
     * @param array $data
     * @return mixed
     * @throws \Exception
     */
    public function getOrderTeamTopUser(array $data)
    {
        $orderSn = $data['order_sn'];
        $orderInfo = Order::where(['order_sn' => $orderSn])->field('order_sn,uid,uid,pay_status,user_level')->findOrEmpty()->toArray();
        if (empty($orderInfo)) {
            return [];
        }
        $topUser = [];
        $topUserList = $this->getUserTopChain(['uid' => $orderInfo['uid']]);
        if (!empty($topUserList)) {
            $topUser = end($topUserList);
        }

        return $topUser ?? [];
    }

    /**
     * @title  修改订单的分润第一人冗余字段和顶级团队长
     * @param array $data
     * @return array
     * @throws \Exception
     */
    public function recordOrderChain(array $data)
    {
        if (empty($data['order_sn'])) {
            return ['divideChain' => [], 'topUser' => [], 'DBRes' => false];
        }
        $divideChain = $this->getOrderChain(['order_sn' => $data['order_sn']]);
        $topUser = $this->getOrderTeamTopUser(['order_sn' => $data['order_sn']]);
        if (!empty($divideChain)) {
            $save['divide_chain'] = json_encode($divideChain, 256);
        }
        if (!empty($topUser)) {
            $save['team_top_user'] = $topUser['uid'];
        }
        $res = false;
        if (!empty($save)) {
            $res = Order::update($save, ['order_sn' => $data['order_sn']])->getData();
        }
        return ['divideChain' => $divideChain ?? [], 'topUser' => $topUser ?? [], 'DBRes' => $res ?? false];
    }

    /**
     * @title  判断用户是否需要降级
     * @param array $data
     * @return mixed
     * @throws \Exception
     */
    public function checkUserLevel(array $data)
    {
        //用户信息
        $userInfo = TeamMemberModel::where(['uid' => $data['uid']])->field('uid,user_phone,level,team_sales_price,team_sales_price_offset')->findOrEmpty()->toArray();
        //判断用户等级是否需要降级
        $userNowLevel = $this->checkMemberSalesPriceLevel($userInfo, $userInfo['level']);

        if ($userNowLevel != $userInfo['level']) {
            if (!empty($userNowLevel)) {
                $demotionGrowthValue = TeamMemberVdc::where(['level' => $userNowLevel, 'status' => 1])->value('demotion_sales_price');
            } else {
                $demotionGrowthValue = 0;
            }
            $this->log(['msg' => '用户' . $data['uid'] . '销售额不足,需要降级 ' . '现在等级为 ' . $userInfo['level'] . '修改为' . $userNowLevel], 'error');
            User::where(['uid' => $userInfo['uid']])->save(['area_vip_level' => $userNowLevel]);
            AreaMemberModel::where(['uid' => $userInfo['uid']])->save(['level' => $userNowLevel, 'demotion_team_sales_price' => $demotionGrowthValue]);

            //修改分润第一人冗余结构
            //先修改自己的,然后修改自己整个团队下级的
            $dRes = $this->refreshDivideChain(['uid' => $userInfo['uid']]);
            //团队的用队列形式,避免等待时间太长
            $dtRes = Queue::later(10, 'app\lib\job\AreaMemberChain', ['searUser' => $userInfo['uid'], 'handleType' => 1], config('system.queueAbbr') . 'AreaMemberChain');
        }
        return true;
    }

    /**
     * @title  返回用户当前销售额对应的正确的等级
     * @param array $userInfo
     * @param int $checkLevel
     * @return int
     */
    public function checkMemberSalesPriceLevel(array $userInfo, int $checkLevel)
    {
        $memberVdc = TeamMemberVdc::where(['level' => $checkLevel, 'status' => 1])->field('level,sales_price,demotion_type,demotion_sales_price')->findOrEmpty()->toArray();
        //到达最小level后直接返回最小level
        if (empty($memberVdc)) {
//            $minLevel = TeamMemberVdc::where(['status' => 1])->max('level');
//            if ($checkLevel > $minLevel) {
//                return 0;
//            }
            return 0;
        }
        //根据不同的降级策略判断决定不同的判断标准是否要降级,如果找不到则默认用该等级的升级成长值
        switch ($memberVdc['demotion_type']) {
            case 1:
                $judgeGrowthValue = $memberVdc['demotion_sales_price'] ?? $memberVdc['sales_price'];
                break;
            case 2:
                //只有在跟用户等级相同的情况才采用用户的历史降级等级, 如果不是的话用回当前判断的会员降级等级
                if ($checkLevel == $userInfo['level']) {
                    $judgeGrowthValue = $userInfo['demotion_sales_price'] ?? $memberVdc['sales_price'];
                } else {
                    $judgeGrowthValue = $memberVdc['demotion_sales_price'] ?? $memberVdc['sales_price'];
                }

                break;
        }
        if (($userInfo['team_sales_price'] + $userInfo['team_sales_price_offset'] ?? 0) < ($judgeGrowthValue ?? 0)) {
            $res = $this->checkMemberSalesPriceLevel($userInfo, $checkLevel + 1);
        } else {
            $res = $checkLevel;
        }
        return $res;
    }


    /**
     * @title  记录错误并保存日志,删除该任务后终止
     * @param array $data 所有数据
     * @param array $error 错误内容
     * @return bool
     */
    public function recordError(array $data, array $error)
    {
        $allData['msg'] = '会员 ' . $data['uid'] . " [ 升级服务出错:" . ($error['msg'] ?? '原因未知') . " ] ";
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
        return (new Log())->setChannel('teamMember')->record($data, $level);
    }
}