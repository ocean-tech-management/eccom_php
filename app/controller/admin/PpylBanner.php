<?php
// +----------------------------------------------------------------------
// |[ 文档说明: 拼拼有礼广告模块Controller]
// +----------------------------------------------------------------------
// | Copyright (c) 2018~2024 http://www.mlhcmk.com All rights reserved.
// +----------------------------------------------------------------------


namespace app\controller\admin;


use app\BaseController;
use app\lib\models\PpylBanner as PpylBannerModel;


class PpylBanner extends BaseController
{
    protected $middleware = [
        'checkAdminToken',
        'checkRule',
        'OperationLog',
    ];

    /**
     * @title  拼拼有礼广告列表
     * @param PpylBannerModel $model
     * @return string
     * @throws \Exception
     */
    public function list(PpylBannerModel $model)
    {
        $list = $model->list($this->requestData);
        return returnData($list['list'], $list['pageTotal']);
    }

    /**
     * @title  拼拼有礼广告详情
     * @param PpylBannerModel $model
     * @return string
     */
    public function info(PpylBannerModel $model)
    {
        $info = $model->info($this->request->param('id'));
        return returnData($info);
    }

    /**
     * @title  新增拼拼有礼广告
     * @param PpylBannerModel $model
     * @return string
     */
    public function create(PpylBannerModel $model)
    {
        $res = $model->new($this->requestData);
        return returnMsg($res);
    }

    /**
     * @title  更新拼拼有礼广告
     * @param PpylBannerModel $model
     * @return string
     */
    public function update(PpylBannerModel $model)
    {
        $res = $model->edit($this->requestData);
        return returnMsg($res);
    }

    /**
     * @title  删除拼拼有礼广告
     * @param PpylBannerModel $model
     * @return string
     */
    public function delete(PpylBannerModel $model)
    {
        $res = $model->del($this->request->param('id'));
        return returnMsg($res);
    }

    /**
     * @title  上/下架拼拼有礼广告
     * @param PpylBannerModel $model
     * @return string
     */
    public function upOrDown(PpylBannerModel $model)
    {
        $res = $model->upOrDown($this->requestData);
        return returnMsg($res);
    }
}