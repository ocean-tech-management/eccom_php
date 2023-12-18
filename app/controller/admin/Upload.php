<?php
// +----------------------------------------------------------------------
// |[ 文档说明: 上传文件模块Controller]
// +----------------------------------------------------------------------
// | Copyright (c) 2018~2024 http://www.mlhcmk.com All rights reserved.
// +----------------------------------------------------------------------


namespace app\controller\admin;


use app\BaseController;
use app\lib\models\Chapter;
use app\lib\models\File;
use app\lib\services\AlibabaCos;
use app\lib\services\AlibabaOSS;
use app\lib\services\FileUpload;
use app\lib\services\Office;

class Upload extends BaseController
{
    protected $middleware = [
        'checkAdminToken',
        'OperationLog',
    ];

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
        $res = (new FileUpload())->upload($this->request->file('file'), false);
        $res = (new AlibabaOSS())->uploadFile($res);

        if (!empty($res)) {
            $new = $model->new(['type' => $data['type'] ?? 1, 'url' => $res, 'md5' => $data['md5']]);
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

    /**
     * @title  上传微信临时素材
     * @return string
     */
    public function WxTemporaryMaterial()
    {
        $data = $this->requestData;
        $imgUrl = $data['imgUrl'];
        $imgUrl = substr($imgUrl, strrpos($imgUrl, 'uploads/'));
        $res = (new FileUpload())->createWxTemporaryMaterial($imgUrl);
        if (!empty($res)) {
            File::update(['wx_media_id' => $res, 'type' => 1], ['url' => $imgUrl]);
        }
        return returnData($res);
    }

    /**
     * @title  获取资源id获取转码后的文件地址
     * @param string $source_id 资源id
     * @return mixed
     */
    public function source(string $source_id)
    {
        $info = (new File())->where(['source_id' => $source_id, 'status' => 1])->field('id,md5,url,source_id,source_url,cover_url,create_time,update_time')->findOrEmpty()->toArray();
        return returnData($info);
    }

    /**
     * @title  创建视频上传凭证
     * @param AlibabaCos $service
     * @return string
     * @remark 凭证有效期3000s,过期用videoId刷新凭证
     * @throws \AlibabaCloud\Client\Exception\ServerException
     * @throws \AlibabaCloud\Client\Exception\ClientException
     */
    public function getVideoSignature(AlibabaCos $service)
    {
        $res = $service->createUploadVideo($this->request->param('video_name'));
        return returnData($res);
    }

    /**
     * @title  刷新视频上传凭证
     * @param AlibabaCos $service
     * @return string
     * @throws \AlibabaCloud\Client\Exception\ServerException
     * @throws \AlibabaCloud\Client\Exception\ClientException
     */
    public function refreshVideoSignature(AlibabaCos $service)
    {
        $res = $service->refreshUploadVideo($this->request->param('video_id'));
        return returnData($res);
    }

    /**
     * @title  获取客户端上传视频的初始化参数
     * @param AlibabaCos $service
     * @return string
     */
    public function videoConfig(AlibabaCos $service)
    {
        $list = $service->getClientInitVodParam();
        return returnData($list);
    }

    /**
     * @title  重新转码操作
     * @param AlibabaCos $service
     * @return string
     * @throws \AlibabaCloud\Client\Exception\ServerException
     * @throws \AlibabaCloud\Client\Exception\ClientException
     */
    public function submitTranscode(AlibabaCos $service)
    {
        Chapter::update(['update_time' => time()], ['source_id' => $this->request->param('source_id')]);
        $res = $service->submitTranscodeJobs($this->request->param('source_id'));
        return returnMsg($res);
    }

    /**
     * @title  读取excel
     * @return void
     * @throws \PhpOffice\PhpSpreadsheet\Reader\Exception
     * @throws \PhpOffice\PhpSpreadsheet\Exception
     */
    public function importExcel()
    {
        $data = $this->requestData;
        $type = $data['type'] ?? 1;
        $fileUpload = (new FileUpload())->type('excel')->upload($this->request->file('file'), false, 'uploads/office');
        $list = (new Office())->ReadExcel($fileUpload, $type);
        return returnData($list);
    }


    /**
     * 上传视频文件
     * @param File $model
     * @return string
     */
    public function uploadVideo(File $model)
    {
        $data = $this->requestData;
        //如果需要传本地,上传方式换成下面这行代码,并一同修改配置文件中的图片域名地址
        //$res = (new FileUpload())->upload($this->request->file('file'));

        //OSS上传文件
        $res = (new FileUpload())->type('video')->upload($this->request->file('file'), false);

        $res = (new AlibabaOSS())->uploadFile($res);

        if (!empty($res)) {
            $new = $model->new(['type' => 2, 'url' => $res, 'md5' => $data['md5']]);
        }

        return returnData($res);
    }

    /**
     * @title  上传文件App
     * @param File $model
     * @return string
     * @throws \OSS\Core\OssException
     */
    public function uploadApp(File $model)
    {
        $data = $this->requestData;
        //如果需要传本地,上传方式换成下面这行代码,并一同修改配置文件中的图片域名地址
        //$res = (new FileUpload())->upload($this->request->file('file'));

        //OSS上传文件
        $res = (new FileUpload())->type('app')->appointUpload($this->request->file('file'), ($data['file_name'] ?? null), false);
        $res = (new AlibabaOSS())->uploadFile($res);

        if (!empty($res)) {
            $new = $model->new(['type' => $data['type'] ?? 1, 'url' => $res, 'validate' => false]);
        }
        return returnData($res);
    }


    /**
     * @title  保存微信头像到本地及OSS
     * @param array $data
     * @return string
     * @throws \OSS\Core\OssException
     */
    public function saveWxAvatar(array $data)
    {
        $headimgurl = $data['headimgurl'];
        $name = $data['openid'];
        $res = (new FileUpload())->fileDownload($headimgurl, 'wxAvatar', $name);
        $res = (new AlibabaOSS())->uploadFile($res, 'wxAvatar');
        return returnData($res);
    }
}