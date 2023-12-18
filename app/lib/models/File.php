<?php
// +----------------------------------------------------------------------
// |[ 文档说明: ]
// +----------------------------------------------------------------------
// | Copyright (c) 2018~2024 http://www.mlhcmk.com All rights reserved.
// +----------------------------------------------------------------------


namespace app\lib\models;


use app\BaseModel;

class File extends BaseModel
{
    protected $field = ['md5', 'url', 'type', 'status', 'source_id', 'source_url', 'cover_url', 'wx_media_id'];
    protected $validateFields = ['md5', 'url', 'status' => 'number'];

    /**
     * @title  检查文件
     * @param string $md5 文件md5
     * @return mixed
     */
    public function md5(string $md5)
    {
        $info = $this->where(['md5' => $md5, 'status' => 1])->field('type,url,source_url,source_id')->order('create_time desc')->findOrEmpty()->toArray();
        if (empty($info)) {
            return [];
        } else {
            $return['source_id'] = $info['source_id'];
            if (($info['type'] == 1) || ($info['type'] == 2)) {
                $return['url'] = rtrim(config('system.imgDomain'), '/') . $info['url'];
                // $return['url'] = $info['url'];
            } else {
                $return['url'] = $info['source_url'];
            }
        }
        return $return;
    }

    /**
     * @title  新建文件
     * @param array $data
     * @return mixed
     */
    public function new(array $data)
    {
        if (($data['type'] == 1) || ($data['type'] == 2)) {
            $data['url'] = str_replace(config('system.imgDomain'), '/', $data['url']);
        }

        $validate = $data['validate'] ?? true;
        return $this->validate($validate)->baseCreate($data, true);
    }

    public function newOrEdit(array $data)
    {
        return $this->updateOrCreate(['source_id' => $data['source_id'], 'status' => 1], $data);
    }
}