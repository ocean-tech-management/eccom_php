<?php
// +----------------------------------------------------------------------
// |[ 文档说明: 讲师模块Controller]
// +----------------------------------------------------------------------
// | Copyright (c) 2018~2024 http://www.mlhcmk.com All rights reserved.
// +----------------------------------------------------------------------


namespace app\controller\admin;


use app\BaseController;
use app\lib\exceptions\ParamException;
use app\lib\models\Lecturer as LecturerModel;
use app\lib\models\Divide;
use app\lib\models\Withdraw;

class Lecturer extends BaseController
{
    protected $middleware = [
        'checkAdminToken',
        'checkRule',
        'OperationLog',
    ];

    /**
     * @title  讲师列表
     * @param LecturerModel $model
     * @return string
     * @throws \Exception
     */
    public function list(LecturerModel $model)
    {
        $list = $model->list($this->requestData);
        return returnData($list['list'], $list['pageTotal']);
    }

    /**
     * @title  讲师详情
     * @param LecturerModel $model
     * @return string
     * @throws \Exception
     */
    public function info(LecturerModel $model)
    {
        $info = $model->info($this->request->param('id'));
        return returnData($info);
    }

    /**
     * @title  新增讲师
     * @param LecturerModel $model
     * @return string
     */
    public function create(LecturerModel $model)
    {
        $res = $model->new($this->requestData);
        return returnMsg($res);
    }

    /**
     * @title  更新讲师
     * @param LecturerModel $model
     * @return string
     */
    public function update(LecturerModel $model)
    {
        $res = $model->edit($this->requestData);
        return returnMsg($res);
    }

    /**
     * @title  删除讲师
     * @param LecturerModel $model
     * @return string
     */
    public function delete(LecturerModel $model)
    {
        $res = $model->del($this->request->param('id'));
        return returnData($res);
    }

    /**
     * @title  讲师课程列表
     * @param LecturerModel $model
     * @return string
     * @throws \Exception
     */
    public function subject(LecturerModel $model)
    {
        $list = $model->subject($this->request->param('id'));
        return returnData($list);
    }

    /**
     * @title  上/下架
     * @param LecturerModel $model
     * @return string
     */
    public function upOrDown(LecturerModel $model)
    {
        $res = $model->upOrDown($this->requestData);
        return returnMsg($res);
    }

    /**
     * @title  解除讲师关联用户绑定
     * @param LecturerModel $model
     * @return string
     */
    public function untie(LecturerModel $model)
    {
        $res = $model->untieUser($this->request->param('id'));
        return returnMsg($res);
    }

    /**
     * @title  修改讲师密码
     * @param LecturerModel $model
     * @return string
     * @throws \Exception
     */
    public function changePwd(LecturerModel $model)
    {
        $res = $model->changePwd($this->requestData);
        return returnMsg($res);
    }

    /**
     * @title  获取讲师收益汇总
     * @param Divide $model
     * @return string
     * @throws \Exception
     */
    public function allIncome(Divide $model)
    {
        $info = $model->getAllIncomeByUser($this->requestData);
        return returnData($info);
    }

    /**
     * @title  提现列表
     * @param Withdraw $model
     * @return string
     * @throws \Exception
     */
    public function withdrawList(Withdraw $model)
    {
        $data = $this->requestData;
        if (empty($data['uid'])) {
            throw new ParamException();
        }
        $list = $model->list($this->requestData);
        return returnData($list['list'], $list['pageTotal']);
    }
}