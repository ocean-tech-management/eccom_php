<?php
// +----------------------------------------------------------------------
// |[ 文档说明: 用户证件模块Model]
// +----------------------------------------------------------------------
// | Copyright (c) 2018~2024 http://www.mlhcmk.com All rights reserved.
// +----------------------------------------------------------------------


namespace app\lib\models;


use app\BaseModel;
use Exception;
use think\facade\Db;

class UserCertificate extends BaseModel
{
    protected $validateFields = ['uid', 'user_phone', 'status' => 'in:1,2'];

    /**
     * @title  证件列表
     * @param array $sear
     * @return array
     * @throws Exception
     */
    public function list(array $sear = []): array
    {
        if (!empty($sear['keyword'])) {
            $map[] = ['', 'exp', Db::raw($this->getFuzzySearSql('name|phone', $sear['keyword']))];
        }
        $map[] = ['uid', '=', $sear['uid']];
        $map[] = ['status', 'in', $this->getStatusByRequestModule($sear['searType'] ?? 1)];

        $page = intval($sear['page'] ?? 0) ?: null;
        if (!empty($page)) {
            $aTotal = $this->where($map)->count();
            $pageTotal = ceil($aTotal / $this->pageNumber);
        }

        $list = $this->where($map)->field('id,uid,user_phone,real_name,id_card,id_card_front,id_card_back,is_default,create_time,sort')->when($page, function ($query) use ($page) {
            $query->page($page, $this->pageNumber);
        })->order('is_default asc,sort asc,create_time desc')->select()->each(function ($item) {
            $item['id_card'] = strlen($item['id_card']) == 15 ? substr_replace($item['id_card'], "********", 4, 8) : (strlen($item['id_card']) == 18 ? substr_replace($item['id_card'], "**********", 4, 10) : "身份证异常");
        })->toArray();

        return ['list' => $list, 'pageTotal' => $pageTotal ?? 0];
    }

    /**
     * @title  证件详情
     * @param array $data
     * @return mixed
     */
    public function info(array $data)
    {
        return $this->where(['id' => $data['id']])->field('id,uid,user_phone,real_name,id_card,id_card_front,id_card_back,is_default,create_time,sort')->findOrEmpty()->toArray();
    }

    /**
     * @title  新增
     * @param array $data
     * @return mixed
     */
    public function DBNew(array $data)
    {
        $res = $this->validate()->baseCreate($data, true);
        $this->changeDefault($res, $data);
        return $res;
    }

    /**
     * @title  编辑
     * @param array $data
     * @return UserCertificate
     */
    public function DBEdit(array $data)
    {
        $res = $this->validate()->baseUpdate([$this->getPk() => $data[$this->getPk()]], $data);
        $this->changeDefault($data[$this->getPk()], $data);
        return $res;
    }

    /**
     * @title  删除
     * @param string $id id
     * @return UserCertificate
     */
    public function DBDel(string $id)
    {
        return $this->baseDelete([$this->getPk() => $id]);
    }

    /**
     * @title  修改默认证件
     * @param string $id
     * @param array $data
     * @return bool
     */
    public function changeDefault(string $id, array $data)
    {
        $res = false;
        $default = $data['is_default'];
        $map[] = ['id', '<>', $id];
        $map[] = ['uid', '=', $data['uid']];
        $map[] = ['is_default', '=', $default];
        $map[] = ['status', '=', 1];
        $old = $this->where($map)->value('id');
        $now = $this->where(['id' => $id, 'status' => 1])->value('is_default');
        //如果原来有默认地址,则修改原来的,没有一个默认地址都没有则当前地址为默认地址
        if (!empty($old)) {
            if ($default == 1 && ($old != $id)) {
                $res = $this->validate(false)->baseUpdate(['id' => $old, 'status' => 1], ['is_default' => 2]);
            }
        } else {
            if ($now != 1) {
                $this->validate(false)->baseUpdate(['id' => $now, 'status' => 1], ['is_default' => 1]);
            }
        }
        return judge($res);
    }

}