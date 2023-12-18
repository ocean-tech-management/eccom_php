<?php
// +----------------------------------------------------------------------
// |[ 文档说明: 团队奖励模块Controller]
// +----------------------------------------------------------------------
// | Copyright (c) 2018~2024 http://www.mlhcmk.com All rights reserved.
// +----------------------------------------------------------------------


namespace app\controller\admin;


use app\BaseController;
use app\lib\models\Reward as RewardModel;

class Reward extends BaseController
{
    protected $middleware = [
        'checkAdminToken',
        'checkRule',
        'OperationLog',
    ];

    /**
     * @title  规则列表
     * @param RewardModel $model
     * @return string
     * @throws \Exception
     */
    public function list(RewardModel $model)
    {
        $list = $model->list($this->requestData);
        return returnData($list['list'], $list['pageTotal']);
    }

    /**
     * @title  规则详情
     * @param RewardModel $model
     * @return string
     */
    public function info(RewardModel $model)
    {
        $info = $model->info($this->requestData);
        return returnData($info);
    }

    /**
     * @title  新增规则
     * @param RewardModel $model
     * @return string
     */
    public function create(RewardModel $model)
    {
        $res = $model->new($this->requestData);
        return returnMsg($res);
    }

    /**
     * @title  编辑规则
     * @param RewardModel $model
     * @return string
     */
    public function update(RewardModel $model)
    {
        $res = $model->edit($this->requestData);
        return returnMsg($res);
    }

    /**
     * @title  删除规则
     * @param RewardModel $model
     * @return string
     */
    public function delete(RewardModel $model)
    {
        $res = $model->del($this->request->param('id'));
        return returnMsg($res);
    }

    /**
     * @title  等级列表
     * @param RewardModel $model
     * @return string
     * @throws \Exception
     */
    public function level(RewardModel $model)
    {
        $data = $this->requestData;
        $data['searType'] = 2;
        $list = $model->list($data);
        return returnData($list['list'], $list['pageTotal']);
    }
}