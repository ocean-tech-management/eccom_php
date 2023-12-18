<?php
// +----------------------------------------------------------------------
// |[ 文档说明: ]
// +----------------------------------------------------------------------
// | Copyright (c) 2018~2024 http://www.mlhcmk.com All rights reserved.
// +----------------------------------------------------------------------


namespace app\lib\services;


use app\lib\exceptions\ServiceException;
use app\lib\models\AppUser;
use app\lib\models\User;
use app\lib\models\WxUser;
use think\facade\Request;
use app\lib\models\UserAuthType;
use think\facade\Cache;
use think\Exception;

class SuperApp
{
    private $requestDomain = '';
    private $platformNum = '';
    private $platformSecret = '';
    //关键配置初始化
    public function __construct()
    {
        $access_key = getAccessKey();
        $super_app = config("system.clientConfig.$access_key");
        $this->requestDomain = $super_app['requestDomain'];
        $this->platformNum = $super_app['platformNum'];
        $this->platformSecret = $super_app['platformSecret'];
    }


    /**
     * @title  授权获取token
     * @param array $data
     * @return mixed
     * @throws \OSS\Core\OssException
     */
    public function getToken(array $data)
    {
        $access_key = getAccessKey();
        $requestUrl = $this->requestDomain . 'api/auth/get_platform_token';
        $requestParam['code'] = $data['code'];
        $requestParam['platform_num'] = $this->platformNum;
        $requestParam['platform_secret'] = $this->platformSecret;
        $requestParam['user_id'] = $data['user_id'];
        $requestParam['app_label'] = $data['app_label'];
        $openid = 'superApp' . $data['user_id'];
        $res = curl_post($requestUrl, $requestParam);
        $requestData = json_decode($res, true);
        if (empty($requestData) || (!empty($requestData) && empty($requestData['data'] ?? []))) {
            throw new ServiceException(['msg' => $requestData['message'] ?? '授权服务有误']);
        }
        $requestData = $requestData['data'];
        $requestData['openid'] = $openid;
        $is_login = false;
        //判断是否已授权到信息
        if (empty($data['userProfile'])) {
            $userAuthUserId = UserAuthType::where(['openid' => $openid, 'access_key' => $access_key, 'status' => 1])->value('uid');
            $userId = $userAuthUserId;
            $userInfo = User::with(['memberVdcAuth'])
                ->where([
                    'uid' => $userId,
                    'status' => 1
                ])
                ->field('openid,uid,name,avatarUrl,is_new_user,vip_level,phone,old_sync,c_vip_level,c_vip_time_out_time')
                ->findOrEmpty()
                ->toArray();
            if (empty($userInfo)) {
                //引用缓存, 避免重复调用此接口造成的数据库查询压力
                if (!empty(cache('superAppUserInfo-' . $requestData['phone']))) {
                    return cache('superAppUserInfo-' . $requestData['phone']);
                }
                $user = (new User())->syncGetUserInfoByPhone($requestData['phone']);
                if (empty($user)) {
                    //获取用户信息
                    $user = $this->getUserInfo(['user_id' => $data['user_id'], 'token' => $requestData['token']]);
                }
                $user['openid'] = $requestData['openid'];
                $user['nickName'] = $user['name'];
                $user['isChose'] = true;
                $user['need_auth'] = true;
            } else {
                $is_login = true;
                $userInfo['isChose'] = false;
                $userInfo['need_auth'] = true;
                if (!empty($user['openid'])) {
                    //使用缓存锁，防止数据库抖动
                    if (!empty(cache('super-' . $openid . '-new'))) {
                        throw new Exception('提交失败, 已存在处理中的数据');
                    }
                    cache('super-' . $openid . '-new', $userInfo, 60);
                    if (!$userAuthUserId) {
                        (new WxUser())->new($user, $userInfo['uid']);
                    } else {
                        //更新信息
                        if (config('system.updateUser') && isset($user['avatarUrl'])) {
                            $updWxUser['nickname'] = $user['nickName'];
                            $updWxUser['headimgurl'] = $user['avatarUrl'];
                        }
                        AppUser::update($updWxUser, ['openid' => $user['openid']]);
                        // WxUser::update($updWxUser, ['openid' => $user['openid']]);
                    }
                }
                $user = $userInfo;
            }
        } else {
            //获取详细信息
            $newUser = $data['userProfile'];
            //新增用户信息
            $newUser['openid'] = 'superApp' . $data['user_id'];
            $newUser['link_user'] = $data['link_user'] ?? null;
            $newUser['share_id'] = $data['share_id'] ?? null;
            $access_key = getAccessKey();
            $newUser['app_id'] = config("system.clientConfig.$access_key")['app_id'];
            //使用缓存锁，防止数据库抖动
            if (empty(cache('super-' . $openid . '-new'))) {
                throw new Exception('提交失败, 已存在处理中的数据');
            }
            cache('super-' . $openid . '-new', $newUser, 60);
            $user = AuthType::AuthPlatForm($newUser, $data['type']);
            unset($user['id']);

            $user['openid'] = $newUser['openid'];
            $user['isChose'] = false;
            $user['need_auth'] = true;
            $is_login = true;
        }
        $user['is_login'] = $is_login;
        if (!empty($user)) {
            Cache::delete('superAppUserInfo-' . $user['phone']);
            cache('superAppUserInfo-' . $user['phone'], $user, 600);
        }
        cache('super-' . $openid . '-new', null);
        return $user;
    }

    /**
     * @title  获取用户详细信息
     * @param array $data
     * @return mixed
     */
    public function getUserInfo(array $data)
    {
        $profileRequestUrl = $this->requestDomain . 'api/auth/get_user_info';

        $profileRequestParam['user_id'] = $data['user_id'];
        $profileRequestParam['platform_num'] = $this->platformNum;
        $profileRequestParam['token'] = $data['token'];

        $res = curl_post($profileRequestUrl, $profileRequestParam);
        $requestData = json_decode($res, true);

        if (empty($requestData) || (!empty($requestData) && empty($requestData['data'] ?? []))) {
            throw new ServiceException(['msg' => $requestData['message'] ?? '授权服务获取用户信息有误']);
        }
        $userProfile = $requestData['data'];
        return $userProfile;
    }
}
