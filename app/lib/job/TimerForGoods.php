<?php
// +----------------------------------------------------------------------
// |[ 文档说明: ]
// +----------------------------------------------------------------------
// | Copyright (c) 2018~2024 http://www.mlhcmk.com All rights reserved.
// +----------------------------------------------------------------------


namespace app\lib\job;


use app\lib\models\ActivityGoods;
use app\lib\models\ActivityGoodsSku;
use app\lib\models\GoodsSku;
use app\lib\models\GoodsSkuVdc;
use app\lib\models\GoodsSpu;
use app\lib\models\PtGoods;
use app\lib\models\PtGoodsSku;
use app\lib\subscribe\Timer;
use think\facade\Cache;
use think\facade\Db;
use think\facade\Log;
use think\queue\Job;

class TimerForGoods
{
    /**
     * @title  fire
     * @param Job $job
     * @param  $data
     * @return void
     * @throws \Exception
     */
    public function fire(Job $job, $data)
    {
        Log::close('file');
        if (!empty($data)) {
            switch ($data['type'] ?? 1) {
                case 1:
                    //检查需要定时上架的产品
                    $map[] = ['up_type', '=', 2];
                    $map[] = ['up_time', '<=', time()];
                    $map[] = ['down_time', '>', time()];
                    $map[] = ['status', '=', 2];
                    $upGoods = GoodsSpu::where($map)->column('goods_sn');
                    if (!empty($upGoods)) {
                        $log['msg'] = '查找到需要定时上架的产品,推入修改商品状态队列';
                        $log['data'] = $upGoods;
                        $log['time'] = date('Y-m-d H:i:s');
                        $res = $this->goodsStatus(['goods_sn' => $upGoods, 'type' => 1, 'status' => 1]);
                        $log['map'] = $map ?? [];
                        $log['delRes'] = $res;
                        (new Timer())->log($log);
                    }
                    break;
                case 2:
                    $downMap[] = ['up_type', '=', 2];
                    $downMap[] = ['', 'exp', Db::raw('down_time is not null')];
                    $downMap[] = ['down_time', '<=', time()];
                    $downMap[] = ['status', '=', 1];
                    $downGoods = GoodsSpu::where($downMap)->column('goods_sn');
                    if (!empty($downGoods)) {
                        $dLog['msg'] = '查找到需要定时下架的产品,推入修改商品状态队列';
                        $dLog['data'] = $downGoods;
                        $dLog['time'] = date('Y-m-d H:i:s');
                        $res = $this->goodsStatus(['goods_sn' => $downGoods ?? [], 'type' => 2, 'status' => 2]);
                        $log['map'] = $downMap ?? [];
                        $dLog['delRes'] = $res;
                        (new Timer())->log($dLog);
                    }
                    break;
            }
            //$res = $this->goodsStatus($data);
        }
        $job->delete();
    }

    /**
     * @title  改变商品状态
     * @param array $data
     * @return GoodsSpu|bool
     */
    public function goodsStatus(array $data)
    {
        if (empty($data)) {
            return false;
        }
        $res = false;
        //$goodsSn = array_column($data, 'goods_sn');
        $goodsSn = $data['goods_sn'];
        if (!empty($goodsSn)) {
            switch ($data['type'] ?? 1) {
                case 1:
                    $save['status'] = 1;
                    break;
                case 2:
                    $save['status'] = 2;
                    break;
            }

            $res = GoodsSpu::update($save, ['goods_sn' => $goodsSn, 'status' => [1, 2]]);
            GoodsSku::update($save, ['goods_sn' => $goodsSn, 'status' => [1, 2]]);
            GoodsSkuVdc::update($save, ['goods_sn' => $goodsSn, 'status' => [1, 2]]);
//            if($save['status'] = 2){
            $allMap['goods_sn'] = $data['goods_sn'];
            $allMap['status'] = $otherStatus ?? [1, 2];
            $saveStatus['status'] = $save['status'];
            //修改活动商品
            ActivityGoods::update($saveStatus, $allMap);
            ActivityGoodsSku::update($saveStatus, $allMap);
            cache('HomeApiActivityList', null);

            //修改拼团活动商品
            PtGoods::update($saveStatus, $allMap);
            PtGoodsSku::update($saveStatus, $allMap);
            cache('ApiHomePtList', null);

            cache('ApiHomeAllList', null);

            //清楚缓存标识
            Cache::tag(['apiHomeGoodsList', 'HomeApiActivityList'])->clear();
//            }
        }

        return $res;
    }

}