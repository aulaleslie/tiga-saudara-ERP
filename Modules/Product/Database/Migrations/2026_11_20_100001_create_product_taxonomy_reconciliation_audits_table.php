<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_taxonomy_reconciliation_audits', function (Blueprint $table) {
            $table->id();
            $table->string('plan_fingerprint', 64)->index();
            $table->string('operator', 191);
            $table->string('status', 32)->default('completed');
            $table->unsignedInteger('category_groups_merged')->default(0);
            $table->unsignedInteger('categories_removed')->default(0);
            $table->unsignedInteger('brand_groups_merged')->default(0);
            $table->unsignedInteger('brands_removed')->default(0);
            $table->unsignedInteger('product_category_references_updated')->default(0);
            $table->unsignedInteger('category_parent_references_updated')->default(0);
            $table->unsignedInteger('product_brand_references_updated')->default(0);
            $table->longText('mapping_payload');
            $table->longText('summary_payload')->nullable();
            $table->timestamp('executed_at');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_taxonomy_reconciliation_audits');
    }
};
