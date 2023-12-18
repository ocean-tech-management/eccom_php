<?php
// +----------------------------------------------------------------------
// |[ 文档说明: 协议模块Controller]
// +----------------------------------------------------------------------


namespace app\controller\api\v1;

use app\BaseController;
use app\lib\models\Agreement as AgreementModel;

class Agreement extends BaseController
{
    /**
     * @title  协议详情
     * @param AgreementModel $model
     * @return string
     */
    public function info(AgreementModel $model)
    {
        $data = $this->requestData;
        $data['cache'] = 'apiAgreementInfo';
        $info = $model->info($data);
        return returnData($info);
    }
}