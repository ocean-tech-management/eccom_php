<?php
// +----------------------------------------------------------------------
// |[ 文档说明: 后台操作日志模块Model]
// +----------------------------------------------------------------------
// | Copyright (c) 2018~2024 http://www.mlhcmk.com All rights reserved.
// +----------------------------------------------------------------------
 

namespace app\lib\models;


use app\BaseModel;
use think\facade\Db;

class OperationLog extends BaseModel
{
    protected $validateFields = ['admin_id'];

    /**
     * @title  操作日志列表
     * @param array $sear
     * @return array
     * @throws \Exception
     */
    public function list(array $sear = []): array
    {
        if (!empty($sear['keyword'])) {
            $map[] = ['', 'exp', Db::raw($this->getFuzzySearSql('path_name|admin_name', $sear['keyword']))];
        }
        if (!empty($sear['path'])) {
            if (is_array($sear['path'])) {
                $map[] = ['path', 'in', $sear['path']];
            } else {
                $map[] = ['path', '=', $sear['path']];
            }
        }
        if (!empty($sear['content'])) {
            $map[] = ['', 'exp', Db::raw($this->getFuzzySearSql('content', $sear['content']))];
        }
        $changeCoderName = $sear['changeCoderName'] ?? false;
        $page = intval($sear['page'] ?? 0) ?: null;

        if (!empty($sear['pageNumber'])) {
            $this->pageNumber = $sear['pageNumber'] ?? 2;
        }

        if (!empty($page)) {
            $aTotal = $this->where($map)->count();
            $pageTotal = ceil($aTotal / $this->pageNumber);
        }

        $list = $this->where($map)->field('admin_id,admin_name,path,path_name,content,create_time,update_time')->when($page, function ($query) use ($page) {
            $query->page($page, $this->pageNumber);
        })->order('create_time desc')->select()->each(function ($item) use ($changeCoderName) {
            if (!empty($item['admin_id']) && !empty($changeCoderName)) {
                if ($item['admin_id'] == '38') {
                    $item['admin_name'] = '后台管理员';
                }
            }
            return $item;
        })->toArray();

        if (!empty($list)) {
            if (!empty($sear['justNeedLastOne'])) {
                $list = current($list);
            }
        }
        return ['list' => $list, 'pageTotal' => $pageTotal ?? 0];
    }
}