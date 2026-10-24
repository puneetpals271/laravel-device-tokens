<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;

return new class extends Migration
{
    /**
     * Two nullable columns on Sanctum's token table so each device token
     * carries the platform and app build that issued it. Rows are deleted,
     * rotated and pruned together with the token, so nothing can go stale.
     */
    public function up(): void
    {
        Schema::table($this->table(), function (Blueprint $table) {
            if (! Schema::hasColumn($this->table(), 'platform')) {
                $table->string('platform', 50)->nullable()->after('name');
            }

            if (! Schema::hasColumn($this->table(), 'app_version')) {
                $table->string('app_version', 50)->nullable()->after('platform');
            }
        });
    }

    public function down(): void
    {
        Schema::table($this->table(), function (Blueprint $table) {
            $table->dropColumn(['platform', 'app_version']);
        });
    }

    protected function table(): string
    {
        return (new Sanctum::$personalAccessTokenModel)->getTable();
    }
};
