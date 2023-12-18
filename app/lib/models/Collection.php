<?php
// +----------------------------------------------------------------------
// |[ 文档说明: 收藏模块Model]
// +----------------------------------------------------------------------
// | Copyright (c) 2018~2024 http://www.mlhcmk.com All rights reserved.
// +----------------------------------------------------------------------


namespace app\lib\models;


use app\BaseModel;
use think\facade\Db;

class Collection extends BaseModel
{
    protected $field = ['uid', 'type', 'subject_id', 'status'];
    protected $validateFields = ['uid', 'type' => 'in:-1,1', 'subject_id'];

    /**
     * @title  收藏列表
     * @param array $sear
     * @return array
     * @throws \Exception
     */
    public function list(array $sear = []): array
    {
        if (!empty($sear['keyword'])) {
            $map[] = ['', 'exp', Db::raw($this->getFuzzySearSql('b.name', $sear['keyword']))];
        }
        $map[] = ['a.type', '=', 1];
        $map[] = ['b.status', 'in', [1, 2]];
        $page = intval($sear['page'] ?? 0) ?: null;
        if ($this->module == 'api') {
            $map[] = ['a.uid', '=', $sear['uid']];
        }

        if (!empty($page)) {
            $aTotal = Db::name('collection')->alias('a')
                ->join('sp_subject b', 'a.subject_id = b.id', 'inner')
                ->join('sp_user c', 'a.uid = c.uid', 'left')
                ->where($map)
                ->group('a.id')->count();
            $pageTotal = ceil($aTotal / $this->pageNumber);
        }

        $list = Db::name('collection')->alias('a')
            ->join('sp_subject b', 'a.subject_id = b.id', 'inner')
            ->join('sp_user c', 'a.uid = c.uid', 'left')
            ->field('a.uid,a.subject_id,a.type,b.name as subject_name,b.cover_path,b.desc,a.create_time')
            ->where($map)
            ->when($page, function ($query) use ($page) {
                $query->page($page, $this->pageNumber);
            })
            ->group('a.id')
            ->order('a.create_time desc')->select()->each(function ($item, $key) {
                if (!empty($item['create_time'])) $item['create_time'] = date('Y-m-d H:i:s', $item['create_time']);
                return $item;
            })->toArray();

        return ['list' => $list, 'pageTotal' => $pageTotal ?? 0];
    }

    /**
     * @title  操作(收藏或取消收藏)
     * @param array $data
     * @return Collection
     */
    public function newOrEdit(array $data)
    {
        $save = $data;
        if ($data['type'] == -1) {
            $save['type'] = 1;
        } elseif ($data['type'] == 1) {
            $save['type'] = -1;
        }
        return $this->updateOrCreate(['uid' => $data['uid'], 'subject_id' => $data['subject_id']], $save);
    }

    /**
     * @title  收藏详情
     * @param array $data
     * @return mixed
     */
    public function info(array $data)
    {
        return $this->where(['uid' => $data['uid'], 'subject_id' => $data, 'type' => 1])->count();
    }
}