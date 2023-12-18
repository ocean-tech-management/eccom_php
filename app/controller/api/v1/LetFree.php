<?php
// +----------------------------------------------------------------------
// |[ 文档说明: ]
// +----------------------------------------------------------------------



namespace app\controller\api\v1;


use app\BaseController;
use app\lib\services\KuaiShangPay;
use app\lib\services\LetFreePay;

class LetFree extends BaseController
{

    protected $middleware = [
        'checkUser',
        'checkApiToken'
    ];

    /**
     * @title  获取签约地址
     * @param LetFreePay $service
     * @return string
     */
    public function buildContract(LetFreePay $service)
    {
        $info = $service->getContractUrl($this->requestData);
        return returnData($info);
    }

    /**
     * @title  用户签约详情
     * @param LetFreePay $service
     * @return string
     */
    public function contractInfo(LetFreePay $service)
    {
        $data = $this->requestData;
        $data['needVerify'] = true;
        $info = $service->contractInfo($data);
        return returnData($info);
    }
}