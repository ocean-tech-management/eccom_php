<?php
// +----------------------------------------------------------------------
// |[ 文档说明: 课程每日推荐Model]
// +----------------------------------------------------------------------
// | Copyright (c) 2018~2024 http://www.mlhcmk.com All rights reserved.
// +----------------------------------------------------------------------


namespace app\lib\models;


use app\BaseModel;
use think\facade\Db;

class SubjectRecommend extends BaseModel
{
    protected $field = ['subject_id', 'reason', 'status'];
    protected $validateFields = ['subject_id', 'status' => 'number'];

    /**
     * @title  课程推荐列表
     * @param array $sear
     * @return array
     * @throws \Exception
     */
    public function list(array $sear = []): array
    {
        $cacheKey = false;
        $cacheExpire = 0;
        if (!empty($sear['keyword'])) {
            $map[] = ['', 'exp', Db::raw($this->getFuzzySearSql('b.name', $sear['keyword']))];
        }
        if (!empty($sear['searTime'])) {
            $time = date('Y-m-d', strtotime($sear['searTime']));
            $map[] = ['a.day', '=', $time];
        }

        $map[] = ['a.status', 'in', $this->getStatusByRequestModule($sear['searType'] ?? 1)];
        if ($this->module == 'api') {
            if (!empty($sear['cache'])) {
                $cacheKey = $sear['cache'];
                $cacheExpire = $sear['cache_expire'];
            }
        }
        $page = intval($sear['page'] ?? 0) ?: null;
        if (!empty($page)) {
            $aTotal = Db::name('subject_recommend')->alias('a')
                ->join('sp_subject b', 'a.subject_id = b.id', 'inner')
                ->where($map)
                ->when($cacheKey, function ($query) use ($cacheKey, $cacheExpire) {
                    $query->cache($cacheKey . 'Num', $cacheExpire);
                })->count();
            $pageTotal = ceil($aTotal / $this->pageNumber);
        }

        $list = Db::name('subject_recommend')->alias('a')
            ->join('sp_subject b', 'a.subject_id = b.id', 'inner')
            ->where($map)
            ->field('b.id,b.name,b.cover_path,b.desc,b.price,a.reason,a.day')
            ->when($page, function ($query) use ($page) {
                $query->page($page, $this->pageNumber);
            })
            ->when($cacheKey, function ($query) use ($cacheKey, $cacheExpire) {
                $query->cache($cacheKey, $cacheExpire);
            })->order('a.day desc')->select()->toArray();

        return ['list' => $list, 'pageTotal' => $pageTotal ?? 0];
    }

    /**
     * @title  编辑每日推荐课程
     * @param array $data
     * @return SubjectRecommend
     */
    public function editOrNew(array $data)
    {
        $time = date('Y-m-d', time());
        if (!empty($data['time'])) {
            $time = date('Y-m-d', strtotime($data['time']));
        }
        $map[] = ['day', '=', $time];
        $map[] = ['status', '=', 1];
        $dayExist = $this->where($map)->field('id')->findOrEmpty()->toArray();
        $res = $this->updateOrCreate(['id' => $dayExist['id'] ?? null], $data);
        return $res;

    }
}