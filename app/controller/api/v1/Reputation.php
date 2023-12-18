<?php
// +----------------------------------------------------------------------
// |[ 文档说明: 口碑评价模块Model]
// +----------------------------------------------------------------------



namespace app\controller\api\v1;


use app\BaseController;
use app\lib\models\Reputation as ReputationModel;

class Reputation extends BaseController
{
    /**
     * @title  口碑评价官提交评价
     * @param ReputationModel $model
     * @return string
     */
    public function submitAppraise(ReputationModel $model)
    {
        $res = $model->userSubmit($this->requestData);
        return returnMsg($res);
    }
}