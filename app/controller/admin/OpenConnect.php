<?php
// +----------------------------------------------------------------------
// |[ 文档说明: 连接第三方开放平台模块Controller]
// +----------------------------------------------------------------------



namespace app\controller\admin;


use app\BaseController;
use app\lib\services\OpenConnect as OpenConnectService;

class OpenConnect extends BaseController
{
    /**
     * @title  商品列表
     * @param OpenConnectService $service
     * @return string
     */
    public function goodsList(OpenConnectService $service)
    {
        $list = $service->setConfig($this->request->param('appId'))->normalRequest('goodsList',$this->requestData);
        return returnData($list);
    }

    /**
     * @title  商品详情
     * @param OpenConnectService $service
     * @return string
     */
    public function goodsInfo(OpenConnectService $service)
    {
        $list = $service->setConfig($this->request->param('appId'))->normalRequest('goodsInfo',$this->requestData);
        return returnData($list);
    }
}