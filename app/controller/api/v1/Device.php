<?php
// +----------------------------------------------------------------------
// |[ 文档说明: 设备模块Controller]
// +----------------------------------------------------------------------



namespace app\controller\api\v1;


use app\BaseController;
use app\lib\exceptions\UserException;
use app\lib\models\Device as DeviceModel;
use app\lib\models\Divide;
use app\lib\models\HealthyBalanceConver;
use app\lib\models\HealthyBalanceDetail;

class Device extends BaseController
{
    protected $middleware = [
        'checkApiToken',
    ];

    /**
     * @title  创建订单
     * @param DeviceModel $model
     * @return string
     * @throws \Exception
     */
    public function buildOrder(DeviceModel $model)
    {
        $res = $model->buildOrder($this->requestData);
        return returnData($res);
    }

    /**
     * @title  设备详情
     * @param DeviceModel $model
     * @return string
     */
    public function deviceInfo(DeviceModel $model)
    {
        $res = $model->cInfo($this->request->param('device_sn'));
        return returnData($res);
    }

    /**
     * @title  健康豆余额明细
     * @param HealthyBalanceDetail $model
     * @return string
     * @throws \Exception
     */
    public function balanceDetail(HealthyBalanceDetail $model)
    {
        $list = $model->list($this->requestData);
        return returnData($list['list'], $list['pageTotal']);
    }

    /**
     * @title  解析设备二维码跳转地址
     * @param DeviceModel $model
     * @return string
     */
    public function qrCodeRedirectUrl(DeviceModel $model)
    {
        $res = $model->geiDeviceRedirectUrl($this->requestData);
        return returnData($res);
    }

    /**
     * @description: 健康豆跨平台转出记录
     * @param string
     * @return {*}
     */    
    public function balanceConverList(HealthyBalanceConver $model)
    {
        if (empty($this->request->param('uid'))) {
            throw new UserException(['msg' => '用户uid不能为空']);
        }
        return returnData($model->list($this->requestData));
    }
}