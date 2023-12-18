<?php
// +----------------------------------------------------------------------
// |[ 文档说明: 课程模块model]
// +----------------------------------------------------------------------
// | Copyright (c) 2018~2024 http://www.mlhcmk.com All rights reserved.
// +----------------------------------------------------------------------


namespace app\lib\models;


use app\BaseModel;
use app\lib\exceptions\ParamException;
use app\lib\exceptions\ServiceException;
use Exception;
use think\facade\Db;
use app\lib\validates\Subject as SubjectValidate;

class Subject extends BaseModel
{
    protected $field = ['name', 'desc', 'cover_path', 'lecturer_id', 'detail_path', 'category_code', 'property_id', 'price', 'status', 'type', 'valid_start_time', 'valid_end_time', 'share_desc', 'thumbnail', 'link_goods'];

    protected $validateFields = ['name', 'lecturer_id', 'category_code'];
    private $belong = 1;

    /**
     * @title  课程列表
     * @param array $sear
     * @return array
     * @throws Exception
     */
    public function list(array $sear = []): array
    {
        $keyword = $sear['keyword'] ?? null;
        $cacheKey = false;
        $cacheExpire = 0;
        if (!empty($sear['category_code'])) {
            $map[] = ['a.category_code', '=', $sear['category_code']];
        }
        if (!empty($sear['lecturer_id'])) {
            $map[] = ['a.lecturer_id', '=', $sear['lecturer_id']];
        }
        if (!empty($sear['property_id'])) {
            $map[] = ['', 'exp', Db::raw('FIND_IN_SET("' . $sear['property_id'] . '",`a`.`property_id`)')];
        }

        //默认搜索正常课程
        if (!empty($sear['subject_type'])) {
            $map[] = ['a.type', '=', intval($sear['subject_type'])];
        } else {
            $map[] = ['a.type', '=', 1];
        }
        if ($this->module == 'api') {
            if (!empty($sear['subject_type']) && $sear['subject_type'] == 2) {
                $map[] = ['a.valid_start_time', '<=', time()];
                $map[] = ['a.valid_end_time', '>=', time()];
            }

            if (!empty($sear['cache'])) {
                $cacheKey = $sear['cache'];
                $cacheExpire = $sear['cache_expire'];
            }
        }

        $map[] = ['a.status', 'in', $this->getStatusByRequestModule($sear['searType'] ?? 1)];
        if (!empty($sear['pageNumber'])) {
            $this->pageNumber = intval($sear['pageNumber']);
        }
        $field = $this->getListFieldByModule();

        $page = intval($sear['page'] ?? 0) ?: null;
        if (!empty($page)) {
            $aTotal = Db::name('subject')->alias('a')
                ->join('sp_category b', 'a.category_code = b.code', 'left')
                ->join('sp_subject_property c', 'a.property_id = c.id', 'left')
                ->join('sp_lecturer d', 'a.lecturer_id = d.id', 'left')
                ->where($map)
                ->when($keyword ?? null, function ($query) use ($keyword) {
                    $subjectSql = $this->getFuzzySearSql('a.name', $keyword);
                    $lecturerSql = $this->getFuzzySearSql('d.name', $keyword);
                    $query->whereRaw('(' . $subjectSql . ' OR ' . $lecturerSql . ')');
                })
                ->group('a.id')
                ->when($cacheKey, function ($query) use ($cacheKey, $cacheExpire) {
                    $query->cache($cacheKey . 'Num', $cacheExpire);
                })
                ->count();

            $pageTotal = ceil($aTotal / $this->pageNumber);
        }

        $aList = Db::name('subject')->alias('a')
            ->join('sp_category b', 'a.category_code = b.code', 'left')
            ->join('sp_subject_property c', 'a.property_id = c.id', 'left')
            ->join('sp_lecturer d', 'a.lecturer_id = d.id', 'left')
            ->where($map)
            ->when($keyword ?? null, function ($query) use ($keyword) {
                $subjectSql = $this->getFuzzySearSql('a.name', $keyword);
                $lecturerSql = $this->getFuzzySearSql('d.name', $keyword);
                $query->whereRaw('(' . $subjectSql . ' OR ' . $lecturerSql . ')');
            })
            ->field($field)
            ->order('a.create_time desc')->group('a.id')->buildSql();

        //子查询统计章节数量和学习次数
        $list = Db::table($aList . ' a')
            ->join('sp_chapter b', 'a.id = b.subject_id and b.status = 1', 'left')
            ->join('sp_subject_progress c', 'a.id = c.subject_id and c.status = 1', 'left')
            ->field('a.*,count(distinct b.id) as chapter_count,count(distinct c.id) as learn_count')
            ->group('a.id')
            ->when($page, function ($query) use ($page) {
                $query->page($page, $this->pageNumber);
            })->order('a.create_time desc')
            ->when($cacheKey, function ($query) use ($cacheKey, $cacheExpire) {
                $query->cache($cacheKey, $cacheExpire);
            })
            ->select()->each(function ($item, $key) {
                if (!empty($item['create_time'])) $item['create_time'] = date('Y-m-d H:i:s', $item['create_time']);
                if (!empty($item['valid_start_time'])) $item['valid_start_time'] = date('Y-m-d H:i:s', $item['valid_start_time']);
                if (!empty($item['valid_end_time'])) $item['valid_end_time'] = date('Y-m-d H:i:s', $item['valid_end_time']);
                return $item;
            })->toArray();

        return ['list' => $list, 'pageTotal' => $pageTotal ?? 0];
    }


    /**
     * @title  课程详情
     * @param array $data
     * @return mixed
     * @throws Exception
     */
    public function info(array $data)
    {
        $id = $data['id'];
        $info = $this->with(['lecturer', 'chapter', 'property'])->field('id,name,desc,cover_path,detail_path,price,lecturer_id,category_code,property_id,status,create_time,type,valid_start_time,valid_end_time,share_desc,thumbnail,link_goods')->withCount(['chapter'])->where([$this->getPk() => $id, 'status' => $this->getStatusByRequestModule()])->findOrEmpty()->toArray();
        if (!empty($info)) {
            $aLinkGoods = [];
            $allCategory = explode(',', $info['category_code']);
            $aCategory = Category::where(['code' => $allCategory, 'status' => 1])->column('name');
            if (!empty($info['link_goods'])) {
                $allGoods = explode(',', $info['link_goods']);
                $aLinkGoods = GoodsSku::where(['sku_sn' => $allGoods, 'status' => 1])->field('goods_sn,sku_sn,title,image,sale_price,member_price')->select()->toArray();
            }
            $info['all_link_goods'] = $aLinkGoods;
            $info['category_name'] = implode(',', $aCategory);
            if ($this->module == 'api') {
                $info['goods_sn'] = null;
                $info['sku_sn'] = null;
                $aGoodsInfo = (new GoodsSpu())->getGoodsInfoBySubjectId($info['id'], 1);
                if (!empty($aGoodsInfo)) {
                    $info['goods_sn'] = $aGoodsInfo[0]['goods_sn'];
                    $info['sku_sn'] = $aGoodsInfo[0]['sku_sn'];
                }

                $goods[0]['goods_sn'] = $info['goods_sn'];
                $goods[0]['sku_sn'] = $info['sku_sn'];
                //检查商品是否存在未付款或已购买的
                $info['is_buy'] = 2;
                $info['is_member'] = 2;
                $info['is_collection'] = -1;
                if (!empty($data['uid'])) {
                    $history = (new Order())->checkBuyHistory($data['uid'], $goods, [2]);
                    $lecturerUser = Lecturer::where(['link_uid' => $data['uid'], 'status' => 1])->value('id');
                    //已购买或者讲师绑定人则视为已经购买,可以观看
                    if (!empty($history) || ($lecturerUser == $info['lecturer_id'])) {
                        $info['is_buy'] = 1;
                    }
                    //检查用户是否是会员
                    $userMember = (new Member())->getUserLevel($data['uid']);
                    if (!empty($userMember)) {
                        $info['is_member'] = 1;
                    }
                    //检查是否收藏
                    $collection = (new Collection())->info(['uid' => $data['uid'], 'subject_id' => $data['id']]);
                    if (!empty($collection)) {
                        $info['is_collection'] = 1;
                    }
                }

            }
        }
        return $info;
    }

    /**
     * @title  新增
     * @param array $data
     * @return mixed
     */
    public function new(array $data)
    {
        $data['name'] = trim($data['name']);
        (new SubjectValidate())->goCheck($data, 'create');
        $res = Db::transaction(function () use ($data) {
            $data['status'] = 2;
            $subjectId = $this->validate()->baseCreate($data, true);
            //收费课程才添加SPU
            if ($data['type'] == 1) {
                $addGods['title'] = $data['name'];
                $addGods['desc'] = $data['desc'];
                $addGods['main_image'] = $data['cover_path'];
                $addGods['category_code'] = $data['category_code'];
                $addGods['brand_code'] = $data['brand_code'] ?? "0000";
                $addGods['link_product_id'] = $subjectId;
                $addGods['attribute_list'] = null;
                $addGods['saleable'] = 2;
                $addGods['unit'] = '套';
                $addGods['belong'] = $this->belong;
                $addGods['status'] = 2;
                $goodsSn = (new GoodsSpu())->new($addGods);
            }

            return $subjectId;
        });

        return $res;
    }

    /**
     * @title  编辑
     * @param array $data
     * @return Subject
     */
    public function edit(array $data)
    {
        $data['name'] = trim($data['name']);
        (new SubjectValidate())->goCheck($data, 'edit');
        $res = Db::transaction(function () use ($data) {
            $editRes = $this->validate()->baseUpdate([$this->getPk() => $data[$this->getPk()]], $data);
            if ($data['type'] == 1) {
                //修改对应的整个课程的SKU的价格及SPU属性
                //(new GoodsSku())->updateSkuBySubjectId($data);
            }

            return $editRes;
        });
        return $res;
    }

    /**
     * @title  删除课程
     * @param int $id
     * @return Subject
     */
    public function del(int $id)
    {
        $res = Db::transaction(function () use ($id) {
            $delRes = $this->baseDelete([$this->getPk() => $id]);
            //删除全部章节
            (new Chapter())->baseDelete(['subject_id' => $id]);
            //删除推荐课程
            (new SubjectRecommend())->baseDelete(['subject_id' => $id]);
            $goodsSpu = GoodsSpu::where(['link_product_id' => $id])->value('goods_sn');
            if (!empty($goodsSpu)) {
                //删除SPU
                (new GoodsSpu())->baseDelete(['link_product_id' => $id]);
                //删除SKU
                (new GoodsSku())->baseDelete(['goods_sn' => $goodsSpu]);
                //删除SKU_vdc
                (new GoodsSkuVdc())->baseDelete(['goods_sn' => $goodsSpu]);
            }
            return $delRes;
        });
        return $res;
    }

    /**
     * @title  上下架
     * @param array $data
     * @return Subject|bool
     */
    public function upOrDown(array $data)
    {
        if ($data['status'] == 1) {
            $save['status'] = 2;
        } elseif ($data['status'] == 2) {
            $chapterNum = Chapter::where(['subject_id' => $data[$this->getPk()], 'status' => 1])->count();
            if (empty($chapterNum)) {
                throw new ServiceException(['msg' => '没有章节的课程暂不允许上架哦']);
            }
            $save['status'] = 1;
        } else {
            return false;
        }
        return $this->baseUpdate([$this->getPk() => $data[$this->getPk()]], $save);
    }

    /**
     * @title  课程数量汇总
     * @param array $sear
     * @return int
     */
    public function total(array $sear = []): int
    {
        if (!empty($sear['start_time'])) {
            $map[] = ['create_time', '>=', strtotime($sear['start_time'] . ' 00:00:00')];
        }
        if (!empty($sear['end_time'])) {
            $map[] = ['create_time', '<=', strtotime($sear['end_time'] . ' 23:59:59')];
        }
        $map[] = ['status', 'in', [1]];
        $info = $this->where($map)->count();
        return $info;
    }

    /**
     * @title  bindSubjectLinkGoods
     * @param array $data
     * @return Subject
     */
    public function bindSubjectLinkGoods(array $data)
    {
        $this->checkSubjectStatus($data['subject_id']);
        $subjectId = $data['subject_id'];
        $save['link_goods'] = $data['link_goods'];
        return $this->baseUpdate([$this->getPk() => $subjectId], $save);
    }

    /**
     * @title  检查课程状态
     * @param string $subjectId 课程id
     * @return int
     */
    public function checkSubjectStatus(string $subjectId)
    {
        $subjectInfo = $this->where([$this->getPk() => $subjectId, 'status' => 1])->count();
        if (empty($subjectInfo)) {
            throw new ParamException(['msg' => '该课程暂不公开']);
        }
        return $subjectInfo;
    }


    public function chapter()
    {
        return $this->hasMany('Chapter', 'subject_id', $this->getPk())->field('id,subject_id,name,desc,type,content,sort,price,create_time')->order('sort asc')->where(['status' => [1]]);
    }

    public function lecturer()
    {
        return $this->hasOne('Lecturer', 'id', 'lecturer_id')->bind(['lecturer_name' => 'name']);
    }

    public function lecturerForOrder()
    {
        return $this->hasOne('Lecturer', 'id', 'lecturer_id')->bind(['divide', 'link_uid', 'lecturer_name' => 'name']);
    }

    public function category()
    {
        return $this->hasOne('Category', 'code', 'category_code')->bind(['category_name' => 'name']);
    }

    public function property()
    {
        return $this->hasOne('SubjectProperty', 'id', 'property_id')->bind(['property_name' => 'name']);
    }


    private function getListFieldByModule()
    {
        switch ($this->module) {
            case 'admin':
            case 'manager':
                $field = 'a.id,a.name,a.cover_path,a.lecturer_id,a.category_code,a.property_id,a.price,a.status,a.type,a.valid_start_time,a.valid_end_time,a.create_time,a.link_goods,b.name as category_name,c.name as property_name,d.name as lecturer_name';
                break;
            case 'api':
                $field = 'a.id,a.name,a.desc,a.cover_path,a.thumbnail,a.lecturer_id,a.category_code,a.property_id,a.price,a.create_time,a.type,a.valid_start_time,a.valid_end_time,b.name as category_name,c.name as property_name,d.name as lecturer_name,d.background as lecturer_background,d.avatar_path';
                break;
            default:
                $field = 'a.*';
        }
        return $field;
    }


}