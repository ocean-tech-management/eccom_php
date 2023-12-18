<?php

namespace app\lib\models;

use app\BaseModel;
use think\facade\Db;

class CrowdfundingWithdrawCustom extends BaseModel
{
    /**
     * @title  美丽金可提现自定义额度列表
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


        $page = intval($sear['page'] ?? 0) ?: null;
        if (!empty($page)) {
            $aTotal = $this->where($map)->count();
            $pageTotal = ceil($aTotal / $this->pageNumber);
        }

        $list = $this->with(['user'])->where($map)->withoutField('id,update_time')->when($page, function ($query) use ($page) {
            $query->page($page, $this->pageNumber);
        })->order('create_time desc')->select()->toArray();

        return ['list' => $list, 'pageTotal' => $pageTotal ?? 0];
    }


    /**
     * @title  详情
     * @param int $id id
     * @return mixed
     */
    public function info(int $id)
    {
        return $this->where(['id' => $id, 'status' => $this->getStatusByRequestModule()])->findOrEmpty()->toArray();
    }

    /**
     * @title  新增
     * @param array $data
     * @return mixed
     */
    public function DBNew(array $data)
    {
        $data['type'] = 1;
        if ((double)$data['price'] < 0) {
            $data['type'] = 2;
        }
        return $this->baseCreate($data, true);
    }

    /**
     * @title  编辑
     * @param array $data
     * @return mixed
     */
    public function edit(array $data)
    {
        return $this->baseUpdate([$this->getPk() => $data[$this->getPk()]], $data);
    }


    public function user()
    {
        return $this->hasOne('User','uid','uid')->bind(['user_name'=>'name','user_phone'=>'phone','user_avatarUrl'=>'avatarUrl','user_vip_level'=>'vip_level']);
    }
}