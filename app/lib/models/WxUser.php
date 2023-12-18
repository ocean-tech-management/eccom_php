<?php
// +----------------------------------------------------------------------
// |[ 文档说明: ]
// +----------------------------------------------------------------------
// | Copyright (c) 2018~2024 http://www.mlhcmk.com All rights reserved.
// +----------------------------------------------------------------------

namespace app\lib\models;


use app\BaseModel;
use think\facade\Request;

class WxUser extends BaseModel
{
    public function uid()
    {
        return $this->hasOne('User', 'openid', 'openid')->bind(['uid']);
    }

    public function wxUserEdit(array $data)
    {
        $save['nickname'] = $data['nickname'];
        $save['headimgurl'] = $data['headimgurl'];
        //更新wxuser表 nickname,headimgurl
        $res = $this->baseUpdate(['tid' => $data['tid']], $save);
        $userInfo = [];
        if ($res) {
            $userInfo = (new User())->getCorrelationUserInfoByUid($data['uid'], $data['openid']);
        }
        return $userInfo;
    }

    /**
     * @title 新增
     * @param array $user
     * @param string $uid
     * @return mixed
     */
    public function new(array $user, string $uid)
    {
        $userData['unionId'] = $user['unionid'] ?? null;
        $userData['openid'] = $user['openid'] ?? null;
        $userData['channel'] = 1;
        $userData['tid'] = getUid(10);
        // $userData['app_id'] = config('system.weChat')['app_id'];
        $access_key = getAccessKey();
        $userData['app_id'] = config("system.clientConfig.$access_key")['app_id'];
        if ($userData['unionId']) {
            $wxUser = $this->where(['openid' => $user['openid']])->findOrEmpty()->toArray();
            if (empty($wxUser)) {
                //获取一下用户授权的个人基本信息
                $wxInfo = WxUser::field('id,nickname,headimgurl,sex')->where(['unionid' => $user['unionid']])->findOrEmpty()->toArray();
                if ($wxInfo) {
                    $userData['nickname'] = $wxInfo['nickname'];
                    $userData['headimgurl'] = $wxInfo['headimgurl'];
                    $userData['sex'] = $wxInfo['sex'];
                }
                //判断accessModel类型
                $model = getAccessModel($access_key);
                $model->create($userData);
            }
        } else {
            //判断accessModel类型
            $model = getAccessModel($access_key);
            $model->where(['openid' => $user['openid']])->findOrEmpty()->toArray();
            $wxUser = $this->where(['openid' => $user['openid']])->findOrEmpty()->toArray();
            if (empty($wxUser)) {
                $model->create($userData);
            }
        }
        $userAuthTypeData = [
            'tid' => $userData['tid'],
            'uid' => $uid,
            'openid' => $userData['openid'],
            'status' => 1,
            'wxunionid' => $userData['unionId'],
            'app_id' => $userData['app_id'],
            'access_key' => $access_key,
        ];
        $res = UserAuthType::create($userAuthTypeData);
        return $res;
    }

    public function userAuthType()
    {
        return $this->hasOne('UserAuthType', 'tid', 'tid')->bind(['uid', 'status']);
    }
}
