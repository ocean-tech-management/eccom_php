<?php
// +----------------------------------------------------------------------
// |[ 文档说明: 活动模块Model]
// +----------------------------------------------------------------------
// | Copyright (c) 2018~2024 http://www.mlhcmk.com All rights reserved.
// +----------------------------------------------------------------------


namespace app\lib\models;


use app\BaseModel;
use app\lib\exceptions\ServiceException;
use Exception;
use think\facade\Cache;
use think\facade\Db;

class Activity extends BaseModel
{
    /**
     * @title  活动列表
     * @param array $sear
     * @return array
     * @throws Exception
     */
    public function list(array $sear = []): array
    {
        if (!empty($sear['keyword'])) {
            $map[] = ['', 'exp', Db::raw($this->getFuzzySearSql('title', $sear['keyword']))];
        }
        $map[] = ['status', 'in', $this->getStatusByRequestModule($sear['searType'] ?? 1)];
        if (!empty($sear['a_type'])) {
            $map[] = ['a_type', '=', $sear['a_type']];
        }
        if (!empty($sear['show_position'])) {
            if (is_array($sear['show_position'])) {
                $map[] = ['show_position', 'in', $sear['show_position']];
            } else {
                $map[] = ['show_position', '=', $sear['show_position']];
            }

        }

        $page = intval($sear['page'] ?? 0) ?: null;
        if (!empty($page)) {
            $aTotal = $this->where($map)->count();
            $pageTotal = ceil($aTotal / $this->pageNumber);
        }

        $list = $this->where($map)->when($page, function ($query) use ($page) {
            $query->page($page, $this->pageNumber);
        })->order('show_position asc,sort asc,create_time desc')->select()->each(function ($item) {
            if (!empty($item['start_time'] ?? null)) {
                $item['start_time'] = timeToDateFormat($item['start_time']);
            }
            if (!empty($item['end_time'] ?? null)) {
                $item['end_time'] = timeToDateFormat($item['end_time']);
            }
        })->toArray();

        return ['list' => $list, 'pageTotal' => $pageTotal ?? 0];
    }

    /**
     * @title  后台-活动详情
     * @param array $data
     * @return array
     */
    public function info(array $data)
    {
        $info = $this->where(['id' => $data['aId'], 'status' => $this->getStatusByRequestModule(1)])->findOrEmpty()->toArray();
        if(!empty($info)){
            if (!empty($info['start_time'] ?? null)) {
                $info['start_time'] = timeToDateFormat($info['start_time']);
            }
            if (!empty($info['end_time'] ?? null)) {
                $info['end_time'] = timeToDateFormat($info['end_time']);
            }
            //允许支付类型 1为商城余额支付 2为微信支付 3为支付宝支付 4为复用流水号支付 5为美丽金支付 6为银行卡协议支付 7为纯积分(美丽豆支付) 8为美丽券支付
            $allAllowTypeName = [1 => '商城余额支付', 2 => '微信支付', 3 => '支付宝支付', 4 => '复用流水号支付', 5 => '美丽金支付', 6 => '银行卡协议支付', 7 => '美丽豆支付', 8 => '美丽券支付'];
            if (!empty($info['allow_pay_type'] ?? [])) {
                foreach (explode(',', $info['allow_pay_type']) as $key => $value) {
                    $info['allow_pay_type_name_array'][] = $allAllowTypeName[$value];
                }
                if(!empty($info['allow_pay_type_name_array'])){
                    $info['allow_pay_type_name'] = implode(', ',$info['allow_pay_type_name_array']);
                }
            }
        }
        return $info;
    }

    /**
     * @title  编辑活动
     * @param array $data
     * @return mixed
     */
    public function edit(array $data)
    {
        if (!empty($data['start_time'] ?? null)) {
            $data['start_time'] = strtotime($data['start_time']);
        }
        if (!empty($data['end_time'] ?? null)) {
            $data['end_time'] = strtotime($data['end_time']);
        }
        $activityGiftType = self::where([$this->getPk() => $data['aId']])->value('gift_type');
        $info = $this->baseUpdate([$this->getPk() => $data['aId']], $data);
        //如果修改赠送类型则需要修改对应活动所有商品的赠送类型和数量
        if (!empty($data['gift_type'] ?? null) && $data['gift_type'] != $activityGiftType) {
            ActivityGoodsSku::where(['activity_id' => $data['aId']])->save(['gift_type' => $data['gift_type'], 'gift_number' => 0]);
        }
        //清除首页活动列表标签的缓存
        Cache::tag(['HomeApiActivityList', 'ApiHomeAllList','specialPayActivityList'])->clear();
        return $info;
    }

    /**
     * @title  新增活动
     * @param array $data
     * @return bool
     */
    public function DBNew(array $data)
    {
        if (!empty($data['start_time'] ?? null)) {
            $data['start_time'] = strtotime($data['start_time']);
        }
        if (!empty($data['end_time'] ?? null)) {
            $data['end_time'] = strtotime($data['end_time']);
        }
        $res = $this->baseCreate($data);
        return judge($res);
    }

    /**
     * @title  C端活动详情-商品列表
     * @param array $sear 活动id
     * @return array
     * @throws \Exception
     */
    public function cInfo(array $sear)
    {
        $id = $sear['aId'];
        $activityInfo = $this->where(['id' => $id, 'status' => $this->getStatusByRequestModule()])->withoutField('create_time,update_time,status')->findOrEmpty()->toArray();
        if (empty($activityInfo)) {
            throw new ServiceException(['msg' => '活动暂未开放~']);
        }
        if ($activityInfo['limit_type'] == 1 && !empty($activityInfo['start_time'] ?? null) && !empty($activityInfo['end_time'] ?? null)) {
            if ($activityInfo['start_time'] >= time() || $activityInfo['end_time'] <= time()) {
                throw new ServiceException(['msg' => '限时活动未在有效期内~']);
            }
        }

        if (!empty($activityInfo['start_time'] ?? null)) {
            $activityInfo['start_time'] = timeToDateFormat($activityInfo['start_time']);
        }
        if (!empty($activityInfo['end_time'] ?? null)) {
            $activityInfo['end_time'] = timeToDateFormat($activityInfo['end_time']);
        }

        if (!empty($sear['keyword'])) {
            $map[] = ['', 'exp', Db::raw($this->getFuzzySearSql('title', $sear['keyword']))];
        }
        $map[] = ['status', 'in', $this->getStatusByRequestModule()];
        $map[] = ['activity_id', '=', $id];

        $startTime = null;
        $hTimeList = [];
        if ($this->module == 'api') {
            if ($activityInfo['limit_type'] == 1) {
                //限时活动展示昨天今天明天后天的时间轴
                if ($id == 1) {
                    //date("Y-m-d", strtotime("+2 day"))
                    $allLimitTimeGoods = ActivityGoods::where(['activity_id' => $id, 'status' => 1])->whereBetweenTime('start_time', date("Y-m-d", strtotime("-1 day")), date("Y-m-d", strtotime("+2 day")) . ' 23:59:59')->whereTime('end_time', '>', time())->order('sort asc,create_time desc')->select()->toArray();
                } else {
                    $allLimitTimeGoods = ActivityGoods::where(['activity_id' => $id, 'status' => 1])->whereTime('end_time', '>', time())->order('sort asc,start_time asc, create_time desc')->select()->toArray();
                }
                //只有限时活动才能筛选时间轴
                if ($id == 1) {
                    if (!empty($allLimitTimeGoods)) {
                        $allGoodsSn = array_column($allLimitTimeGoods, 'goods_sn');
                        foreach ($allLimitTimeGoods as $key => $value) {
                            $goodsStartTime = $value['start_time'];
                            if (!is_numeric($value['start_time'])) {
                                $goodsStartTime = strtotime($value['start_time']);
                                $value['start_time'] = strtotime(date('Y-m-d', strtotime($value['start_time'])) . '00:00:00');
                            } else {
                                $value['start_time'] = strtotime(date('Y-m-d', $value['start_time']) . '00:00:00');
                            }

                            $goodsEndTime = $value['end_time'];
                            if(!is_numeric($value['end_time'])){
                                $goodsEndTime = strtotime($value['end_time']);
                            }

                            $timeList[] = $value['start_time'];
                            //获取每个时间轴对应的所有商品的开始时间,后续需要补充每个时间轴的最早开始时间
                            if (strtotime(date('Y-m-d ', $goodsStartTime) . '00:00:00') == $value['start_time']) {
                                $timeListStartGoods[$value['start_time']][] = $goodsStartTime;
                                $timeListEndGoods[$value['start_time']][] = $goodsEndTime;
                            }

                        }

                        $timeList = array_values(array_unique(array_filter($timeList)));

                        if (!empty($timeList)) {
                            asort($timeList);
                            $nowTime = NextNumberArray(time(), $timeList);

                            $startTime = strtotime(date('Y-m-d ', $nowTime) . '00:00:00');
                            $count = 0;
                            foreach ($timeList as $key => $value) {
                                $HourTime = strtotime(date('Y-m-d ', $value) . '00:00:00');
                                $hTimeList[$count]['time'] = date('Y-m-d', $HourTime);
                                if ($startTime == $HourTime) {
                                    $hTimeList[$count]['is_selected'] = 1;
                                } else {
                                    $hTimeList[$count]['is_selected'] = 2;
                                }
                                $count++;
                            }
                        }

                    }
                    //剔除重复时间
                    $existTime = [];
                    foreach ($hTimeList as $key => $value) {
                        if (in_array($value['time'], $existTime)) {
                            unset($hTimeList[$key]);
                        }
                        if (!isset($existTime[$value['time']])) {
                            $existTime[$value['time']] = $value['time'];
                        }
                    }

                    foreach ($hTimeList as $key => $value) {
                        //给每个时间加上对应商品最早的开始时间
                        $hTimeList[$key]['start_time_node'] = date('Y-m-d',time()).' 00:00:00';
                        if(!empty($timeListStartGoods[strtotime($value['time'])])){
                            $hTimeList[$key]['start_time_node'] = date('Y-m-d H',min($timeListStartGoods[strtotime($value['time'])])).':00:00';
                        }
//                        //给每个时间加上对应商品最早的结束时间
//                        $hTimeList[$key]['end_time_node'] = date('Y-m-d',time()).' 00:00:00';;
//                        if(!empty($timeListEndGoods[strtotime($value['time'])])){
//                            $hTimeList[$key]['end_time_node'] = date('Y-m-d H',min($timeListEndGoods[strtotime($value['time'])])).':00:00';
//                        }
                    }

                    $yesterdayTime = [];
                    $yesterdayIsSelected = false;
//                    //合并昨日所有时间的时间轴
//                    if (!empty($hTimeList)) {
//                        asort($hTimeList);
//                        $yesterday = date("Y-m-d", strtotime("-1 day"));
//                        foreach ($hTimeList as $key => $value) {
//                            $time = $value['time'];
//                            if (strtotime(date('Y-m-d', strtotime($time))) == strtotime($yesterday)) {
//                                $yesterdayTime[] = $time;
//                                if ($value['is_selected'] == 1) {
//                                    $yesterdayIsSelected = true;
//                                }
//                                unset($hTimeList[$key]);
//                            }
//                        }
//                        if (!empty($yesterdayTime)) {
//                            $mergeYesterdayTime['time'] = min($yesterdayTime);
//                            $mergeYesterdayTime['is_selected'] = !empty($yesterdayIsSelected) ? 1 : 2;
//                            array_unshift($hTimeList, $mergeYesterdayTime);
//                        }
//                    }
                }

                if (!empty($sear['start_time'])) {
                    $startTime = strtotime($sear['start_time']);
                    if (!empty($hTimeList)) {
                        $startTimeFormat = strtotime(date('Y-m-d ', $startTime) . '00:00:00');
                        foreach ($hTimeList as $key => $value) {
                            if (strtotime($value['time']) == $startTimeFormat) {
                                $hTimeList[$key]['is_selected'] = 1;
                            } else {
                                $hTimeList[$key]['is_selected'] = 2;
                            }
                        }
                    }

                }

                if (!empty($startTime)) {
//                    //如果查询的是昨天的日期,则搜索昨天的全部商品,不是则按照查询的小时
//                    if (date("Y-m-d", strtotime("-1 day")) == date('Y-m-d', $startTime)) {
//                        $map[] = ['start_time', '>=', strtotime(date('Y-m-d', $startTime) . '00:00:00')];
//                        $map[] = ['start_time', '<=', strtotime(date('Y-m-d', $startTime) . '23:59:59')];
//                    } else {
//                        $map[] = ['start_time', '>=', strtotime(date('Y-m-d H', $startTime) . ':00:00')];
//                        $map[] = ['start_time', '<=', strtotime(date('Y-m-d H', $startTime) . ':59:59')];
//                    }

                    $map[] = ['start_time', '>=', strtotime(date('Y-m-d', $startTime) . '00:00:00')];
                    $map[] = ['start_time', '<=', strtotime(date('Y-m-d', $startTime) . '23:59:59')];
                    $map[] = ['end_time', '>', time()];
                } else {
                    //只有限时活动才能筛选指定开始时间的商品
                    if ($id == 1) {
                        //仅查过去一天和未来两天的商品
                        $map[] = ['start_time', '>=', strtotime(date("Y-m-d", strtotime("-1 day")))];
                        $map[] = ['start_time', '<=', strtotime(date("Y-m-d", strtotime("+2 day")))];
//                        $map[] = ['start_time', '<', strtotime(date("Y-m-d", strtotime("+1 day")))];
                        //$map[] = ['start_time', '<=', time()];
                        $map[] = ['end_time', '>', time()];
                    } else {
                        $map[] = ['end_time', '>', time()];
                    }
                }
                if (!empty($allGoodsSn)) {
                    $map[] = ['goods_sn', 'in', $allGoodsSn];
                }
            }
        }

        $page = intval($sear['page'] ?? 0) ?: null;
        if (!empty($page)) {
            $aTotal = ActivityGoods::where($map)->count();
            $pageTotal = ceil($aTotal / $this->pageNumber);
        }
//        $field = $this->getListFieldByModule();
        $field = 'goods_sn,title,image,sub_title,sale_price,activity_price,sort,limit_type,start_time,end_time,sale_number,activity_id,vip_level,sale_number as spu_sale_number';

        $list = ActivityGoods::where($map)
            ->field($field)
            ->withSum(['SaleGoods' => function ($query) use ($startTime) {
//                if (!empty($startTime)) {
//                    $map[] = ['create_time', '>=', $startTime];
//                    $query->where($map);
//                }
            }], 'count')
            ->withSum('goodsStock', 'stock')
            ->withSum(['goodsStock' => 'virtual_stock'], 'virtual_stock')
            ->withMin(['marketPrice' => 'market_price'], 'market_price')
            ->when($page, function ($query) use ($page) {
                $query->page($page, $this->pageNumber);
            })->order('sort asc,create_time desc')->select()
            ->each(function ($item) {
                //虚拟销量
                $item['virtual_sale_number'] = ActivityGoodsSku::where(['activity_id' => $item['activity_id'], 'status' => 1, 'goods_sn' => $item['goods_sn']])->sum('sale_number');
                if (intval($item['virtual_sale_number']) <= 0) {
                    $item['virtual_sale_number'] = 0;
                }
                $realStock = $item['goods_stock_sum'];
                $realSale = $item['sale_goods_sum'] ?? 0;
                //添加虚拟销量
                if ($item['limit_type'] == 1) {
                    if (strtotime($item['start_time']) > time()) {
                        $item['virtual_sale_number'] = 0;
                        $item['virtual_stock'] = 0;
                    }
                }
                if (intval($item['goods_stock_sum']) <= 0) {
                    $item['salePercentage'] = 100;
                } else {
                    /*    秒杀进度条的计算公式为(实际销量 + 虚拟销量) / (实际销量 + 剩余库存 + 虚拟库存)
                          此公式的约束条件为
                          1. 虚拟销量 <= 虚拟库存
                          2. 在计算比例值时,当实际销量 / (实际销量 + 剩余库存) >= 1时, 则比例强制等于1, 无需带上虚拟销量和虚拟库存计算 (剩余总库存为0时的场景也适用)
                          3. 后台的交互上让运营人员填写需要展示的进度条百分比, 设虚拟销量和虚拟库存都为X, 根据公式反推计算出该商品需要的虚拟虚拟销量和虚拟库存, 并根据SKU数量分配到虚拟销量和虚拟库存到各个SKU(此处这么做是为了保留最大的灵活性, 保留后续要展示SKU级的进度条的拓展能力)

                          这么做的好处为:
                          当虚拟销量无限趋近于虚拟库存, 比例会越来越大, 当虚拟库存的值越来越大, 公式计算得到的比例也会越来越大, 但在实际销量未售罄的情况下, 该公式计算得出的结果不会等于1
            加上上述的两个约束能够很好的保证进度条和实际库存的动态平衡关系, 不会导致进度条满了但是进入详情还能买或者进度条还没满但是进入详情不能买的极端情况, 可以实现进度条因为实际销量的增长而逐步增长, 也可以人为的拉快进度条, 并且还保留了一定的拓展能力    */
                    //比例应该用(虚拟销量+实际销量)/(剩余库存+实际销量+虚拟库存)
                    if ((sprintf("%.2f", (intval($realSale) / intval($realStock + $realSale)))) * 100 >= 100) {
                        $item['salePercentage'] = 100;
                    } else {
                        $item['salePercentage'] = (sprintf("%.2f", (intval($item['virtual_sale_number'] + $realSale) / intval($realStock + $realSale + $item['virtual_stock'])))) * 100;
                    }
                    if (doubleval($item['salePercentage']) <= 1) {
                        $item['salePercentage'] = 1;
                    }
                    if (doubleval($item['salePercentage']) >= 100) {
                        $item['salePercentage'] = 100;
                    }
                }
//                $item['goods_stock_sum'] -= $item['virtual_sale_number'];
                $item['goods_stock_sum'] = $item['goods_stock_sum'] <= 0 ? 0 : intval($item['goods_stock_sum']);
                $item['sale_goods_sum'] += $item['virtual_sale_number'];
                $item['sale_goods_sum'] = $item['sale_goods_sum'] <= 0 ? 0 : intval($item['sale_goods_sum']);
                $item['sale_price'] = $item['activity_price'];
                unset($item['virtual_sale_number']);

                if (!empty($item['spu_sale_number'] ?? null)) {
                    $item['spu_sale_number'] = intval($item['spu_sale_number'] + ($realSale ?? 0));
                } else {
                    $item['spu_sale_number'] = intval(($realSale ?? 0));
                }

                //如果没到开始时间无论如何百分比都为0
                if ($item['limit_type'] == 1) {
                    if (strtotime($item['start_time']) > time()) {
                        $item['salePercentage'] = 0;
                        $item['sale_goods_sum'] = 0;
                        $item['spu_sale_number'] = 0;
                    }
                }
                if (!empty($item['salePercentage'])) {
                    $item['salePercentage'] = intval($item['salePercentage']);
                }

                //OSS自带的图片压缩或转格式
                if (!empty($item['image'])) {
                    $item['image'] .= '?x-oss-process=image/format,webp';
                }
                return $item;
            })->toArray();
        $finally['info'] = $activityInfo;

        if ($this->module == 'api' && $activityInfo['limit_type'] == 1) {
            $finally['timeList'] = $hTimeList ?? [];
        }
        $finally['goods'] = $this->dataFormat($list, $pageTotal ?? 0);

        //展示最近的结束时间
        $finally['cd'] = null;
        if (!empty($allLimitTimeGoods)) {
            $minTime = ActivityGoods::where($map)->min('end_time');
            if (!empty($minTime)) {
                $minTime = date('Y-m-d H:i:s', $minTime);
                $finally['cd'] = $minTime;
            }
        }

        return $finally;

    }

    /**
     * @title  新增活动
     * @param array $data
     * @return mixed
     */
    public function new(array $data)
    {
        return $this->baseCreate($data, true);
    }

    /**
     * @title  C端首页活动列表
     * @param array $sear
     * @return array
     * @throws Exception
     */
    public function cHomeList(array $sear = []): array
    {
        $cacheKey = 'HomeApiActivityList' . ($sear['show_position'] ?? 1);
        $cacheExpire = 600;
        $cacheTag = 'HomeApiActivityList';

        if (empty($sear['clearCache'])) {
            $cacheList = cache($cacheKey);
            if (!empty($cacheList)) {
                return $cacheList;
            }
        }
        $needGoodsSummary = false;

        $map[] = ['a_type', '=', 1];
        $map[] = ['status', '=', 1];
        $map[] = ['show_position', '=', ($sear['show_position'] ?? 1)];

        if (!empty($sear['limit_end_time'])) {
//            $map[] = ['end_time', '>=', $sear['limit_end_time']];
            $map[] = ['', 'exp', Db::raw('end_time - '.time().' '.$sear['limit_end_type'].$sear['limit_end_time'])];
            $needGoodsSummary = true;
        }
        if (!empty($sear['needGoodsSummary'] ?? null)) {
            $needGoodsSummary = true;
        }
        $activityList = $this->field('id,title,icon,desc,type,goods_show_form,sort,limit_type,background_image,poster,start_time,end_time,show_position')->where($map)->order('sort asc,create_time desc')->select()->toArray();
        $aActivity = [];
        if (!empty($activityList)) {
            foreach ($activityList as $key => $value) {
                $activityId[$key]['activity_id'] = $value['id'];
                $activityId[$key]['limit_type'] = $value['limit_type'];
                if ($value['id'] == 2) {
                    $activityId[$key]['limit_number'] = 20;
                } else {
                    $activityId[$key]['limit_number'] = (!empty($sear['limit_number'] ?? null)) ? intval($sear['limit_number']) : 20;
                }

                $aActivity[$key]['id'] = $value['id'];
                $aActivity[$key]['title'] = $value['title'];
                $aActivity[$key]['desc'] = $value['desc'];
                $aActivity[$key]['icon'] = $value['icon'];
                $aActivity[$key]['type'] = $value['type'];
                $aActivity[$key]['goods_show_form'] = $value['goods_show_form'];
                $aActivity[$key]['limit_type'] = $value['limit_type'];
                $aActivity[$key]['background_image'] = $value['background_image'];
                $aActivity[$key]['poster'] = $value['poster'];
                $aActivity[$key]['start_time'] = !empty($value['start_time']) ? timeToDateFormat($value['start_time']) : null;
                $aActivity[$key]['end_time'] = !empty($value['end_time']) ? timeToDateFormat($value['end_time']) : null;
                $aActivity[$key]['all_number'] = 0;

                //如果是已经过期的时限活动则剔除
                if ($value['limit_type'] == 1 && !empty($value['start_time']) && !empty($value['end_time'])) {
                    if ($value['end_time'] <= time()) {
                        unset($activityList[$key]);
                        unset($aActivity[$key]);
                        continue;
                    }
                    if (!empty($value['show_position']) && $value['show_position'] == 5) {
                        if ($value['start_time'] > time()) {
                            unset($activityList[$key]);
                            unset($aActivity[$key]);
                            continue;
                        }
                    }
                }
            }

            $query = null;
            $summaryQuery = null;
            $allActivity = count($activityId);
            //组装sql,采用union
            $fieldList = 'activity_id,activity_type,goods_sn,title,image,sub_title,sale_price,activity_price,sort,limit_type,start_time,end_time';
            foreach ($activityId as $key => $value) {
                if ($value['limit_type'] == 1) {
                    //如果有时间现在需要判断是否在时间限制内,如果是限时秒杀活动需要加多判断查昨天今天明天后天的数据
                    if ($value['activity_id'] == 1) {
                        $startTime = strtotime(date("Y-m-d", strtotime("-1 day")));
                        $endTime = strtotime(date("Y-m-d", strtotime("+2 day")));
//                        $endTime = strtotime(date("Y-m-d", strtotime("+1 day")));
                        $DBQuery = 'status = 1 AND start_time >=' . $startTime . ' AND start_time < ' . $endTime . ' AND end_time >' . time() . ' AND activity_id = ' . $value['activity_id'] . ' ORDER BY sort ASC , create_time desc limit ' . $value['limit_number'];
                    } else {
                        $DBQuery = 'status = 1 AND end_time >' . time() . ' AND activity_id = ' . $value['activity_id'] . ' ORDER BY sort ASC , start_time ASC, create_time desc limit ' . $value['limit_number'];
                    }
                } else {
                    $DBQuery = 'activity_id = ' . $value['activity_id'] . ' and  STATUS = 1 ORDER BY sort ASC , create_time desc limit ' . $value['limit_number'];
                }
                if ($allActivity == 1) {
                    $query = '(SELECT ' . $fieldList . ' FROM sp_activity_goods WHERE ' . $DBQuery . ')';
                } else {
                    if ($key != ($allActivity - 1)) {
                        $query .= '(SELECT ' . $fieldList . ' FROM sp_activity_goods WHERE ' . $DBQuery . ') union ';

                        //统计汇总所有活动的有效商品总数
                        if (!empty($needGoodsSummary)) {
                            $summaryQuery .= '(SELECT activity_id, count(*) as all_number FROM sp_activity_goods WHERE ' . $DBQuery . ' ) union ';
                        }

                    } else {
                        $query .= '(SELECT ' . $fieldList . ' FROM sp_activity_goods WHERE ' . $DBQuery . ')';

                        //统计汇总所有活动的有效商品总数
                        if (!empty($needGoodsSummary)) {
                            $summaryQuery .= '(SELECT activity_id,count(*) as all_number FROM sp_activity_goods WHERE ' . $DBQuery . ')';
                        }
                    }
                }

            }

            $goodsSummaryList = [];

            if (!empty($query)) {
                $list = Db::query($query);
                if(!empty($needGoodsSummary) && !empty($summaryQuery)){
                    $goodsSummaryList = Db::query($summaryQuery);
                }

                if (!empty($list)) {
                    //查找市场价
                    $allGoodsSn = array_column($list, 'goods_sn');
                    $goodsInfo = GoodsSpu::where(['goods_sn' => $allGoodsSn, 'status' => 1])
                        ->field('goods_sn')
//                       ->withMin(['sku'=>'sale_price'],'sale_price')
                        ->withMin(['sku' => 'market_price'], 'market_price')
                        ->withSum(['sku' => 'goods_stock_sum'], 'stock')->select()->toArray();
                    if (!empty($goodsInfo)) {
                        foreach ($goodsInfo as $key => $value) {
                            $goodsInfos[$value['goods_sn']]['market_price'] = $value['market_price'];
                            $goodsInfos[$value['goods_sn']]['goods_stock_sum'] = $value['goods_stock_sum'];
//                            $goodsInfos[$value['goods_sn']]['sale_price'] = $value['sale_price'] ?? 0;
                        }
                    }

//                   //查询每个商品最低活动价
//                   $activitySku = ActivityGoodsSku::where(['goods_sn'=>$allGoodsSn,'status'=>1])->field('min(activity_price) as activity_price,goods_sn')->group('goods_sn')->select()->toArray();
//                    if(!empty($activitySku)){
//                        foreach ($activitySku as $key => $value) {
//                            $activitySkuPrice[$value['goods_sn']]['activity_price'] = $value['activity_price'];
//                        }
//                    }

                    foreach ($aActivity as $key => $value) {
                        foreach ($list as $gKey => $gValue) {
                            //剔除未开始的商品
                            if (!empty($value['show_position']) && $value['show_position'] == 5 && !empty($gValue['start_time'] ?? null) && $gValue['start_time'] > time()) {
                                unset($list[$gKey]);
                                continue;
                            }
                            if (!empty($gValue['start_time'])) {
                                $gValue['start_time'] = timeToDateFormat($gValue['start_time']);
                            }
                            if (!empty($gValue['end_time'])) {
                                $gValue['end_time'] = timeToDateFormat($gValue['end_time']);
                            }
                            //图片展示缩放,OSS自带功能
                            if (!empty($gValue['image'])) {
//                                $gValue['image'] .= '?x-oss-process=image/resize,h_400,m_lfit';
                                $gValue['image'] .= '?x-oss-process=image/format,webp';
                            }
                            if (!empty($gValue['poster'])) {
                                $gValue['poster'] .= '?x-oss-process=image/format,webp';
                            }
                            $gValue['market_price'] = $gValue['sale_price'];
                            $gValue['goods_stock_sum'] = 0;
                            if (!empty($goodsInfos[$gValue['goods_sn']])) {
                                $gValue['market_price'] = $goodsInfos[$gValue['goods_sn']]['market_price'] ?? $gValue['sale_price'];
                                $gValue['goods_stock_sum'] = $goodsInfos[$gValue['goods_sn']]['goods_stock_sum'] ?? 0;
//                                $gValue['sale_price'] = $goodsInfos[$gValue['goods_sn']]['sale_price'] ?? 0;
                            }
//                            if(!empty($activitySkuPrice[$gValue['goods_sn']])){
//                                $gValue['activity_price'] = $activitySkuPrice[$gValue['goods_sn']]['activity_price'] ?? 0;
//                            }

                            if ($gValue['activity_id'] == $value['id']) {
                                $aActivity[$key]['goods'][] = $gValue;
                            }
                        }
                        //图片展示缩放,OSS自带功能
                        if (!empty($value['poster'])) {
                            $aActivity[$key]['poster'] .= '?x-oss-process=image/format,webp';
                        }

                        //是否需要补齐商品汇总数量
                        if (!empty($needGoodsSummary) && !empty($goodsSummaryList)) {

                            foreach ($goodsSummaryList as $gKey => $gValue) {
                                if ($value['id'] == $gValue['activity_id']) {
                                    $aActivity[$key]['all_number'] = $gValue['all_number'];
                                }
                            }
                        }

                    }

                    foreach ($aActivity as $key => $value) {
                        if ($value['id'] == 1) {
                            $aActivity[$key]['timeList'] = $this->cInfo(['aId' => $value['id']])['timeList'] ?? [];
                            $aActivity[$key]['goods'] = $this->cInfo(['aId' => $value['id']])['goods'] ?? [];
                            $aActivity[$key]['cd'] = null;
                            if(!empty($aActivity[$key]['goods'])){
                                $aActivity[$key]['cd'] = min(array_column($aActivity[$key]['goods'], 'end_time'));
                            }
                            break;
                        }
                    }

                    if (!empty($aActivity)) {
                        cache($cacheKey, $aActivity, $cacheExpire, $cacheTag);
                    }
                }
            }
        }


        return $aActivity;
    }

    /**
     * @title  C端-首页活动四宫格列表
     * @param array $sear
     * @return array
     * @throws \Exception
     */
    public function cHomeOtherList(array $sear = []): array
    {
        $cacheKey = 'HomeApiOtherActivityList';
        $cacheExpire = 600;
        if (empty($sear['clearCache'])) {
            $cacheList = cache($cacheKey);
            if (!empty($cacheList)) {
                return $cacheList;
            }
        }

        $map[] = ['a_type', '=', 1];
        $map[] = ['status', '=', 1];
        $map[] = ['show_position', '=', 3];
        $activityList = $this->field('id,title,icon,desc,type,goods_show_form,sort,limit_type,background_image,url_content')->where($map)->order('sort asc,create_time desc')->select()->each(function ($item) {
            //图片展示缩放,OSS自带功能
            if (!empty($item['background_image'])) {
//                $item['background_image'] .= '?x-oss-process=image/resize,h_350,m_lfit';
                $item['background_image'] .= '?x-oss-process=image/format,webp';
            }
            return $item;
        })->toArray();
        if (!empty($activityList)) {
            cache($cacheKey, $activityList, $cacheExpire);
        }
        return $activityList;
    }

    /**
     * @title  团长大礼包列表
     * @param array $sear
     * @return array|mixed
     * @throws \Exception
     */
    public function memberActivityList(array $sear = [])
    {
        $cacheKey = 'HomeApiMemberActivityList';
        $cacheExpire = 600;
//        if (empty($sear['clearCache'])) {
//            $cacheList = cache($cacheKey);
//            if (!empty($cacheList)) {
//                return $cacheList;
//            }
//        }
        $map[] = ['a_type', '=', 2];
        $map[] = ['status', '=', 1];
//        $map[] = ['end_time', '>', time()];
        if (isset($sear['vip_level'])) {
            $gMap[] = ['vip_level', '=', intval($sear['vip_level'] ?? 0)];
        }

        $activityList = $this->where($map)->order('sort asc,create_time desc')->column('id');
        $gMap[] = ['activity_id', 'in', $activityList];
        $gMap[] = ['status', '=', 1];
        $gMap[] = ['end_time', '>', time()];
        $page = intval($sear['page'] ?? 0) ?: null;
        if (!empty($page)) {
            $aTotal = ActivityGoods::where($gMap)->count();
            $pageTotal = ceil($aTotal / $this->pageNumber);
        }
//        $activityList = $this->with(['mGoods'=>function($query){
//            $query->withoutField('id,update_time')->order('create_time');
//        }])->field('id,title,a_type,icon,desc,type,goods_show_form,sort,limit_type')->where($map)
//        ->when($page,function($query) use ($page){
//            $query->page($page,$this->pageNumber);
//        })->order('sort asc,create_time desc')->select()->toArray();

        $goodsList = ActivityGoods::with(['activity'])->where($gMap)->withoutField('id,update_time')
            ->when($page, function ($query) use ($page) {
                $query->page($page, $this->pageNumber);
            })->withMin(['marketPrice' => 'market_price'], 'market_price')->order('sort asc,create_time desc')->select()->each(function ($item) {
                if (!empty($item['image'])) {
                    $item['image'] .= '?x-oss-process=image/format,webp';
                }
                return $item;
            })->toArray();
        switch (intval($sear['vip_level'])) {
            case 0:
                $iMap[] = ['level', '=', 3];
                break;
            case 1:
                break;
            case 2:
                $iMap[] = ['level', '=', 1];
                break;
            case 3:
                $iMap[] = ['level', '=', 2];
                break;

        }
        if (!empty($iMap)) {
            $iMap[] = ['status', 'in', [1, 2]];
            $info = MemberVdc::where($iMap)->field('background,poster')->findOrEmpty()->toArray();
            if (!empty($info['background'])) {
                $info['background'] .= '?x-oss-process=image/format,webp';
            }
            if (!empty($info['poster'])) {
                $info['poster'] .= '?x-oss-process=image/format,webp';
            }
        }

        $finally['info'] = $info ?? [];
        $finally['goods'] = $this->dataFormat($goodsList, $pageTotal ?? 0);

        return $finally;
    }


    /**
     * @title  删除活动
     * @param int $id 活动id
     * @param bool $noClearCache 是否清除缓存
     * @return mixed
     */
    public function del(int $id, bool $noClearCache = false)
    {
        $res = $this->cache('HomeApiActivityList')->where([$this->getPk() => $id])->save(['status' => -1]);
        //一并删除活动商品
        if (!empty($res)) {
            ActivityGoods::update(['status' => -1], ['activity_id' => $id, 'status' => [1, 2]]);
            ActivityGoodsSku::update(['status' => -1], ['activity_id' => $id, 'status' => [1, 2]]);
        }
        if (empty($noClearCache)) {
            if ($res) {
                cache('ApiHomeAllList', null);
                //清除首页活动列表标签的缓存
                Cache::tag(['HomeApiActivityList', 'ApiHomeAllList','specialPayActivityList'])->clear();
            }
        }

        return $res;
    }

    /**
     * @title  上下架活动
     * @param array $data
     * @return mixed
     */
    public function upOrDown(array $data)
    {
        if ($data['status'] == 1) {
            $save['status'] = 2;
        } elseif ($data['status'] == 2) {
            $save['status'] = 1;
        } else {
            return false;
        }
        $res = $this->where([$this->getPk() => $data['activity_id']])->save($save);

        if (empty($data['noClearCache'] ?? null)) {
            if ($res) {
                cache('ApiHomeAllList', null);
                cache('HomeApiActivityList', null);
                cache('specialPayActivityList', null);
                //清除首页活动列表标签的缓存
                Cache::tag(['HomeApiActivityList', 'ApiHomeAllList','specialPayActivityList'])->clear();
            }
        }

        return $res;
    }

    public function goods()
    {
        return $this->hasMany('Activity', $this->getPk(), 'activity_id')->where(['status' => 1]);
    }

    public function goodsLimit()
    {
        $mapGoods[] = ['status', '=', 1];
        $mapGoods[] = ['start_time', '<=', time()];
        $mapGoods[] = ['end_time', '>=', time()];
        return $this->hasMany('Activity', $this->getPk(), 'activity_id')->where(['status' => 1]);
    }

    /**
     * @title  返回数据格式化
     * @param array $data
     * @param int|null $pageTotal
     * @return array
     */
    public function dataFormat(array $data, int $pageTotal = null)
    {
        $dataEmpty = false;
        $aReturn = [];
        if (empty($data)) {
            $message = '暂无数据';
            $dataEmpty = true;
            if (isset($pageTotal) && !empty($pageTotal)) {
                $aReturn['pageTotal'] = $pageTotal;
                $aReturn['list'] = [];
            }
        }

        if (empty($dataEmpty)) {
            if (!empty($pageTotal)) {
                $aReturn['pageTotal'] = $pageTotal;
                $aReturn['list'] = $data ? $data : [];
            } else {
                $aReturn = $data;
            }

        }
        return $aReturn;
    }

    /**
     * @title  品牌馆列表
     * @param array $sear
     * @return array
     * @throws \Exception
     */
    public function brandSpaceList(array $sear = [])
    {
        $list = $this->cHomeList(['show_position' => 4]);
        return $list ?? [];
    }

    /**
     * @title  限时专场列表
     * @param array $sear
     * @return array
     * @throws \Exception
     */
    public function specialAreaList(array $sear = [])
    {
        $searData['show_position'] = 5;
        $searData['clearCache'] = true;
        //1为结束时间大于24小时的 2为结束时间小于等于24小时的
        switch ($sear['type'] ?? 1){
            case 1:
//                $searData['limit_end_time'] = strtotime(date("Y-m-d", strtotime("+1 day")) . ' 23:59:59');
                $searData['limit_end_time'] = 24 * 3600;
                $searData['limit_end_type'] = '>';
                break;
            case 2:
//                $searData['limit_end_time'] = strtotime(date("Y-m-d",strtotime("+1 day")) . ' 23:59:59');
                $searData['limit_end_time'] = 24 * 3600;
                $searData['limit_end_type'] = "<=";
                break;
            default:
                break;
        }
        $searData['limit_number'] = 3;
        $list = $this->cHomeList($searData);
        return $list ?? [];
    }

    /**
     * @title  特殊支付方式活动列表
     * @param array $sear
     * @return array
     * @throws \Exception
     */
    public function specialPayList(array $sear = [])
    {
        $searData['show_position'] = 6;
        $searData['clearCache'] = true;
        $cacheKey = "specialPayActivityList";
        if (!empty(cache($cacheKey))) {
            return cache($cacheKey);
        }
//        $list = $this->cHomeList($searData);
        $searMap[] = ['show_position', '=', 6];
        $searMap[] = ['status', '=', 1];
        $list = self::where($searMap)->field('id,icon,title')->order('sort asc,create_time desc')->select()->toArray();
        if (!empty($list)) {
            cache($cacheKey, $list, (3600 * 8),'specialPayActivityList');
        }
        return $list ?? [];
    }

    /**
     * @title  全部活动名称
     * @param array $data
     * @return array
     * @throws \Exception
     */
    public function allActivity(array $data)
    {
        $type = $data['type'] ?? 1;
        switch ($type) {
            case 1:
                $list = self::where(['a_type' => 1])->field('id as activity_sign,title as name')->order('show_position asc,create_time desc')->select()->toArray();
                break;
            case 2:
                $list = PtActivity::field('activity_code as activity_sign,activity_title as name')->select()->toArray();
                break;
            case 3:
                $list = self::where(['a_type' => 2])->field('id as activity_sign,title as name')->select()->toArray();
                break;
            case 4:
                $list = PpylActivity::field('activity_code as activity_sign,activity_title as name')->select()->toArray();
                break;
        }
        return $list ?? [];
    }

    private function getListFieldByModule()
    {
        switch ($this->module) {
            case 'admin':
            case 'manager':
                $field = '*';
                break;
            case 'api':
                $field = 'goods_sn,title,image,sub_title,sale_price,activity_price,sort,limit_type,start_time,end_time,sale_number,activity_id,vip_level';
                break;
            default:
                $field = 'a.order_sn,a.uid,b.name as user_name,a.goods_sn,a.sku_sn,c.title as goods_title,c.image as goods_images,a.type,a.apply_reason,a.apply_status,a.apply_price,a.received_goods,a.verify_status,a.verify_reason,a.apply_time,a.verify_time,a.create_time,a.status';
        }
        return $field;
    }

    public function mGoods()
    {
        return $this->hasMany('ActivityGoods', 'activity_id', 'id')->where(['status' => $this->getStatusByRequestModule(1)]);
    }

    public function sku()
    {
        return $this->hasMany('ActivityGoodsSku', 'activity_id', 'id')->where(['status' => $this->getStatusByRequestModule(1)]);
    }
}