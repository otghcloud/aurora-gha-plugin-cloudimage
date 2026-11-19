<?php

namespace OTGH\GHARM\CloudImage;

use App\Contracts\Builds\BuilderInterface;
use App\Contracts\Builds\BuildResult;
use App\Enums\BuildStatus;
use App\Enums\PoolOs;
use App\Exceptions\ProvisioningException;
use App\Models\Builds\ImageBuild;
use App\Models\Credentials\BuildCredential;
use App\Models\Credentials\Credential;
use App\Services\Builds\GuestBuildCallbackService;
use App\Services\Builds\RunnerImagesLocator;
use App\Services\Builds\TemplateCatalog;
use App\Services\Builds\TemplateCatalogEntry;
use App\Services\Proxmox\ProxmoxClient;
use App\Services\SettingsRepository;
use App\Services\Ssh\SshConnection;
use Illuminate\Support\Facades\Log;

final class CloudImageBuilder implements BuilderInterface
{
    private const GUEST_IP_TIMEOUT_SECONDS = 300;

    private const GUEST_BUILD_TIMEOUT_SECONDS = 43200;

    private const GUEST_POLL_SECONDS = 2;

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
            if (! $this->runGuestBundle($build, $entry, $templateDirectory, $ip, $credential)) {
                return new BuildResult(true, 0, $vmid);
            }
            // A hard stop() cuts power before buffered writes reach disk, which previously baked
            // zero-byte files (the actions-runner tarball's own contents included) into the
            // sealed template.
            $proxmox->shutdown($vmid);
            $proxmox->convertToTemplate($vmid);

            return new BuildResult(true, 0, $vmid);
        } catch (\Throwable $exception) {
            // Reconciliation may have completed while this worker was blocked on SSH.
            if ($build->fresh()?->status === BuildStatus::Succeeded) {
                Log::warning('Cloud Image worker errored after the build was already completed', [
                    'build' => $build->id,
                    'error' => $exception->getMessage(),
                ]);

                return new BuildResult(true, 0, $vmid);
            }

            $keepFailedVm = $build->keep_failed_vm || app(SettingsRepository::class)->keepFailedBuildVm();

            if ($created && ! $keepFailedVm) {
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

    private function runGuestBundle(
        ImageBuild $build,
        TemplateCatalogEntry $entry,
        string $templateDirectory,
        string $ip,
        Credential|BuildCredential $credential,
    ): bool {
        $manifest = $this->resolvedManifest($build, $templateDirectory);
        $credentials = app(GuestBuildCallbackService::class)->issueCredentials($build);
        $remoteDirectory = GuestBuildPath::forBuild($build, $credential);
        $bundle = GuestBuildBundle::prepare(
            $entry,
            $templateDirectory,
            $manifest,
            [
                'callback_url' => $credentials['url'].'/api/builds/'.$build->id.'/events',
                'callback_token' => $credentials['token'],
                'environment' => $this->buildEnvironment($build, $entry),
            ],
            $this->runnerImages,
            $this->catalog,
        );

        $ssh = new SshConnection(
            host: $ip,
            port: 22,
            username: (string) $credential->resolvedUsername(),
            privateKey: $credential->private_key,
            timeout: 300,
        );

        try {
            $remoteArchive = $remoteDirectory.'.tar.gz';
            $ssh->putFile($remoteArchive, $bundle->archive);
            $ssh->run(sprintf(
                'rm -rf %1$s; mkdir -p %1$s; tar -xzf %2$s -C %1$s; nohup env GHA_BUILD_BUNDLE_DIR=%1$s python3 %1$s/guest-runner.py </dev/null >/dev/null 2>&1 &',
                escapeshellarg($remoteDirectory),
                escapeshellarg($remoteArchive),
            ));
            if (! $this->awaitGuestResult($build, $ssh, $remoteDirectory)) {
                return false;
            }

            $ssh->run('rm -rf '.escapeshellarg($remoteDirectory).' '.escapeshellarg($remoteArchive));

            return true;
        } finally {
            $ssh->disconnect();
            $bundle->delete();
        }
    }

    /** @return array<string, mixed> */
    private function resolvedManifest(ImageBuild $build, string $templateDirectory): array
    {
        $path = rtrim($templateDirectory, '/').'/build.json';
        $manifest = json_decode((string) file_get_contents($path), true);

        if (! is_array($manifest)) {
            throw new ProvisioningException('The cloud image build manifest is invalid.');
        }

        foreach ($manifest['stage_groups'] ?? [] as &$group) {
            foreach ($group['stages'] ?? [] as &$stage) {
                if (is_array($stage['environment'] ?? null)) {
                    $stage['environment'] = $this->stageEnvironment($build, $stage['environment']);
                }
            }
        }
        unset($group, $stage);

        return $manifest;
    }

    /** @return array<string, string> */
    private function buildEnvironment(ImageBuild $build, TemplateCatalogEntry $entry): array
    {
        $account = $build->environment?->githubAccount;
        $githubToken = (string) ($account?->github_token ?? '');

        return [
            // The generated configure stage consumes GH_API_TOKEN, while the
            // bundled curl wrapper reads GITHUB_TOKEN directly.
            'GH_API_TOKEN' => $githubToken,
            'GITHUB_TOKEN' => $githubToken,
            'GH_API_MIN_REMAINING' => (string) config('builds.github_api_min_remaining', 1000),
            'GH_API_WAIT_BUFFER_SECONDS' => (string) config('builds.github_api_wait_buffer_seconds', 30),
            'RUNNER_DIRECTORY' => $entry->runnerDirectory() ?? PoolOs::Linux->defaultRunnerDir(),
        ];
    }

    /** Returns whether this worker owns finalization, rather than a reconciler having completed it. */
    private function awaitGuestResult(ImageBuild $build, SshConnection $ssh, string $remoteDirectory): bool
    {
        $deadline = microtime(true) + self::GUEST_BUILD_TIMEOUT_SECONDS;
        $logOffset = 0;

        while (microtime(true) < $deadline) {
            $build->refresh();

            if ($build->status === BuildStatus::Succeeded) {
                return false;
            }

            if ($build->status->isFinished()) {
                throw new ProvisioningException('Cloud image guest build finished without success.');
            }

            if ($build->guest_outcome !== null) {
                if ($build->guest_outcome === 'succeeded') {
                    if (app(CloudImageGuestBuildReconciler::class)->claimFinalization($build)) {
                        return true;
                    }

                    // Another worker owns shutdown and conversion; do not touch its guest.
                    sleep(self::GUEST_POLL_SECONDS);

                    continue;
                }

                throw new ProvisioningException($build->guest_error ?: 'Cloud image guest build failed.');
            }

            try {
                $logOffset = $this->reconcileGuestLog($build, $ssh, $remoteDirectory, $logOffset);
                $result = trim($ssh->run('cat '.escapeshellarg($remoteDirectory.'/result.json').' 2>/dev/null || true'));
            } catch (\RuntimeException|\ErrorException) {
                // Callback delivery remains primary; a temporary SSH loss is reconciled next poll.
                sleep(self::GUEST_POLL_SECONDS);

                continue;
            }

            $decoded = json_decode($result, true);

            if (is_array($decoded) && isset($decoded['outcome'])) {
                if ($decoded['outcome'] !== 'succeeded') {
                    throw new ProvisioningException((string) ($decoded['error'] ?? 'Cloud image guest build failed.'));
                }

                $build->forceFill([
                    'guest_outcome' => 'succeeded',
                    'guest_exit_code' => $decoded['exit_code'] ?? 0,
                    'guest_error' => null,
                ])->save();

                continue;
            }

            sleep(self::GUEST_POLL_SECONDS);
        }

        throw new ProvisioningException('Timed out waiting for the Cloud Image guest build result.');
    }

    private function reconcileGuestLog(ImageBuild $build, SshConnection $ssh, string $remoteDirectory, int $offset): int
    {
        if ($build->log_path === null) {
            return $offset;
        }

        $encoded = trim($ssh->run(
            'tail -c +'.($offset + 1).' '.escapeshellarg($remoteDirectory.'/build.log').' 2>/dev/null | base64 -w 0'
        ));
        $contents = base64_decode($encoded, true);

        if ($contents === false || $contents === '') {
            return $offset;
        }

        file_put_contents($build->log_path, $contents, FILE_APPEND);

        return $offset + strlen($contents);
    }

    /** @param array<string, mixed> $declared */
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

}
