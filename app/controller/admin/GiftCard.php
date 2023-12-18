<?php
// +----------------------------------------------------------------------
// |[ 文档说明: 礼品卡模块Controller]
// +----------------------------------------------------------------------



namespace app\controller\admin;


use app\BaseController;
use app\lib\models\GiftAttr;
use app\lib\models\GiftBatch;
use app\lib\models\GiftCard as GiftCardModel;

class GiftCard extends BaseController
{
    /**
     * @title  批次列表
     * @param GiftBatch $model
     * @return string
     * @throws \Exception
     */
    public function batchList(GiftBatch $model)
    {
        $list = $model->list($this->requestData);
        return returnData($list['list'], $list['pageTotal']);
    }

    /**
     * @title  批次详情
     * @param GiftBatch $model
     * @return string
     * @throws \Exception
     */
    public function batchInfo(GiftBatch $model)
    {
        $info = $model->info($this->requestData);
        return returnData($info);
    }

    /**
     * @title  新增批次
     * @param GiftBatch $model
     * @return string
     * @throws \Exception
     */
    public function batchCreate(GiftBatch $model)
    {
        $res = $model->DBNew($this->requestData);
        return returnMsg($res);
    }

    /**
     * @title  编辑批次
     * @param GiftBatch $model
     * @return string
     */
    public function batchUpdate(GiftBatch $model)
    {
        $res = $model->DBEdit($this->requestData);
        return returnMsg($res);
    }

    /**
     * @title  删除批次
     * @param GiftBatch $model
     * @return string
     */
    public function batchDelete(GiftBatch $model)
    {
        $res = $model->DBDel($this->requestData);
        return returnMsg($res);
    }

    /**
     * @title  礼品规格详情
     * @param GiftAttr $model
     * @return string
     * @throws \Exception
     */
    public function attrInfo(GiftAttr $model)
    {
        $info = $model->info($this->requestData);
        return returnData($info);
    }

    /**
     * @title  礼品卡列表
     * @param GiftCardModel $model
     * @return string
     * @throws \Exception
     */
    public function cardList(GiftCardModel $model)
    {
        $list = $model->list($this->requestData);
        return returnData($list['list'], $list['pageTotal']);
    }

    /**
     * @title  新增礼品规格及礼品卡
     * @param GiftAttr $model
     * @return string
     * @throws \Exception
     */
    public function attrCreate(GiftAttr $model)
    {
        $res = $model->DBNew($this->requestData);
        return returnData($res);
    }

    /**
     * @title  销毁礼品卡
     * @param GiftCardModel $model
     * @return string
     * @throws \Exception
     */
    public function destroyCard(GiftCardModel $model)
    {
        $res = $model->destroyCard($this->requestData);
        return returnData($res);
    }
}