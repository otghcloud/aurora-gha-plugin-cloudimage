<?php

namespace OTGH\GHARM\CloudImage;

use App\Exceptions\ProvisioningException;
use App\Models\Builds\ImageBuild;
use App\Models\Credentials\BuildCredential;
use App\Models\Credentials\Credential;

/** Resolves the durable guest-owned directory for one Cloud Image build. */
final class GuestBuildPath
{
    public static function forBuild(ImageBuild $build, Credential|BuildCredential $credential): string
    {
        $username = $credential->resolvedUsername();

        if (preg_match('/^[a-z_][a-z0-9_-]*$/i', $username) !== 1) {
            throw new ProvisioningException('The Cloud Image build credential has an invalid Linux username.');
        }

        return '/home/'.$username.'/.gha-build-'.$build->id;
    }
}
