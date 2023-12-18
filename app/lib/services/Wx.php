<?php
// +----------------------------------------------------------------------
// | [ 文档说明: 微信SDK模块]
// +----------------------------------------------------------------------
// | Copyright (c) 2018~2024 http://www.mlhcmk.com All rights reserved.
// +----------------------------------------------------------------------


namespace app\lib\services;


use app\lib\exceptions\WxException;
use app\lib\models\Device;
use app\lib\models\LiveRoom;
use app\lib\models\LiveRoomGoods;
use app\lib\models\LiveRoomReplay;
use app\lib\models\User;
use app\lib\models\WxConfig;
use app\lib\models\WxUser;
use think\facade\Db;
use think\facade\Request;
use think\facade\Validate;
use think\facade\Cache;

class Wx
{
    protected $appId;
    protected $h5AppId;
    protected $appSecret;
    protected $h5AppSecret;
    protected $code;
    protected $authUrl;
    protected $tokenUrl;
    protected $ticketUrl;
    protected $aOauthUrl;
    protected $aQrCodeUrl;
    protected $templateUrl;
    protected $routePateMode;
    protected $gzhTemplateUrl;
    protected $clientModule; //客户端标识,如a,m,p,w,d等

    //关键配置初始化
    public function __construct()
    {
        $access_key = getAccessKey();
        $this->clientModule = substr($access_key, 0, 1);
        $wxConfig = $this->configWxParameter($access_key);
        $wxUrl = config('system.weChatUrl');
        $this->appId = $wxConfig['app_id'];
        $this->appSecret = $wxConfig['app_secret'];
        $this->authUrl = $wxUrl['auth_url'];
        $this->tokenUrl = $wxUrl['token_url'];
        $this->templateUrl = $wxUrl['template_url'];
        $this->gzhTemplateUrl = $wxUrl['gzh_template_url'];
        $this->ticketUrl = $wxUrl['ticket_url'];
        $this->aOauthUrl = $wxUrl['oauth'];
        $this->aQrCodeUrl = $wxUrl['qr_code_url'] ?? null;
        $this->h5AppId = $wxConfig['app_id'] ?? null;
        $this->h5AppSecret = $wxConfig['app_secret'] ?? null;
        // $this->h5AppId = $wxConfig['h5_app_id'] ?? null;
        // $this->h5AppSecret = $wxConfig['h5_app_secret'] ?? null;
    }

    /**
     * @title  根据路由改变关键配置参数
     * @return mixed
     */
    private function configWxParameter($access_key)
    {
        $aPath = ltrim(Request::root(), '/');
        $this->routePateMode = $aPath;
        switch ($aPath) {
            case 'api':
                $wxConfig = config("system.clientConfig.$access_key");
                break;
            default:
                $wxConfig = config("system.clientConfig.$access_key");
                break;
        }
        return $wxConfig;
    }


    /**
     * @title  授权获取微信用户信息
     * @param array $data
     * @return WxUser|mixed|\think\Model
     * @remark type 参数为授权类型 1为小程序 2为公众号
     *         userInfoType 为用户信息类型(仅小程序有) 1为部分信息 2为全部信息(需要传密钥和加密数据)
     * @throws \OSS\Core\OssException
     */
    public function WxUserInfo(array $data)
    {
        $this->code = $data['code'];
        switch ($data['type'] ?? 1) {
            case 1:
                //是否需要解密数据 1为否 2为是
                $userInfoType = $data['userInfoType'] ?? 1;
                $url = sprintf($this->authUrl, $this->appId, $this->appSecret, $this->code);
                $res = curl_get($url);
                $wxInfo = json_decode($res, true);
                if (empty($wxInfo)) $this->error();
                if (array_key_exists('errcode', $wxInfo)) $this->error($wxInfo);
                if ($userInfoType == 2) {
                    //$userInfo = $this->decryptData($wxInfo['session_key'], $data['crypt']);
                    $userInfo = $this->decryptData($data['type'], $wxInfo['session_key'], $data['crypt']);
                    $userArr = $userInfo;
                } else {
                    $userArr = $wxInfo;
                }
                break;
            case 2:
                $oAuth = $this->aOauthUrl;
                $url = sprintf($oAuth['auth_url'], $this->h5AppId, $this->h5AppSecret, $this->code);
                $res = curl_get($url);
                $wxInfo = json_decode($res, true);
                if (empty($wxInfo)) $this->error();
                if (array_key_exists('errcode', $wxInfo)) $this->error($wxInfo);
                //获取用户信息
                $openid = $wxInfo['openid'];
                $accessToken = $wxInfo['access_token'];
                $userInfoUrl = sprintf($oAuth['user_info_url'], $accessToken, $openid);
                $userRes = curl_get($userInfoUrl);
                $userInfo = json_decode($userRes, true);
                (new Log())->setChannel('wx')->record($userInfo);
                if (empty($userInfo)) $this->error();
                if (array_key_exists('errcode', $userInfo)) $this->error($userInfo);
                //授权即绑定
                if (!empty($data['link_user'] ?? null)) {
                    $userInfo['link_user'] = $data['link_user'];
                }
                $userInfo['nickName'] = $userInfo['nickname'];
                $userInfo['avatarUrl'] = $userInfo['headimgurl'];
                $userInfo['unionId'] = $userInfo['unionid'] ?? null;
                $userInfo['gender'] = $userInfo['sex'];
                //$userArr = $this->addNewUser($userInfo);
                $userChose = intval(isset($data['userChose'])) ?? 0;
                $userArr = AuthType::AuthPlatForm($userInfo, $data['type'], $userChose);
                break;
            case 3:
                //是否需要解密数据 1为否 2为是
                $userInfoType = $data['userInfoType'] ?? 1;
                $url = sprintf($this->authUrl, $this->appId, $this->appSecret, $this->code);
                $res = curl_get($url);
                $wxInfo = json_decode($res, true);
                if (empty($wxInfo)) $this->error();
                if (array_key_exists('errcode', $wxInfo)) $this->error($wxInfo);
                if ($userInfoType == 2) {
                    //$userInfo = $this->decryptData($wxInfo['session_key'], $data['crypt'], false);
                    $userInfo = $this->decryptData($data['type'], $wxInfo['session_key'], $data['crypt'], false);
                    $userArr = $userInfo;
                } else {
                    $userArr = $wxInfo;
                }
                break;
            default:
                $userArr = [];
        }

        return $userArr;
    }


    public function authH5public(array $data = [])
    {
        $data['type'] = 2;
        if (isset($data['code']) && !empty($data['code'])) {
            $oAuth = $this->aOauthUrl;
            $url = sprintf($oAuth['auth_url'], $this->h5AppId, $this->h5AppSecret, $data['code']);
            $res = curl_get($url);
            $wxInfo = json_decode($res, true);
            if (empty($wxInfo)) $this->error();
            if (array_key_exists('errcode', $wxInfo)) $this->error($wxInfo);
            //获取用户信息
            $openid = $wxInfo['openid'];
            $accessToken = $wxInfo['access_token'];
            $userInfoUrl = sprintf($oAuth['user_info_url'], $accessToken, $openid);
            $userRes = curl_get($userInfoUrl);
            $userInfo = json_decode($userRes, true);
            if (empty($userInfo)) $this->error();
            if (array_key_exists('errcode', $userInfo)) $this->error($userInfo);
            //授权即绑定
            if (!empty($data['link_user'] ?? null)) {
                $userInfo['link_user'] = $data['link_user'];
            }
            $userInfo['nickName'] = $userInfo['nickname'];
            $userInfo['avatarUrl'] = $userInfo['headimgurl'];
            $userInfo['unionId'] = $userInfo['unionid'] ?? null;
            $userInfo['gender'] = $userInfo['sex'];
            (new Log())->setChannel('wx')->record($userInfo);
        } else {
            //微信数据包
            $userInfo = $data['wx_data'];
        }
        $wxUserInfo = $userInfo;
        $userChose = intval(isset($data['userChose'])) ?? 0;
        $userArr = AuthType::AuthPlatForm($wxUserInfo, $data['type'], $userChose);
        return $userArr;
    }

    /**
     * @title  解密数据
     * @param string $sessionKey
     * @param array $crypt 解密秘钥
     * @param bool $needCreateUser 是否需要新增用户
     * @return WxUser|\think\Model
     */
    public function decryptData(string $type, string $sessionKey, array $crypt, bool $needCreateUser = true)
    {
        $Crypt = new wxBizDataCrypt($this->appId, $sessionKey);
        $CryptErr = $Crypt->decryptData($crypt['encryptedData'], $crypt['iv'], $cryptArray);
        if ($CryptErr != 0) $this->error(['errcode' => $CryptErr, 'errmsg' => '网络出错啦,请重试']);
        $userInfo = json_decode($cryptArray, true);
        if ($needCreateUser) {
            $userInfo['openid'] = $userInfo['openId'];
            //$returnData = $this->addNewUser($userInfo);
            $returnData = AuthType::AuthPlatForm($userInfo, $type);
        } else {
            $returnData = $userInfo;
        }
        return $returnData;
    }

    /**
     * @title  授权用户信息保存
     * @param array $wxInfo
     * @return array|mixed
     * @throws \OSS\Core\OssException
     */
    public function addNewUser(array $wxInfo)
    {
        $user = WxUser::where(['openid' => $wxInfo['openid']])->findOrEmpty()->toArray();
        $userInfo = $wxInfo;
        $userInfo['sex'] = $wxInfo['gender'] ?? 0;
        $userInfo['nickname'] = $wxInfo['nickName'];
        $userInfo['headimgurl'] = $wxInfo['avatarUrl'];
        //头像转存
        // if (!empty($userInfo['headimgurl'] ?? null)) {
        // }
        $headImg = (new FileUpload())->saveWxAvatar(['headimgurl' => $userInfo['headimgurl'], 'openid' => $userInfo['openid']]);
        if (!empty($headImg)) {
            $userInfo['headimgurl'] = $headImg;
        }
        if (!empty($wxInfo['link_user'] ?? null)) {
            $wxInfo['link_user'] = str_replace(' ', '', $wxInfo['link_user']);
        }
        $userInfo['link_user'] = $wxInfo['link_user'] ?? null;

        if (empty($user)) {
            //$wxInfo['session3rd'] = getRandomString(12);
            $wxInfo['mode'] = $this->routePateMode;
            $res = WxUser::create($userInfo);
            $useRes = (new User())->new($userInfo);
        } else {
            $res = WxUser::update($userInfo, ['openid' => $userInfo['openid']]);
            $useRes = (new User())->wxEdit($userInfo);
        }
        return $useRes;
    }


    /**
     * @title  获取AccessToken
     * @return string
     */
    public function getAccessToken($access_key = null)
    {
        if ($access_key) {
            $wxConfig = $this->configWxParameter($access_key);
            $this->appId = $wxConfig['app_id'];
            $this->appSecret = $wxConfig['app_secret'];
        }
        $aAccessInfo = WxConfig::where(['type' => 1, 'origin' => 2, 'app_id' => $this->appId])->findOrEmpty()->toArray();
        $url = sprintf($this->tokenUrl, $this->appId, $this->appSecret);
        $accessToken = null;
        if (empty($aAccessInfo)) {
            $res = curl_get($url);
            $data = json_decode($res, true);
            if (!empty($data) && !empty($data['access_token'] ?? null)) {
                $aInfo['app_id'] = $this->appId;
                $aInfo['config'] = $data['access_token'];
                $aInfo['type'] = 1;
                $aInfo['origin'] = 2;
                $aInfo['timeout_time'] = time() + 7200;
                WxConfig::create($aInfo);
                $accessToken = $data['access_token'];
            }
        } else {
            if ($aAccessInfo['timeout_time'] < time()) {
                $res = curl_get($url);
                $data = json_decode($res, true);
                if (!empty($data) && !empty($data['access_token'] ?? null)) {
                    $aInfo['app_id'] = $this->appId;
                    $aInfo['config'] = $data['access_token'];
                    $aInfo['type'] = 1;
                    $aInfo['origin'] = 2;
                    $aInfo['timeout_time'] = time() + 7200;
                    WxConfig::update($aInfo, ['type' => 1, 'origin' => 2]);
                    $accessToken = $data['access_token'];
                }
            } else {
                $accessToken = $aAccessInfo['config'];
            }
        }
        return $accessToken;
    }

    /**
     * @title  获取JsApiTicket
     * @return string
     */
    public function getJsApiTicket()
    {
        $token = $this->getAccessToken();
        $aTicketInfo = WxConfig::where(['type' => 2, 'origin' => 2, 'app_id' => $this->appId])->findOrEmpty()->toArray();
        $url = sprintf($this->ticketUrl, $token);
        if (empty($aTicketInfo)) {
            $res = curl_get($url);
            $data = json_decode($res, true);
            $aInfo['app_id'] = $this->appId;
            $aInfo['config'] = $data['ticket'];
            $aInfo['type'] = 2;
            $aInfo['origin'] = 2;
            $aInfo['timeout_time'] = time() + 7200;
            WxConfig::create($aInfo);
            $ticket = $data['ticket'];
        } else {
            if ($aTicketInfo['timeout_time'] < time()) {
                $res = curl_get($url);
                $data = json_decode($res, true);
                $aInfo['app_id'] = $this->appId;
                $aInfo['config'] = $data['ticket'];
                $aInfo['type'] = 2;
                $aInfo['origin'] = 2;
                $aInfo['timeout_time'] = time() + 7200;
                WxConfig::update($aInfo, ['type' => 2, 'origin' => 2]);
                $ticket = $data['ticket'];
            } else {
                $ticket = $aTicketInfo['config'];
            }
        }
        return $ticket;
    }

    /**
     * @title  获取H5-AccessToken
     * @return string
     */
    public function getH5AccessToken()
    {
        switch ($this->clientModule) {
            case 'd':
                $origin = 3;
                break;
            case 'p':
                $origin = 1;
                break;
            default:
                $origin = 1;
        }
        $aAccessInfo = WxConfig::where(['type' => 1, 'origin' => $origin, 'app_id' => $this->h5AppId])->findOrEmpty()->toArray();
        $url = sprintf($this->aOauthUrl['token_url'], $this->h5AppId, $this->h5AppSecret);
        if (empty($aAccessInfo)) {
            $res = curl_get($url);
            $data = json_decode($res, true);
            $aInfo['app_id'] = $this->appId;
            $aInfo['config'] = $data['access_token'];
            $aInfo['type'] = 1;
            $aInfo['origin'] = 1;
            $aInfo['timeout_time'] = time() + 7200;
            WxConfig::create($aInfo);
            $accessToken = $data['access_token'];
        } else {
            if ($aAccessInfo['timeout_time'] < time()) {
                $res = curl_get($url);
                $data = json_decode($res, true);
                $aInfo['app_id'] = $this->appId;
                $aInfo['config'] = $data['access_token'];
                $aInfo['type'] = 1;
                $aInfo['origin'] = 1;
                $aInfo['timeout_time'] = time() + 7200;
                WxConfig::update($aInfo, ['type' => 1, 'origin' => 1]);
                $accessToken = $data['access_token'];
            } else {
                $accessToken = $aAccessInfo['config'];
            }
        }
        return $accessToken;
    }

    /**
     * @title  获取(H5)JsApiTicket
     * @return string
     */
    public function getH5JsApiTicket()
    {
        switch ($this->clientModule) {
            case 'd':
                $origin = 3;
                break;
            case 'p':
                $origin = 1;
                break;
            default:
                $origin = 1;
        }
        $token = $this->getH5AccessToken();
        $aTicketInfo = WxConfig::where(['type' => 2, 'origin' => $origin, 'app_id' => $this->h5AppId])->findOrEmpty()->toArray();
        $url = sprintf($this->aOauthUrl['ticket_url'], $token);
        if (empty($aTicketInfo)) {
            $res = curl_get($url);
            $data = json_decode($res, true);
            $aInfo['app_id'] = $this->appId;
            $aInfo['config'] = $data['ticket'];
            $aInfo['type'] = 2;
            $aInfo['origin'] = 1;
            $aInfo['timeout_time'] = time() + 7200;
            WxConfig::create($aInfo);
            $ticket = $data['ticket'];
        } else {
            if ($aTicketInfo['timeout_time'] < time()) {
                $res = curl_get($url);
                $data = json_decode($res, true);
                $aInfo['app_id'] = $this->appId;
                $aInfo['config'] = $data['ticket'];
                $aInfo['type'] = 2;
                $aInfo['origin'] = 1;
                $aInfo['timeout_time'] = time() + 7200;
                WxConfig::update($aInfo, ['type' => 2, 'origin' => 1]);
                $ticket = $data['ticket'];
            } else {
                $ticket = $aTicketInfo['config'];
            }
        }
        return $ticket;
    }

    /**
     * @title  获取直播间列表
     * @return bool
     */
    public function getLiveRoomList()
    {
        $accessToken = $this->getAccessToken();
        $data['start'] = 0;
        $data['limit'] = 20;
        $requestData = json_encode($data);
        $requestUrl = 'https://api.weixin.qq.com/wxa/business/getliveinfo?access_token=' . $accessToken;
        $res = curl_post($requestUrl, $requestData);
        $res = json_decode($res, 1);
        if (empty($res['errcode'])) {
            $dbRes = Db::transaction(function () use ($res) {
                $liveRoomModel = (new LiveRoom());
                $liveRoomGoodsModel = (new LiveRoomGoods());
                foreach ($res['room_info'] as $key => $value) {
                    $res = $liveRoomModel->DBNewOrEdit($value);
                    $goods = $value['goods'];
                    foreach ($goods as $gKey => $gValue) {
                        $gValue['roomid'] = $value['roomid'];
                        $liveRoomGoodsModel->DBNewOrEdit($gValue);
                    }
                }
                return $res;
            });
        } else {
            throw new WxException(['msg' => '可能还没有创建直播间哦~']);
        }
        return judge($res);
    }

    /**
     * @title  获取直播间回放列表
     * @return bool
     */
    public function getLiveRoomReplayList(array $sear)
    {
        $accessToken = $this->getAccessToken();
        $data['action'] = 'get_replay';
        $data['room_id'] = $sear['room_id'];
        $data['start'] = 0;
        $data['limit'] = 20;
        $requestData = json_encode($data);
        $requestUrl = 'https://api.weixin.qq.com/wxa/business/getliveinfo?access_token=' . $accessToken;
        $res = curl_post($requestUrl, $requestData);
        $res = json_decode($res, 1);

        if (empty($res['errcode'])) {
            $dbRes = Db::transaction(function () use ($res, $data) {
                $liveRoomReplayModel = (new LiveRoomReplay());
                foreach ($res['live_replay'] as $key => $value) {
                    $value['roomid'] = $data['room_id'];
                    $value['total'] = $res['total'];
                    $value['replay_create_time'] = strtotime($value['create_time']);
                    $value['expire_time'] = strtotime($value['expire_time']);
                    unset($value['create_time']);
                    $res = $liveRoomReplayModel->DBNewOrEdit($value);
                }
                return $res;
            });
        } else {
            throw new WxException(['msg' => '可能还没有创建直播间哦~']);
        }

        return judge($res);
    }


    /**
     * @title  获取JSAPI签名
     * @param string $url 当前网页地址
     * @return array
     */
    public function buildSignature(string $url)
    {
        //签名算法
        $timestamp = time();  //时间戳
        $jsapi_ticket = $this->getH5JsApiTicket();
        $noncestr = getRandomString(16);

        //拼接$signature原型
        $ping = "jsapi_ticket=" . $jsapi_ticket . "&noncestr=" . $noncestr . "&timestamp=" . $timestamp . "&url=" . $url;
        //加密生成signature
        $signature = sha1($ping);

        $signPackage = array(
            "appId" => $this->h5AppId,
            "nonceStr" => $noncestr,
            "timeStamp" => $timestamp,
            "signature" => $signature
        );

        return $signPackage;
    }

    /**
     * @title  发送模版消息
     * @param array $data 关键参数
     * @param int $type 平台类型 1为小程序 2为公众号
     * @return bool
     */
    public function sendTemplate(array $data, int $type = 1)
    {
        //$data的数据格式是固定的,type是模板类型=>对应配置文件,template数组对应的为模板消息依次的顺序
        $templateId = config('system.templateId.' . $data['type']);
        $accessToken = $this->getAccessTokenByAccessKey(($data['access_key'] ?? null));
        if ($type == 1) {
            $requestUrl = $this->templateUrl;
        } else {
            $requestUrl = $this->gzhTemplateUrl;
        }
        $url = sprintf($requestUrl, $accessToken);
        $tData = [
            'touser' => $data['openid'],   //用户openid
            'template_id' => $templateId,          //模板id
            'data' => $this->buildData($data['template'])
        ];
        if ($type == 1) {
            $tData['page'] = $data['page'] ?? ''; //跳转页面,可带参数
        }
        if ($type == 2) {
            $tData['url'] = $data['page'] ?? ''; //跳转页面,可带参数
            $tData['miniprogram'] = $data['miniprogram'] ?? []; //跳转小程序,可带参数
        }

        if (empty($tData['touser'])) throw new WxException();
        $result = json_decode(curl_post($url, json_encode($tData, JSON_UNESCAPED_UNICODE)), true);
        $log['template'] = $result;
        $log['data'] = $tData;
        (new Log())->setChannel('wx')->record($log);    //记录日志

        if ($result['errcode'] == 0 && $result['errmsg'] == 'ok') {
            return true;
        } else {
            return false;
        }
    }

    /**
     * @title 根据客户端标识获取微信服务需要的accessToken
     * @param $access_key
     * @return string|null
     */
    public function getAccessTokenByAccessKey($access_key = null)
    {
        if (!empty($access_key ?? null)) {
            $clientModule = substr($access_key, 0, 1);
        } else {
            $clientModule = $this->clientModule;
        }
        switch ($clientModule) {
            case 'm':
                $accessToken = $this->getAccessToken($access_key);
                break;
            default:
                $accessToken = $this->getH5AccessToken();
        }
        return $accessToken;
    }


    /**
     * @title  根据数据格式组成模板数据
     * @param array $data 模板数据
     * @param int $type 平台类型 1为小程序 2为公众号
     * @return array
     */
    public function buildData(array $data, int $type = 1)
    {
        $i = 1;
        $array = [];
        foreach ($data as $key => $value) {
            if (is_numeric($key)) {
                $dataKey = 'keyword' . $i;
                $i++;
            } else {
                $dataKey = $key;
            }
            $array[$dataKey]['value'] = $value;
            if ($type == 2) {
                $array[$dataKey]['color'] = '#173177';
            }
        }
        return $array;
    }

    /**
     * @title  获取小程序码
     * @param array $data
     * @return mixed|null
     * @throws \OSS\Core\OssException
     */
    public function getWxaCode(array $data)
    {
        $fileName = date('YmdHis') . mt_rand(100000, 999999) . '.png';
        $rootPath = app()->getRootPath() . 'public/';
        $childPath = 'storage/qr-code/wx/' . date('Ymd');
        $filePath = $rootPath . $childPath;
        $checkPath = is_dir($filePath);
        if (!$checkPath) {
            mkdir($filePath, 0777, true);
        }
        $fileFullPath = $filePath . '/' . $fileName;

        $rData['scene'] = $data['content'];
        $rData['page'] = $data['page'] ?? 'pages/index/index';
        $rData['width'] = intval($data['width'] ?? 480);
        //$rData['auto_color'] = true;
        $rData['line_color'] = $data['line_color'] ?? null;
        $rData['is_hyaline'] = $data['is_hyaline'] ?? false;
        $requestUrl = $this->aQrCodeUrl;
        $accessToken = $this->getAccessToken();
        $requestUrl = sprintf($requestUrl, $accessToken);

        $res = $this->curlPost($requestUrl, json_encode($rData));
        $resJson = json_decode($res, 1);

        if (!empty($resJson)) {
            $log['requestData'] = $data;
            $log['result'] = $resJson;
            (new Log())->setChannel('wx')->record($log, 'error');
            throw new WxException(['msg' => '生成小程序码服务有误,请稍后重试']);
        }

        //上传文件
        $upload = file_put_contents($fileFullPath, $res);
        $imgPath = null;
        if ($upload === false) {
            throw new WxException(['msg' => '保存小程序码有误']);
        } else {
            //$imgPath = config('system.imgDomain') . $childPath . '/'. $fileName;
            $ossFilePath = str_replace('storage/', '', $childPath) . '/' . $fileName;
            $imgPath = (new AlibabaOSS())->uploadFile($ossFilePath, 'wx-code');
        }
        return $imgPath;
    }

    /**
     * @title  生成小程序短码
     * @param array $data
     * @return mixed
     * @remark 默认30天最长有效期
     */
    public function wxScheme(array $data = [])
    {
        $expireDay = 30;
        $expireTime = time() + (3600 * 24 * $expireDay);
        $rData['jump_wxa'] = [];
        $rData['jump_wxa']['path'] = $data['page'] ?? 'pages/index/index';
        $rData['jump_wxa']['query'] = $data['query'] ?? null;
        $rData['expire_type'] = 0;
        $rData['expire_time'] = $expireTime;
        $rData['expire_interval'] = $expireDay;
        $requestUrl = 'https://api.weixin.qq.com/wxa/generatescheme?access_token=%s';
        $accessToken = $this->getAccessToken();
        $requestUrl = sprintf($requestUrl, $accessToken);

        $res = $this->curlPost($requestUrl, json_encode($rData));
        $resJson = json_decode($res, 1);
        if (empty($resJson) || (!empty($resJson) && $resJson['errcode'] != 0)) {
            $log['requestData'] = $data;
            $log['result'] = $resJson;
            (new Log())->setChannel('wx')->record($log, 'error');
            if (empty($data['notThrowError'])) {
                throw new WxException(['msg' => '生成小程序短码服务有误,请稍后重试']);
            } else {
                return false;
            }
        }
        return ['scheme' => $resJson['openlink'], 'expireTime' => $expireTime];
    }

    /**
     * @title  抛出异常
     * @param array $data
     * @return void
     */
    public function error(array $data = [])
    {
        $msg = $data['errmsg'] ?? '微信服务有误';
        throw new WxException([
            'errorCode' => 14001,
            'msg' => $msg
        ]);
    }

    public function curlPost($url, $data)
    {
        $headers = array("Content-type: application/json; charset=UTF-8", "accept: application/json");
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url); //定义请求地址
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST'); //定义请求类型
        curl_setopt($ch, CURLOPT_POST, true); //定义提交类型 1：POST ；0：GET
        curl_setopt($ch, CURLOPT_HEADER, 0); //定义是否显示状态头 1：显示 ； 0：不显示
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, FALSE);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, FALSE);
        //curl_setopt($ch, CURLOPT_HTTPHEADER, 1);//定义header
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1); //定义是否直接输出返回流
        curl_setopt($ch, CURLOPT_POSTFIELDS, $data); //定义提交的数据
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        $res = curl_exec($ch);
        curl_close($ch);

        return $res;
    }

    /**
     * @description: web-h5登录
     * @param {array} $param
     * @return {*}
     */
    public function webLogin(array $param = [])
    {
        $phone = $param['phone'];
        //限制多次登录（十分钟累计5次错误禁用）
        if (Cache::get($phone . "_login_ban_num") || Cache::get($phone . '_login_ban') || Cache::get($phone . "_login_ban_" . date('Y-m-d'))) {
            if (Cache::get($phone . "_login_ban_" . date('Y-m-d')) >= 10) return $this->error(['errmsg' => '登录过于频繁，今日已被禁用！']);
            if (Cache::get($phone . '_login_ban') == 1) return $this->error(['errmsg' => '登录过于频繁，已被禁用30分钟！']);
            if (Cache::get($phone . "_login_ban_num") >= 5) {
                Cache::set($phone . '_login_ban', 1, 60 * 30);
                return $this->error(['errmsg' => '登录过于频繁，已被禁用30分钟！']);
            }
        }
        //判断登录方式
        $user_data = [];
        $type = $param['type'];
        $field = ['id', 'uid', 'share_id', 'phone', 'openid', 'pwd', 'name', 'avatarUrl'];
        switch ($type) {
            case 1:
                $param['pwd'] = privateDecrypt($param['pwd']);
                $password = $param['pwd'];
                $user_data = User::where(['phone' => $phone, 'status' => 1])->field($field)->findOrEmpty()->toArray();
                if (empty($user_data) || is_null($user_data['pwd']) || (md5($password) !== $user_data['pwd'])) {
                    $this->setLoginOut($phone);
                    return $this->error(['errmsg' => '账号密码有误，请确认后重试！']);
                }
                break;
            case 2:
                $phoneCode = $param['phone_code'];
                $user_data = User::where(['phone' => $phone, 'status' => 1])->field($field)->findOrEmpty()->toArray();
                if (empty($user_data)) {
                    $this->setLoginOut($phone);
                    return $this->error(['errmsg' => '账号密码有误，请确认后重试！']);
                }
                Code::getInstance()->goCheck($phone, $phoneCode);
                break;
        }
        //判断补充share_id
        $share_id = User::where(['uid' => $user_data['uid']])->value('share_id');
        if (!$share_id) {
            $new_share_id = getUid(11);
            User::where(['uid' => $user_data['uid']])->update(['share_id' => $new_share_id]);
        }
        //获取token
        $token = (new Token())->buildToken($user_data);
        $user_data['token'] = $token;
        return $user_data;
    }

    /**
     * @description: web-h5注册
     * @param {array} $param
     * @return {*}
     */
    public function webRegister(array $param = [])
    {
        $param['pwd'] = privateDecrypt($param['pwd']);
        $param['re_pwd'] = privateDecrypt($param['re_pwd']);
        //验证字段
        $validate = Validate::rule([
            'phone'  => 'require|max:11',
            'phone_code'  => 'require',
            'pwd' => 'require|min:4',
            're_pwd' => 'require|confirm:pwd',
        ])->message([
            'phone.require' => '手机号码不能为空',
            'phone_code.require' => '验证码不能为空',
            'pwd.require' => '密码不能为空',
            'pwd.min' => '密码不能少于4位',
            're_pwd.require' => '重复密码不能为空',
            're_pwd.confirm' => '两次密码不一致',
        ]);

        if (!$validate->check($param)) {
            throw new WxException(['msg' => $validate->getError()]);
        }
        //执行注册
        $phone = $param['phone'];
        $phoneCode = $param['phone_code'];
        // Code::getInstance()->goCheck($phone, $phoneCode);
        //判断手机号是否已经存在
        $user_id = User::where(['phone' => $phone])->value('id');
        if ($user_id) {
            throw new WxException(['msg' => '账号已存在,请确认后重试']);
        }
        //添加用户
        $user_data = [
            'openid' => getWebH5Openid('web'),
            'phone' => $param['phone'],
            'pwd' => md5($param['pwd']),
            'name' => $param['name'] ?? null,
            'avatarUrl' => $param['avatarUrl'] ?? null,
            'uid' => getUid(10),
            'share_id' => getUid(11),
        ];
        $user = User::create($user_data)->toArray();
        if (!$user) {
            throw new WxException(['msg' => '注册失败,请确认后重试']);
        }
        return $user;
    }

    //限制h5登录
    public function setLoginOut($key)
    {
        //超过五次禁用半小时
        if (Cache::get($key . "_login_ban_num") && Cache::get($key . "_login_ban_num") < 5) {
            $cache_num = Cache::get($key . "_login_ban_num");
            Cache::set($key . "_login_ban_num", ($cache_num + 1), 60 * 10);
            Cache::set($key . '_login_ban', 0, 60 * 30);
        } else {
            Cache::set($key . "_login_ban_num", 1, 60 * 30);
        }
        //超过十次禁用一天
        //计算当前时间距离今日结束时间的时间戳
        $cacehTime = strtotime(date('Y-m-d')) + 3600 * 24 - time() + (60 * 10);
        if (Cache::get($key . "_login_ban_" . date('Y-m-d'))) {
            Cache::set($key . "_login_ban_" . date('Y-m-d'), Cache::get($key . "_login_ban_" . date('Y-m-d')) + 1, $cacehTime);
        } else {
            Cache::set($key . "_login_ban_" . date('Y-m-d'), 1, $cacehTime);
        }
    }

    /**
     * @description: 解除多次登录被限制的账号
     * @param {*} $phone
     * @return {*}
     */  
    public function webClearLoginBan($data)
    {
        if (!isset($data['phone'])) {
            throw new WxException(['msg' => '请输入要解除的手机号码！']);
        }
        $res1 = Cache::delete($data['phone'] . "_login_ban_num"); 
        $res2 = Cache::delete($data['phone'] . "_login_ban_" . date('Y-m-d')); 
        $res3 = Cache::delete($data['phone'] . '_login_ban');
        if ($res1 && $res2 && $res3) {
            return true;
        }else{
            return 0;
        }
    }
}
