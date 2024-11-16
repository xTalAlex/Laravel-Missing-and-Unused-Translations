<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

class FindUnusedTranslations extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'lang:unused 
                            {--skip-json : Skip json file}
                            {--lang= : The language to check the translations for. Default is "en"}
                            {file?*}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Scan app and views folders for unused translations keys.';

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
        $this->unusedKeys = collect([]);
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

        if (! $this->option('skip-json')) {
            $this->info("Checking $this->lang.json ...");

            $jsonLangFilePath = "lang/{$this->lang}.json";
            if (File::exists($jsonLangFilePath)) {
                $translations = $this->withProgressBar(
                    collect(json_decode(File::get($jsonLangFilePath), true)),
                    function ($key, $value) {
                        $this->unusedKeys = $this->unusedKeys->merge($this->searchKeyThroughFiles($key));
                    });
                $this->newLine();
            }
        }

        foreach ($this->langFileNames as $fileName) {
            if (File::exists("lang/{$this->lang}/$fileName.php")) {
                $translations = File::getRequire(base_path("lang/{$this->lang}/$fileName.php"));

                $this->info("Checking $fileName.php ...");

                foreach ($translations as $key => $value) {
                    if (is_array($value)) {
                        $this->withProgressBar(collect($this->getArrayPaths($value))->map(fn ($v) => $fileName.'.'.$key.'.'.$v),
                            function ($path) {
                                $this->unusedKeys = $this->unusedKeys->merge($this->searchKeyThroughFiles($path));
                            });
                        $this->newLine();
                    } else {
                        $this->unusedKeys = $this->unusedKeys->merge($this->searchKeyThroughFiles("$fileName.$key"));
                    }
                }
                $this->newLine();
            } else {
                $this->error("File $fileName.php not found.");

                return 1;
            }
        }

        if ($this->unusedKeys->isEmpty()) {
            $this->newLine(2);
            $this->info('All keys are used.');

        } else {
            $this->newLine(2);
            $this->info("Found {$this->unusedKeys->count()} unused keys:");
            $this->unusedKeys->each(fn ($key) => $this->warn($key));
        }
    }

    /**
     * Search for a key in all files.
     *
     * Only works with __() helper function and on Windows.
     */
    public function searchKeyThroughFiles($key): array
    {
        $unusedKeys = [];

        $outViews = exec('findstr /S /M /C:"__(\''.$key.'\'" resources\views\*');
        $outApp = exec('findstr /S /M /C:"__(\''.$key.'\'" app\*');

        if (strlen($outViews) <= 0 && strlen($outApp) <= 0) {
            $unusedKeys[] = $key;
        }

        if (count($unusedKeys)) {
            $this->newLine();
            $this->warn($key);
        }

        return $unusedKeys;
    }

    /**
     * Get all language file names.
     *
     * Excluding laravel and jetstream defaults (auth, pagination, passwords, validation)
     */
    public function getLanguageFileNames(): array
    {
        $langFileNames = [];
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
