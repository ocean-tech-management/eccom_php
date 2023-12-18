<?php
// +----------------------------------------------------------------------
// |[ 文档说明: 拼拼有礼C端模块Controller]
// +----------------------------------------------------------------------



namespace app\controller\api\v1;


use app\BaseController;
use app\lib\exceptions\OrderException;
use app\lib\models\PpylActivity;
use app\lib\models\PpylBalanceDetail;
use app\lib\models\PpylBanner as PpylBannerModel;
use app\lib\models\PpylArea;
use app\lib\models\PpylCvipOrder;
use app\lib\models\PpylCvipPrice;
use app\lib\models\PpylGoods;
use app\lib\models\PpylOrder;
use app\lib\models\PpylReward;
use app\lib\models\PtOrder;
use app\lib\services\Ppyl as PpylService;
use app\lib\services\Pt as PtService;
use app\lib\services\UserSummary;

class Ppyl extends BaseController
{

    /**
     * @title  开团前检测
     * @param PpylService $service
     * @return string
     * @throws \Exception
     */
    public function startPtCheck(PpylService $service)
    {
        $data = $this->requestData;
        $data['userTrigger'] = true;
        $res = $service->startPtActivityCheck($data);
        return returnMsg($res);
    }

    /**
     * @title  参团前检测
     * @param PpylService $service
     * @return string
     * @throws \Exception
     */
    public function joinPtCheck(PpylService $service)
    {
        $data = $this->requestData;
        $data['userTrigger'] = true;
        $res = $service->joinPtActivityCheck($data);
        return returnMsg($res);
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

    /**
     * @title  商品详情中待拼团列表已经总拼团人数
     * @param PpylOrder $model
     * @return string
     * @throws \Exception
     */
    public function goodsInfoPpylList(PpylOrder $model)
    {
        $data = $this->requestData;
        if(!empty($data['activity_code'] ?? null)){
            $sear['activity_code'] = $data['activity_code'];
        }

        //待成团列表
        $sear['area_code'] = $data['area_code'];
        $sear['searOrderType'] = 2;
        $sear['pay_status'] = 2;
        $sear['pageNumber'] = 5;
        $sear['page'] = 1;
        $sear['searGoodsSpuSn'] = $data['goods_sn'] ?? null;
        $sear['needUserArray'] = true;
        $sear['user_role'] = 1;
        $list = $model->list($sear);
        $ptList = $list['list'] ?? [];

        //总数
        $cMap[] = ['end_time', '>', time()];
        $cMap[] = ['area_code', '=', $data['area_code']];
        $cMap[] = ['activity_status', 'in', [1, 2]];
        $cMap[] = ['pay_status', '=', 2];
        $ptAll = $model->where($cMap)->count();
        $number = date('d', time());
        $ptAll += intval($number . '3') + date('m', time());

        $all['count'] = $ptAll;
        $all['list'] = $ptList;

        return returnData($all);

    }

    /**
     * @title  拼拼有礼专场列表
     * @param PpylArea $model
     * @return string
     * @throws \Exception
     */
    public function areaList(PpylArea $model)
    {
        $list = $model->cList($this->requestData);
        return returnData($list['list'], $list['pageTotal']);
    }

    /**
     * @title  拼拼有礼专场商品列表
     * @param PpylGoods $model
     * @return string
     * @throws \Exception
     */
    public function areaGoods(PpylGoods $model)
    {
        $list = $model->cList($this->requestData);
        return returnData($list['list'], $list['pageTotal']);
    }

    /**
     * @title  拼拼有礼广告轮播图
     * @param PpylBannerModel $model
     * @return string
     * @throws \Exception
     */
    public function bannerList(PpylBannerModel $model)
    {
        $list = $model->list($this->requestData);
        return returnData($list['list']);
    }

    /**
     * @title  拼拼有礼数据统计(暂只有人数统计)
     * @param PpylOrder $model
     * @return string
     */
    public function ppylNumber(PpylOrder $model)
    {
        $info = $model->orderSummary($this->requestData);
        return returnData($info);
    }

    /**
     * @title  CVIP订单记录列表
     * @param PpylCvipOrder $model
     * @throws \Exception
     * @return string
     */
    public function CVIPOrderList(PpylCvipOrder $model)
    {
        $info = $model->cOrderList($this->requestData);
        return returnData($info);
    }

    /**
     * @title  CVIP详情和价格表
     * @param PpylCvipPrice $model
     * @throws \Exception
     * @return string
     */
    public function CVIPInfo(PpylCvipPrice $model)
    {
        $info = $model->cInfo($this->requestData);
        return returnData($info);
    }

    /**
     * @title  创建CVIP订单
     * @param PpylCvipOrder $model
     * @return string
     */
    public function CVIPOrderCreate(PpylCvipOrder $model)
    {
        if(time() >= 1640847600){
            throw new OrderException(['msg'=>'系统升级中, 暂无法下单, 感谢您的支持']);
        }
        $res = $model->buildOrder($this->requestData);
        return returnData($res);
    }

    /**
     * @title  操作自动领取红包开关
     * @param PpylService $service
     * @return string
     */
    public function autoReceiveSwitch(PpylService $service)
    {
        $res = $service->autoReceiveSwitch($this->requestData);
        return returnMsg($res);
    }

    /**
     * @title  用户奖励列表
     * @param PpylReward $model
     * @throws \Exception
     * @return string
     */
    public function rewardList(PpylReward $model)
    {
        $list = $model->cList($this->requestData);
        return returnData($list['list'], $list['pageTotal']);
    }

    /**
     * @title  用户奖励列表汇总
     * @param PpylReward $model
     * @throws \Exception
     * @return string
     */
    public function rewardCount(PpylReward $model)
    {
        $list = $model->cListSummary($this->requestData);
        return returnData($list);
    }


    /**
     * @title  用户拼拼有礼收益汇总,个人中心数据面板
     * @param PpylReward $model
     * @throws \Exception
     * @return string
     */
    public function userRewardSummary(PpylReward $model)
    {
        $list = $model->userRewardSummary($this->requestData);
        return returnData($list);
    }

    /**
     * @title  激活推荐奖励
     * @param PpylReward $model
     * @return string
     * @throws \Exception
     */
    public function activationTopReward(PpylReward $model)
    {
        $list = $model->autoReceiveTopReward($this->requestData);
        return returnMsg($list);

    }

    /**
     * @title  一键领取推荐奖励
     * @param PpylReward $model
     * @return string
     * @throws \Exception
     */
    public function quicklyReceiveReward(PpylReward $model)
    {
        $list = $model->quicklyReceiveReward($this->requestData);
        return returnMsg($list);

    }

    /**
     * @title  领取奖励
     * @param PpylReward $model
     * @throws \Exception
     * @return string
     */
    public function receiveReward(PpylReward $model)
    {
        $res = $model->receiveReward($this->requestData);
        return returnMsg($res);
    }

    /**
     * @title  拼拼有礼余额转入商城余额
     * @param PpylBalanceDetail $model
     * @return string
     */
    public function transformMallBalance(PpylBalanceDetail $model)
    {
        $res = $model->transformMallBalance($this->requestData);
        return returnMsg($res);
    }

    /**
     * @title  拼拼有礼余额明细
//     * @param PpylBalanceDetail $model
     * @param PpylReward $model
     * @throws \Exception
     * @return string
     */
    public function ppylBalanceDetail(PpylReward $model)
    {
        $list = $model->cList($this->requestData);
        return returnData($list['list'], $list['pageTotal']);
    }

    /**
     * @title  用户拼拼有礼收入汇总数据面板
     * @param UserSummary $service
     * @return string
     */
    public function userPpylAllInCome(UserSummary $service)
    {
        $info = $service->userPpylAllInCome();
        return returnData($info);
    }

    /**
     * @title  用户奖励分组汇总
     * @param PpylReward $model
     * @throws \Exception
     * @return string
     */
    public function userPpylRewardGroup(PpylReward $model)
    {
        $info = $model->cListSummaryGroup($this->requestData);
        return returnData($info);
    }

    /**
     * @title  查询拼拼有礼活动列表
     * @param PpylActivity $model
     * @throws \Exception
     * @return string
     */
    public function activityList(PpylActivity $model)
    {
        $cacheExpire = 600;
        //拼拼有礼活动列表
        $ptSear['page'] = 1;
        $ptSear['pageNumber'] = 3;
        $ptSear['style_type'] = 2;
        if (empty($data['clearCache'])) {
            $ptSear['cache'] = 'ApiPpylList';
            $ptSear['cache_expire'] = $cacheExpire;
        }
        $ppylList = $model->cList($ptSear);
        return returnData($ppylList['list'], $ppylList['pageTotal']);
    }
}
