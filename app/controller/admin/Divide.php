<?php
// +----------------------------------------------------------------------
// |[ 文档说明: 分销模块Controller]
// +----------------------------------------------------------------------
// | Copyright (c) 2018~2024 http://www.mlhcmk.com All rights reserved.
// +----------------------------------------------------------------------


namespace app\controller\admin;


use app\BaseController;
use app\lib\exceptions\ParamException;
use app\lib\models\MemberVdc;
use app\lib\models\Lecturer;
use app\lib\models\Divide as DivideModel;
use app\Request;
use think\facade\Cache;

class Divide extends BaseController
{
    protected $middleware = [
        'checkAdminToken',
        'checkRule',
        'OperationLog',
    ];

    /**
     * @title  分销规则列表
     * @param MemberVdc $model
     * @return string
     * @throws \Exception
     */
    public function ruleList(MemberVdc $model)
    {
        $list = $model->list($this->requestData);
        return returnData($list['list'], $list['pageTotal']);
    }

    /**
     * @title  分销规则详情
     * @param MemberVdc $model
     * @return string
     */
    public function ruleInfo(MemberVdc $model)
    {
        $info = $model->getMemberRule($this->request->param('level'));
        return returnData($info);
    }

    /**
     * @title  新增分销规则
     * @param MemberVdc $model
     * @return string
     */
    public function ruleCreate(MemberVdc $model)
    {
        $res = $model->new($this->requestData);
        return returnMsg($res);
    }

    /**
     * @title  编辑分销规则
     * @param MemberVdc $model
     * @return string
     */
    public function ruleUpdate(MemberVdc $model)
    {
        $res = $model->edit($this->requestData);
        return returnMsg($res);
    }

    /**
     * @title  订单收益记录列表
     * @param DivideModel $model
     * @return mixed
     * @throws \Exception
     */
    public function list(DivideModel $model)
    {
        $list = $model->list($this->requestData);
        return returnData($list);
    }

    /**
     * @title  获取分润详情
     * @param DivideModel $model
     * @return string
     * @throws \Exception
     */
    public function detail(DivideModel $model)
    {
        $this->validate($this->requestData, ['order_sn' => 'require']);
        $row = $model->recordDetail($this->requestData);

        return returnData($row);
    }

    /**
     * @title  获取分润记录列表
     * @param DivideModel $model
     * @return string
     * @throws \Exception
     */
    public function recordList(DivideModel $model)
    {
        $list = $model->recordList($this->requestData);
        return returnData($list['list'], $list['pageTotal']);
    }

    /**
     * @title  获取分润明细
     * @param DivideModel $model
     * @return string
     * @throws \Exception
     */
    public function recordDetail(DivideModel $model)
    {
        $this->validate($this->requestData, ['order_sn' => 'require']);
        $row = $model->recordDetail($this->requestData);

        return returnData($row);
    }

    /**
     * @title  订单收益列表
     * @param DivideModel $model
     * @return string
     * @throws \Exception
     */
    public function console(DivideModel $model)
    {
//        $data = $model->console($this->requestData);
//        return returnData($data);
        $data = $this->requestData;
        unset($data['page']);
        $data['returnDataType'] = 2;
        $data['dontNeedList'] = true;
        $data['needCache'] = !empty($data['clearCache'] ?? false) ? false : true;

        $info = $model->list($data);
        return returnData($info['summary'] ?? []);
    }

    /**
     * @title  股东奖励列表
     * @param DivideModel $model
     * @return string
     * @throws \Exception
     */
    public function stocksDivideList(DivideModel $model)
    {
        $data = $this->requestData;
        $data['type'] = 9;
        $list = $model->recordList($data);
        return returnData($list['list'], $list['pageTotal']);
    }

}