<?php

namespace App\Support;

use Illuminate\Support\HtmlString;

/**
 * The two pieces of text with markup around them in the file fields of the admin panel (Task #95).
 * The words come from the dictionary, the markup stays here, so a translator never touches it.
 */
class AdminUpload
{
    /**
     * What the drop area of a file field says. FilePond styles the "Browse" link by its class.
     */
    public static function placeholder(): string
    {
        $browse = '<span class="filepond--label-action">'.e(__('admin.upload.browse')).'</span>';

        return str_replace(':browse', $browse, e(__('admin.upload.placeholder')));
    }

    /**
     * The link under a file field that downloads the sample CSV file.
     */
    public static function sampleLink(string $path, string $text): HtmlString
    {
        return new HtmlString('<a href="'.e($path).'" class="text-primary-600 underline font-medium" download>'.e($text).'</a>');
    }
}
