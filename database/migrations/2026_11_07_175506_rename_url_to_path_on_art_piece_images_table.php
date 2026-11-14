<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Images are now uploaded files, so the column holds a storage path
     * (e.g. "art-pieces/abc.jpg") instead of an external URL.
     */
    public function up(): void
    {
        Schema::table('art_piece_images', function (Blueprint $table) {
            $table->renameColumn('url', 'path');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('art_piece_images', function (Blueprint $table) {
            $table->renameColumn('path', 'url');
        });
    }
};
