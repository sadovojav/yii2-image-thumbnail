<?php

namespace sadovojav\image;

use yii\helpers\Url;
use yii\helpers\Html;
use Imagine\Image\Box;
use yii\imagine\Image;
use yii\base\Exception;
use Imagine\Image\Point;
use yii\helpers\FileHelper;
use Kinglozzer\TinyPng\Compressor;
use Imagine\Image\ManipulatorInterface;

/**
 * Class Thumbnail
 * @package sadovojav\image
 */
class Thumbnail extends \yii\base\Component
{
    /**
     * Path to cache directory
     * @var string
     */
    public $cachePath = '@runtime/thumbnails';

    /**
     * Base path
     * @var null
     */
    public $basePath = null;

    /**
     * Prefix path
     * @var null
     */
    public $prefixPath = null;

    /**
     * Image cache expire
     * @var int
     */
    public $cacheExpire = 604800;

    /**
     * Options
     * @var array
     */
    public $options = [];

    private $tiny;

    private $image;

    private $defaultOptions = [
        'placeholder' => [
            'type' => Thumbnail::PLACEHOLDER_TYPE_URL,
            'backgroundColor' => '#f5f5f5',
            'textColor' => '#cdcdcd',
            'textSize' => 30,
            'text' => 'No image'
        ],
        'quality' => 92,
        'tinyPng' => [
            'apiKey' => null
        ]
    ];

    const THUMBNAIL_OUTBOUND = ManipulatorInterface::THUMBNAIL_OUTBOUND;
    const THUMBNAIL_INSET = ManipulatorInterface::THUMBNAIL_INSET;

    const PLACEHOLDER_TYPE_JS = 'js';
    const PLACEHOLDER_TYPE_URL = 'url';
    const PLACEHOLDER_TYPE_IMAGINE = 'imagine';

    const FUNCTION_CROP = 'crop';
    const FUNCTION_RESIZE = 'resize';
    const FUNCTION_THUMBNAIL = 'thumbnail';
    const FUNCTION_WATERMARK = 'watermark';
    const FUNCTION_COMPRESS = 'compress';

    public function init()
    {
        if (isset($this->options['placeholder']) && count($this->options['placeholder'])) {
            $this->options['placeholder'] = array_merge($this->defaultOptions['placeholder'], $this->options['placeholder']);
        } else {
            $this->options['placeholder'] = $this->defaultOptions['placeholder'];
        }

        $this->options['quality'] = (!isset($this->options['quality']) || !is_numeric($this->options['quality']))
            ? $this->defaultOptions['quality']
            : $this->options['quality'];

        if ($this->options['placeholder']['type'] == Thumbnail::PLACEHOLDER_TYPE_JS) {
            AssetBundle::register(\Yii::$app->getView());
        }

        if (isset($this->options['tinyPng']) && count($this->options['tinyPng'])) {
            $this->options['tinyPng'] = array_merge($this->defaultOptions['tinyPng'], $this->options['tinyPng']);
        } else {
            $this->options['tinyPng'] = $this->defaultOptions['tinyPng'];
        }

        if (!is_null($this->options['tinyPng']['apiKey'])) {
            $this->tiny = new Compressor($this->options['tinyPng']['apiKey']);
        }
    }

    /**
     * Creates and caches the image thumbnail and returns <img> tag
     * @param string $file
     * @param array $params
     * @param array $options
     * @param bool $schema
     * @return string
     */
    public function img($file, array $params, $options = [], $schema = false)
    {
        $cacheFileSrc = $this->make($file, $params);

        if (!$cacheFileSrc) {
            if (isset($params['placeholder'])) {
                $fileUrl = Url::to($this->placeholder($params['placeholder']), $schema);

                return Html::img($fileUrl, $options);
            } else {
                return null;
            }
        }

        $fileUrl = Url::to($cacheFileSrc, $schema);

        return Html::img($fileUrl, $options);
    }

    /**
     * Creates and caches the image thumbnail and returns image url
     * @param string $file
     * @param array $params
     * @param bool $schema
     * @return string
     */
    public function url($file, array $params, $schema = false)
    {
        $cacheFileSrc = $this->make($file, $params);

        if (!$cacheFileSrc) {
            if (isset($params['placeholder'])) {
                $cacheFileSrc = $this->placeholder($params['placeholder']);
            } else {
                return null;
            }
        }

        return Url::to($cacheFileSrc, $schema);
    }

    /**
     * Image placeholder
     * @param array $params
     * @return null|string
     */
    private function placeholder(array $params)
    {
        $placeholder = null;

        $width = (isset($params['width']) && is_numeric($params['width'])) ? $params['width'] : null;
        $height = (isset($params['height']) && is_numeric($params['height'])) ? $params['height'] : null;

        if (is_null($width) || is_null($height)) {
            throw new Exception('Wrong placeholder width or height');
        }

        if (isset($params['backgroundColor']) && $this->checkHexColor($params['backgroundColor'])) {
            $backgroundColor = $params['backgroundColor'];
        } else {
            $backgroundColor = $this->options['placeholder']['backgroundColor'];
        }

        if (isset($params['textColor']) && $this->checkHexColor($params['textColor'])) {
            $textColor = $params['textColor'];
        } else {
            $textColor = $this->options['placeholder']['textColor'];
        }

        $textSize = (isset($params['textSize']) && is_numeric($params['textSize'])) ? $params['textSize'] : $this->options['placeholder']['textSize'];

        $text = !empty($params['text']) ? $params['text'] : $this->options['placeholder']['text'];

        if ($this->options['placeholder']['type'] == self::PLACEHOLDER_TYPE_URL) {
            $placeholder = $this->urlPlaceholder($width, $height, $text, $backgroundColor, $textColor, $textSize);
        } elseif ($this->options['placeholder']['type'] == self::PLACEHOLDER_TYPE_JS) {
            $placeholder = $this->jsPlaceholder($width, $height, $text, $backgroundColor, $textColor, $textSize);
        } elseif ($this->options['placeholder']['type'] == self::PLACEHOLDER_TYPE_IMAGINE) {
            $placeholder = $this->imaginePlaceholder($width, $height, $text, $backgroundColor, $textColor, $textSize);
        }

        return $placeholder;
    }

    /**
     * Return cache path from Imagine placeholder
     * @param integer $width
     * @param integer $height
     * @param string $text
     * @param string $backgroundColor
     * @param string $textColor
     * @param integer $textSize
     * @return string
     */
    private function imaginePlaceholder($width, $height, $text, $backgroundColor, $textColor, $textSize)
    {
        $cache = $this->findPlaceholderInCache($width . $height . $text . $backgroundColor . $textColor . $textSize);

        if ($cache['exists']) {
            return $cache['url'];
        }

        $imagine = Image::getImagine();
        $canvasPalette = new \Imagine\Image\Palette\RGB();
        $textPalette = new \Imagine\Image\Palette\RGB();
        $size = new \Imagine\Image\Box($width, $height);
        $canvasColor = $canvasPalette->color($backgroundColor, 100);
        $textColor = $textPalette->color($textColor, 100);

        $fontPath = \Yii::getAlias('@vendor/sadovojav/yii2-image-thumbnail/assets/fonts/HelveticaNeueCyr.ttf');

        $font = $imagine->font($fontPath, $textSize, $textColor);
        $image = $imagine->create($size, $canvasColor);

        list($left, , $right) = imageftbbox($textSize, 0, $fontPath, $text);

        $x = $width / 2 - ($right - $left) / 2;
        $y = $height / 2 - $textSize / 2;

        $image->draw()->text($text, $font, new Point($x, $y));

        $image->save($cache['file']);

        return $cache['url'];
    }

    /**
     * Return cache path from URL placeholder
     * @param integer $width
     * @param integer $height
     * @param string $text
     * @param string $backgroundColor
     * @param string $textColor
     * @param string $textSize
     * @return string
     */
    private function urlPlaceholder($width, $height, $text, $backgroundColor, $textColor, $textSize)
    {
        $cache = $this->findPlaceholderInCache($width . $height . $text . $backgroundColor . $textColor . $textSize);

        if ($cache['exists']) {
            return $cache['url'];
        }

        $src = 'https://placeholdit.imgix.net/~text?txtsize=' . $textSize . '&bg=' . str_replace('#', '',
                $backgroundColor) . '&txtclr=' . str_replace('#', '', $textColor) . '&txt=' . $text . '&w=' . $width . '&h=' . $height;

        $image = file_get_contents($src);

        file_put_contents($cache['file'], $image);

        return $cache['url'];
    }

    /**
     * Return JS placeholder image
     * @param integer $width
     * @param integer $height
     * @param string $text
     * @param string $backgroundColor
     * @param string $textColor
     * @param string $textSize
     * @return string
     */
    private function jsPlaceholder($width, $height, $text, $backgroundColor, $textColor, $textSize)
    {
        return 'holder.js/' . $width . 'x' . $height . '?bg=' . $backgroundColor . '&fg=' . $textColor . '&size=' . 
    $textSize . '&text=' . $text;
    }

    /**
     * Find image
     * @param $string
     * @return array
     */
    private function findPlaceholderInCache($string)
    {
        $cacheFileName = md5($string);
        $cacheFileExt = '.jpg';
        $cacheFileDir = '/' . substr($cacheFileName, 0, 2);
        $cacheFilePath = \Yii::getAlias($this->cachePath) . $cacheFileDir;
        $cacheFile = $cacheFilePath . '/' . $cacheFileName . $cacheFileExt;
        $cacheUrl = str_replace('\\', '/', preg_replace('/^@[a-z]+/', '', $this->cachePath) . $cacheFileDir . '/'
            . $cacheFileName . $cacheFileExt);

        if (file_exists($cacheFile)) {
            if ($this->cacheExpire !== 0 && (time() - filemtime($cacheFile)) > $this->cacheExpire) {
                unlink($cacheFile);

                $exists = false;
            } else {
                $exists = true;
            }
        } else {
            $exists = false;
        }

        if (!is_dir($cacheFilePath)) {
            mkdir($cacheFilePath, 0755, true);
        }

        return [
            'exists' => $exists,
            'name' => $cacheFileName,
            'file' => $cacheFile,
            'url' => $cacheUrl,
        ];
    }

    /**
     * Make image and save to cache
     * @param string $filePath
     * @param array $params
     * @return string
     */
    private function make($filePath, array $params)
    {
        if (!is_null($this->basePath)) {
            $fileFullPath = FileHelper::normalizePath(\Yii::getAlias($this->basePath . '/' . $filePath));
        } else {
            $fileFullPath = FileHelper::normalizePath($filePath);
        }

        $fileFullPath = urldecode($fileFullPath);

        if (!is_file($fileFullPath)) {
            return false;
        }

        $quality = isset($params['quality']) ? $params['quality'] : $this->options['quality'];

        $cacheFileName = md5($fileFullPath . serialize($params) . $quality . filemtime($fileFullPath));
        $cacheFileExt = strrchr($fileFullPath, '.');
        $cacheFileDir = '/' . substr($cacheFileName, 0, 2);
        $cacheFilePath = \Yii::getAlias($this->cachePath) . $cacheFileDir;
        $cacheFile = $cacheFilePath . '/' . $cacheFileName . $cacheFileExt;
        $cacheUrl = str_replace('\\', '/', preg_replace('/^@[a-z]+/', '', $this->cachePath) . $cacheFileDir . '/'
            . $cacheFileName . $cacheFileExt);

        $cacheUrl = !is_null($this->prefixPath) ? $this->prefixPath . $cacheUrl : $cacheUrl;

        if (file_exists($cacheFile)) {
            if ($this->cacheExpire !== 0 && (time() - filemtime($cacheFile)) > $this->cacheExpire) {
                unlink($cacheFile);
            } else {
                return $cacheUrl;
            }
        }

        if (!is_dir($cacheFilePath)) {
            mkdir($cacheFilePath, 0755, true);
        }

        $this->image = Image::getImagine()->open($fileFullPath);

        foreach ($params as $key => $value) {
            switch ($key) {
                case self::FUNCTION_THUMBNAIL :
                    $this->thumbnail($value);
                    break;
                case self::FUNCTION_RESIZE :
                    $this->resize($value);
                    break;
                case self::FUNCTION_CROP :
                    $this->crop($value);
                    break;
                case self::FUNCTION_WATERMARK :
                    $this->watermark($value);
                    break;
            }
        }

        $this->image->save($cacheFile, [
            'quality' => $quality
        ]);

        if (array_key_exists(self::FUNCTION_COMPRESS, $params)) {
            if ($params[self::FUNCTION_COMPRESS] && !is_null($this->options['tinyPng']['apiKey'])) {
                $result = $this->tiny->compress($cacheFile);
                $result->writeTo($cacheFile);
            }
        }

        return $cacheUrl;
    }

    /**
     * Check hex color
     * @param string $hex
     * @return int
     */
    private function checkHexColor($hex)
    {
        return preg_match('/#([a-f]|[A-F]|[0-9]){3}(([a-f]|[A-F]|[0-9]){3})?\b/', $hex);
    }

    /**
     * Crop image
     * @param array $params
     */
    private function crop(array $params)
    {
        $x = (isset($params['x']) && is_numeric($params['x'])) ? $params['x'] : 0;
        $y = (isset($params['y']) && is_numeric($params['y'])) ? $params['y'] : 0;

        $width = (isset($params['width']) && is_numeric($params['width'])) ? $params['width'] : null;
        $height = (isset($params['height']) && is_numeric($params['height'])) ? $params['height'] : null;

        if (is_null($width) || is_null($height)) {
            throw new Exception('Wrong crop width or height');
        }

        $this->image->crop(new Point($x, $y), new Box($width, $height));
    }

    /**
     * Resize image
     * @param array $params
     */
    private function resize(array $params)
    {
        $width = (isset($params['width']) && is_numeric($params['width'])) ? $params['width'] : null;
        $height = (isset($params['height']) && is_numeric($params['height'])) ? $params['height'] : null;

        if (!is_null($width) && !is_null($height)) {
            $this->image->resize(new Box($width, $height));
        } elseif (!is_null($width)) {
            $height = $this->image->getSize()->getHeight() / ($this->image->getSize()->getWidth() / $width);

            $this->image->resize(new Box($width, $height));
        } elseif (!is_null($height)) {
            $width = $this->image->getSize()->getWidth() / ($this->image->getSize()->getHeight() / $height);

            $this->image->resize(new Box($width, $height));
        } else {
            throw new Exception('Wrong resize width or height');
        }
    }

    /**
     * Make thumbnail image
     * @param array $params
     */
    private function thumbnail(array $params)
    {
        $mode = isset($params['mode']) ? $params['mode'] : self::THUMBNAIL_OUTBOUND;

        $width = (isset($params['width']) && is_numeric($params['width'])) ? $params['width'] : null;
        $height = (isset($params['height']) && is_numeric($params['height'])) ? $params['height'] : null;

        if (is_null($width) || is_null($height)) {
            throw new Exception('Wrong thumbnail width or height');
        }

        $this->image = $this->image->thumbnail(new Box($width, $height), $mode);
    }

    /**
     * Add watermark to image
     * @param array $params Set watermark params
     * @throws Exception Bad params
     */
    private function watermark(array $params)
    {
        if (isset($params['posX']) && is_numeric($params['posX'])) {
            $posX = $params['posX'];
        } else {
            $posX = null;
        }

        if (isset($params['posY']) && is_numeric($params['posY'])) {
            $posY = $params['posY'];
        } else {
            $posY = null;
        }

        if (is_null($posX) || is_null($posY)) {
            throw new Exception('Wrong watermark coordinates');
        }

        if (isset($params['width']) && is_numeric($params['width'])) {
            $width = $params['width'];
        } else {
            $width = 0;
        }

        if (isset($params['height']) && is_numeric($params['height'])) {
            $height = $params['height'];
        } else {
            $height = 0;
        }

        $mode = isset($params['mode']) ? $params['mode'] : self::THUMBNAIL_OUTBOUND;

        if (isset($params['image']) && file_exists(\Yii::getAlias($params['image']))) {
            $watermarkPath = \Yii::getAlias($params['image']);
        } else {
            $watermarkPath = null;
        }

        if (is_null($watermarkPath)) {
            throw new Exception('Incorrect watermark image path');
        }

        $watermark = Image::getImagine()->open($watermarkPath);

        if ($this->image->getSize()->getHeight() < $posY + $watermark->getSize()->getHeight() ||
            $this->image->getSize()->getWidth() < $posX + $watermark->getSize()->getWidth()
        ) {
            throw new Exception('Cannot paste watermark of the given size at the specified position, as it moves outside of the image\'s box');
        }

        if ($width > 0 && $height > 0) {
            $height = $this->image->getSize()->getHeight();
            $width = $this->image->getSize()->getWidth();

            $watermark = $watermark->thumbnail(new Box($width, $height), $mode);
        }

        if ($posX < 0) {
            $posX = $this->image->getSize()->getWidth() - abs($posX) - $watermark->getSize()->getWidth();
        }

        if ($posY < 0) {
            $posY = $this->image->getSize()->getHeight() - abs($posY) - $watermark->getSize()->getHeight();
        }

        $position = new Point($posX, $posY);

        $this->image->paste($watermark, $position);
    }
}
