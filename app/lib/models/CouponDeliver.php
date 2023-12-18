<?php
// +----------------------------------------------------------------------
// |[ 文档说明: ]
// +----------------------------------------------------------------------



namespace app\lib\models;


use app\BaseModel;
use think\facade\Db;

class CouponDeliver extends BaseModel
{
    /**
     * @title  派券历史列表
     * @param array $sear
     * @return array
     * @throws \Exception
     */
    public function list(array $sear = []): array
    {
        if (!empty($sear['keyword'])) {
            $map[] = ['', 'exp', Db::raw($this->getFuzzySearSql('coupon', trim($sear['keyword'])))];
        }
        if (!empty($sear['userPhone'])) {
            $uMap[] = ['', 'exp', Db::raw($this->getFuzzySearSql('phone', trim($sear['userPhone'])))];
            $uMap[] = ['status', 'in', [1, 2]];
            $uMap[] = ['', 'exp', Db::raw('openid is not null')];
            $uid = User::where($uMap)->value('uid');
            $map[] = ['', 'exp', Db::raw($this->getFuzzySearSql('user', trim($uid)))];
        }
        if (!empty($sear['type'] ?? null)) {
            $map[] = ['type', '=', $sear['type']];
        }
        $map[] = ['status', 'in', $this->getStatusByRequestModule($sear['searType'] ?? 1)];
        $page = intval($sear['page'] ?? 0) ?: null;
        if (!empty($page)) {
            $aTotal = $this->where($map)->count();
            $pageTotal = ceil($aTotal / $this->pageNumber);
        }

        $list = $this->where($map)->withoutField('id,update_time')->when($page, function ($query) use ($page) {
            $query->page($page, $this->pageNumber);
        })->order('create_time desc,id desc')->select()->toArray();

        return ['list' => $list, 'pageTotal' => $pageTotal ?? 0];
    }
}