<?php

namespace OTGH\GHARM\CloudImage;

use App\Events\ImageBuildCancelling;
use App\Models\Credentials\BuildCredential;
use App\Models\Credentials\Credential;
use App\Services\Proxmox\ProxmoxClient;
use App\Services\SettingsRepository;
use App\Services\Ssh\SshConnection;
use Illuminate\Support\Facades\Log;

/** Cleans up an intermediate Cloud Image VM when its manager build is cancelled. */
final class CloudImageBuildCancellation
{
    private const GUEST_CANCEL_GRACE_SECONDS = 15;

    public function __construct(private readonly SettingsRepository $settings) {}

    public function __invoke(ImageBuildCancelling $event): void
    {
        $build = $event->build;

        if ($build->builder_type !== 'cloudimage' || $build->template_vmid === null || $build->proxmoxTarget === null) {
            return;
        }

        $proxmox = new ProxmoxClient($build->proxmoxTarget);
        $this->requestGuestCancellation($build, $proxmox);

        if ($build->keep_failed_vm || $this->settings->keepFailedBuildVm()) {
            return;
        }

        try {
            $proxmox->destroy($build->template_vmid);
        } catch (\Throwable $exception) {
            Log::warning('Could not destroy Cloud Image VM while cancelling build', [
                'build' => $build->id,
                'vmid' => $build->template_vmid,
                'error' => $exception->getMessage(),
            ]);
        }
    }

    private function requestGuestCancellation($build, ProxmoxClient $proxmox): void
    {
        $credential = $build->credentialSnapshot ?: $build->credential ?: Credential::query()
            ->where('name', 'Default Linux SSH')
            ->first();

        if (! $credential instanceof Credential && ! $credential instanceof BuildCredential) {
            return;
        }

        if (! $credential->hasSshKeyMaterial()) {
            return;
        }

        try {
            $ip = $proxmox->guestIpv4($build->template_vmid);

            if ($ip === null) {
                return;
            }

            $ssh = new SshConnection(
                host: $ip,
                port: 22,
                username: $credential->resolvedUsername(),
                privateKey: $credential->private_key,
                timeout: self::GUEST_CANCEL_GRACE_SECONDS,
            );
            $remoteDirectory = GuestBuildPath::forBuild($build, $credential);
            $ssh->putString($remoteDirectory.'/cancel.request', "cancelled by manager\n");

            $deadline = microtime(true) + self::GUEST_CANCEL_GRACE_SECONDS;
            while (microtime(true) < $deadline) {
                $result = trim($ssh->run('cat '.escapeshellarg($remoteDirectory.'/result.json').' 2>/dev/null || true'));

                if ($result !== '') {
                    break;
                }

                sleep(1);
            }

            $ssh->disconnect();
        } catch (\Throwable $exception) {
            Log::info('Could not request graceful Cloud Image guest cancellation', [
                'build' => $build->id,
                'vmid' => $build->template_vmid,
                'error' => $exception->getMessage(),
            ]);
        }
    }
}
