<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('keycloak_issuer')->nullable();
            $table->string('keycloak_subject')->nullable()->unique();
            $table->timestamp('keycloak_verified_at')->nullable();
            $table->boolean('keycloak_bootstrap_admin')->default(false);
            $table->boolean('is_active')->default(true);
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['keycloak_subject']);
            $table->dropColumn(['keycloak_issuer', 'keycloak_subject', 'keycloak_verified_at', 'keycloak_bootstrap_admin', 'is_active']);
        });
    }
};
