<?php
// +----------------------------------------------------------------------
// |[ 文档说明: 品牌模块Controller]
// +----------------------------------------------------------------------
// | Copyright (c) 2018~2024 http://www.mlhcmk.com All rights reserved.
// +----------------------------------------------------------------------


namespace app\controller\admin;


use app\BaseController;
use app\lib\models\Brand as BrandModel;


class Brand extends BaseController
{
    protected $middleware = [
        'checkAdminToken',
        'checkRule',
        'OperationLog',
    ];

    /**
     * @title  全部品牌列表
     * @param BrandModel $model
     * @return string
     * @throws \Exception
     */
    public function list(BrandModel $model)
    {
        $list = $model->list($this->requestData);
        return returnData($list['list'], $list['pageTotal']);
    }

    /**
     * @title  品牌详情
     * @param BrandModel $model
     * @return string
     */
    public function info(BrandModel $model)
    {
        $info = $model->info($this->request->param('brand_code'));
        return returnData($info);
    }

    /**
     * @title  创建品牌
     * @param BrandModel $model
     * @return string
     */
    public function create(BrandModel $model)
    {
        $res = $model->new($this->requestData);
        return returnMsg($res);
    }

    /**
     * @title  更新品牌
     * @param BrandModel $model
     * @return string
     */
    public function update(BrandModel $model)
    {
        $res = $model->edit($this->requestData);
        return returnMsg($res);
    }

    /**
     * @title  删除品牌
     * @param BrandModel $model
     * @return string
     */
    public function delete(BrandModel $model)
    {
        $res = $model->del($this->request->param('brand_code'));
        return returnMsg($res);
    }

    /**
     * @title  上/下架品牌
     * @param BrandModel $model
     * @return string
     */
    public function upOrDown(BrandModel $model)
    {
        $res = $model->upOrDown($this->requestData);
        return returnMsg($res);
    }
}