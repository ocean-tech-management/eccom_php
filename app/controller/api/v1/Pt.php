<?php
// +----------------------------------------------------------------------
// |[ 文档说明: 拼团模块]
// +----------------------------------------------------------------------
// | Copyright (c) 2018~2024 http://www.mlhcmk.com All rights reserved.
// +----------------------------------------------------------------------
 

namespace app\controller\api\v1;


use app\BaseController;
use app\lib\models\PtActivity;
use app\lib\models\PtGoods;
use app\lib\models\PtOrder;
use app\lib\services\Pt as PtService;

class Pt extends BaseController
{
    /**
     * @title  开团前检测
     * @param PtService $service
     * @return string
     * @throws \Exception
     */
    public function startPtCheck(PtService $service)
    {
        $res = $service->startPtActivityCheck($this->requestData);
        return returnMsg($res);
    }

    /**
     * @title  参团前检测
     * @param PtService $service
     * @return string
     * @throws \Exception
     */
    public function joinPtCheck(PtService $service)
    {
        $res = $service->joinPtActivityCheck($this->requestData);
        return returnMsg($res);
    }

    /**
     * @title  拼团列表
     * @param PtActivity $model
     * @return string
     * @throws \Exception
     */
    public function list(PtActivity $model)
    {
        $list = $model->cList($this->requestData);
        return returnData($list['list'], $list['pageTotal']);
    }

    /**
     * @title  拼团商品列表
     * @param PtGoods $model
     * @return string
     * @throws \Exception
     */
    public function ptGoodsList(PtGoods $model)
    {
        $list = $model->cList($this->requestData);
        return returnData($list['list'], $list['pageTotal']);
    }

    /**
     * @title  用户拼团情况
     * @param PtOrder $model
     * @return string
     * @throws \Exception
     */
    public function ptInfo(PtOrder $model)
    {
        $info = $model->ptInfo($this->requestData);
        return returnData($info);
    }

    /**
     * @title  用户拼团列表
     * @param PtOrder $model
     * @return string
     * @throws \Exception
     */
    public function userPtList(PtOrder $model)
    {
        $info = $model->userPtList($this->requestData);
        return returnData($info);
    }

    /**
     * @title  商品详情中待拼团列表已经总拼团人数
     * @param PtOrder $model
     * @return string
     * @throws \Exception
     */
    public function goodsInfoPtList(PtOrder $model)
    {
        $data = $this->requestData;
        $sear['activity_code'] = $data['activity_code'];
        $sear['activity_status'] = [1];
        $sear['pay_status'] = 2;
        $sear['pageNumber'] = 5;
        $sear['page'] = 1;
        $sear['goods_sn'] = $data['goods_sn'] ?? null;
        $list = $model->list($sear);
        $ptList = $list['list'] ?? [];
        $ptAll = $model->where(['activity_code' => $data['activity_code'], 'activity_status' => [1, 2], 'pay_status' => 2])->count();

        $all['count'] = $ptAll;
        $all['list'] = $ptList;

        return returnData($all);

    }

    /**
     * @title  全部待完成拼团商品列表
     * @param PtOrder $model
     * @return string
     * @throws \Exception
     */
    public function allPtList(PtOrder $model)
    {
        $sear['activity_status'] = [1];
        $sear['pay_status'] = 2;
        $sear['pageNumber'] = 3;
        $sear['page'] = 1;
        $list = $model->list($sear);
        return returnData($list['list'], $list['pageTotal']);
    }
}