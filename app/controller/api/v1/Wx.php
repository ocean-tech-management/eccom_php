<?php
// +----------------------------------------------------------------------
// |[ 文档说明: ]
// +----------------------------------------------------------------------
// | Copyright (c) 2018~2024 http://www.mlhcmk.com All rights reserved.
// +----------------------------------------------------------------------

namespace app\controller\api\v1;


use app\BaseController;
use app\lib\exceptions\WxException;
use think\facade\Request;
use app\lib\models\UserAuthType;
use app\lib\models\WxUser;
use app\lib\models\User;
use app\lib\models\UserLoginLog;
use app\lib\services\AuthType;
use app\lib\services\Token;
use app\lib\services\Wx as WxService;
use app\lib\services\SuperApp;
use app\lib\services\Code;
use think\Exception;

class Wx extends BaseController
{
    /**
     * @title  获取JSSDK配置
     * @param WxService $service
     * @return string
     */
    public function config(WxService $service)
    {
        $info = $service->buildSignature($this->request->param('url'));
        return returnData($info);
    }


    /**
     * @title  微信授权
     * @param WxService $service
     * @return string
     * @remark 请一定不要删此方法,此方法为老版本兼容!小程序基础组件2.10.4以下使用
     * @throws \OSS\Core\OssException
     *
     */
    public function auth(WxService $service)
    {
        $data = $this->requestData;
        $data['type'] = 1;
        if (!empty($data['crypt'])) {
            $data['userInfoType'] = 2;
        }
        $user = $service->WxUserInfo($data);
        $needAuth = false;
        if (empty($data['crypt'])) {
            $userInfo = User::with(['memberVdcAuth', 'unionId'])->where(['openid' => $user['openid'], 'status' => [1, 2]])->field('openid,uid,name,avatarUrl,is_new_user,vip_level,phone,old_sync')->findOrEmpty()->toArray();
//            unset($userInfo['openid']);
            if (empty($userInfo)) {
                $userNull = $user;
                unset($user);
                $needAuth = true;
                $userNull['need_auth'] = true;
                $userNull['unionId'] = $userNull['unionid'] ?? null;
                $user = $userNull;
            } else {
                $userInfo['need_auth'] = false;
                if (!empty($user['unionid']) && !empty($user['openid'])) {
                    WxUser::update(['unionId' => $user['unionid']], ['openid' => $user['openid']]);
                    $userInfo['unionId'] = $user['unionid'];
                }
                $user = $userInfo;
            }
        } else {
            $user['need_auth'] = false;
        }

        if (empty($needAuth)) {
            $token = (new Token())->buildToken($user);
            $user['token'] = $token;
        }
        return returnData($user);
    }

    /**
     * @title  小程序新授权方法
     * @param WxService $service
     * @return mixed
     * @remake 小程序基础组件2.10.4以上采用此方法(2021-4-13启用)
     * @throws \OSS\Core\OssException
     */
    public function authNew(WxService $service)
    {
        $data = $this->requestData;
        $data['type'] = 1;
        $user = $service->WxUserInfo($data);
        $needAuth = false;
        if (empty($data['userProfile'])) {
//            $userId = UserAuthType::where(['openid'=>$user['openid'],'status'=>1])->field(['uid'])->findOrEmpty()->toArray();
            //判断是否有unionid
            if (!empty($user['unionid'])) {
                $userAuthUserId = UserAuthType::where(['openid' => $user['openid'], 'status' => 1])->value('uid');
                if (!$userAuthUserId) {
                    $userAuthUserId = UserAuthType::whereRaw('status  = 1 and (openid = :openid or (wxunionid = :wxunionid and wxunionid is not null))', ['openid' => $user['openid'], 'wxunionid' => $user['unionid']])->value('uid');
                }
            } else {
                $userAuthUserId = UserAuthType::where(['openid' => $user['openid'], 'status' => 1])->value('uid');
            }
            $userId = $userAuthUserId;
            $userInfo = User::with(['memberVdcAuth'])->where(['uid' => $userId, 'status' => [1, 2]])->field('openid,uid,name,avatarUrl,is_new_user,vip_level,phone,old_sync,c_vip_level,c_vip_time_out_time')->findOrEmpty()->toArray();
            if (empty($userInfo)) {
                $userNull = $user;
                unset($user);
                $needAuth = true;
                $userNull['need_auth'] = true;
                $userNull['unionId'] = $userNull['unionid'] ?? null;
                $user = $userNull;
            } else {
                $userInfo['openid'] = $user['openid'];
                $userInfo['need_auth'] = false;
                //使用缓存锁，防止数据库抖动
                if (!empty(cache('wx-' . $user['openid'] . '-new'))) {
                    throw new WxException(['msg' => '提交失败, 已存在处理中的数据']);
                }
                cache('wx-' . $user['openid'] . '-new', $user, 60);
                if (!empty($user['unionid']) && !empty($user['openid'])) {
                    if (!$userAuthUserId) {
                        (new WxUser())->new($user, $userInfo['uid']);
                    } else {
                        $userAuthid = UserAuthType::field('id')->where(['uid' => $userInfo['uid'], 'status' => 1, 'openid' => $user['openid']])->findOrEmpty()->toArray();
                        if (!$userAuthid) {
                            (new WxUser())->new($user, $userInfo['uid']);
                        }
                        $updWxUser = [
                            'unionId' => $user['unionid'],
                        ];
                        //更新unionid
                        if (config('system.updateUser') && isset($user['avatarUrl'])) {
                            $updWxUser['nickname'] = $user['nickName'];
                            $updWxUser['headimgurl'] = $user['avatarUrl'];
                        }
                        WxUser::update($updWxUser, ['openid' => $user['openid']]);
                        UserAuthType::update(['wxunionid' => $user['unionid']], ['openid' => $user['openid']]);
                    }
//                    WxUser::update(['unionId' => $user['unionid']], ['openid' => $user['openid']]);
                    $userInfo['unionId'] = $user['unionid'];
                }
                $user = $userInfo;
            }
        } else {
            //新增用户信息
            $userProfile = $data['userProfile'];
            $newUser = $userProfile;
            $newUser['openid'] = $user['openid'];
            $newUser['unionId'] = $user['unionid'] ?? null;
            $newUser['link_user'] = $data['link_user'] ?? null;
            $newUser['share_id'] = $data['share_id'] ?? null;
            $access_key = getAccessKey();
            $newUser['app_id'] = config("system.clientConfig.$access_key")['app_id'];
//            $user = $service->addNewUser($newUser);
            //使用缓存锁，防止数据库抖动
            if (!empty(cache('wx-' . $newUser['openid'] . '-new'))) {
                throw new Exception('提交失败, 已存在处理中的数据');
            }
            cache('wx-' . $user['openid'] . '-new', $user, 60);
            $user = AuthType::AuthPlatForm($newUser, $data['type']);
            unset($user['id']);

            $user['openid'] = $newUser['openid'];
            $user['unionId'] = $newUser['unionId'] ?? null;
            $user['need_auth'] = false;
        }
        if (empty($needAuth)) {
            $token = (new Token())->buildToken($user);
            $user['token'] = $token;
        }
        if (!empty($user['unionId'] ?? null)) {
            if (is_array($user['unionId'])) {
                $user['unionId'] = $user['unionId']['unionId'] ?? null;
            }
            $user['is_has'] = 0;
//            if($uid = (new User())->getUidByUnionid($user['unionId'])){
//                $user['is_has'] = AuthType::getUserBlanceAndOrder($uid);
//            }
        }
//        if(!isset($user['unionId']) && is_array($user['unionId'])){
//            $user['unionId'] = $user['unionId']['unionId'] ?? null;
//        }
        cache('wx-' . $user['openid'] . '-new', null);
        return returnData($user);
    }

    /**
     * @title  解密小程序加密的数据
     * @param WxService $service
     * @return mixed
     */
    public function crypt(WxService $service)
    {
        $data = $this->requestData;
        $data['type'] = 3;
        $data['userInfoType'] = 2;
        if (empty($data['crypt'])) {
            return returnData([]);
        }
//        $data['code'] = '053mF6ll2UCvN54oXMml21Q5g03mF6lt';
//        $data['crypt']['iv'] = 'RjcaSWUurCnTWcQ9jFP+ZQ==';
//        $data['crypt']['encryptedData'] = 'rGXPhI46h6vAFCgHrP5r5Ym1kPKz2DdKMPMeKsUovZYfWnkUVhoQzd3KWkGzqpJIVkZABRwQlpmWyj+UDmAoRg6shGiDTftxZQ6TtIdLnggS/pGi1kJei3ArrbDXyPDcVWvGovgw98Hltjg3AUdisMil8HZvHMjfXT6gQeRDk+SxpEimLkHtkJyWKlACI7ixQ5Nr3Pj1tazBr+U6V+2bFg==';
        $info = $service->WxUserInfo($data);
        return returnData($info ?? []);
    }

    /**
     * @title  微信授权(H5)
     * @param  WxService $service
     * @throws \Exception
     * @return string
     */
    public function authForWxPublic(WxService $service)
    {
        $data = $this->requestData;
        $data['type'] = 2;
        $info = $service->WxUserInfo($data);
        if (!empty($info['primary_uid'] ?? null)) {
            $info['uid'] = $info['primary_uid'];
        }
        if (!empty($info)) {
            $token = (new Token())->buildToken($info);
            $info['token'] = $token;
        }
        return returnData($info);
    }

    /**
     * @title  通过openid换取用户uid
     * @param User $model
     * @return string
     */
    public function getUserByOpenid(User $model)
    {
        $info = $model->getUidByOpenid($this->request->param('openid'));
        return returnData($info);
    }

    public function authForWxPublicV2(WxService $wx)
    {
        $data = $this->requestData;
        $data['type'] = 2;
        $r = $wx->authH5public($data);
        $token = (new Token())->buildToken($r);
        $r['token'] = $token;
        return returnData($r);
    }

    /**
     * @title  超级App授权
     * @param User $model
     * @return string
     */
    public function superAppAuth(User $model)
    {
        $data = $this->requestData;
        $data['type'] = 3;
        $info = (new SuperApp())->getToken($data);
        if ($info['is_login']) {
            $token = (new Token())->buildToken($info);
            $info['token'] = $token;
            $info['need_auth'] = false;
        } else {
            $info['isChose'] = true;
        }

        return returnData($info);
    }

    /**
     * @description: web-h5页面登录
     * @return {*}
     */
    public function webLogin()
    {
        $data = $this->requestData;
        $info = (new WxService())->webLogin($data);
        if (!empty($info)) {
            $token = (new Token())->buildToken($info);
            $info['token'] = $token;
        }
        //添加登录记录
        (new UserLoginLog)->new($info);
        return returnData($info);
    }

    /**
     * @description: web-h5页面注册
     * @return {*}
     */
    public function webRegister()
    {
        $data = $this->requestData;
        $info = (new WxService())->webRegister($data);
        if (!empty($info)) {
            $token = (new Token())->buildToken($info);
            $info['token'] = $token;
        }
        //添加登录记录
        (new UserLoginLog)->new($info);
        return returnData($info);
    }

    /**
     * @description: 解除多次登录被限制的账号
     * @param {*} $phone
     * @return {*}
     */
    public function webClearLoginBan()
    {
        $data = $this->requestData;
        $res = (new WxService())->webClearLoginBan($data);
        return returnMsg($res);
    }
}
