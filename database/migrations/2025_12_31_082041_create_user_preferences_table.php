<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateUserPreferencesTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('user_preferences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')
                  ->constrained()
                  ->cascadeOnDelete();
            $table->string('language', 5)->default('fr');
            $table->boolean('dark_mode')->default(false);
            $table->boolean('push_enabled')->default(true);
            $table->boolean('email_notifications')->default(true);
            $table->boolean('sms_notifications')->default(true);
            $table->boolean('transaction_notifications')->default(true);
            $table->boolean('security_alerts')->default(true);
            $table->boolean('daily_report')->default(false);
            $table->boolean('weekly_report')->default(false);
            $table->boolean('monthly_report')->default(false);
            $table->boolean('incident_reports')->default(true);
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
        Schema::dropIfExists('user_preferences');
    }
}
