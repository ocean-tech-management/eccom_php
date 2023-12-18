<?php
// +----------------------------------------------------------------------
// |[ 文档说明: 文件上传Service]
// +----------------------------------------------------------------------
// | Copyright (c) 2018~2024 http://www.mlhcmk.com All rights reserved.
// +----------------------------------------------------------------------


namespace app\lib\services;

use app\lib\exceptions\FileException;
use think\facade\Filesystem;

class FileUpload
{
    protected $type = 'image';                   //上传类型 支持image,video,word,excel,other
    protected $rule;                             //文件验证规则
    protected $domain;
    protected $error;
    protected $uploadPath;
    protected $fileRootPath;
    protected $validateType = true;
    protected $defaultAvatarUrl = 'https://oss-jpzx.xmxxs.top/wxAvatar/default.png';

    public function __construct()
    {
        $this->domain = config('system.imgDomain');
        $this->type($this->type);
        $this->uploadPath = 'storage/';
        $this->fileRootPath = app()->getRootPath() . 'public/' . $this->uploadPath;
    }

    /**
     * @title  设置上传文件类型
     * @param string $type 文件类型
     * @return $this
     * @author  Coder
     * @date   2019年12月02日 18:41
     */
    public function type(string $type)
    {
        $this->type = $type;
        $this->rule = $this->config($type);
        return $this;
    }

    /**
     * @title  是否需要校验文件类型或内容等
     * @param bool $needValidate
     * @return $this
     */
    public function validateType(bool $needValidate = true)
    {
        $this->validateType = $needValidate;
        return $this;
    }

    /**
     * @title  上传文件
     * @param mixed $files 所有文件
     * @param bool $domain 返回路径是否需要加域名
     * @param string $path 上传文件名
     * @param string $originalName 保存文件名
     * @return string
     */
    public function upload($files, bool $domain = true, string $path = 'uploads',string $originalName = ""): string
    {
        if (empty($files)) throw new FileException(['errorCode' => 11003]);

        if (!empty($this->validateType)) {
            if (!$this->validate($files)) throw new FileException(['errorCode' => 11002, 'msg' => $this->getError()]);
        }

//        $saveName = Filesystem::disk('public')->putFileAs($path, $files, $this->fileName($files));
        $fileName = !empty(trim($originalName)) ? $originalName :  $this->fileName($files);
        $saveName = Filesystem::disk('public')->putFileAs($path, $files, $fileName);

        if (!empty($saveName)) {
            //补齐域名
            if ($domain) {
                $saveName = substr_replace($saveName, $this->domain . $this->uploadPath, 0, 0);
            }
        }
        return $saveName ?? '';
    }

    /**
     * @title  指定上传文件
     * @param mixed $files 所有文件
     * @param string $fileName 上传文件名
     * @param bool $domain 返回路径是否需要加域名
     * @param string $path 上传文件路径
     * @return string
     * @date   2019年12月02日 18:37
     */
    public function appointUpload($files, string $fileName = "", bool $domain = true, string $path = 'uploads'): string
    {
        if (empty($files)) throw new FileException(['errorCode' => 11003]);

        if (!empty($this->validateType)) {
            if (!$this->validate($files)) throw new FileException(['errorCode' => 11002, 'msg' => $this->getError()]);
        }

        $uploadFileName = $this->fileName($files);
        if (!empty($fileName)) {
            $uploadFileName = $fileName . '.' . $files->getOriginalExtension();
        }
        $saveName = Filesystem::disk('public')->putFileAs($path, $files, $uploadFileName);

        if (!empty($saveName)) {
            //补齐域名
            if ($domain) {
                $saveName = substr_replace($saveName, $this->domain . $this->uploadPath, 0, 0);
            }
        }
        return $saveName ?? '';
    }


    /**
     * @title  获取错误提醒
     * @author  Coder
     * @date   2019年12月02日 18:41
     */
    public function getError()
    {
        return $this->error;
    }

    /**
     * @title  设置单独的文件配置
     * @param array $config
     * @return $this
     */
    public function setConfig(array $config)
    {
        $this->type = $config['type'];
        $this->rule = $config['rule'];
        return $this;
    }

    /**
     * @title  根据类型获取合法配置
     * @param string $type 文件类型
     * @return array
     * @author  Coder
     * @date   2019年12月02日 18:40
     */
    private function config(string $type = 'image'): array
    {
        $config = [
            'image' => ['size' => 1048576 * 5, 'ext' => 'JPG,JPEG,PNG,jpeg,jpg,png,gif'],
            'video' => ['size' => 1048576 * 10, 'ext' => 'mp3,mp4,mov,m3u8,3gp'],
            'word' => ['size' => 1048576 * 3, 'ext' => 'doc,docx'],
            'excel' => ['size' => 1048576 * 5, 'ext' => 'xls,xlsx'],
            'other' => ['size' => 1048576 * 3, 'ext' => 'pdf,zip'],
            'app' => ['size' => 1048576 * 50, 'ext' => 'wgt,apk,WGT,APK'],
        ];
        return $config[$type] ?? [];
    }

    /**
     * @title  验证文件是否合法
     * @param mixed $file 文件
     * @return bool
     * @author  Coder
     * @date   2019年12月02日 18:39
     */
    public function validate($file): bool
    {
        $rule = $this->rule;
        if (empty($file)) throw new FileException(['errorCode' => 11003]);
        if ($rule) {
            $isFile = $file->isFile();
            $size = $file->getSize();
            $ext = $file->getOriginalExtension();
            $name = $file->getOriginalName();
            if (!$isFile) {
                $this->error = $name . ' 为非法上传文件';
                return false;
            }
            if ($size >= $rule['size']) {
//                $this->error = $name.' 最大为 '.($rule['size'] / 1048576).'M';
                $this->error = '文件最大为 ' . ($rule['size'] / 1048576) . 'M';
                return false;
            }
            if (strpos($rule['ext'], $ext) === false) {
                $this->error = $name . ' 的后缀仅允许 ' . $rule['ext'];
                return false;
            }
        }
        return true;
    }

    /**
     * @title  获取上传文件名称
     * @param mixed $file 文件
     * @return string
     * @author  Coder
     * @date   2019年12月02日 18:38
     */
    private function fileName($file): string
    {
        return date('YmdHis') . mt_rand(100000, 999999) . '.' . $file->getOriginalExtension();
    }

    /**
     * @title  新建微信临时素材(小程序)
     * @param string $filePath 文件名称(uploads/xxx.jpg)
     * @return mixed
     * @remark 需要注意区分accessToken,公众号的accessToken才可以新建永久和临时素材,小程序的只能新建临时素材
     */
    public function createWxTemporaryMaterial(string $filePath): string
    {
        $filePath = $this->fileRootPath . ltrim($filePath, '/');
        $filePath = realpath($filePath);
        if (empty($filePath)) {
            throw new FileException();
        }
        $wxService = new Wx();
        $accessToken = $wxService->getAccessToken();
        $type = 'image';
        $requestUrl = config('system.material.createTemporary');
        $requestUrl = sprintf($requestUrl, $accessToken, $type);
        $rData['access_token'] = $accessToken;
        $rData['type'] = $type;
        $files = new \CURLFile($filePath, 'image/jpg');
        $rData['media'] = $files;
        $res = curl_post($requestUrl, $rData);
        $res = json_decode($res, 1);

        return $res['media_id'] ?? null;
    }

    /**
     * @title  保存微信头像到本地及OSS
     * @param array $data
     * @return string
     * @throws \OSS\Core\OssException
     */
    public function saveWxAvatar(array $data)
    {
        $headimgurl = !empty($data['headimgurl']) ? $data['headimgurl'] : $this->defaultAvatarUrl;
        $name = $data['openid'] . '.png';
        $res = $this->fileDownload($headimgurl, 'wxAvatar', $name);
        $res = (new AlibabaOSS())->uploadFile($res, 'wxAvatar');
        return $res;
    }

    /**
     * @title  下载文件到本地
     * @param string $url            文件地址,外网全地址
     * @param string $path           保存目录
     * @param string $assignFileName 保存文件名,带后缀
     * @return string
     */
    public function fileDownload(string $url, string $path = 'wxAvatar', string $assignFileName = '',bool $fullPath= false)
    {
        $ch = curl_init();
        $timeout = 5;
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, $timeout);
        $return_content = curl_exec($ch);
        curl_close($ch);

        $downPath = app()->getRootPath() . 'public' . DIRECTORY_SEPARATOR . $this->uploadPath . $path . DIRECTORY_SEPARATOR;
        //目录如果不存在则新建
        if (!is_dir($downPath)) {
            @mkdir($downPath, 0777);
        }
        if (empty($assignFileName)) {
            $thisFilename = date('YmdHis') . mt_rand(100000, 999999) . '.png';
        } else {
            $thisFilename = $assignFileName;
        }
        $filename = $downPath . $thisFilename;

        $fp = @fopen($filename, "w"); //将文件绑定到流,相同文件名会覆盖
        fwrite($fp, $return_content); //写入文件

        if(empty($fullPath)){
            $filename =  $path . DIRECTORY_SEPARATOR . $thisFilename;
        }
        return $filename;
    }

}