<?php
namespace Muffin\Slug;

/**
 * Slugger interface.
 */
interface SluggerInterface
{
    /**
     * Return a URL safe version of a string.
     *
     * @param string $string Original string.
     * @param string $separator Separator.
     * @return string
     */
    public static function slug($string, $separator = '-');
}
