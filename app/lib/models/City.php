<?php
// +----------------------------------------------------------------------
// |[ 文档说明: 城市表模块Model]
// +----------------------------------------------------------------------
// | Copyright (c) 2018~2024 http://www.mlhcmk.com All rights reserved.
// +----------------------------------------------------------------------


namespace app\lib\models;


use app\BaseModel;
use think\facade\Db;

class City extends BaseModel
{

    /**
     * @title  城市列表
     * @param array $sear
     * @return array
     * @throws \Exception
     */
    public function list(array $sear = []): array
    {
        $cacheKey = null;
        $cacheExpire = 0;


        if (!empty($sear['keyword'])) {
            $map[] = ['', 'exp', Db::raw($this->getFuzzySearSql('name', $sear['keyword']))];
        }
        $map[] = ['status', 'in', $this->getStatusByRequestModule($sear['searType'] ?? 1)];
        if (!empty($sear['code'])) {
            if (is_array($sear['code'])) {
                $map[] = ['code', 'in', $sear['code']];
            } else {
                $map[] = ['code', 'in', explode(',', $sear['code'])];
            }
        }
        $format = $sear['formatType'] ?? 2;
        if (!empty($sear['type'])) {
            $map[] = ['type', 'in', $sear['type']];
        }


        $page = intval($sear['page'] ?? 0) ?: null;
        if (!empty($page)) {
            $aTotal = $this->where($map)->count();
            $pageTotal = ceil($aTotal / $this->pageNumber);
        }
        if (!empty($sear['needCache'])) {
            $cacheKey = 'shipCityList' . http_build_query($sear) . $page;
            $cacheList = cache($cacheKey);
            if (!empty($cacheList)) {
                return $cacheList;
            }
        }

        $list = $this->where($map)->withoutField('id,create_time,update_time,status')->withCount(['child'])->when($page, function ($query) use ($page) {
            $query->page($page, $this->pageNumber);
        })->order('code asc,sort asc')->select()->toArray();
        if (!empty($list)) {
            switch ($format) {
                case 1:
                    $child = [];
                    $parent = [];
                    //如果包含了全部市(区)级则默认只显示省级(直辖市),否则只显示市(区)级
                    foreach ($list as $key => $value) {
                        if (empty($value['p_code'])) {
                            $parent[$value['code']]['child_count'] = $value['child_count'] ?? 0;
                            $parent[$value['code']]['key'] = $key;
                        }
                        if (!empty($value['p_code'])) {
                            $child[$value['p_code']][] = $key;
                        }
                    }
                    if (!empty($parent)) {
                        foreach ($parent as $key => $value) {
                            if (!empty($child[$key])) {
                                if (count($child[$key]) == $value['child_count']) {
                                    foreach ($child[$key] as $cKey => $cValue) {
                                        unset($list[$cValue]);
                                    }
                                } else {
                                    unset($list[$value['key']]);
                                }
                            } else {
                                unset($list[$value['key']]);
                            }
                        }
                    }
                    break;
                case 2:
                    $list = $this->getCityGenreTree($list);
                    break;
            }
            if (!empty($sear['needCache'])) {
                cache($cacheKey, $list, (3600 * 8));
            }

        }

        return $list;

    }

    /**
     * 菜单栏无限极分类
     * @param array $cate 需要分级的数组
     * @param string $pCode 父类id
     * @param string $name 子类数组名字
     * @return array         分好级的数组
     * @author  Coder
     * @date 2020年06月01日 11:59
     */
    function getCityGenreTree(array $cate, $pCode = null, $name = 'child')
    {
        $arr = array();
        foreach ($cate as $key => $v) {
            if ($v['p_code'] == $pCode) {

                unset($cate[$key]);
                //根据不同层级给不同子类名称
                if ($v['type'] == 1) {
                    $name = 'city';
                } elseif ($v['type'] == 2) {
                    $name = 'area';
                }
                $v[$name] = $this->getCityGenreTree($cate, $v['code'], $name);

                if (empty($v[$name])) unset($v[$name]);

                $arr[] = $v;
            }
        }
        return $arr;
    }

    public function child()
    {
        return $this->hasMany(get_class($this), 'p_code', 'code')->where(['status' => $this->getStatusByRequestModule(2)])->where([['', 'exp', Db::raw('p_code is not null')]]);
    }

}