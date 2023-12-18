<?php

namespace app\lib\services;

use app\lib\exceptions\OrderException;
use app\lib\exceptions\UserException;
use app\lib\models\User;
use app\lib\models\UserAuthType;
use app\lib\models\Order;
use app\lib\models\WxUser;
use app\lib\models\AppUser;
use think\Collection;
use think\facade\Log;
use think\Model;
use think\facade\Request;

class AuthType
{

    //中间表status
    protected static $status = [
        'enable' => 1,
        'disable' => 0
    ];

    protected static $type = [
        1 => \app\lib\models\WxUser::class,
        2 => \app\lib\models\WxUser::class,
        3 => \app\lib\models\AppUser::class,
    ];

    protected function error(array $data = [])
    {
        $msg = $data['errmsg'] ?? '您的输入信息存在异常,请再次确认';
        $code = $data['error_code'] ?? 8001;
        throw new UserException([
            'errorCode' => $code,
            'msg' => $msg
        ]);
    }

    /**
     * 授权中间表
     * @param string $wxuserId
     * @param string $uId
     * @param string $openId
     * @return UserAuthType|Model
     */
    public static function addUserAuthType(string $wxuserId, string $uId, string $openId, string $unionid = null, string $appId = null)
    {
        if (empty($unionid)) {
            $unionid = null;
        }
        $userAuthTypeData = [
            'tid' => $wxuserId,
            'uid' => $uId,
            'openid' => $openId,
            'status' => 1,
            'wxunionid' => $unionid,
            'app_id' => $appId,
            'access_key' => getAccessKey(),
        ];
        return UserAuthType::create($userAuthTypeData);
    }

    /**
     * 授权平台
     * @param array $inputInfo
     * @param int $platForm
     * @return array|mixed|string[]
     * @throws \OSS\Core\OssException
     * @throws \think\db\exception\DbException
     */
    public static function AuthPlatForm(array $inputInfo, int $platForm, int $userChose = 0)
    {
        //$platForm 1为小程序 2为公众号(或App或移动网站) 3为超A
        //$userChose
        $modelInstance = new static::$type[$platForm];
        switch ($platForm) {
            case 1:
            case 2:
            case 3:
                // $inputInfo['app_id'] = $platForm == 1 ? config('system.weChat')['app_id'] : config('system.weChat')['h5_app_id'];
                $access_key = getAccessKey();
                $inputInfo['app_id'] = config("system.clientConfig.$access_key")['app_id'];
                $userData['sex'] = $inputInfo['gender'] ?? 0;
                $userData['nickname'] = !empty($inputInfo['nickName']) ? $inputInfo['nickName'] : '用户' . getRandomString(7);
                $userData['headimgurl'] = $inputInfo['avatarUrl'] ?? null;
                if ($platForm == 1 || $platForm == 2) {
                    $userData['unionId'] = $inputInfo['unionId'] ?? null;
                }
                $userData['openid'] = $inputInfo['openid'] ?? null;
                $userData['channel'] = $platForm;
                //如果是微信公众号授权且当前客户端为移动APP或网页的, 则授权平台为APP平台或网页
                if ($platForm == 2) {
                    switch (substr($access_key, 0, 1)) {
                        case 'd':
                            $userData['channel'] = 3;
                            break;
                        case 'w':
                            $userData['channel'] = 4;
                            break;
                    }
                }
                $userData['tid'] = getUid(10);
                $userData['app_id'] = $inputInfo['app_id'];
                //                $userData['wxunionid'] = $inputInfo['unionId'];
                $wxUserInfo = $modelInstance->alias('wx')
                    ->leftJoin([UserAuthType::getTable() => 'auth'], 'auth.tid = wx.tid COLLATE utf8mb4_unicode_ci and auth.status = 1')
                    ->where('auth.status', 1)
                    ->where('auth.openid', $inputInfo['openid'])
                    ->when(isset($inputInfo['unionId']), function ($query) use ($inputInfo) {
                        $query->whereOr(function ($query) use ($inputInfo) {
                            $query->where(['auth.wxunionId' => $inputInfo['unionId']])->where('auth.wxunionId', 'exp', 'is not null');
                        });
                    })
                    ->select();
                $returnInfo = [];
                $returnInfo['isChose'] = false;
                if ($platForm == 2 && $wxUserInfo->isEmpty() && !$userChose) {
                    $returnInfo['wxData'] = $inputInfo;
                    $returnInfo['isChose'] = true;
                    return $returnInfo;
                }
                // Log::write('auth_info_headimgurl', var_export($userData, true));
                //头像转存
                // if (!empty($userData['headimgurl'] ?? null)) {
                // }
                $headImg = (new FileUpload())->saveWxAvatar(['headimgurl' => $userData['headimgurl'], 'openid' => $userData['openid']]);
                if (!empty($headImg)) {
                    $userData['headimgurl'] = $headImg;
                }
                if (!empty($inputInfo['link_user'] ?? null)) {
                    $inputInfo['link_user'] = str_replace(' ', '', $inputInfo['link_user']);
                }
                $userData['link_user'] = $inputInfo['link_user'] ?? null;
                //绑定到指定账号：小程序端
                if (isset($inputInfo['share_id']) && !empty($inputInfo['share_id']) && $platForm == 1) {
                    return self::bindToAccount($wxUserInfo, $inputInfo, $modelInstance, $userData);
                } else {
                    if ($wxUserInfo->isEmpty()) {
                        //新号
                        if ($platForm != 3) {
                            //查找是否存在之前被切断封号的unionid
                            $wxUser = $modelInstance->when(!empty($userData['unionId']), function ($query) use ($userData) {
                                $query->where(['unionId' => $userData['unionId']]);
                            })
                                ->where(['openid' => $userData['openid']])
                                ->findOrEmpty();
                            if ($wxUser->isEmpty()) {
                                $wxUser = $modelInstance->create($userData);
                            }
                            $sysUser = (new User())->new($userData);
                            $userAutyTypeRes = self::addUserAuthType($wxUser->tid, $sysUser['uid'], $inputInfo['openid'], $inputInfo['unionId'], $inputInfo['app_id']);
                        } else {
                            $wxUser = $modelInstance->create($userData);
                            $sysUser = (new User())->new($userData);
                            $userAutyTypeRes = self::addUserAuthType($wxUser->tid, $sysUser['uid'], $inputInfo['openid'], '', $inputInfo['app_id']);
                        }
                        $sysUser['openid'] = $userAutyTypeRes->openid; //以防万一
                        $returnInfo = $sysUser;
                    } else {
                        //已有帐号
                        //			              $uidArr = array_unique($wxUserInfo->column('user_id','unionId'));
                        //                        $uid = $uidArr[$inputInfo['unionId']];
                        $uidArr = $wxUserInfo->column('uid', 'openid');
                        if ($platForm != 3) {
                            $uid = $uidArr[$inputInfo['openid']] ?? array_unique($wxUserInfo->column('uid', 'unionId'))[$inputInfo['unionId']];
                        } else {
                            $uid = $uidArr[$inputInfo['openid']];
                        }
                        $alreadyExistOpenIds = $wxUserInfo->column('openid');
                        if (!in_array($inputInfo['openid'], $alreadyExistOpenIds)) {
                            //绑定到已有账户
                            $wxUser = $modelInstance->create($userData);
                            if ($platForm != 3) {
                                $userAutyTypeRes = self::addUserAuthType($wxUser->tid, $uid, $inputInfo['openid'], $inputInfo['unionId'], $inputInfo['app_id']);
                            } else {
                                $userAutyTypeRes = self::addUserAuthType($wxUser->tid, $uid, $inputInfo['openid'], '', $inputInfo['app_id']);
                            }
                            $field = ['id', 'uid', 'share_id', 'name', 'phone', 'openid', 'avatarUrl', 'link_superior_user', 'is_new_user', 'create_time', 'update_time'];
                            $userinfo = User::where(['uid' => $uid, 'status' => 1])->field($field)->findOrEmpty()->toArray();
                            $userinfo['openid'] = $userAutyTypeRes->openid; //以防万一
                            $returnInfo = $userinfo;
                        } else {
                            //用户授权登录，更新openid与时间等用户信息
                            $tidArr = $wxUserInfo->column('tid', 'openid');
                            $tid = $userData['tid'];
                            unset($userData['tid']);
                            WxUser::update($userData, ['tid' => $tidArr[$inputInfo['openid']]]);
                            if ($platForm != 3) {
                                UserAuthType::update(['openid' => $inputInfo['openid'], 'wxunionid' => $inputInfo['unionId']], ['uid' => $uid, 'tid' => $tidArr[$inputInfo['openid']]]);
                            } else {
                                UserAuthType::update(['openid' => $inputInfo['openid']], ['uid' => $uid, 'tid' => $tidArr[$inputInfo['openid']]]);
                            }
                            $userData['uid'] = $uid;
                            $userData['tid'] = $tid;
                            $returnInfo = (new WxUser())->wxUserEdit($userData); //以防万一：这里的openid已连表获取
                        }
                    }
                }
                return $returnInfo;
            case 4:
                return ['msg' => '敬请期待~~~'];
            default:
                return ['msg' => '敬请期待~~~'];
        }
    }

    /**
     * 绑定账户
     * @param Collection $wxUserInfo
     * @param array $inputInfo
     * @param Model $modelInstance
     * @param array $userData
     * @return array
     * @throws \think\db\exception\DbException
     */
    protected static function bindToAccount(Collection $wxUserInfo, array $inputInfo, Model $modelInstance, array $userData)
    {
        $field = ['id', 'uid', 'share_id', 'name', 'phone', 'openid', 'avatarUrl', 'link_superior_user', 'is_new_user', 'create_time', 'update_time'];
        $mainUserData = User::where(['share_id' => $inputInfo['share_id'], 'status' => 1])->field($field)->findOrEmpty();
        if ($mainUserData->isEmpty()) throw new UserException();
        $existWxUser = $wxUserInfo->where('openid', $inputInfo['openid']);
        if ($existWxUser->isEmpty()) {
            $userData['nickname'] = $mainUserData['name'];
            $userData['headimgurl'] = $mainUserData['avatarUrl'];
            //该账户是纯新号，绑定到通过三种方式认证的账户
            $wxUser = $modelInstance->create($userData);
            self::addUserAuthType($wxUser->tid, $mainUserData->uid, $inputInfo['openid'], $inputInfo['unionId'], $inputInfo['app_id']);
        } else {
            //该账号下是否有美丽金或者订单记录
            //            $uidArr = array_unique($wxUserInfo->column('uid','unionId'));
            //            $uid = $uidArr[$inputInfo['unionId']];
            $uidArr = array_unique($wxUserInfo->column('uid', 'openid'));
            $uid = $uidArr[$inputInfo['openid']];

            if (self::getUserBlanceAndOrder($uid)) throw new UserException(['msg' => (new User())->getUserProtectionInfo($uid)['crowd_balance']]);
            //该账户是之前已入驻(小白号)，则禁用中间表旧数据,绑定到指定账号
            $existUid = $existWxUser->column('uid', 'openid');
            $existTid = $existWxUser->column('tid', 'openid');
            //禁用user_auth_type表
            UserAuthType::update(['status' => 2], ['uid' => $existUid[$inputInfo['openid']], 'tid' => $existTid[$inputInfo['openid']]]);
            //增加user_auth_type表关联
            self::addUserAuthType($existTid[$inputInfo['openid']], $mainUserData->uid, $inputInfo['openid'], $inputInfo['unionId'], $inputInfo['app_id']);
            //解绑后，判断用户名下还有没有其它账号，没有则禁用user表的status字段
            if (UserAuthType::where(['uid' => $existUid[$inputInfo['openid']], 'status' => 1])->count() == 0) {
                User::update(['status' => 2], ['uid' => $existUid[$inputInfo['openid']]]);
            }
        }
        return $mainUserData->toArray();
    }

    /**
     * @title h5端后置绑定账户
     * @param string $h5ShareId
     * @param string $h5Openid
     * @param string $uid
     * @return UserAuthType|Model
     * @throws \think\db\exception\DbException
     */
    protected static function h5BehindBindToAccount(string $h5ShareId, string $h5Openid, string $uid)
    {
        $h5UserInfo = User::alias('u')
            ->leftJoin([UserAuthType::getTable() => 'auth'], "auth.uid = u.uid COLLATE utf8mb4_unicode_ci and auth.status = 1")
            ->where('u.share_id', $h5ShareId)
            ->where('auth.openid', $h5Openid)
            //            ->where('auth.status',1)
            ->where('u.status', 1)
            ->field('auth.uid,tid,auth.openid,crowd_balance,wxunionid,app_id')
            ->findOrEmpty()->toArray();
        if (empty($h5UserInfo)) throw new UserException();
        //H5绑定操作时，判断该账号是否有美丽金或者订单记录
        //        $userOrderCount = Order::where('uid',$h5UserInfo['uid'])->count();
        $userOrderCount = (new Order())->checkUserOrder($h5UserInfo['uid']);
        if ($userOrderCount > 0 || $h5UserInfo['crowd_balance'] > 0) {
            throw new OrderException(['errorCode' => 1500132]);
        }
        UserAuthType::update(['status' => 2], ['uid' => $h5UserInfo['uid'], 'tid' => $h5UserInfo['tid']]);
        $newH5UserAuthType = self::addUserAuthType($h5UserInfo['tid'], $uid, $h5UserInfo['openid'], $h5UserInfo['wxunionid'], $h5UserInfo['app_id']);
        if (UserAuthType::where(['uid' => $h5UserInfo['uid'], 'status' => 1])->count() == 0) {
            User::update(['status' => 2], ['uid' => $h5UserInfo['uid']]);
        }
        return $newH5UserAuthType ?? null;
    }


    /**
     * 验证登录授权方式
     * @param array $param
     * @return array
     * @throws \think\db\exception\DbException
     */
    public function checkUserAuth(array $param = [])
    {
        $userInfo = [];
        $type = $param['type'];
        $field = ['id', 'uid', 'share_id', 'name', 'phone', 'openid', 'avatarUrl', 'link_superior_user', 'is_new_user', 'create_time', 'update_time', 'pwd', 'pay_pwd', 'crowd_balance'];
        switch ($type) {
            case 1:
                $shareId = $param['share_id'];
                $password = $param['pwd'];
                $authUserInfo = User::where(['share_id' => $shareId, 'status' => 1])->field($field)->findOrEmpty()->toArray();
                if (empty($authUserInfo) || is_null($authUserInfo['pwd']) || md5($password) !== $authUserInfo['pwd']) return $this->error();
                break;
            case 2:
                $phone = $param['phone'];
                $password = $param['pwd'];
                $phoneCode = $param['phone_code'];
                $authUserInfo = User::where(['phone' => $phone, 'pwd' => md5($password), 'status' => 1])->field($field)->findOrEmpty()->toArray();
                if (empty($authUserInfo)) return $this->error();
                Code::getInstance()->goCheck($phone, $phoneCode);
                break;
            case 3:
                $shareId = $param['share_id'];
                $payPassword = $param['pay_pwd'];
                $authUserInfo = User::where(['share_id' => $shareId, 'pay_pwd' => md5($payPassword), 'status' => 1])->field($field)->findOrEmpty()->toArray();
                if (empty($authUserInfo)) return $this->error();
                break;
        }
        //判断是否已存在当前应用账号
        // $param['openid'] = 'oTxQK5Gg-dGgeBI86lkpd7n912sM';
        if (isset($param['openid']) && !empty($param['openid'])) {
            $access_key = getAccessKey();
            $user_openid = UserAuthType::where(['app_id' => config("system.clientConfig.$access_key")['app_id'], 'uid' => $authUserInfo['uid'], 'status' => 1])->value('openid');
            if (!empty($user_openid) && $user_openid != $param['openid']) {
                throw new UserException(['errorCode' => 800103]);
            }
        }
        if (isset($param['bind_type']) && !empty($param['bind_type']) && $param['bind_type'] == 1) {
            if (empty($param['wx_data'])) {
                throw new UserException(['msg' => '微信用户信息异常！']);
            }
            if (isset($param['openid']) && !empty($param['openid'])) {
                $param['wx_data']['openid'] = $param['openid'];
            }
            $bindRes = self::h5BeForeBindToAccount($authUserInfo, $param['wx_data'], $authUserInfo['uid']);
            if ($bindRes) {
                unset($authUserInfo['pwd']);
                unset($authUserInfo['pay_pwd']);
                $authUserInfo['openid'] = $bindRes->openid;
                $userInfo = $authUserInfo;
            }
        }

        if (isset($param['platform']) && !empty($param['platform']) && $param['platform'] == 'h5' && !empty($authUserInfo)) {
            $bindRes = self::h5BehindBindToAccount($param['h5_share_id'], $param['h5_openid'], $authUserInfo['uid']);
            if (!($bindRes->isEmpty())) {
                unset($authUserInfo['pwd']);
                unset($authUserInfo['pay_pwd']);
                $authUserInfo['openid'] = $bindRes->openid;
                $userInfo = $authUserInfo;
            }
        }
        if (empty($userInfo)) {
            //判断补充share_id
            $share_id = User::where(['uid' => $authUserInfo['uid']])->value('share_id');
            if (!$share_id) {
                $new_share_id = getUid(11);
                User::where(['uid' => $authUserInfo['uid']])->update(['share_id' => $new_share_id]);
                $authUserInfo['share_id'] = $new_share_id;
            }
            $userInfo = ['share_id' => $authUserInfo['share_id'], 'phone' => $authUserInfo['phone']];
        }
        return $userInfo;
        // $userInfo = $userInfo ?? ['share_id' => $authUserInfo['share_id'], 'phone' => $authUserInfo['phone']];
        // return  ['share_id'=>$authUserInfo['share_id'],'phone'=>$authUserInfo['phone'],'user_info'=>$userInfo];
    }

    //h5前置弹窗已有用户
    public static function h5BeForeBindToAccount(array $authUserInfo = [], array $wxUser = [], string $uid = null)
    {
        $returnInfo = [];
        //当前账户没有旧关联，不用解绑
        $wxUserData = [];
        $wxUserData['sex'] = $wxUser['gender'] ?? 0;
        $wxUserData['nickname'] = $wxUser['nickName'];
        $wxUserData['headimgurl'] = $wxUser['avatarUrl'] ?? null;
        $wxUserData['unionId'] = $wxUser['unionId'] ?? null;
        $wxUserData['openid'] = $wxUser['openid'] ?? null;
        //判断access_key类型
        $key = getAccessKey();
        $type = substr($key, 0, 1);
        switch ($type) {
            case 'a':
            case 'd':
                $channel = 3;
                break;
            case 'p':
                $channel = 2;
                break;
            case 'm':
                $channel = 1;
                break;
            //网页环境下微信授权渠道为4
            case 'w':
                $channel = 4;
            default:
                $channel = 2;
                break;
        }
        $wxUserData['channel'] = $channel;
        $wxUserData['tid'] = getUid(10);
        // $wxUserData['app_id'] = config('system.weChat')['h5_app_id'];
        $access_key = getAccessKey();
        $app_id = config("system.clientConfig.$access_key")['app_id'];
        $wxUserData['app_id'] = $app_id;
        //有就不再插入
        $wxUser = getAccessModel(getAccessKey())->create($wxUserData);
        // Log::write('app_add_res', var_export($wxUser, true));
        if ($wxUser) {
            $userAuthTypeData = [];
            $userAuthTypeData['openid'] = $wxUser['openid'] ?? null;
            $userAuthTypeData['tid'] = $wxUser->tid;
            $userAuthTypeData['uid'] = $uid;
            $userAuthTypeData['status'] = 1;
            $userAuthTypeData['wxunionid'] = $wxUser['unionId'] ?? null;
            // $userAuthTypeData['app_id'] = config('system.weChat')['h5_app_id'];
            $userAuthTypeData['access_key'] = $access_key;
            $userAuthTypeData['app_id'] = $app_id;
            $userAuthTypeRes = UserAuthType::create($userAuthTypeData);
            if ($userAuthTypeRes) {
                $returnInfo = $userAuthTypeRes;
                //判断补充share_id
                $share_id = User::where(['uid' => $uid])->value('share_id');
                if (!$share_id) {
                    $new_share_id = getUid(11);
                    User::where(['uid' => $uid])->update(['share_id' => $new_share_id]);
                }
            }
        }
        return $returnInfo ?? null;
    }

    /**
     * @title 用户美丽金或订单状态
     * @param string $uid
     * @return int
     */
    public static function getUserBlanceAndOrder(string $uid)
    {
        $crowdBalanceAndOrderStatus = 0;
        $userCrowdBalance = (new User())->getUserProtectionInfo($uid);
        $userOrderCount = (new Order())->checkUserOrder($uid);
        if ($userOrderCount > 0) {
            $crowdBalanceAndOrderStatus = 1;
        }
        return $crowdBalanceAndOrderStatus;
    }
}
