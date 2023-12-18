<?php
// +----------------------------------------------------------------------
// |[ 文档说明: 课程章节Model]
// +----------------------------------------------------------------------
// | Copyright (c) 2018~2024 http://www.mlhcmk.com All rights reserved.
// +----------------------------------------------------------------------


namespace app\lib\models;


use app\BaseModel;
use Exception;
use think\facade\Db;

class Chapter extends BaseModel
{
    protected $field = ['subject_id', 'name', 'price', 'desc', 'type', 'content', 'sort', 'status', 'source_id'];
    protected $validateFields = ['subject_id', 'name'];
    private $belong = 1;

    /**
     * @title  章节列表
     * @param array $sear
     * @return array
     * @throws Exception
     */
    public function list(array $sear = []): array
    {
        if (!empty($sear['keyword'])) {
            $map[] = ['', 'exp', Db::raw($this->getFuzzySearSql('name', $sear['keyword']))];
        }
        $map[] = ['status', 'in', $this->getStatusByRequestModule($sear['searType'] ?? 1)];
        $map[] = ['subject_id', '=', $sear['subject_id']];
        $page = intval($sear['page'] ?? 0) ?: null;
        if (!empty($page)) {
            $aTotal = $this->where($map)->count();
            $pageTotal = ceil($aTotal / $this->pageNumber);
        }
        $field = $this->getListFieldByModule();

        $list = $this->with(['subject', 'file'])->where($map)->field($field)->when($page, function ($query) use ($page) {
            $query->page($page, $this->pageNumber);
        })->order('sort asc,create_time desc')->select()->toArray();

        return ['list' => $list, 'pageTotal' => $pageTotal ?? 0];
    }

    /**
     * @title  章节详情
     * @param array $data
     * @return mixed
     * @throws Exception
     */
    public function info(array $data)
    {
        $id = $data['id'];
        $info = $this->with(['subject', 'file'])->where([$this->getPk() => $id, 'status' => [1]])->field('id,subject_id,name,desc,type,content,price,create_time')->findOrEmpty()->toArray();
        if (!empty($info)) {
            $info['is_buy'] = 2;
            $info['learn_number'] = SubjectProgress::where(['subject_id' => $info['subject_id'], 'chapter_id' => $id, 'status' => [1, 2]])->count();
            if (!empty($data['uid'])) {
                $goodsSn = GoodsSpu::where(['link_product_id' => $info['subject_id'], 'status' => [1, 2]])->value('goods_sn');
                $chapterSku = GoodsSku::where(['goods_sn' => $goodsSn, 'status' => [1, 2]])->where(function ($query) use ($id) {
                    $mapOr = ['', 'exp', Db::raw('(content REGEXP \'[^0-9.]\') = 1')];
                    $query->where(['content' => $id])->whereOr([$mapOr]);
                })->field('goods_sn,sku_sn')->select()->toArray();

                $history = (new Order())->checkBuyHistory($data['uid'], $chapterSku, [2]);
                $lecturerUser = Lecturer::where(['link_uid' => $data['uid'], 'status' => 1])->value('id');
                if ($info['subject_type'] != 2) {
                    //已购买或者讲师绑定人则视为已经购买,可以观看
                    if (!empty($history) || ($lecturerUser == $info['lecturer_id'])) {
                        $info['is_buy'] = 1;
                    } else {
                        $info['content'] = '暂无权限观看视频';
                    }
                }
                if ($info['subject_type'] == 2) {
                    if ($info['valid_start_time'] >= time() || $info['valid_end_time'] <= time()) {
                        $info['content'] = '限时免费课程已经失效咯,不能观看啦';
                    }
                }
            }
        }
        return $info;
    }

    /**
     * @title  新增或编辑章节
     * @param array $data
     * @return mixed
     */
    public function newOrEdit(array $data)
    {
        $res = Db::transaction(function () use ($data) {
            $memberDis = (new MemberVdc())->where(['belong' => $this->belong, 'level' => 1, 'status' => 1])->value('discount');
            $aDefaultDis = (new MemberVdc())->where(['belong' => $this->belong, 'status' => 1])->field('level,discount,vdc_one,vdc_two')->select()->toArray();
            $chapterRes = false;
            $subjectId = $data['subject_id'];
            $chapter = $data['chapter'];
            $aGoodsInfo = GoodsSpu::where(['belong' => 2, 'link_product_id' => $subjectId, 'status' => [1, 2]])->field('main_image,goods_sn')->findOrEmpty()->toArray();
            $goods_sn = $aGoodsInfo['goods_sn'] ?? null;
            $goodsMainImage = $aGoodsInfo['main_image'] ?? null;
            $aSubjectInfo = Subject::where(['id' => $subjectId])->field('name,cover_path,desc,price')->findOrEmpty()->toArray();
            //查看已经转码成功的视频
            $transcode = [];
            $sourceIds = array_column($chapter, 'source_id');
            if (!empty($sourceIds)) {
                $fileMap[] = ['type', '=', 2];
                $fileMap[] = ['source_id', 'in', $sourceIds];
                $fileMap[] = ['', 'exp', Db::raw('source_url is not null')];
                $fileMap[] = ['status', '=', 1];
                $transcode = File::where($fileMap)->column('source_url', 'source_id');
            }
            $chapters = [];
            if (!empty($chapter)) {
                foreach ($chapter as $key => $value) {
                    if (!empty($transcode[$value['source_id']])) {
                        $value['content'] = $transcode[$value['source_id']];
                        $value['status'] = 1;
                    } else {
                        $value['status'] = 3;
                    }
                    $chapterRes = $this->updateOrCreate([$this->getPk() => $value[$this->getPk()]], $value);
                    $chapters[$key] = $chapterRes->getData();
                }

                if (!empty($aGoodsInfo)) {

                    $skuChapter = $chapters;
                    $totalPrice = 0.00;
                    $memberPrice = 0.00;
                    foreach ($skuChapter as $key => $value) {
                        $skuChapter[$key]['goods_sn'] = $goods_sn;
                        $skuChapter[$key]['title'] = $value['name'];
                        $skuChapter[$key]['content'] = intval($value[$this->getPk()]);
                        $skuChapter[$key]['image'] = $value['image'] ?? $goodsMainImage;
                        $skuChapter[$key]['market_price'] = $value['price'];
                        $skuChapter[$key]['sale_price'] = $value['price'];
                        $skuChapter[$key]['member_price'] = priceFormat($value['price'] * $memberDis);
                        $skuChapter[$key]['cost_price'] = $value['price'];
                        $skuChapter[$key]['sort'] = $value['sort'];
                        $skuChapter[$key]['fare'] = 0.00;
                        $skuChapter[$key]['free_shipping'] = 1;
                        $skuChapter[$key]['stock'] = 99999999;
                        $skuChapter[$key]['attr'] = ['部分章节' => $value['name']];
                        $skuChapter[$key]['vdc'] = $value['vdc'];
                        $skuChapter[$key]['status'] = $value['status'] == 1 ? 1 : 2;
                        $totalPrice += $skuChapter[$key]['sale_price'];
                        $memberPrice += $skuChapter[$key]['member_price'];
                    }
                    if (!empty($aSubjectInfo['price'])) {
                        $totalPrice = priceFormat($aSubjectInfo['price']);
                        $memberPrice = priceFormat($totalPrice * $memberDis);
                    }
                    //添加多一个全部章节的SKU
                    $nChapter = count($skuChapter);
                    $skuChapter[$nChapter]['goods_sn'] = $goods_sn;
                    $skuChapter[$nChapter]['title'] = $aSubjectInfo['name'];
                    $skuChapter[$nChapter]['image'] = $aSubjectInfo['cover_path'] ?? null;
                    $skuChapter[$nChapter]['content'] = $aSubjectInfo['desc'] . ' ';
                    $skuChapter[$nChapter]['market_price'] = $totalPrice;
                    $skuChapter[$nChapter]['sale_price'] = $totalPrice;
                    $skuChapter[$nChapter]['member_price'] = $memberPrice;
                    $skuChapter[$nChapter]['cost_price'] = $totalPrice;
                    $skuChapter[$nChapter]['fare'] = 0.00;
                    $skuChapter[$nChapter]['free_shipping'] = 1;
                    $skuChapter[$nChapter]['stock'] = 99999999;
                    $skuChapter[$nChapter]['attr'] = ['全部课程' => $aSubjectInfo['name']];
                    $skuChapter[$nChapter]['vdc'] = $aDefaultDis;
                    $skuChapter[$nChapter]['sort'] = 999;

                    $allData = ['sku' => $skuChapter, 'goods_sn' => $goods_sn];
                    $res = (new GoodsSku())->newOrEdit($allData);
                }

            }
            return $chapterRes;
        });
        return $res;

    }

    /**
     * @title  删除章节
     * @param int $id
     * @return Chapter
     */
    public function del(int $id)
    {
        $res = Db::transaction(function () use ($id) {
            $delRes = $this->baseDelete([$this->getPk() => $id]);
            //删除对应的SKU
            $skuRes = (new GoodsSku())->baseDelete(['content' => $id]);
            return $delRes;
        });
        return $res;

    }

    public function subject()
    {
        return $this->hasOne('Subject', 'id', 'subject_id')->bind(['subject_name' => 'name', 'subject_type' => 'type', 'valid_start_time', 'valid_end_time', 'subject_desc' => 'desc', 'subject_cover_path' => 'cover_path', 'lecturer_id', 'subject_share_desc' => 'share_desc']);
    }

    public function file()
    {
        return $this->hasOne('File', 'source_url', 'content')->bind(['cover_url']);
    }

    public function getListFieldByModule()
    {
        switch ($this->module) {
            case 'admin':
                $field = 'id,name,subject_id,desc,type,sort,create_time,content,status,source_id,update_time,create_time';
                break;
            case 'api':
                $field = 'id,name,subject_id,desc,type,sort,create_time';
                break;
            default:
                $field = 'a.*';
        }
        return $field;
    }


}