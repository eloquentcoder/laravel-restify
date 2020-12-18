<?php

namespace Binaryk\LaravelRestify\Commands;

use Carbon\Carbon;
use Doctrine\DBAL\Schema\Column;
use Faker\Generator as Faker;
use Illuminate\Console\Command;
use Illuminate\Console\ConfirmableTrait;
use Illuminate\Database\ConnectionResolverInterface as Resolver;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class StubCommand extends Command
{
    use ConfirmableTrait;

    protected $signature = 'restify:stub {table} {--count= : The number of entries}';

    protected $description = 'Based on table definition, will try to seed the table with mock data.';

    /**
     * @var Faker
     */
    private $faker;

    public function __construct(Resolver $resolver, Faker $faker)
    {
        parent::__construct();
        $this->resolver = $resolver;
        $this->faker = $faker;
    }

    public function handle()
    {
        if (! $this->confirmToProceed()) {
            return true;
        }

        if (! $this->resolver->connection()->getSchemaBuilder()->hasTable($table = $this->argument('table'))) {
            return false;
        }

        DB::connection()->getDoctrineSchemaManager()->getDatabasePlatform()->registerDoctrineTypeMapping('enum', 'string');

        $start = microtime(true);
        Collection::times($count = $this->option('count') ?? 1)->each(fn () => $this->make($table));

        $time = round(microtime(true) - $start, 2);

        $this->info("Seeded {$count} {$table} in {$time} seconds");
    }

    protected function make($table)
    {
        $data = [];

        collect(Schema::getColumnListing($table))->each(function ($column) use (&$data, $table) {
            $connection = Schema::getConnection();
            /** * @var Column $columnDefinition */
            $columnDefinition = $connection->getDoctrineColumn($table, $column);

            $type = $columnDefinition->getType()->getName();

            switch ($type) {
                case 'string':
                    $data[$column] = $this->faker->text(50);

                    if (Str::contains($column, 'email')) {
                        $data[$column] = $this->faker->email;
                    }

                    if (Str::contains($column, 'password')) {
                        $data[$column] = Hash::make('secret');
                    }

                    if (Str::contains($column, 'uuid')) {
                        $data[$column] = Str::orderedUuid();
                    }

                    if (Str::contains($column, 'image') || Str::contains($column, 'picture')) {
                        $data[$column] = $this->faker->imageUrl();
                    }
                    break;
                case 'datetime':
                    $data[$column] = Carbon::now();
                    break;
                case 'boolean':
                    $data[$column] = $this->faker->boolean;
                    break;
                case 'bigint':
                case 'int':
                case 'integer':
                    if ($columnDefinition->getAutoincrement() === true) {
                        //primary key
                        return;
                    }

                    if (Str::endsWith($column, '_id')) {
                        $guessTable = Str::pluralStudly(Str::beforeLast($column, '_id'));
                        if (Schema::hasTable($guessTable)) {
                            $data[$column] = optional(DB::table($guessTable)->inRandomOrder()->first())->id ?? $this->faker->randomNumber(4);
                        }
                    } else {
                        $data[$column] = $this->faker->randomNumber(4);
                    }
                    break;
            }
        });

        $id = DB::table($table)->insertGetId($data);

        $this->info('Created '.Str::singular(Str::studly($table)).' with id:'.$id);
    }
}
