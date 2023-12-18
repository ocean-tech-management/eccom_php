<?php
// +----------------------------------------------------------------------
// |[ 文档说明: ]
// +----------------------------------------------------------------------
// | Copyright (c) 2018~2024 http://www.mlhcmk.com All rights reserved.
// +----------------------------------------------------------------------
 

namespace app\controller\api\v1;


use app\BaseController;
use app\lib\models\File;
use app\lib\services\AlibabaOSS;
use app\lib\services\FileUpload;

class Upload extends BaseController
{
//    protected $middleware = [
//        'checkApiToken',
//    ];

    /**
     * @title  上传文件
     * @param File $model
     * @return string
     * @throws \OSS\Core\OssException
     */
    public function upload(File $model)
    {
        $data = $this->requestData;
        //如果需要传本地,上传方式换成下面这行代码,并一同修改配置文件中的图片域名地址
        //$res = (new FileUpload())->upload($this->request->file('file'));

        //OSS上传文件
        $res = (new FileUpload())->setConfig(['type' => 'image', 'rule' => ['size' => 1048576 * 10, 'ext' => 'jpeg,jpg,png,gif']])->upload($this->request->file('file'), false);
        $res = (new AlibabaOSS())->uploadFile($res);

        if (!empty($res)) {
            $new = $model->new(['type' => $data['type'] ?? 1, 'url' => $res, 'validate' => false]);
        }
        return returnData($res);
    }

    /**
     * @title  上传证件文件
     * @param File $model
     * @return string
     * @throws \OSS\Core\OssException
     */
    public function uploadCertificate(File $model)
    {
        $data = $this->requestData;
        //如果需要传本地,上传方式换成下面这行代码,并一同修改配置文件中的图片域名地址
        //$res = (new FileUpload())->upload($this->request->file('file'));

        //加上图片水印,透明度80%,居中
        $dealParam = 'image/watermark,image_d2F0ZXJtYXJrL3dhdGVybWFyay5wbmc,t_80,g_center';
        //OSS上传文件
        $res = (new FileUpload())->upload($this->request->file('file'), false);
        $res = (new AlibabaOSS())->uploadFile($res, null, $dealParam);

        if (!empty($res)) {
            $new = $model->new(['type' => $data['type'] ?? 1, 'url' => $res, 'validate' => false]);
        }
        return returnData($res);
    }

    /**
     * @title  创建文件
     * @param File $model
     * @return string
     */
    public function create(File $model)
    {
        $res = $model->new($this->requestData);
        return returnMsg($res);
    }

    /**
     * @title  查看文件是否上传过
     * @param File $model
     * @return string
     */
    public function read(File $model)
    {
        $res = $model->md5($this->request->param('md5'));
        return returnData($res);
    }
}