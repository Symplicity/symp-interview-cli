<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddCandidateGrade extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('code_server_instances', function (Blueprint $table) {
            $table->string('hire_recommendation_level')->nullable(); // Strong no hire, no hire, hire, strong hire
            $table->string('position_level_recommendation')->nullable(); // Junior, Plain, Senior, Tech Lead
            $table->string('general_score')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        //
    }
}
