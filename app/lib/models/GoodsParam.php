<?php
// +----------------------------------------------------------------------
// |[ 文档说明: 商品参数模块Model]
// +----------------------------------------------------------------------
// | Copyright (c) 2018~2024 http://www.mlhcmk.com All rights reserved.
// +----------------------------------------------------------------------


namespace app\lib\models;


use app\BaseModel;
use app\lib\exceptions\ServiceException;
use app\lib\services\CodeBuilder;
use Exception;
use think\facade\Db;

class GoodsParam extends BaseModel
{
    protected $validateFields = ['param_code', 'content'];

    /**
     * @title  商品参数模版列表
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
        $field = $this->getListFieldByModule();

        $list = $this->where($map)->field($field)->when($page, function ($query) use ($page) {
            $query->page($page, $this->pageNumber);
        })->when($cacheKey, function ($query) use ($cacheKey, $cacheExpire) {
            $query->cache($cacheKey, $cacheExpire);
        })->order('sort asc,create_time desc')->select()->toArray();

        return ['list' => $list, 'pageTotal' => $pageTotal ?? 0];
    }

    /**
     * @title  商品参数模版详情
     * @param string $paramCode 商品参数模版编码
     * @return mixed
     */
    public function info(string $paramCode)
    {
        return $this->where(['param_code' => $paramCode, 'status' => $this->getStatusByRequestModule()])->findOrEmpty()->toArray();
    }

    /**
     * @title  新增商品参数模版
     * @param array $data
     * @return mixed
     */
    public function new(array $data)
    {
        $data['param_code'] = (new CodeBuilder())->buildParamCode();
        $data['title'] = trim($data['title']);
        $data['content'] = json_encode($data['content'], 256);
        if (!empty($this->where(['title' => trim($data['title']), 'status' => [1, 2]])->count())) {
            throw new ServiceException(['msg' => '该模版名称已存在']);
        }
        return $this->validate()->baseCreate($data, true);
    }

    /**
     * @title  编辑商品参数
     * @param array $data
     * @return GoodsParam
     */
    public function edit(array $data)
    {
        $data['title'] = trim($data['title']);
        $data['content'] = json_encode($data['content'], 256);
        $map[] = ['param_code', '<>', $data['param_code']];
        $map[] = ['status', 'in', [1, 2]];
        $map[] = ['title', '=', trim($data['title'])];
        if (!empty($this->where($map)->count())) {
            throw new ServiceException(['msg' => '该模版名称已存在']);
        }
        return $this->validate()->baseUpdate(['param_code' => $data['param_code']], $data);
    }

    /**
     * @title  删除商品参数
     * @param string $paramCode 商品参数模版编码
     * @return GoodsParam
     */
    public function del(string $paramCode)
    {
        return $this->baseDelete(['param_code' => $paramCode]);
    }

    /**
     * @title  上下架
     * @param array $data
     * @return GoodsParam|bool
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
        return $this->baseUpdate(['param_code' => $data['param_code']], $save);
    }

    private function getListFieldByModule()
    {
        switch ($this->module) {
            case 'admin':
            case 'manager':
                $field = 'param_code,title,content,sort,remark,status,create_time';
                break;
            case 'api':
                $field = 'id,title,image,sort,type,content';
                break;
            default:
                $field = '*';
        }
        return $field;
    }

}