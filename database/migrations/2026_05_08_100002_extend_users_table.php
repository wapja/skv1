<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('organisation_id')
                ->nullable()
                ->after('id')
                ->constrained('organisations')
                ->restrictOnDelete();

            $table->string('password')->nullable()->change();

            $table->boolean('is_super_admin')->default(false)->after('organisation_id');
            $table->index('is_super_admin');

            $table->string('status')->default('pending_activation')->after('email_verified_at');
            $table->string('activation_token')->nullable()->after('status');
            $table->timestamp('activation_expires_at')->nullable()->after('activation_token');
            $table->timestamp('activated_at')->nullable()->after('activation_expires_at');
            $table->text('two_factor_secret')->nullable()->after('activated_at');
            $table->timestamp('two_factor_enabled_at')->nullable()->after('two_factor_secret');
            $table->string('locale', 5)->default('nl')->after('two_factor_enabled_at');

            $table->softDeletes();

            $table->index(['organisation_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['organisation_id']);
            $table->dropIndex(['organisation_id', 'status']);
            $table->dropIndex(['is_super_admin']);
            $table->dropSoftDeletes();
            $table->dropColumn([
                'organisation_id', 'is_super_admin', 'status',
                'activation_token', 'activation_expires_at', 'activated_at',
                'two_factor_secret', 'two_factor_enabled_at',
                'locale',
            ]);
        });
    }
};
