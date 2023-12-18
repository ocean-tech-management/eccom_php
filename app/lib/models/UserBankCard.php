<?php
// +----------------------------------------------------------------------
// |[ 文档说明: 用户银行卡模块Model]
// +----------------------------------------------------------------------



namespace app\lib\models;


use app\BaseModel;
use app\lib\exceptions\ServiceException;
use app\lib\exceptions\UserException;
use app\lib\services\CodeBuilder;
use app\lib\services\JoinPay;
use think\facade\Db;

class UserBankCard extends BaseModel
{
    /**
     * @title  证件列表
     * @param array $sear
     * @return array
     * @throws \Exception
     */
    public function list(array $sear = []): array
    {
        if (!empty($sear['keyword'])) {
            $map[] = ['', 'exp', Db::raw($this->getFuzzySearSql('user_phone', $sear['keyword']))];
        }
        $map[] = ['status', 'in', $this->getStatusByRequestModule($sear['searType'] ?? 1)];

        if ($this->module == 'api') {
            $map[] = ['uid', '=', $sear['uid']];
            $map[] = ['contract_status', 'in', [1]];
        }

        $page = intval($sear['page'] ?? 0) ?: null;
        if (!empty($page)) {
            $aTotal = $this->where($map)->count();
            $pageTotal = ceil($aTotal / $this->pageNumber);
        }
        $field = $this->getListFieldByModule();

        $list = $this->where($map)->field($field)->when($page, function ($query) use ($page) {
            $query->page($page, $this->pageNumber);
        })->order('is_default asc,sort asc,create_time desc')->select()->each(function ($item) {
            if (!empty($item['id_card'])) {
                $item['id_card'] = encryptBank($item['id_card'],'');
            }
            if (!empty($item['bank_card'])) {
                $item['bank_card'] = encryptBank($item['bank_card'],'');
            }
            if (!empty($item['bank_phone'])) {
                $item['bank_phone'] = encryptPhone($item['bank_phone']);
            }
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
        $field = $this->getListFieldByModule();
        return $this->where(['card_sn' => $data['card_sn']])->field($field)->findOrEmpty()->toArray();
    }

    /**
     * @title  新增
     * @param array $data
     * @return mixed
     */
    public function DBNew(array $data)
    {
        if (empty($data['card_sn'])) {
            $data['card_sn'] = (new CodeBuilder())->buildUserCardSn();
        }
        $res = $this->baseCreate($data, true);
        $this->changeDefault($res, $data);
        return $res;
    }

    /**
     * @title  编辑
     * @param array $data
     * @return UserBankCard
     */
    public function DBEdit(array $data)
    {
        $res = $this->baseUpdate(['card_sn' => $data['card_sn']], $data);
        $this->changeDefault($data['card_sn'], $data);
        return $res;
    }

    /**
     * @title  删除
     * @param string $cardSn
     * @return mixed
     */
    public function DBDel(string $cardSn)
    {
        return $this->baseDelete(['card_sn' => $cardSn]);
    }

    /**
     * @title  修改默认证件
     * @param string $cardSn
     * @param array $data
     * @return bool
     */
    public function changeDefault(string $cardSn, array $data)
    {
        $res = false;
        $default = $data['is_default'];
        $map[] = ['card_sn', '<>', $cardSn];
        $map[] = ['uid', '=', $data['uid']];
        $map[] = ['is_default', '=', $default];
        $map[] = ['status', '=', 1];
        $old = $this->where($map)->value('card_sn');
        $now = $this->where(['card_sn' => $cardSn, 'status' => 1])->value('is_default');
        //如果原来有默认卡,则修改原来的,一个默认卡都没有则当前卡为默认卡
        if (!empty($old)) {
            if ($default == 1 && ($old != $cardSn)) {
                $res = $this->validate(false)->baseUpdate(['card_sn' => $old, 'status' => 1], ['is_default' => 2]);
            }
        } else {
            if ($now != 1) {
                $this->validate(false)->baseUpdate(['card_sn' => $now, 'status' => 1], ['is_default' => 1]);
            }
        }
        return judge($res);
    }

    private function getListFieldByModule()
    {
        switch ($this->module) {
            case 'admin':
            case 'manager':
                $field = 'card_sn,uid,user_phone,id_card,card_type,bank_card,bank_phone,bank_code,bank_name,sign_no,is_default,create_time,status,sort';
                break;
            case 'api':
                $field = 'card_sn,uid,user_phone,card_type,bank_card,bank_code,bank_name,sign_no,is_default,create_time,status,sort';
                break;
            default:
                $field = 'card_sn,uid,user_phone';
        }
        return $field;
    }
}