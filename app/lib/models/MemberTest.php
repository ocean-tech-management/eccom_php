<?php
// +----------------------------------------------------------------------
// |[ 文档说明: ]
// +----------------------------------------------------------------------



namespace app\lib\models;


use app\BaseModel;
use app\lib\exceptions\OrderException;
use app\lib\exceptions\PpylException;
use think\facade\Db;
use think\facade\Queue;

class MemberTest extends BaseModel
{
    public function cUserCount()
    {
        return $this->hasOne('User', 'link_superior_user', 'uid')->field('uid,phone');
    }
    public function thr()
    {
        throw new PpylException(['msg'=>'错误啦我丢11111111']);
    }

    public function test(array $data = [])
    {

        set_time_limit(0);
        ini_set('memory_limit', '10072M');
        ini_set('max_execution_time', '0');
        $nowLevel = cache('nowLevel') ?? 1;
        dump('进来执行了,现在执行层级为等级为' . $nowLevel);
//        $dataUid = "6flbTIAOLz,aJrXnm8jsT,A98jEUbwC9,S8r0K9Iew4,K6k4cCssOc";
//        $dataUid = explode(',',$dataUid);
        if ($nowLevel == 1) {
            $mapN[] = ['', 'exp', Db::raw('link_superior_user is null')];
//            $mapN[] = ['status', '=', 1];
            $notTopUserUid = Member::where($mapN)->column('uid');
//            $notTopUserUid = ['S8r0K9Iew4'];
        } else {
            $levelList = cache('level-' . ($nowLevel - 1));
            if (!empty($levelList)) {
                $notTopUserUid = $levelList;
            }
        }
//        $notTopUserUid = cache('level-2');
        if (empty($notTopUserUid)) {
            dump('已经没有可以找的上级了');
            return false;
        }

//        $notTopUserUid = cache('level-4');
//        dump($notTopUserUid);die;
//        $notTopUserUid = ['Cc3Adds9IF'];
//        dump($notTopUserUid);
//        dump($notTopUserUid);
        $page = $this->requestData['page'] ?? ($data['page'] ?? 1);
        $map[] = ['', 'exp', Db::raw('team_chain is null')];
        $map[] = ['link_superior_user', 'in', $notTopUserUid];
        $map[] = ['', 'exp', Db::raw('link_superior_user is not null')];
//        $map[] = ['uid','in',$dataUid];
        $list = Member::with(['parent'])->where($map)
            ->page(1, 500)
            ->field('uid,user_phone,link_superior_user')->select()->toArray();
//        dump($list);
        dump('本次查询到下级数据' . count($list) . '条' . timeToDateFormat(time()));

        $linkUser = [];
        if (!empty($list)) {
            foreach ($list as $key => $value) {
                if (!empty($value['link_superior_user'])) {
                    if (empty($value['parent'])) {
                        $linkUser[$value['uid']] = $value['link_superior_user'];
                    } else {
                        if (!empty($value['parent']['team_chain'])) {
                            $linkUser[$value['uid']] = $value['link_superior_user'] . ',' . $value['parent']['team_chain'];
                        } else {
                            if ($nowLevel != 1) {
                                dump('出现了错误,这个用户的上级没有团队链条,他应该有,用户信息如下');
                                dump(json_encode($value, 256));
                                return false;
                            } else {
                                $linkUser[$value['uid']] = $value['link_superior_user'];
                            }

                        }
                    }
                } else {
                    dump('这个用户查出来没有上级');
                    dump($value);
                }
            }
        } else {
            dump('已经没有所属下级了,上级其中之一有', current($notTopUserUid));
            return false;
        }
        dump('本次查询到插入数据库数据' . count($linkUser ?? []) . '条');
//        if(count($linkUser) != count($linkUser)){
//            dump('数据有异常,查出来的列表和最后要添加的数据不一致,现在的等级是'.$nowLevel);
//            dump($list);
//            dump($linkUser);die;
//            return false;
//        }

        if (!empty($linkUser)) {
            foreach ($linkUser as $key => $value) {
                $res = Member::update(['team_chain' => $value], ['uid' => $key]);
                $allRes[$key]['dbRes'] = $res->getData();
                $allRes[$key]['uid'] = $key;
            }
            $oldTopUserList = cache('level-' . $nowLevel);
            $haveChildUser = Member::alias('a')->join('sp_member b', 'a.uid = b.link_superior_user', 'left')->where(['a.uid' => array_keys($linkUser)])->field('a.uid,count(b.id) as child_number')->group('a.uid')->select()->toArray();
            $addUid = [];
            if (!empty($haveChildUser)) {
                foreach ($haveChildUser as $key => $value) {
                    if (!empty($value['child_number'] ?? 0) && $value['child_number'] > 0) {
                        $addUid[] = $value['uid'];
                    }
                }
            }
            dump('剔除后只有' . count($addUid) . '人拥有下级');
            if (!empty($addUid)) {
                if (!empty($oldTopUserList)) {
                    $newList = array_unique(array_merge_recursive($oldTopUserList, array_unique($addUid)));
                } else {
                    $newList = array_unique($addUid);
                }
//            dump($newList);
                cache('level-' . $nowLevel, $newList, 72000);

//            dump(cache('level-'.$nowLevel));
            }

            if (count($list) < 500) {
                dump('查询出来的数量是' . count($list) . '插入数据库的数量是' . count($linkUser));
                dump($nowLevel . '等级数量已经不够了,准备进行下一级');
                cache('nowLevel', ($nowLevel + 1), 72000);
            }
//            dump( cache('nowLevel'));die;

            Queue::push('app\lib\job\Auto', ['page' => 1, 'autoType' => 4], config('system.queueAbbr') . 'Auto');
        }
        return true;
    }
}