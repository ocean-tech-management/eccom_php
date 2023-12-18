<?php
// +----------------------------------------------------------------------
// |[ 文档说明: 测试模块]
// +----------------------------------------------------------------------



namespace app\controller\admin;


use app\BaseController;
use app\lib\exceptions\ServiceException;
use app\lib\models\User;
use think\facade\Db;

class Test extends BaseController
{
    //查询某个用户的团队业绩
    public function testCheck()
    {
        $data = $this->requestData;
        $phone = $data['phone'] ?? null;
        if (empty($phone)) {
            throw new ServiceException(['msg' => '非法数据']);
        }
        if (!is_numeric($phone)) {
            throw new ServiceException(['msg' => '非法数据!已被风控拦截']);
        }
        $userInfo = User::where(['phone' => $phone, 'status' => 1])->field('uid,name')->findOrEmpty()->toArray();
        if (empty($userInfo)) {
            throw new ServiceException(['msg' => '查无此用户']);
        }
        $uid = $userInfo['uid'];
        $query = Db::query("select sum(real_pay_price) as all_price FROM sp_order where order_type in (3,6) and order_status in (2,3,8) and uid in (select uid from sp_member where FIND_IN_SET('$uid',team_chain)) ORDER BY create_time desc");

        $rTdata['phone'] = $phone;
        $rTdata['user_name'] = $userInfo['name'] ?? '未知用户';
        if (empty($query)) {
            $rTdata['price'] = 0;
        } else {
            $rTdata['price'] = $query[0]['all_price'] ?? 0;
        }
        return returnData($rTdata);

    }
}