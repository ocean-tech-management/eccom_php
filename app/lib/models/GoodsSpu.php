<?php
// +----------------------------------------------------------------------
// |[ 文档说明: ]
// +----------------------------------------------------------------------
// | Copyright (c) 2018~2024 http://www.mlhcmk.com All rights reserved.
// +----------------------------------------------------------------------


namespace app\lib\models;


use app\BaseModel;
use app\lib\exceptions\AuthException;
use app\lib\exceptions\CrowdFundingActivityException;
use app\lib\exceptions\OpenException;
use app\lib\exceptions\OrderException;
use app\lib\exceptions\ServiceException;
use app\lib\exceptions\ShipException;
use app\lib\services\CodeBuilder;
use app\lib\validates\GoodsSpu as GoodsSpuValidate;
use think\facade\Cache;
use think\facade\Db;
use function Complex\theta;

class GoodsSpu extends BaseModel
{
    protected $field = ['goods_sn', 'main_image', 'title', 'sub_title', 'brand_code', 'category_code', 'attribute_list', 'link_product_id', 'saleable', 'unit', 'desc', 'belong', 'status', 'show_status', 'up_type', 'up_time', 'down_time', 'postage_code', 'tag_id', 'supplier_code', 'goods_code', 'related_recommend_goods', 'params', 'warehouse_icon', 'goods_videos', 'intro_video'];
    protected $validateFields = ['title', 'main_image'];
    private $belong = 1;

    /**
     * @title  商品列表
     * @param array $sear
     * @return array
     * @throws \Exception
     */
    public function list(array $sear = []): array
    {
        if (!empty($sear['keyword'])) {
            $map[] = ['', 'exp', Db::raw($this->getFuzzySearSql('title|goods_code', $sear['keyword']))];
        }
        if (!empty($sear['category_code'])) {
            $allCategory = Category::where(function ($query) use ($sear) {
                $mapOr[] = ['p_code', '=', $sear['category_code']];
                $map[] = ['code', '=', $sear['category_code']];
                $query->where($map)->cache('goodsSpuCategoryList')->whereOr([$mapOr]);
            })->where(['status' => 1])->column('code');
            if (!empty($allCategory)) {
                $map[] = ['category_code', 'in', $allCategory];
            }
        }
        if (!empty($sear['brand_code'])) {
            $map[] = ['brand_code', '=', $sear['brand_code']];
        }

        if (!empty($sear['goods_code'])) {
            $map[] = ['', 'exp', Db::raw($this->getFuzzySearSql('goods_code', $sear['goods_code']))];
        }

        if (!empty($sear['belong'])) {
            $map[] = ['belong', '=', $sear['belong']];
        } else {
            $map[] = ['belong', '=', $this->belong];
        }

        if (!empty($sear['goods_sn'])) {
            if (is_array($sear['goods_sn'])) {
                $map[] = ['goods_sn', 'in', $sear['goods_sn']];
            } else {
                $map[] = ['goods_sn', '=', $sear['goods_sn']];
            }
        }

        if (!empty($sear['adminInfo'])) {
            if ($sear['adminInfo']['type'] == 3) {
                if (empty($sear['adminInfo']['supplier_code'])) {
                    throw new AuthException(['errorCode' => 2200105]);
                }
                $map[] = ['supplier_code', '=', $sear['adminInfo']['supplier_code']];
            } else {
                if (!empty($sear['supplier_code'])) {
                    $map[] = ['supplier_code', '=', $sear['supplier_code']];
                }
            }
        }
        $map[] = ['status', 'in', $this->getStatusByRequestModule($sear['searType'] ?? 2)];

        switch ($sear['sortType'] ?? null) {
            case 1:
                $sortField = 'create_time desc,sort asc';
                break;
            case 2:
                $sortField = 'sort asc,create_time desc';
                break;
            default:
                $sortField = 'create_time desc,sort asc';
        }

        $page = intval($sear['page'] ?? 0) ?: null;
        if (!empty($sear['pageNumber'] ?? null)) {
            $this->pageNumber = intval($sear['pageNumber']);
        }
        if (!empty($page)) {
            $aTotal = $this->where($map)->count();
            $pageTotal = ceil($aTotal / $this->pageNumber);
        }

        $list = $this->with(['category', 'tag','priceMaintain'])->where($map)->field('goods_sn,goods_code,title,main_image,category_code,brand_code,link_product_id,unit,belong,status,create_time,show_status,sort')
            ->withSum(['payOrder' => 'sell_number'], 'count')
            ->withSum(['payOrder' => 'sell_price'], 'real_pay_price')
            ->when($page, function ($query) use ($page) {
                $query->page($page, $this->pageNumber);
            })->order($sortField)->select()->each(function ($item) {
                $item['sell_order_number'] = 0;
                $item['sku_sell_order_number'] = 0;
                if (empty($item['sell_number'])) {
                    $item['sell_number'] = 0;
                }
                if (empty($item['sell_price'])) {
                    $item['sell_price'] = 0.00;
                }
                $item['priceMaintainStatus'] = 1;
                return $item;
            })->toArray();

        if (!empty($list)) {
            foreach ($list as $key => $value) {
                if(!empty($value['priceMaintain'] ?? [])){
                    $list[$key]['priceMaintainStatus'] = 2;
                }
            }
            $aCategory = array_column($list, 'category_code');
            $pCategory = Category::with(['parent'])->where(['code' => $aCategory, 'status' => $this->getStatusByRequestModule($sear['searType'] ?? 1)])->select()->toArray();
            $allGoodsSn = array_unique(array_column($list, 'goods_sn'));
            if (!empty($pCategory)) {
                foreach ($list as $key => $value) {
                    foreach ($pCategory as $pcKey => $pcValue) {
                        if ($value['category_code'] == $pcValue['code']) {
                            if (!empty($pcValue['parent'])) {
                                $list[$key]['p_category_code'] = $pcValue['parent']['code'];
                                $list[$key]['p_category_name'] = $pcValue['parent']['name'];
                            }
                        }
                    }
                }
            }
            if (!empty($sear['needActivity'])) {
                if (!empty($allGoodsSn)) {
                    $activityGoods = ActivityGoods::with(['activity'])->where(['goods_sn' => $allGoodsSn, 'status' => [1, 2]])->field('goods_sn,activity_id,limit_type,start_time,end_time')->select()->toArray();
                    if (!empty($activityGoods)) {
                        foreach ($list as $key => $value) {
                            $list[$key]['activity'] = [];
                            foreach ($activityGoods as $aKey => $aValue) {
                                if ($aValue['goods_sn'] == $value['goods_sn'] && !empty($aValue['activity'])) {
                                    $list[$key]['activity'][] = $aValue['activity'] ?? [];
                                }
                            }
                        }
                    }
                }
            }
            //统计正确的商品销售数量
            $list = $this->realSalesInfoForSpu(['list' => $list, 'sear' => $sear ?? []]);

        }

        return ['list' => $list, 'pageTotal' => $pageTotal ?? 0, 'total' => $aTotal ?? 0];
    }

    /**
     * @title  商城商品列表
     * @param array $sear
     * @return mixed
     * @throws \Exception
     */
    public function shopList(array $sear = [])
    {
        $cacheKey = $sear['cache'] ?? 'ApiHomeGoodsList';
        $cacheExpire = $sear['cache_expire'] ?? 600;
        $cacheTag = 'apiHomeGoodsList';

        if (!empty($sear['keyword'])) {
            $map[] = ['', 'exp', Db::raw($this->getFuzzySearSql('title', $sear['keyword']))];
        }
        if (!empty($sear['category_code'])) {
            $allCategory = Category::where(function ($query) use ($sear) {
                $mapOr[] = ['p_code', '=', $sear['category_code']];
                $map[] = ['code', '=', $sear['category_code']];
                $query->where($map)->cache('goodsSpuCategoryList')->whereOr([$mapOr]);
            })->where(['status' => 1])->column('code');
            if (!empty($allCategory)) {
                $map[] = ['category_code', 'in', $allCategory];
            }
        }

        if (!empty($sear['brand_code'])) {
            $map[] = ['brand_code', '=', $sear['brand_code']];
        }

        if (!empty($sear['show_status'])) {
            $map[] = ['show_status', 'in', $sear['show_status']];
        } else {
            $map[] = ['show_status', 'in', [1, 2]];
        }

        $map[] = ['belong', '=', 1];
        $map[] = ['status', 'in', $this->getStatusByRequestModule($sear['searType'] ?? 2)];


        $page = intval($sear['page'] ?? 0) ?: null;
        if (!empty($sear['pageNumber'])) {
            $this->pageNumber = intval($sear['pageNumber']);
        }

        if ($this->module = 'api') {
            if (empty($sear['clearCache'])) {
                if (!empty($sear['keyword'])) {
                    $cacheKey .= '-' . $sear['keyword'];
                }
                if (!empty($sear['brand_code'])) {
                    $cacheKey .= '-' . $sear['brand_code'];
                }
                if (!empty($sear['category_code'])) {
                    $cacheKey .= '-' . $sear['category_code'];
                }
                $cacheKey .= '-page' . $page . '-pageNumber' . $this->pageNumber;

                $cacheList = cache($cacheKey);

                if (!empty($cacheList)) {
                    return $cacheList;
                }
            }
        }

        if (!empty($page)) {
            $aTotal = $this->where($map)
//                ->when($cacheKey,function($query) use ($cacheKey,$cacheExpire){
//                $query->cache($cacheKey.'Num',$cacheExpire);
//            })
                ->count();
            $pageTotal = ceil($aTotal / $this->pageNumber);
        }

        $list = $this->with(['tag'])->where($map)->field('goods_sn,goods_code,title,sub_title,main_image,category_code,brand_code,unit,tag_id,create_time')->withMin('sku', 'sale_price')->withMin(['sku' => 'market_price_min'], 'market_price')->withCount(['payOrder'])->withSum(['sku' => 'stock'], 'stock')->when($page, function ($query) use ($page) {
            $query->page($page, $this->pageNumber);
        })->order('sort asc,create_time desc')->select()->toArray();

        if (!empty($list)) {
            //图片展示缩放,OSS自带功能
            foreach ($list as $key => $value) {
//                $list[$key]['main_image'] .= '?x-oss-process=image/resize,h_400,m_lfit';
                $list[$key]['main_image'] .= '?x-oss-process=image/format,webp';
            }

            cache($cacheKey, ['list' => $list, 'pageTotal' => $pageTotal ?? 0], $cacheExpire, $cacheTag);
        }

        return ['list' => $list, 'pageTotal' => $pageTotal ?? 0];
    }

    /**
     * @title  商城商品详情
     * @param array $data
     * @return array|mixed
     * @throws \Exception
     */
    public function shopGoodsInfo(array $data)
    {
        $goodsSn = trim($data['goods_sn']);
        $spuInfo = [];
        $cacheTag = config('cache.systemCacheKey.apiGoodsInfo.key') . $goodsSn;
        $cacheKey = $cacheTag . ($data['getReadyInfo'] ?? '') . ($data['activity_type'] ?? '') . ($data['activity_id'] ?? '');
        $cacheExpire = 1800;

        if (empty($data['clearCache'])) {
            if (!empty(cache($cacheKey))) {
                $spuInfo = cache($cacheKey);
            }
        }

        if (empty($spuInfo)) {
            $spuInfo = $this->with(['shopSku', 'goodsImagesApi', 'shopSkuVdc', 'goodsDetailImagesApi', 'tag'])->where(['goods_sn' => $goodsSn, 'status' => [1, 2], 'saleable' => 1])->field('goods_sn,goods_code,main_image,title,sub_title,desc,unit,tag_id,category_code,status,related_recommend_goods,params,warehouse_icon,intro_video,goods_videos')->findOrEmpty()->toArray();
        }

//        if(empty($spuInfo)){
//            throw new ServiceException(['msg'=>'该商品已经下架啦~']);
//        }

        if (!empty($spuInfo)) {
            //若现在商品价格正处于维护中时,不允许下单,库存默认全部为0,只有重新锁定价格后才能正常售卖
            if (!empty($spuInfo['shopSku'] ?? [])) {
                foreach ($spuInfo['shopSku'] as $key => $value) {
                    if ($value['price_maintain'] == 2) {
                        $spuInfo['shopSku'][$key]['stock'] = 0;
                    }
                }
            }

            //添加商品缓存标识
            cache($cacheKey, $spuInfo, $cacheExpire, $cacheTag);

            //获取活动预热信息
            if (!empty($data['getReadyInfo'] ?? null)) {
                $spuInfo['readyInfo'] = $this->getWarmUpActivityInfo(['type' => $data['activity_type'], 'activity_id' => $data['activity_id'], 'goods_sn' => $goodsSn]);
            }
            //获取商品参加活动的详情
//            $data['spuInfo'] = $spuInfo;
//            $spuInfo = $this->getPpylActivityInfo($data);
//            $data['spuInfo'] = $spuInfo;
//            $spuInfo = $this->getPtActivityInfo($data);
            $data['spuInfo'] = $spuInfo;
            $spuInfo = $this->getCrowdActivityInfo($data);
            $data['spuInfo'] = $spuInfo;
            $spuInfo = $this->getActivityInfo($data);

            //取出价格范围
            $allPrice = array_column($spuInfo['shopSku'], 'sale_price');
            if (empty($allPrice)) {
                $spuInfo['price_range'] = '商品暂未开放购买,请勿拍下';
            } else {
                $minPrice = min($allPrice);
                $maxPrice = max($allPrice);
                if ($minPrice == $maxPrice) {
                    $spuInfo['price_range'] = $minPrice;
                } else {
                    $spuInfo['price_range'] = $minPrice . '-' . $maxPrice;
                }
            }

            if (!empty($spuInfo['goods_videos'])) {
                $spuInfo['goods_videos'] = json_decode($spuInfo['goods_videos'], true);
            }


            //邮费模版读取
            $userAddressArea = $data['city'] ?? null;
            $allPostageTemplateCode = array_column($spuInfo['shopSku'], 'postage_code');
            //如果无地址或查无运费模版的时候默认包邮
            if (empty($userAddressArea) || empty($allPostageTemplateCode)) {
                foreach ($spuInfo['shopSku'] as $key => $value) {
                    $spuInfo['shopSku'][$key]['fare'] = 0;
                    $spuInfo['shopSku'][$key]['free_shipping'] = 1;
                }
            } else {
                if (!empty($allPostageTemplateCode)) {
                    $templateInfo = (new PostageTemplate())->list(['code' => $allPostageTemplateCode, 'notFormat' => true, 'cache' => 'goodsInfoPostageTemplate-' . implode(',', $allPostageTemplateCode), 'cache_expire' => 120])['list'] ?? [];

                    if (!empty($templateInfo)) {
                        foreach ($spuInfo['shopSku'] as $key => $value) {
                            if (!isset($spuInfo['shopSku']['free_shipping'])) {
                                $spuInfo['shopSku'][$key]['free_shipping'] = 1;
                            }
                            $defaultFare = [];
                            foreach ($templateInfo as $tKey => $tValue) {
                                if ($tValue['free_shipping'] == 2) {
                                    if ($value['postage_code'] == $tValue['code'] && !empty($tValue['detail'])) {
                                        foreach ($tValue['detail'] as $cKey => $cValue) {
                                            if ($cValue['type'] == 1) {
                                                $defaultFare = $cValue;
                                            }
                                            if (in_array($userAddressArea, $cValue['city_code'])) {
                                                $spuInfo['shopSku'][$key]['fare'] = $cValue['default_price'];
                                                $spuInfo['shopSku'][$key]['exist_fare'] = true;
                                            }
                                        }
                                        //如果一直都没有找到匹配城市则使用默认运费模版
                                        if (empty(doubleval($spuInfo['shopSku'][$key]['fare'])) && (empty($spuInfo['shopSku'][$key]['exist_fare'])) && !empty($defaultFare)) {
                                            $spuInfo['shopSku'][$key]['fare'] = $defaultFare['default_price'];
                                        }

                                    }
                                }
                            }


                            $spuInfo['shopSku'][$key]['free_shipping'] = (empty(doubleval($spuInfo['shopSku'][$key]['fare']))) ? 1 : 2;

                        }
                    }
                }
            }

            //判断是否需要包邮 1是 2否 2部分包邮
            $shipping = array_unique(array_column($spuInfo['shopSku'], 'free_shipping'));
            $spuInfo['free_shipping'] = reset($shipping);
            if (count($shipping) == 2) {
                $spuInfo['free_shipping'] = 3;
            }

            //修改描述富文本里面图片地址格式为webp
//            if(!empty($spuInfo['desc'])){
//                $spuInfo['desc'] = str_replace('.jpg','.jpg?x-oss-process=image/format,webp',$spuInfo['desc']);
//                $spuInfo['desc'] = str_replace('.png','.png?x-oss-process=image/format,webp',$spuInfo['desc']);
//            }
            if (!empty($spuInfo['main_image'])) {
                $spuInfo['main_image'] .= '?x-oss-process=image/quality,q_95';
            }

            //修改SKU的图片为webp
            if (!empty($spuInfo['shopSku'])) {
                foreach ($spuInfo['shopSku'] as $key => $value) {
                    if (!empty($value['image'])) {
                        $spuInfo['shopSku'][$key]['image'] .= '?x-oss-process=image/quality,q_100';
                    }
                    $spuInfo['shopSku'][$key]['exist_exchange'] = false;
                }
            }

            //拼回图片的地址
            if (!empty($spuInfo['goodsImagesApi'])) {
                $imageDomain = config('system.imgDomain');
                foreach ($spuInfo['goodsImagesApi'] as $key => $value) {
                    $spuInfo['goodsImagesApi'][$key]['image_url'] = substr_replace($value['image_url'], $imageDomain, strpos($value['image_url'], '/'), strlen('/'));
                    if (!empty($spuInfo['goodsImagesApi'][$key]['image_url'])) {
                        $spuInfo['goodsImagesApi'][$key]['image_url'] .= '?x-oss-process=image/quality,q_95';
                    }
                }
                $spuInfo['goodsImages'] = $spuInfo['goodsImagesApi'];
                unset($spuInfo['goodsImagesApi']);
            }

            if (!empty($spuInfo['goodsDetailImagesApi'])) {
                $imageDomain = config('system.imgDomain');
                foreach ($spuInfo['goodsDetailImagesApi'] as $key => $value) {
                    $spuInfo['goodsDetailImagesApi'][$key]['image_url'] = substr_replace($value['image_url'], $imageDomain, strpos($value['image_url'], '/'), strlen('/'));
                }
                $spuInfo['goodsDetailImages'] = $spuInfo['goodsDetailImagesApi'];
                unset($spuInfo['goodsDetailImagesApi']);
            }

            $spuInfo['uid'] = $data['uid'] ?? null;
            //获取用户信息
            if (!empty($data['uid'])) {
                $userInfo = (new User())->with(['address'])->where(['uid' => $data['uid'], 'status' => 1])->field('uid,name,integral,vip_level,link_superior_user,c_vip_level,c_vip_time_out_time,auto_receive_reward')->findOrEmpty()->toArray();
                $spuInfo['user'] = $userInfo;
                $memberTitle = MemberVdc::where(['status' => 1])->order('level asc')->column('name', 'level');
                //修改不同会员不同成本价
                if (!empty($userInfo['vip_level'])) {
                    foreach ($spuInfo['shopSku'] as $key => $value) {
                        $spuInfo['shopSku'][$key]['show_vdc'] = [];
                        foreach ($spuInfo['shopSkuVdc'] as $vKey => $vValue) {
                            if ($vValue['sku_sn'] == $value['sku_sn']) {
                                if ($vValue['level'] == $userInfo['vip_level']) {
                                    $spuInfo['shopSku'][$key]['show_vdc'][$vValue['level']]['title'] = $memberTitle[$vValue['level']];
                                    $spuInfo['shopSku'][$key]['show_vdc'][$vValue['level']]['price'] = $vValue['purchase_price'];
                                    $spuInfo['shopSku'][$key]['show_vdc'][$vValue['level']]['level'] = $vValue['level'];

                                    $spuInfo['shopSku'][$key]['sale_price'] = $vValue['purchase_price'];
                                    $spuInfo['shopSku'][$key]['pt_sale_price'] = $vValue['purchase_price'];
                                } elseif ($vValue['level'] > $userInfo['vip_level']) {
                                    $spuInfo['shopSku'][$key]['show_vdc'][$vValue['level']]['title'] = $memberTitle[$vValue['level']];
                                    $spuInfo['shopSku'][$key]['show_vdc'][$vValue['level']]['price'] = $vValue['purchase_price'];
                                    $spuInfo['shopSku'][$key]['show_vdc'][$vValue['level']]['level'] = $vValue['level'];
                                }
                            }
                        }

                        if (!empty($spuInfo['shopSku'][$key]['show_vdc'])) {
                            array_multisort(array_column($spuInfo['shopSku'][$key]['show_vdc'], 'price'), SORT_ASC, $spuInfo['shopSku'][$key]['show_vdc']);
                        }
                    }

                }
                //判断用户是否为口碑评价官
                $spuInfo['user']['reputation_user'] = false;
                $reputation = ReputationUser::where(['uid' => $data['uid'], 'status' => 1])->cache('goodsInfoReputationUser-' . $data['uid'], 300)->count();
                if (!empty($reputation)) {
                    $spuInfo['user']['reputation_user'] = true;
                }
            }
            unset($spuInfo['shopSkuVdc']);

            $spuInfo['could_add_car'] = true;
            //查看商品是否存在于兑换模块, 如果存在则不允许加购
//            $existExchange = ExchangeGoodsSku::where(['sku_sn' => array_column($spuInfo['shopSku'], 'sku_sn'), 'status' => 1])->field('goods_sn,sku_sn,type')->column('sku_sn');
            if (!empty(cache('goodsInfoExchangeGoods-' . $data['goods_sn']))) {
                $existExchange = cache('goodsInfoExchangeGoods-' . $data['goods_sn']);
            } else {
                $existExchange = ExchangeGoodsSku::where(['sku_sn' => array_column($spuInfo['shopSku'], 'sku_sn'), 'status' => 1])->field('goods_sn,sku_sn,type')->column('sku_sn');
                if (!empty($existExchange)) {
                    cache('goodsInfoExchangeGoods-' . $data['goods_sn'], $existExchange, 60);
                }
            }

            if (!empty($existExchange)) {
                foreach ($spuInfo['shopSku'] as $key => $value) {
                    if(in_array($value['sku_sn'],$existExchange)){
                        $spuInfo['shopSku'][$key]['exist_exchange'] = true;
                    }
                }
            }
            //默认支付方式
            $spuInfo['default_pay_type'] = (new \app\lib\services\Order())->defaultPayType;

//            $attribute_list = json_decode($spuInfo['attribute_list'],1);;
//            //获取每个SKU下对应的全部规格属性
//            $skuSpec = [];
//            foreach ($attribute_list as $spec => $specArr) {
//                foreach ($specArr as $attr => $skuArr) {
//                    foreach ($skuArr as $sku) {
//                        $skuSpec[$sku]['allAttr'][] = $attr;
//                    }
//                }
//            }
//            //获取每个SKU下对应的当前的库存及价格
//            foreach ($spuInfo['skuPrice'] as $key => $value) {
//                foreach ($skuSpec as $skuKey => $skuValue) {
//                    if($value['sku_sn'] == $skuKey){
//                        $skuSpec[$skuKey]['info']['stock'] = $value['stock'];
//                        $skuSpec[$skuKey]['info']['sale_price'] = $value['sale_price'];
//                        $skuSpec[$skuKey]['info']['member_price'] = $value['member_price'];
//                    }
//                }
//            }
//            $spuInfo['sku_attr'] = $skuSpec;
//
//            //获取SKU规格
//            foreach ($attribute_list as $key => $attr) {
//                foreach ($attr as $attrKey => $attrValue) {
//                    foreach ($attrValue as $k => $v) {
//                        unset($attribute_list[$key][$attrKey][$k]);
//                    }
//                }
//            }
//            $spuInfo['attr'] = $attribute_list;
//            unset($spuInfo['skuPrice']);
        }
        return $spuInfo;
    }

    /**
     * @title  获取商品参加拼拼有礼活动的详情
     * @param array $data
     * @return mixed
     * @throws \Exception
     */
    public function getPpylActivityInfo(array $data)
    {
        $spuInfo = $data['spuInfo'];
        $list = $spuInfo['shopSku'];
        if (!empty($list)) {
            $spuInfo['exist_activity'] = false;
            $spuInfo['exist_ppyl_activity'] = false;

            foreach ($list as $key => $value) {
                $list[$key]['exist_ppyl_activity'] = false;
                $list[$key]['ppyl_activity_info'] = [];
                $list[$key]['ppyl_start_time'] = null;
                $list[$key]['ppyl_end_time'] = null;
                $list[$key]['ppyl_stock'] = 0;
                $list[$key]['ppyl_reward_price'] = 0;
            }

            //查找是否有在符合时间内的拼拼有礼商品
//            $ptMap[] = ['start_time','<=',time()];
            $ptMap[] = ['end_time', '>', time()];
            $ptMap[] = ['status', '=', 1];
            $ptMap[] = ['goods_sn', '=', $spuInfo['goods_sn']];

            $existPt = PpylGoods::where($ptMap)->field('activity_code,area_code,id,goods_sn')->select()->toArray();

            if (empty(count($existPt))) {
                $spuInfo['shopSku'] = $list;
                return $spuInfo;
            }

            $skuSn = array_column($list, 'sku_sn');
            //拼拼有礼活动
            $ptActivity = PpylGoodsSku::with(['activity', 'goodsSpu', 'area'])->where(['activity_code' => current(array_column($existPt, 'activity_code')), 'area_code' => current(array_column($existPt, 'area_code')), 'status' => 1])->field('goods_sn,sku_sn,specs,activity_price,stock,activity_code,area_code,reward_price')->order('activity_price asc')->select()->toArray();

            foreach ($list as $key => $value) {
                if (!empty($ptActivity)) {
                    $list[$key]['exist_ppyl_activity'] = false;
                    foreach ($ptActivity as $aKey => $aValue) {
                        if ($aValue['sku_sn'] == $value['sku_sn']) {
                            $list[$key]['ppyl_reward_price'] = $aValue['reward_price'] ?? 0;
                            //拼团情况如果活动库存为0则不改变价格
//                            $list[$key]['sku_stock'] = $value['stock'];
                            if (empty($aValue['stock']) || $aValue['stock'] < 0) {
                                $list[$key]['ppyl_stock'] = 0;
                                continue;
                            } else {
                                $list[$key]['ppyl_stock'] = $aValue['stock'];
                            }
                            if (empty($list[$key]['ppyl_activity_info']) && !empty($aValue['activity'])) {
                                if ((string)$aValue['activity_price'] <= (string)$value['sale_price']) {
                                    $spuInfo['exist_activity'] = true;
                                    $spuInfo['exist_ppyl_activity'] = true;
                                    $list[$key]['aType'] = 4;
                                    $aValue['activity']['access_goods_number'] = 0;
                                    $aValue['area']['access_goods_number'] = 0;
                                    $list[$key]['ppyl_sale_price'] = $aValue['activity_price'];
                                    $list[$key]['ppyl_original_price'] = $aValue['activity_price'];
                                    $list[$key]['ppyl_activity_info']['activity'] = $aValue['activity'];
                                    $list[$key]['ppyl_activity_info']['area'] = $aValue['area'];
                                    $list[$key]['exist_ppyl_activity'] = true;
                                    $list[$key]['ppyl_start_time'] = $aValue['goodsSpu']['start_time'];
                                    $list[$key]['ppyl_end_time'] = $aValue['goodsSpu']['end_time'];
                                    if (is_numeric($aValue['goodsSpu']['start_time'])) {
                                        $list[$key]['ppyl_start_time'] = timeToDateFormat($aValue['goodsSpu']['ppyl_start_time']);
                                    }
                                    if (is_numeric($aValue['goodsSpu']['end_time'])) {
                                        $list[$key]['ppyl_end_time'] = timeToDateFormat($aValue['goodsSpu']['ppyl_end_time']);
                                    }
//                                    $list[$key]['start_time'] = $aValue['activity']['start_time'];
//                                    $list[$key]['end_time'] = $aValue['activity']['end_time'];
                                    $list[$key]['activity_sign'] = null;
                                    $list[$key]['ppyl_activity_sign'] = $aValue['activity_code'];
                                    $joinPtSku[] = $value['sku_sn'];
                                }
                            }
                        } else {
                            $needFindActivitySku[] = $value['sku_sn'];
                        }
                    }
                }
            }

            if (!empty($joinPtSku)) {
                $joinPtSku = array_unique(array_filter($joinPtSku));
                $allPtGoods = PpylOrder::where(['sku_sn' => $joinPtSku, 'pay_status' => [2], 'activity_status' => [1, 2]])->field('goods_sn,sku_sn')->select()->toArray();
                if (!empty($allPtGoods)) {
                    foreach ($list as $key => $value) {
                        foreach ($allPtGoods as $aKey => $aValue) {
                            if ($aValue['sku_sn'] == $value['sku_sn']) {
                                $list[$key]['ppyl_activity_info']['activity']['access_goods_number'] += 1;
                                $list[$key]['ppyl_activity_info']['area']['access_goods_number'] += 1;
                            }
                        }
                    }
                }
            }
        }

        $spuInfo['shopSku'] = $list;

        return $spuInfo;
    }

    /**
     * @title  获取商品参加拼团活动的详情
     * @param array $data
     * @return mixed
     * @throws \Exception
     */
    public function getPtActivityInfo(array $data)
    {
        $spuInfo = $data['spuInfo'];
        $list = $spuInfo['shopSku'];
        if (!empty($list)) {
            $spuInfo['exist_activity'] = false;
            $spuInfo['exist_pt_activity'] = false;

            foreach ($list as $key => $value) {
                $list[$key]['exist_pt_activity'] = false;
                $list[$key]['pt_activity_info'] = [];
                $list[$key]['pt_start_time'] = null;
                $list[$key]['pt_end_time'] = null;
                $list[$key]['pt_stock'] = 0;
            }

            //查找是否有在符合时间内的拼团商品
//            $ptMap[] = ['start_time','<=',time()];
            $ptMap[] = ['end_time', '>', time()];
            $ptMap[] = ['status', '=', 1];
            $ptMap[] = ['goods_sn', '=', $spuInfo['goods_sn']];

            $existPt = PtGoods::where($ptMap)->field('activity_code,id,goods_sn')->select()->toArray();

            if (empty(count($existPt))) {
                $spuInfo['shopSku'] = $list;
                return $spuInfo;
            }

            $skuSn = array_column($list, 'sku_sn');
            //优先拼团活动
            $ptActivity = PtGoodsSku::with(['activity', 'goodsSpu'])->where(['activity_code' => current(array_column($existPt, 'activity_code')), 'status' => 1])->field('goods_sn,sku_sn,specs,activity_price,stock,activity_code')->order('activity_price asc')->select()->toArray();

            if (!empty($ptActivity)) {
                $firstPtActivity = current($ptActivity);
                if ($firstPtActivity['activity']['type'] == 3) {
                    $userLevel = (new User())->where(['uid' => $data['uid'], 'status' => 1])->field('vip_level,link_superior_user,parent_team')->findOrEmpty()->toArray();
                    if (!empty($userLevel['vip_level'])) {
                        throw new OrderException(['msg' => '您已经是会员啦~不需要再购买团长大礼包了~']);
                    }
                    if (!empty($userLevel['link_superior_user']) && !empty($userLevel['parent_team'])) {
                        throw new OrderException(['msg' => '您已经存在绑定的上级代理啦~请耐心等待系统处理']);
                    }
                }
            }

            foreach ($list as $key => $value) {
                if (!empty($ptActivity)) {
                    $list[$key]['exist_pt_activity'] = false;
                    foreach ($ptActivity as $aKey => $aValue) {
                        if ($aValue['sku_sn'] == $value['sku_sn']) {
                            //拼团情况如果活动库存为0则不改变价格
//                            $list[$key]['sku_stock'] = $value['stock'];
                            if (empty($aValue['stock']) || $aValue['stock'] < 0) {
                                $list[$key]['pt_stock'] = 0;
                                continue;
                            } else {
                                $list[$key]['pt_stock'] = $aValue['stock'];
                            }
                            if (empty($list[$key]['pt_activity_info']) && !empty($aValue['activity'])) {
                                if ((string)$aValue['activity_price'] <= (string)$value['sale_price']) {
                                    $spuInfo['exist_activity'] = true;
                                    $spuInfo['exist_pt_activity'] = true;
                                    $list[$key]['aType'] = 2;
                                    $aValue['activity']['access_goods_number'] = 0;
                                    $list[$key]['pt_sale_price'] = $aValue['activity_price'];
                                    $list[$key]['pt_original_price'] = $aValue['activity_price'];
                                    $list[$key]['pt_activity_info'] = $aValue['activity'];
                                    $list[$key]['exist_pt_activity'] = true;
                                    $list[$key]['pt_start_time'] = $aValue['goodsSpu']['start_time'];
                                    $list[$key]['pt_end_time'] = $aValue['goodsSpu']['end_time'];
                                    if (is_numeric($aValue['goodsSpu']['start_time'])) {
                                        $list[$key]['pt_start_time'] = timeToDateFormat($aValue['goodsSpu']['pt_start_time']);
                                    }
                                    if (is_numeric($aValue['goodsSpu']['end_time'])) {
                                        $list[$key]['pt_end_time'] = timeToDateFormat($aValue['goodsSpu']['pt_end_time']);
                                    }
//                                    $list[$key]['start_time'] = $aValue['activity']['start_time'];
//                                    $list[$key]['end_time'] = $aValue['activity']['end_time'];
                                    $list[$key]['activity_sign'] = null;
                                    $list[$key]['pt_activity_sign'] = $aValue['activity_code'];
                                    $joinPtSku[] = $value['sku_sn'];
                                }
                            }
                        } else {
                            $needFindActivitySku[] = $value['sku_sn'];
                        }
                    }
                }
            }

            if (!empty($joinPtSku)) {
                $joinPtSku = array_unique(array_filter($joinPtSku));
                $allPtGoods = PtOrder::where(['sku_sn' => $joinPtSku, 'pay_status' => [2], 'activity_status' => [1, 2]])->field('goods_sn,sku_sn')->select()->toArray();
                if (!empty($allPtGoods)) {
                    foreach ($list as $key => $value) {
                        foreach ($allPtGoods as $aKey => $aValue) {
                            if ($aValue['sku_sn'] == $value['sku_sn']) {
                                $list[$key]['pt_activity_info']['access_goods_number'] += 1;
                            }
                        }
                    }
                }
            }
        }

        $spuInfo['shopSku'] = $list;

        return $spuInfo;
    }

    /**
     * @title  获取商品参加活动的详情
     * @param array $data
     * @return mixed
     * @throws \Exception
     */
    public function getActivityInfo(array $data)
    {
        $spuInfo = $data['spuInfo'];
        $list = $spuInfo['shopSku'];

        if (!empty($list)) {
            if (!isset($spuInfo['exist_activity']) && !empty($spuInfo['exist_activity'])) {
                $spuInfo['exist_activity'] = false;
            }
            $spuInfo['exist_normal_activity'] = false;
            $aMap = [];
            $aMapOr = [];
            $selectActivity = false;
            //如果有指定活动id则专门找指定活动id
            if (!empty($data['activity_id']) && (!empty($data['activity_type']) && $data['activity_type'] == 1)) {
                $aMap[] = ['activity_id', '=', $data['activity_id']];
                $aMapOr[] = ['activity_id', '=', $data['activity_id']];
                $selectActivity = true;
            }
            //查找是否有在符合时间内的活动商品
            $aMap[] = ['status', '=', 1];
            $aMap[] = ['goods_sn', '=', $spuInfo['goods_sn']];
            $aMap[] = ['limit_type', '=', 2];
            $existGoods = ActivityGoods::where(function ($query) use ($aMap, $spuInfo, $aMapOr, $selectActivity) {
                $aMapOr[] = ['status', '=', 1];
                $aMapOr[] = ['goods_sn', '=', $spuInfo['goods_sn']];
                $aMapOr[] = ['limit_type', '=', 1];
//                if(empty($selectActivity)){
//                    $aMapOr[] = ['start_time','<=',time()];
                $aMapOr[] = ['end_time', '>', time()];
//                }
                $query->where($aMap)->whereOr([$aMapOr]);
            })->column('goods_sn');

            $existGoods = array_unique($existGoods);

            $skuSn = array_column($list, 'sku_sn');
            $needFindActivitySku = $skuSn;

            foreach ($list as $key => $value) {
                $list[$key]['activity_info'] = [];
                $list[$key]['exist_activity'] = false;
            }
            //如果商品没有拼团活动则筛选看是否有参加普通活动
            if (!empty($existGoods)) {
                if (!empty($data['activity_id']) && (!empty($data['activity_type']) && $data['activity_type'] == 1)) {
                    $kMap[] = ['activity_id', '=', $data['activity_id']];
                }
                $kMap[] = ['goods_sn', 'in', $existGoods];
                $kMap[] = ['sku_sn', 'in', $needFindActivitySku];
                $kMap[] = ['status', '=', 1];
                $activityGoods = ActivityGoodsSku::with(['activity', 'goodsSpu', 'sku'])->where($kMap)->field('goods_sn,sku_sn,specs,activity_price,activity_id,sale_number,vip_level,gift_type,gift_number')->order('activity_price asc,activity_id asc')->select()->toArray();
                foreach ($activityGoods as $key => $value) {
                    $allActivityType[] = $value['activity']['a_type'];
                }
                if (!empty($allActivityType)) {
                    $typeNumber = count(array_unique($allActivityType));
                    //大于等于2证明包含了团长大礼包的类型在里面,因为类型只有两个,一个普通一个团长大礼包
                    //包含了团长大礼包活动的情况下其他活动都无效,直接拿团长大礼包
                    if ($typeNumber >= 2) {
                        foreach ($activityGoods as $key => $value) {
                            if ($value['activity']['a_type'] == 1) {
                                unset($activityGoods[$key]);
                            }
                        }
                    }
                }


                if (!empty($activityGoods)) {
                    $firstActivity = current($activityGoods);
                    $memberActivity = false;
                    if ($firstActivity['activity']['a_type'] == 2) {
                        $memberActivity = true;
                        $userLevel = (new User())->where(['uid' => $data['uid'], 'status' => 1])->field('vip_level,link_superior_user,parent_team')->findOrEmpty()->toArray();
//                        if(!empty($userLevel['vip_level'])){
//                            throw new OrderException(['msg'=>'您已经是会员啦~不需要再购买团长大礼包了~']);
//                        }
//                        if(!empty($userLevel['link_superior_user']) && !empty($userLevel['parent_team']) && !empty($userLevel['vip_level'])){
//                            throw new OrderException(['msg'=>'您已经存在绑定的上级代理啦~请耐心等待系统处理']);
//                        }
                    }
                }
                foreach ($list as $key => $value) {
                    $list[$key]['activity_info'] = [];
                    if (!isset($list[$key]['pt_sale_price'])) {
                        $list[$key]['pt_sale_price'] = 0;
                    }
                    if (!empty($activityGoods)) {
                        $spuInfo['exist_activity'] = true;
                        $spuInfo['exist_normal_activity'] = true;
                        $list[$key]['exist_activity'] = false;
                        foreach ($activityGoods as $aKey => $aValue) {
                            if ($aValue['sku_sn'] == $value['sku_sn']) {
                                if (empty($list[$key]['activity_info']) && !empty($aValue['activity'])) {
                                    if (doubleval($aValue['activity_price']) <= doubleval($value['sale_price'])) {
                                        //团长大礼包订单类型是3
                                        if (!empty($memberActivity)) {
                                            $list[$key]['aType'] = 3;
                                        } else {
                                            $list[$key]['aType'] = 1;
                                        }

                                        $list[$key]['activity_sign'] = $aValue['activity_id'];
                                        $list[$key]['sale_price'] = $aValue['activity_price'];
                                        $aValue['activity']['vip_level'] = $aValue['vip_level'] ?? 0;

                                        $list[$key]['activity_info'] = $aValue['activity'];
                                        $list[$key]['exist_activity'] = true;
                                        $list[$key]['start_time'] = $aValue['goodsSpu']['start_time'];
                                        $list[$key]['end_time'] = $aValue['goodsSpu']['end_time'];
                                        $list[$key]['gift_type'] = $aValue['gift_type'] ?? 0;
                                        $list[$key]['gift_number'] = $aValue['gift_number'] ?? 0;
//                                        $list[$key]['stock'] -= ($aValue['sale_number'] ?? 0);
                                        $list[$key]['stock'] = $list[$key]['stock'] <= 0 ? 0 : intval($list[$key]['stock']);
                                        if (is_numeric($aValue['goodsSpu']['start_time'])) {
                                            $list[$key]['start_time'] = timeToDateFormat($aValue['goodsSpu']['start_time']);
                                        }
                                        if (is_numeric($aValue['goodsSpu']['end_time'])) {
                                            $list[$key]['end_time'] = timeToDateFormat($aValue['goodsSpu']['end_time']);
                                        }
                                        unset($aValue['goodsSpu']);
                                        if (!isset($list[$key]['pt_activity_sign'])) {
                                            $list[$key]['pt_activity_sign'] = null;
                                        }

                                    }
                                }
                            }
                        }
                    }
                }

            }
        }
        $spuInfo['shopSku'] = $list;

        return $spuInfo;
    }

    /**
     * @title  获取商品参加正众筹活动的详情
     * @param array $data
     * @return mixed
     * @throws \Exception
     */
    public function getCrowdActivityInfo(array $data)
    {
        $spuInfo = $data['spuInfo'];
        $list = $spuInfo['shopSku'];

        if (!empty($list)) {
            if (!isset($spuInfo['exist_activity']) && !empty($spuInfo['exist_activity'])) {
                $spuInfo['exist_activity'] = false;
            }
            $spuInfo['exist_crowd_activity'] = false;
            foreach ($list as $key => $value) {
                $list[$key]['exist_crowd_activity'] = false;
                $list[$key]['crowd_activity_info'] = [];
                $list[$key]['crowd_start_time'] = null;
                $list[$key]['crowd_end_time'] = null;
            }

            $aMap = [];
            $aMapOr = [];
            $selectActivity = false;
            //如果有指定期则专门找指定期
            if (!empty($data['activity_code'])) {
                $aMap[] = ['activity_code', '=', $data['activity_code']];
                $aMap[] = ['round_number', '=', $data['round_number']];
                $aMap[] = ['period_number', '=', $data['period_number']];
                $aMapOr[] = ['activity_code', '=', $data['activity_code']];
                $aMapOr[] = ['round_number', '=', $data['round_number']];
                $aMapOr[] = ['period_number', '=', $data['period_number']];
                $selectActivity = true;
            }
            //查找是否有在符合时间内的活动商品
            $aMap[] = ['a.status', '=', 1];
            $aMap[] = ['a.goods_sn', '=', $spuInfo['goods_sn']];
            $aMap[] = ['a.limit_type', '=', 2];
            $existGoods = CrowdfundingActivityGoods::alias('a')
            ->join('sp_crowdfunding_period b','a.activity_code = b.activity_code and a.round_number = b.round_number and a.period_number = b.period_number and b.status = 1 and b.buy_status = 2 and b.result_status = 4','right')
                ->where(function ($query) use ($aMap, $spuInfo, $aMapOr, $selectActivity) {
                $aMapOr[] = ['a.status', '=', 1];
                $aMapOr[] = ['a.goods_sn', '=', $spuInfo['goods_sn']];
                $aMapOr[] = ['a.limit_type', '=', 1];
//                if(empty($selectActivity)){
//                    $aMapOr[] = ['start_time','<=',time()];
                $aMapOr[] = ['a.end_time', '>', time()];
//                }
                $query->where($aMap)->whereOr([$aMapOr]);
            })->field('b.activity_code,b.round_number,b.period_number,a.goods_sn,a.limit_type,a.start_time,a.end_time')->order('a.create_time desc')->select()->toArray();

            if (empty($existGoods)) {
                $spuInfo['shopSku'] = $list;
                return $spuInfo;
            }
            $allExistGoods = $existGoods;

            //查看该期是否状态正常可认购
            $activityCode = current(array_unique(array_column($existGoods,'activity_code')));
            $activityRoundNumber = current(array_unique(array_column($existGoods,'round_number')));
            $activityPeriodNumber = current(array_unique(array_column($existGoods,'period_number')));

            $existGoods = array_unique(array_column($existGoods,'goods_sn'));



            $skuSn = array_column($list, 'sku_sn');
            $needFindActivitySku = $skuSn;

            foreach ($list as $key => $value) {
                $list[$key]['crowd_activity_info'] = [];
                $list[$key]['exist_activity'] = false;
            }
            if (!empty($existGoods)) {
                if (!empty($data['activity_code'])) {
                    $kMap[] = ['activity_code', '=', $data['activity_code']];
                    $kMap[] = ['round_number', '=', $data['round_number']];
                    $kMap[] = ['period_number', '=', $data['period_number']];
                }else{
                    $kMap[] = ['activity_code', '=', $activityCode];
                    $kMap[] = ['round_number', '=', $activityRoundNumber];
                    $kMap[] = ['period_number', '=', $activityPeriodNumber];
                }
                $kMap[] = ['goods_sn', 'in', $existGoods];
                $kMap[] = ['status', '=', 1];
                $gMap = $kMap;

                $kMap[] = ['sku_sn', 'in', $needFindActivitySku];
                $activityGoods = CrowdfundingActivityGoodsSku::with(['activity', 'sku'])->where($kMap)->field('goods_sn,sku_sn,specs,activity_price,activity_code,round_number,period_number,sale_number,vip_level')->order('create_time desc, activity_price asc')->select()->toArray();
                //重新寻找合适的SPU
                foreach ($activityGoods as $key => $value) {
                    foreach ($allExistGoods as $gKey => $gValue) {
                        if ($value['activity_code'] == $gValue['activity_code'] && $value['round_number'] == $gValue['round_number'] && $value['period_number'] == $gValue['period_number'] && $value['goods_sn'] == $gValue['goods_sn']) {
                            $activityGoods[$key]['goodsSpu'] = $gValue;
                        }
                    }
                }

                $periodInfo = CrowdfundingPeriod::where(['activity_code' => $activityCode, 'round_number' => $activityRoundNumber, 'period_number' => $activityPeriodNumber, 'status' => 1])->findOrEmpty()->toArray();
                if (empty($periodInfo) || (!empty($periodInfo) && ($periodInfo['buy_status'] != 2 || $periodInfo['result_status'] != 4))) {
                    $spuInfo['shopSku'] = $list;
                    return $spuInfo;
                }

                $canBuy = false;
                $haveAdvanceBuy = false;
                //判断用户是否有提前购资格, 有则直接可以购买, 跳过时间判断
                $checkUserAdvanceBuy = (new AdvanceCardDetail())->checkUserAdvanceBuy(['checkType' => 2, 'uid' => $data['uid'], 'period' => [['activity_id' => $activityCode, 'round_number' => $activityRoundNumber, 'period_number' => $activityPeriodNumber]]]);
                if (!empty($checkUserAdvanceBuy) && !empty($checkUserAdvanceBuy['res'])) {
                    $canBuy = true;
                    $haveAdvanceBuy = true;
                }
                //如果没有提前购资格在继续判断开放时间段
                if (empty($canBuy)) {
                    //是否在可以购买, 如果有指定开放时间则默认不允许, 在指定时间才可以, 如果没有指定时间则都可以买
                    $durationList = CrowdfundingPeriodSaleDuration::where(['activity_code' => $activityCode, 'round_number' => $activityRoundNumber, 'period_number' => $activityPeriodNumber, 'status' => 1])->withoutField('id,sort,update_time,create_time')->select()->each(function ($item) {
                        if (!empty($item['start_time'])) {
                            $item['start_time'] = timeToDateFormat($item['start_time']);
                        }
                        if (!empty($item['end_time'])) {
                            $item['end_time'] = timeToDateFormat($item['end_time']);
                        }
                        $item['is_selected'] = 2;
                    })->toArray();
                    if (empty($durationList)) {
                        $canBuy = true;
                    }
                    //把开放时间段按小到大排序
                    array_multisort($durationList, SORT_ASC, $durationList);
                    $allTimeList = $durationList;
                    //筛选最近的选中时间段
                    if (!empty($allTimeList ?? [])) {
                        $timeList = array_values(array_unique(array_filter(array_column($allTimeList, 'start_time'))));
                        foreach ($timeList as $key => $value) {
                            $timeList[$key] = strtotime($value);
                        }
                        if (!empty($timeList)) {
                            asort($timeList);
                            $nowTime = NextNumberArray(time(), $timeList);
                            $startTime = strtotime(date('Y-m-d H', $nowTime) . ':00:00');
                            $count = 0;
                            foreach ($timeList as $key => $value) {
                                $HourTime = strtotime(date('Y-m-d H', $value) . ':00:00');
                                $hTimeList[$count]['time'] = date('Y-m-d H', $HourTime);
                                if ($startTime == $HourTime) {
                                    $hTimeList[$count]['is_selected'] = 1;
                                } else {
                                    $hTimeList[$count]['is_selected'] = 2;
                                }
                                $sTimeList[$HourTime] = $hTimeList[$count]['is_selected'];
                                $count++;
                            }
                        }
                        foreach ($durationList as $key => $value) {
                            if (!empty($sTimeList[strtotime(date('Y-m-d H', strtotime($value['start_time'])) . ':00:00')] ?? null)) {
                                $durationList[$key]['is_selected'] = $sTimeList[strtotime(date('Y-m-d H', strtotime($value['start_time'])) . ':00:00')];
                            }
                        }
                    }

                    foreach ($durationList as $key => $value) {
                        if (time() >= strtotime($value['start_time']) && time() < strtotime($value['end_time'])) {
                            $canBuy = true;
                        }
                    }
                }

                foreach ($list as $key => $value) {
                    $list[$key]['activity_info'] = [];
                    if (!isset($list[$key]['crowd_sale_price'])) {
                        $list[$key]['crowd_sale_price'] = 0;
                    }
                    if (!empty($activityGoods)) {
                        $spuInfo['exist_activity'] = true;
                        $spuInfo['exist_crowd_activity'] = true;
                        $list[$key]['exist_activity'] = false;
                        foreach ($activityGoods as $aKey => $aValue) {
                            if ($aValue['sku_sn'] == $value['sku_sn']) {
                                if (!empty($durationList)) {
                                    $list[$key]['crowd_duration_list'] = $durationList;
                                }
                                $list[$key]['can_buy'] = $canBuy;
                                $list[$key]['have_advance_buy'] = $haveAdvanceBuy;
                                if (empty($list[$key]['crowd_activity_info']) && !empty($aValue['activity'])) {
                                    if (doubleval($aValue['activity_price']) <= doubleval($value['sale_price'])) {
                                        $list[$key]['aType'] = 1;
                                        $list[$key]['crowd_activity_sign'] = $aValue['activity_code'];
                                        $list[$key]['crowd_round_number'] = $aValue['round_number'];
                                        $list[$key]['crowd_period_number'] = $aValue['period_number'];
                                        $list[$key]['sale_price'] = $aValue['activity_price'];
                                        $aValue['activity']['vip_level'] = $aValue['vip_level'] ?? 0;

                                        $list[$key]['crowd_activity_info'] = $aValue['activity'];
                                        $list[$key]['exist_crowd_activity'] = true;
                                        $list[$key]['crowd_start_time'] = $aValue['goodsSpu']['start_time'];
                                        $list[$key]['crowd_end_time'] = $aValue['goodsSpu']['end_time'];
//                                        $list[$key]['stock'] -= ($aValue['sale_number'] ?? 0);
                                        $list[$key]['stock'] = $list[$key]['stock'] <= 0 ? 0 : intval($list[$key]['stock']);
//                                        //强行打补丁, 修改期的开始时间绕过前端判断
                                        if (!empty($haveAdvanceBuy ?? false)) {
                                            $aValue['goodsSpu']['start_time'] = time() - 10;
                                        }
                                        if (is_numeric($aValue['goodsSpu']['start_time'])) {
                                            $list[$key]['crowd_start_time'] = timeToDateFormat($aValue['goodsSpu']['start_time']);
                                        }
                                        if (is_numeric($aValue['goodsSpu']['end_time'])) {
                                            $list[$key]['crowd_end_time'] = timeToDateFormat($aValue['goodsSpu']['end_time']);
                                        }
                                        unset($aValue['goodsSpu']);
                                        if (!isset($list[$key]['pt_activity_sign'])) {
                                            $list[$key]['pt_activity_sign'] = null;
                                        }

                                    }
                                }
                            }
                        }
                    }
                }

            }
        }
        $spuInfo['shopSku'] = $list;

        return $spuInfo;
    }


    /**
     * @title  创建商品SPU
     * @param array $data
     * @return mixed
     */
    public function new(array $data)
    {
        $data['belong'] = $data['belong'] ?? $this->belong;
        (new GoodsSpuValidate())->goCheck($data, 'create');
        $add['goods_sn'] = (new CodeBuilder())->buildSpuCode($data['category_code'], $data['brand_code'] ?? 0000);
        $add['goods_code'] = $data['goods_code'] ?? $add['goods_sn'];
        $add['main_image'] = $data['main_image'];
        $add['title'] = $data['title'];
        $add['sub_title'] = $data['sub_title'] ?? null;
        $add['brand_code'] = $data['brand_code'] ?? "0000";
        $add['category_code'] = $data['category_code'];
        $add['attribute_list'] = $data['attribute_list'] ?? $this->defaultSpuAttr();
        $add['link_product_id'] = $data['link_product_id'] ?? null;
        $add['desc'] = $data['desc'] ?? null;
        $add['unit'] = $data['unit'];
        $add['belong'] = $data['belong'];
        $add['status'] = $data['status'] ?? 1;
        $add['saleable'] = $data['saleable'] ?? 1;
        $add['show_status'] = $data['show_status'] ?? 1;
        $add['up_type'] = $data['up_type'] ?? 1;
        $add['up_type'] = $data['up_type'] ?? 1;
        $add['postage_code'] = $data['postage_code'] ?? null;
        $add['tag_id'] = $data['tag_id'] ?? null;
        $add['supplier_code'] = $data['supplier_code'] ?? null;
        if (!empty($data['related_recommend_goods'] ?? null)) {
            $add['related_recommend_goods'] = json_encode($data['related_recommend_goods'], true);
        }
        if (!empty($data['params'] ?? null)) {
            if (!is_array($data['params'])) {
                $params = json_decode($data['params'], true);
                if (($params && is_object($params)) || (is_array($params) && !empty($params))) {
                    $data['params'] = $params;
                }
            }
            $add['params'] = json_encode($data['params'], 256);
        }
        $add['warehouse_icon'] = $data['warehouse_icon'] ?? null;
        $add['intro_video'] = $data['intro_video'] ?? null;
        if (!empty($data['goods_videos'])) {
            $add['goods_videos'] = json_encode($data['goods_videos'], 256);
        }

        switch ($add['up_type']) {
            case 1:
                $add['status'] = 1;
                break;
            case 2:
                $add['status'] = 2;
                $add['up_time'] = !empty($data['up_time']) ? strtotime($data['up_time'] ?? time()) : null;
                if ($add['up_time'] < time()) {
                    throw new ServiceException(['msg' => '上架时间必须大于当前时间']);
                }
                $add['down_time'] = !empty($data['down_time']) ? strtotime($data['down_time']) : null;
                if ($add['up_time'] <= time()) {
                    $add['status'] = 1;
                }
                break;
            case 3:
                $add['status'] = 2;
                break;
        }
        $res = $this->validate()->baseCreate($add);
        $add['images'] = $data['images'];
        //$add['detailImages'] = $data['detailImages'];
        $goodsSn = $res->getData('goods_sn');
        if (!empty($add['images'])) {
            foreach ($add['images'] as $key => $value) {
                $add['images'][$key]['goods_sn'] = $goodsSn;
                $add['images'][$key]['sku_sn'] = null;
                $add['images'][$key]['id'] = null;
                $add['images'][$key]['type'] = 1;
            }
            (new GoodsImages())->newOrEdit($add['images']);
        }
//        if(!empty($add['detailImages'])){
//            foreach ($add['detailImages'] as $key => $value) {
//                $add['detailImages'][$key]['goods_sn'] = $goodsSn;
//                $add['detailImages'][$key]['sku_sn'] = null;
//                $add['detailImages'][$key]['id'] = null;
//                $add['detailImages'][$key]['type'] = 3;
//            }
//            (new GoodsImages())->newOrEdit($add['detailImages']);
//        }
        if (!empty($data['related_recommend_goods'] ?? null)) {
            cache(config('system.queueAbbr') . 'GoodsInfoOtherGoodsByCustomize' . $goodsSn, null);
        }
        return $goodsSn;
    }

    /**
     * @title  编辑商品SPU
     * @param array $data
     * @return mixed
     */
    public function edit(array $data)
    {
        $data['belong'] = $data['belong'] ?? $this->belong;
        (new GoodsSpuValidate())->goCheck($data, 'edit');
        $save['goods_code'] = $data['goods_code'] ?? $data['goods_sn'];
        $save['main_image'] = $data['main_image'];
        $save['title'] = $data['title'];
        $save['sub_title'] = $data['sub_title'] ?? null;
        $save['brand_code'] = $data['brand_code'] ?? "0000";
        $save['category_code'] = $data['category_code'];
        $save['attribute_list'] = $data['attribute_list'] ?? $this->defaultSpuAttr();
        $save['link_product_id'] = $data['link_product_id'] ?? null;
        $save['desc'] = $data['desc'] ?? null;
        $save['unit'] = $data['unit'];
        $save['belong'] = $data['belong'];
        $save['status'] = $data['status'] ?? 1;
        $save['saleable'] = $data['saleable'] ?? 1;
        $save['show_status'] = $data['show_status'] ?? 1;
        $save['up_type'] = $data['up_type'] ?? 1;
        $save['postage_code'] = $data['postage_code'] ?? 1;
        $save['tag_id'] = $data['tag_id'] ?? null;
        $save['supplier_code'] = $data['supplier_code'] ?? null;
        if (!empty($data['related_recommend_goods'] ?? null)) {
            $save['related_recommend_goods'] = json_encode($data['related_recommend_goods'], true);
        }
        if (!empty($data['params'] ?? null)) {
            $save['params'] = json_encode($data['params'], 256);
        }
        $save['warehouse_icon'] = $data['warehouse_icon'] ?? null;
        $save['intro_video'] = $data['intro_video'] ?? null;
        $save['goods_videos'] = '';
        if (!empty($data['goods_videos'])) {
            $save['goods_videos'] = json_encode($data['goods_videos'], 256);
        }

        switch ($save['up_type']) {
            case 1:
                $save['status'] = 1;
                break;
            case 2:
                $save['status'] = 2;
                $save['up_time'] = strtotime($data['up_time'] ?? time());
//                if($save['up_time'] < time()){
//                    throw new ServiceException(['msg'=>'上架时间必须大于当前时间']);
//                }
                $save['down_time'] = !empty($data['down_time']) ? strtotime($data['down_time']) : null;
                if ($save['up_time'] <= time()) {
                    $save['status'] = 1;
                }
                break;
            case 3:
                $save['status'] = 2;
                break;
        }
        $res = $this->validate()->baseUpdate(['goods_sn' => $data['goods_sn']], $save);
        $save['images'] = $data['images'];
        //$save['detailImages'] = $data['detailImages'];
        $goodsSn = $res->getData();
        if (!empty($save['images'])) {
            (new GoodsImages())->newOrEdit($save['images']);
        }
//        if(!empty($data['main_image'])){
        //修改活动商品主图
        ActivityGoods::where(['goods_sn' => $data['goods_sn'], 'status' => [1, 2]])->update(['image' => $data['main_image'], 'title' => $data['title'], 'sub_title' => $data['sub_title'] ?? null, 'status' => $save['status']]);
        PtGoods::where(['goods_sn' => $data['goods_sn'], 'status' => [1, 2]])->update(['image' => $data['main_image'], 'title' => $data['title'], 'sub_title' => $data['sub_title'] ?? null, 'status' => $save['status']]);
//        }

//        if(!empty($save['detailImages'])){
//            (new GoodsImages())->newOrEdit($save['detailImages']);
//        }
        if (!empty($data['related_recommend_goods'] ?? null)) {
            cache(config('system.queueAbbr') . 'GoodsInfoOtherGoodsByCustomize' . $data['goods_sn'], null);
        }
        return $goodsSn;
    }

    /**
     * @title  商品详情
     * @param string $goodsSn 商品编号
     * @return mixed
     * @throws \Exception
     */
    public function info(string $goodsSn)
    {
        $info = $this->with(['category', 'brand', 'sku', 'goodsImages', 'goodsDetailImages'])->where(['goods_sn' => $goodsSn, 'status' => $this->getStatusByRequestModule(1)])->withoutField('id,update_time')->findOrEmpty()->toArray();
        if (!empty($info)) {
            $allSkuVdc = GoodsSkuVdc::where(['goods_sn' => $goodsSn, 'status' => $this->getStatusByRequestModule(1)])->field('level,goods_sn,sku_sn,belong,purchase_price,vdc_genre,vdc_type,vdc_one,vdc_two')->select()->toArray();
            if (!empty($allSkuVdc)) {
                foreach ($info['sku'] as $key => $value) {
                    foreach ($allSkuVdc as $vKey => $vValue) {
                        if ($value['sku_sn'] == $vValue['sku_sn']) {
                            $info['sku'][$key]['vdc'][] = $vValue;
                        }
                    }
                }
            }
            if (!empty($info['goodsImages'])) {
                $imageDomain = config('system.imgDomain');
                foreach ($info['goodsImages'] as $key => $value) {
                    $info['goodsImages'][$key]['image_url'] = substr_replace($value['image_url'], $imageDomain, strpos($value['image_url'], '/'), strlen('/'));
                }
            }
            if (!empty($info['goodsDetailImages'])) {
                $imageDomain = config('system.imgDomain');
//                foreach ($info['goodsDetailImages'] as $key => $value) {
//                    $info['goodsDetailImages'][$key]['image_url'] = substr_replace($value['image_url'],$imageDomain,strpos($value['image_url'],'/'),strlen('/'));
//                }
            }
            $info['related_recommend_goods_array'] = [];
            if (!empty($info['related_recommend_goods'])) {
                $relatedRecommendGoods = self::where(['goods_sn' => json_decode($info['related_recommend_goods'], true), 'status' => [1, 2]])->field('goods_sn,title,main_image')->select()->toArray();
                $info['related_recommend_goods_array'] = $relatedRecommendGoods ?? [];
            }
            if (!empty($info['goods_videos'])) {
                $info['goods_videos'] = json_decode($info['goods_videos'], true) ?? [];
            }
        }
        return $info;
    }

    /**
     * @title  获取商品活动预热信息
     * @param array $data
     * @return array
     */
    public function getWarmUpActivityInfo(array $data)
    {
        $activityGoods = [];
        //活动类型 1为活动活动 2为拼团活动 3为拼拼有礼活动
        $type = $data['type'] ?? 1;
        switch ($type ?? 1){
            case 1:
                $cacheTag = 'apiWarmUpActivityInfo';
                break;
            case 2:
                $cacheTag = 'apiWarmUpPtActivityInfo';
                break;
            case 3:
                $cacheTag = 'apiWarmUpPpylActivityInfo';
                break;
        }

        $cacheTag .= trim($data['goods_sn']);
        $cacheKey = $cacheTag . ($data['activity_id'] ?? null);

        $cacheExpire = 300;

        if (empty($data['clearCache'])) {
            if (!empty(cache($cacheKey))) {
                $activityGoods = cache($cacheKey);
            }
        }

        if (empty($activityGoods)) {
            switch ($type ?? 1){
                case 1:
                    $model = (new ActivityGoods());
                    $map[] = ['activity_id', '=', $data['activity_id']];
                    $withModel = 'sku';
                    break;
                case 2:
                    $model = (new PtGoods());
                    $map[] = ['activity_code', '=', $data['activity_id']];
                    $withModel = 'sku';
                    break;
                case 3:
                    $model = (new PpylGoods());
                    $map[] = ['area_code', '=', $data['activity_id']];
                    $withModel = 'sku';
                    break;
            }

            $map[] = ['status', '=', 1];
            $map[] = ['goods_sn', '=', $data['goods_sn']];

            $activityGoods = $model->with([$withModel => function ($query) {
                $query->field('goods_sn,sku_sn,title,specs');
            }])->where($map)->findOrEmpty()->toArray();

            if (!empty($activityGoods)) {
                if (in_array($type, [2, 3])) {
                    $activityGoods['limit_type'] = 1;
                }
                cache($cacheKey, $activityGoods, $cacheExpire, $cacheTag);
            }
        }

        return $activityGoods ?? [];
    }

    /**
     * @title  通过商品SPU获取复制商品的全新数据结构
     * @param array $sear
     * @return mixed
     * @throws \Exception
     */
    public function copyGoods(array $sear)
    {
        $goodsSn = $sear['goods_sn'];
        $goodsInfo = $this->info($goodsSn);
        if (empty($goodsInfo)) {
            throw new OrderException(['msg' => '不存在的商品哦']);
        }
        //去除一些唯一标识以做新增用
        unset($goodsInfo['id']);
        unset($goodsInfo['goods_sn']);
        unset($goodsInfo['goods_code']);
        unset($goodsInfo['create_time']);
        unset($goodsInfo['update_time']);
        unset($goodsInfo['status']);
        unset($goodsInfo['category_name']);
        unset($goodsInfo['brand_name']);
        //默认不上架
        $goodsInfo['up_type'] = 3;
        foreach ($goodsInfo['sku'] as $key => $value) {
            unset($goodsInfo['sku'][$key]['id']);
            unset($goodsInfo['sku'][$key]['goods_sn']);
            unset($goodsInfo['sku'][$key]['sku_sn']);
            unset($goodsInfo['sku'][$key]['create_time']);
            unset($goodsInfo['sku'][$key]['update_time']);
            unset($goodsInfo['sku'][$key]['status']);
            if (!empty($value['vdc'])) {
                foreach ($value['vdc'] as $vKey => $vValue) {
                    unset($goodsInfo['sku'][$key]['vdc'][$vKey]['goods_sn']);
                    unset($goodsInfo['sku'][$key]['vdc'][$vKey]['sku_sn']);
                }
            }
            $goodsInfo['sku'][$key]['attr'] = json_decode($value['specs'], true);
            if (!empty($value['attr_sn'])) {
                $goodsInfo['sku'][$key]['attr_sn'] = json_decode($value['attr_sn'], true);
            }
            unset($goodsInfo['sku'][$key]['specs']);
            $goodsInfo['sku'][$key]['stock'] = 0;
            $goodsInfo['sku'][$key]['virtual_stock'] = 0;
        }
        if (!empty($goodsInfo['goodsImages'])) {
            foreach ($goodsInfo['goodsImages'] as $iKey => $vValue) {
                unset($goodsInfo['goodsImages'][$iKey]['goods_sn']);
                unset($goodsInfo['goodsImages'][$iKey]['sku_sn']);
                unset($goodsInfo['goodsImages'][$iKey]['id']);
            }
            $goodsInfo['images'] = $goodsInfo['goodsImages'];
            unset($goodsInfo['goodsImages']);
        }

        return $goodsInfo;

    }

    /**
     * @title  根据课程id获取商品详情
     * @param string $subjectId 课程id
     * @param int $type 商品类型 1为SPU,2为SKU
     * @return array
     * @throws \Exception
     */
    public function getGoodsInfoBySubjectId(string $subjectId, int $type = 1)
    {
        $goodsSn = $this->where(['link_product_id' => $subjectId, 'status' => [1], 'belong' => $this->belong])->field('goods_sn,belong,link_product_id,status,title,main_image,brand_code,category_code,attribute_list')->buildSql();
        //查看content字段是否为数字,如果是数字则为章节sku,不是数字则为整个课程的sku
        if ($type == 1) {
            $map[] = ['', 'exp', Db::raw('(b.content REGEXP \'[^0-9.]\') = 1')];
        } else {
            $map[] = ['', 'exp', Db::raw('(b.content REGEXP \'[^0-9.]\') = 0')];
        }
        $map[] = ['b.status', '=', 1];
        $aSku = Db::table($goodsSn . ' a')
            ->join('sp_goods_sku b', 'a.goods_sn = b.goods_sn', 'left')
            ->where($map)
            ->field('a.*,b.sku_sn,b.specs as sku_specs,b.content as sku_content,b.sale_price')
            ->order('b.sort asc,b.create_time desc')
            ->select()
            ->toArray();
        return $aSku;
    }

    /**
     * @title  删除商品
     * @param string $goodsSn
     * @return mixed
     */
    public function del(string $goodsSn)
    {
        $existOrder = OrderGoods::where(['goods_sn' => $goodsSn, 'pay_status' => [1, 2]])->count();
        if (!empty($existOrder)) {
            throw new ServiceException(['msg' => '该商品已存在订单记录,为保证数据安全,无法删除!']);
        }
        $res = Db::transaction(function () use ($goodsSn) {
            //删除SPU
            $res = $this->baseDelete(['goods_sn' => $goodsSn]);
            //删除SKU
            (new GoodsSku())->baseDelete(['goods_sn' => $goodsSn]);
            //删除SKU_vdc
            (new GoodsSkuVdc())->baseDelete(['goods_sn' => $goodsSn]);
            //删除活动商品
            (new ActivityGoods())->baseDelete(['goods_sn' => $goodsSn]);
            (new ActivityGoodsSku())->baseDelete(['goods_sn' => $goodsSn]);
            cache('HomeApiActivityList', null);

            //删除拼团活动商品
            (new PtGoods())->baseDelete(['goods_sn' => $goodsSn]);
            (new PtGoodsSku())->baseDelete(['goods_sn' => $goodsSn]);
            cache('ApiHomePtList', null);

            //删除拼拼有礼活动商品
            (new PpylGoods())->baseDelete(['goods_sn' => $goodsSn]);
            (new PpylGoodsSku())->baseDelete(['goods_sn' => $goodsSn]);
            cache('ApiHomePpylList', null);

            cache('ApiHomeAllList', null);
            return $res;
        });
        return $res;
    }

    /**
     * @title  上下架
     * @param array $data
     * @return GoodsSpu|bool
     */
    public function upOrDown(array $data)
    {
        $goodsInfo = GoodsSpu::where(['goods_sn' => $data['goods_sn']])->findOrEmpty()->toArray();

        $upTime = strtotime($goodsInfo['up_time']);
        $downTime = strtotime($goodsInfo['down_time']);
        if ($goodsInfo['up_type'] == 2 && $data['status'] == 2) {
            if ($upTime > time()) {
                throw new ServiceException(['msg' => '该商品属于定时上架产品,未到指定时间不允许上架操作']);
            } elseif ($downTime <= time()) {
                $save['up_type'] = 1;
            }
        }

        if ($goodsInfo['up_type'] == 2 && $data['status'] == 1) {
            if ($upTime <= time()) {
                $save['down_time'] = time() - 1;
            }
        }

        if ($data['status'] == 1) {
            $save['status'] = 2;
            $save['up_type'] = 3;
        } elseif ($data['status'] == 2) {
            $save['status'] = 1;
            //如果原本上架类型为不上架,现在修改为上架状态也要修改对应的上架类型为立即上架
            if ($goodsInfo['up_type'] == 3) {
                $save['up_type'] = 1;
            }
        } else {
            return false;
        }
        if ($goodsInfo['status'] == $save['status']) {
            throw new ServiceException(['msg' => '操作状态有误,请联系运维人员']);
        }

        $res = Db::transaction(function () use ($data, $save) {
            $spuRes = $this->baseUpdate(['goods_sn' => $data['goods_sn'], 'status' => [1, 2]], $save);
            unset($save['up_type']);
            (new GoodsSku())->baseUpdate(['goods_sn' => $data['goods_sn'], 'status' => [1, 2]], $save);
            (new GoodsSkuVdc())->baseUpdate(['goods_sn' => $data['goods_sn'], 'status' => [1, 2]], $save);
            //删除活动商品
            (new ActivityGoods())->baseUpdate(['goods_sn' => $data['goods_sn'], 'status' => [1, 2]], $save);
            (new ActivityGoodsSku())->baseUpdate(['goods_sn' => $data['goods_sn'], 'status' => [1, 2]], $save);
            cache('HomeApiActivityList', null);

            //删除拼团活动商品
            (new PtGoods())->baseUpdate(['goods_sn' => $data['goods_sn'], 'status' => [1, 2]], $save);
            (new PtGoodsSku())->baseUpdate(['goods_sn' => $data['goods_sn'], 'status' => [1, 2]], $save);
            cache('ApiHomePtList', null);

            //删除拼拼有礼活动商品
            (new PpylGoods())->baseUpdate(['goods_sn' => $data['goods_sn'], 'status' => [1, 2]], $save);
            (new PpylGoodsSku())->baseUpdate(['goods_sn' => $data['goods_sn'], 'status' => [1, 2]], $save);
            cache('ApiHomePpylList', null);

            cache('ApiHomeAllList', null);
            Cache::tag(['apiHomeGoodsList', 'HomeApiActivityList'])->clear();
            return $spuRes;
        });
        return $res;

    }

    /**
     * @title  商品列表-订单筛选条件专属
     * @param array $sear
     * @return array
     * @throws \Exception
     */
    public function listForOrderSear(array $sear)
    {
        if (!empty($sear['keyword'])) {
            $map[] = ['', 'exp', Db::raw($this->getFuzzySearSql('title|goods_code', $sear['keyword']))];
        }
        if (!empty($sear['category_code'])) {
            $allCategory = Category::where(function ($query) use ($sear) {
                $mapOr[] = ['p_code', '=', $sear['category_code']];
                $map[] = ['code', '=', $sear['category_code']];
                $query->where($map)->cache('goodsSpuCategoryList')->whereOr([$mapOr]);
            })->where(['status' => 1])->column('code');
            if (!empty($allCategory)) {
                $map[] = ['category_code', 'in', $allCategory];
            }
        }
        if (!empty($sear['brand_code'])) {
            $map[] = ['brand_code', '=', $sear['brand_code']];
        }
        if (!empty($sear['belong'])) {
            $map[] = ['belong', '=', $sear['belong']];
        } else {
            $map[] = ['belong', '=', $this->belong];
        }
        //供应商只能筛选属于自己的商品
        if (!empty($sear['adminInfo'])) {
            $supplierCode = $sear['adminInfo']['supplier_code'] ?? null;
            if (!empty($supplierCode)) {
                $allSpu = self::where(['supplier_code' => $supplierCode, 'status' => 1])->column('goods_sn');
                if (empty($allSpu)) {
                    throw new ShipException(['msg' => '暂无属于您的供货商品']);
                }
                $map[] = ['goods_sn', 'in', $allSpu];
            }
        }

        $map[] = ['status', 'in', $this->getStatusByRequestModule($sear['searType'] ?? 1)];

        $page = intval($sear['page'] ?? 0) ?: null;
        if (!empty($page)) {
            $aTotal = $this->where($map)->count();
            $pageTotal = ceil($aTotal / $this->pageNumber);
        }

        $list = $this->where($map)->field('goods_sn,title,main_image')->when($page, function ($query) use ($page) {
            $query->page($page, $this->pageNumber);
        })->order('create_time desc')->select()->toArray();


        return ['list' => $list, 'pageTotal' => $pageTotal ?? 0];

    }

    /**
     * @title  更新商品排序
     * @param array $data
     * @return bool
     */
    public function updateSort(array $data)
    {
        $goodsNumber = count($data);
        if (empty($goodsNumber)) {
            throw new ServiceException(['msg' => '请选择商品']);
        }
        $DBRes = Db::transaction(function () use ($data) {
            foreach ($data as $key => $value) {
                $res = $this->where(['goods_sn' => $value['goods_sn']])->save(['sort' => $value['sort'] ?? 1]);
            }
            return $res;
        });

        return judge($DBRes);
    }

    /**
     * @title  商品实际销售情况(SPU)
     * @param array $data
     * @return array|bool|mixed
     * @throws \Exception
     */
    public function realSalesInfoForSpu(array $data)
    {
        $list = $data['list'];
        if (empty($list)) {
            return false;
        }
        $sear = $data['sear'];
        $allGoodsSn = array_unique(array_column($list, 'goods_sn'));
        if (!empty($sear['sale_create_time']) && !empty($sear['sale_end_time'])) {
            $oMap[] = ['create_time', '>=', strtotime($sear['sale_create_time'])];
            $oMap[] = ['create_time', '<=', strtotime($sear['sale_end_time'])];
        }
        $oMap[] = ['goods_sn', 'in', $allGoodsSn];

        //统计正确的订单数量
        $orderGoods = OrderGoods::where($oMap)->where(['pay_status' => 2, 'after_status' => [1, -1, 5]])->field('order_sn,count,goods_sn,sku_sn,real_pay_price')->select()->toArray();
        $orderCount = [];
        if (!empty($orderGoods)) {
            foreach ($orderGoods as $key => $value) {
                $orderCount[$value['goods_sn']][] = $value['order_sn'];
            }
            if (!empty($orderCount)) {
                foreach ($list as $key => $value) {
                    if (!empty($orderCount[$value['goods_sn']])) {
                        $list[$key]['sell_order_number'] = count(array_unique(array_filter($orderCount[$value['goods_sn']])));
                        if (!empty($sear['needAllSkuOrderNumber'] ?? null)) {
                            $list[$key]['sku_sell_order_number'] = count(array_filter($orderCount[$value['goods_sn']]));
                        } else {
                            $list[$key]['sku_sell_order_number'] = $list[$key]['sell_order_number'];
                        }
                    }
                }
            }
        }

        if (!empty($sear['sale_create_time']) && !empty($sear['sale_end_time'])) {
            $ptMap[] = ['create_time', '>=', strtotime($sear['sale_create_time'])];
            $ptMap[] = ['create_time', '<=', strtotime($sear['sale_end_time'])];
        }
        $ptMap[] = ['goods_sn', 'in', $allGoodsSn];
        //减少未成团的拼团商品销售数量
        $ptGoods = PtOrder::where($ptMap)->where(['pay_status' => 2, 'activity_status' => 1, 'status' => 1])->field('order_sn,goods_sn,sku_sn,activity_status,real_pay_price')->select()->toArray();
        $ptNumberCount = [];
        $ptOrderCount = [];
        $ptGoodsPrice = [];
        if (!empty($ptGoods)) {
            foreach ($ptGoods as $key => $value) {
                if (!isset($ptNumberCount[$value['goods_sn']])) {
                    $ptNumberCount[$value['goods_sn']] = 0;
                }
                if (!isset($ptGoodsPrice[$value['goods_sn']])) {
                    $ptGoodsPrice[$value['goods_sn']] = 0;
                }
                if (!isset($ptGoodsPrice[$value['goods_sn']])) {
                    $ptGoodsPrice[$value['goods_sn']] = 0;
                }
                $ptOrderCount[$value['goods_sn']][] = $value['order_sn'];
                $ptNumberCount[$value['goods_sn']] += 1;
                $ptGoodsPrice[$value['goods_sn']] += $value['real_pay_price'];
            }
            if (!empty($ptOrderCount)) {
                foreach ($list as $key => $value) {
                    if (!empty($ptOrderCount[$value['goods_sn']])) {
                        $list[$key]['sell_order_number'] -= count(array_unique(array_filter($ptOrderCount[$value['goods_sn']])));
//                            if(!empty($ptNumberCount[$value['goods_sn']])){
//                                $list[$key]['sell_number'] -= $ptNumberCount[$value['goods_sn']];
//                            }
                        $list[$key]['sell_number'] -= count(array_unique(array_filter($ptOrderCount[$value['goods_sn']])));
                        if (!empty($sear['needAllSkuOrderNumber'] ?? null)) {
                            $list[$key]['sku_sell_order_number'] -= count(array_filter($ptOrderCount[$value['goods_sn']]));
                        } else {
                            $list[$key]['sku_sell_order_number'] = $list[$key]['sell_order_number'];
                        }
                        if (!empty($ptGoodsPrice[$value['goods_sn']])) {
                            $list[$key]['sell_price'] -= $ptGoodsPrice[$value['goods_sn']];
                        }
                    }
                }
            }
        }

        //恢复换货申请的订单及商品销售数量
        $afMap[] = ['type', '=', 3];
        $afMap[] = ['after_status', 'not in', [3, -1, -2, -3]];
        if (!empty($sear['sale_create_time']) && !empty($sear['sale_end_time'])) {
            $afMap[] = ['create_time', '>=', strtotime($sear['sale_create_time'])];
            $afMap[] = ['create_time', '<=', strtotime($sear['sale_end_time'])];
        }
        $changeGoods = AfterSale::where(['goods_sn' => $allGoodsSn])->where($afMap)->field('order_sn,goods_sn,sku_sn')->select()->toArray();
        $changeNumberCount = [];
        $changeOrderCount = [];
        $changePrice = [];
        if (!empty($changeGoods)) {
            $changeGoodsSn = array_unique(array_column($changeGoods, 'goods_sn'));
            $changeGoodsSkuSn = array_unique(array_column($changeGoods, 'sku_sn'));
            $changeOrderSn = array_unique(array_column($changeGoods, 'order_sn'));
            $goodsInfo = OrderGoods::where(['order_sn' => $changeOrderSn, 'goods_sn' => $changeGoodsSn, 'sku_sn' => $changeGoodsSkuSn])->field('order_sn,count,goods_sn,sku_sn,real_pay_price')->select()->toArray();
            if (!empty($goodsInfo)) {
                foreach ($goodsInfo as $key => $value) {
                    $goodsCountInfo[$value['order_sn']][$value['sku_sn']] = $value['count'];
                    $goodsPriceInfo[$value['order_sn']][$value['sku_sn']] = $value['real_pay_price'];
                }
            }
            foreach ($changeGoods as $key => $value) {
                if (!isset($changeNumberCount[$value['goods_sn']])) {
                    $changeNumberCount[$value['goods_sn']] = 0;
                }
                if (!isset($changePrice[$value['goods_sn']])) {
                    $changePrice[$value['goods_sn']] = 0;
                }
                $changeOrderCount[$value['goods_sn']][] = $value['order_sn'];
                if (!empty($goodsCountInfo[$value['order_sn']])) {
                    $changeNumberCount[$value['goods_sn']] += $goodsCountInfo[$value['order_sn']][$value['sku_sn']] ?? 0;
                }
                if (!empty($goodsPriceInfo[$value['order_sn']])) {
                    $changePrice[$value['goods_sn']] += $goodsPriceInfo[$value['order_sn']][$value['sku_sn']] ?? 0;
                }

            }

            if (!empty($changeOrderCount)) {
                foreach ($list as $key => $value) {
                    if (!empty($changeOrderCount[$value['goods_sn']])) {
                        $list[$key]['sell_order_number'] += count(array_unique(array_filter($changeOrderCount[$value['goods_sn']])));
                        if (!empty($changeNumberCount[$value['goods_sn']])) {
                            $list[$key]['sell_number'] += $changeNumberCount[$value['goods_sn']];
                            if (!empty($list[$key]['after_sale_number'])) {
                                $list[$key]['after_sale_number'] -= $changeNumberCount[$value['goods_sn']];
                            }
                        }
                        if (!empty($changePrice[$value['goods_sn']])) {
                            $list[$key]['sell_price'] += $changePrice[$value['goods_sn']];
                        }
                        if (!empty($sear['needAllSkuOrderNumber'] ?? null)) {
                            $list[$key]['sku_sell_order_number'] += count(array_filter($changeOrderCount[$value['goods_sn']]));
                        } else {
                            $list[$key]['sku_sell_order_number'] = $list[$key]['sell_order_number'];
                        }
                    }
                }
            }
        }

        foreach ($list as $key => $value) {
            $list[$key]['sell_price'] = priceFormat($value['sell_price']);
        }

        return $list ?? [];
    }

    /**
     * @title  商品实际销售情况(SKU)
     * @param array $data
     * @return array|bool|mixed
     * @throws \Exception
     */
    public function realSalesInfoForSku(array $data)
    {
        $list = $data['list'];
        if (empty($list)) {
            return false;
        }
        $sear = $data['sear'];
        $allGoodsSn = array_unique(array_column($list, 'goods_sn'));
        $allSkuSn = array_unique(array_column($list, 'sku_sn'));
        //统计正确的订单数量
        $orderGoods = OrderGoods::where(['goods_sn' => $allGoodsSn, 'sku_sn' => $allSkuSn])->where(['pay_status' => 2, 'after_status' => [1, -1]])->field('order_sn,count,goods_sn,sku_sn,real_pay_price')->select()->toArray();
        $orderCount = [];
        if (!empty($orderGoods)) {
            foreach ($orderGoods as $key => $value) {
                $orderCount[$value['sku_sn']][] = $value['order_sn'];
            }
            if (!empty($orderCount)) {
                foreach ($list as $key => $value) {
                    if (!empty($orderCount[$value['sku_sn']])) {
                        $list[$key]['sell_order_number'] = count(array_unique(array_filter($orderCount[$value['sku_sn']])));
                        if (!empty($sear['needAllSkuOrderNumber'] ?? null)) {
                            $list[$key]['sku_sell_order_number'] = count(array_filter($orderCount[$value['sku_sn']]));
                        } else {
                            $list[$key]['sku_sell_order_number'] = $list[$key]['sell_order_number'];
                        }
                    }
                }
            }
        }

        //减少未成团的拼团商品销售数量
        $ptGoods = PtOrder::where(['goods_sn' => $allGoodsSn, 'sku_sn' => $allSkuSn])->where(['pay_status' => 2, 'activity_status' => 1, 'status' => 1])->field('order_sn,goods_sn,sku_sn,activity_status,real_pay_price')->select()->toArray();
        $ptNumberCount = [];
        $ptOrderCount = [];
        $ptGoodsPrice = [];
        if (!empty($ptGoods)) {
            foreach ($ptGoods as $key => $value) {
                if (!isset($ptNumberCount[$value['sku_sn']])) {
                    $ptNumberCount[$value['sku_sn']] = 0;
                }
                if (!isset($ptGoodsPrice[$value['sku_sn']])) {
                    $ptGoodsPrice[$value['sku_sn']] = 0;
                }
                if (!isset($ptGoodsPrice[$value['sku_sn']])) {
                    $ptGoodsPrice[$value['sku_sn']] = 0;
                }
                $ptOrderCount[$value['sku_sn']][] = $value['order_sn'];
                $ptNumberCount[$value['sku_sn']] += 1;
                $ptGoodsPrice[$value['sku_sn']] += $value['real_pay_price'];
            }
            if (!empty($ptOrderCount)) {
                foreach ($list as $key => $value) {
                    if (!empty($ptOrderCount[$value['sku_sn']])) {
                        $list[$key]['sell_order_number'] -= count(array_unique(array_filter($ptOrderCount[$value['sku_sn']])));
//                            if(!empty($ptNumberCount[$value['goods_sn']])){
//                                $list[$key]['sell_number'] -= $ptNumberCount[$value['goods_sn']];
//                            }
                        $list[$key]['sell_number'] -= count(array_unique(array_filter($ptOrderCount[$value['sku_sn']])));
                        if (!empty($sear['needAllSkuOrderNumber'] ?? null)) {
                            $list[$key]['sku_sell_order_number'] -= count(array_filter($ptOrderCount[$value['sku_sn']]));
                        } else {
                            $list[$key]['sku_sell_order_number'] = $list[$key]['sell_order_number'];
                        }
                        if (!empty($ptGoodsPrice[$value['sku_sn']])) {
                            $list[$key]['sell_price'] -= $ptGoodsPrice[$value['sku_sn']];
                        }
                    }
                }
            }
        }

        //恢复换货申请的订单及商品销售数量
        $afMap[] = ['type', '=', 3];
        $afMap[] = ['after_status', 'not in', [3, -1, -2, -3]];
        $changeGoods = AfterSale::where(['goods_sn' => $allGoodsSn, 'sku_sn' => $allSkuSn])->where($afMap)->field('order_sn,goods_sn,sku_sn')->select()->toArray();
        $changeNumberCount = [];
        $changeOrderCount = [];
        $changePrice = [];
        if (!empty($changeGoods)) {
            $changeGoodsSn = array_unique(array_column($changeGoods, 'goods_sn'));
            $changeGoodsSkuSn = array_unique(array_column($changeGoods, 'sku_sn'));
            $changeOrderSn = array_unique(array_column($changeGoods, 'order_sn'));
            $goodsInfo = OrderGoods::where(['order_sn' => $changeOrderSn, 'goods_sn' => $changeGoodsSn, 'sku_sn' => $changeGoodsSkuSn])->field('order_sn,count,goods_sn,sku_sn,real_pay_price')->select()->toArray();
            if (!empty($goodsInfo)) {
                foreach ($goodsInfo as $key => $value) {
                    $goodsCountInfo[$value['order_sn']][$value['sku_sn']] = $value['count'];
                    $goodsPriceInfo[$value['order_sn']][$value['sku_sn']] = $value['real_pay_price'];
                }
            }

            foreach ($changeGoods as $key => $value) {
                if (!isset($changeNumberCount[$value['sku_sn']])) {
                    $changeNumberCount[$value['sku_sn']] = 0;
                }
                if (!isset($changePrice[$value['sku_sn']])) {
                    $changePrice[$value['sku_sn']] = 0;
                }
                $changeOrderCount[$value['sku_sn']][] = $value['order_sn'];
                if (!empty($goodsCountInfo[$value['order_sn']])) {
                    $changeNumberCount[$value['sku_sn']] += $goodsCountInfo[$value['order_sn']][$value['sku_sn']] ?? 0;
                }
                if (!empty($goodsPriceInfo[$value['order_sn']])) {
                    $changePrice[$value['sku_sn']] += $goodsPriceInfo[$value['order_sn']][$value['sku_sn']] ?? 0;
                }
            }

            if (!empty($changeOrderCount)) {
                foreach ($list as $key => $value) {
                    if (!empty($changeOrderCount[$value['sku_sn']])) {
                        $list[$key]['sell_order_number'] += count(array_unique(array_filter($changeOrderCount[$value['sku_sn']])));
                        if (!empty($changeNumberCount[$value['sku_sn']])) {
                            $list[$key]['sell_number'] += $changeNumberCount[$value['sku_sn']];
                            if (!empty($list[$key]['after_sale_number'])) {
                                $list[$key]['after_sale_number'] -= $changeNumberCount[$value['sku_sn']];
                            }
                        }
                        if (!empty($changePrice[$value['sku_sn']])) {
                            $list[$key]['sell_price'] += $changePrice[$value['sku_sn']];
                        }
                        if (!empty($sear['needAllSkuOrderNumber'] ?? null)) {
                            $list[$key]['sku_sell_order_number'] += count(array_filter($changeOrderCount[$value['sku_sn']]));
                        } else {
                            $list[$key]['sku_sell_order_number'] = $list[$key]['sell_order_number'];
                        }
                    }
                }
            }
        }

        foreach ($list as $key => $value) {
            $list[$key]['sell_price'] = priceFormat($value['sell_price']);
        }

        return $list ?? [];
    }

    /**
     * @title  个人中心页面的商品推荐
     * @param array $data
     * @return mixed
     * @throws \Exception
     */
    public function otherGoodsListInUserCenter(array $data)
    {
        $uid = $data['uid'];
        $userBehavior = Behavior::where(['uid' => $uid, 'type' => 2])->order('enter_time desc')->value('goods_sn');

        if (!empty($userBehavior)) {
            $map[] = ['status', '=', 1];
            $map[] = ['goods_sn', '=', $userBehavior];
            $goodsInfo = GoodsSpu::where($map)->order('create_time desc')->field('goods_sn,category_code')->findOrEmpty()->toArray();
        }

        if(empty($goodsInfo)){
            $goodsInfo = GoodsSpu::where(['status' => 1, 'show_status' => 1])->order('create_time desc')->field('goods_sn,category_code')->findOrEmpty()->toArray();
        }

        if (empty($goodsInfo)) {
            $list['other'] = [];
            $list['hot'] = [];
            return $list;
        }
        $list = $this->otherGoodsList(['goods_sn' => $goodsInfo['goods_sn'], 'category_code' => $goodsInfo['category_code'] ?? null, 'allowAllStatus' => true]);
//        $list['hot'] = [];
        return $list ?? [];
    }

    /**
     * @title  其他商品和热销榜列表
     * @param array $data
     * @return mixed
     * @throws \Exception
     */
    public function otherGoodsList(array $data)
    {
        $goodsSn = $data['goods_sn'] ?? null;
        $categoryCode = $data['category_code'];
        if (!empty($goodsSn)) {
            $map[] = ['goods_sn', '=', $goodsSn];
            if (empty($data['allowAllStatus'] ?? false)) {
                $map[] = ['status', 'in', $this->getStatusByRequestModule(1)];
            }
            $cacheKey = 'otherGoodsInfo' . $goodsSn;
            $spuInfo = $this->where($map)->cache($cacheKey, 180)->findOrEmpty()->toArray();
            if (empty($spuInfo)) {
                throw new ServiceException(['msg' => '暂无此商品信息~']);
            }
        }

        //其他产品
        $customizeGoodsArray = [];

        //查看是否有自定义的相关推荐商品
        if (!empty($spuInfo)) {
            if (!empty($spuInfo['related_recommend_goods'])) {
                $customizeGoods = json_decode($spuInfo['related_recommend_goods'], true);
                $customizeGoodsArrays = $this->shopList(['searMoreGoods' => $customizeGoods, 'page' => 1, 'pageNumber' => 3, 'cache' => config('system.queueAbbr') . 'GoodsInfoOtherGoodsByCustomize' . $goodsSn, 'cache_expire' => 180]);
                $customizeGoodsArray = $customizeGoodsArrays['list'] ?? [];
            }
        }

        $customizeNumber = count($customizeGoodsArray ?? []);
        $other = $customizeGoodsArray;

        //自定义的相关推荐商品数量不足3则补足
        if ($customizeNumber < 3) {
            if (!empty($goodsSn)) {
                $mapData['notSearGoods'] = $goodsSn;
            }
            $mapData['category_code'] = $categoryCode;
            $mapData['page'] = 1;
            $mapData['pageNumber'] = (3 - ($customizeNumber ?? 0));
            $mapData['cache'] = config('system.queueAbbr') . 'GoodsInfoOtherGoods' . $categoryCode;
            $mapData['cache_expire'] = 180;
            $categoryOtherGoods = $this->shopList($mapData);
            $categoryOtherGood = $categoryOtherGoods['list'] ?? [];
            if (!empty($categoryOtherGood)) {
                $other = array_merge_recursive($customizeGoodsArray, $categoryOtherGood);
            }
        }

        //热销榜
        $hot = (new OrderGoods())->ShopHotSaleList(['orderType' => 2, 'page' => 1, 'pageNumber' => 3, 'order_belong' => 1, 'cache' => config('system.queueAbbr') . 'GoodsInfoHotSale', 'cache_expire' => 180]);

        $list['other'] = $other;
        $list['hot'] = $hot['list'];

        return $list;
    }

    /**
     * @title  商品列表-开放平台
     * @param array $sear
     * @return array
     * @throws \Exception
     */
    public function openList(array $sear = []): array
    {
        if(empty($sear['appId'])){
            return ['list' => [], 'pageTotal' => 0, 'total' => 6666666666];
        }
//        $userOpenGoods = OpenGoods::where(['appId'=>trim($sear['appId']),'status'=>1])->value('goods_sn');
//
//        if (empty($userOpenGoods)) {
//            return ['list' => [], 'pageTotal' => 0, 'total' => 0];
//        } else {
//            $map[] = ['goods_sn', 'in', explode(',', $userOpenGoods)];
//        }

        if (!empty($sear['keyword'])) {
            $map[] = ['', 'exp', Db::raw($this->getFuzzySearSql('title|goods_code', $sear['keyword']))];
        }

        if (!empty($sear['category_code'])) {
            $allCategory = Category::where(function ($query) use ($sear) {
                $mapOr[] = ['p_code', '=', $sear['category_code']];
                $map[] = ['code', '=', $sear['category_code']];
                $query->where($map)->cache('goodsSpuCategoryList')->whereOr([$mapOr]);
            })->where(['status' => 1])->column('code');
            if (!empty($allCategory)) {
                $map[] = ['category_code', 'in', $allCategory];
            }
        }

        if (!empty($sear['brand_code'])) {
            $map[] = ['brand_code', '=', $sear['brand_code']];
        }

        if (!empty($sear['goods_code'])) {
            $map[] = ['', 'exp', Db::raw($this->getFuzzySearSql('goods_code', $sear['goods_code']))];
        }

        $map[] = ['status', 'in', [1]];

        switch ($sear['sortType'] ?? null) {
            case 1:
                $sortField = 'create_time desc,sort asc';
                break;
            case 2:
                $sortField = 'sort asc,create_time desc';
                break;
            default:
                $sortField = 'create_time desc,sort asc';
        }

        $page = intval($sear['page'] ?? 0) ?: null;
        if (!empty($sear['pageNumber'] ?? null)) {
            $this->pageNumber = intval($sear['pageNumber']);
        }

        if (!empty($page)) {
            $aTotal = $this->where($map)->count();
            $pageTotal = ceil($aTotal / $this->pageNumber);
        }

        $list = $this->with(['category'])->where($map)->field('goods_sn,goods_code,title,main_image,category_code,brand_code,unit,belong,status,create_time,show_status,sort')
            ->when($page, function ($query) use ($page) {
                $query->page($page, $this->pageNumber);
            })->order($sortField)->select()->toArray();

        if (!empty($list)) {
            $aCategory = array_column($list, 'category_code');
            $pCategory = Category::with(['parent'])->where(['code' => $aCategory, 'status' => $this->getStatusByRequestModule($sear['searType'] ?? 1)])->select()->toArray();
            $allGoodsSn = array_unique(array_column($list, 'goods_sn'));
            if (!empty($pCategory)) {
                foreach ($list as $key => $value) {
                    foreach ($pCategory as $pcKey => $pcValue) {
                        if ($value['category_code'] == $pcValue['code']) {
                            if (!empty($pcValue['parent'])) {
                                $list[$key]['p_category_code'] = $pcValue['parent']['code'];
                                $list[$key]['p_category_name'] = $pcValue['parent']['name'];
                            }
                        }
                    }
                }
            }

        }

        return ['list' => $list, 'pageTotal' => $pageTotal ?? 0, 'total' => $aTotal ?? 0];
    }

    /**
     * @title  开放平台-商品详情
     * @param array $data
     * @return mixed
     * @throws \Exception
     */
    public function openInfo(array $data)
    {
        $goodsSn = $data['goods_sn'];
        $appId = $data['appId'];
        if(empty($appId)){
            return [];
        }
        $map[] = ['appId', '=', trim($appId)];
        $map[] = ['status', '=', 1];
        $map[] = ['', 'exp', Db::raw($this->getFuzzySearSql('goods_sn', $goodsSn))];
        $userOpenGoods = OpenGoods::where(['appId' => trim($appId), 'status' => 1])->value('goods_sn');

        if (empty($userOpenGoods)) {
            throw new OpenException(['errorCode' => 2600105]);
        }

        $info = $this->with(['category', 'brand', 'sku', 'goodsImages', 'goodsDetailImages'])->where(['goods_sn' => $goodsSn, 'status' => $this->getStatusByRequestModule(1)])->withoutField('id,update_time')->findOrEmpty()->toArray();
        if (!empty($info)) {
//            $allSkuVdc = GoodsSkuVdc::where(['goods_sn' => $goodsSn, 'status' => $this->getStatusByRequestModule(1)])->field('level,goods_sn,sku_sn,belong,purchase_price,vdc_genre,vdc_type,vdc_one,vdc_two')->select()->toArray();
//            if (!empty($allSkuVdc)) {
//                foreach ($info['sku'] as $key => $value) {
//                    foreach ($allSkuVdc as $vKey => $vValue) {
//                        if ($value['sku_sn'] == $vValue['sku_sn']) {
//                            $info['sku'][$key]['vdc'][] = $vValue;
//                        }
//                    }
//                }
//            }
            if (!empty($info['goodsImages'])) {
                $imageDomain = config('system.imgDomain');
                foreach ($info['goodsImages'] as $key => $value) {
                    $info['goodsImages'][$key]['image_url'] = substr_replace($value['image_url'], $imageDomain, strpos($value['image_url'], '/'), strlen('/'));
                }
            }
            if (!empty($info['goodsDetailImages'])) {
                $imageDomain = config('system.imgDomain');
//                foreach ($info['goodsDetailImages'] as $key => $value) {
//                    $info['goodsDetailImages'][$key]['image_url'] = substr_replace($value['image_url'],$imageDomain,strpos($value['image_url'],'/'),strlen('/'));
//                }
            }
            $info['related_recommend_goods_array'] = [];
            if (!empty($info['related_recommend_goods'])) {
                $relatedRecommendGoods = self::where(['goods_sn' => json_decode($info['related_recommend_goods'], true), 'status' => [1, 2]])->field('goods_sn,title,main_image')->select()->toArray();
                $info['related_recommend_goods_array'] = $relatedRecommendGoods ?? [];
            }
            if (!empty($info['goods_videos'])) {
                $info['goods_videos'] = json_decode($info['goods_videos'], true) ?? [];
            }
            if(!empty($info['postage_code'])){
                $postageInfo = (new PostageTemplate())->info(['code'=>$info['postage_code']]);
                if(!empty($postageInfo)){
                    if(!empty($postageInfo['detail'] ?? [])){
                        foreach ($postageInfo['detail'] as $key => $value) {
                            unset($postageInfo['detail'][$key]['id']);
                            unset($postageInfo['detail'][$key]['create_time']);
                        }
                    }
                }
                unset($postageInfo['create_time']);
                unset($postageInfo['update_time']);
                $info['postage_info'] = $postageInfo;
            }
        }
        return $info;
    }

    /**
     * @title  编辑商品价格锁定状态
     * @param array $data
     * @return bool
     */
    public function updateGoodsPriceMaintain(array $data)
    {
        $goodsSn = $data['goods_sn'];
        //价格维护状态 1为锁定中(正常使用,不允许修改价格) 2为维护中(C端下单库存为0,允许修改价格)
        $status = $data['status'] == 1 ? 2 : 1;
        $res = Db::transaction(function () use ($data, $goodsSn, $status) {
            $uRes = GoodsSku::update(['price_maintain' => $status], ['goods_sn' => $goodsSn, 'status' => [1, 2]]);
            return $uRes;
        });
        return judge($res);
    }

    public function category()
    {
        return $this->hasOne('Category', 'code', 'category_code')->where(['status' => 1])->bind(['category_name' => 'name']);
    }

    public function defaultSpuAttr()
    {
        $default['暂无属性'] = '等待添加中';
        return json_encode($default, JSON_UNESCAPED_UNICODE);
    }

    public function brand()
    {
        return $this->hasOne('Brand', 'brand_code', 'brand_code')->bind(['brand_name']);
    }

    public function tag()
    {
        return $this->hasOne('Tag', 'id', 'tag_id')->where(['status' => $this->getStatusByRequestModule(1)])->bind(['tag_icon' => 'icon']);
    }

    public function sku()
    {
        return $this->hasMany('GoodsSku', 'goods_sn', 'goods_sn')->where(['status' => $this->getStatusByRequestModule(1)]);
    }

    public function payOrder()
    {
        return $this->hasMany('OrderGoods', 'goods_sn', 'goods_sn')->where(['pay_status' => 2, 'after_status' => [1, -1]]);
    }

    public function shopSku()
    {
        return $this->hasMany('GoodsSku', 'goods_sn', 'goods_sn')->where(['status' => [1, 2]])->field('goods_sn,sku_sn,title,image,sub_title,specs,stock,market_price,sale_price,member_price,fare,free_shipping,postage_code,attach_type,status,price_maintain')->order('sort asc');
    }

    public function shopSkuVdc()
    {
        return $this->hasMany('GoodsSkuVdc', 'goods_sn', 'goods_sn')->where(['status' => 1])->field('goods_sn,sku_sn,level,purchase_price,vdc_genre,vdc_type,vdc_one,vdc_two')->order('level asc');
    }

    public function skuPrice()
    {
        return $this->hasMany('GoodsSku', 'goods_sn', 'goods_sn')->where(['status' => $this->getStatusByRequestModule(1)])->field('sale_price,goods_sn,free_shipping,stock,sku_sn,member_price,sale_price');
    }

    public function goodsImages()
    {
        return $this->hasMany('GoodsImages', 'goods_sn', 'goods_sn')->where(['status' => 1, 'type' => 1])->withoutField('status,update_time')->order('sort asc');
    }

    public function goodsImagesApi()
    {
        return $this->hasMany('GoodsImages', 'goods_sn', 'goods_sn')->where(['status' => 1, 'type' => 1])->field('goods_sn,image_url,sort')->order('sort asc');
    }


    public function goodsDetailImages()
    {
        return $this->hasMany('GoodsImages', 'goods_sn', 'goods_sn')->where(['status' => 1, 'type' => 3])->withoutField('status,update_time')->order('sort asc');
    }

    public function goodsDetailImagesApi()
    {
        return $this->hasMany('GoodsImages', 'goods_sn', 'goods_sn')->where(['status' => 1, 'type' => 3])->field('goods_sn,image_url,sort')->order('sort asc');
    }

    public function getUpTimeAttr($value)
    {
        return !empty($value) ? timeToDateFormat($value) : null;
    }

    public function getDownTimeAttr($value)
    {
        return !empty($value) ? timeToDateFormat($value) : null;
    }

    public function supplier()
    {
        return $this->hasOne('Supplier', 'supplier_code', 'supplier_code')->where(['status' => [1, 2]]);
    }

    public function goodsStock()
    {
        return $this->hasMany('GoodsSku', 'goods_sn', 'goods_sn')->where(['status' => 1]);
    }

    public function saleGoods()
    {
        return $this->hasMany('OrderGoods', 'goods_sn', 'goods_sn')->where(['pay_status' => 2, 'status' => 1]);
    }

    public function priceMaintain()
    {
        return $this->hasMany('GoodsSku', 'goods_sn', 'goods_sn')->where(['status' => 1, 'price_maintain' => 2])->field('goods_sn,sku_sn,price_maintain');
    }


}