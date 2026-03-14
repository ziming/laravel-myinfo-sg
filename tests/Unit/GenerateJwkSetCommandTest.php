<?php

declare(strict_types=1);

namespace Ziming\LaravelMyinfoSg\Tests\Unit;

use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Symfony\Component\Console\Tester\CommandTester;
use Ziming\LaravelMyinfoSg\GenerateJwkSetCommand;
use Ziming\LaravelMyinfoSg\Tests\TestCase;

class GenerateJwkSetCommandTest extends TestCase
{
    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function testGenerateJwkSetCommandOutputsExpectedJwksPayloads(): void
    {
        $now = Carbon::create(2026, 3, 15, 10, 20, 30, 'UTC');
        Carbon::setTestNow($now);

        $command = $this->app->make(GenerateJwkSetCommand::class);
        $command->setLaravel($this->app);
        $tester = new CommandTester($command);

        $this->assertSame(0, $tester->execute([]));

        $output = str_replace("\r\n", "\n", $tester->getDisplay());

        $this->assertStringContainsString('Pretty Printed Json', $output);
        $this->assertStringContainsString('--------', $output);
        $this->assertStringContainsString(
            'Non Pretty Printed Json, If you prefer to have it in your env file for example',
            $output
        );

        $prettyJson = trim(Str::before(Str::after($output, 'Pretty Printed Json'), '--------'));
        $compactJson = trim(Str::after(
            $output,
            'Non Pretty Printed Json, If you prefer to have it in your env file for example'
        ));

        $prettyPayload = json_decode($prettyJson, true, 512, JSON_THROW_ON_ERROR);
        $compactPayload = json_decode($compactJson, true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame(['keys'], array_keys($prettyPayload));
        $this->assertCount(2, $prettyPayload['keys']);
        $this->assertSame($prettyPayload['keys'], $compactPayload);

        $expectedTimestamp = $now->toISOString();
        $this->assertSame(
            ['sig-'.$expectedTimestamp, 'enc-'.$expectedTimestamp],
            array_column($compactPayload, 'kid')
        );
        $this->assertSame(['sig', 'enc'], array_column($compactPayload, 'use'));
        $this->assertSame(['ES256', 'ES256'], array_column($compactPayload, 'alg'));
        $this->assertSame(['EC', 'EC'], array_column($compactPayload, 'kty'));
        $this->assertSame(['P-256', 'P-256'], array_column($compactPayload, 'crv'));
    }
}
