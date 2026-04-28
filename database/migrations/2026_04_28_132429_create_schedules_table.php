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
        Schema::create('schedules', function (Blueprint $table) {
            $table->id();
            $table->string('sched_code')->unique();
            $table->foreignId('subject_id')->cascadeOnUpdate();
            $table->string('semester');
            $table->string('school_year');
            $table->int('slots')->default(40);

            $table->softDeletes();
            $table->timestamps();
        });

        Schema::create('schedule_section', function (Blueprint $table) {
            $table->id();
            $table->foreignId('schedule_id')->cascadeOnUpdate();
            $table->string('program_code');
            $table->int('year_level')->nullable();
            $table->string('section');

            $table->timestamps();
        });

        Schema::create('schedule_room_time', function (Blueprint $table) {
            $table->id();
            $table->foreignId('schedule_id')->cascadeOnUpdate();
            $table->foreignId('room_id')->cascadeOnUpdate();

            $table->timestamps();
        });

        Schema::create('schedule_faculty', function (Blueprint $table) {
            $table->id();
            $table->foreignId('schedule_id')->cascadeOnUpdate();
            $table->foreignId('user_id')->cascadeOnUpdate();

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('schedules');
    }
};
