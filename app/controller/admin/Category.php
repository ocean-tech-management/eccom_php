<?php
// +----------------------------------------------------------------------
// |[ 文档说明: 分类模块Controller]
// +----------------------------------------------------------------------
// | Copyright (c) 2018~2024 http://www.mlhcmk.com All rights reserved.
// +----------------------------------------------------------------------


namespace app\controller\admin;


use app\BaseController;
use app\lib\models\Category as CategoryModel;
use app\lib\models\GoodsSku;


class Category extends BaseController
{
    protected $middleware = [
        'checkAdminToken',
        'checkRule',
        'OperationLog',
    ];

    /**
     * @title  全部分类列表
     * @param CategoryModel $model
     * @return string
     * @throws \Exception
     */
    public function list(CategoryModel $model)
    {
        $list = $model->list($this->requestData);
        return returnData($list['list'], $list['pageTotal']);
    }

    /**
     * @title  分类详情
     * @param CategoryModel $model
     * @return string
     */
    public function info(CategoryModel $model)
    {
        $info = $model->info($this->request->param('code'));
        return returnData($info);
    }

    /**
     * @title  创建分类
     * @param CategoryModel $model
     * @return string
     */
    public function create(CategoryModel $model)
    {
        $res = $model->new($this->requestData);
        return returnMsg($res);
    }

    /**
     * @title  更新分类
     * @param CategoryModel $model
     * @return string
     */
    public function update(CategoryModel $model)
    {
        $res = $model->edit($this->requestData);
        return returnMsg($res);
    }

    /**
     * @title  删除分类
     * @param CategoryModel $model
     * @return string
     */
    public function delete(CategoryModel $model)
    {
        $res = $model->del($this->request->param('code'));
        return returnMsg($res);
    }

    /**
     * @title  上/下架分类
     * @param CategoryModel $model
     * @return string
     */
    public function upOrDown(CategoryModel $model)
    {
        $res = $model->upOrDown($this->requestData);
        return returnMsg($res);
    }

    /**
     * @title  父级分类列表
     * @param CategoryModel $model
     * @return string
     * @throws \Exception
     */
    public function pList(CategoryModel $model)
    {
        $list = $model->list($this->requestData);
        return returnData($list['list'], $list['pageTotal']);
    }

    /**
     * @title  更新商品排序
     * @param CategoryModel $model
     * @return string
     */
    public function updateSort(CategoryModel $model)
    {
        $res = $model->updateSort($this->requestData);
        return returnMsg($res);
    }
}