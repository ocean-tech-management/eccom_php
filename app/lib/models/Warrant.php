<?php
// +----------------------------------------------------------------------
// |[ 文档说明: 授权书模块Model]
// +----------------------------------------------------------------------
// | Copyright (c) 2018~2024 http://www.mlhcmk.com All rights reserved.
// +----------------------------------------------------------------------


namespace app\lib\models;


use app\BaseModel;
use Exception;
use think\facade\Db;

class Warrant extends BaseModel
{
    protected $field = ['name', 'content', 'sort', 'type', 'content', 'status'];
    protected $validateFields = ['content'];

    /**
     * @title  授权书模版列表
     * @param array $sear
     * @return array
     * @throws Exception
     */
    public function list(array $sear = []): array
    {
        $cacheKey = false;
        $cacheExpire = 0;

        if (!empty($sear['keyword'])) {
            $map[] = ['', 'exp', Db::raw($this->getFuzzySearSql('name', $sear['keyword']))];
        }
        $map[] = ['status', 'in', $this->getStatusByRequestModule($sear['searType'] ?? 1)];
        if ($this->module == 'api') {
            if (!empty($sear['cache'])) {
                $cacheKey = $sear['cache'];
                $cacheExpire = $sear['cache_expire'];
            }
        }

        $page = intval($sear['page'] ?? 0) ?: null;
        if (!empty($page)) {
            $aTotal = $this->where($map)->when($cacheKey, function ($query) use ($cacheKey, $cacheExpire) {
                $query->cache($cacheKey, $cacheExpire);
            })->count();
            $pageTotal = ceil($aTotal / $this->pageNumber);
        }
        $field = $this->getListFieldByModule();

        $list = $this->where($map)->field($field)->when($page, function ($query) use ($page) {
            $query->page($page, $this->pageNumber);
        })->when($cacheKey, function ($query) use ($cacheKey, $cacheExpire) {
            $query->cache($cacheKey, $cacheExpire);
        })->order('create_time desc')->select()->toArray();

        return ['list' => $list, 'pageTotal' => $pageTotal ?? 0];
    }

    /**
     * @title  C端授权书列表
     * @param array $sear
     * @return array
     * @throws Exception
     */
    public function cList(array $sear = []): array
    {
        $map[] = ['status', 'in', $this->getStatusByRequestModule($sear['searType'] ?? 1)];
        $list = $this->where($map)->field('id,name,content,type')->order('create_time desc')->select()->toArray();
        return ['list' => $list, 'pageTotal' => $pageTotal ?? 0];
    }

    /**
     * @title  授权书模板详情
     * @param int $id 广告id
     * @return mixed
     */
    public function info(int $id)
    {
        return $this->where(['id' => $id, 'status' => $this->getStatusByRequestModule()])->findOrEmpty()->toArray();
    }

    /**
     * @title  新增授权书模板
     * @param array $data
     * @return mixed
     */
    public function new(array $data)
    {
        return $this->validate()->baseCreate($data, true);
    }

    /**
     * @title  编辑授权书模板
     * @param array $data
     * @return mixed
     */
    public function edit(array $data)
    {
        return $this->validate()->baseUpdate([$this->getPk() => $data[$this->getPk()]], $data);
    }

    /**
     * @title  删除授权书模板
     * @param int $id 授权书模板id
     * @return mixed
     */
    public function del(int $id)
    {
        return $this->baseDelete([$this->getPk() => $id]);
    }

    /**
     * @title  上下架
     * @param array $data
     * @return mixed|bool
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
        return $this->baseUpdate([$this->getPk() => $data[$this->getPk()]], $save);
    }

    private function getListFieldByModule()
    {
        switch ($this->module) {
            case 'admin':
            case 'manager':
                $field = 'id,name,content,type,status,create_time';
                break;
            case 'api':
                $field = 'id,name,content,type';
                break;
            default:
                $field = '*';
        }
        return $field;
    }

}