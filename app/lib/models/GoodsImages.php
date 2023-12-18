<?php
// +----------------------------------------------------------------------
// |[ 文档说明: ]
// +----------------------------------------------------------------------
// | Copyright (c) 2018~2024 http://www.mlhcmk.com All rights reserved.
// +----------------------------------------------------------------------


namespace app\lib\models;


use app\BaseModel;
use think\facade\Cache;
use think\facade\Db;

class GoodsImages extends BaseModel
{
    protected $field = ['goods_sn', 'sku_sn', 'type', 'image_url', 'image_url', 'sort', 'status' => 'number'];
    protected $validateFields = ['goods_sn', 'image_url'];

    /**
     * @title  奖励列表
     * @param array $sear 搜索条件
     * @return array
     * @throws \Exception
     */
    public function list(array $sear)
    {
        if (!empty($sear['keyword'])) {
            $map[] = ['', 'exp', Db::raw($this->getFuzzySearSql('goods_sn', $sear['keyword']))];
        }
        if (!empty($sear['goods_sn'])) {
            $map[] = ['goods_sn', '=', $sear['goods_sn']];
        }
        if (!empty($sear['sku_sn'])) {
            $map[] = ['sku_sn', '=', $sear['sku_sn']];
        }
        $imageDomain = config('system.imgDomain');
        $map[] = ['status', 'in', $this->getStatusByRequestModule($sear['searType'] ?? 1)];
        $page = intval($sear['page'] ?? 0) ?: null;
        if (!empty($page)) {
            $aTotal = $this->where($map)->count();
            $pageTotal = ceil($aTotal / $this->pageNumber);
        }

        $list = $this->where($map)->field('id,name,user_level,train_level,train_number,freed_scale,freed_cycle,level,recommend_number,team_number,reward_scale,reward_other,status')->when($page, function ($query) use ($page) {
            $query->page($page, $this->pageNumber);
        })->order('level asc,create_time desc')->select()->each(function ($item) use ($imageDomain) {
            if (empty($item['image_url'])) {
                $item['image_url'] = substr_replace($item['image_url'], $imageDomain, strpos($item['image_url'], '/'), strlen('/'));
            }
            return $item;
        })->toArray();

        return ['list' => $list, 'pageTotal' => $pageTotal ?? 0];
    }

    /**
     * @title  新增或编辑
     * @param array $data
     * @return mixed
     */
    public function newOrEdit(array $data)
    {
        $res = Db::transaction(function () use ($data) {
            $imageDomain = config('system.imgDomain');
            foreach ($data as $key => $value) {
                $save['goods_sn'] = $value['goods_sn'];
                $save['type'] = $value['type'] ?? 1;
                $save['sku_sn'] = $value['sku_sn'] ?? null;
                $save['image_url'] = str_replace($imageDomain, '/', $value['image_url']);
                $save['sort'] = intval($value['sort']);
                $dbRes = $this->validate(false)->updateOrCreate([$this->getPk() => $value[$this->getPk()], 'status' => [1, 2]], $save);
            }
            return $dbRes;
        });
        return $res;
    }

    /**
     * @title  删除
     * @param string $id 图片id
     * @return GoodsImages
     */
    public function del(string $id)
    {
        $info = $this->where([$this->getPk() => $id])->findOrEmpty()->toArray();
        if (!empty($info['goods_sn'])) {
            $goodsSn = $info['goods_sn'];
            Cache::tag([config('cache.systemCacheKey.apiGoodsInfo.key') . $goodsSn])->clear();
            Cache::tag(['apiWarmUpActivityInfo' . $goodsSn])->clear();
            Cache::tag(['apiWarmUpPtActivityInfo' . $goodsSn])->clear();
        }
        return $this->baseDelete([$this->getPk() => $id]);
    }
}