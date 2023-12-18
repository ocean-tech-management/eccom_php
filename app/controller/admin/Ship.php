<?php
// +----------------------------------------------------------------------
// |[ 文档说明: 物流模块Controller]
// +----------------------------------------------------------------------
// | Copyright (c) 2018~2024 http://www.mlhcmk.com All rights reserved.
// +----------------------------------------------------------------------


namespace app\controller\admin;


use app\BaseController;
use app\lib\models\City;
use app\lib\models\OperationLog;
use app\lib\models\Order as OrderModel;
use app\lib\models\PostageDetail;
use app\lib\models\PostageTemplate;
use app\lib\models\ShipOrder;
use app\lib\models\ShippingCompany;
use app\lib\models\ShippingDetail;
use app\lib\services\Ship as ShipService;
use app\lib\services\Shipping;

class Ship extends BaseController
{
    protected $middleware = [
        'checkAdminToken',
        'checkRule',
        'OperationLog',
    ];

    /**
     * @title  发货订单列表
     * @param ShipOrder $model
     * @return string
     * @throws \Exception
     */
    public function orderList(ShipOrder $model)
    {
        $data = $this->requestData;
        $data['needNormalKey'] = true;
        $data['needDivideDetail'] = true;
        $data['adminInfo'] = $this->request->adminInfo;
        $list = $model->list($data);
        return returnData($list['list'], $list['pageTotal'], 0, '成功', $list['total'] ?? 0);
    }

    /**
     * @title  同步订单
     * @param ShipOrder $model
     * @return string
     * @throws \Exception
     */
    public function syncOrder(ShipOrder $model)
    {
        set_time_limit(0);
        ini_set('memory_limit', '3072M');
        ini_set('max_execution_time', '0');
        $res = $model->sync($this->requestData);
        return returnMsg($res);
    }

    /**
     * @title  最后同步时间
     * @param OperationLog $model
     * @return string
     * @throws \Exception
     */
    public function syncTime(OperationLog $model)
    {
        $data['path'] = 'ship/sync';
        $type = $this->request->param('type') ?? 1;
        if ($type == 2) {
            $data['pageNumber'] = 2;
            $data['page'] = 1;
        } else {
            $data['justNeedLastOne'] = true;
            $data['pageNumber'] = 1;
            $data['page'] = 1;
        }
        $data['changeCoderName'] = true;
        $info = $model->list($data)['list'] ?? [];
        return returnData($info);
    }

    /**
     * @title  拆分订单
     * @param ShipService $service
     * @return string
     * @throws \Exception
     */
    public function splitOrder(ShipService $service)
    {
        set_time_limit(0);
        ini_set('memory_limit', '3072M');
        ini_set('max_execution_time', '0');
        $res = $service->splitOrder($this->requestData);
        return returnData($res);
    }

    /**
     * @title  合并订单
     * @param ShipService $service
     * @return string
     * @throws \Exception
     */
    public function mergeOrder(ShipService $service)
    {
        $res = $service->mergeOrder($this->requestData);
        return returnData($res);
    }

    /**
     * @title  取消拆单
     * @param ShipService $service
     * @return string
     * @throws \Exception
     */
    public function cancelSplit(ShipService $service)
    {
        $res = $service->cancelSplit($this->requestData);
        return returnData($res);
    }

    /**
     * @title  取消合单
     * @param ShipService $service
     * @return string
     * @throws \Exception
     */
    public function cancelMerge(ShipService $service)
    {
        $res = $service->cancelMerge($this->requestData);
        return returnData($res);
    }

    /**
     * @title  填写/修改物流
     * @param ShipService $service
     * @return string
     */
    public function updateShip(ShipService $service)
    {
        $res = $service->updateShipOrder($this->requestData);
        return returnData($res);
    }

    /**
     * @title  修改收货信息
     * @param ShipService $service
     * @return string
     */
    public function updateShipInfo(ShipService $service)
    {
        $res = $service->updateShipInfo($this->requestData);
        return returnData($res);
    }

    /**
     * @title  修改订单附加状态
     * @param ShipService $service
     * @return string
     */
    public function updateAttach(ShipService $service)
    {
        $res = $service->updateAttachInfo($this->requestData);
        return returnData($res);
    }

    /**
     * @title  发货操作
     * @param ShipService $service
     * @return string
     * @throws \Exception
     */
    public function ship(ShipService $service)
    {
        set_time_limit(0);
        ini_set('memory_limit', '3072M');
        ini_set('max_execution_time', '0');
        $data = $this->requestData;
        $data['adminInfo'] = $this->request->adminInfo;
        $list = $service->ship($data);
        return returnData($list);
    }

    /**
     * @title  无物流发货
     * @param ShipService $service
     * @return string
     * @throws \Exception
     */
    public function noShippingCode(ShipService $service)
    {
        $list = $service->noShippingCode($this->requestData);
        return returnData($list);
    }

    /**
     * @title  自动拆单操作
     * @param ShipService $service
     * @return string
     * @throws \Exception
     */
    public function autoSplitOrder(ShipService $service)
    {
        set_time_limit(0);
        ini_set('memory_limit', '3072M');
        ini_set('max_execution_time', '0');
        $list = $service->autoSplitOrder($this->requestData);
        return returnData($list);
    }

    /**
     * @title  发货订单商品信息汇总面板
     * @param ShipService $service
     * @return string
     * @throws \Exception
     */
    public function orderSummary(ShipService $service)
    {
        $list = $service->shippingOrderSummary($this->requestData);
        return returnData($list);
    }

    /**
     * @title  修改发货的订单备货状态
     * @param ShipService $service
     * @return string
     * @throws \Exception
     */
    public function storing(ShipService $service)
    {
        $res = $service->changeShippingStatus($this->requestData);
        return returnMsg($res);
    }

    /**
     * @title  快递100快递公司编码
     * @param ShippingCompany $model
     * @return string
     * @throws \Exception
     */
    public function companyList(ShippingCompany $model)
    {
        $list = $model->list($this->requestData);
        return returnData($list['list'], $list['pageTotal']);
    }

    /**
     * @title  物流详情
     * @param ShippingDetail $model
     * @return string
     * @throws \Exception
     */
    public function info(ShippingDetail $model)
    {
        $list = $model->list($this->requestData);
        return returnData($list['list'], $list['pageTotal']);
    }

    /**
     * @title  物流统计
     * @param ShippingDetail $model
     * @return string
     * @throws \Exception
     */
    public function summary(ShippingDetail $model)
    {
        $list = $model->summary($this->requestData);
        return returnData($list);
    }

    /**
     * @title  运费模版列表
     * @param PostageTemplate $model
     * @return string
     * @throws \Exception
     */
    public function postageTemplateList(PostageTemplate $model)
    {
        $list = $model->list($this->requestData);
        return returnData($list['list'], $list['pageTotal']);
    }

    /**
     * @title  运费模板详情
     * @param PostageTemplate $model
     * @return string
     * @throws \Exception
     */
    public function postageTemplateInfo(PostageTemplate $model)
    {
        $info = $model->info($this->requestData);
        return returnData($info);
    }

    /**
     * @title  新增运费模版
     * @param PostageTemplate $model
     * @return string
     */
    public function postageTemplateCreate(PostageTemplate $model)
    {
        $res = $model->DBNew($this->requestData);
        return returnData($res);
    }

    /**
     * @title  编辑运费模版
     * @param PostageTemplate $model
     * @return string
     */
    public function postageTemplateUpdate(PostageTemplate $model)
    {
        $res = $model->DBEdit($this->requestData);
        return returnData($res);
    }

    /**
     * @title  删除运费模版
     * @param PostageTemplate $model
     * @return string
     */
    public function postageTemplateDelete(PostageTemplate $model)
    {
        $res = $model->DBDelete($this->requestData);
        return returnData($res);
    }

    /**
     * @title  城市区域列表
     * @param City $model
     * @return string
     * @throws \Exception
     */
    public function city(City $model)
    {
        $data = $this->requestData;
        $data['needCache'] = true;
        $list = $model->list($data);
        return returnData($list);
    }

    /**
     * @title  删除运费模版自定义运费详情
     * @param PostageDetail $model
     * @return string
     */
    public function postageDetailDelete(PostageDetail $model)
    {
        $res = $model->DBDelete($this->request->param('id'));
        return returnData($res);
    }

    /**
     * @title  设置默认运费模板
     * @param PostageTemplate $model
     * @return string
     */
    public function setDefaultTemplate(PostageTemplate $model)
    {
        $res = $model->setDefault($this->request->param('code'));
        return returnMsg($res);
    }


}