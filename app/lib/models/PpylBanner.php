<?php
// +----------------------------------------------------------------------
// |[ 文档说明: 拼拼有礼Banner模块Model]
// +----------------------------------------------------------------------
// | Copyright (c) 2018~2024 http://www.mlhcmk.com All rights reserved.
// +----------------------------------------------------------------------


namespace app\lib\models;


use app\BaseModel;
use Exception;
use think\facade\Db;

class PpylBanner extends BaseModel
{
    protected $field = ['activity_code','title', 'image', 'sort', 'type', 'content', 'status'];
    protected $validateFields = ['image','activity_code'];

    /**
     * @title  广告列表
     * @param array $sear
     * @return array
     * @throws Exception
     */
    public function list(array $sear = []): array
    {
        $cacheKey = false;
        $cacheExpire = 0;

        if (!empty($sear['keyword'])) {
            $map[] = ['', 'exp', Db::raw($this->getFuzzySearSql('title', $sear['keyword']))];
        }
        $map[] = ['status', 'in', $this->getStatusByRequestModule($sear['searType'] ?? 1)];
        if (!empty($sear['activity_code'])) {
            $map[] = ['activity_code', '=', $sear['activity_code']];
        }


        if ($this->module == 'api') {
            if (!empty($sear['cache'])) {
                $cacheKey = $sear['cache'];
                $cacheExpire = $sear['cache_expire'];
            }
            //C端访问必须传活动编码
            if (empty($sear['activity_code'])) {
                return ['list' => [], 'pageTotal' => 0];
            }
        }

        $page = intval($sear['page'] ?? 0) ?: null;
        if (!empty($page)) {
            $aTotal = $this->where($map)->count();
            $pageTotal = ceil($aTotal / $this->pageNumber);
        }
        $field = $this->getListFieldByModule();

        $list = $this->where($map)->field($field)->when($page, function ($query) use ($page) {
            $query->page($page, $this->pageNumber);
        })->when($cacheKey, function ($query) use ($cacheKey, $cacheExpire) {
            $query->cache($cacheKey, $cacheExpire);
        })->order('sort asc,create_time desc')->select()->each(function ($item) {
            if ($this->module == 'api') {
                if (!empty($item['image'])) {
//                    $item['image'] .= '?x-oss-process=image/resize,h_1170,m_lfit';
                    $item['image'] .= '?x-oss-process=image/format,webp';
                }
            }
            return $item;
        })->toArray();

        return ['list' => $list, 'pageTotal' => $pageTotal ?? 0];
    }

    /**
     * @title  C端广告列表
     * @param array $sear
     * @return array
     * @throws Exception
     */
    public function cList(array $sear = []): array
    {
        $map[] = ['status', 'in', $this->getStatusByRequestModule($sear['searType'] ?? 1)];
        $map[] = ['activity_code', '=', $sear['activity_code']];
        $list = $this->where($map)->field('id,activity_code,title,image,sort,type')->order('create_time desc')->select()->toArray();

        return ['list' => $list, 'pageTotal' => $pageTotal ?? 0];
    }

    /**
     * @title  广告详情
     * @param int $id 广告id
     * @return mixed
     */
    public function info(int $id)
    {
        return $this->where(['id' => $id, 'status' => $this->getStatusByRequestModule()])->findOrEmpty()->toArray();
    }

    /**
     * @title  新增广告
     * @param array $data
     * @return mixed
     */
    public function new(array $data)
    {
        cache('ApiPpylBanner', null);
        return $this->validate()->baseCreate($data, true);
    }

    /**
     * @title  编辑广告
     * @param array $data
     * @return PpylBanner
     */
    public function edit(array $data)
    {
        cache('ApiPpylBanner', null);
        return $this->validate()->baseUpdate([$this->getPk() => $data[$this->getPk()]], $data);
    }

    /**
     * @title  删除广告
     * @param int $id 广告id
     * @return PpylBanner
     */
    public function del(int $id)
    {
        cache('ApiPpylBanner', null);
        return $this->baseDelete([$this->getPk() => $id]);
    }

    /**
     * @title  上下架
     * @param array $data
     * @return PpylBanner|bool
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
        cache('ApiPpylBanner', null);
        return $this->baseUpdate([$this->getPk() => $data[$this->getPk()]], $save);
    }

    private function getListFieldByModule()
    {
        switch ($this->module) {
            case 'admin':
            case 'manager':
                $field = 'id,activity_code,title,image,sort,type,status,sort,create_time';
                break;
            case 'api':
                $field = 'id,activity_code,title,image,sort,type,content';
                break;
            default:
                $field = '*';
        }
        return $field;
    }

}