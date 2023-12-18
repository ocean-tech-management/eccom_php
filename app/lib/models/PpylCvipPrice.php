<?php
// +----------------------------------------------------------------------
// |[ 文档说明: ]
// +----------------------------------------------------------------------



namespace app\lib\models;


use app\BaseModel;
use app\lib\services\CodeBuilder;
use think\facade\Db;

class PpylCvipPrice extends BaseModel
{

    protected $validateFields = ['level' => 'in:1,2,3', 'status' => 'number'];
    private $belong = 1;

    /**
     * @title  会员分销规则列表
     * @param array $sear
     * @return array
     * @throws \Exception
     */
    public function list(array $sear = []): array
    {
        if (!empty($sear['keyword'])) {
            $map[] = ['', 'exp', Db::raw($this->getFuzzySearSql('name', $sear['keyword']))];
        }

        if (!empty($sear['member_level'])) {
            $map[] = ['level', '>', intval($sear['member_level'])];
        }
        $map[] = ['status', 'in', $this->getStatusByRequestModule($sear['searType'] ?? 1)];

        $page = intval($sear['page'] ?? 0) ?: null;
        if (!empty($page)) {
            $aTotal = $this->where($map)->count();
            $pageTotal = ceil($aTotal / $this->pageNumber);
        }

        $list = $this->where($map)->field('combo_sn,name,level,expire_time,price,market_price,poster,desc,status,create_time')->when($page, function ($query) use ($page) {
            $query->page($page, $this->pageNumber);
        })->order('create_time desc')->select()->toArray();

        return ['list' => $list, 'pageTotal' => $pageTotal ?? 0];
    }

    /**
     * @title  拼拼有礼会员C端价格列表
     * @param array $data
     * @return array
     * @throws \Exception
     */
    public function cInfo(array $data)
    {
        $level = $data['level'] ?? 1;
        $uid = $data['uid'] ?? null;
        if (!empty($uid)) {
            $userInfo = User::where(['uid' => $uid, 'status' => 1])->field('uid,name,phone,c_vip_level,c_vip_time_out_time,auto_receive_reward')->findOrEmpty()->toArray();
            if(!empty($userInfo['c_vip_time_out_time'] ?? null)){
                $userInfo['c_vip_time_out_time'] = timeToDateFormat($userInfo['c_vip_time_out_time']);
            }
            $returnData['userInfo'] = $userInfo ?? [];
        }
        $list = self::where(['level' => $level, 'status' => 1])->field('combo_sn,name,level,expire_time,price,poster,market_price,desc')->order('price asc')->select()->toArray();
        $returnData['price'] = $list ?? [];

        return $returnData;
    }

    /**
     * @title  价格详情
     * @param array $data
     * @return array
     */
    public function info(array $data)
    {
        $info = $this->where(['combo_sn'=>$data['combo_sn'],'status'=>[1,2]])->withoutField('id,update_time')->findOrEmpty()->toArray();
        return $info;
    }

    /**
     * @title  新增价格
     * @param array $data
     * @return mixed
     */
    public function new(array $data)
    {
        $data['combo_sn'] = (new CodeBuilder())->buildCVIPGradientSn();
        return $this->validate()->baseCreate($data);
    }

    /**
     * @title  编辑价格
     * @param array $data
     * @return PpylCvipPrice
     */
    public function edit(array $data)
    {
        $res = $this->validate()->baseUpdate(['combo_sn' => $data['combo_sn']], $data);
        return $res;
    }

    /**
     * @title  删除价格
     * @param string $comboSn 套餐编号
     * @return PpylCvipPrice
     */
    public function del(string $comboSn)
    {
        return $this->baseDelete(['combo_sn' => $comboSn]);
    }

    /**
     * @title  上下架
     * @param array $data
     * @return PpylCvipPrice|bool
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
        return $this->baseUpdate(['combo_sn' => $data['combo_sn']], $save);
    }
}