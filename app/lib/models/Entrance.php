<?php
// +----------------------------------------------------------------------
// |[ 文档说明: C端首页顶部icon入口模块Model]
// +----------------------------------------------------------------------
// | Copyright (c) 2018~2024 http://www.mlhcmk.com All rights reserved.
// +----------------------------------------------------------------------


namespace app\lib\models;


use app\BaseModel;
use think\facade\Cache;
use think\facade\Db;

class Entrance extends BaseModel
{
    /**
     * @title  后台icon列表
     * @param array $sear
     * @return array
     * @throws \Exception
     */
    pubLic function list(array $sear): array
    {
        if (!empty($sear['keyword'])) {
            $map[] = ['', 'exp', Db::raw($this->getFuzzySearSql('name', $sear['keyword']))];
        }

        $map[] = ['status', 'in', $this->getStatusByRequestModule($sear['searType'] ?? 1)];

        $page = intval($sear['page'] ?? 0) ?: null;
        if (!empty($page)) {
            $aTotal = $this->where($map)->count();
            $pageTotal = ceil($aTotal / $this->pageNumber);
        }

        $list = $this->where($map)->when($page, function ($query) use ($page) {
            $query->page($page, $this->pageNumber);
        })->order('sort asc,create_time desc')->select()->toArray();

        return ['list' => $list, 'pageTotal' => $pageTotal ?? 0];
    }

    /**
     * @title  C端icon列表
     * @param array $sear
     * @return array
     * @throws \Exception
     */
    pubLic function cList(array $sear): array
    {
        $cacheKey = false;
        $cacheExpire = 0;

        $map[] = ['status', '=', 1];

        if ($this->module == 'api') {
            if (!empty($sear['cache'])) {
                $cacheKey = $sear['cache'];
                $cacheExpire = $sear['cache_expire'];
            }
        }
        $page = intval($sear['page'] ?? 0) ?: null;
        if (!empty($page)) {
            $aTotal = $this->where($map)->count();
            $pageTotal = ceil($aTotal / $this->pageNumber);
        }

        $list = $this->where($map)->when($page, function ($query) use ($page) {
            $query->page($page, $this->pageNumber);
        })->when($cacheKey, function ($query) use ($cacheKey, $cacheExpire) {
            $query->cache($cacheKey, $cacheExpire);
        })->withoutField('update_time,status')->order('sort asc,create_time desc')->select()->each(function ($item) {
            if (!empty($item['icon'])) {
                $item['icon'] .= '?x-oss-process=image/format,webp';
            }
            if (!empty($item['background'])) {
                $item['background'] .= '?x-oss-process=image/format,webp';
            }
            return $item;
        })->toArray();

        return ['list' => $list, 'pageTotal' => $pageTotal ?? 0];
    }

    /**
     * @title  icon详情
     * @param array $data
     * @return array
     */
    public function info(array $data)
    {
        $info = $this->where([$this->getPk() => $data[$this->getPk()], 'status' => $this->getStatusByRequestModule(1)])->findOrEmpty()->toArray();
        return $info;
    }

    /**
     * @title  编辑
     * @param array $data
     * @return mixed
     */
    public function DBEdit(array $data)
    {
        $info = $this->baseUpdate([$this->getPk() => $data[$this->getPk()]], $data);
        //清除首页顶部icon列表标签的缓存
        cache('ApiHomeIconList', null);
        return $info;
    }

    /**
     * @title  新增
     * @param array $data
     * @return bool
     */
    public function DBNew(array $data)
    {
        $res = $this->baseCreate($data);
        //清除首页顶部icon列表标签的缓存
        cache('ApiHomeIconList', null);
        return judge($res);
    }


    /**
     * @title  删除icon入口
     * @param int $id id
     * @param bool $noClearCache 是否清除缓存
     * @return mixed
     */
    public function DBDel(int $id, bool $noClearCache = false)
    {
        $res = $this->where([$this->getPk() => $id])->save(['status' => -1]);

        if (empty($noClearCache)) {
            if ($res) {
                //清除首页顶部icon列表标签的缓存
                cache('ApiHomeIconList', null);
            }
        }

        return $res;
    }

    /**
     * @title  上下架icon入口
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
        $res = $this->where([$this->getPk() => $data[$this->getPk()]])->save($save);

        if (empty($data['noClearCache'] ?? null)) {
            if ($res) {
                //清除首页顶部icon列表标签的缓存
                cache('ApiHomeIconList', null);
            }
        }

        return $res;
    }

}