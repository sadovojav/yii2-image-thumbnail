<?php

namespace sadovojav\image;

use Yii;
use yii\web\View;
use yii\base\Exception;
use yii\helpers\Html;
use yii\helpers\FileHelper;
use yii\imagine\Image;
use Imagine\Image\Box;
use Imagine\Image\Point;
use Imagine\Image\ManipulatorInterface;

/**
 * Class Thumbnail
 * @package sadovojav\image
 */
class Thumbnail extends \yii\base\Component
{
    /**
     * @var string
     */
    public $cachePath = '@runtime/thumbnails';

    /**
     * @var string
     */
    public $basePath = '@webroot';

    /**
     * @var int
     */
    public $cacheExpire = 604800;

    /**
     * @var array
     */
    public $options = [];

    /**
     * @var
     */
    private $image;

    /**
     * @var array
     */
    private $defaultOptions = [
        'placeholder' => [
            'type' => Thumbnail::PLACEHOLDER_TYPE_URL,
            'backgroundColor' => '#f5f5f5',
            'textColor' => '#cdcdcd',
            'text' => 'Ooops!'
        ],
        'quality' => 92
    ];

    const THUMBNAIL_OUTBOUND = ManipulatorInterface::THUMBNAIL_OUTBOUND;
    const THUMBNAIL_INSET = ManipulatorInterface::THUMBNAIL_INSET;

    const PLACEHOLDER_TYPE_JS = 'js';
    const PLACEHOLDER_TYPE_URL = 'url';

    const FUNCTION_CROP = 'crop';
    const FUNCTION_RESIZE = 'resize';
    const FUNCTION_THUMBNAIL = 'thumbnail';

    public function init()
    {
        $this->options = array_merge($this->options, $this->defaultOptions);

        if ($this->options['placeholder']['type'] == Thumbnail::PLACEHOLDER_TYPE_JS) {
            list(,$path) = \Yii::$app->assetManager->publish(__DIR__."/assets");

            Yii::$app->getView()->registerJsFile($path . '/js/holder.min.js', [
                'position' => View::POS_END
            ]);
        }
    }

    /**
     * Creates and caches the image thumbnail and returns <img> tag
     * @param string $file
     * @param array $params
     * @param array $options
     * @return string
     */
    public function img($file, $params, $options = [])
    {
        $cacheFileSrc = $this->make($file, $params);

        if (!$cacheFileSrc) {
            if (isset($params['placeholder'])) {
                return $this->placeholder($params['placeholder'], $options);
            } else {
                return null;
            }
        }

        return Html::img($cacheFileSrc, $options);
    }

    /**
     * Creates and caches the image thumbnail and returns image url
     * @param string $file
     * @param array $params
     * @return string
     */
    public function url($file, $params)
    {
        $cacheFileSrc = $this->make($file, $params);

        return $cacheFileSrc ? $cacheFileSrc : null;
    }

    /**
     * Image placeholder
     * @param array $params
     * @param array $options
     * @return null|string
     */
    public function placeholder(array $params, $options = [])
    {
        $placeholder = null;

        $width = (isset($params['width']) && is_numeric($params['width'])) ? $params['width'] : null;
        $height = (isset($params['height']) && is_numeric($params['height'])) ? $params['height'] : null;

        if (is_null($width) || is_null($height)) {
            throw new Exception('Wrong placeholder width or height');
        }

        if (isset($options['backgroundColor']) && $this->checkHexColor($options['backgroundColor'])) {
            $backgroundColor = $options['backgroundColor'];
        } else {
            $backgroundColor = $this->options['placeholder']['backgroundColor'];
        }

        if (isset($options['textColor']) && $this->checkHexColor($options['textColor'])) {
            $textColor = $options['textColor'];
        } else {
            $textColor = $this->options['placeholder']['textColor'];
        }

        $text = !empty($params['text']) ? $params['text'] : $this->options['placeholder']['text'];

        if ($this->options['placeholder']['type'] == self::PLACEHOLDER_TYPE_URL) {
            $placeholder = $this->urlPlaceholder($width, $height, $text, $backgroundColor, $textColor, $options);
        } elseif ($this->options['placeholder']['type'] == self::PLACEHOLDER_TYPE_JS) {
            $placeholder = $this->jsPlaceholder($width, $height, $text, $backgroundColor, $textColor, $options);
        }

        return $placeholder;
    }

    /**
     * Return url placeholder image
     * @param integer $width
     * @param integer $height
     * @param string $text
     * @param string $backgroundColor
     * @param string $textColor
     * @param array $options
     * @return string
     */
    private function urlPlaceholder($width, $height, $text, $backgroundColor, $textColor, $options)
    {
        $src = 'http://placehold.it/' . $width . 'x' . $height . '/' . str_replace('#', '', $backgroundColor) . '/' .
            str_replace('#', '', $textColor) . '&text=' . $text;

        return Html::img($src, $options);
    }

    /**
     * Return js placeholder image
     * @param integer $width
     * @param integer $height
     * @param string $text
     * @param string $backgroundColor
     * @param string $textColor
     * @param array $options
     * @return string
     */
    private function jsPlaceholder($width, $height, $text, $backgroundColor, $textColor, $options)
    {
        $src = 'holder.js/' . $width . 'x' . $height . '/' . $backgroundColor . ':' . $textColor . '/text:' . $text;

        return Html::img('', array_merge($options, ['data-src' => $src]));
    }

    /**
     * Make image and save to cache
     * @param string $file
     * @param array $params
     * @return string
     */
    private function make($file, $params)
    {
        $file = FileHelper::normalizePath(Yii::getAlias($this->basePath . '/' . $file));

        if (!is_file($file)) {
            return false;
        }

        $quality = isset($params['quality']) ? $params['quality'] : $this->options['quality'];

        $cacheFileName = md5($file . serialize($params) . $quality . filemtime($file));
        $cacheFileExt = strrchr($file, '.');
        $cacheFileDir = DIRECTORY_SEPARATOR . substr($cacheFileName, 0, 2);
        $cacheFilePath = Yii::getAlias($this->cachePath) . $cacheFileDir;
        $cacheFile = $cacheFilePath . DIRECTORY_SEPARATOR . $cacheFileName . $cacheFileExt;
        $cacheUrl = str_replace('\\', '/', preg_replace('/^@[a-z]+/', '', $this->cachePath) . $cacheFileDir . DIRECTORY_SEPARATOR
            . $cacheFileName . $cacheFileExt);

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

        $this->image = Image::getImagine()->open($file);

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
            }
        }

        $this->image->save($cacheFile, [
            'quality' => $quality
        ]);

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
}
