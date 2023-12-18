<?php
// +----------------------------------------------------------------------
// |[ 文档说明: 二维码模块Service]
// +----------------------------------------------------------------------
// | Copyright (c) 2018~2024 http://www.mlhcmk.com All rights reserved.
// +----------------------------------------------------------------------


namespace app\lib\services;

use Endroid\QrCode\Color\Color;
use Endroid\QrCode\Encoding\Encoding;
use Endroid\QrCode\ErrorCorrectionLevel;
use Endroid\QrCode\Label\Label;
use Endroid\QrCode\QrCode as QrCodeVendor;
use Endroid\QrCode\Writer\PngWriter;

class QrCode
{
    protected $_qr;                             //Qr-code实例
    protected $_encoding = 'UTF-8';             //编码
    protected $_size = 300;                     //二维码大小(PX)
    protected $_logo = false;                   //是否需要Logo
    protected $_logo_url = '';                  //logo地址
    protected $_logo_size = 80;                 //logo大小
    protected $_title = false;                  //是否需要显示标题
    protected $_title_content = '';             //标题文字内容
    protected $_generate = 'writefile';         //display-直接显示 writefile-写入文件
    protected $_file_upload_path = '';          //文件上传路径
    protected $_domain = false;                 //文件上传域名
    protected $_uploadOss = true;               //是否上传到阿里云OSS
    const MARGIN = 0;                           //二维码内容相对于整张图片的外边距
    const WRITE_NAME = 'png';                   //写入文件的后缀名
    const FOREGROUND_COLOR = ['r' => 0, 'g' => 0, 'b' => 0, 'a' => 0];      //前景色
    const BACKGROUND_COLOR = ['r' => 255, 'g' => 255, 'b' => 255, 'a' => 0];//背景色

    /**
     * QrCode constructor.
     * @param array $config 初始化参数
     */
    public function __construct(array $config = [])
    {
        $this->_generate = $config['generate'] ?? $this->_generate;
        $this->_encoding = $config['encoding'] ?? $this->_encoding;
        $this->_size = $config['size'] ?? $this->_size;
        $this->_logo = $config['logo'] ?? false;
        $this->_logo_size = $config['logo_size'] ?? $this->_logo_size;
        $this->_domain = $config['domain'] ?? config('system.imgDomain');
        $this->_title = $config['title'] ?? false;
        $this->_title_content = $config['title_content'] ?? '';
        $this->_logo_url = $config['logo_url'] ?? app()->getRootPath() . 'public' . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'logo.jpg';
        $this->_file_upload_path = $config['file_upload_path'] ?? app()->getRootPath() . 'public' . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'qr-code';

    }

    /**
     * @title  生成二维码
     * @param string $content 二维码内容
     * @return array|string
     * @throws \Endroid\QrCode\Exception\InvalidPathException
     */
    public function create(string $content)
    {
        if (empty($content)) {
            return false;
        }
        $this->_qr = new QrCodeVendor($content);
        $this->_qr->setSize($this->_size);
//        $this->_qr->setWriterByName(self::WRITE_NAME);
        $this->_qr->setMargin(self::MARGIN);
        $this->_qr->setEncoding(new Encoding($this->_encoding));
        $this->_qr->setErrorCorrectionLevel(new ErrorCorrectionLevel\ErrorCorrectionLevelHigh());
        $this->_qr->setForegroundColor(new Color(0, 0, 0));
        $this->_qr->setBackgroundColor(new Color(255, 255, 255));

        if ($this->_generate == 'display') {
            // 前端调用 例：<img src="http://localhost/qr.php?url=base64_url_string">
            header('Content-Type: ' . $this->_qr->getContentType());
            return $this->_qr->writeString();
        } else if ($this->_generate == 'writefile') {
            return $this->generateImg($this->_file_upload_path, $this->_domain);
        } else {
            return false;
        }
    }

    /**
     * 生成文件
     * @param string $file_path 目录文件 例: /tmp
     * @param bool $domain 返回路径是否需要加域名
     * @return mixed
     */
    public function generateImg(string $file_path, bool $domain = true)
    {
        $file_name = $file_path . DIRECTORY_SEPARATOR . uniqid() . '.' . self::WRITE_NAME;
        if (!file_exists($file_path)) {
            mkdir($file_path, 0777, true);
        }

        try {
            $writer = new PngWriter();
            $result = $writer->write($this->_qr);
            $result->saveToFile($file_name);
//            $this->_qr->writeFile($file_name);
            $file_name = str_replace(app()->getRootPath() . 'public' . DIRECTORY_SEPARATOR, '', $file_name);
            if (!empty($this->_uploadOss)) {
                //上传到OSS
                $ossFileName = str_replace('storage' . DIRECTORY_SEPARATOR, '', $file_name);
                $file_name = (new AlibabaOSS())->uploadFile($ossFileName, 'qr-code');
            } else {
                if ($domain) {
                    $file_name = $this->_domain . $file_name;
                }
            }
            return $file_name;
        } catch (\Exception $e) {
            return $e->getMessage();
        }
    }
}