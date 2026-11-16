<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Each rule gives one role to an OU's people, a group's members, or one user.
        Schema::create('access_microsoft_ad_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('role_id')->constrained('access_roles')->cascadeOnDelete();
            $table->string('type', 10);
            $table->string('value', 500);
            $table->boolean('sub_ous')->default(true);
            $table->unsignedInteger('matched')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('access_microsoft_ad_rules');
    }
};
