<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 1. Add the new columns as nullable so existing rows survive.
        Schema::table('users', function (Blueprint $table) {
            $table->string('first_name')->nullable()->after('id');
            $table->string('middle_name', 50)->nullable()->after('first_name');
            $table->string('last_name')->nullable()->after('middle_name');
            $table->string('internal_id', 50)->nullable()->after('last_name');
            $table->string('phone', 30)->nullable()->after('internal_id');
            $table->string('address')->nullable()->after('phone');
            $table->date('start_date')->nullable()->after('address');
            $table->date('end_date')->nullable()->after('start_date');
        });

        // 2. Backfill: split existing `name` on whitespace.
        DB::table('users')->orderBy('id')->lazy()->each(function ($u) {
            $parts = preg_split('/\s+/', trim((string) ($u->name ?? '')));
            $first = array_shift($parts) ?: 'Onbekend';
            $last = array_pop($parts) ?: 'Onbekend';
            $middle = $parts ? implode(' ', $parts) : null;

            DB::table('users')->where('id', $u->id)->update([
                'first_name' => $first,
                'middle_name' => $middle,
                'last_name' => $last,
                'start_date' => optional($u->created_at)
                    ? (new DateTime($u->created_at))->format('Y-m-d')
                    : now()->toDateString(),
            ]);
        });

        // 3. Tighten NOT NULL on the required fields.
        Schema::table('users', function (Blueprint $table) {
            $table->string('first_name')->nullable(false)->change();
            $table->string('last_name')->nullable(false)->change();
            $table->date('start_date')->nullable(false)->change();
        });

        // 4. Drop the old `name` column.
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('name');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('name')->nullable()->after('id');
        });

        DB::table('users')->orderBy('id')->lazy()->each(function ($u) {
            $name = trim(implode(' ', array_filter([
                $u->first_name,
                $u->middle_name,
                $u->last_name,
            ])));
            DB::table('users')->where('id', $u->id)->update(['name' => $name ?: 'Onbekend']);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->string('name')->nullable(false)->change();
            $table->dropColumn([
                'first_name', 'middle_name', 'last_name',
                'internal_id', 'phone', 'address',
                'start_date', 'end_date',
            ]);
        });
    }
};
