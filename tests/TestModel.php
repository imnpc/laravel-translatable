<?php

namespace SolutionForest\Translatable\Test;

use Illuminate\Database\Eloquent\Model;
use SolutionForest\Translatable\HasTranslations;

class TestModel extends Model
{
    use HasTranslations;

    protected $table = 'test_models';

    protected $guarded = [];
    public $timestamps = false;

    public $translatable = ['name', 'other_field'];
}
