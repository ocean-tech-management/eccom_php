<?php
// +----------------------------------------------------------------------
// |[ 文档说明: ]
// +----------------------------------------------------------------------



namespace app\controller\admin;


use app\BaseController;
use app\lib\models\Device as DeviceModel;
use app\lib\models\DeviceCombo;
use app\lib\models\DeviceOrder;
use app\lib\models\Divide;
use app\lib\models\User;
use app\lib\services\QrCode;
use app\lib\services\Device as DeviceService;

class Device extends BaseController
{

    protected $middleware = [
        'checkAdminToken',
        'checkRule',
        'OperationLog',
    ];

    /**
     * @title  设备列表
     * @param DeviceModel $model
     * @return string
     * @throws \Exception
     */
    public function list(DeviceModel $model)
    {
        $list = $model->list($this->requestData);
        return returnData($list['list'], $list['pageTotal']);
    }

    /**
     * @title  设备详情
     * @param DeviceModel $model
     * @return string
     * @throws \Exception
     */
    public function info(DeviceModel $model)
    {
        $info = $model->info($this->requestData);
        return returnData($info);
    }

    /**
     * @title  设备创建
     * @param DeviceModel $model
     * @return string
     * @throws \Exception
     */
    public function create(DeviceModel $model)
    {
        $res = $model->new($this->requestData);
        return returnMsg($res);
    }

    /**
     * @title  设备更新
     * @param DeviceModel $model
     * @return string
     * @throws \Exception
     */
    public function update(DeviceModel $model)
    {
        $res = $model->edit($this->requestData);
        return returnMsg($res);
    }

    /**
     * @title  设备删除
     * @param DeviceModel $model
     * @return string
     * @throws \Exception
     */
    public function delete(DeviceModel $model)
    {
        $res = $model->del($this->request->param('device_sn'));
        return returnMsg($res);
    }

    /**
     * @title  设备后台状态变更
     * @param DeviceModel $model
     * @return string
     * @throws \Exception
     */
    public function status(DeviceModel $model)
    {
        $res = $model->upOrDown($this->requestData);
        return returnMsg($res);
    }

    /**
     * @title  设备实际状态变更
     * @param DeviceModel $model
     * @return string
     * @throws \Exception
     */
    public function deviceStatus(DeviceModel $model)
    {
        $res = $model->deviceUpOrDown($this->requestData);
        return returnMsg($res);
    }


    /**
     * @title  设备订单列表
     * @param DeviceOrder $model
     * @return string
     * @throws \Exception
     */
    public function deviceOrder(DeviceOrder $model)
    {
        $list = $model->list($this->requestData);
        return returnData($list['list'], $list['pageTotal']);

    }

    /**
     * @title  体验中心或区代用户列表
     * @param User $model
     * @return string
     * @throws \Exception
     */
    public function areaOrToggleUserList(User $model)
    {
        $list = $model->areaOrToggleUserList($this->requestData);
        return returnData($list['list'], $list['pageTotal']);
    }

    /**
     * @title  确认完成设备订单
     * @param DeviceModel $model
     * @return string
     */
    public function completeDivideOrder(DeviceModel $model)
    {
        $res = $model->confirmDeviceComplete($this->requestData);
        return returnMsg($res);
    }

    /**
     * @title  设备订单分润记录
     * @param Divide $model
     * @return string
     */
    public function deviceDivideList(Divide $model)
    {
        $list = $model->deviceDivideList($this->requestData);
        return returnData($list['list'], $list['pageTotal']);
    }

    /**
     * @title  生成二维码
     * @param DeviceModel $model
     * @return string
     */
    public function buildQrCode(DeviceModel $model)
    {
        $res = $model->buildDeviceQrCode($this->requestData);
        return returnData($res);
    }

    /**
     * @title  设备套餐列表
     * @param DeviceCombo $model
     * @return string
     * @throws \Exception
     */
    public function comboList(DeviceCombo $model)
    {
        $list = $model->list($this->requestData);
        return returnData($list['list'], $list['pageTotal']);
    }

    /**
     * @title  设备套餐详情
     * @param DeviceCombo $model
     * @return string
     * @throws \Exception
     */
    public function comboInfo(DeviceCombo $model)
    {
        $info = $model->info($this->request->param('combo_sn'));
        return returnData($info);
    }

    /**
     * @title  设备套餐创建/更新
     * @param DeviceCombo $model
     * @return string
     * @throws \Exception
     */
    public function comboUpdate(DeviceCombo $model)
    {
        $res = $model->DBNewOrEdit($this->requestData);
        return returnMsg($res);
    }

    /**
     * @title  设备套餐删除
     * @param DeviceCombo $model
     * @return string
     * @throws \Exception
     */
    public function comboDelete(DeviceCombo $model)
    {
        $res = $model->del($this->request->param('combo_sn'));
        return returnMsg($res);
    }

    /**
     * @title  直接操作智能开关
     * @param DeviceService $service
     * @return string
     * @throws \Exception
     */
    public function operDevicePower(DeviceService $service)
    {
        $res = $service->directChangePower($this->requestData);
        return returnMsg($res);
    }

}