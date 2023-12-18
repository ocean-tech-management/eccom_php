<?php
// +----------------------------------------------------------------------
// |[ 文档说明: 众筹期模块Model]
// +----------------------------------------------------------------------



namespace app\lib\models;


use app\BaseModel;
use app\lib\exceptions\CrowdFundingActivityException;
use app\lib\exceptions\OrderException;
use app\lib\services\CodeBuilder;
use think\facade\Cache;
use think\facade\Db;
use think\facade\Queue;

class CrowdfundingPeriod extends BaseModel
{

    /**
     * @title  期列表
     * @param array $sear
     * @return array
     * @throws \Exception
     */
    public function list(array $sear = []): array
    {
        if (!empty($sear['keyword'])) {
            $map[] = ['', 'exp', Db::raw($this->getFuzzySearSql('title', $sear['keyword']))];
        }
        $map[] = ['status', 'in', $this->getStatusByRequestModule($sear['searType'] ?? 1)];
        if (!empty($sear['result_status'])) {
            $map[] = ['result_status', '=', $sear['result_status']];
        }
        $map[] = ['activity_code', '=', $sear['activity_code']];
        $page = intval($sear['page'] ?? 0) ?: null;
        if (!empty($page)) {
            $aTotal = $this->where($map)->count();
            $pageTotal = ceil($aTotal / $this->pageNumber);
        }

        $list = $this->where($map)->when($page, function ($query) use ($page) {
            $query->page($page, $this->pageNumber);
        })->order('sort asc,create_time desc')->select()->each(function ($item) {
            if (!empty($item['start_time'])) {
                $item['start_time'] = timeToDateFormat($item['start_time']);
            }
            if (!empty($item['end_time'])) {
                $item['end_time'] = timeToDateFormat($item['end_time']);
            }
            if ($item['last_sales_price'] < 0) {
                $item['last_sales_price'] = '已超卖' . priceFormat(abs($item['last_sales_price']));
            }
        })->toArray();

        return ['list' => $list, 'pageTotal' => $pageTotal ?? 0];
    }

    /**
     * @title  期详情
     * @param array $data
     * @return array
     */
    public function info(array $data)
    {
        $info = $this->with(['activityName'])->where(['activity_code' => $data['activity_code'], 'round_number' => $data['round_number'], 'period_number' => $data['period_number'], 'status' => $this->getStatusByRequestModule(1)])->findOrEmpty()->toArray();
        if (!empty($info)) {
            $info['gift_type'] = CrowdfundingActivity::where(['activity_code' => $data['activity_code']])->value('gift_type');
        }
        return $info;
    }

    /**
     * @title  更新销售额
     * @param array $data
     * @return bool
     */
    public function updateSalesPrice(array $data)
    {
        //判断操作缓存锁
        $this->checkOperateCache($data);
        $periodInfo = self::where(['activity_code' => $data['activity_code'], 'round_number' => $data['round_number'], 'period_number' => $data['period_number'], 'status' => [1, 2]])->findOrEmpty()->toArray();
        if (empty($periodInfo)) {
            throw new CrowdFundingActivityException(['msg' => '暂无符合的期']);
        }
        if ($periodInfo['buy_status'] == 1 || $periodInfo['result_status'] != 4 || ($periodInfo['last_sales_price'] <= 0)) {
            throw new CrowdFundingActivityException(['msg' => '非认购中或认购满的期不允许编辑']);
        }

        if (empty($data['sales_price'] ?? 0)) {
            return true;
        }
        if ($data['sales_price'] < 0 && $data['sales_price'] + $periodInfo['last_sales_price'] < 0) {
            throw new CrowdFundingActivityException(['msg' => '不允许将销售额降低超过当前剩余认购额']);
        }
        $DBRes = Db::transaction(function () use ($data, $periodInfo) {
            $periodLockInfo = self::where(['id' => $periodInfo['id']])->lock(true)->findOrEmpty()->toArray();
            if ($periodLockInfo['sales_price'] + $data['sales_price'] <= 0) {
                throw new CrowdFundingActivityException(['msg' => '不允许编辑销售额小于等于0']);
            }
            $data['sales_price'] = doubleval($data['sales_price']);
            $this->where(['id' => $periodInfo['id']])->inc('sales_price', $data['sales_price'])->update();
            $this->where(['id' => $periodInfo['id']])->inc('last_sales_price', $data['sales_price'])->update();
            return true;
        });
        return judge($DBRes);
    }

    /**
     * @title  更新参与门槛条件
     * @param array $data
     * @return bool
     */
    public function updateCondition(array $data)
    {
        //判断操作缓存锁
        $this->checkOperateCache($data);
        $periodInfo = self::where(['activity_code' => $data['activity_code'], 'round_number' => $data['round_number'], 'period_number' => $data['period_number'], 'status' => [1, 2]])->findOrEmpty()->toArray();
        if (empty($periodInfo)) {
            throw new CrowdFundingActivityException(['msg' => '暂无符合的期']);
        }
        if ($periodInfo['buy_status'] == 1 || $periodInfo['result_status'] != 4 || ($periodInfo['last_sales_price'] <= 0)) {
            throw new CrowdFundingActivityException(['msg' => '非认购中或认购满的期不允许编辑']);
        }
        if ($data['join_condition_type'] == -1) {
            $data['condition_price'] = 0;
        }
        if ($data['price_compute_time_type'] == 1) {
            $data['condition_price_start_time'] = null;
            $data['condition_price_end_time'] = null;
        }
        if ($data['price_compute_time_type'] == 2 && (empty($data['condition_price_start_time']) || empty($data['condition_price_end_time']))) {
            throw new CrowdFundingActivityException(['msg' => '请选择判断时间范围']);
        }

        $DBRes = Db::transaction(function () use ($data, $periodInfo) {
            $periodLockInfo = self::where(['id' => $periodInfo['id']])->lock(true)->findOrEmpty()->toArray();
            $saveData['join_condition_type'] = $data['join_condition_type'];
            $saveData['condition_price'] = $data['condition_price'] ?? 0;
            $saveData['price_compute_time_type'] = $data['price_compute_time_type'] ?? 1;
            $saveData['price_compute_type'] = $data['price_compute_type'] ?? 1;
            $saveData['condition_price_start_time'] = $data['condition_price_start_time'] ?? null;
            $saveData['condition_price_end_time'] = $data['condition_price_end_time'] ?? null;
            $this->where(['id' => $periodInfo['id'], 'status' => [1, 2]])->save($saveData);
            return true;
        });
        return judge($DBRes);
    }

    /**
     * @title  强制完成期
     * @param array $data
     * @return bool
     */
    public function completePeriod(array $data)
    {
        //判断锁
        $this->checkOperateCache($data);
        $periodInfo = self::where(['activity_code' => $data['activity_code'], 'round_number' => $data['round_number'], 'period_number' => $data['period_number'], 'status' => [1, 2]])->findOrEmpty()->toArray();
        if (empty($periodInfo)) {
            throw new CrowdFundingActivityException(['msg' => '暂无符合的期']);
        }
        if ($periodInfo['buy_status'] == 1 || $periodInfo['result_status'] != 4 || ($periodInfo['last_sales_price'] <= 0)) {
            throw new CrowdFundingActivityException(['msg' => '非认购中或认购满的期不允许操作']);
        }
        //推队列处理
        $queueRes = $crowdQueue = Queue::later(5, 'app\lib\job\CrowdFunding', ['activity_code' => $data['activity_code'], 'round_number' => $data['activity_code'], 'period_number' => $data['period_number'], 'dealType' => 1, 'operateType' => 3], config('system.queueAbbr') . 'CrowdFunding');
        return judge($queueRes);
    }

    /**
     * @title  判断是否可以完成期(后台手动触发, 主要是防止卡主的情况)
     * @param array $data
     * @return bool
     */
    public function completePeriodByOrder(array $data)
    {
        //判断锁
        $this->checkOperateCache($data);
        $cacheKey = 'OperateCompletePeriodByOrder-' . (implode('-', $data));
        if (!empty(cache($cacheKey))) {
            throw new CrowdFundingActivityException(['msg' => '系统正在执行此类型操作,请勿频繁操作,请耐心等待!执行一次后仍无法成功请联系运维管理员, 切勿重复多次执行']);
        }
        cache($cacheKey, $data, (3600 * 12));
        $periodInfo = self::where(['activity_code' => $data['activity_code'], 'round_number' => $data['round_number'], 'period_number' => $data['period_number'], 'status' => [1, 2]])->findOrEmpty()->toArray();
        if (empty($periodInfo)) {
            throw new CrowdFundingActivityException(['msg' => '暂无符合的期']);
        }
        if ($periodInfo['buy_status'] == 1 || $periodInfo['result_status'] != 4 || ($periodInfo['last_sales_price'] > 0)) {
            throw new CrowdFundingActivityException(['msg' => '非认购中或未认购满的期不允许操作']);
        }
        $lastOrderSn = OrderGoods::where(['crowd_code' => $data['activity_code'], 'crowd_round_number' => $data['round_number'], 'crowd_period_number' => $data['period_number'], 'status' => 1, 'pay_status' => 2])->order('create_time desc')->value('order_sn');
        $res = false;
        if (!empty($lastOrderSn)) {
            $orderInfo = Order::where(['order_sn' => $lastOrderSn])->findOrEmpty()->toArray();
            $res = Queue::push('app\lib\job\CrowdFunding', ['dealType' => 1, 'order_sn' => $lastOrderSn, 'orderInfo' => $orderInfo], config('system.queueAbbr') . 'CrowdFunding');
        } else {
            throw new CrowdFundingActivityException(['msg' => '查无符合的订单无法执行判断']);
        }
        return judge($res);
    }

    /**
     * @title  新增期
     * @param array $data
     * @return mixed
     * @throws \Exception
     */
    public function DBNew(array $data)
    {
        $roundNumber = $data['round_number'];
        $periodNumber = $data['period_number'];
        $existPeriod = self::where(['activity_code' => $data['activity_code'], 'round_number' => $data['round_number'], 'period_number' => $data['period_number'], 'status' => [1,2]])->field('id')->findOrEmpty()->toArray();
        if (!empty($existPeriod)) {
            throw new CrowdFundingActivityException(['msg' => '已存在该期活动']);
        }
        $existPeriod = self::where(['activity_code' => $data['activity_code'], 'result_status' => [4], 'status' => [1]])->field('id')->findOrEmpty()->toArray();
        if (!empty($existPeriod)) {
            throw new CrowdFundingActivityException(['msg' => '该区存在正常认购中的期, 不允许重复添加']);
        }
        //新增类型 1为手动新增 2为自动新增 默认1
        $addType = $data['add_type'] ?? 1;
        $goodsArray = $data['goods'] ?? [];
        $topRoundNumber = $data['top_round_number'] ?? 0;
        $topPeriodNumber = $data['top_period_number'] ?? 0;
        $timeTopRoundNumber = $data['time_top_round_number'] ?? 0;
        $timeTopPeriodNumber = $data['time_top_period_number'] ?? 0;

        //$regenerateType 1为成功的新增, 则沿用本区本轮上期的;2为失败的新增, 则用本区上一轮第一期的
        $regenerateType = $data['regenerate_type'] ?? 0;
        $addGoods = [];
        $addTimeDuration = [];
        //重开加缓存,防止重复重开
        if ($regenerateType == 2) {
            $cacheKey = 'regeneratePeriod-' . $data['activity_code'] . '-' . $data['round_number'] . '-' . $data['period_number'] . '-' . $data['top_round_number'] . '-' . $data['top_period_number'];
            if (cache($cacheKey)) {
                throw new CrowdFundingActivityException(['msg' => '该区<' . $cacheKey . '>正在重开中~请耐心等待']);
            }
            cache($cacheKey, 'isRegenerating', 600);
        }
        //查看本期的详情, 查询本期自动重开的时间间隔,递增比例,销售额递增期数
        $activityInfo = CrowdfundingActivity::where(['activity_code' => $data['activity_code']])->findOrEmpty()->toArray();
        if (empty($activityInfo ?? [])) {
            if (!empty($cacheKey)) {
                cache($cacheKey, null);
            }
            throw new CrowdFundingActivityException(['msg' => '查无有效区详情, 无法继续执行']);
        }

        if ($addType == 2) {

            //需要增加的时间间隔,先查询本区是否有单独指定的重开间隔时间, 如果没有则找系统默认全局统一的重开间隔时间
//            $timeInterval = CrowdfundingSystemConfig::where(['id'=>1])->value('reopen_time_interval');
            $timeInterval = $activityInfo['reopen_time_interval'] ?? 0;
            if (intval($timeInterval) <= 0) {
                $timeInterval = CrowdfundingSystemConfig::where(['id' => 1])->value('reopen_time_interval');
            }
            //先查找指定一期的商品
            $topPeriodGoods = CrowdfundingActivityGoods::with(['sku' => function ($query) {
                $query->withoutField('id,create_time,update_time');
            }])->where(['activity_code' => $data['activity_code'], 'round_number' => $topRoundNumber, 'period_number' => $topPeriodNumber, 'status' => [1]])->withoutField('id,create_time,update_time')->select()->toArray();
            $goodsArray = $topPeriodGoods;
            if (empty($goodsArray)) {
                throw new CrowdFundingActivityException(['msg' => '自动生成期失败, 因为查无有效商品']);
            }
            foreach ($goodsArray as $key => $value) {
                $goodsArray[$key]['activity_code'] = $data['activity_code'];
                $goodsArray[$key]['round_number'] = $data['round_number'];
                $goodsArray[$key]['period_number'] = $data['period_number'];
                foreach ($value['sku'] as $skuKey => $skuValue) {
                    $goodsArray[$key]['sku'][$skuKey]['activity_code'] = $data['activity_code'];
                    $goodsArray[$key]['sku'][$skuKey]['round_number'] = $data['round_number'];
                    $goodsArray[$key]['sku'][$skuKey]['period_number'] = $data['period_number'];
                }
            }
            $goodsCount = 0;
            foreach ($goodsArray as $key => $value) {
                foreach ($value['sku'] as $skuKey => $skuValue) {
                    $newGoods[$goodsCount]['activity_code'] = $skuValue['activity_code'];
                    $newGoods[$goodsCount]['round_number'] = $skuValue['round_number'];
                    $newGoods[$goodsCount]['period_number'] = $skuValue['period_number'];
                    $newGoods[$goodsCount]['goods_sn'] = $skuValue['goods_sn'];
                    $newGoods[$goodsCount]['sku_sn'] = $skuValue['sku_sn'];
                    $newGoods[$goodsCount]['activity_price'] = $skuValue['activity_price'];
                    $newGoods[$goodsCount]['title'] = $skuValue['title'];
                    $newGoods[$goodsCount]['attrs'] = $skuValue['specs'];
                    $newGoods[$goodsCount]['sale_price'] = $skuValue['sale_price'];
                    $newGoods[$goodsCount]['gift_type'] = $skuValue['gift_type'] ?? -1;
                    $newGoods[$goodsCount]['gift_number'] = $skuValue['gift_number'] ?? 0;
//                    $newGoods[$goodsCount]['limit_type'] = $skuValue['limit_type'];
//                    $newGoods[$goodsCount]['start_time'] = !empty($skuValue['start_time']) ? timeToDateFormat($skuValue['start_time']) : null;
//                    $newGoods[$goodsCount]['end_time'] = !empty($skuValue['end_time']) ? timeToDateFormat($skuValue['end_time']) : null;

                    $goodsCount++;
                }
            }

            //查找对应时间段
            if($regenerateType == 2){
                //如果是失败重开, 期的约束时间要为上一轮最后一期失败的时间基础上加指定间隔时间段(48小时)
                $timeDuration = CrowdfundingPeriodSaleDuration::where(['activity_code' => $data['activity_code'], 'round_number' => $timeTopRoundNumber, 'period_number' => $timeTopPeriodNumber, 'status' => [1]])->select()->toArray();
            }else{
                $timeDuration = CrowdfundingPeriodSaleDuration::where(['activity_code' => $data['activity_code'], 'round_number' => $topRoundNumber, 'period_number' => $topPeriodNumber, 'status' => [1]])->select()->toArray();
            }

            if (!empty($timeDuration)) {
                $CodeBuilder = (new CodeBuilder());
                foreach ($timeDuration as $key => $value) {
                    unset($timeDuration[$key]['duration_code']);
                    $timeDuration[$key]['round_number'] = $data['round_number'];
                    $timeDuration[$key]['period_number'] = $data['period_number'];
                    $timeDuration[$key]['start_time'] = timeToDateFormat($value['start_time'] + $timeInterval);
                    $timeDuration[$key]['end_time'] = timeToDateFormat($value['end_time'] + $timeInterval);
                }
            }
            $addGoods['activity_code'] = $data['activity_code'];
            $addGoods['round_number'] = $data['round_number'];
            $addGoods['period_number'] = $data['period_number'];
            $addGoods['goods'] = $newGoods;
            $addTimeDuration = $timeDuration;
        }
        if ($addType == 2) {
            $newPeriodData = self::where(['activity_code' => $data['activity_code'], 'round_number' => $topRoundNumber, 'period_number' => $topPeriodNumber, 'status' => [1,2]])->withoutField('id,create_time,update_time,success_time,fail_time,fuse_time')->findOrEmpty()->toArray();
            $newPeriodData['round_number'] = $data['round_number'];
            $newPeriodData['period_number'] = $data['period_number'];
            //查找新增的涨幅比例
//            $riseScale = CrowdfundingActivity::where(['activity_code'=>$data['activity_code']])->findOrEmpty()->toArray();
            $riseScale = $activityInfo;

            if ($regenerateType == 1) {
                //查看本区设置的销售额递增期数,若大于1则默认取余该数=1的期才做涨幅, 其他期不做涨幅<即多期一涨>;若小于1,则默认本次新增直接做涨幅<即一期一涨>
                $risePeriodNumber = $activityInfo['rise_period_number'] ?? 1;
                if (intval($risePeriodNumber) > 1) {
                    if (intval($newPeriodData['period_number']) % intval($risePeriodNumber) != 1) {
                        $riseScale['rise_scale'] = 0;
                    }
                }
                $newPeriodData['sales_price'] = priceFormat($newPeriodData['sales_price'] * (1 + ($riseScale['rise_scale'] ?? 0.25)));
            } else {
                $newPeriodData['sales_price'] = priceFormat($newPeriodData['sales_price']);
            }
            $newPeriodData['last_sales_price'] = $newPeriodData['sales_price'];
            $newPeriodData['buy_status'] = 2;
            $newPeriodData['result_status'] = 4;
            //失败后的重开默认是下架状态
            $newPeriodData['status'] = $regenerateType == 1 ? 1 : 2;
            $addGoods['limit_type'] = $newPeriodData['limit_type'];
            if ($newPeriodData['limit_type'] == 1) {
                if($regenerateType == 2){
                    //如果是失败重开, 期的约束时间要为上一轮最后一期失败的时间基础上加指定间隔时间段(48小时)
                    $timeTopPeriodInfo = self::where(['activity_code' => $data['activity_code'], 'round_number' => $timeTopRoundNumber, 'period_number' => $timeTopPeriodNumber, 'status' => [1,2]])->field('start_time,end_time')->findOrEmpty()->toArray();
                    $newPeriodData['start_time'] = $timeTopPeriodInfo['start_time'] + $timeInterval;
                    $newPeriodData['end_time'] = $timeTopPeriodInfo['end_time'] + $timeInterval;
                }else{
                    $newPeriodData['start_time'] = $newPeriodData['start_time'] + $timeInterval;
                    $newPeriodData['end_time'] = $newPeriodData['end_time'] + $timeInterval;
                }
                $addGoods['start_time'] = $newPeriodData['start_time'];
                $addGoods['end_time'] = $newPeriodData['end_time'];
            }
        } else {
            if (!empty($data['start_time'] ?? null)) {
                $data['start_time'] = strtotime($data['start_time']);
            }
            if (!empty($data['end_time'] ?? null)) {
                $data['end_time'] = strtotime($data['end_time']);
            }
            $data['last_sales_price'] = $data['sales_price'];
            $newPeriodData = $data;
        }

        //新增数据库
        $DBRes = Db::transaction(function () use ($newPeriodData, $addGoods, $addTimeDuration) {
            $res = $this->create($newPeriodData);

            if (!empty($addGoods ?? [])) {
                //自动新增期需要一并新增商品
                (new CrowdfundingActivityGoods())->DBNewOrEdit($addGoods);
            }

            if (!empty($addTimeDuration ?? [])) {
                //自动新增期需要一并新增时间段
                (new CrowdfundingPeriodSaleDuration())->DBNewOrEdit(['time_duration'=>$addTimeDuration]);

            }
            return $res;
        });
        cache('HomeApiCrowdFundingPeriodList', null);
        cache('HomeApiCrowdFundingActivityList', null);

        //清除首页活动列表标签的缓存
        Cache::tag(['HomeApiCrowdFundingActivityList', ('HomeApiCrowdFundingPeriodList' . '-' . ($data['activity_code'] ?? ''))])->clear();
        return judge($DBRes);
    }

    /**
     * @title  编辑期
     * @param array $data
     * @return mixed
     */
    public function edit(array $data)
    {
        $cacheKey = 'CrowdFundingPeriodSuccess';
        $failCacheKey = 'CrowdFundingPeriodFail';

        $periodKey = '-' . $data['activity_code'] . '-' . $data['round_number'] . '-' . $data['period_number'];

        $cacheKey .= $periodKey;
        if (!empty(cache($cacheKey))) {
            throw new CrowdFundingActivityException(['msg' => '该期已成功, 正在进行发放奖金逻辑, 请勿操作修改']);
        }

        $failCacheKey .= $periodKey;
        if (!empty(cache($failCacheKey))) {
            throw new CrowdFundingActivityException(['msg' => '该期已失败, 正在检测过期, 请勿操作修改']);
        }

        if (!empty(cache($cacheKey))) {
            throw new CrowdFundingActivityException(['msg' => '该期已成功, 正在进行发放奖金逻辑, 请勿操作修改']);
        }

        $existPeriodInfo = self::where(['activity_code' => $data['activity_code'], 'round_number' => $data['round_number'], 'period_number' => $data['period_number'], 'status' => $this->getStatusByRequestModule()])->findOrEmpty()->toArray();

//        if (!empty($existPeriod)) {
//            throw new CrowdFundingActivityException(['msg' => '已存在该期活动']);
//        }
        if ($existPeriodInfo['result_status'] != 4) {
            throw new CrowdFundingActivityException(['msg' => '该期非认购中状态, 无法编辑']);
        }
        $existPeriod = self::where(['activity_code' => $data['activity_code'], 'result_status' => [4], 'status' => [1]])->field('id')->findOrEmpty()->toArray();
        if (!empty($existPeriod)) {
            throw new CrowdFundingActivityException(['msg' => '该区存在正常认购中的期, 请先下架后编辑']);
        }
        if ((string)$data['sales_price'] < $existPeriodInfo['sales_price']) {
            throw new CrowdFundingActivityException(['msg' => '不允许比之前设定的销售额低']);
        }
        $update['title'] = $data['title'];
        $update['icon'] = $data['icon'] ?? null;
        $update['background_image'] = $data['background_image'] ?? null;
        $update['poster'] = $data['poster'] ?? null;
        $update['desc'] = $data['desc'] ?? null;
        $update['sales_price'] = $data['sales_price'];
        $update['join_limit_number'] = $data['join_limit_number'];
        $update['join_limit_amount'] = $data['join_limit_amount'];
        $update['join_limit_amount_show'] = $data['join_limit_amount_show'] ?? $data['join_limit_amount'];
        $update['reward_scale'] = $data['reward_scale'];
        $update['fail_return_scale'] = $data['fail_return_scale'];
        $update['fail_reward_scale'] = $data['fail_reward_scale'];
        $update['limit_type'] = $data['limit_type'] ?? 1;
        if (!empty($data['advance_buy_scale'] ?? null)) {
            $update['advance_buy_scale'] = $data['advance_buy_scale'];
        }
        if (!empty($data['fuse_second_return_scale'] ?? null)) {
            $update['fuse_second_return_scale'] = $data['fuse_second_return_scale'];
        }
        if (!empty($data['fuse_second_rising_scale'] ?? null)) {
            $update['fuse_second_rising_scale'] = $data['fuse_second_rising_scale'];
        }
        if (!empty($data['fuse_second_once_return_scale'] ?? null)) {
            $update['fuse_second_once_return_scale'] = $data['fuse_second_once_return_scale'];
        }
        if (!empty($data['advance_buy_reward_send_time'] ?? 0)) {
            $update['advance_buy_reward_send_time'] = intval($data['advance_buy_reward_send_time']);
        }
        if ($update['limit_type'] == 1) {
            $update['start_time'] = strtotime($data['start_time']);
            $update['end_time'] = strtotime($data['end_time']);
            //同时修改商品
            CrowdfundingActivityGoods::update(['start_time' => $update['start_time'], 'end_time' => $update['end_time']], ['limit_type' => 1, 'activity_code' => $data['activity_code'], 'round_number' => $data['round_number'], 'period_number' => $data['period_number'],'status'=>[1,2]]);
        }
        //不允许编辑销售额
        unset($data['sales_price']);

        $info = $this->baseUpdate(['activity_code' => $data['activity_code'], 'round_number' => $data['round_number'], 'period_number' => $data['period_number'], 'status' => 2], $update);

        //清除首页活动列表标签的缓存
        cache('ApiCrowdFundingActivityList', null);
        cache('HomeApiCrowdFundingPeriodList', null);
        Cache::tag(['HomeApiCrowdFundingActivityList', ('HomeApiCrowdFundingPeriodList' . '-' . ($data['activity_code'] ?? ''))])->clear();
        return $info;
    }


    /**
     * @title  删除期
     * @param array $data
     * @param bool $noClearCache 是否清除缓存
     * @return mixed
     */
    public function del(string $data, bool $noClearCache = false)
    {
        $res = $this->cache('HomeApiCrowdFundingActivityList')->where(['activity_code' => $data['activity_code'], 'round_number' => $data['round_number'], 'period_number' => $data['period_number']])->save(['status' => -1]);
        //一并删除活动商品
        if (!empty($res)) {
            CrowdfundingActivityGoods::update(['status' => -1], ['activity_code' => $data['activity_code'], 'round_number' => $data['round_number'], 'period_number' => $data['period_number'], 'status' => [1, 2]]);
            CrowdfundingActivityGoodsSku::update(['status' => -1], ['activity_code' => $data['activity_code'], 'round_number' => $data['round_number'], 'period_number' => $data['period_number'], 'status' => [1, 2]]);
        }
        if (empty($noClearCache)) {
            if ($res) {
                //清除首页活动列表标签的缓存
                cache('ApiCrowdFundingActivityList', null);
                cache('HomeApiCrowdFundingPeriodList', null);
                Cache::tag(['HomeApiCrowdFundingActivityList', 'ApiCrowdFundingActivityList'])->clear();
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

        $info = $this->where(['activity_code' => $data['activity_code'], 'round_number' => $data['round_number'], 'period_number' => $data['period_number'], 'status' => [1, 2]])->findOrEmpty()->toArray();
        if ($info['result_status'] != 4) {
            throw new CrowdFundingActivityException(['msg' => '该期非认购中状态, 无法编辑']);
        }

        $res = $this->cache('HomeApiCrowdFundingActivityList')->where(['activity_code' => $data['activity_code'], 'round_number' => $data['round_number'], 'period_number' => $data['period_number']])->save($save);

        if (empty($data['noClearCache'] ?? null)) {
            if ($res) {
                //清除首页活动列表标签的缓存
                cache('ApiCrowdFundingActivityList', null);
                cache('HomeApiCrowdFundingPeriodList', null);
                Cache::tag(['HomeApiCrowdFundingActivityList', 'ApiCrowdFundingActivityList'])->clear();
            }
        }
        return $res;
    }

    /**
     * @title  C端首页期列表
     * @param array $sear
     * @return array
     * @throws \Exception
     */
    public function cHomeList(array $sear = []): array
    {
        $cacheKey = 'HomeApiCrowdFundingPeriodList'.'-'.md5(implode($sear));
        $cacheExpire = 60;
        $cacheTag = 'HomeApiCrowdFundingPeriodList' . '-' . ($sear['activity_code'] ?? '');

        if (empty($sear['clearCache'])) {
            $cacheList = cache($cacheKey);
            if (!empty($cacheList)) {
                return ['list' => $cacheList, 'pageTotal' => cache($cacheKey.'-count')];
            }
        }

        $map[] = ['status', '=', 1];
        $map[] = ['activity_code', '=', $sear['activity_code']];
        if (!empty($sear['round_number'] ?? null)) {
            $map[] = ['round_number', '=', $sear['round_number']];
        }
        if (!empty($sear['period_number'] ?? null)) {
            $map[] = ['period_number', '=', $sear['period_number']];
        }
        if (!empty($sear['result_status'] ?? null)) {
            $map[] = ['result_status', '=', intval($sear['result_status'])];
        }
        if (!empty($sear['fuse_period']) && $sear['fuse_period'] == 1) {
            $map[] = ['', 'exp', Db::raw('fail_round_number is not null and fail_period_number is not null')];
        }
        $page = intval($sear['page'] ?? 0) ?: null;
        if (!empty($page)) {
            $aTotal = $this->where($map)->count();
            $pageTotal = ceil($aTotal / $this->pageNumber);
            cache($cacheKey.'-count', $pageTotal, $cacheExpire, $cacheTag);
        }

        $periodList = $this->with(['activityName'])->field('activity_code,round_number,period_number,title,icon,background_image,poster,desc,type,limit_type,sales_price,last_sales_price,join_limit_number,join_limit_amount_show as join_limit_amount,sort,buy_status,result_status,status,create_time,success_time,fail_time,start_time,end_time,fuse_time')->where($map)->when($page, function ($query) use ($page) {
            $query->page($page, $this->pageNumber);
        })->order('create_time desc')->select()->each(function ($item) {
            if (!empty($item['start_time'] ?? null)) {
                $item['start_time'] = timeToDateFormat($item['start_time']);
            }
            if (!empty($item['end_time'] ?? null)) {
                $item['end_time'] = timeToDateFormat($item['end_time']);
            }
            if (!empty($item['fuse_time'] ?? null)) {
                $item['fuse_time'] = timeToDateFormat($item['fuse_time']);
            }
            if (!empty($item['success_time'] ?? null)) {
                $item['success_time'] = timeToDateFormat($item['success_time']);
            }
            if (!empty($item['fail_time'] ?? null)) {
                $item['fail_time'] = timeToDateFormat($item['fail_time']);
            } 
            $item['schedule'] = 0;
            $item['join_min_amount'] = "200.00";
        })->toArray();
        $aActivity = [];
        $findTimeNumber = 0;
        if (!empty($periodList)) {
            foreach ($periodList as $key => $value) {
                //图片展示缩放,OSS自带功能
                if (!empty($value['background_image'])) {
                    $periodList[$key]['background_image'] .= '?x-oss-process=image/format,webp';
                }
                if (!empty($value['poster'])) {
                    $periodList[$key]['poster'] .= '?x-oss-process=image/format,webp';
                }
                if (doubleval($value['last_sales_price']) <= 0) {
                    $periodList[$key]['schedule'] = 1;
                } elseif ((string)$value['sales_price'] == (string)$value['last_sales_price']) {
                    $periodList[$key]['schedule'] = 0;
                } else {
                    $periodList[$key]['schedule'] = priceFormat(($value['sales_price'] - $value['last_sales_price']) / $value['sales_price']);
                }
                if (doubleval($periodList[$key]['schedule']) > 1) {
                    $periodList[$key]['schedule'] = 1;
                }
                $periodList[$key]['schedule'] = priceFormat($periodList[$key]['schedule'] * 100);
                $periodList[$key]['duration'] = [];
                if ($value['buy_status'] == 2 && $value['result_status'] == 4) {
                    $needFindTime[$findTimeNumber]['activity_code'] = $value['activity_code'];
                    $needFindTime[$findTimeNumber]['round_number'] = $value['round_number'];
                    $needFindTime[$findTimeNumber]['period_number'] = $value['period_number'];
                }
            }

            if (!empty($needFindTime ?? [])) {
                $oWhere[] = ['status', '=', 1];
                $durationList = CrowdfundingPeriodSaleDuration::where(function ($query) use ($needFindTime) {
                    $needFindTime = array_values($needFindTime);
                    foreach ($needFindTime as $key => $value) {
                        ${'where' . ($key + 1)}[] = ['activity_code', '=', $value['activity_code']];
                        ${'where' . ($key + 1)}[] = ['round_number', '=', $value['round_number']];
                        ${'where' . ($key + 1)}[] = ['period_number', '=', $value['period_number']];
                    }
                    for ($i = 0; $i < count($needFindTime); $i++) {
                        $allWhereOr[] = ${'where' . ($i + 1)};
                    }
                    $query->whereOr($allWhereOr);
                })->where($oWhere)->withoutField('id,create_time,update_time,status')->select()->each(function ($item) {
                    if (!empty($item['start_time'])) {
                        $item['start_time'] = timeToDateFormat($item['start_time']);
                    }
                    if (!empty($item['end_time'])) {
                        $item['end_time'] = timeToDateFormat($item['end_time']);
                    }
                    $item['is_selected'] = 2;
                    $item['can_buy'] = false;
                })->toArray();
                if (!empty($durationList)) {
                    //判断是否在可以购买范围内
                    foreach ($durationList as $key => $value) {
                        if(time() >= strtotime($value['start_time']) && time() < strtotime($value['end_time'])){
                            $durationList[$key]['can_buy'] = true;
                        }
                    }
                    foreach ($periodList as $pKey => $pValue) {
                        foreach ($durationList as $dKey => $dValue) {
                            //默认展示今天的时间
                            if ($dValue['activity_code'] == $pValue['activity_code'] && $dValue['round_number'] == $pValue['round_number'] && $dValue['period_number'] == $pValue['period_number']) {
                                $periodList[$pKey]['duration'][] = $dValue;
                                $allTimeList[] = $dValue;
                            }
                        }

                    }
                }
            }
            //把开放时间段按小到大排序
            foreach ($periodList as $pKey => $pValue) {
                if (!empty($pValue['duration'])) {
                    array_multisort(array_column($pValue['duration'], 'start_time'), SORT_ASC, $pValue['duration']);
                    $periodList[$pKey]['duration'] = $pValue['duration'];
                }
            }
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
                foreach ($periodList as $key => $value) {
                    if (!empty($value['duration'])) {
                        foreach ($value['duration'] as $dKey => $dValue) {
                            if (!empty($sTimeList[strtotime(date('Y-m-d H', strtotime($dValue['start_time'])) . ':00:00')] ?? null)) {
                                $periodList[$key]['duration'][$dKey]['is_selected'] = $sTimeList[strtotime(date('Y-m-d H', strtotime($dValue['start_time'])) . ':00:00')];
                            }
                        }
                    }

                }
            }

            //加入缓存
            cache($cacheKey, $periodList, $cacheExpire, $cacheTag);
        }

        return ['list' => $periodList, 'pageTotal' => $pageTotal ?? 0];
    }

    /**
     * @title  操作期之前判断是否有期的缓存锁
     * @param array $data
     * @return mixed
     */
    public function checkOperateCache(array $data)
    {
        $PeriodSuccessKey = 'CrowdFundingPeriodSuccess' . '-';
        $PeriodFailKey = 'CrowdFundingPeriodSuccess' . '-';
        $crowdKey = $data['activity_code'] . '-' . $data['round_number'] . '-' . $data['period_number'];
        if (!empty(cache($PeriodSuccessKey . $crowdKey)) || !empty(cache($PeriodFailKey . $crowdKey))) {
            throw new OrderException(['msg' => '该期系统正在处理中, 请勿手工编辑, 强行编辑将导致未知错误!']);
        }
        return true;
    }

    public function activity()
    {
        return $this->hasOne('CrowdfundingActivity', 'activity_code', 'activity_code');
    }

    public function activityName()
    {
        return $this->hasOne('CrowdfundingActivity', 'activity_code', 'activity_code')->bind(['activity_title' => 'title']);
    }
}