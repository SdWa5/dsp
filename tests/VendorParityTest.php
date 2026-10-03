<?php

namespace App\Tests;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

/** Guard the evidence inventory against omissions and false completeness claims. */
final class VendorParityTest extends TestCase
{
    private const ROOT = __DIR__.'/..';

    public function testInventoryCoversEveryCapturedTabAndNamedForm(): void
    {
        $process = $this->check();
        self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        self::assertStringContainsString('22 tabs and 34 forms', $process->getOutput());
        self::assertSame(
            0,
            $this->check('--require-complete')->getExitCode(),
            'Recorded unknowns satisfy the agreed audit completion rule.'
        );
    }

    public function testConfirmedImplementationGapStillBlocksCompletion(): void
    {
        $manifest = $this->manifest();
        $manifest['features']['ip']['status'] = 'confirmed gap';
        $process = $this->checkManifest($manifest, '--require-complete');
        self::assertSame(2, $process->getExitCode());
        self::assertStringContainsString('confirmed gap(s) to implement', $process->getOutput());
    }

    public function testEvidenceFollowUpMustLinkToAConcreteTodo(): void
    {
        $manifest = $this->manifest();
        $manifest['forms']['TfmHidData | Hid Data']['features'] = ['gain'];
        $process = $this->checkManifest($manifest);
        self::assertSame(1, $process->getExitCode());
        self::assertStringContainsString('Capture follow-up has no unresolved TODO', $process->getErrorOutput());
    }

    public function testDroppingAFormFailsTheCoverageCheck(): void
    {
        $manifest = $this->manifest();
        unset($manifest['forms']['TfmHidData | Hid Data']);
        $process = $this->checkManifest($manifest);
        self::assertSame(1, $process->getExitCode());
        self::assertStringContainsString('Coverage differs from captures/dialogs.json', $process->getErrorOutput());
    }

    public function testInventedScreenshotDoesNotCountAsEvidence(): void
    {
        $manifest = $this->manifest();
        $manifest['forms']['TfmHidData | Hid Data']['screenshots'] = ['tools/shots/missing-hid-evidence.png'];
        $process = $this->checkManifest($manifest);
        self::assertSame(1, $process->getExitCode());
        self::assertStringContainsString('Missing PNG evidence', $process->getErrorOutput());
    }

    public function testUnknownFeatureRequiresAConcreteVerification(): void
    {
        $manifest = $this->manifest();
        $manifest['features']['ip']['verify'] = '';
        $process = $this->checkManifest($manifest);
        self::assertSame(1, $process->getExitCode());
        self::assertStringContainsString('Unresolved feature has no verification recipe', $process->getErrorOutput());
    }

    public function testUnresolvedFeatureMustHaveATodo(): void
    {
        $manifest = $this->manifest();
        $manifest['features']['unrecorded'] = $manifest['features']['ip'];
        $process = $this->checkManifest($manifest);
        self::assertSame(1, $process->getExitCode());
        self::assertStringContainsString('Unresolved feature has no TODO: unrecorded', $process->getErrorOutput());
    }

    public function testAFormCannotBeExcludedByItsNameAlone(): void
    {
        $manifest = $this->manifest();
        $manifest['features']['hid']['status'] = 'internal/helper';
        $process = $this->checkManifest($manifest);
        self::assertSame(1, $process->getExitCode());
        self::assertStringContainsString('Helper exclusion has no evidence: hid', $process->getErrorOutput());
    }

    private function check(string ...$options): Process
    {
        $process = new Process([\PHP_BINARY, self::ROOT.'/tools/vendor-parity.php', ...$options], self::ROOT);
        $process->run();

        return $process;
    }

    /** @return array<string, mixed> */
    private function manifest(): array
    {
        return json_decode(
            (string) file_get_contents(self::ROOT.'/docs/vendor-parity.json'),
            true,
            flags: \JSON_THROW_ON_ERROR
        );
    }

    /** @param array<string, mixed> $manifest */
    private function checkManifest(array $manifest, string ...$options): Process
    {
        $file = tempnam(sys_get_temp_dir(), 'vendor-parity-');
        self::assertIsString($file);
        try {
            file_put_contents($file, json_encode($manifest, \JSON_THROW_ON_ERROR));

            return $this->check('--manifest', $file, ...$options);
        } finally {
            unlink($file);
        }
    }
}
