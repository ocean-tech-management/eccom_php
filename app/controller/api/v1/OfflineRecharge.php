<?php
// +----------------------------------------------------------------------
// |[ 文档说明: ]
// +----------------------------------------------------------------------



namespace app\controller\api\v1;


use app\BaseController;
use app\lib\models\CrowdfundingSystemConfig;
use app\lib\models\OfflineRecharge as OfflineRechargeModel;

class OfflineRecharge extends BaseController
{
    /**
     * @title  用户线下充值提交
     * @param OfflineRechargeModel $model
     * @return string
     */
    public function apply(OfflineRechargeModel $model)
    {
        $res = $model->userSubmit($this->requestData);
        return returnMsg($res);
    }

    /**
     * @title  用户线下充值配置
     * @param CrowdfundingSystemConfig $model
     * @return string
     */
    public function config(CrowdfundingSystemConfig $model)
    {
        $data['searField'] = 2;
        $info = $model->info($data);
        return returnData($info);
    }
}