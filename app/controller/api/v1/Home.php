<?php
// +----------------------------------------------------------------------
// |[ 文档说明: ]
// +----------------------------------------------------------------------
// | Copyright (c) 2018~2024 http://www.mlhcmk.com All rights reserved.
// +----------------------------------------------------------------------


namespace app\controller\api\v1;


use app\BaseController;
use app\lib\models\Activity;
use app\lib\models\Category;
use app\lib\models\Banner;
use app\lib\models\CouponUserType;
use app\lib\models\ExchangeGoods;
use app\lib\models\GoodsSpu;
use app\lib\models\Entrance;
use app\lib\models\MemberVdc;
use app\lib\models\OrderGoods;
use app\lib\models\PpylActivity;
use app\lib\models\PtActivity;
use app\lib\models\ScreenBanner;
use app\lib\models\SystemConfig;
use app\lib\services\DynamicParams;
use app\lib\services\QrCode;
use app\lib\services\Wx;
use think\facade\Cache;

class Home extends BaseController
{
    private $cacheExpire = 600;

    /**
     * @title  首页顶部列表
     * @return string
     * @throws \Exception
     */
    public function topList()
    {
        $data = $this->requestData;
        //是否清除缓存
        if (!empty($data['clearCache'] ?? false)) {
            cache('mallHomeApiCategory', null);
            cache('ApiHomeBanner', null);
            cache('ApiHomeScreenBanner', null);
            cache('apiHomeMemberBg', null);
            cache('apiHomeBrandSpaceBg', null);
            cache('ApiHomeIconList', null);
            Cache::tag(['ApiHomeScreenBanner'])->clear();
        }

        $data['type'] = 2;
        $data['cache'] = 'mallHomeApiCategory';
        $data['cache_expire'] = $this->cacheExpire;
        //$aCategory = (new Category())->list($data);
        //轮播图
        $aBanner = (new Banner())->list(['cache' => 'ApiHomeBanner', 'cache_expire' => $this->cacheExpire]);

        //icon入口列表
        $aEntrance = (new Entrance())->cList(['cache' => 'ApiHomeIconList', 'cache_expire' => $this->cacheExpire]);

        //首屏广告
        $aScreenBanner = (new ScreenBanner())->cList(['show_position' => 1, 'cache' => 'ApiHomeScreenBanner', 'cache_expire' => $this->cacheExpire]);

        //团长大礼包入口背景图
        $memberBackground = (new MemberVdc())->background(['cache' => 'apiHomeMemberBg', 'cache_expire' => $this->cacheExpire]);

        //品牌馆入口背景图
        $brandSpaceBackGround = (new SystemConfig())->info(['searField' => 2, 'cache' => 'apiHomeBrandSpaceBg', 'cache_expire' => $this->cacheExpire])['brand_space_background'];

        $all = ['banner' => $aBanner['list'] ?? [], 'entrance' => $aEntrance['list'] ?? [], 'screenBanner' => $aScreenBanner ?? [], 'memberBackground' => $memberBackground ?? [], 'brandSpaceBackGround' => $brandSpaceBackGround ?? null];
        return returnData($all);
    }

    /**
     * @title  首页活动列表
     * @param Activity $model
     * @return string
     * @throws \Exception
     */
    public function list()
    {
        $model = (new Activity());
        $data = $this->requestData;
        $cacheKey = 'ApiHomeAllList';
        $cacheExpire = 600;

        if (!empty($data['clearCache'])) {
            cache($cacheKey, null);
        }
        $cacheList = cache($cacheKey);

        if (empty($cacheList)) {
            //四宫格活动列表
            $otherActivityList = $model->cHomeOtherList($this->requestData);

            //普通活动列表
            $activityList = $model->cHomeList($this->requestData);

            //拼团活动列表
            $ptSear['page'] = 1;
            $ptSear['pageNumber'] = 3;
            if (empty($data['clearCache'])) {
                $ptSear['cache'] = 'ApiHomePtList';
                $ptSear['cache_expire'] = $cacheExpire;
            }
            $ptList = (new PtActivity())->cList($ptSear);

            //拼拼有礼活动列表
            $ptSear['page'] = 1;
            $ptSear['pageNumber'] = 3;
            if (empty($data['clearCache'])) {
                $ptSear['cache'] = 'ApiHomePpylList';
                $ptSear['cache_expire'] = $cacheExpire;
            }
            $ppylList = (new PpylActivity())->cList($ptSear);

            //分类列表
            $categorySear['type'] = 2;
            if (empty($data['clearCache'])) {
                $categorySear['cache'] = 'ApiHomeCategoryList';
                $categorySear['cache_expire'] = $cacheExpire;
            }
            $categoryList = (new Category())->list($categorySear);

//            //商品列表
//            $goodsSear['page'] = 1;
//            $goodsSear['pageNumber'] = 6;
//            if (empty($data['clearCache'])) {
//                $goodsSear['cache'] = 'ApiHomeGoodsList';
//                $goodsSear['cache_expire'] = $cacheExpire;
//            }
//            $goodsList = (new GoodsSpu())->shopList($goodsSear);

            //品牌馆列表
//            $brandData = $this->requestData;
//            $brandData['show_position'] = 4;
//            $brandSpaceList = $model->cHomeList($brandData);
//            //如果没有商品则剔除该品牌不做展示
//            if (!empty($brandSpaceList)) {
//                foreach ($brandSpaceList as $key => $value) {
//                    if (empty($value['goods'])) {
//                        unset($brandSpaceList[$key]);
//                    }
//                }
//                $brandSpaceList = array_values($brandSpaceList);
//            }

            //限时专场列表
            $specialAreaData = $this->requestData;
            $specialAreaData['show_position'] = 5;
            $specialAreaData['needGoodsSummary'] = true;
            $specialAreaList = $model->cHomeList($specialAreaData);

            //如果没有商品则剔除该限时专场不做展示
            if (!empty($specialAreaList)) {
                foreach ($specialAreaList as $key => $value) {
                    if (empty($value['goods'])) {
                        unset($specialAreaList[$key]);
                    }
                }
                $specialAreaList = array_values($specialAreaList);
            }


            $list['otherActivity'] = $otherActivityList ?? [];
            $list['activity'] = $activityList ?? [];
            $list['pt'] = $ptList['list'] ?? [];
            $list['ppyl'] = $ppylList['list'] ?? [];
            $list['category'] = $categoryList['list'] ?? [];
            $list['goods'] = $goodsList['list'] ?? [];
//            $list['brandSpace'] = $brandSpaceList ?? [];
            $list['specialArea'] = $specialAreaList ?? [];
            cache('ApiHomeAllList', $list, $cacheExpire, 'ApiHomeAllList');
        } else {
            $list = $cacheList;
        }
        return returnData($list);
    }

    /**
     * @title  首页数据合并接口
     * @return mixed
     * @throws \Exception
     */
    public function merge()
    {
        $all = [];
        $top = $this->topList()->getData();
        $list = $this->list()->getData();
        $all['top'] = $top['data'] ?? [];
        $all['list'] = $list['data'] ?? [];
        return returnData($all);
    }

    /**
     * @title  活动详情
     * @param Activity $model
     * @return string
     * @throws \Exception
     */
    public function activityInfo(Activity $model)
    {
        $list = $model->cInfo($this->requestData);
        return returnData($list);
    }

    /**
     * @title  团长大礼包列表
     * @param Activity $model
     * @return string
     * @throws \Exception
     */
    public function memberActivityList(Activity $model)
    {
        $list = $model->memberActivityList($this->requestData);
        return returnData($list);
    }

    /**
     * @title  优惠券/活动 面向对象列表
     * @param CouponUserType $model
     * @return string
     * @throws \Exception
     */
    public function userTypeList(CouponUserType $model)
    {
        $list = $model->list($this->requestData);
        return returnData($list['list'], $list['pageTotal']);
    }

    /**
     * @title  获取小程序码
     * @param Wx $service
     * @return string
     * @throws \OSS\Core\OssException
     */
    public function getWxaCode(Wx $service)
    {
        $data = $this->requestData;
        //如有生成动态参需要则在参数上拼接上动态参键名
        if (!empty($data['dynamic_params'] ?? null)) {
            $DynamicParams = (new DynamicParams())->create($data['dynamic_params']);
            if (!empty($DynamicParams)) {
                $data['content'] .= ($data['delimiter'] ?? '&');
                $data['content'] .= $DynamicParams;
            }
        }
        $info = $service->getWxaCode($data);
        return returnData($info);
    }

    /**
     * @title  品牌馆列表
     * @param Activity $model
     * @return string
     * @throws \Exception
     */
    public function brandSpaceList(Activity $model)
    {
        $list = $model->brandSpaceList($this->requestData);
        return returnData($list);
    }

    /**
     * @title  限时专场列表
     * @param Activity $model
     * @return string
     * @throws \Exception
     */
    public function specialAreaList(Activity $model)
    {
        $list = $model->specialAreaList($this->requestData);
        return returnData($list);
    }

    /**
     * @title  icon入口详情
     * @param Entrance $model
     * @return string
     */
    public function entrance(Entrance $model)
    {
        $info = $model->info($this->requestData);
        return returnData($info);
    }

    /**
     * @title  热销排行列表
     * @param OrderGoods $model
     * @return string
     * @throws \Exception
     */
    public function hotSaleList(OrderGoods $model)
    {
        $data = $this->requestData;
        $data['orderType'] = 2;
        $list = $model->hotSaleList($data);
        return returnData($list['list'], $list['pageTotal']);
    }

    /**
     * @title  兑换商品列表
     * @param ExchangeGoods $model
     * @return string
     * @throws \Exception
     */
    public function exchangeList(ExchangeGoods $model)
    {
        $data = $this->requestData;
        $list = $model->cList($data);
        return returnData($list['list'], $list['pageTotal']);
    }

    /**
     * @title  创建新的动态参
     * @param DynamicParams $service
     * @return string
     */
    public function newDynamicParams(DynamicParams $service)
    {
        $DynamicParams = $service->create($this->requestData);
        return returnData($DynamicParams);
    }

    /**
     * @title  获取动态参内容
     * @param DynamicParams $service
     * @return string
     */
    public function getDynamicParams(DynamicParams $service)
    {
        $DynamicParams = $service->verify($this->request->param('key'));
        return returnData($DynamicParams);
    }

    /**
     * @title  生成二维码
     * @param QrCode $service
     * @return string
     */
    public function buildQrCode(QrCode $service)
    {
        $data = $this->requestData;
        $qrCode = $service->create($data['content']);
        return returnData($qrCode);
    }

    /**
     * @title 特殊支付方式活动列表
     * @param Activity $model
     * @return string
     * @throws \Exception
     */
    public function specialPayActivityList(Activity $model)
    {
        $list = $model->specialPayList($this->requestData);
        return returnData($list);
    }


}