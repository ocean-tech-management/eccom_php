<?php
// +----------------------------------------------------------------------
// |[ 文档说明: 用户模块model]
// +----------------------------------------------------------------------
// | Copyright (c) 2018~2024 http://www.mlhcmk.com All rights reserved.
// +----------------------------------------------------------------------


namespace app\lib\models;


use app\BaseModel;
use app\lib\exceptions\AdminException;
use app\lib\exceptions\MemberException;
use app\lib\exceptions\ServiceException;
use app\lib\exceptions\UserException;
use app\lib\models\OperationLog as OperationLogModel;
use app\lib\services\Code;
use app\lib\services\FileUpload;
use app\lib\services\Log;
use app\lib\services\Office;
use app\lib\services\Token;
use app\lib\services\Wx;
use Exception;
use think\facade\Db;
use think\facade\Request;

class User extends BaseModel
{
    protected $field = ['name', 'uid','share_id', 'phone', 'openid', 'avatarUrl', 'vip_level', 'member_card', 'integral', 'address', 'is_new_user', 'is_new_vip', 'is_allow', 'total_balance', 'avaliable_balance', 'fronzen_balance', 'withdraw_total', 'link_superior_user', 'status', 'parent_team', 'team_number', 'old_all_team_performance', 'old_update_team_performance', 'team_performance', 'growth_value', 'old_sync','c_vip_level','c_vip_time_out_time','auto_receive_reward','ppyl_balance','ppyl_withdraw_total','ppyl_fronzen_balance','divide_balance','divide_fronzen_balance','divide_withdraw_total','real_name','pwd','ad_balance','ad_fronzen_balance','ad_withdraw_total','team_vip_level','team_sales_price','team_balance','team_fronzen_balance','team_withdraw_total','shareholder_balance','shareholder_withdraw_total','shareholder_fronzen_balance','area_balance','area_withdraw_total','area_fronzen_balance','related_user','crowd_balance','crowd_withdraw_total','crowd_fronzen_balance','pay_pwd','exp_level','exp_upgrade_time','ban_crowd_transfer','withdraw_bank_card','area_vip_level','healthy_balance','can_buy','team_shareholder_level','team_shareholder_upgrade_time','primary_uid','ticket_balance'];

    protected $validateFields = ['name', 'status' => 'number'];

    /**
     * @title  用户列表
     * @param array $sear
     * @return array
     * @throws \Exception
     */
    public function list(array $sear = []): array
    {
        if (!empty($sear['keyword'])) {
            $map[] = ['', 'exp', Db::raw($this->getFuzzySearSql('name|phone|primary_uid', trim($sear['keyword'])))];
        }
        if (!empty($sear['real_name'])) {
            $map[] = ['', 'exp', Db::raw($this->getFuzzySearSql('real_name', trim($sear['real_name'])))];
        }
        $needOrderSummary = $sear['needOrderSummary'] ?? false;
        if (empty($sear['needAllLevel'])) {
            $map[] = ['vip_level', '=', 0];
        }
        if (!empty($sear['topUserPhone'])) {
            $uMap[] = ['', 'exp', Db::raw($this->getFuzzySearSql('phone', trim($sear['topUserPhone'])))];
            $uMap[] = ['status', '=', 1];
            $topUserUid = User::where($uMap)->column('uid');
            if (!empty($topUserUid)) {
                $map[] = ['link_superior_user', 'in', $topUserUid];
            }
        }
        $orderField = 'create_time desc,id desc';
        if (!empty($sear['exp_level'] ?? null)) {
            $map[] = ['exp_level', '>', 0];
            $orderField = 'exp_upgrade_time desc,create_time desc,id desc';
        }

        if (!empty($sear['team_shareholder_level'] ?? null)) {
            $map[] = ['team_shareholder_level', '>', 0];
            $orderField = 'team_shareholder_upgrade_time desc,create_time desc,id desc';
        }
        if (!empty($sear['ban_crowd_transfer'] ?? null)) {
            $map[] = ['ban_crowd_transfer', '=', $sear['ban_crowd_transfer']];
        }

        if (!empty($sear['can_buy'] ?? null)) {
            $map[] = ['can_buy', '=', $sear['can_buy']];
        }



        $map[] = ['status', 'in', $this->getStatusByRequestModule($sear['searType'] ?? 1)];
        $page = intval($sear['page'] ?? 0) ?: null;
        if (!empty($sear['pageNumber'] ?? null)) {
            $this->pageNumber = intval($sear['pageNumber']);
        }
        if (!empty($page)) {
            $aTotal = $this->where($map)->count();
            $pageTotal = ceil($aTotal / $this->pageNumber);
        }
        $vipTitle = MemberVdc::where(['status' => [1, 2]])->column('name', 'level');

        //total_balance,avaliable_balance,fronzen_balance,withdraw_total
        $list = $this->with(['link','primary','wx'])->where($map)->field('name,uid,share_id,openid,phone,integral,vip_level,member_card,avatarUrl,address,link_superior_user,create_time,growth_value,c_vip_level,c_vip_time_out_time,auto_receive_reward,ppyl_balance,ppyl_withdraw_total,ppyl_fronzen_balance,divide_balance,divide_fronzen_balance,divide_withdraw_total,ad_balance,ad_fronzen_balance,ad_withdraw_total,real_name,crowd_balance,exp_level,exp_upgrade_time,(ad_balance + team_balance + area_balance + shareholder_balance) as h5_balance,(ppyl_balance + divide_balance) as shop_balance,ban_crowd_transfer,team_shareholder_level,team_shareholder_upgrade_time,primary_uid')->withSum(['notReceive'=>'ppylNotReceiveReward'],'real_reward_price')->withSum(['ppylFrozen'=>'ppylFrozenReward'],'real_reward_price')->withSum(['arrive'=>'ppylArriveReward'],'real_reward_price')->when($page, function ($query) use ($page) {
            $query->page($page, $this->pageNumber);
        })->when($needOrderSummary, function ($query) use ($sear) {
            $orderMap = [];
            if (!empty($sear['order_start_time']) && !empty($sear['order_end_time'])) {
                $orderMap[] = ['create_time', '>=', strtotime($sear['order_start_time'])];
                $orderMap[] = ['create_time', '<=', strtotime($sear['order_end_time'])];
            }
            $query->where($orderMap)->withCount(['orderSummary'])->withSum('orderSummary', 'real_pay_price');
        })->order($orderField)->select()->each(function ($item) use ($vipTitle) {
            $item['vip_name'] = $vipTitle[$item['vip_level']] ?? '普通用户';
            if (empty($item['link_user_level'])) {
                $item['link_user_level'] = 0;
            }
            $item['link_user_vip_name'] = $vipTitle[$item['link_user_level']] ?? '普通用户';
            if(!empty($item['c_vip_time_out_time'])){
                $item['c_vip_time_out_time'] = timeToDateFormat($item['c_vip_time_out_time']);
            }
            if(!empty($item['exp_upgrade_time'])){
                $item['exp_upgrade_time'] = timeToDateFormat($item['exp_upgrade_time']);
            }
            if(!empty($item['team_shareholder_upgrade_time'])){
                $item['team_shareholder_upgrade_time'] = timeToDateFormat($item['team_shareholder_upgrade_time']);
            }
            $item['total_reward'] = priceFormat(($item['ppyl_balance'] ?? 0) + ($item['ppyl_withdraw_total'] ?? 0) + ($item['ppyl_fronzen_balance'] ?? 0) + ($item['divide_balance'] ?? 0) + ($item['divide_fronzen_balance'] ?? 0) + ($item['divide_withdraw_total'] ?? 0));
            $item['total_balance'] = priceFormat(($item['ppyl_balance'] ?? 0)  + ($item['ppyl_fronzen_balance'] ?? 0) + ($item['divide_balance'] ?? 0) + ($item['divide_fronzen_balance'] ?? 0));
            $item['total_withdraw'] = priceFormat(($item['ppyl_withdraw_total'] ?? 0)+ ($item['divide_withdraw_total'] ?? 0));
            if(empty($item['ppylNotReceiveReward'] ?? null)){
                $item['ppylNotReceiveReward'] = '0.00';
            }
            if(empty($item['ppylFrozenReward'] ?? null)){
                $item['ppylFrozenReward'] = '0.00';
            }
            if(empty($item['ppylArriveReward'] ?? null)){
                $item['ppylArriveReward'] = '0.00';
            }
        })->toArray();

        return ['list' => $list, 'pageTotal' => $pageTotal ?? 0];
    }

    /**
     * @title  新建用户
     * @param array $data
     * @return mixed
     * @throws
     */
    public function new(array $data)
    {
        $add['uid'] = getUid(10);
        $add['name'] = $data['nickname'];
        $add['phone'] = $data['phone'] ?? null;
        $add['openid'] = $data['openid'] ?? null;
        $add['avatarUrl'] = $data['headimgurl'];
        $add['share_id'] = getUid(11);

        if (empty($data['link_user'] ?? null)) {
            $add['link_superior_user'] = SystemConfig::where(['status' => 1])->value('default_link_uid');
        } else {
            $topUserInfo = User::where(['uid' => $data['link_user'], 'status' => 1])->count();
            if (!empty($topUserInfo)) {
                $add['link_superior_user'] = $data['link_user'];
            } else {
                $add['link_superior_user'] = SystemConfig::where(['status' => 1])->value('default_link_uid');
            }
        }
        if (!empty($data['phone'] ?? null)) {
            //默认登录密码为手机尾数后四位
            $add['pwd'] = md5(substr($data['phone'], -4));
            //赠送提前购卡
            (new AdvanceCardDetail())->sendAdvanceBuyCard(['send_type' => 4, 'uid' => $add['uid']]);
        }

        $add['is_new_user'] = 1;
        $res = $this->validate()->baseCreate($add);
        return $res->getData();
    }

    /**
     * @title  微信授权更新用户信息
     * @param array $data
     * @return array
     */
    public function wxEdit(array $data)
    {
        $user = User::where(['uid' => $data['openid'], 'status' => 1])->field('uid,name,phone,address,avatarUrl,link_superior_user')->findOrEmpty()->toArray();
        $save['name'] = $data['nickname'];
        $save['avatarUrl'] = $data['headimgurl'];
        if (!empty($user) && empty($user['link_superior_user'] ?? null) && !empty($data['link_user'] ?? null)) {
            $topUserInfo = User::where(['uid' => $data['link_user'], 'status' => 1])->count();
            if (!empty($topUserInfo)) {
                $save['link_superior_user'] = $data['link_user'];
            } else {
                $save['link_superior_user'] = SystemConfig::where(['status' => 1])->value('default_link_uid');
            }

        }
        $res = $this->baseUpdate(['openid' => $data['openid'], 'status' => [1, 2]], $save);
        $userInfo = [];
        if ($res) {
            $userInfo = $this->getUserInfoByOpenid($data['openid']);
        }
        return $userInfo;
    }

    /**
     * @title  微信授权更新用户信息 V2
     * @param array $data
     * @return array
     */
    public function wxEditV2(array $data)
    {
        $user = User::where(['uid' => $data['uid'], 'status' => 1])->field('uid,name,phone,address,avatarUrl,link_superior_user')->findOrEmpty()->toArray();

        $save['name'] = $data['nickname'];
        $save['avatarUrl'] = $data['headimgurl'];
        if (!empty($user) && empty($user['link_superior_user'] ?? null) && !empty($data['link_user'] ?? null)) {
            $topUserInfo = User::where(['uid' => $data['link_user'], 'status' => 1])->count();
            if (!empty($topUserInfo)) {
                $save['link_superior_user'] = $data['link_user'];
            } else {
                $save['link_superior_user'] = SystemConfig::where(['status' => 1])->value('default_link_uid');
            }

        }
        $res = $this->baseUpdate(['uid' => $data['uid'], 'status' => [1, 2]], $save);
        $userInfo = [];
        if ($res) {
//            $userInfo = $this->getUserInfoByUid($data['uid']);
            $userInfo = $this->getCorrelationUserInfoByUid($data['uid'],$data['openid']);
        }
        return $userInfo;
    }

    /**
     * @title  更新资料
     * @param array $data
     * @throws \Exception
     * @return mixed
     */
    public function edit(array $data)
    {
        $save['name'] = $data['name'];
        $nowUserInfo = self::where(['uid' => $data['uid'], 'status' => 1])->field('id,uid,phone,vip_level,pwd,advance_buy_card')->findOrEmpty()->toArray();
        if (!empty($data['phone'])) {
            //Code::getInstance()->goCheck($data['phone'],$data['code']);
            $save['phone'] = trim($data['phone']);
            $phoneExist = User::where(['phone' => $save['phone']])->field('uid,status,old_sync,openid,avatarUrl,name')->findOrEmpty()->toArray();
            if (!empty($phoneExist)) {
                if (in_array($phoneExist['old_sync'], [1, 2]) && $phoneExist['uid'] != $data['uid']) {
                    throw new ServiceException(['msg' => ' 该手机号码异常，请使用其他号码']);
                } elseif ($phoneExist['old_sync'] == 5) {
                    //暂时注释,交换新老身份的做法
//                    $nowUserInfo = $this->where(['uid'=>$data['uid']])->findOrEmpty()->toArray();
//                    $oldUpdate['name'] = $nowUserInfo['name'];
//                    $oldUpdate['openid'] = $nowUserInfo['openid'];
//                    $oldUpdate['avatarUrl'] = $nowUserInfo['avatarUrl'];
//                    $oldUpdate['is_new_user'] = 2;
//                    $oldUpdate['is_new_vip'] = 2;
//                    $oldUpdate['old_sync'] = 1;
//                    $oldMap[] = ['','exp',Db::raw('openid is null')];
//                    $oldMap[] = ['uid','=',$phoneExist['uid']];
//                    $oldMap[] = ['old_sync','=',5];
//                    $updateOldUser = User::update($oldUpdate,$oldMap);
//                    $updateNowUser = User::update(['status'=>-1,'old_sync'=>3],['uid'=>$data['uid']]);
//                    $oldUserInfo = $this->where(['uid'=>$phoneExist['uid']])->findOrEmpty()->toArray();
//                    return ['changeUser'=>true,'oldUser'=>true,'userInfo'=>$oldUserInfo];
                }
            }
            Member::update(['user_phone' => $save['phone']], ['uid' => $data['uid'], 'status' => [1, 2]]);
            TeamMember::update(['user_phone' => $save['phone']], ['uid' => $data['uid'], 'status' => [1, 2]]);
        }

        if (!empty($data['address'])) $save['address'] = $data['address'];
        if (!empty($data['avatarUrl'])) $save['avatarUrl'] = $data['avatarUrl'];
        //默认登录密码为手机尾数后四位
        if(!empty($save['phone']) && empty($nowUserInfo['pwd'] ?? null)){
            $save['pwd'] = md5(substr($save['phone'],-4));
        }
        $res = $this->validate()->baseUpdate(['uid' => $data['uid'], 'status' => [1, 2]], $save);

        //查询用户是否有订单记录, 如果没有则赠送提前购卡<仅在用户没有手机号码的时候才有>
        if (empty($nowUserInfo['phone'])) {
            $orderList = Order::where(['uid' => $data['uid'], 'pay_status' => 2, 'order_status' => [2, 3]])->findOrEmpty()->toArray();
            if (empty($orderList)) {
                (new AdvanceCardDetail())->sendAdvanceBuyCard(['send_type' => 4, 'uid' => $data['uid']]);
            }
        }


        //只有当前用户没有等级的情况下才自动同步
        if (!empty($nowUserInfo ?? []) && empty($nowUserInfo['vip_level'])) {
            //自动同步信息
            if (!empty($save['phone'] ?? null)) {
                $syncData['phone'] = $save['phone'];
                $syncData['uid'] = $data['uid'];
                $syncData['notThrowError'] = true;
                $syncRes = $this->syncOldUser($syncData);

                //如果同步成功了,清除当前用户的token,使其强制重新登录
                if (!empty($syncResy)) {
                    cache(Request::header('token'), null);
                }

                //记录日志
                $log['msg'] = '自动同步导入用户信息';
                $log['requestData'] = $syncData;
                $log['res'] = $syncRes;
                (new Log())->setChannel('member')->record($log, 'info');
            }
        }

        return ['changeUser' => false, 'userInfo' => []];
    }

    /**
     * @title  更新用户头像或昵称
     * @param array $data
     * @return mixed
     */
    public function editNicknameOrAvatarUrl(array $data)
    {
        $access_key = getAccessKey();
        $wxConfig = config("system.clientConfig.$access_key");
        $data['tid'] = UserAuthType::where(['uid' => $data['uid'], 'app_id' => $wxConfig['app_id'], 'access_key' => $access_key])->value('tid');
        foreach ($data as $key => $value) {
            if($key == 'name'){
                checkParam($value);
            }
        }
        $res = Db::transaction(function () use ($data) {
            $wxRes = WxUser::update(['nickname' => $data['name'], 'headimgurl' => $data['avatarUrl']], ['tid' => $data['tid']]);
            $uRes = self::update(['name' => $data['name'], 'avatarUrl' => $data['avatarUrl']], ['uid' => $data['uid'], 'status' => [1, 2]]);
            if ($wxRes && $uRes) {
                return $uRes;
            }
            return false;
        });
        return $res;
    }

    /**
     * @title  根据用户uid获取用户信息
     * @param string $uid
     * @return array
     */
    public function getUserInfo(string $uid): array
    {
        $info = $this->with(['memberVdc', 'unionId','teamMemberVdc'])->where(['uid' => $uid, 'status' => 1])->withoutField('id,create_time,update_time,is_allow,toggle_level')->withCount(['userCoupon' => function ($query, &$alias) {
            $cMap[] = ['valid_status', 'in', [1]];
            $cMap[] = ['valid_end_time', '>', time()];
            $query->where($cMap);
            $alias = 'coupon_count';
        }])->findOrEmpty()->toArray();

        if (!empty($info)) {
            $info['orderNumber'] = (new Order())->cOrderStatusSummary(['uid' => $uid]);
            $info['growth_value_simple'] = $info['growth_value'] > 10000 ? intval($info['growth_value'] / 10000) . 'w' : $info['growth_value'];
            if (empty($info['member_card'])) {
                $info['member_card'] = Member::where(['uid' => $uid, 'status' => 1])->value('member_card') ?? null;
            }
            $info['need_set_pay_pwd'] = true;
            $info['pay_pwd_unset'] = true;
            if (!empty($info['pay_pwd'])) {
                $info['need_set_pay_pwd'] = false;
                $info['pay_pwd_unset'] = false;
            }
            unset($info['pay_pwd']);
            if (empty($info['handing_scale']) && empty($info['vip_level'])) {
                $info['handing_scale'] = '0.07';
                $info['vip_level'] = 0;
                $info['vip_name'] = '普通用户';
            }

            if (empty($info['team_vip_level'])) {
                $info['team_vip_level'] = 0;
                $info['team_vip_name'] = '非团队代理';
            }

            if (!empty($info['c_vip_time_out_time'])) {
                $info['c_vip_time_out_time'] = timeToDateFormat($info['c_vip_time_out_time']);
            }

            $info['allow_sync_primary_account'] = true;
            $isPrimary = self::where(['primary_uid' => $uid, 'status' => 1])->count();
            if (!empty($isPrimary) || !empty($info['primary_uid'] ?? null)) {
                $info['allow_sync_primary_account'] = false;
            }
            if(!empty($info['advance_buy_card'] ?? null)){
                $info['advance_buy_card'] = priceFormat($info['advance_buy_card']);
            }

            //是否有美丽金和订单
            $info['is_has'] = 0;
            $userOrderCount = (new Order())->checkUserOrder($uid);
            if ($userOrderCount > 0 || floatval($info['crowd_balance']) > 0){
                $info['is_has'] = 1;
            }

            $platform = Request::param('platform') ?? 1;
            //1为小程序商城钱包 2为H5 3为小程序众筹钱包
            switch ($platform) {
                case 1:
                    $info['total_balance'] = priceFormat($info['ppyl_balance'] + $info['divide_balance']);
                    $info['avaliable_balance'] = priceFormat($info['ppyl_balance'] + $info['divide_balance']);
                    break;
                case 2:
                    $info['total_balance'] = priceFormat($info['ad_balance'] + $info['team_balance'] + $info['shareholder_balance'] + $info['area_balance']);
                    $info['avaliable_balance'] = priceFormat($info['ad_balance'] + $info['team_balance'] + $info['shareholder_balance'] + $info['area_balance']);
                    break;
                case 3:
                    $info['total_balance'] = priceFormat($info['crowd_balance']);
                    $info['avaliable_balance'] = priceFormat($info['crowd_balance']);
                    break;
                default:
                    $info['total_balance'] = 0;
                    $info['avaliable_balance'] = 0;
            }
        }
        return $info;
    }

    /**
     * @title  同步老用户资料
     * @param array $data
     * @return mixed
     */
    public function syncOldUser(array $data)
    {
        $save['phone'] = trim($data['phone']);
        $notThrowError = $data['notThrowError'] ?? false;
        $phoneExist = User::where(['phone' => $save['phone']])->field('uid,status,old_sync,openid,avatarUrl,name,pwd')->findOrEmpty()->toArray();
        $nowUserOrder = Order::where(['uid' => $data['uid']])->count();
        if (!empty($nowUserOrder)) {
            if (empty($notThrowError)) {
                throw new MemberException(['msg' => '该账号已产生过订单,无法同步信息']);
            } else {
                return false;
            }
        }
        if (!empty($phoneExist)) {
            if ($phoneExist['old_sync'] == 5) {
                $nowUserInfo = $this->where(['uid' => $data['uid']])->findOrEmpty()->toArray();
                $oldUpdate['name'] = $nowUserInfo['name'];
                $oldUpdate['openid'] = $nowUserInfo['openid'];
                $oldUpdate['avatarUrl'] = $nowUserInfo['avatarUrl'];
                $oldUpdate['is_new_user'] = 2;
                $oldUpdate['is_new_vip'] = 2;
                $oldUpdate['old_sync'] = 1;
                //默认登录密码为手机尾数后四位
                if(!empty($phoneExist['phone']) && empty($phoneExist['pwd'] ?? null)){
                    $oldUpdate['pwd'] = md5(substr($phoneExist['phone'],-4));
                }
                $oldMap[] = ['', 'exp', Db::raw('openid is null')];
                $oldMap[] = ['uid', '=', $phoneExist['uid']];
                $oldMap[] = ['old_sync', '=', 5];
                $updateOldUser = User::update($oldUpdate, $oldMap);
                $updateNowUser = User::update(['status' => -1, 'old_sync' => 3], ['uid' => $data['uid']]);
                $oldUserInfo = $this->where(['uid' => $phoneExist['uid']])->findOrEmpty()->toArray();
                $res = ['changeUser' => true, 'oldUser' => true, 'userInfo' => $oldUserInfo ?? []];
            } elseif ($phoneExist['old_sync'] == 1) {
                $res = ['changeUser' => false, 'oldUser' => true, 'userInfo' => []];
            } else {
                $res = ['changeUser' => false, 'oldUser' => false, 'userInfo' => []];
                //$updateNowUser = User::update(['old_sync' => 4], ['uid' => $data['uid']]);
            }

        } else {
            $res = ['changeUser' => false, 'oldUser' => false, 'userInfo' => []];
            //默认登录密码为手机尾数后四位
            if (!empty($save['phone']) && empty($phoneExist['pwd'] ?? null)) {
                $update['pwd'] = md5(substr($save['phone'], -4));
            }
            $update['phone'] = $data['phone'];
            $updateNowUser = User::update($update, ['uid' => $data['uid']]);
            Member::update(['phone' => $data['phone']], ['uid' => $data['uid']]);
        }

        return $res;
    }

    /**
     * @title  根据电话号码获取用户信息
     * @param string $phone 用户手机号码
     * @return array
     */
    public function getUserInfoByPhone(string $phone): array
    {
        return $this->where(['phone' => $phone, 'status' => 1])->withoutField('id,create_time,update_time,openid,is_allow')->findOrEmpty()->toArray();
    }

    /**
     * @title  根据openid获取用户信息
     * @param string $phone 用户openid
     * @return array
     */
    public function getUserInfoByOpenid(string $phone): array
    {
        return $this->where(['openid' => $phone, 'status' => 1])->field('uid,name,phone,address,avatarUrl,link_superior_user,primary_uid')->findOrEmpty()->toArray();
    }

    /**
     * @title  根据电话号码获取用户信息(同步信息时用)
     * @param string $phone 用户手机号码
     * @return array
     */
    public function syncGetUserInfoByPhone(string $phone): array
    {
        return $this->where(['phone' => $phone, 'status' => 1])->field('uid,name,phone,avatarUrl,vip_level,link_superior_user,primary_uid')->findOrEmpty()->toArray();
    }


    /**
     * @title  获取用户被保护的信息
     * @param string $uid 用户uid
     * @param bool $needCache 是否需要缓存信息
     * @return array
     */
    public function getUserProtectionInfo(string $uid, bool $needCache = false): array
    {
        $info = $this->where(['uid' => $uid, 'status' => 1])->field('uid,share_id,phone,openid,is_allow,total_balance,avaliable_balance,fronzen_balance,withdraw_total,link_superior_user,vip_level,c_vip_level,c_vip_time_out_time,auto_receive_reward,divide_balance,ppyl_balance,divide_fronzen_balance,ppyl_fronzen_balance,divide_withdraw_total,ppyl_withdraw_total,ad_balance,ad_fronzen_balance,ad_withdraw_total,team_balance,team_fronzen_balance,team_withdraw_total,shareholder_balance,shareholder_fronzen_balance,shareholder_withdraw_total,area_balance,area_fronzen_balance,area_withdraw_total,crowd_balance,crowd_withdraw_total,crowd_fronzen_balance,advance_buy_card,pay_pwd,withdraw_bank_card,can_buy,integral,ticket_balance')->when($needCache, function ($query) use ($uid) {
            $cache = config('cache.systemCacheKey.userProtectionInfo');
            $cacheKey = $cache['key'] . $uid;
            $query->cache($cacheKey, $cache['expire']);
        })->findOrEmpty()->toArray();
        if (!empty($info)) {
            $info['total_balance'] = ($info['divide_balance'] ?? 0) + ($info['ppyl_balance'] ?? 0);
        }
        $info['openid'] = self::getOpenIdByUid($uid);
        return $info;
    }

    /**
     * 根据用户id获取openid
     * @param string $uid
     * @param string $appId
     * @return mixed
     */
    // public static function getOpenIdByUid(string $uid,string $appId = 'wxf132402c38f4d841')
    public static function getOpenIdByUid(string $uid)
    {
        $appId = config("system.clientConfig." . getAccessKey())['app_id'];
        if (empty($appId)) {
            return null;
        }
        return UserAuthType::where(['uid' => $uid, 'app_id' => $appId, 'status' => 1])->value('openid');
    }

    /**
     * @title  绑定上级关系
     * @param string $uid 当前用户uid
     * @param string $superiorUid 绑定的上级用户uid
     * @return bool
     * @throws \Exception
     */
    public function bindSuperiorUser(string $uid, string $superiorUid)
    {
        $userInfo = $this->getUserInfo($uid);
        $superiorUser = $this->getUserInfo($superiorUid);
        //判断条件,两个用户都必须为合法用户,当前用户没有绑定上级,不允许自己绑定自己,要绑定上级用户的上级用户不允许为自己(这是为了防止闭环绑定)
        if (!empty($userInfo) && !empty($superiorUser)) {
            if (empty($userInfo['link_superior_user'])) {
                if ($uid != $superiorUid && ($superiorUser['link_superior_user'] != $uid)) {
                    $res = $this->baseUpdate(['uid' => $uid, 'status' => [1, 2]], ['link_superior_user' => $superiorUid]);
                    if (judge($res)) {
                        //发送消息模板
//                        $wxUser = $this->getUserProtectionInfo($superiorUid);
//                        $data['openid'] = $wxUser['openid'];
                        $data['openid'] = self::getOpenIdByUid($uid);
                        $data['type'] = 'bind';
                        $data['access_key'] = getAccessKey();
                        $data['template'] = ['first' => '有新用户跟您绑定关系啦~', $userInfo['name'], 'ta已经成为您的下级，ta成为会员或购买课程您就可以获得一定比例的分润哦~'];
                        (new Wx())->sendTemplate($data, 2);
                        //查看上级是否可升级
                        //(new Member())->memberUpgrade($superiorUid);
                    }
                }
            }
        }


        return true;
    }

    /**
     * @title  我的团队
     * @param array $sear
     * @return array
     * @throws \Exception
     */
    public function teamList(array $sear)
    {
        $uid = $sear['uid'];
        $level = $sear['level'] ?? 1;

        $map[] = ['link_superior_user', '=', $uid];
        $map[] = ['status', 'in', [1]];

        $page = intval($sear['page'] ?? 0) ?: null;
        if (!empty($page)) {
            $aTotal = $this->where($map)->count();
            $pageTotal = ceil($aTotal / $this->pageNumber);
        }

        $list = $this->where($map)->field('uid,name,phone,avatarUrl,create_time,vip_level')->withCount(['next'])->when($page, function ($query) use ($page) {
            $query->page($page, $this->pageNumber);
        })->order('create_time desc')->select()->each(function ($item, $key) use ($level) {
            $item['teamType'] = 2;
            if ($level == 1) {
                $item['next_count'] = 0;
            }
            if (!empty($item['phone'])) {
                $item['phone'] = substr_replace($item['phone'], '****', 3, 4);
            }

            return $item;
        })->toArray();
//        $topUser = $this->where(['uid'=>$uid,'status'=>1])->value('link_superior_user');
//        if(!empty($topUser)){
//            $map[] = ['link_superior_user','=',$topUser];
//            $topList = $this->where($map)->field('uid,name,phone,avatarUrl,create_time,vip_level')->withCount(['next','top'])->select()->each(function ($item,$key){
//                $item['teamType'] = 1;
//                return $item;
//            })->toArray();
//        }
        return ['list' => $list, 'pageTotal' => $pageTotal ?? 0];
    }

    /**
     * @title  用户数量汇总
     * @param array $sear
     * @return array
     */
    public function total(array $sear = []): array
    {
//        if(!empty($sear['start_time'])){
//            $map[] = ['create_time','>=',strtotime($sear['start_time'].' 00:00:00')];
//        }
//        if(!empty($sear['end_time'])){
//            $map[] = ['create_time','<=',strtotime($sear['end_time'].' 23:59:59')];
//        }
        if (!empty($sear['keyword'])) {
            $map[] = ['', 'exp', Db::raw($this->getFuzzySearSql('name|phone', $sear['keyword']))];
        }
        if (!empty($sear['user_level'])) {
            if (is_array($sear['user_level'])) {
                $map[] = ['vip_level', 'in', $sear['user_level']];
            } else {
                $map[] = ['vip_level', '=', $sear['user_level']];
            }
        } else {
            $map[] = ['vip_level', '=', 0];
        }
        if (!empty($sear['topUserPhone'])) {
            $uMap[] = ['', 'exp', Db::raw($this->getFuzzySearSql('phone', trim($sear['topUserPhone'])))];
            $uMap[] = ['status', '=', 1];
            $topUserUid = User::where($uMap)->column('uid');
            if (!empty($topUserUid)) {
                $map[] = ['link_superior_user', 'in', $topUserUid];
            }
        }

        $map[] = ['status', 'in', $this->getStatusByRequestModule($sear['searType'] ?? 1)];
        $info = $this->where($map)->count();
        if ($sear['needTimeSummaryData'] ?? false) {
            $todayNew = $this->where($map)->whereDay('create_time')->count();
            $weekNew = $this->where($map)->whereWeek('create_time')->count();
            $monthNew = $this->where($map)->whereMonth('create_time')->count();
        }
        $finally['allUserNumber'] = $info;
        $finally['todayUserNumber'] = $todayNew ?? 0;
        $finally['weekUserNumber'] = $weekNew ?? 0;
        $finally['monthUserNumber'] = $monthNew ?? 0;
        return $finally;
    }

    /**
     * 获取关联用户信息
     * @param string $uid
     * @param string $openid
     * @return array
     */
    public function getCorrelationUserInfoByUid(string $uid,string $openid): array
    {
        return $this->alias('u')
            ->leftJoin([UserAuthType::getTable()=>'auth'],'auth.uid = u.uid COLLATE utf8mb4_unicode_ci and auth.status = 1')
            ->field('u.id,auth.uid,share_id,name,phone,auth.openid,avatarUrl,link_superior_user,is_new_user,u.create_time,u.update_time')
            ->where(['u.uid'=>$uid,'auth.openid'=>$openid])
            ->findOrEmpty()
            ->toArray();
    }

    /**
     * @title  获取用户对应的角色区域
     * @param string $uid
     * @return array|bool
     */
    public function getUserIdentityByUid(string $uid)
    {
        $userInfo = $this->getUserInfo($uid);
        if (empty($userInfo)) {
            return false;
        }
        if (empty($userInfo['vip_level'])) {
            $isNewUser = (new Order())->checkUserOrder($uid);
            if (empty($isNewUser)) {
                $userIdentity = [1, 2, 9];
            } else {
                $userIdentity = [1, 8, 9];
            }
        } else {
            $aUserType = (new CouponUserType())->where(['vip_level' => $userInfo['vip_level'], 'status' => 1, 'u_group_type' => 2])->value('u_type');
            if (!empty($aUserType)) {
                $userIdentity = [1, $aUserType, 3, 8];
            } else {
                $userIdentity = [1, 3, 8];
            }

        }
        return $userIdentity;

    }

    /**
     * @title  根据用户uid获取用户信息(授权书专属)
     * @param string $uid
     * @return array
     */
    public function getUserInfoForWarrant(string $uid): array
    {
        $info = $this->with(['memberVdc', 'member'])->where(['uid' => $uid, 'status' => 1])->field('uid,name,phone,avatarUrl,vip_level')->findOrEmpty()->toArray();
        return $info;
    }

    /**
     * @title  关联的普通用户列表
     * @param array $sear
     * @return array
     * @throws \Exception
     */
    public function teamDirectNormalUser(array $sear)
    {
        if (empty($sear['uid'])) {
            return ['list' => [], 'pageTotal' => 0, 'total' => 0];
        }

        if (!empty($sear['keyword'])) {
            $map[] = ['', 'exp', Db::raw($this->getFuzzySearSql('name|phone', trim($sear['keyword'])))];
        }
        $map[] = ['link_superior_user', '=', $sear['uid']];
        $map[] = ['vip_level', '=', 0];

        switch ($sear['searTimeType'] ?? null) {
            //本日
            case 1:
                $map[] = ['create_time', '>=', strtotime(date('Y-m-d') . ' 00:00:00')];
                $map[] = ['create_time', '<=', strtotime(date('Y-m-d') . ' 23:59:59')];
                break;
            case 2:
                //本月
                $thisMonthStart = date('Y-m-01', strtotime(date("Y-m-d"))) . ' 00:00:00';
                $thisMonthEnd = date('Y-m-d', strtotime("$thisMonthStart +1 month -1 day")) . ' 23:59:59';
                $map[] = ['create_time', '>=', strtotime($thisMonthStart)];
                $map[] = ['create_time', '<=', strtotime($thisMonthEnd)];
                break;
        }

        $page = intval($sear['page'] ?? 0) ?: null;

        if (!empty($page)) {
            $aTotal = $this->where($map)->count();
            $pageTotal = ceil($aTotal / $this->pageNumber);
        }

        $list = $this->where($map)->field('uid,openid,vip_level,name,phone,avatarUrl,growth_value,create_time')->withSum('orderSummary', 'real_pay_price')->order('create_time desc')->when($page, function ($query) use ($page) {
            $query->page($page, $this->pageNumber);
        })->select()->each(function ($item) {
            if (!empty($item['phone'])) {
                $item['phone'] = encryptPhone($item['phone']);
            }
        })->toArray();

        return ['list' => $list, 'pageTotal' => $pageTotal ?? 0, 'total' => $aTotal ?? 0];
    }

    /**
     * @title  关联的普通用户列表数据汇总面板
     * @param array $sear
     * @return array
     * @throws \Exception
     */
    public function teamDirectNormalUserSummary(array $sear)
    {
        if (empty($sear['uid'])) {
            return ['list' => [], 'pageTotal' => 0, 'total' => 0];
        }

        if (!empty($sear['keyword'])) {
            $map[] = ['', 'exp', Db::raw($this->getFuzzySearSql('name|phone', trim($sear['keyword'])))];
        }
        $map[] = ['link_superior_user', '=', $sear['uid']];
        $map[] = ['vip_level', '=', 0];

        $allUser = $this->where($map)->field('uid,phone,name,vip_level,avatarUrl,create_time')->order('create_time desc')->select()->toArray();

        //本月的开始和结束
        $thisMonthStart = date('Y-m-01', strtotime(date("Y-m-d"))) . ' 00:00:00';
        $thisMonthEnd = date('Y-m-d', strtotime("$thisMonthStart +1 month -1 day")) . ' 23:59:59';

        $AllNumber[0]['number'] = 0;
//        $AllNumber[0]['title'] = '注册用户';
//        $AllNumber[0]['list'] = [];
//        $AllNumber[0]['specificType'] = 'a0';
        $todayNumber[0]['number'] = 0;
//        $todayNumber[0]['title'] = '注册用户';

//        $todayNumber[0]['list'] = [];
//        $todayNumber[0]['specificType'] = 'd0';
        $MonthNumber[0]['number'] = 0;
//        $MonthNumber[0]['title'] = '注册用户';
//        $MonthNumber[0]['list'] = [];
//        $MonthNumber[0]['specificType'] = 'm0';

        if (!empty($allUser)) {
            //统计当日和当月的人员数据
            foreach ($allUser as $key => $value) {
                $level = $value['vip_level'];
                if (!isset($AllNumber[$level])) {
                    $AllNumber[$level]['number'] = 0;
//                    $AllNumber[$level]['list'] = [];
//                    $AllNumber[$level]['specificType'] = 'a'.$level;
                }
                if (!isset($todayNumber[$level])) {
                    $todayNumber[$level]['number'] = 0;
//                    $todayNumber[$level]['list'] = [];
//                    $todayNumber[$level]['specificType'] = 'd'.$level;
                }
                if (!isset($MonthNumber[$level])) {
                    $MonthNumber[$level]['number'] = 0;
//                    $MonthNumber[$level]['list'] = [];
//                    $MonthNumber[$level]['specificType'] = 'm'.$level;
                }
                //统计全部
                $AllNumber[$level]['number'] += 1;
//                $AllNumber[$level]['list'][] = $value;
//                $AllNumber[$level]['specificType'] = 'a'.$level;
                //统计当天
                if (substr($value['create_time'], 0, 10) == date('Y-m-d')) {
                    $todayNumber[$level]['number'] += 1;
//                    $todayNumber[$level]['list'][] = $value;
//                    $todayNumber[$level]['specificType'] = 'd'.$level;
                }
                //统计当月
                if ((strtotime($value['create_time']) >= strtotime($thisMonthStart)) && (strtotime($value['create_time']) <= strtotime($thisMonthEnd))) {
                    $MonthNumber[$level]['number'] += 1;
//                    $MonthNumber[$level]['list'][] = $value;
//                    $MonthNumber[$level]['specificType'] = 'm'.$level;
                }
            }
        }

        $finally = ['all' => $AllNumber ?? [], 'today' => $todayNumber ?? [], 'month' => $MonthNumber ?? []];

        return $finally ?? [];
    }

    /**
     * @title  通过openid获取用户uid
     * @param string $openid
     * @return mixed
     */
    public function getUidByOpenid(string $openid)
    {
        //        return $this->where(['openid' => $openid, 'status' => 1])->value('uid');
        return UserAuthType::where(['openid' => $openid,'status' => 1])->value('uid');
    }

    /**
     * @title  读取excel表中电话号码获取用户信息
     * @param array $data
     * @return array
     * @throws \Exception
     */
    public function getUserInfoByExcel(array $data)
    {
        $type = 5;
        $fileUpload = (new FileUpload())->type('excel')->upload(Request::file('file'), false, 'uploads/office');
        $list = (new Office())->ReadExcel($fileUpload, $type);
        if (empty($list)) {
            return [];
        }

        $map[] = ['', 'exp', Db::raw('openid is not null')];
        $map[] = ['phone', 'in', array_unique(array_column($list, 'userPhone'))];
        $map[] = ['status', 'in', [1, 2]];
        $userInfos = self::where($map)->order('create_time asc')->field('uid,phone,name,avatarUrl')->select()->toArray();

        if (empty($userInfos)) {
            throw new UserException(['msg' => '不存在有效的用户']);
        }

        foreach ($userInfos as $key => $value) {
            $userInfo[$value['phone']] = $value;
        }
        foreach ($list as $key => $value) {
            $list[$key]['info'] = $userInfo[$value['userPhone']] ?? null;
        }
        return $list;
    }

    /**
     * @title  H5端登录
     * @param array $data
     * @return mixed
     */
    public function login(array $data)
    {
        $map[] = ['phone', '=', $data['phone']];
        $map[] = ['pwd', '=', md5($data['pwd'])];
        $map[] = ['status', 'in', $this->getStatusByRequestModule($sear['searType'] ?? 1)];
        $aInfo = $this->where($map)->field('uid,name,phone,avatarUrl,vip_level,status')->findOrEmpty()->toArray();
        if (empty($aInfo)) {
            throw new ServiceException(['msg' => '帐号或密码错误']);
        }
        if ($aInfo['status'] == 2) {
            throw new ServiceException(['msg' => '无法登陆']);
        }
        $token = (new Token())->buildToken($aInfo);
        $aInfo['token'] = $token;

        //记录当前登录用户的所有token, 以便后续用户登录异常清除所有token
        $cacheKey = 'h5-' . $aInfo['uid'];
        $LoginToken = cache($cacheKey);
        if (empty($LoginToken)) {
            $LoginToken = [$token];
        } else {
            $LoginToken[] = $token;
        }
        if (!empty($LoginToken)) {
            cache($cacheKey, $LoginToken, 10800);
        }

        return $aInfo;
    }

    /**
     * @title  修改H5端密码
     * @param array $data
     * @return bool
     */
    public function updatePwd(array $data)
    {
        if (empty($data['pwd'] ?? null) || empty($data['uid'] ?? null)) {
            return false;
        }
//        if (!preg_match("/^(([a-z]+[0-9]+)|([0-9]+[a-z]+))[a-z0-9]*$/i", trim($data['pwd']))) {
//            throw new ServiceException(['msg' => '密码仅允许字母和数字']);
//        }
        $map[] = ['uid', '=', $data['uid']];
        $map[] = ['status', '=', 1];
        $aInfo = $this->where($map)->field('uid,name,phone,avatarUrl,pwd,status')->findOrEmpty()->toArray();
        if (empty($aInfo)) {
            throw new ServiceException(['msg' => '无用户信息']);
        }
        if (!empty($aInfo['pwd']) && ($aInfo['pwd'] != md5(trim($data['old_pwd'])))) {
            throw new ServiceException(['msg' => '输入信息有误, 请检查']);
        }
        $res = self::update(['pwd' => md5(trim($data['pwd']))], ['uid' => $data['uid'], 'status' => 1]);
        return judge($res);
    }

    /**
     * @title  修改支付密码
     * @param array $data
     * @return bool
     */
    public function updatePayPwd(array $data)
    {
        if (empty($data['pay_pwd'] ?? null) || empty($data['uid'] ?? null)) {
            return false;
        }
//        if (!is_numeric($data['pay_pwd']) || strlen($data['pay_pwd']) != 6) {
//            throw new ServiceException(['msg' => '支付密码仅允许为六位数字']);
//        }
        $map[] = ['uid', '=', $data['uid']];
        $map[] = ['status', '=', 1];
        $aInfo = $this->where($map)->field('uid,name,phone,avatarUrl,pay_pwd,status')->findOrEmpty()->toArray();
        if (empty($aInfo)) {
            throw new ServiceException(['msg' => '无用户信息']);
        }
        if (!empty($aInfo['pay_pwd']) && ($aInfo['pay_pwd'] != md5(trim($data['old_pay_pwd'] ?? null)) && $aInfo['pay_pwd'] != $data['old_pay_pwd'])) {
            throw new ServiceException(['msg' => '输入信息有误, 请检查']);
        }
        $res = self::update(['pay_pwd' => md5(trim($data['pay_pwd']))], ['uid' => $data['uid'], 'status' => 1]);
        return judge($res);
    }

    /**
     * @title  指定用户为体验中心身份
     * @param array $data
     * @return mixed
     */
    public function assignToggleExp(array $data)
    {
        $status = $data['is_exp'];
        if ($status == 1) {
            $save['exp_level'] = $data['exp_level'] ?? 1;
        } else {
            $save['exp_level'] = 0;
        }
        $save['exp_upgrade_time'] = time();
        $res = self::update($save, ['uid' => $data['uid'], 'status' => 1]);
        return judge($res);
    }

    /**
     * @title  指定用户为团队股东身份
     * @param array $data
     * @return mixed
     */
    public function assignToggleTeamShareholder(array $data)
    {
        $status = $data['is_team_shareholder'];
        if ($status == 1) {
            $save['team_shareholder_level'] = $data['team_shareholder_level'] ?? 4;
        } else {
            $save['team_shareholder_level'] = 0;
        }
        $save['team_shareholder_upgrade_time'] = time();
        $res = self::update($save, ['uid' => $data['uid'], 'status' => 1]);
        return judge($res);
    }

    /**
     * @title  禁用用户互转美丽金功能
     * @param array $data
     * @return mixed
     */
    public function banUserCrowdTransfer(array $data)
    {
        $status = $data['ban_status'];
        if ($status == 1) {
            $save['ban_crowd_transfer'] = 2;
        } else {
            $save['ban_crowd_transfer'] = 1;
        }
        if (!empty($data['choose_all'] ?? false)) {
            $res = self::update($save, ['status' => 1]);
        } else {
            $res = self::update($save, ['uid' => $data['uid'], 'status' => 1]);
        }
        return judge($res);
    }

    /**
     * @title  禁止用户购买功能
     * @param array $data
     * @return mixed
     */
    public function banUserBuy(array $data)
    {
        $status = $data['ban_status'];
        if ($status == 1) {
            $save['can_buy'] = 2;
        } else {
            $save['can_buy'] = 1;
        }
        if (!empty($data['choose_all'] ?? false)) {
            $res = self::update($save, ['status' => 1]);
        } else {
            $res = self::update($save, ['uid' => $data['uid'], 'status' => 1]);
        }
        return judge($res);
    }

    /**
     * @title  区代或体验中心用户列表
     * @param array $sear
     * @return array
     * @throws \Exception
     */
    public function areaOrToggleUserList(array $sear)
    {
        if (!empty($sear['keyword'])) {
            $map[] = ['', 'exp', Db::raw($this->getFuzzySearSql('name|phone', trim($sear['keyword'])))];
        }
        if (!empty($sear['uid'] ?? null)) {
            $map[] = ['uid', '=', $sear['uid']];
        }
        $orderField = 'create_time desc,id desc';

        $map[] = ['status', 'in', $this->getStatusByRequestModule($sear['searType'] ?? 1)];
        $page = intval($sear['page'] ?? 0) ?: null;
        if (!empty($page)) {
            $aTotal = $this->where($map)->count();
            $pageTotal = ceil($aTotal / $this->pageNumber);
        }

        $list = $this->where($map)->where(function ($query) {
            $where1[] = ['exp_level', '>', 0];
            $where2[] = ['area_vip_level', '>', 0];
            $query->whereOr([$where1, $where2]);
        })->field('name,uid,phone,avatarUrl,area_vip_level,exp_level')->when($page, function ($query) use ($page) {
            $query->page($page, $this->pageNumber);
        })->order($orderField)->select()->toArray();

        return ['list' => $list, 'pageTotal' => $pageTotal ?? 0];
    }

    /**
     * @title  修改用户手机号码
     * @param array $data
     * @return bool
     */
    public function updateUserPhone(array $data)
    {
        $userInfo = self::where(['uid' => $data['uid'], 'status' => 1])->findOrEmpty()->toArray();
        if (empty($userInfo)) {
            throw new UserException(['msg' => '不存在的用户或该用户不允许编辑']);
        }

        $phone = trim($data['phone']);
        if (!is_numeric($phone) || strlen($phone) != 11) {
            throw new UserException(['msg' => '请输入有效的手机号码']);
        }
        $map[] = ['uid', '<>', $data['uid']];
        $map[] = ['phone', '=', $phone];
        $existPhone = self::where($map)->count();
        if (!empty($existPhone)) {
            throw new UserException(['msg' => '手机号码已存在']);
        }
        $DBRes = Db::transaction(function () use ($data, $phone) {
            //默认登录密码为手机尾数后四位
            $pwd = md5(substr($phone, -4));
            $res = User::update(['phone' => $phone, 'pwd' => ($pwd ?? null)], ['uid' => $data['uid'], 'status' => [1, 2]]);
            Member::update(['user_phone' => $phone], ['uid' => $data['uid'], 'status' => [1, 2]]);
            AreaMember::update(['user_phone' => $phone], ['uid' => $data['uid'], 'status' => [1, 2]]);
            TeamMember::update(['user_phone' => $phone], ['uid' => $data['uid'], 'status' => [1, 2]]);
            return $res;
        });

        return judge($DBRes);
    }

    /**
     * @title 清退用户, 清除用户数据信息
     * @param array $data
     * @return mixed
     */
    public function clearUser(array $data)
    {
        $userNumber = User::where(['phone' => $data['phone'], 'status' => 1])->count();
        if (intval($userNumber) != 1) {
            throw new UserException(['msg' => '用户信息异常无法操作']);
        }
        $userInfo = User::where(['phone' => $data['phone']])->findOrEmpty()->toArray();
        if ($userInfo['status'] != 1) {
            throw new UserException(['msg' => '用户已被禁用']);
        }
        $res = Db::transaction(function () use ($userInfo) {
            $saveUser['openid'] = $userInfo['openid'] . '-clear';
            $saveUser['status'] = 2;
            $saveUser['can_buy'] = 2;
            $saveUser['ban_crowd_transfer'] = 1;
            $saveUser['crowd_balance'] = 0;
            $saveUser['crowd_fronzen_balance'] = 0;
            $saveUser['team_balance'] = 0;
            $saveUser['team_fronzen_balance'] = 0;
            $saveUser['divide_balance'] = 0;
            $saveUser['divide_fronzen_balance'] = 0;
            $saveUser['healthy_balance'] = 0;
            $saveUser['ppyl_balance'] = 0;
            $saveUser['area_balance'] = 0;
            $saveUser['area_fronzen_balance'] = 0;
            User::where(['uid' => $userInfo['uid'], 'status' => 1])->save($saveUser);

            //修改冻结中的分润
            Divide::where(['link_uid' => $userInfo['uid'], 'arrival_status' => 2, 'status' => 1])->save(['arrival_status' => 3, 'status' => -1]);

            //修改冻结中的提现
            Withdraw::where(['uid' => $userInfo['uid'], 'check_status' => 3, 'status' => 1])->save(['check_status' => 2, 'remark' => '用户要求驳回', 'check_time' => time()]);

            //清除用户所有登录方式
            UserAuthType::where(['uid' => $userInfo['uid'], 'status' => 1])->save(['status' => -1]);
        });
        return true;
    }

    /**
     * @title 用户余额明细
     * @param array $data
     * @return mixed
     */
    public function userBalanceSummaryDetail(array $data)
    {
        $type = $data['type'] ?? 1;
        switch ($type) {
            case 1:
                $field = 'crowd_balance as total_balance,crowd_withdraw_total as withdraw_balance,crowd_fronzen_balance as fronzen_balance';
                break;
            case 2:
                $field = 'integral as total_balance';
                break;
            case 3:
                $field = 'healthy_balance as total_balance';
                break;
            case 4:
                $field = 'ticket_balance as total_balance';
                break;
            case 5:
                $field = 'advance_buy_card as total_balance';
                break;
            case 6:
                //冻结美丽金余额汇总
                $field = 'sum(total_price) as total_balance,sum(last_total_price) as fronzen_balance';
                break;
            default:
                throw new ServiceException(['msg' => '不支持的类型']);
        }
        if ($type == 6) {
            $userInfo = CrowdfundingFuseRecord::where(['uid' => $data['uid']])->field($field)->findOrEmpty()->toArray();
        } else {
            $userInfo = self::where(['uid' => $data['uid']])->field($field)->findOrEmpty()->toArray();
        }


        $finally['total_balance'] = $userInfo['total_balance'] ?? 0;
        $finally['withdraw_balance'] = $userInfo['withdraw_balance'] ?? null;
        $finally['fronzen_balance'] = $userInfo['fronzen_balance'] ?? null;
        return $finally;
    }


    public function next()
    {
        return $this->hasMany(get_class($this), 'link_superior_user', 'uid');
    }

    public function top()
    {
        return $this->hasMany(get_class($this), 'uid', 'link_superior_user');
    }

    public function topLink()
    {
        return $this->hasOne(get_class($this), 'uid', 'link_superior_user');
    }


    public function link()
    {
        return $this->hasOne(get_class($this), 'uid', 'link_superior_user')->bind(['link_user_name' => 'name', 'link_user_phone' => 'phone', 'link_user_level' => 'vip_level']);
    }

    public function member()
    {
        return $this->hasOne('Member', 'uid', 'uid')->where(['status' => [1, 2]]);
    }

    public function teamMember()
    {
        return $this->hasOne('TeamMember', 'uid', 'uid')->where(['status' => [1, 2]]);
    }

    public function areaMember()
    {
        return $this->hasOne('AreaMember', 'uid', 'uid')->where(['status' => [1, 2]]);
    }

    public function memberDemotionGrowthValue()
    {
        return $this->hasOne('Member', 'uid', 'uid')->where(['status' => [1, 2]])->bind(['demotion_growth_value']);
    }

    public function ParentMember()
    {
        return $this->hasOne('Member', 'uid', 'link_superior_user')->where(['status' => [1, 2]]);
    }

    public function address()
    {
        return $this->hasOne('ShippingAddress', 'uid', 'uid')->where(['status' => 1, 'is_default' => 1])->field('uid,province,city,area,address');
    }

    public function orders()
    {
        return $this->hasMany('Order', 'link_superior_user', 'uid')->where(['order_status' => 8, 'pay_status' => 2]);
    }

    public function memberVdc()
    {
        return $this->hasOne('MemberVdc', 'level', 'vip_level')->bind(['vip_name' => 'name', 'handing_scale']);
    }

    public function teamMemberVdc()
    {
        return $this->hasOne('TeamMemberVdc', 'level', 'team_vip_level')->bind(['team_vip_name' => 'name']);
    }

    public function memberVdcAuth()
    {
        return $this->hasOne('MemberVdc', 'level', 'vip_level')->bind(['vip_name' => 'name', 'handing_scale']);
    }

    public function orderSummary()
    {
        return $this->hasMany('Order', 'uid', 'uid')->where(['order_status' => [2, 3, 4, 8], 'pay_status' => 2, 'after_status' => [1, 5, -1]]);
    }

    public function memberCard()
    {
        return $this->hasOne('Member', 'uid', 'uid')->where(['status' => [1, 2]])->bind(['member_card']);
    }

    public function coupon()
    {
        return $this->hasOne('Coupon', 'uid', 'uid')->where(['status' => [1]]);
    }

    public function userCoupon()
    {
        return $this->hasOne('UserCoupon', 'uid', 'uid')->where(['status' => [1]]);
    }

    public function unionId()
    {
        return $this->hasOne('WxUser', 'openid', 'openid')->bind(['unionId']);
    }

    public function notReceive()
    {
        return $this->hasMany('PpylReward', 'link_uid', 'uid')->where(['status'=>1,'arrival_status'=>3]);
    }

    public function ppylFrozen()
    {
        return $this->hasMany('PpylReward', 'link_uid', 'uid')->where(['status'=>1,'arrival_status'=>2]);
    }

    public function arrive()
    {
        return $this->hasMany('PpylReward', 'link_uid', 'uid')->where(['status'=>1,'arrival_status'=>1]);
    }

    public function userAuthType()
    {
        return $this->hasMany('UserAuthType','uid','uid')->where(['status'=>1]);
    }

    public function wxUser()
    {
        return $this->hasOneThrough('WxUser', 'UserAuthType', 'uid', 'tid', 'uid', 'tid');
    }

    public function primary()
    {
        return $this->hasOne('User', 'uid', 'primary_uid')->bind(['primary_name' => 'name', 'primary_phone' => 'phone']);
    }

    public function primaryUserInfo()
    {
        return $this->hasOne('User', 'uid', 'primary_uid');
    }

    public function wx()
    {
        return $this->hasOne('WxUser', 'openid', 'openid')->bind(['auth_channel' => 'channel', 'wx_app_id' => 'app_id']);
    }

    public function crowdBalance()
    {
        return $this->hasMany('CrowdfundingBalanceDetail', 'uid', 'uid')->where(['status' => 1]);
    }

}