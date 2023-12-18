<?php
// +----------------------------------------------------------------------
// |[ 文档说明: 商品系统评价(口碑评价)模块Controller]
// +----------------------------------------------------------------------
// | Copyright (c) 2018~2024 http://www.mlhcmk.com All rights reserved.
// +----------------------------------------------------------------------


namespace app\controller\admin;


use app\BaseController;
use app\lib\models\Reputation as ReputationModel;
use app\lib\models\ReputationImages;
use app\lib\models\ReputationUser;


class Reputation extends BaseController
{
    protected $middleware = [
        'checkAdminToken',
        'checkRule',
        'OperationLog',
    ];

    /**
     * @title  口碑评价列表
     * @param ReputationModel $model
     * @return string
     * @throws \Exception
     */
    public function list(ReputationModel $model)
    {
        $list = $model->list($this->requestData);
        return returnData($list['list'], $list['pageTotal'], 0, '成功', $list['total']);
    }

    /**
     * @title  口碑评价详情
     * @param ReputationModel $model
     * @return string
     */
    public function info(ReputationModel $model)
    {
        $info = $model->info($this->request->param('id'));
        return returnData($info);
    }

    /**
     * @title  新增口碑评价
     * @param ReputationModel $model
     * @return string
     */
    public function create(ReputationModel $model)
    {
        $res = $model->new($this->requestData);
        return returnMsg($res);
    }

    /**
     * @title  更新口碑评价
     * @param ReputationModel $model
     * @return string
     */
    public function update(ReputationModel $model)
    {
        $res = $model->edit($this->requestData);
        return returnMsg($res);
    }

    /**
     * @title  审核口碑评价
     * @param ReputationModel $model
     * @return string
     */
    public function check(ReputationModel $model)
    {
        $res = $model->check($this->requestData);
        return returnMsg($res);
    }


    /**
     * @title  删除口碑评价
     * @param ReputationModel $model
     * @return string
     */
    public function delete(ReputationModel $model)
    {
        $res = $model->del($this->request->param('id'));
        return returnMsg($res);
    }

    /**
     * @title  上/下架口碑评价
     * @param ReputationModel $model
     * @return string
     */
    public function status(ReputationModel $model)
    {
        $res = $model->upOrDown($this->requestData);
        return returnMsg($res);
    }

    /**
     * @title  置顶口碑评价
     * @param ReputationModel $model
     * @return string
     */
    public function top(ReputationModel $model)
    {
        $res = $model->top($this->requestData);
        return returnMsg($res);
    }

    /**
     * @title  修改口碑评价排序
     * @param ReputationModel $model
     * @return string
     */
    public function sort(ReputationModel $model)
    {
        $res = $model->updateSort($this->requestData);
        return returnMsg($res);
    }

    /**
     * @title  加/取精口碑评价
     * @param ReputationModel $model
     * @return string
     */
    public function featured(ReputationModel $model)
    {
        $res = $model->featured($this->requestData);
        return returnMsg($res);
    }

    /**
     * @title  删除图片
     * @param ReputationImages $model
     * @return string
     */
    public function imagesDelete(ReputationImages $model)
    {
        $res = $model->del($this->request->param('id'));
        return returnMsg($res);
    }

    /**
     * @title  口碑评价官列表
     * @param ReputationUser $model
     * @return string
     * @throws \Exception
     */
    public function userList(ReputationUser $model)
    {
        $list = $model->list($this->requestData);
        return returnData($list['list'], $list['pageTotal'], 0, '成功', $list['total']);
    }

    /**
     * @title  口碑评价官详情
     * @param ReputationUser $model
     * @return string
     */
    public function userInfo(ReputationUser $model)
    {
        $info = $model->info($this->request->param('id'));
        return returnData($info);
    }

    /**
     * @title  新增口碑评价官
     * @param ReputationUser $model
     * @return string
     */
    public function userCreate(ReputationUser $model)
    {
        $res = $model->new($this->requestData);
        return returnMsg($res);
    }

    /**
     * @title  更新口碑评价官
     * @param ReputationUser $model
     * @return string
     */
    public function userUpdate(ReputationUser $model)
    {
        $res = $model->edit($this->requestData);
        return returnMsg($res);
    }

    /**
     * @title  删除口碑评价官
     * @param ReputationUser $model
     * @return string
     */
    public function userDelete(ReputationUser $model)
    {
        $res = $model->del($this->request->param('user_code'));
        return returnMsg($res);
    }

    /**
     * @title  上/下架口碑评价官
     * @param ReputationUser $model
     * @return string
     */
    public function userStatus(ReputationUser $model)
    {
        $res = $model->upOrDown($this->requestData);
        return returnMsg($res);
    }
}