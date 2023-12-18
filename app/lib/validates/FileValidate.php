<?php
// +----------------------------------------------------------------------
// |[ 文档说明: ]
// +----------------------------------------------------------------------
// | Copyright (c) 2018~2024 http://www.mlhcmk.com All rights reserved.
// +----------------------------------------------------------------------


namespace app\lib\validates;


use app\lib\BaseValidate;

class FileValidate extends BaseValidate
{
    public $errorCode = 11002;

    protected $rule = [
        'file|文件' => 'file|fileSize:2048|fileExt:jpeg,jpg,png,gif,mp4,m3u8,3gp',
    ];

    public function sceneVideo()
    {
        return $this->remove('file', 'fileSize|fileExt')
            ->append('file', 'fileSize:5120|fileExt:mp3,mp4,mov');
    }

    public function sceneWord()
    {
        return $this->remove('file', 'fileSize|fileExt')
            ->append('file', 'fileSize:5120|fileExt:doc,docx');
    }

    public function sceneExcel()
    {
        return $this->remove('file', 'fileSize|fileExt')
            ->append('file', 'fileSize:5120|fileExt:xls,xlsx');
    }

    public function sceneOther()
    {
        return $this->remove('file', 'fileSize|fileExt')
            ->append('file', 'fileSize:5120|fileExt:pdf');
    }

}