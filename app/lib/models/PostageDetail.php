<?php
// +----------------------------------------------------------------------
// |[ 文档说明: 运费模版详情模块Model]
// +----------------------------------------------------------------------
// | Copyright (c) 2018~2024 http://www.mlhcmk.com All rights reserved.
// +----------------------------------------------------------------------


namespace app\lib\models;


use app\BaseModel;

class PostageDetail extends BaseModel
{
    protected $validateFields = ['template_code', 'default_number', 'default_price'];

    /**
     * @title  新增/编辑 运费模板城市详情
     * @param array $data
     * @return PostageDetail
     */
    public function DBNewOrEdit(array $data)
    {
        return $this->updateOrCreate([$this->getPk() => $data[$this->getPk()] ?? null], $data);
    }

    /**
     * @title  删除 运费模板城市详情
     * @param string $id
     * @return PostageDetail
     */
    public function DBDelete(string $id)
    {
        return $this->baseDelete([$this->getPk() => $id]);
    }
}