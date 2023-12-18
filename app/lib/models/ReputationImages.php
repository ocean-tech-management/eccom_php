<?php
// +----------------------------------------------------------------------
// |[ 文档说明: 商品系统评价(口碑)-图片模块Model]
// +----------------------------------------------------------------------
// | Copyright (c) 2018~2024 http://www.mlhcmk.com All rights reserved.
// +----------------------------------------------------------------------


namespace app\lib\models;


use app\BaseModel;
use think\facade\Db;

class ReputationImages extends BaseModel
{
    protected $validateFields = ['image_url'];

    /**
     * @title  新增或编辑
     * @param array $data
     * @return mixed
     */
    public function newOrEdit(array $data)
    {
        $res = Db::transaction(function () use ($data) {
            $imageDomain = config('system.imgDomain');
            foreach ($data as $key => $value) {
                $save['reputation_id'] = $value['reputation_id'];
                $save['image_url'] = str_replace($imageDomain, '/', $value['image_url']);
                $save['sort'] = intval($value['sort']);
                $dbRes = $this->validate(false)->updateOrCreate([$this->getPk() => $value[$this->getPk()], 'status' => [1, 2]], $save);
            }
            return $dbRes;
        });
        return $res;
    }

    /**
     * @title  删除
     * @param string $id 图片id
     * @return ReputationImages
     */
    public function del(string $id)
    {
        return $this->baseDelete([$this->getPk() => $id]);
    }
}