<?php

namespace ClinicFlow\Tests;

use ClinicFlow\Services\UserPreferences;
use PHPUnit\Framework\TestCase;

class UserPreferencesVoiceTest extends TestCase
{
    public function testDictationIsOffByDefault(): void
    {
        $d = UserPreferences::defaults();
        $this->assertFalse($d['voice_input']);
        $this->assertSame('en-US', $d['voice_lang']);
    }

    public function testDoctorCanOptInAndPickALanguage(): void
    {
        $out = UserPreferences::sanitise(['voice_input' => '1', 'voice_lang' => 'en-GB'], UserPreferences::defaults());
        $this->assertTrue($out['voice_input']);
        $this->assertSame('en-GB', $out['voice_lang']);
    }

    public function testUnknownLanguageKeepsThePreviousChoice(): void
    {
        $current = array_merge(UserPreferences::defaults(), ['voice_lang' => 'en-AU']);
        $out = UserPreferences::sanitise(['voice_lang' => '"><script>'], $current);
        $this->assertSame('en-AU', $out['voice_lang']);
    }

    public function testUntickedBoxTurnsDictationOff(): void
    {
        $current = array_merge(UserPreferences::defaults(), ['voice_input' => true]);
        $this->assertFalse(UserPreferences::sanitise([], $current)['voice_input']);
    }

    public function testWordListIsTidiedAndCapped(): void
    {
        $out = UserPreferences::sanitise(['voice_words' => "  Metoclopramide \n\n=\nsevilla = Seville"], UserPreferences::defaults());
        $this->assertSame("Metoclopramide\nsevilla = Seville", $out['voice_words']);
        $many = implode("\n", array_fill(0, 400, 'term'));
        $this->assertCount(150, explode("\n", UserPreferences::cleanVoiceWords($many)));
    }
}
