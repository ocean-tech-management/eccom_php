<?php
// +----------------------------------------------------------------------
// | 医生说 [ 文档说明: 令牌service]
// +----------------------------------------------------------------------
// | Copyright (c) 2018~2024 http://www.mlhcmk.com All rights reserved.
// +----------------------------------------------------------------------


namespace app\lib\services;


use app\lib\exceptions\TokenException;
use app\lib\exceptions\UserException;
use app\lib\models\AdminUser;
use app\lib\models\User;
use think\facade\Cache;
use think\facade\Request;

class Token
{
    protected $headerTokenName = 'token';
    protected $headerToken;

    public function __construct()
    {
        $this->headerToken = Request::header("$this->headerTokenName");
    }

    /**
     * @title  生成用户令牌
     * @param array $userInfo 用户信息
     * @return string 令牌
     * @throws TokenException
     * @author  Coder
     * @date   2019年09月26日 18:08
     */
    public function buildToken(array $userInfo)
    {
        $cacheKey = $this->buildCacheKey();
        $cacheValue = json_encode($userInfo);
        $expire = config('system.token.userTokenExpire');
//        $res = Cache::set($cacheKey, $cacheValue, $expire);
        $tag = $userInfo['uid'] ?? null;
        if (!empty($tag)) {
            $res = cache($cacheKey, $cacheValue, $expire, $tag);
        } else {
            $res = Cache::set($cacheKey, $cacheValue, $expire);
        }

        if (!$res) throw new TokenException(['msg' => '缓存失败', 'errorCode' => 40021]);
        return $cacheKey;
    }

    /**
     * @title  重新获取token
     * @author  Coder
     * @date   2019年09月27日 11:37
     */
    public function refreshToken()
    {
        $checkToken = $this->verifyToken();
        if (empty($checkToken)) throw new TokenException(['errorCode' => 1000103]);
        //$rootUrl = trim(strrchr(Request::rootUrl(),'/'),'/');
        $baseUrl = explode('/', trim(Request::pathinfo()));
        $rootUrl = $baseUrl[0];
        switch ($rootUrl) {
            case 'admin':
                $user = AdminUser::where(['id' => $checkToken['id'], 'status' => 1])->field('id,name,account,email,type')->findOrEmpty()->toArray();
                break;
            case 'api':
                $user = User::where(['uid' => $checkToken['uid'], 'status' => 1])->findOrEmpty()->toArray();
                break;
            default:
                $user = [];
                break;
        }
        if (empty($user)) throw new UserException();
        $token = $this->buildToken($user);
        //if($token) Cache::delete($this->headerToken);
        return $token;
    }

    /**
     * @Name   生成缓存键名
     * @return string
     * @author  Coder
     * @date   2019年03月08日 10:50
     */
    protected function buildCacheKey()
    {
        //使用三组字符串md5加密生成
        $randStr = $this->getRandChar(32);
        $time = microtime(true);
        $key = config('system.token.userTokenKeys');
        return md5($randStr . $time . $key);
    }

    public function verifyHeaderToken()
    {
        if (empty($this->headerToken)) {
            return null;
        } else {
            return $this->headerToken;
        }
    }

    /**
     * @title  验证用户令牌
     * @author  Coder
     * @date   2019年09月26日 18:08
     */
    public function verifyToken()
    {
        if (!$this->verifyHeaderToken()) return [];
        $token = $this->headerToken;
        $userInfo = json_decode(Cache::get($token), true);
        return $userInfo;
    }

    /**
     * @Name   获取随机字符串
     * @param int $length 长度
     * @return string|null
     * @author  Coder
     * @date   2019年09月26日 18:16
     */
    function getRandChar(int $length = 32)
    {
        $str = null;
        $strPol = "ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789abcdefghijklmnopqrstuvwxyz";
        $max = strlen($strPol) - 1;

        for ($i = 0; $i < $length; $i++) {
            $str .= $strPol[rand(0, $max)];
        }

        return $str;
    }


    public function buildAppSecret($str)
    {
        return (md5($str . md5(time() . config('system.token.systemName'))));
    }

    public function buildAppKey()
    {
        return (md5(sha1(time() . config('system.token.systemName'))));
    }

}