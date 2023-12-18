<?php
// +----------------------------------------------------------------------
// |[ 文档说明: 拼拼有礼模块Controller]
// +----------------------------------------------------------------------
// | Copyright (c) 2018~2024 http://www.mlhcmk.com All rights reserved.
// +----------------------------------------------------------------------
 

namespace app\controller\admin;


use app\BaseController;
use app\lib\models\ActivityGoods;
use app\lib\models\ActivityGoodsSku;
use app\lib\models\Banner as BannerModel;
use app\lib\models\Divide as DivideModel;
use app\lib\models\GoodsSku;
use app\lib\models\MemberVdc;
use app\lib\models\PpylActivity;
use app\lib\models\PpylArea;
use app\lib\models\PpylConfig;
use app\lib\models\PpylCvipPrice;
use app\lib\models\PpylGoods;
use app\lib\models\PpylGoodsSku;
use app\lib\models\PpylMemberVdc;
use app\lib\models\PpylOrder;
use app\lib\models\PpylReward;
use app\lib\models\PpylWaitOrder;
use app\lib\models\PtActivity;
use app\lib\models\Activity as ActivityModel;
use app\lib\models\PtGoods;
use app\lib\models\PtGoodsSku;
use app\lib\models\User as UserModel;
use app\lib\models\UserRepurchase;
use app\lib\services\Ppyl as PpylService;

class Ppyl extends BaseController
{
    protected $middleware = [
        'checkAdminToken',
        'checkRule',
        'OperationLog',
    ];

    /**
     * @title  拼团活动列表
     * @param PpylActivity $model
     * @return string
     * @throws \Exception
     */
    public function ptList(PpylActivity $model)
    {
        $list = $model->list($this->requestData);
        return returnData($list['list'], $list['pageTotal']);
    }

    /**
     * @title  拼团活动详情
     * @param PpylActivity $model
     * @return string
     * @throws \Exception
     */
    public function ptInfo(PpylActivity $model)
    {
        $info = $model->info($this->requestData);
        return returnData($info);
    }

    /**
     * @title  新增拼团活动
     * @param PpylActivity $model
     * @return string
     */
    public function ptCreate(PpylActivity $model)
    {
        $res = $model->DBNew($this->requestData);
        return returnMsg($res);
    }

    /**
     * @title  编辑拼团活动
     * @param PpylActivity $model
     * @return string
     */
    public function ptUpdate(PpylActivity $model)
    {
        $res = $model->DBEdit($this->requestData);
        return returnMsg($res);
    }

    /**
     * @title  删除拼团活动
     * @param PpylActivity $model
     * @return string
     */
    public function ptDelete(PpylActivity $model)
    {
        $res = $model->DBDelete($this->requestData);
        return returnMsg($res);
    }


    /**
     * @title  上下架活动
     * @param PpylActivity $model
     * @return string
     */
    public function ptUpOrDown(PpylActivity $model)
    {
        $res = $model->upOrDown($this->requestData);
        return returnMsg($res);
    }

    /**
     * @title  拼团专场活动列表
     * @param PpylArea $model
     * @return string
     * @throws \Exception
     */
    public function ptAreaList(PpylArea $model)
    {
        $list = $model->list($this->requestData);
        return returnData($list['list'], $list['pageTotal']);
    }

    /**
     * @title  拼团专场活动详情
     * @param PpylArea $model
     * @return string
     * @throws \Exception
     */
    public function ptAreaInfo(PpylArea $model)
    {
        $info = $model->info($this->requestData);
        return returnData($info);
    }

    /**
     * @title  新增拼团专场活动
     * @param PpylArea $model
     * @return string
     */
    public function ptAreaCreate(PpylArea $model)
    {
        $res = $model->DBNew($this->requestData);
        return returnMsg($res);
    }

    /**
     * @title  编辑拼团专场活动
     * @param PpylArea $model
     * @return string
     */
    public function ptAreaUpdate(PpylArea $model)
    {
        $res = $model->DBEdit($this->requestData);
        return returnMsg($res);
    }

    /**
     * @title  删除拼团专场活动
     * @param PpylArea $model
     * @return string
     */
    public function ptAreaDelete(PpylArea $model)
    {
        $res = $model->DBDelete($this->requestData);
        return returnMsg($res);
    }

    /**
     * @title  更新拼团专场活动商品排序
     * @param PpylArea $model
     * @return string
     */
    public function updatePtAreaGoodsSort(PpylArea $model)
    {
        $res = $model->upOrDown($this->requestData);
        return returnMsg($res);
    }

    /**
     * @title  新增/编辑拼团商品
     * @param PpylGoods $model
     * @return string
     * @throws \Exception
     */
    public function createOrUpdatePtGoods(PpylGoods $model)
    {
        $res = $model->DBNewOrEdit($this->requestData);
        return returnMsg($res);
    }

    /**
     * @title  拼团商品SPU详情
     * @param PpylGoods $model
     * @return string
     * @throws \Exception
     */
    public function ptGoodsInfo(PpylGoods $model)
    {
        $info = $model->list($this->requestData);
        return returnData($info['list'], $info['pageTotal']);
    }

    /**
     * @title  拼团商品SKU详情
     * @param PpylGoodsSku $model
     * @return string
     * @throws \Exception
     */
    public function ptGoodsSkuInfo(PpylGoodsSku $model)
    {
        $info = $model->list($this->requestData);
        return returnData($info['list'], $info['pageTotal']);
    }


    /**
     * @title  删除拼团商品
     * @param PpylGoods $model
     * @return string
     * @throws \Exception
     */
    public function deletePtGoods(PpylGoods $model)
    {
        $res = $model->del($this->request->param('id'));
        return returnMsg($res);
    }

    /**
     * @title  删除拼团商品SKU
     * @param PpylGoodsSku $model
     * @return string
     * @throws \Exception
     */
    public function deletePtGoodsSku(PpylGoodsSku $model)
    {
        $res = $model->del($this->requestData);
        return returnMsg($res);

    }

    /**
     * @title  更新拼团活动商品排序
     * @param PpylGoods $model
     * @return string
     */
    public function updatePtGoodsSort(PpylGoods $model)
    {
        $res = $model->updateSort($this->request->param('data'));
        return returnMsg($res);
    }

    /**
     * @title  商品销售情况
     * @param PpylGoodsSku $model
     * @return string
     * @throws \Exception
     */
    public function ptGoodsSkuSaleInfo(PpylGoodsSku $model)
    {
        $list = $model->goodsSkuSale($this->requestData);
        return returnData($list);
    }

    /**
     * @title  更新拼团商品库存
     * @param PpylGoodsSku $model
     * @return string
     */
    public function updatePtStock(PpylGoodsSku $model)
    {
        $res = $model->updateStock($this->requestData);
        return returnMsg($res);
    }

    /**
     * @title  拼拼有礼订单列表
     * @param PpylOrder $model
     * @return string
     * @throws \Exception
     */
    public function ppylOrder(PpylOrder $model)
    {
        $list = $model->list($this->requestData);
        return returnData($list['list'], $list['pageTotal']);
    }

    /**
     * @title  拼拼有礼排队订单列表
     * @param PpylWaitOrder $model
     * @return string
     * @throws \Exception
     */
    public function ppylWaitOrder(PpylWaitOrder $model)
    {
        $list = $model->list($this->requestData);
        return returnData($list['list'], $list['pageTotal']);
    }

    /**
     * @title  拼拼有礼奖励订单列表
     * @param PpylReward $model
     * @return string
     * @throws \Exception
     */
    public function ppylReward(PpylReward $model)
    {
        $list = $model->list($this->requestData);
        return returnData($list['list'], $list['pageTotal']);
    }

    /**
     * @title  获取分润明细
     * @param PpylReward $model
     * @return string
     * @throws \Exception
     */
    public function rewardDetail(PpylReward $model)
    {
        $this->validate($this->requestData, ['order_sn' => 'require']);
        $row = $model->recordDetail($this->requestData);

        return returnData($row);
    }

    /**
     * @title  会员分销规则列表
     * @param PpylMemberVdc $model
     * @return string
     * @throws \Exception
     */
    public function vdcList(PpylMemberVdc $model)
    {
        $list = $model->list($this->requestData);
        return returnData($list['list'], $list['pageTotal']);
    }

    /**
     * @title  分销规则列表
     * @param PpylMemberVdc $model
     * @return string
     * @throws \Exception
     */
    public function ruleList(PpylMemberVdc $model)
    {
        $list = $model->list($this->requestData);
        return returnData($list['list'], $list['pageTotal']);
    }

    /**
     * @title  分销规则详情
     * @param PpylMemberVdc $model
     * @return string
     */
    public function ruleInfo(PpylMemberVdc $model)
    {
        $info = $model->getMemberRule($this->request->param('level'));
        return returnData($info);
    }

    /**
     * @title  新增分销规则
     * @param PpylMemberVdc $model
     * @return string
     */
    public function ruleCreate(PpylMemberVdc $model)
    {
        $res = $model->new($this->requestData);
        return returnMsg($res);
    }

    /**
     * @title  编辑分销规则
     * @param PpylMemberVdc $model
     * @return string
     */
    public function ruleUpdate(PpylMemberVdc $model)
    {
        $res = $model->edit($this->requestData);
        return returnMsg($res);
    }

    /**
     * @title  CVIP价格列表
     * @param PpylCvipPrice $model
     * @return string
     * @throws \Exception
     */
    public function CVIPList(PpylCvipPrice $model)
    {
        $list = $model->list($this->requestData);
        return returnData($list['list'], $list['pageTotal']);
    }

    /**
     * @title  CVIP价格详情
     * @param PpylCvipPrice $model
     * @return string
     */
    public function CVIPInfo(PpylCvipPrice $model)
    {
        $info = $model->info($this->requestData);
        return returnData($info);
    }

    /**
     * @title  新增CVIP价格
     * @param PpylCvipPrice $model
     * @return string
     */
    public function CVIPCreate(PpylCvipPrice $model)
    {
        $res = $model->new($this->requestData);
        return returnMsg($res);
    }

    /**
     * @title  更新CVIP价格
     * @param PpylCvipPrice $model
     * @return string
     */
    public function CVIPUpdate(PpylCvipPrice $model)
    {
        $res = $model->edit($this->requestData);
        return returnMsg($res);
    }

    /**
     * @title  删除CVIP价格
     * @param PpylCvipPrice $model
     * @return string
     */
    public function CVIPDelete(PpylCvipPrice $model)
    {
        $res = $model->del($this->request->param('combo_sn'));
        return returnMsg($res);
    }

    /**
     * @title  上/下架CVIP价格
     * @param PpylCvipPrice $model
     * @return string
     */
    public function CVIPUpOrDown(PpylCvipPrice $model)
    {
        $res = $model->upOrDown($this->requestData);
        return returnMsg($res);
    }

    /**
     * @title  指定用户CVIP有效期
     * @param PpylService $service
     * @return string
     */
    public function assignCVIP(PpylService $service)
    {
        $res = $service->assignCVIP($this->requestData);
        return returnMsg($res);
    }

    /**
     * @title  用户列表
     * @param UserModel $model
     * @return string
     * @throws \Exception
     */
    public function userList(UserModel $model)
    {
        $data = $this->requestData;
        $data['needAllLevel'] = true;
        $list = $model->list($data);
        return returnData($list['list'], $list['pageTotal']);
    }

    /**
     * @title  用户列表-查看余额专用
     * @param UserModel $model
     * @return string
     * @throws \Exception
     */
    public function userListForBalance(UserModel $model)
    {
        $data = $this->requestData;
        $data['needAllLevel'] = true;
        $list = $model->list($data);
        return returnData($list['list'], $list['pageTotal']);
    }

    /**
     * @title  拼拼有礼基础配置详情
     * @param PpylConfig $model
     * @return string
     */
    public function configInfo(PpylConfig $model)
    {
        $res = $model->info($this->requestData);
        return returnData($res);
    }


    /**
     * @title  修改拼拼有礼基础配置
     * @param PpylConfig $model
     * @return string
     */
    public function updateConfig(PpylConfig $model)
    {
        $res = $model->DBEdit($this->requestData);
        return returnMsg($res);
    }

    /**
     * @title  用户寄售次数列表
     * @param UserRepurchase $model
     * @return string
     * @throws \Exception
     */
    public function userRepurchaseList(UserRepurchase $model)
    {
        $data = $this->requestData;
        $list = $model->list($data);
        return returnData($list['list'], $list['pageTotal']);
    }

    /**
     * @title  更新用户寄售次数
     * @param UserRepurchase $model
     * @return string
     */
    public function updateUserRepurchase(UserRepurchase $model)
    {
        $res = $model->updateUserRepurchase($this->requestData);
        return returnData($res);
    }
}