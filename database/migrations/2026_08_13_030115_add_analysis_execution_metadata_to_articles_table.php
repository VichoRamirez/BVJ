<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('articles', function (Blueprint $table) {
            $table->unsignedSmallInteger('analysis_attempts')->default(0);
            $table->text('analysis_error')->nullable();
            $table->timestamp('analysis_started_at')->nullable();
            $table->timestamp('analysis_completed_at')->nullable();
            $table->uuid('analysis_run_id')->nullable();
        });

        DB::table('analyses')
            ->select(['id', 'article_id', 'analyzed_at'])
            ->chunkById(100, function (Collection $analyses): void {
                foreach ($analyses as $analysis) {
                    $article = DB::table('articles')
                        ->where('id', $analysis->article_id)
                        ->where('analysis_status', 'completed')
                        ->first(['id', 'updated_at', 'analysis_attempts']);

                    if ($article === null) {
                        continue;
                    }

                    DB::table('articles')->where('id', $article->id)->update([
                        'analysis_attempts' => max(1, (int) $article->analysis_attempts),
                        'analysis_completed_at' => $analysis->analyzed_at ?? $article->updated_at,
                    ]);
                }
            }, 'id', 'id');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('articles', function (Blueprint $table) {
            $table->dropColumn([
                'analysis_attempts',
                'analysis_error',
                'analysis_started_at',
                'analysis_completed_at',
                'analysis_run_id',
            ]);
        });
    }
};
