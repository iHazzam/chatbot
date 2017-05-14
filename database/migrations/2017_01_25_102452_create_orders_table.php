<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class CreateOrdersTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('orders', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('user_id');
            $table->string('address_line1');
            $table->string('address_line2')->nullable();
            $table->string('city');
            $table->string('postcode');
            $table->string('country');
            $table->string('email');
            $table->string('phone');
            $table->string('project_name');
            $table->string('purchase_order_reference');
            $table->date('delivery_date');
            $table->enum('delivery', ['delivery', 'collection','unconfirmed']);
            $table->decimal('order_total', 8, 2);
            $table->decimal('shipping_total', 8,2);
            $table->longText('incoterms')->nullable();;
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
        Schema::dropIfExists('orders');
    }
}
