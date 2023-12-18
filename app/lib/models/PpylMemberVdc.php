<?php
// +----------------------------------------------------------------------
// |[ 文档说明: 拼拼有礼会员规则]
// +----------------------------------------------------------------------
// | Copyright (c) 2018~2024 http://www.mlhcmk.com All rights reserved.
// +----------------------------------------------------------------------


namespace app\lib\models;


use app\BaseModel;
use app\lib\exceptions\MemberException;
use Exception;
use think\facade\Db;
use app\lib\validates\MemberVdc as MemberVdcValidate;

class PpylMemberVdc extends BaseModel
{
    protected $validateFields = ['level' => 'in:1,2,3', 'status' => 'number'];
    private $belong = 1;

    /**
     * @title  会员分销规则列表
     * @param array $sear
     * @return array
     * @throws Exception
     */
    public function list(array $sear = []): array
    {
        if (!empty($sear['keyword'])) {
            $map[] = ['', 'exp', Db::raw($this->getFuzzySearSql('name', $sear['keyword']))];
        }
        $map[] = ['belong', '=', $this->belong];

        if (!empty($sear['member_level'])) {
            $map[] = ['level', '>', intval($sear['member_level'])];
        }
        $map[] = ['status', 'in', $this->getStatusByRequestModule($sear['searType'] ?? 1)];

        $page = intval($sear['page'] ?? 0) ?: null;
        if (!empty($page)) {
            $aTotal = $this->where($map)->count();
            $pageTotal = ceil($aTotal / $this->pageNumber);
        }

        $list = $this->where($map)->field('name,level,discount,vdc_genre,vdc_type,vdc_one,vdc_two,handing_scale,close_divide,create_time,recommend_ppyl_number')->when($page, function ($query) use ($page) {
            $query->page($page, $this->pageNumber);
        })->order('create_time desc')->select()->toArray();

        return ['list' => $list, 'pageTotal' => $pageTotal ?? 0];
    }

    /**
     * @title  根据会员等级获取会员折扣/分销规则
     * @param int $level
     * @return mixed
     */
    public function getMemberRule(int $level): array
    {
        $info = $this->where(['belong' => $this->belong, 'level' => $level, 'status' => 1])->findOrEmpty()->toArray();
        $info['recommend_level'] = json_decode($info['recommend_level'], true);
        $info['recommend_number'] = json_decode($info['recommend_number'], true);
        $info['train_level'] = json_decode($info['train_level'], true);
        $info['train_number'] = json_decode($info['train_number'], true);
        $info['recommend_ppyl_number'] = $info['recommend_ppyl_number'] ?? 0;
        return $info;
    }

    /**
     * @title  获取默认会员折扣
     * @return mixed
     */
    public function getDefaultMemberDis()
    {
        return $this->where(['belong' => $this->belong, 'level' => 3, 'status' => [1, 2]])->field('discount,vdc_one as vdc')->findOrEmpty()->toArray();
    }

    /**
     * @title  新增规则
     * @param array $data
     * @return mixed
     */
    public function new(array $data)
    {
        return $this->validate()->baseCreate($data);
    }

    /**
     * @title  编辑规则
     * @param array $data
     * @return PpylMemberVdc
     */
    public function edit(array $data)
    {
        $res = $this->validate()->baseUpdate(['belong' => $this->belong, 'level' => $data['level']], $data);

        return $res;
    }

    public function recommend()
    {
        return $this->hasOne(get_class($this), 'level', 'recommend_level')->field('level,name');
    }

    public function train()
    {
        return $this->hasOne(get_class($this), 'level', 'train_level')->field('level,name');
    }
}