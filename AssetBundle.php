<?php

namespace sadovojav\image;

/**
 * Class AssetBundle
 * @package sadovojav\image
 */
class AssetBundle extends \yii\web\AssetBundle
{
    public $sourcePath = '@bower/holderjs';

    public $js = [
        'holder.min.js',
    ];
}