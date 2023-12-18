<?php
// +----------------------------------------------------------------------
// |[ 文档说明: 系统配置模块控制器]
// +----------------------------------------------------------------------



namespace app\controller\api\v1;


use app\BaseController;
use app\lib\models\SystemConfig;

class System extends BaseController
{
    /**
     * @title  系统配置信息详情
     * @param SystemConfig $model
     * @return string
     */
    public function info(SystemConfig $model)
    {
        $info = $model->cInfo($this->requestData);
        return returnData($info);
    }

    /**
     * @title  获取客户端海报背景图等
     * @param SystemConfig $model
     * @return string
     */
    public function clientGg(SystemConfig $model)
    {
        $info = $model->getBackGround($this->requestData);
        return returnData($info);
    }
}