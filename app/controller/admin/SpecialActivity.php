<?php
// +----------------------------------------------------------------------
// |[ 文档说明: ]
// +----------------------------------------------------------------------
// | Copyright (c) 2018~2024 http://www.mlhcmk.com All rights reserved.
// +----------------------------------------------------------------------


namespace app\controller\admin;


use app\BaseController;
use app\lib\models\Activity;
use app\lib\models\Activity as ActivityModel;
use app\lib\models\ActivityGoods;
use app\lib\models\ActivityGoodsSku;

class SpecialActivity extends BaseController
{
    protected $middleware = [
        'checkAdminToken',
        'checkRule',
        'OperationLog',
    ];

    /**
     * @title  N宫格列表
     * @param Activity $model
     * @return string
     * @throws \Exception
     */
    public function squareGridList(Activity $model)
    {
        $data = $this->requestData;
        $data['show_position'] = 3;
        $list = $model->list($data);
        return returnData($list['list'], $list['pageTotal']);
    }

    /**
     * @title  N宫格详情
     * @param ActivityModel $model
     * @return string
     */
    public function squareGridInfo(Activity $model)
    {
        $info = $model->info($this->requestData);
        return returnData($info);
    }

    /**
     * @title  编辑N宫格
     * @param ActivityModel $model
     * @return string
     */
    public function squareGridUpdate(ActivityModel $model)
    {
        $res = $model->edit($this->requestData);
        return returnMsg($res);
    }

    /**
     * @title  N宫格上/下架
     * @param ActivityModel $model
     * @return string
     */
    public function squareGridUpOrDown(ActivityModel $model)
    {
        $res = $model->upOrDown($this->requestData);
        return returnMsg($res);
    }

    /**
     * @title  品牌馆列表
     * @param Activity $model
     * @return string
     * @throws \Exception
     */
    public function brandSpaceList(Activity $model)
    {
        $data = $this->requestData;
        $data['show_position'] = 4;
        $list = $model->list($data);
        return returnData($list['list'], $list['pageTotal']);
    }

    /**
     * @title  品牌馆详情
     * @param ActivityModel $model
     * @return string
     */
    public function brandSpaceInfo(Activity $model)
    {
        $info = $model->info($this->requestData);
        return returnData($info);
    }

    /**
     * @title  新增品牌馆
     * @param ActivityModel $model
     * @return string
     */
    public function brandSpaceCreate(ActivityModel $model)
    {
        $data = $this->requestData;
        $data['show_position'] = 4;
        $res = $model->DBNew($data);
        return returnMsg($res);
    }

    /**
     * @title  编辑品牌馆
     * @param ActivityModel $model
     * @return string
     */
    public function brandSpaceUpdate(ActivityModel $model)
    {
        $res = $model->edit($this->requestData);
        return returnMsg($res);
    }

    /**
     * @title  上下架品牌馆
     * @param ActivityModel $model
     * @return string
     */
    public function brandSpaceUpOrDown(ActivityModel $model)
    {
        $data = $this->requestData;
        $data['noClearCache'] = true;
        $res = $model->upOrDown($this->requestData);
        return returnMsg($res);
    }

    /**
     * @title  删除品牌馆
     * @param ActivityModel $model
     * @return string
     */
    public function brandSpaceDelete(ActivityModel $model)
    {
        $res = $model->del($this->request->param('activity_id'), true);
        return returnMsg($res);
    }

    /**
     * @title  新增/编辑品牌馆商品
     * @param ActivityGoods $model
     * @return string
     * @throws \Exception
     */
    public function brandSpaceCreateOrUpdateGoods(ActivityGoods $model)
    {
        $data = $this->requestData;
        $data['noClearCache'] = true;
        $res = $model->DBNewOrEdit($data);
        return returnMsg($res);
    }

    /**
     * @title  品牌馆商品SPU详情
     * @param ActivityGoods $model
     * @return string
     * @throws \Exception
     */
    public function brandSpaceGoodsInfo(ActivityGoods $model)
    {
        $info = $model->list($this->requestData);
        return returnData($info['list'], $info['pageTotal']);
    }

    /**
     * @title  品牌馆商品SKU详情
     * @param ActivityGoodsSku $model
     * @return string
     * @throws \Exception
     */
    public function brandSpaceGoodsSkuInfo(ActivityGoodsSku $model)
    {
        $info = $model->list($this->requestData);
        return returnData($info['list'], $info['pageTotal']);
    }


    /**
     * @title  删除品牌馆商品
     * @param ActivityGoods $model
     * @return string
     * @throws \Exception
     */
    public function brandSpaceDeleteGoods(ActivityGoods $model)
    {
        $res = $model->del($this->request->param('id'), true);
        return returnMsg($res);
    }

    /**
     * @title  更新品牌馆商品排序
     * @param ActivityGoods $model
     * @return string
     */
    public function brandSpaceUpdateGoodsSort(ActivityGoods $model)
    {
        $res = $model->updateSort($this->requestData);
        return returnMsg($res);
    }

    /**
     * @title  删除品牌馆商品SKU
     * @param ActivityGoodsSku $model
     * @return string
     * @throws \Exception
     */
    public function brandSpaceDeleteGoodsSku(ActivityGoodsSku $model)
    {
        $data = $this->requestData;
        $data['noClearCache'] = true;
        $res = $model->del($data);
        return returnMsg($res);
    }

    /**
     * @title  限时专场列表
     * @param Activity $model
     * @return string
     * @throws \Exception
     */
    public function specialAreaList(Activity $model)
    {
        $data = $this->requestData;
        $data['show_position'] = 5;
        $list = $model->list($data);
        return returnData($list['list'], $list['pageTotal']);
    }

    /**
     * @title   限时专场详情
     * @param ActivityModel $model
     * @return string
     */
    public function specialAreaInfo(Activity $model)
    {
        $info = $model->info($this->requestData);
        return returnData($info);
    }

    /**
     * @title  新增限时专场
     * @param ActivityModel $model
     * @return string
     */
    public function specialAreaCreate(ActivityModel $model)
    {
        $data = $this->requestData;
        $data['show_position'] = 5;
        $res = $model->DBNew($data);
        return returnMsg($res);
    }

    /**
     * @title  编辑限时专场
     * @param ActivityModel $model
     * @return string
     */
    public function specialAreaUpdate(ActivityModel $model)
    {
        $res = $model->edit($this->requestData);
        return returnMsg($res);
    }

    /**
     * @title  上下架限时专场
     * @param ActivityModel $model
     * @return string
     */
    public function specialAreaUpOrDown(ActivityModel $model)
    {
        $data = $this->requestData;
        $data['noClearCache'] = true;
        $res = $model->upOrDown($this->requestData);
        return returnMsg($res);
    }

    /**
     * @title  删除限时专场
     * @param ActivityModel $model
     * @return string
     */
    public function specialAreaDelete(ActivityModel $model)
    {
        $res = $model->del($this->request->param('activity_id'), true);
        return returnMsg($res);
    }

    /**
     * @title  新增/编辑限时专场商品
     * @param ActivityGoods $model
     * @return string
     * @throws \Exception
     */
    public function specialAreaCreateOrUpdateGoods(ActivityGoods $model)
    {
        $data = $this->requestData;
        $data['noClearCache'] = true;
        $res = $model->DBNewOrEdit($data);
        return returnMsg($res);
    }

    /**
     * @title  限时专场商品SPU详情
     * @param ActivityGoods $model
     * @return string
     * @throws \Exception
     */
    public function specialAreaGoodsInfo(ActivityGoods $model)
    {
        $info = $model->list($this->requestData);
        return returnData($info['list'], $info['pageTotal']);
    }

    /**
     * @title  限时专场商品SKU详情
     * @param ActivityGoodsSku $model
     * @return string
     * @throws \Exception
     */
    public function specialAreaGoodsSkuInfo(ActivityGoodsSku $model)
    {
        $info = $model->list($this->requestData);
        return returnData($info['list'], $info['pageTotal']);
    }


    /**
     * @title  删除限时专场商品
     * @param ActivityGoods $model
     * @return string
     * @throws \Exception
     */
    public function specialAreaDeleteGoods(ActivityGoods $model)
    {
        $res = $model->del($this->request->param('id'), true);
        return returnMsg($res);
    }

    /**
     * @title  更新限时专场商品排序
     * @param ActivityGoods $model
     * @return string
     */
    public function specialAreaUpdateGoodsSort(ActivityGoods $model)
    {
        $res = $model->updateSort($this->requestData);
        return returnMsg($res);
    }

    /**
     * @title  删除限时专场商品SKU
     * @param ActivityGoodsSku $model
     * @return string
     * @throws \Exception
     */
    public function specialAreaDeleteGoodsSku(ActivityGoodsSku $model)
    {
        $data = $this->requestData;
        $data['noClearCache'] = true;
        $res = $model->del($data);
        return returnMsg($res);
    }


}