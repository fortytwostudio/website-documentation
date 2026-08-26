<?php

namespace fortytwostudio\websitedocumentation\elementtypes;

use Craft;
use fortytwostudio\websitedocumentation\base\ElementType;

class MatrixType extends ElementType
{
    public static function displayName(): string
    {
        return Craft::t('websitedocumentation', 'Matrix');
    }

    public static function hasTitle(): bool
    {
        return false;
    }

    public static function hasUrl(): bool
    {
        return false;
    }

    public static function hasNewWindow(): bool
    {
        return false;
    }

    public static function getColor(): string
    {
        return '#7c3aed';
    }
}
