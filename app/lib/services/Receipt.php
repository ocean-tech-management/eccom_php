<?php
/**
 * Created by PhpStorm.
 * User: yang
 * Date: 2020/11/26
 * Time: 19:50
 */

namespace app\lib\services;


class Receipt
{
    protected $config = [];
    protected $fontFilePath;
    protected $rootPath;

    /**
     * 生成订单回执(老方法)
     * @param $data
     * @return string
     */
    public function buildOld($data)
    {
        $file = '/storage/temp/' . $data['order_sn'] . '.png';
        if (file_exists($file)) return $file;
        $this->rootPath = root_path();
        $this->fontFilePath = $this->rootPath . 'public/static/111.ttf';
        $baseHight = 750 + count($data['goods']) * 250;
        $this->setPostBaseParam($baseHight, 710)
            ->setHeaderBg($this->rootPath . 'public/static/bg.png')
            ->setHeaderText($data['order_sn'], $data['create_time'], $data['shipping_name'], $data['shipping_phone'], $data['shipping_address']);
        $this->setLine(490);
        $init = 0;
        $totalPrice = 0;
        foreach ($data['goods'] as $items) {
            $this->setGoodsBox(530 + $init, $items);//每个商品间隔250单位
            $totalPrice += ($items['sale_price'] * $items['count'] ?? 1);
            $init += 250;
        }
        $this->setFoot(530 + $init + 30, $totalPrice, $data['fare_price']);//底部高度280个单位

        $draw = new Draw($this->config);
        $draw->draw($this->rootPath . '/public/storage/temp/' . $data['order_sn'] . '.png');
        $file = '/storage/temp/' . $data['order_sn'] . '.png';
        return $file;
    }

    /**
     * @title  生成订单回执
     * @param $data
     * @return mixed|string
     * @throws \OSS\Core\OssException
     */
    public function build($data)
    {
        $imgDomain = config('system.imgDomain');
        $file = $imgDomain.'temp/' . $data['order_sn'] . '.png';
        if (@file_get_contents($file)) return $file;
        $this->rootPath = root_path();
        $this->fontFilePath = $this->rootPath . 'public/static/111.ttf';
        $baseHight = 750 + count($data['goods']) * 250;
        $this->setPostBaseParam($baseHight, 710)
            ->setHeaderBg($this->rootPath . 'public/static/bg.png')
            ->setHeaderText($data['order_sn'], $data['create_time'], $data['shipping_name'], $data['shipping_phone'], $data['shipping_address']);
        $this->setLine(490);
        $init = 0;
        $totalPrice = 0;
        foreach ($data['goods'] as $items) {
            $this->setGoodsBox(530 + $init, $items);//每个商品间隔250单位
            $totalPrice += ($items['sale_price'] * $items['count'] ?? 1);
            $init += 250;
        }
        $this->setFoot(530 + $init + 30, $totalPrice, $data['fare_price']);//底部高度280个单位

        $draw = new Draw($this->config);
        $draw->draw($this->rootPath . 'public/storage/temp/' . $data['order_sn'] . '.png');
        $file = 'temp/' . $data['order_sn'] . '.png';
        $res = (new AlibabaOSS())->uploadFile($file,'temp');

        return $res;
    }


    protected function setFoot($baseY, $totalPrice, $farePrice)
    {
        $totalPrice = priceFormat($totalPrice);
        $baseLen = strlen('￥0.00');
        $baseX = 590;
        $this->config['string'][] = [
            'color' => '#313131',
            'string' => '商品总价',
            'width' => 200,
            'fontFilePath' => $this->fontFilePath,
            'size' => 18,
            'x' => 30,        // 位置 x
            'y' => $baseY,        // 位置 y
        ];

        $this->config['string'][] = [
            'color' => '#313131',
            'string' => '￥' . $totalPrice,
            'fontFilePath' => $this->fontFilePath,
            'width' => 200,
            'size' => 20,
            'x' => $baseX - ((strlen('￥' . $totalPrice) - $baseLen) * 14),        // 位置 x
            'y' => $baseY,        // 位置 y
        ];
        $this->config['string'][] = [
            'color' => '#313131',
            'string' => '运费',
            'fontFilePath' => $this->fontFilePath,
            'width' => 200,
            'size' => 18,
            'x' => 30,        // 位置 x
            'y' => $baseY + 50,        // 位置 y
        ];

        $this->config['string'][] = [
            'color' => '#313131',
            'string' => '￥' . $farePrice,
            'fontFilePath' => $this->fontFilePath,
            'width' => 200,
            'size' => 20,
            'x' => $baseX - ((strlen('￥' . $farePrice) - $baseLen) * 14),        // 位置 x
            'y' => $baseY + 50,        // 位置 y
        ];
        $this->setLine($baseY + 100);
        $this->config['string'][] = [
            'color' => '#313131',
            'string' => '合计',
            'fontFilePath' => $this->fontFilePath,
            'width' => 200,
            'size' => 18,
            'x' => 30,        // 位置 x
            'y' => $baseY + 150,        // 位置 y
        ];

        $this->config['string'][] = [
            'color' => '#ff0000',
            'string' => '￥' . priceFormat($totalPrice + $farePrice),
            'fontFilePath' => $this->fontFilePath,
            'width' => 200,
            'size' => 20,
            'x' => $baseX - ((strlen('￥' . priceFormat($totalPrice + $farePrice)) - $baseLen) * 14),        // 位置 x
            'y' => $baseY + 150,        // 位置 y
        ];
    }

    public function setGoodsBox($baseY, $data)
    {
        $specArr = json_decode($data['specs'], true);
        $spec = '暂无规格';
        if (is_array($specArr)) {
            $spec = '';
            foreach ($specArr as $item) {
                $spec .= $item . '　';
            }
        }

        $this->config['image'][] = [
            'path' => $data['images'],
            'height' => 160,
            'width' => 160,
            'x' => 30,        // 位置 x
            'y' => $baseY,            // 位置 y
        ];

        $this->config['string'][] = [
            'color' => '#313131',
            'string' => $data['title'],
            'fontFilePath' => $this->fontFilePath,
            'lineCount' => 2,            // 行数
            'width' => 380,
            'size' => 20,
            'x' => 210,        // 位置 x
            'y' => $baseY + 30,        // 位置 y
        ];

        $this->config['string'][] = [
            'color' => '#313131',
            'string' => 'x ' . $data['count'],
            'fontFilePath' => $this->fontFilePath,
            'lineCount' => 2,            // 行数
            'width' => 410,
            'size' => 18,
            'x' => 645,        // 位置 x
            'y' => $baseY + 30,        // 位置 y
        ];

        $this->config['string'][] = [
            'color' => '#313131',
            'string' => $spec,
            'fontFilePath' => $this->fontFilePath,
            'width' => 450,
            'size' => 16,
            'x' => 210,        // 位置 x
            'y' => $baseY + 105,        // 位置 y
        ];
        $this->config['string'][] = [
            'color' => '#313131',
            'string' => '￥' . $data['sale_price'],
            'fontFilePath' => $this->fontFilePath,
            'width' => 200,
            'size' => 20,
            'x' => 210,        // 位置 x
            'y' => $baseY + 154,        // 位置 y
        ];

        $this->setLine($baseY + 200);
    }

    protected function setLine($y)
    {
        $image = [
            'path' => $this->rootPath . 'public/static/point.jpg',
            'height' => 2,
            'width' => 710,
            'x' => 0,        // 位置 x
            'y' => $y,      // 位置 y
        ];
        $this->config['image'][] = $image;

        return $this;
    }

    protected function setHeaderText($orderNum = 'Y0283028030852', $orderTime = '2020-08-12 22:23', $shippingName, $shippingPhone, $shippingAddress)
    {
        $orderTime = str_replace(' ', '　', $orderTime);//防止空格被过滤

        $shippingName = substr($shippingName, 0, 3);
        $shippingName = $shippingName . '**';
        $shippingPhone = substr($shippingPhone, 0, 3) . '****' . substr($shippingPhone, 7, 4);
        $shippingAddress = $this->subAddress($shippingAddress);

        $this->config['string'][] = [
            'color' => '#313131',
            'string' => '下单回执',
            'fontFilePath' => $this->fontFilePath,
            'size' => 33,
            'x' => 30,        // 位置 x
            'y' => 90,        // 位置 y
        ];

        $this->config['string'][] = [
            'color' => '#313131',
            'string' => '订单单号： ' . $orderNum,
            'fontFilePath' => $this->fontFilePath,
            'size' => 18,
            'x' => 30,        // 位置 x
            'y' => 153,        // 位置 y
        ];

        $this->config['string'][] = [
            'color' => '#313131',
            'string' => '下单时间：' . $orderTime,
            'fontFilePath' => $this->fontFilePath,
            'size' => 18,
            'x' => 30,        // 位置 x
            'y' => 191,        // 位置 y
        ];
        $this->config['string'][] = ['color' => '#313131',
            'string' => '收货人：' . $shippingName . '　　　　' . $shippingPhone,
            'fontFilePath' => $this->fontFilePath,
            'size' => 18,
            'x' => 35,        // 位置 x
            'y' => 390,        // 位置 y
        ];

        $this->config['string'][] = [
            'color' => '#313131',
            'string' => '地　址：' . $shippingAddress,
            'fontFilePath' => $this->fontFilePath,
            'lineCount' => 2,            // 行数
            'width' => 600,
            'size' => 18,
            'x' => 35,        // 位置 x
            'y' => 450,        // 位置 y
        ];
    }

    protected function setHeaderBg($url)
    {
        $this->config['image'][] = [
            'path' => $url,
            'width' => $this->config['width'],
            'x' => 0,    // 位置 x
            'y' => 0    // 位置 y
        ];

//        $this->config['image'][] = [
//            'path' => $this->rootPath.'public/static/sign.png',
//            'width' => 250,
//            'x' => 430,    // 位置 x
//            'y' => 420    // 位置 y
//        ];

        return $this;
    }

    protected function setPostBaseParam($height, $width)
    {
        $this->config['rate'] = $height / $width;
        $this->config['width'] = $width;
        return $this;
    }


    /**
     * 隐藏地址部分信息
     * @param $address
     * @return bool|string
     */
    protected function subAddress($address)
    {
        $endSign = strpos($address, '区');
        if (!$endSign) $endSign = strpos($address, '县');
        if (!$endSign) $endSign = strpos($address, '岛');
        if (!$endSign) $endSign = strpos($address, '市');
        if (!$endSign) $endSign = strpos($address, '省');

        return substr($address, 0, $endSign + 3) . '*****';
    }
}

class Draw
{
    // 图片主对象
    protected $im;

    // 图片对象组
    protected $imageList;
    // 文字对象组
    protected $stringList;

    protected $width = 750;
    protected $height = 2000;

    // 宽高比
    protected $rate = 16 / 9;

    // 默认背景颜色值
    protected $bgColor = '#fff';

    // 背景图
    protected $bgImgPath;

    public function __construct($params = [])
    {
        $this->parseParams($params);
        $this->createIm();
    }

    /**
     * 画图
     * @author 孔志明 <i@giveme.xin>
     * @date   2019-04-04
     */
    public function draw($file)
    {
        $this->drawImage();

        $this->drawString();

        return $this->outImage($file);
    }

    private function outImage($file)
    {
        //输出图像
        $result = imagepng($this->im, $file);
        imagedestroy($this->im);
        return $result;
    }


    // 创建IM
    private function createIm()
    {
        $this->setImSize();
        $this->im = imagecreatetruecolor($this->width, $this->height);
        // 默认颜色
        $bgColor = $this->setColor($this->bgColor);
        imagefill($this->im, 0, 0, $bgColor);

        // 填充背景图
        if ($this->bgImgPath) {
            $this->fillImage($this->bgImgPath);
        }
    }

    // 设置图片宽高，判断有背景图就取背景图的宽高
    private function setImSize()
    {
        if ($this->bgImgPath) {
            $bgImgIm = $this->imagecreatefromPath($this->bgImgPath);
            $width = imagesx($bgImgIm);
            $scale = $width / $this->width;
            $this->width = floor($width / $scale);
        }

        $this->height = floor($this->width * $this->rate);
    }

    // 解析参数
    private function parseParams($params)
    {
        foreach ($params as $key => $value) {
            if ($key === 'string') {
                foreach ($value as $v) {
                    $this->stringList[] = new STR($v);
                }
                continue;
            }

            if ($key === 'image') {
                foreach ($value as $v) {
                    $this->imageList[] = new IMG($v);
                }
                continue;
            }

            $this->$key = $value;
        }
    }

    // 绘制文字
    public function drawString()
    {
        if (!$this->stringList) {
            return;
        }

        foreach ($this->stringList as $obj) {
            $value = get_object_vars($obj);
            $this->fillString($value);
        }
    }

    /**
     * 多图绘制
     * @author 孔志明 <i@giveme.xin>
     * @date   2019-04-04
     */
    public function drawImage()
    {
        if (!$this->imageList) {
            return;
        }

        foreach ($this->imageList as $obj) {
            $value = get_object_vars($obj);
            $value['pos_arr'][] = $value['x'];
            $value['pos_arr'][] = $value['y'];
            $value['pos_arr'][] = 0;
            $value['pos_arr'][] = 0;

            $value['pos'] = implode(',', $value['pos_arr']);

            $this->fillImage($value['path'], $value);
        }
    }

    // 填充图
    private function fillImage($path, $params = ['width' => 0, 'height' => 0, 'pos' => '0,0,0,0'])
    {
        $des_w = $params['width'] ?: $this->width;
        $des_h = $params['height'] ?: $this->height;

        // 创建原图资源
        $src_img = $this->imagecreatefromPath($path);
        //获取原图的宽高
        $src_w = imagesx($src_img);
        $src_h = imagesy($src_img);
        // 计算缩放比例（用原图片的宽高分别处以对应目的图片的宽高，选择比例大的作为基准进行缩放）
        $scale = ($src_w / $des_w) > ($src_h / $des_h) ? ($src_w / $des_w) : ($src_h / $des_h);
        //计算实际缩放时目的图的宽高（向下取整）
        $des_w = floor($src_w / $scale);
        $des_h = floor($src_h / $scale);

        if (is_array($params['pos'])) {
            list($des_x, $des_y, $src_x, $src_y) = $params;
        } else {
            $tmp = explode(',', $params['pos']);
            list($des_x, $des_y, $src_x, $src_y) = $tmp;
        }

        // 圆角
        if (isset($params['radius']) && $params['radius'] > 0) {
            $src_img = $this->createRadius($src_img, $src_w, $src_h, $params['radius']);
        }

        imagecopyresampled($this->im, $src_img, $des_x, $des_y, $src_x, $src_y, $des_w, $des_h, $src_w, $src_h);
    }

    // 填充文字
    public function fillString($params)
    {
        $textcolor = $this->setColor($params['color']);
        $font_file_path = $params['fontFilePath'];
        $font_size = $params['size'];
        $br_height = $params['lineHeight']; // 换行高度
        $content = $params['string']; // 内容

        $width = $params['x']; // 初始宽度
        $height = $params['y']; // 初始高度

        $font_width = $params['width']; // 字宽设置
        $line_count = $params['lineCount'] - 1; // 行数

        $arr = [];
        for ($i = 0; $i < mb_strlen($content); $i++) {
            $t = mb_substr($content, $i, 1);
            if (trim($t) || $t === '0') {
                $arr[$i] = $t;
            }
        }

        $str = '';
        $tmp_br = 0;
        $is_over = 0;
        foreach ($arr as $key => $value) {
            if ($tmp_br / $br_height > $line_count) {
                $is_over = 1;
                break;
            }

            $str .= $value;

            $box = imagettfbbox($font_size, 0, $font_file_path, $str);
            $now_length = $box[2] - $box[0];
            if ($now_length >= $font_width) {
                imagettftext($this->im, $font_size, 0, $width, $height + $tmp_br, $textcolor, $font_file_path, $str);
                $str = '';
                $tmp_br += $br_height;
            }
        }

        if (!$is_over && $str != '') {
            imagettftext($this->im, $font_size, 0, $width, $height + $tmp_br, $textcolor, $font_file_path, $str);
        }
    }

    private function createRadius($srcIm, $src_w, $src_h, $radius)
    {
        // 创建一个正方形的图像
        $img = imagecreatetruecolor($radius, $radius);
        // 图像的背景
        $bgcolors = imagecolorallocate($img, 255, 255, 255);
        $fgcolor = imagecolorallocate($img, 0, 0, 0);
        imagefill($img, 0, 0, $bgcolors);
        // $radius,$radius：以图像的右下角开始画弧
        // $radius*2, $radius*2：已宽度、高度画弧
        // 180, 270：指定了角度的起始和结束点
        // fgcolor：指定颜色
        imagefilledarc($img, $radius, $radius, $radius * 2, $radius * 2, 180, 270, $fgcolor, IMG_ARC_PIE);
        // 将弧角图片的颜色设置为透明
        imagecolortransparent($img, $fgcolor);

        // lt(左上角)
        imagecopymerge($srcIm, $img, 0, 0, 0, 0, $radius, $radius, 100);
        // lb(左下角)
        $lb_corner = imagerotate($img, 90, 0);
        imagecopymerge($srcIm, $lb_corner, 0, $src_h - $radius, 0, 0, $radius, $radius, 100);
        // rb(右上角)
        $rb_corner = imagerotate($img, 180, 0);
        imagecopymerge($srcIm, $rb_corner, $src_w - $radius, $src_h - $radius, 0, 0, $radius, $radius, 100);
        // rt(右下角)
        $rt_corner = imagerotate($img, 270, 0);
        imagecopymerge($srcIm, $rt_corner, $src_w - $radius, 0, 0, 0, $radius, $radius, 100);

        return $srcIm;
    }

    // 获取图片对象
    private function imagecreatefromPath($path)
    {
        $md = '_' . md5($path);
        if ($this->$md) {
            return $this->$md;
        }

        $srcarr = getimagesize($path);
        if ($srcarr == false) {
            throw new \Exception("获取图片失败，请重试：" . $path);
        }

        //处理图片创建函数和图片输出函数
        switch ($srcarr[2]) {
            case 1://gif
                $imagecreatefrom = 'imagecreatefromgif';
                $imageout = 'imagegif';
                break;
            case 2://jpg
                $imagecreatefrom = 'imagecreatefromjpeg';
                $imageout = 'imagejpeg';
                break;
            case 3://png
                $imagecreatefrom = 'imagecreatefrompng';
                $imageout = 'imagepng';
                break;
        }

        // 创建原图资源
        return $this->$md = $imagecreatefrom($path);
    }

    // 定义颜色
    private function setColor($color)
    {
        list($a, $b, $c) = $this->hex2rgb($color);
        return imagecolorallocate($this->im, $a, $b, $c);
    }

    // 颜色值转换
    private function hex2rgb($colour)
    {
        if ($colour[0] == '#') {
            $colour = substr($colour, 1);
        }
        if (strlen($colour) == 6) {
            list($r, $g, $b) = [$colour[0] . $colour[1], $colour[2] . $colour[3], $colour[4] . $colour[5]];
        } elseif (strlen($colour) == 3) {
            list($r, $g, $b) = [$colour[0] . $colour[0], $colour[1] . $colour[1], $colour[2] . $colour[2]];
        } else {
            return [0, 0, 0];
        }
        $r = hexdec($r);
        $g = hexdec($g);
        $b = hexdec($b);
        return [$r, $g, $b];
    }

    public function __set($key, $value)
    {
        $this->$key = $value;
    }

    public function __get($key)
    {
        return isset($this->$key) ? $this->$key : '';
    }
}

class STR
{
    public $string = '';
    public $color = '#000';
    public $size = 20;
    public $x = 0;
    public $y = 0;
    public $width = 750;
    public $lineHeight = 40;
    public $lineCount = 1;
    public $fontFilePath = __DIR__ . '/assets/1.ttf';

    public function __construct($params)
    {
        $obj = get_class($this);
        $keys = $this->getKeys($this);
        foreach ($params as $key => $value) {
            if (in_array($key, $keys)) {
                $this->$key = $value;
            }
        }
    }

    protected function getKeys()
    {
        $obj = get_class($this);
        $arr = get_object_vars($this);
        return array_keys($arr);
    }

    public function __set($key, $value)
    {
        $this->$key = $value;
    }

    public function __get($key)
    {
        return isset($this->$key) ? $this->$key : '';
    }
}

class IMG
{
    public $path = '';
    public $x = 0;
    public $y = 0;
    public $width = 100;
    public $height = 0;
    public $rate = 1;
    public $radius = 0;

    public function __construct($params)
    {
        $obj = get_class($this);
        $keys = $this->getKeys($this);
        foreach ($params as $key => $value) {
            if (in_array($key, $keys)) {
                $this->$key = $value;
            }
        }

        if (!$this->height) {
            $this->height = $this->width * ($this->rate ?: 1);
        }
    }

    protected function getKeys()
    {
        $obj = get_class($this);
        $arr = get_object_vars($this);
        return array_keys($arr);
    }

    public function __set($key, $value)
    {
        $this->$key = $value;
    }

    public function __get($key)
    {
        return isset($this->$key) ? $this->$key : '';
    }
}