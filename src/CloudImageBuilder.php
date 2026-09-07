<?php

namespace OTGH\GHARM\CloudImage;

use App\Contracts\Builds\BuilderInterface;
use App\Contracts\Builds\BuildResult;
use App\Exceptions\ProvisioningException;
use App\Models\BuildCredential;
use App\Models\Credential;
use App\Models\ImageBuild;
use App\Services\Builds\RunnerImagesLocator;
use App\Services\Builds\TemplateCatalog;
use App\Services\Builds\TemplateCatalogEntry;
use App\Services\Proxmox\ProxmoxClient;
use App\Services\Ssh\SshConnection;

final class CloudImageBuilder implements BuilderInterface
{
    private const GUEST_IP_TIMEOUT_SECONDS = 300;

    public function __construct(
        private readonly RunnerImagesLocator $runnerImages = new RunnerImagesLocator,
        private readonly TemplateCatalog $catalog = new TemplateCatalog,
    ) {}

    public function type(): string
    {
        return 'cloudimage';
    }

    public function build(
        ImageBuild $build,
        TemplateCatalogEntry $entry,
        string $templateDirectory,
    ): BuildResult {
        $target = $build->proxmoxTarget;
        $template = $build->runnerTemplate;
        $credential = $build->credentialSnapshot ?: $build->credential ?: Credential::query()
            ->where('name', 'Default Linux SSH')
            ->first();

        if ($target === null || $template === null || $build->template_vmid === null) {
            throw new ProvisioningException('The cloud image build has incomplete Proxmox metadata.');
        }

        if ($credential === null || ! $credential->hasSshKeyMaterial()) {
            throw new ProvisioningException('The cloud image build has no usable SSH key credential; Ubuntu cloud images disable password login by default.');
        }

        $artifact = $entry->builder()['artifact'] ?? [];
        $requirements = $entry->requirements();
        $proxmox = new ProxmoxClient($target);
        $vmid = (int) $build->template_vmid;
        $created = false;

        try {
            $source = $artifact['file'] ?? null;
            $isoStorage = (string) ($target->build_iso_storage ?: $target->build_vm_storage);
            $vmStorage = (string) $target->build_vm_storage;

            if (! is_string($source) || $source === '') {
                $url = $artifact['url'] ?? null;

                if (! is_string($url) || $url === '' || $isoStorage === '') {
                    throw new ProvisioningException('The cloud image builder requires an artifact file or URL and image storage.');
                }

                $source = $proxmox->downloadCloudImage($isoStorage, $url);
            }

            if ($vmStorage === '') {
                throw new ProvisioningException('The target has no VM storage configured for cloud images.');
            }

            $proxmox->createCloudImageVm(
                vmid: $vmid,
                name: $template->vmName(),
                cores: (int) ($requirements['cpu_cores'] ?? 2),
                memory: (int) ($requirements['memory_mb'] ?? 4096),
                networkAdapter: $target->networkAdapter(),
            );
            $created = true;
            $proxmox->importCloudImage($vmid, $vmStorage, $source);
            $proxmox->resizeCloudImageDisk($vmid, ((int) ($requirements['disk_gb'] ?? 25)).'G');
            $proxmox->configureCloudInit(
                vmid: $vmid,
                username: (string) $credential->resolvedUsername(),
                password: null,
                publicKey: $credential->public_key,
                ipConfig: 'ip=dhcp',
            );
            $proxmox->start($vmid);

            $ip = $this->awaitGuestIp($proxmox, $vmid);
            $this->runManifestCommands($build, $entry, $templateDirectory, $ip, $credential);
            // A hard stop() cuts power before buffered writes reach disk, which previously baked
            // zero-byte files (the actions-runner tarball's own contents included) into the
            // sealed template - see ProxmoxClient::shutdown() for the full explanation.
            $proxmox->shutdown($vmid);
            $proxmox->convertToTemplate($vmid);

            return new BuildResult(true, 0, $vmid);
        } catch (\Throwable $exception) {
            if ($created) {
                try {
                    $proxmox->destroy($vmid);
                } catch (\Throwable) {
                    // Preserve the original build failure; cleanup is retried by reconciliation.
                }
            }

            throw $exception;
        }
    }

    private function awaitGuestIp(ProxmoxClient $proxmox, int $vmid): string
    {
        $deadline = microtime(true) + self::GUEST_IP_TIMEOUT_SECONDS;

        while (microtime(true) < $deadline) {
            $ip = $proxmox->guestIpv4($vmid);

            if ($ip !== null) {
                return $ip;
            }

            sleep(3);
        }

        throw new ProvisioningException("Cloud image VM {$vmid} never reported an IPv4 address.");
    }

    private function runManifestCommands(
        ImageBuild $build,
        TemplateCatalogEntry $entry,
        string $templateDirectory,
        string $ip,
        Credential|BuildCredential $credential,
    ): void {
        $manifestPath = rtrim($templateDirectory, '/').'/build.json';

        if (! is_readable($manifestPath)) {
            return;
        }

        $manifest = json_decode((string) file_get_contents($manifestPath), true);

        if (! is_array($manifest)) {
            throw new ProvisioningException('The cloud image build manifest is invalid.');
        }

        $ssh = new SshConnection(
            host: $ip,
            port: 22,
            username: (string) $credential->resolvedUsername(),
            password: null,
            privateKey: $credential->private_key,
            // Build stages can go quiet for a while (e.g. a silent package download); phpseclib's
            // timeout is inactivity-based, not a hard cap, but a low value makes that more likely.
            timeout: 300,
        );

        try {
            foreach ($manifest['stage_groups'] ?? [] as $group) {
                foreach ($group['stages'] ?? [] as $stage) {
                    // Written unconditionally so upload-only stages (no `script`) still mark
                    // themselves seen; BuildProgress scans the log for these markers, not uploads.
                    file_put_contents((string) $build->log_path, ($stage['marker'] ?? '')."\n", FILE_APPEND);

                    $this->uploadStageFiles($ssh, $entry, $templateDirectory, $stage);
                    $this->runStageScript($ssh, $build, $templateDirectory, $stage);
                }
            }

            // Belt and braces alongside the graceful shutdown in build(): ext4's delayed
            // allocation can leave a just-written file's data sitting in page cache for tens of
            // seconds, so force it to disk before the guest is asked to power off.
            $ssh->run('sync');
        } finally {
            $ssh->disconnect();
        }
    }

    /**
     * Each stage's work is a real bash script on disk (`stage-scripts/<id>.sh`), uploaded and run
     * as a single SSH command - not a JSON array of ad-hoc command strings. That avoids needing to
     * double-escape shell syntax through JSON, lets a stage `export` its own env vars normally
     * instead of repeating them on every line, and opens far fewer SSH channels per build (each of
     * which is a chance for phpseclib's channel bookkeeping to get confused on a long build).
     *
     * @param  array<string, mixed>  $stage
     */
    private function runStageScript(SshConnection $ssh, ImageBuild $build, string $templateDirectory, array $stage): void
    {
        $script = $stage['script'] ?? null;

        if (! is_string($script) || $script === '') {
            return;
        }

        if ($script === '' || str_contains($script, '..')) {
            throw new ProvisioningException('Cloud image manifest contains an invalid script path.');
        }

        $localPath = rtrim($templateDirectory, '/').'/'.ltrim($script, '/');

        if (! is_readable($localPath)) {
            throw new ProvisioningException('Cloud image stage script does not exist: '.$localPath);
        }

        $remotePath = '/tmp/gha-stage-'.($stage['id'] ?? 'unknown').'.sh';
        $statusPath = $remotePath.'.status';
        $ssh->putFile($remotePath, $localPath)->chmod(0755, $remotePath);

        $environment = $this->stageEnvironment($build, $stage['environment'] ?? []);
        // `</dev/null` so a script that unexpectedly hits an interactive prompt (e.g. apt asking
        // "Do you want to continue?") fails fast on EOF instead of hanging the build forever - our
        // exec channel has no stdin to answer it, and a PTY-less SSH exec doesn't supply one.
        $inner = $environment === []
            ? 'bash '.escapeshellarg($remotePath)
            : 'export '.$this->exportEnvironment($environment).'; bash '.escapeshellarg($remotePath);

        // The script's real exit code is recorded on the guest rather than trusted from the SSH
        // channel: a package upgrade that restarts services can kill our session mid-stage, and
        // that surfaces as a clean exit status 0 even though the script never finished.
        $command = sprintf(
            'rm -f %s; { %s; } </dev/null; echo $? > %s',
            escapeshellarg($statusPath),
            $inner,
            escapeshellarg($statusPath)
        );

        $logPath = (string) $build->log_path;
        // Streamed rather than buffered, so the log reflects progress as the script runs instead
        // of appearing all at once when it finally exits.
        $ssh->run($command, function (string $chunk) use ($logPath): void {
            file_put_contents($logPath, $chunk, FILE_APPEND);
        });

        $this->assertStageSucceeded($ssh, $statusPath, (string) ($stage['id'] ?? 'unknown'));
    }

    /**
     * Read back the exit code the stage script recorded on the guest.
     *
     * A missing status file means the remote shell died before the script finished, which the
     * SSH channel on its own reports as success.
     */
    private function assertStageSucceeded(SshConnection $ssh, string $statusPath, string $stageId): void
    {
        $status = trim($ssh->run('cat '.escapeshellarg($statusPath).' 2>/dev/null || true'));

        if ($status === '') {
            throw new ProvisioningException(
                'Cloud image stage did not run to completion: '.$stageId.'. The remote shell exited '
                .'before the stage script finished, most likely because a service restart dropped the session.'
            );
        }

        if ($status !== '0') {
            throw new ProvisioningException('Cloud image stage failed: '.$stageId.' (exit code '.$status.').');
        }
    }

    /** @param array<string, mixed> $stage */
    private function uploadStageFiles(SshConnection $ssh, TemplateCatalogEntry $entry, string $templateDirectory, array $stage): void
    {
        $runnerRoot = $this->runnerImages->filesystemRoot($entry);

        foreach ($stage['uploads'] ?? [] as $upload) {
            if (! is_array($upload)) {
                continue;
            }

            $source = $upload['source'] ?? null;
            $destination = $upload['destination'] ?? null;

            if (! is_string($source) || ! is_string($destination) || $source === '' || $destination === '' || str_contains($source, '..')) {
                throw new ProvisioningException('Cloud image manifest contains an invalid upload path.');
            }

            $root = ($upload['source_root'] ?? 'runner_images') === 'catalog_root'
                ? $this->catalog->root()
                : (($upload['source_root'] ?? 'runner_images') === 'template' ? $templateDirectory : $runnerRoot);

            if ($root === null) {
                throw new ProvisioningException('The cloud image manifest requires runner-images files, but no scripts root is available.');
            }

            $localPath = rtrim($root, '/').'/'.ltrim($source, '/');
            $sourceIsDirectory = is_dir($localPath);
            $files = $sourceIsDirectory ? $this->filesIn($localPath) : [$localPath];

            if ($files === [] || ! is_readable($localPath)) {
                throw new ProvisioningException('Cloud image upload source does not exist: '.$localPath);
            }

            $remoteRoot = $sourceIsDirectory ? $destination : dirname($destination);
            $ssh->run('sudo mkdir -p '.escapeshellarg($remoteRoot).' && sudo chmod 0777 '.escapeshellarg($remoteRoot));

            foreach ($files as $file) {
                $relative = $sourceIsDirectory ? ltrim(substr($file, strlen(rtrim($localPath, '/'))), '/') : basename($destination);
                $remote = $sourceIsDirectory ? rtrim($destination, '/').'/'.$relative : $destination;
                $ssh->run('mkdir -p '.escapeshellarg(dirname($remote)));
                $ssh->putFile($remote, $file);
                $mode = (int) ($upload['mode'] ?? 0644);
                $ssh->chmod($mode, $remote);
            }
        }
    }

    /** @return list<string> */
    private function filesIn(string $directory): array
    {
        $files = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS));

        foreach ($iterator as $file) {
            if ($file->isFile()) {
                $files[] = $file->getPathname();
            }
        }

        sort($files);

        return $files;
    }

    /**
     * Resolves a stage's declared `environment` map to real values, applied once per stage (see
     * `runManifestCommands()`) rather than repeated on every command. A reference matching one of
     * the dynamic per-build values below is substituted; anything else is used as a literal
     * (e.g. static paths/flags that are safe to keep in the public manifest as-is).
     *
     * @param  array<string, mixed>  $declared
     */
    private function stageEnvironment(ImageBuild $build, array $declared): array
    {
        $account = $build->environment?->githubAccount;
        $available = [
            'github_api_token' => (string) ($account?->github_token ?? ''),
            'github_api_min_remaining' => (string) config('builds.github_api_min_remaining', 1000),
            'github_api_wait_buffer_seconds' => (string) config('builds.github_api_wait_buffer_seconds', 30),
        ];
        $resolved = [];

        foreach ($declared as $name => $reference) {
            if (! is_string($name) || ! is_string($reference)) {
                throw new ProvisioningException('Cloud image manifest declares an invalid environment value.');
            }

            $resolved[$name] = $available[$reference] ?? $reference;
        }

        return $resolved;
    }

    /** @param array<string, string> $environment */
    private function exportEnvironment(array $environment): string
    {
        return implode(' ', array_map(
            fn (string $name, string $value): string => $name.'='.escapeshellarg($value),
            array_keys($environment),
            $environment,
        ));
    }
}
