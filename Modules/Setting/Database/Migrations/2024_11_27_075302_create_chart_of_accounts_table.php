<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up()
    {
        Schema::create('chart_of_accounts', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->string('account_number')->unique();
            $table->enum('category', [
                'Akun Piutang',
                'Aktiva Lancar Lainnya',
                'Kas & Bank',
                'Persediaan',
                'Aktiva Tetap',
                'Aktiva Lainnya',
                'Depresiasi & Amortisasi',
                'Akun Hutang',
                'Kartu Kredit',
                'Kewajiban Lancar Lainnya',
                'Kewajiban Jangka Panjang',
                'Ekuitas',
                'Pendapatan',
                'Pendapatan Lainnya',
                'Harga Pokok Penjualan',
                'Beban',
                'Beban Lainnya'
            ]);
            $table->unsignedBigInteger('parent_account_id')->nullable();
            $table->unsignedBigInteger('tax_id')->nullable();
            $table->text('description')->nullable();
            $table->timestamps();

            $table->foreign('parent_account_id')->references('id')->on('chart_of_accounts');
            $table->foreign('tax_id')->references('id')->on('taxes');
        });
    }

    public function down()
    {
        Schema::dropIfExists('chart_of_accounts');
    }
};
