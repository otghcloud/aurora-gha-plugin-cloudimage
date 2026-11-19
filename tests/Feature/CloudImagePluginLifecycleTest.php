<?php

namespace Tests\Feature;

require_once dirname(__DIR__, 2).'/src/GuestBuildPath.php';
require_once dirname(__DIR__, 2).'/src/GuestBuildBundle.php';
require_once dirname(__DIR__, 2).'/src/CloudImageBuilder.php';
require_once dirname(__DIR__, 2).'/src/CloudImageGuestBuildReconciler.php';
require_once dirname(__DIR__, 2).'/src/CloudImageBuildCancellation.php';

use App\Enums\BuildStatus;
use App\Exceptions\ProvisioningException;
use App\Models\Builds\ImageBuild;
use App\Models\Credentials\BuildCredential;
use App\Models\GitHub\GitHubAccount;
use App\Models\Infrastructure\Environment;
use App\Models\Infrastructure\ProxmoxTarget;
use App\Models\Templates\RunnerTemplate;
use App\Services\Builds\BuildCanceller;
use App\Services\Builds\ImageBuildFinalizer;
use App\Services\Builds\RunnerImagesLocator;
use App\Services\Builds\TemplateCatalog;
use App\Services\Builds\TemplateCatalogEntry;
use App\Services\Builds\TemplateRebuilder;
use App\Services\Proxmox\ProxmoxClient;
use App\Services\SettingsRepository;
use App\Services\Ssh\SshConnection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Mockery;
use OTGH\GHARM\CloudImage\CloudImageBuilder;
use OTGH\GHARM\CloudImage\CloudImageGuestBuildReconciler;
use OTGH\GHARM\CloudImage\GuestBuildBundle;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use ReflectionMethod;
use Tests\TestCase;

final class CloudImageTestSshConnection
{
    /** @var array<int, string> */
    public static array $responses = [];

    /** @var array<int, array{string, string}> */
    public static array $uploadedFiles = [];

    /** @var array<int, string> */
    public static array $commands = [];

    public static int $disconnects = 0;

    public static array $timeouts = [];

    public function __construct(
        public readonly string $host,
        public readonly int $port,
        public readonly string $username,
        public readonly ?string $password = null,
        public readonly ?string $privateKey = null,
        public readonly ?string $passphrase = null,
        public readonly int $timeout = 300,
    ) {
        self::$timeouts[] = $timeout;
    }

    public function putFile(string $remotePath, string $localPath): self
    {
        self::$uploadedFiles[] = [$remotePath, $localPath];

        return $this;
    }

    public function run(array|string $commands, ?callable $onOutput = null): string
    {
        self::$commands[] = is_array($commands) ? implode("\n", $commands) : $commands;

        return array_shift(self::$responses) ?? '';
    }

    public function disconnect(): void
    {
        self::$disconnects++;
    }
}

class CloudImagePluginLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private ImageBuild $build;

    private string $fixtureRoot;

    protected function setUp(): void
    {
        if (in_array($this->name(), [
            'test_ssh_result_fallback_is_persisted_and_claimed_before_worker_finalization',
            'test_guest_bootstrap_is_detached_and_removes_the_uploaded_bundle',
            'test_restart_recovery_reads_result_file_and_finalizes_the_guest_vm',
        ], true) && ! class_exists(SshConnection::class, false)) {
            class_alias(CloudImageTestSshConnection::class, SshConnection::class);
        }
        CloudImageTestSshConnection::$responses = [];
        CloudImageTestSshConnection::$uploadedFiles = [];
        CloudImageTestSshConnection::$commands = [];
        CloudImageTestSshConnection::$disconnects = 0;
        CloudImageTestSshConnection::$timeouts = [];

        parent::setUp();

        $account = GitHubAccount::create([
            'account_type' => 'organization',
            'login' => 'plugin-test',
            'github_token' => 'test-token',
            'github_webhook_secret' => 'test-secret',
        ]);
        $environment = Environment::create([
            'name' => 'Cloud Image Test',
            'slug' => 'cloud-image-test',
            'github_account_id' => $account->id,
        ]);
        $target = ProxmoxTarget::create([
            'name' => 'PVE Test',
            'slug' => 'pve-test',
            'proxmox_url' => 'https://pve.example.test:8006/api2/json',
            'proxmox_node' => 'pve-test',
            'proxmox_token_id' => 'root@pam!runner',
            'proxmox_token_secret' => 'test-token-secret',
        ]);
        $template = RunnerTemplate::create([
            'environment_id' => $environment->id,
            'name' => 'Ubuntu Test',
            'os' => 'linux',
            'template_catalog_id' => 'ubuntu-test',
        ]);
        $this->build = ImageBuild::create([
            'environment_id' => $environment->id,
            'runner_template_id' => $template->id,
            'proxmox_target_id' => $target->id,
            'template_catalog_id' => 'ubuntu-test',
            'builder_type' => 'cloudimage',
            'status' => BuildStatus::Running,
            'template_vmid' => 9000,
            'guest_callback_token_hash' => hash('sha256', 'test-callback-token'),
        ]);

        $this->fixtureRoot = dirname(__DIR__, 2).'/storage/framework/testing/cloudimage-'.bin2hex(random_bytes(6));
        mkdir($this->fixtureRoot, 0750, true);
    }

    protected function tearDown(): void
    {
        if (isset($this->fixtureRoot) && is_dir($this->fixtureRoot)) {
            File::deleteDirectory($this->fixtureRoot);
        }

        parent::tearDown();
    }

    public function test_guest_bundle_contains_template_runtime_runner_and_scripts_and_is_deleted(): void
    {
        [$entry, $templateDirectory, $runnerRoot, $catalogRoot] = $this->bundleFixture();
        $images = Mockery::mock(RunnerImagesLocator::class);
        $images->shouldReceive('filesystemRoot')->once()->with($entry)->andReturn($runnerRoot);
        $catalog = Mockery::mock(TemplateCatalog::class);
        $catalog->shouldReceive('root')->twice()->andReturn($catalogRoot);

        $bundle = GuestBuildBundle::prepare(
            $entry,
            $templateDirectory,
            ['stage_groups' => [['id' => 'base']]],
            ['callback_url' => 'https://manager.example.test/api/builds/1/events'],
            $images,
            $catalog,
        );

        $archive = new \PharData($bundle->archive);
        foreach ([
            'template/build.json',
            'template/stage.sh',
            'runner-images/runner.sh',
            'catalog/scripts/catalog-helper.sh',
            'manifest.json',
            'runtime.json',
            'guest-runner.py',
        ] as $member) {
            $this->assertTrue(isset($archive[$member]), "Expected {$member} in the guest archive.");
        }
        $this->assertSame(
            'https://manager.example.test/api/builds/1/events',
            json_decode(file_get_contents('phar://'.$bundle->archive.'/runtime.json'), true)['callback_url'],
        );

        $directory = $bundle->directory;
        $archivePath = $bundle->archive;
        $bundle->delete();

        $this->assertDirectoryDoesNotExist($directory);
        $this->assertFileDoesNotExist($archivePath);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_ssh_result_fallback_is_persisted_and_claimed_before_worker_finalization(): void
    {
        CloudImageTestSshConnection::$responses = ['{"outcome":"succeeded","exit_code":0}'];
        $ssh = new SshConnection('192.0.2.10', 22, 'runner');

        $completed = $this->awaitGuestResult($ssh);

        $this->assertTrue($completed);
        $this->assertSame('succeeded', $this->build->fresh()->guest_outcome);
        $this->assertNotNull($this->build->fresh()->guest_finalizing_at);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_guest_bootstrap_is_detached_and_removes_the_uploaded_bundle(): void
    {
        $this->build->proxmoxTarget->update(['cloudimage_ssh_timeout' => 123]);
        [$entry, $templateDirectory, $runnerRoot, $catalogRoot] = $this->bundleFixture();
        app(SettingsRepository::class)->set(SettingsRepository::BUILD_API_URL, 'https://manager.example.test');
        $images = Mockery::mock(RunnerImagesLocator::class);
        $images->shouldReceive('filesystemRoot')->once()->with($entry)->andReturn($runnerRoot);
        $catalog = Mockery::mock(TemplateCatalog::class);
        $catalog->shouldReceive('root')->twice()->andReturn($catalogRoot);

        CloudImageTestSshConnection::$responses = [
            '',
            '{"outcome":"succeeded","exit_code":0}',
            '',
        ];

        $method = new ReflectionMethod(CloudImageBuilder::class, 'runGuestBundle');
        $ran = $method->invoke(
            new CloudImageBuilder($images, $catalog),
            $this->build,
            $entry,
            $templateDirectory,
            '192.0.2.10',
            new BuildCredential([
                'username' => 'runner',
                'private_key' => 'test-private-key',
                'public_key' => 'ssh-ed25519 test-public-key',
            ]),
        );

        $this->assertTrue($ran);
        [$remoteArchive, $localArchive] = CloudImageTestSshConnection::$uploadedFiles[0];
        $this->assertStringEndsWith('.tar.gz', $remoteArchive);
        $this->assertFileDoesNotExist($localArchive);
        $this->assertStringContainsString('nohup env GHA_BUILD_BUNDLE_DIR=', CloudImageTestSshConnection::$commands[0]);
        $this->assertStringContainsString('python3 ', CloudImageTestSshConnection::$commands[0]);
        $this->assertStringContainsString('</dev/null >/dev/null 2>&1 &', CloudImageTestSshConnection::$commands[0]);
        $this->assertStringContainsString('rm -rf', CloudImageTestSshConnection::$commands[2]);
        $this->assertSame(1, CloudImageTestSshConnection::$disconnects);
        $this->assertSame([123], CloudImageTestSshConnection::$timeouts);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_restart_recovery_reads_result_file_and_finalizes_the_guest_vm(): void
    {
        $this->build->proxmoxTarget->update(['cloudimage_ssh_timeout' => 234]);
        $this->attachBuildCredential();
        $this->fakeProxmox(resultFromGuest: true);
        CloudImageTestSshConnection::$responses = ['{"outcome":"succeeded","exit_code":0}'];

        $count = $this->reconciler()->reconcile();

        $this->assertSame(1, $count);
        $this->assertSame(BuildStatus::Succeeded, $this->build->fresh()->status);
        $this->assertSame('succeeded', $this->build->fresh()->guest_outcome);
        $this->assertStringContainsString('result.json', CloudImageTestSshConnection::$commands[0]);
        $this->assertSame(1, CloudImageTestSshConnection::$disconnects);
        $this->assertSame([234], CloudImageTestSshConnection::$timeouts);
        Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
            && str_ends_with($request->url(), '/qemu/9000/status/shutdown'));
        Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
            && str_ends_with($request->url(), '/qemu/9000/template'));
    }

    public function test_guest_ip_wait_respects_the_supplied_node_timeout(): void
    {
        $proxmox = Mockery::mock(ProxmoxClient::class);
        $proxmox->shouldReceive('guestIpv4')->with(9000)->andReturn(null, '192.0.2.10');

        $this->expectException(ProvisioningException::class);
        $this->expectExceptionMessage('never reported an IPv4 address');

        (new ReflectionMethod(CloudImageBuilder::class, 'awaitGuestIp'))->invoke(
            new CloudImageBuilder, $proxmox, 9000, 1,
        );
    }

    public function test_guest_build_wait_respects_the_node_timeout(): void
    {
        $this->build->proxmoxTarget->update(['cloudimage_build_timeout' => 1]);
        $build = Mockery::mock(ImageBuild::class)->makePartial();
        $build->shouldReceive('getCasts')->andReturn($this->build->getCasts());
        $build->setRawAttributes($this->build->getAttributes(), true);
        $build->setRelation('proxmoxTarget', $this->build->proxmoxTarget);
        $build->shouldReceive('refresh')->once()->andReturn($build);
        $build->shouldReceive('refresh')->zeroOrMoreTimes()->andReturnUsing(function () use ($build): ImageBuild {
            $build->status = BuildStatus::Succeeded;

            return $build;
        });
        $ssh = Mockery::mock(SshConnection::class);
        $ssh->shouldReceive('run')->once()->andReturn('');

        $this->expectException(ProvisioningException::class);
        $this->expectExceptionMessage('Timed out waiting for the Cloud Image guest build result.');

        (new ReflectionMethod(CloudImageBuilder::class, 'awaitGuestResult'))->invoke(
            new CloudImageBuilder, $build, $ssh, '/unused',
        );
    }

    public function test_long_node_task_waits_prevent_premature_finalization_takeover(): void
    {
        $this->build->proxmoxTarget->update(['proxmox_task_timeout' => 7200]);
        $this->build->update(['guest_finalizing_at' => now()->subMinutes(16)]);

        $this->assertFalse($this->reconciler()->claimFinalization($this->build));

        $this->build->update(['guest_finalizing_at' => now()->subHours(6)]);
        $this->assertTrue($this->reconciler()->claimFinalization($this->build));
    }

    public function test_reconciled_guest_failure_destroys_the_vm_and_finalizes_failed(): void
    {
        $this->build->forceFill([
            'guest_outcome' => 'failed',
            'guest_exit_code' => 42,
            'guest_error' => 'Stage failed',
        ])->save();
        $this->fakeProxmox();

        $this->assertSame(1, $this->reconciler()->reconcile());
        $this->assertSame(BuildStatus::Failed, $this->build->fresh()->status);
        $this->assertSame(42, $this->build->fresh()->exit_code);
        $this->assertProxmoxVmDestroyed();
    }

    public function test_global_failed_vm_retention_prevents_reconciler_destruction(): void
    {
        app(SettingsRepository::class)->set(SettingsRepository::KEEP_FAILED_BUILD_VM, true);
        $this->build->forceFill(['guest_outcome' => 'failed'])->save();
        $this->fakeProxmox();

        $this->assertSame(1, $this->reconciler()->reconcile());
        $this->assertSame(BuildStatus::Failed, $this->build->fresh()->status);
        Http::assertNotSent(fn (Request $request): bool => $request->method() === 'DELETE');
    }

    public function test_per_build_failed_vm_retention_overrides_the_default(): void
    {
        $this->build->forceFill(['guest_outcome' => 'failed', 'keep_failed_vm' => true])->save();
        $this->fakeProxmox();

        $this->assertSame(1, $this->reconciler()->reconcile());
        $this->assertSame(BuildStatus::Failed, $this->build->fresh()->status);
        Http::assertNotSent(fn (Request $request): bool => $request->method() === 'DELETE');
    }

    public function test_force_kill_cancels_the_build_and_destroys_its_cloud_image_vm(): void
    {
        $this->fakeProxmox();

        $this->assertTrue(app(BuildCanceller::class)->cancel($this->build));

        $this->assertSame(BuildStatus::Cancelled, $this->build->fresh()->status);
        $this->assertNotNull($this->build->fresh()->finished_at);
        $this->assertProxmoxVmDestroyed();
    }

    public function test_force_kill_respects_global_failed_vm_retention(): void
    {
        app(SettingsRepository::class)->set(SettingsRepository::KEEP_FAILED_BUILD_VM, true);
        $this->fakeProxmox();

        $this->assertTrue(app(BuildCanceller::class)->cancel($this->build));
        $this->assertSame(BuildStatus::Cancelled, $this->build->fresh()->status);
        Http::assertNotSent(fn (Request $request): bool => $request->method() === 'DELETE');
    }

    private function awaitGuestResult(SshConnection $ssh): bool
    {
        return (new ReflectionMethod(CloudImageBuilder::class, 'awaitGuestResult'))->invoke(
            new CloudImageBuilder,
            $this->build,
            $ssh,
            '/home/runner/.gha-build-'.$this->build->id,
        );
    }

    /** @return array{TemplateCatalogEntry, string, string, string} */
    private function bundleFixture(): array
    {
        $templateDirectory = $this->fixtureRoot.'/template';
        $runnerRoot = $this->fixtureRoot.'/runner-images';
        $catalogRoot = $this->fixtureRoot.'/catalog';
        foreach ([
            $templateDirectory,
            $runnerRoot,
            $catalogRoot.'/guest-agents',
            $catalogRoot.'/scripts',
        ] as $directory) {
            mkdir($directory, 0750, true);
        }
        file_put_contents($templateDirectory.'/build.json', json_encode([
            'stage_groups' => [['stages' => [['id' => 'sample']]]],
        ], JSON_THROW_ON_ERROR));
        file_put_contents($templateDirectory.'/stage.sh', 'echo template');
        file_put_contents($runnerRoot.'/runner.sh', 'echo runner image');
        file_put_contents($catalogRoot.'/scripts/catalog-helper.sh', 'echo catalog helper');
        file_put_contents($catalogRoot.'/guest-agents/cloudimage-guest-runner.py', '#!/usr/bin/env python3');

        $entry = new TemplateCatalogEntry([
            'id' => 'ubuntu-test',
            'builders' => [
                'cloudimage' => [
                    'type' => 'cloudimage',
                    'provisioner' => [
                        'scripts_root_required' => true,
                        'runner_images_directory' => 'images/ubuntu/scripts',
                    ],
                ],
            ],
        ]);

        return [$entry, $templateDirectory, $runnerRoot, $catalogRoot];
    }

    private function attachBuildCredential(): void
    {
        BuildCredential::create([
            'image_build_id' => $this->build->id,
            'os' => 'linux',
            'username' => 'runner',
            'private_key' => 'test-private-key',
            'public_key' => 'ssh-ed25519 test-public-key',
        ]);
    }

    private function reconciler(): CloudImageGuestBuildReconciler
    {
        return new CloudImageGuestBuildReconciler(
            app(ImageBuildFinalizer::class),
            app(TemplateRebuilder::class),
            app(SettingsRepository::class),
        );
    }

    private function fakeProxmox(bool $resultFromGuest = false): void
    {
        Http::fake(function (Request $request) use ($resultFromGuest) {
            $path = (string) parse_url($request->url(), PHP_URL_PATH);

            if (str_ends_with($path, '/agent/network-get-interfaces') && $resultFromGuest) {
                return Http::response(['data' => [
                    'result' => [[
                        'name' => 'eth0',
                        'ip-addresses' => [[
                            'ip-address-type' => 'ipv4',
                            'ip-address' => '192.0.2.10',
                        ]],
                    ]],
                ]]);
            }

            if (str_contains($path, '/tasks/')) {
                return Http::response(['data' => ['status' => 'stopped', 'exitstatus' => 'OK']]);
            }

            if (str_ends_with($path, '/status/current')) {
                return Http::response(['data' => ['status' => 'stopped']]);
            }

            if (in_array($request->method(), ['POST', 'DELETE'], true)) {
                return Http::response(['data' => 'UPID:cloudimage-test']);
            }

            return Http::response(['data' => []]);
        });
    }

    private function assertProxmoxVmDestroyed(): void
    {
        Http::assertSent(fn (Request $request): bool => $request->method() === 'DELETE'
            && str_contains($request->url(), '/qemu/9000?'));
    }
}
