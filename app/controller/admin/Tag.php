<?php
// +----------------------------------------------------------------------
// |[ 文档说明: 商品标签模块Controller]
// +----------------------------------------------------------------------
// | Copyright (c) 2018~2024 http://www.mlhcmk.com All rights reserved.
// +----------------------------------------------------------------------


namespace app\controller\admin;


use app\BaseController;
use app\lib\models\Tag as TagModel;


class Tag extends BaseController
{
    protected $middleware = [
        'checkAdminToken',
        'checkRule',
        'OperationLog',
    ];

    /**
     * @title  标签列表
     * @param TagModel $model
     * @return string
     * @throws \Exception
     */
    public function list(TagModel $model)
    {
        $list = $model->list($this->requestData);
        return returnData($list['list'], $list['pageTotal']);
    }

    /**
     * @title  标签详情
     * @param TagModel $model
     * @return string
     */
    public function info(TagModel $model)
    {
        $info = $model->info($this->request->param('id'));
        return returnData($info);
    }

    /**
     * @title  新增标签
     * @param TagModel $model
     * @return string
     */
    public function create(TagModel $model)
    {
        $res = $model->new($this->requestData);
        return returnMsg($res);
    }

    /**
     * @title  更新标签
     * @param TagModel $model
     * @return string
     */
    public function update(TagModel $model)
    {
        $res = $model->edit($this->requestData);
        return returnMsg($res);
    }

    /**
     * @title  删除标签
     * @param TagModel $model
     * @return string
     */
    public function delete(TagModel $model)
    {
        $res = $model->del($this->request->param('id'));
        return returnMsg($res);
    }

    /**
     * @title  上/下架标签
     * @param TagModel $model
     * @return string
     */
    public function upOrDown(TagModel $model)
    {
        $res = $model->upOrDown($this->requestData);
        return returnMsg($res);
    }
}