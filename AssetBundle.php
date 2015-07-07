<?php

namespace sadovojav\image;

/**
 * Class AssetBundle
 * @package adovojav\image
 */
class AssetBundle extends \yii\web\AssetBundle
{
    /**
     * @inherit
     */
    public $sourcePath = '@bower/holderjs';

    /**
     * @inherit
     */
    public $js = [
        'holder.min.js',
    ];
}