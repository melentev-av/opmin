<?php

namespace App\IfMatch4;

function kind(string $ext): string
{
    if ($ext === 'jpg' || $ext === 'png' || 'gif' === $ext) {
        return 'image';
    }
    if ($ext === 'mp4') {
        return 'video';
    }

    return 'file';
}

?>
-----
<?php

namespace App\IfMatch4;

function kind(string $ext): string
{
    return match ($ext) {
        'jpg', 'png', 'gif' => 'image',
        'mp4' => 'video',
        default => 'file',
    };
}

?>
