<?php
// +----------------------------------------------------------------------
// |[ 文档说明: 阿里云OSS]
// +----------------------------------------------------------------------
// | Copyright (c) 2018~2024 http://www.mlhcmk.com All rights reserved.
// +----------------------------------------------------------------------


namespace app\lib\services;


use app\lib\exceptions\FileException;
use OSS\Http\RequestCore;
use OSS\Http\ResponseCore;
use OSS\OssClient;

class AlibabaOSS
{
    private $accessKeyId;
    private $accessKeySecret;
    //private $endpoint = 'http://oss-cn-shenzhen.aliyuncs.com/';
    private $endpoint;
    private $bucket;
    private $ossClient;
    private $defaultFilePath;

    /**
     * AlibabaOSS constructor.
     * @throws \OSS\Core\OssException
     */
    public function __construct()
    {
        $this->initOssClient();
        $this->defaultFilePath = app()->getRootPath() . 'public/storage/';
    }

    /**
     * @title  初始化OSS客户端
     * @return void
     * @throws \OSS\Core\OssException
     */
    public function initOssClient()
    {
        $config = config('system.oss');
        $accessKeyId = $config['accessKeyId'];
        $accessKeySecret = $config['accessKeySecret'];
        $this->accessKeyId = $accessKeyId;
        $this->accessKeySecret = $accessKeySecret;
        $this->endpoint = $config['endpoint'];
        $this->bucket = $config['bucket'];
        $ossClient = new OssClient($this->accessKeyId, $this->accessKeySecret, $this->endpoint, true);
        $this->ossClient = $ossClient;
    }

    /**
     * @title  上传文件
     * @param string $filePath 文件路径(/uploads/test.image)
     * @param string $uploadPathName 上传目录文件夹名称
     * @param string $deal 图片处理参数
     * @return mixed
     */
    public function uploadFile(string $filePath, string $uploadPathName = null, string $deal = null)
    {
        $filePath = $this->defaultFilePath . $filePath;
        $filePath = realpath($filePath);
        if (empty($filePath)) {
            throw new FileException();
        }
        //获取文件后缀
        $extension = substr(strrchr($filePath, '.'), 1);
        //获取文件对应的文件夹目录
        $uploadPath = !empty($uploadPathName) ? $uploadPathName : $this->fileType($extension, 2) ?? 'unknown';
        $objectName = $uploadPath . '/' . substr(strrchr($filePath, DIRECTORY_SEPARATOR), 1);
        //设置签名
        $this->ossClient->signUrl($this->bucket, $objectName, 3600, 'PUT');
//        $res = $this->ossClient->uploadFile($this->bucket, $objectName, $filePath);
        $content = file_get_contents($filePath);
        $res = $this->ossClient->putObject($this->bucket, $objectName, $content);
        $log['upload'] = $res;

        $url = null;
        if (!empty($res)) {
            $url = $res['oss-request-url'] ?? ($res['info']['url'] ?? null);
        }

        //将图片处理后转存
        if (!empty($deal)) {
            $style = $deal;
            // 将处理后的图片转存到当前Bucket
            $process = $style .
                '|sys/saveas' .
                ',o_' . $this->base64url_encode($objectName) .
                ',b_' . $this->base64url_encode($this->bucket);
            $dealRes = $this->ossClient->processObject($this->bucket, $objectName, $process);
        }
        $log['deal'] = $dealRes ?? [];
//        (new Log())->record($log);

        return $url;
    }

    /**
     * @title  查找文件类型
     * @param string|null $fileType 搜索文件分类|后缀
     * @param int $searType 搜索类型 1为搜索分类对应的后缀 2为搜索后缀对应的分类
     * @return array|mixed
     */
    public function fileType(string $fileType = null, int $searType = 1)
    {
        $config = [
            'image' => ['ext' => 'jpeg,jpg,png,gif,JPG,JPEG,GIF,PNG'],
            'video' => ['ext' => 'mp3,mp4,mov'],
            'word' => ['ext' => 'doc,docx'],
            'excel' => ['ext' => 'xls,xlsx'],
            'other' => ['ext' => 'pdf,zip'],
            'log' => ['ext' => 'log'],
            'app' => ['ext' => 'wgt,apk,WGT,APK'],
        ];

        $res = [];
        if (!empty($fileType)) {
            switch ($searType) {
                case 1:
                    $res = $config[$fileType]['ext'] ?? [];
                    break;
                case 2:
                    $res = null;
                    foreach ($config as $key => $value) {
                        if (in_array($fileType, explode(',', $value['ext']))) {
                            $res = $key;
                            break;
                        }

                    }
                    break;
            }
        }

        return $res;
    }

    public function base64url_encode($data)
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

}