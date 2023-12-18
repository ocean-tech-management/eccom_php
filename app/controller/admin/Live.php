<?php
// +----------------------------------------------------------------------
// |[ 文档说明: ]
// +----------------------------------------------------------------------
// | Copyright (c) 2018~2024 http://www.mlhcmk.com All rights reserved.
// +----------------------------------------------------------------------
 

namespace app\controller\admin;


use app\BaseController;
use app\lib\models\LiveRoom;
use app\lib\models\LiveRoomGoods;
use app\lib\services\Live as LiveService;

class Live extends BaseController
{
    protected $middleware = [
        'checkAdminToken',
        'checkRule',
        'OperationLog',
    ];

    /**
     * @title  直播间列表
     * @param LiveRoom $model
     * @return string
     * @throws \Exception
     */
    public function liveRoomList(LiveRoom $model)
    {
        $list = $model->list($this->requestData);
        return returnData($list['list'], $list['pageTotal']);
    }

    /**
     * @title  直播商品库列表
     * @param LiveRoomGoods $model
     * @return string
     * @throws \Exception
     */
    public function goodsList(LiveRoomGoods $model)
    {
        $list = $model->list($this->requestData);
        return returnData($list['list'], $list['pageTotal']);
    }

    /**
     * @title  同步直播间列表
     * @param LiveService $service
     * @return string
     */
    public function syncLiveRoom(LiveService $service)
    {
        $list = $service->syncLiveRoom();
        return returnMsg($list);
    }

    /**
     * @title  同步商品库列表
     * @param LiveService $service
     * @return string
     */
    public function syncGoods(LiveService $service)
    {
        $data = $this->requestData;
        $status = $data['status'] ?? 0;
        $list = $service->syncGoods(0, 30, $status);
        return returnMsg($list);
    }

    /**
     * @title  添加审核商品
     * @param LiveService $service
     * @return string
     */
    public function createGoods(LiveService $service)
    {
        $res = $service->createGoods($this->requestData);
        return returnMsg($res);
    }

    /**
     * @title  更新待审核的商品信息
     * @param LiveService $service
     * @return string
     */
    public function updateGoods(LiveService $service)
    {
        $res = $service->updateLiveRoomGoods($this->requestData);
        return returnMsg($res);
    }

    /**
     * @title  删除商品库的商品
     * @param LiveService $service
     * @return string
     */
    public function deleteGoods(LiveService $service)
    {
        $res = $service->deleteLiveRoomGoods($this->requestData);
        return returnMsg($res);
    }

    /**
     * @title  撤回商品审核
     * @param LiveService $service
     * @return string
     */
    public function resetAuditGoods(LiveService $service)
    {
        $res = $service->resetAuditGoods($this->requestData);
        return returnMsg($res);
    }

    /**
     * @title  直播间导入商品
     * @param LiveService $service
     * @return string
     */
    public function importLiveRoomGoods(LiveService $service)
    {
        $res = $service->importLiveRoomGoods($this->requestData);
        return returnMsg($res);
    }

    /**
     * @title  获取直播间分享二维码
     * @param LiveService $service
     * @return string
     */
    public function liveRoomShareCode(LiveService $service)
    {
        $info = $service->getLiveRoomShareCode($this->requestData);
        return returnData($info);
    }

    /**
     * @title  修改直播间展示状态
     * @param LiveRoom $model
     * @return string
     */
    public function updateRoomShowStatus(LiveRoom $model)
    {
        $res = $model->showStatus($this->requestData);
        return returnMsg($res);
    }
}