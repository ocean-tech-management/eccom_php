<?php
// +----------------------------------------------------------------------
// |[ 文档说明: 编码生成器Service]
// +----------------------------------------------------------------------
// | Copyright (c) 2018~2024 http://www.mlhcmk.com All rights reserved.
// +----------------------------------------------------------------------


namespace app\lib\services;


use app\lib\exceptions\CouponException;
use app\lib\exceptions\ServiceException;
use app\lib\models\AttributeKey;
use app\lib\models\Brand;
use app\lib\models\Category;
use app\lib\models\GoodsSku;
use app\lib\models\GoodsSpu;
use app\lib\models\Coupon;
use app\lib\models\Member;
use app\lib\models\TeamMember;
use app\lib\models\AreaMember;
use app\lib\models\OpenAccount;
use app\lib\models\Supplier;
use think\facade\Db;

class CodeBuilder
{
    /**
     * @title  生成SPU码
     * @param string $categoryCode 分类编码
     * @param string $brandCode 品牌编码
     * @return string
     * @remark 生成规则:共计13位=四位分类编码(4)+四位品牌编码(4)+毫秒小数点后前五位(5)
     *         若出现重复则递归重新生成
     */
    public function buildSpuCode(string $categoryCode, string $brandCode = '0000'): string
    {
        $newCode = sprintf("%04d", $categoryCode) . sprintf("%04d", $brandCode) . substr(microtime(), 2, 5);
        if (GoodsSpu::where(['goods_sn' => $newCode])->count() > 0) {
            $newCode = $this->buildSpuCode($categoryCode, $brandCode);
        }
        return $newCode;
    }

    /**
     * @title  生成SKU基础码
     * @param string $spuSn 商品SPU码
     * @return string
     * @remark 生成规则:共计8位=商品SPU码最后五位(5)+三位随机数(3)
     *         若出现重复则递归重新生成,生成后的SKU基础码在拼接上SKU数量(2)形成正式的10位的SKU码
     */
    public function buildSkuCode(string $spuSn)
    {
        $newCode = substr($spuSn, -5) . sprintf("%03d", mt_rand(1, 999));
        if (GoodsSku::whereRaw("LOCATE('" . $newCode . "', `sku_sn`) > 0 ")->count() > 0) {
            $newCode = $this->buildSkuCode($spuSn);
        }
        return $newCode;
    }


    /**
     * @title  生成优惠券唯一编码
     * @param array $data
     * @return string
     * @remark 生成规则:共计19位
     *         平台券:2位类型+2位场景+8位年月日+3位毫秒数+4位顺序数
     *     其他优惠券:2位类型+2位场景+4位最小一级分类编码+4位品牌编码+3位毫秒数+4位顺序数
     * 上限规则:同一使用场景的同一类型的优惠券同一天发券数量上限为9999张
     */
    public function buildCouponCode(array $data)
    {
        $coupon = (new Coupon());
        //同一使用场景的同一类型的优惠券同一天发券数量上限为9999张
        if ($coupon->where(['type' => $data['type'], 'used' => $data['used'], 'status' => [1, 2]])->whereDay('create_time')->count() >= 9999) {
            throw new CouponException(['errorCode' => 1200105]);
        }
        $count = $coupon->where(['type' => $data['type'], 'used' => $data['used']])->count();

        $count += 1;
        $rand = sprintf("%03d", substr(microtime(), 2, 3));
        if ($data['used'] == 10) {
            //共计19位,2位类型+2位场景+8位年月日+3位毫秒数+4位顺序数
            $code = sprintf("%02d", $data['used']) . sprintf("%02d", $data['type']) . date('Ymd', time()) . $rand . sprintf("%04d", $count);
        } else {
            if (empty($data['with_category'])) {
                $aCategory = ['0000'];
            } else {
                $aCategory = explode(',', $data['with_category']);
            }

            //共计19位,2位类型+2位场景+4位最小一级分类编码+4位品牌编码+3位毫秒数+4位顺序数
            $code = sprintf("%02d", $data['used']) . sprintf("%02d", $data['type']) . sprintf("%04d", end($aCategory)) . sprintf("%04d", $data['with_brand'] ?? "0000") . $rand . sprintf("%04d", $count);
        }
        //检查是否重复
        $code = $this->checkCouponExist($code);
        return $code;
    }

    /**
     * @title  检查优惠券编码是否重复
     * @param $code
     * @return mixed
     */
    public function checkCouponExist($code)
    {
        $exist = Coupon::where(['code' => $code])->count();
        if (!empty($exist)) {
            $newCode = $code + 1;
            $code = $this->checkCouponExist($newCode);
        }
        return $code;
    }

    /**
     * @title  生成分类编码
     * @param Category $model
     * @return string
     * @remark 生成规则:共计4位 根据全部分类数量加1生成,故分类编码上限为9999
     */
    public function buildCategoryCode(Category $model)
    {
        $count = $model->count();
        $code = sprintf("%04d", $count + 1);
        $code = $this->uniqueCategoryCode($code);
        return $code;
    }

    /**
     * @title  分类编码去重
     * @param string $code
     * @return string
     */
    public function uniqueCategoryCode(string $code)
    {
        $exist = (new Category())->where(['code' => $code])->count();
        if (!empty($exist)) {
            $code = $this->uniqueCategoryCode(sprintf("%04d", (intval($code) + 1)));
        }
        return $code;
    }

    /**
     * @title  生成品牌编码
     * @param Brand $model
     * @param string $brandName 品牌名称 以用来判断是否存在
     * @param string $categoryCode 分类编码
     * @return string
     * @remark 生成规则:共计4位 根据全部优惠券数量加1生成,故分类编码上限为9999
     */
    public function buildBrandCode(Brand $model, string $brandName, string $categoryCode)
    {
        $brandInfo = $model->where(['brand_name' => $brandName, 'category_code' => $categoryCode, 'status' => [1, 2]])->value('brand_code');
        if (!empty($brandInfo)) {
            return $brandInfo;
        } else {
            $count = $model->count();
            return sprintf("%06d", $count + 1);
        }
    }


    /**
     * @title  获取订单号
     * @return string
     * @remark 生成规则:共计18位=格式化时间日期(8)+时间戳秒(2)+毫秒小数点后前四位(4)+四位随机数(4),
     * (更好的方法可以选择雪花算法)
     */
    public function buildOrderNo()
    {
        return date('Ymd') . substr(time(), -2, 2) . substr(microtime(), 2, 4) . sprintf('%04d', rand(1, 9999));
    }

    /**
     * @title  获取用户优惠券编码
     * @return string
     * @remark 生成规则:共计10位=毫秒小数点后前四位(4)+六位随机数(6)
     */
    public function buildUserCouponCode()
    {
        return substr(microtime(), 2, 4) . sprintf('%06d', mt_rand(1, 999999));
    }

    /**
     * @title  获取会员卡号
     * @param int $type 类型 1为普通生成 2为指定生成
     * @return string
     * @remark 生成规则:共计8位=会员总数加一并格式化(8)
     */
    public function buildMemberNum(int $type = 1)
    {
        if ($type == 1) {
            $allMember = Member::count();
            $memberCard = sprintf("%010d", ($allMember + 1));
        } else {
            $map[] = ['member_card', 'like', 'DT%'];
            $allMember = Member::where($map)->count();
            $memberCard = 'DT' . sprintf("%04d", ($allMember + 1));
        }
        return $memberCard;
    }

    /**
     * @title  获取团队会员卡号
     * @param int $type 类型 1为普通生成 2为指定生成
     * @return string
     * @remark 生成规则:共计8位=会员总数加一并格式化(8)
     */
    public function buildTeamMemberNum(int $type = 1)
    {
        if ($type == 1) {
            $allMember = TeamMember::count();
            $memberCard = sprintf("%010d", ($allMember + 1));
        } else {
            $map[] = ['member_card', 'like', 'DT%'];
            $allMember = TeamMember::where($map)->count();
            $memberCard = 'DT' . sprintf("%04d", ($allMember + 1));
        }
        return $memberCard;
    }

    /**
     * @title  获取区代会员卡号
     * @param int $type 类型 1为普通生成 2为指定生成
     * @return string
     * @remark 生成规则:共计8位=会员总数加一并格式化(8)
     */
    public function buildAreaMemberNum(int $type = 1)
    {
        if ($type == 1) {
            $allMember = AreaMember::count();
            $memberCard = sprintf("%010d", ($allMember + 1));
        } else {
            $map[] = ['member_card', 'like', 'DT%'];
            $allMember = AreaMember::where($map)->count();
            $memberCard = 'AR' . sprintf("%04d", ($allMember + 1));
        }
        return $memberCard;
    }


    /**
     * @title  获取商品属性编码
     * @param string $categoryCode 分类编码
     * @return string
     * @remark 生成规则:共计10位=分类编码(5)+改分类下所有属性总数加一并格式化(5)，故一个分类下属性编码上限为99999
     */
    public function buildAttributeCode(string $categoryCode)
    {
        $existNum = AttributeKey::where(['category_code' => $categoryCode])->count();
        if (intval($existNum) >= 99999) {
            throw new ServiceException(['msg' => '该分类下的属性编码数量已到达最大上限,请删除部分或联系超级管理员']);
        }
        return $categoryCode . sprintf("%05d", ($existNum + 1));
    }

    /**
     * @title  获取团队奖励释放订单编号
     * @return string
     * @remark 生成规则:共计18位=格式化时间日期(8)+时间戳秒(2)+毫秒小数点后前四位(4)+四位随机数(4),
     * (更好的方法可以选择雪花算法)
     */
    public function buildRewardFreedSn()
    {
        return date('Ymd') . substr(time(), -2, 2) . substr(microtime(), 2, 4) . sprintf('%04d', rand(1, 9999));
    }

    /**
     * @title  获取售后服务编号
     * @return string
     * @remark 生成规则:共计19位= S售后标识(1位)+格式化时间日期(8)+时间戳秒(2)+毫秒小数点后前四位(4)+四位随机数(4),
     * (更好的方法可以选择雪花算法)
     */
    public function buildAfterSaleSn()
    {
        return 'S' . date('Ymd') . substr(time(), -2, 2) . substr(microtime(), 2, 4) . sprintf('%04d', rand(1, 9999));
    }

    /**
     * @title  获取退款编号
     * @return string
     * @remark 生成规则:共计19位= R退款标识(1位)+格式化时间日期(8)+时间戳秒(2)+毫秒小数点后前四位(4)+四位随机数(4),
     * (更好的方法可以选择雪花算法)
     */
    public function buildRefundSn()
    {
        return 'R' . date('Ymd') . substr(time(), -2, 2) . substr(microtime(), 2, 4) . sprintf('%04d', rand(1, 9999));
    }

    /**
     * @title  获取活动编号
     * @return string
     * @remark 生成规则:共计18位=格式化时间日期(8)+时间戳秒(2)+毫秒小数点后前四位(4)+四位随机数(4),
     * (更好的方法可以选择雪花算法)
     */
    public function buildActivityCode()
    {
        return date('Ymd') . substr(time(), -2, 2) . substr(microtime(), 2, 4) . sprintf('%04d', rand(1, 9999));
    }

    /**
     * @title  获取子订单号
     * @param string $parentOrderSn 父订单号
     * @return string
     * @remark 生成规则:共计18位=父订单号后八位(8)+时间戳秒(2)+毫秒小数点后前四位(4)+四位随机数(4),
     * (更好的方法可以选择雪花算法)
     */
    public function buildOrderChileNo(string $parentOrderSn)
    {
        return substr($parentOrderSn, -8) . substr(time(), -2, 2) . substr(microtime(), 2, 4) . sprintf('%04d', rand(1, 9999));
    }

    /**
     * @title  获取购物车编号
     * @return string
     * @remark 生成规则:共计18位=格式化时间日期(8)+时间戳秒(2)+毫秒小数点后前四位(4)+四位随机数(4),
     * (更好的方法可以选择雪花算法)
     */
    public function buildShipCartSn()
    {
        return date('Ymd') . substr(time(), -2, 2) . substr(microtime(), 2, 4) . sprintf('%04d', rand(1, 9999));
    }

    /**
     * @title  获取提现编号
     * @return string
     * @remark 生成规则:共计18位=格式化时间日期(8)+时间戳秒(2)+毫秒小数点后前四位(4)+四位随机数(4),
     * (更好的方法可以选择雪花算法)
     */
    public function buildWithdrawOrderNo()
    {
        return 'W' . date('Ymd') . substr(time(), -2, 2) . substr(microtime(), 2, 4) . sprintf('%03d', rand(1, 9999));
    }

    /**
     * @title  获取拼团活动订单团号
     * @return string
     * @remark 生成规则:共计18位=英文P(1)+格式化时间日期(8)+时间戳秒(2)+毫秒小数点后前四位(4)+四位随机数(3),
     * (更好的方法可以选择雪花算法)
     */
    public function buildPtActivityCode()
    {
        return 'P' . date('Ymd') . substr(time(), -2, 2) . substr(microtime(), 2, 4) . sprintf('%03d', rand(1, 9999));
    }

    /**
     * @title  获取拼拼有礼活动订单团号
     * @return string
     * @remark 生成规则:共计19位=英文PP(1)+格式化时间日期(8)+时间戳秒(2)+毫秒小数点后前四位(4)+四位随机数(3),
     * (更好的方法可以选择雪花算法)
     */
    public function buildPpylActivityCode()
    {
        return 'PL' . date('Ymd') . substr(time(), -2, 2) . substr(microtime(), 2, 4) . sprintf('%03d', rand(1, 9999));
    }

    /**
     * @title  获取运费模版编码
     * @return string
     * @remark 生成规则:共计10位 = 毫秒小数点后前四位(4)+六位随机数(6)
     */
    public function buildPostageCode()
    {
        return substr(microtime(), 2, 4) . sprintf('%06d', mt_rand(1, 999999));
    }

    /**
     * @title  获取供应商编码
     * @param Supplier $model
     * @return string
     * @remark 生成规则:共计6位 = 顺序位(6)
     */
    public function buildSupplierCode(Supplier $model)
    {
        $count = $model->count();
        $code = sprintf("%06d", $count + 1);
        $code = $this->uniqueSupplierCode($code);
        return $code;
    }

    /**
     * @title  供应商编码去重
     * @param string $supplierCode
     * @return string
     */
    public function uniqueSupplierCode(string $supplierCode)
    {
        $exist = (new Supplier())->where(['supplier_code' => $supplierCode])->count();
        if (!empty($exist)) {
            $supplierCode = $this->uniqueSupplierCode(sprintf("%06d", (intval($supplierCode) + 1)));
        }
        return $supplierCode;
    }

    /**
     * @title  获取会员激励机制发放奖励明细编号
     * @return string
     * @remark 生成规则:共计18位=英文P(1)+格式化时间日期(8)+时间戳秒(2)+毫秒小数点后前四位(4)+四位随机数(3)
     * (更好的方法可以选择雪花算法)
     */
    public function buildIncentivesCode()
    {
        return 'I' . date('Ymd') . substr(time(), -2, 2) . substr(microtime(), 2, 4) . sprintf('%03d', rand(1, 9999));
    }

    /**
     * @title  获取礼品批次编号
     * @return string
     * @remark 生成规则:共计18位=英文GB(2)+格式化时间日期(8)+时间戳秒(2)+毫秒小数点后前四位(4)+两位随机数(2)
     */
    public function buildGiftBatchSn()
    {
        return 'GB' . date('Ymd') . substr(time(), -2, 2) . substr(microtime(), 2, 4) . sprintf('%02d', rand(1, 99));
    }

    /**
     * @title  获取礼品规格编号
     * @return string
     * @remark 生成规则:共计10位=英文GA(2)+时间戳秒(2)+毫秒小数点后前四位(4)+两位随机数(2)
     */
    public function buildGiftAttrSn()
    {
        return 'GA' . date('Ymd') . substr(time(), -2, 2) . substr(microtime(), 2, 4) . sprintf('%02d', rand(1, 99));
    }

    /**
     * @title  获取礼品卡编号(唯一标识)
     * @param string $batchSn
     * @return string
     * @remark 生成规则:共计16位=英文G(1)+礼品批次编号后五位(5)+时间戳秒(2)+毫秒小数点后前四位(4)+四位随机数(4)
     */
    public function buildGiftCardSn(string $batchSn)
    {
        return 'G' . substr($batchSn, -5) . substr(time(), -2, 2) . substr(microtime(), 2, 4) . sprintf('%04d', rand(1, 9999));
    }

    /**
     * @title  获取礼品卡卡号(兑换时用)
     * @param string $prefix
     * @return string
     * @remark 生成规则:8~12位=英文前缀(0~4)+时间戳秒(2)+毫秒小数点后前四位(4)+四位随机数(4)
     */
    public function buildGiftCardConvertSn(string $prefix = '')
    {
        $pre = !empty($prefix) ? (strlen($prefix) > 4 ? substr($prefix, 0, 4) : $prefix) : '';
        return !empty($pre) ? $pre : '' . substr(time(), -2, 2) . substr(microtime(), 2, 4) . sprintf('%04d', rand(1, 9999));
    }

    /**
     * @title  获取卡密
     * @param int $number
     * @return string
     */
    public function buildCardPassword(int $number = 12)
    {

        $code = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ';

        $rand = $code[rand(0, 25)]

            . strtoupper(dechex(date('m')))

            . date('d') . substr(time(), -5)

            . substr(microtime(), 2, 6)

            . sprintf('%04d', rand(0, 9999));

        //根据md5后的二进制重新生成一个字符串,如需保证去重率请修改$rand的生成方式
        $randMD5 = md5($rand, true);
        if ($number > (strlen($randMD5) - 1)) {
            throw new ServiceException(['msg' => '最大长度为' . (strlen($randMD5) - 1)]);
        }

        for (

            $a = $randMD5,

            $s = '0123456789ABCDEFGHIJKLMNOPQRSTUV',

            $d = '',

            $f = 0;

            $f < $number;

            $f++

        ) {
            $g = ord($a[$f]); // ord（）函数获取首字母的 的 ASCII值
            //用按位异或的值 减去 按位与的值 获取最终的下标
            $d .= $s[($g ^ ord($a[$f + (strlen($a) - $number)])) - $g & 0x1F]; //按位异或，按位与。
        }

        return $d;
    }

    /**
     * @title  生成开放平台开发者appId
     * @return string
     * @remark 生成规则:8位=固定三位(2)+毫秒小数点后前四位(4)+两位随机数(2)
     */
    public function buildOpenDeveloperAppId()
    {
        $appId = '10' . substr(microtime(), 2, 4) . sprintf('%02d', rand(1, 99));
        $exist = OpenAccount::where(['appId' => $appId])->count();
        if (!empty($exist)) {
            $appId = $this->buildOpenDeveloperAppId();
        }
        return $appId;
    }

    /**
     * @title  生成开放平台开发者SecretKey
     * @param string $appId 开发者appId
     * @return string
     */
    public function buildOpenDeveloperSecretKey(string $appId)
    {
        return (md5($appId . md5(time() . config('system.token.systemName'))));
    }

    /**
     * @title  获取口碑评论官编号
     * @return string
     * @remark 生成规则:共计10位=英文RV(2)+时间戳秒(2)+毫秒小数点后前四位(4)+两位随机数(2)
     */
    public function buildReputationUserCode()
    {
        return 'RV' . date('Ymd') . substr(time(), -2, 2) . substr(microtime(), 2, 4) . sprintf('%02d', rand(1, 99));
    }

    /**
     * @title  生成商品参数模版编码
     * @return string
     * @remark 生成规则:共计10位 = 毫秒小数点后前四位(4)+六位随机数(6)
     */
    public function buildParamCode()
    {
        return substr(microtime(), 2, 4) . sprintf('%06d', mt_rand(1, 999999));
    }

    /**
     * @title  生成前端动态参数缓存键名
     * @param int $length 长度
     * @return string
     */
    public function buildDynamicParamsCacheKey(int $length = 7)
    {
        //生成一个包含 大写英文字母, 小写英文字母, 数字 的数组
        $arr = array_merge(range(0, 9), range('a', 'z'), range('A', 'Z'));
        $str = '';
        $arr_len = count($arr);
        for ($i = 0; $i < $length; $i++) {
            $rand = mt_rand(0, $arr_len - 1);
            $str .= $arr[$rand];
        }

        return $str;
    }

    /**
     * @title  获取售后信息编码
     * @return string
     * @remark 生成规则:共计10位 = 毫秒小数点后前四位(6)+六位随机数(4)
     */
    public function buildAfterSaleMsgCode()
    {
        return substr(microtime(), 2, 6) . sprintf('%04d', mt_rand(1, 9999));
    }

    /**
     * @title  获取拼拼有礼专场编码
     * @return string
     * @remark 生成规则:共计10位 = 毫秒小数点后前四位(4)+六位随机数(6)
     */
    public function buildAreaCode()
    {
        return substr(microtime(), 2, 4) . sprintf('%06d', mt_rand(1, 999999));
    }

    /**
     * @title  获取自动计划遍号
     * @return string
     * @remark 生成规则:共计19位 = 固定标识A(1)+格式化时间日期(8)+时间戳秒(2)+毫秒小数点后前四位(4)+四位随机数(4),
     * (更好的方法可以选择雪花算法)
     */
    public function buildPlanNo()
    {
        return 'A' . date('Ymd') . substr(time(), -2, 2) . substr(microtime(), 2, 4) . sprintf('%04d', rand(1, 9999));
    }

    /**
     * @title  获取CVIP价格梯度编码
     * @return string
     * @remark 生成规则:共计10位 = 毫秒小数点后前四位(6)+六位随机数(4)
     */
    public function buildCVIPGradientSn()
    {
        return substr(microtime(), 2, 6) . sprintf('%04d', mt_rand(1, 9999));
    }

    /**
     * @title  获取订单奖励编号
     * @return string
     * @remark 生成规则:共计20位 = 固定标识RE(1)+格式化时间日期(8)+时间戳秒(2)+毫秒小数点后前四位(4)+四位随机数(4),
     * (更好的方法可以选择雪花算法)
     */
    public function buildRewardSn()
    {
        return 'RE' . date('Ymd') . substr(time(), -2, 2) . substr(microtime(), 2, 4) . sprintf('%04d', rand(1, 9999));
    }

    /**
     * @title  获取广宣奖计划编码
     * @return string
     * @remark 生成规则:共计10位 = 毫秒小数点后前四位(4)+六位随机数(6)
     */
    public function buildPropagandaRewardPlanCode()
    {
        return substr(microtime(), 2, 4) . sprintf('%06d', mt_rand(1, 999999));
    }

    /**
     * @title  获取套餐赠送编码
     * @return string
     * @remark 生成规则:共计10位 = 毫秒小数点后前四位(4)+六位随机数(6)
     */
    public function buildHandselSnCode()
    {
        return substr(microtime(), 2, 4) . sprintf('%06d', mt_rand(1, 999999));
    }

    /**
     * @title  获取众筹活动订单编号
     * @return string
     * @remark 生成规则:共计18位=英文C(1)+格式化时间日期(8)+时间戳秒(2)+毫秒小数点后前四位(4)+四位随机数(3),
     * (更好的方法可以选择雪花算法)
     */
    public function buildCrowdFundingActivityCode()
    {
        return 'C' . date('Ymd') . substr(time(), -2, 2) . substr(microtime(), 2, 4) . sprintf('%03d', rand(1, 9999));
    }

    /**
     * @title  获取众筹模式期的时间段编码
     * @return string
     * @remark 生成规则:共计10位 = 毫秒小数点后前四位(4)+六位随机数(6)
     */
    public function buildCrowdFundingSaleDurationCode()
    {
        return substr(microtime(), 2, 4) . sprintf('%06d', mt_rand(1, 999999));
    }

    /**
     * @title  获取充值众筹钱包订单号
     * @return string
     * @remark 生成规则:共计20位=大写字母CZ(1)+格式化时间日期(8)+时间戳秒(2)+毫秒小数点后前四位(4)+四位随机数(4),
     * (更好的方法可以选择雪花算法)
     */
    public function buildRechargeOrderNo()
    {
        return 'CZ' . date('Ymd') . substr(time(), -2, 2) . substr(microtime(), 2, 4) . sprintf('%04d', rand(1, 9999));
    }

    /**
     * @title  获取中奖订单号
     * @return string
     * @remark 生成规则:共计20位=格式化时间日期(8)+时间戳秒(2)+毫秒小数点后前四位(4)+四位随机数(4),
     * (更好的方法可以选择雪花算法)
     */
    public function buildCrowdFundingLotteryWinNo()
    {
        return 'CL' . date('Ymd') . substr(time(), -2, 2) . substr(microtime(), 2, 4) . sprintf('%04d', rand(1, 9999));
    }

    /**
     * @title  获取众筹抽奖编号
     * @return string
     * @remark 生成规则:共计20位=格式化时间日期(8)+时间戳秒(2)+毫秒小数点后前四位(4)+四位随机数(4),
     * (更好的方法可以选择雪花算法)
     */
    public function buildCrowdFundingLotteryPlanSn()
    {
        return 'CP' . date('Ymd') . substr(time(), -2, 2) . substr(microtime(), 2, 4) . sprintf('%04d', rand(1, 9999));
    }

    /**
     * @title  获取线下充值提交编号
     * @return string
     * @remark 生成规则:共计20位=大写字母OC(2)+格式化时间日期(8)+时间戳秒(2)+毫秒小数点后前四位(4)+四位随机数(4),
     * (更好的方法可以选择雪花算法)
     */
    public function buildOfflineRechargeSn()
    {
        return 'OC' . date('Ymd') . substr(time(), -2, 2) . substr(microtime(), 2, 4) . sprintf('%04d', rand(1, 9999));
    }

    /**
     * @title  获取银行卡卡包编码
     * @return string
     * @remark 生成规则:共计20位=大写字母UC(2)+格式化时间日期(8)+时间戳秒(2)+毫秒小数点后前四位(4)+四位随机数(4),
     * (更好的方法可以选择雪花算法)
     */
    public function buildUserCardSn()
    {
        return 'UC' . date('Ymd') . substr(time(), -2, 2) . substr(microtime(), 2, 4) . sprintf('%04d', rand(1, 9999));
    }

    /**
     * @title  获取设备套餐编码
     * @return string
     * @remark 生成规则:共计10位 = 毫秒小数点后前四位(4)+六位随机数(6)
     */
    public function buildDeviceComboCode()
    {
        return substr(microtime(), 2, 4) . sprintf('%06d', mt_rand(1, 999999));
    }

    /**
     * @title  获取系统后台充值积分(美丽豆)的订单号
     * @return string
     * @remark 生成规则:共计21位=大写字母SRI(3)+格式化时间日期(8)+时间戳秒(2)+毫秒小数点后前四位(4)+四位随机数(4),
     * (更好的方法可以选择雪花算法)
     */
    public function buildSystemRechargeIntegralSn()
    {
        return 'SRI' . date('Ymd') . substr(time(), -2, 2) . substr(microtime(), 2, 4) . sprintf('%04d', rand(1, 9999));
    }

    /**
     * @title  获取系统后台充值健康豆的订单号
     * @return string
     * @remark 生成规则:共计21位=大写字母SRH(3)+格式化时间日期(8)+时间戳秒(2)+毫秒小数点后前四位(4)+四位随机数(4),
     * (更好的方法可以选择雪花算法)
     */
    public function buildSystemRechargeHealthySn()
    {
        return 'SRH' . date('Ymd') . substr(time(), -2, 2) . substr(microtime(), 2, 4) . sprintf('%04d', rand(1, 9999));
    }

    /**
     * @title  获取系统后台充值美丽卡的订单号
     * @return string
     * @remark 生成规则:共计21位=大写字母SRAD(4)+格式化时间日期(8)+时间戳秒(2)+毫秒小数点后前四位(4)+三位随机数(3),
     * (更好的方法可以选择雪花算法)
     */
    public function buildSystemRechargeAdvanceCardSn()
    {
        return 'SRAD' . date('Ymd') . substr(time(), -2, 2) . substr(microtime(), 2, 4) . sprintf('%04d', rand(1, 999));
    }

    /**
     * @title  生成用户余额限制编码
     * @return string
     * @remark 生成规则:共计13位=大写字母UBL(4)+毫秒小数点后前五位(5)+四位随机数(4)
     */
    public function buildUserBalanceLimitSn(): string
    {
        return 'UBL' . substr(microtime(), 2, 5) . sprintf('%04d', rand(1, 9999));
    }

    /**
     * @title  获取用户签约协议编码
     * @return string
     * @remark 生成规则:共计20位=大写字母UAG(2)+格式化时间日期(8)+时间戳秒(2)+毫秒小数点后前四位(4)+四位随机数(3),
     * (更好的方法可以选择雪花算法)
     */
    public function buildUserAgreementSn()
    {
        return 'UAG' . date('Ymd') . substr(time(), -2, 2) . substr(microtime(), 2, 4) . sprintf('%04d', rand(1, 999));
    }

    /**
     * @title  获取签约协议编码
     * @return string
     * @remark 生成规则:共计20位=大写字母AG(2)+格式化时间日期(8)+时间戳秒(2)+毫秒小数点后前四位(4)+四位随机数(4),
     * (更好的方法可以选择雪花算法)
     */
    public function buildAgreementSn()
    {
        return 'AG' . date('Ymd') . substr(time(), -2, 2) . substr(microtime(), 2, 4) . sprintf('%04d', rand(1, 9999));
    }

    /**
     * @title  获取APP版本号编码
     * @return string
     * @remark 生成规则:共计10位=毫秒小数点后前四位(4)+六位随机数(6)
     */
    public function buildAppVersionSn()
    {
        return substr(microtime(), 2, 4) . sprintf('%06d', mt_rand(1, 999999));
    }


}