<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

class FindMissingTranslations extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'lang:missing 
                            {--skip-json : Skip json file}
                            {--lang= : The language to check the translations for. Default is "en"}
                            {file?*}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Scan app and views folders for missing translations keys.';

    /**
     * Language file names contained in lang folder
     */
    protected array $langFileNames = [];

    protected string $lang = 'en';

    protected Collection $unusedKeys;

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $directories = ['app', 'resources/views'];
        $translationKeys = collect([]);

        $this->lang = $this->option('lang') ?? 'en';

        if (! File::exists("lang/{$this->lang}") && ! File::exists("lang/{$this->lang}.json")) {
            $this->error("No translations found for lang \"{$this->lang}\".");

            return 1;
        }

        if ($this->argument('file')) {
            $this->langFileNames = collect($this->argument('file'))->map(fn ($file) => Str::replaceLast('.php', '', $file))->toArray();
        } else {
            $this->langFileNames = $this->getLanguageFileNames();
        }

        foreach ($directories as $directory) {
            $this->info("Scanning directory $directory ...");

            $this->withProgressBar(collect(File::allFiles(base_path($directory))), function ($file) use (&$translationKeys) {
                // $this->line("- Scanning file $file ...");
                preg_match_all("/__\('([^']+)'\)/", File::get($file), $matches);
                $translationKeys = $translationKeys->when(! empty($matches[1]),
                    fn ($collection) => $collection->merge($matches[1])->unique()
                );
            });
            $this->newLine(2);
        }

        $this->info("Total keys found: {$translationKeys->count()}");

        if (! $this->option('skip-json')) {
            $this->info("Checking $this->lang.json ...");

            $jsonLangFilePath = "lang/{$this->lang}.json";
            if (File::exists($jsonLangFilePath)) {
                $translations = $this->withProgressBar(
                    collect(json_decode(File::get($jsonLangFilePath), true)),
                    function ($key, $value) use (&$translationKeys) {
                        $translationKeys = $translationKeys->filter(fn ($k) => $k !== $key);
                    });
                $this->newLine(2);
            }
        }

        foreach ($this->langFileNames as $fileName) {
            if (File::exists("lang/{$this->lang}/$fileName.php")) {
                $translations = File::getRequire(base_path("lang/{$this->lang}/$fileName.php"));

                $this->info("Checking $fileName.php ...");

                foreach ($translations as $key => $value) {
                    if (is_array($value)) {
                        $this->withProgressBar(
                            collect($this->getArrayPaths($value))->map(fn ($v) => $fileName.'.'.$key.'.'.$v),
                            function ($path) use (&$translationKeys) {
                                $translationKeys = $translationKeys->filter(fn ($k) => $k !== "$path");
                            }
                        );
                        $this->newLine();
                    } else {
                        $translationKeys = $translationKeys->filter(fn ($k) => $k !== "$fileName.$key");
                    }
                }
                $this->newLine();
            } else {
                $this->error("File $fileName.php not found.");

                return 1;
            }
        }

        if ($translationKeys->isEmpty()) {
            $this->newLine(2);
            $this->info('All keys are translated.');

        } else {
            $this->newLine(2);
            $this->info("Found {$translationKeys->count()} missing keys:");
            $translationKeys->each(fn ($key) => $this->warn($key));
        }
    }

    /**
     * Get all language file names.
     *
     * Excluding laravel and jetstream defaults (auth, pagination, passwords, validation)
     */
    public function getLanguageFileNames(): array
    {
        $excludedFileNames = ['auth.php', 'pagination.php', 'passwords.php', 'validation.php'];
        $langFolderPath = "lang/{$this->lang}";

        if (File::exists($langFolderPath)) {
            $files = File::files($langFolderPath);
            foreach ($files as $file) {
                if (! in_array($file->getFilename(), $excludedFileNames) && File::isFile("lang/en/{$file->getFilename()}")) {
                    $langFileNames[] = pathinfo($file, PATHINFO_FILENAME);
                }
            }

            $this->newLine();
            $this->info('Loaded language files: '.implode(', ', collect($langFileNames)->map(fn ($name) => "$name.php")->toArray()));
            $this->newLine();
        } else {
            $this->newLine();
            $this->error('Lang folder not found.');
            $this->newLine();
        }

        return $langFileNames;
    }

    /**
     * Return all paths of a multidimensional array in dot notation.
     */
    public function getArrayPaths(array $array, $prefix = '', $depth = 0): Collection
    {
        return collect($array)->flatMap(function ($value, $key) use ($prefix, $depth) {
            $currentPath = $prefix ? "$prefix.$key" : $key;

            return is_array($value) ? $this->getArrayPaths($value, $currentPath, $depth + 1) : [$currentPath];
        });
    }
}
