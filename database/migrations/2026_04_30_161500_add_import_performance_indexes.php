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
        Schema::table('entities', function (Blueprint $table) {
            $table->index('name', 'entities_name_idx');
            $table->index('root_entity_id', 'entities_root_entity_id_idx');
        });

        Schema::table('attribute_values', function (Blueprint $table) {
            $table->index(['entity_id', 'attribute_id'], 'attribute_values_entity_attribute_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('attribute_values', function (Blueprint $table) {
            $table->dropIndex('attribute_values_entity_attribute_idx');
        });

        Schema::table('entities', function (Blueprint $table) {
            $table->dropIndex('entities_root_entity_id_idx');
            $table->dropIndex('entities_name_idx');
        });
    }
};
