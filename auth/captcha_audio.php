<?php
/**
 * The CAPTCHA challenge, spoken.
 *
 * The accessible alternative to the distorted image: someone who cannot read
 * the picture listens to the same characters instead. It plays the code that
 * is already in the session and never issues a new one, so the image and the
 * audio always agree and pressing play does not quietly invalidate the image
 * the person is looking at.
 *
 * Real speech, from the engine built into Windows, one cached clip per
 * character (see includes/captcha_voice.php). Where no speech engine is
 * available the endpoint falls back to a tone code and says so in the
 * X-Captcha-Audio header, so the page can tell the listener what they are
 * about to hear rather than implying a voice that is not there.
 *
 * Nothing about the answer reaches the browser except the sound itself: the
 * code is never put in a header, a filename or any JSON.
 */
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../includes/captcha_voice.php';

/* Synthesis is the more expensive of the two endpoints, and an open one that
   can be looped is how a CAPTCHA becomes a way to pin the CPU. */
if (!captcha_rate_ok('audio')) {
    http_response_code(429);
    header('Retry-After: 30');
    header('Content-Type: text/plain; charset=utf-8');
    exit('Too many requests. Please wait a moment and try again.');
}

$code = captcha_current_code();

if ($code === null) {
    // Nothing live to read. Asking for the image first keeps the two from
    // ever describing different codes.
    http_response_code(409);
    header('Content-Type: text/plain; charset=utf-8');
    exit('No challenge is waiting. Refresh the CAPTCHA and try again.');
}

/* ------------------------------------------------------------- speech */
$pcm = null;
$kind = 'tones';

try {
    $pcm = captcha_voice_pcm_for($code);
    if ($pcm !== null) {
        $kind = 'speech';
    }
} catch (Throwable $e) {
    // A broken engine must not take the sign-in page down with it; the
    // listener still gets something, and the reason goes to the log.
    error_log('captcha_audio: ' . $e->getMessage());
    $pcm = null;
}

/* ------------------------------------------- fallback: the tone code ---
   Used only when no speech engine answered. Each character becomes two tones
   from a fixed table, read at dictation pace. The information really is in
   the sound, but it is a code rather than a voice, and the page is told which
   it received so it can say so. */
if ($pcm === null) {
    $rate = CAPTCHA_VOICE_RATE;
    $samples = [];

    $tone = static function (float $freq, float $seconds, float $gain = 0.32) use (&$samples, $rate): void {
        $n = (int)round($seconds * $rate);
        $fade = max(1, (int)($n * 0.12));
        for ($i = 0; $i < $n; $i++) {
            $env = 1.0;
            if ($i < $fade) {
                $env = $i / $fade;
            } elseif ($i > $n - $fade) {
                $env = max(0.0, ($n - $i) / $fade);
            }
            $samples[] = sin(2 * M_PI * $freq * ($i / $rate)) * $gain * $env;
        }
    };
    $rest = static function (float $seconds) use (&$samples, $rate): void {
        $n = (int)round($seconds * $rate);
        for ($i = 0; $i < $n; $i++) {
            $samples[] = 0.0;
        }
    };
    $hiss = static function (float $seconds, float $gain = 0.05) use (&$samples, $rate): void {
        $n = (int)round($seconds * $rate);
        for ($i = 0; $i < $n; $i++) {
            $samples[] = (mt_rand() / mt_getrandmax() - 0.5) * 2 * $gain;
        }
    };

    $low  = [392.0, 440.0, 494.0, 523.0, 587.0, 659.0];
    $high = [784.0, 880.0, 988.0, 1047.0, 1175.0];

    $rest(0.35);
    foreach (str_split($code) as $char) {
        $index = strpos(CAPTCHA_VOICE_ALPHABET, $char);
        if ($index === false) {
            continue;
        }
        $tone($low[$index % count($low)], 0.20);
        $rest(0.05);
        $tone($high[intdiv($index, count($low)) % count($high)], 0.20);
        $hiss(0.10);
        $rest(0.30);
    }
    $rest(0.25);

    $pcm = '';
    foreach ($samples as $sample) {
        $clipped = max(-1.0, min(1.0, $sample));
        $pcm .= pack('v', (int)round($clipped * 32767) & 0xFFFF);
    }
}

$wav = captcha_wav($pcm);

// Never cached: the challenge changes, and a cached clip would read out a code
// that has already been consumed.
header('Content-Type: audio/wav');
header('Content-Length: ' . strlen($wav));
header('X-Captcha-Audio: ' . $kind);
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

echo $wav;
