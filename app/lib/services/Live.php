<?php
// +----------------------------------------------------------------------
// |[ 文档说明: 小程序直播模块Service]
// +----------------------------------------------------------------------
// | Copyright (c) 2018~2024 http://www.mlhcmk.com All rights reserved.
// +----------------------------------------------------------------------
 

namespace app\lib\services;


use app\lib\exceptions\ServiceException;
use app\lib\models\File;
use app\lib\models\LiveRoom;
use app\lib\models\LiveRoomGoods;
use app\lib\services\Live as LiveService;
use think\facade\Db;
use think\facade\Request;

class Live
{
    private $pageUrl = 'pages/product-detail/product-detail?sn=';

    /**
     * @title  创建直播间
     * @param array $data
     * @return mixed|string
     */
    public function createLive(array $data)
    {
        $rData['name'] = $data['name'];
        $rData['coverImg'] = $data['cover_img'];  //微信素材id,不是链接
        $rData['startTime'] = $data['start_time'];
        $rData['endTime'] = $data['end_time'];
        $rData['anchorName'] = $data['anchor_name'];
        $rData['anchorWechat'] = $data['anchor_wechat'];
        $rData['shareImg'] = $data['share_img'];  //微信素材id,不是链接
        $rData['type'] = $data['type'] ?? 0;
        $rData['screenType'] = $data['screen_type'] ?? 0;
        $rData['closeLike'] = $data['close_like'] ?? 0;
        $rData['closeGoods'] = $data['close_goods'] ?? 0;
        $rData['closeComment'] = $data['close_comment'] ?? 0;
        $requestUrl = config('system.wxLive.createLive');
        $res = $this->request($requestUrl, 'POST', json_encode($rData, JSON_UNESCAPED_UNICODE));
        if (!empty($res) && $res['errcode'] == 0) {
            $data['roomid'] = $res['roomId'];
            $dbNewRoom = (new LiveRoom())->DBNewOrEdit($data)->getData();
        }
        return $dbNewRoom ?? '创建失败';
    }

    /**
     * @title  获取直播间列表
     * @param array $data
     * @return bool|mixed|string
     */
    public function getLiveRoomList(array $data = [])
    {
        $rData['start'] = $data['start'] ?? 0;
        $rData['limit'] = $data['limit'] ?? 10;
        $requestUrl = config('system.wxLive.roomList');
        $res = $this->request($requestUrl, 'POST', json_encode($rData, JSON_UNESCAPED_UNICODE));
        return $res;
    }

    /**
     * @title  同步获取全部直播间列表
     * @param int $firstStart 查询首次开始位置
     * @param int $firstLimit 查询开始限制条数(每页条数)
     * @return array|mixed
     */
    public function syncLiveRoom(int $firstStart = 0, int $firstLimit = 30)
    {
        $startNumber = $firstStart;
        $limitNumber = $firstLimit;
        $pageNumber = $firstLimit;
        $list = $this->getLiveRoomList(['start' => $startNumber, 'limit' => $limitNumber]);
        $allRoom = [];
        if (!empty($list)) {
            $aFirst = $list['room_info'];
            if (!empty($list['total']) && (intval($list['total']) > $limitNumber)) {
                $forNumber = ceil(intval($list['total']) / $pageNumber) - 1;
                if (intval($forNumber) > 0) {
                    for ($i = 0; $i < $forNumber; $i++) {
                        $nextRoomList = $this->getLiveRoomList(['start' => $limitNumber, 'limit' => $pageNumber]);
                        if (!empty($nextRoomList) && $nextRoomList['errcode'] == 0) {
                            $aNext = $nextRoomList['room_info'] ?? [];
                            $limitNumber += $limitNumber;
                            $allRoom = array_merge_recursive($aFirst, $aNext);
                            $aFirst = $allRoom;
                        }
                    }
                }
            } else {
                $allRoom = $aFirst;
            }
        }
        //同步到数据库
        if (!empty($allRoom)) {
            $dbRes = Db::transaction(function () use ($allRoom) {
                $LiveRoomModel = new LiveRoom();
                $liveRoomGoodsModel = new LiveRoomGoods();
                foreach ($allRoom as $key => $value) {
                    $roomRes = $LiveRoomModel->DBNewOrEdit($value);
                    if (!empty($value['goods'])) {
                        foreach ($value['goods'] as $gKey => $gValue) {
                            $gValue['roomid'] = $value['roomid'];
                            $goodsRes = $liveRoomGoodsModel->DBNewOrEdit($gValue);
                        }
                    }

                }
                return $roomRes;
            });

        }
        return true;
    }

    /**
     * @title  同步获取全部商品库商品
     * @param int $firstStart 查询首次开始位置
     * @param int $firstLimit 查询开始限制条数(每页条数)
     * @param int $status 查询状态 0：未审核。1：审核中，2：审核通过，3：审核驳回
     * @return array|mixed
     */
    public function syncGoods(int $firstStart = 0, int $firstLimit = 30, int $status = 0)
    {
        $startNumber = $firstStart;
        $limitNumber = $firstLimit;
        $pageNumber = $firstLimit;
        $list = $this->getGoodsList(['offset' => $startNumber, 'limit' => $limitNumber, 'status' => $status]);
        $allGoods = [];
        if (!empty($list)) {
            $aFirst = $list['goods'];
            if (!empty($list['goods']) && (intval($list['goods']) > $limitNumber)) {
                $forNumber = ceil(intval($list['goods']) / $pageNumber) - 1;
                if (intval($forNumber) > 0) {
                    for ($i = 0; $i < $forNumber; $i++) {
                        $nextGoodsList = $this->getGoodsList(['offset' => $limitNumber, 'limit' => $pageNumber, 'status' => $status]);
                        if (!empty($nextGoodsList) && $nextGoodsList['errcode'] == 0) {
                            $aNext = $nextGoodsList['goods'] ?? [];
                            $limitNumber += $limitNumber;
                            $allGoods = array_merge_recursive($aFirst, $aNext);
                            $aFirst = $allGoods;
                        }
                    }
                }
            } else {
                $allGoods = $aFirst;
            }
        }
        //同步到数据库
        if (!empty($allGoods)) {
            $dbRes = Db::transaction(function () use ($allGoods) {
                $liveRoomGoodsModel = new LiveRoomGoods();
                foreach ($allGoods as $key => $value) {
                    $value['price_type'] = $value['priceType'];
                    $goodsRes = $liveRoomGoodsModel->DBNewOrEdit($value);
                }
                return $goodsRes;
            });

        }
        return true;
    }

    public function getLiveRoomReplay(array $data)
    {

    }


    /**
     * @title  商品列表
     * @param array $data
     * @return bool|mixed|string
     */
    public function getGoodsList(array $data)
    {
        $rData['offset'] = $data['offset'] ?? 0;
        $rData['limit'] = $data['limit'] ?? 30;
        $rData['status'] = $data['verify_status'] ?? 2;
        $requestUrl = config('system.wxLive.goodsList');
        $res = $this->request($requestUrl, 'GET', $rData);
        return $res;
    }

    /**
     * @title  添加商品审核
     * @param array $data
     * @return mixed
     */
    public function createGoods(array $data)
    {
        $rData['goodsInfo']['coverImgUrl'] = $data['media_id'];
        $rData['goodsInfo']['name'] = $data['name'];
        $rData['goodsInfo']['priceType'] = $data['priceType'];
        $rData['goodsInfo']['price'] = $data['price'];
        $rData['goodsInfo']['price2'] = $data['price2'] ?? null;
        $rData['goodsInfo']['url'] = $this->pageUrl . $data['goods_sn'];
        $requestUrl = config('system.wxLive.createGoods');
        $res = $this->request($requestUrl, 'POST', json_encode($rData, JSON_UNESCAPED_UNICODE));
        $goodsRes = [];
        //记录商品信息
        if (!empty($res) && ($res['errcode'] == 0)) {
            $goods['goodsId'] = $res['goodsId'];
            $goods['auditId'] = $res['auditId'];
            $goods['name'] = $data['name'];
            $goods['cover_img'] = File::where(['wx_media_id' => $data['media_id'], 'type' => 1])->value('url');
            $goods['url'] = $rData['goodsInfo']['url'];
            $goods['price'] = $rData['goodsInfo']['price'];
            $goods['price2'] = $rData['goodsInfo']['price2'] ?? null;
            $goods['price_type'] = $rData['goodsInfo']['price_type'];
            $goods['thirdPartyTag'] = 2;
            $goods['verify_status'] = 0;
            $goodsRes = LiveRoomGoods::create($goods)->getData();
        }
        return $goodsRes;
    }

    /**
     * @title  撤回商品审核
     * @param array $data
     * @return LiveRoomGoods|array
     */
    public function resetAuditGoods(array $data)
    {
        $rData['goodsId'] = $data['goodsId'];
        $rData['auditId'] = $data['auditId'];
        $goodsStatus = LiveRoomGoods::where(['goodsId' => $rData['goodsId'], 'auditId' => $rData['auditId'], 'status' => [1, 2]])->findOrEmpty()->toArray();
        if (!empty($goodsStatus)) {
            throw new ServiceException(['msg' => '暂无商品信息']);
        }
        if (!in_array($goodsStatus['verify_status'], [0, 1])) {
            throw new ServiceException(['msg' => '仅允许待审核和审核中的商品撤回审核~']);
        }
        $requestUrl = config('system.wxLive.resetAuditGoods');
        $res = $this->request($requestUrl, 'POST', json_encode($rData, JSON_UNESCAPED_UNICODE));
        $goodsRes = [];
        //修改商品信息
        if (!empty($res) && ($res['errcode'] == 0)) {
            $goodsRes = LiveRoomGoods::update(['verify_status' => -1], ['goodsId' => $rData['goodsId'], 'auditId' => $rData['auditId'], 'verify_status' => [0, 1], 'status' => [1, 2]])->getData();
        }
        return $goodsRes;
    }

    /**
     * @title  删除商品库中的商品
     * @param array $data
     * @return array|mixed
     * @remark 可删除【小程序直播】商品库中的商品，删除后直播间上架的该商品也将被同步删除，不可恢复；
     */
    public function deleteLiveRoomGoods(array $data)
    {
        $rData['goodsId'] = $data['goodsId'];
        $goodsStatus = LiveRoomGoods::where(['goodsId' => $rData['goodsId'], 'status' => [1, 2]])->findOrEmpty()->toArray();
        if (!empty($goodsStatus)) {
            throw new ServiceException(['msg' => '暂无商品信息']);
        }
        if (!in_array($goodsStatus['verify_status'], [2])) {
            throw new ServiceException(['msg' => '仅审核通过商品删除~']);
        }
        $requestUrl = config('system.wxLive.deleteGoods');
        $res = $this->request($requestUrl, 'POST', json_encode($rData, JSON_UNESCAPED_UNICODE));
        $goodsRes = [];
        //修改商品信息
        if (!empty($res) && ($res['errcode'] == 0)) {
            $goodsRes = LiveRoomGoods::update(['verify_status' => -1, 'status' => -1], ['goodsId' => $rData['goodsId'], 'status' => [1, 2]])->getData();
        }
        return $goodsRes;
    }

    /**
     * @title  更新待审核的商品信息
     * @param array $data
     * @return array|mixed
     */
    public function updateLiveRoomGoods(array $data)
    {
        $goodsStatus = LiveRoomGoods::where(['goodsId' => $data['goodsId'], 'status' => [1, 2]])->findOrEmpty()->toArray();
        if (!empty($goodsStatus)) {
            throw new ServiceException(['msg' => '暂无商品信息']);
        }
        if (!in_array($goodsStatus['verify_status'], [0])) {
            throw new ServiceException(['msg' => '仅未审核的商品才允许更新哦~']);
        }

        $rData['goodsInfo']['goodsId'] = $data['goodsId'];
        $rData['goodsInfo']['coverImgUrl'] = $data['media_id'];
        $rData['goodsInfo']['name'] = $data['name'];
        $rData['goodsInfo']['priceType'] = $data['priceType'];
        $rData['goodsInfo']['price'] = $data['price'];
        $rData['goodsInfo']['price2'] = $data['price2'] ?? null;
        $rData['goodsInfo']['url'] = $this->pageUrl . $data['goods_sn'];


        $requestUrl = config('system.wxLive.updateGoods');
        $res = $this->request($requestUrl, 'POST', json_encode($rData, JSON_UNESCAPED_UNICODE));
        $goodsRes = [];
        //修改商品信息
        if (!empty($res) && ($res['errcode'] == 0)) {
            $goods['name'] = $data['name'];
            $goods['cover_img'] = File::where(['wx_media_id' => $data['media_id'], 'type' => 1])->value('url');
            $goods['url'] = $rData['goodsInfo']['url'];
            $goods['price'] = $rData['goodsInfo']['price'];
            $goods['price2'] = $rData['goodsInfo']['price2'] ?? null;
            $goods['price_type'] = $rData['goodsInfo']['price_type'];
            $goodsRes = LiveRoomGoods::update($goods, ['goodsId' => $data['goodsId'], 'status' => [1, 2]])->getData();
        }
        return $goodsRes;
    }

    /**
     * @title  直播间导入商品
     * @param array $data
     * @return mixed
     */
    public function importLiveRoomGoods(array $data)
    {
        $rData['ids'] = $data['goods_id']; //array
        $rData['roomId'] = $data['room_id'];
        $requestUrl = config('system.wxLive.importGoods');
        $res = $this->request($requestUrl, 'POST', json_encode($rData, JSON_UNESCAPED_UNICODE));
        return $res;
    }

    /**
     * @title  获取直播间分享二维码
     * @param array $data
     * @return mixed
     */
    public function getLiveRoomShareCode(array $data)
    {
        if (empty($data['params'] ?? null)) {
            $rData['params'] = $data['params'] ?? null;
        }
        $rData['roomId'] = $data['room_id'];
        $requestUrl = config('system.wxLive.roomShareCode');
        $res = $this->request($requestUrl, 'GET', $rData);
        return $res;
    }

    /**
     * @title  获取临时素材下载地址<未完成>
     * @param string $mediaId 临时素材媒体id
     * @return mixed
     */
    public function getTemporaryUrl(string $mediaId)
    {
        $rData['media_id'] = $mediaId;
        $requestUrl = config('system.material.getTemporaryUrl');
        $requestUrl .= '&' . http_build_query($rData);
        $res = $this->curlGetFile($requestUrl);
        return $res;
    }

    /**
     * @title  发起微信请求
     * @param string $requestUrl 请求url
     * @param string $method 请求方式 GET|POST
     * @param mixed $requestData 请求参数
     * @return mixed
     */
    public function request(string $requestUrl, string $method = 'POST', $requestData)
    {
        $accessToken = (new Wx())->getAccessToken();
        $requestUrl = sprintf($requestUrl, $accessToken);
        $res = [];
        if ($method == 'POST') {
            $res = $this->curlPost($requestUrl, $requestData);
        } elseif ($method == 'GET') {
            if (!empty($requestData)) {
                $requestUrl .= '&' . http_build_query($requestData);
            }
            $res = curl_get($requestUrl);
        }
        if (!empty($res)) {
            $res = json_decode($res, true);
        }
        $log['requestUrl'] = $requestUrl;
        $log['requestMethod'] = $method;
        $log['requestData'] = $requestData;
        $log['requestRes'] = $res ?? [];
        (new Log())->record($log);
        if (empty($res)) {
            throw new ServiceException(['msg' => '微信小程序直播服务端Api正在更新中,请先移步小程序直播控制台操作~']);
        }
        return $res;

    }

    public function curlPost($url, $data)
    {
        $headers = array("Content-type: application/json; charset=UTF-8", "accept: application/json");
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url); //定义请求地址
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST'); //定义请求类型
        curl_setopt($ch, CURLOPT_POST, true); //定义提交类型 1：POST ；0：GET
        curl_setopt($ch, CURLOPT_HEADER, 0); //定义是否显示状态头 1：显示 ； 0：不显示
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, FALSE);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, FALSE);
        //curl_setopt($ch, CURLOPT_HTTPHEADER, 1);//定义header
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);//定义是否直接输出返回流
        curl_setopt($ch, CURLOPT_POSTFIELDS, $data); //定义提交的数据
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        $res = curl_exec($ch);
        curl_close($ch);

        return $res;
    }

    public function curlGetFile(string $url)
    {
        $info = curl_init();
        curl_setopt($info, CURLOPT_URL, $url);
        curl_setopt($info, CURLOPT_HEADER, 1);
        curl_setopt($info, CURLOPT_SSL_VERIFYPEER, FALSE);
        curl_setopt($info, CURLOPT_SSL_VERIFYHOST, FALSE);
        curl_setopt($info, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($info, CURLOPT_TIMEOUT, 30); //超时时间30S
        curl_setopt($info, CURLOPT_RETURNTRANSFER, 1);//定义是否直接输出返回流
        $output = curl_exec($info);
        $httpinfo = curl_getinfo($info);
        $err = curl_error($info);
        curl_close($info);
        return ['header' => $httpinfo, 'body' => $output];
    }
}