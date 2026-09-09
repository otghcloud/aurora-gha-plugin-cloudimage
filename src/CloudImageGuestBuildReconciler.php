<?php

namespace OTGH\GHARM\CloudImage;

use App\Contracts\Builds\BuildResult;
use App\Enums\BuildStatus;
use App\Models\Builds\ImageBuild;
use App\Models\Credentials\BuildCredential;
use App\Models\Credentials\Credential;
use App\Services\Builds\ImageBuildFinalizer;
use App\Services\Builds\TemplateRebuilder;
use App\Services\Proxmox\ProxmoxClient;
use App\Services\SettingsRepository;
use App\Services\Ssh\SshConnection;
use Illuminate\Support\Facades\Log;

/** Reconciles guest-owned Cloud Image builds after missed callbacks or manager restarts. */
final class CloudImageGuestBuildReconciler
{
    private const FINALIZATION_LEASE_SECONDS = 900;

    public function __construct(
        private readonly ImageBuildFinalizer $finalizer,
        private readonly TemplateRebuilder $rebuilder,
        private readonly SettingsRepository $settings,
    ) {}

    public function reconcile(): int
    {
        $count = 0;
        $builds = ImageBuild::query()
            ->where('builder_type', 'cloudimage')
            ->where('status', BuildStatus::Running->value)
            ->whereNotNull('guest_callback_token_hash')
            ->get();

        foreach ($builds as $build) {
            try {
                if ($this->reconcileBuild($build)) {
                    $count++;
                }
            } catch (\Throwable $exception) {
                Log::warning('Could not reconcile Cloud Image guest build', [
                    'build' => $build->id,
                    'error' => $exception->getMessage(),
                ]);
            }
        }

        return $count;
    }

    private function reconcileBuild(ImageBuild $build): bool
    {
        $build->refresh();

        if ($build->guest_outcome === null) {
            $this->recoverGuestResult($build);
            $build->refresh();
        }

        if ($build->guest_outcome === null || ! $this->claimFinalization($build)) {
            return false;
        }

        $proxmox = new ProxmoxClient($build->proxmoxTarget);

        if ($build->guest_outcome === 'succeeded') {
            $proxmox->shutdown((int) $build->template_vmid);
            $proxmox->convertToTemplate((int) $build->template_vmid);
            $this->finalizer->complete($build, new BuildResult(true, 0, (int) $build->template_vmid));

            return true;
        }

        if (! $build->keep_failed_vm && ! $this->settings->keepFailedBuildVm()) {
            $proxmox->destroy((int) $build->template_vmid);
        }

        if ($build->guest_outcome === 'cancelled') {
            $build->forceFill([
                'status' => BuildStatus::Cancelled,
                'exit_code' => $build->guest_exit_code ?? 130,
                'finished_at' => now(),
            ])->save();
            $this->rebuilder->advanceBatch($build->refresh());
        } else {
            $this->finalizer->complete($build, new BuildResult(false, $build->guest_exit_code ?? 1));
        }

        return true;
    }

    private function claimFinalization(ImageBuild $build): bool
    {
        $staleBefore = now()->subSeconds(self::FINALIZATION_LEASE_SECONDS);

        return ImageBuild::query()
            ->whereKey($build->id)
            ->where('status', BuildStatus::Running->value)
            ->where(function ($query) use ($staleBefore): void {
                $query->whereNull('guest_finalizing_at')
                    ->orWhere('guest_finalizing_at', '<', $staleBefore);
            })
            ->update(['guest_finalizing_at' => now()]) === 1;
    }

    private function recoverGuestResult(ImageBuild $build): void
    {
        if ($build->proxmoxTarget === null || $build->template_vmid === null) {
            return;
        }

        $credential = $build->credentialSnapshot ?: $build->credential ?: Credential::query()
            ->where('name', 'Default Linux SSH')
            ->first();

        if (! $credential instanceof Credential && ! $credential instanceof BuildCredential) {
            return;
        }

        $proxmox = new ProxmoxClient($build->proxmoxTarget);
        $ip = $proxmox->guestIpv4($build->template_vmid);

        if ($ip === null) {
            return;
        }

        $ssh = new SshConnection(
            host: $ip,
            port: 22,
            username: $credential->resolvedUsername(),
            privateKey: $credential->private_key,
            timeout: 30,
        );
        $directory = GuestBuildPath::forBuild($build, $credential);

        try {
            $this->reconcileGuestLog($build, $ssh, $directory);
            $result = json_decode(trim($ssh->run('cat '.escapeshellarg($directory.'/result.json').' 2>/dev/null || true')), true);

            if (! is_array($result) || ! isset($result['outcome'])) {
                return;
            }

            $build->forceFill([
                'guest_outcome' => $result['outcome'],
                'guest_exit_code' => $result['exit_code'] ?? 1,
                'guest_error' => $result['error'] ?? null,
            ])->save();
        } finally {
            $ssh->disconnect();
        }
    }

    private function reconcileGuestLog(ImageBuild $build, SshConnection $ssh, string $directory): void
    {
        if ($build->log_path === null) {
            return;
        }

        $offset = is_file($build->log_path) ? filesize($build->log_path) : 0;
        $encoded = trim($ssh->run('tail -c +'.($offset + 1).' '.escapeshellarg($directory.'/build.log').' 2>/dev/null | base64 -w 0'));
        $contents = base64_decode($encoded, true);

        if ($contents !== false && $contents !== '') {
            file_put_contents($build->log_path, $contents, FILE_APPEND);
        }
    }
}
