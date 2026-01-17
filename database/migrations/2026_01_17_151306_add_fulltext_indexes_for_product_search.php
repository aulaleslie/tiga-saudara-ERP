<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Creates FULLTEXT indexes with ngram parser for typo-tolerant product search.
     * ngram token_size=2 allows matching partial words like "katrid" -> "CATRIDGE"
     */
    public function up(): void
    {
        // FULLTEXT index on products.product_name with ngram parser
        DB::statement('
            ALTER TABLE products
            ADD FULLTEXT INDEX ft_products_name (product_name) WITH PARSER ngram
        ');

        // FULLTEXT index on products.product_code with ngram parser
        DB::statement('
            ALTER TABLE products
            ADD FULLTEXT INDEX ft_products_code (product_code) WITH PARSER ngram
        ');

        // Combined FULLTEXT index for searching both name and code together
        DB::statement('
            ALTER TABLE products
            ADD FULLTEXT INDEX ft_products_name_code (product_name, product_code) WITH PARSER ngram
        ');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement('ALTER TABLE products DROP INDEX ft_products_name');
        DB::statement('ALTER TABLE products DROP INDEX ft_products_code');
        DB::statement('ALTER TABLE products DROP INDEX ft_products_name_code');
    }
};
