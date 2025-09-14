<?php

namespace Modules\Upload\Entities;

use App\Models\BaseModel;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

class Upload extends BaseModel implements HasMedia
{
    use InteractsWithMedia;

    protected $guarded = [];


}
