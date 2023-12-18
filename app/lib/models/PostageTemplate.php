<?php
// +----------------------------------------------------------------------
// |[ 文档说明: 运费模板模块Model]
// +----------------------------------------------------------------------
// | Copyright (c) 2018~2024 http://www.mlhcmk.com All rights reserved.
// +----------------------------------------------------------------------


namespace app\lib\models;


use app\BaseModel;
use app\lib\exceptions\ShipException;
use app\lib\services\CodeBuilder;
use app\lib\validates\Postage;
use think\facade\Db;

class PostageTemplate extends BaseModel
{
    protected $validateFields = ['code', 'title', 'status' => 'number'];

    /**
     * @title  运费模版列表
     * @param array $sear
     * @return array
     * @throws \Exception
     */
    public function list(array $sear = []): array
    {
        if (!empty($sear['keyword'])) {
            $map[] = ['', 'exp', Db::raw($this->getFuzzySearSql('title', $sear['keyword']))];
        }
        $map[] = ['status', 'in', $this->getStatusByRequestModule($sear['searType'] ?? 1)];

        if (!empty($sear['code'])) {
            if (is_array($sear['code'])) {
                $map[] = ['code', 'in', $sear['code']];
            } else {
                $map[] = ['code', '=', $sear['code']];
            }
        }

        $cacheKey = null;
        $cacheExpire = 0;
        if ($this->module == 'api') {
            if (!empty($sear['cache'])) {
                $cacheKey = $sear['cache'];
                $cacheExpire = $sear['cache_expire'] ?? 180;
            }
        }

        $page = intval($sear['page'] ?? 0) ?: null;
        if (!empty($page)) {
            $aTotal = $this->where($map)->when($cacheKey, function ($query) use ($cacheKey, $cacheExpire) {
                $query->cache($cacheKey.'-count', $cacheExpire);
            })->count();
            $pageTotal = ceil($aTotal / $this->pageNumber);
        }

        $list = $this->with(['detail'])->where($map)->withoutField('id,update_time')->when($page, function ($query) use ($page) {
            $query->page($page, $this->pageNumber);
        })->when($cacheKey, function ($query) use ($cacheKey, $cacheExpire) {
            $query->cache($cacheKey, $cacheExpire);
        })->order('is_default asc,create_time desc,id desc')->select()->toArray();
        if (!empty($list)) {
            $aCityCode = [];
            foreach ($list as $key => $value) {
                if (!empty($value['detail'])) {
                    foreach ($value['detail'] as $dKey => $dValue) {
                        $cityCodeArray = explode(',', $dValue['city_code']);
                        $list[$key]['detail'][$dKey]['city_code'] = $cityCodeArray;
                        $aCityCode = array_merge_recursive($aCityCode, $cityCodeArray);
                    }
                }
            }
            //格式化城市显示
//            if (empty($sear['notFormat'])) {
//                $aCityCode = array_unique($aCityCode);
//                $allCity = (new City())->list(['code' => $aCityCode, 'formatType' => 1])['list'] ?? [];
//                if (!empty($allCity)) {
//                    foreach ($list as $lKey => $lValue) {
//                        foreach ($lValue['detail'] as $key => $value) {
//                            foreach ($allCity as $cKey => $cValue) {
//                                if (!empty($value['city_code']) && in_array($cValue['code'], $value['city_code'])) {
//                                    $list[$lKey]['detail'][$key]['city_name'][] = $cValue['name'];
//                                }
//                            }
//                        }
//                    }
//                }
//            }
        }

        return ['list' => $list, 'pageTotal' => $pageTotal ?? 0];
    }

    /**
     * @title  运费模版详情
     * @param array $data
     * @return array
     * @throws \Exception
     */
    public function info(array $data)
    {
        $map[] = ['status', 'in', $this->getStatusByRequestModule($sear['searType'] ?? 1)];
        $map[] = ['code', '=', $data['code']];
        $info = $this->with(['detail'])->where($map)->withoutField('id,create_time')->findOrEmpty()->toArray();

        if (!empty($info)) {
            if (!empty($info['detail'])) {
                $aCityCode = [];
                foreach ($info['detail'] as $key => $value) {
                    $aCityCode = array_merge_recursive($aCityCode, explode(',', $value['city_code']));
                }
                $aCityCode = array_unique($aCityCode);
                $allCity = (new City())->list(['code' => $aCityCode, 'formatType' => 2])['list'] ?? [];
                if (!empty($allCity)) {
                    foreach ($info['detail'] as $key => $value) {
                        foreach ($allCity as $cKey => $cValue) {
                            if (!empty($value['city_code']) && in_array($cValue['code'], $value['city_code'])) {
                                $info['detail'][$key]['city_name'][] = $cValue['name'];
                            }
                        }
                    }

                }
            }
        }

        return $info ?? [];
    }

    /**
     * @title  新增运费模版
     * @param array $data
     * @return mixed
     */
    public function DBNew(array $data)
    {
        (new Postage())->goCheck($data, 'create');
        if (($data['free_shipping'] != 1) && empty($data['detail'])) {
            throw new ShipException(['errorCode' => 1900112]);
        }
        $dbRes = Db::transaction(function () use ($data) {
            $oldDefault = $this->where(['is_default' => 1, 'status' => 1])->value('code');
            if (empty($oldDefault)) {
                $data['is_default'] = 1;
            } else {
                //原来存在默认,编辑的时候又选择了要重新设定默认的情况,需要取消原来的默认
                if (!empty($data['is_default']) && $data['is_default'] == 1) {
                    self::update(['is_default' => 2], ['code' => $oldDefault, 'status' => 1]);
                }
            }
            $data['code'] = (new CodeBuilder())->buildPostageCode();
            $res = $this->baseCreate($data);

            $detail = $data['detail'] ?? [];
            if (!empty($detail)) {
                $type = array_column($detail, 'type');
                if (!in_array(1, $type)) {
                    throw new ShipException(['errorCode' => 1900113]);
                }

                //判断城市选择是否有重复的
                $aAllCity = [];
                foreach ($detail as $key => $value) {
                    if ($value['type'] == 2) {
                        $aSame = array_intersect($aAllCity, $value['city_code']);
                        if (empty($aSame)) {
                            $aAllCity = array_merge_recursive($aAllCity, $value['city_code']);
                        } else {
                            throw new ShipException(['errorCode' => 1900114]);
                        }
                    }
                }

                $postageDetailModel = (new PostageDetail());
                foreach ($detail as $key => $value) {
                    if (intval($value['create_number']) <= 0 || intval($value['default_number']) <= 0) {
                        throw new ShipException(['msg' => '限制数量至少为1哦~']);
                    }
                    $value['template_code'] = $data['code'];
                    if ($value['type'] == 2) {
                        $value['city_code'] = implode(',', $value['city_code']);
                    }
                    $postageDetailModel->DBNewOrEdit($value);
                }
            }

            return $res->getData();
        });
        return $dbRes;

    }

    /**
     * @title  编辑运费模版
     * @param array $data
     * @return mixed
     */
    public function DBEdit(array $data)
    {
        (new Postage())->goCheck($data, 'edit');
        if (($data['free_shipping'] != 1) && empty($data['detail'])) {
            throw new ShipException(['errorCode' => 1900112]);
        }
        $dbRes = Db::transaction(function () use ($data) {
            $nMap[] = ['is_default', '=', 1];
            $nMap[] = ['status', '=', 1];
            $nMap[] = ['code', '<>', $data['code']];
            $oldDefault = $this->where($nMap)->value('code');;
            if (empty($oldDefault)) {
                $data['is_default'] = 1;
            } else {
                //原来存在默认,编辑的时候又选择了要重新设定默认的情况,需要取消原来的默认
                if (!empty($data['is_default']) && $data['is_default'] == 1) {
                    self::update(['is_default' => 2], ['code' => $oldDefault, 'status' => 1]);
                }
            }
            $res = $this->baseUpdate(['code' => $data['code']], $data);
            $detail = $data['detail'] ?? [];

            if (!empty($detail)) {
                $type = array_column($detail, 'type');
                if (!in_array(1, $type)) {
                    throw new ShipException(['errorCode' => 1900113]);
                }

                //判断城市选择是否有重复的
                $aAllCity = [];
                foreach ($detail as $key => $value) {
                    if ($value['type'] == 2) {
                        $aSame = array_intersect($aAllCity, $value['city_code']);
                        if (empty($aSame)) {
                            $aAllCity = array_merge_recursive($aAllCity, $value['city_code']);
                        } else {
                            throw new ShipException(['errorCode' => 1900114]);
                        }
                    }
                }

                $postageDetailModel = (new PostageDetail());
                foreach ($detail as $key => $value) {
                    if (intval($value['create_number']) <= 0 || intval($value['default_number']) <= 0) {
                        throw new ShipException(['msg' => '限制数量至少为1哦~']);
                    }
                    $value['template_code'] = $data['code'];
                    if ($value['type'] == 2) {
                        $value['city_code'] = implode(',', $value['city_code']);
                    }

                    $postageDetailModel->DBNewOrEdit($value);
                }
            }

            return $res->getData();
        });
        return $dbRes;
    }

    /**
     * @title  删除运费模版
     * @param array $data
     * @return bool
     */
    public function DBDelete(array $data)
    {
        $dbRes = Db::transaction(function () use ($data) {
            $res = $this->baseDelete(['code' => $data['code']]);
            $detailRes = PostageDetail::update(['status' => -1], ['template_code' => $data['code']]);
            return $res;
        });
        return judge($dbRes);
    }

    /**
     * @title  设置默认运费模版
     * @param string $code
     * @return PostageTemplate
     */
    public function setDefault(string $code)
    {
        $oldDefault = $this->where(['is_default' => 1, 'status' => 1])->field('code')->findOrEmpty()->toArray();
        if (!empty($oldDefault)) {
            self::update(['is_default' => 2], ['code' => $oldDefault['code'], 'status' => 1]);
        }
        $res = self::update(['is_default' => 1], ['code' => $code, 'status' => 1]);
        return $res;
    }


    public function detail()
    {
        return $this->hasMany('PostageDetail', 'template_code', 'code')->where(['status' => $this->getStatusByRequestModule(2)])->withoutField('update_time')->order('type asc,sort asc,create_time desc');
    }
}