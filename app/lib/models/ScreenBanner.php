<?php
// +----------------------------------------------------------------------
// |[ 文档说明: 屏幕广告模块Model]
// +----------------------------------------------------------------------
// | Copyright (c) 2018~2024 http://www.mlhcmk.com All rights reserved.
// +----------------------------------------------------------------------


namespace app\lib\models;


use app\BaseModel;
use app\lib\services\Token;
use Exception;
use think\facade\Cache;
use think\facade\Db;

class ScreenBanner extends BaseModel
{
    protected $field = ['title', 'image', 'sort', 'type', 'content', 'status', 'show_position', 'content_type', 'show_time', 'start_time', 'end_time', 'show_user_type'];
    protected $validateFields = ['image'];

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
        if (!empty($sear['show_position'])) {
            $map[] = ['show_position', '=', intval($sear['show_position'])];
        }
        if (!empty($sear['type'])) {
            $map[] = ['type', '=', intval($sear['type'])];
        }
        if (!empty($sear['content_type'])) {
            $map[] = ['content_type', '=', intval($sear['content_type'])];
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
        })->order('sort asc,create_time desc')->select()->each(function ($item) {
            if (!empty($item['start_time'])) {
                $item['start_time'] = timeToDateFormat($item['start_time']);
            }
            if (!empty($item['end_time'])) {
                $item['end_time'] = timeToDateFormat($item['end_time']);
            }
            return $item;
        })->toArray();

        return ['list' => $list, 'pageTotal' => $pageTotal ?? 0];
    }

    /**
     * @title  C端屏幕广告(只展示一个)
     * @param array $sear
     * @return array
     * @throws Exception
     */
    public function cList(array $sear = []): array
    {
        $cacheKey = false;
        $cacheExpire = 0;
        $userType = [];
        $tag = 'ApiHomeScreenBanner';

        if ($this->module == 'api') {
            //获取用户身份的区域
            $aUser = (new Token())->verifyToken();
            if (!empty($aUser) && !empty($aUser['uid'])) {
                $userType = (new User())->getUserIdentityByUid($aUser['uid']);
            }
            if (!empty($sear['cache'])) {
                if (!empty($userType)) {
                    $cacheKey = $sear['cache'] . '-' . implode(',', $userType);
                } else {
                    $cacheKey = $sear['cache'];
                }
                $cacheExpire = $sear['cache_expire'];
                if (!empty(cache($cacheKey))) {
                    return cache($cacheKey);
                }
            }
        }
        if (!empty($userType)) {
            $map[] = ['show_user_type', 'in', $userType];
        } else {
            $map[] = ['show_user_type', '=', 1];
        }

        $map[] = ['status', 'in', [1]];
        $map[] = ['show_position', '=', $sear['show_position'] ?? 1];
        $map[] = ['start_time', '<=', time()];
        $map[] = ['end_time', '>', time()];
        $map[] = ['show_time', '>', 0];

        $info = $this->where($map)->field('id,title,image,sort,type,content_type,content,show_time,end_time')->order('sort asc,create_time desc')->findOrEmpty()->toArray();
        if (!empty($info)) {
            //计算缓存时间
            $expireTime = $info['end_time'] - time();
            if ($expireTime < $cacheExpire) {
                $cacheExpire = $expireTime;
            }
            cache($cacheKey, $info, $cacheExpire, $tag);
        }


        return $info;
    }

    /**
     * @title  广告详情
     * @param int $id 广告id
     * @return mixed
     */
    public function info(int $id)
    {
        $info = $this->where(['id' => $id, 'status' => $this->getStatusByRequestModule()])->findOrEmpty()->toArray();
        if (!empty($info['start_time'])) {
            $info['start_time'] = timeToDateFormat($info['start_time']);
        }
        if (!empty($info['end_time'])) {
            $info['end_time'] = timeToDateFormat($info['end_time']);
        }

        return $info;

    }

    /**
     * @title  新增广告
     * @param array $data
     * @return mixed
     */
    public function new(array $data)
    {
        $res = $this->validate()->baseCreate($data, true);
        cache('ApiHomeScreenBanner', null);
        return $res;
    }

    /**
     * @title  编辑广告
     * @param array $data
     * @return ScreenBanner
     */
    public function edit(array $data)
    {
        $res = $this->validate()->baseUpdate([$this->getPk() => $data[$this->getPk()]], $data);
        cache('ApiHomeScreenBanner', null);
        return $res;
    }

    /**
     * @title  删除广告
     * @param int $id 广告id
     * @return ScreenBanner
     */
    public function del(int $id)
    {
        $res = $this->baseDelete([$this->getPk() => $id]);
        cache('ApiHomeScreenBanner', null);
        return $res;
    }

    /**
     * @title  上下架
     * @param array $data
     * @return ScreenBanner|bool
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
        $res = $this->baseUpdate([$this->getPk() => $data[$this->getPk()]], $save);
        cache('ApiHomeScreenBanner', null);
        return $res;
    }

    private function getListFieldByModule()
    {
        switch ($this->module) {
            case 'admin':
            case 'manager':
                $field = 'id,title,image,sort,type,status,sort,create_time,show_position,content_type,show_time,start_time,end_time';
                break;
            case 'api':
                $field = 'id,title,image,sort,type,content,content_type,show_time';
                break;
            default:
                $field = '*';
        }
        return $field;
    }

}