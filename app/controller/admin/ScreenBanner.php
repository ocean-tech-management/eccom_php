<?php
// +----------------------------------------------------------------------
// |[ 文档说明: 屏幕广告模块Controller]
// +----------------------------------------------------------------------
// | Copyright (c) 2018~2024 http://www.mlhcmk.com All rights reserved.
// +----------------------------------------------------------------------


namespace app\controller\admin;


use app\BaseController;
use app\lib\models\ScreenBanner as ScreenBannerModel;


class ScreenBanner extends BaseController
{
    protected $middleware = [
        'checkAdminToken',
        'checkRule',
        'OperationLog',
    ];

    /**
     * @title  广告列表
     * @param ScreenBannerModel $model
     * @return string
     * @throws \Exception
     */
    public function list(ScreenBannerModel $model)
    {
        $list = $model->list($this->requestData);
        return returnData($list['list'], $list['pageTotal']);
    }

    /**
     * @title  广告详情
     * @param ScreenBannerModel $model
     * @return string
     */
    public function info(ScreenBannerModel $model)
    {
        $info = $model->info($this->request->param('id'));
        return returnData($info);
    }

    /**
     * @title  新增广告
     * @param ScreenBannerModel $model
     * @return string
     */
    public function create(ScreenBannerModel $model)
    {
        $res = $model->new($this->requestData);
        return returnMsg($res);
    }

    /**
     * @title  更新广告
     * @param ScreenBannerModel $model
     * @return string
     */
    public function update(ScreenBannerModel $model)
    {
        $res = $model->edit($this->requestData);
        return returnMsg($res);
    }

    /**
     * @title  删除广告
     * @param ScreenBannerModel $model
     * @return string
     */
    public function delete(ScreenBannerModel $model)
    {
        $res = $model->del($this->request->param('id'));
        return returnMsg($res);
    }

    /**
     * @title  上/下架广告
     * @param ScreenBannerModel $model
     * @return string
     */
    public function upOrDown(ScreenBannerModel $model)
    {
        $res = $model->upOrDown($this->requestData);
        return returnMsg($res);
    }
}