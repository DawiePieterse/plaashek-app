<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Livestock types (PLAN.md section 2.2, phase 4b): farms keep more than cattle. Each species carries its own
 * rules, all editable in Kudde's settings: what its classes are called, how long its young count as young, its
 * gestation, how often to weigh, the word for a birth, and whether its young are tagged at weaning (pigs).
 *
 * Every animal gets a species; those already on file are cattle, which is all Kudde knew until now. Tags become
 * unique per species (owner's call): sheep 001 and cow 001 may both exist.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kudde_species', function (Blueprint $table) {
            $table->id();
            // Stable key for code and imports ("cattle"); the names are what the screens show.
            $table->string('code', 30)->unique();
            $table->string('name_en', 50);
            $table->string('name_af', 50);
            $table->boolean('enabled')->default(false);
            $table->unsignedSmallInteger('sort_order')->default(0);
            // {"en": {"young_female": "heifer calf", ...}, "af": {...}}: a name per class role in each language.
            $table->json('class_names');
            $table->unsignedSmallInteger('young_until_days');
            $table->unsignedSmallInteger('gestation_days');
            // Null: Kudde's own "weigh every" setting.
            $table->unsignedSmallInteger('weigh_every_days')->nullable();
            // {"en": "calving", "af": "kalwing"}
            $table->json('birth_word');
            $table->boolean('young_tagged_at_weaning')->default(false);
            $table->timestamps();
        });

        $now = now();
        foreach (self::SEED as $i => $species) {
            DB::table('kudde_species')->insert([
                'code' => $species['code'],
                'name_en' => $species['name'][0],
                'name_af' => $species['name'][1],
                'enabled' => $species['code'] === 'cattle',
                'sort_order' => $i,
                'class_names' => json_encode(['en' => array_combine(self::ROLES, $species['en']), 'af' => array_combine(self::ROLES, $species['af'])]),
                'young_until_days' => $species['young'],
                'gestation_days' => $species['gestation'],
                'weigh_every_days' => null,
                'birth_word' => json_encode(['en' => $species['birth'][0], 'af' => $species['birth'][1]]),
                'young_tagged_at_weaning' => $species['weaning'],
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        $cattle = DB::table('kudde_species')->where('code', 'cattle')->value('id');

        Schema::table('kudde_animals', function (Blueprint $table) {
            $table->unsignedBigInteger('species_id')->nullable()->after('id');
        });
        DB::table('kudde_animals')->update(['species_id' => $cattle]);
        Schema::table('kudde_animals', function (Blueprint $table) {
            $table->unsignedBigInteger('species_id')->nullable(false)->change();
            $table->foreign('species_id')->references('id')->on('kudde_species');
            $table->dropUnique(['tag']);
            $table->unique(['species_id', 'tag']);
            $table->index('tag');
        });
    }

    public function down(): void
    {
        Schema::table('kudde_animals', function (Blueprint $table) {
            $table->dropForeign(['species_id']);
            $table->dropUnique(['species_id', 'tag']);
            $table->dropIndex(['tag']);
            $table->dropColumn('species_id');
            $table->unique('tag');
        });
        Schema::dropIfExists('kudde_species');
    }

    /** The class roles, in the order a herd summary lists them. Mirrors App\Modules\Kudde\Models\Species::ROLES. */
    private const ROLES = ['mother', 'female', 'male', 'castrated', 'young_female', 'young_male', 'young_castrated'];

    private const SEED = [
        ['code' => 'cattle', 'name' => ['Cattle', 'Beeste'],
            'en' => ['cow', 'heifer', 'bull', 'steer', 'heifer calf', 'bull calf', 'steer calf'],
            'af' => ['koei', 'vers', 'bul', 'os', 'verskalf', 'bulkalf', 'oskalf'],
            'young' => 365, 'gestation' => 283, 'birth' => ['calving', 'kalwing'], 'weaning' => false],
        ['code' => 'sheep', 'name' => ['Sheep', 'Skape'],
            'en' => ['ewe', 'ewe', 'ram', 'wether', 'ewe lamb', 'ram lamb', 'wether lamb'],
            'af' => ['ooi', 'ooi', 'ram', 'hamel', 'ooilam', 'ramlam', 'hamellam'],
            'young' => 180, 'gestation' => 147, 'birth' => ['lambing', 'lam'], 'weaning' => false],
        ['code' => 'goats', 'name' => ['Goats', 'Bokke'],
            'en' => ['doe', 'doe', 'buck', 'wether', 'doe kid', 'buck kid', 'wether kid'],
            'af' => ['ooi', 'ooi', 'bok', 'kapater', 'ooilam', 'boklam', 'kapaterlam'],
            'young' => 180, 'gestation' => 150, 'birth' => ['kidding', 'lam'], 'weaning' => false],
        ['code' => 'pigs', 'name' => ['Pigs', 'Varke'],
            'en' => ['sow', 'gilt', 'boar', 'barrow', 'piglet', 'piglet', 'piglet'],
            'af' => ['sog', 'jongsog', 'beer', 'burg', 'varkie', 'varkie', 'varkie'],
            'young' => 70, 'gestation' => 114, 'birth' => ['farrowing', 'kleinmaak'], 'weaning' => true],
    ];
};
