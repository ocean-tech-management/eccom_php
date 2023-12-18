<?php
// +----------------------------------------------------------------------
// |[ 文档说明: 登录模块Controller]
// +----------------------------------------------------------------------
// | Copyright (c) 2018~2024 http://www.mlhcmk.com All rights reserved.
// +----------------------------------------------------------------------


namespace app\controller\api\v1;


use app\BaseController;
use app\lib\exceptions\ServiceException;
use app\lib\services\Code;
use app\lib\models\User;
use app\lib\services\Token;
use think\facade\Cache;

class login extends BaseController
{
    /**
     * @title  h5端帐号密码登录
     * @return string
     */
    public function login()
    {
        $res = (new User())->login($this->requestData);
        return returnData($res);
    }

    /**
     * @title  h5端密码修改
     * @return string
     */
    public function updatePwd()
    {
        $res = (new User())->updatePwd($this->requestData);
        return returnData($res);
    }

    /**
     * @title  支付密码修改
     * @return string
     */
    public function updatePayPwd()
    {
        $res = (new User())->updatePayPwd($this->requestData);
        return returnData($res);
    }

    /**
     * @title 获取短信验证码
     * @return string
     */
    public function code()
    {
        $code = Code::getInstance()->register($this->request->param('phone'));
        return returnMsg(judge($code));
    }

    /**
     * @title  刷新token
     * @param Token $service
     * @return string
     */
    public function refreshToken(Token $service)
    {
        $info = $service->refreshToken();
        return returnData($info);
    }

    /**
     * @title  创建token
     * @param Token $service
     * @return string
     */
    public function buildToken(Token $service)
    {
        $data = $this->requestData;
        $clientModule = substr(getAccessKey(), 0, 1);
        //仅允许实际的APP端才可以调用此接口
        if (!in_array($clientModule, ['d', 'a'])) {
            throw new ServiceException(['msg' => '不允许的客户端渠道']);
        }
        if (empty($data['uid'] ?? null)) {
            throw new ServiceException(['msg' => '非法用户信息']);
        }
        $userInfo = User::where(['uid' => $data['uid'], 'status' => 1])->count('id');
        if (empty($userInfo)) {
            throw new ServiceException(['msg' => '非法用户']);
        }
        //清除用户的历史的所有缓存令牌, 迫使用户强制重新登录
        Cache::tag([$data['uid']])->clear();
        $info = $service->buildToken($data);
        return returnData($info);
    }
}