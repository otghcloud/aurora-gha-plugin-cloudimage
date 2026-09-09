<?php

namespace OTGH\GHARM\CloudImage;

use App\Exceptions\ProvisioningException;
use App\Services\Builds\RunnerImagesLocator;
use App\Services\Builds\TemplateCatalog;
use App\Services\Builds\TemplateCatalogEntry;
use Illuminate\Support\Facades\File;

/** Prepares the single archive executed by the guest-owned Cloud Image runner. */
final class GuestBuildBundle
{
    private function __construct(
        public readonly string $directory,
        public readonly string $archive,
    ) {}

    /**
     * @param  array<string, mixed>  $manifest
     * @param  array<string, mixed>  $runtime
     */
    public static function prepare(
        TemplateCatalogEntry $entry,
        string $templateDirectory,
        array $manifest,
        array $runtime,
        RunnerImagesLocator $runnerImages,
        TemplateCatalog $catalog,
    ): self {
        $directory = sys_get_temp_dir().'/gha-build-'.bin2hex(random_bytes(12));
        $archive = $directory.'.tar.gz';
        $runnerRoot = $runnerImages->filesystemRoot($entry);
        $runner = rtrim($templateDirectory, '/').'/guest-runner.py';

        if ($runnerRoot === null || ! is_file($runner)) {
            throw new ProvisioningException('The installed Cloud Image template does not contain the guest runner assets.');
        }

        File::ensureDirectoryExists($directory);
        File::copyDirectory($templateDirectory, $directory.'/template');
        File::copyDirectory($runnerRoot, $directory.'/runner-images');
        File::copyDirectory($catalog->root().'/scripts', $directory.'/catalog/scripts');
        file_put_contents($directory.'/manifest.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)."\n");
        file_put_contents($directory.'/runtime.json', json_encode($runtime, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)."\n");
        copy($runner, $directory.'/guest-runner.py');
        chmod($directory.'/guest-runner.py', 0755);

        try {
            $tar = new \PharData($directory.'.tar');
            $tar->buildFromDirectory($directory);
            $tar->compress(\Phar::GZ);
            unset($tar);
            unlink($directory.'.tar');
        } catch (\Throwable $exception) {
            self::deletePath($directory);
            @unlink($archive);

            throw new ProvisioningException('Could not create the Cloud Image guest build archive: '.$exception->getMessage(), previous: $exception);
        }

        return new self($directory, $archive);
    }

    public function delete(): void
    {
        self::deletePath($this->directory);
        @unlink($this->archive);
    }

    private static function deletePath(string $path): void
    {
        if (is_dir($path)) {
            File::deleteDirectory($path);
        }
    }
}
