<?php
// +----------------------------------------------------------------------
// |[ 文档说明: ]
// +----------------------------------------------------------------------
// | Copyright (c) 2018~2024 http://www.mlhcmk.com All rights reserved.
// +----------------------------------------------------------------------


namespace app\lib\services;

use app\lib\exceptions\FileException;
use app\lib\exceptions\MemberException;
use app\lib\exceptions\ParamException;
use app\lib\exceptions\ServiceException;
use app\lib\exceptions\UserException;
use app\lib\models\GrowthValueDetail;
use app\lib\models\Member as MemberModel;
use app\lib\models\MemberOrder;
use app\lib\models\MemberTest;
use app\lib\models\MemberVdc;
use app\lib\models\PpylMemberVdc;
use app\lib\models\PpylOrder;
use app\lib\models\Reward;
use app\lib\models\Order;
use app\lib\models\Divide as DivideModel;
use app\lib\models\User;
use app\lib\models\UserTest;
use think\facade\Db;
use think\facade\Queue;

class Member
{
    private $belong = 1;
    protected $becomeLevelTwoPullNewMember = 1;
    public $memberUpgradeOrderKey = 'memberUpgradeOrder';
    protected $canNotOPERUidList = [];

    //导入会员专用变量
    protected $topUserTeamStartNumber = [];
    protected $lastMemberCount = 0;
    protected $notTopUserStartUser = 0;
    protected $TeamHierarchy = [];
    //导入会员团队冗余结构专用变量
    public $teamChainArray = [];

    /**
     * @title  新建会员订单
     * @param array $data
     * @return mixed
     */
    public function order(array $data)
    {
        $res = Db::transaction(function () use ($data) {
            $member = (new MemberModel());
            $aUserLevel = $member->getUserLevel($data['uid']);
            if (!empty($aUserLevel)) {
                throw new MemberException(['errorCode' => 1600101]);
            }
            $aUserInfo = (new User())->getUserProtectionInfo($data['uid']);
            //生成会员订单
            $newMember = (new MemberOrder())->new($data);
            //生成微信订单
            $wxRes = [];
            if (!empty($newMember)) {
                $wxOrder['openid'] = $aUserInfo['openid'];
                $wxOrder['out_trade_no'] = $newMember['order_sn'];
                $wxOrder['body'] = '会员订单';
                //$wxOrder['total_fee'] = priceFormat($newMember['real_pay_price']);
                $wxOrder['total_fee'] = 0.01;
                $wxOrder['notify_url'] = config('system.callback.memberCallBackUrl');
                $wxOrder['attach'] = '会员订单';
                $wxRes = (new WxPayService())->order($wxOrder);
            }
            return $wxRes;
        });

        return $res;
    }

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
        $member = (new MemberModel());
        $divide = (new Divide());
        $aMemberInfo = $member->where(['uid' => $uid, 'status' => 1])->field('uid,user_phone,member_card,level,type,type,link_superior_user,child_team_code,team_code,parent_team,growth_value')->findOrEmpty()->toArray();
        $aUserLevel = $aMemberInfo['level'] ?? 0;

        //非会员无法升级
        if (empty($aMemberInfo) || empty($aUserLevel)) {
            $log['res'] = $upgradeRes;
            $log['upgradeTopUser'][] = $this->memberTopUserUpgrade($aMemberInfo['link_superior_user'] ?? '');
            return $this->recordError($log, ['msg' => '非会员无法升级']);
        }
        //判断该等级是否允许继续自主升级(如果没有上级的自由用户不允许继续往上升级),VIP不允许自主升级
        //1/28新增强制SV等级用户不允许自主往上升级(2022/3/11取消)
        $allowUpgrade = MemberVdc::where(['status' => 1, 'level' => $aUserLevel])->value('close_upgrade');
        if (($allowUpgrade == 1 && (empty($aMemberInfo['link_superior_user']) && $aMemberInfo['type'] == 1))) {
            $log['upgradeTopUser'][] = $this->memberTopUserUpgrade($aMemberInfo['link_superior_user'] ?? '');
            return $this->recordError($log, ['msg' => '已关闭自主升级通道,当前等级已为顶级,如有疑惑可联客服专员']);
        }

        $aNextLevel = MemberVdc::where(['status' => 1, 'level' => ($aUserLevel - 1)])->order('level desc')->findOrEmpty()->toArray();

        //没有找到对应的等级规则 则不升级
        if (empty($aNextLevel)) {
            $log['upgradeTopUser'][] = $this->memberTopUserUpgrade($aMemberInfo['link_superior_user'] ?? '');
            return $this->recordError($log, ['msg' => '没有找到对应的等级规则 不升级']);
        }

        //判断成长值
        if ((string)$aMemberInfo['growth_value'] < (string)$aNextLevel['growth_value']) {
            $log['upgradeTopUser'][] = $this->memberTopUserUpgrade($aMemberInfo['link_superior_user'] ?? '');
            return $this->recordError($log, ['msg' => '成长值不足' . $aNextLevel['growth_value'] . ' ,无法升级!']);
        }

        //判断队伍成员人数
        if ($aNextLevel['need_team_condition'] == 1) {
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

            $log['allRes'] = $allRes;

        } else {
            $directRes = true;
            $allRes = true;
        }

        $log['nextLevelRule'] = $aNextLevel;
        $log['upgradeRes'] = false;

        $upgradeRes = false;
        //同时满足全部条件才能升级
        if (!empty($directRes) && !empty($allRes)) {
            $log['upgradeRes'] = true;
            $log['upgradeMsg'] = '该用户 [' . $aMemberInfo['uid'] . ' - ' . $aMemberInfo['user_phone'] . '] 当前等级为' . $aMemberInfo['level'] . ' 经系统检测符合升级,等级即将升级为' . $aNextLevel['level'];
            $upgradeRes = Db::transaction(function () use ($aNextLevel, $uid) {
                $userSave['vip_level'] = $aNextLevel['level'];
                $memberSave['level'] = $aNextLevel['level'];
                $memberSave['upgrade_time'] = time();
                $memberSave['demotion_growth_value'] = $aNextLevel['demotion_growth_value'] ?? $aNextLevel['growth_value'];
                $user = User::update($userSave, ['uid' => $uid]);
                $member = MemberModel::update($memberSave, ['uid' => $uid]);

                //修改分润第一人冗余结构
                //先修改自己的,然后修改自己整个团队下级的
                $dRes = $this->refreshDivideChain(['uid' => $uid]);
                //团队的用队列形式,避免等待时间太长
                $dtRes = Queue::later(10, 'app\lib\job\MemberChain', ['searUser' => $uid, 'handleType' => 1], config('system.queueAbbr') . 'MemberChain');

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
            $upgradeQueue = Queue::push('app\lib\job\MemberUpgrade', ['uid' => $uid], config('system.queueAbbr') . 'MemberUpgrade');
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
     * @title  拼拼有礼渠道-会员等级升级
     * @param string $uid 用户uid
     * @param bool $topUserUpgrade 是否需要递归查找上级是否可升级
     * @return mixed
     * @throws \Exception
     */
    public function memberUpgradeByPpyl(string $uid, bool $topUserUpgrade = true)
    {
        $log['uid'] = $uid;
        $log['type'] = '拼拼有礼渠道-会员等级升级';
        $upgradeRes = false;
        $member = (new MemberModel());
        $divide = (new Divide());
        $aMemberInfo = $member->where(['uid' => $uid, 'status' => 1])->field('uid,user_phone,member_card,level,type,type,link_superior_user,child_team_code,team_code,parent_team,growth_value')->findOrEmpty()->toArray();

        $notMember = false;
        $aUserLevel = $aMemberInfo['level'] ?? 0;
        if (empty($aMemberInfo)) {
            $userInfo = User::where(['uid'=>$uid,'status'=>1])->findOrEmpty()->toArray();
            $maxLevel = PpylMemberVdc::where(['status' => 1])->max('level');
            $aUserLevel = $maxLevel + 1;
            $notMember = true;
            $aMemberInfo['uid'] = $userInfo['uid'];
            $aMemberInfo['user_phone'] = $userInfo['phone'];
            $aMemberInfo['level'] = 0;
            $aMemberInfo['link_superior_user'] = $userInfo['link_superior_user'] ?? null;
        }


//        //非会员无法升级
//        if (empty($aMemberInfo) || empty($aUserLevel)) {
//            $log['res'] = $upgradeRes;
//            $log['upgradeTopUser'][] = $this->memberTopUserUpgradeByPpyl($aMemberInfo['link_superior_user'] ?? '');
//            return $this->recordError($log, ['msg' => '非会员无法升级']);
//        }
        if (empty($notMember)) {
            //判断该等级是否允许继续自主升级(如果没有上级的自由用户不允许继续往上升级),VIP不允许自主升级
            $allowUpgrade = PpylMemberVdc::where(['status' => 1, 'level' => $aUserLevel])->value('close_upgrade');
//        if (($allowUpgrade == 1 && (empty($aMemberInfo['link_superior_user']) && $aMemberInfo['type'] == 1)) || $aUserLevel == 2) {
//            $log['upgradeTopUser'][] = $this->memberTopUserUpgradeByPpyl($aMemberInfo['link_superior_user'] ?? '');
//            return $this->recordError($log, ['msg' => '已关闭自主升级通道,当前等级已为顶级,如有疑惑可联客服专员']);
//        }
        }


        $aNextLevel = PpylMemberVdc::where(['status' => 1, 'level' => ($aUserLevel - 1)])->order('level desc')->findOrEmpty()->toArray();

        //没有找到对应的等级规则 则不升级
        if (empty($aNextLevel)) {
            $log['upgradeTopUser'][] = $this->memberTopUserUpgradeByPpyl($aMemberInfo['link_superior_user'] ?? '');
            return $this->recordError($log, ['msg' => '没有找到对应的等级规则 不升级']);
        }

        //判断队伍成员人数
        if ($aNextLevel['need_team_condition'] == 1) {
            $directTeam = $divide->getNextDirectLinkUserGroupByLevelForUserTable($aMemberInfo['uid']);
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
                        if ($directAccessNumber >= $directConditionNumber) {
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
                        if ($allAccessNumber >= $allConditionNumber) {
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

            $log['allRes'] = $allRes;

        } else {
            $directRes = true;
            $allRes = true;
        }

        $log['nextLevelRule'] = $aNextLevel;

        //判断直推中参与参与拼拼有礼的人数
        if (!empty($aNextLevel['recommend_ppyl_number'] ?? 0)) {
            $ppylRes = false;
            $log['ppylMsg'] = '直推参与拼拼有礼的人数不符合要求';

            $map[] = ['status', '=', 1];
            $map[] = ['link_superior_user', '=', $uid];
            $ppylDirectUser = User::where($map)->value('uid');
            if (!empty($ppylDirectUser)) {
                $userSql = User::where($map)->field('uid')->buildSql();
                $ppylMap[] = ['activity_status', 'in', [1, 2, -3]];
                $ppylMap[] = ['', 'exp', Db::raw('group_time is not null')];
                $ppylMap[] = ['pay_status', 'in', [2, -2]];
                $ppylMap[] = ['', 'exp', Db::raw('uid in ' . $userSql)];
                $ppylNumberSql = PpylOrder::where($ppylMap)->field('uid,order_sn')->group('uid')->buildSql();
                $ppylNumber = Db::table($ppylNumberSql . ' a')->count();

                $log['ppylNumber'] = $ppylNumber;

                if ($ppylNumber >= $aNextLevel['recommend_ppyl_number']) {
                    $log['ppylMsg'] = '直推参与拼拼有礼的人数符合要求';
                    $ppylRes = true;
                }
            }
        } else {
            $ppylRes = true;
        }

        $upgradeRes = false;
        //同时满足全部条件才能升级
        if (!empty($directRes) && !empty($allRes) && !empty($ppylRes)) {
            $log['upgradeMsg'] = '该用户 [' . $aMemberInfo['uid'] . ' - ' . $aMemberInfo['user_phone'] . '] 当前等级为' . $aMemberInfo['level'] . ' 经系统检测符合升级,等级即将升级为' . $aNextLevel['level'];
            if (!isset($aNextLevel['growth_value'])) {
                $memberLevel = MemberVdc::where(['level' => $aNextLevel['level'], 'status' => 1])->findOrEmpty()->toArray();
                $aNextLevel['growth_value'] = $memberLevel['growth_value'];
                $aNextLevel['demotion_growth_value'] = $memberLevel['demotion_growth_value'] ?? 0;
            }

            $upgradeRes = Db::transaction(function () use ($aNextLevel, $uid,$aMemberInfo) {
                $existMember = MemberModel::where(['uid'=>$uid,'status'=>[1,2]])->count();
                if(empty($existMember)){
                    $member = $this->becomeMember(['link_superior_user' => $aMemberInfo['link_superior_user'], 'uid' => $uid, 'user_phone' => $aMemberInfo['user_phone']]);
                }else{
                    $memberSave['level'] = $aNextLevel['level'];
                    $memberSave['upgrade_time'] = time();
                    $memberSave['demotion_growth_value'] = $aNextLevel['demotion_growth_value'] ?? $aNextLevel['growth_value'];
                    $memberSave['ppyl_channel'] = 1;
                    $memberSave['ppyl_max_level'] = $aNextLevel['level'];

                    $member = MemberModel::update($memberSave, ['uid' => $uid]);

                    //修改分润第一人冗余结构
                    //先修改自己的,然后修改自己整个团队下级的
                    $dRes = $this->refreshDivideChain(['uid' => $uid]);
                    //团队的用队列形式,避免等待时间太长
                    $dtRes = Queue::later(10, 'app\lib\job\MemberChain', ['searUser' => $uid, 'handleType' => 1], config('system.queueAbbr') . 'MemberChain');
                }

                $userSave['vip_level'] = $aNextLevel['level'];
                $user = User::update($userSave, ['uid' => $uid]);

                return $user->getData();
            });
        } else {
            $log['upgradeMsg'] = '该用户 [' . $aMemberInfo['uid'] . ' - ' . $aMemberInfo['user_phone'] . '] 当前等级为' . $aMemberInfo['level'] . ' 经系统检测不符合升级成为等级' . $aNextLevel['level'] . '的条件';
        }

        //发送消息模版---暂未完成----start

        //发送消息模版---暂未完成----end

        $log['res'] = $upgradeRes;
        $this->log($log, 'info');

        //如果有成功升级且存在上级则判断上级是否也会因此而升级
        if (!empty($topUserUpgrade)) {
            if (!empty($aMemberInfo['link_superior_user'])) {
                $topRes = $this->memberUpgradeByPpyl($aMemberInfo['link_superior_user']);
            }
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
    public function memberTopUserUpgradeByPpyl(string $uid, bool $topUserUpgrade = true)
    {
        if (!empty($topUserUpgrade)) {
            if (!empty($uid)) {
                $topRes = $this->memberUpgradeByPpyl($uid, $topUserUpgrade);
                $log['upgradeTopUser'][] = $topRes;
                return $topRes;
            }
        }
        return false;
    }

    /**
     * @title  会员等级升级(老方法,暂且保留)
     * @param string $uid 用户uid
     * @param bool $topUserUpgrade 是否需要递归查找上级是否可升级
     * @return mixed
     * @date 2020/11/5
     * @throws \Exception
     */
    public function memberUpgradeOld(string $uid, bool $topUserUpgrade = true)
    {
        $log['uid'] = $uid;
        $log['type'] = '会员等级升级';
        $upgradeRes = false;
        $member = (new MemberModel());
        $divide = (new Divide());
        $aMemberInfo = $member->where(['uid' => $uid, 'status' => 1])->field('uid,user_phone,member_card,level,type,link_superior_user,child_team_code,team_code,parent_team')->findOrEmpty()->toArray();
        $aUserLevel = $aMemberInfo['level'] ?? 0;

        //非会员无法升级
        if (empty($aUserLevel)) {
            $log['res'] = $upgradeRes;
            return $this->recordError($log, ['msg' => '非会员无法升级']);
        }

        $directTeam = $divide->getNextDirectLinkUserGroupByLevel($aMemberInfo['uid']);
        $allTeam = $divide->getTeamAllUserGroupByLevel($aMemberInfo['uid']);
        $aNextLevel = MemberVdc::where(['status' => 1, 'level' => ($aUserLevel - 1)])->order('level desc')->findOrEmpty()->toArray();

        //没有找到对应的等级规则 则不升级
        if (empty($aNextLevel)) {
            return $this->recordError($log, ['msg' => '没有找到对应的等级规则 不升级']);
        }

        $log['directTeam'] = $directTeam;
        $log['allTeam'] = $allTeam;
        $log['nextLevelRule'] = $aNextLevel;

        //检查直推成员中升级指定的等级和人数是否符合条件
        $directRes = false;
        $log['directMsg'] = '直推团队升级指定的等级和人数不符合要求';
        if (!empty($directTeam)) {
            if (!empty($directTeam['userLevel'])) {
                if (isset($directTeam['userLevel'][$aNextLevel['recommend_level']])) {
                    if ($aNextLevel['recommend_number'] <= $directTeam['userLevel'][$aNextLevel['recommend_level']]['count']) {
                        $log['directMsg'] = '直推团队升级指定的等级和人数符合要求';
                        $directRes = true;
                    }
                }
            }
        }

        $log['directRes'] = $directRes;

        //检查全部成员中升级指定的等级和人数是否符合条件
        $allRes = false;
        $log['allMsg'] = '全部团队升级指定的等级和人数不符合要求';
        if (!empty($allTeam)) {
            if (!empty($allTeam['userLevel'])) {
                if (isset($allTeam['userLevel'][$aNextLevel['train_level']])) {
                    if ($aNextLevel['train_number'] <= $allTeam['userLevel'][$aNextLevel['train_level']]['count']) {
                        $log['allMsg'] = '全部团队升级指定的等级和人数符合要求';
                        $allRes = true;
                    }
                }
            }
        }

        $log['allRes'] = $allRes;

        //检查全部成员关联的已支付的全部订单总额
        $orderPriceRes = false;
        $log['orderPriceMsg'] = '全部成员关联的已支付的全部订单总额不符合要求';
        if ($aNextLevel['sales_team_level'] == 1) {
            $checkOrderTeam = $directTeam;
        } elseif ($aNextLevel['sales_team_level'] == 2) {
            //追加自己
            $allTeam['allUser']['onlyUidList'][] = $uid;
            $checkOrderTeam = $allTeam;
        }

        if (!empty($checkOrderTeam['allUser']['onlyUidList'])) {
            $orderTotalPrice = (new MemberModel())->getUserTeamOrderPrice($checkOrderTeam['allUser']['onlyUidList'], [1, 2]);
            $log['orderTotalPrice'] = $orderTotalPrice;
            if ($orderTotalPrice >= doubleval($aNextLevel['sales_price'])) {
                $log['orderPriceMsg'] = '全部成员关联的已支付的全部订单总额符合要求';
                $orderPriceRes = true;
            }
        }

        $log['orderPriceRes'] = $orderPriceRes;
        $upgradeRes = false;
        //同时满足三个条件才能升级
        if (!empty($directRes) && !empty($allRes) && !empty($orderPriceRes)) {
            $log['upgradeMsg'] = '该用户 [' . $aMemberInfo['uid'] . ' - ' . $aMemberInfo['user_phone'] . '] 当前等级为' . $aMemberInfo['level'] . ' 经系统检测符合升级,等级即将升级为' . $aNextLevel['level'];
            $upgradeRes = Db::transaction(function () use ($aNextLevel, $uid) {
                $userSave['vip_level'] = $aNextLevel['level'];
                $memberSave['level'] = $aNextLevel['level'];
                $user = User::update($userSave, ['uid' => $uid]);
                $member = MemberModel::update($memberSave, ['uid' => $uid]);
                return $user->getData();
            });
        } else {
            $log['upgradeMsg'] = '该用户 [' . $aMemberInfo['uid'] . ' - ' . $aMemberInfo['user_phone'] . '] 当前等级为' . $aMemberInfo['level'] . ' 经系统检测不符合升级成为等级' . $aNextLevel['level'] . '的条件';
        }
        //发送消息模版---暂未完成----start

        //发送消息模版---暂未完成----end

        //如果有成功升级且存在上级则判断上级是否也会因此而升级
        if (!empty($topUserUpgrade)) {
            if (!empty($aMemberInfo['link_superior_user'])) {
                $topRes = $this->memberUpgrade($aMemberInfo['link_superior_user']);
                $log['upgradeTopUser'][] = $topRes;
            }
        }

        $log['res'] = $upgradeRes;
        $this->log($log, 'info');

        //return !empty($upgradeRes) ? $upgradeRes->getData() : [];
        return judge($upgradeRes);
    }


    /**
     * @title  升级成为团长
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
        //是否为赠送的团长, 如果是则需要先给他赠送指定的成长值
        $isSend = $orderInfo['is_send'] ?? false;

        if (!empty($orderInfo)) {

            $mUser = User::where(['uid' => $orderInfo['uid']])->findOrEmpty()->toArray();
            $memberVdc = MemberVdc::where(['status' => 1])->field('level,name,growth_value,demotion_growth_value')->order('level asc')->select()->toArray();
            if (empty($memberVdc)) {
                $res['res'] = false;
                $res['msg'] = '暂无有效的会员等级制度';
                $this->recordError($log, ['msg' => $res['msg']]);
                return $res;
            }
            foreach ($memberVdc as $key => $value) {
                $levelName[$value['level']] = $value['name'];
                if ($value['level'] == $memberFirstLevel) {
                    $memberFirstLevelGrowthValue = $value['growth_value'] ?? 0;
                    $memberFirstLevelDemotionGrowthValue = $value['demotion_growth_value'] ?? ($value['growth_value'] ?? 0);
                }
            }
            $levelName[0] = '普通用户';

            $log['userInfo'] = $mUser ?? [];
            $orderTopUser = [];
            if (!empty($orderInfo['link_superior_user'])) {
                $topUser = !empty($mUser['link_superior_user']) ? $mUser['link_superior_user'] : $orderInfo['link_superior_user'];
                $orderTopUser = MemberModel::where(['uid' => $topUser])->findOrEmpty()->toArray();
                //上级如果不是会员,生成一个会员表的占位会员
                if (empty($orderTopUser)) {
                    $topUserInfo = User::where(['uid' => $topUser])->findOrEmpty()->toArray();
                    $this->becomeZeroMember(['uid' => $topUser, 'link_superior_user' => $topUserInfo['link_superior_user'], 'user_phone' => $topUserInfo['phone'] ?? null]);
                    $orderTopUser = MemberModel::where(['uid' => $topUser])->findOrEmpty()->toArray();
                }
            }

            $log['topUserInfo'] = $orderTopUser ?? [];

            if (!empty($mUser)) {
                //如果是赠送的需要赠送指定成长值
                if(!empty($isSend ?? false)) {
                    $detail = GrowthValueDetail::where(['uid' => $orderInfo['uid'], 'assign_level' => $memberFirstLevel, 'arrival_status' => 1, 'status' => 1, 'type' => 4])->findOrEmpty()->toArray();
                    if (empty($detail) && !empty($memberFirstLevelGrowthValue ?? 0)) {
                        $newAssignGrowthDetail['type'] = 4;
                        $newAssignGrowthDetail['uid'] = $orderInfo['uid'];
                        $newAssignGrowthDetail['user_phone'] = $mUser['phone'];
                        $newAssignGrowthDetail['user_level'] = $mUser['vip_level'];
                        $newAssignGrowthDetail['growth_scale'] = '0.01';
                        $newAssignGrowthDetail['growth_value'] = $memberFirstLevelGrowthValue;
                        $newAssignGrowthDetail['surplus_growth_value'] = $memberFirstLevelGrowthValue;
                        $newAssignGrowthDetail['arrival_status'] = 1;
                        $newAssignGrowthDetail['assign_level'] = $memberFirstLevel;
                        $newAssignGrowthDetail['remark'] = '福利专区- ' . ($orderInfo['crowd_key'] ?? '') . ' 系统代理升级赠送';
                        GrowthValueDetail::create($newAssignGrowthDetail);
                        User::where(['uid' => $orderInfo['uid']])->inc('growth_value', $memberFirstLevelGrowthValue)->update();
                        $mUser['growth_value'] += $newAssignGrowthDetail['growth_value'];
                    }
                }

                if (intval($mUser['vip_level']) > 0) {
                    $res['res'] = false;
                    $res['msg'] = $orderInfo['uid'] . '该用户已经为' . ($levelName[$mUser['vip_level']] ?? " <未知等级> ") . ',无法继续绑定升级';
                    $this->recordError($log, ['msg' => $res['msg']]);
                    return $res;
                }
                if ((string)($mUser['growth_value']) < $memberFirstLevelGrowthValue) {
                    $res['res'] = false;
                    $res['msg'] = $orderInfo['uid'] . '该用户成长值不足, 现在为' . $mUser['growth_value'] . ',升级的成长值条件为' . $memberFirstLevelGrowthValue . ',无法继续绑定升级为初级会员';
                    $this->recordError($log, ['msg' => $res['msg']]);
                    return $res;
                }
                //如果原先作为普通会员但是有推荐过人,存在会员体系中的话,允许重新跟上级要求购买团长大礼包然后重新修改会员体系中的登记信息
                $userMember = MemberModel::where(['uid' => $orderInfo['uid'], 'status' => [1, 2]])->findOrEmpty()->toArray();
                $update['level'] = 3;
                $update['type'] = 1;
                if (!empty($isSend ?? false)) {
                    $update['type'] = 2;
                }
                $update['growth_value'] = ($mUser['growth_value'] ?? 0);
                $update['demotion_growth_value'] = $memberFirstLevelDemotionGrowthValue ?? $memberFirstLevelGrowthValue;
                $update['link_superior_user'] = $mUser['link_superior_user'] ?? null;
                //添加开发者备注
                if (!empty($orderInfo['coder_remark'] ?? null)) {
                    $update['coder_remark'] = $orderInfo['coder_remark'];
                }
                if (!empty($orderTopUser)) {
                    $update['team_code'] = $orderTopUser['team_code'];
                    $update['parent_team'] = $orderTopUser['child_team_code'];
                    $update['link_superior_user'] = $orderTopUser['uid'];
                }
                if (!empty($userMember)) {
                    if (empty($userMember['level']) && !empty($orderTopUser ?? [])) {
                        //如果跟原来的上级不一致,则冗余团队结构采用用新上级的
                        if ($userMember['link_superior_user'] != $orderTopUser['uid']) {
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

                        $dbRes = MemberModel::update($update, ['uid' => $orderInfo['uid'], 'status' => [1, 2]]);
                        if (!empty($update['team_chain'] ?? null)) {
                            \app\lib\models\TeamMember::update(['team_chain' => $update['team_chain']], ['uid' => $orderInfo['uid'], 'status' => [1, 2]]);
                        }
                        //修改分润第一人冗余结构
                        Queue::push('app\lib\job\MemberChain', ['uid' => $orderInfo['uid'], 'handleType' => 1], config('system.queueAbbr') . 'MemberChain');

                    } else {
                        $res['res'] = false;
                        $res['msg'] = $orderInfo['uid'] . '该用户无法继续绑定升级';
                        $this->recordError($log, ['msg' => $res['msg']]);
                        return $res;
                    }

                } else {
                    $update['member_card'] = (new CodeBuilder())->buildMemberNum();
                    $update['uid'] = $orderInfo['uid'];
                    $update['user_phone'] = $orderInfo['user_phone'] ?? null;
                    if (!empty($orderTopUser)) {
                        $update['child_team_code'] = (new MemberModel())->buildMemberTeamCode($update['level'], $update['parent_team']);
                    } else {
                        $teamCode = (new MemberModel())->buildMemberTeamCode($update['level'], null);
                        $update['team_code'] = $teamCode;
                        $update['child_team_code'] = $teamCode;
                        $update['parent_team'] = null;
                        $update['link_superior_user'] = null;
                    }
//                    $update['growth_value'] = !empty($orderInfo['growth_value']) ? $orderInfo['growth_value'] : 0;
                    $update['upgrade_time'] = time();
//                    //记录冗余团队结构
                    if (!empty($orderTopUser)) {
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
                    $dbRes = MemberModel::create($update);

                    //修改分润第一人冗余结构
                    Queue::push('app\lib\job\MemberChain', ['uid' => $update['uid'], 'handleType' => 1], config('system.queueAbbr') . 'MemberChain');
                }

                $userUpdateData['vip_level'] = $update['level'];
                $userUpdateData['is_new_vip'] = 2;
                if(!empty($update['link_superior_user'] ?? null)){
                    $userUpdateData['link_superior_user'] = $update['link_superior_user'];
                }

                $userRes = User::update($userUpdateData, ['uid' => $mUser['uid']]);

                //(new User())->where(['uid'=>$orderTopUser['uid']])->inc('team_number',1)->update();

                $res['res'] = true;
                $res['msg'] = '订单用户' . $orderInfo['uid'] . '成功升级为LV3(已修改上级)';
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
     * @title  升级成为占位会员(没有等级的会员)
     * @param array $orderInfo
     * @return mixed
     * @throws \Exception
     */
    public function becomeZeroMember(array $orderInfo)
    {
        $res['res'] = false;
        $res['msg'] = '等待处理中';
        $log['orderInfo'] = $orderInfo;
        $log['uid'] = $orderInfo['uid'];
        $log['type'] = '升级成为占位会员(没有等级的会员)';
        $memberFirstLevel = 0;
        $memberFirstLevelGrowthValue = 0;

        if (!empty($orderInfo)) {

            $mUser = User::where(['uid' => $orderInfo['uid']])->findOrEmpty()->toArray();
            $memberVdc = MemberVdc::where(['status' => 1])->field('level,name,growth_value,demotion_growth_value')->order('level asc')->select()->toArray();
            if (empty($memberVdc)) {
                $res['res'] = false;
                $res['msg'] = '暂无有效的会员等级制度';
                $this->recordError($log, ['msg' => $res['msg']]);
                return $res;
            }
            foreach ($memberVdc as $key => $value) {
                $levelName[$value['level']] = $value['name'];
                if ($value['level'] == $memberFirstLevel) {
                    $memberFirstLevelGrowthValue = $value['growth_value'] ?? 0;
                    $memberFirstLevelDemotionGrowthValue = $value['demotion_growth_value'] ?? ($value['growth_value'] ?? 0);
                }
            }
            $levelName[0] = '普通用户';

            $log['userInfo'] = $mUser ?? [];
            $orderTopUser = [];
            if (!empty($orderInfo['link_superior_user'])) {
                $topUser = !empty($mUser['link_superior_user']) ? $mUser['link_superior_user'] : $orderInfo['link_superior_user'];
                $orderTopUser = MemberModel::where(['uid' => $topUser])->findOrEmpty()->toArray();
                //上级如果不是会员,生成一个会员表的占位会员
                if (empty($orderTopUser)) {
                    $topUserInfo = User::where(['uid' => $topUser])->findOrEmpty()->toArray();
                    $this->becomeZeroMember(['uid' => $topUser, 'link_superior_user' => $topUserInfo['link_superior_user'], 'user_phone' => $topUserInfo['phone'] ?? null]);
                    $orderTopUser = MemberModel::where(['uid' => $topUser])->findOrEmpty()->toArray();
                }
            }

            $log['topUserInfo'] = $orderTopUser ?? [];
            if (!empty($mUser)) {
                if (intval($mUser['vip_level']) > 0) {
                    $res['res'] = false;
                    $res['msg'] = $orderInfo['uid'] . '该用户已经为' . ($levelName[$mUser['vip_level']] ?? " <未知等级> ") . ',无法继续绑定升级';
                    $this->recordError($log, ['msg' => $res['msg']]);
                    return $res;
                }
                //如果原先作为普通会员但是有推荐过人,存在会员体系中的话,允许重新跟上级要求购买团长大礼包然后重新修改会员体系中的登记信息
                $userMember = MemberModel::where(['uid' => $orderInfo['uid'], 'status' => [1, 2]])->findOrEmpty()->toArray();
                $update['level'] = 0;
                $update['type'] = 1;
                $update['growth_value'] = $mUser['growth_value'] ?? 0;
                $update['demotion_growth_value'] = 0;
                $update['link_superior_user'] = $mUser['link_superior_user'] ?? null;
                if (!empty($orderTopUser)) {
                    $update['team_code'] = $orderTopUser['team_code'];
                    $update['parent_team'] = $orderTopUser['child_team_code'];
                    $update['link_superior_user'] = $orderTopUser['uid'];
                }
                if (!empty($userMember)) {
                    if (empty($userMember['level']) && !empty($orderTopUser ?? [])) {
                        //如果跟原来的上级不一致,则冗余团队结构采用用新上级的
                        if ($userMember['link_superior_user'] != $orderTopUser['uid']) {
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

                        $dbRes = MemberModel::update($update, ['uid' => $orderInfo['uid']]);
                        //修改分润第一人冗余结构
                        Queue::push('app\lib\job\MemberChain', ['uid' => $orderInfo['uid'], 'handleType' => 1], config('system.queueAbbr') . 'MemberChain');

                    } else {
                        $res['res'] = false;
                        $res['msg'] = $orderInfo['uid'] . '该用户无法继续绑定升级';
                        $this->recordError($log, ['msg' => $res['msg']]);
                        return $res;
                    }

                } else {
                    $update['member_card'] = (new CodeBuilder())->buildMemberNum();
                    $update['uid'] = $orderInfo['uid'];
                    $update['user_phone'] = $orderInfo['user_phone'] ?? null;
                    if (!empty($orderTopUser)) {
                        $update['child_team_code'] = (new MemberModel())->buildMemberTeamCode($update['level'], $update['parent_team']);
                    } else {
                        $teamCode = (new MemberModel())->buildMemberTeamCode($update['level'], null);
                        $update['team_code'] = $teamCode;
                        $update['child_team_code'] = $teamCode;
                        $update['parent_team'] = null;
                        $update['link_superior_user'] = $mUser['link_superior_user'] ?? null;;
                    }
//                    $update['growth_value'] = !empty($orderInfo['growth_value']) ? $orderInfo['growth_value'] : 0;
                    $update['upgrade_time'] = time();
//                    //记录冗余团队结构
                    if (!empty($orderTopUser)) {
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
                    $dbRes = MemberModel::create($update);

                    //修改分润第一人冗余结构
                    Queue::push('app\lib\job\MemberChain', ['uid' => $update['uid'], 'handleType' => 1], config('system.queueAbbr') . 'MemberChain');
                }
                $userUpdateData['vip_level'] = $update['level'];
                $userUpdateData['is_new_vip'] = 2;
                if(!empty($update['link_superior_user'] ?? null)){
                    $userUpdateData['link_superior_user'] = $update['link_superior_user'];
                }
                $userRes = User::update($userUpdateData, ['uid' => $mUser['uid']]);

                //(new User())->where(['uid'=>$orderTopUser['uid']])->inc('team_number',1)->update();

                $res['res'] = true;
                $res['msg'] = '订单用户' . $orderInfo['uid'] . '成功升级为占位会员(已修改上级)';
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
     * @title  拼拼有礼渠道-升级成为团长
     * @param array $orderInfo
     * @return mixed
     * @throws \Exception
     */
    public function becomeMemberByPpyl(array $orderInfo)
    {
        $res['res'] = false;
        $res['msg'] = '拼拼有礼渠道-等待处理中';
        //$orderInfo 包含uid,user_phone,link_superior_user
        $log['orderInfo'] = $orderInfo;
        $log['uid'] = $orderInfo['uid'];
        $log['type'] = '升级成为会员';
        //默认升级的等级为3,最低一级
        $memberFirstLevel = 3;
        $memberFirstLevelGrowthValue = 0;

        if (!empty($orderInfo)) {

            $mUser = User::where(['uid' => $orderInfo['uid']])->findOrEmpty()->toArray();
            $memberVdc = PpylMemberVdc::where(['status' => 1])->field('level,name')->order('level asc')->select()->toArray();
            if (empty($memberVdc)) {
                $res['res'] = false;
                $res['msg'] = '暂无有效的会员等级制度';
                $this->recordError($log, ['msg' => $res['msg']]);
                return $res;
            }
            $memberVdcInfos = MemberVdc::where(['status' => 1])->field('level,name,growth_value,demotion_growth_value')->order('level asc')->select()->toArray();
            foreach ($memberVdcInfos as $key => $value) {
                $memberVdcInfo[$value['level']] = $value;
            }
            foreach ($memberVdc as $key => $value) {
                $memberVdc[$key]['growth_value'] = $memberVdcInfo[$value['level']]['growth_value'];
                $memberVdc[$key]['demotion_growth_value'] = $memberVdcInfo[$value['level']]['demotion_growth_value'];
            }

            foreach ($memberVdc as $key => $value) {
                $levelName[$value['level']] = $value['name'];
                if ($value['level'] == $memberFirstLevel) {
                    $memberFirstLevelGrowthValue = $value['growth_value'] ?? 0;
                    $memberFirstLevelDemotionGrowthValue = $value['demotion_growth_value'] ?? ($value['growth_value'] ?? 0);
                }
            }
            $levelName[0] = '普通用户';

            $log['userInfo'] = $mUser ?? [];
            $orderTopUser = [];
            if (!empty($orderInfo['link_superior_user'])) {
                $topUser = !empty($mUser['link_superior_user']) ? $mUser['link_superior_user'] : $orderInfo['link_superior_user'];
                $orderTopUser = MemberModel::where(['uid' => $topUser])->findOrEmpty()->toArray();
            }

            $log['topUserInfo'] = $orderTopUser ?? [];
            if (!empty($mUser)) {
                if (intval($mUser['vip_level']) > 0) {
                    $res['res'] = false;
                    $res['msg'] = $orderInfo['uid'] . '该用户已经为' . ($levelName[$mUser['vip_level']] ?? " <未知等级> ") . ',无法继续绑定升级';
                    $this->recordError($log, ['msg' => $res['msg']]);
                    return $res;
                }
                //如果原先作为普通会员但是有推荐过人,存在会员体系中的话,允许重新跟上级要求购买团长大礼包然后重新修改会员体系中的登记信息
                $userMember = MemberModel::where(['uid' => $orderInfo['uid'], 'status' => [1, 2]])->findOrEmpty()->toArray();
                $update['level'] = 3;
                $update['type'] = 1;
                $update['growth_value'] = ($mUser['growth_value'] ?? 0);
                $update['demotion_growth_value'] = $memberFirstLevelDemotionGrowthValue ?? $memberFirstLevelGrowthValue;
                $update['link_superior_user'] = null;
                if (!empty($orderTopUser)) {
                    $update['team_code'] = $orderTopUser['team_code'];
                    $update['parent_team'] = $orderTopUser['child_team_code'];
                    $update['link_superior_user'] = $orderTopUser['uid'];
                }
                if (!empty($userMember)) {
                    if (empty($userMember['level'])) {
                        //如果跟原来的上级不一致,则冗余团队结构采用用新上级的
                        if ($userMember['link_superior_user'] != $orderTopUser['uid']) {
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

                        $dbRes = MemberModel::update($update, ['uid' => $orderInfo['uid'], 'status' => [1, 2]]);
                        if (!empty($update['team_chain'] ?? null)) {
                            \app\lib\models\TeamMember::update(['team_chain' => $update['team_chain']], ['uid' => $orderInfo['uid'], 'status' => [1, 2]]);
                        }
                        //修改分润第一人冗余结构
                        Queue::push('app\lib\job\MemberChain', ['uid' => $orderInfo['uid'], 'handleType' => 1], config('system.queueAbbr') . 'MemberChain');

                    } else {
                        $res['res'] = false;
                        $res['msg'] = $orderInfo['uid'] . '该用户无法继续绑定升级';
                        $this->recordError($log, ['msg' => $res['msg']]);
                        return $res;
                    }

                } else {
                    $update['member_card'] = (new CodeBuilder())->buildMemberNum();
                    $update['uid'] = $orderInfo['uid'];
                    $update['user_phone'] = $orderInfo['user_phone'] ?? null;
                    if (!empty($orderTopUser)) {
                        $update['child_team_code'] = (new MemberModel())->buildMemberTeamCode($update['level'], $update['parent_team']);
                    } else {
                        $teamCode = (new MemberModel())->buildMemberTeamCode($update['level'], null);
                        $update['team_code'] = $teamCode;
                        $update['child_team_code'] = $teamCode;
                        $update['parent_team'] = null;
                        $update['link_superior_user'] = null;
                    }
//                    $update['growth_value'] = !empty($orderInfo['growth_value']) ? $orderInfo['growth_value'] : 0;
                    $update['upgrade_time'] = time();
//                    //记录冗余团队结构
                    if (!empty($orderTopUser)) {
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
                    $dbRes = MemberModel::create($update);

                    //修改分润第一人冗余结构
                    Queue::push('app\lib\job\MemberChain', ['uid' => $update['uid'], 'handleType' => 1], config('system.queueAbbr') . 'MemberChain');
                }

                $userRes = User::update(['vip_level' => $update['level'], 'is_new_vip' => 2, 'link_superior_user' => $update['link_superior_user'] ?? null], ['uid' => $mUser['uid']]);

                //(new User())->where(['uid'=>$orderTopUser['uid']])->inc('team_number',1)->update();

                $res['res'] = true;
                $res['msg'] = '拼拼有礼渠道-订单用户' . $orderInfo['uid'] . '成功升级为VIP(已修改上级)';
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
     * @title  升级成为团长(老方法,暂且保留)
     * @param array $orderInfo
     * @return mixed
     * @date 2020/11/5
     */
    public function becomeMemberOld(array $orderInfo)
    {
        $res['res'] = false;
        $res['msg'] = '等待处理中';
        $log['orderInfo'] = $orderInfo;
        $log['uid'] = $orderInfo['uid'];
        $log['type'] = '升级成为团长';

        if (!empty($orderInfo) && ($orderInfo['order_type'] == 3) && !empty($orderInfo['link_child_team_code']) && !empty($orderInfo['link_superior_user'])) {

            $mUser = User::where(['uid' => $orderInfo['uid']])->findOrEmpty()->toArray();
            $levelName = [1 => '合伙人', 2 => '高级团长', 3 => '团长', 0 => '普通会员'];
            $log['userInfo'] = $mUser ?? [];

            $orderTopUser = MemberModel::where(['uid' => $orderInfo['link_superior_user']])->findOrEmpty()->toArray();

            $log['topUserInfo'] = $orderTopUser ?? [];
            if (!empty($mUser)) {
                if (intval($mUser['vip_level']) > 0) {
                    $res['res'] = false;
                    $res['msg'] = $orderInfo['uid'] . '该用户已经为' . $levelName[$mUser['vip_level']] . ',无法继续绑定升级';
                    $this->recordError($log, ['msg' => $res['msg']]);
                    return $res;
                }
                //如果原先作为普通会员但是有推荐过人,存在会员体系中的话,允许重新跟上级要求购买团长大礼包然后重新修改会员体系中的登记信息
                $userMember = MemberModel::where(['uid' => $orderInfo['uid'], 'status' => [1, 2]])->findOrEmpty()->toArray();
                $update['level'] = 3;
                $update['type'] = 1;
                $update['team_code'] = $orderTopUser['team_code'];
                $update['parent_team'] = $orderTopUser['child_team_code'];
                $update['link_superior_user'] = $orderTopUser['uid'];
                if (!empty($userMember)) {
                    if (empty($userMember['level'])) {
                        $dbRes = MemberModel::update($update, ['uid' => $orderInfo['uid']]);
                    } else {
                        $res['res'] = false;
                        $res['msg'] = $orderInfo['uid'] . '该用户无法继续绑定升级';
                        $this->recordError($log, ['msg' => $res['msg']]);
                        return $res;
                    }

                } else {
                    $update['member_card'] = (new CodeBuilder())->buildMemberNum();
                    $update['uid'] = $orderInfo['uid'];
                    $update['user_phone'] = $orderInfo['user_phone'];
                    $update['child_team_code'] = (new MemberModel())->buildMemberTeamCode($update['level'], $update['parent_team']);
                    $dbRes = MemberModel::create($update);
                }

                $userRes = User::update(['vip_level' => $update['level'], 'is_new_vip' => 2, 'link_superior_user' => $update['link_superior_user']], ['uid' => $mUser['uid']]);

                //(new User())->where(['uid'=>$orderTopUser['uid']])->inc('team_number',1)->update();

                $res['res'] = true;
                $res['msg'] = '订单用户' . $orderInfo['uid'] . '成功升级为团长(已修改上级)';
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
        if (!in_array($level, [0, 1, 2, 3])) {
            $this->recordError($log, ['msg' => '所选会员等级非法']);
            throw new MemberException(['msg' => '所选会员等级非法']);
        }
        if (!empty($topUser) && ($uid == $topUser)) {
            $this->recordError($log, ['msg' => '不允许选自己为上级']);
            throw new MemberException(['msg' => '不允许选自己为上级']);
        }

        $userInfo = User::with(['member'])->where(['uid' => $uid, 'status' => 1])->findOrEmpty()->toArray();
        if (!empty($topUser)) {
            $topUserInfo = User::with(['member'])->where(['uid' => $topUser, 'status' => 1])->findOrEmpty()->toArray();
        }
        $log['userInfo'] = $userInfo ?? [];
        $log['topUserInfo'] = $topUserInfo ?? [];

        if (empty($userInfo) && empty($topUserInfo)) {
            $this->recordError($log, ['msg' => '会员信息异常']);
            throw new UserException();
        }

        //判断是否在不可操作人的uid名单内
        if (!empty($this->canNotOPERUidList)) {
            if (in_array($data['uid'], $this->canNotOPERUidList)) {
                throw new MemberException(['errorCode' => 1600105]);
            }
        }

        //判断是否存在头尾相接的情况,如果需要指定的上级的团队结构包含当前用户,则不允许指定
        if (!empty($userInfo['member']['link_superior_user'] ?? null) && $topUser != ($userInfo['member']['link_superior_user'])) {
            if (!empty($topUserInfo['member']['team_chain'] ?? null) && (strpos($topUserInfo['member']['team_chain'], $uid) !== false)) {
                throw new MemberException(['errorCode' => 1600106]);
            }
        }


        //指定普通用户上级
        if ($level == 0) {
            if (!empty($userInfo['vip_level'])) {
                $this->recordError($log, ['msg' => '不允许将会员降级']);
                throw new UserException(['msg' => '不允许将会员降级']);
            }
            if (empty($topUserInfo)) {
                $this->recordError($log, ['msg' => '指定上级必选']);
                throw new UserException(['msg' => '指定上级必选']);
            }

            $userModel = (new User());
            if (!empty($userInfo['link_superior_user']) && ($userInfo['link_superior_user'] == $topUserInfo['uid'])) {
                $this->recordError($log, ['msg' => '已经是该用户的下级啦']);
                throw new UserException(['msg' => '已经是该用户的下级啦']);
            }
            $dbURes = $userModel->where(['uid' => $userInfo['uid']])->save(['parent_team' => $topUserInfo['member']['child_team_code'], 'link_superior_user' => $topUserInfo['uid']]);
            $userModel->where(['uid' => $topUserInfo['uid']])->inc('team_number', 1)->update();

            if (!empty($userInfo['link_superior_user']) && ($userInfo['link_superior_user'] != $topUserInfo['uid'])) {
                $userModel->where(['uid' => $userInfo['link_superior_user']])->dec('team_number', 1)->update();
            }

            if (!empty($dbURes)) {
                $log['res'] = '指定普通用户上级成功处理';
            }

            //如果用户存在与会员表里面,需要顺带修改分润冗余结构
            $nMemberInfo = MemberModel::where(['uid' => $uid, 'status' => 1])->findOrEmpty()->toArray();
            if (!empty($nMemberInfo) && $topUserInfo['uid'] != $nMemberInfo['link_superior_user']) {
                MemberModel::update(['link_superior_user' => $topUserInfo['uid']], ['uid' => $uid, 'status' => 1]);
                //先修改自己的,然后修改自己整个团队下级的
                $dRes = $this->refreshDivideChain(['uid' => $uid]);
                //团队的用队列形式,避免等待时间太长
                $dtRes = Queue::later(10, 'app\lib\job\MemberChain', ['searUser' => $uid, 'handleType' => 1], config('system.queueAbbr') . 'MemberChain');
            }

            if (!empty($dbURes)) {
                //记录操作日志
                $mLog['uid'] = $uid;
                $mLog['before_level'] = 0;
                $mLog['after_level'] = 0;
                $mLog['before_link_user'] = $userInfo['link_superior_user'] ?? null;
                $mLog['after_link_user'] = $topUserInfo['uid'] ?? null;
                $mLog['type'] = 1;
                $mLog['remark'] = '指定普通用户上级';
                (new AdminLog())->memberOPER($mLog);
            }
            $this->log($log, 'info');
            return judge($dbURes);
        }


        $topUserInc = null;
        $topUserDes = null;
        $needChangeTeam = false;
        $needChangeDivideChain = false;
        $changeType = 1;
        if (!empty($topUser)) {
            if (empty($userInfo['member']) && empty($userInfo['vip_level'])) {
                if (!empty($topUserInfo['member'])) {
                    $uAssign['parent_team'] = $topUserInfo['member']['child_team_code'];
                    $uAssign['link_superior_user'] = $topUserInfo['uid'];
                    $uAssign['vip_level'] = $level;
                    $memberCardType = 1;
                    if ($level == 1) {
                        $memberCardType = 2;
                    }
                    $mAssign['member_card'] = (new CodeBuilder())->buildMemberNum($memberCardType);
                    $mAssign['uid'] = $uid;
                    $mAssign['user_phone'] = $userInfo['phone'] ?? null;
                    if ($level == 1) {
                        $sParentCode = null;
                    } else {
                        $sParentCode = $uAssign['parent_team'];
                    }
                    $mAssign['child_team_code'] = (new MemberModel())->buildMemberTeamCode($level, $sParentCode);
                    $mAssign['team_code'] = $topUserInfo['member']['team_code'];
                    $mAssign['parent_team'] = $uAssign['parent_team'];
                    $mAssign['link_superior_user'] = $uAssign['link_superior_user'];
                    $mAssign['level'] = $level;
                    $mAssign['type'] = 2;
                    $topUserInc = $topUserInfo['uid'];
                } else {
                    throw new MemberException(['errorCode' => 1600103]);
                }
            }

            //2021/1/12 修改为不用判断用户等级,因为可能存在用户vip等级为0但是在会员表里面的(因为要存在团队结构里面)
//            if(!empty($userInfo['member']) && !empty($userInfo['vip_level'])){
            if (!empty($userInfo['member'])) {
//                if($userInfo['vip_level'] > $level){
//                    $this->recordError($log,['msg'=>'不允许将会员降级']);
//                    throw new UserException(['msg'=>'不允许将会员降级']);
//                }
                $uAssign['parent_team'] = $topUserInfo['member']['child_team_code'];
                $uAssign['link_superior_user'] = $topUserInfo['uid'];
                $uAssign['vip_level'] = $level;

                $mAssign['team_code'] = $topUserInfo['member']['team_code'];
                $mAssign['parent_team'] = $topUserInfo['member']['child_team_code'];
                $mAssign['link_superior_user'] = $topUserInfo['uid'];
                $mAssign['type'] = 2;
                $mAssign['level'] = $level;
                $mAssign['user_phone'] = $userInfo['phone'];
                if ($mAssign['level'] == 1) {
                    $mAssign['member_card'] = (new CodeBuilder())->buildMemberNum(2);
                }
                //指定上级与原来的上级不同的情况下需要修改团队成员结构,top_change_type=2为修改该用户的下级归属为指定的上级 1为不修改
                if ($userInfo['link_superior_user'] != $topUserInfo['uid']) {
                    $needChangeTeam = true;
                    $needChangeDivideChain = true;
//                    $topUserInc = $topUserInfo['uid'];
                    $topUserDes = $userInfo['link_superior_user'];
                    $changeType = $data['top_change_type'] ?? 1;
                }
            }
        } else {
            if (empty($userInfo['member']) && empty($userInfo['vip_level'])) {
                $uAssign['vip_level'] = $level;

                $memberCardType = 1;
                if ($level == 1) {
                    $memberCardType = 2;
                }
                $mAssign['member_card'] = (new CodeBuilder())->buildMemberNum($memberCardType);
                $mAssign['uid'] = $uid;
                $mAssign['user_phone'] = $userInfo['phone'];
                $mAssign['child_team_code'] = (new MemberModel())->buildMemberTeamCode($level, null);
                $mAssign['team_code'] = $mAssign['child_team_code'];
                $mAssign['parent_team'] = null;
                $mAssign['link_superior_user'] = !empty($userInfo['link_superior_user']) ? $userInfo['link_superior_user'] : null;
                $mAssign['level'] = $level;
                $mAssign['type'] = 2;
            } else {
                $uAssign['vip_level'] = $level;

                $mAssign['type'] = 2;
                $mAssign['level'] = $level;
                $mAssign['user_phone'] = $userInfo['phone'];
                $mAssign['link_superior_user'] = !empty($userInfo['link_superior_user']) ? $userInfo['link_superior_user'] : null;
                if ($level == 1) {
                    $mAssign['member_card'] = (new CodeBuilder())->buildMemberNum(2);
                }
                //$topUserDes = $userInfo['link_superior_user'];
                $topUserDes = null;
            }
            if (!empty($mAssign['link_superior_user'] ?? null)) {
                $topUserInfo = User::with(['member'])->where(['uid' => $mAssign['link_superior_user'], 'status' => 1])->findOrEmpty()->toArray();
            }
            $log['topUserInfo'] = $topUserInfo ?? [];

        }
        $log['uAssign'] = $uAssign ?? [];
        $log['mAssign'] = $mAssign ?? [];
        if (empty($uAssign) || empty($mAssign)) {
            $this->recordError($log, ['msg' => '指定会员出现了错误,请查验']);
            throw new MemberException();
        }

        $dbRes = Db::transaction(function () use ($uAssign, $mAssign, $uid, $topUser, $topUserInc, $topUserDes, $needChangeTeam, $userInfo, $topUserInfo, $changeType, $needChangeDivideChain) {
            if (!empty($needChangeTeam)) {
                $divideService = new Divide();
                $directTeam = $divideService->getNextDirectLinkUserGroupByLevel($userInfo['member']['uid']);
                //如果存在直推则修改直推的上级为当前用户的上级<---2020-11-19更改为仅修改直推的团队编码>
                $directUser = $directTeam['allUser']['onlyUidList'];
                $dirUserCount = count($directUser);
                if (!empty($dirUserCount)) {
                    //查找所有用户
//                    $userAllTeam = $divideService->getTeamAllUserGroupByLevel($userInfo['member']['uid']);

                    $dUpdate['team_code'] = $topUserInfo['member']['team_code'];
                    if ($changeType == 2) {
                        $dUpdate['parent_team'] = $topUserInfo['member']['child_team_code'];
                        $dUpdate['link_superior_user'] = $topUserInfo['uid'];
                        $dUpdate['create_time'] = time();
                    }

                    //修改会员信息
                    MemberModel::update($dUpdate, ['uid' => $directUser, 'status' => [1, 2]]);

                    if ($changeType == 2) {
                        $dUUpdate['parent_team'] = $dUpdate['parent_team'];
                        $dUUpdate['link_superior_user'] = $dUpdate['link_superior_user'];
                        //修改用户信息
                        User::update($dUUpdate, ['uid' => $directUser, 'status' => [1, 2]]);
                    }

                    if ($changeType == 1) {
                        $dirUserCount = 1;
                    }
                    //增加上级用户团队数和减少当前用户团队数
                    (new User())->where(['uid' => $topUserInfo['uid']])->inc('team_number', $dirUserCount)->update();

                    if ($changeType == 2) {
                        if (!empty($userInfo['team_code']) && $userInfo['team_code'] > 0) {
                            (new User())->where(['uid' => $uid])->dec('team_number', $dirUserCount)->update();
                        }
                    }


//                    //查看当前会员是否有冻结中的分润利益,如果有则由上级用户接管---<暂时先注释>
//                    $notCompleteDivideOrder = DivideModel::where(['link_uid'=>$uid,'arrival_status'=>2])->column('order_sn');
//                    if(!empty($notCompleteDivideOrder)){
//                        $changeDivide = DivideModel::update(['link_uid'=>$topUserInfo['uid']],['order_sn'=>$notCompleteDivideOrder,'link_uid'=>$uid,'arrival_status'=>2]);
//                    }
                }

                //如果有下级则当前用户的上级接管所有下级
//                if(!empty($userAllTeam)){
//                    $allDownUser = $userAllTeam['allUser']['onlyUidList'];
//                    $allUserCount = count($allDownUser);
//                    if(!empty($allUserCount)){
//                        $daUpdate['team_code'] = $topUserInfo['member']['team_code'];
//                        //修改会员信息
//                        MemberModel::update($daUpdate,['uid'=>$allDownUser,'status'=>[1,2]]);
//                    }
//                }

            }
            $memberGrowthValue = MemberVdc::where(['status' => 1])->column('growth_value', 'level');
            //判断是否需要扣除指定的成长值,升级则加成长值 降级则减少成长值 平级不操作
            if ((empty($userInfo['vip_level']) && !empty($uAssign['vip_level'])) || ($uAssign['vip_level'] < $userInfo['vip_level'])) {
                $detail = GrowthValueDetail::where(['uid' => $uid, 'assign_level' => $uAssign['vip_level'], 'arrival_status' => 1, 'status' => 1, 'type' => 4])->findOrEmpty()->toArray();
                if (empty($detail) && !empty($memberGrowthValue[$uAssign['vip_level']])) {
                    $newAssignGrowthDetail['type'] = 4;
                    $newAssignGrowthDetail['uid'] = $uid;
                    $newAssignGrowthDetail['user_phone'] = $userInfo['phone'];
                    $newAssignGrowthDetail['user_level'] = $userInfo['vip_level'];
                    $newAssignGrowthDetail['growth_scale'] = '0.01';
                    $newAssignGrowthDetail['growth_value'] = $memberGrowthValue[$uAssign['vip_level']];
                    $newAssignGrowthDetail['surplus_growth_value'] = $memberGrowthValue[$uAssign['vip_level']];
                    $newAssignGrowthDetail['arrival_status'] = 1;
                    $newAssignGrowthDetail['assign_level'] = $uAssign['vip_level'];
                    $newAssignGrowthDetail['remark'] = '系统代理升级赠送';
                    GrowthValueDetail::create($newAssignGrowthDetail);
                    User::where(['uid' => $uid])->inc('growth_value', $memberGrowthValue[$uAssign['vip_level']])->update();
                    if (!empty($userInfo['vip_level'])) {
                        MemberModel::where(['uid' => $uid])->inc('growth_value', $memberGrowthValue[$uAssign['vip_level']])->update();
                    } else {
                        //如果是更新的情况下,会员表存在记录但是会员等级为0,需要增加成长值
                        if (empty($mAssign['child_team_code']) && empty($userInfo['vip_level'])) {
                            MemberModel::where(['uid' => $uid])->inc('growth_value', $memberGrowthValue[$uAssign['vip_level']])->update();
                        }
                        $addGrowthValue = $memberGrowthValue[$uAssign['vip_level']];
                    }

                }
            } elseif (($uAssign['vip_level'] > $userInfo['vip_level'])) {
                $dMap[] = ['type', '=', 4];
                $dMap[] = ['assign_level', '<', $uAssign['vip_level']];
                $dMap[] = ['arrival_status', '=', 1];
                $dMap[] = ['status', '=', 1];
                $dMap[] = ['uid', '=', $uid];
                $detail = GrowthValueDetail::where($dMap)->select()->toArray();
                if (!empty($detail)) {
                    foreach ($detail as $key => $value) {
                        $updateAssignGrowthDetail['surplus_growth_value'] = 0;
                        $updateAssignGrowthDetail['arrival_status'] = -2;
                        $updateAssignGrowthDetail['status'] = -1;
                        $updateAssignGrowthDetail['remark'] = '系统代理升级赠送,但已被操作降级并回收';
                        GrowthValueDetail::update($updateAssignGrowthDetail, ['id' => $value['id'], 'uid' => $uid]);
                        User::where(['uid' => $uid])->dec('growth_value', $value['growth_value'])->update();
                        MemberModel::where(['uid' => $uid])->dec('growth_value', $value['growth_value'])->update();
                    }
                }

                //查询是否有当前指定等级的赠送成长值记录,如果无则赠送多一次,防止掉级或者出现成长值与等级不符的情况
                $deMap[] = ['type', '=', 4];
                $deMap[] = ['assign_level', '=', $uAssign['vip_level']];
                $deMap[] = ['arrival_status', '=', 1];
                $deMap[] = ['status', '=', 1];
                $deMap[] = ['uid', '=', $uid];
                $exitLevelGrowth = GrowthValueDetail::where($deMap)->count();

                if (empty($exitLevelGrowth)) {
                    $newAssignGrowthDetail['type'] = 4;
                    $newAssignGrowthDetail['uid'] = $uid;
                    $newAssignGrowthDetail['user_phone'] = $userInfo['phone'];
                    $newAssignGrowthDetail['user_level'] = $userInfo['vip_level'];
                    $newAssignGrowthDetail['growth_scale'] = '0.01';
                    $newAssignGrowthDetail['growth_value'] = $memberGrowthValue[$uAssign['vip_level']];
                    $newAssignGrowthDetail['surplus_growth_value'] = $memberGrowthValue[$uAssign['vip_level']];
                    $newAssignGrowthDetail['arrival_status'] = 1;
                    $newAssignGrowthDetail['assign_level'] = $uAssign['vip_level'];
                    $newAssignGrowthDetail['remark'] = '降级后系统代理升级赠送';
                    GrowthValueDetail::create($newAssignGrowthDetail);
                    User::where(['uid' => $uid])->inc('growth_value', $memberGrowthValue[$uAssign['vip_level']])->update();
                    MemberModel::where(['uid' => $uid])->inc('growth_value', $memberGrowthValue[$uAssign['vip_level']])->update();
                }
            }

            if (!empty($uAssign)) {
                $uRes = User::update($uAssign, ['uid' => $uid]);
            }

            if (!empty($mAssign)) {
                //只要操作了等级就修改等级最后升级时间和修改对应的降级成长值
                if (!empty($mAssign['level']) && ($mAssign['level'] != $userInfo['vip_level'])) {
                    $mAssign['upgrade_time'] = time();
                    $demotionGrowthValue = MemberVdc::where(['status' => 1, 'level' => $mAssign['level']])->value('demotion_growth_value');
                    $mAssign['demotion_growth_value'] = $demotionGrowthValue ?? $memberGrowthValue[$userInfo['vip_level']];
                    $needChangeDivideChain = true;
                }

                //查看是否需要修改团队结构冗余字段
                if ($mAssign['link_superior_user'] != ($userInfo['member']['link_superior_user'] ?? null)) {
                    if (empty($mAssign['link_superior_user'])) {
                        $mAssign['team_chain'] = null;
                    } else {
                        if (!empty($topUserInfo['member']['team_chain'] ?? null)) {
                            $mAssign['team_chain'] = $mAssign['link_superior_user'] . ',' . $topUserInfo['member']['team_chain'];
                        } else {
                            if ($topUserInfo['member']['level'] != 1) {
                                throw new MemberException(['msg' => '上级团队结构出现错误,请联系运维管理员']);
                            }
                            $mAssign['team_chain'] = $mAssign['link_superior_user'];
                        }
                    }
                    $needChangeDivideChain = true;
                }


                if (!empty($mAssign['child_team_code'])) {
                    $mAssign['growth_value'] = $addGrowthValue ?? 0;
                    //累加普通用户的原有成长值
                    if (empty($userInfo['vip_level']) && !empty(doubleval($userInfo['growth_value']))) {
                        $mAssign['growth_value'] += $userInfo['growth_value'] ?? 0;
                    }
                    $mRes = MemberModel::create($mAssign);
                    //修改分润第一人冗余结构
                    $dRes = $this->refreshDivideChain(['uid' => $mAssign['uid']]);
                } else {
                    $mRes = MemberModel::update($mAssign, ['uid' => $uid, 'status' => 1]);

                    if (!empty($mAssign['team_chain'] ?? null)) {
                        \app\lib\models\TeamMember::update(['team_chain' => $mAssign['team_chain']], ['uid' => $uid, 'status' => 1]);
                    }
                    if (!empty($mAssign['link_superior_user'] ?? null)) {
                        \app\lib\models\TeamMember::update(['link_superior_user' => $mAssign['link_superior_user']], ['uid' => $uid, 'status' => 1]);
                    }
                    //查看是否需要修改以他为首下面的团队的冗余结构
                    if ($mAssign['link_superior_user'] != ($userInfo['member']['link_superior_user'] ?? null)) {
                        $dirTeamNumber = MemberModel::where(['link_superior_user' => $uid, 'status' => 1])->count();
                        if (!empty($dirTeamNumber)) {
                            if (!empty($userInfo['member']['team_chain'] ?? null)) {
                                $oldTeamChain = $uid . ',' . $userInfo['member']['team_chain'];
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
                                $changeTeamRes = Db::query("UPDATE sp_member set team_chain=REPLACE(team_chain,'" . $oldTeamChain . "','" . $nowTeamChain . "') where uid in (select a.* from (SELECT uid from sp_member where FIND_IN_SET('" . $uid . "',team_chain) and status in (1,2)) a)");

                                $changeTeamMemberRes = Db::query("UPDATE sp_team_member set team_chain=REPLACE(team_chain,'" . $oldTeamChain . "','" . $nowTeamChain . "') where uid in (select a.* from (SELECT uid from sp_team_member where FIND_IN_SET('" . $uid . "',team_chain) and status in (1,2)) a)");
                            }
                        }
                    }

                    //修改分润第一人冗余结构
                    if (!empty($needChangeDivideChain)) {
                        //先修改自己的,然后修改自己整个团队下级的
                        $dRes = $this->refreshDivideChain(['uid' => $uid]);
                        //团队的用队列形式,避免等待时间太长
                        $dtRes = Queue::later(10, 'app\lib\job\MemberChain', ['searUser' => $uid, 'handleType' => 1], config('system.queueAbbr') . 'MemberChain');
                    }

                }
            }
            if (!empty($topUserInc)) {
                (new User())->where(['uid' => $topUserInc])->inc('team_number', 1)->update();
            }
            if (!empty($topUserDes)) {
                (new User())->where(['uid' => $topUserDes])->dec('team_number', 1)->update();
            }
            if (!empty($uAssign)) {
                //记录操作日志
                $mLog['uid'] = $uid;
                $mLog['before_level'] = $userInfo['vip_level'] ?? 0;
                $mLog['after_level'] = $uAssign['vip_level'];
                $mLog['before_link_user'] = $userInfo['link_superior_user'] ?? null;
                $mLog['after_link_user'] = $uAssign['link_superior_user'] ?? null;
                $mLog['type'] = 1;
                $mLog['remark'] = '操作了代理的等级或上级关系';
                (new AdminLog())->memberOPER($mLog);
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
        $memberInfo = MemberModel::with(['parent', 'user'])->where(['uid' => $uid, 'status' => [1, 2]])->findOrEmpty()->toArray();
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
            $parentInfo = MemberModel::with(['user'])->where(['uid' => $parentUid, 'status' => [1, 2]])->findOrEmpty()->toArray();
        }
        $divideService = (new Divide());
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
                    MemberModel::update($dUpdate, ['uid' => $directUser, 'status' => [1, 2]]);

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
                        $changeTeamRes = Db::query("UPDATE sp_member set team_chain=REPLACE(team_chain,'" . $oldTeamChain . "','" . $nowTeamChain . "') , update_time = '" . $updateTime . "' where uid in (select a.* from (SELECT uid from sp_member where FIND_IN_SET('" . $uid . "',team_chain) and status in (1,2)) a)");
                    }

                    //修改分润第一人冗余结构
                    //团队的用队列形式,避免等待时间太长
                    $dtRes = Queue::push('app\lib\job\MemberChain', ['searUser' => $parentMember['uid'], 'update_time' => $updateTime, 'handleType' => 1], config('system.queueAbbr') . 'MemberChain');
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
                    $changeDivide = DivideModel::update(['link_uid' => $parentMember['uid'], 'remark' => '由于原下级' . $memberInfo['user_name'] . '被取消代理权益,故由其上级接管冻结分润'], ['order_sn' => $notCompleteDivideOrder, 'link_uid' => $uid, 'arrival_status' => 2]);
                }
            }

            //删除所有成长值
            GrowthValueDetail::update(['status' => -1, 'arrival_status' => -1, 'surplus_growth_value' => 0], ['uid' => $uid, 'arrival_status' => [1, 2]]);

            //添加成长值减少明细
            $detail['type'] = 3;
            $detail['uid'] = $memberInfo['uid'];
            $detail['user_phone'] = $memberInfo['user_phone'] ?? null;
            $detail['growth_value'] = intval('-' . $memberInfo['growth_value']);
            $detail['surplus_growth_value'] = 0;
            $detail['remark'] = '系统取消会员身份,回收所有成长值';
            $detail['arrival_status'] = 1;
            GrowthValueDetail::create($detail);

            //修改当前用户信息<删除会员信息和等级>
            $res = MemberModel::update(['status' => -1, 'growth_value' => 0, 'team_chain' => null, 'divide_chain' => null], ['uid' => $uid, 'status' => [1, 2]]);
            User::update(['vip_level' => 0, 'team_number' => 0, 'fronzen_balance' => 0, 'growth_value' => 0], ['uid' => $uid, 'status' => [1, 2]]);

            //记录操作日志
            $mLog['uid'] = $uid;
            $mLog['before_level'] = $memberInfo['level'];
            $mLog['after_level'] = 0;
            $mLog['type'] = 2;
            $mLog['remark'] = '取消了代理权益';
            $mLog['growth_value'] = $detail ?? [];
            (new AdminLog())->memberOPER($mLog);
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
        $memberModel = (new MemberModel());
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
//                $member[$key]['member_card'] = $codeBuild->buildMemberNum();
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
                    $value['member_card'] = $codeBuild->buildMemberNum();
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
        $memberVdcInfo = MemberVdc::where(['status' => 1])->field('level,name,growth_value,demotion_growth_value')->select()->toArray();
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
                $newUser[$key]['vip_level'] = $existDBUserInfo[$value['userPhone']]['vip_level'];
                $newUser[$key]['notNewUser'] = true;
            } else {
                $newUser[$key]['uid'] = getUid(10);
                $newUser[$key]['openid'] = $value['openid'] ?? null;
                $newUser[$key]['name'] = $value['userName'];
                $newUser[$key]['phone'] = $value['userPhone'];
                if (empty($memberLevel[$value['shenfen']] ?? null)) {
                    throw new ServiceException(['msg' => '会员身份名称有误']);
                }
                $newUser[$key]['vip_level'] = $memberLevel[$value['shenfen']];
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
                $newUser[$key]['growth_value'] = $memberVdc[$newUser[$key]['vip_level']];
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
        $existDBMember = MemberModel::where(['user_phone' => array_unique(array_column($list, 'userPhone')), 'status' => [1, 2]])->field('uid,user_phone,level,team_code,child_team_code,parent_team')->select()->toArray();

        //查找全部上级的会员信息
        $existDBTopMember = MemberModel::where(['user_phone' => array_unique(array_column($list, 'topUserPhone')), 'status' => [1, 2]])
            ->withCount(['cUserCount' => 'cCount'])
            ->field('uid,user_phone,level,team_code,child_team_code,parent_team')->select()->toArray();
        $allMember = MemberModel::count();
        $notMap[] = ['', 'exp', Db::raw('link_superior_user is null')];
        $notMap[] = ['status', 'in', [1, 2]];
        $notTopUserStartUser = MemberModel::where($notMap)->count();
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
            $mModel = (new MemberModel());

            $allUser = !empty($newUser) ? $newUser : ($oldUser ?? []);

            if (empty($allUser)) {
                throw new ServiceException(['msg' => '查无实际可导入的用户或会员,请检验数据是否已存在']);
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
                $memberModel = (new MemberModel());
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
                        $newMember[$key]['team_code'] = $this->getTeamCode('', $value['vip_level'], $notTopUserStartUser);
                        $newMember[$key]['child_team_code'] = $newMember[$key]['team_code'];
                        $notTopUserStartUser++;
                        $this->notTopUserStartUser = $notTopUserStartUser;
                        $this->topUserTeamStartNumber[$value['uid']] = $notTopUserStartUser;

                    } else {
                        $topUserInfo = $topUser[$value['link_superior_user']] ?? ($DBTopUser[$value['link_superior_user']] ?? []);
                        if (!empty($topUserInfo)) {
                            $newMember[$key]['team_code'] = $topUserInfo['team_code'];
                            $newMember[$key]['child_team_code'] = $this->getTeamCode($topUserInfo['child_team_code'], $value['vip_level'], $this->topUserTeamStartNumber[$value['link_superior_user']] ?? 1000);

                            $newMember[$key]['parent_team'] = $topUserInfo['child_team_code'];
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
                    (new MemberModel())->saveAll($newMember);
                }
            }

            return ['newMember' => $newMember ?? [], 'existMemberUser' => $existMemberUser ?? []];
        });


        $finally['accessUser'] = !empty($newUser) ? array_values($newUser) : [];
        $finally['accessMember'] = !empty($DBRes['newMember'] ?? []) ? array_values($DBRes['newMember']) : [];
        if (!empty($oldUser)) {
            foreach ($oldUser as $key => $value) {
                $oldUser[$key]['vip_name'] = $memberTitle[$value['vip_level']];
                $oldUser[$key]['rawData'] = $userInfo[$value['phone']] ?? [];
            }
        }
        $finally['existUser'] = $oldUser ?? [];
        if (!empty($existMemberUser)) {
            foreach ($existMemberUser as $key => $value) {
                $existMemberUser[$key]['vip_name'] = $memberTitle[$value['vip_level']];
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
        $memberModel = (new MemberModel());
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
                    $newMember['team_code'] = $toptopUser['team_code'];
                    $newMember['child_team_code'] = $this->getTeamCode($toptopUser['child_team_code'], $value['vip_level'], $this->topUserTeamStartNumber[$topUser['link_superior_user']] ?? 1000);
                    if (!empty($this->topUserTeamStartNumber[$topUser['link_superior_user']] ?? null)) {
                        $this->topUserTeamStartNumber[$topUser['link_superior_user']] += 1;
                    }
                    $newMember['parent_team'] = $toptopUser['child_team_code'];
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
            $linkUserParent = Db::query("SELECT u2.member_card,u2.uid,u2.user_phone,u2.team_code,u2.child_team_code,u2.parent_team,u2.level,u2.type,u2.status,u2.link_superior_user,u1.LEVEL AS divide_level FROM(SELECT @id c_ids,(SELECT @id := GROUP_CONCAT( link_superior_user ) FROM sp_member,(SELECT @id := " . "'" . $uid . "'" . ",@l := 0 ) b WHERE status in (1,2) AND FIND_IN_SET( uid, @id COLLATE utf8mb4_unicode_ci )) p_ids,@l := @l + 1 AS LEVEL FROM sp_member WHERE @id IS NOT NULL ) u1 JOIN sp_member u2 ON u1.c_ids = u2.uid COLLATE utf8mb4_unicode_ci WHERE u2.status = 1 ORDER BY u1.LEVEL ASC;");
        } else {
            //mysql8.0.16版本以前使用这条sql
            $linkUserParent = Db::query("SELECT u2.member_card,u2.uid,u2.user_phone,u2.team_code,u2.child_team_code,u2.parent_team,u2.level,u2.type,u2.status,u2.link_superior_user,u1.LEVEL AS divide_level FROM(SELECT @id c_ids,(SELECT @id := GROUP_CONCAT( link_superior_user ) FROM sp_member WHERE status in (1,2) AND FIND_IN_SET( uid, @id COLLATE utf8mb4_unicode_ci )) p_ids,@l := @l + 1 AS LEVEL FROM sp_member,(SELECT @id := " . "'" . $uid . "'" . ",@l := 0 ) b WHERE @id IS NOT NULL ) u1 JOIN sp_member u2 ON u1.c_ids = u2.uid COLLATE utf8mb4_unicode_ci WHERE u2.status = 1 ORDER BY u1.LEVEL ASC;");
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
        $log['msg'] = '获取或刷新用户相关的分润第一人冗余结构';
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

        $list = MemberModel::where($map)->field('uid,level,team_chain')->select()->toArray();
        if (empty($list)) {
            //记录日志
            (new Log())->setChannel('memberChain')->record($log);
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
                $topUserList = MemberModel::where(['uid' => $findTopUser, 'status' => 1])->column('level', 'uid');
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
//                $sql =  "UPDATE sp_member set divide_chain = CASE uid";
//                foreach ($firstNumber as $key => $value) {
////                    $sql = "UPDATE sp_member set divide_chain = '". json_encode($value,256)."'"." where uid = '".$key."';";
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

                        $sql[$key] = "UPDATE sp_member set divide_chain = ( CASE team_chain";
                        foreach ($value as $k => $v) {
                            if (empty($teamChainList[$key] ?? null)) {
                                $teamChainList[$key] = "'" . $k . "'";
                            } else {
                                $teamChainList[$key] .= ",'" . $k . "'";
                            }
//                    $sql = "UPDATE sp_member set divide_chain = '". json_encode($value,256)."'"." where uid = '".$key."';";
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

                    $teamChangeSql = $sql;
                    foreach ($teamChangeSql as $key => $value) {
                        $teamSql[$key] = str_replace('sp_member', 'sp_team_member', $value);
                    }
                    if (!empty($teamSql ?? [])) {
                        foreach ($teamSql as $key => $value) {
                            $tRes[$key] = Db::query($value);
                        }
                    }
                    return $res;
                });
                $log['DBRes'] = $DBRes ?? false;
            }
        }

        //记录日志
        (new Log())->setChannel('memberChain')->record($log);
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
            $linkUserParent = Db::query("SELECT u2.uid,u2.phone as user_phone,u2.vip_level as level,u2.status,u2.link_superior_user,u1.LEVEL AS divide_level FROM(SELECT @id c_ids,(SELECT @id := GROUP_CONCAT( link_superior_user ) FROM sp_user,(SELECT @id := " . "'" . $uid . "'" . ",@l := 0 ) b WHERE status in (1,2) AND FIND_IN_SET( uid, @id COLLATE utf8mb4_unicode_ci )) p_ids,@l := @l + 1 AS LEVEL FROM sp_user WHERE @id IS NOT NULL ) u1 JOIN sp_user u2 ON u1.c_ids = u2.uid COLLATE utf8mb4_unicode_ci WHERE u2.status = 1 " . $otherMapSql . "ORDER BY u1.LEVEL ASC;");
        } else {
            //mysql8.0.16版本以前使用这条sql
            $linkUserParent = Db::query("SELECT u2.uid,u2.phone as user_phone,u2.vip_level as level,u2.status,u2.link_superior_user,u1.LEVEL AS divide_level FROM(SELECT @id c_ids,(SELECT @id := GROUP_CONCAT( link_superior_user ) FROM sp_user WHERE status in (1,2) AND FIND_IN_SET( uid, @id COLLATE utf8mb4_unicode_ci )) p_ids,@l := @l + 1 AS LEVEL FROM sp_user,(SELECT @id := " . "'" . $uid . "'" . ",@l := 0 ) b WHERE @id IS NOT NULL ) u1 JOIN sp_user u2 ON u1.c_ids = u2.uid COLLATE utf8mb4_unicode_ci WHERE u2.status = 1 " . $otherMapSql . " ORDER BY u1.LEVEL ASC;");
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
        $memberDivideChain = MemberModel::where(['uid' => $uid, 'status' => 1])->value('team_chain');

        if (empty($memberDivideChain)) {
            $userTopTeam = $this->getUserTopUserInUserDB(['uid' => $uid, 'notMySelf' => true]);
            if (!empty($userTopTeam)) {
                $aMemberDivideChain = $userTopTeam;
            }
        } else {
            $aMemberDivideChainList = explode(',', $memberDivideChain);
            $topUserList = User::where(['uid' => $aMemberDivideChainList, 'status' => 1])->column('vip_level', 'uid');

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
        $orderInfo = Order::where(['order_sn' => $orderSn])->field('order_sn,uid,uid,pay_status,user_level,order_type')->findOrEmpty()->toArray();
        if (empty($orderInfo)) {
            return [];
        }
        //如果已经存在分润记录,从分润里面拿,如果没有则重新获取上级链条后计算出第一人
        $existDivide = DivideModel::where(['order_sn' => $orderInfo['order_sn'], 'status' => 1])->select()->toArray();
        if (!empty($existDivide)) {
            foreach ($existDivide as $key => $value) {
                if ($orderInfo['order_type'] == 6) {
                    if ($value['type'] == 8 && $value['link_uid'] != $orderInfo['uid'] && $value['is_exp'] != 1) {
                        $divideChain[$value['link_uid']] = $value['level'];
                    } else {
                        if ($value['divide_type'] == 1) {
                            $divideChain[$value['link_uid']] = $value['level'];
                        }
                    }
                } else {
                    if ($value['divide_type'] == 1) {
                        $divideChain[$value['link_uid']] = $value['level'];
                    }
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
        return (new Log())->setChannel('member')->record($data, $level);
    }
}