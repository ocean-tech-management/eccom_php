<?php
// +----------------------------------------------------------------------
// |[ 文档说明: 股东会员模块Model]
// +----------------------------------------------------------------------



namespace app\lib\models;


use app\BaseModel;
use app\lib\exceptions\MemberException;
use app\lib\exceptions\ServiceException;
use app\lib\models\Divide as DivideModel;
use app\lib\models\Member as MemberModel;
use app\lib\models\TeamMember as TeamMemberModel;
use app\lib\services\CodeBuilder;
use app\lib\services\Log;
use app\lib\services\TeamDivide;
use app\lib\services\TeamMember;
use think\facade\Db;
use think\facade\Queue;

class ShareholderMember extends BaseModel
{

    /**
     * @title  会员列表
     * @param array $sear
     * @return array
     * @throws \Exception
     */
    public function list(array $sear = []): array
    {
        if (!empty($sear['keyword'])) {
            $map[] = ['', 'exp', Db::raw($this->getFuzzySearSql('b.name|a.user_phone|b.phone', trim($sear['keyword'])))];
        }
        if (!empty($sear['phone'])) {
            $map[] = ['', 'exp', Db::raw($this->getFuzzySearSql('a.user_phone', trim($sear['phone'])))];
        }

        if (!empty($sear['topUserPhone'])) {
            //topUserType 1为查找直属下级 2为团队全部下级 3为查询分润第一人冗余结构
            $topUser = User::where(['phone' => trim($sear['topUserPhone']), 'status' => $this->getStatusByRequestModule($sear['searType'] ?? 1)])->order('create_time desc')->column('uid');
            if (empty($topUser)) {
                throw new ServiceException(['msg' => '查无该上级用户']);
            }
            switch ($sear['topUserType'] ?? 1) {
                case 1:
                    $map[] = ['a.link_superior_user', 'in', $topUser];
                    break;
                case 2:
                    $topUser = current($topUser);
                    $map[] = ['', 'exp', Db::raw("find_in_set('$topUser',a.team_chain)")];
                    break;
                case 3:
                    $topUser = implode('|', $topUser);
                    //正则查询,不用find_in_set是因为divide_chain字段不是用逗号分隔的
                    //支持多个人,只要$divideTopUser用|分割开就可以了
                    $map[] = ['', 'exp', Db::raw('a.divide_chain REGEXP ' . "'" . $topUser . "'")];
                    break;

            }
        }


        $map[] = ['a.status', 'in', $this->getStatusByRequestModule($sear['searType'] ?? 1)];
        if (!empty($sear['level'])) {
            if (is_array($sear['level'])) {
                $map[] = ['a.level', 'in', $sear['level']];
            } else {
                $map[] = ['a.level', '=', $sear['level']];
            }
        } else {
            $map[] = ['a.level', 'in', [1, 2, 3, 4]];
        }

        if (!empty($sear['start_time']) && !empty($sear['end_time'])) {
            $map[] = ['a.create_time', '>=', strtotime($sear['start_time'])];
            $map[] = ['a.create_time', '<=', strtotime($sear['end_time'])];
        }

        if (!empty($sear['upgrade_start_time']) && !empty($sear['upgrade_end_time'])) {
            $map[] = ['a.upgrade_time', '>=', strtotime($sear['upgrade_start_time'])];
            $map[] = ['a.upgrade_time', '<=', strtotime($sear['upgrade_end_time'])];
        }

        $page = intval($sear['page'] ?? 0) ?: null;

        if (!empty($page)) {
            $aTotal = Db::name('shareholder_member')->alias('a')
                ->join('sp_user b', 'a.uid = b.uid', 'left')
                ->where($map)->count();
            $pageTotal = ceil($aTotal / $this->pageNumber);
        }
        $memberVdc = ShareholderMemberVdc::where(['status'=>1])->column('name','level');
        $memberVdc[0] = '非股东';
        $list = Db::name('shareholder_member')->alias('a')
            ->join('sp_user b', 'a.uid = b.uid', 'left')

            ->join('sp_team_member c', 'a.uid = c.uid', 'left')
            ->join('sp_user d', 'c.link_superior_user = d.uid', 'left')
            ->field('a.uid,a.user_phone,a.level,b.name as user_name,c.link_superior_user,b.avatarUrl,b.team_sales_price,b.team_performance,b.old_all_team_performance,b.old_update_team_performance,d.name as link_user_name,d.phone as link_user_phone,d.team_vip_level as link_user_level,a.create_time,a.upgrade_time,c.demotion_team_sales_price,c.team_chain,c.divide_chain,b.total_balance,b.shareholder_balance,c.team_sales_price_offset')
            ->where($map)
            ->when($page, function ($query) use ($page) {
                $query->page($page, $this->pageNumber);
            })->order('a.create_time desc,a.upgrade_time desc')
            ->select()->each(function ($item, $key) use ($memberVdc){
                if (!empty($item['create_time'])) $item['create_time'] = date('Y-m-d H:i:s', $item['create_time']);
                if (!empty($item['upgrade_time'])) $item['upgrade_time'] = date('Y-m-d H:i:s', $item['upgrade_time']);
                if (!empty($item['divide_chain'])) {
                    $item['divide_chain'] = json_decode($item['divide_chain'], true);
                }
                $item['level_name'] = $memberVdc[$item['level'] ?? 0];
                return $item;
            })->toArray();

        if (!empty($sear['needOrderTotal'])) {
            if (!empty($list)) {
                $allTopUser = array_column($list, 'uid');
                $memberMap[] = ['status', '=', 1];
                $memberMap[] = ['link_superior_user', 'in', $allTopUser];
                $directUser = Member::where($memberMap)->field('uid,link_superior_user')->select()->toArray();

                $userParent = [];
                foreach ($directUser as $key => $value) {
                    $userParent[$value['uid']] = $value['link_superior_user'];
                }
                $directUserUid = array_column($directUser, 'uid');
                $allUser = array_merge_recursive($allTopUser, $directUserUid);

                //查找每个人的订单总额
                $orderMap[] = ['link_uid', 'in', $allUser];
                $orderMap[] = ['arrival_status', 'in', [1]];
                $orderTotalPriceSql = DivideModel::where($orderMap)->field('sum(total_price) as total_price,link_uid as uid,order_sn')->group('uid,order_sn')->buildSql();
                $orderTotalPrice = Db::table($orderTotalPriceSql . " a")->field('sum(total_price) as total_price,uid')->group('uid')->select()->toArray();

                //查找每个人的每月的订单总额
                $monthOrderTotalPriceSql = DivideModel::where($orderMap)->whereMonth('create_time')->field('total_price,link_uid as uid')->group('uid,order_sn')->buildSql();
                $monthOrderTotalPrice = Db::table($monthOrderTotalPriceSql . " a")->field('sum(total_price) as total_price,uid')->group('uid')->select()->toArray();

                foreach ($list as $key => $value) {
                    if (!isset($list[$key]['direct_month_price'])) {
                        $list[$key]['direct_month_price'] = 0;
                    }
                    if (!isset($list[$key]['direct_all_price'])) {
                        $list[$key]['direct_all_price'] = 0;
                    }
                    foreach ($monthOrderTotalPrice as $mAllKey => $mAllValue) {
                        if ($value['uid'] == $mAllValue['uid'] || (!empty($userParent[$mAllValue['uid']]) && $value['uid'] == $userParent[$mAllValue['uid']])) {
                            $list[$key]['direct_month_price'] += $mAllValue['total_price'];
                        }
                    }
                    foreach ($orderTotalPrice as $allKey => $allValue) {
                        if ($value['uid'] == $allValue['uid'] || (!empty($userParent[$allValue['uid']]) && $value['uid'] == $userParent[$allValue['uid']])) {
                            $list[$key]['direct_all_price'] += $allValue['total_price'];
                        }
                    }
                }

                foreach ($list as $key => $value) {
                    $list[$key]['direct_all_price'] += $value['old_update_team_performance'];
                    $list[$key]['team_all_price'] = $value['old_all_team_performance'] + $value['team_performance'];
                }
            }
        }

        return ['list' => $list, 'pageTotal' => $pageTotal ?? 0];
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
        $log['type'] = '升级成为股东';
        $memberFirstLevelGrowthValue = 0;
        $notTeamMemberInfo = false;

        if (!empty($orderInfo)) {

            $mUser = User::where(['uid' => $orderInfo['uid']])->findOrEmpty()->toArray();

            $memberVdc = ShareholderMemberVdc::where(['status' => 1])->order('level asc')->select()->toArray();
            $shareholderMemberInfo = self::where(['uid' => $orderInfo['uid']])->findOrEmpty()->toArray();
            $memberFirstLevel = max(array_column($memberVdc,'level'));

            if(empty($mUser['team_vip_level'] ?? 0)){
                $res['res'] = false;
                $res['msg'] = '非团队会员无法成为股东级会员';
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
                $orderTopUser = TeamMemberModel::where(['uid' => $topUser])->findOrEmpty()->toArray();
            }
            //如果没有团队会员上级则尝试找原来的普通用户上级或会员上级
            if(empty($orderTopUser)){
                $orderTopUser = MemberModel::where(['uid' => $topUser])->findOrEmpty()->toArray();
                $notTeamMemberInfo = true;
            }

            $log['topUserInfo'] = $orderTopUser ?? [];
            if (!empty($mUser)) {
                if (intval($shareholderMemberInfo['level'] ?? 0) > 0) {
                    $res['res'] = false;
                    $res['msg'] = $orderInfo['uid'] . '该用户已经为' . ($levelName[$shareholderMemberInfo['level']] ?? " <未知等级> ") . ',无法继续绑定升级';
                    $this->recordError($log, ['msg' => $res['msg']]);
                    return $res;
                }


                //判断销售额
                $userTotalSalePrice = 0;
                //统计该用户的销售额
                $sMap[] = ['a.link_uid','=',$orderInfo['uid']];
                $sMap[] = ['a.status','=',1];
                $sMap[] = ['', 'exp', Db::raw('b.real_pay_price - b.refund_price > 0 and b.activity_sign in (' . implode(',', (new TeamMember())->activitySign). ')')];

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
                    $this->recordError($log, ['msg' => $res['msg']]);
                    return $res;
                }

                //如果原先作为普通会员但是有推荐过人,存在会员体系中的话,允许重新跟上级要求购买团长大礼包然后重新修改会员体系中的登记信息
                $userMember = self::where(['uid' => $orderInfo['uid'], 'status' => [1, 2]])->findOrEmpty()->toArray();
                $update['level'] = $memberFirstLevel;
                $update['type'] = 1;
                $update['demotion_team_sales_price'] = $memberFirstLevelDemotionGrowthValue ?? $memberFirstLevelGrowthValue;

                if (!empty($userMember)) {
                    if (empty($userMember['level'])) {
                        $update['upgrade_time'] = time();
                        $dbRes = self::update($update, ['uid' => $orderInfo['uid']]);
                    } else {
                        $res['res'] = false;
                        $res['msg'] = $orderInfo['uid'] . '该用户无法继续绑定升级';
                        $this->recordError($log, ['msg' => $res['msg']]);
                        return $res;
                    }

                } else {
                    $update['member_card'] = (new CodeBuilder())->buildTeamMemberNum();
                    $update['uid'] = $orderInfo['uid'];
                    $update['user_phone'] = $orderInfo['user_phone'] ?? ($mUser['phone'] ?? null);
                    $update['demotion_team_sales_price'] = $memberFirstLevelInfo['demotion_sales_price'] ?? $memberFirstLevelInfo['sales_price'];
                    $update['upgrade_time'] = time();
                    $dbRes = self::create($update);
                }


                $res['res'] = true;
                $res['msg'] = '订单用户' . $orderInfo['uid'] . '成功升级为股东级会员LV' . $memberFirstLevel;
                $log['msg'] = $res['msg'];
                $this->log($log, 'info');

                //尝试上级是否可以升级
                if (!empty($mUser['link_superior_user'] ?? null)) {
                    $upgradeQueue = Queue::push('app\lib\job\TeamMemberUpgrade', ['uid' => $mUser['link_superior_user'], 'type' => 4], config('system.queueAbbr') . 'TeamMemberUpgrade');
                }
            } else {
                $res['res'] = false;
                $res['msg'] = $orderInfo['uid'] . ' 查无该用户,无法继续绑定升级';
                $this->recordError($log, ['msg' => $res['msg']]);
            }
        } else {
            $res['res'] = false;
            $res['msg'] = $orderInfo['uid'] . '不符合推荐要求,无法继续绑定升级';
            $this->recordError($log, ['msg' => $res['msg']]);
            return $res;
        }
        return $res;
    }

    /**
     * @title  指定会员
     * @param array $data
     * @return mixed
     */
    public function assign(array $data)
    {
        $uid = $data['uid'] ?? null;
        if (empty($uid)) {
            return false;
        }
        $level = $data['level'] ?? 1;
        //指定类型 1为调整等级  2为取消身份
        $assignType = $data['assign_type'] ?? 1;

        $memberInfo = self::where(['uid' => $uid, 'status' => 1])->findOrEmpty()->toArray();
        if ($assignType == 1) {
            if (!empty($memberInfo) && $memberInfo['level'] == $level) {
                throw new MemberException(['msg' => '该用户已经是股东啦']);
            }
        } else {
            if (empty($memberInfo)) {
                throw new MemberException(['msg' => '该用户不是股东无法取消']);
            }
        }

        $userInfo = User::where(['uid' => $uid, 'status' => 1])->findOrEmpty()->toArray();
        $vdcInfo = ShareholderMemberVdc::where(['level' => $level, 'status' => 1])->findOrEmpty()->toArray();
        if($assignType == 1){
            $update['level'] = $data['level'];
            $update['user_phone'] = $userInfo['phone'] ?? null;
            $update['demotion_team_sales_price'] = $vdcInfo['demotion_sales_price'];
            $update['type'] = 2;
            $update['upgrade_time'] = time();
            if (empty($memberInfo)) {
                $update['uid'] = $uid;
                $res = ShareholderMember::create($update);
            } else {
                $res = ShareholderMember::update($update, ['uid' => $uid, 'status' => 1]);
            }
        }else{
            $update['level'] = 0;
            $update['upgrade_time'] = time();
            $update['demotion_team_sales_price'] = 0;
            $res = ShareholderMember::update($update, ['uid' => $uid, 'status' => 1]);
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
        $allData['msg'] = '股东级会员 ' . $data['uid'] . " [ 升级服务出错:" . ($error['msg'] ?? '原因未知') . " ] ";
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
        return (new Log())->setChannel('shareholderMember')->record($data, $level);
    }

    public function user()
    {
        return $this->hasOne('User','uid','uid');
    }

    public function userName()
    {
        return $this->hasOne('User','uid','uid')->bind(['name']);
    }
}