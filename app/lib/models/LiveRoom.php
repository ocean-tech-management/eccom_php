<?php
// +----------------------------------------------------------------------
// |[ 文档说明: ]
// +----------------------------------------------------------------------
// | Copyright (c) 2018~2024 http://www.mlhcmk.com All rights reserved.
// +----------------------------------------------------------------------
 

namespace app\lib\models;


use app\BaseModel;
use Exception;
use think\facade\Db;

class LiveRoom extends BaseModel
{
    protected $validateFields = ['roomid'];

    /**
     * @title  直播间列表
     * @param array $sear
     * @return array
     * @throws Exception
     */
    public function list(array $sear = []): array
    {
        if (!empty($sear['keyword'])) {
            $map[] = ['', 'exp', Db::raw($this->getFuzzySearSql('name|anchor_name', $sear['keyword']))];
        }
        $map[] = ['status', 'in', $this->getStatusByRequestModule($sear['searType'] ?? 1)];

        if ($this->module == 'admin' && !empty($sear['show_status'] ?? null)) {
            $map[] = ['show_status', '=', $sear['show_status']];
        }

        $page = intval($sear['page'] ?? 0) ?: null;
        if (!empty($page)) {
            $aTotal = $this->where($map)->count();
            $pageTotal = ceil($aTotal / $this->pageNumber);
        }
        if ($this->module == 'api') {
            $map[] = ['show_status', '=', 1];
        }

        $list = $this->with(['goods', 'replay'])->where($map)->withoutField('id,update_time')->when($page, function ($query) use ($page) {
            $query->page($page, $this->pageNumber);
        })->order('create_time desc')->select()->toArray();

        return ['list' => $list, 'pageTotal' => $pageTotal ?? 0];
    }

    /**
     * @title  新增或编辑
     * @param array $data
     * @return LiveRoom
     */
    public function DBNewOrEdit(array $data)
    {
        return $this->updateOrCreate(['roomid' => $data['roomid'] ?? null], $data);
    }

    /**
     * @title  修改直播间展示状态
     * @param array $data
     * @return LiveRoom|bool
     */
    public function showStatus(array $data)
    {
        if ($data['show_status'] == 1) {
            $save['show_status'] = 2;
        } elseif ($data['show_status'] == 2) {
            $save['show_status'] = 1;
        } else {
            return false;
        }
        return $this->baseUpdate(['roomid' => $data['roomid']], $save);
    }

    public function goods()
    {
        return $this->hasMany('LiveRoomGoods', 'roomid', 'roomid')->withoutField('id,update_time');
    }

    public function replay()
    {
        return $this->hasMany('LiveRoomReplay', 'roomid', 'roomid')->withoutField('id,update_time');
    }


    public function getStartTimeAttr($value)
    {
        return !empty($value) ? timeToDateFormat($value) : $value;
    }

    public function getEndTimeAttr($value)
    {
        return !empty($value) ? timeToDateFormat($value) : $value;
    }
}