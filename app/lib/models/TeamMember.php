<?php
// +----------------------------------------------------------------------
// |[ 文档说明: 团队会员模块Model]
// +----------------------------------------------------------------------
// | Copyright (c) 2018~2024 http://www.mlhcmk.com All rights reserved.
// +----------------------------------------------------------------------


namespace app\lib\models;


use app\BaseModel;
use app\controller\api\v1\Summary;
use app\lib\exceptions\MemberException;
use app\lib\exceptions\ServiceException;
use app\lib\models\Divide as DivideModel;
use app\lib\models\Member as MemberModel;
use app\lib\services\CodeBuilder;
use app\lib\services\Divide;
use think\facade\Db;

class TeamMember extends BaseModel
{
    protected $field = ['member_card', 'uid', 'user_phone', 'level', 'link_superior_user', 'status', 'leader_level', 'team_code', 'child_team_code', 'type', 'parent_team', 'team_sales_price', 'upgrade_time', 'demotion_team_sales_price', 'team_chain','team_sales_price_offset'];
    protected $validateFields = ['member_card', 'uid', 'level', 'status' => 'number'];
    private $belong = 1;

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
            $aTotal = Db::name('team_member')->alias('a')
                ->join('sp_user b', 'a.uid = b.uid and b.status = 1', 'left')
                ->join('sp_user c', 'a.link_superior_user = c.uid and c.status = 1', 'left')
                ->where($map)->count();
            $pageTotal = ceil($aTotal / $this->pageNumber);
        }

        $list = Db::name('team_member')->alias('a')
            ->join('sp_user b', 'a.uid = b.uid and b.status = 1', 'left')
            ->join('sp_user c', 'a.link_superior_user = c.uid and c.status = 1', 'left')
            ->field('a.uid,a.member_card,a.user_phone,a.level,a.link_superior_user,b.name as user_name,b.avatarUrl,a.team_sales_price,b.team_performance,b.old_all_team_performance,b.old_update_team_performance,c.name as link_user_name,c.phone as link_user_phone,c.team_vip_level as link_user_level,a.create_time,a.upgrade_time,a.demotion_team_sales_price,a.team_chain,a.divide_chain,b.total_balance,b.ad_balance,a.team_sales_price_offset,b.crowd_balance,b.share_id')
            ->where($map)
            ->when($page, function ($query) use ($page) {
                $query->page($page, $this->pageNumber);
            })->order('a.create_time desc,a.upgrade_time desc')
            ->select()->each(function ($item, $key) {
                if (!empty($item['create_time'])) $item['create_time'] = date('Y-m-d H:i:s', $item['create_time']);
                if (!empty($item['upgrade_time'])) $item['upgrade_time'] = date('Y-m-d H:i:s', $item['upgrade_time']);
                if (!empty($item['divide_chain'])) {
                    $item['divide_chain'] = json_decode($item['divide_chain'], true);
                }
                return $item;
            })->toArray();


        if (!empty($list)) {

            //补充分润第一人冗余结构详细信息
            foreach ($list as $key => $value) {
                if (!empty($value['divide_chain'] ?? [])) {
                    if (empty($divideTopUserList)) {
                        $divideTopUserList = array_keys($value['divide_chain']);
                    } else {
                        $divideTopUserList = array_merge_recursive($divideTopUserList, array_keys($value['divide_chain']));
                    }
                }
                if (!empty($value['team_chain'])) {
                    $teamChain = explode(',', $value['team_chain']);
                    $teamChainTop = end($teamChain);
                    $list[$key]['topTeamUid'] = $teamChainTop;
                    $teamTopUserList[] = $teamChainTop;
                } else {
                    $list[$key]['topTeamUid'] = null;
                }

            }

            //查找顶级团队长的用户信息
            if (!empty($teamTopUserList)) {
                $allTeamUidList = array_unique($teamTopUserList);
                $allTeamTopUserInfos = User::where(['uid' => $allTeamUidList, 'status' => [1, 2]])->field('uid,name,phone,avatarUrl,team_vip_level as level')->select()->toArray();

                if (!empty($allTeamTopUserInfos)) {
                    foreach ($allTeamTopUserInfos as $key => $value) {
                        $allTeamTopUserInfo[$value['uid']] = $value;
                    }

                    foreach ($list as $key => $value) {
                        $list[$key]['topTeamUserInfo'] = $allTeamTopUserInfo[$value['topTeamUid']] ?? [];
                    }
                }
            }

            //查找全部分润第一人的用户信息
            if (!empty($divideTopUserList)) {
                $allDivideUidList = array_unique($divideTopUserList);

                $allDivideUserInfos = User::where(['uid' => $allDivideUidList, 'status' => [1, 2]])->field('uid,name,phone,avatarUrl')->select()->toArray();

                if (!empty($allDivideUserInfos)) {
                    foreach ($allDivideUserInfos as $key => $value) {
                        $allDivideUserInfo[$value['uid']] = $value;
                    }
                    foreach ($list as $key => $value) {
                        $list[$key]['divide_chain_info'] = [];
                        if (!empty($value['divide_chain'])) {
                            foreach ($value['divide_chain'] as $cK => $cV) {
                                if (!empty($allDivideUserInfo[$cK])) {
                                    $list[$key]['divide_chain_info'][$cK] = $allDivideUserInfo[$cK];
                                    $list[$key]['divide_chain_info'][$cK]['level'] = $cV;
                                }
                            }
                            $list[$key]['divide_chain_info'] = array_values($list[$key]['divide_chain_info']);
                        }
                    }
                }
            }
        }

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
     * @title  会员各等级总数列表
     * @param array $sear
     * @return array
     * @throws \Exception
     */
    public function levelMember(array $sear = []): array
    {
        if (!empty($sear['keyword'])) {
            $map[] = ['', 'exp', Db::raw($this->getFuzzySearSql('b.name|b.phone', $sear['keyword']))];
        }

        $map[] = ['a.status', 'in', $this->getStatusByRequestModule($sear['searType'] ?? 1)];
        if (!empty($sear['level'])) {
            $map[] = ['a.level', 'in', $sear['level']];
        } else {
            $map[] = ['a.level', 'in', [1, 2, 3, 4]];
        }

        if (!empty($sear['phone'])) {
            $map[] = ['', 'exp', Db::raw($this->getFuzzySearSql('b.phone', trim($sear['phone'])))];
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

        if (!empty($sear['start_time']) && !empty($sear['end_time'])) {
            $map[] = ['a.create_time', '>=', strtotime($sear['start_time'])];
            $map[] = ['a.create_time', '<=', strtotime($sear['end_time'])];
        }

        if (!empty($sear['upgrade_start_time']) && !empty($sear['upgrade_end_time'])) {
            $map[] = ['a.upgrade_time', '>=', strtotime($sear['upgrade_start_time'])];
            $map[] = ['a.upgrade_time', '<=', strtotime($sear['upgrade_end_time'])];
        }

        $list = Db::name('team_member')->alias('a')
            ->join('sp_user b', 'a.uid = b.uid', 'left')
            ->join('sp_team_member_vdc c', 'c.level = a.level', 'left')
            ->where($map)->group('level')->field('a.level,c.name,count(a.id) as number')
            ->select()->toArray();
        $allNumber = 0;
        foreach ($list as $key => $value) {
            $allNumber += $value['number'];
        }
        $memberTitle = TeamMemberVdc::where(['status' => 1])->order('level asc')->column('name', 'level');
        if (!empty($list)) {
            $existLevel = array_unique(array_filter(array_column($list, 'level')));
            $count = count($list);
            foreach ($memberTitle as $key => $value) {
                if (!in_array($key, $existLevel)) {
                    $list[$count]['level'] = $key;
                    $list[$count]['name'] = $value;
                    $list[$count]['number'] = 0;
                    $count++;
                }
            }
        } else {
            $count = 0;
            foreach ($memberTitle as $key => $value) {
                $list[$count]['level'] = $key;
                $list[$count]['name'] = $value;
                $list[$count]['number'] = 0;
                $count++;
            }
        }

        $all['level'] = 'all';
        $all['name'] = '全部代理';
        $all['number'] = $allNumber ?? 0;
        array_unshift($list, $all);
        if (!empty($list)) {
            array_multisort(array_column($list, 'level'), SORT_ASC, $list);
        }

        return $list;
    }

    /**
     * @title  会员用户详情
     * @param array $data
     * @return mixed
     * @throws \Exception
     */
    public function info(array $data)
    {
        $uid = $data['uid'];
        $map[] = ['uid', '=', $uid];
        $map[] = ['status', 'in', $this->getStatusByRequestModule(1)];
        $aMemberInfo = $this->with(['user', 'pUser', 'vipName'])->where($map)->withoutField('id,update_time')->findOrEmpty()->toArray();
        if (empty($aMemberInfo)) {
            throw new MemberException(['msg' => '非会员用户无法查看~']);
        }
        if (empty($aMemberInfo['level'])) {
            throw new MemberException(['msg' => '该会员还不是正式代理,请联系上级 ' . $aMemberInfo['pUser']['name'] . ' 购买团长大礼包升级~']);
        }
        $aUserLevel = $aMemberInfo['level'];
        $orderTeamType = [1 => '直属团队', 2 => '整个团队'];
        $divide = (new Divide());
        //直推人数
        $directTeam = $divide->getNextDirectLinkUserGroupByLevel($aMemberInfo['uid']);
        //团队全部人数
        $allTeam = $divide->getTeamAllUserGroupByLevel($aMemberInfo['uid']);

        $nextLevel = ($aUserLevel - 1);

        if ($nextLevel > 0) {
            $aNextLevel = MemberVdc::with(['recommend', 'train'])->where(['status' => 1, 'level' => ($aUserLevel - 1)])->order('level desc')->findOrEmpty()->toArray();
        } else {
            $aNextLevel = MemberVdc::with(['recommend', 'train'])->where(['status' => 1, 'level' => $aUserLevel])->order('level desc')->findOrEmpty()->toArray();
        }
        $aMemberInfo['allTeamNumber'] = $allTeam['allUser']['count'] ?? 0;
        if ($aNextLevel['sales_team_level'] == 1) {
            $checkOrderTeam = $directTeam;
        } elseif ($aNextLevel['sales_team_level'] == 2) {
            //追加自己
            $allTeam['allUser']['onlyUidList'][] = $uid;
            $checkOrderTeam = $allTeam;
        } else {
            $checkOrderTeam = $directTeam;
        }

        $aMemberInfo['allTeamOrderPrice'] = 0;
        if (!empty($checkOrderTeam['allUser']['onlyUidList'])) {
            $orderTotalPrice = $this->getUserTeamOrderPrice($checkOrderTeam['allUser']['onlyUidList']);
            $aMemberInfo['allTeamOrderPrice'] = $orderTotalPrice;
        }

        //直推要求
        $aims[0]['title'] = '直接招募' . $aNextLevel['recommend']['name'];
        $aims[0]['aimsNumber'] = $aNextLevel['recommend_number'];
        $aims[0]['nowNumber'] = $directTeam['userLevel'][$aNextLevel['recommend_level']]['count'] ?? 0;
        $aims[1]['title'] = '团队招募' . $aNextLevel['train']['name'];
        $aims[1]['aimsNumber'] = $aNextLevel['train_number'];
        $aims[1]['nowNumber'] = $allTeam['userLevel'][$aNextLevel['recommend_level']]['count'] ?? 0;;
        $aims[2]['title'] = $orderTeamType[$aNextLevel['sales_team_level']];
        $aims[2]['aimsNumber'] = $aNextLevel['sales_price'];
        $aims[2]['nowNumber'] = !empty($orderTotalPrice) ? $orderTotalPrice : 0;
        $info['memberInfo'] = $aMemberInfo ?? [];
        $info['aims'] = $aims ?? [];
        return $info;
    }

    /**
     * @title  检查用户是否为会员
     * @param string $uid
     * @return array
     */
    public function checkUserMember(string $uid): array
    {
        return $this->where(['uid' => $uid, 'status' => [1, 2]])->value('member_num');
    }

    /**
     * @title  获取会员详细信息
     * @param string $uid
     * @return void
     */
    public function getMemberInfo(string $uid)
    {
        $this->where(['uid' => $uid, 'status' => 1])->withoutField('id,update_time')->findOrEmpty()->toArray();
    }

    /**
     * @title  获取用户会员等级
     * @param string $uid 用户uid
     * @param bool $needCache 是否需要缓存信息
     * @return mixed
     */
    public function getUserLevel(string $uid, bool $needCache = false)
    {
        return $this->where(['uid' => $uid, 'status' => [1, 2]])->when($needCache, function ($query) use ($uid) {
            $cache = config('cache.systemCacheKey.orderUserLevel');
            $cacheKey = $cache['key'] . $uid;
            $query->cache($cacheKey, $cache['expire']);
        })->value('level');
    }

    /**
     * @title  获取用户团队成员的销售额
     * @param array $uid
     * @param  $status $status 状态
     * @return int|mixed
     */
    public function getUserTeamOrderPrice(array $uid, array $status = [1, 2])
    {
        $mapD[] = ['link_uid', 'in', $uid];
        $mapD[] = ['arrival_status', 'in', $status];
        $orderTotalPriceSql = DivideModel::where($mapD)->field('total_price')->group('order_sn')->buildSql();
        $orderTotalPrice = Db::table($orderTotalPriceSql . " a")->value('sum(total_price)');
        return $orderTotalPrice ?? 0;
    }

    /**
     * @title  获取会员用户能拿到的会员价和分销折扣
     * @param string $uid 用户uid
     * @return array
     */
    public function getUserMemberPrice(string $uid): array
    {
        $userLevel = $this->getUserLevel($uid);
        $memberRule = (new MemberVdc())->getMemberRule(intval($userLevel));
        $vdc = 0.00;
        $discount = 0.00;
        if (!empty($memberRule)) {
            $discount = $memberRule['discount'];
            if ($memberRule['vdc_type'] == 2) {
                $vdc = $memberRule['vdc_two'];
            } else {
                $vdc = $memberRule['vdc_one'];
            }
        }
        return ['discount' => $discount, 'vdc' => $vdc];
    }

    /**
     * @title  成为新会员
     * @param array $data
     * @return mixed
     */
    public function new(array $data)
    {
        $aUserInfo = (new User())->getUserInfo($data['uid']);
        $add['uid'] = $aUserInfo['uid'];
        $add['user_phone'] = $aUserInfo['phone'];
        $add['member_card'] = (new CodeBuilder())->buildMemberNum();
        $add['level'] = $data['level'] ?? 1;
        $add['link_superior_user'] = $aUserInfo['link_superior_user'] ?? null;
        $res = $this->baseCreate($add);
        return $res;
    }


    /**
     * @title  用户数量汇总
     * @param array $sear
     * @return int
     */
    public function total(array $sear = []): int
    {
        if (!empty($sear['start_time'])) {
            $map[] = ['create_time', '>=', strtotime($sear['start_time'] . ' 00:00:00')];
        }
        if (!empty($sear['end_time'])) {
            $map[] = ['create_time', '<=', strtotime($sear['end_time'] . ' 23:59:59')];
        }
        $map[] = ['status', 'in', [1]];
        $info = $this->where($map)->count();
        return $info;
    }

    /**
     * @title  获取团队编码
     * @param int $level 需要获取的用户需要的等级
     * @param string|null $ParentTeam 父类团队子编码
     * @return string
     * @remark 团队编码长度是固定的!分别是五位,十位,十六位
     */
    public function buildMemberTeamCode(int $level, string $ParentTeam = null)
    {
        $pLength = strlen($ParentTeam);
        $sqlMaxLength = 0;
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
            default:
                $ParentTeam = null;
                break;
        }

        if (!empty($ParentTeam)) {
            $mMap[] = ['', 'exp', Db::raw($this->getFuzzySearSql('child_team_code', $ParentTeam))];
            $mMap[] = ['child_team_code', '<>', $ParentTeam];
            if (!empty($sqlMaxLength)) {
                $mMap[] = ['', 'exp', Db::raw('length(child_team_code) <= ' . $sqlMaxLength)];
            }

        } else {
            //$mMap[] = ['level','=',1];
            $mMap[] = ['', 'exp', Db::raw('length(child_team_code) <= 5')];
        }

        $allMember = Member::where($mMap)->count();
        if ($level == 3 && !empty($ParentTeam)) {
            if (intval($sqlMaxLength) > 10) {
                $SPTFLen = "%06d";
            } else {
                $SPTFLen = "%05d";
            }
        } else {
            $SPTFLen = "%05d";
        }

        $teamCode = trim($ParentTeam . sprintf($SPTFLen, ($allMember + 1)));
        $finalCode = $this->checkTeamCodeOnly($teamCode, $ParentTeam, $SPTFLen, $allMember);
        return $finalCode;
    }

    /**
     * @title  检查会员队伍编号是不是唯一,不是则加1然后递归查找
     * @param string $teamCode
     * @param string $ParentTeam
     * @param string $SPTFLen
     * @param int $allMember
     * @return mixed
     */
    public function checkTeamCodeOnly(string $teamCode, string $ParentTeam = null, string $SPTFLen, int $allMember)
    {
        $finalCode = $teamCode;
        $res = self::where(['child_team_code' => $teamCode])->count();
        if (!empty($res)) {
            $allMember += 1;
            $teamCode = trim($ParentTeam . sprintf($SPTFLen, $allMember));
            $finalCode = $this->checkTeamCodeOnly($teamCode, $ParentTeam, $SPTFLen, $allMember);
        }
        return $finalCode;
    }

    /**
     * @title  查找全部团队下的成员
     * @param array $sear
     * @return array
     * @throws \Exception
     */
    public function searTeamUser(array $sear = [])
    {
//        $userChildTeamCode = $sear['child_team_code'];
//        $uid = Member::where(['child_team_code'=>$userChildTeamCode])->value('uid');
        $uid = $sear['uid'];
        $needEncryptPhone = $sear['encryptPhone'] ?? true;
        //不包含父类自己
//        $mySelfSql = "AND u2.child_team_code != " . "'" . $userChildTeamCode . "'";

        if (!empty($sear['keyword'])) {
            $map[] = ['', 'exp', Db::raw($this->getFuzzySearSql('b.name|b.phone', $sear['keyword']))];
        }

//        $sql = "(SELECT u2.uid,u2.user_phone,u2.child_team_code,u2.parent_team,u2.level,u2.type,u2.link_superior_user,u2.status,u1.team_level FROM( SELECT @ids AS p_ids, (SELECT @ids := GROUP_CONCAT(child_team_code) FROM sp_team_member WHERE FIND_IN_SET(parent_team, @ids COLLATE utf8mb4_unicode_ci)) AS c_ids, @l := @l+1 AS team_level FROM sp_team_member, (SELECT @ids := " . "'" . $userChildTeamCode . "'" . ", @l := 0 ) b WHERE @ids IS NOT NULL ) u1 JOIN sp_team_member u2 ON FIND_IN_SET(u2.child_team_code, u1.p_ids COLLATE utf8mb4_unicode_ci) " . $mySelfSql . " order by u1.team_level )";

        $mySelfSql = "AND u2.uid != " . "'" . $uid . "'";
        $otherSql = '';

        //sql思路 传入父类uid,找到子类后拼接起来形成一个新的父类@ids,然后再用这个新的父类@ids作为父类uids,去找所属下级子类,依次递归一直往下找直到新的父类@ids为null为止
        $databaseVersion = str_replace('.', '', config('system.databaseVersion'));
        if (!empty($databaseVersion) && is_numeric($databaseVersion) && $databaseVersion > 8016) {
            //mysql 8.0.16以上使用这条sql
            $sql = "(SELECT u2.uid,u2.user_phone,u2.child_team_code,u2.parent_team,u2.level,u2.type,u2.link_superior_user,u2.status,u1.team_level,u2.create_time FROM( SELECT @ids AS p_ids, (SELECT @ids := GROUP_CONCAT(uid) FROM sp_team_member, (SELECT @ids := " . "'" . $uid . "'" . ", @l := 0 ) b WHERE FIND_IN_SET(link_superior_user, @ids COLLATE utf8mb4_unicode_ci)) AS c_ids, @l := @l+1 AS team_level FROM sp_team_member WHERE @ids IS NOT NULL ) u1 JOIN sp_team_member u2 ON FIND_IN_SET(u2.uid, u1.p_ids COLLATE utf8mb4_unicode_ci) " . $otherSql . $mySelfSql . " order by u1.team_level)";
        } else {
            //mysql 8.0.16及以下使用这条sql
            $sql = "(SELECT u2.uid,u2.user_phone,u2.child_team_code,u2.parent_team,u2.level,u2.type,u2.link_superior_user,u2.status,u1.team_level,u2.create_time FROM( SELECT @ids AS p_ids, (SELECT @ids := GROUP_CONCAT(uid) FROM sp_team_member WHERE FIND_IN_SET(link_superior_user, @ids COLLATE utf8mb4_unicode_ci)) AS c_ids, @l := @l+1 AS team_level FROM sp_team_member, (SELECT @ids := " . "'" . $uid . "'" . ", @l := 0 ) b WHERE @ids IS NOT NULL ) u1 JOIN sp_team_member u2 ON FIND_IN_SET(u2.uid, u1.p_ids COLLATE utf8mb4_unicode_ci) " . $otherSql . $mySelfSql . " order by u1.team_level)";
        }

        $map[] = ['a.status', '=', 1];

        $list = $aTotal = Db::table($sql . " a")
            ->join('sp_user b', 'a.uid = b.uid', 'left')
            ->join('sp_team_member d', 'b.uid = d.uid', 'left')
            //->join('sp_order c','c.link_superior_user = a.uid and c.pay_status = 2 and c.order_status = 8','left') //sum(c.total_price) as user_order_price
            ->field('a.*')->where($map)->select()->toArray();

        $all['allUser']['onlyUidList'] = array_column($list, 'uid');
        $all['allUser']['list'] = $list;
        $all['allUser']['uid'] = $uid;

        return $all;
    }

    /**
     * @title  指定用户为体验中心身份
     * @param array $data
     * @return mixed
     */
    public function assignToggleExp(array $data)
    {
        $status = $data['is_exp'];
        if($status == 1){
            $save['toggle_level'] = 0;
        }else{
            $save['toggle_level'] = 1;
        }
        $res = self::update($save,['uid'=>$data['uid'],'status'=>1]);
        return judge($res);
    }


    public function importTest($map, $data)
    {
        return $this->updateOrCreate($map, $data);
    }

    public function parent()
    {
        return $this->hasOne(get_class($this), 'uid', 'link_superior_user')->where(['status' => [1, 2]]);
    }

    public function user()
    {
        return $this->hasOne('User', 'uid', 'uid')->bind(['user_name' => 'name', 'user_avatarUrl' => 'avatarUrl', 'vip_level', 'user_team_number' => 'team_number', 'old_all_team_performance', 'old_update_team_performance', 'team_performance','team_vip_level']);
    }

    public function pUser()
    {
        return $this->hasOne('User', 'uid', 'link_superior_user')->field('uid,name,avatarUrl,vip_level,phone,team_vip_level');
    }

    public function cUserCount()
    {
        return $this->hasOne('User', 'link_superior_user', 'uid')->field('uid,phone');
    }

    public function linkOrder()
    {
        return $this->hasMany('Order', 'link_superior_user', 'uid')->where(['pay_status' => 2, 'order_status' => 8]);
    }

    public function vipName()
    {
        return $this->hasOne('TeamMemberVdc', 'level', 'level')->bind(['vip_name' => 'name']);
    }

    public function userInfo()
    {
        return $this->hasOne('User', 'uid', 'uid')->bind(['user_name' => 'name', 'user_avatarUrl' => 'avatarUrl', 'vip_level' => 'level']);
    }

}