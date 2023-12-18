<?php
// +----------------------------------------------------------------------
// |[ 文档说明: 阿里云视频点播COSService]
// +----------------------------------------------------------------------
// | Copyright (c) 2018~2024 http://www.mlhcmk.com All rights reserved.
// +----------------------------------------------------------------------


namespace app\lib\services;


use AlibabaCloud\Client\AlibabaCloud;
use AlibabaCloud\Client\Exception\ClientException;
use AlibabaCloud\Client\Exception\ServerException;
use AlibabaCloud\Vod\Vod;

require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'VodUploadSDK' . DIRECTORY_SEPARATOR . 'voduploadsdk' . DIRECTORY_SEPARATOR . 'Autoloader.php';

class AlibabaCos
{
    private $client = 'AliyunVodClient';
    private $userId;
    private $regionId = 'cn-shanghai';
    private $videoDomain;
    private $videoTemplateGroupId;
    private $accessKeyId;
    private $accessKeySecret;

    /**
     * AlibabaCos constructor.
     * @throws ClientException
     */
    public function __construct()
    {
        $this->initVodClient();
    }

    /**
     * @title  初始化
     * @return void
     * @throws ClientException
     */
    public function initVodClient()
    {
        $config = config('system.sms');
        $accessKeyId = $config['accessKeyId'];
        $accessKeySecret = $config['accessKeySecret'];
        $regionId = 'cn-shanghai';  // 点播服务接入区域
        AlibabaCloud::accessKeyClient($accessKeyId, $accessKeySecret)
            ->regionId($regionId)
            ->connectTimeout(1)
            ->timeout(3)
            ->name($this->client);
        $this->userId = $config['userId'];
        $this->videoDomain = $config['videoDomain'];
        $this->videoTemplateGroupId = $config['videoTemplateGroupId'];
        $this->accessKeyId = $accessKeyId;
        $this->accessKeySecret = $accessKeySecret;
    }

    /**
     * @title  获取客户端上传视频的初始化参数
     * @return array
     */
    public function getClientInitVodParam(): array
    {
        $param['userId'] = $this->userId;
        $param['videoDomain'] = $this->videoDomain;
        $param['region'] = $this->regionId;
        return $param;
    }

    /**
     * @title  创建视频上传凭证
     * @param string $videoName 视频名称
     * @return \AlibabaCloud\Client\Result\Result|mixed
     * @remark 凭证有效期3000s,过期用videoId刷新凭证
     * @throws ServerException
     * @throws ClientException
     */
    public function createUploadVideo(string $videoName)
    {
        $callbackUrl['CallbackType'] = 'http';
        $callbackUrl['CallbackURL'] = config('system.callback.cosCallBackUrl');
        $userData['MessageCallback'] = $callbackUrl;
        $userData['Extend'] = config('system.projectNameAbbr');
        $title = str_replace(strrchr($videoName, "."), "", $videoName);
        $res = Vod::v20170321()->createUploadVideo()
            ->withFileName($videoName)
            ->withTitle($title)
            ->withUserData(json_encode($userData))
            ->withTemplateGroupId($this->videoTemplateGroupId)
            ->client($this->client)->format('JSON')->request();
        $res = json_decode($res, 1);
        return $res;
    }

    /**
     * @title  刷新视频上传凭证
     * @param string $videoId 视频id
     * @return \AlibabaCloud\Client\Result\Result|mixed
     * @throws ServerException
     * @throws ClientException
     */
    public function refreshUploadVideo(string $videoId)
    {
        $res = Vod::v20170321()->refreshUploadVideo()->withVideoId($videoId)->client($this->client)->format('JSON')->request();
        $res = json_decode($res, 1);
        return $res;
    }

    /**
     * @title  获取视频信息
     * @param string $videoId
     * @return \AlibabaCloud\Client\Result\Result
     * @throws ServerException
     * @throws ClientException
     */
    public function getVideoInfo(string $videoId)
    {
        $res = Vod::v20170321()->getVideoInfo()->withVideoId($videoId)->client($this->client)->format('JSON')->request();
        $res = json_decode($res, 1);
        return $res;
    }

    /**
     * @title  提交媒体转码作业
     * @param string $videoId
     * @return \AlibabaCloud\Client\Result\Result|mixed
     * @throws ServerException
     * @throws ClientException
     */
    public function submitTranscodeJobs(string $videoId)
    {
        $callbackUrl['CallbackType'] = 'http';
        $callbackUrl['CallbackURL'] = config('system.callback.cosCallBackUrl');
        $userData['MessageCallback'] = $callbackUrl;
        $userData['Extend'] = 'tcmTranscodeJobs';
        $res = Vod::v20170321()->submitTranscodeJobs()->withVideoId($videoId)->withTemplateGroupId($this->videoTemplateGroupId)->withUserData(json_encode($userData))->client($this->client)->format('JSON')->request();
        $res = json_decode($res, 1);
        return $res;
    }

    public function createUploadImage(string $videoName)
    {
        $callbackUrl['CallbackType'] = 'http';
        $callbackUrl['CallbackURL'] = config('system.callback.cosCallBackUrl');
        $userData['MessageCallback'] = $callbackUrl;
        $userData['Extend'] = config('system.projectNameAbbr');
        $title = str_replace(strrchr($videoName, "."), "", $videoName);
        $res = Vod::v20170321()->createUploadImage()
            ->withTitle($title)
            ->withUserData(json_encode($userData))->request();
        $res = json_decode($res, 1);
        return $res;
    }

    /**
     * @title  图片路径
     * @param string $filePath
     * @return mixed
     * @throws \Exception
     */
    public function uploadImage(string $filePath)
    {
        $uploader = new \AliyunVodUploader($this->accessKeyId, $this->accessKeySecret);
        $title = str_replace(strrchr($filePath, "."), "", $filePath);
        $uploadImageRequest = new \UploadImageRequest($filePath, 'testUploadLocalImage via PHP-SDK');
        $res = $uploader->uploadLocalImage($uploadImageRequest);
        return $res;
    }
}