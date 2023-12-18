<?php
// +----------------------------------------------------------------------
// |[ 文档说明: ]
// +----------------------------------------------------------------------
// | Copyright (c) 2018~2024 http://www.mlhcmk.com All rights reserved.
// +----------------------------------------------------------------------


namespace app\controller\api\v1;


use app\BaseController;
use app\lib\models\Banner as BannerModel;


class Banner extends BaseController
{
    /**
     * @title  首页广告轮播图
     * @param BannerModel $model
     * @return string
     * @throws \Exception
     */
    public function list(BannerModel $model)
    {
        $list = $model->list($this->requestData);
        return returnData($list['list']);
    }
}