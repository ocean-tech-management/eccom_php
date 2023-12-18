<?php
// +----------------------------------------------------------------------
// |[ 文档说明: 股东奖规则模块Model]
// +----------------------------------------------------------------------



namespace app\lib\models;


use app\BaseModel;
use app\lib\exceptions\ServiceException;
use think\facade\Db;

class ShareholderReward extends BaseModel
{
    protected $validateFields = ['level' => 'in:1,2,3', 'status' => 'number'];
    protected $belong = 1;

    /**
     * @title  股东奖规则列表
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

        $page = intval($sear['page'] ?? 0) ?: null;
        if (!empty($page)) {
            $aTotal = $this->where($map)->count();
            $pageTotal = ceil($aTotal / $this->pageNumber);
        }

        $list = $this->where($map)->withoutField('update_time')->when($page, function ($query) use ($page) {
            $query->page($page, $this->pageNumber);
        })->order('create_time desc')->select()->toArray();

        return ['list' => $list, 'pageTotal' => $pageTotal ?? 0];
    }

    /**
     * @title  详情
     * @param array $data
     * @return array
     */
    public function info(array $data)
    {
        $info = self::where([$this->getPk()=>$data[$this->getPk()]])->findOrEmpty()->toArray();
        return $info;
    }

    /**
     * @title  编辑股东奖规则
     * @param array $data
     * @return bool
     */
    public function DBEdit(array $data)
    {
        if ($data['combo_number'] <= 0) {
            throw new ServiceException(['msg' => '套餐数不可小于0']);
        }
        $res = $this->validate()->baseUpdate(['level' => $data['level']], $data);
        return judge($res);
    }
}