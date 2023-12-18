<?php
// +----------------------------------------------------------------------
// |[ 文档说明: 商品标签模块Model]
// +----------------------------------------------------------------------
// | Copyright (c) 2018~2024 http://www.mlhcmk.com All rights reserved.
// +----------------------------------------------------------------------


namespace app\lib\models;


use app\BaseModel;
use Exception;
use think\facade\Db;

class Tag extends BaseModel
{
    protected $field = ['name', 'icon', 'sort', 'status'];
    protected $validateFields = ['name'];

    /**
     * @title  标签列表
     * @param array $sear
     * @return array
     * @throws Exception
     */
    public function list(array $sear = []): array
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
        $field = $this->getListFieldByModule();

        $list = $this->where($map)->field($field)->when($page, function ($query) use ($page) {
            $query->page($page, $this->pageNumber);
        })->order('sort asc,create_time desc')->select()->toArray();

        return ['list' => $list, 'pageTotal' => $pageTotal ?? 0];
    }


    /**
     * @title  标签详情
     * @param int $id 标签id
     * @return mixed
     */
    public function info(int $id)
    {
        return $this->where(['id' => $id, 'status' => $this->getStatusByRequestModule()])->findOrEmpty()->toArray();
    }

    /**
     * @title  新增标签
     * @param array $data
     * @return mixed
     */
    public function new(array $data)
    {
        return $this->validate()->baseCreate($data, true);
    }

    /**
     * @title  编辑标签
     * @param array $data
     * @return Tag
     */
    public function edit(array $data)
    {
        return $this->validate()->baseUpdate([$this->getPk() => $data[$this->getPk()]], $data);
    }

    /**
     * @title  删除标签
     * @param int $id 广告id
     * @return Tag
     */
    public function del(int $id)
    {
        return $this->baseDelete([$this->getPk() => $id]);
    }

    /**
     * @title  上下架
     * @param array $data
     * @return Tag|bool
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
                $field = 'id,name,icon,sort,status';
                break;
            case 'api':
                $field = 'id,name,icon,sort,status';
                break;
            default:
                $field = '*';
        }
        return $field;
    }

}