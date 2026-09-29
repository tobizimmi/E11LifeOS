<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Encrypts what health entries are about (medication, check-up) and the blood type, and all
     * documents, receipts and handover photos stored so far.
     */
    public function up(): void
    {
        Schema::table('health_entries', function (Blueprint $table) {
            $table->text('title')->change();
            $table->text('dosage')->nullable()->change();
        });

        Schema::table('health_profiles', function (Blueprint $table) {
            $table->text('blood_type')->nullable()->change();
        });

        $this->transform('health_entries', ['title', 'dosage'], fn (string $value) => Crypt::encryptString($value));
        $this->transform('health_profiles', ['blood_type'], fn (string $value) => Crypt::encryptString($value));

        Artisan::call('files:encrypt');
    }

    public function down(): void
    {
        $this->transform('health_entries', ['title', 'dosage'], fn (string $value) => Crypt::decryptString($value));
        $this->transform('health_profiles', ['blood_type'], fn (string $value) => Crypt::decryptString($value));
    }

    /**
     * @param  list<string>  $columns
     */
    private function transform(string $table, array $columns, callable $callback): void
    {
        DB::table($table)->orderBy('id')->each(function (object $row) use ($table, $columns, $callback) {
            $values = collect($columns)->filter(fn (string $column) => $row->{$column} !== null && $row->{$column} !== '')
                ->mapWithKeys(fn (string $column) => [$column => $callback($row->{$column})])->all();

            if ($values !== []) {
                DB::table($table)->where('id', $row->id)->update($values);
            }
        });
    }
};
