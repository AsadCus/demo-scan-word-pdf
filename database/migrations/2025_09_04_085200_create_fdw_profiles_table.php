<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('fdw_profiles', function (Blueprint $table) {
            $table->id();
            $table->string('name')->nullable();
            $table->string('photo_profile')->nullable();
            $table->string('dob')->nullable();
            $table->string('age')->nullable();
            $table->string('birth_place')->nullable();
            $table->string('height')->nullable();
            $table->string('weight')->nullable();
            $table->string('nationality')->nullable();
            $table->string('address')->nullable();
            $table->string('repatriation_to')->nullable();
            $table->string('contact_number')->nullable();
            $table->string('religion')->nullable();
            $table->string('education')->nullable();
            $table->string('siblings')->nullable();
            $table->string('marital_status')->nullable();
            $table->string('children')->nullable();
            $table->string('children_ages')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('fdw_profiles');
    }
};
