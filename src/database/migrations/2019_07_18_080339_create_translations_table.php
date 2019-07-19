<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class CreateTranslationsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('sf_translations', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->integer('translatable_id');
            $table->string('translatable_type', 250);
            $table->string('lang', 20);
            $table->char('searchable', 1);
            $table->text('content', 1);
            $table->string('content_key', 250);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('sf_translations');
    }
}
