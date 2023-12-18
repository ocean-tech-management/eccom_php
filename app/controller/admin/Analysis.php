<?php
// +----------------------------------------------------------------------
// |[ 文档说明: 数据分析模块Controller]
// +----------------------------------------------------------------------
// | Copyright (c) 2018~2024 http://www.mlhcmk.com All rights reserved.
// +----------------------------------------------------------------------


namespace app\controller\admin;


use app\BaseController;
use app\lib\services\TencentDataAnalysis;

class Analysis extends BaseController
{
    protected $middleware = [
        'checkAdminToken',
        'checkRule',
        'OperationLog',
    ];

    /**
     * @title  应用每天的pv\uv\vv\iv数据
     * @param TencentDataAnalysis $service
     * @return string
     */
    public function coreData(TencentDataAnalysis $service)
    {
        $list = $service->coreData($this->requestData);
        return returnData($list);
    }
}