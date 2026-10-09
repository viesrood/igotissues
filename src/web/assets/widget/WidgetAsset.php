<?php

declare(strict_types=1);

namespace viesrood\igotissues\web\assets\widget;

use craft\web\AssetBundle;

/**
 * The report button and its window. No dependencies, so it loads the same on
 * the front end as in the control panel.
 */
class WidgetAsset extends AssetBundle
{
    public $sourcePath = __DIR__ . '/dist';

    public $js = [
        'igotissues.js',
    ];

    public $css = [
        'igotissues.css',
    ];

    public $jsOptions = ['defer' => true];
}
