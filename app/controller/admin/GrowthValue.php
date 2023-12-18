<?php
// +----------------------------------------------------------------------
// |[ 文档说明: 成长值模块Controller]
// +----------------------------------------------------------------------
// | Copyright (c) 2018~2024 http://www.mlhcmk.com All rights reserved.
// +----------------------------------------------------------------------


namespace app\controller\admin;


use app\BaseController;
use app\lib\models\GrowthValueDetail;
use app\lib\services\GrowthValue as GrowthValueService;
use app\lib\models\User;

class GrowthValue extends BaseController
{
    /**
     * @title  成长值明细列表
     * @param GrowthValueDetail $model
     * @return string
     * @throws \Exception
     */
    public function list(GrowthValueDetail $model)
    {
        $list = $model->list($this->requestData);
        return returnData($list['list'], $list['pageTotal']);
    }

    /**
     * @title  发放成长值
     * @param GrowthValueService $service
     * @return string
     * @throws \Exception
     */
    public function grant(GrowthValueService $service)
    {
        $res = $service->grantGrowthValue(1, $this->requestData);
        return returnMsg($res);
    }

    /**
     * @title  减少成长值
     * @param GrowthValueService $service
     * @return string
     * @throws \Exception
     */
    public function reduce(GrowthValueService $service)
    {
        $res = $service->cancelGrowthValue(1, $this->requestData);
        return returnMsg($res);
    }

    /**
     * @title  所有用户列表
     * @param User $model
     * @return string
     * @throws \Exception
     */
    public function allUserList(User $model)
    {
        $data = $this->requestData;
        $data['needAllLevel'] = true;
        $list = $model->list($data);
        return returnData($list['list'], $list['pageTotal']);
    }
}