<?php
// +----------------------------------------------------------------------
// |[ 文档说明: 其他平台同步到本平台的用户信息]
// +----------------------------------------------------------------------
// | Copyright (c) 2018~2024 http://www.mlhcmk.com All rights reserved.
// +----------------------------------------------------------------------
 

namespace app\lib\models;


use app\BaseModel;
use app\lib\exceptions\ServiceException;
use app\lib\services\Code;
use think\facade\Cache;

class WxUserSync extends BaseModel
{
    /**
     * @title  同步其他平台用户信息到主体平台
     * @param array $data
     * @return bool
     */
    public function syncOtherAppUser(array $data)
    {
        switch ($data['app_type'] ?? 1) {
            case 1:
                $appId = 'wx02e79327a25021e4';
                break;
            default:
                throw new ServiceException(['msg' => '未知的应用类型']);
        }
        //校验验证码
        Code::getInstance()->goCheck($data['sync_phone'] ?? null,$data['code'] ?? '1');
        $checkSynUserPwd = User::where(['phone' => $data['sync_phone'], 'status' => 1])->field('uid,name,phone,pwd')->findOrEmpty()->toArray();
        if (empty($checkSynUserPwd) || (!empty($checkSynUserPwd) && ($checkSynUserPwd['pwd'] != md5(trim(($data['sync_pwd'] ?? null))) && $checkSynUserPwd['pwd'] != $data['sync_pwd']))) {
            throw new ServiceException(['msg' => '同步用户信息有误!']);
        }
        if (!empty($checkSynUserPwd['primary_uid'])) {
            throw new ServiceException(['msg' => '被同步用户非主账号!']);
        }
        $SyncUserExistRecord = self::where(['uid' => $checkSynUserPwd['uid'], 'app_id' => $appId, 'status' => 1])->count();
        if (!empty($SyncUserExistRecord)) {
            throw new ServiceException(['msg' => '已存在同步信息,无法重复同步']);
        }
        $wxUserInfo = WxUser::where(['openid' => $data['openid']])->findOrEmpty()->toArray();
        if (empty($wxUserInfo)) {
            throw new ServiceException(['msg' => '请您先在本平台授权用户信息']);
        }
        $openidUid = User::where(['openid' => $data['openid'], 'status' => 1])->value('uid');
        if (!empty($openidUid ?? null)) {
            $existOrder = Order::where(['uid' => $openidUid])->count();
            if (!empty($existOrder)) {
                throw new ServiceException(['msg' => '当前账号存在订单,无法同步']);
            }
        }
        if ($openidUid == $checkSynUserPwd['uid']) {
            throw new ServiceException(['msg' => '请勿同步本账号信息, 若您主账号尚未授权手机号码, 请联系官方客服']);
        }
        $existSync = self::where(['openid' => $data['openid'], 'status' => 1])->count();
        if (!empty($existSync)) {
            throw new ServiceException(['msg' => '帐号已同步']);
        }

        $newSync['uid'] = $checkSynUserPwd['uid'];
        $newSync['openid'] = $data['openid'];
        $newSync['app_id'] = $appId;
        $newSync['nickname'] = $wxUserInfo['nickname'];
        $newSync['sex'] = $wxUserInfo['sex'];
        $newSync['headimgurl'] = $wxUserInfo['headimgurl'];
        $newSync['country'] = $wxUserInfo['country'];
        $newSync['province'] = $wxUserInfo['province'];
        $newSync['city'] = $wxUserInfo['city'];
        $newSync['unionId'] = $wxUserInfo['unionId'];
        $res = self::create($newSync);

        User::update(['primary_uid' => $checkSynUserPwd['uid']], ['openid' => $data['openid'], 'status' => 1]);
        return judge($res);
    }

    /**
     * @title  清除用户同步信息
     * @param array $data
     * @return bool
     */
    public function clearUserSyncInfo(array $data)
    {
        $userInfo = User::where(['uid' => $data['uid']])->field('openid,primary_uid')->findOrEmpty()->toArray();
        $userOpenid = $userInfo['openid'] ?? null;
        $res = false;
        if (!empty($userOpenid)) {
            $res = User::update(['primary_uid' => null], ['uid' => $data['uid'], 'status' => 1]);
            WxUserSync::update(['status' => -1], ['openid' => $userOpenid, 'status' => 1]);
            if (!empty($userInfo['primary_uid'] ?? null)) {
                Cache::tag([$userInfo['primary_uid']])->clear();
            }
        } else {
            throw new ServiceException(['msg' => '用户不存在有效授权信息']);
        }
        return judge($res);
    }
}