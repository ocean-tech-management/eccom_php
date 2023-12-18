<?php
// +----------------------------------------------------------------------
// |[ 文档说明: 导出excel权限中间判断控制器Controller]
// +----------------------------------------------------------------------
// | Copyright (c) 2018~2024 http://www.mlhcmk.com All rights reserved.
// +----------------------------------------------------------------------
 

namespace app\controller\admin;


use app\BaseController;
use app\lib\constant\WithdrawConstant;
use app\lib\exceptions\ServiceException;
use app\lib\models\AfterSale as AfterSaleModel;
use app\lib\models\BalanceDetail;
use app\lib\models\CrowdfundingBalanceDetail;
use app\lib\models\Export;
use app\lib\models\GoodsSpu;
use app\lib\models\Order as OrderModel;
use app\lib\models\Divide as DivideModel;
use app\lib\models\PpylOrder;
use app\lib\models\PpylReward;
use app\lib\models\PpylWaitOrder;
use app\lib\models\PropagandaReward;
use app\lib\models\ShipOrder;
use app\lib\models\Withdraw;
use think\facade\Cache;

class Excel extends BaseController
{
    protected $middleware = [
        'checkAdminToken',
        'checkRule',
        'OperationLog',
    ];

    /**
     * @title  导出订单列表数据
     * @param OrderModel $model
     * @return string
     * @throws \Exception
     */
    public function order(OrderModel $model)
    {
        set_time_limit(0);
        ini_set('memory_limit', '3072M');
        ini_set('max_execution_time', '0');
        $data = $this->requestData;
        $data['needDivideChain'] = true;
        $data['needGoods'] = true;
        $data['needGoodsCode'] = true;
//        $data['needGoodsShippingCode'] = true;
        $list = $model->list($data);
        $data['adminInfo'] = $this->request->adminInfo;
        return returnData($list['list'], $list['pageTotal']);
    }


    /**
     * @title  导出分润记录列表数据
     * @param DivideModel $model
     * @return string
     * @throws \Exception
     */
    public function divide(DivideModel $model)
    {
        set_time_limit(0);
        ini_set('memory_limit', '3072M');
        ini_set('max_execution_time', '0');
        $list = $model->recordList($this->requestData);
        return returnData($list['list'], $list['pageTotal']);
    }

    /**
     * @title  导出收益列表数据
     * @param DivideModel $model
     * @return string
     * @throws \Exception
     */
    public function incomeList(DivideModel $model)
    {
        set_time_limit(0);
        ini_set('memory_limit', '3072M');
        ini_set('max_execution_time', '0');
        $list = $model->list($this->requestData);
        return returnData($list);
    }

    /**
     * @title  导出提现记录列表数据
     * @param Withdraw $model
     * @return string
     * @throws \Exception
     */
    public function withdraw(Withdraw $model)
    {
        set_time_limit(0);
        ini_set('memory_limit', '3072M');
        ini_set('max_execution_time', '0');
        $list = $model->list($this->requestData);
        return returnData(['list' => $list['list'], 'code_no' => $list['code_no'], 'pageTotal' => $list['pageTotal']]);
    }

    /**
     * @title  导出提现记录列表数据
     * @param Withdraw $model
     * @return string
     * @throws \Exception
     */
    public function withdrawExport(Withdraw $model)
    {
        set_time_limit(0);
        ini_set('memory_limit', '3072M');
        ini_set('max_execution_time', '0');
        $data = $this->requestData;
        $data['admin_id'] = (int)$this->request->adminId;
        $url = $model->export($data);
        return returnData($url);
    }

    /**
     * 获取导出文件的密码
     * @param Withdraw $model
     * @return string
     * @throws \Exception
     */
    public function withdrawExportPwd(Withdraw $model)
    {
        $pwd = $model->getFilePassword($this->requestData);
        return returnData(['code' => $pwd]);
    }

    /**
     * 获取最近的导出文件记录
     * @param Export $model
     * @return string
     * @throws \Exception
     */
    public function WithdrawExportList(Export $model)
    {
        $list = $model->list($this->requestData);
        return returnData($list);
    }

    /**
     * 获取导出文件模板的标题和字段
     * @param \app\lib\services\Export $service
     * @return string
     */
    public function getExportField(\app\lib\services\Export $service)
    {
        $info = $service->getExportField($this->requestData['type'] ?? 0);
        return returnData(['field' => $info['field'], 'start' => $info['start']]);
    }

    /**
     * @title  发货订单列表
     * @param ShipOrder $model
     * @return string
     * @throws \Exception
     */
    public function orderList(ShipOrder $model)
    {
        set_time_limit(0);
        ini_set('memory_limit', '3072M');
        ini_set('max_execution_time', '0');
        $data = $this->requestData;
        $data['needNormalKey'] = true;
        $data['needDivideDetail'] = true;
        $data['needUserDetail'] = true;
        $data['searType'] = $data['searType'] ?? [2];
        $data['afterType'] = [1, 5, -1];
        if (!empty($data['searGoodsSkuSn']) && (in_array('4888004901', $data['searGoodsSkuSn']) || in_array('5393492701', $data['searGoodsSkuSn']))) {
            $data['afterType'] = [1, 4, 5, -1];
        }
        $data['adminInfo'] = $this->request->adminInfo;
        $list = $model->list($data);
        return returnData($list['list'], $list['pageTotal']);
    }

    /**
     * @title  会员个人业绩汇总
     * @param DivideModel $model
     * @return string
     * @throws \Exception
     */
    public function memberPerformance(DivideModel $model)
    {
        set_time_limit(0);
        ini_set('memory_limit', '3072M');
        ini_set('max_execution_time', '0');
        $data = $this->requestData;
        $list = $model->memberSummary($data);
        return returnData($list['list'], $list['pageTotal']);
    }

    /**
     * @title  售后申请列表
     * @param AfterSaleModel $model
     * @return string
     * @throws \Exception
     */
    public function afterSaleList(AfterSaleModel $model)
    {
        set_time_limit(0);
        ini_set('memory_limit', '3072M');
        ini_set('max_execution_time', '0');
        $list = $model->list($this->requestData);
        return returnData($list['list'], $list['pageTotal'], 0, '成功', $list['total'] ?? 0);
    }

    /**
     * @title  用户列表(包含用户自购订单数据)
     * @param OrderModel $model
     * @return string
     * @throws \Exception
     */
    public function userSelfBuyOrder(OrderModel $model)
    {
        set_time_limit(0);
        ini_set('memory_limit', '3072M');
        ini_set('max_execution_time', '0');
        $list = $model->userSelfBuyOrder($this->requestData);
        return returnData($list['list'], $list['pageTotal']);
    }

    /**
     * @title  拼拼有礼订单列表
     * @param PpylOrder $model
     * @return string
     * @throws \Exception
     */
    public function ppylOrder(PpylOrder $model)
    {
        set_time_limit(0);
        ini_set('memory_limit', '3072M');
        ini_set('max_execution_time', '0');
        $list = $model->list($this->requestData);
        return returnData($list['list'], $list['pageTotal']);
    }


    /**
     * @title  拼拼有礼奖励订单列表(导出)
     * @param PpylReward $model
     * @return string
     * @throws \Exception
     */
    public function ppylReward(PpylReward $model)
    {
        set_time_limit(0);
        ini_set('memory_limit', '3072M');
        ini_set('max_execution_time', '0');
        $list = $model->exportList($this->requestData);
        return returnData($list['list'], $list['pageTotal']);
    }

    /**
     * @title  商品列表(导出)
     * @param GoodsSpu $model
     * @return string
     * @throws \Exception
     */
    public function spuList(GoodsSpu $model)
    {
        $data = $this->requestData;
        $data['adminInfo'] = $this->request->adminInfo;
        $data['needActivity'] = true;
        $list = $model->list($data);
        return returnData($list['list'], $list['pageTotal'], 0, '成功', $list['total'] ?? 0);
    }

    /**
     * @title  广宣奖奖励列表 (导出)
     * @param DivideModel $model
     * @return string
     */
    public function propagandaRewardList(DivideModel $model)
    {
        $data = $this->requestData;
        $list = $model->PropagandaRewardList($data);
        return returnData($list['list'], $list['pageTotal'], 0, '成功', $list['total'] ?? 0);
    }

    /**
     * @title  导出用户余额明细
     * @param BalanceDetail $model
     * @return string
     * @throws \Exception
     */
    public function userBalance(BalanceDetail $model)
    {
        $data = $this->requestData;
        $list = $model->list($data);
        return returnData($list['list'], $list['pageTotal']);
    }

    /**
     * @title  导出用户美丽金余额明细
     * @param CrowdfundingBalanceDetail $model
     * @return string
     * @throws \Exception
     */
    public function userCrowdFundingBalance(CrowdfundingBalanceDetail $model)
    {
        $list = $model->list($this->requestData);
        return returnData($list['list'], $list['pageTotal']);
    }

    /**
     * @title  用戶股东奖励列表
     * @param DivideModel $model
     * @return string
     * @throws \Exception
     */
    public function userStocksDivideList(DivideModel $model)
    {
        $data = $this->requestData;
        $data['type'] = 9;
        $list = $model->recordList($data);
        return returnData($list['list'], $list['pageTotal']);
    }



}