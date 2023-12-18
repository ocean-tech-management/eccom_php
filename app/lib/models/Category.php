<?php
// +----------------------------------------------------------------------
// |[ 文档说明: 分类模块Model]
// +----------------------------------------------------------------------
// | Copyright (c) 2018~2024 http://www.mlhcmk.com All rights reserved.
// +----------------------------------------------------------------------


namespace app\lib\models;


use app\BaseModel;
use app\lib\exceptions\ServiceException;
use app\lib\services\CodeBuilder;
use Exception;
use think\facade\Db;
use app\lib\validates\Category as CategoryValidate;

class Category extends BaseModel
{
    protected $field = ['code', 'name', 'p_code', 'icon', 'status', 'type', 'desc', 'sort'];
    protected $validateFields = ['code', 'name', 'status' => 'number'];

    /**
     * @title  分类列表
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
        if (!empty($sear['p_code'])) {
            $map[] = ['p_code', '=', $sear['p_code']];
        }
        $sType = $sear['sType'] ?? 1; //归属平台
        $map[] = ['type', '=', $sType];
        $type = $sear['type'] ?? 1;  //列表内容类型
        $map[] = ['status', 'in', $this->getStatusByRequestModule($sear['searType'] ?? 1)];
        if ($this->module == 'api') {
            if (!empty($sear['cache'])) {
                $cacheKey = $sear['cache'];
                $cacheExpire = $sear['cache_expire'];
            }
        }

        //分类等级筛选
        switch ($type) {
            case 1:
                if (!empty($sear['p_code'])) {
                    $map[] = ['p_code', '=', $sear['p_code']];
                }
                break;
            case 2:
                //只查顶级分类
                $map[] = ['', 'exp', Db::raw('p_code is null')];
                break;
            case 3:
                //只查子级分类
                $map[] = ['', 'exp', Db::raw('p_code is not null')];
                if (!empty($sear['p_code'])) {
                    $map[] = ['p_code', '=', $sear['p_code']];
                }
                break;
        }

        $field = $this->getListFieldByModule();

        $page = intval($sear['page'] ?? 0) ?: null;
        if (!empty($page)) {
            $aTotal = $this->where($map)->when($cacheKey, function ($query) use ($cacheKey, $cacheExpire) {
                $query->cache($cacheKey . 'Num', $cacheExpire);
            })->count();
            $pageTotal = ceil($aTotal / $this->pageNumber);
        }

        $list = $this->with(['parent', 'child' => function ($query) {
            $query->where(['status' => $this->getStatusByRequestModule($sear['searType'] ?? 1)])->order('sort asc')->field($this->getListFieldByModule() . ',p_code');
        }])->where($map)->field($field)->when($page, function ($query) use ($page) {
            $query->page($page, $this->pageNumber);
        })->when($cacheKey, function ($query) use ($cacheKey, $cacheExpire) {
            $query->cache($cacheKey, $cacheExpire);
        })->order('sort asc,create_time desc')->select()->each(function ($item) {
            if ($this->module == 'api') {
                //图片展示缩放,OSS自带功能
                if (!empty($item['icon'])) {
                    $item['icon'] .= '?x-oss-process=image/format,webp';
                }
                if (!empty($item['child'])) {
                    foreach ($item['child'] as $key => $value) {
                        if (!empty($value['icon'])) {
                            $item['child'][$key]['icon'] .= '?x-oss-process=image/format,webp';
//                            $item['child'][$key]['icon'] .= '?x-oss-process=image/resize,h_75,m_lfit';
                        }

                    }
                }
            }

            return $item;
        })->toArray();


        return ['list' => $list, 'pageTotal' => $pageTotal ?? 0];
    }

    /**
     * @title  C端分类列表
     * @param array $sear
     * @return array
     * @throws \Exception
     */
    public function cList(array $sear = []): array
    {
        //隐藏顶级分类
        $map[] = ['code', '<>', "0001"];
        $map[] = ['p_code', '=', "0001"];
        $map[] = ['status', 'in', $this->getStatusByRequestModule($sear['searType'] ?? 2)];

        $list = $this->where($map)->field('code,name,icon')->order('create_time desc')->select()->toArray();

        return ['list' => $list, 'pageTotal' => $pageTotal ?? 0];
    }


    /**
     * @title  新增分类
     * @param array $data
     * @return mixed
     */
    public function new(array $data)
    {
        if (empty($data['p_code'])) {
            unset($data['p_code']);
        }
        $data['name'] = trim($data['name']);
        $data['code'] = (new CodeBuilder())->buildCategoryCode($this);
        (new CategoryValidate())->goCheck($data, 'create');
        return $this->validate()->baseCreate($data, true);
    }

    /**
     * @title  分类详情
     * @param string $code
     * @return mixed
     */
    public function info(string $code)
    {
        return $this->with(['pcode', 'child'])->where(['code' => $code, 'status' => [1, 2]])->field('code,name,p_code,icon,status,create_time,icon')->findOrEmpty()->toArray();
    }

    /**
     * @title  更新分类
     * @param array $data
     * @return Category
     */
    public function edit(array $data)
    {
        $data['name'] = trim($data['name']);
        (new CategoryValidate())->goCheck($data, 'edit');
        if (empty($data['p_code'])) {
            unset($data['p_code']);
        }
        return $this->validate()->baseUpdate(['code' => $data['code']], $data);
    }

    /**
     * @title  删除分类
     * @param string $code 分类编码
     * @return Category
     */
    public function del(string $code)
    {
        $categoryInfo = self::with(['child'])->where(['code' => $code, 'status' => [1, 2]])->findOrEmpty()->toArray();
        $deleteRes = $this->baseDelete(['code' => $code]);
        $deleteChildRes = $this->baseDelete(['p_code' => $code]);

        //删除分类下连带的品牌
        $branchDeleteCode = [$code];
        if (!empty($categoryInfo) && !empty($categoryInfo['child'])) {
            $childCode = array_unique(array_filter(array_column($categoryInfo['child'], 'code')));
            $branchDeleteCode = array_merge_recursive($branchDeleteCode, $childCode);
        }

        if (!empty($branchDeleteCode)) {
            $branchDeleteCode = array_unique($branchDeleteCode);
            $deleteBranch = Brand::update(['status' => -1], ['category_code' => $branchDeleteCode, 'status' => [1, 2]]);
        }

        return $deleteRes;
    }

    /**
     * @title  上下架分类
     * @param array $data
     * @return Category|bool
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
        return $this->baseUpdate(['code' => $data['code']], $save);
    }

    /**
     * @title  更新分类排序
     * @param array $data
     * @return bool
     */
    public function updateSort(array $data)
    {
        $categoryNumber = count($data);
        if (empty($categoryNumber)) {
            throw new ServiceException(['msg' => '请选择分类']);
        }
        $DBRes = self::update(['sort' => $data['sort'] ?? 999], ['code' => $data['code']]);
//        $DBRes = Db::transaction(function () use ($data) {
//            foreach ($data as $key => $value) {
//                $res = $this->where(['code' => $value['code']])->save(['sort' => $value['sort'] ?? 1]);
//            }
//            return $res;
//        });

        return judge($DBRes);
    }


    public function parent()
    {
        return $this->hasOne(get_class($this), 'code', 'p_code')->field('code,name')->where(['status' => [1, 2]]);
    }

    public function child()
    {
        return $this->hasMany(get_class($this), 'p_code', 'code');
    }

    private function getListFieldByModule()
    {
        switch ($this->module) {
            case 'admin':
                $field = 'code,name,p_code,icon,status,create_time,desc,sort';
                break;
            case 'api':
                $field = 'code,name,icon,desc,sort';
                break;
            default:
                $field = '*';
        }
        return $field;
    }
}